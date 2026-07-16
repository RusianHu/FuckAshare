<?php
/**
 * StockSearchService — A 股代码/名称/拼音关键词搜索与安全解析。
 *
 * 搜索结果来自东方财富公开证券建议接口，但只保留沪深北 A 股。解析时仅在
 * 代码明确、名称/拼音精确匹配或候选唯一时自动选中；模糊多候选必须由用户确认。
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';

class StockSearchService
{
    const SOURCE_NAME = 'eastmoney_stock_search';
    const SUGGEST_URL = 'https://searchapi.eastmoney.com/api/suggest/get';
    const SUGGEST_TOKEN = 'D43BF722C8E33BDC906FB84D85E326E8';
    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 20;
    const DEFAULT_CACHE_TTL = 600;

    /** @var object */
    private $http;

    /** @var CacheStore */
    private $cache;

    /** @var int */
    private $cacheTtl;

    /**
     * 可注入 HTTP/缓存对象，便于无网络的确定性测试。
     */
    public function __construct($http = null, $cache = null)
    {
        $this->http = $http ?: new HttpClient([
            'timeout' => 8,
            'connect_timeout' => 4,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                'Referer: https://quote.eastmoney.com/',
                'Accept: application/json, text/plain, */*',
            ],
        ]);
        $this->cache = $cache ?: CacheStoreFactory::getInstance();
        $this->cacheTtl = max(30, (int)AppConfig::get('cache_ttl.stock_search', self::DEFAULT_CACHE_TTL));
    }

    /**
     * 搜索 A 股候选，支持代码、中文名称、拼音首字母及上游支持的拼音关键词。
     */
    public function search(string $keyword, int $limit = self::DEFAULT_LIMIT): DataSourceResult
    {
        $keyword = $this->normalizeKeyword($keyword);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        if ($keyword === '') {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_search', 'empty_keyword', '请输入股票代码、名称或拼音关键词');
        }
        if (mb_strlen($keyword, 'UTF-8') > 100) {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_search', 'keyword_too_long', '股票关键词长度不能超过 100 个字符');
        }

        // v2：候选会过滤 upstream-only 宽泛召回，避免读取旧版未过滤缓存。
        $cacheKey = 'stock_search:v2:' . sha1(mb_strtolower($keyword, 'UTF-8')) . ':' . $limit;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            return DataSourceResult::success(self::SOURCE_NAME, 'stock_search', $cached['data'], [
                'keyword' => $keyword,
                'total' => count($cached['data']),
                'cache' => 'hit',
                'cache_backend' => $this->cache->backendName(),
            ]);
        }

        $url = self::SUGGEST_URL . '?' . http_build_query([
            'input' => $keyword,
            'type' => 14,
            'count' => self::MAX_LIMIT,
            'token' => self::SUGGEST_TOKEN,
        ]);
        $resp = $this->http->get($url);
        if (($resp['error'] ?? null) || (int)($resp['http_code'] ?? 0) !== 200) {
            return DataSourceResult::error(
                self::SOURCE_NAME,
                'stock_search',
                'network_error',
                '股票搜索服务暂时不可用: ' . (($resp['error'] ?? '') ?: 'HTTP ' . (int)($resp['http_code'] ?? 0))
            );
        }

        $items = $this->parsePayload((string)($resp['body'] ?? ''), $keyword);
        if ($items === null) {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_search', 'parse_error', '股票搜索结果解析失败');
        }
        // 东方财富偶尔会在尾部返回名称、代码、拼音均不匹配的宽泛关联项。
        // 只要已有直接相关候选，就剔除这类 upstream-only 结果；若全是关联项则保留，
        // 避免破坏上游可能支持的历史名称或特殊别名召回。
        $directMatches = array_values(array_filter($items, function(array $item): bool {
            return ($item['match'] ?? 'upstream') !== 'upstream';
        }));
        if (!empty($directMatches)) {
            $items = $directMatches;
        }
        $items = array_slice($items, 0, $limit);
        $this->cache->set($cacheKey, [
            'cached_at' => time(),
            'data' => $items,
        ], $this->cacheTtl);

        return DataSourceResult::success(self::SOURCE_NAME, 'stock_search', $items, [
            'keyword' => $keyword,
            'total' => count($items),
            'cache_backend' => $this->cache->backendName(),
        ]);
    }

    /**
     * 把查询文本安全解析为唯一 A 股。模糊多候选返回 ambiguous_stock。
     */
    public function resolve(string $query): DataSourceResult
    {
        $query = $this->normalizeKeyword($query);
        if ($query === '') {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_resolve', 'empty_keyword', '请输入股票代码、名称或拼音关键词');
        }

        $direct = StockCode::parse($query);
        if ($direct->isValid() && $direct->isAStock()) {
            return DataSourceResult::success(self::SOURCE_NAME, 'stock_resolve', [
                'code' => $direct->code,
                'symbol' => $direct->toDisplay(),
                'name' => '',
                'pinyin' => '',
                'market' => $this->marketLabel($direct->market),
                'security_type' => $this->marketLabel($direct->market),
                'secid' => $direct->toEastmoneySecid(),
                'match' => 'code',
            ], ['query' => $query, 'resolution' => 'direct_code']);
        }

        $search = $this->search($query, self::MAX_LIMIT);
        if (!$search->success) {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_resolve', $search->errorCode ?: 'search_failed', $search->errorMessage ?: '股票搜索失败');
        }
        $items = is_array($search->data) ? $search->data : [];
        if (empty($items)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_resolve', 'stock_not_found', '未找到匹配的 A 股，请尝试股票全名、代码或拼音首字母', [
                'query' => $query,
                'candidates' => [],
            ]);
        }

        $exact = array_values(array_filter($items, function(array $item): bool {
            return in_array($item['match'] ?? '', ['exact_code', 'exact_name', 'exact_pinyin'], true);
        }));
        if (count($exact) === 1) {
            return DataSourceResult::success(self::SOURCE_NAME, 'stock_resolve', $exact[0], [
                'query' => $query,
                'resolution' => $exact[0]['match'],
            ]);
        }
        if (count($items) === 1) {
            return DataSourceResult::success(self::SOURCE_NAME, 'stock_resolve', $items[0], [
                'query' => $query,
                'resolution' => 'unique_candidate',
            ]);
        }

        return DataSourceResult::error(self::SOURCE_NAME, 'stock_resolve', 'ambiguous_stock', '关键词匹配到多只 A 股，请从候选列表中选择', [
            'query' => $query,
            'candidates' => array_slice($items, 0, self::DEFAULT_LIMIT),
        ]);
    }

    /**
     * 公开解析方法用于契约测试；无效 JSON 返回 null，合法空结果返回 []。
     */
    public function parsePayload(string $body, string $keyword): ?array
    {
        $parsed = HttpClient::parseJson($body);
        if (!$parsed['ok'] || !isset($parsed['data']['QuotationCodeTable'])) {
            return null;
        }
        $table = $parsed['data']['QuotationCodeTable'];
        if (!is_array($table) || (int)($table['Status'] ?? 0) !== 0) {
            return null;
        }

        $items = [];
        $seen = [];
        foreach ((array)($table['Data'] ?? []) as $index => $raw) {
            if (!is_array($raw) || ($raw['Classify'] ?? '') !== 'AStock') {
                continue;
            }
            $code = preg_replace('/\D/', '', (string)($raw['Code'] ?? ''));
            $stockCode = StockCode::parse($code);
            if (!$stockCode->isValid() || !$stockCode->isAStock() || isset($seen[$stockCode->toDisplay()])) {
                continue;
            }
            $seen[$stockCode->toDisplay()] = true;
            $name = trim((string)($raw['Name'] ?? ''));
            $pinyin = strtoupper(trim((string)($raw['PinYin'] ?? '')));
            $items[] = [
                'code' => $stockCode->code,
                'symbol' => $stockCode->toDisplay(),
                'name' => $name,
                'pinyin' => $pinyin,
                'market' => $this->marketLabel($stockCode->market),
                'security_type' => (string)($raw['SecurityTypeName'] ?? $this->marketLabel($stockCode->market)),
                'secid' => (string)($raw['QuoteID'] ?? $stockCode->toEastmoneySecid()),
                'match' => $this->matchType($keyword, $stockCode->code, $name, $pinyin),
                '_index' => (int)$index,
            ];
        }

        usort($items, function(array $a, array $b): int {
            $weights = [
                'exact_code' => 0,
                'exact_name' => 1,
                'exact_pinyin' => 2,
                'name_prefix' => 3,
                'pinyin_prefix' => 4,
                'name_contains' => 5,
                'upstream' => 6,
            ];
            $aw = $weights[$a['match']] ?? 99;
            $bw = $weights[$b['match']] ?? 99;
            return $aw === $bw ? (($a['_index'] ?? 0) <=> ($b['_index'] ?? 0)) : ($aw <=> $bw);
        });
        foreach ($items as &$item) {
            unset($item['_index']);
        }
        unset($item);
        return $items;
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = preg_replace('/[\x00-\x1F\x7F]/', '', trim($keyword));
        return preg_replace('/\s+/u', ' ', $keyword);
    }

    private function matchType(string $keyword, string $code, string $name, string $pinyin): string
    {
        $needle = mb_strtolower(trim($keyword), 'UTF-8');
        $cleanName = mb_strtolower($this->stripCorporateActionPrefix($name), 'UTF-8');
        $rawName = mb_strtolower($name, 'UTF-8');
        $upperNeedle = strtoupper($keyword);
        if ($needle === mb_strtolower($code, 'UTF-8')) return 'exact_code';
        if ($needle === $rawName || $needle === $cleanName) return 'exact_name';
        if ($upperNeedle !== '' && $upperNeedle === $pinyin) return 'exact_pinyin';
        if ($needle !== '' && (mb_strpos($rawName, $needle) === 0 || mb_strpos($cleanName, $needle) === 0)) return 'name_prefix';
        if ($upperNeedle !== '' && strpos($pinyin, $upperNeedle) === 0) return 'pinyin_prefix';
        if ($needle !== '' && (mb_strpos($rawName, $needle) !== false || mb_strpos($cleanName, $needle) !== false)) return 'name_contains';
        return 'upstream';
    }

    private function stripCorporateActionPrefix(string $name): string
    {
        return preg_replace('/^(?:N|C|XD|XR|DR|ST|\*ST)+/iu', '', trim($name));
    }

    private function marketLabel(string $market): string
    {
        $labels = ['SH' => '沪A', 'SZ' => '深A', 'BJ' => '北A'];
        return $labels[$market] ?? $market;
    }
}
