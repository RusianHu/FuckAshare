<?php
/**
 * EventMarketWindowService — 交易所证券事件日前后日 K 的共享读取与确定性摘要。
 */

require_once __DIR__ . '/MarketDataService.php';
require_once __DIR__ . '/DataSourceResult.php';

class EventMarketWindowService
{
    /** @var MarketDataService */
    private $market;

    public function __construct(?MarketDataService $market = null)
    {
        $this->market = $market ?: new MarketDataService();
    }

    public function window(string $providerCode, string $displayCode, string $eventDate, int $before = 10, int $after = 15): DataSourceResult
    {
        $event = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate, new DateTimeZone('Asia/Shanghai'));
        if (!$event || $event->format('Y-m-d') !== $eventDate) {
            return DataSourceResult::error('event_market', 'dividend_event_market', 'invalid_date', '事件日期格式无效');
        }
        $before = max(5, min(30, $before));
        $after = max(5, min(30, $after));
        $endDate = $event->modify('+' . max(30, $after * 3) . ' days')->format('Y-m-d');
        $count = min(180, $before + $after + 70);
        $result = $this->market->kline($providerCode, '1d', $count, $endDate, MarketDataService::SOURCE_ASHARE, false, false);
        if (!$result->hasData()) {
            return DataSourceResult::error($result->source ?: 'ashare', 'dividend_event_market', $result->errorCode ?: 'kline_unavailable', $result->errorMessage ?: '该次事件附近的日 K 暂不可用', $result->meta);
        }

        $rows = [];
        foreach ((array)$result->data as $row) {
            if (!is_array($row)) continue;
            $date = substr((string)($row['time'] ?? $row['date'] ?? ''), 0, 10);
            if ($date === '' || $date > $endDate) continue;
            $open = $this->number($row['open'] ?? null);
            $close = $this->number($row['close'] ?? null);
            $high = $this->number($row['high'] ?? null);
            $low = $this->number($row['low'] ?? null);
            if ($open === null || $close === null || $high === null || $low === null) continue;
            $rows[] = [
                'date' => $date,
                'open' => $this->round($open, 4),
                'close' => $this->round($close, 4),
                'high' => $this->round($high, 4),
                'low' => $this->round($low, 4),
                'volume' => $this->round($this->number($row['volume'] ?? null), 2),
                'amount' => $this->round($this->number($row['amount'] ?? null), 2),
                'turnover_rate' => $this->round($this->number($row['turnover_rate'] ?? null), 4),
            ];
        }
        usort($rows, function ($a, $b) { return strcmp($a['date'], $b['date']); });
        if (empty($rows)) {
            return DataSourceResult::error($result->source, 'dividend_event_market', 'kline_empty', '该次事件附近没有可展示的日 K');
        }

        $anchor = null;
        foreach ($rows as $index => $row) {
            if ($row['date'] >= $eventDate) { $anchor = $index; break; }
        }
        if ($anchor === null && $rows[count($rows) - 1]['date'] < $eventDate) {
            $window = array_slice($rows, -$before);
            $eventIndex = null;
        } else {
            if ($anchor === null) {
                return DataSourceResult::error($result->source, 'dividend_event_market', 'event_outside_kline', '行情数据未覆盖该次事件日期');
            }
            $start = max(0, $anchor - $before);
            $window = array_slice($rows, $start, $before + $after + 1);
            $eventIndex = $anchor - $start;
        }
        $window = array_values($window);
        foreach ($window as $index => &$row) {
            $previous = $index > 0 ? (float)$window[$index - 1]['close'] : null;
            $row['change_pct'] = $previous && $previous != 0.0 ? $this->round(((float)$row['close'] / $previous - 1) * 100, 4) : null;
            $row['is_event_day'] = $eventIndex !== null && $index === $eventIndex;
        }
        unset($row);

        $eventRow = $eventIndex !== null ? ($window[$eventIndex] ?? null) : null;
        $preClose = $eventIndex !== null && $eventIndex > 0
            ? (float)$window[$eventIndex - 1]['close']
            : ($eventIndex === null && !empty($window) ? (float)$window[count($window) - 1]['close'] : null);
        $eventChange = $eventRow && $preClose ? ((float)$eventRow['close'] / $preClose - 1) * 100 : null;
        $firstClose = (float)($window[0]['close'] ?? 0);
        $lastClose = (float)($window[count($window) - 1]['close'] ?? 0);
        $periodChange = $firstClose > 0 ? ($lastClose / $firstClose - 1) * 100 : null;
        $preVolumes = [];
        $postVolumes = [];
        foreach ($window as $index => $row) {
            if (!is_numeric($row['volume'] ?? null)) continue;
            if ($eventIndex === null || $index < $eventIndex) $preVolumes[] = (float)$row['volume'];
            elseif ($index > $eventIndex) $postVolumes[] = (float)$row['volume'];
        }
        $preAvg = $this->average($preVolumes);
        $postAvg = $this->average($postVolumes);
        $recoveryDays = null;
        if ($preClose !== null && $eventIndex !== null) {
            for ($i = $eventIndex; $i < count($window); $i++) {
                if ((float)$window[$i]['close'] >= $preClose) { $recoveryDays = $i - $eventIndex; break; }
            }
        }

        return DataSourceResult::success($result->source, 'dividend_event_market', [
            'code' => $displayCode,
            'event_date' => $eventDate,
            'event_trading_date' => $eventRow['date'] ?? null,
            'event_index' => $eventIndex,
            'rows' => $window,
            'summary' => [
                'event_change_pct' => $this->round($eventChange, 4),
                'window_change_pct' => $this->round($periodChange, 4),
                'window_high' => $this->round(max(array_column($window, 'high')), 4),
                'window_low' => $this->round(min(array_column($window, 'low')), 4),
                'pre_close' => $this->round($preClose, 4),
                'event_close' => $eventRow['close'] ?? null,
                'pre_avg_volume' => $this->round($preAvg, 2),
                'post_avg_volume' => $this->round($postAvg, 2),
                'post_pre_volume_ratio' => $preAvg && $postAvg !== null ? $this->round($postAvg / $preAvg, 4) : null,
                'recovery_trading_days' => $recoveryDays,
                'recovered_in_window' => $recoveryDays !== null,
            ],
        ], [
            'as_of' => date('c'),
            'requested_before' => $before,
            'requested_after' => $after,
            'price_adjustment' => 'provider_default',
        ]);
    }

    private function number($value): ?float { return is_numeric($value) ? (float)$value : null; }
    private function round(?float $value, int $precision): ?float { return $value === null ? null : round($value, $precision); }
    private function average(array $values): ?float { return empty($values) ? null : array_sum($values) / count($values); }
}
