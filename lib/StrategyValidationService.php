<?php

require_once __DIR__ . '/StrategyDataProvider.php';
require_once __DIR__ . '/StrategyRegistry.php';

class StrategyValidationService
{
    /** @var StrategyDataProvider */
    private $provider;

    /** @var StrategyRegistry */
    private $registry;

    public function __construct(StrategyDataProvider $provider, StrategyRegistry $registry)
    {
        $this->provider = $provider;
        $this->registry = $registry;
    }

    public function health(): array
    {
        $health = $this->provider->healthCheck();
        $health['strategy_count'] = count($this->registry->all());
        $health['default_pool'] = $this->registry->defaultPool();
        $health['checked_at'] = date('c');
        return $health;
    }

    public function dependencySummary(array $strategyIds, array $stockRows, array $sectorRows): array
    {
        $historyReady = 0;
        foreach ($stockRows as $row) {
            if (!empty($row['history_ready'])) $historyReady++;
        }

        $summary = [
            'stock_rows' => count($stockRows),
            'sector_rows' => count($sectorRows),
            'history_ready' => $historyReady,
            'history_ready_ratio' => count($stockRows) ? round($historyReady / count($stockRows), 4) : 0,
            'per_strategy' => [],
        ];

        foreach ($strategyIds as $id) {
            $strategy = $this->registry->get($id);
            if (!$strategy) continue;
            $assetType = $strategy['asset_type'] ?? 'stock';
            $availableRows = $assetType === 'sector' ? count($sectorRows) : ($assetType === 'diagnostic' ? 0 : count($stockRows));
            $summary['per_strategy'][$id] = [
                'asset_type' => $assetType,
                'needs_history' => !empty($strategy['needs_history']),
                'available_rows' => $availableRows,
                'history_ready_ratio' => $assetType === 'stock' ? $summary['history_ready_ratio'] : null,
                'version' => $strategy['version'] ?? '1.0',
            ];
        }

        return $summary;
    }
}
