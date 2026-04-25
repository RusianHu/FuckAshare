<?php

require_once __DIR__ . '/../lib/CacheStoreFactory.php';
require_once __DIR__ . '/../lib/MarketDataService.php';
require_once __DIR__ . '/../lib/CircuitBreaker.php';
require_once __DIR__ . '/../lib/DataSourceResult.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function temp_dir(string $name): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fa_test_' . $name . '_' . getmypid();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function cleanup_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function invoke_use_cache(MarketDataService $service, string $action, string $key, callable $fetcher): DataSourceResult
{
    $method = new ReflectionMethod(MarketDataService::class, 'useCache');
    $method->setAccessible(true);
    return $method->invoke($service, $action, $key, $fetcher);
}

$cacheDir = temp_dir('cache');
CacheStoreFactory::useFileStore($cacheDir);
$store = CacheStoreFactory::getInstance();

$store->set('fresh', ['success' => true, 'data' => ['v' => 1]], 10);
assert_true($store->get('fresh')['data']['v'] === 1, 'FileCacheStore reads fresh cache');

$store->set('expired', ['success' => true, 'data' => ['v' => 2], 'cached_at' => time() - 2], 1);
assert_true($store->get('expired') === null, 'FileCacheStore hides expired cache');
assert_true($store->getStale('expired')['data']['v'] === 2, 'FileCacheStore reads stale cache');

assert_true($store->acquireLock('lock-a', 5), 'FileCacheStore acquires first lock');
assert_true(!$store->acquireLock('lock-a', 5), 'FileCacheStore refuses held lock');
$store->releaseLock('lock-a');
assert_true($store->acquireLock('lock-a', 5), 'FileCacheStore reacquires released lock');
$store->releaseLock('lock-a');

CacheStoreFactory::configureRedis(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.01]);
assert_true(CacheStoreFactory::getInstance() instanceof FileCacheStore, 'CacheStoreFactory falls back to file store when Redis is unavailable');

CacheStoreFactory::useFileStore($cacheDir);
$breaker = new CircuitBreaker('phase2_test_' . getmypid(), 2, 1);
assert_true($breaker->allow(), 'CircuitBreaker starts closed');
$breaker->failure('one');
assert_true($breaker->allow(), 'CircuitBreaker remains closed before threshold');
$breaker->failure('two');
assert_true(!$breaker->allow(), 'CircuitBreaker opens at threshold');
sleep(2);
assert_true($breaker->allow(), 'CircuitBreaker allows half-open probe after cooldown');
$breaker->success();
assert_true($breaker->getState()['state'] === CircuitBreaker::STATE_CLOSED, 'CircuitBreaker closes after success');

$service = new MarketDataService();
$calls = 0;
$result = invoke_use_cache($service, 'quote', 'unit:success', function() use (&$calls) {
    $calls++;
    return DataSourceResult::success('unit', 'quote', ['ok' => true]);
});
assert_true($result->hasData() && $calls === 1, 'MarketDataService stores successful miss');

$result = invoke_use_cache($service, 'quote', 'unit:success', function() use (&$calls) {
    $calls++;
    return DataSourceResult::error('unit', 'quote', 'unexpected', 'should not fetch');
});
assert_true($result->hasData() && $calls === 1 && $result->meta['cache'] === 'hit', 'MarketDataService reads cache hit');

$failCalls = 0;
$result = invoke_use_cache($service, 'quote', 'unit:negative', function() use (&$failCalls) {
    $failCalls++;
    return DataSourceResult::error('unit', 'quote', 'upstream_down', 'upstream down');
});
assert_true(!$result->hasData() && $failCalls === 1, 'MarketDataService records failed miss');

$result = invoke_use_cache($service, 'quote', 'unit:negative', function() use (&$failCalls) {
    $failCalls++;
    return DataSourceResult::success('unit', 'quote', ['unexpected' => true]);
});
assert_true(!$result->hasData() && $failCalls === 1 && $result->meta['cache'] === 'negative', 'MarketDataService serves negative cache');

$lockKey = 'stampede:unit:blocked';
assert_true($store->acquireLock($lockKey, 5), 'Test setup acquires stampede lock');
$blockedCalls = 0;
$result = invoke_use_cache($service, 'quote', 'unit:blocked', function() use (&$blockedCalls) {
    $blockedCalls++;
    return DataSourceResult::success('unit', 'quote', ['unexpected' => true]);
});
$store->releaseLock($lockKey);
assert_true($result->errorCode === 'cache_wait_timeout' && $blockedCalls === 0, 'MarketDataService degrades instead of stampeding on held lock');

cleanup_dir($cacheDir);
echo "Phase 2 core tests passed\n";
