<?php
/**
 * 创建AI聊天会话
 */
header('Content-Type: application/json');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init([
    'endpoint'    => 'create_session',
    'rate_limit'  => 30,
    'rate_window' => 60,
]);

SecurityAudit::requireMethod('POST');

session_start();
$sessionId = session_id();
session_write_close();
$timestamp = time();

echo json_encode([
    'session' => [
        'id' => $sessionId,
        'created_at' => $timestamp
    ]
]);
