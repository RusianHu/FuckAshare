<?php
/**
 * 股票关键词搜索特性测试。
 *
 * 用法：
 *   php stock_search_feature_tests.php
 *   php stock_search_feature_tests.php --live
 *   php stock_search_feature_tests.php --loopback
 */

require_once __DIR__ . '/lib/StockSearchService.php';
require_once __DIR__ . '/lib/FileCacheStore.php';
require_once __DIR__ . '/lib/MarketDataService.php';

$passed = 0;
$failed = 0;

function checkStockSearch($condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
    } else {
        $failed++;
        echo "[FAIL] {$message}\n";
    }
}

class StockSearchFakeHttp
{
    public $calls = 0;
    private $body;

    public function __construct(array $rows)
    {
        $this->body = json_encode([
            'QuotationCodeTable' => [
                'Data' => $rows,
                'Status' => 0,
                'Message' => '成功',
                'TotalCount' => count($rows),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function get(string $url, array $headers = []): array
    {
        $this->calls++;
        return ['body' => $this->body, 'http_code' => 200, 'error' => null, 'content_type' => 'application/json'];
    }
}

$rows = [
    ['Code'=>'601872','Name'=>'招商轮船','PinYin'=>'ZSLC','Classify'=>'AStock','SecurityTypeName'=>'沪A','QuoteID'=>'1.601872'],
    ['Code'=>'600036','Name'=>'招商银行','PinYin'=>'ZSYH','Classify'=>'AStock','SecurityTypeName'=>'沪A','QuoteID'=>'1.600036'],
    ['Code'=>'600000','Name'=>'XD浦发银行','PinYin'=>'PFYH','Classify'=>'AStock','SecurityTypeName'=>'沪A','QuoteID'=>'1.600000'],
    ['Code'=>'920001','Name'=>'北交测试','PinYin'=>'BJCS','Classify'=>'AStock','SecurityTypeName'=>'北A','QuoteID'=>'0.920001'],
    ['Code'=>'03968','Name'=>'招商银行','PinYin'=>'ZSYH','Classify'=>'HK','SecurityTypeName'=>'港股','QuoteID'=>'116.03968'],
    ['Code'=>'159003','Name'=>'招商快线ETF','PinYin'=>'ZSKXETF','Classify'=>'Fund','SecurityTypeName'=>'基金','QuoteID'=>'0.159003'],
    ['Code'=>'200024','Name'=>'招商局B','PinYin'=>'ZSJB','Classify'=>'BStock','SecurityTypeName'=>'深B','QuoteID'=>'0.200024'],
];

$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fa_stock_search_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
$fakeHttp = new StockSearchFakeHttp($rows);
$service = new StockSearchService($fakeHttp, new FileCacheStore($cacheDir));

$search = $service->search('招商', 10);
checkStockSearch($search->success, '关键词搜索成功');
checkStockSearch(count((array)$search->data) === 2, '搜索过滤港股、基金、B 股与无直接相关性的宽泛候选');
checkStockSearch(($search->data[0]['symbol'] ?? '') === 'sh601872', '名称前缀候选保留上游稳定顺序');

$northSearch = $service->search('北交', 10);
checkStockSearch($northSearch->success && ($northSearch->data[0]['symbol'] ?? '') === 'bj920001', '北交所代码归一化为 bj 前缀');

$callsBeforeCachedSearch = $fakeHttp->calls;
$cached = $service->search('招商', 10);
checkStockSearch($cached->success && $fakeHttp->calls === $callsBeforeCachedSearch, '相同关键词命中缓存，不重复访问上游');

$exactName = $service->resolve('招商银行');
checkStockSearch($exactName->success && ($exactName->data['symbol'] ?? '') === 'sh600036', '股票全名精确解析为稳定代码');

$exactPinyin = $service->resolve('zsyh');
checkStockSearch($exactPinyin->success && ($exactPinyin->data['symbol'] ?? '') === 'sh600036', '拼音首字母不区分大小写精确解析');

$corporateActionName = $service->resolve('浦发银行');
checkStockSearch($corporateActionName->success && ($corporateActionName->data['symbol'] ?? '') === 'sh600000', 'XD/XR/DR 等公司行动前缀不破坏精确名称匹配');

$ambiguous = $service->resolve('招商');
checkStockSearch(!$ambiguous->success && $ambiguous->errorCode === 'ambiguous_stock', '模糊多候选不会静默选择第一只股票');
checkStockSearch(count((array)($ambiguous->meta['candidates'] ?? [])) >= 2, '歧义响应携带可供前端选择的安全候选');

$direct = $service->resolve('BJ920001');
checkStockSearch($direct->success && ($direct->data['symbol'] ?? '') === 'bj920001', '沪深北显式前缀代码直接解析且不依赖搜索上游');

$badPayload = $service->parsePayload('{broken', '招商');
checkStockSearch($badPayload === null, '损坏的上游 JSON 被明确拒绝');

$html = file_get_contents(__DIR__ . '/index.php');
$js = file_get_contents(__DIR__ . '/main.js');
$css = file_get_contents(__DIR__ . '/style.css');
checkStockSearch(strpos($html, 'role="combobox"') !== false && strpos($html, 'stock-search-results') !== false, '股票输入框具备可访问的联想搜索结构');
checkStockSearch(strpos($js, 'const StockSearchModule') !== false && strpos($js, 'ambiguous_stock') === false, '前端使用统一候选模块并由后端负责歧义判定');
checkStockSearch(strpos($js, 'data.candidates || data.meta?.candidates') !== false, '行情 API 的歧义候选会回显供用户选择');
checkStockSearch(strpos($js, 'resolvedStock.name || selectedStockName') !== false, '候选选中后名称与稳定代码会一起贯穿行情上下文');
checkStockSearch(strpos($js, "this.input.form?.dispatchEvent(new Event('submit'))") !== false, '点击或键盘选中股票候选后立即触发行情查询');
checkStockSearch(strpos($js, "normalizeCode(quote.symbol || quote.code || '')") !== false, '实时行情卡片优先展示带市场前缀的完整股票代码');
checkStockSearch(strpos($js, "normalizeCode(s.symbol || s.code || '')") !== false, '实时看板统一展示并使用完整股票代码');
checkStockSearch(strpos($css, '.stock-search-results[hidden]') !== false && strpos($css, 'max-height: 46vh') !== false, '候选层具备显隐优先级与移动端高度约束');

if (in_array('--live', $argv, true)) {
    echo "\n[LIVE] 开始真实股票搜索与 K 线调用...\n";
    $liveService = new StockSearchService();
    $liveSearch = $liveService->search('招商', 10);
    checkStockSearch($liveSearch->success && count((array)$liveSearch->data) >= 2, '真实东方财富关键词搜索返回多只 A 股候选');
    $liveName = $liveService->resolve('招商银行');
    checkStockSearch($liveName->success && ($liveName->data['symbol'] ?? '') === 'sh600036', '真实股票名称解析为招商银行 sh600036');
    $livePinyin = $liveService->resolve('zsyh');
    $pinyinCandidates = (array)($livePinyin->meta['candidates'] ?? []);
    $pinyinSymbols = array_column($pinyinCandidates, 'symbol');
    checkStockSearch(!$livePinyin->success
        && $livePinyin->errorCode === 'ambiguous_stock'
        && in_array('sh600036', $pinyinSymbols, true)
        && in_array('sh601916', $pinyinSymbols, true), '真实同音首字母 zsyh 安全识别招商银行/浙商银行歧义');
    if ($liveName->success) {
        $market = new MarketDataService();
        $kline = $market->kline($liveName->data['symbol'], '1d', 5, '', MarketDataService::SOURCE_AUTO, true, false);
        checkStockSearch($kline->hasData() && count((array)$kline->data) > 0, '解析后的稳定代码完成真实 K 线调用');
    }
}

if (in_array('--loopback', $argv, true)) {
    echo "\n[LOOPBACK] 开始本地主站 API 调用...\n";
    $client = new HttpClient(['timeout' => 25, 'connect_timeout' => 3]);
    $searchResp = $client->get('http://127.0.0.1:8081/stock_search_api.php?key=' . urlencode('招商') . '&limit=10');
    $searchJson = json_decode($searchResp['body'] ?? '', true);
    checkStockSearch(($searchResp['http_code'] ?? 0) === 200 && ($searchJson['success'] ?? false) && count((array)($searchJson['data'] ?? [])) >= 2, 'loopback 股票搜索 API 返回候选');

    $nameResp = $client->get('http://127.0.0.1:8081/api.php?code=' . urlencode('招商银行') . '&frequency=1d&count=5&source=auto');
    $nameJson = json_decode($nameResp['body'] ?? '', true);
    checkStockSearch(($nameResp['http_code'] ?? 0) === 200 && ($nameJson['success'] ?? false) && (($nameJson['stock']['symbol'] ?? '') === 'sh600036'), 'loopback 行情 API 以名称解析并返回真实 K 线');

    $ambiguousResp = $client->get('http://127.0.0.1:8081/api.php?code=' . urlencode('招商') . '&frequency=1d&count=5&source=auto');
    $ambiguousJson = json_decode($ambiguousResp['body'] ?? '', true);
    checkStockSearch(($ambiguousJson['success'] ?? true) === false && ($ambiguousJson['code'] ?? '') === 'ambiguous_stock' && count((array)($ambiguousJson['candidates'] ?? [])) >= 2, 'loopback 行情 API 拒绝模糊多候选并返回候选');
}

echo "\n股票搜索测试完成：{$passed} 通过，{$failed} 失败。\n";
exit($failed > 0 ? 1 : 0);
