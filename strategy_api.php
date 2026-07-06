<?php
header('Content-Type: application/json; charset=utf-8');

@set_time_limit(180);

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/StrategyPoolService.php';

SecurityAudit::init(['endpoint' => 'strategy_api', 'rate_limit' => 20]);

$action = SecurityAudit::getParam('action', 'list', ['whitelist' => ['list', 'run', 'run_all', 'health']]);
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

function strategy_bool_param(array $body, string $key, bool $default): bool
{
    $value = isset($body[$key]) ? $body[$key] : ($_GET[$key] ?? $default);
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return ((int)$value) === 1;
    if (is_string($value)) return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    return $default;
}

function strategy_params_by_strategy(array $body): array
{
    $raw = $body['params_by_strategy'] ?? [];
    if (!is_array($raw)) return [];

    $out = [];
    foreach ($raw as $strategyId => $params) {
        $strategyId = (string)$strategyId;
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $strategyId) || !is_array($params)) continue;
        $clean = [];
        foreach ($params as $key => $value) {
            $key = (string)$key;
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) continue;
            if (is_numeric($value)) $clean[$key] = $value + 0;
        }
        if ($clean) $out[$strategyId] = $clean;
    }
    return $out;
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

    if ($action === 'health') {
        echo json_encode([
            'success' => true,
            'health' => $service->health(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = strategy_request_body();
    $pages = strategy_int_param($body, 'pages', 2, 1, 5);
    $candidateLimit = strategy_int_param($body, 'candidate_limit', 80, 20, 200);
    $paramsByStrategy = strategy_params_by_strategy($body);
    $includeDiagnostics = strategy_bool_param($body, 'include_diagnostics', false);

    if ($action === 'run') {
        $strategyId = isset($body['strategy_id']) ? (string)$body['strategy_id'] : SecurityAudit::getParam('strategy_id', '', [
            'required' => true,
            'pattern' => '/^[A-Za-z0-9_-]+$/',
            'maxLength' => 64,
        ]);
        echo json_encode($service->run($strategyId, $pages, $candidateLimit, $paramsByStrategy[$strategyId] ?? [], $includeDiagnostics), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'run_all') {
        $strategyIds = $body['strategy_ids'] ?? [];
        if (!is_array($strategyIds)) $strategyIds = [];
        $strategyIds = array_values(array_filter(array_map('strval', $strategyIds), function ($id) {
            return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
        }));
        echo json_encode($service->runAll($strategyIds, $pages, $candidateLimit, $paramsByStrategy, $includeDiagnostics), JSON_UNESCAPED_UNICODE);
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
