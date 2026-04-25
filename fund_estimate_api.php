<?php
/**
 * 基金实时估值代理API
 * Phase 1.3: 内部委托 FundService::estimate()，保留旧响应格式兼容前端
 * 新增: 批量估值参数 codes（逗号分隔），一次请求多只基金
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'fund_estimate']);

// 支持批量参数 codes（优先）或单只 code
$codesParam = SecurityAudit::getParam('codes', '', ['maxLength' => 500]);

if (!empty($codesParam)) {
    // 批量估值
    $codeList = array_values(array_filter(array_map('trim', explode(',', $codesParam)), 'strlen'));
    $codeList = array_values(array_unique($codeList));
    foreach ($codeList as $c) {
        if (!preg_match('/^\d{6}$/', $c)) {
            echo json_encode(['success' => false, 'message' => "基金代码 {$c} 格式不正确"]);
            exit;
        }
    }
    if (count($codeList) > 20) {
        echo json_encode(['success' => false, 'message' => '基金代码数量超过限制，最多 20 个']);
        exit;
    }

    require_once __DIR__ . '/lib/FundService.php';
    $service = new FundService();
    $result = $service->batchEstimate($codeList);

    if ($result->hasData()) {
        // 转换为数组格式，兼容前端
        $items = [];
        foreach ($result->data as $code => $item) {
            $items[] = $item;
        }
        echo json_encode([
            'success' => true,
            'total'   => count($items),
            'data'    => $items,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => $result->errorMessage ?? '批量估值请求失败'], JSON_UNESCAPED_UNICODE);
    }
} else {
    // 单只估值（兼容旧前端）
    $code = SecurityAudit::getParam('code', '', [
        'required'  => true,
        'pattern'   => SecurityAudit::FUND_CODE_PATTERN,
        'maxLength' => 6,
    ]);

    require_once __DIR__ . '/lib/FundService.php';
    $service = new FundService();
    $result = $service->estimate($code);

    if ($result->hasData()) {
        echo json_encode([
            'success' => true,
            'data'    => $result->data,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => $result->errorMessage ?? '解析基金估值数据失败'], JSON_UNESCAPED_UNICODE);
    }
}
