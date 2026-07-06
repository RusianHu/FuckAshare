<?php

require_once __DIR__ . '/StrategyRegistry.php';
require_once __DIR__ . '/StrategyDataProvider.php';
require_once __DIR__ . '/StrategyRuleEngine.php';
require_once __DIR__ . '/StrategyValidationService.php';

class StrategyPoolService
{
    /** @var StrategyRegistry */
    private $registry;

    /** @var StrategyDataProvider */
    private $provider;

    /** @var StrategyRuleEngine */
    private $rules;

    /** @var StrategyValidationService */
    private $validation;

    public function __construct()
    {
        $this->registry = new StrategyRegistry();
        $this->provider = new StrategyDataProvider();
        $this->rules = new StrategyRuleEngine();
        $this->validation = new StrategyValidationService($this->provider, $this->registry);
    }

    public function listStrategies(): array
    {
        return $this->registry->listStrategies();
    }

    public function health(): array
    {
        return $this->validation->health();
    }

    public function runAll(array $strategyIds = [], int $pages = 2, int $candidateLimit = 80, array $paramsByStrategy = [], bool $includeDiagnostics = false): array
    {
        $started = microtime(true);
        $ids = $this->registry->normalizeIds($strategyIds);
        $needsStock = $this->needsAssetType($ids, 'stock');
        $needsSector = $this->needsAssetType($ids, 'sector');
        $needsDiagnostic = $this->needsAssetType($ids, 'diagnostic') || $includeDiagnostics;
        $needsHistory = $this->needsHistory($ids);

        $candidates = $needsStock ? $this->provider->loadCandidateSnapshot($pages, $candidateLimit) : [];
        $stockRows = $needsStock ? $this->provider->hydrateStockIndicators($candidates, $needsHistory, 90) : [];
        $sectorRows = $needsSector ? $this->provider->loadSectorRows('f62', 'industry') : [];
        $health = $needsDiagnostic ? $this->validation->health() : null;
        $diagnosticRows = $needsDiagnostic && $health ? $this->provider->diagnosticRows($health) : [];

        $results = [];
        foreach ($ids as $id) {
            $strategy = $this->registry->get($id);
            if (!$strategy) continue;
            $rows = $this->rowsForStrategy($strategy, $stockRows, $sectorRows, $diagnosticRows);
            $matched = $this->rules->run($strategy, $rows, $paramsByStrategy[$id] ?? []);
            $results[$id] = $this->resultForStrategy($strategy, $matched, $started, $paramsByStrategy[$id] ?? []);
        }

        return [
            'success' => true,
            'as_of' => date('Y-m-d'),
            'results' => $results,
            'meta' => $this->buildMeta($ids, $candidates, $stockRows, $sectorRows, $health, $pages, $candidateLimit, $started),
        ];
    }

    public function run(string $strategyId, int $pages = 2, int $candidateLimit = 80, array $params = [], bool $includeDiagnostics = false): array
    {
        if (!$this->registry->has($strategyId)) {
            return ['success' => false, 'message' => "未知策略: {$strategyId}"];
        }

        $data = $this->runAll([$strategyId], $pages, $candidateLimit, [$strategyId => $params], $includeDiagnostics);
        return [
            'success' => true,
            'as_of' => $data['as_of'],
            'result' => $data['results'][$strategyId],
            'meta' => $data['meta'],
        ];
    }

    private function rowsForStrategy(array $strategy, array $stockRows, array $sectorRows, array $diagnosticRows): array
    {
        $assetType = $strategy['asset_type'] ?? 'stock';
        if ($assetType === 'sector') return $sectorRows;
        if ($assetType === 'diagnostic') return $diagnosticRows;
        return $stockRows;
    }

    private function resultForStrategy(array $strategy, array $rows, float $started, array $paramOverrides): array
    {
        return [
            'strategy' => $strategy['id'],
            'name' => $strategy['name'],
            'asset_type' => $strategy['asset_type'] ?? 'stock',
            'version' => $strategy['version'] ?? '1.0',
            'watch_only' => !empty($strategy['watch_only']),
            'source_ref' => $strategy['source_ref'] ?? '',
            'risk_note' => $strategy['risk_note'] ?? '',
            'params' => $this->rules->mergeParams($strategy, $paramOverrides),
            'as_of' => date('Y-m-d'),
            'total' => count($rows),
            'rows' => $rows,
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    private function buildMeta(array $ids, array $candidates, array $stockRows, array $sectorRows, ?array $health, int $pages, int $candidateLimit, float $started): array
    {
        $dataHealth = $this->provider->getDataHealth();
        $historyReady = 0;
        foreach ($stockRows as $row) {
            if (!empty($row['history_ready'])) $historyReady++;
        }

        return [
            'source' => 'strategy_pool',
            'candidate_count' => count($candidates),
            'hydrated_count' => count($stockRows),
            'sector_count' => count($sectorRows),
            'pages' => $pages,
            'candidate_limit' => $candidateLimit,
            'elapsed_ms' => round((microtime(true) - $started) * 1000, 1),
            'history_ready_ratio' => count($stockRows) ? round($historyReady / count($stockRows), 4) : 0,
            'data_health' => $dataHealth,
            'health' => $health,
            'dependency_summary' => $this->validation->dependencySummary($ids, $stockRows, $sectorRows),
            'candidate_sources' => $this->provider->getCandidateSources(),
            'source_errors' => $this->provider->getSourceErrors(),
            'strategy_versions' => $this->registry->strategyVersions($ids),
            'coverage_note' => '候选池来自东方财富排行合并；K线经 MarketDataService 统一链路获取（Ashare 主、雪球兜底）；板块轮动来自东方财富板块资金流。',
        ];
    }

    private function needsAssetType(array $ids, string $assetType): bool
    {
        foreach ($ids as $id) {
            $strategy = $this->registry->get($id);
            if (($strategy['asset_type'] ?? 'stock') === $assetType) return true;
        }
        return false;
    }

    private function needsHistory(array $ids): bool
    {
        foreach ($ids as $id) {
            $strategy = $this->registry->get($id);
            if (($strategy['asset_type'] ?? 'stock') === 'stock' && !empty($strategy['needs_history'])) return true;
        }
        return false;
    }
}
