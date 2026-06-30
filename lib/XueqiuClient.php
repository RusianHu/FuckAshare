<?php
/**
 * XueqiuClient — 雪球数据源客户端
 *
 * 能力：
 * - /hq 预热获取匿名会话 cookie
 * - cookie jar 存放系统临时目录
 * - unsigned 请求优先
 * - challenge HTML 识别
 * - 业务失败识别
 * - 5 个产品化接口: quote / kline / hot_stock / screener / fundx
 * - 字段归一化映射
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';

class XueqiuClient
{
    const BASE_URL  = 'https://stock.xueqiu.com';
    const HQ_URL    = 'https://xueqiu.com/hq';
    const FUNDX_URL = 'https://xueqiu.com/statuses/fundx/public/list.json';

    const SOURCE_NAME = 'xueqiu';

    /** @var HttpClient */
    private $http;

    /** @var string cookie jar 路径 */
    private $cookieJar;

    /** @var bool 是否已预热 */
    private $warmed = false;

    /** @var bool 调试模式 */
    private $debug;

    /** @var CircuitBreaker 熔断器 */
    private $breaker;

    /** @var float 预热耗时 */
    public $warmDuration = 0.0;

    /**
     * @param array $opts ['debug' => false, 'timeout' => 10]
     */
    public function __construct(array $opts = [])
    {
        $this->debug    = !empty($opts['debug']);
        $this->breaker   = new CircuitBreaker('xueqiu', 3, 60);
        $this->cookieJar = HttpClient::createTempCookieJar();

        $this->http = new HttpClient([
            'timeout'        => $opts['timeout'] ?? 10,
            'connect_timeout' => 5,
            'cookie_jar'     => $this->cookieJar,
            'headers'        => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer'    => 'https://xueqiu.com/',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);
    }

    /**
     * 预热：访问 /hq 获取匿名会话 cookie
     */
    public function warmup(): bool
    {
        if ($this->warmed) {
            return true;
        }

        $start = microtime(true);

        // 先访问 /hq 页面
        $resp = $this->http->get(self::HQ_URL, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://xueqiu.com/',
        ]);

        $this->warmDuration = microtime(true) - $start;

        if ($resp['error']) {
            $this->log("预热失败: " . $resp['error']);
            return false;
        }

        // 检查是否返回了有效 cookie
        if ($this->cookieJar && file_exists($this->cookieJar)) {
            $cookieContent = file_get_contents($this->cookieJar);
            if (strpos($cookieContent, 'xq_a_token') !== false || strpos($cookieContent, 'cookie') !== false) {
                $this->warmed = true;
                $this->log("预热成功, 耗时 {$this->warmDuration}s");
                return true;
            }
        }

        // 即使没有明确 token，预热仍可能有效（某些 cookie 不含 token 名）
        $this->warmed = ($resp['http_code'] === 200);
        $this->log("预热完成 (http={$resp['http_code']}), 耗时 {$this->warmDuration}s");
        return $this->warmed;
    }

    // ── 5 个产品化接口 ──

    /**
     * quote: 个股行情详情
     */
    public function quote(string $symbol, bool $raw = false): DataSourceResult
    {
        $sc = StockCode::parse($symbol);
        if (!$sc->isValid()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'quote', 'invalid_code', "无效股票代码: {$symbol}");
        }

        $xqSymbol = $sc->toXueqiu();
        $url = self::BASE_URL . "/v5/stock/quote.json?symbol={$xqSymbol}&extend=detail";

        return $this->call('quote', $url, $raw, function($data) use ($xqSymbol) {
            return $this->normalizeQuote($data, $xqSymbol);
        });
    }

    /**
     * kline: K 线数据
     */
    public function kline(string $symbol, string $period = 'day', int $count = 120, bool $raw = false): DataSourceResult
    {
        $sc = StockCode::parse($symbol);
        if (!$sc->isValid()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'kline', 'invalid_code', "无效股票代码: {$symbol}");
        }

        $xqSymbol = $sc->toXueqiu();
        $url = self::BASE_URL . "/v5/stock/chart/kline.json?symbol={$xqSymbol}&begin="
             . (time() * 1000) . "&period={$period}&count=-{$count}&type=before";

        return $this->call('kline', $url, $raw, function($data) use ($xqSymbol) {
            return $this->normalizeKline($data, $xqSymbol);
        });
    }

    /**
     * hot_stock: 雪球热度榜
     */
    public function hot_stock(string $type = '10', int $size = 20, bool $raw = false): DataSourceResult
    {
        $url = self::BASE_URL . "/v5/stock/hot_stock/list.json?size={$size}&_type={$type}&type={$type}";

        return $this->call('hot_stock', $url, $raw, function($data) {
            return $this->normalizeHotStock($data);
        });
    }

    /**
     * screener: 条件选股
     */
    public function screener(int $page = 1, int $size = 20, string $orderBy = 'percent', string $order = 'desc', string $market = 'CN', string $type = 'sh_sz', bool $raw = false): DataSourceResult
    {
        $params = "page={$page}&size={$size}&order={$order}&order_by={$orderBy}&market={$market}";
        if ($type !== '') {
            $params .= "&type={$type}";
        }
        $url = self::BASE_URL . "/v5/stock/screener/quote/list.json?{$params}";

        return $this->call('screener', $url, $raw, function($data) {
            return $this->normalizeScreener($data);
        });
    }

    /**
     * fundx: 市场/基金动态
     */
    public function fundx(int $page = 1, string $source = '', int $lastId = 0, bool $raw = false): DataSourceResult
    {
        $params = "page={$page}";
        if ($source !== '') {
            $params .= "&source={$source}";
        }
        if ($lastId > 0) {
            $params .= "&last_id={$lastId}";
        }
        $url = self::FUNDX_URL . "?{$params}";

        return $this->call('fundx', $url, $raw, function($data) {
            return $this->normalizeFundx($data);
        });
    }

    // ── 内部调用 ──

    /**
     * 统一调用入口：预热 → 请求 → challenge 检测 → 归一化
     */
    private function call(string $action, string $url, bool $raw, callable $normalizer): DataSourceResult
    {
        // 熔断检查
        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'circuit_open', '雪球接口熔断中，暂停请求', [
                'circuit_state' => $state['state'],
                'failures'      => $state['failures'],
                'last_reason'   => $state['last_reason'] ?? '',
            ]);
        }

        // 自动预热
        if (!$this->warmed) {
            $this->warmup();
        }

        $resp = $this->http->get($url);

        // 网络错误
        if ($resp['error']) {
            $this->breaker->failure('network_error: ' . $resp['error']);
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'network_error', '网络请求失败: ' . $resp['error'], [
                'provider_status' => $resp['http_code'],
                'duration' => $this->http->lastDuration,
            ]);
        }

        // challenge HTML 检测
        if ($this->isChallengeHtml($resp['body'], $resp['content_type'])) {
            $this->breaker->failure('challenge_html');
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'challenge_html', '雪球接口返回 WAF challenge，已停止本次请求', [
                'provider_status' => $resp['http_code'],
                'duration' => $this->http->lastDuration,
            ]);
        }

        // HTTP 非 2xx
        if ($resp['http_code'] < 200 || $resp['http_code'] >= 300) {
            $this->breaker->failure('http_' . $resp['http_code']);
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'network_error', "HTTP 错误: {$resp['http_code']}", [
                'provider_status' => $resp['http_code'],
                'duration' => $this->http->lastDuration,
            ]);
        }

        // JSON 解析
        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok']) {
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'parse_error', $parsed['error'], [
                'provider_status' => $resp['http_code'],
                'duration' => $this->http->lastDuration,
            ]);
        }

        $data = $parsed['data'];

        // 业务错误检测
        if (isset($data['error_code']) && $data['error_code'] !== 0) {
            $msg = $data['error_description'] ?? "业务错误 code={$data['error_code']}";
            $this->breaker->failure('business_error: ' . $msg);
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'business_error', $msg, [
                'provider_status' => $resp['http_code'],
                'duration' => $this->http->lastDuration,
            ]);
        }

        // 成功 → 重置熔断器
        $this->breaker->success();

        // raw 模式直接返回原始数据
        if ($raw) {
            return DataSourceResult::success(self::SOURCE_NAME, $action, $data, [
                'provider_status' => $resp['http_code'],
                'duration' => $this->http->lastDuration,
            ]);
        }

        // 归一化
        $normalized = $normalizer($data);
        return DataSourceResult::success(self::SOURCE_NAME, $action, $normalized, [
            'provider_status' => $resp['http_code'],
            'duration' => $this->http->lastDuration,
        ]);
    }

    // ── challenge 检测 ──

    /**
     * 检测是否为 WAF challenge HTML
     */
    private function isChallengeHtml(string $body, string $contentType): bool
    {
        // Content-Type 为 HTML
        if (stripos($contentType, 'text/html') !== false) {
            return true;
        }

        // 包含 challenge 特征
        if (strpos($body, 'aliyunwaf_') !== false) {
            return true;
        }
        if (strpos($body, 'renderData') !== false && strpos($body, '<html') !== false) {
            return true;
        }

        // 以 <!DOCTYPE 或 <html 开头（非 JSON 响应）
        $trimmed = ltrim($body);
        if (preg_match('/^(<!DOCTYPE|<html)/i', $trimmed)) {
            return true;
        }

        return false;
    }

    // ── 字段归一化 ──

    /**
     * quote 归一化
     */
    private function normalizeQuote(array $raw, string $xqSymbol): array
    {
        $q = $raw['data']['quote'] ?? $raw['data'] ?? $raw;

        return [
            'code'          => $q['code'] ?? '',
            'symbol'        => $q['symbol'] ?? $xqSymbol,
            'name'          => $q['name'] ?? '',
            'price'         => $q['current'] ?? 0,
            'change_pct'    => $q['percent'] ?? 0,
            'change_amt'    => $q['chg'] ?? 0,
            'open'          => $q['open'] ?? 0,
            'high'          => $q['high'] ?? 0,
            'low'           => $q['low'] ?? 0,
            'prev_close'    => $q['last_close'] ?? 0,
            'volume'        => $q['volume'] ?? 0,
            'amount'        => $q['amount'] ?? 0,
            'turnover_rate' => $q['turnover_rate'] ?? 0,
            'volume_ratio'  => $q['volume_ratio'] ?? 0,
            'pe_ttm'        => $q['pe_ttm'] ?? 0,
            'pb'            => $q['pb'] ?? 0,
            'total_mv'      => $q['market_capital'] ?? 0,
            'circ_mv'       => $q['float_market_capital'] ?? 0,
            'quote_time'    => isset($q['timestamp']) ? date('c', (int)floor($q['timestamp'] / 1000)) : '',
            'source'        => self::SOURCE_NAME,
        ];
    }

    /**
     * kline 归一化
     */
    private function normalizeKline(array $raw, string $xqSymbol): array
    {
        $data   = $raw['data'] ?? $raw;
        $column = $data['column'] ?? [];
        $items  = $data['item'] ?? [];

        // 列名映射
        $colMap = [];
        foreach ($column as $i => $col) {
            $colMap[$col] = $i;
        }

        $result = [];
        foreach ($items as $item) {
            $row = [];
            $row['time']          = isset($colMap['timestamp'], $item[$colMap['timestamp']])
                ? date('Y-m-d', (int)floor($item[$colMap['timestamp']] / 1000)) : '';
            $row['open']          = $item[$colMap['open'] ?? 2] ?? 0;
            $row['close']         = $item[$colMap['close'] ?? 5] ?? 0;
            $row['high']          = $item[$colMap['high'] ?? 3] ?? 0;
            $row['low']           = $item[$colMap['low'] ?? 4] ?? 0;
            $row['volume']        = $item[$colMap['volume'] ?? 1] ?? 0;
            $row['amount']        = $item[$colMap['amount'] ?? 9] ?? 0;
            $row['change_pct']    = $item[$colMap['percent'] ?? 7] ?? 0;
            $row['turnover_rate'] = $item[$colMap['turnoverrate'] ?? 8] ?? 0;
            $row['source']        = self::SOURCE_NAME;
            $result[] = $row;
        }

        return [
            'symbol' => $xqSymbol,
            'count'  => count($result),
            'data'   => $result,
        ];
    }

    /**
     * hot_stock 归一化
     */
    private function normalizeHotStock(array $raw): array
    {
        $items = $raw['data']['items'] ?? $raw['data'] ?? [];

        $result = [];
        foreach ($items as $item) {
            $code = $item['code'] ?? $item['symbol'] ?? '';

            $result[] = [
                'symbol'        => $code,
                'code'          => strtolower($code),
                'name'          => $item['name'] ?? '',
                'exchange'      => $item['exchange'] ?? '',
                'price'         => $item['current'] ?? 0,
                'change_pct'    => $item['percent'] ?? 0,
                'change_amt'    => $item['chg'] ?? 0,
                'hot_value'     => $item['value'] ?? 0,
                'hot_increment' => $item['increment'] ?? 0,
                'rank_change'   => $item['rank_change'] ?? 0,
                'source'        => self::SOURCE_NAME,
            ];
        }

        return $result;
    }

    /**
     * screener 归一化
     */
    private function normalizeScreener(array $raw): array
    {
        $list = $raw['data']['list'] ?? $raw['data'] ?? [];

        $result = [];
        foreach ($list as $item) {
            $sym = $item['symbol'] ?? '';
            $result[] = [
                'symbol'             => $sym,
                'code'               => strtolower($sym),
                'name'               => $item['name'] ?? '',
                'price'              => $item['current'] ?? 0,
                'change_pct'         => $item['percent'] ?? 0,
                'change_amt'         => $item['chg'] ?? 0,
                'volume'             => $item['volume'] ?? 0,
                'amount'             => $item['amount'] ?? 0,
                'turnover_rate'      => $item['turnover_rate'] ?? 0,
                'volume_ratio'       => $item['volume_ratio'] ?? 0,
                'market_capital'     => $item['market_capital'] ?? 0,
                'float_market_capital' => $item['float_market_capital'] ?? 0,
                'pe_ttm'             => $item['pe_ttm'] ?? 0,
                'pb'                 => $item['pb'] ?? 0,
                'roe_ttm'            => $item['roe_ttm'] ?? 0,
                'dividend_yield'     => $item['dividend_yield'] ?? 0,
                'followers'          => $item['followers'] ?? 0,
                'limitup_days'       => $item['limitup_days'] ?? 0,
                'source'             => self::SOURCE_NAME,
            ];
        }

        return [
            'total' => $raw['data']['count'] ?? count($result),
            'data'  => $result,
        ];
    }

    /**
     * fundx 归一化
     */
    private function normalizeFundx(array $raw): array
    {
        $list = $raw['list'] ?? $raw['data']['items'] ?? $raw['data'] ?? [];
        $hasNext = $raw['has_next_page'] ?? false;

        $result = [];
        foreach ($list as $item) {
            // 优先 rawTitle → title，内容取 description → text（去 HTML）
            $title = $item['rawTitle'] ?? $item['title'] ?? '';
            $desc  = $item['description'] ?? $item['text'] ?? '';
            $desc  = strip_tags($desc);

            $result[] = [
                'id'              => $item['id'] ?? $item['target'] ?? '',
                'title'           => $title,
                'description'     => mb_substr($desc, 0, 300),
                'created_at'      => isset($item['created_at'])
                    ? date('c', (int)floor($item['created_at'] / 1000)) : '',
                'author_name'     => $item['user']['screen_name'] ?? $item['screen_name'] ?? '',
                'followers_count' => $item['user']['followers_count'] ?? 0,
                'like_count'      => $item['like_count'] ?? 0,
                'reply_count'     => $item['reply_count'] ?? 0,
                'retweet_count'   => $item['retweet_count'] ?? 0,
                'fav_count'       => $item['fav_count'] ?? 0,
                'view_count'      => $item['view_count'] ?? 0,
                'target'          => $item['target'] ?? '',
                'source'          => self::SOURCE_NAME,
            ];
        }

        return [
            'data'          => $result,
            'has_next_page' => $hasNext,
        ];
    }

    // ── 辅助 ──

    private function log(string $msg): void
    {
        if ($this->debug) {
            error_log("[XueqiuClient] {$msg}");
        }
    }

    public function __destruct()
    {
        $this->http->cleanup();
    }
}
