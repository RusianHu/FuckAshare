<?php

require_once __DIR__ . '/../lib/AIToolRegistry.php';
require_once __DIR__ . '/../lib/AIToolExecutor.php';
require_once __DIR__ . '/../lib/AIChatToolAgent.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, $message);
}

function assert_before(string $first, string $second, string $haystack, string $message): void
{
    $a = strpos($haystack, $first);
    $b = strpos($haystack, $second);
    assert_true($a !== false && $b !== false && $a < $b, $message);
}

function assert_strict_objects(array $schema, string $path): void
{
    $type = $schema['type'] ?? null;
    $isObject = $type === 'object' || (is_array($type) && in_array('object', $type, true));
    if ($isObject) {
        $properties = $schema['properties'] ?? [];
        assert_true(is_array($properties), "{$path} object schema has properties");
        assert_true(($schema['additionalProperties'] ?? true) === false, "{$path} additionalProperties=false");
        assert_true(isset($schema['required']) && count($schema['required']) === count($properties), "{$path} requires every property");
    }

    foreach (($schema['properties'] ?? []) as $key => $child) {
        if (is_array($child)) {
            assert_strict_objects($child, "{$path}.{$key}");
        }
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
        assert_strict_objects($schema['items'], "{$path}[]");
    }
}

class FakeMarketDataService extends MarketDataService
{
    public function __construct(array $opts = [])
    {
    }

    public function kline(string $code, string $frequency = '1d', int $count = 120, string $endDate = '', string $source = self::SOURCE_AUTO, bool $fallback = true, bool $raw = false): DataSourceResult
    {
        $rows = [];
        for ($i = 1; $i <= max(30, $count); $i++) {
            $close = 10 + $i * 0.2;
            $rows[] = [
                'date' => sprintf('2026-01-%02d', (($i - 1) % 28) + 1),
                'open' => $close - 0.1,
                'high' => $close + 0.3,
                'low' => $close - 0.3,
                'close' => $close,
                'volume' => 100000 + $i,
            ];
        }
        return DataSourceResult::success('fake', 'kline', $rows);
    }
    public function marketBreadth(string $scope = 'a_share', bool $includeLimitStats = true, bool $includeIndexQuotes = true): DataSourceResult
    {
        return DataSourceResult::success('fake', 'market_breadth', [
            'scope' => $scope,
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'indices' => $includeIndexQuotes ? [[
                'code' => '000001',
                'market' => 'SH',
                'name' => '上证指数',
                'price' => 3000.0,
                'change_pct' => 1.0,
                'up_count' => 900,
                'down_count' => 300,
                'flat_count' => 50,
                'total_count' => 1250,
                'advance_decline_ratio' => 3.0,
            ]] : [],
            'aggregate' => [
                'method' => 'full_a_share_scan',
                'up_count' => 3000,
                'down_count' => 1800,
                'flat_count' => 100,
                'unknown_count' => 10,
                'tradable_count' => 4900,
                'total_count' => 4910,
                'up_ratio_pct' => 61.22,
                'down_ratio_pct' => 36.73,
                'advance_decline_ratio' => 1.6667,
                'breadth_score' => 62.25,
                'sentiment_label' => 'positive',
                'sample_scope' => $scope,
            ],
            'limit_stats' => $includeLimitStats ? [
                'method' => 'approx_by_pct_threshold',
                'limit_up_count' => 80,
                'limit_down_count' => 12,
                'near_limit_up_count' => 210,
                'near_limit_down_count' => 40,
                'note' => '涨停/跌停统计为公开行情涨跌幅阈值近似口径，可能不完全覆盖 ST、北交所、上市新股等特殊规则。',
            ] : [
                'method' => 'not_requested',
                'limit_up_count' => null,
                'limit_down_count' => null,
                'near_limit_up_count' => null,
                'near_limit_down_count' => null,
                'note' => '调用参数未要求计算涨停/跌停近似统计。',
            ],
        ], [
            'capability_level' => $includeLimitStats ? 'full_scan' : 'indices_only',
            'partial' => false,
            'failures' => [],
        ]);
    }
}

class FakeToolExecutor extends AIToolExecutor
{
    public $calls = [];

    public function __construct()
    {
    }

    public function executeForModel(string $name, array $args): string
    {
        $this->calls[] = [$name, $args];
        return json_encode([
            'success' => true,
            'source' => 'fake',
            'action' => $name,
            'data' => ['ok' => true, 'args' => $args],
            'meta' => ['updated_at' => '2026-01-01T00:00:00+00:00'],
        ], JSON_UNESCAPED_UNICODE);
    }
}

class MarketScanDeepDiveExecutor extends AIToolExecutor
{
    public $calls = [];

    public function __construct()
    {
    }

    public function executeForModel(string $name, array $args): string
    {
        $this->calls[] = [$name, $args];
        $data = ['ok' => true, 'args' => $args];
        if ($name === 'fa_get_hot_stocks') {
            $data = [];
            for ($i = 1; $i <= 10; $i++) {
                $data[] = [
                    'code' => sprintf('60%04d', $i),
                    'name' => '候选' . $i,
                    'f62' => 100000000 - $i * 1000000,
                    'f3' => 1.0 + $i / 10,
                ];
            }
        }
        return json_encode([
            'success' => true,
            'source' => 'fake',
            'action' => $name,
            'data' => $data,
            'meta' => ['updated_at' => '2026-01-01T00:00:00+00:00'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

class FundRankExecutor extends AIToolExecutor
{
    public $calls = [];

    public function __construct()
    {
    }

    public function executeForModel(string $name, array $args): string
    {
        $this->calls[] = [$name, $args];
        $data = ['ok' => true, 'args' => $args];
        if ($name === 'fa_get_fund_rank') {
            $data = [];
            for ($i = 1; $i <= 6; $i++) {
                $data[] = [
                    'code' => sprintf('00%04d', $i),
                    'name' => '基金' . $i,
                    'day_growth' => (string)(6.0 - $i / 10),
                    'selected_growth' => (string)(6.0 - $i / 10),
                ];
            }
        }
        return json_encode([
            'success' => true,
            'source' => 'fake',
            'action' => $name,
            'data' => $data,
            'meta' => ['updated_at' => '2026-01-01T00:00:00+00:00'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

class FakeFundServiceForNewTools extends FundService
{
    public function __construct()
    {
    }

    public function indexProfile(string $code): DataSourceResult
    {
        if ($code !== '008163') {
            return DataSourceResult::error('fake_fund', 'index_profile', 'invalid_code', 'unexpected code');
        }
        return DataSourceResult::success('fake_fund', 'index_profile', [
            'fund_code' => '008163',
            'fund_name' => '南方标普红利低波50ETF联接A',
            'index_code' => 'SPCLLHCP',
            'index_name' => '标普中国A股大盘红利低波50指数',
            'benchmark' => '标普中国A股大盘红利低波50指数收益率*95%+银行活期存款利率(税后)*5%',
            'investment_strategy' => '日均跟踪偏离度不超过0.35%,年跟踪误差不超过4%。',
        ]);
    }

    public function dividendHistory(string $code, int $page = 1, int $pageSize = 100): DataSourceResult
    {
        return DataSourceResult::success('fake_fund', 'dividend_history', [[
            'date' => '2026-07-02',
            'nav' => '0.9932',
            'acc_nav' => '1.6922',
            'growth_rate' => '1.13',
            'dividend' => '每份派现金0.0040元',
            'cash_per_unit' => 0.004,
        ]], ['code' => $code, 'page' => $page, 'page_size' => $pageSize]);
    }

    public function fundDocuments(string $code, int $page = 1, int $pageSize = 20, string $docType = 'all', bool $includeContent = false, int $contentLimit = 6000): DataSourceResult
    {
        return DataSourceResult::success('fake_fund', 'documents', [[
            'title' => '南方标普中国A股大盘红利低波50交易型开放式指数证券投资基金联接基金2022年第3季度报告',
            'announcement_type' => '定期报告',
            'date' => '2022-10-26',
            'url' => 'https://fund.eastmoney.com/gonggao/008163,AN202210261579469610.html',
            'pdf_url' => 'https://pdf.dfcfw.com/pdf/H2_AN202210261579469610_1.pdf',
            'doc_type' => 'periodic_report',
            'content_status' => $includeContent ? 'parser_unavailable' : 'not_requested',
            'content' => '',
        ]], ['code' => $code, 'doc_type' => $docType, 'include_content' => $includeContent, 'content_limit' => $contentLimit]);
    }
}

$tools = AIToolRegistry::chatTools();
assert_true(count($tools) >= 17, 'registry exposes planned tool set');
foreach ($tools as $tool) {
    assert_true(($tool['type'] ?? '') === 'function', 'tool type is function');
    $fn = $tool['function'] ?? [];
    assert_true(($fn['strict'] ?? false) === true, 'tool strict mode is enabled');
    $params = $fn['parameters'] ?? [];
    assert_true(($params['type'] ?? '') === 'object', 'tool parameters are object schemas');
    assert_true(($params['additionalProperties'] ?? true) === false, 'top-level additionalProperties=false');
    assert_true(isset($params['required']) && count($params['required']) === count($params['properties'] ?? []), 'strict schema requires every property');
    assert_strict_objects($params, $fn['name'] ?? 'tool');
}

$normalizedOptions = AIAgentOptions::normalize([
    'max_tool_calls_total' => 0,
    'max_deep_dive_candidates' => 5,
    'emit_agent_events' => 1,
]);
assert_true($normalizedOptions['max_tool_calls_total'] === 1, 'agent options clamps numeric budgets to positive values');
assert_true($normalizedOptions['max_deep_dive_candidates'] === 5, 'agent options preserves configured deep dive budget');
assert_true($normalizedOptions['emit_agent_events'] === true, 'agent options normalizes booleans');
assert_true($normalizedOptions['trace_enabled'] === false, 'agent options disables trace persistence by default');
assert_true($normalizedOptions['auto_prefetch'] === false, 'agent options disables server auto-prefetch by default');

$profile = AIAgentProfile::resolve([
    ['role' => 'user', 'content' => '查询资金流向前10的股票，充分深入评估它们，给出建议'],
], $normalizedOptions);
assert_true($profile->name() === 'market_scanner', 'agent profile resolves market scanner for capital inflow scan');
assert_true($profile->toolsAreFullyAvailable() === true, 'agent profile never hides the full tool set');
assert_contains('全部只读研究工具都保持可用', $profile->systemPromptSuffix(), 'agent profile prompt keeps all tools available');

$fundProfile = AIAgentProfile::resolve([
    ['role' => 'user', 'content' => '从基金角度分析医药基金，同时对比 600519 的股票行情背景'],
], $normalizedOptions);
assert_true($fundProfile->name() === 'fund_researcher', 'agent profile resolves fund researcher for fund requests');
assert_contains('也可以调用股票、板块和热度工具', $fundProfile->systemPromptSuffix(), 'fund profile explicitly allows cross-tool research');

$capturedFundPayload = null;
$fundToolExposureStream = '';
$fundToolExposureAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 1, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    new FakeToolExecutor(),
    function(array $payload) use (&$capturedFundPayload): array {
        $capturedFundPayload = $payload;
        return [
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'final'],
            ]],
        ];
    }
);
$fundToolExposureAgent->run([
    ['role' => 'user', 'content' => '从基金角度分析医药基金，同时对比 600519 的股票行情背景'],
], function(string $chunk) use (&$fundToolExposureStream): void {
    $fundToolExposureStream .= $chunk;
});
$payloadToolNames = array_map(function($tool) {
    return $tool['function']['name'] ?? '';
}, $capturedFundPayload['tools'] ?? []);
assert_true(in_array('fa_get_fund_rank', $payloadToolNames, true), 'fund profile payload includes fund tools');
assert_true(in_array('fa_get_index_profile', $payloadToolNames, true), 'fund profile payload includes index profile tool');
assert_true(in_array('fa_get_fund_dividend_history', $payloadToolNames, true), 'fund profile payload includes dividend history tool');
assert_true(in_array('fa_get_fund_documents', $payloadToolNames, true), 'fund profile payload includes fund documents tool');
assert_true(in_array('fa_get_stock_quote', $payloadToolNames, true), 'fund profile payload still includes stock quote tool');
assert_true(in_array('fa_get_hot_stocks', $payloadToolNames, true), 'fund profile payload still includes stock discovery tool');
assert_true(in_array('fa_get_market_breadth', $payloadToolNames, true), 'fund profile payload still includes market breadth tool');

$streamEmitter = new AIAgentStreamEmitter($normalizedOptions);
$sanitized = $streamEmitter->sanitizeAssistantChunk("data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"secret\",\"content\":\"visible\"}}]}\n\n");
assert_contains('visible', $sanitized, 'stream emitter preserves visible content');
assert_contains('secret', $sanitized, 'stream emitter exposes reasoning_content by default');
$suppressingEmitter = new AIAgentStreamEmitter(array_merge($normalizedOptions, ['suppress_reasoning_content' => true]));
$suppressed = $suppressingEmitter->sanitizeAssistantChunk("data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"secret\",\"content\":\"visible\"}}]}\n\n");
assert_true(strpos($suppressed, 'secret') === false && strpos($suppressed, 'reasoning_content') === false, 'stream emitter can still suppress raw reasoning content when configured');
$pseudoToolStream = '';
$streamEmitter->syntheticContent(function(string $chunk) use (&$pseudoToolStream): void {
    $pseudoToolStream .= $chunk;
}, "<function=fa_get_hot_stocks>\n\n补充说明：已回退。");
assert_true(strpos($pseudoToolStream, '<function=') === false, 'synthetic content strips pseudo tool markup');
assert_contains('补充说明', $pseudoToolStream, 'synthetic content preserves user-facing fallback text');

$trace = new AIAgentTraceRecorder('run_test', $normalizedOptions);
$trace->record('tool_call_started', ['tool' => 'fa_get_hot_stocks']);
$trace->record('tool_call_finished', ['tool' => 'fa_get_hot_stocks', 'success' => true]);
$trace->record('run_finished', ['stop_reason' => 'final_answer']);
$traceSummary = $trace->summary();
assert_true($traceSummary['tool_call_started'] === 1, 'trace recorder counts started tool calls');
assert_true($traceSummary['tool_call_finished'] === 1, 'trace recorder counts finished tool calls');
assert_true($traceSummary['stop_reason'] === 'final_answer', 'trace recorder captures stop reason');

$checkpointState = new AIAgentState('run_checkpoint_test');
$checkpointTrace = new AIAgentTraceRecorder($checkpointState->runId, $normalizedOptions);
$streamEmitter->setTraceRecorder($checkpointTrace);
$checkpointStream = '';
$checkpointManager = new AIAgentCheckpointManager($checkpointState, $streamEmitter, function(string $chunk) use (&$checkpointStream): void {
    $checkpointStream .= $chunk;
}, $checkpointTrace);
$checkpoint = $checkpointManager->create('model_response', [
    ['role' => 'system', 'content' => 's'],
    ['role' => 'user', 'content' => 'u'],
], ['tool_call_count' => 0]);
assert_true($checkpoint['label'] === 'model_response', 'checkpoint manager creates labeled checkpoints');
assert_contains('checkpoint_created', $checkpointStream, 'checkpoint manager emits checkpoint_created event');
assert_true($checkpointTrace->summary()['checkpoints'] === 1, 'checkpoint events enter trace recorder');
$streamEmitter->setTraceRecorder(null);

$guardrail = new AIAgentGuardrailPolicy();
$guardrailReview = $guardrail->reviewFinalText('这只股票一定上涨，必须满仓买入。');
assert_true($guardrailReview['ok'] === false, 'guardrail detects unsafe deterministic financial advice');
assert_contains('不构成投资建议', $guardrailReview['append_text'], 'guardrail corrective suffix includes required disclaimer');
assert_true($guardrail->toolAccessIsReadOnly(['fa_get_stock_quote', 'fa_get_fund_info']) === true, 'guardrail accepts readonly project tools');

$executor = new AIToolExecutor(new FakeMarketDataService(), null, 30000);
$normalized = $executor->execute('fa_normalize_stock_code', ['code' => '600519']);
assert_true($normalized['success'] === true, 'normalize stock code succeeds');
assert_true($normalized['data']['market'] === 'SH', 'normalize infers SH market');

$invalid = $executor->execute('fa_normalize_stock_code', ['code' => '600519;rm']);
assert_true($invalid['success'] === false && $invalid['code'] === 'tool_error', 'invalid stock code is rejected structurally');

$indicators = $executor->execute('fa_calculate_kline_indicators', [
    'code' => '600519',
    'frequency' => '1d',
    'count' => 80,
    'source' => 'auto',
]);
assert_true($indicators['success'] === true, 'indicator tool succeeds with fake kline data');
assert_true(isset($indicators['data']['ma']['ma5']), 'indicator tool returns MA values');
assert_true(isset($indicators['data']['macd']['dif']), 'indicator tool returns MACD values');

$marketBreadth = $executor->execute('fa_get_market_breadth', [
    'scope' => 'a_share',
    'include_limit_stats' => true,
    'include_index_quotes' => true,
]);
assert_true($marketBreadth['success'] === true, 'market breadth tool succeeds with fake data');
assert_true(($marketBreadth['data']['aggregate']['method'] ?? '') === 'full_a_share_scan', 'market breadth returns aggregate method');
assert_true(($marketBreadth['data']['limit_stats']['method'] ?? '') === 'approx_by_pct_threshold', 'market breadth returns approximate limit stats');

$invalidMarketBreadthScope = $executor->execute('fa_get_market_breadth', [
    'scope' => 'bad_scope',
    'include_limit_stats' => true,
    'include_index_quotes' => true,
]);
assert_true($invalidMarketBreadthScope['success'] === false && $invalidMarketBreadthScope['code'] === 'tool_error', 'invalid market breadth scope is rejected structurally');

$invalidMarketBreadthBool = $executor->execute('fa_get_market_breadth', [
    'scope' => 'a_share',
    'include_limit_stats' => 'true',
    'include_index_quotes' => true,
]);
assert_true($invalidMarketBreadthBool['success'] === false && $invalidMarketBreadthBool['code'] === 'tool_error', 'invalid market breadth boolean is rejected structurally');

$compare = $executor->execute('fa_compare_candidates', [
    'asset_type' => 'stock',
    'sort_metric' => 'score',
    'order' => 'desc',
    'candidates' => [
        ['code' => 'A', 'name' => 'Alpha', 'metrics' => ['score' => 1]],
        ['code' => 'B', 'name' => 'Beta', 'metrics' => ['score' => 3]],
    ],
]);
assert_true($compare['data']['items'][0]['code'] === 'B', 'candidate comparison sorts deterministically');

$fundToolExecutor = new AIToolExecutor(new FakeMarketDataService(), new FakeFundServiceForNewTools(), 30000);
$indexProfile = $fundToolExecutor->execute('fa_get_index_profile', ['code' => '008163']);
assert_true($indexProfile['success'] === true, 'index profile tool succeeds');
assert_true(($indexProfile['data']['index_code'] ?? '') === 'SPCLLHCP', 'index profile returns index code');
assert_contains('标普中国A股大盘红利低波50指数', (string)($indexProfile['data']['index_name'] ?? ''), 'index profile returns index name');

$dividendHistory = $fundToolExecutor->execute('fa_get_fund_dividend_history', [
    'code' => '008163',
    'page' => 1,
    'page_size' => 100,
]);
assert_true($dividendHistory['success'] === true, 'dividend history tool succeeds');
assert_contains('每份派现金0.0040元', (string)($dividendHistory['data'][0]['dividend'] ?? ''), 'dividend history returns cash dividend text');
assert_true(($dividendHistory['data'][0]['cash_per_unit'] ?? 0) == 0.004, 'dividend history parses cash per unit');

$fundDocuments = $fundToolExecutor->execute('fa_get_fund_documents', [
    'code' => '008163',
    'page' => 1,
    'page_size' => 20,
    'doc_type' => 'periodic_report',
    'include_content' => true,
    'content_limit' => 6000,
]);
assert_true($fundDocuments['success'] === true, 'fund documents tool succeeds');
assert_contains('季度报告', (string)($fundDocuments['data'][0]['title'] ?? ''), 'fund documents returns report title');
assert_contains('.pdf', (string)($fundDocuments['data'][0]['pdf_url'] ?? ''), 'fund documents returns pdf url');
assert_true(($fundDocuments['data'][0]['content_status'] ?? '') === 'parser_unavailable', 'fund documents exposes parser unavailable status');

$invalidFundCode = $fundToolExecutor->execute('fa_get_index_profile', ['code' => '00816x']);
assert_true($invalidFundCode['success'] === false && $invalidFundCode['code'] === 'tool_error', 'invalid fund code is rejected');

$invalidDocType = $fundToolExecutor->execute('fa_get_fund_documents', [
    'code' => '008163',
    'page' => 1,
    'page_size' => 20,
    'doc_type' => 'bad',
    'include_content' => false,
    'content_limit' => 6000,
]);
assert_true($invalidDocType['success'] === false && $invalidDocType['code'] === 'tool_error', 'invalid document type is rejected');

$invalidContentLimit = $fundToolExecutor->execute('fa_get_fund_documents', [
    'code' => '008163',
    'page' => 1,
    'page_size' => 20,
    'doc_type' => 'all',
    'include_content' => false,
    'content_limit' => 50000,
]);
assert_true($invalidContentLimit['success'] === false && $invalidContentLimit['code'] === 'tool_error', 'oversized document content limit is rejected');

$smallExecutor = new AIToolExecutor(new FakeMarketDataService(), null, 180);
$longOutput = $smallExecutor->executeForModel('fa_compare_candidates', [
    'asset_type' => 'stock',
    'sort_metric' => 'score',
    'order' => 'desc',
    'candidates' => array_map(function($i) {
        return ['code' => 'C' . $i, 'name' => str_repeat('Name', 10), 'metrics' => ['score' => $i, 'long' => str_repeat('x', 80)]];
    }, range(1, 20)),
]);
assert_true(mb_strlen($longOutput) <= 180, 'tool output is truncated to configured character limit');

$fakeExecutor = new FakeToolExecutor();
$transportCalls = [];
$transport = function(array $payload) use (&$transportCalls) {
    $transportCalls[] = $payload;
    if (count($transportCalls) === 1) {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'fa_get_stock_quote',
                            'arguments' => '{"codes":["600519"],"source":"auto","fallback":true}',
                        ],
                    ]],
                ],
            ]],
        ];
    }
    return [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => '最终研究结论：已参考工具数据。内容仅供研究参考，不构成投资建议。',
            ],
        ]],
    ];
};

$agent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 3, 'max_tool_calls_per_round' => 4, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    $fakeExecutor,
    $transport,
    function(array $payload, callable $emit): void {
        $content = '';
        foreach ($payload['messages'] as $message) {
            $content .= (string)($message['content'] ?? '');
        }
        assert_true(strpos($content, 'fa_get_stock_quote') !== false, 'agent streams final response with tool result context');
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"最终研究结论：已参考工具数据。\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$stream = '';
$agent->run([
    ['role' => 'system', 'content' => 'test'],
    ['role' => 'user', 'content' => '分析 600519'],
], function(string $chunk) use (&$stream) {
    $stream .= $chunk;
});

assert_true(count($fakeExecutor->calls) === 1, 'agent executes only the model-requested tool on main path');
assert_true(count($transportCalls) === 2, 'agent performs a second non-streaming model turn after tool observation');
assert_contains('assistant_thought', $stream, 'agent emits visible thought before tool calls');
assert_true(strpos($stream, 'assistant_thought') < strpos($stream, 'tool_status'), 'visible thought is emitted before tool status');
assert_contains('tool_status', $stream, 'agent emits optional tool status event');
assert_contains('最终研究结论', $stream, 'agent emits final assistant content as SSE');
assert_contains('data: [DONE]', $stream, 'agent terminates SSE stream');

$plainStream = '';
$plainAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    [],
    $fakeExecutor,
    null,
    function(array $payload, callable $emit): void {
        assert_true(($payload['stream'] ?? false) === true, 'plain fallback uses streaming payload');
        $messages = $payload['messages'];
        $prompt = '';
        foreach ($messages as $message) {
            $prompt .= (string)($message['content'] ?? '');
        }
        assert_true(strpos((string)($messages[0]['content'] ?? ''), '时间锚点') === false, 'plain stable system prompt excludes dynamic time anchor');
        $anchor = $messages[count($messages) - 2] ?? [];
        assert_true(($anchor['role'] ?? '') === 'system', 'plain time anchor is a separate system message before latest user');
        assert_contains('时间锚点：当前北京时间（Asia/Shanghai）', (string)($anchor['content'] ?? ''), 'plain time anchor includes current Beijing time');
        assert_contains('不要声称无法获取当前时间', (string)($anchor['content'] ?? ''), 'plain time anchor forbids claiming no current time');
        $emit("data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"hidden-reasoning\"}}]}\n\n");
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"plain\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$plainAgent->streamPlain([
    ['role' => 'user', 'content' => 'hello'],
], function(string $chunk) use (&$plainStream) {
    $plainStream .= $chunk;
});
assert_contains('plain', $plainStream, 'plain streaming fallback works');
assert_contains('hidden-reasoning', $plainStream, 'plain streaming exposes raw reasoning_content by default');

$noDoneStream = '';
$noDoneAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    [],
    $fakeExecutor,
    null,
    function(array $payload, callable $emit): void {
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"没有DONE的最终回答\"}}]}\n\n");
    }
);
$noDoneAgent->streamPlain([
    ['role' => 'user', 'content' => 'hello'],
], function(string $chunk) use (&$noDoneStream) {
    $noDoneStream .= $chunk;
}, new AIAgentState('run_no_done'), 'plain_stream_no_done');
assert_contains('没有DONE的最终回答', $noDoneStream, 'no-DONE upstream content is preserved');
assert_contains('不构成投资建议', $noDoneStream, 'no-DONE upstream stream still gets guardrail/disclaimer suffix');
assert_contains('run_finished', $noDoneStream, 'no-DONE upstream stream still emits run_finished');
assert_contains('data: [DONE]', $noDoneStream, 'no-DONE upstream stream is completed by server');

$directAnswerExecutor = new FakeToolExecutor();
$directAnswerStream = '';
$directAnswerAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['expose_tool_trace' => true, 'auto_prefetch' => true],
    $directAnswerExecutor,
    function(array $payload): array {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '常识性回答：可以直接解释概念，不需要查询行情。内容仅供研究参考，不构成投资建议。',
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        assert_true(false, 'direct answer must not enter final stream fallback');
    }
);
$directAnswerAgent->run([
    ['role' => 'user', 'content' => '什么是市盈率？'],
], function(string $chunk) use (&$directAnswerStream) {
    $directAnswerStream .= $chunk;
});
assert_true(count($directAnswerExecutor->calls) === 0, 'direct answer does not trigger server auto-prefetch even when option is true');
assert_contains('常识性回答', $directAnswerStream, 'direct answer is emitted without tools');
assert_true(strpos($directAnswerStream, '服务端数据预取') === false, 'direct answer exposes no server prefetch trace');

$stockContinuationExecutor = new FakeToolExecutor();
$stockContinuationStream = '';
$stockContinuationTransportCalls = 0;
$stockContinuationAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 5, 'max_tool_calls_per_round' => 8, 'expose_tool_trace' => true, 'auto_prefetch' => true, 'stream_after_tool_round' => true],
    $stockContinuationExecutor,
    function(array $payload) use (&$stockContinuationTransportCalls): array {
        $stockContinuationTransportCalls++;
        if ($stockContinuationTransportCalls === 1) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'normalize_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'fa_normalize_stock_code',
                                'arguments' => '{"code":"600519"}',
                            ],
                        ]],
                    ],
                ]],
            ];
        }
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '准备性调用后模型没有继续请求研究工具。内容仅供研究参考，不构成投资建议。',
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        assert_true(false, 'setup-only model answer must be emitted directly without server planned final stream');
    }
);
$stockContinuationAgent->run([
    ['role' => 'user', 'content' => '分析 600519 的行情、技术面和资金流'],
], function(string $chunk) use (&$stockContinuationStream) {
    $stockContinuationStream .= $chunk;
});
$stockContinuationNames = array_map(function($call) { return $call[0]; }, $stockContinuationExecutor->calls);
assert_true(count(array_filter($stockContinuationNames, function($name) { return $name === 'fa_normalize_stock_code'; })) === 1, 'stock continuation executes requested normalize once');
assert_true(count(array_filter($stockContinuationNames, function($name) { return in_array($name, ['fa_get_stock_quote', 'fa_calculate_kline_indicators', 'fa_get_stock_flow'], true); })) === 0, 'stock continuation does not run server-planned research tools');
assert_true(strpos($stockContinuationStream, '服务端规划深挖') === false, 'stock continuation exposes no server planned trace');
assert_contains('准备性调用后模型没有继续请求研究工具', $stockContinuationStream, 'stock continuation emits model final answer directly');

$partialArgsExecutor = new FakeToolExecutor();
$partialArgsAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 1, 'expose_tool_trace' => true, 'auto_prefetch' => false, 'stream_after_tool_round' => true],
    $partialArgsExecutor,
    function(array $payload): array {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'partial_rank_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'fa_get_fund_rank',
                            'arguments' => '{"type":"all","period":"day"}',
                        ],
                    ]],
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"partial-args-final\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$partialArgsStream = '';
$partialArgsAgent->run([
    ['role' => 'user', 'content' => '查询今日基金涨幅排行'],
], function(string $chunk) use (&$partialArgsStream) {
    $partialArgsStream .= $chunk;
});
assert_true(array_key_exists('page', $partialArgsExecutor->calls[0][1]) && $partialArgsExecutor->calls[0][1]['page'] === null, 'tool runtime fills missing strict nullable page argument');
assert_true(array_key_exists('page_size', $partialArgsExecutor->calls[0][1]) && $partialArgsExecutor->calls[0][1]['page_size'] === null, 'tool runtime fills missing strict nullable page_size argument');

$invalidArgsExecutor = new FundRankExecutor();
$invalidArgsStream = '';
$invalidArgsTransportCalls = 0;
$invalidArgsAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 4, 'max_tool_calls_per_round' => 8, 'expose_tool_trace' => true, 'auto_prefetch' => true, 'stream_after_tool_round' => true],
    $invalidArgsExecutor,
    function(array $payload) use (&$invalidArgsTransportCalls): array {
        $invalidArgsTransportCalls++;
        if ($invalidArgsTransportCalls === 2) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'fund_info_after_rank',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'fa_get_fund_info',
                                    'arguments' => '{"codes":["000001","000002"]}',
                                ],
                            ],
                            [
                                'id' => 'fund_estimate_after_rank',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'fa_get_fund_estimate',
                                    'arguments' => '{"codes":["000001","000002"]}',
                                ],
                            ],
                        ],
                    ],
                ]],
            ];
        }
        if ($invalidArgsTransportCalls >= 3) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '基金深入研究最终结论：已结合排行、资料和估值观察。内容仅供研究参考，不构成投资建议。',
                    ],
                ]],
            ];
        }
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'bad_fund_rank_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'fa_get_fund_rank',
                            'arguments' => '{"type": ',
                        ],
                    ]],
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        assert_true(false, 'repaired invalid args path should finish through model decision loop, not forced final stream');
    }
);
$invalidArgsAgent->run([
    ['role' => 'user', 'content' => '查询今天涨势最好的几只基金，深入研究分析它们，然后给出建议'],
], function(string $chunk) use (&$invalidArgsStream) {
    $invalidArgsStream .= $chunk;
});
assert_true($invalidArgsTransportCalls === 3, 'invalid args path repairs the selected tool call and continues model decision loop');
assert_true(count($invalidArgsExecutor->calls) === 3, 'invalid args path executes repaired rank plus model-selected follow-up tools');
assert_true($invalidArgsExecutor->calls[0][0] === 'fa_get_fund_rank', 'invalid args repair preserves the model-selected fund rank tool');
assert_true(($invalidArgsExecutor->calls[0][1]['period'] ?? '') === 'day', 'invalid args repair infers day period for today top gainers');
assert_true(($invalidArgsExecutor->calls[0][1]['type'] ?? '') === 'all', 'invalid args repair infers all fund type by default');
assert_true(($invalidArgsExecutor->calls[0][1]['page_size'] ?? 0) === 10, 'invalid args repair uses compact default fund rank page size');
assert_true(in_array('fa_get_fund_info', array_map(function($call) { return $call[0]; }, $invalidArgsExecutor->calls), true), 'invalid args path lets model continue to fund info');
assert_true(in_array('fa_get_fund_estimate', array_map(function($call) { return $call[0]; }, $invalidArgsExecutor->calls), true), 'invalid args path lets model continue to fund estimate');
assert_true(strpos($invalidArgsStream, '参数 JSON 不完整') !== false, 'invalid args repair exposes JSON repair status instead of hiding it');
assert_true(strpos($invalidArgsStream, '服务端数据预取') === false, 'invalid args path exposes no server prefetch trace');
assert_contains('基金深入研究最终结论', $invalidArgsStream, 'invalid args path emits final answer after follow-up tools');

$newFundToolExecutor = new FakeToolExecutor();
$newFundToolStream = '';
$newFundToolTransportCalls = 0;
$newFundToolAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 5, 'max_tool_calls_per_round' => 4, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    $newFundToolExecutor,
    function(array $payload) use (&$newFundToolTransportCalls): array {
        $newFundToolTransportCalls++;
        if ($newFundToolTransportCalls === 1) {
            $prompt = '';
            foreach ($payload['messages'] as $message) {
                $prompt .= (string)($message['content'] ?? '');
            }
            assert_contains('fa_get_index_profile', $prompt, 'system prompt mentions index profile tool');
            assert_contains('fa_get_fund_dividend_history', $prompt, 'system prompt mentions dividend history tool');
            assert_contains('fa_get_fund_documents', $prompt, 'system prompt mentions fund documents tool');
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'index_profile_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'fa_get_index_profile',
                                'arguments' => '{"code":"008163"}',
                            ],
                        ]],
                    ],
                ]],
            ];
        }
        if ($newFundToolTransportCalls === 2) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'dividend_history_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'fa_get_fund_dividend_history',
                                    'arguments' => '{"code":"008163","page":1,"page_size":100}',
                                ],
                            ],
                            [
                                'id' => 'documents_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'fa_get_fund_documents',
                                    'arguments' => '{"code":"008163","page":1,"page_size":20,"doc_type":"contract","include_content":true,"content_limit":6000}',
                                ],
                            ],
                        ],
                    ],
                ]],
            ];
        }
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '基金工具最终结论：已结合指数画像、分红历史和基金文档观察。内容仅供研究参考，不构成投资建议。',
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        assert_true(false, 'new fund tool path should finish directly through model decision loop');
    }
);
$newFundToolAgent->run([
    ['role' => 'user', 'content' => '008163 是红利型基金吗？最近分红了吗？找基金合同依据。'],
], function(string $chunk) use (&$newFundToolStream) {
    $newFundToolStream .= $chunk;
});
$newFundToolNames = array_map(function($call) { return $call[0]; }, $newFundToolExecutor->calls);
assert_true(in_array('fa_get_index_profile', $newFundToolNames, true), 'agent path can call index profile tool');
assert_true(in_array('fa_get_fund_dividend_history', $newFundToolNames, true), 'agent path can call dividend history tool');
assert_true(in_array('fa_get_fund_documents', $newFundToolNames, true), 'agent path can call fund documents tool');
assert_contains('基金工具最终结论', $newFundToolStream, 'agent path emits final answer after new fund tools');

$marketBreadthExecutor = new FakeToolExecutor();
$marketBreadthTransportCalls = 0;
$marketBreadthStream = '';
$marketBreadthAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['max_tool_rounds' => 3, 'max_tool_calls_per_round' => 4, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    $marketBreadthExecutor,
    function(array $payload) use (&$marketBreadthTransportCalls): array {
        $marketBreadthTransportCalls++;
        if ($marketBreadthTransportCalls === 1) {
            $messages = $payload['messages'];
            $prompt = '';
            foreach ($messages as $message) {
                $prompt .= (string)($message['content'] ?? '');
            }
            assert_true(strpos($prompt, 'fa_get_market_breadth') !== false, 'system prompt mentions market breadth tool');
            assert_true(strpos((string)($messages[0]['content'] ?? ''), '时间锚点') === false, 'tool stable system prompt excludes dynamic time anchor');
            $anchor = $messages[count($messages) - 2] ?? [];
            assert_true(($anchor['role'] ?? '') === 'system', 'tool time anchor is a separate system message before latest user');
            assert_contains('时间锚点：当前北京时间（Asia/Shanghai）', (string)($anchor['content'] ?? ''), 'tool time anchor includes current Beijing time');
            assert_contains('不要声称无法获取当前时间', (string)($anchor['content'] ?? ''), 'tool time anchor forbids claiming no current time');
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'breadth_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'fa_get_market_breadth',
                                'arguments' => '{"scope":"a_share","include_limit_stats":true,"include_index_quotes":true}',
                            ],
                        ]],
                    ],
                ]],
            ];
        }
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '市场宽度最终结论：已参考涨跌家数和近似涨跌停统计。内容仅供研究参考，不构成投资建议。',
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        assert_true(false, 'market breadth model path should finish directly after useful tool result');
    }
);
$marketBreadthAgent->run([
    ['role' => 'user', 'content' => '今天大盘环境怎么样，市场宽度和涨跌家数如何？'],
], function(string $chunk) use (&$marketBreadthStream): void {
    $marketBreadthStream .= $chunk;
});
assert_true($marketBreadthTransportCalls === 2, 'market breadth request performs one tool turn then final answer');
assert_true(($marketBreadthExecutor->calls[0][0] ?? '') === 'fa_get_market_breadth', 'market breadth request executes market breadth tool');
assert_contains('获取市场宽度', $marketBreadthStream, 'market breadth stream exposes tool status');
assert_contains('市场宽度最终结论', $marketBreadthStream, 'market breadth stream emits final answer');

$modelLoopExecutor = new MarketScanDeepDiveExecutor();
$modelLoopTransportCalls = 0;
$modelLoopStream = '';
$modelLoopAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    [
        'max_tool_rounds' => 6,
        'max_tool_calls_per_round' => 8,
        'max_tool_calls_total' => 64,
        'max_deep_dive_candidates' => 10,
        'expose_tool_trace' => true,
        'auto_prefetch' => true,
        'stream_after_tool_round' => true,
    ],
    $modelLoopExecutor,
    function(array $payload) use (&$modelLoopTransportCalls): array {
        $modelLoopTransportCalls++;
        if ($modelLoopTransportCalls === 1) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'breadth_before_hot_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'fa_get_market_breadth',
                                'arguments' => '{"scope":"a_share","include_limit_stats":true,"include_index_quotes":true}',
                            ],
                        ]],
                    ],
                ]],
            ];
        }
        if ($modelLoopTransportCalls === 2) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'hot_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'fa_get_hot_stocks',
                                'arguments' => '{"page":1,"page_size":10,"sort":"f62","order":1}',
                            ],
                        ]],
                    ],
                ]],
            ];
        }
        if ($modelLoopTransportCalls === 3) {
            return [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'quote_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'fa_get_stock_quote',
                                    'arguments' => '{"codes":["600001","600002","600003"],"source":"auto","fallback":true}',
                                ],
                            ],
                            [
                                'id' => 'flow_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'fa_get_stock_flow',
                                    'arguments' => '{"code":"600001","limit":30}',
                                ],
                            ],
                        ],
                    ],
                ]],
            ];
        }
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '多轮模型主路径最终结论：已根据热榜、行情和资金流观察继续决策。内容仅供研究参考，不构成投资建议。',
                ],
            ]],
        ];
    },
    function(array $payload, callable $emit): void {
        $content = '';
        foreach ($payload['messages'] as $message) {
            $content .= (string)($message['content'] ?? '');
        }
        assert_true(strpos($content, 'fa_get_market_breadth') !== false, 'multi-turn final context includes market breadth result');
        assert_true(strpos($content, 'fa_get_hot_stocks') !== false, 'multi-turn final context includes hot stocks result');
        assert_true(strpos($content, 'fa_get_stock_quote') !== false, 'multi-turn final context includes quote result');
        assert_true(strpos($content, 'server_planned') === false, 'multi-turn main path does not use server planned tools');
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"multi-turn-final\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$modelLoopAgent->run([
    ['role' => 'user', 'content' => '查询资金流向前10的股票，充分深入评估它们，给出建议'],
], function(string $chunk) use (&$modelLoopStream) {
    $modelLoopStream .= $chunk;
});
$modelLoopNames = array_map(function($call) { return $call[0]; }, $modelLoopExecutor->calls);
assert_true($modelLoopTransportCalls === 4, 'multi-turn main path lets model decide again after each tool observation');
assert_true(($modelLoopNames[0] ?? '') === 'fa_get_market_breadth', 'multi-turn executes market breadth before hot stocks');
assert_true(in_array('fa_get_hot_stocks', $modelLoopNames, true), 'multi-turn executes discovery tool after market breadth');
assert_true(in_array('fa_get_stock_quote', $modelLoopNames, true), 'multi-turn executes second model quote tool');
assert_true(in_array('fa_get_stock_flow', $modelLoopNames, true), 'multi-turn executes second model flow tool');
assert_true(!in_array('fa_calculate_kline_indicators', $modelLoopNames, true), 'multi-turn main path avoids automatic server planned indicators');
assert_contains('工具观察已回填，继续让 AI 决定下一步', $modelLoopStream, 'multi-turn stream exposes observe-then-act continuation');
assert_contains('多轮模型主路径最终结论', $modelLoopStream, 'multi-turn main path emits model final answer');

$fallbackStream = '';
$fallbackAgent = new AIChatToolAgent(
    ['api_url' => 'http://fake', 'api_key' => 'test', 'model' => 'fake-model'],
    ['tool_timeout' => 3, 'expose_tool_trace' => true, 'auto_prefetch' => false],
    $fakeExecutor,
    function(array $payload): array {
        throw new RuntimeException('tools not supported');
    },
    function(array $payload, callable $emit): void {
        $emit("data: {\"choices\":[{\"delta\":{\"content\":\"fallback-ok\"}}]}\n\n");
        $emit("data: [DONE]\n\n");
    }
);
$fallbackAgent->run([
    ['role' => 'user', 'content' => 'hello'],
], function(string $chunk) use (&$fallbackStream) {
    $fallbackStream .= $chunk;
});
assert_contains('fallback_plain_stream', $fallbackStream, 'tool handshake failure emits fallback status');
assert_contains('fallback-ok', $fallbackStream, 'tool handshake failure falls back to plain stream');

echo "AI tool agent tests passed\n";
