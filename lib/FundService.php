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
        'rank'        => 300,    // 基金排行：5 分钟
        'history'     => 300,    // 历史净值：5 分钟
        'index_profile' => 3600, // 基金跟踪指数画像：1 小时
        'dividend_history' => 300,
        'documents'   => 1800,   // 基金公告列表：30 分钟
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

    /**
     * 基金收益排行
     */
    public function rank(string $type = 'all', string $period = 'year', int $page = 1, int $pageSize = 30): DataSourceResult
    {
        $typeMap = [
            'all'  => 'all',
            'stock' => 'gp',
            'mixed' => 'hh',
            'bond'  => 'zq',
            'index' => 'zs',
            'qdii'  => 'qdii',
            'fof'   => 'fof',
        ];
        $periodMap = [
            'day' => 'rzdf',
            'week' => 'zzf',
            'month' => '1yzf',
            'quarter' => '3yzf',
            'half_year' => '6yzf',
            'year' => '1nzf',
            'two_year' => '2nzf',
            'three_year' => '3nzf',
            'this_year' => 'jnzf',
            'since' => 'lnzf',
        ];

        $typeKey = $typeMap[$type] ?? 'all';
        $sortKey = $periodMap[$period] ?? '1nzf';
        $page = max(1, min($page, 1000));
        $pageSize = max(5, min($pageSize, 100));
        $cacheKey = $this->cacheKey('rank', "{$type}:{$period}:{$page}:{$pageSize}");

        return $this->useCache('rank', $cacheKey, function() use ($type, $period, $typeKey, $sortKey, $page, $pageSize) {
            return $this->withBreaker('rank', function() use ($type, $period, $typeKey, $sortKey, $page, $pageSize) {
                $endDate = date('Y-m-d');
                $startDate = date('Y-m-d', strtotime('-1 year'));
                $url = 'https://fund.eastmoney.com/data/rankhandler.aspx?' . http_build_query([
                    'op' => 'ph',
                    'dt' => 'kf',
                    'ft' => $typeKey,
                    'rs' => '',
                    'gs' => '0',
                    'sc' => $sortKey,
                    'st' => 'desc',
                    'sd' => $startDate,
                    'ed' => $endDate,
                    'qdii' => '',
                    'tabSubtype' => ',,,,,',
                    'pi' => $page,
                    'pn' => $pageSize,
                    'dx' => '1',
                    'v' => sprintf('%.6f', microtime(true)),
                ]);

                $resp = $this->http->get($url, [
                    'Referer' => 'https://fund.eastmoney.com/data/fundranking.html',
                    'Accept'  => '*/*',
                ]);

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'rank', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseRankResponse($resp['body'], $period);
                if ($parsed === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'rank', 'parse_error', '解析基金排行数据失败');
                }

                $parsed['type'] = $type;
                $parsed['period'] = $period;
                return DataSourceResult::success(self::SOURCE_NAME, 'rank', $parsed['items'], [
                    'type' => $type,
                    'period' => $period,
                    'page' => $parsed['page'],
                    'page_size' => $parsed['page_size'],
                    'total' => $parsed['total'],
                    'total_pages' => $parsed['total_pages'],
                    'category_counts' => $parsed['category_counts'],
                ]);
            });
        });
    }

    /**
     * 基金历史净值
     */
    public function history(string $code, int $page = 1, int $pageSize = 30): DataSourceResult
    {
        $page = max(1, min($page, 200));
        $pageSize = max(5, min($pageSize, 100));
        $key = $this->cacheKey('history', "{$code}:{$page}:{$pageSize}");

        return $this->useCache('history', $key, function() use ($code, $page, $pageSize) {
            return $this->withBreaker('history', function() use ($code, $page, $pageSize) {
                $url = 'https://fundf10.eastmoney.com/F10DataApi.aspx?' . http_build_query([
                    'type' => 'lsjz',
                    'code' => $code,
                    'page' => $page,
                    'per'  => $pageSize,
                    'sdate' => '',
                    'edate' => '',
                    'rt' => sprintf('%.6f', microtime(true)),
                ]);

                $resp = $this->http->get($url, [
                    'Referer' => "https://fundf10.eastmoney.com/jjjz_{$code}.html",
                    'Accept'  => '*/*',
                ]);

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'history', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseHistoryResponse($resp['body']);
                if ($parsed === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'history', 'parse_error', '解析历史净值失败');
                }

                return DataSourceResult::success(self::SOURCE_NAME, 'history', $parsed['items'], [
                    'code' => $code,
                    'page' => $parsed['page'],
                    'page_size' => $pageSize,
                    'records' => $parsed['records'],
                    'pages' => $parsed['pages'],
                ]);
            });
        });
    }

    /**
     * 基金跟踪指数画像（由基金详情反推，不承诺完整指数编制细则）
     */
    public function indexProfile(string $code): DataSourceResult
    {
        $key = $this->cacheKey('index_profile', $code);

        return $this->useCache('index_profile', $key, function() use ($code) {
            return $this->withBreaker('index_profile', function() use ($code) {
                $detail = $this->fetchFundDetail($code);
                if ($detail === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'index_profile', 'empty_data', '基金详情未返回指数画像数据');
                }

                $strategy = (string)($detail['investment_strategy'] ?? '');
                $constraint = $this->extractTrackingConstraint($strategy);
                $hasIndex = ($detail['index_code'] ?? '') !== '' || ($detail['index_name'] ?? '') !== '';

                return DataSourceResult::success(self::SOURCE_NAME, 'index_profile', [
                    'fund_code' => $detail['code'] ?? $code,
                    'fund_name' => $detail['name'] ?? '',
                    'fund_full_name' => $detail['full_name'] ?? '',
                    'fund_type' => $detail['type'] ?? '',
                    'index_code' => $detail['index_code'] ?? '',
                    'index_name' => $detail['index_name'] ?? '',
                    'index_exchange' => $detail['index_exchange'] ?? '',
                    'benchmark' => $detail['benchmark'] ?? '',
                    'performance_compare' => $detail['performance_compare'] ?? '',
                    'investment_target' => $detail['investment_target'] ?? '',
                    'investment_strategy' => $strategy,
                    'tracking_error_constraint' => $constraint,
                    'scope_note' => 'v1 画像来自基金详情/业绩基准/投资策略字段，不代表完整指数官网编制细则或全量成分权重。',
                ], [
                    'code' => $code,
                    'capability_level' => $hasIndex ? 'fund_derived_index_profile' : 'fund_detail_only',
                    'partial' => !$hasIndex,
                ]);
            });
        });
    }

    /**
     * 基金历史分红记录（从历史净值分红送配列提取）
     */
    public function dividendHistory(string $code, int $page = 1, int $pageSize = 100): DataSourceResult
    {
        $page = max(1, min($page, 200));
        $pageSize = max(1, min($pageSize, 100));
        $key = $this->cacheKey('dividend_history', "{$code}:{$page}:{$pageSize}");

        return $this->useCache('dividend_history', $key, function() use ($code, $page, $pageSize) {
            return $this->withBreaker('dividend_history', function() use ($code, $page, $pageSize) {
                $url = 'https://fundf10.eastmoney.com/F10DataApi.aspx?' . http_build_query([
                    'type' => 'lsjz',
                    'code' => $code,
                    'page' => $page,
                    'per'  => $pageSize,
                    'sdate' => '',
                    'edate' => '',
                    'rt' => sprintf('%.6f', microtime(true)),
                ]);

                $resp = $this->http->get($url, [
                    'Referer' => "https://fundf10.eastmoney.com/jjjz_{$code}.html",
                    'Accept'  => '*/*',
                ]);

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'dividend_history', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseHistoryResponse($resp['body']);
                if ($parsed === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'dividend_history', 'parse_error', '解析基金分红历史失败');
                }

                $items = [];
                foreach ($parsed['items'] as $row) {
                    $dividend = trim((string)($row['dividend'] ?? ''));
                    if ($dividend === '') continue;
                    $items[] = [
                        'date' => $row['date'] ?? '',
                        'nav' => $row['nav'] ?? '',
                        'acc_nav' => $row['acc_nav'] ?? '',
                        'growth_rate' => $row['growth_rate'] ?? '',
                        'dividend' => $dividend,
                        'cash_per_unit' => $this->parseCashDividendPerUnit($dividend),
                        'purchase_status' => $row['purchase_status'] ?? '',
                        'redeem_status' => $row['redeem_status'] ?? '',
                    ];
                    if (count($items) >= $pageSize) {
                        break;
                    }
                }

                return DataSourceResult::success(self::SOURCE_NAME, 'dividend_history', $items, [
                    'code' => $code,
                    'page' => $parsed['page'],
                    'page_size' => $pageSize,
                    'records' => $parsed['records'],
                    'pages' => $parsed['pages'],
                    'dividend_records_in_page' => count($items),
                    'source_note' => '记录来自历史净值表的分红送配列。',
                ]);
            });
        });
    }

    /**
     * 基金公告/报告/合同文档
     */
    public function fundDocuments(string $code, int $page = 1, int $pageSize = 20, string $docType = 'all', bool $includeContent = false, int $contentLimit = 6000): DataSourceResult
    {
        $page = max(1, min($page, 200));
        $pageSize = max(1, min($pageSize, 100));
        $docType = $docType === '' ? 'all' : $docType;
        $contentLimit = max(1000, min($contentLimit, 20000));
        $key = $this->cacheKey('documents', implode(':', [$code, $page, $pageSize, $docType, $includeContent ? 1 : 0, $contentLimit]));

        return $this->useCache('documents', $key, function() use ($code, $page, $pageSize, $docType, $includeContent, $contentLimit) {
            return $this->withBreaker('documents', function() use ($code, $page, $pageSize, $docType, $includeContent, $contentLimit) {
                $url = 'https://fundf10.eastmoney.com/F10DataApi.aspx?' . http_build_query([
                    'type' => 'jjgg',
                    'code' => $code,
                    'page' => $page,
                    'per'  => $pageSize,
                    'rt' => sprintf('%.6f', microtime(true)),
                ]);

                $resp = $this->http->get($url, [
                    'Referer' => "https://fundf10.eastmoney.com/jjgg_{$code}.html",
                    'Accept'  => '*/*',
                ]);

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'documents', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseDocumentsResponse($resp['body']);
                if ($parsed === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'documents', 'parse_error', '解析基金公告列表失败');
                }

                $items = [];
                foreach ($parsed['items'] as $item) {
                    $item['doc_type'] = $this->classifyDocument($item['title'] ?? '', $item['announcement_type'] ?? '');
                    if ($docType !== 'all' && $item['doc_type'] !== $docType) {
                        continue;
                    }
                    if ($includeContent) {
                        $item = $this->attachDocumentContent($item, $contentLimit);
                    }
                    $items[] = $item;
                    if (count($items) >= $pageSize) {
                        break;
                    }
                }

                return DataSourceResult::success(self::SOURCE_NAME, 'documents', $items, [
                    'code' => $code,
                    'doc_type' => $docType,
                    'include_content' => $includeContent,
                    'content_limit' => $includeContent ? $contentLimit : 0,
                    'page' => $parsed['page'],
                    'page_size' => $pageSize,
                    'records' => $parsed['records'],
                    'pages' => $parsed['pages'],
                    'returned' => count($items),
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
            'full_name'     => $item['FULLNAME'] ?? '',
            'type'          => $item['FTYPE'] ?? '',
            'risk_level'    => $item['RISKLEVEL'] ?? $item['RLEVEL_SZ'] ?? '',
            'establish_date'=> $item['ESTABDATE'] ?? '',
            'scale'         => $item['ENDNAV'] ?? '',
            'scale_date'    => $item['FEGMRQ'] ?? '',
            'nav_date'      => $item['PDATE'] ?? $item['FSRQ'] ?? $item['SYRQ'] ?? '',
            'nav'           => $item['NAV'] ?? $item['DWJZ'] ?? '',
            'acc_nav'       => $item['ACCNAV'] ?? $item['LJJZ'] ?? '',
            'nav_chg_rate'  => $item['NAVCHGRT'] ?? $item['RZDF'] ?? '',
            'latest_price'  => $item['GSZ'] ?? $item['NEWPRICE'] ?? '',
            'is_buy'        => ($item['ISBUY'] ?? '0') === '1' || ($item['BUY'] ?? false) === true,
            'min_purchase'  => $item['MINSG'] ?? '',
            'fund_company'  => $item['JJGS'] ?? '',
            'fund_manager'  => $item['JJJL'] ?? '',
            'custodian'     => $item['TGYH'] ?? '',
            'management_fee'=> $item['MGREXP'] ?? '',
            'custody_fee'   => $item['TRUSTEXP'] ?? '',
            'benchmark'     => $item['BENCH'] ?? $item['PERFCMP'] ?? '',
            'investment_target' => $item['INVTGT'] ?? '',
            'investment_strategy' => $item['INVSTRA'] ?? '',
            'index_code'     => $item['INDEXCODE'] ?? '',
            'index_name'     => $item['INDEXNAME'] ?? '',
            'index_exchange' => $item['INDEXTEXCH'] ?? $item['NEWINDEXTEXCH'] ?? '',
            'performance_compare' => $item['PERFCMP'] ?? '',
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

    private function parseRankResponse(string $body, string $selectedPeriod): ?array
    {
        if (!preg_match('/datas:\s*(\[[\s\S]*?\])\s*,\s*allRecords:/', $body, $matches)) {
            return null;
        }

        $datas = json_decode($matches[1], true);
        if (!is_array($datas)) {
            return null;
        }

        $meta = [
            'total' => $this->extractRankNumber($body, 'allRecords'),
            'page' => $this->extractRankNumber($body, 'pageIndex'),
            'page_size' => $this->extractRankNumber($body, 'pageNum'),
            'total_pages' => $this->extractRankNumber($body, 'allPages'),
            'category_counts' => [
                'index' => $this->extractRankNumber($body, 'zs_count'),
                'stock' => $this->extractRankNumber($body, 'gp_count'),
                'mixed' => $this->extractRankNumber($body, 'hh_count'),
                'bond' => $this->extractRankNumber($body, 'zq_count'),
                'qdii' => $this->extractRankNumber($body, 'qdii_count'),
                'fof' => $this->extractRankNumber($body, 'fof_count'),
            ],
        ];

        $items = [];
        foreach ($datas as $row) {
            $cols = explode(',', $row);
            $items[] = [
                'code' => $cols[0] ?? '',
                'name' => $cols[1] ?? '',
                'pinyin' => $cols[2] ?? '',
                'nav_date' => $cols[3] ?? '',
                'nav' => $cols[4] ?? '',
                'acc_nav' => $cols[5] ?? '',
                'day_growth' => $cols[6] ?? '',
                'week_growth' => $cols[7] ?? '',
                'month_growth' => $cols[8] ?? '',
                'quarter_growth' => $cols[9] ?? '',
                'half_year_growth' => $cols[10] ?? '',
                'year_growth' => $cols[11] ?? '',
                'two_year_growth' => $cols[12] ?? '',
                'three_year_growth' => $cols[13] ?? '',
                'this_year_growth' => $cols[14] ?? '',
                'since_growth' => $cols[15] ?? '',
                'establish_date' => $cols[16] ?? '',
                'buy_status' => $cols[17] ?? '',
                'custom_growth' => $cols[18] ?? '',
                'fee' => $cols[19] ?? '',
                'discount_fee' => $cols[20] ?? '',
                'selected_growth' => $this->rankGrowthByPeriod($cols, $selectedPeriod),
            ];
        }

        return array_merge($meta, ['items' => $items]);
    }

    private function rankGrowthByPeriod(array $cols, string $period): string
    {
        $indexMap = [
            'day' => 6,
            'week' => 7,
            'month' => 8,
            'quarter' => 9,
            'half_year' => 10,
            'year' => 11,
            'two_year' => 12,
            'three_year' => 13,
            'this_year' => 14,
            'since' => 15,
        ];
        $idx = $indexMap[$period] ?? 11;
        return $cols[$idx] ?? '';
    }

    private function extractRankNumber(string $body, string $key): int
    {
        if (preg_match('/' . preg_quote($key, '/') . '\s*:\s*(\d+)/', $body, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    private function parseHistoryResponse(string $body): ?array
    {
        if (!preg_match('/content:\s*"([\s\S]*?)"\s*,\s*records:/', $body, $contentMatch)) {
            return null;
        }

        $html = stripcslashes($contentMatch[1]);
        $records = 0;
        $pages = 0;
        $page = 1;
        if (preg_match('/records\s*:\s*(\d+)/', $body, $m)) $records = (int)$m[1];
        if (preg_match('/pages\s*:\s*(\d+)/', $body, $m)) $pages = (int)$m[1];
        if (preg_match('/curpage\s*:\s*(\d+)/', $body, $m)) $page = (int)$m[1];

        preg_match_all('/<tr>(.*?)<\/tr>/is', $html, $rowMatches);
        $items = [];
        foreach ($rowMatches[1] as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cellMatches);
            if (count($cellMatches[1]) < 4) {
                continue;
            }
            $cells = array_map([$this, 'cleanHtmlCell'], $cellMatches[1]);
            $items[] = [
                'date' => $cells[0] ?? '',
                'nav' => $cells[1] ?? '',
                'acc_nav' => $cells[2] ?? '',
                'growth_rate' => str_replace('%', '', $cells[3] ?? ''),
                'purchase_status' => $cells[4] ?? '',
                'redeem_status' => $cells[5] ?? '',
                'dividend' => $cells[6] ?? '',
            ];
        }

        return [
            'items' => $items,
            'records' => $records,
            'pages' => $pages,
            'page' => $page,
        ];
    }

    private function parseDocumentsResponse(string $body): ?array
    {
        if (!preg_match('/content:\s*"([\s\S]*?)"\s*,\s*records:/', $body, $contentMatch)) {
            return null;
        }

        $html = stripcslashes($contentMatch[1]);
        $records = 0;
        $pages = 0;
        $page = 1;
        if (preg_match('/records\s*:\s*(\d+)/', $body, $m)) $records = (int)$m[1];
        if (preg_match('/pages\s*:\s*(\d+)/', $body, $m)) $pages = (int)$m[1];
        if (preg_match('/curpage\s*:\s*(\d+)/', $body, $m)) $page = (int)$m[1];

        preg_match_all('/<tr>(.*?)<\/tr>/is', $html, $rowMatches);
        $items = [];
        foreach ($rowMatches[1] as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cellMatches);
            if (count($cellMatches[1]) < 3) {
                continue;
            }
            $titleCell = $cellMatches[1][0] ?? '';
            $announcementUrl = $this->extractFirstHref($titleCell, false);
            $pdfUrl = $this->extractFirstHref($titleCell, true);
            $items[] = [
                'title' => $this->cleanHtmlCell($titleCell),
                'announcement_type' => $this->cleanHtmlCell($cellMatches[1][1] ?? ''),
                'date' => $this->cleanHtmlCell($cellMatches[1][2] ?? ''),
                'url' => $announcementUrl,
                'pdf_url' => $pdfUrl,
            ];
        }

        return [
            'items' => $items,
            'records' => $records,
            'pages' => $pages,
            'page' => $page,
        ];
    }

    private function extractFirstHref(string $html, bool $pdf): string
    {
        preg_match_all('/<a\b[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $matches);
        foreach ($matches[1] ?? [] as $href) {
            $isPdf = stripos($href, '.pdf') !== false || stripos($href, 'pdf.dfcfw.com') !== false;
            if ($pdf !== $isPdf) continue;
            if (strpos($href, '//') === 0) return 'https:' . $href;
            if (strpos($href, 'http://') === 0) return 'https://' . substr($href, 7);
            if (strpos($href, 'https://') === 0) return $href;
            return 'https://fund.eastmoney.com' . (strpos($href, '/') === 0 ? $href : '/' . $href);
        }
        return '';
    }

    private function classifyDocument(string $title, string $announcementType): string
    {
        $text = $title . ' ' . $announcementType;
        if (preg_match('/(季度报告|中期报告|年度报告|定期报告|季报|半年报|年报)/u', $text)) return 'periodic_report';
        if (preg_match('/(招募说明书|招募说明书更新|基金产品资料概要)/u', $text)) return 'prospectus';
        if (preg_match('/(基金合同|托管协议)/u', $text)) return 'contract';
        if (preg_match('/(分红|收益分配|派息)/u', $text)) return 'dividend';
        return 'other';
    }

    private function attachDocumentContent(array $item, int $contentLimit): array
    {
        $item['content_status'] = 'not_available';
        $item['content'] = '';
        $url = (string)($item['url'] ?? '');
        if ($url !== '') {
            $htmlContent = $this->fetchAnnouncementHtmlText($url, $contentLimit);
            if ($htmlContent !== '') {
                $item['content_status'] = 'html_extracted';
                $item['content'] = $htmlContent;
                return $item;
            }
        }

        $pdfUrl = (string)($item['pdf_url'] ?? '');
        if ($pdfUrl !== '') {
            $pdf = $this->extractPdfText($pdfUrl, $contentLimit);
            $item['content_status'] = $pdf['status'];
            $item['content'] = $pdf['content'];
            if (!empty($pdf['message'])) {
                $item['content_message'] = $pdf['message'];
            }
        }

        return $item;
    }

    private function fetchAnnouncementHtmlText(string $url, int $limit): string
    {
        $resp = $this->http->get($url, [
            'Referer' => 'https://fund.eastmoney.com/',
            'Accept'  => 'text/html,*/*',
        ]);
        if ($resp['error'] || $resp['http_code'] !== 200 || $resp['body'] === '') {
            return '';
        }
        $body = $resp['body'];
        $body = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', ' ', $body);
        $body = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', ' ', $body);
        $text = $this->cleanHtmlCell($body);
        if (mb_strlen($text) < 80) {
            return '';
        }
        return mb_substr($text, 0, $limit);
    }

    private function extractPdfText(string $pdfUrl, int $limit): array
    {
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return [
                'status' => 'parser_unavailable',
                'content' => '',
                'message' => 'PHP 命令执行函数不可用，未抽取 PDF 正文。',
            ];
        }
        if (!$this->pythonHasPypdf()) {
            return [
                'status' => 'parser_unavailable',
                'content' => '',
                'message' => 'Python pypdf 依赖不可用，未抽取 PDF 正文。',
            ];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fa_pdf_');
        if ($tmp === false) {
            return ['status' => 'tempfile_failed', 'content' => '', 'message' => '临时文件创建失败'];
        }

        $resp = $this->http->get($pdfUrl, [
            'Referer' => 'https://fund.eastmoney.com/',
            'Accept' => 'application/pdf,*/*',
        ]);
        if ($resp['error'] || $resp['http_code'] !== 200 || $resp['body'] === '') {
            @unlink($tmp);
            return ['status' => 'download_failed', 'content' => '', 'message' => $resp['error'] ?: "HTTP {$resp['http_code']}"];
        }
        file_put_contents($tmp, $resp['body']);

        $script = 'import sys,json; from pypdf import PdfReader; r=PdfReader(sys.argv[1]); limit=int(sys.argv[2]); text="";' .
            "\n" . 'for p in r.pages[:20]:' .
            "\n" . '    text += (p.extract_text() or "") + "\n"' .
            "\n" . '    if len(text) >= limit: break' .
            "\n" . 'print(json.dumps({"text": text[:limit]}, ensure_ascii=False))';
        $cmd = 'python -c ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg((string)$limit);
        $out = shell_exec($cmd);
        @unlink($tmp);

        $decoded = json_decode((string)$out, true);
        $text = is_array($decoded) ? trim((string)($decoded['text'] ?? '')) : '';
        if ($text === '') {
            return ['status' => 'pdf_extract_empty', 'content' => '', 'message' => 'PDF 正文为空或解析失败'];
        }
        return ['status' => 'pdf_extracted', 'content' => $text, 'message' => ''];
    }

    private function pythonHasPypdf(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $cmd = 'python -c ' . escapeshellarg('import importlib.util,sys; sys.exit(0 if importlib.util.find_spec("pypdf") else 1)');
        exec($cmd, $out, $code);
        return $code === 0;
    }

    private function parseCashDividendPerUnit(string $text): ?float
    {
        if (preg_match('/每份派现金\s*([0-9.]+)\s*元/u', $text, $m)) {
            return (float)$m[1];
        }
        if (preg_match('/派现金\s*([0-9.]+)\s*元/u', $text, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    private function extractTrackingConstraint(string $strategy): array
    {
        $result = [
            'daily_tracking_deviation' => null,
            'annual_tracking_error' => null,
            'raw_text' => '',
        ];
        if ($strategy === '') return $result;
        if (preg_match('/日均跟踪偏离度不超过\s*([0-9.]+%)/u', $strategy, $m)) {
            $result['daily_tracking_deviation'] = $m[1];
        }
        if (preg_match('/年跟踪误差不超过\s*([0-9.]+%)/u', $strategy, $m)) {
            $result['annual_tracking_error'] = $m[1];
        }
        if ($result['daily_tracking_deviation'] !== null || $result['annual_tracking_error'] !== null) {
            $result['raw_text'] = mb_substr($strategy, 0, 260);
        }
        return $result;
    }

    private function cleanHtmlCell(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
