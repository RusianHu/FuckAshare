<?php

require_once __DIR__ . '/../lib/AIToolRegistry.php';
require_once __DIR__ . '/../lib/AIToolExecutor.php';
require_once __DIR__ . '/../lib/AIChatToolAgent.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, $message);
}

function assert_strict_objects(array $schema, string $path): void
{
    $type = $schema['type'] ?? null;
    $isObject = $type === 'object' || (is_array($type) && in_array('object', $type, true));
    if ($isObject) {
        $properties = $schema['properties'] ?? [];
        assert_true(is_array($properties), "{$path} object schema has properties");
        assert_true(($schema['additionalProperties'] ?? true) === false, "{$path} additionalProperties=false");
        assert_true(isset($schema['required']) && count($schema['required']) === count($properties), "{$path} requires every property");
    }

    foreach (($schema['properties'] ?? []) as $key => $child) {
        if (is_array($child)) {
            assert_strict_objects($child, "{$path}.{$key}");
        }
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
        assert_strict_objects($schema['items'], "{$path}[]");
    }
}

class FakeMarketDataService extends MarketDataService
{
    public function __construct(array $opts = [])
    {
    }

    public function kline(string $code, string $frequency = '1d', int $count = 120, string $endDate = '', string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        $rows = [];
        for ($i = 1; $i <= max(30, $count); $i++) {
            $close = 10 + $i * 0.2;
            $rows[] = [
                'date' => sprintf('2026-01-%02d', (($i - 1) % 28) + 1),
                'open' => $close - 0.1,
                'high' => $close + 0.3,
                'low' => $close - 0.3,
                'close' => $close,
                'volume' => 100000 + $i,
            ];
        }
        return DataSourceResult::success('fake', 'kline', $rows);
    }
}

class FakeToolExecutor extends AIToolExecutor
{
    public $calls = [];

    public function __construct()
    {
    }

    public function executeForModel(string $name, array $args): string
    {
        $this->calls[] = [$name, $args];
        return json_encode([
            'success' => true,
            'source' => 'fake',
            'action' => $name,
            'data' => ['ok' => true, 'args' => $args],
            'meta' => ['updated_at' => '2026-01-01T00:00:00+00:00'],
        ], JSON_UNESCAPED_UNICODE);
    }
}

$tools = AIToolRegistry::chatTools();
assert_true(count($tools) >= 16, 'registry exposes planned tool set');
foreach ($tools as $tool) {
    assert_true(($tool['type'] ?? '') === 'function', 'tool type is function');
    $fn = $tool['function'] ?? [];
    assert_true(($fn['strict'] ?? false) === true, 'tool strict mode is enabled');
    $params = $fn['parameters'] ?? [];
    assert_true(($params['type'] ?? '') === 'object', 'tool parameters are object schemas');
    assert_true(($params['additionalProperties'] ?? true) === false, 'top-level additionalProperties=false');
    assert_true(isset($params['required']) && count($params['required']) === count($params['properties'] ?? []), 'strict schema requires every property');
    assert_strict_objects($params, $fn['name'] ?? 'tool');
}

$executor = new AIToolExecutor(new FakeMarketDataService(), null, 30000);
$normalized = $executor->execute('fa_normalize_stock_code', ['code' => '600519']);
assert_true($normalized['success'] === true, 'normalize stock code succeeds');
assert_true($normalized['data']['market'] === 'SH', 'normalize infers SH market');

$invalid = $executor->execute('fa_normalize_stock_code', ['code' => '600519;rm']);
assert_true($invalid['success'] === false && $invalid['code'] === 'tool_error', 'invalid stock code is rejected structurally');

$indicators = $executor->execute('fa_calculate_kline_indicators', [
    'code' => '600519',
    'frequency' => '1d',
    'count' => 80,
    'source' => 'auto',
]);
assert_true($indicators['success'] === true, 'indicator tool succeeds with fake kline data');
assert_true(isset($indicators['data']['ma']['ma5']), 'indicator tool returns MA values');
assert_true(isset($indicators['data']['macd']['dif']), 'indicator tool returns MACD values');

$compare = $executor->execute('fa_compare_candidates', [
    'asset_type' => 'stock',
    'sort_metric' => 'score',
    'order' => 'desc',
    'candidates' => [
        ['code' => 'A', 'name' => 'Alpha', 'metrics' => ['score' => 1]],
        ['code' => 'B', 'name' => 'Beta', 'metrics' => ['score' => 3]],
    ],
]);
assert_true($compare['data']['items'][0]['code'] === 'B', 'candidate comparison sorts deterministically');

$smallExecutor = new AIToolExecutor(new FakeMarketDataService(), null, 180);
$longOutput = $smallExecutor->executeForModel('fa_compare_candidates', [
    'asset_type' => 'stock',
    'sort_metric' => 'score',
    'order' => 'desc',
    'candidates' => array_map(function($i) {
        return ['code' => 'C' . $i, 'name' => str_repeat('Name', 10), 'metrics' => ['score' => $i, 'long' => str_repeat('x', 80)]];
    }, range(1, 20)),
]);
assert_true(mb_strlen($longOutput) <= 180, 'tool output is truncated to configured character limit');

$fakeExecutor = new FakeToolExecutor();
$transportCalls = [];
$transport = function(array $payload) use (&$transportCalls) {
    $transportCalls[] = $payload;
    if (count($transportCalls) === 1) {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'fa_get_stock_quote',
                            'arguments' => '{"codes":["600519"],"source":"auto","fallback":true}',
                        ],
                    ]],
                ],
            ]],
        ];
    }
    return [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => '最终研究结论：已参考工具数据。内容仅供研究参考，不构成投资建议。',
            ],
        ]],
    ];
};

$agent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 3, 'max_tool_calls_per_round' => 4, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    $fakeExecutor,
    $transport,
    function(array $payload, callable $emit): void {
        $content = '';
        foreach ($payload['messages'] as $message) {
            $content .= (string)($message['content'] ?? '');
        }
        assert_true(strpos($content, 'fa_get_stock_quote') !== false, 'agent streams final response with tool result context');
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"最终研究结论：已参考工具数据。\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$stream = '';
$agent->run([
    ['role' => 'system', 'content' => 'test'],
    ['role' => 'user', 'content' => '分析 600519'],
], function(string $chunk) use (&$stream) {
    $stream .= $chunk;
});

assert_true(count($fakeExecutor->calls) === 1, 'agent executes model-requested tool once');
assert_true(count($transportCalls) === 1, 'agent avoids second non-streaming handshake after first tool round');
assert_contains('tool_status', $stream, 'agent emits optional tool status event');
assert_contains('最终研究结论', $stream, 'agent emits final assistant content as SSE');
assert_contains('data: [DONE]', $stream, 'agent terminates SSE stream');

$plainStream = '';
$plainAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    [],
    $fakeExecutor,
    null,
    function(array $payload, callable $emit): void {
        assert_true(($payload['stream'] ?? false) === true, 'plain fallback uses streaming payload');
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"plain\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$plainAgent->streamPlain([
    ['role' => 'user', 'content' => 'hello'],
], function(string $chunk) use (&$plainStream) {
    $plainStream .= $chunk;
});
assert_contains('plain', $plainStream, 'plain streaming fallback works');

$prefetchExecutor = new FakeToolExecutor();
$prefetchStream = '';
$prefetchAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['expose_tool_trace' => true, 'auto_prefetch' => true],
    $prefetchExecutor,
    function(array $payload): array {
        throw new RuntimeException('prefetch should stream final directly');
    },
    function(array $payload, callable $emit): void {
        $content = '';
        foreach ($payload['messages'] as $message) {
            $content .= (string)($message['content'] ?? '');
        }
        assert_true(strpos($content, 'fa_get_stock_quote') !== false, 'prefetch appends stock quote tool result context');
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"prefetch-final\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$prefetchAgent->run([
    ['role' => 'user', 'content' => '请分析 sz002281 的行情和技术面'],
], function(string $chunk) use (&$prefetchStream) {
    $prefetchStream .= $chunk;
});
assert_contains('fa_get_stock_quote', $prefetchStream, 'prefetch emits visible stock quote tool status');
assert_contains('fa_calculate_kline_indicators', $prefetchStream, 'prefetch emits visible indicator tool status');
assert_contains('prefetch-final', $prefetchStream, 'prefetch streams final response');

$marketScanExecutor = new FakeToolExecutor();
$marketScanTransportCalls = 0;
$marketScanStream = '';
$marketScanAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 10, 'max_tool_calls_per_round' => 8, 'expose_tool_trace' => true, 'auto_prefetch' => true],
    $marketScanExecutor,
    function(array $payload) use (&$marketScanTransportCalls): array {
        $marketScanTransportCalls++;
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'bad_hot_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'fa_get_hot_stocks',
                            'arguments' => '{"page": ',
                        ],
                    ]],
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        $content = '';
        foreach ($payload['messages'] as $message) {
            $content .= (string)($message['content'] ?? '');
        }
        assert_true(strpos($content, 'capital_inflow_candidates') !== false, 'market scan prefetch appends market scan context');
        assert_true(strpos($content, 'fa_get_hot_stocks') !== false, 'market scan prefetch includes hot stocks result');
        assert_true(strpos($content, 'fa_get_sector_flow') !== false, 'market scan prefetch includes sector flow result');
        assert_true(strpos($content, 'fa_get_xueqiu_hot_stock') !== false, 'market scan prefetch includes xueqiu hot result');
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"market-scan-final\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$marketScanAgent->run([
    ['role' => 'user', 'content' => '分析前十资金流入的股票，综合评估'],
], function(string $chunk) use (&$marketScanStream) {
    $marketScanStream .= $chunk;
});
assert_true($marketScanTransportCalls === 1, 'market scan does not loop indefinitely after malformed model tool call');
assert_contains('AI 工具调用', $marketScanStream, 'market scan shows model attempted tool call');
assert_contains('服务端数据预取', $marketScanStream, 'market scan shows server prefetch fallback separately');
assert_contains('fa_get_hot_stocks', $marketScanStream, 'market scan emits hot stocks tool status');
assert_contains('fa_get_sector_flow', $marketScanStream, 'market scan emits sector flow tool status');
assert_contains('fa_get_xueqiu_hot_stock', $marketScanStream, 'market scan emits xueqiu hot tool status');
assert_contains('market-scan-final', $marketScanStream, 'market scan streams final response');

$fallbackStream = '';
$fallbackAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['tool_timeout' => 3, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    $fakeExecutor,
    function(array $payload): array {
        throw new RuntimeException('tools not supported');
    },
    function(array $payload, callable $emit): void {
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"fallback-ok\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$fallbackAgent->run([
    ['role' => 'user', 'content' => 'hello'],
], function(string $chunk) use (&$fallbackStream) {
    $fallbackStream .= $chunk;
});
assert_contains('fallback_plain_stream', $fallbackStream, 'tool handshake failure emits fallback status');
assert_contains('fallback-ok', $fallbackStream, 'tool handshake failure falls back to plain stream');

echo "AI tool agent tests passed\n";
