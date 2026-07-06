<?php

class StrategyRuleEngine
{
    /** @var array<string,string> */
    private $handlers;

    public function __construct()
    {
        $this->handlers = [
            'trend_breakout' => 'ruleTrendBreakout',
            'ma_golden_cross' => 'ruleMaGoldenCross',
            'macd_golden' => 'ruleMacdGolden',
            'volume_price_surge' => 'ruleVolumePriceSurge',
            'low_volatility_leader' => 'ruleLowVolatilityLeader',
            'broken_board_recovery' => 'ruleBrokenBoardRecovery',
            'oversold_bounce' => 'ruleOversoldBounce',
            'boll_breakout' => 'ruleBollBreakout',
            'bullish_alignment' => 'ruleBullishAlignment',
            'consecutive_limit_ups' => 'ruleConsecutiveLimitUps',
            'pullback_to_support' => 'rulePullbackToSupport',
            'n_day_low_reversal' => 'ruleNDayLowReversal',
            'high_turnover_surge' => 'ruleHighTurnoverSurge',
            'limit_up_momentum' => 'ruleLimitUpMomentum',
            'near_limit_up' => 'ruleNearLimitUp',
            'strong_open' => 'ruleStrongOpen',
            'donchian_turtle_breakout' => 'ruleDonchianTurtleBreakout',
            'dual_thrust_range_breakout' => 'ruleDualThrustRangeBreakout',
            'short_term_reversal' => 'ruleShortTermReversal',
            'multi_factor_score' => 'ruleMultiFactorScore',
            'formula_ohlcv_alpha_subset' => 'ruleFormulaOhlcvAlphaSubset',
            'sector_rotation_momentum' => 'ruleSectorRotationMomentum',
            'strategy_validation_healthcheck' => 'ruleStrategyValidationHealthcheck',
        ];
    }

    public function run(array $strategy, array $rows, array $paramOverrides = []): array
    {
        $params = $this->mergeParams($strategy, $paramOverrides);
        $matched = [];
        foreach ($rows as $row) {
            if (!$this->passesBasicFilter($row, $strategy['basic_filter'] ?? [])) continue;
            if (!empty($strategy['needs_history']) && ($row['asset_type'] ?? 'stock') === 'stock' && empty($row['history_ready'])) continue;
            if (!$this->passesRule($strategy['id'], $row, $params)) continue;
            $row['strategy_params'] = $params;
            $row['strategy_source_ref'] = $strategy['source_ref'] ?? '';
            $row['strategy_risk_note'] = $strategy['risk_note'] ?? '';
            $row['watch_only'] = !empty($strategy['watch_only']);
            $matched[] = $row;
        }

        $matched = $this->applyScores($matched, $strategy['scoring'] ?? [], !empty($strategy['descending']));
        return array_slice($matched, 0, (int)($strategy['limit'] ?? 100));
    }

    public function mergeParams(array $strategy, array $overrides): array
    {
        $params = $strategy['defaults'] ?? [];
        $defs = [];
        foreach (($strategy['params'] ?? []) as $def) $defs[$def['id']] = $def;

        foreach ($overrides as $key => $value) {
            if (!isset($defs[$key])) continue;
            $def = $defs[$key];
            if (!is_numeric($value)) continue;
            $value = ($def['type'] ?? 'float') === 'int' ? (int)$value : (float)$value;
            if (isset($def['min'])) $value = max($value, $def['min']);
            if (isset($def['max'])) $value = min($value, $def['max']);
            $params[$key] = $value;
        }
        return $params;
    }

    private function passesRule(string $id, array $r, array $p): bool
    {
        $method = $this->handlers[$id] ?? null;
        return $method && method_exists($this, $method) ? (bool)$this->{$method}($r, $p) : false;
    }

    private function passesBasicFilter(array $row, array $bf): bool
    {
        if (($row['asset_type'] ?? 'stock') !== 'stock') return true;
        if (($bf['price_min'] ?? null) !== null && ($row['close'] ?? 0) < $bf['price_min']) return false;
        if (($bf['price_max'] ?? null) !== null && ($row['close'] ?? 0) > $bf['price_max']) return false;
        if (($bf['amount_min'] ?? null) !== null && ($row['amount'] ?? 0) < $bf['amount_min']) return false;
        if (($bf['amount_max'] ?? null) !== null && ($row['amount'] ?? 0) > $bf['amount_max']) return false;
        if (($bf['market_cap_min'] ?? null) !== null && ($row['total_mv'] ?? 0) < $bf['market_cap_min']) return false;
        if (($bf['market_cap_max'] ?? null) !== null && ($row['total_mv'] ?? 0) > $bf['market_cap_max']) return false;
        if (($bf['float_cap_min'] ?? null) !== null && ($row['circ_mv'] ?? 0) < $bf['float_cap_min']) return false;
        if (($bf['float_cap_max'] ?? null) !== null && ($row['circ_mv'] ?? 0) > $bf['float_cap_max']) return false;
        if (($bf['turnover_min'] ?? null) !== null && ($row['turnover_rate'] ?? 0) < $bf['turnover_min']) return false;
        if (($bf['turnover_max'] ?? null) !== null && ($row['turnover_rate'] ?? 0) > $bf['turnover_max']) return false;
        if (($bf['exclude_st'] ?? true) && preg_match('/ST|退/i', $row['name'] ?? '')) return false;
        return true;
    }

    private function applyScores(array $rows, array $weights, bool $descending): array
    {
        if (!$rows) return $rows;
        if (!$weights) {
            foreach ($rows as &$row) $row['score'] = isset($row['score']) ? round((float)$row['score'], 2) : 50.0;
            unset($row);
            return $rows;
        }

        $mins = []; $maxs = [];
        foreach ($weights as $col => $weight) {
            $values = array_values(array_filter(array_map(function ($r) use ($col) {
                return isset($r[$col]) && is_numeric($r[$col]) ? (float)$r[$col] : null;
            }, $rows), function ($v) { return $v !== null && is_finite($v); }));
            if ($values) {
                $mins[$col] = min($values);
                $maxs[$col] = max($values);
            }
        }

        $totalWeight = array_sum($weights) ?: 1;
        foreach ($rows as &$row) {
            $score = 0.0;
            foreach ($weights as $col => $weight) {
                if (!isset($row[$col]) || !isset($mins[$col], $maxs[$col]) || !is_numeric($row[$col])) continue;
                $range = $maxs[$col] - $mins[$col];
                $norm = $range > 0 ? (((float)$row[$col] - $mins[$col]) / $range) : 0.5;
                $score += $norm * ($weight / $totalWeight);
            }
            $row['score'] = round($score * 100, 2);
        }
        unset($row);

        usort($rows, function ($a, $b) use ($descending) {
            return $descending ? (($b['score'] ?? 0) <=> ($a['score'] ?? 0)) : (($a['score'] ?? 0) <=> ($b['score'] ?? 0));
        });
        return $rows;
    }

    private function ruleTrendBreakout(array $r, array $p): bool
    {
        return ($r['close'] ?? 0) > ($r['ma60'] ?? INF) && !empty($r['signal_n_day_high']) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleMaGoldenCross(array $r, array $p): bool
    {
        return !empty($r['signal_ma_golden_5_20']) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'] && ($r['close'] ?? 0) > ($r['ma60'] ?? INF);
    }

    private function ruleMacdGolden(array $r, array $p): bool
    {
        return !empty($r['signal_macd_golden']) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleVolumePriceSurge(array $r, array $p): bool
    {
        return !empty($r['signal_ma20_breakout']) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'] && ($r['close'] ?? 0) > ($r['open'] ?? INF);
    }

    private function ruleLowVolatilityLeader(array $r, array $p): bool
    {
        return ($r['momentum_20d'] ?? -INF) > 0 && ($r['annual_vol_20d'] ?? INF) < $p['vol_max'] && ($r['close'] ?? 0) > ($r['ma20'] ?? INF);
    }

    private function ruleBrokenBoardRecovery(array $r, array $p): bool
    {
        return !empty($r['signal_limit_up']) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'] && ($r['change_pct'] ?? 0) > $p['change_pct_min'];
    }

    private function ruleOversoldBounce(array $r, array $p): bool
    {
        return ($r['rsi_14'] ?? INF) < $p['rsi_max'] && ($r['close'] ?? 0) > ($r['open'] ?? INF) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleBollBreakout(array $r, array $p): bool
    {
        return !empty($r['signal_boll_breakout_upper']) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleBullishAlignment(array $r, array $p): bool
    {
        return ($r['ma5'] ?? -INF) > ($r['ma10'] ?? INF) && ($r['ma10'] ?? -INF) > ($r['ma20'] ?? INF) && ($r['ma20'] ?? -INF) > ($r['ma60'] ?? INF) && ($r['momentum_20d'] ?? -INF) > 0;
    }

    private function ruleConsecutiveLimitUps(array $r, array $p): bool
    {
        return !empty($r['signal_limit_up']) && ($r['consecutive_limit_ups'] ?? 0) >= $p['min_boards'];
    }

    private function rulePullbackToSupport(array $r, array $p): bool
    {
        $prox = $p['ma_proximity'];
        return ($r['close'] ?? 0) > ($r['ma20'] ?? INF) * (1 - $prox)
            && ($r['close'] ?? 0) < ($r['ma20'] ?? 0) * (1 + $prox)
            && ($r['vol_ratio_5d'] ?? INF) < $p['vol_ratio_max']
            && ($r['close'] ?? 0) > ($r['ma60'] ?? INF)
            && ($r['momentum_20d'] ?? -INF) > 0;
    }

    private function ruleNDayLowReversal(array $r, array $p): bool
    {
        return !empty($r['signal_n_day_low']) && ($r['close'] ?? 0) > ($r['open'] ?? INF) && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleHighTurnoverSurge(array $r, array $p): bool
    {
        return ($r['turnover_rate'] ?? 0) > ($p['min_turnover'] / 100) && ($r['change_pct'] ?? 0) > ($p['min_change'] / 100);
    }

    private function ruleLimitUpMomentum(array $r, array $p): bool
    {
        return ($r['change_pct'] ?? 0) > ($p['min_change'] / 100) && ($r['consecutive_limit_ups'] ?? 0) >= $p['min_boards'];
    }

    private function ruleNearLimitUp(array $r, array $p): bool
    {
        $limit = $r['limit_pct'] ?? 0.10;
        $gap = $p['limit_gap'] / 100;
        return ($r['change_pct'] ?? 0) > ($p['min_change'] / 100)
            && ($r['change_pct'] ?? 0) >= $limit - $gap
            && ($r['change_pct'] ?? 0) < $limit + 0.003;
    }

    private function ruleStrongOpen(array $r, array $p): bool
    {
        return ($r['open'] ?? 0) > ($r['prev_close'] ?? INF) * (1 + $p['min_open_gap'] / 100)
            && ($r['close'] ?? 0) > ($r['open'] ?? INF)
            && ($r['change_pct'] ?? 0) > ($p['min_change'] / 100);
    }

    private function ruleDonchianTurtleBreakout(array $r, array $p): bool
    {
        $period = (int)($p['donchian_period'] ?? 55);
        $high = $period <= 20 ? ($r['donchian_high_20_prev'] ?? null) : ($r['donchian_high_55_prev'] ?? null);
        return $high !== null
            && ($r['close'] ?? 0) > $high
            && ($r['close'] ?? 0) > ($r['ma60'] ?? INF)
            && (($r['atr_pct'] ?? 0) * 100) >= $p['min_atr_pct']
            && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleDualThrustRangeBreakout(array $r, array $p): bool
    {
        $range = $r['dual_thrust_range'] ?? null;
        if ($range === null) return false;
        $upper = ($r['open'] ?? 0) + (float)$p['k_up'] * $range;
        return ($r['high'] ?? 0) >= $upper
            && ($r['close'] ?? 0) > ($r['open'] ?? INF)
            && ($r['change_pct'] ?? 0) >= ($p['min_change'] / 100);
    }

    private function ruleShortTermReversal(array $r, array $p): bool
    {
        return ($r['momentum_5d'] ?? INF) <= $p['max_return_5d']
            && ($r['rsi_14'] ?? INF) <= $p['rsi_max']
            && ($r['close'] ?? 0) > ($r['open'] ?? INF)
            && ($r['vol_ratio_5d'] ?? 0) >= $p['vol_ratio_min'];
    }

    private function ruleMultiFactorScore(array $r, array $p): bool
    {
        $pe = $r['pe_ttm'] ?? null;
        $pb = $r['pb'] ?? null;
        if ($pe !== null && $pe > 0 && $pe > $p['max_pe_ttm']) return false;
        if ($pb !== null && $pb > 0 && $pb > $p['max_pb']) return false;
        return ($r['factor_score'] ?? 0) >= $p['min_factor_score'];
    }

    private function ruleFormulaOhlcvAlphaSubset(array $r, array $p): bool
    {
        return ($r['formula_alpha_score'] ?? 0) >= $p['min_alpha_score'] && ($r['amount'] ?? 0) >= $p['min_amount_yuan'];
    }

    private function ruleSectorRotationMomentum(array $r, array $p): bool
    {
        return ($r['asset_type'] ?? '') === 'sector'
            && ($r['net_inflow_today'] ?? 0) >= $p['min_net_inflow_yuan']
            && ($r['change_pct_display'] ?? 0) >= $p['min_change_pct'];
    }

    private function ruleStrategyValidationHealthcheck(array $r, array $p): bool
    {
        return ($r['asset_type'] ?? '') === 'diagnostic';
    }
}
