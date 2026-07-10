<?php
/**
 * 分红日历与 AI Tool 聚焦测试。
 *
 * 用法：
 *   .\php\php.exe dividend_feature_tests.php
 *   .\php\php.exe dividend_feature_tests.php --live
 *   .\php\php.exe dividend_feature_tests.php --loopback
 */

require_once __DIR__ . '/lib/DividendService.php';
require_once __DIR__ . '/lib/AIToolExecutor.php';
require_once __DIR__ . '/lib/AIFinanceToolCatalog.php';
require_once __DIR__ . '/lib/AppConfig.php';
require_once __DIR__ . '/lib/AIAgentStreamEmitter.php';

class DividendTestProvider implements DividendDataProvider
{
    public $calls = 0;
    public $fail = false;
    public function sourceName(): string { return 'fake_dividend'; }
    public function calendar(string $startDate, string $endDate): DataSourceResult
    {
        $this->calls++;
        if ($this->fail) return DataSourceResult::error($this->sourceName(), 'dividend_calendar_raw', 'network_error', 'fake failure');
        return DataSourceResult::success($this->sourceName(), 'dividend_calendar_raw', $this->rows());
    }
    public function detail(string $code): DataSourceResult
    {
        $this->calls++;
        if ($this->fail) return DataSourceResult::error($this->sourceName(), 'dividend_detail_raw', 'network_error', 'fake failure');
        $rows = array_values(array_filter($this->rows(), function ($row) use ($code) { return $row['code'] === $code; }));
        if ($code === '600001') {
            $rows[] = array_merge($rows[0], ['report_date'=>'2001-12-31','record_date'=>'2002-06-18','ex_date'=>'2002-06-19','plan_text'=>'10派1元','cash_per_10'=>1.0]);
        }
        return DataSourceResult::success($this->sourceName(), 'dividend_detail_raw', $rows);
    }
    private function rows(): array
    {
        $base = ['report_date'=>'2025-12-31','plan_notice_date'=>'2026-03-01','notice_date'=>'2026-07-08','publish_date'=>'2026-03-01','pay_date'=>null,'bonus_ratio'=>null,'capitalization_ratio'=>null,'total_shares'=>1000000,'source'=>$this->sourceName()];
        return [
            array_merge($base, ['code'=>'600001','name'=>'沪股样本','record_date'=>'2026-07-15','ex_date'=>'2026-07-16','progress'=>'实施分配','plan_text'=>'10派5元','cash_per_10'=>5.0]),
            array_merge($base, ['code'=>'000001','name'=>'深股样本','record_date'=>'2026-07-14','ex_date'=>'2026-07-15','progress'=>'实施分配','plan_text'=>'10派2元','cash_per_10'=>2.0]),
            array_merge($base, ['code'=>'920001','name'=>'北股样本','record_date'=>'2026-07-16','ex_date'=>'2026-07-17','progress'=>'实施分配','plan_text'=>'10派3元','cash_per_10'=>3.0]),
            array_merge($base, ['code'=>'600002','name'=>'未确认样本','record_date'=>'2026-07-17','ex_date'=>'2026-07-20','progress'=>'股东大会决议通过','plan_text'=>'10派4元','cash_per_10'=>4.0]),
            array_merge($base, ['code'=>'900901','name'=>'B股排除','record_date'=>'2026-07-15','ex_date'=>'2026-07-16','progress'=>'实施分配','plan_text'=>'10派1元','cash_per_10'=>1.0]),
        ];
    }
}

class DividendTestMarket extends MarketDataService
{
    public function __construct() {}
    public function quote(string $codes, string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        $prices = ['600001'=>10.0,'000001'=>20.0,'920001'=>null,'600002'=>8.0];
        $names = ['600001'=>'沪股样本','000001'=>'深股样本','920001'=>'北股样本','600002'=>'未确认样本'];
        $items = [];
        foreach (explode(',', $codes) as $code) {
            if (!array_key_exists($code, $prices) || $prices[$code] === null) continue;
            $items[] = ['code'=>$code,'name'=>$names[$code] ?? $code,'price'=>$prices[$code],'prev_close'=>$prices[$code] - 0.1];
        }
        return DataSourceResult::success('fake_quote', 'quote', $items);
    }
    public function kline(string $code, string $frequency = '1d', int $count = 120, string $endDate = '', string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        $rows = [];
        $start = new DateTimeImmutable('2026-06-25');
        for ($i = 0; $i < 42; $i++) {
            $date = $start->modify("+{$i} days");
            if (in_array((int)$date->format('N'), [6, 7], true)) continue;
            $base = 10 + $i * 0.03;
            $rows[] = ['time'=>$date->format('Y-m-d'),'open'=>$base,'close'=>$base + ($i % 2 ? 0.08 : -0.04),'high'=>$base + 0.15,'low'=>$base - 0.12,'volume'=>100000 + $i * 1000];
        }
        return DataSourceResult::success('fake_kline', 'kline', $rows);
    }
}

class DividendMemoryCache implements CacheStore
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

class DividendPagedHttpClient extends HttpClient
{
    public $calls = 0;
    public $fail = false;
    public function __construct() {}
    public function get(string $url, array $headers = []): array
    {
        $this->calls++;
        $this->lastDuration = 0.001;
        if ($this->fail) return ['body'=>'','http_code'=>503,'error'=>'fake upstream failure','headers'=>[]];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $page = (int)($query['pageNumber'] ?? 1);
        $row = [
            'SECURITY_CODE'=>$page === 1 ? '600011' : '000012','SECURITY_NAME_ABBR'=>'分页样本' . $page,
            'REPORT_DATE'=>'2025-12-31','EQUITY_RECORD_DATE'=>'2026-07-' . ($page === 1 ? '13' : '14'),
            'EX_DIVIDEND_DATE'=>'2026-07-' . ($page === 1 ? '14' : '15'),'ASSIGN_PROGRESS'=>'实施分配',
            'IMPL_PLAN_PROFILE'=>'10派' . $page . '元','PRETAX_BONUS_RMB'=>$page,
        ];
        return ['body'=>json_encode(['success'=>true,'result'=>['pages'=>2,'count'=>2,'data'=>[$row]]], JSON_UNESCAPED_UNICODE),'http_code'=>200,'error'=>'','headers'=>[]];
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

$provider = new DividendTestProvider();
$market = new DividendTestMarket();
$cache = new DividendMemoryCache();
$service = new DividendService($provider, $market, $cache);
$baseOptions = ['start_date'=>'2026-07-13','end_date'=>'2026-07-20','status'=>'confirmed','page_size'=>100];

$result = $service->calendar($baseOptions);
check($result->success, '日历服务成功返回');
check(($result->data['pagination']['total'] ?? 0) === 3, '默认排除未确认方案和 B 股');
check(($result->data['items'][0]['code'] ?? '') === '600001', '默认按毛现金率降序');
check(near($result->data['items'][0]['cash_per_share'] ?? null, 0.5), '每10股现金正确换算为每股');
check(near($result->data['items'][0]['gross_yield_pct'] ?? null, 5.0), '毛现金率按当前价格计算');
check(near($result->data['items'][0]['net_yield_pct'] ?? null, 4.0), '短持20%税后现金率正确');
check(($result->meta['partial'] ?? false) === true && ($result->meta['missing_quote_count'] ?? 0) === 1, '缺行情保留事件并标记 partial');
check($provider->calls === 1, '首次查询回源一次');
$service->calendar($baseOptions);
check($provider->calls === 1, '相同日历查询命中缓存');

$mid = $service->calendar(array_merge($baseOptions, ['holding_period'=>'1m_to_1y']));
check(near($mid->data['items'][0]['net_yield_pct'] ?? null, 4.5), '1个月至1年10%税率正确');
$long = $service->calendar(array_merge($baseOptions, ['holding_period'=>'over_1y']));
check(near($long->data['items'][0]['net_yield_pct'] ?? null, 5.0), '超过1年0%税率正确');
$all = $service->calendar(array_merge($baseOptions, ['status'=>'all']));
check(($all->data['pagination']['total'] ?? 0) === 4, 'status=all 包含未确认方案');
$bj = $service->calendar(array_merge($baseOptions, ['market'=>'bj']));
check(($bj->data['pagination']['total'] ?? 0) === 1 && ($bj->data['items'][0]['code'] ?? '') === '920001', '北交所92代码筛选正确');
$minimum = $service->calendar(array_merge($baseOptions, ['min_yield'=>4.0]));
check(($minimum->data['pagination']['total'] ?? 0) === 1, '最低毛率过滤正确');
$paged = $service->calendar(array_merge($baseOptions, ['page'=>2,'page_size'=>1]));
check(($paged->data['pagination']['pages'] ?? 0) === 3 && count($paged->data['items'] ?? []) === 1, '分页总数和当前页切片正确');
$dateSorted = $service->calendar(array_merge($baseOptions, ['sort_by'=>'record_date','order'=>'asc']));
check(($dateSorted->data['items'][0]['record_date'] ?? '') === '2026-07-14', '登记日升序排序正确');
$invalid = $service->calendar(array_merge($baseOptions, ['end_date'=>'2026-10-31']));
check(!$invalid->success && $invalid->errorCode === 'invalid_argument', '拒绝超过60日的日期跨度');
$invalidMarket = $service->calendar(array_merge($baseOptions, ['market'=>'hk']));
check(!$invalidMarket->success && $invalidMarket->errorCode === 'invalid_argument', '拒绝非法市场参数');

$detail = $service->detail('600001', 10, 'within_1m');
check($detail->success && ($detail->data['summary']['cash_dividend_events'] ?? 0) === 1, '个股详情与历史摘要正确');
$fullDetail = $service->detail('600001', null, 'within_1m');
check($fullDetail->success && count($fullDetail->data['history'] ?? []) === 2 && ($fullDetail->data['history_scope'] ?? '') === 'all', '完整历史模式不再截断十年前分红');
$eventMarket = $service->eventMarketWindow('600001', '2026-07-16', 10, 15);
check($eventMarket->success && !empty($eventMarket->data['rows']) && isset($eventMarket->data['summary']['event_change_pct']), '历史分红事件返回附近日 K 与摘要');
check(StockCode::parse('920001')->market === 'BJ' && StockCode::parse('920001')->isAStock(), 'StockCode 支持北交所92代码');
check(StockCode::parse('900901')->market === 'SH' && !StockCode::parse('900901')->isAStock(), '上海 B 股可识别市场但不属于 A 股');

$pagedHttp = new DividendPagedHttpClient();
$pagedClient = new EastmoneyDividendClient($pagedHttp, new CircuitBreaker('dividend_test_pages_' . uniqid(), 3, 60));
$pagedRaw = $pagedClient->calendar('2026-07-13', '2026-07-16');
check($pagedRaw->success && count($pagedRaw->data) === 2 && ($pagedRaw->meta['pages'] ?? 0) === 2, '东方财富客户端合并多页响应');
$failingHttp = new DividendPagedHttpClient(); $failingHttp->fail = true;
$testBreaker = new CircuitBreaker('dividend_test_breaker_' . uniqid(), 2, 60);
$failingClient = new EastmoneyDividendClient($failingHttp, $testBreaker);
$failingClient->calendar('2026-07-13', '2026-07-16');
$failingClient->calendar('2026-07-13', '2026-07-16');
$circuitResult = $failingClient->calendar('2026-07-13', '2026-07-16');
check($testBreaker->isOpen() && $circuitResult->errorCode === 'circuit_open', '分红数据源连续失败触发独立熔断');

$staleCache = new DividendMemoryCache();
$staleKey = 'dividend:calendar:fake_dividend:2026-07-13:2026-07-20';
$staleCache->stale[$staleKey] = ['success'=>true,'source'=>'fake_dividend','action'=>'dividend_calendar_raw','data'=>[[
    'code'=>'600001','name'=>'陈旧样本','report_date'=>'2025-12-31','plan_notice_date'=>null,'notice_date'=>null,'publish_date'=>null,'record_date'=>'2026-07-15','ex_date'=>'2026-07-16','pay_date'=>null,'progress'=>'实施分配','plan_text'=>'10派5元','cash_per_10'=>5.0,'bonus_ratio'=>null,'capitalization_ratio'=>null,'total_shares'=>1,'source'=>'fake_dividend'
]],'meta'=>[]];
$failingProvider = new DividendTestProvider(); $failingProvider->fail = true;
$staleService = new DividendService($failingProvider, $market, $staleCache);
$staleResult = $staleService->calendar($baseOptions);
check($staleResult->success && (($staleResult->meta['upstream_cache'] ?? '') === 'stale_fallback'), '上游失败时使用 stale 事件缓存');

$definitions = AIFinanceToolCatalog::definitions();
foreach (['fa_get_upcoming_dividends','fa_get_stock_dividend_profile'] as $toolName) {
    $definition = $definitions[$toolName] ?? null;
    check(is_array($definition), "注册 AI Tool {$toolName}");
    check(($definition['parameters']['additionalProperties'] ?? null) === false, "{$toolName} 使用 strict object schema");
}
$executor = new AIToolExecutor($market, new FundService(), 60000, $service);
$tool = $executor->execute('fa_get_upcoming_dividends', ['start_date'=>'2026-07-13','days'=>8,'market'=>'all','confirmed_only'=>true,'holding_period'=>'within_1m','min_gross_yield'=>0,'sort_by'=>'gross_yield','order'=>'desc','limit'=>20]);
check(($tool['success'] ?? false) && ($tool['action'] ?? '') === 'dividend_calendar', 'AI 临近分红 Tool 执行成功');
$toolDetail = $executor->execute('fa_get_stock_dividend_profile', ['code'=>'600001','years'=>10,'holding_period'=>'within_1m']);
check(($toolDetail['success'] ?? false) && ($toolDetail['action'] ?? '') === 'dividend_detail', 'AI 个股分红档案 Tool 执行成功');
$toolInvalid = $executor->execute('fa_get_upcoming_dividends', ['start_date'=>'bad','days'=>14,'market'=>'all','confirmed_only'=>true,'holding_period'=>'within_1m','min_gross_yield'=>0,'sort_by'=>'gross_yield','order'=>'desc','limit'=>20]);
check(!($toolInvalid['success'] ?? true), 'AI Tool 拒绝非法日期');
$executor->setResearchState(['tools'=>[['name'=>'fa_get_upcoming_dividends'],['name'=>'fa_get_stock_dividend_profile']],'candidates'=>['600001'=>['name'=>'沪股样本','status'=>'seen']],'failures'=>[]]);
$research = $executor->execute('fa_research_state_summary', ['asset_type'=>'stock','focus'=>'分红事件','include_failures'=>true,'include_next_steps'=>true]);
check(($research['data']['coverage']['dividend_events'] ?? false) && ($research['data']['coverage']['dividend_profile'] ?? false), '研究状态记录分红候选与档案覆盖');
$streamOutput = '';
$stream = new AIAgentStreamEmitter(['expose_tool_trace'=>true,'emit_agent_events'=>true]);
$stream->toolStatus(function ($chunk) use (&$streamOutput) { $streamOutput .= $chunk; }, 1, 'fa_get_upcoming_dividends', ['days'=>14]);
check(strpos($streamOutput, '扫描临近分红事件') !== false && strpos($streamOutput, 'tool_status') !== false, 'SSE 使用中文分红工具状态标签');
$smallExecutor = new AIToolExecutor($market, new FundService(), 320, $service);
$smallOutput = $smallExecutor->executeForModel('fa_get_upcoming_dividends', ['start_date'=>'2026-07-13','days'=>8,'market'=>'all','confirmed_only'=>false,'holding_period'=>'within_1m','min_gross_yield'=>0,'sort_by'=>'gross_yield','order'=>'desc','limit'=>50]);
check(mb_strlen($smallOutput) <= 320 && (strpos($smallOutput, 'truncated') !== false || strpos($smallOutput, 'tool output truncated') !== false), 'AI Tool 输出按字符上限截断');
$jsSource = file_get_contents(__DIR__ . '/main.js');
check(strpos($jsSource, '${escapeHTML(item.name') !== false && strpos($jsSource, '${escapeAttr(item.code)') !== false, '分红事件动态文本与属性经过 HTML 转义');

if (in_array('--live', $argv, true)) {
    echo "\n[LIVE] 开始真实东方财富链路...\n";
    CacheStoreFactory::reset();
    $live = new DividendService();
    $liveCalendar = $live->calendar(['start_date'=>'2026-07-13','end_date'=>'2026-07-16','status'=>'confirmed','page_size'=>100]);
    $liveCodes = $liveCalendar->success ? array_column($liveCalendar->data['items'] ?? [], 'code') : [];
    check($liveCalendar->success, '真实分红日历接口返回成功');
    foreach (['002867','600642','000543','600600','601601','001872','000858'] as $code) {
        check(in_array($code, $liveCodes, true), "真实链路命中样例 {$code}");
    }
    $liveDetail = $live->detail('000858', 10, 'within_1m');
    check($liveDetail->success && !empty($liveDetail->data['history']), '真实五粮液分红历史返回成功');
    $liveExecutor = new AIToolExecutor();
    $liveTool = $liveExecutor->execute('fa_get_upcoming_dividends', ['start_date'=>'2026-07-13','days'=>4,'market'=>'all','confirmed_only'=>true,'holding_period'=>'within_1m','min_gross_yield'=>0,'sort_by'=>'gross_yield','order'=>'desc','limit'=>20]);
    check(($liveTool['success'] ?? false), '真实 AI Tool 返回成功');
}

if (in_array('--loopback', $argv, true)) {
    echo "\n[LOOPBACK] 开始内部 AI Tool HTTP 链路...\n";
    $token = (string)AppConfig::get('ai.tool_agent.internal_exec_token', '');
    check($token !== '', '内部执行 token 已配置');
    if ($token !== '') {
        $endpoint = 'http://127.0.0.1:18080/FuckAshare/ai_tool_exec.php';
        $payloads = [
            ['token'=>$token,'tool_name'=>'fa_get_upcoming_dividends','args'=>['start_date'=>'2026-07-13','days'=>4,'market'=>'all','confirmed_only'=>true,'holding_period'=>'within_1m','min_gross_yield'=>0,'sort_by'=>'gross_yield','order'=>'desc','limit'=>20]],
            ['token'=>$token,'tool_name'=>'fa_get_stock_dividend_profile','args'=>['code'=>'000858','years'=>10,'holding_period'=>'within_1m']],
        ];
        $multi = curl_multi_init();
        $handles = [];
        foreach ($payloads as $payload) {
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do { $status = curl_multi_exec($multi, $active); if ($active) curl_multi_select($multi, 0.2); } while ($active && $status === CURLM_OK);
        foreach ($handles as $index => $handle) {
            $decoded = json_decode((string)curl_multi_getcontent($handle), true);
            check(curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200 && is_array($decoded) && ($decoded['success'] ?? false), 'loopback 并行工具 ' . ($index + 1) . ' 返回有效 JSON');
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        $bad = curl_init($endpoint);
        curl_setopt_array($bad, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['token'=>'invalid','tool_name'=>'fa_get_upcoming_dividends','args'=>[]])]);
        curl_exec($bad);
        check(curl_getinfo($bad, CURLINFO_HTTP_CODE) === 403, 'loopback 拒绝错误 token');
        curl_close($bad);
    }
}

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
