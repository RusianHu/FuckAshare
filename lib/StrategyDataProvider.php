<?php

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/MarketDataService.php';
require_once __DIR__ . '/StrategyIndicatorEngine.php';

class StrategyDataProvider
{
    const SOURCE_NAME = 'eastmoney';
    const PUSH2_URL = 'https://push2.eastmoney.com';
    const PUSH2_DELAY_URL = 'https://push2delay.eastmoney.com';

    /** @var HttpClient */
    private $http;

    /** @var MarketDataService */
    private $market;

    /** @var StrategyIndicatorEngine */
    private $indicators;

    /** @var array */
    private $sourceErrors = [];

    /** @var array */
    private $candidateSources = [];

    /** @var array */
    private $dataHealth = [];

    public function __construct(?MarketDataService $market = null, ?StrategyIndicatorEngine $indicators = null)
    {
        $this->market = $market ?: new MarketDataService();
        $this->indicators = $indicators ?: new StrategyIndicatorEngine();
        $this->http = new HttpClient([
            'timeout' => 12,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://quote.eastmoney.com/',
                'Accept: application/json,text/plain,*/*',
            ],
        ]);
    }

    public function loadCandidateSnapshot(int $pages, int $candidateLimit): array
    {
        $pages = max(1, min($pages, 5));
        $candidateLimit = max(20, min($candidateLimit, 200));
        $sortFields = ['f3', 'f6', 'f8', 'f10'];
        $seen = [];
        $rows = [];
        $sourceCounts = [];

        foreach ($sortFields as $field) {
            for ($page = 1; $page <= $pages; $page++) {
                $pageRows = $this->fetchSnapshotPage($field, $page, 50);
                $sourceCounts[$field] = ($sourceCounts[$field] ?? 0) + count($pageRows);
                foreach ($pageRows as $row) {
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

        $this->candidateSources['stock_snapshot'] = [
            'source' => self::SOURCE_NAME,
            'sort_fields' => $sortFields,
            'raw_counts' => $sourceCounts,
            'unique_count' => count($rows),
        ];

        return array_slice($rows, 0, $candidateLimit);
    }

    public function hydrateStockIndicators(array $candidates, bool $needsHistory, int $historyCount = 90): array
    {
        if (!$needsHistory) {
            foreach ($candidates as &$row) {
                $row['asset_type'] = 'stock';
                $row['history_ready'] = false;
            }
            unset($row);
            return $candidates;
        }

        $result = [];
        $ready = 0;
        $fail = 0;
        foreach ($candidates as $row) {
            $row['asset_type'] = 'stock';
            $klineResult = $this->market->kline($row['symbol'], '1d', $historyCount, '', MarketDataService::SOURCE_AUTO, true, false);
            if ($klineResult->hasData()) {
                $hist = $this->indicators->normalizeKlineRows($klineResult->data);
                $row['kline_source'] = $klineResult->source;
                $row['kline_status'] = $klineResult->status;
                $row['kline_bars'] = count($hist);
                if (count($hist) >= 25) {
                    $row = array_merge($row, $this->indicators->computeIndicators($hist, $row));
                    $row['history_ready'] = true;
                    $ready++;
                } else {
                    $row['history_ready'] = false;
                    $row['kline_error'] = 'insufficient_kline_bars';
                    $fail++;
                }
            } else {
                $row['history_ready'] = false;
                $row['kline_source'] = $klineResult->source;
                $row['kline_status'] = $klineResult->status;
                $row['kline_error'] = $klineResult->errorMessage ?: $klineResult->errorCode;
                $this->recordSourceError('kline', $row['symbol'], $row['kline_error']);
                $fail++;
            }
            $result[] = $this->sanitizeRow($row);
        }

        $result = $this->indicators->applyCrossSectionalFeatures($result);
        $this->dataHealth['kline'] = [
            'requested' => count($candidates),
            'ready' => $ready,
            'failed' => $fail,
            'ready_ratio' => count($candidates) ? round($ready / count($candidates), 4) : 0,
        ];

        return $result;
    }

    public function loadSectorRows(string $key = 'f62', string $type = 'industry'): array
    {
        $result = $this->market->sectorFlow($key, $type);
        if (!$result->hasData() || !is_array($result->data)) {
            $this->recordSourceError('sector_flow', $type, $result->errorMessage ?: $result->errorCode);
            $this->dataHealth['sector_flow'] = [
                'success' => false,
                'source' => $result->source,
                'message' => $result->errorMessage,
            ];
            return [];
        }

        $rows = [];
        foreach ($result->data as $item) {
            if (!is_array($item)) continue;
            $changePctDisplay = $this->num($item['change_pct'] ?? 0) ?? 0;
            $netToday = $this->num($item['net_inflow_today'] ?? 0) ?? 0;
            $net5 = $this->num($item['net_inflow_5d'] ?? 0) ?? 0;
            $net10 = $this->num($item['net_inflow_10d'] ?? 0) ?? 0;
            $amount = $this->num($item['amount'] ?? 0) ?? 0;
            $sectorScore = $netToday * 0.5 + $net5 * 0.3 + $net10 * 0.2 + $changePctDisplay * 1e8;
            $rows[] = [
                'asset_type' => 'sector',
                'symbol' => 'sector:' . (string)($item['code'] ?? ''),
                'code' => (string)($item['code'] ?? ''),
                'secid' => '',
                'name' => (string)($item['name'] ?? ''),
                'close' => null,
                'price' => null,
                'change_pct' => $changePctDisplay / 100,
                'change_pct_display' => $changePctDisplay,
                'turnover_rate' => ($this->num($item['turnover_rate'] ?? 0) ?? 0) / 100,
                'turnover_rate_display' => $this->num($item['turnover_rate'] ?? 0) ?? 0,
                'amount' => $amount,
                'vol_ratio_5d' => null,
                'net_inflow_today' => $netToday,
                'net_inflow_5d' => $net5,
                'net_inflow_10d' => $net10,
                'main_net_inflow' => $this->num($item['main_net_inflow'] ?? 0) ?? 0,
                'sector_score' => $sectorScore,
                'history_ready' => false,
                'source' => $result->source,
            ];
        }

        $this->candidateSources['sector_flow'] = [
            'source' => $result->source,
            'type' => $type,
            'key' => $key,
            'count' => count($rows),
        ];
        $this->dataHealth['sector_flow'] = [
            'success' => true,
            'source' => $result->source,
            'count' => count($rows),
        ];
        return $rows;
    }

    public function healthCheck(): array
    {
        $checks = [];

        $hot = $this->market->hotStocks(1, 5, 'f3', 1);
        $checks['hotStocks'] = $this->resultHealth($hot, is_array($hot->data) ? count($hot->data) : 0);

        $kline = $this->market->kline('sh600519', '1d', 5, '', MarketDataService::SOURCE_AUTO, true, false);
        $klineRows = $kline->hasData() ? $this->indicators->normalizeKlineRows($kline->data) : [];
        $checks['kline'] = $this->resultHealth($kline, count($klineRows));

        $sector = $this->market->sectorFlow('f62', 'industry');
        $checks['sectorFlow'] = $this->resultHealth($sector, is_array($sector->data) ? count($sector->data) : 0);

        return [
            'success' => ($checks['hotStocks']['success'] || $checks['kline']['success'] || $checks['sectorFlow']['success']),
            'checks' => $checks,
            'source_errors' => $this->sourceErrors,
            'as_of' => date('Y-m-d'),
        ];
    }

    public function diagnosticRows(array $health): array
    {
        $rows = [];
        foreach (($health['checks'] ?? []) as $name => $check) {
            $ok = !empty($check['success']);
            $rows[] = [
                'asset_type' => 'diagnostic',
                'symbol' => 'diagnostic:' . $name,
                'code' => $name,
                'name' => $name,
                'close' => null,
                'price' => null,
                'change_pct' => null,
                'change_pct_display' => null,
                'turnover_rate' => null,
                'turnover_rate_display' => null,
                'vol_ratio_5d' => null,
                'amount' => $check['count'] ?? 0,
                'score' => $ok ? 100 : 0,
                'diagnostic_status' => $ok ? 'ok' : 'failed',
                'diagnostic_message' => $ok ? '链路可用' : ($check['message'] ?? '链路不可用'),
                'source' => $check['source'] ?? '',
                'history_ready' => false,
            ];
        }
        return $rows;
    }

    public function getSourceErrors(): array
    {
        return $this->sourceErrors;
    }

    public function getCandidateSources(): array
    {
        return $this->candidateSources;
    }

    public function getDataHealth(): array
    {
        return $this->dataHealth;
    }

    private function fetchSnapshotPage(string $sortField, int $page, int $pageSize): array
    {
        $fs = 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23';
        $fields = 'f2,f3,f5,f6,f7,f8,f10,f12,f13,f14,f15,f16,f17,f18,f20,f21,f23,f115';
        $path = "/api/qt/clist/get?pn={$page}&pz={$pageSize}&po=1&np=1&fltt=2&invt=2&fid={$sortField}&fs=" . urlencode($fs) . "&fields={$fields}&_=" . (time() * 1000);
        $resp = $this->getWithFallback($path, [self::PUSH2_URL, self::PUSH2_DELAY_URL], ['Referer: https://data.eastmoney.com/']);
        if ($resp['error'] || $resp['http_code'] !== 200) {
            $this->recordSourceError('candidate_snapshot', $sortField, $resp['error'] ?: "HTTP {$resp['http_code']}");
            return [];
        }

        $parsed = HttpClient::parseJson($resp['body']);
        $diff = $parsed['data']['data']['diff'] ?? [];
        if (!$parsed['ok'] || !is_array($diff)) {
            $this->recordSourceError('candidate_snapshot', $sortField, 'parse_error');
            return [];
        }

        $rows = [];
        foreach ($diff as $item) {
            $code = (string)($item['f12'] ?? '');
            $market = (int)($item['f13'] ?? 1);
            if ($code === '') continue;
            $prefix = $market === 0 ? 'sz' : 'sh';
            $close = $this->num($item['f2'] ?? null);
            if ($close === null || $close <= 0) continue;
            $rows[] = [
                'asset_type' => 'stock',
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

    private function resultHealth($result, int $count): array
    {
        return [
            'success' => $result->hasData(),
            'source' => $result->source,
            'status' => $result->status,
            'count' => $count,
            'message' => $result->errorMessage,
            'meta' => $result->meta,
        ];
    }

    private function recordSourceError(string $action, string $target, ?string $message): void
    {
        $this->sourceErrors[] = [
            'action' => $action,
            'target' => $target,
            'message' => $message ?: 'unknown_error',
        ];
        if (count($this->sourceErrors) > 30) {
            $this->sourceErrors = array_slice($this->sourceErrors, -30);
        }
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
