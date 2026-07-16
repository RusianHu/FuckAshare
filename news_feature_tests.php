<?php
/**
 * 新闻舆情大阶段回归测试。
 *
 * Run: php/php.exe news_feature_tests.php
 * Live: php/php.exe news_feature_tests.php --live
 */

require_once __DIR__ . '/lib/NewsService.php';
require_once __DIR__ . '/lib/AIToolExecutor.php';
require_once __DIR__ . '/lib/AIToolRegistry.php';
require_once __DIR__ . '/lib/AIChatToolAgent.php';

function newsCheck($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function removeNewsTestTree(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) removeNewsTestTree($target); else @unlink($target);
    }
    @rmdir($path);
}

class FakeNewsHttpClient extends HttpClient
{
    public function __construct() {}

    public function multiGet(array $requests, int $maxParallel = 4): array
    {
        $result = [];
        foreach ($requests as $request) {
            parse_str((string)parse_url($request['url'], PHP_URL_QUERY), $query);
            $callback = (string)($query['cb'] ?? 'callback');
            $payload = [
                'result' => ['cmsArticleWebOld' => [[
                    'title' => '<em>贵州茅台</em>获批新项目',
                    'mediaName' => '测试媒体',
                    'date' => '2026-07-16 10:00:00',
                    'code' => '202607163800000001',
                    'content' => '这段正文不得出现在客户端或服务输出中',
                    'url' => 'http://finance.eastmoney.com/a/202607163800000001.html',
                ]]],
            ];
            $result[(string)$request['key']] = [
                'body' => $callback . '(' . json_encode($payload, JSON_UNESCAPED_UNICODE) . ')',
                'http_code' => 200,
                'error' => null,
                'content_type' => 'application/javascript',
            ];
        }
        return $result;
    }
}

class RewrittenCallbackNewsHttpClient extends HttpClient
{
    public function __construct() {}

    public function multiGet(array $requests, int $maxParallel = 4): array
    {
        $result = [];
        foreach ($requests as $request) {
            $payload = [
                'result' => [
                    'passportWeb' => [[
                        'title' => '股吧结果不应冒充新闻',
                    ]],
                ],
            ];
            $result[(string)$request['key']] = [
                'body' => 'jQuery35109988776655_1770000000000(' . json_encode($payload, JSON_UNESCAPED_UNICODE) . ');',
                'http_code' => 200,
                'error' => null,
                'content_type' => 'application/javascript',
            ];
        }
        return $result;
    }
}

class FakeFastNewsHttpClient extends HttpClient
{
    public function __construct() {}

    public function multiGet(array $requests, int $maxParallel = 4): array
    {
        $rows = [
            [
                'code' => '202607163800000099',
                'title' => '贵州茅台拟推进新一轮回购计划',
                'summary' => '内部摘要不得出现在 Provider 输出中',
                'showTime' => '2026-07-16 18:30:00',
                'stockList' => ['1.600519'],
            ],
            [
                'code' => '202607163800000098',
                'title' => '易方达蓝筹精选混合披露定期报告',
                'summary' => '基金摘要不得外泄',
                'showTime' => '2026-07-16 18:20:00',
                'stockList' => ['0.005827'],
            ],
            [
                'code' => '202607163800000097',
                'title' => 'A股市场交投活跃度回升',
                'summary' => '市场快讯摘要',
                'showTime' => '2026-07-16 18:10:00',
                'stockList' => [],
            ],
        ];
        $result = [];
        foreach ($requests as $request) {
            $result[(string)$request['key']] = [
                'body' => json_encode(['code' => 1, 'data' => ['fastNewsList' => $rows]], JSON_UNESCAPED_UNICODE),
                'http_code' => 200,
                'error' => null,
                'content_type' => 'application/json',
            ];
        }
        return $result;
    }
}

class FakeF10NewsHttpClient extends HttpClient
{
    public function __construct() {}

    public function multiGet(array $requests, int $maxParallel = 4): array
    {
        $payload = [
            'gszx' => [
                'data' => [
                    'items' => [[
                        'uniqueUrl' => 'http://finance.eastmoney.com/a/202607163809159994.html',
                        'title' => '贵州茅台公司资讯更新',
                        'source' => '测试资讯',
                        'showDateTime' => 1784195574000,
                        'summary' => 'F10 摘要不得出现在客户端',
                    ]],
                ],
            ],
            'gsgg' => [],
        ];
        $result = [];
        foreach ($requests as $request) {
            $result[(string)$request['key']] = [
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'http_code' => 200,
                'error' => null,
                'content_type' => 'application/json',
            ];
        }
        return $result;
    }
}

class FakeNewsProvider implements NewsDataProvider
{
    public $queries = [];

    public function sourceName(): string { return 'fake_news'; }

    public function search(string $keyword, int $limit = 20): DataSourceResult
    {
        return $this->searchMany([$keyword], $limit);
    }

    public function searchMany(array $keywords, int $limitPerKeyword = 20): DataSourceResult
    {
        $this->queries[] = array_values($keywords);
        $items = [];
        foreach ($keywords as $keyword) {
            $rows = $this->rows((string)$keyword);
            foreach (array_slice($rows, 0, $limitPerKeyword) as $row) {
                $row['_query'] = (string)$keyword;
                $items[] = $row;
            }
        }
        return DataSourceResult::success($this->sourceName(), 'search_news', $items, [
            'query_statuses' => array_map(function ($keyword) {
                return ['keyword' => $keyword, 'success' => true, 'http_code' => 200];
            }, $keywords),
        ]);
    }

    private function rows(string $keyword): array
    {
        $base = function (string $title, string $date, string $url): array {
            return ['title' => $title, 'source' => '测试媒体', 'published_at' => $date, 'url' => $url];
        };
        if ($keyword === '贵州茅台') return [
            $base('贵州茅台增长超预期并获批扩产', '2026-07-16 12:00:00', 'https://example.com/mt-positive'),
            $base('白酒行业今日观察', '2026-07-16 11:00:00', 'https://example.com/generic-stock'),
        ];
        if ($keyword === '600519') return [
            $base('融资客净买入超亿元', '2026-07-16 10:00:00', 'https://example.com/mt-code'),
        ];
        if ($keyword === '易方达蓝筹精选混合') return [
            $base('易方达蓝筹精选规模增长', '2026-07-15 12:00:00', 'https://example.com/fund-exact'),
            $base('今日数百只基金净值上涨', '2026-07-16 13:00:00', 'https://example.com/generic-fund'),
        ];
        if ($keyword === '005827') return [
            $base('张坤旗下产品披露定期报告', '2026-07-14 09:00:00', 'https://example.com/fund-code'),
        ];
        if ($keyword === '测试负面') return [
            $base('多家公司立案调查并遭处罚', '2026-07-16 15:00:00', 'https://example.com/bad-1'),
            $base('指数大跌失守关键点位', '2026-07-16 14:00:00', 'https://example.com/bad-2'),
            $base('市场交易保持平稳', '2026-07-16 13:00:00', 'https://example.com/neutral'),
        ];
        if ($keyword === 'A股') return [
            $base('A股今日多只个股涨停', '2026-07-16 16:00:00', 'https://example.com/market-1'),
            $base('沪指尾盘失守关键点位', '2026-07-16 15:00:00', 'https://example.com/market-2'),
        ];
        if ($keyword === '沪指') return [
            $base('A股今日多只个股涨停', '2026-07-16 16:00:00', 'https://example.com/market-1'),
        ];
        return [];
    }
}

class FakeNewsMarketService extends MarketDataService
{
    public function __construct(array $opts = []) {}

    public function quote(string $codes, string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        return DataSourceResult::success('fake_market', 'quote', [['code' => '600519', 'name' => '贵州茅台']]);
    }
}

class FakeNewsFundService extends FundService
{
    public function __construct(?CsindexClient $csindex = null) {}

    public function info(array $codes): DataSourceResult
    {
        return DataSourceResult::success('fake_fund', 'info', [[
            'code' => '005827',
            'name' => '易方达蓝筹精选混合',
            'full_name' => '易方达蓝筹精选混合型证券投资基金',
        ]]);
    }
}

$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_news_test_' . getmypid();
removeNewsTestTree($cacheDir);
CacheStoreFactory::useFileStore($cacheDir);

try {
    $client = new EastmoneyNewsClient(new FakeNewsHttpClient(), new CircuitBreaker('news_test_client', 3, 60));
    $clientResult = $client->search('600519', 10);
    newsCheck($clientResult->success && count($clientResult->data) === 1, '东方财富新闻 JSONP 必须可解析');
    newsCheck(($clientResult->data[0]['url'] ?? '') === 'https://finance.eastmoney.com/a/202607163800000001.html', '新闻链接必须升级为 HTTPS');
    newsCheck(!isset($clientResult->data[0]['content']) && strpos(json_encode($clientResult->data, JSON_UNESCAPED_UNICODE), '正文') === false, 'Provider 不得返回新闻正文');

    $regionBreaker = new CircuitBreaker('news_region_test_' . getmypid(), 3, 60);
    $regionClient = new EastmoneyNewsClient(new RewrittenCallbackNewsHttpClient(), $regionBreaker);
    $regionResult = $regionClient->search('600519', 10);
    newsCheck($regionResult->success && count($regionResult->data) === 0, '海外 callback 重写响应必须解析为有效空新闻结果');
    newsCheck(($regionResult->meta['capability_filtered'] ?? false) === true, '仅 passportWeb 时必须标记地域能力裁剪');
    newsCheck(($regionResult->meta['query_statuses'][0]['callback_rewritten'] ?? false) === true, '必须记录实际 callback 与请求 callback 不一致');
    newsCheck(($regionBreaker->getState()['failures'] ?? -1) === 0, '地域能力裁剪不得累计熔断失败');

    $fastClient = new EastmoneyFastNewsClient(
        new FakeFastNewsHttpClient(),
        new CircuitBreaker('news_fast_test_' . getmypid(), 3, 60)
    );
    $fastStock = $fastClient->searchMany(['贵州茅台', '600519'], 10);
    newsCheck($fastStock->success && count($fastStock->data) === 1, '7×24 快讯必须按标题或关联证券代码匹配股票');
    newsCheck(($fastStock->data[0]['url'] ?? '') === 'https://finance.eastmoney.com/a/202607163800000099.html', '7×24 快讯必须生成可访问的东方财富原文链接');
    newsCheck(strpos(json_encode($fastStock->data, JSON_UNESCAPED_UNICODE), '摘要') === false, '7×24 Provider 不得透传 summary');

    $f10Client = new EastmoneyF10NewsClient(
        new FakeF10NewsHttpClient(),
        new CircuitBreaker('news_f10_test_' . getmypid(), 3, 60)
    );
    $f10Stock = $f10Client->searchMany(['贵州茅台', '600519'], 10);
    newsCheck($f10Stock->success && count($f10Stock->data) === 1, 'F10 Provider 必须按股票代码返回公司资讯');
    newsCheck(($f10Stock->data[0]['url'] ?? '') === 'https://finance.eastmoney.com/a/202607163809159994.html', 'F10 公司资讯链接必须升级为 HTTPS');
    newsCheck(strpos(json_encode($f10Stock->data, JSON_UNESCAPED_UNICODE), '摘要') === false, 'F10 Provider 不得透传 summary');
    $f10Fund = $f10Client->searchMany(['易方达蓝筹精选混合', '005827'], 10);
    newsCheck($f10Fund->success && count($f10Fund->data) === 0 && ($f10Fund->meta['skipped_reason'] ?? '') === 'fund_query_not_supported', 'F10 股票 Provider 不得把基金代码误当股票');

    $composite = new CompositeNewsProvider($regionClient, $fastClient);
    $routed = $composite->searchMany(['贵州茅台', '600519'], 10);
    newsCheck($routed->success && count($routed->data) === 1, '海外搜索能力裁剪时必须由 7×24 Provider 自动接管');
    newsCheck(($routed->meta['active_provider'] ?? '') === 'eastmoney_fast_news', '组合 Provider 必须标记实际接管源');
    newsCheck(($routed->meta['provider_route_reason'] ?? '') === 'cmsArticleWebOld_missing_passportWeb_only', '组合 Provider 必须保留地域裁剪路由原因');
    $threeStageComposite = new CompositeNewsProvider($regionClient, $f10Client, $fastClient);
    $f10Routed = $threeStageComposite->searchMany(['贵州茅台', '600519'], 10);
    newsCheck($f10Routed->success && count($f10Routed->data) === 1, '海外个股搜索裁剪时必须优先由 F10 公司资讯接管');
    newsCheck(($f10Routed->meta['active_provider'] ?? '') === 'eastmoney_f10_news' && ($f10Routed->meta['provider_route_stage'] ?? '') === 'secondary', '三段路由必须记录 F10 接管阶段');
    $routedService = new NewsService(
        $composite,
        new FakeNewsMarketService(),
        new FakeNewsFundService(),
        new FileCacheStore($cacheDir . '_route')
    );
    $routedStock = $routedService->assetNews('stock', '600519', '', 10);
    newsCheck($routedStock->success && count($routedStock->data) === 1, '地域裁剪接管结果必须通过标的新闻服务公开返回');
    newsCheck(($routedStock->meta['active_provider'] ?? '') === 'eastmoney_fast_news', '公开 API 元数据必须保留实际接管 Provider');
    newsCheck(array_keys($routedStock->data[0]) === ['title', 'source', 'published_at', 'url'], '快讯接管后仍必须严格保持四字段边界');

    $provider = new FakeNewsProvider();
    $service = new NewsService($provider, new FakeNewsMarketService(), new FakeNewsFundService(), CacheStoreFactory::getInstance());

    $stock = $service->assetNews('stock', '600519', '', 10);
    newsCheck($stock->success && ($stock->meta['asset']['name'] ?? '') === '贵州茅台', '股票代码必须自动映射名称');
    newsCheck(in_array(['贵州茅台', '600519'], $provider->queries, true), '股票新闻必须合并名称与代码查询');
    newsCheck(count($stock->data) === 2, '股票新闻必须过滤名称查询中的泛行业新闻');
    foreach ($stock->data as $item) {
        newsCheck(array_keys($item) === ['title', 'source', 'published_at', 'url'], '公开新闻项只能包含四个字段');
    }

    $fund = $service->assetNews('fund', '005827', '', 10);
    newsCheck($fund->success && ($fund->meta['asset']['name'] ?? '') === '易方达蓝筹精选混合', '基金代码必须自动映射基金名');
    newsCheck(count($fund->data) === 2, '基金新闻必须保留精确名称和代码查询结果');
    newsCheck(strpos(json_encode($fund->data, JSON_UNESCAPED_UNICODE), '数百只基金') === false, '基金新闻不得混入泛基金资讯');

    $market = $service->marketHotNews(['A股', '沪指'], 10);
    newsCheck($market->success && count($market->data) === 2, '市场新闻必须跨关键词去重');
    newsCheck(($market->data[0]['published_at'] ?? '') === '2026-07-16 16:00:00', '市场新闻必须按时间倒序');

    $sentiment = $service->sentimentSnapshot('market', 'stock', '', '', ['测试负面'], 20);
    newsCheck($sentiment->success && ($sentiment->data['label'] ?? '') === 'negative', '负面标题样本必须得到负面快照');
    newsCheck(($sentiment->data['counts']['negative'] ?? 0) === 2, '情绪计数必须可解释');
    newsCheck(($sentiment->meta['methodology'] ?? '') === 'deterministic_chinese_title_lexicon_v1', '情绪快照必须声明确定性方法');

    $definitions = AIToolRegistry::definitions();
    foreach (['fa_get_asset_news', 'fa_get_market_hot_news', 'fa_get_sentiment_snapshot'] as $tool) {
        newsCheck(isset($definitions[$tool]), "AI 工具 {$tool} 必须注册");
        newsCheck(($definitions[$tool]['parameters']['additionalProperties'] ?? null) === false, "AI 工具 {$tool} 必须为严格 schema");
    }

    $executor = new AIToolExecutor(new FakeNewsMarketService(), new FakeNewsFundService(), 30000, null, null, $service);
    $toolResult = $executor->execute('fa_get_asset_news', ['asset_type' => 'stock', 'code' => '600519', 'name' => null, 'limit' => 5]);
    newsCheck(($toolResult['success'] ?? false) === true && count($toolResult['data'] ?? []) === 2, 'AI 标的新闻工具必须可执行');

    $agent = new AIChatToolAgent(['api_url' => 'https://example.invalid', 'api_key' => 'test', 'model' => 'test']);
    $infer = new ReflectionMethod($agent, 'inferArgumentsForRequestedTool');
    $infer->setAccessible(true);
    $inferredAsset = $infer->invoke($agent, 'fa_get_asset_news', '请研究基金 005827 的最新新闻与舆情');
    newsCheck(($inferredAsset['asset_type'] ?? '') === 'fund' && ($inferredAsset['code'] ?? '') === '005827', '模型参数损坏时必须可恢复基金新闻工具参数');
    $inferredMarket = $infer->invoke($agent, 'fa_get_sentiment_snapshot', '请分析当前 A 股市场新闻情绪');
    newsCheck(($inferredMarket['scope'] ?? '') === 'market' && array_key_exists('keywords', $inferredMarket), '模型参数损坏时必须可恢复市场情绪工具参数');

    $html = file_get_contents(__DIR__ . '/index.php');
    $js = file_get_contents(__DIR__ . '/main.js');
    $css = file_get_contents(__DIR__ . '/style.css');
    newsCheck(strpos($html, 'id="panel-news"') !== false && strpos($html, 'id="news-sentiment-score"') !== false, '前端必须包含新闻页与情绪卡片');
    newsCheck(strpos($js, 'const NewsModule = {') !== false && strpos($js, "news_api.php?") !== false, '前端必须初始化新闻模块并调用只读 API');
    newsCheck(strpos($html, 'id="stock-asset-pulse"') !== false && strpos($html, 'id="fund-asset-pulse"') !== false, '行情与基金详情必须包含标的舆情挂载点');
    newsCheck(strpos($js, 'const AssetPulseModule = {') !== false && strpos($js, "action: 'sentiment'") !== false, '全站标的舆情模块必须聚合新闻与情绪快照');
    newsCheck(strpos($js, 'host.dataset.pulseRequest === key') !== false && strpos($js, 'inFlight: new Map()') !== false, '快速切换标的必须防止旧响应覆盖并复用进行中的请求');
    newsCheck(strpos($js, 'stockQueryRequestId') !== false && strpos($js, 'detailRequestId') !== false, '股票与基金主详情也必须防止快速切换时旧响应回写');
    newsCheck(strpos($js, 'id="dividend-asset-pulse"') !== false, '分红详情必须嵌入精简标的舆情');
    newsCheck(strpos($css, '.asset-pulse-card') !== false && strpos($css, '@media (max-width: 768px)') !== false, '标的舆情组件必须包含响应式样式');

    echo "All news feature tests passed.\n";

    if (in_array('--live', $argv, true)) {
        CacheStoreFactory::useFileStore($cacheDir . '_live');
        $live = new NewsService();
        $liveStock = $live->assetNews('stock', '600519', '', 5);
        $liveFund = $live->assetNews('fund', '005827', '', 5);
        $liveMarket = $live->marketHotNews(['A股', '沪指'], 5);
        $liveFast = (new EastmoneyFastNewsClient())->searchMany(['A股'], 5);
        newsCheck($liveStock->success && count($liveStock->data) > 0, 'Live 股票新闻必须返回数据');
        newsCheck($liveFund->success, 'Live 基金新闻请求必须成功（允许精确结果为空）');
        newsCheck($liveMarket->success && count($liveMarket->data) > 0, 'Live 市场新闻必须返回数据');
        newsCheck($liveFast->success && count($liveFast->data) > 0, 'Live 东方财富 7×24 快讯必须返回数据');
        foreach (array_merge($liveStock->data, $liveFund->data, $liveMarket->data) as $item) {
            newsCheck(array_keys($item) === ['title', 'source', 'published_at', 'url'], 'Live 新闻结果不得泄漏额外字段');
        }
        echo 'Live news checks passed: stock=' . count($liveStock->data) . ', fund=' . count($liveFund->data) . ', market=' . count($liveMarket->data) . ', fast=' . count($liveFast->data) . "\n";
    }
} finally {
    CacheStoreFactory::reset();
    removeNewsTestTree($cacheDir);
    removeNewsTestTree($cacheDir . '_live');
    removeNewsTestTree($cacheDir . '_route');
}
