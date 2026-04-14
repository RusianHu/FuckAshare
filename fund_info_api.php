<?php
/**
 * 基金详细信息代理API
 * 代理东方财富基金信息接口
 */
header('Content-Type: application/json; charset=utf-8');

$codes = isset($_GET['codes']) ? $_GET['codes'] : '';

if (empty($codes)) {
    echo json_encode(['success' => false, 'message' => '基金代码不能为空']);
    exit;
}

// 验证基金代码格式
$codeList = array_map('trim', explode(',', $codes));
$validCodes = [];
foreach ($codeList as $c) {
    if (preg_match('/^\d{6}$/', $c)) {
        $validCodes[] = $c;
    }
}

if (empty($validCodes)) {
    echo json_encode(['success' => false, 'message' => '没有有效的基金代码']);
    exit;
}

$codeStr = implode(',', $validCodes);
$url = "https://fundmobapi.eastmoney.com/FundMNewApi/FundMNFInfo?Fcodes={$codeStr}&pageSize=20";

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

$data = json_decode($response, true);
if (!$data || !isset($data['Datas'])) {
    echo json_encode(['success' => false, 'message' => '解析基金数据失败']);
    exit;
}

$funds = [];
foreach ($data['Datas'] as $item) {
    $funds[] = [
        'code' => $item['FCODE'] ?? '',
        'name' => $item['SHORTNAME'] ?? '',
        'type' => $item['FTYPE'] ?? '',
        'nav_date' => $item['PDATE'] ?? '',
        'nav' => $item['NAV'] ?? '',
        'acc_nav' => $item['ACCNAV'] ?? '',
        'nav_chg_rate' => $item['NAVCHGRT'] ?? '',
        'latest_price' => $item['GSZ'] ?? '',
        'is_buy' => ($item['ISBUY'] ?? '0') === '1',
        'min_purchase' => $item['MINSG'] ?? '',
        'fund_company' => $item['JJGS'] ?? '',
        'fund_manager' => $item['JJJL'] ?? '',
    ];
}

echo json_encode([
    'success' => true,
    'total' => count($funds),
    'data' => $funds
]);
