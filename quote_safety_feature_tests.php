<?php
/**
 * 场内行情时间与 ETF 报价安全回归。
 *
 * 运行：.\php\php.exe quote_safety_feature_tests.php
 */

require_once __DIR__ . '/lib/EastmoneyClient.php';
require_once __DIR__ . '/lib/XueqiuClient.php';

class QuoteSafetyHttpClient extends HttpClient
{
    public $requestedUrl = '';
    private $quoteTimestamp;

    public function __construct(int $quoteTimestamp)
    {
        parent::__construct();
        $this->quoteTimestamp = $quoteTimestamp;
    }

    public function get(string $url, array $headers = []): array
    {
        $this->requestedUrl = $url;
        $this->lastDuration = 0.01;
        return [
            'body' => json_encode([
                'data' => [
                    'diff' => [[
                        'f2' => 1.424,
                        'f3' => 2.01,
                        'f12' => '515450',
                        'f13' => 1,
                        'f14' => '红利低波50ETF南方',
                        'f124' => $this->quoteTimestamp,
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'http_code' => 200,
            'error' => null,
            'content_type' => 'application/json',
        ];
    }
}

$passed = 0;
$failed = 0;
function checkQuoteSafety($condition, string $message): void
{
    global $passed, $failed;
    if ($condition) $passed++;
    else {
        $failed++;
        echo "[FAIL] {$message}\n";
    }
}

$timestamp = time() - 5;
$http = new QuoteSafetyHttpClient($timestamp);
$breaker = new CircuitBreaker('quote_safety_test', 99, 1);
$result = (new EastmoneyClient($http, $breaker))->quote(['sh515450']);
$item = $result->data[0] ?? [];
$expectedAt = (new DateTimeImmutable('@' . $timestamp))
    ->setTimezone(new DateTimeZone('Asia/Shanghai'))
    ->format('Y-m-d H:i:s');

checkQuoteSafety($result->hasData(), '场内 ETF 应返回交易所行情');
checkQuoteSafety(strpos($http->requestedUrl, 'f124') !== false, '行情请求必须索取上游更新时间字段 f124');
checkQuoteSafety(($item['code'] ?? '') === '515450', '返回 ETF 代码正确');
checkQuoteSafety(($item['price'] ?? null) === 1.424, '返回 ETF 交易价格正确');
checkQuoteSafety(($item['quote_time'] ?? '') === $expectedAt, '单项必须携带真实行情时间');
checkQuoteSafety(($result->meta['data_at'] ?? '') === $expectedAt, '批次必须携带真实行情时间');
checkQuoteSafety(($result->meta['data_kind'] ?? '') === 'exchange_quote', '场内价格必须明确标成交易所行情');
checkQuoteSafety(($result->meta['non_realtime_count'] ?? -1) === 0, '当日行情不得误报为非实时');

$xueqiu = new XueqiuClient();
$normalizeXueqiuQuote = new ReflectionMethod(XueqiuClient::class, 'normalizeQuote');
$normalizeXueqiuQuote->setAccessible(true);
$xueqiuItem = $normalizeXueqiuQuote->invoke($xueqiu, [
    'data' => [
        'quote' => [
            'code' => '515450',
            'symbol' => 'SH515450',
            'name' => '红利低波50ETF南方',
            'current' => 1.424,
            'timestamp' => $timestamp * 1000,
        ],
    ],
], 'SH515450');
checkQuoteSafety(($xueqiuItem['quote_time'] ?? '') === $expectedAt, '雪球备用源行情时间也必须显式转换为上海时区');

$missingTimeResult = (new EastmoneyClient(
    new QuoteSafetyHttpClient(0),
    new CircuitBreaker('quote_safety_missing_time_test', 99, 1)
))->quote(['sh515450']);
$missingTimeEnvelope = $missingTimeResult->toEnvelope();
checkQuoteSafety(($missingTimeResult->data[0]['quote_time'] ?? 'not-empty') === '', '上游不提供行情时间时不得伪造本机接收时间');
checkQuoteSafety(($missingTimeResult->meta['data_recency'] ?? '') === 'unknown', '行情时间缺失时内容时效必须标成未知');
checkQuoteSafety(($missingTimeEnvelope['meta']['data_status']['severity'] ?? '') === 'warning', '行情时间缺失必须触发可见警告');

echo "\n场内行情安全测试: {$passed} 通过, {$failed} 失败\n";
exit($failed > 0 ? 1 : 0);
