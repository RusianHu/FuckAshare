<?php
/**
 * MarketDataService — 对前端暴露统一 quote、kline、hot、screener、fundx 能力
 *
 * 支持 source / fallback / raw 参数
 * 默认数据源策略：
 *   历史 K 线: Ashare 主 → 雪球兜底
 *   实时行情: 东方财富主 → 雪球兜底
 *   热门股票资金榜: 东方财富（无兜底）
 *   雪球热度榜: 雪球（独立产品）
 *   条件选股: 雪球（独立产品）
 *   动态资讯: 雪球（独立产品）
 *
 * Phase 2: 缓存层重构 → CacheStore 抽象 + 防击穿 + negative cache + stale-while-revalidate
 */

require_once __DIR__ . '/XueqiuClient.php';
require_once __DIR__ . '/EastmoneyClient.php';
require_once __DIR__ . '/AshareBridge.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';

class MarketDataService
{
    const SOURCE_AUTO       = 'auto';
    const SOURCE_EASTMONEY = 'eastmoney';
    const SOURCE_ASHARE    = 'ashare';
    const SOURCE_XUEQIU    = 'xueqiu';

    /** @var XueqiuClient|null */
    private $xueqiu;

    /** @var EastmoneyClient|null */
    private $eastmoney;

    /** @var AshareBridge|null */
    private $ashare;

    /** @var bool 调试模式 */
    private $debug;

    /** @var CacheStore */
    private $cache;

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
        'quote'      => 10,
        'kline_min'  => 60,
        'kline_day'  => 300,
        'hot_stock'  => 60,
        'screener'   => 120,
        'fundx'      => 180,
        'stock_flow' => 30,
        'sector_flow' => 60,
        'hot_stocks' => 30,
    ];

    /** @var int negative cache TTL (秒) */
    const NEGATIVE_CACHE_TTL = 10;

    /** @var int 防击穿锁等待超时 (毫秒) */
    const STAMPEDE_WAIT_MS = 500;

    /** @var int 防击穿锁超时 (秒) */
    const STAMPEDE_LOCK_TTL = 5;

    public function __construct(array $opts = [])
    {
        $this->debug = !empty($opts['debug']);
        $this->cache = CacheStoreFactory::getInstance();
        $configuredTtl = AppConfig::get('cache_ttl', []);
        $this->cacheTtl = array_merge(self::CACHE_TTL, is_array($configuredTtl) ? $configuredTtl : []);
        $this->negativeCacheTtl = (int)AppConfig::get('cache_degradation.negative_cache_ttl', self::NEGATIVE_CACHE_TTL);
        $this->stampedeWaitMs = (int)AppConfig::get('cache_degradation.stampede_wait_ms', self::STAMPEDE_WAIT_MS);
        $this->stampedeLockTtl = (int)AppConfig::get('cache_degradation.stampede_lock_ttl', self::STAMPEDE_LOCK_TTL);
    }

    // ── 公开接口 ──

    /**
     * 统一行情查询
     *
     * @param string $codes    逗号分隔的股票代码
     * @param string $source   数据源
     * @param bool   $fallback 是否允许兜底
     * @param bool   $raw      返回原始数据
     */
    public function quote(string $codes, string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        $codeList = array_values(array_filter(array_map('trim', explode(',', $codes)), 'strlen'));
        $key = $this->cacheKey('quote', implode(',', $codeList), $source, $fallback, $raw);

        return $this->useCache('quote', $key, function() use ($codeList, $codes, $source, $fallback, $raw) {
            if ($source === self::SOURCE_XUEQIU) {
                $result = $this->xueqiu()->quote($codeList[0] ?? '', $raw);
                if ($result->hasData() && !$raw) {
                    $result->data = [$result->data];
                }
                return $result;
            }

            if ($source === self::SOURCE_EASTMONEY) {
                return $this->eastmoney()->quote($codeList);
            }

            $emResult = $this->eastmoney()->quote($codeList);
            if ($emResult->hasData()) {
                return $emResult;
            }

            if ($fallback && count($codeList) === 1) {
                $xqResult = $this->xueqiu()->quote($codeList[0], $raw);
                if ($xqResult->hasData()) {
                    return DataSourceResult::fallback('xueqiu', 'quote', [$xqResult->data], 'eastmoney', $emResult->errorMessage ?? '东方财富请求失败');
                }
            }

            return $emResult;
        });
    }

    /**
     * 统一 K 线查询
     */
    public function kline(string $code, string $frequency = '1d', int $count = 120, string $endDate = '', string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        $cacheBucket = $this->klineCacheBucket($frequency);
        $key = $this->cacheKey($cacheBucket, "{$code}_{$frequency}_{$count}_{$endDate}", $source, $fallback, $raw);

        return $this->useCache($cacheBucket, $key, function() use ($code, $frequency, $count, $endDate, $source, $fallback, $raw) {
            if ($source === self::SOURCE_XUEQIU) {
                $period = $this->freqToXueqiuPeriod($frequency);
                $result = $this->xueqiu()->kline($code, $period, $count, $raw);
                if ($result->hasData() && !$raw && isset($result->data['data'])) {
                    $result->data = $result->data['data'];
                }
                return $result;
            }

            if ($source === self::SOURCE_ASHARE) {
                return $this->ashare()->kline($code, $frequency, $count, $endDate);
            }

            $asResult = $this->ashare()->kline($code, $frequency, $count, $endDate);
            if ($asResult->hasData()) {
                return $asResult;
            }

            if ($fallback) {
                $period = $this->freqToXueqiuPeriod($frequency);
                $xqResult = $this->xueqiu()->kline($code, $period, $count, $raw);
                if ($xqResult->hasData()) {
                    $fallbackData = (!$raw && isset($xqResult->data['data'])) ? $xqResult->data['data'] : $xqResult->data;
                    return DataSourceResult::fallback('xueqiu', 'kline', $fallbackData, 'ashare', $asResult->errorMessage ?? 'Ashare请求失败');
                }
            }

            return $asResult;
        });
    }

    /**
     * 雪球热度榜
     */
    public function hotStock(string $type = '10', int $size = 20, bool $raw = false): DataSourceResult
    {
        $key = $this->cacheKey('hot_stock', "{$type}_{$size}", '', false, $raw);

        return $this->useCache('hot_stock', $key, function() use ($type, $size, $raw) {
            return $this->xueqiu()->hot_stock($type, $size, $raw);
        });
    }

    /**
     * 条件选股
     */
    public function screener(int $page = 1, int $size = 20, string $orderBy = 'percent', string $order = 'desc', string $market = 'CN', string $type = 'sh_sz', bool $raw = false): DataSourceResult
    {
        $key = $this->cacheKey('screener', "{$page}_{$size}_{$orderBy}_{$order}_{$market}_{$type}", '', false, $raw);

        return $this->useCache('screener', $key, function() use ($page, $size, $orderBy, $order, $market, $type, $raw) {
            return $this->xueqiu()->screener($page, $size, $orderBy, $order, $market, $type, $raw);
        });
    }

    /**
     * 动态资讯
     */
    public function fundx(int $page = 1, string $source = '', int $lastId = 0, bool $raw = false): DataSourceResult
    {
        $key = $this->cacheKey('fundx', "{$page}_{$source}_{$lastId}", '', false, $raw);

        return $this->useCache('fundx', $key, function() use ($page, $source, $lastId, $raw) {
            return $this->xueqiu()->fundx($page, $source, $lastId, $raw);
        });
    }

    /**
     * 个股资金流向 (东方财富独占)
     */
    public function stockFlow(string $code, int $lmt = 0): DataSourceResult
    {
        $key = $this->cacheKey('stock_flow', "{$code}_{$lmt}");

        return $this->useCache('stock_flow', $key, function() use ($code, $lmt) {
            return $this->eastmoney()->stockFlow($code, $lmt);
        });
    }

    /**
     * 板块资金流向 (东方财富独占)
     */
    public function sectorFlow(string $key = 'f62', string $type = 'industry'): DataSourceResult
    {
        $cacheKey = $this->cacheKey('sector_flow', "{$key}_{$type}");

        return $this->useCache('sector_flow', $cacheKey, function() use ($key, $type) {
            return $this->eastmoney()->sectorFlow($key, $type);
        });
    }

    /**
     * 热门股票资金榜 (东方财富独占)
     */
    public function hotStocks(int $page = 1, int $pageSize = 50, string $sortField = 'f62', int $sortOrder = 1): DataSourceResult
    {
        $key = $this->cacheKey('hot_stocks', "{$page}_{$pageSize}_{$sortField}_{$sortOrder}");

        return $this->useCache('hot_stocks', $key, function() use ($page, $pageSize, $sortField, $sortOrder) {
            return $this->eastmoney()->hotStocks($page, $pageSize, $sortField, $sortOrder);
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

        // 2. 检查 negative cache（上游近期明确失败过）
        $negCached = $this->cache->get($key . ':neg');
        if ($negCached !== null) {
            $this->log("negative cache hit: {$key}");
            $result = DataSourceResult::error(
                $negCached['source'] ?? 'unknown',
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
            // 另一个进程正在刷新，短暂等待后重试缓存
            usleep($this->stampedeWaitMs * 1000);
            $cached = $this->getFromCache($key);
            if ($cached !== null) {
                $cached->meta['cache'] = 'hit_after_wait';
                $cached->meta['cache_backend'] = $this->cache->backendName();
                return $cached;
            }

            // 等待后仍未命中，尝试 stale 缓存
            $stale = $this->getStaleFromCache($key);
            if ($stale !== null) {
                $stale->meta['cache'] = 'stale';
                $stale->meta['cache_backend'] = $this->cache->backendName();
                $this->log("stale cache served (stampede wait): {$key}");
                return $stale;
            }

            return $this->stampedeTimeoutResult($action);
        }

        try {
            // 4. 执行上游请求
            $result = $fetcher();

            if ($result->hasData()) {
                // 成功 → 写入缓存
                $this->setToCache($key, $result, $ttl);
                $result->meta['cache'] = $gotLock ? 'miss' : 'miss_after_wait';
                $result->meta['cache_backend'] = $this->cache->backendName();
            } else {
                // 失败 → negative cache + 尝试 stale 降级
                $this->setNegativeCache($key, $result);

                $stale = $this->getStaleFromCache($key);
                if ($stale !== null) {
                    $stale->meta['cache'] = 'stale_fallback';
                    $stale->meta['cache_backend'] = $this->cache->backendName();
                    $stale->meta['stale_fallback_reason'] = $result->errorMessage ?: '上游请求失败';
                    $this->log("stale cache fallback on error: {$key}");
                    return $stale;
                }

                $result->meta['cache'] = 'miss';
                $result->meta['cache_backend'] = $this->cache->backendName();
            }

            return $result;
        } finally {
            // 5. 释放锁
            if ($gotLock) {
                $this->cache->releaseLock($lockKey);
            }
        }
    }

    /**
     * 从缓存读取并还原为 DataSourceResult
     */
    private function getFromCache(string $key): ?DataSourceResult
    {
        $data = $this->cache->get($key);
        if ($data === null) return null;

        return $this->hydrateCacheResult($data);
    }

    /**
     * 读取 stale 缓存并还原为 DataSourceResult
     */
    private function getStaleFromCache(string $key): ?DataSourceResult
    {
        $data = $this->cache->getStale($key);
        if ($data === null) return null;

        return $this->hydrateCacheResult($data);
    }

    /**
     * 将缓存数据数组还原为 DataSourceResult
     */
    private function hydrateCacheResult(array $data): DataSourceResult
    {
        if ($data['success'] ?? false) {
            return DataSourceResult::success(
                $data['source'] ?? 'unknown',
                $data['result_action'] ?? $data['action'] ?? '',
                $data['data'],
                $data['meta'] ?? []
            );
        }
        return null;
    }

    /**
     * 将 DataSourceResult 写入缓存
     */
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

    /**
     * 写入 negative cache（上游失败短暂缓存）
     */
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
     *
     * 格式: {action}:{params}:{source}:{fallback}:{raw}
     * Phase 2 规范：缓存 key 统一包含 action、参数、数据源、raw/fallback 标记
     */
    private function cacheKey(string $action, string $params, string $source = '', bool $fallback = false, bool $raw = false): string
    {
        $parts = [$action, $params];
        if ($source !== '') $parts[] = "src:{$source}";
        if ($fallback) $parts[] = 'fb:1';
        if ($raw) $parts[] = 'raw:1';
        return implode('|', $parts);
    }

    // ── 辅助 ──

    private function xueqiu(): XueqiuClient
    {
        if ($this->xueqiu === null) {
            $this->xueqiu = new XueqiuClient(['debug' => $this->debug]);
        }
        return $this->xueqiu;
    }

    private function eastmoney(): EastmoneyClient
    {
        if ($this->eastmoney === null) {
            $this->eastmoney = new EastmoneyClient();
        }
        return $this->eastmoney;
    }

    private function ashare(): AshareBridge
    {
        if ($this->ashare === null) {
            $this->ashare = new AshareBridge();
        }
        return $this->ashare;
    }

    /**
     * Ashare 频率 → 雪球 period
     */
    private function freqToXueqiuPeriod(string $freq): string
    {
        $map = [
            '1m'  => '1m',
            '5m'  => '5m',
            '15m' => '15m',
            '30m' => '30m',
            '60m' => '60m',
            '1d'  => 'day',
            '1w'  => 'week',
            '1M'  => 'month',
        ];
        return $map[$freq] ?? 'day';
    }

    private function klineCacheBucket(string $frequency): string
    {
        return in_array($frequency, ['1m', '5m', '15m', '30m', '60m'], true) ? 'kline_min' : 'kline_day';
    }

    private function log(string $msg): void
    {
        if ($this->debug) {
            error_log("[MarketDataService] {$msg}");
        }
    }
}
