<?php
/**
 * AIChatToolAgent — OpenAI-compatible Chat Completions tool-call orchestrator.
 */

require_once __DIR__ . '/AIToolRegistry.php';
require_once __DIR__ . '/AIToolExecutor.php';
require_once __DIR__ . '/AIAgentOptions.php';
require_once __DIR__ . '/AIAgentState.php';
require_once __DIR__ . '/AIAgentStreamEmitter.php';
require_once __DIR__ . '/AIAgentProfile.php';
require_once __DIR__ . '/AIAgentTraceRecorder.php';
require_once __DIR__ . '/AIAgentCheckpointManager.php';
require_once __DIR__ . '/AIAgentGuardrailPolicy.php';
require_once __DIR__ . '/AIToolRuntime.php';
require_once __DIR__ . '/AIChatCompletionsAdapter.php';

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

    /** @var AIAgentStreamEmitter */
    private $stream;

    /** @var AIToolRuntime */
    private $toolRuntime;

    /** @var AIChatCompletionsAdapter */
    private $model;

    public function __construct(array $channel, array $options = [], ?AIToolExecutor $executor = null, ?callable $transport = null, ?callable $streamTransport = null)
    {
        $this->channel = $channel;
        $this->options = AIAgentOptions::normalize($options);
        $this->executor = $executor ?: new AIToolExecutor(null, null, (int)$this->options['tool_output_char_limit']);
        $this->transport = $transport;
        $this->streamTransport = $streamTransport;
        $this->stream = new AIAgentStreamEmitter($this->options);
        $this->stream->setGuardrailPolicy(new AIAgentGuardrailPolicy());
        $this->toolRuntime = new AIToolRuntime($this->executor, $this->stream, $this->options);
        $this->model = new AIChatCompletionsAdapter($this->channel, $this->options, $this->stream, $this->transport, $this->streamTransport);
    }

    public function run(array $messages, callable $emit): void
    {
        $state = new AIAgentState();
        $profile = AIAgentProfile::resolve($messages, $this->options);
        $trace = new AIAgentTraceRecorder($state->runId, $this->options);
        $this->stream->setTraceRecorder($trace);
        $checkpointManager = new AIAgentCheckpointManager($state, $this->stream, $emit, $trace);
        $originalMessages = $messages;
        $messages = $this->prepareMessages($messages, $profile);
        $tools = AIToolRegistry::chatTools();
        $maxRounds = max(1, (int)$this->options['max_tool_rounds']);
        $this->stream->agentEvent($emit, 'run_started', [
            'run_id' => $state->runId,
            'profile' => $profile->metadata(),
            'max_rounds' => $maxRounds,
            'max_tool_calls_total' => (int)$this->options['max_tool_calls_total'],
        ]);

        for ($round = 1; $round <= $maxRounds; $round++) {
            $state->round = $round;
            $payload = $this->model->payload($messages, false);
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = (bool)$this->options['parallel_tool_calls'];

            $this->stream->agentEvent($emit, 'agent_status', [
                'run_id' => $state->runId,
                'round' => $round,
                'message' => 'AI 正在分析任务并决定下一步是否调用工具。',
            ]);

            try {
                $response = $this->model->complete($payload);
            } catch (Throwable $e) {
                $reason = trim($e->getMessage());
                $message = '当前上游未能完成工具调用握手，已回退普通流式对话。';
                if ($reason !== '') {
                    $message .= '原因：' . mb_substr($reason, 0, 160);
                }
                $this->stream->fallbackStatus($emit, $message);
                $this->stream->agentEvent($emit, 'agent_status', [
                    'run_id' => $state->runId,
                    'message' => $message,
                ]);
                $this->streamPlain($originalMessages, $emit, $state, 'plain_stream_fallback');
                return;
            }
            $assistant = $this->extractAssistantMessage($response);
            if ($assistant === null) {
                $this->stream->error($emit, '上游 AI 响应格式无效，未返回 assistant message。', 'invalid_upstream_response');
                $this->stream->failRun($emit, $state, 'invalid_upstream_response', '上游 AI 响应格式无效，未返回 assistant message。');
                return;
            }

            $finishReason = $response['choices'][0]['finish_reason'] ?? null;
            if ($finishReason === 'length') {
                $this->stream->agentEvent($emit, 'agent_status', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'message' => '模型输出被截断(finish_reason=length)，工具调用参数可能不完整，跳过本轮工具调用。',
                ]);
                $content = $this->stripPseudoToolMarkup((string)($assistant['content'] ?? ''));
                if ($content !== '') {
                    $this->stream->syntheticContent($emit, $content, $state, 'truncated_fallback');
                    return;
                }
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->streamFinal($messages, $emit, $state, 'truncated_retry');
                return;
            }

            $toolCalls = $this->extractToolCalls($assistant);
            $checkpointManager->create('model_response', $messages, [
                'round' => $round,
                'tool_call_count' => count($toolCalls),
                'assistant_content_chars' => mb_strlen((string)($assistant['content'] ?? '')),
            ]);
            if (empty($toolCalls)) {
                $this->stream->agentEvent($emit, 'assistant_delta', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'content_summary' => mb_substr($this->stripPseudoToolMarkup((string)($assistant['content'] ?? '')), 0, 160),
                ]);
                $content = $this->stripPseudoToolMarkup((string)($assistant['content'] ?? ''));
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->stream->syntheticContent($emit, $content, $state, 'final_answer');
                return;
            }

            $this->stream->assistantThought($emit, $this->visibleThoughtForToolCalls($assistant, $toolCalls), $round, $state);
            $messages[] = $this->compactAssistantMessage($assistant, $toolCalls);
            $toolLimit = max(1, (int)$this->options['max_tool_calls_per_round']);
            $toolCalls = array_slice($toolCalls, 0, $toolLimit);
            $toolCalls = $this->repairMalformedToolArguments($toolCalls, $originalMessages, $state, $emit, $round);
            $toolMessages = [];
            foreach ($this->toolRuntime->executeToolCalls($toolCalls, $state, $emit, $round, 'model_tool_call') as $message) {
                $messages[] = $message;
                $toolMessages[] = $message;
            }
            $checkpointManager->create('tool_batch_complete', $messages, [
                'round' => $round,
                'origin' => 'model_tool_call',
                'requested_tool_calls' => count($toolCalls),
            ]);

            if ($this->toolBatchHasOnlyInvalidArguments($toolMessages)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => '模型工具参数不是有效 JSON，且服务端无法在不改变模型工具意图的前提下安全补齐。请停止请求工具，基于已有上下文直接回答；如无法确认实时数据，明确说明缺少有效工具结果。',
                ];
                $checkpointManager->create('ready_for_final_answer', $messages, [
                    'round' => $round,
                    'stop_reason' => 'invalid_tool_arguments',
                ]);
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->streamFinal($messages, $emit, $state, 'invalid_tool_arguments');
                return;
            }

            if (!$this->hasUsefulResearchToolResult($messages) && $this->shouldContinueAfterToolRound($toolCalls, $originalMessages)) {
                if (empty($state->flags['setup_only_model_retry_done']) && $round < $maxRounds) {
                    $state->flags['setup_only_model_retry_done'] = true;
                    $messages[] = [
                        'role' => 'system',
                        'content' => '当前只完成了代码规范化等准备性工具调用。请观察结果后继续调用行情、指标、资金流或基金估值/历史/排行等必要只读研究工具；如已足够再给最终结论。',
                    ];
                    $this->stream->agentEvent($emit, 'agent_status', [
                        'run_id' => $state->runId,
                        'round' => $round,
                        'message' => '准备性工具结果已回填，继续让 AI 决定下一步研究工具。',
                    ]);
                    continue;
                }
            }

            if ($state->toolCalls >= (int)$this->options['max_tool_calls_total']) {
                $messages[] = [
                    'role' => 'system',
                    'content' => '工具调用总次数已达到预算上限。请基于已经返回的工具数据给出阶段性研究结论，明确说明仍然缺失或不确定的信息。',
                ];
                $checkpointManager->create('ready_for_final_answer', $messages, [
                    'round' => $round,
                    'stop_reason' => 'max_tool_calls',
                ]);
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->streamFinal($messages, $emit, $state, 'max_tool_calls');
                return;
            }

            if ($round < $maxRounds) {
                $continuationContent = '工具观察结果已回填。请基于最新观察继续判断下一步：如仍缺少关键事实，请继续调用只读工具；如信息足够，请给出最终研究结论。';
                if ($this->shouldEncourageFundDeepDive($toolCalls, $originalMessages)) {
                    $continuationContent = '基金排行候选已返回。若用户要求深入研究或给出建议，请从候选中选择少量代表性基金，继续调用基金资料、估值或历史净值等只读工具；如信息已经足够，再给最终结论。';
                }
                $messages[] = [
                    'role' => 'system',
                    'content' => $continuationContent,
                ];
                $this->stream->agentEvent($emit, 'agent_status', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'message' => '工具观察已回填，继续让 AI 决定下一步。',
                ]);
                continue;
            }
        }

        $messages[] = [
            'role' => 'system',
            'content' => '工具调用轮次已达上限。请基于已经返回的工具数据给出阶段性研究结论，明确说明仍然缺失或不确定的信息。',
        ];
        $checkpointManager->create('ready_for_final_answer', $messages, [
            'round' => $state->round,
            'stop_reason' => 'max_rounds',
        ]);
        $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
        $this->streamFinal($messages, $emit, $state, 'max_rounds');
    }

    public function streamPlain(array $messages, callable $emit, ?AIAgentState $state = null, string $stopReason = 'plain_stream'): void
    {
        $profile = AIAgentProfile::resolve($messages, $this->options);
        $messages = $this->prepareMessages($messages, $profile, false);

        if ($state === null) {
            $this->model->stream($this->model->payload($messages, true), function (string $data) use ($emit): void {
                $emit($this->stream->sanitizeAssistantChunk($data));
            });
            return;
        }

        $finished = false;
        $finalText = '';
        $wrappedEmit = $this->stream->wrapFinalStream($emit, $state, $stopReason, $finished, $finalText);

        $this->model->stream($this->model->payload($messages, true), $wrappedEmit);
        if (!$finished) {
            $this->stream->riskDisclaimerIfMissing($emit, $finalText, $state);
            $this->stream->agentEvent($emit, 'final_answer_finished', [
                'run_id' => $state->runId,
                'chars' => mb_strlen($finalText),
            ]);
            $this->stream->finishRun($emit, $state, $stopReason);
            $emit("data: [DONE]\n\n");
        }
    }

    private function prepareMessages(array $messages, ?AIAgentProfile $profile = null, bool $includeToolInstructions = true): array
    {
        $prepared = [];
        $hasSystem = false;
        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            if (($message['role'] ?? '') === 'system' && !$hasSystem) {
                $hasSystem = true;
                $message['content'] = $this->systemPrompt($profile, $includeToolInstructions) . "\n\n" . (string)($message['content'] ?? '');
            } elseif (($message['role'] ?? '') === 'system') {
                $hasSystem = true;
            }
            $prepared[] = $message;
        }
        if (!$hasSystem) {
            array_unshift($prepared, ['role' => 'system', 'content' => $this->systemPrompt($profile, $includeToolInstructions)]);
        }
        return $this->withCurrentTimeAnchor($prepared);
    }

    private function withCurrentTimeAnchor(array $messages): array
    {
        $anchor = $this->currentTimeAnchorMessage();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                array_splice($messages, $i, 0, [$anchor]);
                return $messages;
            }
        }
        $messages[] = $anchor;
        return $messages;
    }

    private function requestedSortField(string $text): string
    {
        if (preg_match('/(涨(?:得|的)?(?:最)?多|涨幅|领涨|涨得最好|上涨(?:最)?多|涨幅榜)/u', $text)) {
            return 'f3';
        }
        if (preg_match('/(成交额|成交金额|放量|成交量)/u', $text)) {
            return 'f6';
        }
        return 'f62';
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

    private function toolBatchHasOnlyInvalidArguments(array $toolMessages): bool
    {
        if (empty($toolMessages)) {
            return false;
        }
        foreach ($toolMessages as $message) {
            $decoded = json_decode((string)($message['content'] ?? ''), true);
            if (!is_array($decoded) || ($decoded['code'] ?? '') !== 'invalid_arguments_json') {
                return false;
            }
        }
        return true;
    }

    private function repairMalformedToolArguments(array $toolCalls, array $originalMessages, AIAgentState $state, callable $emit, int $round): array
    {
        $latestUser = $this->latestUserContent($originalMessages);
        if ($latestUser === '') {
            return $toolCalls;
        }

        foreach ($toolCalls as $index => $call) {
            $name = (string)($call['function']['name'] ?? '');
            $argsJson = (string)($call['function']['arguments'] ?? '{}');
            if (is_array(json_decode($argsJson, true))) {
                continue;
            }

            $repaired = $this->inferArgumentsForRequestedTool($name, $latestUser);
            if ($repaired === null) {
                continue;
            }

            $toolCalls[$index]['function']['arguments'] = json_encode($repaired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $state->flags['repaired_tool_arguments_round'] = $round;
            $this->stream->agentEvent($emit, 'agent_status', [
                'run_id' => $state->runId,
                'round' => $round,
                'message' => '模型工具参数 JSON 不完整，已按同一工具意图补齐必要参数继续执行。',
                'tool' => $name,
            ]);
        }

        return $toolCalls;
    }

    private function inferArgumentsForRequestedTool(string $name, string $latestUser): ?array
    {
        if ($name === 'fa_get_fund_rank' && $this->looksLikeFundRequest($latestUser)) {
            return [
                'type' => $this->requestedFundRankType($latestUser),
                'period' => $this->requestedFundRankPeriod($latestUser),
                'page' => 1,
                'page_size' => $this->requestedFundRankPageSize($latestUser),
            ];
        }

        if ($name === 'fa_get_market_breadth' && $this->looksLikeMarketBreadthRequest($latestUser)) {
            return [
                'scope' => $this->requestedMarketBreadthScope($latestUser),
                'include_limit_stats' => true,
                'include_index_quotes' => true,
            ];
        }

        if ($name === 'fa_get_hot_stocks' && $this->looksLikeMarketScanRequest($latestUser)) {
            return [
                'page' => 1,
                'page_size' => $this->requestedTopN($latestUser, 10),
                'sort' => $this->requestedSortField($latestUser),
                'order' => 1,
            ];
        }

        if ($name === 'fa_get_stock_quote' && $this->looksLikeStockResearchRequest($latestUser)) {
            $codes = array_slice($this->extractStockCodes($latestUser), 0, 5);
            if (!empty($codes)) {
                return [
                    'codes' => $codes,
                    'source' => 'auto',
                    'fallback' => true,
                ];
            }
        }

        if (in_array($name, ['fa_get_fund_info', 'fa_get_fund_estimate'], true) && $this->looksLikeFundRequest($latestUser)) {
            $codes = array_slice($this->extractFundCodes($latestUser), 0, 5);
            if (!empty($codes)) {
                return ['codes' => $codes];
            }
        }

        if ($name === 'fa_get_fund_history' && $this->looksLikeFundRequest($latestUser)) {
            $codes = array_slice($this->extractFundCodes($latestUser), 0, 1);
            if (!empty($codes)) {
                return [
                    'code' => $codes[0],
                    'page' => 1,
                    'page_size' => 40,
                ];
            }
        }

        return null;
    }

    private function requestedTopN(string $text, int $default): int
    {
        if (preg_match('/(?:前|top\s*)(\d{1,2})/iu', $text, $m)) {
            return max(1, min(30, (int)$m[1]));
        }
        if (preg_match('/(\d{1,2})(?:只|个)?(?:股票|标的|候选|基金|产品)/u', $text, $m)) {
            return max(1, min(30, (int)$m[1]));
        }
        return $default;
    }

    private function requestedFundRankPageSize(string $text): int
    {
        return max(5, min(30, $this->requestedTopN($text, 10)));
    }

    private function requestedFundRankPeriod(string $text): string
    {
        if (preg_match('/(今天|今日|日涨|涨(?:得|的)?(?:最)?多|涨幅|领涨|上涨(?:最)?多|最新)/u', $text)) return 'day';
        if (preg_match('/(近一周|一周|本周|周)/u', $text)) return 'week';
        if (preg_match('/(近一月|一个月|1月|月)/u', $text)) return 'month';
        if (preg_match('/(近三月|三个月|3月|季度)/u', $text)) return 'quarter';
        if (preg_match('/(近半年|半年|6月)/u', $text)) return 'half_year';
        if (preg_match('/(今年|本年|年内)/u', $text)) return 'this_year';
        if (preg_match('/(近两年|两年|2年)/u', $text)) return 'two_year';
        if (preg_match('/(近三年|三年|3年)/u', $text)) return 'three_year';
        if (preg_match('/(成立以来|以来)/u', $text)) return 'since';
        return 'year';
    }

    private function requestedFundRankType(string $text): string
    {
        if (preg_match('/(股票型|股票基金|主动权益)/u', $text)) return 'stock';
        if (preg_match('/(混合型|混合基金)/u', $text)) return 'mixed';
        if (preg_match('/(债券型|债券基金|债基)/u', $text)) return 'bond';
        if (preg_match('/(指数型|指数基金|ETF|etf)/u', $text)) return 'index';
        if (preg_match('/(QDII|qdii|海外|港股|美股)/u', $text)) return 'qdii';
        if (preg_match('/(FOF|fof)/u', $text)) return 'fof';
        return 'all';
    }

    private function shouldContinueAfterToolRound(array $toolCalls, array $originalMessages): bool
    {
        $latestUser = $this->latestUserContent($originalMessages);
        if (!$this->looksLikeStockResearchRequest($latestUser) && !$this->looksLikeFundRequest($latestUser) && !$this->looksLikeMarketScanRequest($latestUser) && !$this->looksLikeMarketBreadthRequest($latestUser)) {
            return false;
        }

        if ($this->looksLikeMarketScanRequest($latestUser)) {
            $prefetchSortField = $this->requestedSortField($latestUser);
            foreach ($toolCalls as $call) {
                $name = (string)($call['function']['name'] ?? '');
                $args = json_decode((string)($call['function']['arguments'] ?? '{}'), true);
                if ($name === 'fa_get_hot_stocks' && is_array($args) && (string)($args['sort'] ?? '') === $prefetchSortField) {
                    return false;
                }
            }
            return true;
        }

        if ($this->looksLikeMarketBreadthRequest($latestUser)) {
            foreach ($toolCalls as $call) {
                if (($call['function']['name'] ?? '') === 'fa_get_market_breadth') {
                    return false;
                }
            }
            return true;
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

    private function shouldEncourageFundDeepDive(array $toolCalls, array $originalMessages): bool
    {
        $latestUser = $this->latestUserContent($originalMessages);
        if (!$this->looksLikeFundRequest($latestUser)) {
            return false;
        }
        if (!preg_match('/(深入|研究|分析|评估|建议|推荐|筛选|最好|涨势|涨幅|排行|排名)/u', $latestUser)) {
            return false;
        }
        foreach ($toolCalls as $call) {
            if (($call['function']['name'] ?? '') === 'fa_get_fund_rank') {
                return true;
            }
        }
        return false;
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
            'fa_get_market_breadth',
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
        if ($this->looksLikeFundRequest($text)) {
            return false;
        }
        if (preg_match('/\b(?:sh|sz|SH|SZ)\d{6}\b|\b\d{6}\.(?:XSHG|XSHE)\b/u', $text)) {
            return true;
        }
        return preg_match('/(股票|个股|行情|K线|k线|技术|趋势|资金流|主力|支撑|压力|分析|评估|查询)/u', $text)
            && preg_match('/\b\d{6}\b/u', $text);
    }

    private function looksLikeFundRequest(string $text): bool
    {
        return (bool)preg_match('/(基金|净值|估值|基金经理|基金公司|申购|赎回|同类排行|同类排名|基金排行|基金排名|基金信息|基金资料|开放式基金|ETF|etf|QDII|qdii)/u', $text);
    }

    private function looksLikeMarketBreadthRequest(string $text): bool
    {
        if ($this->looksLikeFundRequest($text)) {
            return false;
        }
        return (bool)preg_match('/(大盘|市场宽度|涨跌家数|上涨家数|下跌家数|平盘家数|涨停|跌停|市场情绪|市场环境|普涨|普跌|赚钱效应|亏钱效应|宽度|advance|decline|breadth)/iu', $text);
    }

    private function requestedMarketBreadthScope(string $text): string
    {
        if (preg_match('/(沪市|上证|上海|科创)/u', $text)) {
            return 'sh';
        }
        if (preg_match('/(深市|深证|深圳|创业板)/u', $text)) {
            return 'sz';
        }
        if (preg_match('/(核心指数|指数概览|主要指数)/u', $text)) {
            return 'core_indices';
        }
        return 'a_share';
    }

    private function looksLikeMarketScanRequest(string $text): bool
    {
        if ($this->looksLikeFundRequest($text)) {
            return false;
        }
        return preg_match('/(资金流入|净流入|主力流入|资金|资金榜|热股|热门股|候选|选股|筛选|前十|前10|top\s*10|排名|排行|涨(?:得|的)?(?:最)?多|涨幅|领涨|上涨(?:最)?多|涨幅榜|大盘|市场宽度|涨跌家数|涨停|跌停|情绪|普涨|普跌)/iu', $text)
            && preg_match('/(股票|个股|标的|市场|板块|综合评估|综合分析|评估|分析|今日|今天|最新|大盘|宽度|情绪)/u', $text);
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

    private function currentTimeAnchorMessage(): array
    {
        $marketNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $serverTz = date_default_timezone_get() ?: 'UTC';
        $parts = [
            '时间锚点：当前北京时间（Asia/Shanghai）为 ' . $marketNow->format('Y-m-d H:i') . '。',
            '回答“今天/昨日/近半年”等相对时间问题时以此为准；如果用户询问现在时间或日期，可以直接回答，不要声称无法获取当前时间。',
            '此时间仅用于日期锚定和交易时段判断；行情、资金流、基金净值、排行和新闻仍必须通过工具或实时数据源查询。',
        ];
        if ($serverTz !== 'Asia/Shanghai') {
            $serverNow = new DateTimeImmutable('now', new DateTimeZone($serverTz));
            $parts[] = '服务器默认时区：' . $serverTz . '，服务器当前时间：' . $serverNow->format('Y-m-d H:i') . '。';
        }
        return [
            'role' => 'system',
            'content' => implode('', $parts),
        ];
    }

    private function systemPrompt(?AIAgentProfile $profile = null, bool $includeToolInstructions = true): string
    {
        $lines = [
            '你是一位专业、谨慎的金融研究助理，服务于 A 股、基金、板块资金和市场热度研究。',
            $includeToolInstructions
                ? '你可以使用服务端提供的只读工具查询行情、K线、资金流、基金、雪球热度、市场宽度和确定性技术指标。'
                : '当前渠道未启用正式工具调用；不要编造实时价格、资金流、基金净值、排行或新闻；如需实时事实，请说明需要通过服务端数据源查询。',
            $includeToolInstructions
                ? '优先用工具获取事实数据；不要编造实时价格、资金流、基金净值、排行或新闻。'
                : '无法确认的实时价格、资金流、基金净值、排行或新闻不要编造；需要时说明必须通过实时数据源验证。',
            '回答时清晰区分：数据事实、基于数据的推断、不确定性和需要继续验证的信息。',
        ];
        if ($includeToolInstructions) {
            $lines = array_merge($lines, [
                '涉及大盘环境、市场情绪、市场宽度、涨跌家数、涨停跌停、普涨普跌或赚钱效应时，必须优先调用 fa_get_market_breadth。',
                '涉及市场扫描、资金流入、今日涨幅排行、热门股票或候选标的时，先调用 fa_get_market_breadth 判断市场环境，再调用 fa_get_hot_stocks；按涨幅排序使用 sort=f3，按主力净流入排序使用 sort=f62。',
                '涉及基金排行、今日基金涨幅、基金最新信息或未指定代码的基金研究时，必须优先调用 fa_get_fund_rank；今日/涨幅问题使用 period=day，再按候选调用基金资料和估值工具。',
                '所有档案下都暴露完整只读工具集；即使当前是基金研究档案，也可以在用户问题需要时调用股票行情、K线、资金流、板块、雪球热度和选股工具来交叉验证或比较。',
                '工具选择只按用户问题和研究需要决定，不按“基金版本/股票版本”裁剪；但不要为了展示能力而调用与问题无关的工具。',
                '在每轮正式调用工具前，assistant content 先用 1-3 句中文简要说明当前判断、接下来要查什么和为什么；随后再通过 tool_calls 调用工具。',
                '只能通过正式 tools/tool_calls 协议调用工具；最终回答阶段不要输出 <function=...>、<parameter=...> 等伪工具标签。',
            ]);
        }
        $lines[] = '不要给出保证收益、确定买卖点或个性化投资承诺。结尾必须提示：内容仅供研究参考，不构成投资建议。';
        if ($profile !== null && $includeToolInstructions) {
            $lines[] = $profile->systemPromptSuffix();
        } elseif ($profile !== null) {
            $lines[] = '当前智能体档案：' . $profile->name() . '（' . $profile->description() . '）。';
        }
        return implode("\n", $lines);
    }

    private function streamFinal(array $messages, callable $emit, ?AIAgentState $state = null, string $stopReason = 'final_answer'): void
    {
        $payload = $this->model->payload($this->messagesForFinalStream($messages), true);
        $payload['tool_choice'] = 'none';
        if ($state === null) {
            $this->model->stream($payload, $emit);
            return;
        }

        $finished = false;
        $finalText = '';
        $wrappedEmit = $this->stream->wrapFinalStream($emit, $state, $stopReason, $finished, $finalText);

        $this->model->stream($payload, $wrappedEmit);
        if (!$finished) {
            $this->stream->riskDisclaimerIfMissing($emit, $finalText, $state);
            $this->stream->agentEvent($emit, 'final_answer_finished', [
                'run_id' => $state->runId,
                'chars' => mb_strlen($finalText),
            ]);
            $this->stream->finishRun($emit, $state, $stopReason);
            $emit("data: [DONE]\n\n");
        }
    }

    private function messagesForFinalStream(array $messages): array
    {
        $final = [];
        $toolResults = [];
        $guardrailPolicy = new AIAgentGuardrailPolicy();

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
                'content' => "以下是本轮已经真实执行的工具调用和工具结果。请基于这些结果回答，不要声称无法访问数据；如果某个工具失败，请说明失败项。不要输出 <function=...>、<parameter=...> 等伪工具标签。\n" .
                    json_encode($toolResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        $final[] = [
            'role' => 'system',
            'content' => '最终输出必须是面向用户的自然语言研究结论；禁止输出伪工具调用标签，例如 <function=...>、</function>、<parameter=...>。',
        ];
        $final[] = $guardrailPolicy->finalSystemMessage();

        return $final;
    }

    private function extractAssistantMessage(array $response): ?array
    {
        $message = $response['choices'][0]['message'] ?? null;
        return is_array($message) ? $message : null;
    }

    private function stripPseudoToolMarkup(string $content): string
    {
        if ($content === '' || strpos($content, '<function=') === false) {
            return $content;
        }

        $content = preg_replace('/<function=[^>]*>.*?(?:<\/function>|\n\s*\n|$)/su', '', $content);
        $content = preg_replace('/<\/?parameter(?:=[^>]*)?>[^\n]*/u', '', (string)$content);
        return trim((string)$content);
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

    private function visibleThoughtForToolCalls(array $assistant, array $toolCalls): string
    {
        $content = $this->stripPseudoToolMarkup((string)($assistant['content'] ?? ''));
        if (trim($content) !== '') {
            return $content;
        }

        $labels = AIToolRegistry::descriptions();
        $names = [];
        foreach ($toolCalls as $call) {
            $name = (string)($call['function']['name'] ?? '');
            if ($name === '') continue;
            $label = $labels[$name] ?? $name;
            $names[] = $this->shortToolPurpose($name, $label);
        }
        $names = array_values(array_unique(array_filter($names)));
        if (empty($names)) {
            return '我需要先获取必要的实时数据，再基于结果继续判断。';
        }
        $summary = implode('、', array_slice($names, 0, 4));
        if (count($names) > 4) {
            $summary .= '等数据';
        }
        return '我会先获取' . $summary . '，再基于工具结果继续判断下一步。';
    }

    private function shortToolPurpose(string $name, string $fallback): string
    {
        $map = [
            'fa_normalize_stock_code' => '股票代码格式',
            'fa_get_stock_quote' => '股票实时行情',
            'fa_get_stock_kline' => '股票K线',
            'fa_get_stock_flow' => '个股资金流',
            'fa_get_sector_flow' => '板块资金流',
            'fa_get_hot_stocks' => '市场热榜',
            'fa_get_market_breadth' => '市场宽度',
            'fa_get_xueqiu_hot_stock' => '雪球热股',
            'fa_run_xueqiu_screener' => '雪球选股',
            'fa_get_xueqiu_feed' => '雪球动态',
            'fa_search_funds' => '基金搜索结果',
            'fa_get_fund_info' => '基金资料',
            'fa_get_fund_estimate' => '基金估值',
            'fa_get_fund_history' => '基金历史净值',
            'fa_get_fund_rank' => '基金排行',
            'fa_calculate_kline_indicators' => '技术指标',
            'fa_compare_candidates' => '候选排序',
        ];
        return $map[$name] ?? $fallback;
    }

}
