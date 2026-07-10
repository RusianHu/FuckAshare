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
