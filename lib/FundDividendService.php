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
    /** @var array */
    private $config;

    public function __construct(?FundDividendDataProvider $provider = null, ?FundService $fund = null, ?CacheStore $cache = null)
    {
        $this->provider = $provider ?: new EastmoneyFundDividendClient();
        $this->fund = $fund ?: new FundService();
        $this->cache = $cache ?: CacheStoreFactory::getInstance();
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

        $key = 'fund_dividend:detail:' . $code . ':' . ($eventDate ?? 'latest');
        $cached = $this->cachedProviderResult(
            $key,
            (int)$this->config['detail_ttl'],
            'fund_dividend_detail',
            function () use ($code, $eventDate) { return $this->fetchDetail($code, $eventDate); }
        );
        return $cached;
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
        $announcements = [];
        if ($selected !== null) {
            $docs = $this->fund->fundDocuments($code, 1, 3, 'dividend', true, 4000);
            if ($docs->hasData()) {
                $announcements = array_slice((array)$docs->data, 0, 5);
                $announcementStatus = $this->matchAnnouncement($selected, $announcements);
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
        $cash = $event['cash_per_unit'] ?? null;
        $recordDate = (string)($event['record_date'] ?? '');
        $exDate = (string)($event['ex_date'] ?? '');
        if (empty($announcements)) {
            return 'checked_unmatched';
        }
        foreach ($announcements as $ann) {
            $content = (string)($ann['content'] ?? $ann['title'] ?? '');
            if ($content === '') continue;
            $amountHit = $cash !== null && $this->contentContainsAmount($content, (float)$cash);
            $dateHit = $this->contentContainsDate($content, $recordDate) || $this->contentContainsDate($content, $exDate);
            if ($amountHit && $dateHit) {
                return 'verified';
            }
        }
        return 'checked_unmatched';
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
        return false;
    }

    private function contentContainsDate(string $content, string $date): bool
    {
        if ($date === '') return false;
        if (strpos($content, $date) !== false) return true;
        // 中文日期格式 2026年7月17日
        $cn = preg_replace('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', '$1年$2月$3日', $date);
        $cn = preg_replace('/^(\d{4})年0?(\d{1,2})月0?(\d{1,2})日$/', '$1年$2月$3日', $cn ?? '');
        if ($cn !== '' && $cn !== $date && strpos($content, $cn) !== false) return true;
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
