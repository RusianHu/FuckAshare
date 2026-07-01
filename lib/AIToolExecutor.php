<?php
/**
 * AIToolExecutor — safe read-only tool execution for the AI advisor.
 */

require_once __DIR__ . '/../SecurityAudit.php';
require_once __DIR__ . '/AIToolRegistry.php';
require_once __DIR__ . '/MarketDataService.php';
require_once __DIR__ . '/FundService.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/DataSourceResult.php';

class AIToolExecutor
{
    /** @var MarketDataService */
    private $market;

    /** @var FundService */
    private $fund;

    /** @var int */
    private $outputCharLimit;

    /** @var array<string,string> */
    private $handlers = [
        'fa_normalize_stock_code' => 'executeNormalizeStockCode',
        'fa_get_stock_quote' => 'executeStockQuote',
        'fa_get_stock_kline' => 'executeStockKline',
        'fa_get_stock_flow' => 'executeStockFlow',
        'fa_get_sector_flow' => 'executeSectorFlow',
        'fa_get_hot_stocks' => 'executeHotStocks',
        'fa_get_market_breadth' => 'executeMarketBreadth',
        'fa_get_xueqiu_hot_stock' => 'executeXueqiuHotStock',
        'fa_run_xueqiu_screener' => 'executeXueqiuScreener',
        'fa_get_xueqiu_feed' => 'executeXueqiuFeed',
        'fa_search_funds' => 'executeSearchFunds',
        'fa_get_fund_info' => 'executeFundInfo',
        'fa_get_fund_estimate' => 'executeFundEstimate',
        'fa_get_fund_history' => 'executeFundHistory',
        'fa_get_fund_rank' => 'executeFundRank',
        'fa_calculate_kline_indicators' => 'calculateIndicators',
        'fa_compare_candidates' => 'compareCandidates',
    ];

    public function __construct(?MarketDataService $market = null, ?FundService $fund = null, int $outputCharLimit = 30000)
    {
        $this->market = $market ?: new MarketDataService();
        $this->fund = $fund ?: new FundService();
        $this->outputCharLimit = max(100, $outputCharLimit);
    }

    public function execute(string $name, array $args): array
    {
        $started = microtime(true);
        try {
            if (!AIToolRegistry::has($name) || !isset($this->handlers[$name])) {
                return $this->wrap(false, 'ai_tool', $name, null, 'unknown_tool', "未知工具: {$name}", $started);
            }

            $handler = $this->handlers[$name];
            return $this->$handler($args, $started);
        } catch (Throwable $e) {
            return $this->wrap(false, 'ai_tool', $name, null, 'tool_error', $e->getMessage(), $started);
        }
    }

    public function executeForModel(string $name, array $args): string
    {
        $payload = $this->execute($name, $args);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode($this->wrap(false, 'ai_tool', $name, null, 'json_encode_error', json_last_error_msg(), microtime(true)), JSON_UNESCAPED_UNICODE);
        }
        return $this->truncateJsonString($json);
    }

    private function executeNormalizeStockCode(array $args, float $started): array
    {
        $code = $this->stockCode($args['code'] ?? '');
        $sc = StockCode::parse($code);
        return $this->wrap(true, 'local', 'fa_normalize_stock_code', [
            'raw' => $sc->raw,
            'code' => $sc->code,
            'market' => $sc->market,
            'normalized' => $sc->normalized,
            'eastmoney_secid' => $sc->toEastmoneySecid(),
            'ashare' => $sc->toAshare(),
            'xueqiu' => $sc->toXueqiu(),
            'display' => $sc->toDisplay(),
            'is_a_stock' => $sc->isAStock(),
            'is_valid' => $sc->isValid(),
        ], null, null, $started);
    }

    private function executeStockQuote(array $args, float $started): array
    {
        return $this->fromResult($this->market->quote(
            implode(',', $this->stockCodes($args['codes'] ?? [])),
            $this->enum($args['source'] ?? null, SecurityAudit::ALLOWED_DATA_SOURCES, 'auto'),
            $this->bool($args['fallback'] ?? null, true),
            false
        ), $started);
    }

    private function executeStockKline(array $args, float $started): array
    {
        return $this->fromResult($this->market->kline(
            $this->stockCode($args['code'] ?? ''),
            $this->enum($args['frequency'] ?? null, SecurityAudit::ALLOWED_FREQUENCIES, '1d'),
            $this->int($args['count'] ?? null, 1, 500, 120),
            $this->date($args['end_date'] ?? null, true),
            $this->enum($args['source'] ?? null, SecurityAudit::ALLOWED_DATA_SOURCES, 'auto'),
            true,
            false
        ), $started);
    }

    private function executeStockFlow(array $args, float $started): array
    {
        return $this->fromResult($this->market->stockFlow(
            $this->stockCode($args['code'] ?? ''),
            $this->int($args['limit'] ?? null, 0, 1000, 0)
        ), $started);
    }

    private function executeSectorFlow(array $args, float $started): array
    {
        return $this->fromResult($this->market->sectorFlow(
            $this->enum($args['key'] ?? null, SecurityAudit::ALLOWED_SECTOR_KEYS, 'f62'),
            $this->enum($args['type'] ?? null, SecurityAudit::ALLOWED_SECTOR_TYPES, 'industry')
        ), $started);
    }

    private function executeHotStocks(array $args, float $started): array
    {
        return $this->fromResult($this->market->hotStocks(
            $this->int($args['page'] ?? null, 1, 100, 1),
            $this->int($args['page_size'] ?? null, 1, 200, 50),
            $this->enum($args['sort'] ?? null, SecurityAudit::ALLOWED_SORT_FIELDS, 'f62'),
            $this->int($args['order'] ?? null, -1, 1, 1)
        ), $started);
    }

    private function executeMarketBreadth(array $args, float $started): array
    {
        return $this->fromResult($this->market->marketBreadth(
            $this->enum($args['scope'] ?? null, SecurityAudit::ALLOWED_MARKET_BREADTH_SCOPES, 'a_share'),
            $this->bool($args['include_limit_stats'] ?? null, true),
            $this->bool($args['include_index_quotes'] ?? null, true)
        ), $started);
    }

    private function executeXueqiuHotStock(array $args, float $started): array
    {
        return $this->fromResult($this->market->hotStock(
            $this->enum($args['type'] ?? null, SecurityAudit::ALLOWED_XUEQIU_HOT_TYPES, '10'),
            $this->int($args['size'] ?? null, 1, 100, 20),
            false
        ), $started);
    }

    private function executeXueqiuScreener(array $args, float $started): array
    {
        return $this->fromResult($this->market->screener(
            $this->int($args['page'] ?? null, 1, 100, 1),
            $this->int($args['size'] ?? null, 1, 100, 20),
            $this->enum($args['order_by'] ?? null, SecurityAudit::ALLOWED_SCREENER_ORDER_FIELDS, 'percent'),
            $this->enum($args['order'] ?? null, ['asc', 'desc'], 'desc'),
            $this->enum($args['market'] ?? null, SecurityAudit::ALLOWED_SCREENER_MARKETS, 'CN'),
            $this->enum($args['type'] ?? null, SecurityAudit::ALLOWED_SCREENER_TYPES, 'sh_sz'),
            false
        ), $started);
    }

    private function executeXueqiuFeed(array $args, float $started): array
    {
        return $this->fromResult($this->market->fundx(
            $this->int($args['page'] ?? null, 1, 100, 1),
            $this->safeText($args['source'] ?? '', 40, true),
            $this->int($args['last_id'] ?? null, 0, PHP_INT_MAX, 0),
            false
        ), $started);
    }

    private function executeSearchFunds(array $args, float $started): array
    {
        return $this->fromResult($this->fund->search(
            $this->safeText($args['keyword'] ?? '', SecurityAudit::MAX_KEYWORD_LENGTH, false)
        ), $started);
    }

    private function executeFundInfo(array $args, float $started): array
    {
        return $this->fromResult($this->fund->info($this->fundCodes($args['codes'] ?? [])), $started);
    }

    private function executeFundEstimate(array $args, float $started): array
    {
        $codes = $this->fundCodes($args['codes'] ?? []);
        return $this->fromResult(count($codes) === 1 ? $this->fund->estimate($codes[0]) : $this->fund->batchEstimate($codes), $started);
    }

    private function executeFundHistory(array $args, float $started): array
    {
        return $this->fromResult($this->fund->history(
            $this->fundCode($args['code'] ?? ''),
            $this->int($args['page'] ?? null, 1, 200, 1),
            $this->int($args['page_size'] ?? null, 5, 100, 30)
        ), $started);
    }

    private function executeFundRank(array $args, float $started): array
    {
        return $this->fromResult($this->fund->rank(
            $this->enum($args['type'] ?? null, ['all', 'stock', 'mixed', 'bond', 'index', 'qdii', 'fof'], 'all'),
            $this->enum($args['period'] ?? null, ['day', 'week', 'month', 'quarter', 'half_year', 'year', 'two_year', 'three_year', 'this_year', 'since'], 'year'),
            $this->int($args['page'] ?? null, 1, 1000, 1),
            $this->int($args['page_size'] ?? null, 5, 100, 30)
        ), $started);
    }

    private function fromResult(DataSourceResult $result, float $started): array
    {
        $response = $result->toResponse(false);
        $response['meta']['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
        return $this->truncateArray($response);
    }

    private function wrap(bool $success, string $source, string $action, $data, ?string $code, ?string $message, float $started): array
    {
        $payload = [
            'success' => $success,
            'source' => $source,
            'action' => $action,
            'data' => $data,
            'meta' => [
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'updated_at' => date('c'),
            ],
        ];
        if (!$success) {
            $payload['code'] = $code ?: 'tool_error';
            $payload['message'] = $message ?: '工具执行失败';
            unset($payload['data']);
        }
        return $this->truncateArray($payload);
    }

    private function calculateIndicators(array $args, float $started): array
    {
        $code = $this->stockCode($args['code'] ?? '');
        $frequency = $this->enum($args['frequency'] ?? null, SecurityAudit::ALLOWED_FREQUENCIES, '1d');
        $count = $this->int($args['count'] ?? null, 30, 500, 120);
        $source = $this->enum($args['source'] ?? null, SecurityAudit::ALLOWED_DATA_SOURCES, 'auto');
        $result = $this->market->kline($code, $frequency, $count, '', $source, true, false);

        if (!$result->hasData() || !is_array($result->data)) {
            return $this->fromResult($result, $started);
        }

        $rows = $this->normalizeKlineRows($result->data);
        if (count($rows) < 5) {
            return $this->wrap(false, $result->source, 'fa_calculate_kline_indicators', null, 'insufficient_kline_data', 'K线数据不足，无法计算指标', $started);
        }

        $closes = array_column($rows, 'close');
        $highs = array_column($rows, 'high');
        $lows = array_column($rows, 'low');
        $last = $rows[count($rows) - 1];
        $first = $rows[0];
        $returns = $this->returns($closes);
        $macd = $this->macd($closes);
        $kdj = $this->kdj($rows);
        $boll = $this->boll($closes, 20);

        $data = [
            'code' => $code,
            'frequency' => $frequency,
            'bars' => count($rows),
            'latest' => $last,
            'stage_return_pct' => $this->pct(($last['close'] - $first['close']) / max(abs($first['close']), 0.000001)),
            'volatility_pct' => $this->pct($this->stddev($returns)),
            'high' => ['price' => max($highs), 'date' => $this->dateAtExtreme($rows, 'high', max($highs))],
            'low' => ['price' => min($lows), 'date' => $this->dateAtExtreme($rows, 'low', min($lows))],
            'ma' => [
                'ma5' => $this->lastSma($closes, 5),
                'ma10' => $this->lastSma($closes, 10),
                'ma20' => $this->lastSma($closes, 20),
                'ma60' => $this->lastSma($closes, 60),
            ],
            'boll' => $boll,
            'macd' => $macd,
            'rsi14' => $this->rsi($closes, 14),
            'kdj' => $kdj,
        ];

        return $this->wrap(true, $result->source, 'fa_calculate_kline_indicators', $data, null, null, $started);
    }

    private function compareCandidates(array $args, float $started): array
    {
        $candidates = $args['candidates'] ?? [];
        if (!is_array($candidates)) {
            throw new InvalidArgumentException('candidates 必须是数组');
        }
        if (count($candidates) > 50) {
            throw new InvalidArgumentException('candidates 最多 50 个');
        }

        $metric = $this->safeText($args['sort_metric'] ?? 'score', 40, true);
        $order = $this->enum($args['order'] ?? null, ['asc', 'desc'], 'desc');
        $items = [];
        foreach ($candidates as $item) {
            if (!is_array($item)) continue;
            $metrics = $this->normalizeMetrics($item['metrics'] ?? []);
            $value = $metrics[$metric] ?? null;
            $items[] = [
                'code' => $this->safeText($item['code'] ?? '', 30, true),
                'name' => $this->safeText($item['name'] ?? '', 80, true),
                'metric' => $metric,
                'value' => is_numeric($value) ? (float)$value : null,
                'metrics' => $metrics,
            ];
        }

        usort($items, function($a, $b) use ($order) {
            $av = $a['value'];
            $bv = $b['value'];
            if ($av === $bv) return 0;
            if ($av === null) return 1;
            if ($bv === null) return -1;
            return $order === 'asc' ? ($av <=> $bv) : ($bv <=> $av);
        });

        foreach ($items as $i => &$item) {
            $item['rank'] = $i + 1;
        }
        unset($item);

        return $this->wrap(true, 'local', 'fa_compare_candidates', [
            'asset_type' => $this->enum($args['asset_type'] ?? null, ['stock', 'fund'], 'stock'),
            'sort_metric' => $metric,
            'order' => $order,
            'items' => $items,
        ], null, null, $started);
    }

    private function normalizeMetrics($metrics): array
    {
        if (!is_array($metrics)) return [];
        if (!$this->isList($metrics)) return $metrics;

        $assoc = [];
        foreach ($metrics as $item) {
            if (!is_array($item)) continue;
            $key = trim((string)($item['key'] ?? ''));
            if ($key === '') continue;
            $assoc[$key] = $item['value'] ?? null;
        }
        return $assoc;
    }

    private function normalizeKlineRows(array $data): array
    {
        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $date = (string)($row['date'] ?? $row['time'] ?? $row['timestamp'] ?? '');
            $open = $this->numeric($row['open'] ?? $row['o'] ?? null);
            $high = $this->numeric($row['high'] ?? $row['h'] ?? null);
            $low = $this->numeric($row['low'] ?? $row['l'] ?? null);
            $close = $this->numeric($row['close'] ?? $row['c'] ?? null);
            $volume = $this->numeric($row['volume'] ?? $row['vol'] ?? $row['v'] ?? null, true);
            if ($open === null || $high === null || $low === null || $close === null) continue;
            $rows[] = compact('date', 'open', 'high', 'low', 'close', 'volume');
        }
        return $rows;
    }

    private function numeric($value, bool $allowNull = false): ?float
    {
        if ($value === null || $value === '') return $allowNull ? null : null;
        if (!is_numeric($value)) return null;
        return (float)$value;
    }

    private function lastSma(array $values, int $period): ?float
    {
        if (count($values) < $period) return null;
        return round(array_sum(array_slice($values, -$period)) / $period, 4);
    }

    private function boll(array $closes, int $period): ?array
    {
        if (count($closes) < $period) return null;
        $slice = array_slice($closes, -$period);
        $mid = array_sum($slice) / $period;
        $sd = $this->stddev($slice);
        return [
            'mid' => round($mid, 4),
            'upper' => round($mid + 2 * $sd, 4),
            'lower' => round($mid - 2 * $sd, 4),
        ];
    }

    private function macd(array $closes): array
    {
        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);
        $dif = [];
        foreach ($closes as $i => $_) {
            $dif[] = $ema12[$i] - $ema26[$i];
        }
        $dea = $this->emaSeries($dif, 9);
        $last = count($closes) - 1;
        return [
            'dif' => round($dif[$last], 4),
            'dea' => round($dea[$last], 4),
            'macd' => round(($dif[$last] - $dea[$last]) * 2, 4),
        ];
    }

    private function emaSeries(array $values, int $period): array
    {
        $alpha = 2 / ($period + 1);
        $ema = [];
        foreach ($values as $i => $value) {
            $ema[$i] = $i === 0 ? $value : ($alpha * $value + (1 - $alpha) * $ema[$i - 1]);
        }
        return $ema;
    }

    private function rsi(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) return null;
        $slice = array_slice($closes, -($period + 1));
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i < count($slice); $i++) {
            $change = $slice[$i] - $slice[$i - 1];
            if ($change >= 0) $gain += $change;
            else $loss += abs($change);
        }
        if ($loss == 0.0) return 100.0;
        $rs = ($gain / $period) / ($loss / $period);
        return round(100 - 100 / (1 + $rs), 2);
    }

    private function kdj(array $rows, int $period = 9): ?array
    {
        if (count($rows) < $period) return null;
        $k = 50.0;
        $d = 50.0;
        foreach ($rows as $i => $row) {
            $window = array_slice($rows, max(0, $i - $period + 1), $period);
            $low = min(array_column($window, 'low'));
            $high = max(array_column($window, 'high'));
            $rsv = ($high - $low) == 0.0 ? 50.0 : (($row['close'] - $low) / ($high - $low) * 100);
            $k = (2 / 3) * $k + (1 / 3) * $rsv;
            $d = (2 / 3) * $d + (1 / 3) * $k;
        }
        return ['k' => round($k, 2), 'd' => round($d, 2), 'j' => round(3 * $k - 2 * $d, 2)];
    }

    private function returns(array $values): array
    {
        $items = [];
        for ($i = 1; $i < count($values); $i++) {
            $prev = max(abs($values[$i - 1]), 0.000001);
            $items[] = ($values[$i] - $values[$i - 1]) / $prev;
        }
        return $items;
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

    private function pct(float $value): float
    {
        return round($value * 100, 2);
    }

    private function dateAtExtreme(array $rows, string $field, float $value): string
    {
        foreach ($rows as $row) {
            if ((float)$row[$field] === $value) {
                return $row['date'];
            }
        }
        return '';
    }

    private function truncateArray(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && mb_strlen($json) <= $this->outputCharLimit) {
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload['data'] = $this->shrinkData($payload['data']);
            $payload['meta']['truncated'] = true;
            $payload['meta']['tool_output_char_limit'] = $this->outputCharLimit;
        }
        return $payload;
    }

    private function shrinkData(array $data)
    {
        if ($this->isList($data)) {
            return array_slice($data, 0, 40);
        }
        foreach ($data as $key => $value) {
            if (is_array($value) && $this->isList($value) && count($value) > 40) {
                $data[$key] = array_slice($value, 0, 40);
            }
        }
        return $data;
    }

    private function truncateJsonString(string $json): string
    {
        if (mb_strlen($json) <= $this->outputCharLimit) return $json;
        $payload = json_decode($json, true);
        if (is_array($payload)) {
            $payload = $this->truncateArray($payload);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($json !== false && mb_strlen($json) <= $this->outputCharLimit) return $json;
        return mb_substr((string)$json, 0, $this->outputCharLimit - 80) . "\n...[tool output truncated]";
    }

    private function isList(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    private function stockCodes($codes): array
    {
        if (!is_array($codes)) throw new InvalidArgumentException('codes 必须是数组');
        $valid = [];
        foreach ($codes as $code) {
            $valid[] = $this->stockCode($code);
        }
        $valid = array_values(array_unique($valid));
        if (empty($valid)) throw new InvalidArgumentException('至少需要一个股票代码');
        if (count($valid) > SecurityAudit::MAX_CODES_COUNT) throw new InvalidArgumentException('股票代码最多 20 个');
        return $valid;
    }

    private function stockCode($code): string
    {
        $code = trim((string)$code);
        if ($code === '' || strlen($code) > SecurityAudit::MAX_CODE_LENGTH || !preg_match(SecurityAudit::STOCK_CODE_PATTERN, $code)) {
            throw new InvalidArgumentException('股票代码格式不正确');
        }
        return $code;
    }

    private function fundCodes($codes): array
    {
        if (!is_array($codes)) throw new InvalidArgumentException('codes 必须是数组');
        $valid = [];
        foreach ($codes as $code) {
            $valid[] = $this->fundCode($code);
        }
        $valid = array_values(array_unique($valid));
        if (empty($valid)) throw new InvalidArgumentException('至少需要一个基金代码');
        if (count($valid) > SecurityAudit::MAX_CODES_COUNT) throw new InvalidArgumentException('基金代码最多 20 个');
        return $valid;
    }

    private function fundCode($code): string
    {
        $code = trim((string)$code);
        if (!preg_match(SecurityAudit::FUND_CODE_PATTERN, $code)) {
            throw new InvalidArgumentException('基金代码必须为 6 位数字');
        }
        return $code;
    }

    private function enum($value, array $allowed, $default)
    {
        if ($value === null || $value === '') return $default;
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('参数值不在允许范围内');
        }
        return $value;
    }

    private function int($value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') return $default;
        if (!is_numeric($value)) throw new InvalidArgumentException('参数必须是数字');
        $int = (int)$value;
        if ($int < $min || $int > $max) throw new InvalidArgumentException("参数范围必须在 {$min}-{$max}");
        return $int;
    }

    private function bool($value, bool $default): bool
    {
        if ($value === null) return $default;
        if (!is_bool($value)) throw new InvalidArgumentException('参数必须是布尔值');
        return $value;
    }

    private function date($value, bool $allowEmpty): string
    {
        $value = trim((string)$value);
        if ($value === '' && $allowEmpty) return '';
        if (!preg_match(SecurityAudit::DATE_PATTERN, $value)) {
            throw new InvalidArgumentException('日期必须为 YYYY-MM-DD');
        }
        return $value;
    }

    private function safeText($value, int $maxLength, bool $allowEmpty): string
    {
        $text = trim((string)$value);
        if ($text === '' && !$allowEmpty) throw new InvalidArgumentException('文本参数不能为空');
        if (mb_strlen($text) > $maxLength) throw new InvalidArgumentException("文本参数过长，最大 {$maxLength} 字符");
        if (preg_match('/https?:\/\/|file:\/\/|\\\\|;|\||`|\$\(/i', $text)) {
            throw new InvalidArgumentException('文本参数包含不允许的外部地址或命令字符');
        }
        return $text;
    }
}
