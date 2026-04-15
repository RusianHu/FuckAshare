<?php
/**
 * 股票资金流向代理API
 * 代理东方财富资金流向接口
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'stock_flow']);

$code   = SecurityAudit::getParam('code', '', [
    'required'  => true,
    'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
    'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
]);
$market = SecurityAudit::getParam('market', '', ['sanitize' => 'digits']);
$lmt    = SecurityAudit::getParam('lmt', 0, ['int' => true, 'min' => 0, 'max' => 1000]);

// 构造secid参数

if (!empty($market) && is_numeric($market)) {
    $secid = $market . '.' . $code;
} elseif (stripos($code, 'sh') === 0) {
    $secid = '1.' . substr($code, 2);
} elseif (stripos($code, 'sz') === 0) {
    $secid = '0.' . substr($code, 2);
} elseif (strpos($code, '.XSHG') !== false) {
    $secid = '1.' . str_replace('.XSHG', '', $code);
} elseif (strpos($code, '.XSHE') !== false) {
    $secid = '0.' . str_replace('.XSHE', '', $code);
} elseif (preg_match('/^6\d{5}$/', $code)) {
    $secid = '1.' . $code;
} elseif (preg_match('/^(0|3)\d{5}$/', $code)) {
    $secid = '0.' . $code;
} else {
    $secid = '1.' . $code;
}

$url = "https://push2his.eastmoney.com/api/qt/stock/fflow/daykline/get?secid={$secid}&fields1=f1,f2,f3,f7&fields2=f51,f52,f53,f54,f55,f56,f57,f58,f59,f60,f61,f62,f63,f64,f65";
if ($lmt > 0) {
    $url .= "&lmt={$lmt}";
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://data.eastmoney.com/',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => '请求失败: ' . ($error ?: "HTTP {$httpCode}")]);
    exit;
}

$data = json_decode($response, true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => '解析数据失败']);
    exit;
}

// 解析klines数据
$flowData = [];
if (isset($data['data']['klines']) && is_array($data['data']['klines'])) {
    foreach ($data['data']['klines'] as $line) {
        $parts = explode(',', $line);
        if (count($parts) >= 6) {
            $flowData[] = [
                'time' => $parts[0],
                'main_net_inflow' => floatval($parts[1]),       // 主力净流入
                'small_net_inflow' => floatval($parts[2]),      // 小单净流入
                'mid_net_inflow' => floatval($parts[3]),        // 中单净流入
                'big_net_inflow' => floatval($parts[4]),        // 大单净流入
                'super_net_inflow' => floatval($parts[5]),      // 超大单净流入
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'code' => $code,
    'secid' => $secid,
    'data' => $flowData
]);
