<?php
/**
 * 股票K线数据API
 * 通过调用 Python 脚本获取行情数据
 */
header('Content-Type: application/json');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'api']);

// 错误处理函数
function returnError($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// 获取并验证参数
$code = SecurityAudit::getParam('code', '', [
    'required'  => true,
    'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
    'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
]);

$frequency = SecurityAudit::getParam('frequency', '1d', [
    'whitelist' => SecurityAudit::ALLOWED_FREQUENCIES,
]);

$count = SecurityAudit::getParam('count', 10, [
    'int' => true,
    'min' => 1,
    'max' => 500,
]);

$end_date = SecurityAudit::getParam('end_date', '', [
    'pattern' => SecurityAudit::DATE_PATTERN,
]);

// 构建Python命令（根据系统选择 python3 或 python），并正确转义所有参数
// 使用 __DIR__ 构造 get_stock_data.py 的绝对路径，避免不同工作目录下找不到脚本
$pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'get_stock_data.py';

if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    // 本地 Windows 便携环境还是用系统 python
    $pythonBinary = 'python';
} else {
    // 服务器 Linux 环境：显式指定可用的 python3 绝对路径
    $pythonBinary = '/www/server/pyporject_evn/versions/3.10.11/bin/python3';
    // ↑ 把这一行改成你在服务器上运行
    //    python3 -c "import sys; print(sys.executable)"
    // 得到的完整路径
}

// 逐个参数进行转义（脚本路径 + 4 个参数）
$escapedScript = escapeshellarg($pythonScript);
$escapedCode = escapeshellarg($code);
$escapedFrequency = escapeshellarg($frequency);
$escapedCount = escapeshellarg((string)$count);
$escapedEndDate = escapeshellarg($end_date);

// 直接拼接命令（已对脚本和参数逐一转义），并保留 2>&1 用于捕获 stderr
// 注意：这里不要再对整条命令调用 escapeshellcmd，否则会把 2>&1 转义掉，导致无法捕获错误输出
$command = "$pythonBinary $escapedScript $escapedCode $escapedFrequency $escapedCount $escapedEndDate 2>&1";

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
