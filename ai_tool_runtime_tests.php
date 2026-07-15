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
    assertTrue(isset($schema['properties']['event_date']), '分红档案工具必须允许精确选择事件日期');
    $event = $definitions['fa_get_fund_dividend_event_market'] ?? null;
    assertTrue(is_array($event), '基金分红事件市场聚合工具必须注册');
    assertTrue(($event['parameters']['additionalProperties'] ?? null) === false, '聚合工具必须使用严格参数结构');
    assertTrue(array_keys($event['parameters']['properties'] ?? []) === ['code', 'event_date', 'before', 'after', 'previous_events', 'include_benchmark'], '聚合工具 schema 参数必须完整且固定');
};

$tests['fund_dividend_auto_arguments_and_prompt_policy'] = function (): void {
    $agent = new AIChatToolAgent(['api_url' => 'https://example.invalid', 'api_key' => 'test', 'model' => 'test']);
    $text = "请研判基金 563830 的分红\n除息日：2026-07-16";
    $eventArgs = invokePrivate($agent, 'inferArgumentsForRequestedTool', ['fa_get_fund_dividend_event_market', $text]);
    assertTrue(($eventArgs['code'] ?? '') === '563830', '聚合工具应自动提取基金代码');
    assertTrue(($eventArgs['event_date'] ?? '') === '2026-07-16', '聚合工具应优先自动提取除息日');
    assertTrue(($eventArgs['before'] ?? null) === 10 && ($eventArgs['after'] ?? null) === 15, '聚合工具应补齐默认事件窗口');
    $profileArgs = invokePrivate($agent, 'inferArgumentsForRequestedTool', ['fa_get_fund_dividend_profile', $text]);
    assertTrue(($profileArgs['event_date'] ?? '') === '2026-07-16', '分红档案应使用相同事件日期');
    $prompt = invokePrivate($agent, 'systemPrompt', [null, true]);
    assertTrue(strpos($prompt, '必须同时调用 fa_get_fund_dividend_profile 与 fa_get_fund_dividend_event_market') !== false, '单基金分红提示应强制双工具证据链');
    assertTrue(strpos($prompt, '同日官方净值') !== false && strpos($prompt, '不再调用盘中估值') !== false, '提示应避免同日净值存在时重复查估值');
};

$tests['guardrail_understands_negation_context'] = function (): void {
    $policy = new AIAgentGuardrailPolicy();
    $base = '数据事实来自基金净值，推断仍有不确定性和风险。内容仅供研究参考，不构成投资建议。';
    foreach (['这不是无风险收益。', '现有数据无法保证收益。', '不要直接买入该基金。'] as $sentence) {
        $review = $policy->reviewFinalText($sentence . $base);
        assertTrue(!in_array('promised_return', $review['violations'] ?? [], true), '否定收益语境不得触发收益承诺护栏');
        assertTrue(!in_array('deterministic_trade_command', $review['violations'] ?? [], true), '否定交易语境不得触发交易指令护栏');
    }
    $guarantee = $policy->reviewFinalText('该基金保证收益。' . $base);
    assertTrue(in_array('promised_return', $guarantee['violations'] ?? [], true), '真实保证收益仍必须触发护栏');
    $trade = $policy->reviewFinalText('请马上买入该基金。' . $base);
    assertTrue(in_array('deterministic_trade_command', $trade['violations'] ?? [], true), '真实直接交易指令仍必须触发护栏');
};

$tests['performance_uses_official_total_return_benchmark'] = function (): void {
    $client = new class extends CsindexClient {
        public $requested = [];
        public $fail = false;
        public function __construct() {}
        public function history(string $indexCode, string $startDate, string $endDate): DataSourceResult
        {
            $this->requested[] = $indexCode;
            if ($this->fail) return DataSourceResult::error('csindex', 'index_history', 'network_error', 'fake unavailable');
            $rows = [];
            $date = new DateTimeImmutable('2026-01-01');
            for ($i = 0; $i < 45; $i++) {
                $rows[] = ['date' => $date->modify('+' . $i . ' days')->format('Y-m-d'), 'close' => 200 + $i * 2];
            }
            return DataSourceResult::success('csindex', 'index_history', $rows);
        }
    };
    $fund = new FundService($client);
    $series = [];
    $date = new DateTimeImmutable('2026-01-01');
    for ($i = 0; $i < 45; $i++) {
        $series[] = ['date' => $date->modify('+' . $i . ' days')->format('Y-m-d'), 'price' => 100 + $i];
    }
    $totalReturn = invokePrivate($fund, 'computeRiskAdjusted', [$series, '932365', true]);
    assertTrue(($client->requested[0] ?? '') === '932365CNY010', '累计净值必须请求官方 CNY010 全收益序列');
    assertTrue(($totalReturn['benchmark_index'] ?? '') === '932365CNY010' && ($totalReturn['benchmark_variant'] ?? '') === 'total_return', '必须输出实际全收益基准代码与序列类型');
    assertTrue(($totalReturn['benchmark_source'] ?? '') === 'csindex' && ($totalReturn['sample_pairs'] ?? 0) >= 30, '跟踪误差必须输出官方来源与有效样本对');
    $client->fail = true;
    $unavailable = invokePrivate($fund, 'computeRiskAdjusted', [$series, '932365', true]);
    assertTrue(($unavailable['benchmark_status'] ?? '') === 'benchmark_variant_unavailable' && ($unavailable['tracking_error_pct'] ?? null) === null, '全收益序列失败时不得回退价格指数计算累计净值跟踪误差');
    $short = invokePrivate($fund, 'computeRiskAdjusted', [array_slice($series, 0, 20), '932365', true]);
    assertTrue(($short['benchmark_status'] ?? '') === 'insufficient_fund_samples' && ($short['tracking_error_pct'] ?? null) === null, '不足 30 对时跟踪误差保持 null');
};

$tests['parallel_research_summary_uses_in_process_state'] = function (): void {
    $options = AIAgentOptions::normalize([
        'parallel_tool_calls' => true,
        'internal_exec_endpoint' => 'http://127.0.0.1:9/FuckAshare/ai_tool_exec.php',
        'internal_exec_token' => 'test-token-not-secret',
        'heartbeat_interval' => 0,
    ]);
    $runtime = new AIToolRuntime(new AIToolExecutor(), new AIAgentStreamEmitter($options), $options);
    $state = new AIAgentState('research_summary_local');
    $state->researchState['tools'] = [
        ['name' => 'fa_get_fund_dividend_profile', 'success' => true],
        ['name' => 'fa_get_fund_dividend_event_market', 'success' => true],
    ];
    $messages = $runtime->executeToolCalls([[
        'id' => 'call_summary_local',
        'function' => ['name' => 'fa_research_state_summary', 'arguments' => json_encode([
            'asset_type' => 'fund', 'focus' => '基金分红', 'include_failures' => true, 'include_next_steps' => false,
        ], JSON_UNESCAPED_UNICODE)],
    ]], $state, function (): void {}, 2, 'test');
    $decoded = decodeToolMessage($messages[0]);
    assertTrue(($decoded['success'] ?? false) === true, '并行模式下研究状态汇总应在当前进程成功执行');
    assertTrue(($decoded['data']['coverage']['dividend_evidence'] ?? false) === true && ($decoded['data']['coverage']['dividend_market'] ?? false) === true, '研究状态汇总必须保留档案与事件市场覆盖');
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

$tests['advisor_view_switch_keeps_active_stream_alive'] = function (): void {
    $js = file_get_contents(__DIR__ . '/main.js');
    $html = file_get_contents(__DIR__ . '/index.php');
    assertTrue(is_string($js) && is_string($html), '必须能读取 AI 顾问前端资源');

    $closeStart = strpos($js, '    close({ restoreFocus = true } = {}) {');
    $closeEnd = strpos($js, '    /** 浮窗与完整页只是同一任务的两种视图', $closeStart ?: 0);
    assertTrue($closeStart !== false && $closeEnd !== false, '顾问浮窗必须提供只关闭视图的 close 方法');
    $closeBody = substr($js, $closeStart, $closeEnd - $closeStart);
    assertTrue(strpos($closeBody, '_aiAbortController') === false && strpos($closeBody, '.abort()') === false, '关闭或展开浮窗不得中止进行中的 AI 请求');

    assertTrue(strpos($js, 'expandToPage()') !== false && strpos($js, 'collapseToPanel()') !== false, '必须支持浮窗与完整页双向切换');
    assertTrue(strpos($js, '_syncNewDisplayRecord(index, element)') !== false, '新增流式展示记录必须同步到两个视图');
    assertTrue(strpos($js, '_applyDisplayRecord(mirror, record, options)') !== false, '流式增量必须更新另一个视图的镜像消息');
    assertTrue(strpos($js, 'if (APP._aiAbortController !== requestController) return;') !== false, '旧请求结束不得覆盖新请求的运行状态');
    assertTrue(strpos($html, 'id="advisor-collapse-btn"') !== false && strpos($html, 'id="ai-page-status"') !== false, '完整页必须提供返回浮窗入口和任务状态');
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
