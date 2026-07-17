<?php
/**
 * 股票公告与公司事件回归测试。
 *
 * Run:     php/php.exe announcement_feature_tests.php
 * Live:    php/php.exe announcement_feature_tests.php --live
 * Loopback:php/php.exe announcement_feature_tests.php --loopback
 * AI SSE:  php/php.exe announcement_feature_tests.php --ai-loopback
 */

require_once __DIR__ . '/lib/AnnouncementService.php';
require_once __DIR__ . '/lib/AIToolExecutor.php';
require_once __DIR__ . '/lib/AIToolRegistry.php';
require_once __DIR__ . '/lib/AIChatToolAgent.php';

function announcementCheck($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeAnnouncementTestTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach ((array)scandir($path) as $item) {
        if ($item === '.' || $item === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) removeAnnouncementTestTree($target); else @unlink($target);
    }
    @rmdir($path);
}

class FakeAnnouncementProvider implements AnnouncementDataProvider
{
    public $listCalls = 0;
    public $detailCalls = 0;

    public function sourceName(): string { return 'fake_announcements'; }

    public function listAnnouncements(array $query): DataSourceResult
    {
        $this->listCalls++;
        $page = (int)($query['page'] ?? 1);
        $rows = $page === 1 ? [
            $this->row('AN202607171111111111', '600519', '贵州茅台', '关于收到监管问询函的公告', '问询函其他公告', '2026-07-17'),
            $this->row('AN202607171111111112', '600519', '贵州茅台', '年度股东大会法律意见书', '法律意见书', '2026-07-17'),
            $this->row('AN202607161111111113', '600519', '贵州茅台', '2025年年度权益分派实施公告', '权益分派公告', '2026-07-16'),
        ] : [
            $this->row('AN202607151111111114', '600519', '贵州茅台', '关于签订重大合同的公告', '重大合同', '2026-07-15'),
            $this->row('AN202607171111111111', '600519', '贵州茅台', '关于收到监管问询函的公告', '问询函其他公告', '2026-07-17'),
        ];
        return DataSourceResult::success($this->sourceName(), 'announcement_list', $rows, [
            'page' => $page,
            'page_size' => 100,
            'total_hits' => 5,
            'has_more' => $page < 2,
            'http_code' => 200,
        ]);
    }

    public function announcementDetail(string $announcementId): DataSourceResult
    {
        $this->detailCalls++;
        return DataSourceResult::success($this->sourceName(), 'announcement_detail', [
            'id' => $announcementId,
            'code' => '600519',
            'name' => '贵州茅台',
            'market' => 'sh',
            'title' => '关于签订重大合同的公告',
            'disclosure_date' => '2026-07-15',
            'published_at' => '2026-07-15 18:30:00',
            'provider' => $this->sourceName(),
            'provider_url' => 'https://data.eastmoney.com/notices/detail/600519/' . $announcementId . '.html',
            'document_url' => 'https://pdf.dfcfw.com/pdf/test.pdf',
            'content' => str_repeat('公告正文测试。', 500),
            'content_status' => 'available',
            'content_chars' => 3500,
        ], ['content_exposed' => true]);
    }

    private function row(string $id, string $code, string $name, string $title, string $category, string $date): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'market' => 'sh',
            'securities' => [['code' => $code, 'name' => $name, 'market' => 'sh']],
            'title' => $title,
            'disclosure_date' => $date,
            'published_at' => $date . ' 18:30:00',
            'category_raw' => $category,
            'provider' => $this->sourceName(),
            'provider_url' => 'https://data.eastmoney.com/notices/detail/' . $code . '/' . $id . '.html',
            'document_url' => null,
            'detail_available' => true,
        ];
    }
}

class FakeAnnouncementStockSearch extends StockSearchService
{
    public function __construct() {}
    public function resolve(string $query): DataSourceResult
    {
        if ($query === '贵州茅台' || preg_match('/600519/', $query)) {
            return DataSourceResult::success('fake_stock_search', 'stock_resolve', [
                'code' => '600519', 'symbol' => 'sh600519', 'name' => '贵州茅台', 'market' => '上海',
            ]);
        }
        return DataSourceResult::error('fake_stock_search', 'stock_resolve', 'stock_not_found', '未找到股票');
    }
}

class FakeAnnouncementHttpClient extends HttpClient
{
    public function __construct() {}
    public function get(string $url, array $headers = []): array
    {
        $this->lastDuration = 0.01;
        if (strpos($url, '/api/security/ann') !== false) {
            $body = [
                'data' => [
                    'total_hits' => 1,
                    'list' => [[
                        'art_code' => 'AN202607171234567890',
                        'title' => '测试公司关于股份回购的公告',
                        'notice_date' => '2026-07-18 00:00:00',
                        'display_time' => '2026-07-17 20:00:00',
                        'codes' => [['stock_code' => '600001', 'short_name' => '测试公司']],
                        'columns' => [['column_code' => '001002007003', 'column_name' => '股份回购']],
                    ]],
                ],
            ];
        } else {
            $body = [
                'data' => [
                    'art_code' => 'AN202607171234567890',
                    'notice_title' => '测试公司关于股份回购的公告',
                    'notice_date' => '2026-07-18 00:00:00',
                    'eitime' => '2026-07-17 20:00:00',
                    'security' => ['stock' => '600001', 'short_name' => '测试公司'],
                    'notice_content' => "第一段。\n\n第二段。",
                    'attach_url' => 'https://pdf.dfcfw.com/pdf/test.pdf',
                ],
            ];
        }
        return ['body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'http_code' => 200, 'error' => null, 'content_type' => 'application/json'];
    }
}

$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fa_announcement_tests_' . getmypid();
removeAnnouncementTestTree($cacheDir);
CacheStoreFactory::useFileStore($cacheDir);

try {
    $classifier = new AnnouncementClassifier();
    $risk = $classifier->classify('公司收到立案调查通知', '监管公告');
    announcementCheck($risk['event_type'] === 'risk_regulatory' && $risk['importance'] === 'important', '监管风险公告必须识别为重要风险事件');
    $routine = $classifier->classify('年度股东大会法律意见书', '法律意见书');
    announcementCheck($routine['event_type'] === 'governance' && $routine['importance'] === 'routine', '法律意见书必须降为程序性公告');
    $dividend = $classifier->classify('2025年年度权益分派实施公告', '权益分派公告');
    announcementCheck($dividend['event_type'] === 'dividend' && $dividend['importance'] === 'important', '权益分派必须识别为重要分红事件');
    $major = $classifier->classify('公司重大事项公告', '其他');
    announcementCheck($major['importance'] === 'important', '重大事项公告必须标记为重要');

    $fixtureClient = new EastmoneyAnnouncementClient(new FakeAnnouncementHttpClient(), new CircuitBreaker('announcement_fixture_' . getmypid(), 10, 1));
    $fixtureList = $fixtureClient->listAnnouncements(['market' => 'sh', 'code' => '600001', 'page' => 1, 'page_size' => 20]);
    announcementCheck($fixtureList->success && ($fixtureList->data[0]['id'] ?? '') === 'AN202607171234567890', 'Provider 必须解析公告列表与 art_code');
    announcementCheck(($fixtureList->data[0]['published_at'] ?? '') === '2026-07-17 20:00:00', 'Provider 必须保留可靠发布时间');
    $fixtureDetail = $fixtureClient->announcementDetail('AN202607171234567890');
    announcementCheck($fixtureDetail->success && strpos($fixtureDetail->data['content'], '第二段') !== false, 'Provider 必须解析公告正文');
    announcementCheck(($fixtureDetail->data['document_url'] ?? '') === 'https://pdf.dfcfw.com/pdf/test.pdf', 'Provider 必须保留允许域名的 PDF');

    $provider = new FakeAnnouncementProvider();
    $service = new AnnouncementService($provider, CacheStoreFactory::getInstance(), new AnnouncementClassifier(), new FakeAnnouncementStockSearch());
    $stock = $service->list(['scope' => 'stock', 'name' => '贵州茅台', 'importance' => 'all', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'limit' => 10]);
    announcementCheck($stock->success && count($stock->data) === 4, '股票公告必须解析名称、跨页扫描并按 ID 去重');
    announcementCheck(($stock->meta['asset']['code'] ?? '') === '600519', '股票名称必须解析为唯一 A 股代码');
    foreach ($stock->data as $item) {
        announcementCheck(!array_key_exists('content', $item), '公告列表不得批量暴露正文');
        announcementCheck(isset($item['event_type'], $item['importance'], $item['classification_version']), '公告列表必须包含确定性事件分类');
    }
    $callsAfterFirst = $provider->listCalls;
    $stockCached = $service->list(['scope' => 'stock', 'name' => '贵州茅台', 'importance' => 'all', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'limit' => 10]);
    announcementCheck($stockCached->success && ($stockCached->meta['cache'] ?? '') === 'hit' && $provider->listCalls === $callsAfterFirst, '相同公告查询必须命中缓存');

    $important = $service->list(['scope' => 'market', 'importance' => 'all', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'limit' => 10]);
    announcementCheck($important->success && ($important->meta['importance'] ?? '') === 'important', '市场范围必须强制只展示重要公告');
    announcementCheck(count(array_filter($important->data, function(array $row): bool { return $row['importance'] !== 'important'; })) === 0, '市场公告流不得包含程序性公告');

    $detail = $service->detail('AN202607151111111114', 1000);
    announcementCheck($detail->success && ($detail->data['content_status'] ?? '') === 'truncated', '超长公告正文必须按需截断');
    announcementCheck(($detail->data['content_truncated'] ?? false) === true && ($detail->meta['content_exposed'] ?? false) === true, '正文截断与暴露状态必须明确');
    $detailCalls = $provider->detailCalls;
    $detailCached = $service->detail('AN202607151111111114', 1500);
    announcementCheck($detailCached->success && $provider->detailCalls === $detailCalls, '公告详情不同长度请求必须复用同一正文缓存');

    $definitions = AIToolRegistry::definitions();
    foreach (['fa_get_stock_announcements', 'fa_get_stock_announcement_detail'] as $tool) {
        announcementCheck(isset($definitions[$tool]), "AI 工具 {$tool} 必须注册");
        announcementCheck(($definitions[$tool]['parameters']['additionalProperties'] ?? null) === false, "AI 工具 {$tool} 必须为严格 schema");
    }
    $executor = new AIToolExecutor(null, null, 30000, null, null, null, $service);
    $toolList = $executor->execute('fa_get_stock_announcements', [
        'scope' => 'stock', 'code' => '600519', 'name' => null, 'market' => 'all', 'event_type' => 'all', 'importance' => 'important', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'page' => 1, 'limit' => 10,
    ]);
    announcementCheck(($toolList['success'] ?? false) === true && count($toolList['data'] ?? []) >= 1, 'AI 公告列表工具必须可执行');
    $toolDetail = $executor->execute('fa_get_stock_announcement_detail', ['announcement_id' => 'AN202607151111111114', 'content_limit' => 1200]);
    announcementCheck(($toolDetail['success'] ?? false) === true && !empty($toolDetail['data']['content']), 'AI 公告正文工具必须可执行');
    $invalidDetail = $executor->execute('fa_get_stock_announcement_detail', ['announcement_id' => 'https://evil.invalid', 'content_limit' => 1200]);
    announcementCheck(($invalidDetail['success'] ?? true) === false, 'AI 公告正文工具必须拒绝任意 URL');

    $runtimeOptions = AIAgentOptions::normalize([
        'parallel_tool_calls' => false,
        'emit_agent_events' => false,
        'expose_tool_trace' => false,
        'max_tool_calls_total' => 4,
    ]);
    $runtime = new AIToolRuntime($executor, new AIAgentStreamEmitter($runtimeOptions), $runtimeOptions);
    $runtimeState = new AIAgentState();
    $runtimeMessages = $runtime->executeToolCalls([[
        'id' => 'announcement_schema_normalization',
        'function' => [
            'name' => 'fa_get_stock_announcements',
            'arguments' => json_encode([
                'scope' => 'stock', 'code' => 600519, 'name' => 'None', 'market' => 'None',
                'event_type' => 'all', 'importance' => 'important', 'date_from' => 'None',
                'date_to' => 'None', 'page' => 1, 'limit' => 3,
            ]),
        ],
    ]], $runtimeState, function(string $chunk): void {}, 1, 'test');
    $runtimePayload = json_decode((string)($runtimeMessages[0]['content'] ?? ''), true);
    announcementCheck(($runtimePayload['success'] ?? false) === true, 'AI 工具运行时必须将 nullable 的 None 与数值股票代码按 schema 规范化后首次执行成功');

    $agent = new AIChatToolAgent(['api_url' => 'https://example.invalid', 'api_key' => 'test', 'model' => 'test']);
    $infer = new ReflectionMethod($agent, 'inferArgumentsForRequestedTool');
    $infer->setAccessible(true);
    $inferred = $infer->invoke($agent, 'fa_get_stock_announcements', '请分析股票 600519 的最新公告');
    announcementCheck(($inferred['scope'] ?? '') === 'stock' && ($inferred['code'] ?? '') === '600519', '参数修复必须识别股票公告代码');
    $inferredMarket = $infer->invoke($agent, 'fa_get_stock_announcements', '请概览近期 A 股全市场重要公告');
    announcementCheck(($inferredMarket['scope'] ?? '') === 'market', '参数修复必须识别全市场公告请求');

    $html = file_get_contents(__DIR__ . '/index.php');
    $js = file_get_contents(__DIR__ . '/main.js');
    $css = file_get_contents(__DIR__ . '/style.css');
    announcementCheck(strpos($html, 'id="announcement-section"') !== false && strpos($html, 'id="announcement-detail-overlay"') !== false, '前端必须包含公告列表与正文抽屉');
    announcementCheck(strpos($js, 'announcement_api.php?') !== false && strpos($js, 'openAnnouncementDetail') !== false, '前端必须调用独立公告 API 并按需加载正文');
    announcementCheck(strpos($js, "action: 'sentiment'") !== false && strpos($js, "scope: 'stock'") !== false, '公告与标题情绪必须维持独立请求');
    announcementCheck(strpos($css, '.announcement-detail-drawer') !== false && strpos($css, '@media (max-width: 768px)') !== false, '公告界面必须包含响应式详情样式');
    announcementCheck(strpos(file_get_contents(__DIR__ . '/news_api.php'), 'AnnouncementService') === false, '新闻 API 不得混入公告服务');

    echo "All announcement feature tests passed.\n";

    if (in_array('--live', $argv, true)) {
        CacheStoreFactory::useFileStore($cacheDir . '_live');
        $live = new AnnouncementService();
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $liveList = $live->list([
            'scope' => 'stock', 'code' => '600519', 'importance' => 'all',
            'date_from' => $today->modify('-364 days')->format('Y-m-d'),
            'date_to' => $today->modify('+1 day')->format('Y-m-d'), 'limit' => 5,
        ]);
        announcementCheck($liveList->success && count($liveList->data) > 0, 'Live 股票公告必须返回数据');
        $liveId = (string)$liveList->data[0]['id'];
        $liveDetail = $live->detail($liveId, 3000);
        announcementCheck($liveDetail->success && !empty($liveDetail->data['content']), 'Live 公告正文必须返回文本');
        announcementCheck(!empty($liveDetail->data['document_url']), 'Live 公告详情必须返回 PDF 地址');
        echo 'Live announcement checks passed: list=' . count($liveList->data) . ', id=' . $liveId . ', content_chars=' . ($liveDetail->data['content_chars'] ?? 0) . "\n";
    }

    if (in_array('--loopback', $argv, true)) {
        $http = new HttpClient(['timeout' => 30, 'connect_timeout' => 5]);
        $listResp = $http->get('http://127.0.0.1:8081/announcement_api.php?action=list&scope=stock&code=600519&importance=all&limit=3');
        $listPayload = json_decode((string)$listResp['body'], true);
        announcementCheck((int)$listResp['http_code'] === 200 && ($listPayload['success'] ?? false) === true && !empty($listPayload['data']), 'Loopback 公告列表 API 必须返回真实数据');
        $id = (string)$listPayload['data'][0]['id'];
        $detailResp = $http->get('http://127.0.0.1:8081/announcement_api.php?action=detail&id=' . rawurlencode($id) . '&content_limit=2000');
        $detailPayload = json_decode((string)$detailResp['body'], true);
        announcementCheck((int)$detailResp['http_code'] === 200 && ($detailPayload['success'] ?? false) === true && !empty($detailPayload['data']['content']), 'Loopback 公告详情 API 必须返回真实正文');
        echo 'Loopback announcement API checks passed: id=' . $id . "\n";
    }

    if (in_array('--ai-loopback', $argv, true)) {
        $http = new HttpClient(['timeout' => 300, 'connect_timeout' => 10]);
        $requestBody = json_encode([
            'messages' => [[
                'role' => 'user',
                'content' => '请只使用 fa_get_stock_announcements 工具查询股票600519最近的重要公告（limit 3），不要调用其他工具，也不需要读取公告正文；依据工具结果简要回答。',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $aiResp = $http->post('http://127.0.0.1:8081/ai_api.php', $requestBody, [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ]);
        announcementCheck((int)$aiResp['http_code'] === 200, 'AI Loopback SSE 必须返回 HTTP 200');
        announcementCheck(stripos((string)$aiResp['content_type'], 'text/event-stream') !== false, 'AI Loopback 必须返回 SSE Content-Type');

        $done = false;
        $errors = [];
        $finalText = '';
        $announcementFinishes = [];
        $finishedTools = [];
        $statusTools = [];
        $toolDiagnostics = [];
        foreach (preg_split('/\r?\n/', (string)$aiResp['body']) as $line) {
            if (strpos($line, 'data: ') !== 0) continue;
            $data = substr($line, 6);
            if ($data === '[DONE]') {
                $done = true;
                continue;
            }
            $decoded = json_decode($data, true);
            if (!is_array($decoded)) continue;
            if (isset($decoded['error'])) $errors[] = $decoded['error'];
            if (($decoded['type'] ?? '') === 'tool_status') {
                $statusTools[] = (string)($decoded['tool'] ?? 'unknown');
                $toolDiagnostics[] = [
                    'type' => 'status',
                    'round' => $decoded['round'] ?? null,
                    'tool' => $decoded['tool'] ?? null,
                    'args' => $decoded['args_summary'] ?? null,
                ];
            }
            if (($decoded['type'] ?? '') === 'tool_call_finished') {
                $finishedTools[] = (string)($decoded['tool'] ?? 'unknown') . ':' . (($decoded['success'] ?? false) ? 'success' : 'failed');
                $toolDiagnostics[] = [
                    'type' => 'finished',
                    'round' => $decoded['round'] ?? null,
                    'tool' => $decoded['tool'] ?? null,
                    'success' => $decoded['success'] ?? null,
                    'summary' => $decoded['output_summary'] ?? null,
                ];
            }
            if (($decoded['type'] ?? '') === 'tool_call_finished' && ($decoded['tool'] ?? '') === 'fa_get_stock_announcements') {
                $announcementFinishes[] = $decoded;
            }
            $delta = $decoded['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta)) $finalText .= $delta;
        }
        announcementCheck($done, 'AI Loopback SSE 必须正常收到 [DONE]');
        announcementCheck(empty($errors), 'AI Loopback SSE 不得返回错误事件');
        announcementCheck(count($announcementFinishes) === 1, '公告列表工具必须由模型恰好执行一轮；status=' . implode(',', $statusTools) . '；finished=' . implode(',', $finishedTools) . '；diagnostics=' . json_encode($toolDiagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        announcementCheck(($announcementFinishes[0]['success'] ?? false) === true, '公告列表工具的 tool_call_finished.success 必须为 true');
        announcementCheck(trim($finalText) !== '', 'AI Loopback SSE 必须返回最终回答文本');
        echo 'AI announcement SSE checks passed: tool_calls=1, success=true, done=true, answer_chars=' . mb_strlen($finalText) . "\n";
    }
} finally {
    CacheStoreFactory::reset();
    removeAnnouncementTestTree($cacheDir);
    removeAnnouncementTestTree($cacheDir . '_live');
}
