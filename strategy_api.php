<?php
header('Content-Type: application/json; charset=utf-8');

@set_time_limit(180);

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/StrategyPoolService.php';

SecurityAudit::init(['endpoint' => 'strategy_api', 'rate_limit' => 20]);

$action = SecurityAudit::getParam('action', 'list', ['whitelist' => ['list', 'run', 'run_all']]);
$service = new StrategyPoolService();

function strategy_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function strategy_int_param(array $body, string $key, int $default, int $min, int $max): int
{
    $value = isset($body[$key]) ? $body[$key] : ($_GET[$key] ?? $default);
    $value = is_numeric($value) ? (int)$value : $default;
    return max($min, min($max, $value));
}

try {
    if ($action === 'list') {
        echo json_encode([
            'success' => true,
            'strategies' => $service->listStrategies(),
            'load_errors' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = strategy_request_body();
    $pages = strategy_int_param($body, 'pages', 2, 1, 5);
    $candidateLimit = strategy_int_param($body, 'candidate_limit', 80, 20, 200);

    if ($action === 'run') {
        $strategyId = isset($body['strategy_id']) ? (string)$body['strategy_id'] : SecurityAudit::getParam('strategy_id', '', [
            'required' => true,
            'pattern' => '/^[A-Za-z0-9_-]+$/',
            'maxLength' => 64,
        ]);
        echo json_encode($service->run($strategyId, $pages, $candidateLimit), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'run_all') {
        $strategyIds = $body['strategy_ids'] ?? [];
        if (!is_array($strategyIds)) $strategyIds = [];
        $strategyIds = array_values(array_filter(array_map('strval', $strategyIds), function ($id) {
            return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
        }));
        echo json_encode($service->runAll($strategyIds, $pages, $candidateLimit), JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '策略服务执行失败: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知 action'], JSON_UNESCAPED_UNICODE);
