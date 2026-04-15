<?php
/**
 * 基金实时估值代理API
 * 代理天天基金实时估值接口（JSONP解析）
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'fund_estimate']);

$code = SecurityAudit::getParam('code', '', [
    'required'  => true,
    'pattern'   => SecurityAudit::FUND_CODE_PATTERN,
    'maxLength' => 6,
]);

$url = "https://fundgz.1234567.com.cn/js/{$code}.js?rt=" . time();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://fund.eastmoney.com/',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => '请求失败: ' . ($error ?: "HTTP {$httpCode}")]);
    exit;
}

// 解析JSONP: jsonpgz({...})
if (preg_match('/jsonpgz\((.+)\);?/s', $response, $matches)) {
    $data = json_decode($matches[1], true);
    if ($data) {
        echo json_encode([
            'success' => true,
            'data' => [
                'fundcode' => $data['fundcode'] ?? '',
                'name' => $data['name'] ?? '',
                'jzrq' => $data['jzrq'] ?? '',         // 净值日期
                'dwjz' => $data['dwjz'] ?? '',          // 单位净值
                'gsz' => $data['gsz'] ?? '',            // 估算值
                'gszzl' => $data['gszzl'] ?? '',        // 估算涨跌幅
                'gztime' => $data['gztime'] ?? '',      // 估算时间
            ]
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => '解析基金估值数据失败，可能非交易时间或基金代码不存在']);
