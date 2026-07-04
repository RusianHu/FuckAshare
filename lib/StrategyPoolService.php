<?php

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/StockCode.php';

class StrategyPoolService
{
    const SOURCE_NAME = 'eastmoney';
    const PUSH2_URL = 'https://push2.eastmoney.com';
    const PUSH2_DELAY_URL = 'https://push2delay.eastmoney.com';
    const PUSH2HIS_URL = 'https://push2his.eastmoney.com';

    private $http;
    private $strategies;

    public function __construct()
    {
        $this->http = new HttpClient([
            'timeout' => 12,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://quote.eastmoney.com/',
                'Accept: application/json,text/plain,*/*',
            ],
        ]);
        $this->strategies = $this->buildStrategies();
    }

    public function listStrategies(): array
    {
        return array_values(array_map(function ($s) {
            return [
                'id' => $s['id'],
                'name' => $s['name'],
                'description' => $s['description'],
                'tags' => $s['tags'],
                'source' => 'builtin',
                'params' => $s['params'],
                'scoring' => $s['scoring'],
                'limit' => $s['limit'],
                'stop_loss' => $s['stop_loss'],
                'max_hold_days' => $s['max_hold_days'],
            ];
        }, $this->strategies));
    }

    public function runAll(array $strategyIds = [], int $pages = 2, int $candidateLimit = 80): array
    {
        $started = microtime(true);
        $ids = $this->normalizeStrategyIds($strategyIds);
        $candidates = $this->loadCandidateSnapshot($pages, $candidateLimit);
        $rows = $this->hydrateIndicators($candidates);

        $results = [];
        foreach ($ids as $id) {
            $results[$id] = $this->runOnRows($id, $rows, $started);
        }

        return [
            'success' => true,
            'as_of' => date('Y-m-d'),
            'results' => $results,
            'meta' => [
                'source' => self::SOURCE_NAME,
                'candidate_count' => count($candidates),
                'hydrated_count' => count($rows),
                'pages' => $pages,
                'candidate_limit' => $candidateLimit,
                'elapsed_ms' => round((microtime(true) - $started) * 1000, 1),
                'coverage_note' => '候选池来自东方财富涨幅榜、成交额榜、换手率榜、量比榜合并后精算；不是本地全市场历史库。',
            ],
        ];
    }

    public function run(string $strategyId, int $pages = 2, int $candidateLimit = 80): array
    {
        if (!isset($this->strategies[$strategyId])) {
            return ['success' => false, 'message' => "未知策略: {$strategyId}"];
        }
        $data = $this->runAll([$strategyId], $pages, $candidateLimit);
        return [
            'success' => true,
            'as_of' => $data['as_of'],
            'result' => $data['results'][$strategyId],
            'meta' => $data['meta'],
        ];
    }

    private function normalizeStrategyIds(array $ids): array
    {
        $known = array_keys($this->strategies);
        if (!$ids) return $known;
        $set = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if (isset($this->strategies[$id])) $set[$id] = true;
        }
        return $set ? array_keys($set) : $known;
    }

    private function runOnRows(string $strategyId, array $rows, float $started): array
    {
        $strategy = $this->strategies[$strategyId];
        $matched = [];
        foreach ($rows as $row) {
            if (!$this->passesBasicFilter($row, $strategy['basic_filter']) || !$this->passesStrategy($strategyId, $row, $strategy['defaults'])) {
                continue;
            }
            $matched[] = $row;
        }
        $matched = $this->applyScores($matched, $strategy['scoring'], $strategy['descending']);
        $matched = array_slice($matched, 0, (int)$strategy['limit']);

        return [
            'strategy' => $strategyId,
            'as_of' => date('Y-m-d'),
            'total' => count($matched),
            'rows' => $matched,
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    private function loadCandidateSnapshot(int $pages, int $candidateLimit): array
    {
        $pages = max(1, min($pages, 5));
        $candidateLimit = max(20, min($candidateLimit, 200));
        $sortFields = ['f3', 'f6', 'f8', 'f10'];
        $seen = [];
        $rows = [];
        foreach ($sortFields as $field) {
            for ($page = 1; $page <= $pages; $page++) {
                foreach ($this->fetchSnapshotPage($field, $page, 50) as $row) {
                    $symbol = $row['symbol'];
                    if (!$symbol || isset($seen[$symbol])) continue;
                    $seen[$symbol] = true;
                    $rows[] = $row;
                }
            }
        }
        usort($rows, function ($a, $b) {
            $sa = abs($a['change_pct']) * 100 + min($a['amount'] / 1e8, 20) + $a['turnover_rate'] * 2 + $a['vol_ratio_5d'];
            $sb = abs($b['change_pct']) * 100 + min($b['amount'] / 1e8, 20) + $b['turnover_rate'] * 2 + $b['vol_ratio_5d'];
            return $sb <=> $sa;
        });
        return array_slice($rows, 0, $candidateLimit);
    }

    private function fetchSnapshotPage(string $sortField, int $page, int $pageSize): array
    {
        $fs = 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23';
        $fields = 'f2,f3,f5,f6,f7,f8,f10,f12,f13,f14,f15,f16,f17,f18,f20,f21,f23,f115';
        $path = "/api/qt/clist/get?pn={$page}&pz={$pageSize}&po=1&np=1&fltt=2&invt=2&fid={$sortField}&fs=" . urlencode($fs) . "&fields={$fields}&_=" . (time() * 1000);
        $resp = $this->getWithFallback($path, [self::PUSH2_URL, self::PUSH2_DELAY_URL], ['Referer: https://data.eastmoney.com/']);
        if ($resp['error'] || $resp['http_code'] !== 200) return [];
        $parsed = HttpClient::parseJson($resp['body']);
        $diff = $parsed['data']['data']['diff'] ?? [];
        if (!$parsed['ok'] || !is_array($diff)) return [];

        $rows = [];
        foreach ($diff as $item) {
            $code = (string)($item['f12'] ?? '');
            $market = (int)($item['f13'] ?? 1);
            if ($code === '') continue;
            $prefix = $market === 0 ? 'sz' : 'sh';
            $close = $this->num($item['f2'] ?? null);
            if ($close === null || $close <= 0) continue;
            $rows[] = [
                'symbol' => $prefix . $code,
                'code' => $code,
                'secid' => $market . '.' . $code,
                'name' => (string)($item['f14'] ?? ''),
                'close' => $close,
                'price' => $close,
                'change_pct' => ($this->num($item['f3'] ?? 0) ?? 0) / 100,
                'change_pct_display' => $this->num($item['f3'] ?? 0) ?? 0,
                'volume' => $this->num($item['f5'] ?? 0) ?? 0,
                'amount' => $this->num($item['f6'] ?? 0) ?? 0,
                'amplitude' => ($this->num($item['f7'] ?? 0) ?? 0) / 100,
                'turnover_rate' => ($this->num($item['f8'] ?? 0) ?? 0) / 100,
                'turnover_rate_display' => $this->num($item['f8'] ?? 0) ?? 0,
                'vol_ratio_5d' => $this->num($item['f10'] ?? 0) ?? 0,
                'high' => $this->num($item['f15'] ?? 0) ?? 0,
                'low' => $this->num($item['f16'] ?? 0) ?? 0,
                'open' => $this->num($item['f17'] ?? 0) ?? 0,
                'prev_close' => $this->num($item['f18'] ?? 0) ?? 0,
                'total_mv' => $this->num($item['f20'] ?? 0) ?? 0,
                'circ_mv' => $this->num($item['f21'] ?? 0) ?? 0,
                'pb' => $this->num($item['f23'] ?? 0),
                'pe_ttm' => $this->num($item['f115'] ?? 0),
            ];
        }
        return $rows;
    }

    private function getWithFallback(string $path, array $bases, array $headers = []): array
    {
        $lastResp = null;
        foreach ($bases as $base) {
            $resp = $this->http->get($base . $path, $headers);
            if (!$resp['error'] && $resp['http_code'] === 200) {
                return $resp;
            }
            $lastResp = $resp;
        }
        return $lastResp ?: ['body' => '', 'http_code' => 0, 'error' => 'no endpoint available'];
    }

    private function hydrateIndicators(array $candidates): array
    {
        $result = [];
        $klines = $this->fetchKlinesBatch($candidates, 90);
        foreach ($candidates as $row) {
            $hist = $klines[$row['symbol']] ?? [];
            if (count($hist) >= 25) {
                $row = array_merge($row, $this->computeIndicators($hist, $row));
                $row['history_ready'] = true;
            } else {
                $row['history_ready'] = false;
            }
            $result[] = $this->sanitizeRow($row);
        }
        return $result;
    }

    private function fetchKlinesBatch(array $candidates, int $limit): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($candidates as $row) {
            $url = self::PUSH2HIS_URL . "/api/qt/stock/kline/get?secid=" . rawurlencode($row['secid'])
                . "&fields1=f1,f2,f3,f4,f5,f6&fields2=f51,f52,f53,f54,f55,f56,f57,f58,f59,f60,f61&klt=101&fqt=1&lmt={$limit}&_=" . (time() * 1000);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://quote.eastmoney.com/',
                'Accept: application/json,text/plain,*/*',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['symbol' => $row['symbol'], 'handle' => $ch];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $item) {
            $body = curl_multi_getcontent($item['handle']);
            $parsed = HttpClient::parseJson($body ?: '');
            $klines = $parsed['data']['data']['klines'] ?? [];
            $out[$item['symbol']] = $this->parseKlines(is_array($klines) ? $klines : []);
            curl_multi_remove_handle($mh, $item['handle']);
            curl_close($item['handle']);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function parseKlines(array $klines): array
    {
        $rows = [];
        foreach ($klines as $line) {
            $p = explode(',', (string)$line);
            if (count($p) < 11) continue;
            $rows[] = [
                'time' => $p[0],
                'open' => (float)$p[1],
                'close' => (float)$p[2],
                'high' => (float)$p[3],
                'low' => (float)$p[4],
                'volume' => (float)$p[5],
                'amount' => (float)$p[6],
                'amplitude' => (float)$p[7] / 100,
                'change_pct' => (float)$p[8] / 100,
                'change_amt' => (float)$p[9],
                'turnover_rate' => (float)$p[10] / 100,
            ];
        }
        return $rows;
    }

    private function computeIndicators(array $hist, array $snapshot): array
    {
        $n = count($hist);
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
        $limitPct = $this->limitPct($snapshot);
        $consecutive = $this->consecutiveLimitUps($hist, $snapshot);

        $recentHigh = max(array_slice($highs, max(0, $n - 60)));
        $recentLow = min(array_slice($lows, max(0, $n - 60)));
        $returns = [];
        for ($i = max(1, $n - 20); $i < $n; $i++) {
            if ($closes[$i - 1] > 0) $returns[] = $closes[$i] / $closes[$i - 1] - 1;
        }
        $annualVol = count($returns) > 1 ? $this->std($returns) * sqrt(252) : null;

        return [
            'open' => $latest['open'] ?: $snapshot['open'],
            'high' => $latest['high'] ?: $snapshot['high'],
            'low' => $latest['low'] ?: $snapshot['low'],
            'close' => $latest['close'] ?: $snapshot['close'],
            'price' => $latest['close'] ?: $snapshot['close'],
            'amount' => $latest['amount'] ?: $snapshot['amount'],
            'volume' => $latest['volume'] ?: $snapshot['volume'],
            'turnover_rate' => $latest['turnover_rate'] ?: $snapshot['turnover_rate'],
            'turnover_rate_display' => round(($latest['turnover_rate'] ?: $snapshot['turnover_rate']) * 100, 2),
            'change_pct' => $latest['change_pct'] ?: $snapshot['change_pct'],
            'change_pct_display' => round(($latest['change_pct'] ?: $snapshot['change_pct']) * 100, 2),
            'ma5' => $ma5, 'ma10' => $ma10, 'ma20' => $ma20, 'ma60' => $ma60,
            'momentum_5d' => $momentum(5),
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
            'signal_n_day_high' => $latest['high'] >= $recentHigh,
            'signal_n_day_low' => $latest['low'] <= $recentLow,
            'signal_ma_golden_5_20' => $ma5 !== null && $ma20 !== null && $prevMa5 !== null && $prevMa20 !== null && $ma5 > $ma20 && $prevMa5 <= $prevMa20,
            'signal_macd_golden' => $macd['dif'] !== null && $macd['dea'] !== null && $macd['prev_dif'] !== null && $macd['prev_dea'] !== null && $macd['dif'] > $macd['dea'] && $macd['prev_dif'] <= $macd['prev_dea'],
            'signal_ma20_breakout' => $ma20 !== null && $prevMa20 !== null && $latest['close'] > $ma20 && $prev['close'] <= $prevMa20,
            'signal_boll_breakout_upper' => $boll['upper'] !== null && $prevBoll['upper'] !== null && $latest['close'] > $boll['upper'] && $prev['close'] <= $prevBoll['upper'],
            'signal_limit_up' => $latest['change_pct'] >= $limitPct - 0.002,
            'consecutive_limit_ups' => $consecutive,
            'limit_pct' => $limitPct,
            'quote_time' => $latest['time'],
        ];
    }

    private function passesBasicFilter(array $row, array $bf): bool
    {
        if (($bf['price_min'] ?? null) !== null && $row['close'] < $bf['price_min']) return false;
        if (($bf['price_max'] ?? null) !== null && $row['close'] > $bf['price_max']) return false;
        if (($bf['amount_min'] ?? null) !== null && $row['amount'] < $bf['amount_min']) return false;
        if (($bf['amount_max'] ?? null) !== null && $row['amount'] > $bf['amount_max']) return false;
        if (($bf['market_cap_min'] ?? null) !== null && ($row['total_mv'] ?? 0) < $bf['market_cap_min']) return false;
        if (($bf['market_cap_max'] ?? null) !== null && ($row['total_mv'] ?? 0) > $bf['market_cap_max']) return false;
        if (($bf['float_cap_min'] ?? null) !== null && ($row['circ_mv'] ?? 0) < $bf['float_cap_min']) return false;
        if (($bf['float_cap_max'] ?? null) !== null && ($row['circ_mv'] ?? 0) > $bf['float_cap_max']) return false;
        if (($bf['turnover_min'] ?? null) !== null && $row['turnover_rate'] < $bf['turnover_min']) return false;
        if (($bf['turnover_max'] ?? null) !== null && $row['turnover_rate'] > $bf['turnover_max']) return false;
        if (($bf['exclude_st'] ?? true) && preg_match('/ST|退/i', $row['name'])) return false;
        return true;
    }

    private function passesStrategy(string $id, array $r, array $p): bool
    {
        if (empty($r['history_ready']) && !in_array($id, ['high_turnover_surge', 'strong_open', 'near_limit_up'], true)) return false;
        switch ($id) {
            case 'trend_breakout':
                return $r['close'] > ($r['ma60'] ?? INF) && !empty($r['signal_n_day_high']) && $r['vol_ratio_5d'] >= $p['vol_ratio_min'];
            case 'ma_golden_cross':
                return !empty($r['signal_ma_golden_5_20']) && $r['vol_ratio_5d'] >= $p['vol_ratio_min'] && $r['close'] > ($r['ma60'] ?? INF);
            case 'macd_golden':
                return !empty($r['signal_macd_golden']) && $r['vol_ratio_5d'] >= $p['vol_ratio_min'];
            case 'volume_price_surge':
                return !empty($r['signal_ma20_breakout']) && $r['vol_ratio_5d'] >= $p['vol_ratio_min'] && $r['close'] > $r['open'];
            case 'low_volatility_leader':
                return ($r['momentum_20d'] ?? -INF) > 0 && ($r['annual_vol_20d'] ?? INF) < $p['vol_max'] && $r['close'] > ($r['ma20'] ?? INF);
            case 'broken_board_recovery':
                return !empty($r['signal_limit_up']) && $r['vol_ratio_5d'] >= $p['vol_ratio_min'] && $r['change_pct'] > $p['change_pct_min'];
            case 'oversold_bounce':
                return ($r['rsi_14'] ?? INF) < $p['rsi_max'] && $r['close'] > $r['open'] && $r['vol_ratio_5d'] >= $p['vol_ratio_min'];
            case 'boll_breakout':
                return !empty($r['signal_boll_breakout_upper']) && $r['vol_ratio_5d'] >= $p['vol_ratio_min'];
            case 'bullish_alignment':
                return ($r['ma5'] ?? -INF) > ($r['ma10'] ?? INF) && ($r['ma10'] ?? -INF) > ($r['ma20'] ?? INF) && ($r['ma20'] ?? -INF) > ($r['ma60'] ?? INF) && ($r['momentum_20d'] ?? -INF) > 0;
            case 'consecutive_limit_ups':
                return !empty($r['signal_limit_up']) && ($r['consecutive_limit_ups'] ?? 0) >= $p['min_boards'];
            case 'pullback_to_support':
                $prox = $p['ma_proximity'];
                return $r['close'] > ($r['ma20'] ?? INF) * (1 - $prox) && $r['close'] < ($r['ma20'] ?? 0) * (1 + $prox) && $r['vol_ratio_5d'] < $p['vol_ratio_max'] && $r['close'] > ($r['ma60'] ?? INF) && ($r['momentum_20d'] ?? -INF) > 0;
            case 'n_day_low_reversal':
                return !empty($r['signal_n_day_low']) && $r['close'] > $r['open'] && $r['vol_ratio_5d'] >= $p['vol_ratio_min'];
            case 'high_turnover_surge':
                return $r['turnover_rate'] > ($p['min_turnover'] / 100) && $r['change_pct'] > ($p['min_change'] / 100);
            case 'limit_up_momentum':
                return $r['change_pct'] > ($p['min_change'] / 100) && ($r['consecutive_limit_ups'] ?? 0) >= $p['min_boards'];
            case 'near_limit_up':
                return $r['change_pct'] > ($p['min_change'] / 100) && $r['change_pct'] < ($r['limit_pct'] ?? 0.10) - ($p['limit_gap'] / 100);
            case 'strong_open':
                return $r['open'] > $r['prev_close'] * (1 + $p['min_open_gap'] / 100) && $r['close'] > $r['open'] && $r['change_pct'] > ($p['min_change'] / 100);
        }
        return false;
    }

    private function applyScores(array $rows, array $weights, bool $descending): array
    {
        if (!$rows || !$weights) return $rows;
        $mins = []; $maxs = [];
        foreach ($weights as $col => $weight) {
            $values = array_values(array_filter(array_map(function ($r) use ($col) {
                return isset($r[$col]) && is_numeric($r[$col]) ? (float)$r[$col] : null;
            }, $rows), function ($v) { return $v !== null; }));
            if ($values) {
                $mins[$col] = min($values);
                $maxs[$col] = max($values);
            }
        }
        $totalWeight = array_sum($weights) ?: 1;
        foreach ($rows as &$row) {
            $score = 0.0;
            foreach ($weights as $col => $weight) {
                if (!isset($row[$col]) || !isset($mins[$col], $maxs[$col])) continue;
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

    private function buildStrategies(): array
    {
        $base = ['price_min' => 3, 'price_max' => 300, 'market_cap_min' => 10e8, 'amount_min' => 0.2e8, 'exclude_st' => true];
        $make = function ($id, $name, $desc, $tags, $params, $scoring, $limit = 100, $basic = [], $stop = -0.06, $hold = 15) use ($base) {
            $defaults = [];
            foreach ($params as $p) $defaults[$p['id']] = $p['default'];
            return compact('id', 'name') + [
                'description' => $desc,
                'tags' => $tags,
                'params' => $params,
                'defaults' => $defaults,
                'scoring' => $scoring,
                'limit' => $limit,
                'basic_filter' => array_merge($base, $basic),
                'descending' => true,
                'stop_loss' => $stop,
                'max_hold_days' => $hold,
            ];
        };
        $p = function ($id, $label, $default, $min, $max, $step, $type = 'float') {
            return compact('id', 'label', 'type', 'default', 'min', 'max', 'step');
        };
        $items = [
            $make('trend_breakout', '趋势突破', 'MA60 上方 + 60 日新高 + 量能 >= 2 倍均量', ['趋势','突破','放量'], [$p('vol_ratio_min','最低量比',2.0,0.5,10,0.1)], ['momentum_60d'=>0.4,'vol_ratio_5d'=>0.3,'change_pct'=>0.3], 100, ['price_min'=>5,'price_max'=>200,'market_cap_min'=>20e8,'amount_min'=>1e8], -0.08, 20),
            $make('ma_golden_cross', 'MA 金叉', 'MA5 上穿 MA20 当日触发，量能配合', ['均线','金叉'], [$p('vol_ratio_min','最低量比',1.2,0.5,5,0.1)], ['momentum_20d'=>0.5,'vol_ratio_5d'=>0.3,'change_pct'=>0.2]),
            $make('macd_golden', 'MACD 金叉放量', 'MACD 金叉当日 + 量能放大', ['MACD','金叉','放量'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1)], ['momentum_60d'=>0.4,'vol_ratio_5d'=>0.3,'change_pct'=>0.3], 100, [], -0.07, 20),
            $make('volume_price_surge', '量价齐升', '突破 MA20 + 放量 + 收阳', ['量价','突破'], [$p('vol_ratio_min','最低量比',2.0,0.5,10,0.1)], ['vol_ratio_5d'=>0.4,'change_pct'=>0.3,'momentum_20d'=>0.3]),
            $make('low_volatility_leader', '低波动龙头', '20 日动量为正 + 年化波动 < 30% + MA20 上方', ['低波动','龙头'], [$p('vol_max','最大年化波动',0.30,0.05,1,0.01)], ['momentum_60d'=>0.4,'momentum_20d'=>0.3,'turnover_rate'=>0.3], 100, [], -0.05, 30),
            $make('broken_board_recovery', '断板反包', '连板 >= 2 后断板 1-2 天，出现放量反包信号', ['涨停','反包'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1), $p('change_pct_min','最低涨幅',0.03,0.01,0.10,0.01)], ['change_pct'=>0.4,'vol_ratio_5d'=>0.3,'momentum_5d'=>0.3], 100, [], -0.06, 10),
            $make('oversold_bounce', '超跌反弹', 'RSI14 < 30 超卖区 + 当日收阳 + 放量', ['超跌','反弹','RSI'], [$p('rsi_max','RSI上限',30,10,50,1), $p('vol_ratio_min','最低量比',1.2,0.5,5,0.1)], ['change_pct'=>0.3,'vol_ratio_5d'=>0.3,'momentum_5d'=>0.2,'rsi_14'=>0.2], 100, [], -0.05, 15),
            $make('boll_breakout', '布林突破', '突破布林上轨 + 放量，强势加速信号', ['布林','突破'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1)], ['vol_ratio_5d'=>0.4,'change_pct'=>0.3,'momentum_20d'=>0.3]),
            $make('bullish_alignment', '均线多头', 'MA5 > MA10 > MA20 > MA60 多头排列 + 短期动量为正', ['均线','多头'], [], ['momentum_60d'=>0.4,'momentum_20d'=>0.3,'turnover_rate'=>0.3], 100, [], -0.06, 20),
            $make('consecutive_limit_ups', '连板股', '当日涨停且连续涨停 >= 2 天，强势追涨', ['涨停','连板'], [$p('min_boards','最少连板数',2,1,20,1,'int')], ['consecutive_limit_ups'=>0.5,'change_pct'=>0.3,'amount'=>0.2], 100, [], -0.05, 5),
            $make('pullback_to_support', '缩量回踩', '回踩 MA20 附近 + 缩量 + 中期趋势向上', ['回踩','支撑'], [$p('ma_proximity','均线偏离度',0.02,0.01,0.05,0.005), $p('vol_ratio_max','最大量比',0.8,0.2,1.5,0.1)], ['momentum_60d'=>0.4,'momentum_20d'=>0.3,'turnover_rate'=>0.3], 100, [], -0.05, 20),
            $make('n_day_low_reversal', '新低反转', '触及 60 日新低后当日收阳放量，反转信号', ['反转','新低'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1)], ['change_pct'=>0.4,'vol_ratio_5d'=>0.3,'momentum_5d'=>0.3]),
            $make('high_turnover_surge', '高换手拉升', '换手率 > 5% 且涨幅 > 3%，资金活跃', ['换手率','放量','资金'], [$p('min_turnover','最低换手率%',5,1,20,0.5), $p('min_change','最低涨幅%',3,1,10,0.5)], ['turnover_rate'=>0.4,'change_pct'=>0.3,'momentum_5d'=>0.3], 50, [], -0.05, 10),
            $make('limit_up_momentum', '连板接力', '连板股 + 今日涨幅 > 5%，连板接力追踪', ['涨停','连板','接力'], [$p('min_change','最低涨幅%',5,2,15,0.5), $p('min_boards','最少连板',1,1,10,1,'int')], ['consecutive_limit_ups'=>0.4,'change_pct'=>0.3,'amount'=>0.3], 50, [], -0.05, 5),
            $make('near_limit_up', '逼近涨停', '涨幅 > 7% 且距涨停 < 3%，追涨信号', ['涨停','追涨'], [$p('min_change','最低涨幅%',7,3,15,1), $p('limit_gap','距涨停空间%',3,1,10,0.5)], ['change_pct'=>0.5,'amount'=>0.3,'momentum_5d'=>0.2], 50, [], -0.05, 5),
            $make('strong_open', '强势高开', '高开 > 3% 且收盘高于开盘价，集合竞价强势', ['高开','强势'], [$p('min_open_gap','最低高开%',3,1,10,0.5), $p('min_change','最低涨幅%',3,1,10,0.5)], ['change_pct'=>0.4,'amplitude'=>0.2,'amount'=>0.4], 50, [], -0.05, 10),
        ];
        $out = [];
        foreach ($items as $item) $out[$item['id']] = $item;
        return $out;
    }

    private function avg(array $values): float
    {
        $values = array_values(array_filter($values, 'is_numeric'));
        return $values ? array_sum($values) / count($values) : 0.0;
    }

    private function std(array $values): float
    {
        $avg = $this->avg($values);
        $sum = 0.0;
        foreach ($values as $v) $sum += pow($v - $avg, 2);
        return sqrt($sum / max(count($values) - 1, 1));
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

    private function num($value): ?float
    {
        if ($value === null) return null;
        $text = trim((string)$value);
        if ($text === '' || $text === '-') return null;
        return is_numeric($text) ? (float)$text : null;
    }

    private function sanitizeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_float($v) && (!is_finite($v) || is_nan($v))) $row[$k] = null;
        }
        return $row;
    }
}
