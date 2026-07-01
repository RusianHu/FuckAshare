<?php

require_once __DIR__ . '/../lib/EastmoneyClient.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function invoke_private($object, string $method, array $args = [])
{
    $ref = new ReflectionMethod(get_class($object), $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($object, $args);
}

$client = new EastmoneyClient();
$failures = [];
$index = invoke_private($client, 'normalizeMarketBreadthIndex', [[
    'f12' => '000001',
    'f13' => 1,
    'f14' => '上证指数',
    'f2' => '3000.12',
    'f3' => '1.23',
    'f4' => '36.5',
    'f6' => '123456789',
    'f104' => '1000',
    'f105' => '400',
    'f106' => '20',
], ['code' => '000001', 'market' => 'SH', 'name' => '上证指数'], &$failures]);
assert_true($index['price'] === 3000.12, 'index price is normalized');
assert_true($index['up_count'] === 1000 && $index['down_count'] === 400 && $index['flat_count'] === 20, 'index breadth counts are normalized');
assert_true($index['total_count'] === 1420, 'index total count is calculated');
assert_true($index['advance_decline_ratio'] === 2.5, 'index advance decline ratio is calculated');
assert_true(empty($failures), 'complete index fields produce no failures');

$failures = [];
$missing = invoke_private($client, 'normalizeMarketBreadthIndex', [[
    'f12' => '399006',
    'f13' => 0,
    'f14' => '创业板指',
    'f2' => '2500',
    'f3' => '-',
    'f104' => '10',
    'f106' => '2',
], ['code' => '399006', 'market' => 'SZ', 'name' => '创业板指'], &$failures]);
assert_true($missing['change_pct'] === null, 'dash numeric field becomes null');
assert_true($missing['down_count'] === null && $missing['total_count'] === null, 'missing breadth field becomes null');
assert_true(count($failures) === 1 && $failures[0]['code'] === 'missing_breadth_fields', 'missing breadth fields are reported');

$aggregate = invoke_private($client, 'aggregateFromIndexCounts', [[
    ['up_count' => 10, 'down_count' => 4, 'flat_count' => 1],
    ['up_count' => 2, 'down_count' => 0, 'flat_count' => 1],
    ['up_count' => null, 'down_count' => 3, 'flat_count' => 0],
], 'core_indices']);
assert_true($aggregate['method'] === 'index_constituent_counts', 'aggregate uses index constituent method');
assert_true($aggregate['up_count'] === 12 && $aggregate['down_count'] === 4 && $aggregate['flat_count'] === 2, 'aggregate sums usable index counts');
assert_true($aggregate['index_sample_count'] === 2, 'aggregate ignores incomplete index samples');

$stats = invoke_private($client, 'emptyScanStats');
invoke_private($client, 'accumulateMarketBreadthRows', [[
    ['f3' => '10.00'],
    ['f3' => '-10.00'],
    ['f3' => '0'],
    ['f3' => '-'],
    ['f3' => '7.00'],
    ['f3' => '-7.00'],
], &$stats]);
assert_true($stats['up_count'] === 2 && $stats['down_count'] === 2 && $stats['flat_count'] === 1, 'scan rows classify up down flat');
assert_true($stats['unknown_count'] === 1 && $stats['tradable_count'] === 5, 'scan rows track unknown and tradable counts');
assert_true($stats['limit_up_count'] === 1 && $stats['limit_down_count'] === 1, 'scan rows calculate approximate limits');
assert_true($stats['near_limit_up_count'] === 2 && $stats['near_limit_down_count'] === 2, 'scan rows calculate near limits');

$scanAggregate = invoke_private($client, 'buildAggregate', ['full_a_share_scan', $stats['up_count'], $stats['down_count'], $stats['flat_count'], $stats['unknown_count'], 'a_share', ['formula' => 'test']]);
assert_true($scanAggregate['total_count'] === 6 && $scanAggregate['coverage_ratio_pct'] === 83.33, 'scan aggregate tracks total and coverage');
assert_true($scanAggregate['breadth_score'] === 50.0 && $scanAggregate['sentiment_label'] === 'neutral', 'scan aggregate calculates score and sentiment');

$limitStats = invoke_private($client, 'emptyLimitStats', ['not_requested']);
assert_true($limitStats['method'] === 'not_requested' && $limitStats['limit_up_count'] === null, 'empty limit stats preserve not requested method');

echo "Market breadth normalizer tests passed\n";
