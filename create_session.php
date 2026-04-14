<?php
/**
 * 创建AI聊天会话
 */
header('Content-Type: application/json');
session_start();

$sessionId = session_id();
$timestamp = time();

echo json_encode([
    'session' => [
        'id' => $sessionId,
        'created_at' => $timestamp
    ]
]);
