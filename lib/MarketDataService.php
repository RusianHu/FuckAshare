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
 */

require_once __DIR__ . '/XueqiuClient.php';
require_once __DIR__ . '/EastmoneyClient.php';
require_once __DIR__ . '/AshareBridge.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/DataSourceResult.php';

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

    /** @var string 缓存目录 */
    private $cacheDir;

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

    public function __construct(array $opts = [])
    {
        $this->debug = !empty($opts['debug']);
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
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
        $key = implode(',', $codeList) . "_{$source}_" . (int)$fallback . '_' . (int)$raw;

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
        $key = "{$code}_{$frequency}_{$count}_{$endDate}_{$source}_" . (int)$fallback . '_' . (int)$raw;

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
        return $this->useCache('hot_stock', "{$type}_{$size}_" . (int)$raw, function() use ($type, $size, $raw) {
            return $this->xueqiu()->hot_stock($type, $size, $raw);
        });
    }

    /**
     * 条件选股
     */
    public function screener(int $page = 1, int $size = 20, string $orderBy = 'percent', string $order = 'desc', string $market = 'CN', string $type = 'sh_sz', bool $raw = false): DataSourceResult
    {
        return $this->useCache('screener', "{$page}_{$size}_{$orderBy}_{$order}_{$market}_{$type}_" . (int)$raw, function() use ($page, $size, $orderBy, $order, $market, $type, $raw) {
            return $this->xueqiu()->screener($page, $size, $orderBy, $order, $market, $type, $raw);
        });
    }

    /**
     * 动态资讯
     */
    public function fundx(int $page = 1, string $source = '', int $lastId = 0, bool $raw = false): DataSourceResult
    {
        return $this->useCache('fundx', "{$page}_{$source}_{$lastId}_" . (int)$raw, function() use ($page, $source, $lastId, $raw) {
            return $this->xueqiu()->fundx($page, $source, $lastId, $raw);
        });
    }

    /**
     * 个股资金流向 (东方财富独占)
     */
    public function stockFlow(string $code, int $lmt = 0): DataSourceResult
    {
        return $this->useCache('stock_flow', "{$code}_{$lmt}", function() use ($code, $lmt) {
            return $this->eastmoney()->stockFlow($code, $lmt);
        });
    }

    /**
     * 板块资金流向 (东方财富独占)
     */
    public function sectorFlow(string $key = 'f62', string $type = 'industry'): DataSourceResult
    {
        return $this->useCache('sector_flow', "{$key}_{$type}", function() use ($key, $type) {
            return $this->eastmoney()->sectorFlow($key, $type);
        });
    }

    /**
     * 热门股票资金榜 (东方财富独占)
     */
    public function hotStocks(int $page = 1, int $pageSize = 50, string $sortField = 'f62', int $sortOrder = 1): DataSourceResult
    {
        return $this->useCache('hot_stocks', "{$page}_{$pageSize}_{$sortField}_{$sortOrder}", function() use ($page, $pageSize, $sortField, $sortOrder) {
            return $this->eastmoney()->hotStocks($page, $pageSize, $sortField, $sortOrder);
        });
    }

    // ── 缓存 ──

    private function useCache(string $action, string $key, callable $fetcher): DataSourceResult
    {
        $cached = $this->getCache($action, $key);
        if ($cached !== null) {
            $cached->meta['cache'] = 'hit';
            return $cached;
        }

        $result = $fetcher();
        if ($result->hasData()) {
            $this->setCache($action, $key, $result);
            $result->meta['cache'] = 'miss';
        }
        return $result;
    }

    private function getCache(string $action, string $key): ?DataSourceResult
    {
        $file = $this->cacheFile($action, $key);
        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data)) return null;

        $ttl = self::CACHE_TTL[$action] ?? 60;
        if (time() - ($data['cached_at'] ?? 0) > $ttl) {
            @unlink($file);
            return null;
        }

        if ($data['success']) {
            return DataSourceResult::success($data['source'], $data['result_action'] ?? $data['action'], $data['data'], $data['meta'] ?? []);
        }
        return null;
    }

    private function setCache(string $action, string $key, DataSourceResult $result): void
    {
        $file = $this->cacheFile($action, $key);
        $tmp = $file . '.' . getmypid() . '.tmp';
        $data = [
            'success'       => $result->success,
            'source'        => $result->source,
            'action'        => $action,
            'result_action' => $result->action,
            'data'          => $result->data,
            'meta'          => $result->meta,
            'cached_at'     => time(),
        ];
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    private function cacheFile(string $action, string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5("{$action}_{$key}") . '.json';
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
