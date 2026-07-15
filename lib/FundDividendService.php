<?php
/**
 * FundDividendService - 基金分红事件筛选、净值合并与分配比例计算。
 *
 * 与股票 DividendService 平行的服务层，但面向全市场公募基金：
 *   - 事件源：FundDividendDataProvider（东方财富 dt=8 全市场基金分红列表）。
 *   - 净值：FundService::batchNetValues（FundMNFInfo 批量，无逐基金 detail 回退）。
 *   - 详情证据：复用 FundService 的分红历史、公告、联接基金目标 ETF 与历史净值窗口。
 *
 * 沿用股票分红模块的缓存、熔断、负缓存、防击穿和 stale fallback 模式。
 */

require_once __DIR__ . '/FundDividendDataProvider.php';
require_once __DIR__ . '/EastmoneyFundDividendClient.php';
require_once __DIR__ . '/FundService.php';
require_once __DIR__ . '/MarketDataService.php';
require_once __DIR__ . '/CsindexClient.php';
require_once __DIR__ . '/EventMarketWindowService.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';

class FundDividendService
{
    const SOURCE_NAME = 'fund_dividend';

    /** @var FundDividendDataProvider */
    private $provider;
    /** @var FundService */
    private $fund;
    /** @var CacheStore */
    private $cache;
    /** @var MarketDataService */
    private $market;
    /** @var EventMarketWindowService */
    private $eventMarket;
    /** @var array */
    private $config;

    public function __construct(?FundDividendDataProvider $provider = null, ?FundService $fund = null, ?CacheStore $cache = null, ?MarketDataService $market = null, ?CsindexClient $csindex = null, ?EventMarketWindowService $eventMarket = null)
    {
        $this->provider = $provider ?: new EastmoneyFundDividendClient();
        $this->fund = $fund ?: new FundService($csindex);
        $this->cache = $cache ?: CacheStoreFactory::getInstance();
        $this->market = $market ?: new MarketDataService();
        $this->eventMarket = $eventMarket ?: new EventMarketWindowService($this->market);
        $configured = AppConfig::get('fund_dividend', []);
        $this->config = array_merge([
            'enabled' => true,
            'default_window_days' => 14,
            'max_window_days' => 60,
            'nav_batch_size' => 50,
            'auto_refresh_seconds' => 900,
            'calendar_ttl' => 900,
            'detail_ttl' => 1800,
            'type_map_ttl' => 86400,
            'nav_ttl' => 300,
            'negative_cache_ttl' => 20,
            'stampede_lock_ttl' => 5,
            'stampede_wait_ms' => 500,
        ], is_array($configured) ? $configured : []);
    }

    public function defaults(): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $days = max(1, (int)$this->config['default_window_days']);
        return [
            'start_date' => $today->format('Y-m-d'),
            'end_date' => $today->modify('+' . ($days - 1) . ' days')->format('Y-m-d'),
        ];
    }

    public function calendar(array $options = []): DataSourceResult
    {
        if (empty($this->config['enabled'])) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar', 'feature_disabled', '基金分红日历功能未启用');
        }

        $defaults = $this->defaults();
        $start = (string)($options['start_date'] ?? $defaults['start_date']);
        $end = (string)($options['end_date'] ?? $defaults['end_date']);
        $fundCategory = (string)($options['fund_category'] ?? 'all');
        $minRatio = max(0.0, min(100.0, (float)($options['min_distribution_ratio'] ?? 0)));
        $sortBy = (string)($options['sort_by'] ?? 'record_date');
        $order = (string)($options['order'] ?? 'asc');
        $page = max(1, (int)($options['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($options['page_size'] ?? 50)));

        $validation = $this->validateCalendar($start, $end, $fundCategory, $sortBy, $order);
        if ($validation !== null) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar', 'invalid_argument', $validation);
        }

        // 原始事件（按 start:end 缓存）
        $rawResult = $this->cachedProviderResult(
            'fund_dividend:calendar:' . $this->provider->sourceName() . ":{$start}:{$end}",
            (int)$this->config['calendar_ttl'],
            'fund_dividend_calendar_raw',
            function () use ($start, $end) { return $this->provider->calendar($start, $end); }
        );
        if (!$rawResult->hasData()) return $rawResult;

        // 类型映射（独立长缓存）
        $typeMapResult = $this->cachedProviderResult(
            'fund_dividend:type_map:' . $this->provider->sourceName(),
            (int)$this->config['type_map_ttl'],
            'fund_type_map',
            function () { return $this->provider->fundTypeMap(); }
        );
        $typeMap = $typeMapResult->hasData() ? (array)$typeMapResult->data : [];
        $typeMapAvailable = !empty($typeMap);

        // 指定类型筛选但类型映射不可用 -> metadata_unavailable，不伪造空结果
        if ($fundCategory !== 'all' && !$typeMapAvailable) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar', 'metadata_unavailable', '基金类型映射暂不可用，无法按类型筛选', [
                'partial' => true,
                'event_source' => $this->provider->sourceName(),
                'as_of' => $this->nowIso(),
            ]);
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $todayStr = $today->format('Y-m-d');

        // 归一化事件 + 类型映射
        $events = [];
        $typeMapFailures = [];
        foreach ((array)$rawResult->data as $row) {
            if (!is_array($row) || empty($row['record_date']) || empty($row['code'])) continue;
            $code = (string)$row['code'];
            $fundType = $typeMap[$code] ?? null;
            $category = $fundType ?? 'other';
            if ($fundCategory !== 'all' && $category !== $fundCategory) continue;

            $cash = is_numeric($row['cash_per_unit'] ?? null) ? (float)$row['cash_per_unit'] : null;
            $exDate = (string)($row['ex_date'] ?? '');
            $recordDate = (string)$row['record_date'];
            $payDate = (string)($row['pay_date'] ?? '');
            $currencyStatus = $this->currencyOf((string)($row['name'] ?? ''));
            $currency = $currencyStatus === 'unknown' ? 'unknown' : 'CNY';

            $events[] = [
                'asset_type' => 'fund',
                'code' => $code,
                'name' => (string)($row['name'] ?? ''),
                'fund_type' => $fundType ?? '',
                'fund_category' => $category,
                'record_date' => $recordDate,
                'ex_date' => $exDate !== '' ? $exDate : null,
                'pay_date' => $payDate !== '' ? $payDate : null,
                'days_to_record' => (int)$today->diff(new DateTimeImmutable($recordDate, new DateTimeZone('Asia/Shanghai')))->format('%r%a'),
                'cash_per_unit' => $this->round($cash, 6),
                'currency' => $currency,
                'currency_status' => $currencyStatus === 'unknown' ? 'unknown' : 'verified',
                'nav' => null,
                'nav_date' => null,
                'nav_status' => 'missing_nav',
                'distribution_ratio_pct' => null,
                'ratio_status' => $this->baseRatioStatus($cash, $currency, $exDate),
                'event_stage' => $this->eventStage($recordDate, $exDate, $payDate, $todayStr),
                'implementation_confirmed' => true,
                'announcement_match_status' => 'not_checked',
                'source' => $this->provider->sourceName(),
                'source_url' => $row['source_url'] ?? ('https://fundf10.eastmoney.com/fhsp_' . $code . '.html'),
            ];
        }

        // 仅为除息日不早于今天的候选事件补充最新净值
        $navCandidates = array_values(array_unique(array_filter(array_map(function ($e) use ($todayStr) {
            return $e['ex_date'] !== null && $e['ex_date'] >= $todayStr ? $e['code'] : null;
        }, $events))));
        $navMap = [];
        $navFailures = [];
        if (!empty($navCandidates)) {
            $navResult = $this->fund->batchNetValues($navCandidates);
            if ($navResult->hasData()) {
                foreach ((array)$navResult->data as $nav) {
                    if (is_array($nav) && !empty($nav['code'])) {
                        $navMap[(string)$nav['code']] = $nav;
                    }
                }
            }
            $navFailures = $navResult->meta['failures'] ?? [];
        }

        // 合并净值 + 计算分配比例
        $items = [];
        $missingNavCount = 0;
        $ratioCoverage = 0;
        foreach ($events as &$event) {
            $code = $event['code'];
            $nav = $navMap[$code] ?? null;
            $navValue = is_array($nav) && is_numeric($nav['nav'] ?? null) && (float)$nav['nav'] > 0 ? (float)$nav['nav'] : null;
            $navDate = is_array($nav) ? (string)($nav['nav_date'] ?? '') : '';
            if ($navValue !== null) {
                $event['nav'] = $this->round($navValue, 4);
                $event['nav_date'] = $navDate !== '' ? $navDate : null;
                $event['nav_status'] = 'available';
            } elseif (in_array($code, $navCandidates, true)) {
                $missingNavCount++;
            }
            $event['distribution_ratio_pct'] = $this->computeRatio($event, $navValue, $navDate);
            $event['ratio_status'] = $this->resolveRatioStatus($event, $navValue, $navDate);
            if ($event['ratio_status'] === 'available') $ratioCoverage++;
        }
        unset($event);

        // 最低分配比例筛选：排除无安全比例的事件，不得用缺失净值补零
        if ($minRatio > 0) {
            $items = array_values(array_filter($events, function ($e) use ($minRatio) {
                return $e['distribution_ratio_pct'] !== null && (float)$e['distribution_ratio_pct'] >= $minRatio;
            }));
        } else {
            $items = $events;
        }

        $this->sortItems($items, $sortBy, $order);
        $total = count($items);
        $summary = $this->summary($items);
        $offset = ($page - 1) * $pageSize;
        $paged = array_slice($items, $offset, $pageSize);

        $partial = !$typeMapAvailable || $missingNavCount > 0 || !empty($navFailures) || !empty($rawResult->meta['failures'] ?? []);
        $failures = array_merge(
            $rawResult->meta['failures'] ?? [],
            !$typeMapAvailable && !$typeMapResult->success ? [['stage' => 'type_map', 'code' => $typeMapResult->errorCode, 'message' => $typeMapResult->errorMessage]] : [],
            $navFailures
        );

        return DataSourceResult::success($this->provider->sourceName(), 'fund_dividend_calendar', [
            'items' => $paged,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'pages' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
            ],
            'filters' => compact('start', 'end', 'fundCategory', 'minRatio', 'sortBy', 'order'),
        ], [
            'partial' => $partial,
            'failures' => $failures,
            'event_source' => $this->provider->sourceName(),
            'nav_source' => 'eastmoney_fund_mnf_info',
            'missing_nav_count' => $missingNavCount,
            'ratio_coverage_count' => $ratioCoverage,
            'type_map_available' => $typeMapAvailable,
            'upstream_cache' => $rawResult->meta['cache'] ?? 'miss',
            'as_of' => $this->nowIso(),
        ]);
    }

    public function detail(string $code, ?string $eventDate = null): DataSourceResult
    {
        if (empty($this->config['enabled'])) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_detail', 'feature_disabled', '基金分红日历功能未启用');
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_detail', 'invalid_code', '基金代码必须为 6 位数字');
        }
        if ($eventDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_detail', 'invalid_date', '事件日期必须为 YYYY-MM-DD');
        }

        $key = 'fund_dividend:detail:v2:' . $code . ':' . ($eventDate ?? 'latest');
        $cached = $this->cachedProviderResult(
            $key,
            (int)$this->config['detail_ttl'],
            'fund_dividend_detail',
            function () use ($code, $eventDate) { return $this->fetchDetail($code, $eventDate); }
        );
        return $cached;
    }

    /**
     * AI 分红证据档案：保持 FundService 原结构并补充事件级公告精确匹配。
     */
    public function evidenceProfile(string $code, ?string $eventDate = null, int $limit = 10, bool $includeRelated = true, bool $includeAnnouncements = true, int $announcementLimit = 5): DataSourceResult
    {
        $profile = $this->fund->dividendProfile($code, $limit, $includeRelated, $includeAnnouncements, $announcementLimit);
        if (!$profile->hasData()) return $profile;
        $detail = $this->detail($code, $eventDate);
        $data = (array)$profile->data;
        $status = 'check_failed';
        $matched = null;
        $selected = null;
        $detailFailure = null;
        if ($detail->hasData()) {
            $selected = $detail->data['selected_event'] ?? null;
            $status = (string)($detail->data['announcement_match_status'] ?? 'not_checked');
            $matched = $detail->data['matched_announcement'] ?? null;
        } else {
            $detailFailure = ['source' => $detail->source, 'code' => $detail->errorCode, 'message' => $detail->errorMessage];
        }

        $firstParty = (array)($data['direct_fund']['first_party_verification'] ?? []);
        $selectedSources = is_array($selected) ? (array)($selected['sources'] ?? []) : [];
        $firstPartyHit = ($firstParty['status'] ?? '') === 'available'
            && !empty($firstParty['events_checked'])
            && count(array_filter($selectedSources, function ($source) { return strpos((string)$source, 'official') !== false; })) > 0;
        if ($firstPartyHit) {
            $evidenceLevel = 'first_party_verified';
            $sourceKind = 'manager_first_party';
        } elseif ($status === 'verified') {
            $evidenceLevel = 'aggregator_document_verified';
            $sourceKind = 'eastmoney_f10_document';
        } elseif ($status === 'check_failed') {
            $evidenceLevel = 'check_failed';
            $sourceKind = 'unavailable';
        } elseif (is_array($selected)) {
            $evidenceLevel = 'event_only';
            $sourceKind = 'dividend_event_table';
        } else {
            $evidenceLevel = 'unverified';
            $sourceKind = 'none';
        }

        $publicStatus = $status === 'checked_unmatched' ? 'unmatched' : $status;
        $data['selected_event'] = $selected;
        $data['announcement_match_status'] = $publicStatus;
        $data['matched_announcement'] = $matched;
        $data['verification'] = [
            'evidence_level' => $evidenceLevel,
            'source_kind' => $sourceKind,
            'first_party_status' => (string)($firstParty['status'] ?? 'not_configured_for_manager'),
        ];
        if ($detailFailure !== null) $data['verification']['failure'] = $detailFailure;
        return DataSourceResult::success('fund_dividend_profile', 'dividend_profile', $data, array_merge($profile->meta, [
            'event_date' => $eventDate,
            'announcement_match_status' => $publicStatus,
            'partial' => !empty($profile->meta['partial']) || $detailFailure !== null || $status === 'check_failed',
        ]));
    }

    /**
     * 聚合基金分红事件的净值、ETF 场内日 K、流动性快照与中证全收益基准。
     */
    public function eventResearch(string $code, ?string $eventDate = null, int $before = 10, int $after = 15, int $previousEvents = 1, bool $includeBenchmark = true): DataSourceResult
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'invalid_code', '基金代码必须为 6 位数字');
        }
        if ($eventDate !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate, new DateTimeZone('Asia/Shanghai'));
            if (!$parsed || $parsed->format('Y-m-d') !== $eventDate) {
                return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'invalid_date', '事件日期格式无效');
            }
        }
        if ($before < 5 || $before > 30 || $after < 5 || $after > 30) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'invalid_window', '事件窗口必须在 5 至 30 个交易日之间');
        }
        if ($previousEvents < 0 || $previousEvents > 3) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'invalid_previous_events', '历史事件数量必须在 0 至 3 之间');
        }

        $detail = $this->detail($code, $eventDate);
        if (!$detail->hasData()) {
            return DataSourceResult::error($detail->source ?: self::SOURCE_NAME, 'fund_dividend_event_market', $detail->errorCode ?: 'detail_unavailable', $detail->errorMessage ?: '基金分红事件不可用', $detail->meta);
        }
        $fund = (array)($detail->data['fund'] ?? []);
        $selected = is_array($detail->data['selected_event'] ?? null) ? $detail->data['selected_event'] : null;
        if ($selected === null) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'event_not_found', '未找到可研究的基金分红事件');
        }

        $exchangeCode = $this->exchangeProviderCode($code);
        $isExchangeTraded = $exchangeCode !== null;
        $fundOutput = [
            'code' => $code,
            'name' => (string)($fund['name'] ?? ''),
            'fund_type' => (string)($fund['fund_type'] ?? ''),
            'exchange_traded' => $isExchangeTraded,
            'market_code' => $exchangeCode,
        ];
        $failures = [];
        $snapshot = $this->currentSnapshot($fund, $exchangeCode, $failures);

        $events = [$selected];
        $selectedKey = $this->eventKey($selected);
        $selectedAnchor = (string)($selected['ex_date'] ?? $selected['record_date'] ?? '');
        foreach ((array)($detail->data['history'] ?? []) as $candidate) {
            if ($previousEvents <= 0 || count($events) >= $previousEvents + 1 || !is_array($candidate)) break;
            if ($this->eventKey($candidate) === $selectedKey) continue;
            $anchor = (string)($candidate['ex_date'] ?? $candidate['record_date'] ?? '');
            if (($candidate['event_stage'] ?? '') !== 'completed' || ($selectedAnchor !== '' && $anchor >= $selectedAnchor)) continue;
            $events[] = $candidate;
        }

        $indexCode = '';
        if ($includeBenchmark) {
            $indexProfile = $this->fund->indexProfile($code);
            $indexCode = $indexProfile->hasData() ? (string)($indexProfile->data['index_code'] ?? '') : '';
            if ($indexCode === '') {
                $failures[] = ['component' => 'benchmark_profile', 'code' => $indexProfile->errorCode ?: 'missing_index_code', 'message' => $indexProfile->errorMessage ?: '基金未提供跟踪指数代码'];
            }
        }

        $windows = [];
        $availableComponents = 0;
        $hasPending = false;
        foreach ($events as $event) {
            $anchor = (string)($event['ex_date'] ?? $event['record_date'] ?? '');
            if ($anchor === '') continue;
            $componentFailures = [];
            $navRequestBefore = min(30, max(5, (int)ceil($before * 1.8)));
            $navRequestAfter = min(30, max(5, (int)ceil($after * 1.8)));
            $navResult = $this->eventMarketWindow($code, $anchor, $navRequestBefore, $navRequestAfter);
            $navData = $navResult->hasData() ? (array)$navResult->data : null;
            if ($navData !== null) $availableComponents++;
            else $componentFailures[] = ['component' => 'nav_window', 'code' => $navResult->errorCode, 'message' => $navResult->errorMessage];

            $marketData = null;
            $marketStatus = $isExchangeTraded ? 'unavailable' : 'not_applicable';
            if ($isExchangeTraded) {
                $marketResult = $this->eventMarket->window($exchangeCode, $code, $anchor, $before, $after);
                if ($marketResult->hasData()) {
                    $marketData = (array)$marketResult->data;
                    $marketStatus = 'available';
                    $availableComponents++;
                } else {
                    $componentFailures[] = ['component' => 'market_window', 'code' => $marketResult->errorCode, 'message' => $marketResult->errorMessage];
                }
            }

            $benchmarkData = null;
            $benchmarkStatus = $includeBenchmark ? 'unavailable' : 'skipped';
            if ($includeBenchmark && $indexCode !== '') {
                $eventDt = new DateTimeImmutable($anchor, new DateTimeZone('Asia/Shanghai'));
                $start = $eventDt->modify('-' . max(20, $before * 3) . ' days')->format('Y-m-d');
                $end = $eventDt->modify('+' . max(30, $after * 3) . ' days')->format('Y-m-d');
                $benchmarkResult = $this->fund->indexHistoryWindow($indexCode, $start, $end, true);
                if ($benchmarkResult->hasData()) {
                    $rows = $this->sliceEventRows((array)$benchmarkResult->data, $anchor, $before, $after);
                    if (!empty($rows)) {
                        $benchmarkData = [
                            'index_code' => $indexCode,
                            'series_code' => $indexCode . 'CNY010',
                            'series_kind' => 'total_return',
                            'source' => $benchmarkResult->source,
                            'source_url' => $benchmarkResult->meta['source_url'] ?? null,
                            'factsheet_url' => $benchmarkResult->meta['factsheet_url'] ?? null,
                            'sample_count' => count($rows),
                            'rows' => $rows,
                            'summary' => $this->seriesSummary($rows, $anchor),
                        ];
                        $benchmarkStatus = 'available';
                        $availableComponents++;
                    }
                }
                if ($benchmarkData === null) {
                    $componentFailures[] = ['component' => 'benchmark_window', 'code' => $benchmarkResult->errorCode ?: 'benchmark_empty', 'message' => $benchmarkResult->errorMessage ?: '全收益指数窗口不可用'];
                }
            }

            $metrics = $this->eventMetrics($event, $navData, $marketData, $benchmarkData);
            $pending = !empty($metrics['post_event_data_pending']);
            $hasPending = $hasPending || $pending;
            foreach ($componentFailures as $failure) $failures[] = array_merge(['event_date' => $anchor], $failure);
            $windows[] = [
                'event' => $event,
                'event_date' => $anchor,
                'nav_window' => $navData,
                'market_window' => $marketData,
                'benchmark_window' => $benchmarkData,
                'metrics' => $metrics,
                'component_status' => [
                    'nav' => $navData !== null ? 'available' : 'unavailable',
                    'market' => $marketStatus,
                    'benchmark' => $benchmarkStatus,
                ],
                'failures' => $componentFailures,
            ];
        }

        if ($availableComponents === 0 || empty($windows)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'event_components_unavailable', '事件已确认，但净值、场内日 K 与基准窗口均不可用', ['failures' => $failures]);
        }
        return DataSourceResult::success(self::SOURCE_NAME, 'fund_dividend_event_market', [
            'fund' => $fundOutput,
            'selected_event' => $selected,
            'current_snapshot' => $snapshot,
            'event_windows' => $windows,
        ], [
            'as_of' => $this->nowIso(),
            'requested_before' => $before,
            'requested_after' => $after,
            'previous_events' => $previousEvents,
            'include_benchmark' => $includeBenchmark,
            'post_event_data_pending' => $hasPending,
            'partial' => !empty($failures) || $hasPending,
            'failures' => $failures,
        ]);
    }

    public function eventMarketWindow(string $code, string $eventDate, int $before = 10, int $after = 15): DataSourceResult
    {
        if (empty($this->config['enabled'])) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'feature_disabled', '基金分红日历功能未启用');
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'invalid_code', '基金代码必须为 6 位数字');
        }
        $event = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate, new DateTimeZone('Asia/Shanghai'));
        if (!$event || $event->format('Y-m-d') !== $eventDate) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_event_market', 'invalid_date', '事件日期格式无效');
        }
        $before = max(5, min(30, $before));
        $after = max(5, min(30, $after));
        $sdate = $event->modify('-' . $before . ' days')->format('Y-m-d');
        $edate = $event->modify('+' . $after . ' days')->format('Y-m-d');

        $result = $this->fund->historyWindow($code, $sdate, $edate);
        if (!$result->hasData()) {
            return DataSourceResult::error($result->source ?: self::SOURCE_NAME, 'fund_dividend_event_market', $result->errorCode ?: 'nav_unavailable', $result->errorMessage ?: '该次分红附近的基金净值暂不可用', $result->meta);
        }

        $rows = [];
        foreach ((array)$result->data as $row) {
            if (!is_array($row)) continue;
            $rows[] = [
                'date' => (string)($row['date'] ?? ''),
                'nav' => $this->round($this->numeric($row['nav'] ?? null), 4),
                'acc_nav' => $this->round($this->numeric($row['acc_nav'] ?? null), 4),
                'growth_rate' => $this->round($this->numeric($row['growth_rate'] ?? null), 4),
            ];
        }
        usort($rows, function ($a, $b) { return strcmp($a['date'], $b['date']); });
        if (empty($rows)) {
            return DataSourceResult::error($result->source, 'fund_dividend_event_market', 'nav_empty', '该次分红附近没有可展示的基金净值');
        }

        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $eventIndex = null;
        foreach ($rows as $index => $row) {
            if ($row['date'] === $eventDate) { $eventIndex = $index; break; }
        }
        // 未来事件尚无除息后净值 -> 仅返回已有前置数据
        $postEventRows = $eventIndex !== null ? array_slice($rows, $eventIndex + 1) : array_filter($rows, function ($r) use ($eventDate) { return $r['date'] > $eventDate; });
        $postEventDataPending = $eventDate >= $today || empty($postEventRows);

        foreach ($rows as $index => &$row) {
            $row['is_event_day'] = $eventIndex !== null && $index === $eventIndex;
        }
        unset($row);

        $preNav = null;
        foreach ($rows as $row) {
            // “除息前净值”必须严格早于事件日；不能回退为事件日或窗口末值。
            if ($row['nav'] !== null && $row['date'] < $eventDate) {
                $preNav = $row['nav'];
            }
        }
        $eventRow = $eventIndex !== null ? ($rows[$eventIndex] ?? null) : null;

        return DataSourceResult::success($result->source, 'fund_dividend_event_market', [
            'code' => $code,
            'event_date' => $eventDate,
            'event_index' => $eventIndex,
            'rows' => $rows,
            'summary' => [
                'pre_event_nav' => $this->round($preNav, 4),
                'event_nav' => $eventRow['nav'] ?? null,
                'window_high' => $this->round($this->maxNumeric(array_column($rows, 'nav')), 4),
                'window_low' => $this->round($this->minNumeric(array_column($rows, 'nav')), 4),
                'post_event_data_pending' => $postEventDataPending,
            ],
        ], [
            'as_of' => $this->nowIso(),
            'requested_before' => $before,
            'requested_after' => $after,
            'sdate' => $sdate,
            'edate' => $edate,
            'post_event_data_pending' => $postEventDataPending,
        ]);
    }

    private function fetchDetail(string $code, ?string $eventDate): DataSourceResult
    {
        $info = $this->fund->info([$code]);
        $fund = $info->hasData() && is_array($info->data[0] ?? null) ? $info->data[0] : [];

        $historyResult = $this->fund->dividendHistory($code, 1, 100);
        $history = $historyResult->hasData() ? (array)$historyResult->data : [];

        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

        // 标准化历史事件
        $events = [];
        foreach ($history as $item) {
            $recordDate = (string)($item['record_date'] ?? '');
            $exDate = (string)($item['ex_date'] ?? '');
            $payDate = (string)($item['pay_date'] ?? '');
            $cash = is_numeric($item['cash_per_unit'] ?? null) ? (float)$item['cash_per_unit'] : null;
            $events[] = [
                'record_date' => $recordDate !== '' ? $recordDate : null,
                'ex_date' => $exDate !== '' ? $exDate : null,
                'pay_date' => $payDate !== '' ? $payDate : null,
                'cash_per_unit' => $this->round($cash, 6),
                'event_stage' => $this->eventStage($recordDate, $exDate, $payDate, $today),
                'sources' => $item['sources'] ?? [],
            ];
        }
        usort($events, function ($a, $b) {
            return strcmp((string)($b['record_date'] ?? ''), (string)($a['record_date'] ?? ''));
        });

        // 选中事件
        $selected = null;
        if ($eventDate !== null) {
            foreach ($events as $event) {
                $rd = (string)($event['record_date'] ?? '');
                $ex = (string)($event['ex_date'] ?? '');
                if ($rd === $eventDate || $ex === $eventDate) { $selected = $event; break; }
            }
        }
        if ($selected === null) {
            foreach ($events as $event) {
                if ($event['event_stage'] !== 'completed') { $selected = $event; break; }
            }
        }
        if ($selected === null && !empty($events)) {
            $selected = $events[0];
        }

        // 公告核验：正文同时匹配基金代码（隐含）、登记/除息日期及分红金额才标记 verified
        $announcementStatus = 'not_checked';
        $matchedAnnouncement = null;
        $announcements = [];
        if ($selected !== null) {
            $docs = $this->fund->fundDocuments($code, 1, 3, 'dividend', true, 4000);
            if ($docs->hasData()) {
                $announcements = array_slice((array)$docs->data, 0, 5);
                $match = $this->matchAnnouncementEvidence($selected, $announcements);
                $announcementStatus = $match['status'];
                $matchedAnnouncement = $match['announcement'];
            } elseif (!$docs->success) {
                $announcementStatus = 'check_failed';
            } else {
                $announcementStatus = 'checked_unmatched';
            }
        }

        // 联接基金目标 ETF 关系（复用 dividendProfile 的 resolveTargetEtf）
        $relatedFunds = [];
        $isLinkFund = preg_match('/联接/u', (string)($fund['name'] ?? '') . ' ' . (string)($fund['full_name'] ?? '')) === 1;
        if ($isLinkFund) {
            $profile = $this->fund->dividendProfile($code, 5, true, false);
            if ($profile->hasData()) {
                $relatedFunds = (array)($profile->data['related_funds'] ?? []);
            }
        }

        // 摘要
        $cashEvents = array_values(array_filter($events, function ($e) { return (float)($e['cash_per_unit'] ?? 0) > 0; }));
        $yearsCovered = [];
        $fiveYearCash = 0.0;
        $fiveCutoff = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->modify('-5 years')->format('Y-m-d');
        foreach ($cashEvents as $event) {
            $date = (string)($event['record_date'] ?? '');
            if ($date !== '') $yearsCovered[substr($date, 0, 4)] = true;
            if ($date >= $fiveCutoff) $fiveYearCash += (float)$event['cash_per_unit'];
        }

        $failures = [];
        if (!$info->hasData()) $failures[] = ['stage' => 'fund_info', 'code' => $info->errorCode, 'message' => $info->errorMessage];
        if (!$historyResult->hasData()) $failures[] = ['stage' => 'dividend_history', 'code' => $historyResult->errorCode, 'message' => $historyResult->errorMessage];

        $currencyStatus = $this->currencyOf((string)($fund['name'] ?? ''));

        return DataSourceResult::success(self::SOURCE_NAME, 'fund_dividend_detail', [
            'fund' => [
                'code' => $code,
                'name' => (string)($fund['name'] ?? ''),
                'full_name' => (string)($fund['full_name'] ?? ''),
                'fund_type' => (string)($fund['type'] ?? ''),
                'fund_company' => (string)($fund['fund_company'] ?? ''),
                'is_link_fund' => $isLinkFund,
                'nav' => $this->round($this->numeric($fund['nav'] ?? null), 4),
                'nav_date' => (string)($fund['nav_date'] ?? ''),
                'acc_nav' => $this->round($this->numeric($fund['acc_nav'] ?? null), 4),
                'currency_status' => $currencyStatus,
                'source_url' => 'https://fundf10.eastmoney.com/fhsp_' . $code . '.html',
            ],
            'selected_event' => $selected,
            'history' => $events,
            'summary' => [
                'cash_dividend_events' => count($cashEvents),
                'years_with_cash_dividend' => count($yearsCovered),
                'five_year_total_cash_per_unit' => $this->round($fiveYearCash, 6),
            ],
            'announcements' => $announcements,
            'announcement_match_status' => $announcementStatus,
            'matched_announcement' => $matchedAnnouncement,
            'related_funds' => $relatedFunds,
            'scope_note' => '目标 ETF 分红属于联接基金资产层面的收入，不等同于向联接基金份额持有人直接派发现金；两层事件必须分别表述。',
        ], [
            'code' => $code,
            'event_date' => $eventDate,
            'partial' => !empty($failures) || $announcementStatus === 'check_failed',
            'failures' => $failures,
            'as_of' => $this->nowIso(),
        ]);
    }

    /**
     * 公告正文匹配：同时包含分红金额与登记/除息日期才 verified。
     */
    private function matchAnnouncement(array $event, array $announcements): string
    {
        return $this->matchAnnouncementEvidence($event, $announcements)['status'];
    }

    private function matchAnnouncementEvidence(array $event, array $announcements): array
    {
        $cash = $event['cash_per_unit'] ?? null;
        $recordDate = (string)($event['record_date'] ?? '');
        $exDate = (string)($event['ex_date'] ?? '');
        if (empty($announcements)) {
            return ['status' => 'checked_unmatched', 'announcement' => null];
        }
        foreach ($announcements as $ann) {
            $content = (string)($ann['content'] ?? $ann['title'] ?? '');
            if ($content === '') continue;
            $amountHit = $cash !== null && $this->contentContainsAmount($content, (float)$cash);
            $dateHit = $this->contentContainsDate($content, $recordDate) || $this->contentContainsDate($content, $exDate);
            if ($amountHit && $dateHit) {
                return [
                    'status' => 'verified',
                    'announcement' => [
                        'title' => (string)($ann['title'] ?? ''),
                        'date' => (string)($ann['date'] ?? ''),
                        'url' => (string)($ann['url'] ?? ''),
                        'pdf_url' => (string)($ann['pdf_url'] ?? ''),
                        'content_status' => (string)($ann['content_status'] ?? ''),
                        'matched_conditions' => ['cash_per_unit', 'record_or_ex_date'],
                    ],
                ];
            }
        }
        return ['status' => 'checked_unmatched', 'announcement' => null];
    }

    private function contentContainsAmount(string $content, float $cash): bool
    {
        if ($cash <= 0) return false;
        // 尝试多种格式：0.005 / 0.0050 / 0.00500
        $candidates = [
            number_format($cash, 4, '.', ''),
            number_format($cash, 3, '.', ''),
            number_format($cash, 2, '.', ''),
            rtrim(rtrim(number_format($cash, 4, '.', ''), '0'), '.'),
            (string)$cash,
        ];
        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== '' && strpos($content, $candidate) !== false) {
                return true;
            }
        }
        // ETF 公告常只披露“每 10 份”金额；日历事件则已归一为每份金额。
        $compact = preg_replace('/\s+/u', '', $content) ?? $content;
        $perTenCandidates = array_unique([
            number_format($cash * 10, 4, '.', ''),
            number_format($cash * 10, 3, '.', ''),
            rtrim(rtrim(number_format($cash * 10, 4, '.', ''), '0'), '.'),
        ]);
        foreach ($perTenCandidates as $candidate) {
            if ($candidate === '') continue;
            $quoted = preg_quote($candidate, '/');
            if (preg_match('/(?:每10份基金份额[^0-9]{0,40}|元\/10份基金份额[^0-9]{0,40})' . $quoted . '(?:元|人民币)?/u', $compact)) {
                return true;
            }
        }
        return false;
    }

    private function contentContainsDate(string $content, string $date): bool
    {
        if ($date === '') return false;
        $compact = preg_replace('/\s+/u', '', $content) ?? $content;
        if (strpos($compact, $date) !== false) return true;
        // 中文日期格式 2026年7月17日
        $cn = preg_replace('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', '$1年$2月$3日', $date);
        $cn = preg_replace('/^(\d{4})年0?(\d{1,2})月0?(\d{1,2})日$/', '$1年$2月$3日', $cn ?? '');
        if ($cn !== '' && $cn !== $date && strpos($compact, $cn) !== false) return true;
        return false;
    }

    private function currencyOf(string $name): string
    {
        if (preg_match('/美元|港币|外币|现汇|现钞/u', $name)) return 'unknown';
        return 'CNY';
    }

    private function baseRatioStatus(?float $cash, string $currency, string $exDate): string
    {
        if ($cash === null || $cash <= 0) return 'missing_nav';
        if ($currency === 'unknown') return 'currency_unverified';
        return 'missing_nav';
    }

    private function resolveRatioStatus(array $event, ?float $nav, string $navDate): string
    {
        $cash = $event['cash_per_unit'];
        $currency = $event['currency'];
        $exDate = (string)($event['ex_date'] ?? '');
        if ($cash === null || $cash <= 0) return 'missing_nav';
        if ($currency === 'unknown') return 'currency_unverified';
        if ($nav === null || $nav <= 0) return 'missing_nav';
        if ($navDate !== '' && $exDate !== '' && $navDate >= $exDate) return 'nav_not_pre_ex';
        return 'available';
    }

    private function computeRatio(array $event, ?float $nav, string $navDate): ?float
    {
        $cash = $event['cash_per_unit'];
        $currency = $event['currency'];
        $exDate = (string)($event['ex_date'] ?? '');
        if ($cash === null || $cash <= 0) return null;
        if ($currency === 'unknown') return null;
        if ($nav === null || $nav <= 0) return null;
        if ($navDate !== '' && $exDate !== '' && $navDate >= $exDate) return null;
        return $this->round($cash / $nav * 100, 4);
    }

    private function eventStage(string $recordDate, string $exDate, string $payDate, string $today): string
    {
        if ($recordDate !== '' && $today < $recordDate) return 'upcoming_record';
        if ($exDate !== '' && $today <= $exDate) return 'upcoming_ex';
        if ($payDate !== '' && $today <= $payDate) return 'payment_pending';
        if ($payDate === '' && $exDate !== '' && $today > $exDate) return 'payment_pending';
        return 'completed';
    }

    private function validateCalendar(string $start, string $end, string $fundCategory, string $sortBy, string $order): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return '日期必须为 YYYY-MM-DD';
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$from || !$to || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end) return '日期不是有效日历日期';
        if ($to < $from) return '结束日期不能早于开始日期';
        if ((int)$from->diff($to)->format('%a') + 1 > (int)$this->config['max_window_days']) return '查询日期跨度最多为' . (int)$this->config['max_window_days'] . '天';
        if (!in_array($fundCategory, ['all', 'stock', 'index', 'mixed', 'bond', 'money', 'fof', 'qdii', 'reit', 'other'], true)) return '基金类型参数无效';
        if (!in_array($sortBy, ['record_date', 'distribution_ratio', 'cash_per_unit', 'pay_date'], true)) return '排序字段无效';
        if (!in_array($order, ['asc', 'desc'], true)) return '排序方向无效';
        return null;
    }

    private function sortItems(array &$items, string $sortBy, string $order): void
    {
        $keyMap = [
            'record_date' => 'record_date',
            'distribution_ratio' => 'distribution_ratio_pct',
            'cash_per_unit' => 'cash_per_unit',
            'pay_date' => 'pay_date',
        ];
        $key = $keyMap[$sortBy] ?? 'record_date';
        usort($items, function ($a, $b) use ($key, $order) {
            $av = $a[$key] ?? null;
            $bv = $b[$key] ?? null;
            if ($av === null && $bv !== null) return 1;
            if ($bv === null && $av !== null) return -1;
            $cmp = is_numeric($av) && is_numeric($bv) ? ((float)$av <=> (float)$bv) : strcmp((string)$av, (string)$bv);
            if ($cmp === 0) $cmp = strcmp((string)($a['record_date'] ?? ''), (string)($b['record_date'] ?? '')) ?: strcmp((string)$a['code'], (string)$b['code']);
            return $order === 'asc' ? $cmp : -$cmp;
        });
    }

    private function summary(array $items): array
    {
        $ratios = array_values(array_filter(array_map(function ($item) {
            return is_numeric($item['distribution_ratio_pct'] ?? null) ? (float)$item['distribution_ratio_pct'] : null;
        }, $items), function ($v) { return $v !== null; }));
        sort($ratios, SORT_NUMERIC);
        $median = null;
        $n = count($ratios);
        if ($n > 0) $median = $n % 2 ? $ratios[intdiv($n, 2)] : ($ratios[$n / 2 - 1] + $ratios[$n / 2]) / 2;
        $codes = array_unique(array_column($items, 'code'));
        return [
            'event_count' => count($items),
            'unique_fund_count' => count($codes),
            'within_3_days_count' => count(array_filter($items, function ($item) { return ($item['days_to_record'] ?? 999) >= 0 && ($item['days_to_record'] ?? 999) <= 3; })),
            'max_distribution_ratio_pct' => $this->round(empty($ratios) ? null : max($ratios), 4),
            'median_distribution_ratio_pct' => $this->round($median, 4),
            'ratio_coverage_count' => $n,
        ];
    }

    /**
     * 基金代码的交易所解析只服务于基金工具，避免改变股票 StockCode 的既有语义。
     */
    private function exchangeProviderCode(string $code): ?string
    {
        if (preg_match('/^5\d{5}$/', $code)) return 'sh' . $code;
        if (preg_match('/^1\d{5}$/', $code)) return 'sz' . $code;
        return null;
    }

    private function currentSnapshot(array $fund, ?string $exchangeCode, array &$failures): array
    {
        $nav = $this->numeric($fund['nav'] ?? null);
        $navDate = (string)($fund['nav_date'] ?? '');
        $base = [
            'status' => $exchangeCode === null ? 'not_applicable' : 'unavailable',
            'price' => null,
            'change_pct' => null,
            'open' => null,
            'high' => null,
            'low' => null,
            'prev_close' => null,
            'volume' => null,
            'amount' => null,
            'turnover_rate' => null,
            'unit_nav' => $this->round($nav, 4),
            'nav_date' => $navDate !== '' ? $navDate : null,
            'premium_discount_pct' => null,
            'premium_discount_status' => $exchangeCode === null ? 'not_applicable' : 'quote_unavailable',
        ];
        if ($exchangeCode === null) return $base;

        $quoteResult = $this->market->quote($exchangeCode, MarketDataService::SOURCE_EASTMONEY, false, false);
        $quote = $quoteResult->hasData() && is_array($quoteResult->data[0] ?? null) ? $quoteResult->data[0] : null;
        if ($quote === null) {
            $failures[] = [
                'component' => 'current_snapshot',
                'code' => $quoteResult->errorCode ?: 'quote_unavailable',
                'message' => $quoteResult->errorMessage ?: 'ETF 当前行情不可用',
            ];
            return $base;
        }

        // 明确白名单：ETF 快照不回传 PE、ROE、股本等股票专属字段。
        foreach (['price', 'change_pct', 'open', 'high', 'low', 'prev_close', 'volume', 'amount', 'turnover_rate'] as $field) {
            $base[$field] = $this->round($this->numeric($quote[$field] ?? null), in_array($field, ['volume', 'amount'], true) ? 2 : 4);
        }
        $base['status'] = 'available';
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        if ($base['price'] === null || $base['price'] <= 0) {
            $base['premium_discount_status'] = 'missing_price';
        } elseif ($nav === null || $nav <= 0 || $navDate === '') {
            $base['premium_discount_status'] = 'missing_nav';
        } elseif ($navDate !== $today) {
            $base['premium_discount_status'] = 'nav_date_mismatch';
        } else {
            $base['premium_discount_pct'] = $this->round(($base['price'] / $nav - 1) * 100, 4);
            $base['premium_discount_status'] = 'available';
        }
        return $base;
    }

    private function eventKey(array $event): string
    {
        return implode('|', [
            (string)($event['record_date'] ?? ''),
            (string)($event['ex_date'] ?? ''),
            (string)($event['pay_date'] ?? ''),
            (string)($event['cash_per_unit'] ?? ''),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function sliceEventRows(array $rows, string $eventDate, int $before, int $after): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $date = substr((string)($row['date'] ?? $row['time'] ?? ''), 0, 10);
            $close = $this->numeric($row['close'] ?? null);
            if ($date === '' || $close === null) continue;
            $row['date'] = $date;
            $normalized[] = $row;
        }
        usort($normalized, function ($a, $b) { return strcmp($a['date'], $b['date']); });
        if (empty($normalized)) return [];
        $anchor = null;
        foreach ($normalized as $i => $row) {
            if ($row['date'] >= $eventDate) { $anchor = $i; break; }
        }
        if ($anchor === null) return array_values(array_slice($normalized, -$before));
        return array_values(array_slice($normalized, max(0, $anchor - $before), $before + $after + 1));
    }

    private function seriesSummary(array $rows, string $eventDate): array
    {
        $eventIndex = null;
        foreach ($rows as $i => $row) {
            if ((string)($row['date'] ?? '') >= $eventDate) { $eventIndex = $i; break; }
        }
        $eventRow = $eventIndex !== null ? ($rows[$eventIndex] ?? null) : null;
        $preClose = $eventIndex !== null && $eventIndex > 0
            ? $this->numeric($rows[$eventIndex - 1]['close'] ?? null)
            : ($eventIndex === null ? $this->numeric($rows[count($rows) - 1]['close'] ?? null) : null);
        $eventClose = is_array($eventRow) ? $this->numeric($eventRow['close'] ?? null) : null;
        $first = $this->numeric($rows[0]['close'] ?? null);
        $last = $this->numeric($rows[count($rows) - 1]['close'] ?? null);
        return [
            'event_index' => $eventIndex,
            'event_trading_date' => is_array($eventRow) ? ($eventRow['date'] ?? null) : null,
            'pre_close' => $this->round($preClose, 4),
            'event_close' => $this->round($eventClose, 4),
            'event_change_pct' => $preClose && $eventClose !== null ? $this->round(($eventClose / $preClose - 1) * 100, 4) : null,
            'window_change_pct' => $first && $last !== null ? $this->round(($last / $first - 1) * 100, 4) : null,
            'window_high' => $this->round($this->maxNumeric(array_column($rows, 'high')), 4),
            'window_low' => $this->round($this->minNumeric(array_column($rows, 'low')), 4),
        ];
    }

    private function eventMetrics(array $event, ?array $navWindow, ?array $marketWindow, ?array $benchmarkWindow): array
    {
        $cash = $this->numeric($event['cash_per_unit'] ?? null);
        $eventDate = (string)($event['ex_date'] ?? $event['record_date'] ?? '');
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $navSummary = (array)($navWindow['summary'] ?? []);
        $marketSummary = (array)($marketWindow['summary'] ?? []);
        $benchmarkSummary = (array)($benchmarkWindow['summary'] ?? []);

        $preNav = $this->numeric($navSummary['pre_event_nav'] ?? null);
        $eventNav = $this->numeric($navSummary['event_nav'] ?? null);
        $preClose = $this->numeric($marketSummary['pre_close'] ?? null);
        $eventClose = $this->numeric($marketSummary['event_close'] ?? null);
        $eventIndex = isset($marketWindow['event_index']) && is_numeric($marketWindow['event_index']) ? (int)$marketWindow['event_index'] : null;
        $marketRows = (array)($marketWindow['rows'] ?? []);
        $hasMarketPost = $eventIndex !== null && isset($marketRows[$eventIndex + 1]);
        $hasNavPost = empty($navSummary['post_event_data_pending']);
        $pending = $eventDate >= $today || (!$hasMarketPost && !$hasNavPost);

        $theoreticalNav = $preNav !== null && $cash !== null ? $preNav - $cash : null;
        $firstClose = $this->numeric($marketRows[0]['close'] ?? null);
        $lastClose = $this->numeric($marketRows[count($marketRows) - 1]['close'] ?? null);
        $preRunup = $preClose && $firstClose ? ($preClose / $firstClose - 1) * 100 : null;
        $priceCashReturn = $preClose && $eventClose !== null && $cash !== null ? (($eventClose + $cash) / $preClose - 1) * 100 : null;
        $navCashReturn = $preNav && $eventNav !== null && $cash !== null ? (($eventNav + $cash) / $preNav - 1) * 100 : null;
        $benchmarkEventReturn = $this->numeric($benchmarkSummary['event_change_pct'] ?? null);
        $cashRecovery = null;
        if ($preClose !== null && $cash !== null && $eventIndex !== null) {
            for ($i = $eventIndex; $i < count($marketRows); $i++) {
                $close = $this->numeric($marketRows[$i]['close'] ?? null);
                if ($close !== null && $close + $cash >= $preClose) { $cashRecovery = $i - $eventIndex; break; }
            }
        }

        return [
            'static_theoretical_ex_nav' => $this->round($theoreticalNav, 4),
            'actual_ex_date_nav' => $this->round($eventNav, 4),
            'pre_event_runup_pct' => $this->round($preRunup, 4),
            'ex_date_price_change_pct' => $this->round($this->numeric($marketSummary['event_change_pct'] ?? null), 4),
            'ex_date_cash_adjusted_return_pct' => $pending ? null : $this->round($priceCashReturn, 4),
            'nav_cash_adjusted_return_pct' => $pending ? null : $this->round($navCashReturn, 4),
            'window_cash_adjusted_return_pct' => $pending || !$preClose || $lastClose === null || $cash === null ? null : $this->round((($lastClose + $cash) / $preClose - 1) * 100, 4),
            'benchmark_event_return_pct' => $pending ? null : $this->round($benchmarkEventReturn, 4),
            'benchmark_excess_pct' => $pending || $priceCashReturn === null || $benchmarkEventReturn === null ? null : $this->round($priceCashReturn - $benchmarkEventReturn, 4),
            'price_recovery_trading_days' => $pending ? null : ($marketSummary['recovery_trading_days'] ?? null),
            'cash_adjusted_recovery_trading_days' => $pending ? null : $cashRecovery,
            'window_high' => $this->round($this->numeric($marketSummary['window_high'] ?? $navSummary['window_high'] ?? null), 4),
            'window_low' => $this->round($this->numeric($marketSummary['window_low'] ?? $navSummary['window_low'] ?? null), 4),
            'post_event_data_pending' => $pending,
        ];
    }

    private function cachedProviderResult(string $key, int $ttl, string $action, callable $fetcher): DataSourceResult
    {
        $cached = $this->cache->get($key);
        if (is_array($cached) && !empty($cached['success'])) {
            $result = DataSourceResult::success($cached['source'] ?? $this->provider->sourceName(), $cached['action'] ?? $action, $cached['data'] ?? [], $cached['meta'] ?? []);
            $result->meta['cache'] = 'hit';
            $result->meta['cache_backend'] = $this->cache->backendName();
            return $result;
        }
        $negative = $this->cache->get($key . ':neg');
        if (is_array($negative)) return DataSourceResult::error($negative['source'] ?? self::SOURCE_NAME, $action, $negative['code'] ?? 'negative_cache', $negative['message'] ?? '上游近期失败');

        $lock = 'stampede:' . $key;
        if (!$this->cache->acquireLock($lock, (int)$this->config['stampede_lock_ttl'])) {
            usleep((int)$this->config['stampede_wait_ms'] * 1000);
            $cached = $this->cache->get($key);
            if (is_array($cached) && !empty($cached['success'])) return DataSourceResult::success($cached['source'], $cached['action'], $cached['data'], array_merge($cached['meta'] ?? [], ['cache' => 'hit_after_wait']));
            $stale = $this->cache->getStale($key);
            if (is_array($stale) && !empty($stale['success'])) return DataSourceResult::success($stale['source'], $stale['action'], $stale['data'], array_merge($stale['meta'] ?? [], ['cache' => 'stale']));
            return DataSourceResult::error('cache', $action, 'cache_wait_timeout', '基金分红数据缓存正在刷新，请稍后重试');
        }
        try {
            $result = $fetcher();
            if ($result->hasData()) {
                $this->cache->set($key, ['success' => true, 'source' => $result->source, 'action' => $result->action, 'data' => $result->data, 'meta' => $result->meta], $ttl);
                $result->meta['cache'] = 'miss';
                $result->meta['cache_backend'] = $this->cache->backendName();
                return $result;
            }
            $this->cache->set($key . ':neg', ['source' => $result->source, 'code' => $result->errorCode, 'message' => $result->errorMessage], (int)$this->config['negative_cache_ttl']);
            $stale = $this->cache->getStale($key);
            if (is_array($stale) && !empty($stale['success'])) return DataSourceResult::success($stale['source'], $stale['action'], $stale['data'], array_merge($stale['meta'] ?? [], ['cache' => 'stale_fallback', 'stale_fallback_reason' => $result->errorMessage]));
            return $result;
        } finally {
            $this->cache->releaseLock($lock);
        }
    }

    private function round(?float $value, int $precision): ?float
    {
        return $value === null ? null : round($value, $precision);
    }

    private function numeric($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function maxNumeric(array $values): ?float
    {
        $values = array_values(array_filter(array_map([$this, 'numeric'], $values), function ($v) { return $v !== null; }));
        return empty($values) ? null : max($values);
    }

    private function minNumeric(array $values): ?float
    {
        $values = array_values(array_filter(array_map([$this, 'numeric'], $values), function ($v) { return $v !== null; }));
        return empty($values) ? null : min($values);
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format(DATE_ATOM);
    }
}
