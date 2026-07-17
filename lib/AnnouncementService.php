<?php
/**
 * AnnouncementService — 股票公告解析、事件分类、筛选、缓存与按需正文。
 */

require_once __DIR__ . '/AnnouncementDataProvider.php';
require_once __DIR__ . '/EastmoneyAnnouncementClient.php';
require_once __DIR__ . '/AnnouncementClassifier.php';
require_once __DIR__ . '/StockSearchService.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/DataSourceResult.php';

class AnnouncementService
{
    const SOURCE_NAME = 'announcement_service';
    const MARKETS = ['all', 'sh', 'sz', 'bj'];
    const IMPORTANCE_LEVELS = ['all', 'important', 'routine'];

    /** @var AnnouncementDataProvider */
    private $provider;
    /** @var AnnouncementClassifier */
    private $classifier;
    /** @var StockSearchService */
    private $stockSearch;
    /** @var CacheStore */
    private $cache;
    /** @var array */
    private $config;

    public function __construct(
        ?AnnouncementDataProvider $provider = null,
        ?CacheStore $cache = null,
        ?AnnouncementClassifier $classifier = null,
        ?StockSearchService $stockSearch = null
    ) {
        $this->provider = $provider ?: new EastmoneyAnnouncementClient();
        $this->cache = $cache ?: CacheStoreFactory::getInstance();
        $this->classifier = $classifier ?: new AnnouncementClassifier();
        $this->stockSearch = $stockSearch ?: new StockSearchService(null, $this->cache);
        $cacheTtl = AppConfig::get('cache_ttl', []);
        $this->config = [
            'list_ttl' => max(30, (int)($cacheTtl['announcement_list'] ?? 180)),
            'detail_ttl' => max(300, (int)($cacheTtl['announcement_detail'] ?? 86400)),
            'list_stale_ttl' => max(300, (int)AppConfig::get('announcement.list_stale_ttl', 1800)),
            'detail_stale_ttl' => max(3600, (int)AppConfig::get('announcement.detail_stale_ttl', 604800)),
            'negative_cache_ttl' => max(1, (int)AppConfig::get('announcement.negative_cache_ttl', 10)),
            'max_scan_pages' => max(1, min(10, (int)AppConfig::get('announcement.max_scan_pages', 3))),
            'upstream_page_size' => max(20, min(100, (int)AppConfig::get('announcement.upstream_page_size', 100))),
            'detail_content_limit' => max(1000, min(20000, (int)AppConfig::get('announcement.detail_content_limit', 12000))),
        ];
    }

    /**
     * @param array $params scope/code/name/market/event_type/importance/date_from/date_to/page/limit
     */
    public function list(array $params = []): DataSourceResult
    {
        $scope = strtolower(trim((string)($params['scope'] ?? 'market')));
        if (!in_array($scope, ['stock', 'market'], true)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'invalid_scope', 'scope 仅支持 stock 或 market');
        }
        $market = strtolower(trim((string)($params['market'] ?? 'all')));
        if (!in_array($market, self::MARKETS, true)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'invalid_market', 'market 参数不正确');
        }
        $eventType = strtolower(trim((string)($params['event_type'] ?? 'all')));
        if ($eventType !== 'all' && !in_array($eventType, AnnouncementClassifier::EVENT_TYPES, true)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'invalid_event_type', 'event_type 参数不正确');
        }
        $importance = strtolower(trim((string)($params['importance'] ?? 'important')));
        if (!in_array($importance, self::IMPORTANCE_LEVELS, true)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'invalid_importance', 'importance 参数不正确');
        }
        if ($scope === 'market') $importance = 'important';
        $page = max(1, min(100, (int)($params['page'] ?? 1)));
        $limit = max(1, min(50, (int)($params['limit'] ?? ($scope === 'market' ? 30 : 20))));

        $codeInput = trim((string)($params['code'] ?? ''));
        $nameInput = trim((string)($params['name'] ?? ''));
        $asset = ['code' => '', 'name' => '', 'market' => $market];
        if ($scope === 'stock') {
            $resolved = $this->resolveStock($codeInput, $nameInput);
            if (!$resolved->success) return $resolved;
            $asset = $resolved->data;
            if ($market === 'all' && !empty($asset['market'])) $market = $asset['market'];
        }

        $dates = $this->dateWindow(
            trim((string)($params['date_from'] ?? '')),
            trim((string)($params['date_to'] ?? '')),
            $scope
        );
        if (!$dates['success']) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'invalid_date_range', $dates['message']);
        }

        $normalized = [
            'scope' => $scope,
            'code' => $asset['code'],
            'name' => $asset['name'],
            'market' => $market,
            'event_type' => $eventType,
            'importance' => $importance,
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
            'page' => $page,
            'limit' => $limit,
        ];
        $key = $this->cacheKey('list', $normalized);
        return $this->remember('announcement_list', $key, function () use ($normalized, $asset, $dates) {
            $needed = $normalized['page'] * $normalized['limit'];
            $itemsById = [];
            $providerStatuses = [];
            $hasMoreUpstream = false;
            $partial = false;
            $scanLimited = false;
            $pagesScanned = 0;

            for ($upstreamPage = 1; $upstreamPage <= $this->config['max_scan_pages']; $upstreamPage++) {
                $upstream = $this->provider->listAnnouncements([
                    'market' => $normalized['market'],
                    'code' => $normalized['code'],
                    'date_from' => $normalized['date_from'],
                    'date_to' => $normalized['date_to'],
                    'page' => $upstreamPage,
                    'page_size' => $this->config['upstream_page_size'],
                ]);
                $pagesScanned++;
                $providerStatuses[] = [
                    'page' => $upstreamPage,
                    'success' => $upstream->success,
                    'count' => $upstream->success && is_array($upstream->data) ? count($upstream->data) : 0,
                    'error_code' => $upstream->errorCode,
                    'message' => $upstream->errorMessage,
                    'http_code' => $upstream->meta['http_code'] ?? null,
                ];
                if (!$upstream->success) {
                    if (empty($itemsById)) return $upstream;
                    $partial = true;
                    break;
                }

                $rawItems = is_array($upstream->data) ? $upstream->data : [];
                foreach ($rawItems as $rawItem) {
                    if (!is_array($rawItem)) continue;
                    $item = $this->publicListItem($rawItem);
                    if ($item === null || !$this->matchesFilters($item, $normalized)) continue;
                    $itemsById[$item['id']] = $item;
                }
                $hasMoreUpstream = (bool)($upstream->meta['has_more'] ?? false);
                if (count($itemsById) >= $needed || !$hasMoreUpstream || empty($rawItems)) break;
                if ($upstreamPage === $this->config['max_scan_pages']) $scanLimited = true;
            }

            $items = array_values($itemsById);
            usort($items, [$this, 'compareItems']);
            $offset = ($normalized['page'] - 1) * $normalized['limit'];
            $pageItems = array_slice($items, $offset, $normalized['limit']);
            if ($hasMoreUpstream && count($items) < $needed && $pagesScanned >= $this->config['max_scan_pages']) $scanLimited = true;

            return DataSourceResult::success($this->provider->sourceName(), 'announcement_list', $pageItems, [
                'scope' => $normalized['scope'],
                'asset' => $normalized['scope'] === 'stock' ? $asset : null,
                'market' => $normalized['market'],
                'event_type' => $normalized['event_type'],
                'importance' => $normalized['importance'],
                'date_from' => $normalized['date_from'],
                'date_to' => $normalized['date_to'],
                'date_boundary_adjusted' => $dates['date_boundary_adjusted'],
                'page' => $normalized['page'],
                'limit' => $normalized['limit'],
                'returned' => count($pageItems),
                'matched_in_scan' => count($items),
                'has_more' => $offset + count($pageItems) < count($items) || $hasMoreUpstream,
                'pages_scanned' => $pagesScanned,
                'scan_limited' => $scanLimited,
                'partial' => $partial,
                'active_provider' => $this->provider->sourceName(),
                'provider_chain' => [$this->provider->sourceName()],
                'provider_statuses' => $providerStatuses,
                'fields' => ['id', 'code', 'name', 'market', 'securities', 'title', 'disclosure_date', 'published_at', 'category_raw', 'event_type', 'importance', 'importance_reasons', 'classification_version', 'provider', 'provider_url', 'document_url', 'detail_available'],
                'content_exposed' => false,
            ]);
        });
    }

    public function detail(string $announcementId, ?int $contentLimit = null): DataSourceResult
    {
        $announcementId = strtoupper(trim($announcementId));
        if (!preg_match('/^AN\d{18}$/', $announcementId)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_detail', 'invalid_announcement_id', '公告 ID 格式不正确');
        }
        $contentLimit = $contentLimit === null ? $this->config['detail_content_limit'] : max(1000, min(20000, $contentLimit));
        $key = $this->cacheKey('detail', ['id' => $announcementId]);
        $result = $this->remember('announcement_detail', $key, function () use ($announcementId) {
            $upstream = $this->provider->announcementDetail($announcementId);
            if (!$upstream->success) return $upstream;
            $detail = is_array($upstream->data) ? $upstream->data : [];
            $classification = $this->classifier->classify((string)($detail['title'] ?? ''), (string)($detail['category_raw'] ?? ''));
            $detail = array_merge($detail, $classification);
            $detail['detail_available'] = true;
            return DataSourceResult::success($this->provider->sourceName(), 'announcement_detail', $detail, array_merge($upstream->meta, [
                'active_provider' => $this->provider->sourceName(),
                'provider_chain' => [$this->provider->sourceName()],
                'content_exposed' => true,
            ]));
        });
        if (!$result->success || !is_array($result->data)) return $result;

        $detail = $result->data;
        $content = (string)($detail['content'] ?? '');
        $originalChars = mb_strlen($content, 'UTF-8');
        $truncated = $originalChars > $contentLimit;
        if ($truncated) {
            $content = rtrim(mb_substr($content, 0, $contentLimit, 'UTF-8')) . "\n\n[正文已按长度限制截断]";
            $detail['content_status'] = 'truncated';
        }
        $detail['content'] = $content;
        $detail['content_chars'] = $originalChars;
        $detail['returned_content_chars'] = mb_strlen($content, 'UTF-8');
        $detail['content_truncated'] = $truncated;
        $result->data = $detail;
        $result->meta['content_limit'] = $contentLimit;
        return $result;
    }

    private function resolveStock(string $codeInput, string $nameInput): DataSourceResult
    {
        $query = $codeInput !== '' ? $codeInput : $nameInput;
        if ($query === '') {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'missing_stock', '股票范围需要 code 或 name');
        }
        $resolved = $this->stockSearch->resolve($query);
        if (!$resolved->success) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', $resolved->errorCode ?: 'stock_resolve_failed', $resolved->errorMessage ?: '股票解析失败', $resolved->meta);
        }
        $stock = is_array($resolved->data) ? $resolved->data : [];
        $code = preg_replace('/\D/', '', (string)($stock['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'invalid_stock', '无法解析为有效 A 股代码');
        }
        $market = $this->marketFromResolved((string)($stock['market'] ?? ''), $code);
        return DataSourceResult::success(self::SOURCE_NAME, 'stock_resolve', [
            'code' => $code,
            'name' => trim((string)($stock['name'] ?? $nameInput)),
            'market' => $market,
            'symbol' => (string)($stock['symbol'] ?? ''),
            'mapping_status' => $codeInput !== '' ? 'code' : 'resolved_name',
        ]);
    }

    private function dateWindow(string $dateFrom, string $dateTo, string $scope): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $adjusted = false;
        if ($dateTo === '') {
            // 晚间披露常被上游标记为下一自然日，默认窗口额外覆盖一天。
            $dateTo = $today->modify('+1 day')->format('Y-m-d');
            $adjusted = true;
        }
        if ($dateFrom === '') {
            $days = $scope === 'market' ? 3 : 30;
            $dateFrom = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        }
        if (!$this->validDate($dateFrom) || !$this->validDate($dateTo)) {
            return ['success' => false, 'message' => '日期必须为有效的 YYYY-MM-DD'];
        }
        $from = new DateTimeImmutable($dateFrom, $timezone);
        $to = new DateTimeImmutable($dateTo, $timezone);
        if ($from > $to) return ['success' => false, 'message' => 'date_from 不能晚于 date_to'];
        if ((int)$from->diff($to)->days > 365) return ['success' => false, 'message' => '日期跨度不能超过 365 日'];
        return [
            'success' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'date_boundary_adjusted' => $adjusted,
        ];
    }

    private function publicListItem(array $raw): ?array
    {
        $id = strtoupper(trim((string)($raw['id'] ?? '')));
        $title = trim((string)($raw['title'] ?? ''));
        if (!preg_match('/^AN\d{18}$/', $id) || $title === '') return null;
        $classification = $this->classifier->classify($title, (string)($raw['category_raw'] ?? ''));
        return array_merge([
            'id' => $id,
            'code' => (string)($raw['code'] ?? ''),
            'name' => (string)($raw['name'] ?? ''),
            'market' => (string)($raw['market'] ?? ''),
            'securities' => array_values((array)($raw['securities'] ?? [])),
            'title' => $title,
            'disclosure_date' => (string)($raw['disclosure_date'] ?? ''),
            'published_at' => $raw['published_at'] ?? null,
            'category_raw' => (string)($raw['category_raw'] ?? ''),
            'provider' => (string)($raw['provider'] ?? $this->provider->sourceName()),
            'provider_url' => (string)($raw['provider_url'] ?? ''),
            'document_url' => $raw['document_url'] ?? null,
            'detail_available' => (bool)($raw['detail_available'] ?? true),
        ], $classification);
    }

    private function matchesFilters(array $item, array $params): bool
    {
        if ($params['event_type'] !== 'all' && ($item['event_type'] ?? '') !== $params['event_type']) return false;
        if ($params['importance'] === 'important' && ($item['importance'] ?? '') !== 'important') return false;
        if ($params['importance'] === 'routine' && ($item['importance'] ?? '') !== 'routine') return false;
        $date = (string)($item['disclosure_date'] ?? '');
        if ($date !== '' && ($date < $params['date_from'] || $date > $params['date_to'])) return false;
        if ($params['code'] !== '') {
            $codes = array_map(function(array $security): string {
                return (string)($security['code'] ?? '');
            }, array_filter((array)($item['securities'] ?? []), 'is_array'));
            if (!in_array($params['code'], $codes, true) && (string)($item['code'] ?? '') !== $params['code']) return false;
        }
        return true;
    }

    private function compareItems(array $a, array $b): int
    {
        $aKey = (string)($a['published_at'] ?? $a['disclosure_date'] ?? '') . '|' . (string)($a['id'] ?? '');
        $bKey = (string)($b['published_at'] ?? $b['disclosure_date'] ?? '') . '|' . (string)($b['id'] ?? '');
        return strcmp($bKey, $aKey);
    }

    private function remember(string $action, string $key, callable $fetcher): DataSourceResult
    {
        $cached = $this->cache->get($key);
        if (is_array($cached) && ($cached['success'] ?? false)) return $this->cachedResult($cached, 'hit');

        $negative = $this->cache->get($key . ':neg');
        if (is_array($negative)) {
            $stale = $this->cache->get($key . ':stale');
            if (is_array($stale) && ($stale['success'] ?? false)) {
                $result = $this->cachedResult($stale, 'stale_fallback');
                $result->meta['stale_fallback_reason'] = (string)($negative['error_message'] ?? '上游近期失败');
                return $result;
            }
            $result = DataSourceResult::error($negative['source'] ?? self::SOURCE_NAME, $action, $negative['error_code'] ?? 'negative_cache', $negative['error_message'] ?? '公告上游近期失败');
            $result->meta['cache'] = 'negative';
            $result->meta['cache_backend'] = $this->cache->backendName();
            return $result;
        }

        $lockKey = 'stampede:' . $key;
        $gotLock = $this->cache->acquireLock($lockKey, 5);
        if (!$gotLock) {
            for ($attempt = 0; $attempt < 15; $attempt++) {
                usleep(200000);
                $cached = $this->cache->get($key);
                if (is_array($cached) && ($cached['success'] ?? false)) return $this->cachedResult($cached, 'hit_after_wait');
            }
            $stale = $this->cache->get($key . ':stale');
            if (is_array($stale) && ($stale['success'] ?? false)) return $this->cachedResult($stale, 'stale');
            return DataSourceResult::error('cache', $action, 'cache_wait_timeout', '公告缓存正在刷新，请稍后重试');
        }

        try {
            $result = $fetcher();
            if ($result->success) {
                $payload = [
                    'success' => true,
                    'source' => $result->source,
                    'action' => $result->action,
                    'data' => $result->data,
                    'meta' => $result->meta,
                ];
                $ttl = $action === 'announcement_detail' ? $this->config['detail_ttl'] : $this->config['list_ttl'];
                $staleTtl = $action === 'announcement_detail' ? $this->config['detail_stale_ttl'] : $this->config['list_stale_ttl'];
                $this->cache->set($key, $payload, $ttl);
                $this->cache->set($key . ':stale', $payload, $ttl + $staleTtl);
                $result->meta['cache'] = 'miss';
                $result->meta['cache_backend'] = $this->cache->backendName();
                return $result;
            }

            $this->cache->set($key . ':neg', [
                'source' => $result->source,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ], $this->config['negative_cache_ttl']);
            $stale = $this->cache->get($key . ':stale');
            if (is_array($stale) && ($stale['success'] ?? false)) {
                $fallback = $this->cachedResult($stale, 'stale_fallback');
                $fallback->meta['stale_fallback_reason'] = $result->errorMessage;
                return $fallback;
            }
            return $result;
        } finally {
            $this->cache->releaseLock($lockKey);
        }
    }

    private function cachedResult(array $cached, string $cacheStatus): DataSourceResult
    {
        $result = DataSourceResult::success($cached['source'] ?? self::SOURCE_NAME, $cached['action'] ?? 'announcement_list', $cached['data'] ?? [], $cached['meta'] ?? []);
        $result->meta['cache'] = $cacheStatus;
        $result->meta['cache_backend'] = $this->cache->backendName();
        return $result;
    }

    private function cacheKey(string $action, array $params): string
    {
        return 'announcement|' . $action . '|' . hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function marketFromResolved(string $market, string $code): string
    {
        $market = strtolower($market);
        if (strpos($market, '上') !== false || $market === 'sh' || $market === 'sse') return 'sh';
        if (strpos($market, '北') !== false || $market === 'bj' || $market === 'bse') return 'bj';
        if (strpos($market, '深') !== false || $market === 'sz' || $market === 'szse') return 'sz';
        if (preg_match('/^(?:4|8|92)/', $code)) return 'bj';
        return preg_match('/^(?:5|6|9)/', $code) ? 'sh' : 'sz';
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
