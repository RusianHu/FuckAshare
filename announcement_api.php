<?php
/**
 * 股票公告与公司事件只读 API。
 *
 * action=list   公告列表/事件筛选
 * action=detail 单篇公告正文
 */

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/AnnouncementService.php';

SecurityAudit::init([
    'endpoint' => 'announcement',
    'rate_limit' => 30,
    'rate_window' => 60,
]);
SecurityAudit::requireMethod('GET');
header('Content-Type: application/json; charset=utf-8');

$action = SecurityAudit::getParam('action', 'list', [
    'whitelist' => SecurityAudit::ALLOWED_ANNOUNCEMENT_ACTIONS,
]);
$service = new AnnouncementService();

if ($action === 'detail') {
    $id = SecurityAudit::getParam('id', '', [
        'required' => true,
        'pattern' => SecurityAudit::ANNOUNCEMENT_ID_PATTERN,
        'maxLength' => 20,
    ]);
    $contentLimit = SecurityAudit::getParam('content_limit', 12000, ['int' => true, 'min' => 1000, 'max' => 20000]);
    $result = $service->detail($id, $contentLimit);
} else {
    $scope = SecurityAudit::getParam('scope', 'market', ['whitelist' => SecurityAudit::ALLOWED_ANNOUNCEMENT_SCOPES]);
    $code = SecurityAudit::getParam('code', '', [
        'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
        'sanitize' => 'stock_code',
    ]);
    $name = SecurityAudit::getParam('name', '', [
        'maxLength' => SecurityAudit::MAX_KEYWORD_LENGTH,
        'sanitize' => 'keyword',
    ]);
    if ($scope === 'stock' && $code === '' && $name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'code' => 'missing_stock', 'message' => '股票范围需要 code 或 name'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = $service->list([
        'scope' => $scope,
        'code' => $code,
        'name' => $name,
        'market' => SecurityAudit::getParam('market', 'all', ['whitelist' => SecurityAudit::ALLOWED_ANNOUNCEMENT_MARKETS]),
        'event_type' => SecurityAudit::getParam('event_type', 'all', ['whitelist' => SecurityAudit::ALLOWED_ANNOUNCEMENT_EVENT_TYPES]),
        'importance' => SecurityAudit::getParam('importance', 'important', ['whitelist' => SecurityAudit::ALLOWED_ANNOUNCEMENT_IMPORTANCE]),
        'date_from' => SecurityAudit::getParam('date_from', '', ['pattern' => SecurityAudit::OPTIONAL_DATE_PATTERN, 'maxLength' => 10]),
        'date_to' => SecurityAudit::getParam('date_to', '', ['pattern' => SecurityAudit::OPTIONAL_DATE_PATTERN, 'maxLength' => 10]),
        'page' => SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 100]),
        'limit' => SecurityAudit::getParam('limit', $scope === 'market' ? 30 : 20, ['int' => true, 'min' => 1, 'max' => 50]),
    ]);
}

if (!$result->success) {
    $clientErrors = ['invalid_scope', 'invalid_market', 'invalid_event_type', 'invalid_importance', 'invalid_date_range', 'invalid_announcement_id', 'missing_stock', 'invalid_stock', 'stock_not_found', 'ambiguous_stock'];
    http_response_code(in_array($result->errorCode, $clientErrors, true) ? 400 : 502);
}
echo $result->toJson(false);
