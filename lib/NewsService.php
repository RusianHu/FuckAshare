<?php
/**
 * NewsService — 标的映射、新闻聚合、去重、缓存与确定性标题情绪快照。
 */

require_once __DIR__ . '/NewsDataProvider.php';
require_once __DIR__ . '/EastmoneyNewsClient.php';
require_once __DIR__ . '/MarketDataService.php';
require_once __DIR__ . '/FundService.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/DataSourceResult.php';

class NewsService
{
    const SOURCE_NAME = 'news_service';
    const DEFAULT_MARKET_KEYWORDS = ['A股', '沪指', '基金市场'];

    /** @var NewsDataProvider */
    private $provider;

    /** @var MarketDataService */
    private $market;

    /** @var FundService */
    private $fund;

    /** @var CacheStore */
    private $cache;

    /** @var array */
    private $ttl;

    /** @var string[] */
    private $defaultMarketKeywords;

    /** @var int */
    private $maxQueries;

    public function __construct(
        ?NewsDataProvider $provider = null,
        ?MarketDataService $market = null,
        ?FundService $fund = null,
        ?CacheStore $cache = null
    ) {
        $this->provider = $provider ?: new EastmoneyNewsClient();
        $this->market = $market ?: new MarketDataService();
        $this->fund = $fund ?: new FundService();
        $this->cache = $cache ?: CacheStoreFactory::getInstance();

        $cacheTtl = AppConfig::get('cache_ttl', []);
        $this->ttl = [
            'asset_news' => max(15, (int)($cacheTtl['news_asset'] ?? 60)),
            'market_hot_news' => max(15, (int)($cacheTtl['news_market'] ?? 60)),
            'sentiment_snapshot' => max(15, (int)($cacheTtl['news_sentiment'] ?? 90)),
        ];
        $configuredKeywords = AppConfig::get('news.default_market_keywords', self::DEFAULT_MARKET_KEYWORDS);
        $this->defaultMarketKeywords = $this->normalizeKeywords(is_array($configuredKeywords) ? $configuredKeywords : self::DEFAULT_MARKET_KEYWORDS);
        if (empty($this->defaultMarketKeywords)) $this->defaultMarketKeywords = self::DEFAULT_MARKET_KEYWORDS;
        $this->maxQueries = max(1, min(4, (int)AppConfig::get('news.max_queries', 4)));
    }

    public function assetNews(string $assetType, string $code = '', string $name = '', int $limit = 20): DataSourceResult
    {
        $assetType = strtolower(trim($assetType));
        if (!in_array($assetType, ['stock', 'fund'], true)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'asset_news', 'invalid_asset_type', 'asset_type 仅支持 stock 或 fund');
        }

        $code = $this->normalizeCode($code);
        $name = $this->normalizeKeyword($name, 80);
        $limit = max(1, min(50, $limit));
        if ($code === '' && $name === '') {
            return DataSourceResult::error(self::SOURCE_NAME, 'asset_news', 'missing_asset', '股票/基金代码与名称至少填写一项');
        }

        $key = $this->cacheKey('asset_news', [$assetType, $code, $name, $limit]);
        return $this->remember('asset_news', $key, function () use ($assetType, $code, $name, $limit) {
            $mappingStatus = $name !== '' ? 'provided' : 'not_resolved';
            if ($name === '' && $code !== '') {
                $resolved = $assetType === 'fund' ? $this->resolveFundName($code) : $this->resolveStockName($code);
                if ($resolved !== '') {
                    $name = $resolved;
                    $mappingStatus = 'resolved';
                }
            }

            $queries = $this->normalizeKeywords([$name, $this->searchableCode($code)]);
            if (empty($queries)) {
                return DataSourceResult::error(self::SOURCE_NAME, 'asset_news', 'missing_query', '无法生成有效新闻搜索词');
            }

            $upstream = $this->provider->searchMany(array_slice($queries, 0, $this->maxQueries), max(10, min(30, $limit)));
            if (!$upstream->success) return $upstream;

            $items = $this->assetPublicItems(
                is_array($upstream->data) ? $upstream->data : [],
                $assetType,
                $code,
                $name,
                $limit
            );
            return DataSourceResult::success($this->provider->sourceName(), 'asset_news', $items, [
                'asset' => ['type' => $assetType, 'code' => $code, 'name' => $name],
                'mapping_status' => $mappingStatus,
                'queries' => $queries,
                'total' => count($items),
                'partial' => (bool)($upstream->meta['partial'] ?? false),
                'query_statuses' => $upstream->meta['query_statuses'] ?? [],
                'fields' => ['title', 'source', 'published_at', 'url'],
                'content_exposed' => false,
            ]);
        });
    }

    /** @param string[] $keywords */
    public function marketHotNews(array $keywords = [], int $limit = 30): DataSourceResult
    {
        $keywords = $this->normalizeKeywords($keywords);
        if (empty($keywords)) $keywords = $this->defaultMarketKeywords;
        $keywords = array_slice($keywords, 0, $this->maxQueries);
        $limit = max(1, min(50, $limit));

        $key = $this->cacheKey('market_hot_news', [$keywords, $limit]);
        return $this->remember('market_hot_news', $key, function () use ($keywords, $limit) {
            $upstream = $this->provider->searchMany($keywords, max(10, min(30, (int)ceil($limit / max(1, count($keywords))) + 5)));
            if (!$upstream->success) return $upstream;

            $items = $this->publicItems(is_array($upstream->data) ? $upstream->data : [], $limit);
            return DataSourceResult::success($this->provider->sourceName(), 'market_hot_news', $items, [
                'keywords' => $keywords,
                'ranking' => 'published_at_desc_after_cross_keyword_dedupe',
                'total' => count($items),
                'partial' => (bool)($upstream->meta['partial'] ?? false),
                'query_statuses' => $upstream->meta['query_statuses'] ?? [],
                'fields' => ['title', 'source', 'published_at', 'url'],
                'content_exposed' => false,
            ]);
        });
    }

    /** @param string[] $keywords */
    public function sentimentSnapshot(
        string $scope = 'market',
        string $assetType = 'stock',
        string $code = '',
        string $name = '',
        array $keywords = [],
        int $limit = 30
    ): DataSourceResult {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['asset', 'market'], true)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'sentiment_snapshot', 'invalid_scope', 'scope 仅支持 asset 或 market');
        }
        $assetType = strtolower(trim($assetType));
        $code = $this->normalizeCode($code);
        $name = $this->normalizeKeyword($name, 80);
        $keywords = $this->normalizeKeywords($keywords);
        $limit = max(5, min(50, $limit));

        $key = $this->cacheKey('sentiment_snapshot', [$scope, $assetType, $code, $name, $keywords, $limit]);
        return $this->remember('sentiment_snapshot', $key, function () use ($scope, $assetType, $code, $name, $keywords, $limit) {
            $news = $scope === 'asset'
                ? $this->assetNews($assetType, $code, $name, $limit)
                : $this->marketHotNews($keywords, $limit);

            if (!$news->success) return $news;
            $items = is_array($news->data) ? $news->data : [];
            $snapshot = $this->buildSentimentSnapshot($items);
            $snapshot['scope'] = $scope;
            if ($scope === 'asset') {
                $snapshot['asset'] = $news->meta['asset'] ?? ['type' => $assetType, 'code' => $code, 'name' => $name];
            } else {
                $snapshot['keywords'] = $news->meta['keywords'] ?? $keywords;
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'sentiment_snapshot', $snapshot, [
                'provider' => $this->provider->sourceName(),
                'news_cache' => $news->meta['cache'] ?? 'unknown',
                'methodology' => 'deterministic_chinese_title_lexicon_v1',
                'content_exposed' => false,
                'disclaimer' => '情绪值仅基于新闻标题关键词和时间衰减，不代表事实判断或投资建议。',
            ]);
        });
    }

    private function resolveStockName(string $code): string
    {
        if ($code === '') return '';
        $result = $this->market->quote($code, MarketDataService::SOURCE_AUTO, true, false);
        if (!$result->success || !is_array($result->data)) return '';
        $row = isset($result->data[0]) && is_array($result->data[0]) ? $result->data[0] : $result->data;
        return $this->normalizeKeyword((string)($row['name'] ?? ''), 80);
    }

    private function resolveFundName(string $code): string
    {
        if (!preg_match('/^\d{6}$/', $code)) return '';
        $result = $this->fund->info([$code]);
        if (!$result->success || !is_array($result->data) || !is_array($result->data[0] ?? null)) return '';
        return $this->normalizeKeyword((string)($result->data[0]['name'] ?? $result->data[0]['full_name'] ?? ''), 80);
    }

    /** @return array<int,array{title:string,source:string,published_at:string,url:string}> */
    private function publicItems(array $items, int $limit): array
    {
        $deduped = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $title = $this->normalizeKeyword((string)($item['title'] ?? ''), 240);
            if ($title === '') continue;
            $url = (string)($item['url'] ?? '');
            if (!preg_match('#^https?://#i', $url)) $url = '';
            $public = [
                'title' => $title,
                'source' => $this->normalizeKeyword((string)($item['source'] ?? '东方财富'), 60),
                'published_at' => (string)($item['published_at'] ?? ''),
                'url' => $url,
            ];
            $dedupeKey = $this->dedupeKey($public);
            if (!isset($deduped[$dedupeKey]) || strcmp($public['published_at'], $deduped[$dedupeKey]['published_at']) > 0) {
                $deduped[$dedupeKey] = $public;
            }
        }
        $result = array_values($deduped);
        usort($result, function (array $a, array $b): int {
            return strcmp($b['published_at'], $a['published_at']);
        });
        return array_slice($result, 0, $limit);
    }

    /**
     * 标的新闻需要比市场关键词更严格的相关性约束，尤其避免基金名称搜索退化成泛基金资讯。
     * Provider 仍只提供公开四字段；_query 仅用于服务端筛选，最终会被移除。
     */
    private function assetPublicItems(array $items, string $assetType, string $code, string $name, int $limit): array
    {
        $searchCode = $this->searchableCode($code);
        $nameNeedle = $this->assetNameNeedle($name);
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $title = (string)($item['title'] ?? '');
            $query = (string)($item['_query'] ?? '');
            $titleMatchesName = $nameNeedle !== '' && mb_strpos($title, $nameNeedle) !== false;
            $titleMatchesCode = $searchCode !== '' && mb_strpos($title, $searchCode) !== false;
            $cameFromCodeQuery = $searchCode !== '' && $query === $searchCode;

            if ($titleMatchesName || $titleMatchesCode || $cameFromCodeQuery) {
                $filtered[] = $item;
            }
        }

        // 名称由用户直接给出但上游未返回精确标题时，股票允许保留代码查询结果；
        // 基金则宁可少报，也不把泛基金新闻冒充为该基金新闻。
        if (empty($filtered) && $assetType === 'stock') {
            foreach ($items as $item) {
                if (is_array($item) && (string)($item['_query'] ?? '') === $searchCode) $filtered[] = $item;
            }
        }
        return $this->publicItems($filtered, $limit);
    }

    private function assetNameNeedle(string $name): string
    {
        $needle = preg_replace('/(?:型证券投资基金|证券投资基金|发起式基金|联接基金|混合型|股票型|债券型|指数型|混合|基金)$/u', '', trim($name));
        $needle = trim((string)$needle);
        return mb_strlen($needle) >= 4 ? $needle : trim($name);
    }

    private function dedupeKey(array $item): string
    {
        $title = mb_strtolower((string)($item['title'] ?? ''));
        $title = preg_replace('/[\p{P}\p{S}\s]+/u', '', $title);
        return hash('sha256', (string)$title);
    }

    private function buildSentimentSnapshot(array $items): array
    {
        $weightedSum = 0.0;
        $weightTotal = 0.0;
        $positive = 0;
        $neutral = 0;
        $negative = 0;
        $sources = [];
        $scored = [];
        $newest = '';
        $oldest = '';

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $score = $this->scoreTitle((string)($item['title'] ?? ''));
            $weight = $this->recencyWeight((string)($item['published_at'] ?? ''));
            $weightedSum += $score * $weight;
            $weightTotal += $weight;
            if ($score >= 0.15) $positive++;
            elseif ($score <= -0.15) $negative++;
            else $neutral++;
            $source = (string)($item['source'] ?? '');
            if ($source !== '') $sources[$source] = true;
            $date = (string)($item['published_at'] ?? '');
            if ($date !== '') {
                if ($newest === '' || strcmp($date, $newest) > 0) $newest = $date;
                if ($oldest === '' || strcmp($date, $oldest) < 0) $oldest = $date;
            }
            $scored[] = ['score' => $score, 'item' => $item];
        }

        $overall = $weightTotal > 0 ? $weightedSum / $weightTotal : 0.0;
        $label = $overall >= 0.12 ? 'positive' : ($overall <= -0.12 ? 'negative' : 'neutral');
        $sampleSize = count($scored);
        $directional = $positive + $negative;
        $confidence = $sampleSize === 0 ? 0.0 : min(0.95, 0.35 + min(0.35, $sampleSize / 50) + min(0.25, $directional / max(1, $sampleSize) * 0.25));

        usort($scored, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });
        $positiveExamples = [];
        foreach ($scored as $entry) {
            if ($entry['score'] < 0.15) continue;
            $positiveExamples[] = $entry['item'];
            if (count($positiveExamples) >= 3) break;
        }
        $negativeExamples = [];
        foreach (array_reverse($scored) as $entry) {
            if ($entry['score'] > -0.15) continue;
            $negativeExamples[] = $entry['item'];
            if (count($negativeExamples) >= 3) break;
        }

        return [
            'label' => $label,
            'score' => round($overall, 3),
            'confidence' => round($confidence, 3),
            'sample_size' => $sampleSize,
            'counts' => ['positive' => $positive, 'neutral' => $neutral, 'negative' => $negative],
            'source_count' => count($sources),
            'newest_at' => $newest,
            'oldest_at' => $oldest,
            'positive_examples' => $positiveExamples,
            'negative_examples' => $negativeExamples,
            'methodology' => 'deterministic_chinese_title_lexicon_v1',
        ];
    }

    private function scoreTitle(string $title): float
    {
        $positiveWords = ['增长', '上调', '增持', '回购', '中标', '突破', '创新高', '扭亏', '预增', '盈利', '分红', '获批', '利好', '超预期', '净买入', '上涨', '涨停', '扩产', '签约', '落地', '改善', '修复'];
        $negativeWords = ['下调', '减持', '处罚', '立案', '调查', '亏损', '预亏', '暴雷', '风险', '违约', '退市', '跌停', '下跌', '终止', '问询', '警示', '诉讼', '被执行', '召回', '事故', '裁员', '清仓', '净卖出', '失守'];
        $positive = 0;
        $negative = 0;
        foreach ($positiveWords as $word) if (mb_strpos($title, $word) !== false) $positive++;
        foreach ($negativeWords as $word) if (mb_strpos($title, $word) !== false) $negative++;
        $total = $positive + $negative;
        return $total > 0 ? max(-1.0, min(1.0, ($positive - $negative) / $total)) : 0.0;
    }

    private function recencyWeight(string $publishedAt): float
    {
        if ($publishedAt === '') return 0.35;
        $timestamp = strtotime($publishedAt . ' Asia/Shanghai');
        if ($timestamp === false) return 0.35;
        $ageHours = max(0.0, (time() - $timestamp) / 3600);
        return max(0.2, exp(-$ageHours / 48));
    }

    private function remember(string $action, string $key, callable $fetcher): DataSourceResult
    {
        $cached = $this->cache->get($key);
        if (is_array($cached) && ($cached['success'] ?? false)) {
            $result = DataSourceResult::success($cached['source'] ?? self::SOURCE_NAME, $cached['action'] ?? $action, $cached['data'] ?? [], $cached['meta'] ?? []);
            $result->meta['cache'] = 'hit';
            $result->meta['cache_backend'] = $this->cache->backendName();
            return $result;
        }

        $negative = $this->cache->get($key . ':neg');
        if (is_array($negative)) {
            $result = DataSourceResult::error($negative['source'] ?? self::SOURCE_NAME, $action, $negative['error_code'] ?? 'negative_cache', $negative['error_message'] ?? '新闻上游近期失败');
            $result->meta['cache'] = 'negative';
            $result->meta['cache_backend'] = $this->cache->backendName();
            return $result;
        }

        $lockKey = 'stampede:' . $key;
        $gotLock = $this->cache->acquireLock($lockKey, 5);
        if (!$gotLock) {
            // AI 并行工具常会同时请求新闻列表和情绪快照；最多等待 3 秒复用首个请求结果。
            for ($attempt = 0; $attempt < 15; $attempt++) {
                usleep(200000);
                $cached = $this->cache->get($key);
                if (is_array($cached) && ($cached['success'] ?? false)) {
                    $result = DataSourceResult::success($cached['source'] ?? self::SOURCE_NAME, $cached['action'] ?? $action, $cached['data'] ?? [], $cached['meta'] ?? []);
                    $result->meta['cache'] = 'hit_after_wait';
                    $result->meta['cache_backend'] = $this->cache->backendName();
                    return $result;
                }
            }
            $stale = $this->cache->getStale($key);
            if (is_array($stale) && ($stale['success'] ?? false)) {
                $result = DataSourceResult::success($stale['source'] ?? self::SOURCE_NAME, $stale['action'] ?? $action, $stale['data'] ?? [], $stale['meta'] ?? []);
                $result->meta['cache'] = 'stale';
                $result->meta['cache_backend'] = $this->cache->backendName();
                return $result;
            }
            return DataSourceResult::error('cache', $action, 'cache_wait_timeout', '新闻缓存正在刷新，请稍后重试');
        }

        try {
            $result = $fetcher();
            if ($result->success) {
                $this->cache->set($key, [
                    'success' => true,
                    'source' => $result->source,
                    'action' => $result->action,
                    'data' => $result->data,
                    'meta' => $result->meta,
                ], $this->ttl[$action] ?? 60);
                $result->meta['cache'] = 'miss';
                $result->meta['cache_backend'] = $this->cache->backendName();
                return $result;
            }

            $this->cache->set($key . ':neg', [
                'source' => $result->source,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ], 10);
            $stale = $this->cache->getStale($key);
            if (is_array($stale) && ($stale['success'] ?? false)) {
                $fallback = DataSourceResult::success($stale['source'] ?? self::SOURCE_NAME, $stale['action'] ?? $action, $stale['data'] ?? [], $stale['meta'] ?? []);
                $fallback->meta['cache'] = 'stale_fallback';
                $fallback->meta['stale_fallback_reason'] = $result->errorMessage;
                return $fallback;
            }
            return $result;
        } finally {
            $this->cache->releaseLock($lockKey);
        }
    }

    private function cacheKey(string $action, array $parts): string
    {
        return 'news|' . $action . '|' . hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9.]/', '', trim($code)));
    }

    private function searchableCode(string $code): string
    {
        if (preg_match('/(\d{6})/', $code, $matches)) return $matches[1];
        return $code;
    }

    /** @return string[] */
    private function normalizeKeywords(array $keywords): array
    {
        $result = [];
        foreach ($keywords as $keyword) {
            if (!is_scalar($keyword)) continue;
            $keyword = $this->normalizeKeyword((string)$keyword, 60);
            if ($keyword !== '') $result[$keyword] = $keyword;
        }
        return array_values($result);
    }

    private function normalizeKeyword(string $keyword, int $maxLength): string
    {
        $keyword = (string)preg_replace('/[\x00-\x1F\x7F]/', '', trim($keyword));
        $keyword = (string)preg_replace('/\s+/u', ' ', $keyword);
        return mb_substr($keyword, 0, $maxLength);
    }
}
