<?php

class StrategyIndicatorEngine
{
    public function normalizeKlineRows($data): array
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (!is_array($data)) return [];

        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            $open = $this->num($item['open'] ?? null);
            $close = $this->num($item['close'] ?? null);
            $high = $this->num($item['high'] ?? null);
            $low = $this->num($item['low'] ?? null);
            if ($open === null || $close === null || $high === null || $low === null) continue;

            $rows[] = [
                'time' => (string)($item['time'] ?? $item['date'] ?? ''),
                'open' => $open,
                'close' => $close,
                'high' => $high,
                'low' => $low,
                'volume' => $this->num($item['volume'] ?? 0) ?? 0.0,
                'amount' => $this->num($item['amount'] ?? 0) ?? 0.0,
                'turnover_rate' => $this->normalizeRate($item['turnover_rate'] ?? null),
                'change_pct' => $this->normalizeRate($item['change_pct'] ?? null),
            ];
        }

        usort($rows, function ($a, $b) {
            return strcmp((string)$a['time'], (string)$b['time']);
        });

        for ($i = 1; $i < count($rows); $i++) {
            if (($rows[$i]['change_pct'] ?? null) === null && $rows[$i - 1]['close'] > 0) {
                $rows[$i]['change_pct'] = $rows[$i]['close'] / $rows[$i - 1]['close'] - 1;
            }
        }
        if ($rows && ($rows[0]['change_pct'] ?? null) === null) {
            $rows[0]['change_pct'] = 0.0;
        }

        return $rows;
    }

    public function computeIndicators(array $hist, array $snapshot): array
    {
        $n = count($hist);
        if ($n < 2) return [];

        $closes = array_column($hist, 'close');
        $highs = array_column($hist, 'high');
        $lows = array_column($hist, 'low');
        $volumes = array_column($hist, 'volume');
        $latest = $hist[$n - 1];
        $prev = $hist[$n - 2] ?? $latest;

        $ma = function ($period, $offset = 0) use ($closes) {
            $end = count($closes) - $offset;
            if ($end <= 0) return null;
            $slice = array_slice($closes, max(0, $end - $period), min($period, $end));
            return count($slice) ? array_sum($slice) / count($slice) : null;
        };
        $momentum = function ($period) use ($closes) {
            $n = count($closes);
            if ($n <= $period || !$closes[$n - 1 - $period]) return null;
            return $closes[$n - 1] / $closes[$n - 1 - $period] - 1;
        };

        $ma5 = $ma(5); $ma10 = $ma(10); $ma20 = $ma(20); $ma60 = $ma(60);
        $prevMa5 = $ma(5, 1); $prevMa20 = $ma(20, 1);
        $avgPrevVol5 = $this->avg(array_slice($volumes, max(0, $n - 6), min(5, max(0, $n - 1))));
        $volRatio = $avgPrevVol5 > 0 ? $latest['volume'] / $avgPrevVol5 : ($snapshot['vol_ratio_5d'] ?? 0);
        $rsi14 = $this->rsi($closes, 14);
        $macd = $this->macd($closes);
        $boll = $this->boll($closes, 20);
        $prevBoll = $this->boll(array_slice($closes, 0, -1), 20);
        $atr14 = $this->atr($hist, 14);
        $limitPct = $this->limitPct($snapshot);
        $consecutive = $this->consecutiveLimitUps($hist, $snapshot);

        $recentHigh = max(array_slice($highs, max(0, $n - 60)));
        $recentLow = min(array_slice($lows, max(0, $n - 60)));
        $prevHigh20 = $this->highest($highs, 20, true);
        $prevHigh55 = $this->highest($highs, 55, true);
        $prevLow20 = $this->lowest($lows, 20, true);
        $prevLow55 = $this->lowest($lows, 55, true);
        $returns = $this->returns($closes, 20);
        $annualVol = count($returns) > 1 ? $this->std($returns) * sqrt(252) : null;
        $changePct = $latest['change_pct'];
        if ($changePct === null && ($prev['close'] ?? 0) > 0) {
            $changePct = $latest['close'] / $prev['close'] - 1;
        }
        $turnover = $latest['turnover_rate'] ?: ($snapshot['turnover_rate'] ?? 0);
        $amount = $latest['amount'] ?: ($snapshot['amount'] ?? 0);
        $atrPct = ($atr14 !== null && $latest['close'] > 0) ? $atr14 / $latest['close'] : null;
        $position55 = ($prevHigh55 !== null && $prevLow55 !== null && $prevHigh55 > $prevLow55)
            ? ($latest['close'] - $prevLow55) / ($prevHigh55 - $prevLow55)
            : null;

        $dual = $this->dualThrust($hist, 4, 0.5);
        $momentum5 = $momentum(5);
        $reversalScore = $momentum5 !== null ? max(0, min(100, abs(min($momentum5, 0)) * 500 + max(0, 45 - (float)$rsi14))) : null;
        $formulaAlpha = $this->formulaAlphaScore($hist);

        return [
            'open' => $latest['open'] ?: ($snapshot['open'] ?? 0),
            'high' => $latest['high'] ?: ($snapshot['high'] ?? 0),
            'low' => $latest['low'] ?: ($snapshot['low'] ?? 0),
            'close' => $latest['close'] ?: ($snapshot['close'] ?? 0),
            'price' => $latest['close'] ?: ($snapshot['price'] ?? $snapshot['close'] ?? 0),
            'amount' => $amount,
            'volume' => $latest['volume'] ?: ($snapshot['volume'] ?? 0),
            'turnover_rate' => $turnover,
            'turnover_rate_display' => round($turnover * 100, 2),
            'change_pct' => $changePct ?: 0,
            'change_pct_display' => round(($changePct ?: 0) * 100, 2),
            'ma5' => $ma5, 'ma10' => $ma10, 'ma20' => $ma20, 'ma60' => $ma60,
            'momentum_5d' => $momentum5,
            'momentum_10d' => $momentum(10),
            'momentum_20d' => $momentum(20),
            'momentum_60d' => $momentum(60),
            'annual_vol_20d' => $annualVol,
            'vol_ratio_5d' => round($volRatio, 3),
            'rsi_14' => $rsi14,
            'macd_dif' => $macd['dif'],
            'macd_dea' => $macd['dea'],
            'macd_hist' => $macd['hist'],
            'boll_upper' => $boll['upper'],
            'boll_lower' => $boll['lower'],
            'atr_14' => $atr14,
            'atr_pct' => $atrPct,
            'donchian_high_20_prev' => $prevHigh20,
            'donchian_high_55_prev' => $prevHigh55,
            'donchian_low_20_prev' => $prevLow20,
            'donchian_low_55_prev' => $prevLow55,
            'donchian_position_55' => $position55,
            'dual_thrust_upper' => $dual['upper'],
            'dual_thrust_range' => $dual['range'],
            'dual_thrust_strength' => $dual['upper'] !== null && $dual['upper'] > 0 ? max(0, ($latest['high'] / $dual['upper'] - 1) * 100) : 0,
            'reversal_score' => $reversalScore,
            'formula_alpha_score' => $formulaAlpha,
            'signal_n_day_high' => $latest['high'] >= $recentHigh,
            'signal_n_day_low' => $latest['low'] <= $recentLow,
            'signal_ma_golden_5_20' => $ma5 !== null && $ma20 !== null && $prevMa5 !== null && $prevMa20 !== null && $ma5 > $ma20 && $prevMa5 <= $prevMa20,
            'signal_macd_golden' => $macd['dif'] !== null && $macd['dea'] !== null && $macd['prev_dif'] !== null && $macd['prev_dea'] !== null && $macd['dif'] > $macd['dea'] && $macd['prev_dif'] <= $macd['prev_dea'],
            'signal_ma20_breakout' => $ma20 !== null && $prevMa20 !== null && $latest['close'] > $ma20 && $prev['close'] <= $prevMa20,
            'signal_boll_breakout_upper' => $boll['upper'] !== null && $prevBoll['upper'] !== null && $latest['close'] > $boll['upper'] && $prev['close'] <= $prevBoll['upper'],
            'signal_limit_up' => ($changePct ?: 0) >= $limitPct - 0.002,
            'consecutive_limit_ups' => $consecutive,
            'limit_pct' => $limitPct,
            'quote_time' => $latest['time'],
        ];
    }

    public function applyCrossSectionalFeatures(array $rows): array
    {
        $rows = $this->applyRanks($rows, [
            'momentum_20d' => true,
            'momentum_60d' => true,
            'annual_vol_20d' => false,
            'pe_ttm' => false,
            'pb' => false,
            'amount' => true,
            'total_mv' => false,
            'formula_alpha_score' => true,
        ]);

        foreach ($rows as &$row) {
            $momentum = $row['rank_momentum_60d'] ?? $row['rank_momentum_20d'] ?? 50;
            $lowVol = $row['rank_annual_vol_20d'] ?? 50;
            $valuation = $this->avg([
                $row['rank_pe_ttm'] ?? 50,
                $row['rank_pb'] ?? 50,
            ]);
            $liquidity = $row['rank_amount'] ?? 50;
            $size = $row['rank_total_mv'] ?? 50;
            $row['factor_score'] = round($momentum * 0.28 + $lowVol * 0.22 + $valuation * 0.22 + $liquidity * 0.18 + $size * 0.10, 2);
        }
        unset($row);

        return $rows;
    }

    private function applyRanks(array $rows, array $columns): array
    {
        foreach ($columns as $col => $higherBetter) {
            $values = [];
            foreach ($rows as $i => $row) {
                if (isset($row[$col]) && is_numeric($row[$col]) && is_finite((float)$row[$col])) {
                    $v = (float)$row[$col];
                    if (in_array($col, ['pe_ttm','pb'], true) && $v <= 0) continue;
                    $values[] = ['i' => $i, 'v' => $v];
                }
            }
            if (!$values) continue;
            usort($values, function ($a, $b) use ($higherBetter) {
                return $higherBetter ? ($a['v'] <=> $b['v']) : ($b['v'] <=> $a['v']);
            });
            $count = count($values);
            foreach ($values as $rank => $item) {
                $rows[$item['i']]['rank_' . $col] = $count > 1 ? round(($rank / ($count - 1)) * 100, 2) : 50.0;
            }
        }
        return $rows;
    }

    private function normalizeRate($value): ?float
    {
        $n = $this->num($value);
        if ($n === null) return null;
        return abs($n) > 1 ? $n / 100 : $n;
    }

    private function num($value): ?float
    {
        if ($value === null) return null;
        $text = trim((string)$value);
        if ($text === '' || $text === '-') return null;
        return is_numeric($text) ? (float)$text : null;
    }

    private function avg(array $values): float
    {
        $values = array_values(array_filter($values, 'is_numeric'));
        return $values ? array_sum($values) / count($values) : 0.0;
    }

    private function std(array $values): float
    {
        $values = array_values(array_filter($values, 'is_numeric'));
        if (!$values) return 0.0;
        $avg = $this->avg($values);
        $sum = 0.0;
        foreach ($values as $v) $sum += pow($v - $avg, 2);
        return sqrt($sum / max(count($values) - 1, 1));
    }

    private function returns(array $closes, int $limit = 0): array
    {
        $out = [];
        $start = max(1, $limit > 0 ? count($closes) - $limit : 1);
        for ($i = $start; $i < count($closes); $i++) {
            if ($closes[$i - 1] > 0) $out[] = $closes[$i] / $closes[$i - 1] - 1;
        }
        return $out;
    }

    private function rsi(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) return null;
        $slice = array_slice($closes, -($period + 1));
        $gains = 0.0; $losses = 0.0;
        for ($i = 1; $i < count($slice); $i++) {
            $diff = $slice[$i] - $slice[$i - 1];
            if ($diff >= 0) $gains += $diff; else $losses += abs($diff);
        }
        if ($losses == 0.0) return 100.0;
        $rs = ($gains / $period) / ($losses / $period);
        return round(100 - (100 / (1 + $rs)), 2);
    }

    private function macd(array $closes): array
    {
        if (count($closes) < 35) return ['dif'=>null,'dea'=>null,'hist'=>null,'prev_dif'=>null,'prev_dea'=>null];
        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);
        $dif = [];
        foreach ($closes as $i => $_) $dif[$i] = $ema12[$i] - $ema26[$i];
        $dea = $this->emaSeries($dif, 9);
        $n = count($closes) - 1;
        return [
            'dif' => round($dif[$n], 4),
            'dea' => round($dea[$n], 4),
            'hist' => round(($dif[$n] - $dea[$n]) * 2, 4),
            'prev_dif' => round($dif[$n - 1], 4),
            'prev_dea' => round($dea[$n - 1], 4),
        ];
    }

    private function emaSeries(array $values, int $period): array
    {
        $k = 2 / ($period + 1);
        $ema = [];
        foreach ($values as $i => $v) {
            $ema[$i] = $i === 0 ? (float)$v : ((float)$v * $k + $ema[$i - 1] * (1 - $k));
        }
        return $ema;
    }

    private function boll(array $closes, int $period): array
    {
        if (count($closes) < $period) return ['upper'=>null,'lower'=>null];
        $slice = array_slice($closes, -$period);
        $mid = $this->avg($slice);
        $std = $this->std($slice);
        return ['upper' => round($mid + 2 * $std, 4), 'lower' => round($mid - 2 * $std, 4)];
    }

    private function atr(array $hist, int $period): ?float
    {
        if (count($hist) <= $period) return null;
        $trs = [];
        for ($i = 1; $i < count($hist); $i++) {
            $prevClose = $hist[$i - 1]['close'];
            $trs[] = max(
                $hist[$i]['high'] - $hist[$i]['low'],
                abs($hist[$i]['high'] - $prevClose),
                abs($hist[$i]['low'] - $prevClose)
            );
        }
        $slice = array_slice($trs, -$period);
        return $slice ? round($this->avg($slice), 4) : null;
    }

    private function highest(array $values, int $period, bool $excludeLatest): ?float
    {
        if ($excludeLatest) $values = array_slice($values, 0, -1);
        if (count($values) < max(2, min($period, 2))) return null;
        $slice = array_slice($values, -$period);
        return $slice ? max($slice) : null;
    }

    private function lowest(array $values, int $period, bool $excludeLatest): ?float
    {
        if ($excludeLatest) $values = array_slice($values, 0, -1);
        if (count($values) < max(2, min($period, 2))) return null;
        $slice = array_slice($values, -$period);
        return $slice ? min($slice) : null;
    }

    private function dualThrust(array $hist, int $period, float $kUp): array
    {
        if (count($hist) <= $period) return ['upper' => null, 'range' => null];
        $latest = $hist[count($hist) - 1];
        $slice = array_slice($hist, -($period + 1), $period);
        $hh = max(array_column($slice, 'high'));
        $ll = min(array_column($slice, 'low'));
        $hc = max(array_column($slice, 'close'));
        $lc = min(array_column($slice, 'close'));
        $range = max($hh - $lc, $hc - $ll);
        return [
            'upper' => round($latest['open'] + $kUp * $range, 4),
            'range' => round($range, 4),
        ];
    }

    private function formulaAlphaScore(array $hist): ?float
    {
        if (count($hist) < 20) return null;
        $closes = array_column($hist, 'close');
        $volumes = array_column($hist, 'volume');
        $latest = $hist[count($hist) - 1];
        $high20 = max(array_slice(array_column($hist, 'high'), -20));
        $low20 = min(array_slice(array_column($hist, 'low'), -20));
        $rangePos = $high20 > $low20 ? ($latest['close'] - $low20) / ($high20 - $low20) : 0.5;
        $ret5 = count($closes) > 5 && $closes[count($closes) - 6] > 0 ? $latest['close'] / $closes[count($closes) - 6] - 1 : 0;
        $volRatio = $this->avg(array_slice($volumes, -5)) / max($this->avg(array_slice($volumes, -20)), 1);
        $score = $rangePos * 40 + max(0, min(30, $ret5 * 300)) + max(0, min(30, ($volRatio - 0.8) * 35));
        return round(max(0, min(100, $score)), 2);
    }

    private function limitPct(array $row): float
    {
        $code = $row['code'] ?? '';
        if (preg_match('/ST/i', $row['name'] ?? '')) return 0.05;
        if (preg_match('/^(300|301|688)/', $code)) return 0.20;
        if (preg_match('/^(8|4)/', $code)) return 0.30;
        return 0.10;
    }

    private function consecutiveLimitUps(array $hist, array $snapshot): int
    {
        $count = 0;
        for ($i = count($hist) - 1; $i >= 0; $i--) {
            $row = $hist[$i];
            $limit = $this->limitPct($snapshot);
            if (($row['change_pct'] ?? 0) >= $limit - 0.002) $count++;
            else break;
        }
        return $count;
    }
}
