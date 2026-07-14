<?php
/**
 * AI tool runtime regression tests.
 *
 * Run: php/php.exe ai_tool_runtime_tests.php
 */

require_once __DIR__ . '/lib/AIToolRuntime.php';
require_once __DIR__ . '/lib/AIAgentOptions.php';
require_once __DIR__ . '/lib/CacheStoreFactory.php';

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
