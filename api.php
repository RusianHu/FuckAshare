<?php
header('Content-Type: application/json');

// 设置错误处理
function returnError($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// 获取参数
$code = isset($_GET['code']) ? $_GET['code'] : '';
$frequency = isset($_GET['frequency']) ? $_GET['frequency'] : '1d';
$count = isset($_GET['count']) ? intval($_GET['count']) : 10;
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : '';

// 验证参数
if (empty($code)) {
    returnError('股票代码不能为空');
}

if ($count < 1 || $count > 500) {
    returnError('数据条数必须在1-500之间');
}

// 构建Python命令 - 使用python3替代python
$pythonScript = 'get_stock_data.py';
$command = "python3 $pythonScript \"$code\" \"$frequency\" \"$count\" \"$end_date\" 2>&1";

// 执行Python脚本
$output = [];
$returnCode = 0;
exec($command, $output, $returnCode);

if ($returnCode !== 0) {
    returnError('Python脚本执行失败: ' . implode("\n", $output));
}

// 处理Python脚本输出
$jsonData = implode('', $output);
$data = json_decode($jsonData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    returnError('解析JSON数据失败: ' . json_last_error_msg() . "\n原始输出: " . $jsonData);
}

// 返回数据
echo json_encode([
    'success' => true,
    'code' => $code,
    'frequency' => $frequency,
    'count' => $count,
    'end_date' => $end_date,
    'data' => $data
]);
?>
