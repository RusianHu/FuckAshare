<?php

require_once __DIR__ . '/lib/EastmoneyClient.php';
require_once __DIR__ . '/lib/MarketDataService.php';

class StockFlowTestHttpClient extends HttpClient
{
    public $responses;
    public $urls = [];

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function get(string $url, array $headers = []): array
    {
        $this->urls[] = $url;
        if (empty($this->responses)) {
            throw new RuntimeException('No mock response left for ' . $url);
        }
        return array_shift($this->responses);
    }
}

class StockFlowTestBreaker extends CircuitBreaker
{
    public $successCount = 0;
    public $failures = [];

    public function __construct()
    {
    }

    public function allow(): bool
    {
        return true;
    }

    public function success(): void
    {
        $this->successCount++;
    }

    public function failure(string $reason = ''): void
    {
        $this->failures[] = $reason;
    }

    public function getState(): array
    {
        return ['state' => self::STATE_CLOSED, 'failures' => count($this->failures)];
    }
}

function stockFlowResponse(array $klines): array
{
    return [
        'body' => json_encode(['rc' => 0, 'data' => ['klines' => $klines]]),
        'http_code' => 200,
        'error' => null,
        'content_type' => 'application/json',
    ];
}

function stockFlowEmptyResponse(): array
{
    return [
        'body' => json_encode(['rc' => 100, 'data' => null]),
        'http_code' => 200,
        'error' => null,
        'content_type' => 'application/json',
    ];
}

function stockFlowNetworkError(string $message): array
{
    return [
        'body' => '',
        'http_code' => 0,
        'error' => $message,
        'content_type' => '',
    ];
}

function stockFlowAssert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = 0;

// 历史成功时合并当日盘中累计值，并替换同日期的旧日线。
$http = new StockFlowTestHttpClient([
    stockFlowResponse([
        '2026-07-16,-10,20,-5,-3,-7',
        '2026-07-17,-30,40,-5,-10,-20',
    ]),
    stockFlowResponse(['2026-07-17 14:16,-97,-128,225,29,-126']),
]);
$breaker = new StockFlowTestBreaker();
$result = (new EastmoneyClient($http, $breaker))->stockFlow('sh600036', 10);
stockFlowAssert($result->success, 'history + intraday should succeed');
stockFlowAssert(count($result->data) === 2, 'same-day snapshot should replace instead of append');
stockFlowAssert($result->data[1]['time'] === '2026-07-17 14:16', 'latest snapshot timestamp should be retained');
stockFlowAssert($result->data[1]['main_net_inflow'] === -97.0, 'flow field mapping is incorrect');
stockFlowAssert(($result->meta['coverage'] ?? '') === 'history_plus_latest_intraday', 'coverage metadata is incorrect');
stockFlowAssert($breaker->successCount === 1 && count($breaker->failures) === 0, 'breaker should record success');
$cases++;

// HTTP 200 + data:null 必须视为语义失败，并自动降级到盘中快照。
$http = new StockFlowTestHttpClient([
    stockFlowEmptyResponse(),
    stockFlowResponse(['2026-07-17 14:16,-97,-128,225,29,-126']),
]);
$breaker = new StockFlowTestBreaker();
$result = (new EastmoneyClient($http, $breaker))->stockFlow('600036', 10);
stockFlowAssert($result->success && $result->isFallback(), 'semantic empty history should use fallback');
stockFlowAssert(count($result->data) === 1, 'fallback should contain one latest snapshot');
stockFlowAssert(($result->meta['partial'] ?? false) === true, 'fallback must be marked partial');
stockFlowAssert(($result->meta['history_endpoint']['valid_payload'] ?? true) === false, 'empty payload must be diagnosed as invalid');
$cases++;

// 两条链路都失败时返回显式错误，并记录一次熔断失败。
$http = new StockFlowTestHttpClient([
    stockFlowNetworkError('history down'),
    stockFlowNetworkError('delay down'),
    stockFlowEmptyResponse(),
]);
$breaker = new StockFlowTestBreaker();
$result = (new EastmoneyClient($http, $breaker))->stockFlow('sz000001', 10);
stockFlowAssert(!$result->success, 'dual endpoint failure should fail');
stockFlowAssert($result->errorCode === 'upstream_unavailable', 'dual endpoint error code is incorrect');
stockFlowAssert(count($breaker->failures) === 1, 'dual endpoint failure should increment breaker once');
$cases++;

// 降级结果经过缓存还原后必须继续保持 fallback_used 状态。
$serviceReflection = new ReflectionClass(MarketDataService::class);
$service = $serviceReflection->newInstanceWithoutConstructor();
$hydrate = $serviceReflection->getMethod('hydrateCacheResult');
$cachedResult = $hydrate->invoke($service, [
    'success' => true,
    'source' => 'eastmoney',
    'action' => 'stock_flow',
    'status' => DataSourceResult::STATUS_FALLBACK_USED,
    'data' => [['time' => '2026-07-17 14:16']],
    'meta' => ['partial' => true],
]);
stockFlowAssert($cachedResult->isFallback(), 'cache hydration must preserve fallback status');
$cases++;

echo "stock flow feature tests passed: {$cases}\n";
