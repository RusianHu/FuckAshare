<?php
/**
 * 股票实时行情代理API
 * 代理东方财富实时行情接口，解决跨域问题
 */
header('Content-Type: application/json; charset=utf-8');

// 获取参数
$codes = isset($_GET['codes']) ? $_GET['codes'] : '';

if (empty($codes)) {
    echo json_encode(['success' => false, 'message' => '股票代码不能为空']);
    exit;
}

// 解析股票代码，支持多种格式: sh000001, sz399001, 000001.XSHG
$codeList = array_map('trim', explode(',', $codes));
$secids = [];

foreach ($codeList as $code) {
    $code = preg_replace('/[^A-Za-z0-9.]/', '', $code);
    if (empty($code)) continue;

    if (strpos($code, '.XSHG') !== false) {
        $num = str_replace('.XSHG', '', $code);
        $secids[] = '1.' . $num;
    } elseif (strpos($code, '.XSHE') !== false) {
        $num = str_replace('.XSHE', '', $code);
        $secids[] = '0.' . $num;
    } elseif (stripos($code, 'sh') === 0) {
        $num = substr($code, 2);
        $secids[] = '1.' . $num;
    } elseif (stripos($code, 'sz') === 0) {
        $num = substr($code, 2);
        $secids[] = '0.' . $num;
    } elseif (preg_match('/^6\d{5}$/', $code)) {
        // 6开头默认沪市
        $secids[] = '1.' . $code;
    } elseif (preg_match('/^(0|3)\d{5}$/', $code)) {
        // 0/3开头默认深市
        $secids[] = '0.' . $code;
    } else {
        $secids[] = '1.' . $code;
    }
}

if (empty($secids)) {
    echo json_encode(['success' => false, 'message' => '无法解析股票代码']);
    exit;
}

$secidStr = implode(',', $secids);
$fields = 'f2,f3,f4,f5,f6,f7,f8,f9,f10,f12,f13,f14,f15,f16,f17,f18,f20,f21,f23,f24,f25,f26,f115';
$timestamp = time() * 1000;

$url = "https://push2.eastmoney.com/api/qt/ulist.np/get?fltt=2&fields={$fields}&secids={$secidStr}&_={$timestamp}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://quote.eastmoney.com/',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => '请求东方财富API失败: ' . ($error ?: "HTTP {$httpCode}")]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !isset($data['data'])) {
    echo json_encode(['success' => false, 'message' => '解析数据失败']);
    exit;
}

// 格式化返回数据
$stocks = [];
if (isset($data['data']['diff']) && is_array($data['data']['diff'])) {
    foreach ($data['data']['diff'] as $item) {
        $stocks[] = [
            'code' => $item['f12'] ?? '',
            'market' => $item['f13'] ?? 0,
            'name' => $item['f14'] ?? '',
            'price' => $item['f2'] ?? 0,
            'change_pct' => $item['f3'] ?? 0,
            'change_amt' => $item['f4'] ?? 0,
            'volume' => $item['f5'] ?? 0,
            'amount' => $item['f6'] ?? 0,
            'amplitude' => $item['f7'] ?? 0,
            'turnover_rate' => $item['f8'] ?? 0,
            'pe' => $item['f9'] ?? 0,
            'high' => $item['f15'] ?? 0,
            'low' => $item['f16'] ?? 0,
            'open' => $item['f17'] ?? 0,
            'prev_close' => $item['f18'] ?? 0,
            'total_mv' => $item['f20'] ?? 0,
            'circ_mv' => $item['f21'] ?? 0,
            'pb' => $item['f23'] ?? 0,
            'roe' => $item['f24'] ?? 0,
            'total_shares' => $item['f25'] ?? 0,
            'circ_shares' => $item['f26'] ?? 0,
            'pe_ttm' => $item['f115'] ?? 0,
        ];
    }
}

echo json_encode([
    'success' => true,
    'total' => count($stocks),
    'data' => $stocks
]);
