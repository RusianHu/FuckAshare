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
        // 内部执行分支：配置了 internal_exec_endpoint + token 时，用 self-HTTP 派发工具。
        // 即使只有 1 个长工具，也让主 SSE 请求能在等待期间继续发送 heartbeat。
        if (!empty($this->options['parallel_tool_calls'])
            && !empty($this->options['internal_exec_endpoint'])
            && !empty($this->options['internal_exec_token'])
            && count($toolCalls) > 0
            && function_exists('curl_multi_init')) {
            $parallel = $this->executeToolCallsParallel($toolCalls, $state, $emit, $round, $origin);
            if ($parallel !== null) {
                return $parallel;
            }
            // 已配置内部执行端点时不切换执行路径，避免掩盖部署/网络故障。
            $state->stopReason = 'tool_transport_failure';
            $this->stream->agentEvent($emit, 'agent_status', [
                'run_id' => $state->runId,
                'round' => $round,
                'message' => '内部工具执行端点初始化失败，本轮已停止；请检查 loopback 执行服务。',
                'stop_reason' => 'tool_transport_failure',
            ]);
            return $this->internalInitFailureMessages($toolCalls, $state, $emit, $round, $origin);
        }

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
                $args = $this->normalizeArgumentsForSchema($name, $args);

                $this->stream->toolStatus($emit, $round, $name, $args, $origin);
                $this->stream->agentEvent($emit, 'tool_call_started', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'tool_call_id' => $callId,
                    'tool' => $name,
                    'origin' => $origin,
                    'args_summary' => $this->stream->summarizeArgs($args),
                ]);

                $signature = $this->toolCallSignature($name, $args);
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
                    $state->seenCalls[$signature] = 'in_flight';
                    $this->executor->setResearchState($state->researchState);
                    $result = $this->executor->executeForModel($name, $args);
                    $state->seenCalls[$signature] = 'completed';
                }
            }

            $state->toolCalls++;
            $decoded = json_decode((string)$result, true);
            $this->recordResearchState($state, $name, $args, is_array($decoded) ? $decoded : []);
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

    /**
     * 并行执行一轮内的多个 toolCall：用 curl_multi 把每个工具派发到本地 ai_tool_exec.php 端点。
     * 串行逻辑的等价并行版：预处理(去重/校验) → 批量发 started 事件 → curl_multi 并发 → 按序回填 + finished 事件。
     * 已配置内部端点时不回退到另一执行路径；传输故障会结构化返回并终止后续工具轮。
     */
    private function executeToolCallsParallel(array $toolCalls, AIAgentState $state, callable $emit, int $round, string $origin): ?array
    {
        $endpoint = (string)($this->options['internal_exec_endpoint'] ?? '');
        $token = (string)($this->options['internal_exec_token'] ?? '');
        if ($endpoint === '' || $token === '') {
            return null;
        }

        $toolLimit = max(1, (int)$this->options['max_tool_calls_per_round']);
        $maxTotal = (int)$this->options['max_tool_calls_total'];
        $toolCalls = array_slice($toolCalls, 0, $toolLimit);

        // 1. 预处理：分类 invalid / duplicate / dispatch，受 max_tool_calls_total 约束
        $plan = [];
        $reachedMax = false;
        foreach ($toolCalls as $toolCall) {
            if ($state->toolCalls + count($plan) >= $maxTotal) {
                $reachedMax = true;
                break;
            }
            $name = (string)($toolCall['function']['name'] ?? '');
            $callId = (string)($toolCall['id'] ?? uniqid('call_', true));
            $argsJson = (string)($toolCall['function']['arguments'] ?? '{}');
            $args = json_decode($argsJson, true);
            $entry = [
                'callId' => $callId,
                'name' => $name,
                'argsJson' => $argsJson,
                'args' => is_array($args) ? $args : [],
                'kind' => 'dispatch',
                'result' => null,
                'started' => microtime(true),
                'signature' => '',
            ];

            if (!is_array($args)) {
                $entry['kind'] = 'invalid';
                $entry['result'] = json_encode([
                    'success' => false, 'source' => 'ai_tool', 'action' => $name,
                    'code' => 'invalid_arguments_json', 'message' => '工具参数不是有效 JSON',
                    'meta' => ['updated_at' => date('c')],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $requiredKeys = $this->requiredSchemaKeys($name);
                $missingKeys = array_values(array_diff($requiredKeys, array_keys($args)));
                foreach ($missingKeys as $key) {
                    $args[$key] = null;
                }
                $args = $this->normalizeArgumentsForSchema($name, $args);
                $entry['args'] = $args;
                $signature = $this->toolCallSignature($name, $args);
                $entry['signature'] = $signature;
                if (isset($state->seenCalls[$signature])) {
                    $entry['kind'] = 'duplicate';
                    $entry['result'] = json_encode([
                        'success' => false, 'source' => 'ai_tool', 'action' => $name,
                        'code' => 'duplicate_tool_call', 'message' => '本次请求中已执行过相同工具和参数，已跳过去重。',
                        'meta' => ['updated_at' => date('c')],
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    $state->seenCalls[$signature] = 'in_flight';
                }
            }
            $plan[] = $entry;
        }

        if ($reachedMax) {
            $state->stopReason = 'max_tool_calls';
            $this->stream->agentEvent($emit, 'agent_status', [
                'run_id' => $state->runId,
                'round' => $round,
                'message' => '工具调用次数达到上限，停止继续调用工具。',
                'stop_reason' => 'max_tool_calls',
            ]);
        }

        // 2. 派发前批量发 tool_call_started + toolStatus（保持前端时序可见）
        foreach ($plan as $p) {
            $statusArgs = $p['kind'] === 'invalid' ? ['invalid_arguments_json' => $p['argsJson']] : $p['args'];
            $summaryArgs = $p['kind'] === 'invalid' ? ['invalid_arguments_json' => $p['argsJson']] : $this->stream->summarizeArgs($p['args']);
            $this->stream->toolStatus($emit, $round, $p['name'], $statusArgs, $origin);
            $this->stream->agentEvent($emit, 'tool_call_started', [
                'run_id' => $state->runId,
                'round' => $round,
                'tool_call_id' => $p['callId'],
                'tool' => $p['name'],
                'origin' => $origin,
                'args_summary' => $summaryArgs,
            ]);
        }

        // 研究状态汇总依赖当前进程内的 AIAgentState，不能派发到无状态的内部 HTTP worker。
        foreach ($plan as $idx => $p) {
            if ($p['kind'] !== 'dispatch' || $p['name'] !== 'fa_research_state_summary') continue;
            $this->executor->setResearchState($state->researchState);
            $plan[$idx]['result'] = $this->executor->executeForModel($p['name'], $p['args']);
            $plan[$idx]['kind'] = 'local';
            if ($p['signature'] !== '') $state->seenCalls[$p['signature']] = 'completed';
        }

        // 3. curl_multi 并发派发 dispatch 项
        $dispatchIndices = [];
        foreach ($plan as $idx => $p) {
            if ($p['kind'] === 'dispatch') {
                $dispatchIndices[] = $idx;
            }
        }

        if (!empty($dispatchIndices)) {
            $mh = curl_multi_init();
            if ($mh === false) {
                return null;
            }
            $handles = [];
            $multiResults = [];
            $dispatchFailures = [];
            $timeout = (int)($this->options['tool_timeout'] ?? 45);
            $endpointHost = strtolower((string)(parse_url($endpoint, PHP_URL_HOST) ?? ''));
            $isLoopbackEndpoint = in_array($endpointHost, ['127.0.0.1', 'localhost', '::1'], true);
            foreach ($dispatchIndices as $idx) {
                $p = $plan[$idx];
                $body = json_encode(['token' => $token, 'tool_name' => $p['name'], 'args' => $p['args']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $ch = curl_init($endpoint);
                if ($ch === false) {
                    continue;
                }
                $headers = ['Content-Type: application/json', 'Accept: application/json'];
                $host = trim((string)($this->options['internal_exec_host'] ?? ''));
                if ($host !== '' && preg_match('#^https?://(?:127\.0\.0\.1|localhost)(?::\d+)?/#i', $endpoint)) {
                    $headers[] = 'Host: ' . $host;
                }
                $curlOptions = [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_NOSIGNAL => true,
                ];
                if ($isLoopbackEndpoint && defined('CURLOPT_NOPROXY')) {
                    // 内部令牌和 loopback 请求绝不能进入 HTTP_PROXY/HTTPS_PROXY。
                    $curlOptions[CURLOPT_NOPROXY] = '*';
                }
                curl_setopt_array($ch, $curlOptions);
                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = $ch;
            }

            $active = null;
            $heartbeatInterval = (int)($this->options['heartbeat_interval'] ?? 0);
            $lastHeartbeatAt = microtime(true);
            do {
                $status = curl_multi_exec($mh, $active);
                if ($status !== CURLM_OK) {
                    break;
                }
                if ($heartbeatInterval > 0 && (microtime(true) - $lastHeartbeatAt) >= $heartbeatInterval) {
                    $this->stream->heartbeat($emit, 'tool_batch');
                    $lastHeartbeatAt = microtime(true);
                }
                if ($active > 0) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($active > 0);

            // curl_multi 的单请求错误码存放在完成消息中；curl_errno($ch) 可能错误地返回 0。
            while ($info = curl_multi_info_read($mh)) {
                $completedIdx = array_search($info['handle'] ?? null, $handles, true);
                if ($completedIdx !== false) {
                    $multiResults[$completedIdx] = (int)($info['result'] ?? CURLE_OK);
                }
            }

            foreach ($dispatchIndices as $idx) {
                if (!isset($handles[$idx])) {
                    // curl_init 失败的项
                    $dispatchFailures[] = [
                        'idx' => $idx,
                        'errno' => defined('CURLE_FAILED_INIT') ? CURLE_FAILED_INIT : 2,
                        'http_code' => 0,
                        'message' => 'curl init failed',
                    ];
                    $plan[$idx]['result'] = json_encode([
                        'success' => false, 'source' => 'ai_tool', 'action' => $plan[$idx]['name'],
                        'code' => 'parallel_init_failed', 'message' => '并行派发 curl 初始化失败',
                        'meta' => ['updated_at' => date('c')],
                    ], JSON_UNESCAPED_UNICODE);
                    continue;
                }
                $ch = $handles[$idx];
                $body = curl_multi_getcontent($ch);
                $errno = (int)($multiResults[$idx] ?? curl_errno($ch));
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $connectTimeMs = (int)round((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000);
                $totalTimeMs = (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($errno !== 0 || $httpCode !== 200 || $body === false || $body === '') {
                    $errText = function_exists('curl_strerror') ? (string)curl_strerror($errno) : '';
                    $dispatchFailures[] = [
                        'idx' => $idx,
                        'errno' => $errno,
                        'http_code' => $httpCode,
                        'message' => $errText,
                    ];
                    $plan[$idx]['result'] = json_encode([
                        'success' => false, 'source' => 'ai_tool', 'action' => $plan[$idx]['name'],
                        'code' => 'parallel_dispatch_failed',
                        'message' => '并行派发失败：' . ($errText !== '' ? $errText . ' ' : '') . "HTTP {$httpCode}",
                        'meta' => [
                            'updated_at' => date('c'),
                            'curl_errno' => $errno,
                            'http_code' => $httpCode,
                            'connect_time_ms' => $connectTimeMs,
                            'duration_ms' => $totalTimeMs,
                        ],
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded) && array_key_exists('success', $decoded)) {
                        $plan[$idx]['result'] = $body;
                    } else {
                        $dispatchFailures[] = [
                            'idx' => $idx,
                            'errno' => 0,
                            'http_code' => $httpCode,
                            'message' => 'invalid internal JSON response',
                        ];
                        $plan[$idx]['result'] = json_encode([
                            'success' => false, 'source' => 'ai_tool', 'action' => $plan[$idx]['name'],
                            'code' => 'parallel_bad_response', 'message' => '并行端点返回非预期 JSON',
                            'meta' => ['updated_at' => date('c')],
                        ], JSON_UNESCAPED_UNICODE);
                    }
                }
            }
            curl_multi_close($mh);

            if (!empty($dispatchFailures) && count($dispatchFailures) === count($dispatchIndices)) {
                $state->stopReason = 'tool_transport_failure';
                $firstFailure = $dispatchFailures[0];
                $this->stream->agentEvent($emit, 'agent_status', [
                    'run_id' => $state->runId,
                    'round' => $round,
                    'message' => '内部工具执行端点不可用，本轮工具均未执行；已停止追加工具调用。',
                    'stop_reason' => 'tool_transport_failure',
                    'curl_errno' => (int)($firstFailure['errno'] ?? 0),
                    'http_code' => (int)($firstFailure['http_code'] ?? 0),
                ]);
            }
        }

        // 4. 按原顺序回填 messages + 发 tool_call_finished + recordResearchState
        $messages = [];
        $batchEnd = microtime(true);
        foreach ($plan as $p) {
            if (in_array($p['kind'], ['dispatch', 'local'], true)) {
                $state->toolCalls++;
            }
            $result = (string)$p['result'];
            $decoded = json_decode($result, true);
            if ($p['kind'] === 'dispatch' && $p['signature'] !== '') {
                $code = is_array($decoded) ? (string)($decoded['code'] ?? '') : '';
                if (in_array($code, ['parallel_dispatch_failed', 'parallel_init_failed', 'parallel_bad_response'], true)) {
                    unset($state->seenCalls[$p['signature']]);
                } else {
                    $state->seenCalls[$p['signature']] = 'completed';
                }
            }
            $this->recordResearchState($state, $p['name'], $p['args'], is_array($decoded) ? $decoded : []);
            $duration = $p['kind'] === 'dispatch' ? (int)round(($batchEnd - $p['started']) * 1000) : 0;
            $this->stream->agentEvent($emit, 'tool_call_finished', [
                'run_id' => $state->runId,
                'round' => $round,
                'tool_call_id' => $p['callId'],
                'tool' => $p['name'],
                'origin' => $origin,
                'success' => is_array($decoded) ? (($decoded['success'] ?? false) === true) : false,
                'duration_ms' => $duration,
                'output_summary' => $this->stream->toolOutputSummary(is_array($decoded) ? $decoded : []),
            ]);
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $p['callId'],
                'name' => $p['name'],
                'content' => $result,
            ];
        }

        return $messages;
    }

    private function internalInitFailureMessages(array $toolCalls, AIAgentState $state, callable $emit, int $round, string $origin): array
    {
        $messages = [];
        $toolLimit = max(1, (int)$this->options['max_tool_calls_per_round']);
        foreach (array_slice($toolCalls, 0, $toolLimit) as $toolCall) {
            $name = (string)($toolCall['function']['name'] ?? '');
            $callId = (string)($toolCall['id'] ?? uniqid('call_', true));
            $args = json_decode((string)($toolCall['function']['arguments'] ?? '{}'), true);
            if (!is_array($args)) $args = [];
            $this->stream->toolStatus($emit, $round, $name, $args, $origin);
            $this->stream->agentEvent($emit, 'tool_call_started', [
                'run_id' => $state->runId,
                'round' => $round,
                'tool_call_id' => $callId,
                'tool' => $name,
                'origin' => $origin,
                'args_summary' => $this->stream->summarizeArgs($args),
            ]);
            $result = json_encode([
                'success' => false,
                'source' => 'ai_tool',
                'action' => $name,
                'code' => 'parallel_init_failed',
                'message' => '内部并行执行器初始化失败，工具未执行',
                'meta' => ['updated_at' => date('c')],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $decoded = json_decode((string)$result, true);
            $this->stream->agentEvent($emit, 'tool_call_finished', [
                'run_id' => $state->runId,
                'round' => $round,
                'tool_call_id' => $callId,
                'tool' => $name,
                'origin' => $origin,
                'success' => false,
                'duration_ms' => 0,
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

    private function toolCallSignature(string $name, array $args): string
    {
        $canonical = $this->canonicalizeValue($args);
        return $name . ':' . md5((string)json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalizeValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = count($value) === 0 || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeValue($item);
        }
        return $value;
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

    /**
     * Normalize common provider deviations without changing tool intent.
     * Some OpenAI-compatible models emit Python-style "None" for nullable
     * fields or numeric values for identifiers declared as strings.
     */
    private function normalizeArgumentsForSchema(string $name, array $args): array
    {
        $definitions = AIToolRegistry::definitions();
        $properties = $definitions[$name]['parameters']['properties'] ?? [];
        if (!is_array($properties)) return $args;

        foreach ($args as $key => $value) {
            $schema = $properties[$key] ?? null;
            if (!is_array($schema)) continue;

            $types = $schema['type'] ?? [];
            $types = is_array($types) ? $types : [$types];
            $nullable = in_array('null', $types, true);
            if ($nullable && is_string($value)) {
                $nullToken = strtolower(trim($value));
                if (in_array($nullToken, ['', 'none', 'null', 'nil', 'n/a'], true)) {
                    $args[$key] = null;
                    continue;
                }
            }

            if (in_array('string', $types, true) && (is_int($value) || is_float($value))) {
                $args[$key] = (string)$value;
                continue;
            }
            if (in_array('integer', $types, true) && is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
                $args[$key] = (int)$value;
                continue;
            }
            if (in_array('number', $types, true) && is_string($value) && is_numeric(trim($value))) {
                $args[$key] = (float)$value;
                continue;
            }
            if (in_array('boolean', $types, true) && is_string($value)) {
                $boolToken = strtolower(trim($value));
                if (in_array($boolToken, ['true', 'false'], true)) {
                    $args[$key] = $boolToken === 'true';
                }
            }
        }
        return $args;
    }

    /**
     * 工具完成后写入结构化研究状态，供 fa_research_state_summary 与最终回答审计使用
     */
    private function recordResearchState(AIAgentState $state, string $name, array $args, array $decoded): void
    {
        $success = (bool)($decoded['success'] ?? false);
        $action = (string)($decoded['action'] ?? $name);
        $data = $decoded['data'] ?? null;

        $entry = [
            'name' => $name,
            'success' => $success,
            'action' => $action,
        ];
        if (!$success) {
            $entry['code'] = (string)($decoded['code'] ?? 'tool_error');
            $entry['message'] = (string)($decoded['message'] ?? '');
        }

        // 从结果中提取候选基金代码/名称，更新候选池状态
        $candidates = &$state->researchState['candidates'];
        $markCandidate = function (string $code, string $name, string $status) use (&$candidates) {
            if ($code === '' || !preg_match('/^\d{6}$/', $code)) return;
            $existing = $candidates[$code] ?? ['name' => '', 'status' => 'seen'];
            $rank = ['seen' => 0, 'enriched' => 1, 'dividend_event' => 2, 'screened' => 2, 'stats' => 3, 'rules' => 3, 'dividend_profile' => 4, 'dividend_market' => 4, 'exposure' => 4, 'scored' => 5];
            $newRank = $rank[$status] ?? 0;
            $oldRank = $rank[$existing['status']] ?? 0;
            $status = $newRank >= $oldRank ? $status : $existing['status'];
            $candidates[$code] = [
                'name' => $name !== '' ? $name : $existing['name'],
                'status' => $status,
            ];
        };

        if ($success && is_array($data)) {
            $listItems = array_values(array_filter($data, 'is_array'));
            switch ($name) {
                case 'fa_get_upcoming_dividends':
                    foreach (($data['items'] ?? []) as $c) {
                        if (is_array($c)) $markCandidate((string)($c['code'] ?? ''), (string)($c['name'] ?? ''), 'dividend_event');
                    }
                    break;
                case 'fa_get_upcoming_fund_dividends':
                    foreach (($data['items'] ?? []) as $c) {
                        if (is_array($c)) $markCandidate((string)($c['code'] ?? ''), (string)($c['name'] ?? ''), 'dividend_event');
                    }
                    break;
                case 'fa_get_stock_dividend_profile':
                    $stock = is_array($data['stock'] ?? null) ? $data['stock'] : [];
                    $markCandidate((string)($stock['code'] ?? $args['code'] ?? ''), (string)($stock['name'] ?? ''), 'dividend_profile');
                    break;
                case 'fa_get_fund_dividend_profile':
                    $fund = is_array($data['query_fund'] ?? null) ? $data['query_fund'] : [];
                    $markCandidate((string)($fund['code'] ?? $args['code'] ?? ''), (string)($fund['name'] ?? ''), 'dividend_profile');
                    foreach (($data['related_funds'] ?? []) as $relatedFund) {
                        if (is_array($relatedFund)) {
                            $markCandidate((string)($relatedFund['code'] ?? ''), (string)($relatedFund['name'] ?? ''), 'dividend_profile');
                        }
                    }
                    break;
                case 'fa_get_fund_dividend_event_market':
                    $fund = is_array($data['fund'] ?? null) ? $data['fund'] : [];
                    $markCandidate((string)($fund['code'] ?? $args['code'] ?? ''), (string)($fund['name'] ?? ''), 'dividend_market');
                    break;
                case 'fa_screen_funds':
                    foreach ($listItems as $c) {
                        $markCandidate((string)($c['code'] ?? ''), (string)($c['name'] ?? ''), 'screened');
                    }
                    break;
                case 'fa_score_funds':
                    foreach (($data['items'] ?? []) as $c) {
                        if (is_array($c)) $markCandidate((string)($c['code'] ?? ''), (string)($c['name'] ?? ''), 'scored');
                    }
                    break;
                case 'fa_get_fund_info':
                case 'fa_get_fund_trade_rules':
                    foreach ($listItems as $c) {
                        $markCandidate((string)($c['code'] ?? ''), (string)($c['name'] ?? ''), $name === 'fa_get_fund_trade_rules' ? 'rules' : 'enriched');
                    }
                    break;
                case 'fa_get_fund_performance_stats':
                    foreach ($listItems as $c) {
                        $markCandidate((string)($c['code'] ?? ''), '', 'stats');
                    }
                    break;
                case 'fa_get_fund_holdings_or_index_exposure':
                    $markCandidate((string)($data['code'] ?? ''), (string)($data['name'] ?? ''), 'exposure');
                    break;
                case 'fa_search_funds':
                case 'fa_get_fund_rank':
                    foreach ($listItems as $c) {
                        $markCandidate((string)($c['code'] ?? ''), (string)($c['name'] ?? ''), 'seen');
                    }
                    break;
                case 'fa_get_index_profile':
                    $markCandidate((string)($data['fund_code'] ?? $args['code'] ?? ''), (string)($data['fund_name'] ?? ''), 'exposure');
                    break;
            }
        }

        // 记录失败（非去重、非参数错误）
        if (!$success && !in_array(($decoded['code'] ?? ''), ['duplicate_tool_call', 'invalid_arguments_json'], true)) {
            $state->researchState['failures'][] = [
                'tool' => $name,
                'code' => (string)($args['code'] ?? ($args['codes'][0] ?? '')),
                'error' => (string)($decoded['code'] ?? 'tool_error'),
                'impact' => $this->failureImpact($name, (string)($decoded['message'] ?? '')),
            ];
        }

        $state->researchState['tools'][] = $entry;
    }

    private function failureImpact(string $name, string $message): string
    {
        $impacts = [
            'fa_get_fund_estimate' => '盘中估值缺失，不影响长期统计',
            'fa_get_fund_history' => '历史净值分页部分缺失，已用已取得样本',
            'fa_get_fund_documents' => '文档证据缺失，最终回答需说明数据缺口',
            'fa_get_fund_dividend_profile' => '基金分红、公告或目标 ETF 关系证据缺失，不能确认当前事件归属',
            'fa_get_fund_dividend_event_market' => '事件净值、ETF 日 K 或官方基准均不可用，不能评价除息表现与恢复情况',
            'fa_get_fund_holdings_or_index_exposure' => '风格暴露降级为基金详情推导',
            'fa_get_upcoming_dividends' => '临近分红候选池缺失，不能给出实时事件排序',
            'fa_get_upcoming_fund_dividends' => '基金分红事件池缺失，不能给出全市场基金分红排序',
            'fa_get_stock_dividend_profile' => '个股分红历史缺失，不能判断分红连续性',
        ];
        return $impacts[$name] ?? '工具失败，结果可能不完整';
    }
}
