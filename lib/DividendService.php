<?php
/**
 * DividendService — 分红事件筛选、行情合并和确定性计算。
 */

require_once __DIR__ . '/DividendDataProvider.php';
require_once __DIR__ . '/EastmoneyDividendClient.php';
require_once __DIR__ . '/MarketDataService.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/StockCode.php';

class DividendService
{
    const HOLD_WITHIN_1M = 'within_1m';
    const HOLD_1M_TO_1Y = '1m_to_1y';
    const HOLD_OVER_1Y = 'over_1y';

    /** @var DividendDataProvider */
    private $provider;
    /** @var MarketDataService */
    private $market;
    /** @var CacheStore */
    private $cache;
    /** @var array */
    private $config;

    public function __construct(?DividendDataProvider $provider = null, ?MarketDataService $market = null, ?CacheStore $cache = null)
    {
        $this->provider = $provider ?: new EastmoneyDividendClient();
        $this->market = $market ?: new MarketDataService();
        $this->cache = $cache ?: CacheStoreFactory::getInstance();
        $configured = AppConfig::get('dividend', []);
        $this->config = array_merge([
            'enabled' => true,
            'default_window_days' => 14,
            'max_window_days' => 60,
            'quote_batch_size' => 200,
            'calendar_ttl' => 900,
            'detail_ttl' => 1800,
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
            return DataSourceResult::error('dividend', 'dividend_calendar', 'feature_disabled', '分红日历功能未启用');
        }

        $defaults = $this->defaults();
        $start = (string)($options['start_date'] ?? $defaults['start_date']);
        $end = (string)($options['end_date'] ?? $defaults['end_date']);
        $market = (string)($options['market'] ?? 'all');
        $status = (string)($options['status'] ?? 'confirmed');
        $holding = (string)($options['holding_period'] ?? self::HOLD_WITHIN_1M);
        $minYield = max(0.0, min(100.0, (float)($options['min_yield'] ?? 0)));
        $sortBy = (string)($options['sort_by'] ?? 'gross_yield');
        $order = (string)($options['order'] ?? 'desc');
        $page = max(1, (int)($options['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($options['page_size'] ?? 50)));

        $validation = $this->validateCalendar($start, $end, $market, $status, $holding, $sortBy, $order);
        if ($validation !== null) {
            return DataSourceResult::error('dividend', 'dividend_calendar', 'invalid_argument', $validation);
        }

        $rawResult = $this->cachedProviderResult(
            'dividend:calendar:' . $this->provider->sourceName() . ":{$start}:{$end}",
            (int)$this->config['calendar_ttl'],
            'dividend_calendar_raw',
            function () use ($start, $end) { return $this->provider->calendar($start, $end); }
        );
        if (!$rawResult->hasData()) return $rawResult;

        $events = [];
        foreach ((array)$rawResult->data as $row) {
            if (!is_array($row) || empty($row['record_date'])) continue;
            $rowMarket = $this->marketOf((string)($row['code'] ?? ''));
            if ($rowMarket === null || ($market !== 'all' && $market !== $rowMarket)) continue;
            $confirmed = (($row['progress'] ?? '') === '实施分配');
            if ($status === 'confirmed' && !$confirmed) continue;
            $row['market'] = $rowMarket;
            $row['implementation_confirmed'] = $confirmed;
            $events[] = $row;
        }

        $quoteResult = $this->quotesFor(array_values(array_unique(array_column($events, 'code'))));
        $quoteMap = $quoteResult['quotes'];
        $failures = $quoteResult['failures'];
        $taxRate = $this->taxRate($holding);
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $items = [];
        $missingQuotes = 0;

        foreach ($events as $row) {
            $code = (string)$row['code'];
            $quote = $quoteMap[$code] ?? null;
            $price = is_array($quote) && is_numeric($quote['price'] ?? null) && (float)$quote['price'] > 0 ? (float)$quote['price'] : null;
            $prevClose = is_array($quote) && is_numeric($quote['prev_close'] ?? null) ? (float)$quote['prev_close'] : null;
            $cash10 = is_numeric($row['cash_per_10'] ?? null) ? (float)$row['cash_per_10'] : null;
            $cashShare = $cash10 !== null ? $cash10 / 10 : null;
            $grossYield = ($cashShare !== null && $price !== null) ? $cashShare / $price * 100 : null;
            $netCash = $cashShare !== null ? $cashShare * (1 - $taxRate) : null;
            $netYield = ($netCash !== null && $price !== null) ? $netCash / $price * 100 : null;
            if ($price === null) $missingQuotes++;
            if ($minYield > 0 && ($grossYield === null || $grossYield < $minYield)) continue;

            $record = new DateTimeImmutable($row['record_date'], new DateTimeZone('Asia/Shanghai'));
            $items[] = [
                'code' => $code,
                'name' => (string)($row['name'] ?? ''),
                'market' => $row['market'],
                'report_date' => $row['report_date'] ?? null,
                'plan_text' => (string)($row['plan_text'] ?? ''),
                'plan_status' => (string)($row['progress'] ?? ''),
                'implementation_confirmed' => (bool)$row['implementation_confirmed'],
                'record_date' => $row['record_date'],
                'last_buy_date' => $row['record_date'],
                'ex_date' => $row['ex_date'] ?? null,
                'pay_date' => null,
                'notice_date' => $row['notice_date'] ?? $row['plan_notice_date'] ?? null,
                'cash_per_10' => $this->round($cash10, 6),
                'cash_per_share' => $this->round($cashShare, 6),
                'price' => $this->round($price, 4),
                'prev_close' => $this->round($prevClose, 4),
                'price_status' => $price === null ? 'missing' : 'available',
                'gross_yield_pct' => $this->round($grossYield, 4),
                'holding_period' => $holding,
                'tax_rate_pct' => $this->round($taxRate * 100, 2),
                'net_cash_per_share' => $this->round($netCash, 6),
                'net_yield_pct' => $this->round($netYield, 4),
                'days_to_record' => (int)$today->diff($record)->format('%r%a'),
                'source' => $this->provider->sourceName(),
                'source_url' => 'https://data.eastmoney.com/yjfp/detail/' . rawurlencode($code) . '.html',
                'announcement_url' => null,
            ];
        }

        $this->sortItems($items, $sortBy, $order);
        $total = count($items);
        $summary = $this->summary($items);
        $offset = ($page - 1) * $pageSize;
        $paged = array_slice($items, $offset, $pageSize);
        $partial = $missingQuotes > 0 || !empty($failures);

        return DataSourceResult::success($this->provider->sourceName(), 'dividend_calendar', [
            'items' => $paged,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'pages' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
            ],
            'filters' => compact('start', 'end', 'market', 'status', 'holding', 'minYield', 'sortBy', 'order'),
        ], [
            'partial' => $partial,
            'failures' => $failures,
            'missing_quote_count' => $missingQuotes,
            'event_source' => $this->provider->sourceName(),
            'price_source' => 'eastmoney',
            'tax_model' => 'cn_a_share_individual_2015_101',
            'unavailable_fields' => ['pay_date', 'announcement_url'],
            'upstream_cache' => $rawResult->meta['cache'] ?? 'miss',
            'as_of' => $this->nowIso(),
        ]);
    }

    public function detail(string $code, int $years = 10, string $holding = self::HOLD_WITHIN_1M): DataSourceResult
    {
        $code = trim($code);
        $market = $this->marketOf($code);
        if ($market === null) return DataSourceResult::error('dividend', 'dividend_detail', 'invalid_code', '仅支持沪深北 A 股代码');
        if ($years < 1 || $years > 20) return DataSourceResult::error('dividend', 'dividend_detail', 'invalid_years', '历史年数必须在1至20之间');
        if (!in_array($holding, $this->holdingPeriods(), true)) return DataSourceResult::error('dividend', 'dividend_detail', 'invalid_holding_period', '持有期参数无效');

        $rawResult = $this->cachedProviderResult(
            'dividend:detail:' . $this->provider->sourceName() . ":{$code}",
            (int)$this->config['detail_ttl'],
            'dividend_detail_raw',
            function () use ($code) { return $this->provider->detail($code); }
        );
        if (!$rawResult->hasData()) return $rawResult;

        $cutoff = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->modify("-{$years} years")->format('Y-m-d');
        $taxRate = $this->taxRate($holding);
        $history = [];
        foreach ((array)$rawResult->data as $row) {
            if (!is_array($row) || ($row['code'] ?? '') !== $code) continue;
            $eventDate = $row['record_date'] ?? $row['report_date'] ?? null;
            if ($eventDate !== null && $eventDate < $cutoff) continue;
            $cash10 = is_numeric($row['cash_per_10'] ?? null) ? (float)$row['cash_per_10'] : null;
            $cashShare = $cash10 !== null ? $cash10 / 10 : null;
            $history[] = [
                'report_date' => $row['report_date'] ?? null,
                'record_date' => $row['record_date'] ?? null,
                'ex_date' => $row['ex_date'] ?? null,
                'pay_date' => null,
                'notice_date' => $row['notice_date'] ?? $row['plan_notice_date'] ?? null,
                'plan_status' => (string)($row['progress'] ?? ''),
                'implementation_confirmed' => (($row['progress'] ?? '') === '实施分配'),
                'plan_text' => (string)($row['plan_text'] ?? ''),
                'cash_per_10' => $this->round($cash10, 6),
                'cash_per_share' => $this->round($cashShare, 6),
                'net_cash_per_share' => $this->round($cashShare !== null ? $cashShare * (1 - $taxRate) : null, 6),
            ];
        }
        usort($history, function ($a, $b) {
            return strcmp((string)($b['record_date'] ?? $b['report_date'] ?? ''), (string)($a['record_date'] ?? $a['report_date'] ?? ''));
        });

        $quoteBundle = $this->quotesFor([$code]);
        $quote = $quoteBundle['quotes'][$code] ?? null;
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $upcoming = null;
        foreach ($history as $item) {
            if (!empty($item['record_date']) && $item['record_date'] >= $today) {
                $upcoming = $item;
            }
        }
        $cashItems = array_values(array_filter($history, function ($item) { return (float)($item['cash_per_share'] ?? 0) > 0; }));
        $yearsCovered = [];
        $fiveYearCash = 0.0;
        $fiveCutoff = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))->modify('-5 years')->format('Y-m-d');
        foreach ($cashItems as $item) {
            $eventDate = $item['record_date'] ?? $item['report_date'] ?? '';
            if ($eventDate !== '') $yearsCovered[substr($eventDate, 0, 4)] = true;
            if ($eventDate >= $fiveCutoff) $fiveYearCash += (float)$item['cash_per_share'];
        }

        return DataSourceResult::success($this->provider->sourceName(), 'dividend_detail', [
            'stock' => [
                'code' => $code,
                'name' => (string)($quote['name'] ?? ($rawResult->data[0]['name'] ?? '')),
                'market' => $market,
                'price' => $this->round(is_numeric($quote['price'] ?? null) ? (float)$quote['price'] : null, 4),
                'prev_close' => $this->round(is_numeric($quote['prev_close'] ?? null) ? (float)$quote['prev_close'] : null, 4),
            ],
            'upcoming_event' => $upcoming,
            'history' => $history,
            'summary' => [
                'cash_dividend_events' => count($cashItems),
                'years_with_cash_dividend' => count($yearsCovered),
                'five_year_total_cash_per_share' => $this->round($fiveYearCash, 6),
                'five_year_average_cash_per_event' => $this->round(count(array_filter($cashItems, function ($item) use ($fiveCutoff) {
                    $date = $item['record_date'] ?? $item['report_date'] ?? '';
                    return $date >= $fiveCutoff;
                })) > 0 ? $fiveYearCash / count(array_filter($cashItems, function ($item) use ($fiveCutoff) {
                    $date = $item['record_date'] ?? $item['report_date'] ?? '';
                    return $date >= $fiveCutoff;
                })) : 0, 6),
            ],
            'holding_period' => $holding,
            'tax_rate_pct' => $taxRate * 100,
            'source_url' => 'https://data.eastmoney.com/yjfp/detail/' . rawurlencode($code) . '.html',
        ], [
            'partial' => empty($quote),
            'failures' => $quoteBundle['failures'],
            'tax_model' => 'cn_a_share_individual_2015_101',
            'unavailable_fields' => ['pay_date', 'announcement_url'],
            'as_of' => $this->nowIso(),
        ]);
    }

    public function taxRate(string $holding): float
    {
        if ($holding === self::HOLD_1M_TO_1Y) return 0.10;
        if ($holding === self::HOLD_OVER_1Y) return 0.0;
        return 0.20;
    }

    private function validateCalendar(string $start, string $end, string $market, string $status, string $holding, string $sortBy, string $order): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return '日期必须为 YYYY-MM-DD';
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$from || !$to || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end) return '日期不是有效日历日期';
        if ($to < $from) return '结束日期不能早于开始日期';
        if ((int)$from->diff($to)->format('%a') + 1 > (int)$this->config['max_window_days']) return '查询日期跨度最多为' . (int)$this->config['max_window_days'] . '天';
        if (!in_array($market, ['all', 'sh', 'sz', 'bj'], true)) return '市场参数无效';
        if (!in_array($status, ['confirmed', 'all'], true)) return '方案状态参数无效';
        if (!in_array($holding, $this->holdingPeriods(), true)) return '持有期参数无效';
        if (!in_array($sortBy, ['gross_yield', 'net_yield', 'record_date', 'cash_per_share'], true)) return '排序字段无效';
        if (!in_array($order, ['asc', 'desc'], true)) return '排序方向无效';
        return null;
    }

    private function holdingPeriods(): array
    {
        return [self::HOLD_WITHIN_1M, self::HOLD_1M_TO_1Y, self::HOLD_OVER_1Y];
    }

    private function quotesFor(array $codes): array
    {
        $quotes = [];
        $failures = [];
        $batchSize = max(1, min(200, (int)$this->config['quote_batch_size']));
        foreach (array_chunk($codes, $batchSize) as $batch) {
            if (empty($batch)) continue;
            $result = $this->market->quote(implode(',', $batch), 'eastmoney', false, false);
            if (!$result->hasData()) {
                $failures[] = ['stage' => 'quote', 'codes' => $batch, 'code' => $result->errorCode, 'message' => $result->errorMessage];
                continue;
            }
            foreach ((array)$result->data as $item) {
                if (is_array($item) && !empty($item['code'])) $quotes[(string)$item['code']] = $item;
            }
        }
        return ['quotes' => $quotes, 'failures' => $failures];
    }

    private function marketOf(string $code): ?string
    {
        if (!preg_match('/^\d{6}$/', $code)) return null;
        if (preg_match('/^(?:92|4|8)/', $code)) return 'bj';
        if (preg_match('/^6/', $code) && !preg_match('/^900/', $code)) return 'sh';
        if (preg_match('/^[03]/', $code) && !preg_match('/^200/', $code)) return 'sz';
        return null;
    }

    private function sortItems(array &$items, string $sortBy, string $order): void
    {
        $keyMap = ['gross_yield' => 'gross_yield_pct', 'net_yield' => 'net_yield_pct', 'record_date' => 'record_date', 'cash_per_share' => 'cash_per_share'];
        $key = $keyMap[$sortBy] ?? 'gross_yield_pct';
        usort($items, function ($a, $b) use ($key, $order) {
            $av = $a[$key] ?? null; $bv = $b[$key] ?? null;
            if ($av === null && $bv !== null) return 1;
            if ($bv === null && $av !== null) return -1;
            $cmp = is_numeric($av) && is_numeric($bv) ? ((float)$av <=> (float)$bv) : strcmp((string)$av, (string)$bv);
            if ($cmp === 0) $cmp = strcmp((string)$a['record_date'], (string)$b['record_date']) ?: strcmp((string)$a['code'], (string)$b['code']);
            return $order === 'asc' ? $cmp : -$cmp;
        });
    }

    private function summary(array $items): array
    {
        $gross = array_values(array_filter(array_column($items, 'gross_yield_pct'), 'is_numeric'));
        $net = array_values(array_filter(array_column($items, 'net_yield_pct'), 'is_numeric'));
        sort($net, SORT_NUMERIC);
        $median = null;
        $n = count($net);
        if ($n > 0) $median = $n % 2 ? $net[intdiv($n, 2)] : ($net[$n / 2 - 1] + $net[$n / 2]) / 2;
        return [
            'event_count' => count($items),
            'confirmed_count' => count(array_filter($items, function ($item) { return !empty($item['implementation_confirmed']); })),
            'within_3_days_count' => count(array_filter($items, function ($item) { return ($item['days_to_record'] ?? 999) >= 0 && ($item['days_to_record'] ?? 999) <= 3; })),
            'max_gross_yield_pct' => $this->round(empty($gross) ? null : max($gross), 4),
            'median_net_yield_pct' => $this->round($median, 4),
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
        if (is_array($negative)) return DataSourceResult::error($negative['source'] ?? 'dividend', $action, $negative['code'] ?? 'negative_cache', $negative['message'] ?? '上游近期失败');

        $lock = 'stampede:' . $key;
        if (!$this->cache->acquireLock($lock, (int)$this->config['stampede_lock_ttl'])) {
            usleep((int)$this->config['stampede_wait_ms'] * 1000);
            $cached = $this->cache->get($key);
            if (is_array($cached) && !empty($cached['success'])) return DataSourceResult::success($cached['source'], $cached['action'], $cached['data'], array_merge($cached['meta'] ?? [], ['cache' => 'hit_after_wait']));
            $stale = $this->cache->getStale($key);
            if (is_array($stale) && !empty($stale['success'])) return DataSourceResult::success($stale['source'], $stale['action'], $stale['data'], array_merge($stale['meta'] ?? [], ['cache' => 'stale']));
            return DataSourceResult::error('cache', $action, 'cache_wait_timeout', '分红数据缓存正在刷新，请稍后重试');
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

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format(DATE_ATOM);
    }
}
