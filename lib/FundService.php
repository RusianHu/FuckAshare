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
require_once __DIR__ . '/CsindexClient.php';

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

    /** @var array 基金研究聚合工具配置 */
    private $researchConfig;

    /** @var CsindexClient */
    private $csindex;

    /** @var array 缓存 TTL 配置 (秒) */
    const CACHE_TTL = [
        'estimate'    => 10,     // 基金实时估值：短缓存
        'batch_estimate' => 10,
        'info'        => 300,    // 基金详情：5 分钟
        'search'      => 600,   // 基金搜索：10 分钟
        'rank'        => 300,    // 基金排行：5 分钟
        'history'     => 300,    // 历史净值：5 分钟
        'history_window' => 300, // 历史净值定点窗口（分红事件前后）：5 分钟
        'nav_batch'   => 300,    // 批量最新净值（基金分红日历）：5 分钟
        'index_profile' => 3600, // 基金跟踪指数画像：1 小时
        'dividend_history' => 300,
        'dividend_profile' => 300,
        'documents'   => 1800,   // 基金公告列表：30 分钟
        'detail'      => 3600,   // 基金 F10 详情：1 小时
        'performance_stats' => 300, // 长历史统计：5 分钟
        'trade_rules' => 300,    // 交易规则：5 分钟
        'exposure'    => 3600,   // 风格暴露：1 小时
        'screen'      => 300,    // 候选召回：5 分钟
        'score'       => 120,    // 评分结果：2 分钟
        'holdings'    => 3600,   // 基金持仓：1 小时
        'index_kline' => 300,    // 跟踪指数 K 线：5 分钟
    ];

    /** @var int negative cache TTL (秒) */
    const NEGATIVE_CACHE_TTL = 10;

    /** @var float 风险调整指标使用的年化无风险利率（Sharpe/Sortino 分子） */
    const RF_ANNUAL = 0.02;

    /** @var int 年化交易日因子（A 股近似） */
    const TRADING_DAYS = 250;

    /** @var array 主题负向词：召回时 name 命中且不命中任何主题词则降级为 negative */
    const THEME_NEGATIVE_WORDS = ['半导体', '芯片', '集成电路', '新能源车', '新能源汽车', '光伏', '锂电', '医药', '医疗', '生物', '军工', '国防', '科技', '人工智能', '消费', '白酒', '食品饮料'];

    const EASTMONEY_APP_PARAMS = [
        'plat'     => 'Iphone',
        'appType'  => 'ttjj',
        'product'  => 'EFund',
        'Version'  => '6.9.7',
        'deviceid' => 'web',
    ];

    public function __construct(?CsindexClient $csindex = null)
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
        $this->csindex = $csindex ?: new CsindexClient();
        $configuredTtl = AppConfig::get('cache_ttl', []);
        $this->cacheTtl = array_merge(self::CACHE_TTL, is_array($configuredTtl) ? $configuredTtl : []);
        // 基金分红模块允许单独覆盖批量净值 TTL；避免配置项存在但不生效。
        $fundDividendNavTtl = AppConfig::get('fund_dividend.nav_ttl', null);
        if ($fundDividendNavTtl !== null) {
            $this->cacheTtl['nav_batch'] = max(1, (int)$fundDividendNavTtl);
        }
        $this->negativeCacheTtl = (int)AppConfig::get('cache_degradation.negative_cache_ttl', self::NEGATIVE_CACHE_TTL);
        $this->stampedeWaitMs = (int)AppConfig::get('cache_degradation.stampede_wait_ms', 500);
        $this->stampedeLockTtl = (int)AppConfig::get('cache_degradation.stampede_lock_ttl', 5);
        $research = AppConfig::get('fund_research', []);
        $this->researchConfig = array_merge([
            'target_history_days'   => 500,
            'max_screen_candidates' => 20,
            'max_score_candidates'  => 20,
            'max_parallel_workers'  => 4,
            'retry_network_errors'  => true,
            'screen_page_size'      => 50,
        ], is_array($research) ? $research : []);
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
        $missing = [];
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
                $missing[] = $code;
            }
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'batch_estimate', $results, [
            'total'    => count($validCodes),
            'cached'   => $cachedCount,
            'fetched'  => $fetchedCount,
            // 批量必须声明请求数/返回数/缺失代码，不把"少返回几项"当作完整成功
            'counts'   => [
                'expected' => count($validCodes),
                'returned' => count($validCodes) - count($missing),
                'missing'  => $missing,
            ],
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

            $parsed = $this->parseSearchResponse($resp['body']);
            if ($parsed === null) {
                return DataSourceResult::error(self::SOURCE_NAME, 'search', 'parse_error', '解析搜索结果失败');
            }

            $results = $parsed['items'];

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
                $url = $this->buildRankUrl($typeKey, $sortKey, $page, $pageSize, $startDate, $endDate);

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
        $key = $this->cacheKey('history', "v2:{$code}:{$page}:{$pageSize}");

        return $this->useCache('history', $key, function() use ($code, $page, $pageSize) {
            return $this->withBreaker('history', function() use ($code, $page, $pageSize) {
                $url = $this->historyApiUrl($code, $page, $pageSize);

                $resp = $this->http->get($url, $this->historyApiHeaders($code));

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
     * 批量基金最新净值（基金分红日历专用）。
     *
     * 仅调用 FundMNFInfo 批量接口取 NAV/净值日期/累计净值/日增长率，
     * 不触发 fetchFundDetail 逐基金回退，避免列表场景的 N+1 调用。
     * 单次最多 50 只/请求，超出自动分批。
     *
     * @param string[] $codes 基金代码数组
     * @return DataSourceResult data 为 [{code,name,nav,nav_date,acc_nav,nav_chg_rate}, ...]
     */
    public function batchNetValues(array $codes): DataSourceResult
    {
        $validCodes = array_values(array_unique(array_filter($codes, function ($c) {
            return is_string($c) && preg_match('/^\d{6}$/', $c);
        })));
        if (empty($validCodes)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'nav_batch', 'invalid_code', '没有有效的基金代码');
        }

        $batchSize = max(1, min(50, (int)AppConfig::get('fund_dividend.nav_batch_size', 50)));
        $key = $this->cacheKey('nav_batch', md5(implode(',', $validCodes)));

        return $this->useCache('nav_batch', $key, function () use ($validCodes, $batchSize) {
            return $this->withBreaker('nav_batch', function () use ($validCodes, $batchSize) {
                $items = [];
                $failures = [];
                foreach (array_chunk($validCodes, $batchSize) as $batch) {
                    $url = "https://fundmobapi.eastmoney.com/FundMNewApi/FundMNFInfo?" . http_build_query(array_merge([
                        'Fcodes'   => implode(',', $batch),
                        'pageSize' => $batchSize,
                    ], self::EASTMONEY_APP_PARAMS));

                    $resp = $this->http->get($url, $this->eastmoneyFundMobileHeaders());
                    if ($resp['error'] || $resp['http_code'] !== 200) {
                        $failures[] = ['stage' => 'nav_batch', 'codes' => $batch, 'code' => 'network_error', 'message' => $resp['error'] ?: "HTTP {$resp['http_code']}"];
                        continue;
                    }
                    $parsed = HttpClient::parseJson($resp['body']);
                    if (!$parsed['ok'] || !isset($parsed['data']['Datas']) || ($parsed['data']['Success'] ?? true) === false) {
                        $failures[] = ['stage' => 'nav_batch', 'codes' => $batch, 'code' => 'parse_error', 'message' => $parsed['data']['ErrMsg'] ?? $parsed['error'] ?? '解析批量净值失败'];
                        continue;
                    }
                    foreach ($parsed['data']['Datas'] as $item) {
                        if (!is_array($item)) continue;
                        $code = (string)($item['FCODE'] ?? '');
                        if ($code === '') continue;
                        $items[] = [
                            'code' => $code,
                            'name' => (string)($item['SHORTNAME'] ?? ''),
                            'nav' => is_numeric($item['NAV'] ?? null) ? (float)$item['NAV'] : (is_numeric($item['DWJZ'] ?? null) ? (float)$item['DWJZ'] : null),
                            'nav_date' => (string)($item['PDATE'] ?? $item['FSRQ'] ?? $item['SYRQ'] ?? ''),
                            'acc_nav' => is_numeric($item['ACCNAV'] ?? null) ? (float)$item['ACCNAV'] : (is_numeric($item['LJJZ'] ?? null) ? (float)$item['LJJZ'] : null),
                            'nav_chg_rate' => is_numeric($item['NAVCHGRT'] ?? null) ? (float)$item['NAVCHGRT'] : (is_numeric($item['RZDF'] ?? null) ? (float)$item['RZDF'] : null),
                        ];
                    }
                }

                if (empty($items)) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'nav_batch', 'empty_data', '批量净值未返回任何数据', [
                        'failures' => $failures,
                    ]);
                }

                return DataSourceResult::success(self::SOURCE_NAME, 'nav_batch', $items, [
                    'requested' => count($validCodes),
                    'returned' => count($items),
                    'failures' => $failures,
                ]);
            });
        });
    }

    /**
     * 基金历史净值定点窗口（基金分红事件前后净值图专用）。
     *
     * 使用当前 F10 历史净值 JSON API，通过 startDate/endDate 定点取窗，
     * 返回单位净值、累计净值和日增长率。用于分红事件前后净值窗口展示。
     */
    public function historyWindow(string $code, string $sdate, string $edate): DataSourceResult
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'history_window', 'invalid_code', '基金代码格式不正确');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sdate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $edate)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'history_window', 'invalid_date', '日期必须为 YYYY-MM-DD');
        }
        if ($sdate > $edate) {
            return DataSourceResult::error(self::SOURCE_NAME, 'history_window', 'invalid_date', '开始日期不能晚于结束日期');
        }
        $key = $this->cacheKey('history_window', "v2:{$code}:{$sdate}:{$edate}");

        return $this->useCache('history_window', $key, function () use ($code, $sdate, $edate) {
            return $this->withBreaker('history_window', function () use ($code, $sdate, $edate) {
                $url = $this->historyApiUrl($code, 1, 100, $sdate, $edate);

                $resp = $this->http->get($url, $this->historyApiHeaders($code));

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'history_window', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseHistoryResponse($resp['body']);
                if ($parsed === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'history_window', 'parse_error', '解析历史净值窗口失败');
                }

                $items = array_values(array_filter($parsed['items'], function ($item) use ($sdate, $edate) {
                    $d = (string)($item['date'] ?? '');
                    return $d !== '' && $d >= $sdate && $d <= $edate;
                }));
                usort($items, function ($a, $b) { return strcmp((string)$a['date'], (string)$b['date']); });

                return DataSourceResult::success(self::SOURCE_NAME, 'history_window', $items, [
                    'code' => $code,
                    'sdate' => $sdate,
                    'edate' => $edate,
                    'records' => count($items),
                    'source_url' => $url,
                    'source_note' => '定点窗口来自历史净值接口 sdate/edate 参数；返回单位净值、累计净值和日增长率。',
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
     * 中证官方指数定点历史窗口。累计净值比较使用全收益指数 CNY010。
     */
    public function indexHistoryWindow(string $indexCode, string $startDate, string $endDate, bool $totalReturn = true): DataSourceResult
    {
        $indexCode = strtoupper(trim($indexCode));
        if ($totalReturn && preg_match('/^\d{6}$/', $indexCode)) {
            $indexCode .= 'CNY010';
        }
        return $this->csindex->history($indexCode, $startDate, $endDate);
    }

    /**
     * 基金历史分红记录。
     *
     * 直接读取东方财富分红送配页。该页按“分红事件”列出权益登记日、
     * 除息日、每份金额和现金发放日；不要再把历史净值页的 records/pages
     * 误当成分红次数和分红分页。
     */
    public function dividendHistory(string $code, int $page = 1, int $pageSize = 100): DataSourceResult
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'dividend_history', 'invalid_code', '基金代码格式不正确');
        }
        $page = max(1, min($page, 200));
        $pageSize = max(1, min($pageSize, 100));
        $key = $this->cacheKey('dividend_history', "{$code}:{$page}:{$pageSize}");

        return $this->useCache('dividend_history', $key, function() use ($code, $page, $pageSize) {
            return $this->withBreaker('dividend_history', function() use ($code, $page, $pageSize) {
                $url = "https://fundf10.eastmoney.com/fhsp_{$code}.html";

                $resp = $this->http->get($url, [
                    'Referer' => "https://fundf10.eastmoney.com/fhsp_{$code}.html",
                    'Accept'  => 'text/html,*/*',
                ]);

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'dividend_history', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseDividendHistoryPage($resp['body']);
                if ($parsed === null) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'dividend_history', 'parse_error', '解析基金分红历史失败');
                }

                $total = count($parsed['items']);
                $pages = $total > 0 ? (int)ceil($total / $pageSize) : 0;
                $items = array_slice($parsed['items'], ($page - 1) * $pageSize, $pageSize);

                return DataSourceResult::success(self::SOURCE_NAME, 'dividend_history', $items, [
                    'code' => $code,
                    'fund_name' => $parsed['fund_name'],
                    'page' => $page,
                    'page_size' => $pageSize,
                    'records' => $total,
                    'pages' => $pages,
                    'total_dividend_events' => $total,
                    'dividend_records_in_page' => count($items),
                    'annual_summary' => $parsed['annual_summary'],
                    'source_url' => $url,
                    'source_note' => '记录来自基金分红送配事件表；records/pages 均为分红事件语义，不是历史净值记录。',
                ]);
            });
        });
    }

    /**
     * 基金分红档案：聚合本基金、最新分红公告及联接基金目标 ETF 的分红事件。
     */
    public function dividendProfile(string $code, int $limit = 10, bool $includeRelated = true, bool $includeAnnouncements = true, int $announcementLimit = 5): DataSourceResult
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'dividend_profile', 'invalid_code', '基金代码格式不正确');
        }
        $limit = max(1, min($limit, 50));
        $announcementLimit = max(1, min($announcementLimit, 20));
        $key = $this->cacheKey('dividend_profile', implode(':', [$code, $limit, (int)$includeRelated, (int)$includeAnnouncements, $announcementLimit]));

        return $this->useCache('dividend_profile', $key, function() use ($code, $limit, $includeRelated, $includeAnnouncements, $announcementLimit) {
            $detail = $this->fetchFundDetail($code);
            if ($detail === null) {
                return DataSourceResult::error(self::SOURCE_NAME, 'dividend_profile', 'empty_data', '基金详情未返回，无法建立分红档案');
            }

            $isLinkFund = preg_match('/联接/u', (string)($detail['name'] ?? '') . ' ' . (string)($detail['full_name'] ?? '')) === 1;
            $direct = $this->buildDividendProfileEntry($detail, 'self', $limit, $includeAnnouncements, $announcementLimit, null);
            $related = [];
            $relationshipFailures = [];

            if ($includeRelated && $isLinkFund) {
                $resolved = $this->resolveTargetEtf($detail);
                if ($resolved !== null) {
                    $related[] = $this->buildDividendProfileEntry(
                        $resolved['fund'],
                        'target_etf',
                        $limit,
                        $includeAnnouncements,
                        $announcementLimit,
                        $resolved['resolution']
                    );
                } else {
                    $relationshipFailures[] = [
                        'relationship' => 'target_etf',
                        'reason' => '未能用同基金公司、同跟踪指数和非联接 ETF 候选唯一确认目标 ETF 代码',
                    ];
                }
            }

            $allEntries = array_merge([$direct], $related);
            $upcoming = [];
            foreach ($allEntries as $entry) {
                foreach ($entry['events'] ?? [] as $event) {
                    if (($event['event_stage'] ?? '') !== 'completed') {
                        $upcoming[] = [
                            'relationship' => $entry['relationship'],
                            'code' => $entry['code'],
                            'name' => $entry['name'],
                            'event' => $event,
                        ];
                    }
                }
            }
            usort($upcoming, function($a, $b) {
                $ad = (string)($a['event']['pay_date'] ?? $a['event']['ex_date'] ?? $a['event']['record_date'] ?? '');
                $bd = (string)($b['event']['pay_date'] ?? $b['event']['ex_date'] ?? $b['event']['record_date'] ?? '');
                return strcmp($ad, $bd);
            });
            $hasEntryFailures = false;
            $allAnnouncementsChecked = $includeAnnouncements;
            foreach ($allEntries as $entry) {
                if (!empty($entry['failures'])) {
                    $hasEntryFailures = true;
                }
                if ($includeAnnouncements && empty($entry['announcements_checked'])) {
                    $allAnnouncementsChecked = false;
                }
            }

            return DataSourceResult::success('fund_dividend_profile', 'dividend_profile', [
                'query_fund' => [
                    'code' => $code,
                    'name' => (string)($detail['name'] ?? ''),
                    'full_name' => (string)($detail['full_name'] ?? ''),
                    'fund_company' => (string)($detail['fund_company'] ?? ''),
                    'is_link_fund' => $isLinkFund,
                    'index_code' => (string)($detail['index_code'] ?? ''),
                    'index_name' => (string)($detail['index_name'] ?? ''),
                ],
                'direct_fund' => $direct,
                'related_funds' => $related,
                'upcoming_or_in_progress_events' => $upcoming,
                'relationship_failures' => $relationshipFailures,
                'scope_note' => '目标 ETF 分红属于联接基金资产层面的收入，不等同于向联接基金份额持有人直接派发现金；两层事件必须分别表述。',
            ], [
                'code' => $code,
                'as_of_date' => date('Y-m-d'),
                'include_related' => $includeRelated,
                'include_announcements' => $includeAnnouncements,
                'announcement_checked' => $allAnnouncementsChecked,
                'partial' => $hasEntryFailures || !empty($relationshipFailures),
            ]);
        });
    }

    /**
     * 基金公告/报告/合同文档
     */
    public function fundDocuments(string $code, int $page = 1, int $pageSize = 20, string $docType = 'all', bool $includeContent = false, int $contentLimit = 6000): DataSourceResult
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'documents', 'invalid_code', '基金代码格式不正确');
        }
        $page = max(1, min($page, 200));
        $pageSize = max(1, min($pageSize, 100));
        $docType = $docType === '' ? 'all' : $docType;
        $contentLimit = max(1000, min($contentLimit, 20000));
        $key = $this->cacheKey('documents', 'v2:' . implode(':', [$code, $page, $pageSize, $docType, $includeContent ? 1 : 0, $contentLimit]));

        return $this->useCache('documents', $key, function() use ($code, $page, $pageSize, $docType, $includeContent, $contentLimit) {
            return $this->withBreaker('documents', function() use ($code, $page, $pageSize, $docType, $includeContent, $contentLimit) {
                // 旧 F10DataApi.aspx?type=jjgg 已长期停留在旧公告；当前网页实际使用此 JSON API。
                $providerType = [
                    'all' => 0,
                    'prospectus' => 1,
                    'contract' => 1,
                    'dividend' => 2,
                    'periodic_report' => 3,
                    'other' => 6,
                ][$docType] ?? 0;
                $url = 'https://api.fund.eastmoney.com/f10/JJGG?' . http_build_query([
                    'fundcode' => $code,
                    'pageIndex' => $page,
                    'pageSize' => $pageSize,
                    'type' => $providerType,
                ]);

                $resp = $this->http->get($url, [
                    'Referer' => "https://fundf10.eastmoney.com/jjgg_{$code}.html",
                    'Accept'  => 'application/json,text/plain,*/*',
                ]);

                if ($resp['error'] || $resp['http_code'] !== 200) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'documents', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
                }

                $parsed = $this->parseEastmoneyDocumentsResponse($resp['body']);
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
                    'provider_type' => $providerType,
                    'source_url' => $url,
                    'source_note' => '公告来自东方财富当前 F10 JSON 公告接口；分红公告 type=2。',
                ]);
            });
        });
    }

    private function buildDividendProfileEntry(array $detail, string $relationship, int $limit, bool $includeAnnouncements, int $announcementLimit, ?array $resolution): array
    {
        $code = (string)($detail['code'] ?? '');
        $name = (string)($detail['name'] ?? '');
        $company = (string)($detail['fund_company'] ?? '');
        $failures = [];

        $history = $this->dividendHistory($code, 1, $limit);
        $events = $history->hasData() ? $history->data : [];
        if (!$history->success) {
            $failures[] = ['source' => 'eastmoney_dividend_events', 'code' => $history->errorCode, 'message' => $history->errorMessage];
        }

        $announcements = [];
        $announcementsChecked = false;
        if ($includeAnnouncements) {
            $docs = $this->fundDocuments($code, 1, $announcementLimit, 'dividend', false, 1000);
            $announcementsChecked = $docs->success;
            if ($docs->hasData()) {
                $announcements = $docs->data;
            } elseif (!$docs->success) {
                $failures[] = ['source' => 'eastmoney_dividend_announcements', 'code' => $docs->errorCode, 'message' => $docs->errorMessage];
            }
        }

        $firstParty = [
            'provider' => null,
            'status' => 'not_configured_for_manager',
            'events_checked' => false,
            'announcements_checked' => false,
        ];
        if (strpos($this->normalizeFundCompany($company), '南方') !== false) {
            $southern = $this->fetchSouthernDividendEvidence($code, $limit, $includeAnnouncements ? $announcementLimit : 0);
            $firstParty = $southern['verification'];
            if (!empty($southern['events'])) {
                $events = $this->mergeDividendEvents($southern['events'], $events, $limit);
            }
            if ($includeAnnouncements && !empty($southern['announcements'])) {
                $announcements = $this->mergeDividendAnnouncements($southern['announcements'], $announcements, $announcementLimit);
                $announcementsChecked = true;
            }
            foreach ($southern['failures'] as $failure) {
                $failures[] = $failure;
            }
        }

        $latestEvent = $events[0] ?? null;
        return [
            'relationship' => $relationship,
            'code' => $code,
            'name' => $name,
            'full_name' => (string)($detail['full_name'] ?? ''),
            'fund_company' => $company,
            'index_code' => (string)($detail['index_code'] ?? ''),
            'index_name' => (string)($detail['index_name'] ?? ''),
            'relationship_resolution' => $resolution,
            'latest_event' => $latestEvent,
            'events' => array_slice($events, 0, $limit),
            'announcements' => array_slice($announcements, 0, $announcementLimit),
            'announcements_checked' => $announcementsChecked,
            'first_party_verification' => $firstParty,
            'failures' => $failures,
            'interpretation_note' => $relationship === 'target_etf'
                ? '这是目标 ETF 自身向 ETF 持有人派发的事件，不是查询基金份额的直接现金分红。该收入会进入联接基金资产，再由联接基金按自身公告决定是否分配。'
                : '这是查询基金份额自身的分红事件。',
        ];
    }

    private function fetchSouthernDividendEvidence(string $code, int $eventLimit, int $announcementLimit): array
    {
        $events = [];
        $announcements = [];
        $failures = [];
        $eventsChecked = false;
        $announcementsChecked = false;
        $headers = [
            'Referer' => "https://www.nffund.com/new/personal-financing/detail.html?fundCode={$code}",
            'Accept' => 'application/json,text/plain,*/*',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
        ];

        $eventResp = $this->http->post(
            'https://www.nffund.com/nfwebApi/fund/dividends',
            http_build_query(['fundCode' => $code, 'startDate' => '', 'endDate' => '', 'curPage' => 1, 'pageSize' => max(20, $eventLimit)]),
            $headers
        );
        if (!$eventResp['error'] && $eventResp['http_code'] === 200) {
            $parsedEvents = $this->parseSouthernDividendResponse($eventResp['body']);
            if ($parsedEvents !== null) {
                $eventsChecked = true;
                $events = array_slice($parsedEvents, 0, $eventLimit);
            } else {
                $failures[] = ['source' => 'nffund_official_dividends', 'code' => 'parse_error', 'message' => '南方基金官方分红接口解析失败'];
            }
        } else {
            $failures[] = ['source' => 'nffund_official_dividends', 'code' => 'network_error', 'message' => $eventResp['error'] ?: "HTTP {$eventResp['http_code']}"];
        }

        if ($announcementLimit > 0) {
            $noticeResp = $this->http->post(
                'https://www.nffund.com/nfwebApi/notice/fundAnnouncement',
                http_build_query([
                    'fundCode' => $code,
                    'title' => '分红',
                    'infoType' => '',
                    'tabsid' => 'newgg',
                    'type' => 0,
                    'curPage' => 1,
                    'pageSize' => $announcementLimit,
                ]),
                $headers
            );
            if (!$noticeResp['error'] && $noticeResp['http_code'] === 200) {
                $parsedNotices = $this->parseSouthernAnnouncementResponse($noticeResp['body']);
                if ($parsedNotices !== null) {
                    $announcementsChecked = true;
                    $announcements = array_slice($parsedNotices, 0, $announcementLimit);
                } else {
                    $failures[] = ['source' => 'nffund_official_announcements', 'code' => 'parse_error', 'message' => '南方基金官方公告接口解析失败'];
                }
            } else {
                $failures[] = ['source' => 'nffund_official_announcements', 'code' => 'network_error', 'message' => $noticeResp['error'] ?: "HTTP {$noticeResp['http_code']}"];
            }
        }

        return [
            'events' => $events,
            'announcements' => $announcements,
            'failures' => $failures,
            'verification' => [
                'provider' => 'nffund_official',
                'status' => $eventsChecked ? 'available' : 'unavailable',
                'events_checked' => $eventsChecked,
                'announcements_checked' => $announcementsChecked,
                'product_url' => "https://www.nffund.com/new/personal-financing/detail.html?fundCode={$code}",
            ],
        ];
    }

    private function mergeDividendEvents(array $preferred, array $secondary, int $limit): array
    {
        $merged = [];
        foreach (array_merge($preferred, $secondary) as $event) {
            $key = implode('|', [
                (string)($event['record_date'] ?? ''),
                (string)($event['ex_date'] ?? ''),
                (string)($event['pay_date'] ?? ''),
                number_format((float)($event['cash_per_unit'] ?? 0), 8, '.', ''),
            ]);
            if (!isset($merged[$key])) {
                $merged[$key] = $event;
                $merged[$key]['sources'] = array_values(array_unique((array)($event['sources'] ?? [])));
            } else {
                $merged[$key]['sources'] = array_values(array_unique(array_merge(
                    (array)($merged[$key]['sources'] ?? []),
                    (array)($event['sources'] ?? [])
                )));
            }
        }
        $items = array_values($merged);
        usort($items, function($a, $b) {
            return strcmp((string)($b['record_date'] ?? ''), (string)($a['record_date'] ?? ''));
        });
        return array_slice($items, 0, $limit);
    }

    private function mergeDividendAnnouncements(array $preferred, array $secondary, int $limit): array
    {
        $merged = [];
        foreach (array_merge($preferred, $secondary) as $item) {
            $key = (string)($item['date'] ?? '') . '|' . preg_replace('/\s+/u', '', (string)($item['title'] ?? ''));
            if (!isset($merged[$key])) {
                $merged[$key] = $item;
            } else {
                $merged[$key]['source_urls'] = array_values(array_unique(array_filter(array_merge(
                    (array)($merged[$key]['source_urls'] ?? [$merged[$key]['url'] ?? '']),
                    (array)($item['source_urls'] ?? [$item['url'] ?? ''])
                ))));
            }
        }
        $items = array_values($merged);
        usort($items, function($a, $b) {
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });
        return array_slice($items, 0, $limit);
    }

    private function resolveTargetEtf(array $detail): ?array
    {
        $name = (string)($detail['name'] ?? '');
        if (preg_match('/联接/u', $name) !== 1) {
            return null;
        }

        $base = preg_replace('/联接(?:基金)?[A-Z]?(?:类)?$/ui', '', $name);
        $companyCore = $this->normalizeFundCompany((string)($detail['fund_company'] ?? ''));
        if ($companyCore !== '') {
            $base = preg_replace('/^' . preg_quote($companyCore, '/') . '/u', '', $base);
        }
        $base = trim((string)$base);
        $core = preg_replace('/^(标普|中证|上证|深证|国证|恒生|富时|MSCI|纳斯达克)+/ui', '', $base);
        $queries = array_values(array_unique(array_filter([$base, $core])));

        $candidates = [];
        foreach (array_slice($queries, 0, 2) as $query) {
            $result = $this->search($query);
            if (!$result->hasData()) continue;
            foreach ($result->data as $candidate) {
                $candidateCode = (string)($candidate['code'] ?? '');
                $candidateName = (string)($candidate['name'] ?? '');
                if ($candidateCode === '' || $candidateCode === (string)($detail['code'] ?? '')) continue;
                if (stripos($candidateName, 'ETF') === false || preg_match('/联接/u', $candidateName)) continue;
                $candidates[$candidateCode] = $candidate;
            }
        }
        if (empty($candidates)) {
            return null;
        }

        $info = $this->info(array_slice(array_keys($candidates), 0, 20));
        if (!$info->hasData()) {
            return null;
        }

        $ranked = [];
        foreach ($info->data as $candidateDetail) {
            $score = 20; // 已满足 ETF 且非联接
            $sameCompany = $companyCore !== '' && $this->normalizeFundCompany((string)($candidateDetail['fund_company'] ?? '')) === $companyCore;
            $sameIndex = (string)($detail['index_code'] ?? '') !== ''
                && (string)($candidateDetail['index_code'] ?? '') === (string)($detail['index_code'] ?? '');
            if ($sameCompany) $score += 50;
            if ($sameIndex) $score += 50;
            $ranked[] = [
                'score' => $score,
                'same_company' => $sameCompany,
                'same_index' => $sameIndex,
                'fund' => $candidateDetail,
            ];
        }
        usort($ranked, function($a, $b) { return $b['score'] <=> $a['score']; });
        $best = $ranked[0] ?? null;
        if ($best === null || $best['score'] < 70) {
            return null;
        }
        if (isset($ranked[1]) && $ranked[1]['score'] === $best['score']) {
            return null;
        }

        return [
            'fund' => $best['fund'],
            'resolution' => [
                'confidence' => ($best['same_company'] && $best['same_index']) ? 'high' : 'medium',
                'score' => $best['score'],
                'same_fund_company' => $best['same_company'],
                'same_tracking_index_code' => $best['same_index'],
                'search_queries' => $queries,
                'evidence_note' => '由联接基金名称召回非联接 ETF，再用基金公司和跟踪指数代码进行唯一确认。',
            ],
        ];
    }

    private function normalizeFundCompany(string $company): string
    {
        $company = preg_replace('/(基金管理|股份|有限责任|有限公司|基金)/u', '', $company);
        return preg_replace('/[\s·・()（）]/u', '', (string)$company);
    }

    // ── 缓存层 (Phase 2 重构) ──

    /**
     * fa_get_fund_performance_stats：分页拉取历史净值并计算确定性绩效统计
     */
    public function performanceStats(array $codes, int $targetDays = 500, array $periods = ['1m','3m','6m','1y','3y','since_sample'], bool $useAccNav = true, int $includeRecentRows = 10): DataSourceResult
    {
        $codes = array_values(array_unique(array_filter($codes, function ($c) {
            return is_string($c) && preg_match('/^\d{6}$/', $c);
        })));
        if (empty($codes)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'performance_stats', 'invalid_code', '没有有效的基金代码');
        }
        $codes = array_slice($codes, 0, 20);

        $targetDays = max(20, min($targetDays, 1500));
        $allowedPeriods = ['1m','3m','6m','1y','2y','3y','since_sample'];
        $periods = array_values(array_filter($periods, function ($p) use ($allowedPeriods) {
            return in_array($p, $allowedPeriods, true);
        }));
        if (empty($periods)) {
            $periods = ['1m','3m','6m','1y','3y','since_sample'];
        }
        $includeRecentRows = max(0, min($includeRecentRows, 60));
        $maxParallel = max(1, min((int)($this->researchConfig['max_parallel_workers'] ?? 4), 8));

        $cacheKey = $this->cacheKey('performance_stats', md5(implode(',', $codes) . ':' . $targetDays . ':' . implode(',', $periods) . ':' . (int)$useAccNav . ':' . $includeRecentRows));
        return $this->useCache('performance_stats', $cacheKey, function() use ($codes, $targetDays, $periods, $useAccNav, $includeRecentRows, $maxParallel) {
            $pageSize = 49;
            $items = [];
            $failures = [];
            foreach ($codes as $code) {
                $fetched = $this->fetchHistoryRowsParallel($code, $targetDays, $pageSize, $maxParallel);
                foreach ($fetched['failures'] as $f) {
                    $failures[] = array_merge(['code' => $code], $f);
                }
                // 取跟踪指数代码用于跟踪误差（detail 已缓存，命中率高）
                $indexCode = '';
                $detail = $this->fetchFundDetail($code);
                if ($detail !== null) {
                    $indexCode = (string)($detail['index_code'] ?? '');
                }
                $items[] = $this->buildPerformanceItem($code, $fetched, $periods, $useAccNav, $includeRecentRows, $indexCode);
            }

            $partial = false;
            foreach ($items as $item) {
                if (($item['coverage_level'] ?? '') === 'insufficient_history') {
                    $partial = true;
                }
                if (!empty($item['meta']['page_failures'])) {
                    $partial = true;
                }
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'performance_stats', $items, [
                'codes' => $codes,
                'target_days' => $targetDays,
                'periods' => $periods,
                'use_acc_nav' => $useAccNav,
                'include_recent_rows' => $includeRecentRows,
                'partial' => $partial,
                'failures' => $failures,
            ]);
        });
    }

    /**
     * 并行分页拉取某基金历史净值行（页 1 走缓存，后续页 curl_multi 并发，失败重试 1 次）
     */
    private function fetchHistoryRowsParallel(string $code, int $targetDays, int $pageSize, int $maxParallel): array
    {
        $page1 = $this->history($code, 1, $pageSize);
        $failures = [];
        if (!$page1->hasData()) {
            return ['rows' => [], 'records' => 0, 'pages_total' => 0, 'pages_fetched' => 0, 'failures' => [['page' => 1, 'error' => $page1->errorCode ?: 'fetch_failed']], 'page_failures' => 1];
        }

        $pagesTotal = (int)($page1->meta['pages'] ?? 1);
        $records = (int)($page1->meta['records'] ?? count($page1->data));
        $allRows = is_array($page1->data) ? $page1->data : [];
        $pagesFetched = 1;

        $neededPages = max(1, (int)ceil($targetDays / $pageSize));
        $neededPages = min($neededPages, max(1, $pagesTotal));
        if ($neededPages <= 1) {
            return ['rows' => $allRows, 'records' => $records, 'pages_total' => $pagesTotal, 'pages_fetched' => $pagesFetched, 'failures' => [], 'page_failures' => 0];
        }

        // 收集 2..neededPages，先查缓存
        $pageRows = [];
        $toFetch = [];
        for ($p = 2; $p <= $neededPages; $p++) {
            $ck = $this->cacheKey('history', "v2:{$code}:{$p}:{$pageSize}");
            $cached = $this->cache->get($ck);
            if ($cached !== null && ($cached['success'] ?? false) && isset($cached['data'])) {
                $pageRows[$p] = $cached['data'];
                $pagesFetched++;
            } else {
                $toFetch[] = $p;
            }
        }

        if (!empty($toFetch)) {
            $requests = [];
            foreach ($toFetch as $p) {
                $url = $this->historyApiUrl($code, $p, $pageSize);
                $requests[] = [
                    'key' => "p{$p}",
                    'url' => $url,
                    'headers' => $this->historyApiHeaders($code),
                ];
            }
            $responses = $this->http->multiGet($requests, $maxParallel);
            foreach ($toFetch as $p) {
                $resp = $responses["p{$p}"] ?? null;
                $body = $resp['body'] ?? '';
                $parsed = ($resp !== null && !$resp['error'] && $resp['http_code'] === 200 && $body !== '')
                    ? $this->parseHistoryResponse($body) : null;

                // 失败重试 1 次（走缓存的 history()，含熔断）
                if ($parsed === null) {
                    $retry = $this->history($code, $p, $pageSize);
                    if ($retry->hasData()) {
                        $pageRows[$p] = is_array($retry->data) ? $retry->data : [];
                        $pagesFetched++;
                        continue;
                    }
                    $err = (string)($resp['error'] ?? '');
                    if ($err === '' && (($resp['http_code'] ?? 0) !== 200)) {
                        $err = 'HTTP ' . ($resp['http_code'] ?? 0);
                    }
                    if ($err === '') {
                        $err = 'parse_error';
                    }
                    $failures[] = ['page' => $p, 'error' => $err];
                    continue;
                }

                $pageRows[$p] = $parsed['items'];
                $pagesFetched++;
                $ck = $this->cacheKey('history', "v2:{$code}:{$p}:{$pageSize}");
                $this->cache->set($ck, [
                    'success' => true,
                    'source' => self::SOURCE_NAME,
                    'action' => 'history',
                    'result_action' => 'history',
                    'data' => $parsed['items'],
                    'meta' => ['code' => $code, 'page' => $p, 'page_size' => $pageSize, 'records' => $parsed['records'], 'pages' => $parsed['pages']],
                ], $this->cacheTtl['history']);
            }
        }

        ksort($pageRows);
        foreach ($pageRows as $rows) {
            $allRows = array_merge($allRows, is_array($rows) ? $rows : []);
        }

        return ['rows' => $allRows, 'records' => $records, 'pages_total' => $pagesTotal, 'pages_fetched' => $pagesFetched, 'failures' => $failures, 'page_failures' => count($failures)];
    }

    /**
     * 由原始历史行计算收益/风险/极值统计
     */
    private function buildPerformanceItem(string $code, array $fetched, array $periods, bool $useAccNav, int $includeRecentRows, string $indexCode = ''): array
    {
        $rows = is_array($fetched['rows'] ?? null) ? $fetched['rows'] : [];
        $recentRows = array_slice($rows, 0, $includeRecentRows);

        // 按日期升序去重
        $sorted = [];
        foreach ($rows as $row) {
            $date = (string)($row['date'] ?? '');
            if ($date === '' || isset($sorted[$date])) {
                continue;
            }
            $sorted[$date] = $row;
        }
        ksort($sorted);
        $series = [];
        $accUsed = 0;
        foreach ($sorted as $date => $row) {
            $nav = $this->num($row['nav'] ?? '');
            $acc = $this->num($row['acc_nav'] ?? '');
            $price = null;
            if ($useAccNav && $acc !== null) {
                $price = $acc;
                $accUsed++;
            } elseif ($nav !== null) {
                $price = $nav;
            }
            if ($price === null || $price <= 0) {
                continue;
            }
            $series[] = ['date' => $date, 'price' => $price, 'growth' => $this->num($row['growth_rate'] ?? '')];
        }

        $rowCount = count($series);
        $coverageLevel = $rowCount < 60 ? 'insufficient_history' : 'sufficient';

        $returns = [];
        if ($rowCount >= 2) {
            $last = $series[$rowCount - 1]['price'];
            $periodDays = ['1m' => 21, '3m' => 63, '6m' => 126, '1y' => 250, '2y' => 500, '3y' => 750];
            foreach ($periods as $p) {
                if ($p === 'since_sample') {
                    $first = $series[0]['price'];
                    $returns["{$p}_pct"] = $this->pct2(($last - $first) / $first);
                    continue;
                }
                $n = $periodDays[$p] ?? null;
                if ($n === null || $rowCount <= $n) {
                    $returns["{$p}_pct"] = null;
                    continue;
                }
                $prev = $series[$rowCount - 1 - $n]['price'];
                $returns["{$p}_pct"] = $prev > 0 ? $this->pct2(($last - $prev) / $prev) : null;
            }
        } else {
            foreach ($periods as $p) {
                $returns["{$p}_pct"] = null;
            }
        }

        $risk = $this->computeRiskStats($series);
        $extremes = $this->computeExtremes($series);
        $riskAdjusted = $this->computeRiskAdjusted($series, $indexCode, $useAccNav && $accUsed > 0);

        return [
            'code' => $code,
            'sample' => [
                'rows' => $rowCount,
                'date_start' => $rowCount > 0 ? $series[0]['date'] : '',
                'date_end' => $rowCount > 0 ? $series[$rowCount - 1]['date'] : '',
                'records_reported' => (int)($fetched['records'] ?? $rowCount),
                'pages_fetched' => (int)($fetched['pages_fetched'] ?? 0),
                'use_acc_nav' => $useAccNav && $accUsed > 0,
                'acc_nav_rows' => $accUsed,
            ],
            'returns' => $returns,
            'risk' => $risk,
            'risk_adjusted' => $riskAdjusted,
            'extremes' => $extremes,
            'recent_rows' => array_values($recentRows),
            'coverage_level' => $coverageLevel,
            'partial' => $coverageLevel === 'insufficient_history' || (int)($fetched['page_failures'] ?? 0) > 0,
            'meta' => ['page_failures' => (int)($fetched['page_failures'] ?? 0)],
        ];
    }

    private function computeRiskStats(array $series): array
    {
        $n = count($series);
        if ($n < 2) {
            return [
                'max_drawdown_pct' => null, 'max_drawdown_start' => '', 'max_drawdown_end' => '',
                'daily_vol_pct' => null, 'annualized_vol_pct' => null,
                'positive_days' => 0, 'negative_days' => 0, 'win_rate_pct' => null,
            ];
        }
        $peak = $series[0]['price'];
        $peakDate = $series[0]['date'];
        $maxDd = 0.0;
        $ddStart = '';
        $ddEnd = '';
        foreach ($series as $point) {
            if ($point['price'] > $peak) {
                $peak = $point['price'];
                $peakDate = $point['date'];
            }
            $dd = $peak > 0 ? ($point['price'] / $peak - 1) : 0;
            if ($dd < $maxDd) {
                $maxDd = $dd;
                $ddStart = $peakDate;
                $ddEnd = $point['date'];
            }
        }

        $dailyReturns = [];
        $positive = 0;
        $negative = 0;
        for ($i = 1; $i < $n; $i++) {
            $prev = $series[$i - 1]['price'];
            if ($prev <= 0) continue;
            $r = ($series[$i]['price'] - $prev) / $prev;
            $dailyReturns[] = $r;
            if ($r > 0) $positive++;
            elseif ($r < 0) $negative++;
        }
        $vol = $this->stddev($dailyReturns);
        $totalDays = $positive + $negative;

        return [
            'max_drawdown_pct' => round($maxDd * 100, 2),
            'max_drawdown_start' => $ddStart,
            'max_drawdown_end' => $ddEnd,
            'daily_vol_pct' => round($vol * 100, 2),
            'annualized_vol_pct' => round($vol * sqrt(250) * 100, 2),
            'positive_days' => $positive,
            'negative_days' => $negative,
            'win_rate_pct' => $totalDays > 0 ? round($positive / $totalDays * 100, 2) : null,
        ];
    }

    private function computeExtremes(array $series): array
    {
        $best = null;
        $worst = null;
        foreach ($series as $point) {
            $g = $point['growth'];
            if ($g === null) continue;
            if ($best === null || $g > $best['growth_pct']) {
                $best = ['date' => $point['date'], 'growth_pct' => $g];
            }
            if ($worst === null || $g < $worst['growth_pct']) {
                $worst = ['date' => $point['date'], 'growth_pct' => $g];
            }
        }
        return [
            'best_day' => $best,
            'worst_day' => $worst,
        ];
    }

    private function num($value): ?float
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value)) return null;
        return (float)$value;
    }

    /**
     * 解析费率字符串（如 "0.50%" / "0.5" / "1.20%"）为百分比数值
     */
    private function parseFeePercent(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') return null;
        $value = str_replace('%', '', $value);
        if (!is_numeric($value)) return null;
        return (float)$value;
    }

    private function pct2(float $ratio): float
    {
        return round($ratio * 100, 2);
    }

    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n === 0) return 0.0;
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }
        return sqrt($sum / $n);
    }

    /**
     * 样本标准差（n-1），用于风险调整指标（Sharpe/Sortino/跟踪误差）
     */
    private function stddevSample(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }
        return sqrt($sum / ($n - 1));
    }

    /**
     * 计算风险调整指标：Sharpe / Sortino / 跟踪误差。
     * Sharpe/Sortino 用年化无风险利率 RF_ANNUAL；跟踪误差仅对国内指数基金（有可拉基准）计算。
     */
    private function computeRiskAdjusted(array $series, string $indexCode, bool $usesAccumulatedNav = true): array
    {
        $result = [
            'sharpe' => null,
            'sortino' => null,
            'tracking_error_pct' => null,
            'benchmark_index' => '',
            'benchmark_variant' => '',
            'benchmark_source' => '',
            'benchmark_status' => $indexCode === '' ? 'not_configured' : 'pending',
            'sample_pairs' => 0,
        ];
        $benchCode = $this->normalizeDomesticIndexCode($indexCode);
        if ($benchCode !== null) {
            $result['benchmark_index'] = $usesAccumulatedNav ? $benchCode . 'CNY010' : $benchCode;
            $result['benchmark_variant'] = $usesAccumulatedNav ? 'total_return' : 'price';
            $result['benchmark_source'] = CsindexClient::SOURCE_NAME;
        }
        $n = count($series);
        if ($n < 30) {
            if ($benchCode !== null) $result['benchmark_status'] = 'insufficient_fund_samples';
            return $result;
        }
        $dailyReturns = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = $series[$i - 1]['price'];
            if ($prev <= 0) continue;
            $dailyReturns[] = ($series[$i]['price'] - $prev) / $prev;
        }
        $m = count($dailyReturns);
        if ($m < 30) {
            if ($benchCode !== null) $result['benchmark_status'] = 'insufficient_fund_samples';
            return $result;
        }
        $mean = array_sum($dailyReturns) / $m;
        $annExcess = $mean * self::TRADING_DAYS - self::RF_ANNUAL;
        $vol = $this->stddevSample($dailyReturns);
        if ($vol > 0) {
            $result['sharpe'] = round($annExcess / ($vol * sqrt(self::TRADING_DAYS)), 3);
        }
        $downside = array_values(array_filter($dailyReturns, function ($r) { return $r < 0; }));
        $dvol = $this->stddevSample($downside);
        if ($dvol > 0) {
            $result['sortino'] = round($annExcess / ($dvol * sqrt(self::TRADING_DAYS)), 3);
        }
        if ($benchCode !== null) {
            $benchSeries = $this->fetchIndexSeries($benchCode, $series, $usesAccumulatedNav);
            if (!empty($benchSeries)) {
                $te = $this->computeTrackingError($series, $benchSeries);
                if ($te !== null) {
                    $result['tracking_error_pct'] = round($te['te'] * 100, 2);
                    $result['benchmark_status'] = 'available';
                    $result['sample_pairs'] = $te['pairs'];
                } else {
                    $result['benchmark_status'] = 'insufficient_pairs';
                }
            } else {
                $result['benchmark_status'] = $usesAccumulatedNav ? 'benchmark_variant_unavailable' : 'benchmark_unavailable';
            }
        } elseif ($indexCode !== '') {
            $result['benchmark_status'] = 'unsupported_index_code';
        }
        return $result;
    }

    /**
     * 国内中证指数代码归一化：接受 6 位数字；字母型海外指数返回 null。
     */
    private function normalizeDomesticIndexCode(string $indexCode): ?string
    {
        $indexCode = trim($indexCode);
        if (preg_match('/^\d{6}$/', $indexCode)) {
            return $indexCode;
        }
        return null;
    }

    /**
     * 拉取国内指数日 K 序列（前复权收盘价），带缓存。失败返回 null。
     */
    private function fetchIndexSeries(string $indexCode, array $fundSeries, bool $totalReturn): ?array
    {
        if (empty($fundSeries)) return null;
        $startDate = (string)($fundSeries[0]['date'] ?? '');
        $endDate = (string)($fundSeries[count($fundSeries) - 1]['date'] ?? '');
        $result = $this->indexHistoryWindow($indexCode, $startDate, $endDate, $totalReturn);
        if (!$result->hasData()) return null;
        $series = [];
        foreach ((array)$result->data as $row) {
            if (!is_array($row)) continue;
            $date = (string)($row['date'] ?? '');
            $close = $this->num($row['close'] ?? null);
            if ($date !== '' && $close !== null && $close > 0) {
                $series[] = ['date' => $date, 'price' => $close];
            }
        }
        return empty($series) ? null : $series;
    }

    /**
     * 跟踪误差：基金日收益与基准日收益之差的年化标准差。按日期对齐，对齐后不足 30 对返回 null。
     */
    private function computeTrackingError(array $fundSeries, array $benchSeries): ?array
    {
        $benchMap = [];
        foreach ($benchSeries as $p) {
            if (isset($p['date'], $p['price'])) {
                $benchMap[$p['date']] = $p['price'];
            }
        }
        $aligned = [];
        foreach ($fundSeries as $p) {
            if (isset($p['date']) && isset($benchMap[$p['date']])) {
                $aligned[] = ['fund' => $p['price'], 'bench' => $benchMap[$p['date']]];
            }
        }
        $cnt = count($aligned);
        if ($cnt < 31) {
            return null;
        }
        $diffs = [];
        for ($i = 1; $i < $cnt; $i++) {
            if ($aligned[$i - 1]['fund'] <= 0 || $aligned[$i - 1]['bench'] <= 0) continue;
            $fr = ($aligned[$i]['fund'] - $aligned[$i - 1]['fund']) / $aligned[$i - 1]['fund'];
            $br = ($aligned[$i]['bench'] - $aligned[$i - 1]['bench']) / $aligned[$i - 1]['bench'];
            $diffs[] = $fr - $br;
        }
        if (count($diffs) < 30) {
            return null;
        }
        $te = $this->stddevSample($diffs) * sqrt(self::TRADING_DAYS);
        return ['te' => $te, 'pairs' => count($diffs)];
    }

    /**
     * fa_screen_funds：多关键词搜索 + 排行样本合并 + 资料补全 + 去重
     */
    public function screenFunds(?string $theme, ?array $keywords, ?array $fundTypes, ?array $periods, int $pageSize = 50, int $maxCandidates = 20, ?float $minScaleYuan = null, bool $includeUnbuyable = true): DataSourceResult
    {
        $maxCandidates = max(3, min($maxCandidates, 50));
        $pageSize = max(10, min($pageSize, 100));
        $aliases = $this->themeAliases($theme);
        $effectiveKeywords = is_array($keywords) && !empty($keywords)
            ? array_values(array_unique(array_map('strval', $keywords)))
            : $aliases;
        if (empty($effectiveKeywords) && $theme === null) {
            $effectiveKeywords = ['红利'];
        }

        $types = is_array($fundTypes) && !empty($fundTypes)
            ? array_values(array_intersect($fundTypes, ['all','stock','mixed','bond','index','qdii','fof']))
            : $this->inferFundTypes($theme);
        if (empty($types)) {
            $types = ['index','stock','mixed'];
        }
        $periods = is_array($periods) && !empty($periods)
            ? array_values(array_intersect($periods, ['month','quarter','half_year','year','two_year','three_year','this_year','since']))
            : ['year','half_year','this_year'];

        $failures = [];
        $rawHits = [];
        $rawCount = 0;
        $maxParallel = max(1, min((int)($this->researchConfig['max_parallel_workers'] ?? 4), 8));

        // 1. 构建搜索 + 排行任务并并发拉取（缓存感知）
        $jobs = [];
        $searchHeaders = $this->eastmoneyFundMobileHeaders();
        $rankHeaders = ['Referer' => 'https://fund.eastmoney.com/data/fundranking.html', 'Accept' => '*/*'];
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-1 year'));
        $typeMap = ['all'=>'all','stock'=>'gp','mixed'=>'hh','bond'=>'zq','index'=>'zs','qdii'=>'qdii','fof'=>'fof'];
        $periodMap = ['month'=>'1yzf','quarter'=>'3yzf','half_year'=>'6yzf','year'=>'1nzf','two_year'=>'2nzf','three_year'=>'3nzf','this_year'=>'jnzf','since'=>'lnzf'];

        foreach ($effectiveKeywords as $kw) {
            $kw = trim($kw);
            if ($kw === '') continue;
            $jobs[] = [
                'cache_key' => $this->cacheKey('search', md5($kw)),
                'url' => $this->buildSearchUrl($kw),
                'headers' => $searchHeaders,
                'tag' => 'search:' . $kw,
                'action' => 'search',
                'ttl_key' => 'search',
                'parser' => function ($body) { return $this->parseSearchResponse($body); },
            ];
        }
        foreach ($types as $type) {
            foreach ($periods as $period) {
                $jobs[] = [
                    'cache_key' => $this->cacheKey('rank', "{$type}:{$period}:1:{$pageSize}"),
                    'url' => $this->buildRankUrl($typeMap[$type] ?? 'all', $periodMap[$period] ?? '1nzf', 1, $pageSize, $startDate, $endDate),
                    'headers' => $rankHeaders,
                    'tag' => "rank:{$type}:{$period}",
                    'action' => 'rank',
                    'ttl_key' => 'rank',
                    'parser' => function ($body) use ($period) { return $this->parseRankResponse($body, $period); },
                ];
            }
        }

        $batch = $this->batchFetch($jobs, $maxParallel);
        foreach ($batch['failures'] as $f) {
            $tag = (string)$f['tag'];
            $parts = explode(':', $tag);
            $entry = ['source' => $parts[0] ?? 'unknown', 'error' => $f['error']];
            if (($parts[0] ?? '') === 'search' && isset($parts[1])) {
                $entry['keyword'] = $parts[1];
            } elseif (($parts[0] ?? '') === 'rank') {
                $entry['type'] = $parts[1] ?? '';
                $entry['period'] = $parts[2] ?? '';
            }
            $failures[] = $entry;
        }

        // 2. 合并搜索结果
        foreach ($effectiveKeywords as $kw) {
            $kw = trim($kw);
            if ($kw === '') continue;
            $res = $batch['results']['search:' . $kw] ?? null;
            if ($res === null) continue;
            foreach ($res['items'] as $idx => $item) {
                $code = (string)($item['code'] ?? '');
                if ($code === '' || !preg_match('/^\d{6}$/', $code)) continue;
                $rawCount++;
                $rawHits[$code] = $rawHits[$code] ?? ['code' => $code, 'name' => (string)($item['name'] ?? ''), 'type' => (string)($item['type'] ?? $item['category'] ?? ''), 'company' => (string)($item['company'] ?? ''), 'is_buy' => (bool)($item['is_buy'] ?? false), 'scale' => '', 'match_reasons' => [], 'source_hits' => []];
                $rawHits[$code]['source_hits'][] = ['source' => 'search', 'keyword' => $kw, 'rank' => $idx + 1];
                if (strpos((string)($item['name'] ?? ''), $kw) !== false) {
                    $rawHits[$code]['match_reasons'][] = 'keyword:' . $kw;
                }
            }
        }

        // 3. 合并排行结果
        foreach ($types as $type) {
            foreach ($periods as $period) {
                $res = $batch['results']["rank:{$type}:{$period}"] ?? null;
                if ($res === null) continue;
                foreach ($res['items'] as $idx => $item) {
                    $code = (string)($item['code'] ?? '');
                    if ($code === '' || !preg_match('/^\d{6}$/', $code)) continue;
                    $rawCount++;
                    $rawHits[$code] = $rawHits[$code] ?? ['code' => $code, 'name' => (string)($item['name'] ?? ''), 'type' => '', 'company' => '', 'is_buy' => (bool)($item['buy_status'] ?? false), 'scale' => '', 'match_reasons' => [], 'source_hits' => []];
                    $rawHits[$code]['source_hits'][] = ['source' => 'rank', 'type' => $type, 'period' => $period, 'rank' => $idx + 1];
                }
            }
        }

        if (empty($rawHits)) {
            return DataSourceResult::success(self::SOURCE_NAME, 'screen', [], [
                'theme' => $theme, 'aliases_used' => $aliases, 'keywords' => $effectiveKeywords,
                'dedupe' => ['raw_hits' => 0, 'deduped' => 0, 'returned' => 0],
                'failures' => $failures, 'partial' => !empty($failures),
            ]);
        }

        // 4. 预排序并限制补全数量（避免对全部去重候选拉取资料）
        $rawList = array_values($rawHits);
        usort($rawList, function ($a, $b) {
            $ah = count($a['source_hits']);
            $bh = count($b['source_hits']);
            if ($ah === $bh) {
                $ar = $this->bestSearchRank($a['source_hits']);
                $br = $this->bestSearchRank($b['source_hits']);
                if ($ar === $br) return 0;
                return ($ar === 0 ? PHP_INT_MAX : $ar) <=> ($br === 0 ? PHP_INT_MAX : $br);
            }
            return $bh <=> $ah;
        });
        $enrichLimit = max($maxCandidates * 3, 30);
        $rawList = array_slice($rawList, 0, $enrichLimit);

        // 5. 批量资料补全
        $codes = array_column($rawList, 'code');
        $enriched = [];
        foreach (array_chunk($codes, 20) as $chunk) {
            $infoRes = $this->info($chunk);
            if ($infoRes->hasData()) {
                foreach ($infoRes->data as $fund) {
                    $c = (string)($fund['code'] ?? '');
                    if ($c !== '') {
                        $enriched[$c] = $fund;
                    }
                }
            }
        }

        // 6. 组装候选 + 过滤 + match reason
        $candidates = [];
        foreach ($rawList as $hit) {
            $code = $hit['code'];
            $info = $enriched[$code] ?? null;
            $name = $info['name'] ?? $hit['name'];
            $type = $info['type'] ?? $hit['type'];
            $company = $info['fund_company'] ?? $hit['company'];
            $isBuy = $info !== null ? (bool)($info['is_buy'] ?? false) : $hit['is_buy'];
            $scale = $info['scale'] ?? '';
            $benchmark = (string)($info['benchmark'] ?? '');
            $strategy = (string)($info['investment_strategy'] ?? '');

            $scaleYuan = $this->parseScaleYuan((string)$scale);
            if ($minScaleYuan !== null && ($scaleYuan === null || $scaleYuan < $minScaleYuan)) {
                continue;
            }
            if (!$includeUnbuyable && !$isBuy) {
                continue;
            }

            $matchReasons = $this->uniqueStrings($hit['match_reasons']);
            foreach ($effectiveKeywords as $kw) {
                if (strpos($name, $kw) !== false) {
                    $matchReasons[] = 'keyword:' . $kw;
                }
                if ($benchmark !== '' && strpos($benchmark, $kw) !== false) {
                    $matchReasons[] = 'benchmark:' . $kw;
                }
            }
            $matchReasons = $this->uniqueStrings($matchReasons);
            if (empty($matchReasons)) {
                $matchReasons[] = 'rank_evidence';
            }

            // 主题相关性分级：strong（命中主题词）> weak（仅排行凑数）> negative（命中负向词且无主题命中）
            $themeRelevance = $this->themeRelevanceLevel($matchReasons, $name, $effectiveKeywords);
            if ($themeRelevance === 'weak') {
                $matchReasons[] = 'theme_weak_rank_only';
            } elseif ($themeRelevance === 'negative') {
                $matchReasons[] = 'theme_mismatch';
            }
            $matchReasons = $this->uniqueStrings($matchReasons);

            $candidates[] = [
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'company' => $company,
                'is_buy' => $isBuy,
                'scale' => (string)$scale,
                'scale_yuan' => $scaleYuan,
                'benchmark' => $benchmark,
                'theme_relevance' => $themeRelevance,
                'match_reasons' => $matchReasons,
                'source_hits' => array_slice($hit['source_hits'], 0, 6),
                'coverage' => [
                    'has_info' => $info !== null,
                    'has_rank_evidence' => !empty(array_filter($hit['source_hits'], function ($s) { return ($s['source'] ?? '') === 'rank'; })),
                    'has_strategy_evidence' => $benchmark !== '' || $strategy !== '',
                ],
            ];
        }

        // 5. 排序：主题相关性优先（strong>weak>negative），其次命中来源数，再搜索排名
        $relevanceRank = ['strong' => 0, 'weak' => 1, 'negative' => 2];
        usort($candidates, function ($a, $b) use ($relevanceRank) {
            $ar = $relevanceRank[$a['theme_relevance'] ?? 'weak'] ?? 1;
            $br = $relevanceRank[$b['theme_relevance'] ?? 'weak'] ?? 1;
            if ($ar !== $br) return $ar <=> $br;
            $ah = count($a['source_hits']);
            $bh = count($b['source_hits']);
            if ($ah === $bh) {
                $asr = $this->bestSearchRank($a['source_hits']);
                $bsr = $this->bestSearchRank($b['source_hits']);
                if ($asr === $bsr) return 0;
                return ($asr === 0 ? PHP_INT_MAX : $asr) <=> ($bsr === 0 ? PHP_INT_MAX : $bsr);
            }
            return $bh <=> $ah;
        });

        $returned = array_slice($candidates, 0, $maxCandidates);

        $themeStats = ['strong' => 0, 'weak' => 0, 'negative' => 0];
        foreach ($candidates as $c) {
            $lvl = $c['theme_relevance'] ?? 'weak';
            if (isset($themeStats[$lvl])) $themeStats[$lvl]++;
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'screen', $returned, [
            'theme' => $theme,
            'aliases_used' => $aliases,
            'keywords' => $effectiveKeywords,
            'fund_types' => $types,
            'periods' => $periods,
            'dedupe' => ['raw_hits' => $rawCount, 'deduped' => count($rawHits), 'returned' => count($returned), 'theme_relevance' => $themeStats],
            'failures' => $failures,
            'partial' => !empty($failures) || count($enriched) < count($rawHits),
        ]);
    }

    private function themeAliases(?string $theme): array
    {
        switch ($theme) {
            case 'dividend': return ['红利','高股息','股息','红利低波','低波红利','央企红利','标普红利','中证红利','港股通高股息'];
            case 'low_volatility': return ['低波','低波动','红利低波','低波红利'];
            case 'broad_index': return ['沪深300','中证500','中证1000','宽基','指数'];
            case 'bond': return ['债券','纯债','信用债','利率债','债基'];
            case 'qdii': return ['QDII','美股','纳斯达克','标普500','海外','港股通'];
            default: return [];
        }
    }

    /**
     * 主题相关性分级：strong（命中主题词）> weak（仅排行凑数）> negative（命中负向词且无主题命中）
     * 用于把 theme=dividend 时召回出的科技/半导体等主题无关候选排到后面。
     */
    private function themeRelevanceLevel(array $matchReasons, string $name, array $keywords): string
    {
        $hasThemeHit = false;
        foreach ($matchReasons as $mr) {
            if (is_string($mr) && (strpos($mr, 'keyword:') === 0 || strpos($mr, 'benchmark:') === 0)) {
                $hasThemeHit = true;
                break;
            }
        }
        if ($hasThemeHit) {
            return 'strong';
        }
        if (empty($keywords)) {
            return 'weak';
        }
        // 无主题词命中：name 命中负向词则判为 negative（明显与目标主题无关）
        foreach (self::THEME_NEGATIVE_WORDS as $nw) {
            if ($name !== '' && strpos($name, $nw) !== false) {
                return 'negative';
            }
        }
        return 'weak';
    }

    private function inferFundTypes(?string $theme): array
    {
        switch ($theme) {
            case 'dividend': return ['index','stock','mixed'];
            case 'low_volatility': return ['index'];
            case 'broad_index': return ['index'];
            case 'bond': return ['bond'];
            case 'qdii': return ['qdii'];
            default: return ['index','stock','mixed'];
        }
    }

    private function parseScaleYuan(string $scale): ?float
    {
        $scale = trim($scale);
        if ($scale === '') return null;
        if (preg_match('/([\d.]+)\s*亿/u', $scale, $m)) {
            return (float)$m[1] * 100000000;
        }
        if (preg_match('/([\d.]+)\s*万/u', $scale, $m)) {
            return (float)$m[1] * 10000;
        }
        if (is_numeric($scale)) {
            return (float)$scale;
        }
        return null;
    }

    private function bestSearchRank(array $sourceHits): int
    {
        $best = 0;
        foreach ($sourceHits as $hit) {
            if (($hit['source'] ?? '') === 'search' && isset($hit['rank'])) {
                $r = (int)$hit['rank'];
                if ($best === 0 || $r < $best) {
                    $best = $r;
                }
            }
        }
        return $best;
    }

    private function uniqueStrings(array $items): array
    {
        return array_values(array_unique(array_filter($items, function ($v) {
            return is_string($v) && $v !== '';
        })));
    }

    /**
     * fa_get_fund_trade_rules：申购/赎回/限购/费率/购买状态
     */
    public function tradeRules(array $codes, bool $includeFeeDetail = true, bool $includePlatformStatus = true): DataSourceResult
    {
        $codes = array_values(array_unique(array_filter($codes, function ($c) {
            return is_string($c) && preg_match('/^\d{6}$/', $c);
        })));
        if (empty($codes)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'trade_rules', 'invalid_code', '没有有效的基金代码');
        }
        $codes = array_slice($codes, 0, 20);

        $cacheKey = $this->cacheKey('trade_rules', md5(implode(',', $codes) . ':' . (int)$includeFeeDetail . ':' . (int)$includePlatformStatus));
        return $this->useCache('trade_rules', $cacheKey, function() use ($codes, $includeFeeDetail, $includePlatformStatus) {
            $infoMap = [];
            foreach (array_chunk($codes, 20) as $chunk) {
                $infoRes = $this->info($chunk);
                if ($infoRes->hasData()) {
                    foreach ($infoRes->data as $fund) {
                        $infoMap[(string)($fund['code'] ?? '')] = $fund;
                    }
                }
            }

            $failures = [];
            $items = [];
            foreach ($codes as $code) {
                $info = $infoMap[$code] ?? null;
                if ($info === null) {
                    // 降级：直接取详情
                    $info = $this->fetchFundDetail($code);
                }
                $name = (string)($info['name'] ?? '');
                $isBuy = (bool)($info['is_buy'] ?? false);
                $minPurchase = (string)($info['min_purchase'] ?? '');
                $managementFee = (string)($info['management_fee'] ?? '');
                $custodyFee = (string)($info['custody_fee'] ?? '');

                // 申购/赎回状态：降级读历史净值最近一行
                $purchaseStatus = '';
                $redeemStatus = '';
                $hist = $this->history($code, 1, 5);
                if ($hist->hasData() && !empty($hist->data)) {
                    $latest = $hist->data[0];
                    $purchaseStatus = (string)($latest['purchase_status'] ?? '');
                    $redeemStatus = (string)($latest['redeem_status'] ?? '');
                } else {
                    $failures[] = ['code' => $code, 'tool' => 'history', 'error' => $hist->errorCode ?: 'fetch_failed'];
                }
                if ($purchaseStatus === '') {
                    $purchaseStatus = $isBuy ? '开放申购' : '未知';
                }
                if ($redeemStatus === '') {
                    $redeemStatus = '未知';
                }

                $maxHint = '';
                if (preg_match('/限制/u', $purchaseStatus)) {
                    $maxHint = '单日单账户限额需以公告/平台为准';
                }

                $item = [
                    'code' => $code,
                    'name' => $name,
                    'purchase_status' => $purchaseStatus,
                    'redeem_status' => $redeemStatus,
                    'is_buy' => $isBuy,
                    'min_purchase' => $minPurchase,
                    'max_purchase_hint' => $maxHint,
                ];
                if ($includeFeeDetail) {
                    $item['management_fee'] = $managementFee;
                    $item['custody_fee'] = $custodyFee;
                    $item['sales_service_fee'] = (string)($info['sales_service_fee'] ?? '');
                    $item['fee_note'] = '费率字段来自基金详情；申购费率阶梯可能需要公告或平台补充。';
                }
                if ($includePlatformStatus) {
                    $item['platform_status_note'] = '购买/赎回状态来自东方财富基金详情与历史净值列，实际可投性以销售平台为准。';
                }
                $items[] = $item;
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'trade_rules', $items, [
                'codes' => $codes,
                'include_fee_detail' => $includeFeeDetail,
                'include_platform_status' => $includePlatformStatus,
                'failures' => $failures,
                'partial' => !empty($failures),
            ]);
        });
    }

    /**
     * fa_get_fund_holdings：基金真实十大持仓 + 行业暴露（东方财富 FundArchivesApis）
     * 解决 fundExposure 持仓硬编码为空的问题。失败返回 empty_data，不抛异常。
     */
    public function fundHoldings(string $code, int $topline = 10, bool $includeIndustry = true): DataSourceResult
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'holdings', 'invalid_code', '基金代码格式不正确');
        }
        $topline = max(1, min($topline, 50));
        $key = $this->cacheKey('holdings', $code . ':' . $topline . ':' . ($includeIndustry ? '1' : '0'));

        return $this->useCache('holdings', $key, function() use ($code, $topline, $includeIndustry) {
            return $this->withBreaker('holdings', function() use ($code, $topline, $includeIndustry) {
                $headers = ['Referer' => "https://fundf10.eastmoney.com/ccmx_{$code}.html", 'Accept' => '*/*'];
                // 东方财富 FundArchivesDatas.aspx?type=jjcc 返回 var apidata={content:"<HTML表格>"}
                $stockUrl = 'https://fundf10.eastmoney.com/FundArchivesDatas.aspx?' . http_build_query([
                    'type' => 'jjcc', 'code' => $code, 'topline' => $topline, 'year' => '', 'month' => '',
                ]);
                $resp = $this->http->get($stockUrl, $headers);
                $body = (!$resp['error'] && $resp['http_code'] === 200) ? (string)$resp['body'] : '';
                $parsed = $body !== '' ? $this->parseHoldingsHtml($body, $topline) : ['report_date' => '', 'top_holdings' => []];

                if (empty($parsed['top_holdings'])) {
                    return DataSourceResult::error(self::SOURCE_NAME, 'holdings', 'empty_data', '持仓数据未返回，可能为新发基金或接口结构变更', ['code' => $code]);
                }

                // 行业暴露端点（tscc）当前不可用，留空待后续迭代；不影响股票持仓返回
                return DataSourceResult::success(self::SOURCE_NAME, 'holdings', [
                    'code' => $code,
                    'report_date' => $parsed['report_date'],
                    'top_holdings' => $parsed['top_holdings'],
                    'industry_exposure' => $includeIndustry ? [] : null,
                    'data_source' => 'eastmoney_fund_archives',
                ], [
                    'code' => $code,
                    'topline' => $topline,
                    'include_industry' => $includeIndustry,
                    'holdings_count' => count($parsed['top_holdings']),
                    'industry_count' => 0,
                    'partial' => $includeIndustry,
                ]);
            });
        });
    }

    /**
     * 解析 FundArchivesDatas.aspx?type=jjcc 返回的 var apidata={content:"<HTML>"} 持仓表格
     * 列：序号 股票代码 股票名称 最新价 涨跌幅 相关资讯 占净值比 持股数 市值
     */
    private function parseHoldingsHtml(string $body, int $topline): array
    {
        $reportDate = '';
        if (preg_match('/截止至[：:]\s*<font[^>]*>([^<]+)<\/font>/u', $body, $m)) {
            $reportDate = trim($m[1]);
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $body, $m)) {
            $reportDate = $m[1];
        }

        $topHoldings = [];
        if (preg_match_all('/<tr[^>]*>.*?<\/tr>/s', $body, $rows)) {
            foreach ($rows[0] as $tr) {
                if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $cm)) continue;
                $cells = array_map(function ($c) { return trim(strip_tags($c)); }, $cm[1]);
                if (count($cells) < 7) continue;
                $idx = $this->num($cells[0]);
                if ($idx === null || $idx < 1) continue;  // 跳过表头/非数据行
                $stockCode = preg_replace('/\D/', '', $cells[1]);
                if ($stockCode === '') continue;
                $topHoldings[] = [
                    'code' => $stockCode,
                    'name' => $cells[2],
                    'weight' => $this->parseFeePercent($cells[6]),
                ];
                if (count($topHoldings) >= $topline) break;
            }
        }
        return ['report_date' => $reportDate, 'top_holdings' => $topHoldings];
    }

    /**
     * fa_get_fund_holdings_or_index_exposure：持仓/行业/指数暴露与风格因子标签
     */
    public function fundExposure(string $code, string $prefer = 'auto', bool $includeTopHoldings = true, bool $includeIndustry = true, bool $includeDocumentEvidence = false, int $contentLimit = 6000): DataSourceResult
    {
        $cacheKey = $this->cacheKey('exposure', $code . ':' . $prefer . ':' . (int)$includeTopHoldings . ':' . (int)$includeIndustry . ':' . (int)$includeDocumentEvidence . ':' . $contentLimit);
        return $this->useCache('exposure', $cacheKey, function() use ($code, $prefer, $includeTopHoldings, $includeIndustry, $includeDocumentEvidence, $contentLimit) {
            $detail = $this->fetchFundDetail($code);
            if ($detail === null) {
                return DataSourceResult::error(self::SOURCE_NAME, 'exposure', 'empty_data', '基金详情未返回，无法推导风格暴露', ['code' => $code, 'exposure_type' => 'unavailable']);
            }

            $name = (string)($detail['name'] ?? '');
            $benchmark = (string)($detail['benchmark'] ?? '');
            $strategy = (string)($detail['investment_strategy'] ?? '');
            $target = (string)($detail['investment_target'] ?? '');
            $indexCode = (string)($detail['index_code'] ?? '');
            $indexName = (string)($detail['index_name'] ?? '');
            $type = (string)($detail['type'] ?? '');

            $text = $name . ' ' . $benchmark . ' ' . $strategy . ' ' . $target . ' ' . $indexName;
            $styleTags = $this->deriveStyleTags($text);
            $factorEvidence = $this->deriveFactorEvidence($text, $styleTags);

            $exposureType = 'fund_detail_derived';
            if ($indexCode !== '' || $indexName !== '') {
                $exposureType = 'index_derived';
            }

            $documentEvidence = [];
            if ($includeDocumentEvidence) {
                $docsRes = $this->fundDocuments($code, 1, 5, 'all', false, 0);
                if ($docsRes->hasData()) {
                    foreach (array_slice($docsRes->data, 0, 3) as $doc) {
                        $documentEvidence[] = [
                            'title' => (string)($doc['title'] ?? ''),
                            'doc_type' => (string)($doc['doc_type'] ?? ''),
                            'date' => (string)($doc['date'] ?? ''),
                        ];
                    }
                }
                if (!empty($documentEvidence)) {
                    $exposureType = $exposureType === 'fund_detail_derived' ? 'document_derived' : $exposureType;
                }
            }

            // 拉取真实持仓（失败时保持空数组，与历史行为兼容，不伪造权重）
            $topHoldings = [];
            $industryExposure = [];
            $holdingsSource = 'unavailable';
            $holdingsReportDate = '';
            if ($includeTopHoldings || $includeIndustry) {
                $holdingsRes = $this->fundHoldings($code, 10, $includeIndustry);
                if ($holdingsRes->hasData() && is_array($holdingsRes->data)) {
                    $hd = $holdingsRes->data;
                    if ($includeTopHoldings && isset($hd['top_holdings']) && is_array($hd['top_holdings'])) {
                        $topHoldings = $hd['top_holdings'];
                    }
                    if ($includeIndustry && isset($hd['industry_exposure']) && is_array($hd['industry_exposure'])) {
                        $industryExposure = $hd['industry_exposure'];
                    }
                    $holdingsReportDate = (string)($hd['report_date'] ?? '');
                    $holdingsSource = 'eastmoney_fund_archives';
                    if (!empty($topHoldings) || !empty($industryExposure)) {
                        $exposureType = $exposureType === 'fund_detail_derived' ? 'holdings_fetched' : $exposureType . '+holdings';
                    }
                }
            }

            $data = [
                'code' => $code,
                'name' => $name,
                'exposure_type' => $exposureType,
                'benchmark' => $benchmark,
                'index_code' => $indexCode,
                'index_name' => $indexName,
                'fund_type' => $type,
                'style_tags' => $styleTags,
                'factor_evidence' => $factorEvidence,
                'top_holdings' => $includeTopHoldings ? $topHoldings : null,
                'industry_exposure' => $includeIndustry ? $industryExposure : null,
                'holdings_source' => $holdingsSource,
                'holdings_report_date' => $holdingsReportDate,
                'document_evidence' => $documentEvidence,
                'scope_note' => $holdingsSource === 'eastmoney_fund_archives'
                    ? '持仓与行业暴露来自东方财富最新报告期（占净值比），非指数官网全量成分权重。'
                    : '持仓数据未取得，行业和成分暴露只作为基金详情/报告推导；不伪造实际持仓权重。',
            ];

            return DataSourceResult::success(self::SOURCE_NAME, 'exposure', $data, [
                'code' => $code,
                'prefer' => $prefer,
                'include_document_evidence' => $includeDocumentEvidence,
                'content_limit' => $includeDocumentEvidence ? $contentLimit : 0,
                'partial' => empty($styleTags) || $holdingsSource === 'unavailable' || ($includeIndustry && empty($industryExposure)),
            ]);
        });
    }

    private function deriveStyleTags(string $text): array
    {
        $rules = [
            '红利' => '/红利|高股息|股息/u',
            '低波' => '/低波|低波动/u',
            '大盘' => '/大盘/u',
            '中盘' => '/中盘/u',
            '小盘' => '/小盘/u',
            '价值' => '/价值/u',
            '成长' => '/成长/u',
            '央企' => '/央企/u',
            '国企' => '/国企/u',
            '港股通' => '/港股通/u',
            '指数联接' => '/联接/u',
            'ETF' => '/ETF|etf/u',
            'QDII' => '/QDII|qdii/u',
            '债券' => '/债券|纯债|信用债/u',
            '消费' => '/消费/u',
            '医药' => '/医药|医疗|生物/u',
            '科技' => '/科技|半导体|芯片|人工智能|新能源/u',
            '军工' => '/军工|国防/u',
        ];
        $tags = [];
        foreach ($rules as $tag => $pattern) {
            if (preg_match($pattern, $text)) {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    private function deriveFactorEvidence(string $text, array $styleTags): array
    {
        $map = [
            '红利' => ['factor' => 'dividend', 'evidence' => '名称/基准包含 红利/高股息/股息'],
            '低波' => ['factor' => 'low_volatility', 'evidence' => '名称/基准包含 低波/低波动'],
            '大盘' => ['factor' => 'large_cap', 'evidence' => '名称/基准包含 大盘'],
            '价值' => ['factor' => 'value', 'evidence' => '名称/基准包含 价值'],
            '成长' => ['factor' => 'growth', 'evidence' => '名称/基准包含 成长'],
            '央企' => ['factor' => 'soe', 'evidence' => '名称/基准包含 央企'],
            '港股通' => ['factor' => 'hk_connect', 'evidence' => '名称/基准包含 港股通'],
        ];
        $evidence = [];
        foreach ($styleTags as $tag) {
            if (isset($map[$tag])) {
                $evidence[] = $map[$tag];
            }
        }
        return $evidence;
    }

    /**
     * fa_score_funds：确定性多维评分与排序
     */
    public function scoreFunds(array $codes, ?string $objective = 'balanced', ?string $horizon = 'long', ?string $riskPreference = 'medium', ?array $weights = null, bool $requireBuyable = false): DataSourceResult
    {
        $codes = array_values(array_unique(array_filter($codes, function ($c) {
            return is_string($c) && preg_match('/^\d{6}$/', $c);
        })));
        if (empty($codes)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'score', 'invalid_code', '没有有效的基金代码');
        }
        $codes = array_slice($codes, 0, (int)($this->researchConfig['max_score_candidates'] ?? 20));

        $objective = in_array($objective, ['balanced','long_term_stable','low_fee_index','dividend_income','active_alpha','low_drawdown'], true) ? $objective : 'balanced';
        $horizon = in_array($horizon, ['short','medium','long'], true) ? $horizon : 'long';
        $riskPreference = in_array($riskPreference, ['low','medium','high'], true) ? $riskPreference : 'medium';

        $weightTable = $this->weightTable($objective, $horizon, $riskPreference);
        if (is_array($weights) && !empty($weights)) {
            foreach ($weights as $w) {
                if (!is_array($w)) continue;
                $key = (string)($w['key'] ?? '');
                $val = $w['value'] ?? null;
                if ($key !== '' && is_numeric($val) && array_key_exists($key, $weightTable)) {
                    $weightTable[$key] = (float)$val;
                }
            }
        }
        $totalWeight = array_sum($weightTable);
        if ($totalWeight <= 0) {
            $weightTable = $this->weightTable('balanced', 'long', 'medium');
            $totalWeight = array_sum($weightTable);
        }
        foreach ($weightTable as $k => $v) {
            $weightTable[$k] = round($v / $totalWeight, 4);
        }

        $weightsHash = is_array($weights) ? md5(json_encode($weights)) : 'null';
        $cacheKey = $this->cacheKey('score', md5(implode(',', $codes) . ':' . $objective . ':' . $horizon . ':' . $riskPreference . ':' . $weightsHash . ':' . (int)$requireBuyable));
        return $this->useCache('score', $cacheKey, function() use ($codes, $objective, $horizon, $riskPreference, $weights, $requireBuyable, $weightTable) {
            // 1. 批量资料
            $infoMap = [];
            foreach (array_chunk($codes, 20) as $chunk) {
                $infoRes = $this->info($chunk);
                if ($infoRes->hasData()) {
                    foreach ($infoRes->data as $fund) {
                        $infoMap[(string)($fund['code'] ?? '')] = $fund;
                    }
                }
            }

            // 2. 绩效统计
            $statsMap = [];
            // Keep this aligned with the common deep-dive call so fa_score_funds can reuse
            // the cached performance_stats result instead of re-fetching long history.
            $statsRes = $this->performanceStats($codes, (int)($this->researchConfig['target_history_days'] ?? 500), ['1m','3m','6m','1y','3y','since_sample'], true, 10);
            if ($statsRes->hasData()) {
                foreach ($statsRes->data as $stat) {
                    $statsMap[(string)($stat['code'] ?? '')] = $stat;
                }
            }

            // 3. 交易规则
            $rulesMap = [];
            $rulesRes = $this->tradeRules($codes, true, true);
            if ($rulesRes->hasData()) {
                foreach ($rulesRes->data as $rule) {
                    $rulesMap[(string)($rule['code'] ?? '')] = $rule;
                }
            }

            // 4. 风格暴露 + 分红
            $exposureMap = [];
            $dividendMap = [];
            foreach ($codes as $code) {
                $exp = $this->fundExposure($code, 'auto', false, false, false, 0);
                if ($exp->hasData()) {
                    $exposureMap[$code] = $exp->data;
                }
                $div = $this->dividendHistory($code, 1, 100);
                if ($div->hasData()) {
                    $dividendMap[$code] = $div->data;
                }
            }

            $themeKeywords = $objective === 'dividend_income' ? ['红利','高股息','股息','低波'] : [];

            $items = [];
            $notRanked = [];
            $failures = [];
            foreach ($codes as $code) {
                $info = $infoMap[$code] ?? null;
                if ($info === null) {
                    $notRanked[] = ['code' => $code, 'reason' => '资料缺失，无法评分'];
                    $failures[] = ['code' => $code, 'tool' => 'fa_get_fund_info', 'error' => 'no_info'];
                    continue;
                }
                $stats = $statsMap[$code] ?? null;
                $rules = $rulesMap[$code] ?? null;
                $exposure = $exposureMap[$code] ?? null;
                $dividends = $dividendMap[$code] ?? [];

                $breakdown = $this->scoreBreakdown($code, $info, $stats, $rules, $exposure, $dividends, $themeKeywords, $failures);
                $score = 0.0;
                $missingDims = [];
                foreach ($weightTable as $dim => $w) {
                    $val = $breakdown[$dim] ?? null;
                    if ($val === null) {
                        $score += $w * 50.0;  // null 按中性分，不再静默拉低得分
                        $missingDims[] = $dim;
                    } else {
                        $score += $w * (float)$val;
                    }
                }
                $score = round($score, 1);

                $penalties = [];
                if ($requireBuyable && !($rules['is_buy'] ?? false)) {
                    $score = round($score * 0.5, 1);
                    $penalties[] = '当前不可购买，require_buyable 已硬扣分';
                }
                if (($stats['coverage_level'] ?? '') === 'insufficient_history') {
                    $penalties[] = '历史样本不足，绩效维度可信度低';
                }
                if (!empty($missingDims)) {
                    $penalties[] = '缺失维度按中性分处理：' . implode('、', $missingDims);
                }

                $items[] = [
                    'code' => $code,
                    'name' => (string)($info['name'] ?? ''),
                    'score' => $score,
                    'score_breakdown' => $breakdown,
                    'missing_dims' => $missingDims,
                    'reasons' => $this->scoreReasons($breakdown, $exposure),
                    'penalties' => $penalties,
                    'evidence_refs' => array_filter([
                        $stats !== null ? "performance_stats:{$code}" : null,
                        $rules !== null ? "trade_rules:{$code}" : null,
                        $exposure !== null ? "exposure:{$code}" : null,
                        !empty($dividends) ? "dividend_history:{$code}" : null,
                    ]),
                ];
            }

            usort($items, function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            foreach ($items as $i => &$item) {
                $item['rank'] = $i + 1;
            }
            unset($item);

            $criticalDims = ['return_quality', 'risk_adjusted_return', 'drawdown_control'];
            $missingDimCount = 0;
            $criticalMissing = 0;
            foreach ($items as $item) {
                foreach ($item['score_breakdown'] as $dim => $val) {
                    if ($val === null) {
                        $missingDimCount++;
                        if (in_array($dim, $criticalDims, true)) $criticalMissing++;
                    }
                }
            }
            $confidence = 'high';
            if ($missingDimCount > 0) $confidence = 'medium';
            if ($missingDimCount >= count($items) * 2 || $criticalMissing >= count($items)) $confidence = 'low';

            return DataSourceResult::success(self::SOURCE_NAME, 'score', [
                'objective' => $objective,
                'horizon' => $horizon,
                'risk_preference' => $riskPreference,
                'weights' => $weightTable,
                'items' => $items,
                'not_ranked' => $notRanked,
                'score_confidence' => $confidence,
            ], [
                'codes' => $codes,
                'failures' => $failures,
                'partial' => !empty($failures) || $confidence !== 'high',
            ]);
        });
    }

    private function weightTable(string $objective, string $horizon, string $riskPreference): array
    {
        $base = [
            'theme_fit' => 0.14,
            'return_quality' => 0.13,
            'drawdown_control' => 0.14,
            'risk_adjusted_return' => 0.10,
            'tracking_quality' => 0.05,
            'scale_liquidity' => 0.12,
            'fee_efficiency' => 0.10,
            'dividend_behavior' => 0.10,
            'buyability' => 0.08,
            'data_quality' => 0.04,
        ];
        switch ($objective) {
            case 'long_term_stable':
                $base['return_quality'] += 0.06; $base['drawdown_control'] += 0.04; $base['risk_adjusted_return'] += 0.04; $base['theme_fit'] -= 0.06; $base['buyability'] -= 0.04; break;
            case 'dividend_income':
                $base['dividend_behavior'] += 0.10; $base['theme_fit'] += 0.04; $base['risk_adjusted_return'] += 0.03; $base['drawdown_control'] += 0.02; $base['return_quality'] -= 0.08; $base['fee_efficiency'] -= 0.06; break;
            case 'low_fee_index':
                $base['fee_efficiency'] += 0.10; $base['scale_liquidity'] += 0.04; $base['tracking_quality'] += 0.06; $base['dividend_behavior'] -= 0.08; $base['theme_fit'] -= 0.06; break;
            case 'low_drawdown':
                $base['drawdown_control'] += 0.12; $base['risk_adjusted_return'] += 0.03; $base['return_quality'] -= 0.06; $base['dividend_behavior'] -= 0.06; break;
            case 'active_alpha':
                $base['return_quality'] += 0.08; $base['risk_adjusted_return'] += 0.06; $base['fee_efficiency'] -= 0.06; $base['scale_liquidity'] -= 0.04; $base['drawdown_control'] -= 0.02; break;
        }
        if ($riskPreference === 'low') { $base['drawdown_control'] += 0.04; $base['risk_adjusted_return'] += 0.02; $base['return_quality'] -= 0.04; $base['theme_fit'] -= 0.02; }
        if ($riskPreference === 'high') { $base['return_quality'] += 0.04; $base['risk_adjusted_return'] += 0.02; $base['drawdown_control'] -= 0.04; }
        if ($horizon === 'short') { $base['return_quality'] += 0.02; $base['drawdown_control'] -= 0.02; }
        if ($horizon === 'long') { $base['drawdown_control'] += 0.02; $base['risk_adjusted_return'] += 0.02; $base['return_quality'] -= 0.02; $base['data_quality'] -= 0.02; }
        // 保证非负下限
        foreach ($base as $k => $v) { if ($v < 0.02) $base[$k] = 0.02; }
        return $base;
    }

    private function scoreBreakdown(string $code, array $info, ?array $stats, ?array $rules, ?array $exposure, array $dividends, array $themeKeywords, array &$failures): array
    {
        $breakdown = [];

        // theme_fit
        $styleTags = $exposure['style_tags'] ?? [];
        $benchmark = (string)($exposure['benchmark'] ?? $info['benchmark'] ?? '');
        if (!empty($themeKeywords)) {
            $hit = 0;
            foreach ($themeKeywords as $kw) {
                if (in_array($kw, $styleTags, true) || strpos($benchmark, $kw) !== false) $hit++;
            }
            $breakdown['theme_fit'] = $hit === 0 ? 40 : min(95, 55 + $hit * 15);
        } else {
            $breakdown['theme_fit'] = empty($styleTags) ? 55 : min(90, 55 + count($styleTags) * 8);
        }

        // return_quality
        $returns = $stats['returns'] ?? [];
        $retVals = [];
        foreach (['1y_pct','3y_pct','since_sample_pct','6m_pct'] as $k) {
            if (isset($returns[$k]) && is_numeric($returns[$k])) $retVals[] = (float)$returns[$k];
        }
        if (!empty($retVals)) {
            $avg = array_sum($retVals) / count($retVals);
            $breakdown['return_quality'] = max(5, min(100, round(50 + $avg * 2.5)));
        } else {
            $breakdown['return_quality'] = null;
            $failures[] = ['code' => $code, 'tool' => 'performance_stats', 'error' => 'no_returns'];
        }

        // drawdown_control
        $maxDd = $stats['risk']['max_drawdown_pct'] ?? null;
        if ($maxDd !== null) {
            $breakdown['drawdown_control'] = max(5, min(100, round(100 + $maxDd * 2)));
        } else {
            $breakdown['drawdown_control'] = null;
        }

        // scale_liquidity
        $scaleYuan = $this->parseScaleYuan((string)($info['scale'] ?? ''));
        if ($scaleYuan !== null) {
            if ($scaleYuan >= 5e9) $s = 95;
            elseif ($scaleYuan >= 1e9) $s = 85;
            elseif ($scaleYuan >= 2e8) $s = 70;
            elseif ($scaleYuan >= 5e7) $s = 55;
            else $s = 35;
            $breakdown['scale_liquidity'] = $s;
        } else {
            $breakdown['scale_liquidity'] = 50;
        }

        // fee_efficiency
        $mgmt = $this->parseFeePercent((string)($info['management_fee'] ?? ''));
        $cust = $this->parseFeePercent((string)($info['custody_fee'] ?? ''));
        if ($mgmt !== null) {
            $totalFee = $mgmt + ($cust ?? 0.0);
            $breakdown['fee_efficiency'] = max(5, min(100, round(100 - $totalFee * 40)));
        } else {
            $breakdown['fee_efficiency'] = null;
        }

        // dividend_behavior
        if (!empty($dividends)) {
            $breakdown['dividend_behavior'] = min(95, 50 + count($dividends) * 8);
        } else {
            $breakdown['dividend_behavior'] = 30;
        }

        // buyability
        $isBuy = (bool)($rules['is_buy'] ?? $info['is_buy'] ?? false);
        $purchaseStatus = (string)($rules['purchase_status'] ?? '');
        if ($isBuy && !preg_match('/限制|暂停/u', $purchaseStatus)) $b = 100;
        elseif ($isBuy && preg_match('/限制/u', $purchaseStatus)) $b = 70;
        elseif (preg_match('/暂停/u', $purchaseStatus)) $b = 20;
        else $b = 50;
        $breakdown['buyability'] = $b;

        // risk_adjusted_return（Sharpe/Sortino 风险调整收益，区分绝对收益与风险调整后表现）
        $riskAdj = $stats['risk_adjusted'] ?? null;
        $sharpe = $riskAdj['sharpe'] ?? null;
        $sortino = $riskAdj['sortino'] ?? null;
        $metric = $sharpe ?? $sortino;
        if ($metric !== null) {
            if ($metric < 0) $r = 30;
            elseif ($metric < 1) $r = 50 + $metric * 25;
            elseif ($metric < 2) $r = 75 + ($metric - 1) * 15;
            else $r = min(95, 90 + ($metric - 2) * 5);
            $breakdown['risk_adjusted_return'] = max(5, min(100, round($r)));
        } else {
            $breakdown['risk_adjusted_return'] = null;
            $failures[] = ['code' => $code, 'tool' => 'performance_stats', 'error' => 'no_risk_adjusted'];
        }

        // tracking_quality（跟踪误差，仅指数基金；非指数或无可用基准返回 null）
        $te = $riskAdj['tracking_error_pct'] ?? null;
        if ($te !== null) {
            if ($te < 2) $t = 95;
            elseif ($te < 5) $t = 90 - ($te - 2) * 5;
            elseif ($te < 10) $t = 75 - ($te - 5) * 5;
            else $t = max(40, 50 - ($te - 10) * 3);
            $breakdown['tracking_quality'] = max(5, min(100, round($t)));
        } else {
            $breakdown['tracking_quality'] = null;
        }

        // data_quality
        $coverage = 100;
        if ($stats === null) $coverage -= 25;
        elseif (($stats['coverage_level'] ?? '') === 'insufficient_history') $coverage -= 15;
        if ($rules === null) $coverage -= 15;
        if ($exposure === null) $coverage -= 15;
        if (empty($dividends)) $coverage -= 5;
        if ($stats !== null && ($stats['risk_adjusted']['sharpe'] ?? null) === null) $coverage -= 5;
        $breakdown['data_quality'] = max(10, $coverage);

        return $breakdown;
    }

    private function scoreReasons(array $breakdown, ?array $exposure): array
    {
        $reasons = [];
        arsort($breakdown);
        $labels = [
            'theme_fit' => '主题契合度',
            'return_quality' => '收益质量',
            'drawdown_control' => '回撤控制',
            'risk_adjusted_return' => '风险调整收益',
            'tracking_quality' => '指数跟踪质量',
            'scale_liquidity' => '规模流动性',
            'fee_efficiency' => '费率效率',
            'dividend_behavior' => '分红表现',
            'buyability' => '可投性',
            'data_quality' => '数据质量',
        ];
        $top = array_slice(array_keys($breakdown), 0, 3);
        foreach ($top as $dim) {
            $val = $breakdown[$dim];
            if ($val !== null && $val >= 70) {
                $reasons[] = ($labels[$dim] ?? $dim) . '较强';
            }
        }
        if (!empty($exposure['style_tags'])) {
            $reasons[] = '风格标签：' . implode('、', array_slice($exposure['style_tags'], 0, 4));
        }
        if (empty($reasons)) {
            $reasons[] = '综合评分依据多维证据';
        }
        return $reasons;
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
        $cacheKey = $this->cacheKey('detail', $code);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && isset($cached['detail'])) {
            return $cached['detail'];
        }

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

            $detail = $this->normalizeFundInfoItem($parsed['data']['Datas']);
            $this->cache->set($cacheKey, ['detail' => $detail], $this->cacheTtl['detail'] ?? 3600);
            return $detail;
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

    private function buildRankUrl(string $typeKey, string $sortKey, int $page, int $pageSize, string $startDate, string $endDate): string
    {
        return 'https://fund.eastmoney.com/data/rankhandler.aspx?' . http_build_query([
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
    }

    private function buildSearchUrl(string $keyword): string
    {
        return "https://fundsuggest.eastmoney.com/FundSearch/api/FundSearchAPI.ashx?m=9&key=" . urlencode($keyword);
    }

    private function parseSearchResponse(string $body): ?array
    {
        $parsed = HttpClient::parseJson($body);
        if (!$parsed['ok'] || !isset($parsed['data']['Datas'])) {
            return null;
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
        return ['items' => $results, 'meta' => ['total' => count($results)]];
    }

    /**
     * 缓存感知的批量并发抓取：先查缓存，未命中用 curl_multi 并发拉取并回写缓存。
     * 用于 screenFunds 的多关键词搜索 + 多排行样本并行化。
     *
     * @param array    $jobs   [['cache_key'=>string,'url'=>string,'headers'=>array,'tag'=>mixed,'parser'=>callable,'ttl_key'=>string], ...]
     * @param int      $maxParallel
     * @return array ['results'=>[tag=>['items'=>array,'meta'=>array]], 'failures'=>[...], 'cached'=>int, 'fetched'=>int]
     */
    private function batchFetch(array $jobs, int $maxParallel): array
    {
        $results = [];
        $failures = [];
        $toFetch = [];
        $cached = 0;
        foreach ($jobs as $job) {
            $data = $this->cache->get($job['cache_key']);
            if ($data !== null && ($data['success'] ?? false) && isset($data['data'])) {
                $results[$job['tag']] = ['items' => $data['data'], 'meta' => $data['meta'] ?? []];
                $cached++;
            } else {
                $toFetch[] = $job;
            }
        }

        if (!empty($toFetch)) {
            $reqs = [];
            foreach ($toFetch as $i => $job) {
                $reqs[] = ['key' => 'j' . $i, 'url' => $job['url'], 'headers' => $job['headers'] ?? []];
            }
            $responses = $this->http->multiGet($reqs, $maxParallel);
            foreach ($toFetch as $i => $job) {
                $resp = $responses['j' . $i] ?? null;
                $body = $resp['body'] ?? '';
                $parsed = ($resp !== null && !$resp['error'] && $resp['http_code'] === 200 && $body !== '')
                    ? $job['parser']($body) : null;
                if ($parsed === null) {
                    $failures[] = ['tag' => $job['tag'], 'error' => (string)($resp['error'] ?? '') ?: 'HTTP ' . ($resp['http_code'] ?? 0)];
                    continue;
                }
                $this->cache->set($job['cache_key'], [
                    'success' => true,
                    'source' => self::SOURCE_NAME,
                    'action' => $job['action'] ?? 'batch',
                    'result_action' => $job['action'] ?? 'batch',
                    'data' => $parsed['items'],
                    'meta' => $parsed['meta'] ?? [],
                ], $this->cacheTtl[$job['ttl_key'] ?? 'history'] ?? 300);
                $results[$job['tag']] = $parsed;
            }
        }

        return ['results' => $results, 'failures' => $failures, 'cached' => $cached, 'fetched' => count($toFetch)];
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

    private function parseDividendHistoryPage(string $body): ?array
    {
        if (!preg_match('/<table\b[^>]*class=[\'\"][^\'\"]*cfxq[^\'\"]*[\'\"][^>]*>[\s\S]*?<tbody>([\s\S]*?)<\/tbody>/iu', $body, $tableMatch)) {
            return null;
        }

        $fundName = '';
        if (preg_match('/累计分红[^<]*<\/label>/u', $body, $summaryBlock)) {
            if (preg_match('/<a\b[^>]*>(.*?)<\/a>/iu', $summaryBlock[0], $nameMatch)) {
                $fundName = $this->cleanHtmlCell($nameMatch[1]);
            }
        }
        if ($fundName === '' && preg_match('/<a\b[^>]*href=[\'\"](?:https?:)?\/\/fund\.eastmoney\.com\/\d{6}\.html[\'\"][^>]*>(.*?)<\/a>/iu', $body, $nameMatch)) {
            $fundName = $this->cleanHtmlCell($nameMatch[1]);
        }

        $annualSummary = '';
        if (preg_match('/(\d{4}年度[\s\S]{0,200}?累计分红[0-9.]+元\/份)/u', strip_tags($body), $summaryMatch)) {
            $annualSummary = preg_replace('/\s+/u', '', $summaryMatch[1]);
        }

        preg_match_all('/<tr\b[^>]*>([\s\S]*?)<\/tr>/iu', $tableMatch[1], $rowMatches);
        $items = [];
        foreach ($rowMatches[1] as $row) {
            preg_match_all('/<td\b[^>]*>([\s\S]*?)<\/td>/iu', $row, $cellMatches);
            if (count($cellMatches[1]) < 5) continue;
            $cells = array_map([$this, 'cleanHtmlCell'], $cellMatches[1]);
            $recordDate = (string)($cells[1] ?? '');
            $exDate = (string)($cells[2] ?? '');
            $dividend = (string)($cells[3] ?? '');
            $payDate = (string)($cells[4] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordDate)) continue;
            $cash = $this->parseCashDividendPerUnit($dividend);
            $items[] = [
                'year' => preg_replace('/\D/', '', (string)($cells[0] ?? '')),
                'record_date' => $recordDate,
                'ex_date' => $exDate,
                'pay_date' => $payDate,
                'cash_per_unit' => $cash,
                'dividend' => $dividend,
                'date' => $recordDate,
                'event_stage' => $this->dividendEventStage($recordDate, $exDate, $payDate),
                'sources' => ['eastmoney_choice'],
            ];
        }
        if (empty($items) && strpos($tableMatch[1], '暂无分红') === false) {
            return null;
        }

        return [
            'fund_name' => $fundName,
            'annual_summary' => $annualSummary,
            'items' => $items,
        ];
    }

    private function parseEastmoneyDocumentsResponse(string $body): ?array
    {
        $parsed = HttpClient::parseJson($body);
        if (!$parsed['ok'] || !is_array($parsed['data'] ?? null)) {
            return null;
        }
        $json = $parsed['data'];
        if ((int)($json['ErrCode'] ?? -1) !== 0 || !is_array($json['Data'] ?? null)) {
            return null;
        }
        $typeNames = [1 => '发行运作', 2 => '分红送配', 3 => '定期报告', 4 => '人事调整', 5 => '基金销售', 6 => '其他公告'];
        $items = [];
        foreach ($json['Data'] as $item) {
            if (!is_array($item)) continue;
            $id = (string)($item['ID'] ?? '');
            $fundCode = (string)($item['FUNDCODE'] ?? '');
            $date = (string)($item['PUBLISHDATEDesc'] ?? $item['PUBLISHDATE'] ?? '');
            if (strlen($date) >= 10) $date = substr($date, 0, 10);
            $category = (int)($item['NEWCATEGORY'] ?? 0);
            $items[] = [
                'title' => (string)($item['TITLE'] ?? $item['ShortTitle'] ?? ''),
                'announcement_type' => $typeNames[$category] ?? '其他公告',
                'date' => $date,
                'url' => ($fundCode !== '' && $id !== '') ? "https://fund.eastmoney.com/gonggao/{$fundCode},{$id}.html" : '',
                'pdf_url' => $id !== '' ? "https://pdf.dfcfw.com/pdf/H2_{$id}_1.pdf" : '',
                'provider_category' => $category,
                'source' => 'eastmoney_f10_current',
            ];
        }
        $pageSize = max(1, (int)($json['PageSize'] ?? count($items) ?: 1));
        $records = (int)($json['TotalCount'] ?? count($items));
        return [
            'items' => $items,
            'records' => $records,
            'pages' => $records > 0 ? (int)ceil($records / $pageSize) : 0,
            'page' => max(1, (int)($json['PageIndex'] ?? 1)),
        ];
    }

    private function parseSouthernDividendResponse(string $body): ?array
    {
        $parsed = HttpClient::parseJson($body);
        if (!$parsed['ok'] || !is_array($parsed['data'] ?? null)) return null;
        $json = $parsed['data'];
        if (($json['code'] ?? '') !== 'ETS-5BP00000') return null;
        $rows = $json['data']['jjfhlist']['list'] ?? null;
        if (!is_array($rows)) return null;
        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $recordDate = $this->normalizeCompactDate((string)($row['f8'] ?? $row['f3'] ?? ''));
            $exDate = $this->normalizeCompactDate((string)($row['f9'] ?? ''));
            $payDate = $this->normalizeCompactDate((string)($row['f10'] ?? ''));
            if ($recordDate === '') continue;
            $cash = isset($row['f7f6']) && is_numeric($row['f7f6']) ? (float)$row['f7f6'] : (isset($row['f7']) && is_numeric($row['f7']) ? (float)$row['f7'] : null);
            $events[] = [
                'year' => substr($recordDate, 0, 4),
                'record_date' => $recordDate,
                'ex_date' => $exDate,
                'pay_date' => $payDate,
                'cash_per_unit' => $cash,
                'dividend' => $cash !== null ? '每份派现金' . number_format($cash, 4, '.', '') . '元' : '',
                'date' => $recordDate,
                'event_stage' => $this->dividendEventStage($recordDate, $exDate, $payDate),
                'sources' => ['nffund_official'],
            ];
        }
        return $events;
    }

    private function parseSouthernAnnouncementResponse(string $body): ?array
    {
        $parsed = HttpClient::parseJson($body);
        if (!$parsed['ok'] || !is_array($parsed['data'] ?? null)) return null;
        $json = $parsed['data'];
        if (($json['code'] ?? '') !== 'ETS-5BP00000') return null;
        $rows = $json['data']['list'] ?? null;
        if (!is_array($rows)) return null;
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $path = (string)($row['linkUrl'] ?? '');
            if ($path !== '' && strpos($path, 'http') !== 0) {
                $path = 'https://www.nffund.com' . (strpos($path, '/') === 0 ? $path : '/' . $path);
            }
            $items[] = [
                'title' => (string)($row['title'] ?? ''),
                'announcement_type' => (string)($row['typeName'] ?? '临时报告'),
                'date' => (string)($row['createTimeString'] ?? substr((string)($row['publishTime'] ?? ''), 0, 10)),
                'url' => $path,
                'pdf_url' => $path,
                'doc_type' => 'dividend',
                'source' => 'nffund_official',
                'source_urls' => array_values(array_filter([$path])),
            ];
        }
        return $items;
    }

    private function normalizeCompactDate(string $date): string
    {
        $digits = preg_replace('/\D/', '', $date);
        if (strlen($digits) !== 8) return '';
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
    }

    private function dividendEventStage(string $recordDate, string $exDate, string $payDate): string
    {
        $today = date('Y-m-d');
        if ($recordDate === $today) return 'record_date_today';
        if ($exDate === $today) return 'ex_date_today';
        if ($payDate === $today) return 'payment_today';
        if ($recordDate !== '' && $recordDate > $today) return 'announced_upcoming_record_date';
        if ($exDate !== '' && $exDate > $today) return 'announced_upcoming_ex_date';
        if ($payDate !== '' && $payDate > $today) return 'payment_pending';
        return 'completed';
    }

    private function parseHistoryResponse(string $body): ?array
    {
        $jsonResult = HttpClient::parseJson($body);
        if ($jsonResult['ok'] && is_array($jsonResult['data'] ?? null)) {
            $json = $jsonResult['data'];
            $data = $json['Data'] ?? null;
            if ((int)($json['ErrCode'] ?? -1) !== 0 || !is_array($data) || !is_array($data['LSJZList'] ?? null)) {
                return null;
            }

            $items = [];
            foreach ($data['LSJZList'] as $row) {
                if (!is_array($row)) continue;
                $date = trim((string)($row['FSRQ'] ?? $row['SDATE'] ?? ''));
                if ($date === '') continue;
                $items[] = [
                    'date' => $date,
                    'nav' => (string)($row['DWJZ'] ?? ''),
                    'acc_nav' => (string)($row['LJJZ'] ?? ''),
                    'growth_rate' => (string)($row['JZZZL'] ?? ''),
                    'purchase_status' => (string)($row['SGZT'] ?? ''),
                    'redeem_status' => (string)($row['SHZT'] ?? ''),
                    'dividend' => (string)($row['FHSP'] ?? $row['FHFCBZ'] ?? $row['FHFCZ'] ?? ''),
                ];
            }

            $pageSize = max(1, (int)($json['PageSize'] ?? count($items) ?: 1));
            $records = max(0, (int)($json['TotalCount'] ?? count($items)));
            return [
                'items' => $items,
                'records' => $records,
                'pages' => $records > 0 ? (int)ceil($records / $pageSize) : 0,
                'page' => max(1, (int)($json['PageIndex'] ?? 1)),
            ];
        }

        // 兼容旧 F10DataApi.aspx 的 JavaScript/HTML 响应，便于旧缓存与镜像源继续使用。
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

    private function historyApiUrl(string $code, int $page, int $pageSize, string $startDate = '', string $endDate = ''): string
    {
        return 'https://api.fund.eastmoney.com/f10/lsjz?' . http_build_query([
            'fundCode' => $code,
            'pageIndex' => $page,
            'pageSize' => $pageSize,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    private function historyApiHeaders(string $code): array
    {
        return [
            'Referer' => "https://fundf10.eastmoney.com/jjjz_{$code}.html",
            'Accept' => 'application/json,text/plain,*/*',
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
            $apiContent = $this->fetchAnnouncementApiText($url, $contentLimit);
            if ($apiContent !== '') {
                $item['content_status'] = 'api_extracted';
                $item['content'] = $apiContent;
                return $item;
            }
        }

        $htmlContent = '';
        if ($url !== '') {
            $htmlContent = $this->fetchAnnouncementHtmlText($url, $contentLimit);
            // 东方财富公告详情由前端再请求正文 API；页面本身只有导航壳，不能据此终止 PDF 回退。
            $isEastmoneyShell = preg_match('/fund\.eastmoney\.com\/gonggao\/[^,]+,AN\d+\.html/i', $url) === 1;
            if ($htmlContent !== '' && !$isEastmoneyShell) {
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

        if ($item['content'] === '' && $htmlContent !== '') {
            $item['content_status'] = 'html_shell';
            $item['content_message'] = '公告页面仅返回导航壳，正文 API 与 PDF 均未能提供可核验内容。';
        }

        return $item;
    }

    private function fetchAnnouncementApiText(string $url, int $limit): string
    {
        if (!preg_match('/[,\/]((?:AN)\d+)/i', $url, $m)) {
            return '';
        }
        $artCode = strtoupper($m[1]);
        $apiUrl = 'https://np-cnotice-fund.eastmoney.com/api/content/ann?' . http_build_query([
            'client_source' => 'web_fund',
            'show_all' => 1,
            'art_code' => $artCode,
        ]);
        $resp = $this->http->get($apiUrl, [
            'Referer' => 'https://fund.eastmoney.com/',
            'Accept' => 'application/json,text/plain,*/*',
        ]);
        if ($resp['error'] || $resp['http_code'] !== 200 || $resp['body'] === '') {
            return '';
        }
        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok'] || !is_array($parsed['data'] ?? null)) {
            return '';
        }
        $json = $parsed['data'];
        if ((int)($json['success'] ?? 0) !== 1 || !is_array($json['data'] ?? null)) {
            return '';
        }
        $content = trim((string)($json['data']['notice_content'] ?? ''));
        return $content === '' ? '' : mb_substr($content, 0, $limit);
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
