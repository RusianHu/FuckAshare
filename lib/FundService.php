<?php
/**
 * FundService — 基金数据统一服务层
 * 封装基金估值、基金详情、基金搜索，复用 HttpClient 与 CacheStore
 *
 * Phase 2: 缓存层重构 → CacheStore 抽象 + 防击穿 + negative cache + stale-while-revalidate
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/AppConfig.php';

class FundService
{
    const SOURCE_NAME = 'eastmoney_fund';

    /** @var HttpClient */
    private $http;

    /** @var CacheStore */
    private $cache;

    /** @var CircuitBreaker */
    private $breaker;

    /** @var array */
    private $cacheTtl;

    /** @var int */
    private $negativeCacheTtl;

    /** @var int */
    private $stampedeWaitMs;

    /** @var int */
    private $stampedeLockTtl;

    /** @var array 缓存 TTL 配置 (秒) */
    const CACHE_TTL = [
        'estimate'    => 10,     // 基金实时估值：短缓存
        'batch_estimate' => 10,
        'info'        => 300,    // 基金详情：5 分钟
        'search'      => 600,   // 基金搜索：10 分钟
    ];

    /** @var int negative cache TTL (秒) */
    const NEGATIVE_CACHE_TTL = 10;

    const EASTMONEY_APP_PARAMS = [
        'plat'     => 'Iphone',
        'appType'  => 'ttjj',
        'product'  => 'EFund',
        'Version'  => '6.9.7',
        'deviceid' => 'web',
    ];

    public function __construct()
    {
        $this->http = new HttpClient([
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer'    => 'https://fund.eastmoney.com/',
            ],
        ]);
        $this->cache = CacheStoreFactory::getInstance();
        $this->breaker = new CircuitBreaker('fund');
        $configuredTtl = AppConfig::get('cache_ttl', []);
        $this->cacheTtl = array_merge(self::CACHE_TTL, is_array($configuredTtl) ? $configuredTtl : []);
        $this->negativeCacheTtl = (int)AppConfig::get('cache_degradation.negative_cache_ttl', self::NEGATIVE_CACHE_TTL);
        $this->stampedeWaitMs = (int)AppConfig::get('cache_degradation.stampede_wait_ms', 500);
        $this->stampedeLockTtl = (int)AppConfig::get('cache_degradation.stampede_lock_ttl', 5);
    }

    /**
     * 单只基金实时估值
     */
    public function estimate(string $code): DataSourceResult
    {
        $key = $this->cacheKey('estimate', $code);

        return $this->useCache('estimate', $key, function() use ($code) {
            return $this->withBreaker('estimate', function() use ($code) {
            $url = "https://fundgz.1234567.com.cn/js/{$code}.js?rt=" . time();
            $resp = $this->http->get($url, $this->eastmoneyFundMobileHeaders());

            if ($resp['error'] || $resp['http_code'] !== 200) {
                return DataSourceResult::error(self::SOURCE_NAME, 'estimate', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            }

            if (preg_match('/jsonpgz\((.+)\);?/s', $resp['body'], $matches)) {
                $data = json_decode($matches[1], true);
                if ($data) {
                    $result = [
                        'fundcode' => $data['fundcode'] ?? '',
                        'name'     => $data['name'] ?? '',
                        'jzrq'     => $data['jzrq'] ?? '',
                        'dwjz'     => $data['dwjz'] ?? '',
                        'gsz'      => $data['gsz'] ?? '',
                        'gszzl'    => $data['gszzl'] ?? '',
                        'gztime'   => $data['gztime'] ?? '',
                    ];
                    return DataSourceResult::success(self::SOURCE_NAME, 'estimate', $result);
                }
            }

            return DataSourceResult::error(self::SOURCE_NAME, 'estimate', 'parse_error', '解析基金估值数据失败，可能非交易时间或基金代码不存在');
            });
        });
    }

    /**
     * 批量基金实时估值
     *
     * @param string[] $codes 基金代码数组
     * @return DataSourceResult
     */
    public function batchEstimate(array $codes): DataSourceResult
    {
        $validCodes = array_values(array_unique(array_filter($codes, function($c) {
            return preg_match('/^\d{6}$/', $c);
        })));

        if (empty($validCodes)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'batch_estimate', 'invalid_code', '没有有效的基金代码');
        }

        $results = [];
        $cachedCount = 0;
        $fetchedCount = 0;
        foreach ($validCodes as $code) {
            $result = $this->estimate($code);
            if ($result->hasData()) {
                $results[$code] = $result->data;
                $cacheState = $result->meta['cache'] ?? '';
                if (in_array($cacheState, ['hit', 'hit_after_wait', 'stale', 'stale_fallback'], true)) {
                    $cachedCount++;
                } else {
                    $fetchedCount++;
                }
            } else {
                $results[$code] = null;
            }
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'batch_estimate', $results, [
            'total'    => count($validCodes),
            'cached'   => $cachedCount,
            'fetched'  => $fetchedCount,
        ]);
    }

    /**
     * 基金详细信息
     *
     * @param string[] $codes 基金代码数组（最多 20 个）
     */
    public function info(array $codes): DataSourceResult
    {
        $codeStr = implode(',', $codes);
        $key = $this->cacheKey('info', $codeStr);

        return $this->useCache('info', $key, function() use ($codes) {
            return $this->withBreaker('info', function() use ($codes) {
            $codeStr = implode(',', $codes);
            $url = "https://fundmobapi.eastmoney.com/FundMNewApi/FundMNFInfo?" . http_build_query(array_merge([
                'Fcodes'   => $codeStr,
                'pageSize' => 20,
            ], self::EASTMONEY_APP_PARAMS));

            $resp = $this->http->get($url, $this->eastmoneyFundMobileHeaders());

            if ($resp['error'] || $resp['http_code'] !== 200) {
                return $this->infoByDetailFallback($codes, '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            }

            $parsed = HttpClient::parseJson($resp['body']);
            if (!$parsed['ok'] || !isset($parsed['data']['Datas']) || ($parsed['data']['Success'] ?? true) === false) {
                return $this->infoByDetailFallback($codes, $parsed['data']['ErrMsg'] ?? $parsed['error'] ?? '解析基金数据失败');
            }

            $funds = [];
            foreach ($parsed['data']['Datas'] as $item) {
                $fund = $this->normalizeFundInfoItem($item);
                if ($fund['type'] === '' || $fund['fund_company'] === '' || $fund['fund_manager'] === '') {
                    $detail = $this->fetchFundDetail($fund['code']);
                    if ($detail !== null) {
                        $fund = $this->mergeMissingFundFields($fund, $detail);
                    }
                }
                $funds[] = $fund;
            }

            if (empty($funds)) {
                return $this->infoByDetailFallback($codes, '基金详情返回为空');
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'info', $funds, [
                'total' => count($funds),
            ]);
            });
        });
    }

    /**
     * 基金搜索
     */
    public function search(string $keyword): DataSourceResult
    {
        $key = $this->cacheKey('search', md5($keyword));

        return $this->useCache('search', $key, function() use ($keyword) {
            return $this->withBreaker('search', function() use ($keyword) {
            $encodedKey = urlencode($keyword);
            $url = "https://fundsuggest.eastmoney.com/FundSearch/api/FundSearchAPI.ashx?m=9&key={$encodedKey}";

            $resp = $this->http->get($url, $this->eastmoneyFundMobileHeaders());

            if ($resp['error'] || $resp['http_code'] !== 200) {
                return DataSourceResult::error(self::SOURCE_NAME, 'search', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            }

            $parsed = HttpClient::parseJson($resp['body']);
            if (!$parsed['ok'] || !isset($parsed['data']['Datas'])) {
                return DataSourceResult::error(self::SOURCE_NAME, 'search', 'parse_error', '解析搜索结果失败');
            }

            $results = [];
            foreach ($parsed['data']['Datas'] as $item) {
                $results[] = [
                    'code'         => $item['CODE'] ?? '',
                    'name'         => $item['NAME'] ?? '',
                    'pinyin'       => $item['JP'] ?? '',
                    'category'     => $item['CATEGORY'] ?? '',
                    'type'         => $item['FTYPE'] ?? '',
                    'nav'          => $item['DWJZ'] ?? '',
                    'nav_date'     => $item['FSRQ'] ?? '',
                    'min_purchase' => $item['MINSG'] ?? '',
                    'company'      => $item['JJGS'] ?? '',
                    'manager'      => $item['JJJL'] ?? '',
                    'is_buy'       => ($item['ISBUY'] ?? '0') === '1',
                ];
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'search', $results, [
                'keyword' => $keyword,
                'total'   => count($results),
            ]);
            });
        });
    }

    // ── 缓存层 (Phase 2 重构) ──

    /**
     * 统一缓存入口：防击穿 + negative cache + stale-while-revalidate
     */
    private function useCache(string $action, string $key, callable $fetcher): DataSourceResult
    {
        $ttl = $this->cacheTtl[$action] ?? 60;

        // 1. 检查缓存命中
        $cached = $this->getFromCache($key);
        if ($cached !== null) {
            $cached->meta['cache'] = 'hit';
            $cached->meta['cache_backend'] = $this->cache->backendName();
            return $cached;
        }

        // 2. 检查 negative cache
        $negCached = $this->cache->get($key . ':neg');
        if ($negCached !== null) {
            $result = DataSourceResult::error(
                $negCached['source'] ?? self::SOURCE_NAME,
                $negCached['action'] ?? $action,
                $negCached['error_code'] ?? 'negative_cache',
                $negCached['error_message'] ?? '上游近期失败，短暂缓存降级'
            );
            $result->meta['cache'] = 'negative';
            $result->meta['cache_backend'] = $this->cache->backendName();
            return $result;
        }

        // 3. 防击穿：尝试获取 per-key mutex
        $lockKey = "stampede:{$key}";
        $gotLock = $this->cache->acquireLock($lockKey, $this->stampedeLockTtl);

        if (!$gotLock) {
            usleep($this->stampedeWaitMs * 1000);
            $cached = $this->getFromCache($key);
            if ($cached !== null) {
                $cached->meta['cache'] = 'hit_after_wait';
                $cached->meta['cache_backend'] = $this->cache->backendName();
                return $cached;
            }

            $stale = $this->getStaleFromCache($key);
            if ($stale !== null) {
                $stale->meta['cache'] = 'stale';
                $stale->meta['cache_backend'] = $this->cache->backendName();
                return $stale;
            }

            return $this->stampedeTimeoutResult($action);
        }

        try {
            // 4. 执行上游请求
            $result = $fetcher();

            if ($result->hasData()) {
                $this->setToCache($key, $result, $ttl);
                $result->meta['cache'] = $gotLock ? 'miss' : 'miss_after_wait';
                $result->meta['cache_backend'] = $this->cache->backendName();
            } else {
                $this->setNegativeCache($key, $result);

                $stale = $this->getStaleFromCache($key);
                if ($stale !== null) {
                    $stale->meta['cache'] = 'stale_fallback';
                    $stale->meta['cache_backend'] = $this->cache->backendName();
                    $stale->meta['stale_fallback_reason'] = $result->errorMessage ?: '上游请求失败';
                    return $stale;
                }

                $result->meta['cache'] = 'miss';
                $result->meta['cache_backend'] = $this->cache->backendName();
            }

            return $result;
        } finally {
            if ($gotLock) {
                $this->cache->releaseLock($lockKey);
            }
        }
    }

    private function getFromCache(string $key): ?DataSourceResult
    {
        $data = $this->cache->get($key);
        if ($data === null) return null;

        if ($data['success'] ?? false) {
            return DataSourceResult::success(
                $data['source'] ?? self::SOURCE_NAME,
                $data['result_action'] ?? $data['action'] ?? '',
                $data['data'],
                $data['meta'] ?? []
            );
        }
        return null;
    }

    private function getStaleFromCache(string $key): ?DataSourceResult
    {
        $data = $this->cache->getStale($key);
        if ($data === null) return null;

        if ($data['success'] ?? false) {
            return DataSourceResult::success(
                $data['source'] ?? self::SOURCE_NAME,
                $data['result_action'] ?? $data['action'] ?? '',
                $data['data'],
                $data['meta'] ?? []
            );
        }
        return null;
    }

    private function setToCache(string $key, DataSourceResult $result, int $ttl): void
    {
        $this->cache->set($key, [
            'success'       => true,
            'source'        => $result->source,
            'action'        => $result->action,
            'result_action' => $result->action,
            'data'          => $result->data,
            'meta'          => $result->meta,
        ], $ttl);
    }

    private function setNegativeCache(string $key, DataSourceResult $result): void
    {
        $this->cache->set($key . ':neg', [
            'success'       => false,
            'source'        => $result->source,
            'action'        => $result->action,
            'error_code'    => $result->errorCode ?? 'unknown',
            'error_message' => $result->errorMessage ?? '请求失败',
        ], $this->negativeCacheTtl);
    }

    private function withBreaker(string $action, callable $fetcher): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'circuit_open', '基金接口熔断中，暂停请求', [
                'circuit_state' => $state['state'],
                'failures'      => $state['failures'],
                'last_reason'   => $state['last_reason'] ?? '',
            ]);
        }

        $result = $fetcher();
        if ($result->hasData()) {
            $this->breaker->success();
        } elseif ($result->errorCode !== 'invalid_code') {
            $this->breaker->failure($result->errorCode . ': ' . $result->errorMessage);
        }
        return $result;
    }

    private function stampedeTimeoutResult(string $action): DataSourceResult
    {
        $result = DataSourceResult::error(
            'cache',
            $action,
            'cache_wait_timeout',
            '缓存正在刷新，请稍后重试'
        );
        $result->meta['cache'] = 'stampede_wait_timeout';
        $result->meta['cache_backend'] = $this->cache->backendName();
        return $result;
    }

    /**
     * 统一缓存 key 生成
     */
    private function cacheKey(string $action, string $params): string
    {
        return "fund:{$action}:{$params}";
    }

    private function infoByDetailFallback(array $codes, string $reason): DataSourceResult
    {
        $funds = [];
        foreach ($codes as $code) {
            $detail = $this->fetchFundDetail($code);
            if ($detail !== null) {
                $funds[] = $detail;
            }
        }

        if (!empty($funds)) {
            return DataSourceResult::success(self::SOURCE_NAME, 'info', $funds, [
                'total' => count($funds),
                'upstream_fallback' => 'FundMNDetailInformation/FundMNNBasicInformation',
                'fallback_reason' => $reason,
            ]);
        }

        return DataSourceResult::error(self::SOURCE_NAME, 'info', 'parse_error', '解析基金数据失败: ' . $reason);
    }

    private function fetchFundDetail(string $code): ?array
    {
        foreach (['FundMNDetailInformation', 'FundMNNBasicInformation'] as $api) {
            $url = "https://fundmobapi.eastmoney.com/FundMNewApi/{$api}?" . http_build_query(array_merge([
                'FCODE' => $code,
            ], self::EASTMONEY_APP_PARAMS));

            $resp = $this->http->get($url, $this->eastmoneyFundMobileHeaders());

            if ($resp['error'] || $resp['http_code'] !== 200) {
                continue;
            }

            $parsed = HttpClient::parseJson($resp['body']);
            if (!$parsed['ok'] || !is_array($parsed['data']['Datas'] ?? null) || ($parsed['data']['Success'] ?? true) === false) {
                continue;
            }

            return $this->normalizeFundInfoItem($parsed['data']['Datas']);
        }

        return null;
    }

    private function normalizeFundInfoItem(array $item): array
    {
        return [
            'code'          => $item['FCODE'] ?? '',
            'name'          => $item['SHORTNAME'] ?? '',
            'type'          => $item['FTYPE'] ?? '',
            'nav_date'      => $item['PDATE'] ?? $item['FSRQ'] ?? $item['SYRQ'] ?? '',
            'nav'           => $item['NAV'] ?? $item['DWJZ'] ?? '',
            'acc_nav'       => $item['ACCNAV'] ?? $item['LJJZ'] ?? '',
            'nav_chg_rate'  => $item['NAVCHGRT'] ?? $item['RZDF'] ?? '',
            'latest_price'  => $item['GSZ'] ?? $item['NEWPRICE'] ?? '',
            'is_buy'        => ($item['ISBUY'] ?? '0') === '1' || ($item['BUY'] ?? false) === true,
            'min_purchase'  => $item['MINSG'] ?? '',
            'fund_company'  => $item['JJGS'] ?? '',
            'fund_manager'  => $item['JJJL'] ?? '',
        ];
    }

    private function mergeMissingFundFields(array $base, array $fallback): array
    {
        foreach ($fallback as $key => $value) {
            if (($base[$key] ?? '') === '' && $value !== '') {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function eastmoneyFundMobileHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 EastmoneyFund/6.9.7',
            'Referer'    => 'https://fund.eastmoney.com/',
            'Accept'     => 'application/json,text/plain,*/*',
        ];
    }
}
