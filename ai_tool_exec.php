<?php
// ============================================================
// ai_tool_exec.php — 内部工具执行端点（仅供 AIToolRuntime 并行派发使用）
// 非用户接口。仅允许 127.0.0.1/::1 访问 + 内部 token 鉴权。
// 接收 {token, tool_name, args}，返回单个工具的执行结果 JSON。
// ============================================================

require_once __DIR__ . '/lib/AppConfig.php';
require_once __DIR__ . '/lib/AIToolExecutor.php';
require_once __DIR__ . '/SecurityAudit.php';

// 关闭输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 仅允许本机访问（REMOTE_ADDR 由 web server 设置，不可被客户端伪造）
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'forbidden', 'message' => 'internal endpoint only'], JSON_UNESCAPED_UNICODE);
    exit;
}

SecurityAudit::requireMethod('POST');

$aiConfig = AppConfig::get('ai', []);
$toolAgentConfig = is_array($aiConfig['tool_agent'] ?? null) ? $aiConfig['tool_agent'] : [];
$expectedToken = (string)($toolAgentConfig['internal_exec_token'] ?? '');

if ($expectedToken === '') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'not_configured', 'message' => 'internal_exec_token 未配置，并行执行禁用'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 单工具执行上限 60s
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
header('X-Accel-Buffering: no');

$input = SecurityAudit::getJsonBody([]);

$token = (string)($input['token'] ?? '');
$toolName = (string)($input['tool_name'] ?? '');
$args = $input['args'] ?? [];

if ($token === '' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'invalid_token', 'message' => '内部 token 校验失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($toolName === '' || !AIToolRegistry::has($toolName)) {
    echo json_encode(['success' => false, 'code' => 'unknown_tool', 'message' => "未知工具: {$toolName}"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($args)) {
    $args = [];
}

$outputCharLimit = (int)($toolAgentConfig['tool_output_char_limit'] ?? 60000);
$executor = new AIToolExecutor(null, null, $outputCharLimit);

// executeForModel 内部已捕获 Throwable 并返回结构化错误，同时按 outputCharLimit 截断
try {
    echo $executor->executeForModel($toolName, $args);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'code' => 'tool_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
