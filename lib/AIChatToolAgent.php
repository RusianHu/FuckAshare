<?php
/**
 * AIChatToolAgent — OpenAI-compatible Chat Completions tool-call orchestrator.
 */

require_once __DIR__ . '/AIToolRegistry.php';
require_once __DIR__ . '/AIToolExecutor.php';

class AIChatToolAgent
{
    /** @var array */
    private $channel;

    /** @var array */
    private $options;

    /** @var AIToolExecutor */
    private $executor;

    /** @var callable|null */
    private $transport;

    /** @var callable|null */
    private $streamTransport;

    public function __construct(array $channel, array $options = [], ?AIToolExecutor $executor = null, ?callable $transport = null, ?callable $streamTransport = null)
    {
        $this->channel = $channel;
        $this->options = array_merge([
            'max_tool_rounds' => 10,
            'max_tool_calls_per_round' => 8,
            'tool_timeout' => 45,
            'tool_output_char_limit' => 60000,
            'parallel_tool_calls' => true,
            'expose_tool_trace' => true,
            'auto_prefetch' => true,
            'stream_after_tool_round' => true,
            'timeout' => 300,
            'connect_timeout' => 15,
        ], $options);
        $this->executor = $executor ?: new AIToolExecutor(null, null, (int)$this->options['tool_output_char_limit']);
        $this->transport = $transport;
        $this->streamTransport = $streamTransport;
    }

    public function run(array $messages, callable $emit): void
    {
        $originalMessages = $messages;
        $messages = $this->prepareMessages($messages);
        $tools = AIToolRegistry::chatTools();
        $seenCalls = [];
        $usedTools = false;
        $maxRounds = max(1, (int)$this->options['max_tool_rounds']);

        for ($round = 1; $round <= $maxRounds; $round++) {
            $payload = $this->basePayload($messages, false);
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = (bool)$this->options['parallel_tool_calls'];

            try {
                $response = $this->complete($payload);
            } catch (Throwable $e) {
                $reason = trim($e->getMessage());
                if (!empty($this->options['auto_prefetch'])) {
                    $prefetched = $this->prefetchResearchContext($originalMessages, $emit, 'server_prefetch');
                    if (!empty($prefetched)) {
                        $messages[] = $this->prefetchSystemMessage($prefetched);
                        $this->streamFinal($messages, $emit);
                        return;
                    }
                }

                $message = '当前上游未能完成工具调用握手，已回退普通流式对话。';
                if ($reason !== '') {
                    $message .= '原因：' . mb_substr($reason, 0, 160);
                }
                $this->emitFallbackStatus($emit, $message);
                $this->streamPlain($originalMessages, $emit);
                return;
            }
            $assistant = $this->extractAssistantMessage($response);
            if ($assistant === null) {
                $this->emitError($emit, '上游 AI 响应格式无效，未返回 assistant message。', 'invalid_upstream_response');
                return;
            }

            $toolCalls = $this->extractToolCalls($assistant);
            if (empty($toolCalls)) {
                $content = (string)($assistant['content'] ?? '');
                if ((!$usedTools || !$this->hasUsefulResearchToolResult($messages)) && !empty($this->options['auto_prefetch'])) {
                    $prefetched = $this->prefetchResearchContext($originalMessages, $emit, 'server_prefetch');
                    if (!empty($prefetched)) {
                        $messages[] = $this->prefetchSystemMessage($prefetched);
                        $this->streamFinal($messages, $emit);
                        return;
                    }
                }
                $this->emitSyntheticContent($emit, $content);
                return;
            }

            $usedTools = true;
            $messages[] = $this->compactAssistantMessage($assistant, $toolCalls);
            $toolLimit = max(1, (int)$this->options['max_tool_calls_per_round']);
            $toolCalls = array_slice($toolCalls, 0, $toolLimit);

            foreach ($toolCalls as $toolCall) {
                $name = (string)($toolCall['function']['name'] ?? '');
                $callId = (string)($toolCall['id'] ?? uniqid('call_', true));
                $argsJson = (string)($toolCall['function']['arguments'] ?? '{}');
                $args = json_decode($argsJson, true);
                if (!is_array($args)) {
                    $args = [];
                    $this->emitToolStatus($emit, $round, $name, ['invalid_arguments_json' => $argsJson], 'model_tool_call');
                    $result = json_encode([
                        'success' => false,
                        'source' => 'ai_tool',
                        'action' => $name,
                        'code' => 'invalid_arguments_json',
                        'message' => '工具参数不是有效 JSON',
                        'meta' => ['updated_at' => date('c')],
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    $signature = $name . ':' . md5(json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if (isset($seenCalls[$signature])) {
                        $result = json_encode([
                            'success' => false,
                            'source' => 'ai_tool',
                            'action' => $name,
                            'code' => 'duplicate_tool_call',
                            'message' => '本次请求中已执行过相同工具和参数，已跳过去重。',
                            'meta' => ['updated_at' => date('c')],
                        ], JSON_UNESCAPED_UNICODE);
                    } else {
                        $seenCalls[$signature] = true;
                        $this->emitToolStatus($emit, $round, $name, $args, 'model_tool_call');
                        $result = $this->executor->executeForModel($name, $args);
                    }
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'name' => $name,
                    'content' => $result,
                ];
            }

            if ($this->shouldContinueAfterToolRound($toolCalls, $originalMessages) && !empty($this->options['auto_prefetch'])) {
                $prefetched = $this->prefetchResearchContext($originalMessages, $emit, 'server_prefetch');
                if (!empty($prefetched)) {
                    $messages[] = $this->prefetchSystemMessage($prefetched);
                    $this->streamFinal($messages, $emit);
                    return;
                }
            }

            if (!$this->hasUsefulResearchToolResult($messages) && !empty($this->options['auto_prefetch'])) {
                $prefetched = $this->prefetchResearchContext($originalMessages, $emit, 'server_prefetch');
                if (!empty($prefetched)) {
                    $messages[] = $this->prefetchSystemMessage($prefetched);
                    $this->streamFinal($messages, $emit);
                    return;
                }
            }

            if (!empty($this->options['stream_after_tool_round'])) {
                $messages[] = [
                    'role' => 'system',
                    'content' => '已执行上一条 assistant 消息中模型主动请求的工具。请基于 tool 结果直接给出最终研究结论；不要再次请求工具。若数据不足，请明确说明。',
                ];
                $this->streamFinal($messages, $emit);
                return;
            }
        }

        $messages[] = [
            'role' => 'system',
            'content' => '工具调用轮次已达上限。请基于已经返回的工具数据给出阶段性研究结论，明确说明仍然缺失或不确定的信息。',
        ];
        $this->streamFinal($messages, $emit);
    }

    public function streamPlain(array $messages, callable $emit): void
    {
        $this->streamPayload($this->basePayload($messages, true), $emit);
    }

    private function prepareMessages(array $messages): array
    {
        $prepared = [];
        $hasSystem = false;
        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            if (($message['role'] ?? '') === 'system') {
                $hasSystem = true;
                $message['content'] = $this->systemPrompt() . "\n\n" . (string)($message['content'] ?? '');
            }
            $prepared[] = $message;
        }
        if (!$hasSystem) {
            array_unshift($prepared, ['role' => 'system', 'content' => $this->systemPrompt()]);
        }
        return $prepared;
    }

    private function prefetchResearchContext(array $messages, callable $emit, string $origin): array
    {
        $latestUser = $this->latestUserContent($messages);
        if ($latestUser === '') return [];

        $contexts = [];
        if ($this->looksLikeFundRequest($latestUser)) {
            $fundCodes = $this->extractFundCodes($latestUser);
            foreach (array_slice($fundCodes, 0, 2) as $code) {
                $contexts[] = [
                    'asset_type' => 'fund',
                    'code' => $code,
                    'tool_results' => [
                        'fa_get_fund_info' => $this->executePrefetchTool($emit, 'fa_get_fund_info', ['codes' => [$code]], $origin),
                        'fa_get_fund_estimate' => $this->executePrefetchTool($emit, 'fa_get_fund_estimate', ['codes' => [$code]], $origin),
                        'fa_get_fund_history' => $this->executePrefetchTool($emit, 'fa_get_fund_history', ['code' => $code, 'page' => 1, 'page_size' => 40], $origin),
                        'fa_get_fund_rank' => $this->executePrefetchTool($emit, 'fa_get_fund_rank', ['type' => 'all', 'period' => 'year', 'page' => 1, 'page_size' => 30], $origin),
                    ],
                ];
            }
            return $contexts;
        }

        if ($this->looksLikeMarketScanRequest($latestUser)) {
            $hotStocks = $this->executePrefetchTool($emit, 'fa_get_hot_stocks', [
                'page' => 1,
                'page_size' => $this->requestedTopN($latestUser, 10),
                'sort' => 'f62',
                'order' => 1,
            ], $origin);
            $sectorFlow = $this->executePrefetchTool($emit, 'fa_get_sector_flow', [
                'key' => 'f62',
                'type' => 'industry',
            ], $origin);
            $xueqiuHot = $this->executePrefetchTool($emit, 'fa_get_xueqiu_hot_stock', [
                'type' => '10',
                'size' => 20,
            ], $origin);

            $candidateTools = [];
            foreach ($this->candidateCodesFromHotStocks($hotStocks, 3) as $code) {
                $candidateTools[$code] = [
                    'fa_get_stock_quote' => $this->executePrefetchTool($emit, 'fa_get_stock_quote', ['codes' => [$code], 'source' => 'auto', 'fallback' => true], $origin),
                    'fa_calculate_kline_indicators' => $this->executePrefetchTool($emit, 'fa_calculate_kline_indicators', ['code' => $code, 'frequency' => '1d', 'count' => 120, 'source' => 'auto'], $origin),
                    'fa_get_stock_flow' => $this->executePrefetchTool($emit, 'fa_get_stock_flow', ['code' => $code, 'limit' => 30], $origin),
                ];
            }

            return [[
                'asset_type' => 'market_scan',
                'topic' => 'capital_inflow_candidates',
                'tool_results' => [
                    'fa_get_hot_stocks' => $hotStocks,
                    'fa_get_sector_flow' => $sectorFlow,
                    'fa_get_xueqiu_hot_stock' => $xueqiuHot,
                ],
                'candidate_deep_dive' => $candidateTools,
            ]];
        }

        if ($this->looksLikeStockResearchRequest($latestUser)) {
            $stockCodes = $this->extractStockCodes($latestUser);
            foreach (array_slice($stockCodes, 0, 2) as $code) {
                $contexts[] = [
                    'asset_type' => 'stock',
                    'code' => $code,
                    'tool_results' => [
                        'fa_get_stock_quote' => $this->executePrefetchTool($emit, 'fa_get_stock_quote', ['codes' => [$code], 'source' => 'auto', 'fallback' => true], $origin),
                        'fa_calculate_kline_indicators' => $this->executePrefetchTool($emit, 'fa_calculate_kline_indicators', ['code' => $code, 'frequency' => '1d', 'count' => 120, 'source' => 'auto'], $origin),
                        'fa_get_stock_flow' => $this->executePrefetchTool($emit, 'fa_get_stock_flow', ['code' => $code, 'limit' => 30], $origin),
                    ],
                ];
            }
        }

        return $contexts;
    }

    private function candidateCodesFromHotStocks(array $hotStocks, int $limit): array
    {
        if (($hotStocks['success'] ?? false) !== true || !is_array($hotStocks['data'] ?? null)) {
            return [];
        }
        $codes = [];
        foreach ($hotStocks['data'] as $item) {
            if (!is_array($item)) continue;
            $code = (string)($item['dm'] ?? $item['code'] ?? '');
            if ($code !== '' && preg_match('/^(sh|sz|SH|SZ)?\d{6}$/', $code)) {
                $codes[] = $code;
            }
            if (count($codes) >= $limit) break;
        }
        return array_values(array_unique($codes));
    }

    private function requestedTopN(string $text, int $default): int
    {
        if (preg_match('/(?:前|top\s*)(\d{1,2})/iu', $text, $m)) {
            return max(5, min(30, (int)$m[1]));
        }
        if (preg_match('/(\d{1,2})(?:只|个)?(?:股票|标的|候选)/u', $text, $m)) {
            return max(5, min(30, (int)$m[1]));
        }
        return $default;
    }

    private function prefetchSystemMessage(array $prefetched): array
    {
        return [
            'role' => 'system',
            'content' => "以下是服务端兜底预取本地只读研究工具得到的数据。请只基于这些数据和用户上下文进行分析；如果数据不足，明确说明不足，不要编造实时数据。\n" .
                json_encode($prefetched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function executePrefetchTool(callable $emit, string $name, array $args, string $origin): array
    {
        $this->emitToolStatus($emit, 1, $name, $args, $origin);
        $json = $this->executor->executeForModel($name, $args);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [
            'success' => false,
            'source' => 'ai_tool',
            'action' => $name,
            'code' => 'invalid_tool_json',
            'message' => '工具结果无法解析为 JSON',
        ];
    }

    private function latestUserContent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return (string)($messages[$i]['content'] ?? '');
            }
        }
        return '';
    }

    private function shouldContinueAfterToolRound(array $toolCalls, array $originalMessages): bool
    {
        $latestUser = $this->latestUserContent($originalMessages);
        if (!$this->looksLikeStockResearchRequest($latestUser) && !$this->looksLikeFundRequest($latestUser) && !$this->looksLikeMarketScanRequest($latestUser)) {
            return false;
        }

        $names = [];
        foreach ($toolCalls as $call) {
            $names[] = (string)($call['function']['name'] ?? '');
        }
        $names = array_values(array_unique(array_filter($names, 'strlen')));
        if (empty($names)) return false;

        $setupOnly = ['fa_normalize_stock_code'];
        return count(array_diff($names, $setupOnly)) === 0;
    }

    private function hasUsefulResearchToolResult(array $messages): bool
    {
        $useful = [
            'fa_get_stock_quote',
            'fa_get_stock_kline',
            'fa_get_stock_flow',
            'fa_calculate_kline_indicators',
            'fa_get_fund_info',
            'fa_get_fund_estimate',
            'fa_get_fund_history',
            'fa_get_fund_rank',
            'fa_get_hot_stocks',
            'fa_get_sector_flow',
            'fa_get_xueqiu_hot_stock',
            'fa_run_xueqiu_screener',
        ];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'tool' || !in_array((string)($message['name'] ?? ''), $useful, true)) {
                continue;
            }
            $decoded = json_decode((string)($message['content'] ?? ''), true);
            if (is_array($decoded) && ($decoded['success'] ?? false) === true) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeStockResearchRequest(string $text): bool
    {
        if (preg_match('/\b(?:sh|sz|SH|SZ)\d{6}\b|\b\d{6}\.(?:XSHG|XSHE)\b/u', $text)) {
            return true;
        }
        return preg_match('/(股票|个股|行情|K线|k线|技术|趋势|资金流|主力|支撑|压力|分析|评估|查询)/u', $text)
            && preg_match('/\b\d{6}\b/u', $text);
    }

    private function looksLikeFundRequest(string $text): bool
    {
        return preg_match('/(基金|净值|估值|基金经理|基金公司|申购|赎回|同类排行)/u', $text)
            && preg_match('/\b\d{6}\b/u', $text);
    }

    private function looksLikeMarketScanRequest(string $text): bool
    {
        return preg_match('/(资金流入|净流入|主力流入|资金榜|热股|热门股|候选|选股|筛选|前十|前10|top\s*10|排名|排行)/iu', $text)
            && preg_match('/(股票|个股|标的|市场|板块|综合评估|综合分析|评估|分析)/u', $text);
    }

    private function extractStockCodes(string $text): array
    {
        preg_match_all('/\b(?:(?:sh|sz|SH|SZ)\d{6}|\d{6}\.(?:XSHG|XSHE)|\d{6})\b/u', $text, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    private function extractFundCodes(string $text): array
    {
        preg_match_all('/\b\d{6}\b/u', $text, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            '你是一位专业、谨慎的金融研究助理，服务于 A 股、基金、板块资金和市场热度研究。',
            '你可以使用服务端提供的只读工具查询行情、K线、资金流、基金、雪球热度和确定性技术指标。',
            '优先用工具获取事实数据；不要编造实时价格、资金流、基金净值、排行或新闻。',
            '回答时清晰区分：数据事实、基于数据的推断、不确定性和需要继续验证的信息。',
            '不要给出保证收益、确定买卖点或个性化投资承诺。结尾必须提示：内容仅供研究参考，不构成投资建议。',
        ]);
    }

    private function basePayload(array $messages, bool $stream): array
    {
        return [
            'model' => (string)$this->channel['model'],
            'messages' => $messages,
            'stream' => $stream,
        ];
    }

    private function complete(array $payload): array
    {
        if ($this->transport) {
            $result = call_user_func($this->transport, $payload);
            if (!is_array($result)) {
                throw new RuntimeException('测试 transport 必须返回数组');
            }
            return $result;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->channel['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->channel['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, (int)$this->options['tool_timeout']));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->options['connect_timeout']);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("API请求失败: {$error}", $httpCode ?: $errno);
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException('上游 AI 返回非 JSON 响应: ' . json_last_error_msg(), $httpCode ?: 0);
        }
        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE)) : (string)$json['error'];
            throw new RuntimeException($message, $httpCode ?: 0);
        }
        return $json;
    }

    private function streamFinal(array $messages, callable $emit): void
    {
        $payload = $this->basePayload($this->messagesForFinalStream($messages), true);
        $payload['tool_choice'] = 'none';
        $this->streamPayload($payload, $emit);
    }

    private function messagesForFinalStream(array $messages): array
    {
        $final = [];
        $toolResults = [];

        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            $role = $message['role'] ?? '';
            if ($role === 'tool') {
                $toolResults[] = [
                    'name' => $message['name'] ?? '',
                    'tool_call_id' => $message['tool_call_id'] ?? '',
                    'content' => $message['content'] ?? '',
                ];
                continue;
            }

            $clean = [
                'role' => $role,
                'content' => $message['content'] ?? '',
            ];
            if ($role === 'assistant' && isset($message['tool_calls'])) {
                $toolResults[] = [
                    'assistant_tool_calls' => $message['tool_calls'],
                ];
                if ($clean['content'] === null || $clean['content'] === '') {
                    continue;
                }
            }
            if (in_array($role, ['system', 'user', 'assistant'], true)) {
                $final[] = $clean;
            }
        }

        if (!empty($toolResults)) {
            $final[] = [
                'role' => 'system',
                'content' => "以下是本轮已经真实执行的工具调用和工具结果。请基于这些结果回答，不要声称无法访问数据；如果某个工具失败，请说明失败项。\n" .
                    json_encode($toolResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $final;
    }

    private function streamPayload(array $payload, callable $emit): void
    {
        if ($this->streamTransport) {
            call_user_func($this->streamTransport, $payload, $emit);
            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->channel['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->channel['api_key'],
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->options['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->options['connect_timeout']);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($emit) {
            if (connection_aborted()) {
                return 0;
            }
            $emit($data);
            return strlen($data);
        });

        $result = curl_exec($ch);
        if ($result === false && curl_errno($ch)) {
            $message = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: curl_errno($ch);
            $this->emitError($emit, "API请求失败: {$message}", 'proxy_error', $code);
        }
        curl_close($ch);
    }

    private function extractAssistantMessage(array $response): ?array
    {
        $message = $response['choices'][0]['message'] ?? null;
        return is_array($message) ? $message : null;
    }

    private function extractToolCalls(array $assistant): array
    {
        if (isset($assistant['tool_calls']) && is_array($assistant['tool_calls'])) {
            return array_values(array_filter($assistant['tool_calls'], function($call) {
                return is_array($call) && (($call['type'] ?? 'function') === 'function');
            }));
        }

        if (isset($assistant['function_call']) && is_array($assistant['function_call'])) {
            return [[
                'id' => uniqid('legacy_call_', true),
                'type' => 'function',
                'function' => [
                    'name' => $assistant['function_call']['name'] ?? '',
                    'arguments' => $assistant['function_call']['arguments'] ?? '{}',
                ],
            ]];
        }

        return [];
    }

    private function compactAssistantMessage(array $assistant, ?array $toolCalls = null): array
    {
        $message = [
            'role' => 'assistant',
            'content' => $assistant['content'] ?? null,
        ];
        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        } elseif (isset($assistant['tool_calls'])) {
            $message['tool_calls'] = $assistant['tool_calls'];
        }
        return $message;
    }

    private function emitToolStatus(callable $emit, int $round, string $name, array $args, string $origin = 'model_tool_call'): void
    {
        if (empty($this->options['expose_tool_trace'])) return;
        $isPrefetch = $origin === 'server_prefetch';
        $labels = AIToolRegistry::descriptions();
        $payload = [
            'type' => 'tool_status',
            'round' => $round,
            'tool' => $name,
            'origin' => $origin,
            'trace_title' => $isPrefetch ? '服务端数据预取' : 'AI 工具调用',
            'message' => ($isPrefetch ? '预取数据：' : '') . $this->toolStatusText($name),
            'description' => $labels[$name] ?? '',
            'args_summary' => $this->summarizeArgs($args),
        ];
        $emit("event: tool_status\n");
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }

    private function emitFallbackStatus(callable $emit, string $message): void
    {
        if (empty($this->options['expose_tool_trace'])) return;
        $payload = [
            'type' => 'tool_status',
            'round' => 0,
            'tool' => 'fallback_plain_stream',
            'message' => $message,
            'description' => 'Tool calling fallback',
            'args_summary' => [],
        ];
        $emit("event: tool_status\n");
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }

    private function toolStatusText(string $name): string
    {
        $map = [
            'fa_get_stock_quote' => '查询实时行情',
            'fa_get_stock_kline' => '获取K线数据',
            'fa_get_stock_flow' => '获取个股资金流',
            'fa_get_sector_flow' => '获取板块资金流',
            'fa_get_hot_stocks' => '查询资金热榜',
            'fa_get_xueqiu_hot_stock' => '查询雪球热度',
            'fa_run_xueqiu_screener' => '运行条件选股',
            'fa_get_xueqiu_feed' => '获取雪球动态',
            'fa_search_funds' => '搜索基金',
            'fa_get_fund_info' => '获取基金资料',
            'fa_get_fund_estimate' => '获取基金估值',
            'fa_get_fund_history' => '获取基金历史净值',
            'fa_get_fund_rank' => '获取基金同类排行',
            'fa_calculate_kline_indicators' => '计算技术指标',
            'fa_compare_candidates' => '排序候选标的',
        ];
        return $map[$name] ?? '调用研究工具';
    }

    private function summarizeArgs(array $args): array
    {
        $summary = [];
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                $summary[$key] = count($value) > 5 ? array_slice($value, 0, 5) + ['_more' => count($value) - 5] : $value;
            } else {
                $summary[$key] = $value;
            }
        }
        return $summary;
    }

    private function emitSyntheticContent(callable $emit, string $content): void
    {
        if ($content === '') {
            $this->emitError($emit, '服务器返回空响应，请稍后重试。', 'empty_response');
            $emit("data: [DONE]\n\n");
            return;
        }
        $payload = [
            'choices' => [[
                'delta' => ['content' => $content],
                'finish_reason' => null,
            ]],
        ];
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
        $emit("data: [DONE]\n\n");
    }

    private function emitError(callable $emit, string $message, string $type, int $code = 0): void
    {
        $emit('data: ' . json_encode([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $code,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }
}
