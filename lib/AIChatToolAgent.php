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

            $modelStarted = microtime(true);
            $this->stream->agentEvent($emit, 'model_request_started', [
                'run_id' => $state->runId,
                'round' => $round,
                'phase' => 'tool_decision',
                'message_count' => count($payload['messages'] ?? []),
                'tool_count' => count($tools),
            ]);
            try {
                $response = $this->model->complete($payload);
                $this->stream->agentEvent($emit, 'model_request_finished', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'phase' => 'tool_decision',
                    'duration_ms' => (int)round((microtime(true) - $modelStarted) * 1000),
                ]);
            } catch (Throwable $e) {
                $reason = trim($e->getMessage());
                $httpCode = (int)$e->getCode();
                $this->stream->agentEvent($emit, 'model_request_failed', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'phase' => 'tool_decision',
                    'duration_ms' => (int)round((microtime(true) - $modelStarted) * 1000),
                    'message' => mb_substr($reason, 0, 220),
                ]);
                if (!$this->shouldFallbackToPlainStream($e)) {
                    $message = $this->upstreamFailureMessage($reason, $httpCode);
                    $this->stream->error($emit, $message, 'upstream_unavailable', $httpCode, [
                        'phase' => 'tool_decision',
                        'http_code' => $httpCode,
                    ]);
                    $this->stream->failRun($emit, $state, 'upstream_unavailable', $message);
                    return;
                }
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
            $this->stream->reasoningContent($emit, $this->extractReasoningContent($assistant));

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
                $finalContextMessage = $this->compactAssistantMessage($assistant);
                $finalContextMessage['content'] = $content;
                $this->stream->conversationContext($emit, [$finalContextMessage], $state);
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->stream->syntheticContent($emit, $content, $state, 'final_answer');
                return;
            }

            $this->stream->assistantThought($emit, $this->visibleThoughtForToolCalls($assistant, $toolCalls), $round, $state);
            $toolLimit = max(1, (int)$this->options['max_tool_calls_per_round']);
            $requestedToolCallCount = count($toolCalls);
            $toolCalls = array_slice($toolCalls, 0, $toolLimit);
            if ($requestedToolCallCount > count($toolCalls)) {
                $this->stream->agentEvent($emit, 'agent_status', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'message' => "模型本轮请求 {$requestedToolCallCount} 个工具，已按每轮上限执行前 " . count($toolCalls) . ' 个。',
                ]);
            }
            $toolCalls = $this->repairMalformedToolArguments($toolCalls, $originalMessages, $state, $emit, $round);
            $assistantToolMessage = $this->compactAssistantMessage($assistant, $toolCalls);
            $messages[] = $assistantToolMessage;
            $toolMessages = [];
            foreach ($this->toolRuntime->executeToolCalls($toolCalls, $state, $emit, $round, 'model_tool_call') as $message) {
                $messages[] = $message;
                $toolMessages[] = $message;
            }
            $this->stream->conversationContext($emit, array_merge([$assistantToolMessage], $toolMessages), $state);
            $checkpointManager->create('tool_batch_complete', $messages, [
                'round' => $round,
                'origin' => 'model_tool_call',
                'requested_tool_calls' => count($toolCalls),
            ]);

            if ($state->stopReason === 'tool_transport_failure') {
                $messages[] = [
                    'role' => 'system',
                    'content' => '内部工具执行端点发生传输故障，本轮请求的工具均未真实执行。禁止改用其他工具或继续重试；请只说明基础设施故障及尚未验证的数据，不要把它误判为财经数据源故障。',
                ];
                $checkpointManager->create('ready_for_final_answer', $messages, [
                    'round' => $round,
                    'stop_reason' => 'tool_transport_failure',
                ]);
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->streamFinal($messages, $emit, $state, 'tool_transport_failure');
                return;
            }

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

            if ($this->shouldFinalizeFundResearch($messages, $originalMessages, $round)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => '当前基金研究已经拿到候选池、基金资料、历史表现以及风格或分红依据。除非仍缺少关键事实，请停止继续搜索或重复查询，直接给出最终研究结论，明确推荐对象、比较依据、适合的投资者和主要不确定性。',
                ];
                $checkpointManager->create('ready_for_final_answer', $messages, [
                    'round' => $round,
                    'stop_reason' => 'fund_research_sufficient',
                ]);
                $this->stream->agentEvent($emit, 'final_answer_started', ['run_id' => $state->runId]);
                $this->streamFinal($messages, $emit, $state, 'fund_research_sufficient');
                return;
            }

            if ($round < $maxRounds) {
                $continuationContent = '工具观察结果已回填。请基于最新观察继续判断下一步：如仍缺少关键事实，请继续调用只读工具；如信息足够，请给出最终研究结论。';
                if ($this->shouldEncourageFundDeepDive($toolCalls, $originalMessages)) {
                    $continuationContent = '基金候选已返回。若用户要求深入研究或给出建议，请优先调用聚合工具：用 fa_get_fund_performance_stats 获取长历史收益与回撤、fa_get_fund_holdings_or_index_exposure 补充风格画像、fa_get_fund_trade_rules 确认可投性，最后用 fa_score_funds 做确定性评分排序；评分完成后即可停止搜索并直接给最终结论。';
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

        $this->stream->agentEvent($emit, 'model_stream_started', [
            'run_id' => $state->runId,
            'phase' => 'plain_stream',
            'message_count' => count($messages),
        ]);
        $finished = false;
        $finalText = '';
        $wrappedEmit = $this->stream->wrapFinalStream($emit, $state, $stopReason, $finished, $finalText);

        try {
            $this->model->stream($this->model->payload($messages, true), $wrappedEmit);
        } catch (Throwable $e) {
            $httpCode = (int)$e->getCode();
            $message = $this->upstreamFailureMessage(trim($e->getMessage()), $httpCode);
            $this->stream->error($emit, $message, 'upstream_stream_error', $httpCode, [
                'phase' => 'plain_stream',
                'http_code' => $httpCode,
            ]);
            $this->stream->failRun($emit, $state, 'upstream_stream_error', $message);
            return;
        }
        if (!$finished) {
            if (trim($finalText) === '') {
                $message = '上游 AI 流式接口未返回任何有效内容。';
                $this->stream->error($emit, $message, 'empty_response');
                $this->stream->failRun($emit, $state, 'empty_response', $message);
                return;
            }
            $this->stream->riskDisclaimerIfMissing($emit, $finalText, $state);
            $this->stream->agentEvent($emit, 'final_answer_finished', [
                'run_id' => $state->runId,
                'chars' => mb_strlen($finalText),
            ]);
            $this->stream->finishRun($emit, $state, $stopReason);
            $emit("data: [DONE]\n\n");
        }
    }

    private function shouldFallbackToPlainStream(Throwable $error): bool
    {
        $code = (int)$error->getCode();
        if (in_array($code, [400, 404, 405, 415, 422], true)) {
            return true;
        }
        return $code === 0 && (bool)preg_match('/(tools?|tool_choice|parallel_tool_calls).{0,48}(unsupported|not supported|invalid|unknown)/iu', $error->getMessage());
    }

    private function upstreamFailureMessage(string $reason, int $httpCode): string
    {
        if ($httpCode === 401 || $httpCode === 403 || stripos($reason, 'auth_unavailable') !== false) {
            return '上游 AI 渠道当前无可用鉴权凭据，请检查渠道账号/令牌状态或稍后重试。' . ($reason !== '' ? ' 详情：' . mb_substr($reason, 0, 260) : '');
        }
        if ($httpCode === 429) {
            return '上游 AI 渠道当前请求过多或额度受限，请稍后重试。' . ($reason !== '' ? ' 详情：' . mb_substr($reason, 0, 260) : '');
        }
        if ($httpCode >= 500) {
            return '上游 AI 服务暂时不可用，请稍后重试。' . ($reason !== '' ? ' 详情：' . mb_substr($reason, 0, 260) : '');
        }
        return $reason !== '' ? $reason : '上游 AI 请求失败，请稍后重试。';
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

        if ($name === 'fa_get_upcoming_fund_dividends' && $this->looksLikeFundDividendScanRequest($latestUser)) {
            return [
                'start_date' => (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
                'days' => $this->requestedFundDividendDays($latestUser),
                'fund_category' => $this->requestedFundDividendCategory($latestUser),
                'min_distribution_ratio' => 0,
                'sort_by' => 'record_date',
                'order' => 'asc',
                'limit' => $this->requestedTopN($latestUser, 20),
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

        if (in_array($name, ['fa_get_index_profile', 'fa_get_fund_dividend_history', 'fa_get_fund_dividend_profile', 'fa_get_fund_dividend_event_market', 'fa_get_fund_documents'], true) && $this->looksLikeFundRequest($latestUser)) {
            $codes = array_slice($this->extractFundCodes($latestUser), 0, 1);
            if (!empty($codes)) {
                if ($name === 'fa_get_index_profile') {
                    return ['code' => $codes[0]];
                }
                if ($name === 'fa_get_fund_dividend_history') {
                    return [
                        'code' => $codes[0],
                        'page' => 1,
                        'page_size' => 100,
                    ];
                }
                if ($name === 'fa_get_fund_dividend_profile') {
                    return [
                        'code' => $codes[0],
                        'event_date' => $this->extractDividendEventDate($latestUser),
                        'limit' => 10,
                        'include_related' => true,
                        'include_announcements' => true,
                        'announcement_limit' => 5,
                    ];
                }
                if ($name === 'fa_get_fund_dividend_event_market') {
                    return [
                        'code' => $codes[0],
                        'event_date' => $this->extractDividendEventDate($latestUser),
                        'before' => 10,
                        'after' => 15,
                        'previous_events' => 1,
                        'include_benchmark' => true,
                    ];
                }
                return [
                    'code' => $codes[0],
                    'page' => 1,
                    'page_size' => 20,
                    'doc_type' => $this->requestedFundDocumentType($latestUser),
                    'include_content' => $this->requestedFundDocumentContent($latestUser),
                    'content_limit' => 6000,
                ];
            }
        }

        if ($name === 'fa_screen_funds' && $this->looksLikeFundRequest($latestUser)) {
            return [
                'theme' => $this->requestedScreenTheme($latestUser),
                'keywords' => null,
                'fund_types' => null,
                'periods' => ['year', 'half_year', 'this_year'],
                'page_size' => 50,
                'max_candidates' => 15,
                'min_scale_yuan' => null,
                'include_unbuyable' => true,
            ];
        }

        if ($name === 'fa_score_funds' && $this->looksLikeFundRequest($latestUser)) {
            $codes = array_slice($this->extractFundCodes($latestUser), 0, 10);
            if (!empty($codes)) {
                return [
                    'codes' => $codes,
                    'objective' => $this->requestedScoreObjective($latestUser),
                    'horizon' => 'long',
                    'risk_preference' => 'medium',
                    'weights' => null,
                    'require_buyable' => false,
                ];
            }
        }

        if ($name === 'fa_get_fund_performance_stats' && $this->looksLikeFundRequest($latestUser)) {
            $codes = array_slice($this->extractFundCodes($latestUser), 0, 10);
            if (!empty($codes)) {
                return [
                    'codes' => $codes,
                    'target_days' => 500,
                    'periods' => ['1m', '3m', '6m', '1y', '3y', 'since_sample'],
                    'use_acc_nav' => true,
                    'include_recent_rows' => 5,
                ];
            }
        }

        if ($name === 'fa_get_fund_trade_rules' && $this->looksLikeFundRequest($latestUser)) {
            $codes = array_slice($this->extractFundCodes($latestUser), 0, 10);
            if (!empty($codes)) {
                return [
                    'codes' => $codes,
                    'include_fee_detail' => true,
                    'include_platform_status' => true,
                ];
            }
        }

        if ($name === 'fa_get_asset_news') {
            $isFund = $this->looksLikeFundRequest($latestUser);
            $codes = $isFund ? $this->extractFundCodes($latestUser) : $this->extractStockCodes($latestUser);
            if (!empty($codes)) {
                return [
                    'asset_type' => $isFund ? 'fund' : 'stock',
                    'code' => $codes[0],
                    'name' => null,
                    'limit' => 20,
                ];
            }
        }

        if ($name === 'fa_get_stock_announcements' && preg_match('/(公告|披露|公司事件|重大事项|年报|季报|业绩预告|问询函)/u', $latestUser)) {
            $codes = $this->extractStockCodes($latestUser);
            $scope = empty($codes) && preg_match('/(全市场|市场|沪深|A股|近期重要)/u', $latestUser) ? 'market' : 'stock';
            return [
                'scope' => $scope,
                'code' => !empty($codes) ? $codes[0] : null,
                'name' => null,
                'market' => 'all',
                'event_type' => 'all',
                'importance' => 'important',
                'date_from' => null,
                'date_to' => null,
                'page' => 1,
                'limit' => $scope === 'market' ? 30 : 20,
            ];
        }

        if ($name === 'fa_get_stock_announcement_detail' && preg_match('/\b(AN\d{18})\b/i', $latestUser, $announcementMatch)) {
            return [
                'announcement_id' => strtoupper($announcementMatch[1]),
                'content_limit' => 12000,
            ];
        }

        if ($name === 'fa_get_market_hot_news' && preg_match('/(新闻|热点|资讯|舆情|催化)/u', $latestUser)) {
            return ['keywords' => null, 'limit' => 30];
        }

        if ($name === 'fa_get_sentiment_snapshot' && preg_match('/(新闻|热点|资讯|舆情|情绪|催化)/u', $latestUser)) {
            $isFund = $this->looksLikeFundRequest($latestUser);
            $codes = $isFund ? $this->extractFundCodes($latestUser) : $this->extractStockCodes($latestUser);
            $assetScope = !empty($codes);
            return [
                'scope' => $assetScope ? 'asset' : 'market',
                'asset_type' => $assetScope ? ($isFund ? 'fund' : 'stock') : null,
                'code' => $assetScope ? $codes[0] : null,
                'name' => null,
                'keywords' => null,
                'limit' => 30,
            ];
        }

        return null;
    }

    private function requestedScreenTheme(string $text): ?string
    {
        if (preg_match('/(红利|高股息|股息|分红)/u', $text)) return 'dividend';
        if (preg_match('/(低波|低波动)/u', $text)) return 'low_volatility';
        if (preg_match('/(宽基|沪深300|中证500|中证1000)/u', $text)) return 'broad_index';
        if (preg_match('/(债券|纯债|债基)/u', $text)) return 'bond';
        if (preg_match('/(QDII|qdii|美股|纳斯达克|海外|港股通)/u', $text)) return 'qdii';
        return null;
    }

    private function requestedScoreObjective(string $text): string
    {
        if (preg_match('/(红利|高股息|股息|分红|派息|收益分配)/u', $text)) return 'dividend_income';
        if (preg_match('/(低费|便宜|费率|指数基金|被动)/u', $text)) return 'low_fee_index';
        if (preg_match('/(低回撤|稳健|回撤小|波动小|防御)/u', $text)) return 'low_drawdown';
        if (preg_match('/(长期|长期持有|长期稳定)/u', $text)) return 'long_term_stable';
        if (preg_match('/(超额|alpha|主动|进攻)/u', $text)) return 'active_alpha';
        return 'balanced';
    }

    private function requestedFundDocumentType(string $text): string
    {
        if (preg_match('/(季报|季度报告|半年报|中期报告|年报|年度报告|定期报告)/u', $text)) return 'periodic_report';
        if (preg_match('/(招募|招募说明书|产品资料概要)/u', $text)) return 'prospectus';
        if (preg_match('/(基金合同|合同|托管协议)/u', $text)) return 'contract';
        if (preg_match('/(分红|派息|收益分配)/u', $text)) return 'dividend';
        return 'all';
    }

    private function requestedFundDocumentContent(string $text): bool
    {
        return (bool)preg_match('/(正文|原文|内容|依据|证据|条款|详细|摘录|摘要)/u', $text);
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

    /**
     * 是否像全市场基金分红扫描请求（未指定单只基金代码）。
     */
    private function looksLikeFundDividendScanRequest(string $text): bool
    {
        if ($this->extractFundCodes($text) !== []) return false;
        return (bool)preg_match('/(基金|ETF|etf|QDII|qdii|LOF|lof|REIT|FOF).{0,12}(分红|派息|收益分配|登记日|除息)/u', $text)
            || (bool)preg_match('/(分红|派息|收益分配).{0,12}(基金|ETF|etf)/u', $text);
    }

    private function requestedFundDividendDays(string $text): int
    {
        if (preg_match('/(未来|接下来|近)?\s*(\d{1,2})\s*(?:天|日)/u', $text, $m)) return max(1, min(60, (int)$m[2]));
        if (preg_match('/(本周|一周|近一周|这周)/u', $text)) return 7;
        if (preg_match('/(本月|近一月|一个月|这月)/u', $text)) return 30;
        if (preg_match('/(近半月|半个月|两周| fortnight)/u', $text)) return 14;
        return 14;
    }

    private function requestedFundDividendCategory(string $text): string
    {
        if (preg_match('/(股票型|股票基金|主动权益)/u', $text)) return 'stock';
        if (preg_match('/(混合型|混合基金)/u', $text)) return 'mixed';
        if (preg_match('/(债券型|债券基金|债基)/u', $text)) return 'bond';
        if (preg_match('/(货币(?:型|基金)?)/u', $text)) return 'money';
        if (preg_match('/(QDII|qdii|海外|港股|美股)/u', $text)) return 'qdii';
        if (preg_match('/(FOF|fof)/u', $text)) return 'fof';
        if (preg_match('/(REIT|基础设施)/u', $text)) return 'reit';
        if (preg_match('/(指数型|指数基金|ETF|etf)/u', $text)) return 'index';
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
            $name = $call['function']['name'] ?? '';
            if (in_array($name, ['fa_get_fund_rank', 'fa_screen_funds'], true)) {
                return true;
            }
        }
        return false;
    }

    private function shouldFinalizeFundResearch(array $messages, array $originalMessages, int $round): bool
    {
        // 动态收敛软下限：评分成功且证据齐备即可收敛，避免强制拖到第 4 轮浪费 LLM 决策预算
        if ($round < 2) {
            return false;
        }
        $latestUser = $this->latestUserContent($originalMessages);
        if (!$this->looksLikeFundRequest($latestUser)) {
            return false;
        }

        $toolStats = $this->successfulToolStats($messages);
        $wantsRecommendation = preg_match('/(推荐|建议|最好|最佳|挑选|筛选|选出|值得|优选|对比|比较)/u', $latestUser);

        // 评分成功后即可收敛：推荐类问题必须先评分再收敛
        if (($toolStats['fa_score_funds'] ?? 0) > 0) {
            if (!$wantsRecommendation) {
                return true;
            }
            // 推荐类问题：评分后若已有候选池+表现依据即可收敛
            $hasCandidates = ($toolStats['fa_screen_funds'] ?? 0) > 0 || ($toolStats['fa_get_fund_rank'] ?? 0) > 0 || ($toolStats['fa_search_funds'] ?? 0) > 0;
            $hasPerformance = ($toolStats['fa_get_fund_performance_stats'] ?? 0) > 0 || ($toolStats['fa_get_fund_history'] ?? 0) > 0;
            return $hasCandidates && $hasPerformance;
        }

        // 未评分路径：保持原有“候选+资料+表现+风格”齐备才收敛
        $hasCandidates = ($toolStats['fa_get_fund_rank'] ?? 0) > 0 || ($toolStats['fa_search_funds'] ?? 0) > 0 || ($toolStats['fa_screen_funds'] ?? 0) > 0;
        $hasInfo = ($toolStats['fa_get_fund_info'] ?? 0) > 0;
        $hasPerformance = ($toolStats['fa_get_fund_history'] ?? 0) > 0 || ($toolStats['fa_get_fund_estimate'] ?? 0) > 0 || ($toolStats['fa_get_fund_performance_stats'] ?? 0) > 0;
        $hasStyleOrDividend = ($toolStats['fa_get_index_profile'] ?? 0) > 0 || ($toolStats['fa_get_fund_dividend_history'] ?? 0) > 0 || ($toolStats['fa_get_fund_dividend_profile'] ?? 0) > 0 || ($toolStats['fa_get_fund_dividend_event_market'] ?? 0) > 0 || ($toolStats['fa_get_fund_holdings_or_index_exposure'] ?? 0) > 0;

        // 推荐类问题未评分时不轻易收敛
        if ($wantsRecommendation && ($toolStats['fa_score_funds'] ?? 0) === 0) {
            return false;
        }

        return $hasCandidates && $hasInfo && $hasPerformance && $hasStyleOrDividend;
    }

    private function successfulToolStats(array $messages): array
    {
        $stats = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'tool') {
                continue;
            }
            $decoded = json_decode((string)($message['content'] ?? ''), true);
            if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
                continue;
            }
            $name = (string)($message['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $stats[$name] = (int)($stats[$name] ?? 0) + 1;
        }
        return $stats;
    }

    private function hasUsefulResearchToolResult(array $messages): bool
    {
        $useful = [
            'fa_get_stock_quote',
            'fa_get_stock_kline',
            'fa_get_stock_flow',
            'fa_get_stock_announcements',
            'fa_get_stock_announcement_detail',
            'fa_calculate_kline_indicators',
            'fa_get_fund_info',
            'fa_get_fund_estimate',
            'fa_get_fund_history',
            'fa_get_fund_rank',
            'fa_get_index_profile',
            'fa_get_fund_dividend_history',
            'fa_get_fund_dividend_profile',
            'fa_get_fund_dividend_event_market',
            'fa_get_upcoming_fund_dividends',
            'fa_get_fund_documents',
            'fa_screen_funds',
            'fa_get_fund_performance_stats',
            'fa_score_funds',
            'fa_get_fund_trade_rules',
            'fa_get_fund_holdings_or_index_exposure',
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
        if ($this->looksLikeStockAnnouncementRequest($text)) {
            return true;
        }
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
        return (bool)preg_match('/(基金|净值|估值|基金经理|基金公司|申购|赎回|同类排行|同类排名|基金排行|基金排名|基金信息|基金资料|开放式基金|ETF|etf|QDII|qdii|红利型|红利策略|分红|派息|收益分配|跟踪指数|业绩基准|招募说明书|基金合同|产品资料概要|季报|年报)/u', $text);
    }

    private function looksLikeStockAnnouncementRequest(string $text): bool
    {
        if (preg_match('/(基金|ETF|etf|QDII|qdii).{0,10}(公告|季报|年报|披露)|(?:公告|季报|年报|披露).{0,10}(基金|ETF|etf)/u', $text)) {
            return false;
        }
        return (bool)preg_match('/(股票|个股|上市公司|公司事件|重大事项|公告|披露|问询函|业绩预告)/u', $text)
            && ((bool)preg_match('/\b(?:(?:sh|sz|bj|SH|SZ|BJ)\d{6}|\d{6}(?:\.(?:XSHG|XSHE|SH|SZ|BJ))?)\b/u', $text)
                || (bool)preg_match('/(股票|个股|上市公司|全市场|A股|沪深|公司事件)/u', $text));
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

    private function extractDividendEventDate(string $text): ?string
    {
        if (preg_match('/(?:除息日|除权除息日|事件日期)\s*[：:]?\s*(\d{4}-\d{2}-\d{2})/u', $text, $match)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new DateTimeZone('Asia/Shanghai'));
            if ($date && $date->format('Y-m-d') === $match[1]) return $match[1];
        }
        return null;
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
                ? '你可以使用服务端提供的只读工具查询行情、K线、资金流、股票公告与公司事件、股票分红日历、基金、新闻舆情、雪球热度、市场宽度和确定性技术指标。'
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
                '涉及单只股票/基金的最新新闻、媒体报道、事件催化或舆情时，调用 fa_get_asset_news 获取标题事实，并调用 fa_get_sentiment_snapshot 获取可解释的标题情绪弱信号；涉及大盘、板块或主题新闻热点时，调用 fa_get_market_hot_news，并按需调用 fa_get_sentiment_snapshot。新闻搜索不是官方公告源，标题情绪不等于事实、全网舆情或交易信号，回答必须说明样本量、时间和相关性缺口。',
                '涉及上市公司公告、披露文件、业绩预告、问询函或公司事件时，调用 fa_get_stock_announcements 获取公告索引和确定性事件分类；需要解释金额、比例、日期、条件或风险时，必须再调用 fa_get_stock_announcement_detail 读取正文。公告重要性只用于降噪，不等于利好或利空；公告不得进入新闻标题情绪计算。基金公告仍使用 fa_get_fund_documents。',
                '涉及股票分红日历、临近分红、股权登记日、除权除息日、抢息、抢分红或本次现金股息率时，必须先查询股票分红工具：全市场或日期窗口扫描使用 fa_get_upcoming_dividends；已明确单只股票时优先使用 fa_get_stock_dividend_profile，无需先重复扫描全市场；不要用雪球年度股息率替代实施事件。',
                '研判单只分红候选时应调用 fa_get_stock_dividend_profile 核查历史分红，再由你按问题需要从行情、技术指标、资金流和市场宽度中最多选择 3 个关键工具；档案已有有效价格时不要重复查询行情，用户未询问大盘环境时不必调用市场宽度；相互独立的查询应尽量在同一轮并行发出，避免无意义的串行轮次和过大的工具上下文；本次事件现金率不是年化收益，必须说明除息价格调整、税费和价格波动风险。',
                '基金筛选/推荐/挑选类问题（如“帮我挑几只红利型基金”“最好的XX基金”）且未指定代码时，必须优先调用 fa_screen_funds 召回候选池，不要只用 fa_search_funds 单关键词搜索；红利主题用 theme=dividend。',
                '涉及基金历史表现、回撤、波动、长期收益、胜率时，必须优先调用 fa_get_fund_performance_stats（自动分页拉取长历史并计算收益/回撤/波动），少用裸 fa_get_fund_history。',
                '涉及基金申购、赎回、限购、费率、是否可买时，必须调用 fa_get_fund_trade_rules；无法确认限购金额时说明需以公告/平台为准，不要编造数字。',
                '涉及基金持仓、行业暴露、指数/因子暴露、风格画像深化时，必须调用 fa_get_fund_holdings_or_index_exposure；区分实际持仓事实与从名称/基准推断的风格标签，无持仓数据时不要伪造行业权重。',
                '给出基金推荐或排序前，必须调用 fa_score_funds 做确定性多维评分排序（可复现），最终回答要展示评分维度或排序依据；未评分前不要直接给推荐排序。',
                '多轮研究或存在工具失败时，最终回答前可调用 fa_research_state_summary 汇总已查字段、失败项和下一步建议；最终回答必须说明候选池召回来源、评分依据和数据缺口。',
                '涉及基金排行、今日基金涨幅、基金最新信息或未指定代码的基金研究时，必须优先调用 fa_get_fund_rank；今日/涨幅问题使用 period=day，再按候选调用基金资料和估值工具。',
                '涉及基金风格、是否红利型/指数型、跟踪指数、业绩基准或投资策略依据时，必须优先调用 fa_get_index_profile。',
                '涉及基金分红、派息、收益分配、未来日期、本月事件、会不会分红或公告核实时：全市场、本周、本月或未来日期的基金分红问题优先调用 fa_get_upcoming_fund_dividends 做全市场召回、排序与风险摘要，不在同一请求中自动深挖多只基金；已明确单只基金事件时必须同时调用 fa_get_fund_dividend_profile 与 fa_get_fund_dividend_event_market，除此之外最多再选 2 个工具；只有裸历史列表问题才优先用 fa_get_fund_dividend_history。若聚合工具已有同日官方净值，不再调用盘中估值。只有拿到实际基准序列且样本对足够时才能评价跟踪误差；只有成交额、换手率等行情证据才能评价流动性；is_buy 只表示平台申购状态，不得解释为仅限二级市场。理论除息值与实际除息日净值必须分开表述；登记日收盘后不得再提示“收盘前买入”。必须区分查询基金对持有人的直接分红与目标 ETF 进入基金资产的分红；未完成公告检查不得声称“没有公告”；不得把分配比例称为年化收益，不推测基金红利税，只说明未覆盖的佣金、价差和政策数据缺口。',
                '涉及基金合同、招募说明书、产品资料概要、季报/年报、公告或需要文档证据时，必须调用 fa_get_fund_documents；如果正文/PDF 解析不可用，最终回答要说明数据缺口。',
                '基金研究中的候选深挖应控制在少量代表性基金，优先选择不超过 3 只；避免为了穷举而重复搜索同义词、重复查多期排行或重复查相同类型资料。聚合工具（fa_screen_funds/fa_get_fund_performance_stats/fa_score_funds）应优先于模型多次调用裸工具。',
                '当已经拿到候选池、基金资料、历史表现、风格或分红依据并完成评分后，应停止继续搜索并直接给出结论；不要为了“更完整”而无止境追加工具调用。',
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

        $this->stream->agentEvent($emit, 'model_stream_started', [
            'run_id' => $state->runId,
            'phase' => 'final_answer',
            'message_count' => count($payload['messages'] ?? []),
        ]);
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
        if ($this->model->isMiMoThinkingModel()) {
            return $this->messagesForMiMoFinalStream($messages);
        }

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

    private function messagesForMiMoFinalStream(array $messages): array
    {
        $final = [];
        $guardrailPolicy = new AIAgentGuardrailPolicy();

        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            $role = (string)($message['role'] ?? '');
            if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) continue;

            $clean = [
                'role' => $role,
                'content' => $message['content'] ?? ($role === 'assistant' ? null : ''),
            ];
            if ($role === 'assistant') {
                if (array_key_exists('reasoning_content', $message)) {
                    $clean['reasoning_content'] = (string)$message['reasoning_content'];
                }
                if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                    $clean['tool_calls'] = $message['tool_calls'];
                }
            } elseif ($role === 'tool') {
                $clean['tool_call_id'] = (string)($message['tool_call_id'] ?? '');
                if (isset($message['name'])) {
                    $clean['name'] = (string)$message['name'];
                }
            }
            $final[] = $clean;
        }

        $final[] = [
            'role' => 'system',
            'content' => '最终输出必须是面向用户的自然语言研究结论；禁止继续调用工具，也禁止输出伪工具调用标签，例如 <function=...>、</function>、<parameter=...>。',
        ];
        $final[] = $guardrailPolicy->finalSystemMessage();
        return $final;
    }

    private function extractAssistantMessage(array $response): ?array
    {
        $message = $response['choices'][0]['message'] ?? null;
        return is_array($message) ? $message : null;
    }

    private function extractReasoningContent(array $assistant): string
    {
        foreach (['reasoning_content', 'reasoning', 'thinking'] as $key) {
            $value = $assistant[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return '';
    }

    private function stripPseudoToolMarkup(string $content): string
    {
        if ($content === '' || (
            strpos($content, '<function=') === false
            && strpos($content, '<tool_call') === false
            && strpos($content, '<parameter=') === false
        )) {
            return $content;
        }

        $content = preg_replace('/<tool_call\b[^>]*>\s*<function=[^>]*>.*?<\/function>\s*<\/tool_call>/su', '', $content);
        $content = preg_replace('/<function=[^>]*>.*?(?:<\/function>|\n\s*\n|$)/su', '', (string)$content);
        $content = preg_replace('/<\/?parameter(?:=[^>]*)?>[^\n]*/u', '', (string)$content);
        $content = preg_replace('/<\/?tool_call\b[^>]*>/u', '', (string)$content);
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

        return $this->extractPseudoToolCalls((string)($assistant['content'] ?? ''));
    }

    private function extractPseudoToolCalls(string $content): array
    {
        if ($content === '' || strpos($content, '<function=') === false) {
            return [];
        }

        preg_match_all('/<function=([A-Za-z0-9_:-]+)\s*>(.*?)<\/function>/su', $content, $matches, PREG_SET_ORDER);
        $toolCalls = [];
        foreach ($matches as $match) {
            $name = (string)($match[1] ?? '');
            if ($name === '' || !AIToolRegistry::has($name)) {
                continue;
            }
            $args = $this->parsePseudoToolArguments((string)($match[2] ?? ''));
            $toolCalls[] = [
                'id' => uniqid('pseudo_call_', true),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ];
        }
        return $toolCalls;
    }

    private function parsePseudoToolArguments(string $body): array
    {
        preg_match_all('/<parameter=([A-Za-z0-9_:-]+)\s*>(.*?)<\/parameter>/su', $body, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            $decoded = json_decode(trim($body), true);
            return is_array($decoded) ? $decoded : [];
        }

        $args = [];
        foreach ($matches as $match) {
            $key = (string)($match[1] ?? '');
            if ($key === '') {
                continue;
            }
            $args[$key] = $this->decodePseudoToolArgument((string)($match[2] ?? ''));
        }
        return $args;
    }

    private function decodePseudoToolArgument(string $value)
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (strcasecmp($value, 'true') === 0) return true;
        if (strcasecmp($value, 'false') === 0) return false;
        if (strcasecmp($value, 'null') === 0) return null;

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $value;
    }

    private function compactAssistantMessage(array $assistant, ?array $toolCalls = null): array
    {
        $content = $assistant['content'] ?? null;
        if ($toolCalls !== null && is_string($content)) {
            $content = $this->stripPseudoToolMarkup($content);
            if ($content === '') {
                $content = null;
            }
        }
        $message = [
            'role' => 'assistant',
            'content' => $content,
        ];
        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        } elseif (isset($assistant['tool_calls'])) {
            $message['tool_calls'] = $assistant['tool_calls'];
        }
        if (array_key_exists('reasoning_content', $assistant)) {
            // MiMo 要求逐轮原样回传；即使为空字符串也不能擅自删除该字段。
            $message['reasoning_content'] = is_string($assistant['reasoning_content'])
                ? $assistant['reasoning_content']
                : '';
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
            'fa_get_asset_news' => '标的最新新闻',
            'fa_get_market_hot_news' => '市场热点新闻',
            'fa_get_sentiment_snapshot' => '新闻标题情绪快照',
            'fa_get_stock_announcements' => '股票公告与公司事件',
            'fa_get_stock_announcement_detail' => '股票公告正文',
            'fa_get_upcoming_dividends' => '临近分红候选',
            'fa_get_stock_dividend_profile' => '个股分红历史',
            'fa_get_xueqiu_hot_stock' => '雪球热股',
            'fa_run_xueqiu_screener' => '雪球选股',
            'fa_get_xueqiu_feed' => '雪球动态',
            'fa_search_funds' => '基金搜索结果',
            'fa_get_fund_info' => '基金资料',
            'fa_get_fund_estimate' => '基金估值',
            'fa_get_fund_history' => '基金历史净值',
            'fa_get_fund_rank' => '基金排行',
            'fa_get_index_profile' => '基金指数画像',
            'fa_get_fund_dividend_history' => '基金分红历史',
            'fa_get_fund_dividend_profile' => '基金分红档案与关联事件',
            'fa_get_fund_dividend_event_market' => '基金分红事件市场窗口',
            'fa_get_upcoming_fund_dividends' => '全市场基金分红扫描',
            'fa_get_fund_documents' => '基金文档',
            'fa_screen_funds' => '基金候选召回',
            'fa_get_fund_performance_stats' => '基金长历史统计',
            'fa_score_funds' => '基金确定性评分',
            'fa_get_fund_trade_rules' => '基金交易规则',
            'fa_get_fund_holdings_or_index_exposure' => '基金风格暴露',
            'fa_research_state_summary' => '研究状态总结',
            'fa_calculate_kline_indicators' => '技术指标',
            'fa_compare_candidates' => '候选排序',
        ];
        return $map[$name] ?? $fallback;
    }

}
