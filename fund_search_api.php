<?php
/**
 * 基金搜索代理API
 * 代理东方财富基金搜索接口
 */
header('Content-Type: application/json; charset=utf-8');

$keyword = isset($_GET['key']) ? $_GET['key'] : '';

if (empty($keyword)) {
    echo json_encode(['success' => false, 'message' => '搜索关键词不能为空']);
    exit;
}

$encodedKey = urlencode($keyword);
$url = "https://fundsuggest.eastmoney.com/FundSearch/api/FundSearchAPI.ashx?m=9&key={$encodedKey}";

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
    echo json_encode(['success' => false, 'message' => '解析搜索结果失败']);
    exit;
}

$results = [];
foreach ($data['Datas'] as $item) {
    $results[] = [
        'code' => $item['CODE'] ?? '',
        'name' => $item['NAME'] ?? '',
        'pinyin' => $item['JP'] ?? '',
        'category' => $item['CATEGORY'] ?? '',
        'type' => $item['FTYPE'] ?? '',
        'nav' => $item['DWJZ'] ?? '',
        'nav_date' => $item['FSRQ'] ?? '',
        'min_purchase' => $item['MINSG'] ?? '',
        'company' => $item['JJGS'] ?? '',
        'manager' => $item['JJJL'] ?? '',
        'is_buy' => ($item['ISBUY'] ?? '0') === '1',
    ];
}

echo json_encode([
    'success' => true,
    'keyword' => $keyword,
    'total' => count($results),
    'data' => $results
]);
