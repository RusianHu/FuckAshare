<?php
/**
 * AI tool runtime regression tests.
 *
 * Run: php/php.exe ai_tool_runtime_tests.php
 */

require_once __DIR__ . '/lib/AIToolRuntime.php';
require_once __DIR__ . '/lib/AIAgentOptions.php';
require_once __DIR__ . '/lib/CacheStoreFactory.php';
require_once __DIR__ . '/lib/AIChatToolAgent.php';

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function decodeToolMessage(array $message): array
{
    $decoded = json_decode((string)($message['content'] ?? ''), true);
    assertTrue(is_array($decoded), '工具消息必须包含有效 JSON');
    return $decoded;
}

function invokePrivate(object $object, string $method, array $args = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

function removeTestTree(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) {
            removeTestTree($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
}

$tests = [];
$tests['empty_result_is_not_data'] = function (): void {
    $result = DataSourceResult::success('test', 'empty', []);
    assertTrue(!$result->hasData(), '空数组不得被视为可用数据');
};

$tests['fund_dividend_profile_tool_schema_is_registered'] = function (): void {
    $definitions = AIFinanceToolCatalog::definitions();
    assertTrue(isset($definitions['fa_get_fund_dividend_profile']), '基金分红档案工具必须注册');
    $schema = $definitions['fa_get_fund_dividend_profile']['parameters'] ?? [];
    assertTrue(($schema['additionalProperties'] ?? null) === false, '基金分红档案工具必须使用严格参数结构');
    assertTrue(isset($schema['properties']['include_related']), '工具参数必须允许查询目标 ETF');
    assertTrue(isset($schema['properties']['include_announcements']), '工具参数必须允许核验公告');
};

$tests['fund_dividend_event_and_document_parsers'] = function (): void {
    $service = new FundService();
    $html = '<html><a href="https://fund.eastmoney.com/515450.html">南方红利低波50ETF</a>'
        . '<table class="cfxq"><tbody><tr><td>2026年</td><td>2026-07-14</td><td>2026-07-15</td>'
        . '<td>每份派现金0.0100元</td><td>2026-07-20</td></tr></tbody></table></html>';
    $history = invokePrivate($service, 'parseDividendHistoryPage', [$html]);
    assertTrue(is_array($history) && count($history['items'] ?? []) === 1, '分红送配表应解析为一条分红事件');
    assertTrue(($history['items'][0]['record_date'] ?? '') === '2026-07-14', '必须保留权益登记日');
    assertTrue(($history['items'][0]['ex_date'] ?? '') === '2026-07-15', '必须保留除息日');
    assertTrue(($history['items'][0]['pay_date'] ?? '') === '2026-07-20', '必须保留现金发放日');
    assertTrue(abs((float)($history['items'][0]['cash_per_unit'] ?? 0) - 0.01) < 0.000001, '必须解析每份现金金额');

    $documentJson = json_encode([
        'ErrCode' => 0,
        'Data' => [[
            'ID' => 'AN202607091234',
            'FUNDCODE' => '515450',
            'TITLE' => '南方红利低波50ETF分红公告',
            'PUBLISHDATEDesc' => '2026-07-10 00:00:00',
            'NEWCATEGORY' => 2,
        ]],
        'TotalCount' => 1,
        'PageIndex' => 1,
        'PageSize' => 20,
    ], JSON_UNESCAPED_UNICODE);
    $documents = invokePrivate($service, 'parseEastmoneyDocumentsResponse', [$documentJson]);
    assertTrue(($documents['items'][0]['date'] ?? '') === '2026-07-10', '当前公告接口日期应正确解析');
    assertTrue(($documents['items'][0]['announcement_type'] ?? '') === '分红送配', '当前公告接口类别应正确解析');

    $officialJson = json_encode([
        'code' => 'ETS-5BP00000',
        'data' => ['jjfhlist' => ['list' => [[
            'f8' => '20260714',
            'f9' => '20260715',
            'f10' => '20260720',
            'f7f6' => '0.0100',
        ]]]],
    ], JSON_UNESCAPED_UNICODE);
    $official = invokePrivate($service, 'parseSouthernDividendResponse', [$officialJson]);
    assertTrue(($official[0]['pay_date'] ?? '') === '2026-07-20', '基金公司官方接口现金发放日应正确解析');
    assertTrue(in_array('nffund_official', $official[0]['sources'] ?? [], true), '官方事件必须标记证据来源');
};

$tests['multi_transport_error_and_canonical_dedupe'] = function (): void {
    $options = AIAgentOptions::normalize([
        'parallel_tool_calls' => true,
        'internal_exec_endpoint' => 'http://127.0.0.1:9/FuckAshare/ai_tool_exec.php',
        'internal_exec_token' => 'test-token-not-secret',
        'tool_timeout' => 8,
        'heartbeat_interval' => 0,
    ]);
    $executor = new AIToolExecutor();
    $stream = new AIAgentStreamEmitter($options);
    $runtime = new AIToolRuntime($executor, $stream, $options);
    $state = new AIAgentState('run_transport_test');
    $events = [];
    $calls = [
        [
            'id' => 'call_a',
            'function' => [
                'name' => 'fa_get_stock_dividend_profile',
                'arguments' => json_encode(['code' => '601668', 'years' => 10, 'holding_period' => 'within_1m']),
            ],
        ],
        [
            'id' => 'call_b',
            'function' => [
                'name' => 'fa_get_stock_dividend_profile',
                'arguments' => json_encode(['holding_period' => 'within_1m', 'code' => '601668', 'years' => 10]),
            ],
        ],
    ];
    $messages = $runtime->executeToolCalls($calls, $state, function (string $event) use (&$events): void {
        $events[] = $event;
    }, 1, 'test');

    assertTrue(count($messages) === 2, '应回填两个工具消息');
    $first = decodeToolMessage($messages[0]);
    $second = decodeToolMessage($messages[1]);
    assertTrue(($first['code'] ?? '') === 'parallel_dispatch_failed', '连接失败必须返回 parallel_dispatch_failed');
    assertTrue((int)($first['meta']['curl_errno'] ?? 0) !== 0, '必须保留 curl_multi 的真实错误码');
    assertTrue((int)($first['meta']['http_code'] ?? -1) === 0, '连接失败的 HTTP 状态应为 0');
    assertTrue(($second['code'] ?? '') === 'duplicate_tool_call', '参数键顺序不同仍应命中去重');
    assertTrue($state->toolCalls === 1, '重复调用不得消耗真实工具预算');
    assertTrue($state->stopReason === 'tool_transport_failure', '整批传输失败必须停止后续工具轮');
};

$tests['channel_wide_503_does_not_retry_plain_stream'] = function (): void {
    $streamAttempts = 0;
    $agent = new AIChatToolAgent(
        ['api_url' => 'https://example.invalid/v1/chat/completions', 'api_key' => 'test', 'model' => 'test'],
        ['emit_agent_events' => true, 'expose_tool_trace' => true, 'heartbeat_interval' => 0],
        null,
        function (): array {
            throw new RuntimeException('上游 AI 错误(HTTP 503): auth_unavailable: no auth available', 503);
        },
        function () use (&$streamAttempts): void {
            $streamAttempts++;
        }
    );
    $output = '';
    $agent->run([['role' => 'user', 'content' => '请分析基金 563830 分红']], function (string $chunk) use (&$output): void {
        $output .= $chunk;
    });

    assertTrue($streamAttempts === 0, '渠道级 503 不应向同一失效渠道重复发起普通流请求');
    assertTrue(strpos($output, 'upstream_unavailable') !== false, '渠道级错误必须输出结构化 upstream_unavailable');
    assertTrue(strpos($output, 'run_failed') !== false, '渠道级错误必须将代理任务标记为失败');
    assertTrue(strpos($output, 'data: [DONE]') !== false, '失败的 SSE 仍必须正常发送结束标记');
};

$tests['plain_stream_fallback_failure_is_not_marked_complete'] = function (): void {
    $agent = new AIChatToolAgent(
        ['api_url' => 'https://example.invalid/v1/chat/completions', 'api_key' => 'test', 'model' => 'test'],
        ['emit_agent_events' => true, 'expose_tool_trace' => true, 'heartbeat_interval' => 0],
        null,
        function (): array {
            throw new RuntimeException('tools are not supported', 400);
        },
        function (): void {
            throw new RuntimeException('上游 AI 错误(HTTP 503): auth_unavailable', 503);
        }
    );
    $output = '';
    $agent->run([['role' => 'user', 'content' => '请分析基金 563830 分红']], function (string $chunk) use (&$output): void {
        $output .= $chunk;
    });

    assertTrue(strpos($output, 'fallback_plain_stream') !== false, '工具能力类 400 应保留普通流回退');
    assertTrue(strpos($output, 'upstream_stream_error') !== false, '回退流失败必须输出真实上游错误');
    assertTrue(strpos($output, 'run_failed') !== false, '回退流失败必须标记任务失败');
    assertTrue(strpos($output, 'run_finished') === false, '回退流失败不得误报任务完成');
    assertTrue(strpos($output, 'data: [DONE]') !== false, '回退流失败仍必须结束 SSE');
};

$tests['mimo_thinking_preserves_reasoning_across_tool_rounds'] = function (): void {
    $payloads = [];
    $responses = [
        [
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'reasoning_content' => 'round-one-reasoning',
                    'tool_calls' => [[
                        'id' => 'call_round_one',
                        'type' => 'function',
                        'function' => ['name' => 'fa_normalize_stock_code', 'arguments' => '{"code":"600000"}'],
                    ]],
                ],
            ]],
        ],
        [
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'reasoning_content' => 'round-two-reasoning',
                    'tool_calls' => [[
                        'id' => 'call_round_two',
                        'type' => 'function',
                        'function' => ['name' => 'fa_normalize_stock_code', 'arguments' => '{"code":"000001"}'],
                    ]],
                ],
            ]],
        ],
        [
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => '多轮工具调用完成。内容仅供研究参考，不构成投资建议。',
                    'reasoning_content' => 'final-round-reasoning',
                    'tool_calls' => null,
                ],
            ]],
        ],
    ];
    $transport = function (array $payload) use (&$payloads, &$responses): array {
        $payloads[] = $payload;
        if (empty($responses)) throw new RuntimeException('测试响应已耗尽');
        return array_shift($responses);
    };

    $agent = new AIChatToolAgent(
        ['api_url' => 'https://example.invalid/v1/chat/completions', 'api_key' => 'test', 'model' => 'mimo-v2.5-pro'],
        [
            'emit_agent_events' => true,
            'expose_tool_trace' => false,
            'parallel_tool_calls' => false,
            'heartbeat_interval' => 0,
            'max_tool_rounds' => 4,
            'tool_decision_max_tokens' => 2048,
        ],
        new AIToolExecutor(),
        $transport
    );
    $output = '';
    $agent->run([['role' => 'user', 'content' => '测试 MiMo thinking 多轮工具调用']], function (string $chunk) use (&$output): void {
        $output .= $chunk;
    });

    assertTrue(count($payloads) === 3, '应完成两轮工具调用和一轮最终回答');
    foreach ($payloads as $payload) {
        assertTrue(($payload['thinking']['type'] ?? '') === 'enabled', 'MiMo 工具请求必须保持 thinking=enabled');
        assertTrue(($payload['max_completion_tokens'] ?? 0) === 2048, 'MiMo 应使用 max_completion_tokens');
        assertTrue(!isset($payload['max_tokens']), 'MiMo 请求不应同时发送 max_tokens');
    }

    $secondAssistants = array_values(array_filter($payloads[1]['messages'] ?? [], function ($message): bool {
        return ($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls']);
    }));
    assertTrue(($secondAssistants[0]['reasoning_content'] ?? null) === 'round-one-reasoning', '第二轮必须回传第一轮 reasoning_content');

    $thirdAssistants = array_values(array_filter($payloads[2]['messages'] ?? [], function ($message): bool {
        return ($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls']);
    }));
    assertTrue(count($thirdAssistants) === 2, '第三轮必须保留全部历史 assistant 工具消息');
    assertTrue(($thirdAssistants[0]['reasoning_content'] ?? null) === 'round-one-reasoning', '第三轮必须保留第一轮推理');
    assertTrue(($thirdAssistants[1]['reasoning_content'] ?? null) === 'round-two-reasoning', '第三轮必须保留第二轮推理');
    assertTrue(strpos($output, '"type":"conversation_context"') !== false, 'SSE 必须下发隐藏会话上下文');
    assertTrue(strpos($output, 'final-round-reasoning') !== false, '最终非流式 assistant 的推理也必须进入会话上下文');
};

$tests['message_validation_accepts_mimo_tool_history'] = function (): void {
    $messages = SecurityAudit::validateMessages([
        ['role' => 'user', 'content' => '查询行情'],
        [
            'role' => 'assistant',
            'content' => null,
            'reasoning_content' => '需要调用行情工具。',
            'tool_calls' => [[
                'id' => 'call_validation',
                'type' => 'function',
                'function' => ['name' => 'fa_get_stock_quote', 'arguments' => '{"codes":["600000"]}'],
            ]],
        ],
        ['role' => 'tool', 'tool_call_id' => 'call_validation', 'name' => 'fa_get_stock_quote', 'content' => '{"success":true}'],
        ['role' => 'assistant', 'content' => '查询完成。', 'reasoning_content' => '已经获得结果。'],
    ]);
    assertTrue(count($messages) === 4, '入口校验必须接受完整 MiMo assistant/tool 历史');
};

$tests['mimo_final_stream_keeps_native_tool_protocol'] = function (): void {
    $streamPayload = null;
    $agent = new AIChatToolAgent(
        ['api_url' => 'https://example.invalid/v1/chat/completions', 'api_key' => 'test', 'model' => 'mimo-v2.5-pro'],
        [
            'emit_agent_events' => true,
            'expose_tool_trace' => false,
            'parallel_tool_calls' => false,
            'heartbeat_interval' => 0,
            'max_tool_calls_total' => 1,
        ],
        new AIToolExecutor(),
        function (): array {
            return [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'reasoning_content' => 'reasoning-before-final-stream',
                        'tool_calls' => [[
                            'id' => 'call_before_final_stream',
                            'type' => 'function',
                            'function' => ['name' => 'fa_normalize_stock_code', 'arguments' => '{"code":"600000"}'],
                        ]],
                    ],
                ]],
            ];
        },
        function (array $payload, callable $emit) use (&$streamPayload): void {
            $streamPayload = $payload;
            $emit("data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"final-stream-reasoning\"},\"finish_reason\":null}]}\n\n");
            $emit("data: {\"choices\":[{\"delta\":{\"content\":\"最终结论。内容仅供研究参考，不构成投资建议。\"},\"finish_reason\":null}]}\n\n");
            $emit("data: [DONE]\n\n");
        }
    );
    $output = '';
    $agent->run([['role' => 'user', 'content' => '测试最终流协议']], function (string $chunk) use (&$output): void {
        $output .= $chunk;
    });

    assertTrue(is_array($streamPayload), '达到工具预算后必须进入最终流');
    $assistantToolMessages = array_values(array_filter($streamPayload['messages'] ?? [], function ($message): bool {
        return ($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls']);
    }));
    $toolMessages = array_values(array_filter($streamPayload['messages'] ?? [], function ($message): bool {
        return ($message['role'] ?? '') === 'tool';
    }));
    assertTrue(count($assistantToolMessages) === 1, 'MiMo 最终流必须保留原生 assistant tool_calls 消息');
    assertTrue(($assistantToolMessages[0]['reasoning_content'] ?? null) === 'reasoning-before-final-stream', 'MiMo 最终流必须保留工具轮推理');
    assertTrue(count($toolMessages) === 1 && ($toolMessages[0]['tool_call_id'] ?? '') === 'call_before_final_stream', 'MiMo 最终流必须保留匹配的 tool 结果');
    assertTrue(($streamPayload['thinking']['type'] ?? '') === 'enabled', 'MiMo 最终流也必须保持 thinking=enabled');
    assertTrue(strpos($output, 'final-stream-reasoning') !== false, '最终流推理必须继续透传');
};

$tests['numeric_code_produces_kline_indicators'] = function (): void {
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_ai_tool_test_' . getmypid();
    removeTestTree($cacheDir);
    CacheStoreFactory::useFileStore($cacheDir);
    try {
        $executor = new AIToolExecutor();
        $decoded = json_decode($executor->executeForModel('fa_calculate_kline_indicators', [
            'code' => '601668',
            'frequency' => '1d',
            'count' => 120,
            'source' => 'ashare',
        ]), true);
        assertTrue(is_array($decoded), '指标工具必须返回有效 JSON');
        assertTrue(($decoded['success'] ?? false) === true, '纯数字代码必须成功计算指标');
        assertTrue((int)($decoded['data']['bars'] ?? 0) === 120, '指标工具应获得 120 条 K 线');
    } finally {
        CacheStoreFactory::reset();
        removeTestTree($cacheDir);
    }
};

$passed = 0;
foreach ($tests as $name => $test) {
    $started = microtime(true);
    $test();
    $passed++;
    echo '[PASS] ' . $name . ' (' . (int)round((microtime(true) - $started) * 1000) . "ms)\n";
}
echo "All {$passed} AI tool runtime tests passed.\n";
