<?php
/**
 * 板块资金流向代理API
 * 代理东方财富板块资金流向接口
 */
header('Content-Type: application/json; charset=utf-8');

$key = isset($_GET['key']) ? $_GET['key'] : 'f62';
$type = isset($_GET['type']) ? $_GET['type'] : 'industry';

// 验证key参数
$allowedKeys = ['f62', 'f164', 'f174'];
if (!in_array($key, $allowedKeys)) {
    echo json_encode(['success' => false, 'message' => 'key参数无效，允许: f62(今日), f164(5日), f174(10日)']);
    exit;
}

// 映射板块类型
$typeMap = [
    'industry' => 'm:90+s:4',    // 行业
    'concept'  => 'm:90+e:4',    // 概念
    'theme'    => 'm:90+t:3',    // 主题
    'region'   => 'm:90+t:1',    // 地域
];

if (!isset($typeMap[$type])) {
    echo json_encode(['success' => false, 'message' => 'type参数无效，允许: industry, concept, theme, region']);
    exit;
}

$codeParam = $typeMap[$type];
$url = "https://data.eastmoney.com/dataapi/bkzj/getbkzj?key={$key}&code={$codeParam}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://data.eastmoney.com/bkzj.html',
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
if (!$data || !isset($data['data']['diff'])) {
    echo json_encode(['success' => false, 'message' => '解析数据失败']);
    exit;
}

// 格式化板块数据
$sectors = [];
foreach ($data['data']['diff'] as $item) {
    $sectors[] = [
        'code' => $item['f12'] ?? '',
        'name' => $item['f14'] ?? '',
        'net_inflow_today' => $item['f62'] ?? 0,
        'net_inflow_5d' => $item['f164'] ?? 0,
        'net_inflow_10d' => $item['f174'] ?? 0,
        'change_pct' => $item['f3'] ?? 0,
        'main_net_inflow' => $item['f66'] ?? 0,
        'super_net_inflow' => $item['f70'] ?? 0,
        'big_net_inflow' => $item['f74'] ?? 0,
        'mid_net_inflow' => $item['f78'] ?? 0,
        'small_net_inflow' => $item['f82'] ?? 0,
        'turnover_rate' => $item['f8'] ?? 0,
        'amount' => $item['f6'] ?? 0,
    ];
}

echo json_encode([
    'success' => true,
    'key' => $key,
    'type' => $type,
    'total' => count($sectors),
    'data' => $sectors
]);
