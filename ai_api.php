<?php
// ============================================================
// AI API 代理 — 多渠道 SSE 流式转发
// 最佳实践：关闭所有缓冲、正确设置 SSE 头、避免超时
// Phase 1: 增加每 IP 并发流限制与全局并发流限制
// ============================================================

require_once __DIR__ . '/lib/AppConfig.php';
require_once __DIR__ . '/SecurityAudit.php';

$aiConfig = AppConfig::get('ai', []);
$rateLimitConfig = AppConfig::get('rate_limit', []);

SecurityAudit::init([
    'endpoint'    => 'ai_api',
    'rate_limit'  => (int)($rateLimitConfig['ai_limit'] ?? 30),   // AI 接口更严格限制
    'rate_window' => (int)($rateLimitConfig['ai_window'] ?? 60),
    'sse'         => true, // SSE 模式：reject 时输出事件流格式
]);

// 1. 关闭所有输出缓冲，确保 SSE 数据实时推送
while (ob_get_level()) {
    ob_end_clean();
}

// 2. 取消 PHP 脚本最大执行时间限制（推理模型可能思考很久）
set_time_limit(0);
ignore_user_abort(false);

// 3. 设置 SSE 流式响应头
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');  // 禁止 Nginx 缓冲

// 4. Session：启动后立即释放写锁，避免阻塞并发请求
session_start();
session_write_close();

// ============================================================
// Phase 1.2: AI 并发流限制
// 每 IP 最大并发流数 & 全局最大并发流数
// ============================================================
$AI_MAX_CONCURRENT_PER_IP = (int)($aiConfig['max_concurrent_per_ip'] ?? 2);   // 每 IP 最多同时 AI 流数
$AI_MAX_CONCURRENT_GLOBAL = (int)($aiConfig['max_concurrent_global'] ?? 10);  // 全局最多同时 AI 流数
$AI_STALE_THRESHOLD = (int)($aiConfig['stale_threshold'] ?? 310);

function getAIClientIP(): string {
    if (SecurityAudit::TRUST_PROXY) {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rejectAIConcurrency(string $message, string $type): void {
    http_response_code(429);
    echo "data: " . json_encode([
        'error' => [
            'message' => $message,
            'type'    => $type,
        ]
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
    exit;
}

function acquireAIStreamSlot(string $ip, int $maxPerIp, int $maxGlobal, int $staleThreshold): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_ai_concurrent';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $guardFile = $dir . DIRECTORY_SEPARATOR . '.guard.lock';
    $guard = @fopen($guardFile, 'c');
    if (!$guard) {
        rejectAIConcurrency('服务器 AI 并发控制暂不可用，请稍后重试', 'concurrent_guard_unavailable');
    }

    try {
        if (!flock($guard, LOCK_EX)) {
            rejectAIConcurrency('服务器 AI 并发控制繁忙，请稍后重试', 'concurrent_guard_busy');
        }

        $now = time();
        // 清理过期锁文件。持有全局 guard 时完成统计与注册，避免并发穿透限制。
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = @json_decode((string)@file_get_contents($file), true);
            if (!$data || ($now - ($data['started_at'] ?? 0)) > $staleThreshold) {
                @unlink($file);
            }
        }

        $ipCount = 0;
        $globalCount = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = @json_decode((string)@file_get_contents($file), true);
            if ($data && ($now - ($data['started_at'] ?? 0)) <= $staleThreshold) {
                $globalCount++;
                if (($data['ip'] ?? '') === $ip) {
                    $ipCount++;
                }
            }
        }

        if ($ipCount >= $maxPerIp) {
            rejectAIConcurrency("AI 并发流数超限（每 IP 最多 {$maxPerIp} 个），请等待当前请求完成", 'concurrent_limit');
        }

        if ($globalCount >= $maxGlobal) {
            rejectAIConcurrency('服务器 AI 并发已满，请稍后重试', 'global_concurrent_limit');
        }

        $id = uniqid('ai_', true);
        $file = $dir . DIRECTORY_SEPARATOR . $id . '.json';
        $written = @file_put_contents($file, json_encode([
            'ip'         => $ip,
            'started_at' => $now,
            'pid'        => getmypid(),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        if ($written === false) {
            rejectAIConcurrency('服务器 AI 并发槽位注册失败，请稍后重试', 'concurrent_slot_create_failed');
        }

        return $file;
    } finally {
        @flock($guard, LOCK_UN);
        @fclose($guard);
    }
}

$aiClientIP = getAIClientIP();
$aiLockFile = acquireAIStreamSlot($aiClientIP, $AI_MAX_CONCURRENT_PER_IP, $AI_MAX_CONCURRENT_GLOBAL, $AI_STALE_THRESHOLD);

// 注册清理：脚本正常退出或异常终止时删除锁文件
register_shutdown_function(function() use ($aiLockFile) {
    @unlink($aiLockFile);
});

// 5. 验证请求方法
SecurityAudit::requireMethod('POST');

// 6. 解析并验证请求体
$input = SecurityAudit::getJsonBody([
    'messages' => [],  // 消息将在专用方法中验证
]);

if (!isset($input['messages']) || empty($input['messages'])) {
    echo "data: " . json_encode(['error' => ['message' => 'Messages are required.']]) . "\n\n";
    flush();
    exit;
}

// 验证消息内容安全性
$input['messages'] = SecurityAudit::validateMessages($input['messages']);

$defaultChannel = (string)($aiConfig['default_channel'] ?? '');
$channels = is_array($aiConfig['channels'] ?? null) ? $aiConfig['channels'] : [];

if ($defaultChannel === '' || empty($channels[$defaultChannel]) || !is_array($channels[$defaultChannel])) {
    echo "data: " . json_encode(['error' => ['message' => 'AI 渠道配置无效，请检查 config.php 中 ai.default_channel 与 ai.channels。']]) . "\n\n";
    flush();
    exit;
}

$channel = $channels[$defaultChannel];
foreach (['api_url', 'api_key', 'model'] as $field) {
    if (empty($channel[$field]) || !is_string($channel[$field])) {
        echo "data: " . json_encode(['error' => ['message' => "AI 渠道 {$defaultChannel} 缺少 {$field} 配置。"]], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        exit;
    }
}

$model = $channel['model'];

// 7. 构建上游请求数据
$postData = json_encode([
    'model'    => $model,
    'messages' => $input['messages'],
    'stream'   => true,
]);

// 8. 初始化 cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $channel['api_url']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);  // 流式必须为 false
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $channel['api_key'],
    'Content-Type: application/json',
    'Accept: text/event-stream',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

// SSL 配置
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// 超时配置：推理模型思考时间可能很长，默认设置 5 分钟
curl_setopt($ch, CURLOPT_TIMEOUT, (int)($aiConfig['timeout'] ?? 300));
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)($aiConfig['connect_timeout'] ?? 15));

// 9. 流式透传：将上游 SSE 数据逐块转发给客户端
//    同时检测客户端断开，及时停止上游 cURL
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
    // 如果客户端已断开，停止上游传输
    if (connection_aborted()) {
        return 0;  // 返回 0 终止 cURL 传输
    }
    echo $data;
    if (ob_get_level()) ob_flush();
    flush();
    return strlen($data);
});

// 10. 执行请求
$result = curl_exec($ch);

// 11. 错误处理：如果 cURL 出错，通过 SSE 发送错误事件
if (curl_errno($ch)) {
    $errorMsg = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // 尝试通过 SSE 通知前端
    echo "data: " . json_encode([
        'error' => [
            'message' => "API请求失败: $errorMsg",
            'type'    => 'proxy_error',
            'code'    => $httpCode ?: curl_errno($ch),
        ]
    ]) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

curl_close($ch);
