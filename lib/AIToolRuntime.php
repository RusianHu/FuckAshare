<?php
/**
 * AIToolRuntime — tool-call decoding, execution, deduplication, and events.
 */

require_once __DIR__ . '/AIToolExecutor.php';
require_once __DIR__ . '/AIAgentState.php';
require_once __DIR__ . '/AIAgentStreamEmitter.php';

class AIToolRuntime
{
    /** @var AIToolExecutor */
    private $executor;

    /** @var AIAgentStreamEmitter */
    private $stream;

    /** @var array */
    private $options;

    public function __construct(AIToolExecutor $executor, AIAgentStreamEmitter $stream, array $options)
    {
        $this->executor = $executor;
        $this->stream = $stream;
        $this->options = $options;
    }

    public function executeToolCalls(array $toolCalls, AIAgentState $state, callable $emit, int $round, string $origin): array
    {
        $messages = [];
        foreach ($toolCalls as $toolCall) {
            if ($state->toolCalls >= (int)$this->options['max_tool_calls_total']) {
                $state->stopReason = 'max_tool_calls';
                $this->stream->agentEvent($emit, 'agent_status', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'message' => '工具调用次数达到上限，停止继续调用工具。',
                    'stop_reason' => 'max_tool_calls',
                ]);
                break;
            }

            $started = microtime(true);
            $name = (string)($toolCall['function']['name'] ?? '');
            $callId = (string)($toolCall['id'] ?? uniqid('call_', true));
            $argsJson = (string)($toolCall['function']['arguments'] ?? '{}');
            $args = json_decode($argsJson, true);
            if (!is_array($args)) {
                $args = [];
                $this->stream->toolStatus($emit, $round, $name, ['invalid_arguments_json' => $argsJson], $origin);
                $this->stream->agentEvent($emit, 'tool_call_started', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'tool_call_id' => $callId,
                    'tool' => $name,
                    'origin' => $origin,
                    'args_summary' => ['invalid_arguments_json' => $argsJson],
                ]);
                $result = json_encode([
                    'success' => false,
                    'source' => 'ai_tool',
                    'action' => $name,
                    'code' => 'invalid_arguments_json',
                    'message' => '工具参数不是有效 JSON',
                    'meta' => ['updated_at' => date('c')],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $requiredKeys = $this->requiredSchemaKeys($name);
                $missingKeys = array_values(array_diff($requiredKeys, array_keys($args)));
                foreach ($missingKeys as $key) {
                    $args[$key] = null;
                }

                $this->stream->toolStatus($emit, $round, $name, $args, $origin);
                $this->stream->agentEvent($emit, 'tool_call_started', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'tool_call_id' => $callId,
                    'tool' => $name,
                    'origin' => $origin,
                    'args_summary' => $this->stream->summarizeArgs($args),
                ]);

                $signature = $name . ':' . md5(json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (isset($state->seenCalls[$signature])) {
                    $result = json_encode([
                        'success' => false,
                        'source' => 'ai_tool',
                        'action' => $name,
                        'code' => 'duplicate_tool_call',
                        'message' => '本次请求中已执行过相同工具和参数，已跳过去重。',
                        'meta' => ['updated_at' => date('c')],
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    $state->seenCalls[$signature] = true;
                    $result = $this->executor->executeForModel($name, $args);
                }
            }

            $state->toolCalls++;
            $decoded = json_decode((string)$result, true);
            $this->stream->agentEvent($emit, 'tool_call_finished', [
                'run_id' => $state->runId,
                'round' => $round,
                'tool_call_id' => $callId,
                'tool' => $name,
                'origin' => $origin,
                'success' => is_array($decoded) ? (($decoded['success'] ?? false) === true) : false,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'output_summary' => $this->stream->toolOutputSummary(is_array($decoded) ? $decoded : []),
            ]);

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'name' => $name,
                'content' => $result,
            ];
        }
        return $messages;
    }

    private function requiredSchemaKeys(string $name): array
    {
        $tools = AIToolRegistry::chatTools();
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? [];
            if (($fn['name'] ?? '') !== $name) {
                continue;
            }
            $required = $fn['parameters']['required'] ?? [];
            return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
        }
        return [];
    }
}

