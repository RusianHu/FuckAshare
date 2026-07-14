<?php
/**
 * 基金分红日历特性测试。
 *
 * 用法：
 *   .\php\php.exe fund_dividend_feature_tests.php
 *   .\php\php.exe fund_dividend_feature_tests.php --live
 *   .\php\php.exe fund_dividend_feature_tests.php --loopback
 */

require_once __DIR__ . '/lib/FundDividendService.php';
require_once __DIR__ . '/lib/FundService.php';
require_once __DIR__ . '/lib/EastmoneyFundDividendClient.php';
require_once __DIR__ . '/lib/AIToolExecutor.php';
require_once __DIR__ . '/lib/AIFinanceToolCatalog.php';
require_once __DIR__ . '/lib/AppConfig.php';
require_once __DIR__ . '/lib/AIAgentStreamEmitter.php';

// 测试日期统一使用 Asia/Shanghai 时区，与服务层一致
date_default_timezone_set('Asia/Shanghai');

// ── Fake Provider ──
class FakeFundDividendProvider implements FundDividendDataProvider
{
    public $calls = 0;
    public $typeCalls = 0;
    public $fail = false;
    public $failTypeMap = false;
    public $rows;

    public function __construct(array $rows) { $this->rows = $rows; }

    public function sourceName(): string { return 'fake_fund_dividend'; }

    public function calendar(string $startDate, string $endDate): DataSourceResult
    {
        $this->calls++;
        if ($this->fail) return DataSourceResult::error($this->sourceName(), 'fund_dividend_calendar_raw', 'network_error', 'fake failure');
        $in = array_values(array_filter($this->rows, function ($r) use ($startDate, $endDate) {
            return ($r['record_date'] ?? '') >= $startDate && ($r['record_date'] ?? '') <= $endDate;
        }));
        return DataSourceResult::success($this->sourceName(), 'fund_dividend_calendar_raw', $in, [
            'provider_status' => 200, 'provider_count' => count($in), 'pages' => 1, 'truncated' => false, 'failures' => [],
        ]);
    }

    public function fundTypeMap(): DataSourceResult
    {
        $this->typeCalls++;
        if ($this->failTypeMap) return DataSourceResult::error($this->sourceName(), 'fund_type_map', 'network_error', 'fake type map failure');
        return DataSourceResult::success($this->sourceName(), 'fund_type_map', [
            '000001' => 'mixed', '000002' => 'mixed', '510500' => 'index', '011973' => 'index',
            '013087' => 'bond', '012762' => 'index', '561580' => 'index', '159001' => 'stock',
            '000009' => 'money', '900001' => 'qdii',
        ], ['provider_status' => 200, 'provider_count' => 10]);
    }
}

// ── Fake FundService（覆盖基金分红日历用到的 batchNetValues / dividendHistory / fundDocuments / dividendProfile / info）──
class FakeFundService extends FundService
{
    public $navMap;
    public $historyMap;
    public $announcements;
    public $annFail = false;
    public $relatedFunds = [];
    public $infoNames = [];
    public $windowRows = [];

    public function __construct() {}

    public function batchNetValues(array $codes): DataSourceResult
    {
        $items = [];
        foreach ($codes as $code) {
            if (isset($this->navMap[$code])) $items[] = array_merge(['code' => $code, 'name' => '基金' . $code], $this->navMap[$code]);
        }
        if (empty($items)) return DataSourceResult::error(self::SOURCE_NAME, 'nav_batch', 'empty_data', '无净值');
        return DataSourceResult::success(self::SOURCE_NAME, 'nav_batch', $items, ['requested' => count($codes), 'returned' => count($items), 'failures' => []]);
    }

    public function historyWindow(string $code, string $sdate, string $edate): DataSourceResult
    {
        $rows = array_values(array_filter($this->windowRows, function ($r) use ($sdate, $edate) {
            $d = $r['date'] ?? '';
            return $d >= $sdate && $d <= $edate;
        }));
        return DataSourceResult::success(self::SOURCE_NAME, 'history_window', $rows, ['code' => $code, 'sdate' => $sdate, 'edate' => $edate, 'records' => count($rows)]);
    }

    public function dividendHistory(string $code, int $page = 1, int $pageSize = 100): DataSourceResult
    {
        $rows = $this->historyMap[$code] ?? [];
        return DataSourceResult::success(self::SOURCE_NAME, 'dividend_history', $rows, ['code' => $code, 'records' => count($rows)]);
    }

    public function fundDocuments(string $code, int $page = 1, int $pageSize = 20, string $docType = 'all', bool $includeContent = false, int $contentLimit = 6000): DataSourceResult
    {
        if ($this->annFail) return DataSourceResult::error(self::SOURCE_NAME, 'documents', 'network_error', '公告读取失败');
        $items = $this->announcements[$code] ?? [];
        return DataSourceResult::success(self::SOURCE_NAME, 'documents', $items, ['code' => $code, 'records' => count($items)]);
    }

    public function dividendProfile(string $code, int $limit = 10, bool $includeRelated = true, bool $includeAnnouncements = true, int $announcementLimit = 5): DataSourceResult
    {
        return DataSourceResult::success('fund_dividend_profile', 'dividend_profile', [
            'query_fund' => ['code' => $code, 'name' => $this->infoNames[$code] ?? ('基金' . $code)],
            'related_funds' => $this->relatedFunds,
        ], ['code' => $code]);
    }

    public function info(array $codes): DataSourceResult
    {
        $funds = [];
        foreach ($codes as $code) {
            $name = $this->infoNames[$code] ?? ('基金' . $code);
            $funds[] = ['code' => $code, 'name' => $name, 'full_name' => $name . '全称', 'type' => '指数型-股票', 'fund_company' => '某基金公司', 'nav' => $this->navMap[$code]['nav'] ?? null, 'nav_date' => $this->navMap[$code]['nav_date'] ?? '', 'acc_nav' => null];
        }
        return DataSourceResult::success(self::SOURCE_NAME, 'info', $funds, ['total' => count($funds)]);
    }
}

// ── 内存缓存 ──
class FundDividendMemoryCache implements CacheStore
{
    public $fresh = [];
    public $stale = [];
    public function get(string $key): ?array { return $this->fresh[$key] ?? null; }
    public function set(string $key, array $data, int $ttl): void { $this->fresh[$key] = $data; }
    public function delete(string $key): void { unset($this->fresh[$key], $this->stale[$key]); }
    public function getStale(string $key): ?array { return $this->stale[$key] ?? null; }
    public function acquireLock(string $lockKey, int $timeout = 5): bool { return true; }
    public function releaseLock(string $lockKey): void {}
    public function backendName(): string { return 'memory'; }
    public function ping(): bool { return true; }
}

// ── 分页/类型映射 HTTP fixture ──
class FundDividendPagedHttpClient extends HttpClient
{
    public $calls = 0;
    public $fail = false;
    public $failTypeMap = false;
    public $malformedList = false;
    public $yearRows = [];
    public $urls = [];
    public function __construct() {}
    public function get(string $url, array $headers = []): array
    {
        $this->calls++;
        $this->urls[] = $url;
        $this->lastDuration = 0.001;
        if ($this->fail) return ['body' => '', 'http_code' => 503, 'error' => 'fake upstream failure', 'headers' => []];
        if (strpos($url, 'fundcode_search.js') !== false) {
            if ($this->failTypeMap) return ['body' => 'garbage', 'http_code' => 200, 'error' => '', 'headers' => []];
            $rows = [["000001","HX","华夏成长混合","混合型-灵活","HX"],["510500","ZZ","中证500ETF南方","指数型-股票","ZZ"]];
            return ['body' => "\xEF\xBB\xBFvar r = " . json_encode($rows) . ";", 'http_code' => 200, 'error' => '', 'headers' => []];
        }
        // dt=8 分红列表
        if ($this->malformedList) return ['body' => '<html>upstream interception</html>', 'http_code' => 200, 'error' => '', 'headers' => []];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $page = (int)($q['page'] ?? 1);
        $rows = $this->yearRows[$page] ?? [];
        $totalPages = count(array_filter($this->yearRows, function ($r) { return !empty($r); }));
        $body = "var pageinfo = [{$totalPages}, 100, {$page}]; var jjfh_data=" . json_encode($rows) . ";";
        return ['body' => $body, 'http_code' => 200, 'error' => '', 'headers' => []];
    }
}

$passed = 0;
$failed = 0;
function check($condition, string $message): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "[PASS] {$message}\n"; }
    else { $failed++; echo "[FAIL] {$message}\n"; }
}
function near($actual, $expected, float $epsilon = 0.0001): bool { return is_numeric($actual) && abs((float)$actual - $expected) <= $epsilon; }

// ── 测试数据 ──
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayAfter = date('Y-m-d', strtotime('+2 days'));
$pastEx = date('Y-m-d', strtotime('-5 days'));
$pastExNav = date('Y-m-d', strtotime('-6 days'));

$rows = [
    ['code' => '510500', 'name' => '中证500ETF南方', 'record_date' => $today, 'ex_date' => $dayAfter, 'cash_per_unit' => 0.05, 'pay_date' => date('Y-m-d', strtotime('+5 days')), 'source_flag' => '', 'year' => date('Y'), 'source' => 'fake_fund_dividend', 'source_url' => 'https://fundf10.eastmoney.com/fhsp_510500.html'],
    ['code' => '011973', 'name' => '新华中债1-5年农发行A', 'record_date' => $tomorrow, 'ex_date' => $tomorrow, 'cash_per_unit' => 0.052, 'pay_date' => date('Y-m-d', strtotime('+4 days')), 'source_flag' => '', 'year' => date('Y'), 'source' => 'fake_fund_dividend', 'source_url' => ''],
    ['code' => '013087', 'name' => '中加优悦一年定开债券', 'record_date' => $today, 'ex_date' => $today, 'cash_per_unit' => 0.007, 'pay_date' => date('Y-m-d', strtotime('+3 days')), 'source_flag' => '', 'year' => date('Y'), 'source' => 'fake_fund_dividend', 'source_url' => ''],
    ['code' => '900001', 'name' => '某港股通美元债QDII', 'record_date' => $tomorrow, 'ex_date' => $tomorrow, 'cash_per_unit' => 0.01, 'pay_date' => '', 'source_flag' => '', 'year' => date('Y'), 'source' => 'fake_fund_dividend', 'source_url' => ''],
    ['code' => '561580', 'name' => '央企红利ETF华泰柏瑞', 'record_date' => $dayAfter, 'ex_date' => date('Y-m-d', strtotime('+3 days')), 'cash_per_unit' => 0.005, 'pay_date' => date('Y-m-d', strtotime('+6 days')), 'source_flag' => '', 'year' => date('Y'), 'source' => 'fake_fund_dividend', 'source_url' => ''],
    ['code' => '000001', 'name' => '华夏成长混合', 'record_date' => $pastEx, 'ex_date' => $pastEx, 'cash_per_unit' => 0.03, 'pay_date' => date('Y-m-d', strtotime('-3 days')), 'source_flag' => '', 'year' => date('Y'), 'source' => 'fake_fund_dividend', 'source_url' => ''],
];

$provider = new FakeFundDividendProvider($rows);
$navMap = [
    '510500' => ['nav' => 8.0, 'nav_date' => date('Y-m-d', strtotime('-1 day')), 'acc_nav' => 2.7, 'nav_chg_rate' => 0.5],
    '011973' => ['nav' => 1.07, 'nav_date' => date('Y-m-d', strtotime('-1 day')), 'acc_nav' => 1.07, 'nav_chg_rate' => 0.1],
    '013087' => ['nav' => 1.01, 'nav_date' => date('Y-m-d', strtotime('-1 day')), 'acc_nav' => 1.01, 'nav_chg_rate' => 0.0],
    '561580' => ['nav' => 1.16, 'nav_date' => date('Y-m-d', strtotime('-1 day')), 'acc_nav' => 1.29, 'nav_chg_rate' => 0.2],
    '000001' => ['nav' => 1.50, 'nav_date' => $pastExNav, 'acc_nav' => 1.50, 'nav_chg_rate' => 0.0],
];
$fund = new FakeFundService();
$fund->navMap = $navMap;
$fund->historyMap = [
    '510500' => [
        ['record_date' => $today, 'ex_date' => $dayAfter, 'pay_date' => date('Y-m-d', strtotime('+5 days')), 'cash_per_unit' => 0.05, 'event_stage' => 'upcoming_ex', 'sources' => ['fake']],
        ['record_date' => date('Y-m-d', strtotime('-30 days')), 'ex_date' => date('Y-m-d', strtotime('-30 days')), 'pay_date' => date('Y-m-d', strtotime('-27 days')), 'cash_per_unit' => 0.04, 'event_stage' => 'completed', 'sources' => ['fake']],
    ],
];
$fund->announcements = [
    '510500' => [
        ['title' => '中证500ETF南方收益分配公告', 'date' => date('Y-m-d', strtotime('-2 days')), 'url' => 'https://example.com/ann1', 'pdf_url' => '', 'content' => '本次每份派现金0.0500元，权益登记日' . $today . '，除息日' . $dayAfter . '。'],
        ['title' => '无关公告', 'date' => date('Y-m-d', strtotime('-10 days')), 'url' => 'https://example.com/ann2', 'pdf_url' => '', 'content' => '其他事项。'],
    ],
];
$fund->windowRows = [
    ['date' => date('Y-m-d', strtotime('-3 days')), 'nav' => 7.95, 'acc_nav' => 2.65, 'growth_rate' => 0.3],
    ['date' => date('Y-m-d', strtotime('-2 days')), 'nav' => 7.98, 'acc_nav' => 2.68, 'growth_rate' => 0.4],
    ['date' => date('Y-m-d', strtotime('-1 days')), 'nav' => 8.0, 'acc_nav' => 2.7, 'growth_rate' => 0.25],
    ['date' => $dayAfter, 'nav' => 7.95, 'acc_nav' => 2.65, 'growth_rate' => -0.625],
];

$cache = new FundDividendMemoryCache();
$svc = new FundDividendService($provider, $fund, $cache);
$baseOptions = ['start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+6 days')), 'page_size' => 100];

// ── 解析：东方财富客户端 ──
$pagedHttp = new FundDividendPagedHttpClient();
$pageOneRows = [];
for ($n = 0; $n < 100; $n++) {
    $pageOneRows[] = [sprintf('%06d', 100000 + $n), '分页样例' . $n, $today, $dayAfter, '0.01', date('Y-m-d', strtotime('+5 days')), ''];
}
$pagedHttp->yearRows = [
    1 => $pageOneRows,
    2 => [['510500', '中证500ETF南方', $tomorrow, $dayAfter, '0.05', date('Y-m-d', strtotime('+5 days')), '']],
];
$pagedClient = new EastmoneyFundDividendClient($pagedHttp, new CircuitBreaker('fund_test_pages_' . uniqid('', true), 3, 60));
$parsed = $pagedClient->calendar($today, $dayAfter);
check($parsed->success && count($parsed->data) === 101 && in_array('510500', array_column($parsed->data, 'code'), true), '东方财富客户端按总页数合并多页 jjfh_data');
check(($parsed->meta['pages'] ?? 0) === 2 && $pagedHttp->calls === 2, 'pageinfo[0] 按总页数解释且读取第 2 页');
$firstQuery = [];
parse_str((string)parse_url($pagedHttp->urls[0] ?? '', PHP_URL_QUERY), $firstQuery);
check(($firstQuery['rank'] ?? '') === 'DJR' && ($firstQuery['sort'] ?? '') === 'desc', '事件请求显式固定登记日倒序契约');
$normalizedTarget = null;
foreach ($parsed->data as $event) { if (($event['code'] ?? '') === '510500') { $normalizedTarget = $event; break; } }
check(($normalizedTarget['record_date'] ?? '') === $tomorrow && near($normalizedTarget['cash_per_unit'] ?? null, 0.05), '客户端字段标准化 record_date/ex_date/cash_per_unit');
$tm = $pagedClient->fundTypeMap();
check($tm->success && ($tm->data['510500'] ?? '') === 'index' && ($tm->data['000001'] ?? '') === 'mixed', '客户端解析 fundcode_search.js 类型映射并归一');

// 空年度
$emptyHttp = new FundDividendPagedHttpClient();
$emptyHttp->yearRows = [1 => []];
$emptyClient = new EastmoneyFundDividendClient($emptyHttp, new CircuitBreaker('fund_test_empty_' . uniqid('', true), 3, 60));
$emptyRes = $emptyClient->calendar('2026-01-01', '2026-01-10');
check($emptyRes->success && count($emptyRes->data) === 0, '空年度返回空结果而非解析失败');

// 网络失败与 HTTP 200 畸形响应
$malformedHttp = new FundDividendPagedHttpClient();
$malformedHttp->fail = true;
$malformedClient = new EastmoneyFundDividendClient($malformedHttp, new CircuitBreaker('fund_test_malformed_' . uniqid('', true), 3, 60));
$malformedRes = $malformedClient->calendar($today, $dayAfter);
check(!$malformedRes->success && $malformedRes->errorCode === 'network_error', '畸形/失败响应返回错误');
$malformed200Http = new FundDividendPagedHttpClient();
$malformed200Http->malformedList = true;
$malformed200Client = new EastmoneyFundDividendClient($malformed200Http, new CircuitBreaker('fund_test_malformed_200_' . uniqid('', true), 3, 60));
$malformed200Res = $malformed200Client->calendar($today, $dayAfter);
check(!$malformed200Res->success && $malformed200Res->errorCode === 'parse_error', 'HTTP 200 缺少 pageinfo/jjfh_data 时返回解析错误而非空年度');

// ── 服务：日期过滤 / 类型映射 / 排序 / 分页 / 摘要 / 事件阶段 ──
$result = $svc->calendar($baseOptions);
check($result->success, '基金日历服务成功返回');
check(($result->data['pagination']['total'] ?? 0) === 5, '日期过滤排除历史事件（ex_date 早于今天的历史事件仍按 record_date 在窗口内则保留）');
check(($result->meta['ratio_coverage_count'] ?? 0) >= 4, '比例覆盖计数正确');
check(($result->data['summary']['unique_fund_count'] ?? 0) === 5, '唯一基金数去重');
$svc->calendar($baseOptions);
check($provider->calls === 1, '相同日历查询命中事件缓存');
$indexOnly = $svc->calendar(array_merge($baseOptions, ['fund_category' => 'index']));
check($indexOnly->success && ($indexOnly->data['pagination']['total'] ?? 0) === 3, 'fund_category=index 按类型映射筛选');
$qdiiOnly = $svc->calendar(array_merge($baseOptions, ['fund_category' => 'qdii']));
check(($qdiiOnly->data['pagination']['total'] ?? 0) === 1 && ($qdiiOnly->data['items'][0]['code'] ?? '') === '900001', 'fund_category=qdii 筛选 QDII');
$invalidCat = $svc->calendar(array_merge($baseOptions, ['fund_category' => 'crypto']));
check(!$invalidCat->success && $invalidCat->errorCode === 'invalid_argument', '拒绝非法基金类型');
$overWindow = $svc->calendar(array_merge($baseOptions, ['end_date' => date('Y-m-d', strtotime('+70 days'))]));
check(!$overWindow->success && $overWindow->errorCode === 'invalid_argument', '拒绝超过 60 日窗口');
$dateSorted = $svc->calendar(array_merge($baseOptions, ['sort_by' => 'record_date', 'order' => 'asc']));
check(($dateSorted->data['items'][0]['record_date'] ?? '') === $today, '登记日升序排序');
$cashDesc = $svc->calendar(array_merge($baseOptions, ['sort_by' => 'cash_per_unit', 'order' => 'desc']));
check(($cashDesc->data['items'][0]['cash_per_unit'] ?? 0) === 0.052, '每份分红降序排序');
$paged = $svc->calendar(array_merge($baseOptions, ['page' => 2, 'page_size' => 2]));
check(($paged->data['pagination']['pages'] ?? 0) === 3 && count($paged->data['items'] ?? []) === 2, '分页总数与切片正确');
$stages = array_unique(array_column($result->data['items'], 'event_stage'));
check(in_array('upcoming_record', $stages, true) || in_array('upcoming_ex', $stages, true), '事件阶段标记 upcoming_record/upcoming_ex');

// ── 安全比例 ──
$ratioItem = null;
foreach ($result->data['items'] as $it) { if ($it['code'] === '510500') $ratioItem = $it; }
check($ratioItem && near($ratioItem['distribution_ratio_pct'], 0.05 / 8.0 * 100), '人民币份额按除息前净值计算分配比例');
check(($ratioItem['ratio_status'] ?? '') === 'available', '安全比例状态 available');
$usdItem = null;
foreach ($result->data['items'] as $it) { if ($it['code'] === '900001') $usdItem = $it; }
check(($usdItem['currency'] ?? '') === 'unknown' && ($usdItem['ratio_status'] ?? '') === 'currency_unverified' && $usdItem['distribution_ratio_pct'] === null, '美元/外币名称标记 unknown 币种且不计算比例');
$noRatioFilter = $svc->calendar(array_merge($baseOptions, ['min_distribution_ratio' => 0.5]));
foreach (($noRatioFilter->data['items'] ?? []) as $it) {
    check($it['distribution_ratio_pct'] !== null && (float)$it['distribution_ratio_pct'] >= 0.5, 'min_distribution_ratio>0 排除无安全比例事件（不补零）');
    break;
}
$nullSort = $svc->calendar(array_merge($baseOptions, ['sort_by' => 'distribution_ratio', 'order' => 'desc']));
$last = end($nullSort->data['items']);
check($last['distribution_ratio_pct'] === null, '比例空值始终排在有值事件之后');

// 净值日期 == ex_date / 晚于 ex_date 不计算比例
$badNavFund = new FakeFundService();
$badNavFund->navMap = ['510500' => ['nav' => 8.0, 'nav_date' => $dayAfter, 'acc_nav' => 2.7]];
$badNavSvc = new FundDividendService($provider, $badNavFund, new FundDividendMemoryCache());
$badNavRes = $badNavSvc->calendar($baseOptions);
$badItem = null;
foreach ($badNavRes->data['items'] as $it) { if ($it['code'] === '510500') $badItem = $it; }
check($badItem && $badItem['distribution_ratio_pct'] === null && ($badItem['ratio_status'] ?? '') === 'nav_not_pre_ex', '净值日期不早于除息日时 ratio_status=nav_not_pre_ex');

// ── 降级：类型映射失败 / NAV 部分失败 / 熔断 / 负缓存 / stale ──
$noTypeProvider = new FakeFundDividendProvider($rows);
$noTypeProvider->failTypeMap = true;
$noTypeSvc = new FundDividendService($noTypeProvider, $fund, new FundDividendMemoryCache());
$noTypeAll = $noTypeSvc->calendar($baseOptions);
check($noTypeAll->success && ($noTypeAll->meta['partial'] ?? false) === true && ($noTypeAll->meta['type_map_available'] ?? true) === false, '类型映射失败时 fund_category=all 仍返回事件并标记 partial');
$noTypeFiltered = $noTypeSvc->calendar(array_merge($baseOptions, ['fund_category' => 'index']));
check(!$noTypeFiltered->success && $noTypeFiltered->errorCode === 'metadata_unavailable', '类型映射不可用时指定类型筛选返回 metadata_unavailable');

$partialNavFund = new FakeFundService();
$partialNavFund->navMap = ['510500' => $navMap['510500']]; // 只给一个净值，其余缺失
$partialNavSvc = new FundDividendService($provider, $partialNavFund, new FundDividendMemoryCache());
$partialNavRes = $partialNavSvc->calendar($baseOptions);
check(($partialNavRes->meta['missing_nav_count'] ?? 0) >= 1 && ($partialNavRes->meta['partial'] ?? false) === true, 'NAV 部分失败保留事件并标记 missing_nav_count');

$breaker = new CircuitBreaker('fund_test_breaker_' . uniqid('', true), 2, 60);
$failingHttp = new FundDividendPagedHttpClient();
$failingHttp->fail = true;
$failingClient = new EastmoneyFundDividendClient($failingHttp, $breaker);
$failingClient->calendar($today, $dayAfter);
$failingClient->calendar($today, $dayAfter);
$openRes = $failingClient->calendar($today, $dayAfter);
check($breaker->isOpen() && $openRes->errorCode === 'circuit_open', '基金分红数据源连续失败触发独立熔断');

$staleCache = new FundDividendMemoryCache();
$staleKey = 'fund_dividend:calendar:fake_fund_dividend:' . $today . ':' . date('Y-m-d', strtotime('+6 days'));
$staleCache->stale[$staleKey] = ['success' => true, 'source' => 'fake_fund_dividend', 'action' => 'fund_dividend_calendar_raw', 'data' => $rows, 'meta' => []];
$staleProvider = new FakeFundDividendProvider($rows);
$staleProvider->fail = true;
$staleSvc = new FundDividendService($staleProvider, $fund, $staleCache);
$staleRes = $staleSvc->calendar($baseOptions);
check($staleRes->success && (($staleRes->meta['upstream_cache'] ?? '') === 'stale_fallback'), '上游失败时使用 stale 事件缓存');

// ── 详情：当前事件选择 / 公告精确匹配 / 未匹配 / 失败 / 联接基金 / 净值窗口 ──
$detail = $svc->detail('510500');
check($detail->success && ($detail->data['selected_event']['record_date'] ?? '') === $today, '详情选中最新未完成事件');
check(($detail->data['announcement_match_status'] ?? '') === 'verified', '公告正文同时匹配金额与日期时标记 verified');
check(($detail->data['summary']['cash_dividend_events'] ?? 0) === 2, '详情历史事件数与摘要正确');

$unmatchedFund = new FakeFundService();
$unmatchedFund->navMap = $navMap;
$unmatchedFund->historyMap = $fund->historyMap;
$unmatchedFund->announcements = ['510500' => [['title' => '无关公告', 'date' => '2026-01-01', 'url' => '#', 'pdf_url' => '', 'content' => '与本基金分红无关。']]];
$unmatchedSvc = new FundDividendService($provider, $unmatchedFund, new FundDividendMemoryCache());
$unmatchedDetail = $unmatchedSvc->detail('510500');
check(($unmatchedDetail->data['announcement_match_status'] ?? '') === 'checked_unmatched', '公告读取成功但未匹配时标记 checked_unmatched');

$annFailFund = new FakeFundService();
$annFailFund->navMap = $navMap;
$annFailFund->historyMap = $fund->historyMap;
$annFailFund->annFail = true;
$annFailSvc = new FundDividendService($provider, $annFailFund, new FundDividendMemoryCache());
$annFailDetail = $annFailSvc->detail('510500');
check(($annFailDetail->data['announcement_match_status'] ?? '') === 'check_failed', '公告读取失败时标记 check_failed');

$linkFund = new FakeFundService();
$linkFund->navMap = $navMap;
$linkFund->historyMap = $fund->historyMap;
$linkFund->announcements = $fund->announcements;
$linkFund->windowRows = $fund->windowRows;
$linkFund->infoNames = ['510500' => '中证500ETF联接A'];
$linkFund->relatedFunds = [['code' => '510050', 'name' => '中证500ETF', 'relationship' => 'target_etf', 'interpretation_note' => '目标 ETF 资产层分红。']];
$linkSvc = new FundDividendService($provider, $linkFund, new FundDividendMemoryCache());
$linkDetail = $linkSvc->detail('510500');
check(count($linkDetail->data['related_funds'] ?? []) === 1, '联接基金详情解析目标 ETF 关系');

$detailWithDate = $svc->detail('510500', $today);
check($detailWithDate->success && ($detailWithDate->data['selected_event']['record_date'] ?? '') === $today, '传入 event_date 时选中匹配事件');
$badCode = $svc->detail('ABC');
check(!$badCode->success && $badCode->errorCode === 'invalid_code', '详情拒绝非法基金代码');

// 事件前后净值窗口 + 未来事件后置数据缺失
$window = $svc->eventMarketWindow('510500', $dayAfter, 10, 15);
check($window->success && !empty($window->data['rows']), '事件净值窗口返回行');
check(near($window->data['summary']['pre_event_nav'] ?? null, 8.0), '除息前净值严格取事件日前最后净值，不取除息当日净值');
$futureWindow = $svc->eventMarketWindow('510500', $dayAfter, 10, 15);
check(($futureWindow->meta['post_event_data_pending'] ?? false) === true, '未来事件后置数据缺失标记 post_event_data_pending');

// ── API 兼容：asset_type 默认 stock / 股票响应零变化 ──
$stockDefs = AIFinanceToolCatalog::definitions();
check(is_array($stockDefs) && !empty($stockDefs), '工具目录加载成功');
check(isset($stockDefs['fa_get_upcoming_dividends']), '股票扫描工具仍存在（股票零变化）');
$stockExecutor = new AIToolExecutor();
$stockTool = $stockExecutor->execute('fa_get_upcoming_dividends', ['start_date' => $today, 'days' => 4, 'market' => 'all', 'confirmed_only' => true, 'holding_period' => 'within_1m', 'min_gross_yield' => 0, 'sort_by' => 'gross_yield', 'order' => 'desc', 'limit' => 5]);
check(($stockTool['action'] ?? '') === 'dividend_calendar', '股票 AI 工具 action 仍为 dividend_calendar（零变化）');

// ── AI 工具：严格 Schema / 执行成功失败 / 截断 / 中文状态 / 覆盖 ──
$definitions = AIFinanceToolCatalog::definitions();
$def = $definitions['fa_get_upcoming_fund_dividends'] ?? null;
check(is_array($def), '注册 AI Tool fa_get_upcoming_fund_dividends');
check(($def['parameters']['additionalProperties'] ?? null) === false, 'fa_get_upcoming_fund_dividends 使用 strict object schema');
check(implode(',', array_keys($def['parameters']['properties'])) === 'start_date,days,fund_category,min_distribution_ratio,sort_by,order,limit', '工具参数完整');

$executor = new AIToolExecutor(null, $fund, 60000, null, $svc);
$tool = $executor->execute('fa_get_upcoming_fund_dividends', ['start_date' => $today, 'days' => 7, 'fund_category' => 'all', 'min_distribution_ratio' => 0, 'sort_by' => 'record_date', 'order' => 'asc', 'limit' => 5]);
check(($tool['success'] ?? false) && ($tool['action'] ?? '') === 'fund_dividend_calendar', 'AI 基金分红扫描 Tool 执行成功');
$toolInvalid = $executor->execute('fa_get_upcoming_fund_dividends', ['start_date' => 'bad', 'days' => 14, 'fund_category' => 'all', 'limit' => 5]);
check(!($toolInvalid['success'] ?? true), 'AI Tool 拒绝非法日期');
$smallExecutor = new AIToolExecutor(null, $fund, 320, null, $svc);
$smallOutput = $smallExecutor->executeForModel('fa_get_upcoming_fund_dividends', ['start_date' => $today, 'days' => 7, 'fund_category' => 'all', 'min_distribution_ratio' => 0, 'sort_by' => 'record_date', 'order' => 'asc', 'limit' => 50]);
check(mb_strlen($smallOutput) <= 320 && (strpos($smallOutput, 'truncated') !== false), 'AI Tool 输出按字符上限截断');
$executor->setResearchState(['tools' => [['name' => 'fa_get_upcoming_fund_dividends']], 'candidates' => [], 'failures' => []]);
$research = $executor->execute('fa_research_state_summary', ['asset_type' => 'fund', 'focus' => '基金分红', 'include_failures' => true, 'include_next_steps' => true]);
check(($research['data']['coverage']['dividend_events'] ?? false) === true, '研究状态记录基金分红事件覆盖');
$streamOutput = '';
$stream = new AIAgentStreamEmitter(['expose_tool_trace' => true, 'emit_agent_events' => true]);
$stream->toolStatus(function ($chunk) use (&$streamOutput) { $streamOutput .= $chunk; }, 1, 'fa_get_upcoming_fund_dividends', ['days' => 14]);
check(strpos($streamOutput, '扫描全市场基金分红事件') !== false && strpos($streamOutput, 'tool_status') !== false, 'SSE 使用中文基金分红工具状态标签');

// ── 前端：状态隔离 / 请求中止 / 移动渲染 / 分页 / 转义 ──
$jsSource = file_get_contents(__DIR__ . '/main.js');
check(strpos($jsSource, 'switchMode') !== false && strpos($jsSource, 'this.mode = mode;') !== false, '前端实现股票/基金模式切换');
check(strpos($jsSource, 'savedStates:') !== false, '前端模式状态隔离（savedStates）');
check(strpos($jsSource, 'this.controller.abort()') !== false && strpos($jsSource, 'loadRequestId') !== false, '前端请求中止与 request ID 防覆盖');
check(strpos($jsSource, 'renderFundItems') !== false && strpos($jsSource, 'renderFundDetail') !== false, '前端基金专属渲染方法');
check(strpos($jsSource, '每份分红') !== false && strpos($jsSource, "每股现金") !== false, '前端统一"每份分红"同时保留股票"每股现金"');
check(strpos($jsSource, 'fundStageLabel') !== false && strpos($jsSource, "'待登记'") !== false, '前端基金状态徽章 待登记/待除息/待发放/已完成');
check(strpos($jsSource, 'currency_status') !== false && strpos($jsSource, 'unknown') !== false, '前端未知币种处理');
check(strpos($jsSource, "if (this.mode === 'fund')") !== false, '前端基金自动刷新不受交易时段限制');
check(strpos($jsSource, 'fundDividendAutoRefreshSeconds') !== false && strpos($jsSource, 'restartAutoRefreshTimer') !== false, '基金自动刷新使用独立配置并在模式切换时重建定时器');
check(strpos($jsSource, 'escapeHTML(item.name') !== false && strpos($jsSource, 'escapeAttr(item.code)') !== false, '前端基金动态文本与属性经过 HTML 转义');
$htmlSource = file_get_contents(__DIR__ . '/index.php');
check(strpos($htmlSource, 'dividend-mode-toggle') !== false && strpos($htmlSource, 'data-dividend-mode="fund"') !== false, 'HTML 含股票/基金切换控件');
check(strpos($htmlSource, 'dividend-fund-category') !== false && strpos($htmlSource, 'dividend-min-ratio') !== false, 'HTML 含基金类型与最低分配比例筛选');
check(strpos($htmlSource, 'dividend-fund-summary') !== false, 'HTML 含基金汇总卡');
check(strpos($htmlSource, 'asset_type=fund') === false, 'HTML 不硬编码 asset_type（由 JS 注入）');
$cssSource = file_get_contents(__DIR__ . '/style.css');
check(strpos($cssSource, '[data-stock-only][hidden]') !== false && strpos($cssSource, '[data-fund-only][hidden]') !== false, '股票/基金模式 hidden 规则压过 flex/grid display');

if (in_array('--live', $argv, true)) {
    echo "\n[LIVE] 开始真实东方财富基金分红链路...\n";
    CacheStoreFactory::reset();
    $live = new FundDividendService();
    $liveCal = $live->calendar(['start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+13 days')), 'page_size' => 100]);
    check($liveCal->success, '真实基金分红日历接口返回成功');
    if ($liveCal->success) {
        check(($liveCal->data['summary']['event_count'] ?? 0) > 0, '真实基金日历返回非空事件');
        check(($liveCal->meta['type_map_available'] ?? false) === true, '真实类型映射成功');
        $first = $liveCal->data['items'][0] ?? [];
        check(!empty($first['record_date']) && !empty($first['code']), '真实事件含登记日与代码');
        $noStockFields = !array_key_exists('net_yield_pct', $first) && !array_key_exists('holding_period', $first) && !array_key_exists('tax_rate_pct', $first);
        check($noStockFields, '基金响应不含股票税档/net_yield_pct/税后字段');
        $liveDetail = $live->detail('561580');
        check($liveDetail->success && !empty($liveDetail->data['history']), '真实基金详情返回成功');
        $liveWindow = $live->eventMarketWindow('561580', '2026-07-20', 10, 15);
        check($liveWindow->success, '真实基金净值窗口返回成功');
    }
    $livePagedProvider = new EastmoneyFundDividendClient();
    $livePaged = $livePagedProvider->calendar(date('Y-m-d', strtotime('-59 days')), $today);
    check($livePaged->success && count((array)$livePaged->data) > 100, '真实 60 日基金分红事件跨页返回超过 100 条');
    check(($livePaged->meta['pages'] ?? 0) > 1, '真实 60 日基金分红链路实际读取多页');
    $liveExecutor = new AIToolExecutor();
    $liveTool = $liveExecutor->execute('fa_get_upcoming_fund_dividends', ['start_date' => $today, 'days' => 4, 'fund_category' => 'all', 'min_distribution_ratio' => 0, 'sort_by' => 'record_date', 'order' => 'asc', 'limit' => 10]);
    check(($liveTool['success'] ?? false), '真实 AI 基金分红 Tool 返回成功');
}

if (in_array('--loopback', $argv, true)) {
    echo "\n[LOOPBACK] 开始内部 AI Tool HTTP 链路...\n";
    $token = (string)AppConfig::get('ai.tool_agent.internal_exec_token', '');
    check($token !== '', '内部执行 token 已配置');
    if ($token !== '') {
        $endpoint = 'http://127.0.0.1:18080/FuckAshare/ai_tool_exec.php';
        $payloads = [
            ['token' => $token, 'tool_name' => 'fa_get_upcoming_fund_dividends', 'args' => ['start_date' => $today, 'days' => 4, 'fund_category' => 'all', 'min_distribution_ratio' => 0, 'sort_by' => 'record_date', 'order' => 'asc', 'limit' => 5]],
            ['token' => $token, 'tool_name' => 'fa_get_fund_dividend_profile', 'args' => ['code' => '561580', 'limit' => 5, 'include_related' => true, 'include_announcements' => true, 'announcement_limit' => 3]],
        ];
        $multi = curl_multi_init();
        $handles = [];
        foreach ($payloads as $payload) {
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_NOPROXY => '*',
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        $active = null;
        do { $status = curl_multi_exec($multi, $active); } while ($status === CURLM_CALL_MULTI_PERFORM || $active);
        $idx = 0;
        foreach ($handles as $handle) {
            $body = curl_multi_getcontent($handle);
            $decoded = json_decode($body, true);
            $ok = is_array($decoded) && ($decoded['success'] ?? false) === true;
            check($ok, 'loopback 工具 ' . $payloads[$idx]['tool_name'] . ' 执行成功');
            $idx++;
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
    }
}

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
