<?php
/**
 * A股净流入排名代理API
 * 数据源：东方财富 clist 接口
 * 返回字段：dm, mc, zxj, zdf, hsl, jlr, jlrl
 * 
 * 排序字段说明：
 *   f62  = 主力净流入（默认，超大单+大单）
 *   f184 = 主力净流入占比
 *   f66  = 超大单净流入
 *   f72  = 大单净流入
 *   f6   = 成交额
 *   f3   = 涨跌幅
 */
header('Content-Type: application/json; charset=utf-8');

// 参数
$page     = isset($_GET['page'])     ? intval($_GET['page'])     : 1;
$pageSize = isset($_GET['pagesize']) ? intval($_GET['pagesize']) : 50;
$sortField = isset($_GET['sort'])    ? $_GET['sort']             : 'f62';
$sortOrder = isset($_GET['order'])   ? intval($_GET['order'])    : 1; // 1=降序, 0=升序

// 限制参数范围
if ($page < 1) $page = 1;
if ($pageSize < 1) $pageSize = 50;
if ($pageSize > 200) $pageSize = 200;

$allowedSortFields = ['f62', 'f184', 'f66', 'f72', 'f6', 'f3'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'f62';
}
$sortOrder = ($sortOrder === 0) ? 0 : 1;

// A股股票筛选条件（排除ETF、债券、基金等非股票品种）
// m:0+t:6    = 深圳A股
// m:0+t:80   = 创业板
// m:1+t:2    = 上海A股
// m:1+t:23   = 科创板
$fs = 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23';

// 请求字段
// f2=最新价, f3=涨跌幅, f8=换手率, f12=代码, f13=市场, f14=名称
// f62=主力净流入, f184=主力净流入占比
// f66=超大单净流入, f69=超大单净流入占比
// f72=大单净流入, f75=大单净流入占比
// f78=中单净流入, f81=中单净流入占比
// f84=小单净流入, f87=小单净流入占比
$fields = 'f2,f3,f8,f12,f13,f14,f62,f184,f66,f69,f72,f75,f78,f81,f84,f87';

// 构建请求URL
$url = "https://push2.eastmoney.com/api/qt/clist/get?"
     . "pn={$page}&pz={$pageSize}&po={$sortOrder}&np=1&fltt=2&invt=2"
     . "&fid={$sortField}&fs=" . urlencode($fs) . "&fields={$fields}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://data.eastmoney.com/',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    echo json_encode(['error' => '请求东方财富数据失败: ' . ($error ?: "HTTP {$httpCode}")]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !isset($data['data']['diff']) || (isset($data['rc']) && $data['rc'] !== 0)) {
    echo json_encode(['error' => '解析东方财富数据失败']);
    exit;
}

// 字段映射：东方财富 → 前端
// dm(代码带sh/sz前缀), mc(名称), zxj(最新价), zdf(涨跌幅),
// hsl(换手率), jlr(主力净流入), jlrl(主力净流入占比)
$result = [];
foreach ($data['data']['diff'] as $item) {
    $market = isset($item['f13']) ? intval($item['f13']) : 1;
    $code   = isset($item['f12']) ? $item['f12'] : '';
    $prefix = ($market === 0) ? 'sz' : 'sh';

    // 过滤无效数据（停牌、退市等导致f2为"-"）
    $price = $item['f2'] ?? 0;
    if (!is_numeric($price)) {
        continue;
    }

    $result[] = [
        'dm'    => $prefix . $code,           // 代码（带sh/sz前缀，兼容前端）
        'mc'    => $item['f14'] ?? '',         // 名称
        'zxj'   => floatval($price),          // 最新价
        'zdf'   => floatval($item['f3'] ?? 0), // 涨跌幅
        'hsl'   => floatval($item['f8'] ?? 0), // 换手率
        'jlr'   => floatval($item['f62'] ?? 0), // 主力净流入（替代原全口径净流入）
        'jlrl'  => floatval($item['f184'] ?? 0), // 主力净流入占比（替代原净流入率）
        // 扩展字段：分层资金流（供前端未来扩展）
        'cjlr_super'       => floatval($item['f66'] ?? 0), // 超大单净流入
        'cjlr_super_rate'  => floatval($item['f69'] ?? 0), // 超大单净流入占比
        'cjlr_big'         => floatval($item['f72'] ?? 0), // 大单净流入
        'cjlr_big_rate'    => floatval($item['f75'] ?? 0), // 大单净流入占比
        'cjlr_mid'         => floatval($item['f78'] ?? 0), // 中单净流入
        'cjlr_mid_rate'    => floatval($item['f81'] ?? 0), // 中单净流入占比
        'cjlr_small'       => floatval($item['f84'] ?? 0), // 小单净流入
        'cjlr_small_rate'  => floatval($item['f87'] ?? 0), // 小单净流入占比
    ];
}

// 返回纯数组格式
echo json_encode($result, JSON_UNESCAPED_UNICODE);
