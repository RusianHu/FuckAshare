<?php
session_start();

header('Content-Type: application/json');

// 检查是否为 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['messages']) || empty($input['messages'])) {
    echo json_encode(['error' => 'Messages are required.']);
    exit;
}

// 获取会话ID
$sessionId = isset($input['session_id']) ? $input['session_id'] : session_id();

// 聊天记录文件目录（确保该目录有写权限）
//$chatHistoryDir = 'chat_history';
//if (!file_exists($chatHistoryDir)) {
//    mkdir($chatHistoryDir, 0777, true);
//}

// 聊天记录文件路径
//$chatHistoryFile = $chatHistoryDir . '/' . $sessionId . '.json';

// DeepSeek API 配置
$apiKey = 'sk-6e20ac384fe241048de4d28f02e115e3'; // API密钥
$apiUrl = 'https://api.deepseek.com/chat/completions';

// 获取模型选择，默认使用 deepseek-chat
$model = isset($input['model']) ? $input['model'] : 'deepseek-chat';

// 构建请求数据
$postData = [
    'model' => $model,
    'messages' => $input['messages'],
    'stream' => true
];

// 使用 cURL 发送请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

// 设置为流式输出
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
    echo $data;
    flush();
    return strlen($data);
});

curl_exec($ch);
if (curl_errno($ch)) {
    echo json_encode(['error' => 'Request error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// 将消息保存到文件
//file_put_contents($chatHistoryFile, json_encode($input['messages']));
?>
