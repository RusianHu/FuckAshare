<?php
/**
 * AIAgentStreamEmitter — SSE/event helpers for the AI agent.
 */

require_once __DIR__ . '/AIToolRegistry.php';
require_once __DIR__ . '/AIAgentState.php';
require_once __DIR__ . '/AIAgentTraceRecorder.php';
require_once __DIR__ . '/AIAgentGuardrailPolicy.php';

class AIAgentStreamEmitter
{
    /** @var array */
    private $options;

    /** @var AIAgentTraceRecorder|null */
    private $traceRecorder = null;

    /** @var AIAgentGuardrailPolicy|null */
    private $guardrailPolicy = null;

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function setTraceRecorder(?AIAgentTraceRecorder $traceRecorder): void
    {
        $this->traceRecorder = $traceRecorder;
    }

    public function setGuardrailPolicy(?AIAgentGuardrailPolicy $guardrailPolicy): void
    {
        $this->guardrailPolicy = $guardrailPolicy;
    }

    public function agentEvent(callable $emit, string $type, array $payload): void
    {
        if ($this->traceRecorder !== null) {
            $this->traceRecorder->record($type, $payload);
        }
        if (empty($this->options['emit_agent_events'])) return;
        $payload['type'] = $type;
        $emit("event: {$type}\n");
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
        if ($this->traceRecorder !== null && in_array($type, ['run_finished', 'run_failed'], true)) {
            $this->traceRecorder->flush();
        }
    }

    public function toolStatus(callable $emit, int $round, string $name, array $args, string $origin = 'model_tool_call'): void
    {
        if (empty($this->options['expose_tool_trace'])) return;
        $labels = AIToolRegistry::descriptions();
        $title = 'AI 模型正在调用工具';
        $payload = [
            'type' => 'tool_status',
            'round' => $round,
            'tool' => $name,
            'origin' => $origin,
            'trace_title' => $title,
            'message' => '模型调用：' . $this->toolStatusText($name),
            'description' => $labels[$name] ?? '',
            'args_summary' => $this->summarizeArgs($args),
        ];
        $emit("event: tool_status\n");
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }

    public function fallbackStatus(callable $emit, string $message): void
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

    public function assistantThought(callable $emit, string $message, int $round, ?AIAgentState $state = null): void
    {
        $message = trim($this->stripPseudoToolMarkup($message));
        if ($message === '') return;
        $payload = [
            'type' => 'assistant_thought',
            'round' => $round,
            'message' => $message,
        ];
        if ($state !== null) {
            $payload['run_id'] = $state->runId;
        }
        if ($this->traceRecorder !== null) {
            $this->traceRecorder->record('assistant_thought', $payload);
        }
        $emit("event: assistant_thought\n");
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }

    public function finishRun(callable $emit, AIAgentState $state, string $stopReason): void
    {
        $state->stopReason = $stopReason;
        $this->agentEvent($emit, 'run_finished', [
            'run_id' => $state->runId,
            'stop_reason' => $stopReason,
            'rounds' => $state->round,
            'tool_calls' => $state->toolCalls,
            'elapsed_ms' => $state->elapsedMs(),
        ]);
    }

    public function failRun(callable $emit, AIAgentState $state, string $stopReason, string $message): void
    {
        $state->stopReason = $stopReason;
        $this->agentEvent($emit, 'run_failed', [
            'run_id' => $state->runId,
            'stop_reason' => $stopReason,
            'message' => $message,
            'rounds' => $state->round,
            'tool_calls' => $state->toolCalls,
            'elapsed_ms' => $state->elapsedMs(),
        ]);
        $emit("data: [DONE]\n\n");
    }

    public function error(callable $emit, string $message, string $type, int $code = 0): void
    {
        $emit('data: ' . json_encode([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $code,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }

    public function syntheticContent(callable $emit, string $content, ?AIAgentState $state = null, string $stopReason = 'final_answer'): void
    {
        $content = $this->stripPseudoToolMarkup($content);
        if ($content === '') {
            $this->error($emit, '服务器返回空响应，请稍后重试。', 'empty_response');
            if ($state !== null) {
                $this->failRun($emit, $state, 'empty_response', '服务器返回空响应，请稍后重试。');
                return;
            }
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
        $this->riskDisclaimerIfMissing($emit, $content, $state);
        if ($state !== null) {
            $this->agentEvent($emit, 'final_answer_finished', [
                'run_id' => $state->runId,
                'chars' => mb_strlen($content),
            ]);
            $this->finishRun($emit, $state, $stopReason);
        }
        $emit("data: [DONE]\n\n");
    }

    public function wrapFinalStream(callable $emit, AIAgentState $state, string $stopReason, &$finished = null, &$finalTextOut = null): callable
    {
        $finished = false;
        $finalTextOut = '';
        return function (string $data) use ($emit, $state, $stopReason, &$finished, &$finalTextOut): void {
            if (!$finished && strpos($data, 'data: [DONE]') !== false) {
                $parts = explode('data: [DONE]', $data, 2);
                $finalTextOut .= $this->extractDeltaContent($parts[0]);
                if ($parts[0] !== '') {
                    $emit($this->sanitizeAssistantChunk($parts[0]));
                }
                $this->riskDisclaimerIfMissing($emit, $finalTextOut, $state);
                $this->agentEvent($emit, 'final_answer_finished', [
                    'run_id' => $state->runId,
                    'chars' => mb_strlen($finalTextOut),
                ]);
                $this->finishRun($emit, $state, $stopReason);
                $emit('data: [DONE]' . ($parts[1] ?? ''));
                $finished = true;
                return;
            }
            $finalTextOut .= $this->extractDeltaContent($data);
            $emit($this->sanitizeAssistantChunk($data));
        };
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

    public function sanitizeAssistantChunk(string $data): string
    {
        if (empty($this->options['suppress_reasoning_content']) || $data === '') {
            return $data;
        }

        $lines = preg_split('/(\r?\n)/', $data, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($lines)) return $data;

        $out = '';
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (strpos(trim($line), 'data:') !== 0) {
                $out .= $line;
                continue;
            }

            $prefixLength = strpos($line, 'data:');
            $prefix = substr($line, 0, $prefixLength + 5);
            $json = trim(substr($line, $prefixLength + 5));
            if ($json === '' || $json === '[DONE]') {
                $out .= $line;
                continue;
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $out .= $line;
                continue;
            }

            if (isset($decoded['choices'][0]['delta']['reasoning_content'])) {
                unset($decoded['choices'][0]['delta']['reasoning_content']);
                if (empty($decoded['choices'][0]['delta']) && !isset($decoded['choices'][0]['finish_reason'])) {
                    continue;
                }
                $line = $prefix . ' ' . json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $out .= $line;
        }
        return $out;
    }

    public function extractDeltaContent(string $data): string
    {
        if ($data === '') return '';
        $content = '';
        foreach (preg_split('/\r?\n/', $data) as $line) {
            $line = trim($line);
            if (strpos($line, 'data:') !== 0) continue;
            $json = trim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') continue;
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) continue;
            $delta = $decoded['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta) && $delta !== '') {
                $content .= $delta;
            }
        }
        return $content;
    }

    public function riskDisclaimerIfMissing(callable $emit, string $content, ?AIAgentState $state = null): void
    {
        if ($this->guardrailPolicy !== null) {
            $review = $this->guardrailPolicy->reviewFinalText($content);
            $append = (string)($review['append_text'] ?? '');
            if ($append !== '') {
                if ($state !== null) {
                    $this->agentEvent($emit, 'guardrail_applied', [
                        'run_id' => $state->runId,
                        'violations' => $review['violations'] ?? [],
                    ]);
                }
                $payload = [
                    'choices' => [[
                        'delta' => ['content' => $append],
                        'finish_reason' => null,
                    ]],
                ];
                $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
                return;
            }
        }
        if (strpos($content, '不构成投资建议') !== false) return;
        $payload = [
            'choices' => [[
                'delta' => ['content' => "\n\n内容仅供研究参考，不构成投资建议。"],
                'finish_reason' => null,
            ]],
        ];
        $emit('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n");
    }

    public function summarizeArgs(array $args): array
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

    public function toolOutputSummary(array $decoded): array
    {
        $summary = [
            'success' => ($decoded['success'] ?? false) === true,
            'source' => $decoded['source'] ?? null,
            'action' => $decoded['action'] ?? null,
        ];
        if (isset($decoded['code'])) {
            $summary['code'] = $decoded['code'];
        }
        $data = $decoded['data'] ?? null;
        if (is_array($data)) {
            $summary['rows'] = count($data);
        } elseif ($data !== null) {
            $summary['data_type'] = gettype($data);
        }
        return $summary;
    }

    private function toolStatusText(string $name): string
    {
        $map = [
            'fa_get_stock_quote' => '查询实时行情',
            'fa_get_stock_kline' => '获取K线数据',
            'fa_get_stock_flow' => '获取个股资金流',
            'fa_get_sector_flow' => '获取板块资金流',
            'fa_get_hot_stocks' => '查询资金热榜',
            'fa_get_market_breadth' => '获取市场宽度',
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
}
