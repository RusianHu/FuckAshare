<?php
/**
 * 新闻舆情只读 API
 *
 * action=asset     指定股票/基金新闻
 * action=market    市场关键词热点新闻
 * action=sentiment 标的或市场确定性标题情绪快照
 */

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/NewsService.php';

SecurityAudit::init([
    'endpoint' => 'news',
    'rate_limit' => 30,
    'rate_window' => 60,
]);
SecurityAudit::requireMethod('GET');
header('Content-Type: application/json; charset=utf-8');

$action = SecurityAudit::getParam('action', 'market', [
    'whitelist' => SecurityAudit::ALLOWED_NEWS_ACTIONS,
]);
$limit = SecurityAudit::getParam('limit', 20, ['int' => true, 'min' => 1, 'max' => 50]);
$service = new NewsService();

switch ($action) {
    case 'asset':
        $assetType = SecurityAudit::getParam('asset_type', 'stock', [
            'whitelist' => SecurityAudit::ALLOWED_NEWS_ASSET_TYPES,
        ]);
        $code = SecurityAudit::getParam('code', '', [
            'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
            'sanitize' => 'stock_code',
        ]);
        $name = SecurityAudit::getParam('name', '', [
            'maxLength' => SecurityAudit::MAX_KEYWORD_LENGTH,
            'sanitize' => 'keyword',
        ]);
        if ($code === '' && $name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'missing_asset', 'message' => 'code 与 name 至少填写一项'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = $service->assetNews($assetType, $code, $name, $limit);
        break;

    case 'sentiment':
        $scope = SecurityAudit::getParam('scope', 'market', [
            'whitelist' => SecurityAudit::ALLOWED_NEWS_SCOPES,
        ]);
        $assetType = SecurityAudit::getParam('asset_type', 'stock', [
            'whitelist' => SecurityAudit::ALLOWED_NEWS_ASSET_TYPES,
        ]);
        $code = SecurityAudit::getParam('code', '', [
            'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
            'sanitize' => 'stock_code',
        ]);
        $name = SecurityAudit::getParam('name', '', [
            'maxLength' => SecurityAudit::MAX_KEYWORD_LENGTH,
            'sanitize' => 'keyword',
        ]);
        $keywords = parseNewsKeywords(SecurityAudit::getParam('keywords', '', [
            'maxLength' => 240,
            'sanitize' => 'keyword',
        ]));
        if ($scope === 'asset' && $code === '' && $name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'missing_asset', 'message' => '标的情绪查询需要 code 或 name'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = $service->sentimentSnapshot($scope, $assetType, $code, $name, $keywords, $limit);
        break;

    case 'market':
    default:
        $keywords = parseNewsKeywords(SecurityAudit::getParam('keywords', '', [
            'maxLength' => 240,
            'sanitize' => 'keyword',
        ]));
        $result = $service->marketHotNews($keywords, $limit);
        break;
}

if (!$result->success) {
    http_response_code(in_array($result->errorCode, ['invalid_asset_type', 'missing_asset', 'missing_query', 'invalid_scope'], true) ? 400 : 502);
}
echo $result->toJson(false);

/** @return string[] */
function parseNewsKeywords(string $raw): array
{
    if (trim($raw) === '') return [];
    $parts = preg_split('/[,，;；]+/u', $raw) ?: [];
    $result = [];
    foreach (array_slice($parts, 0, 4) as $part) {
        $part = trim($part);
        if ($part !== '') $result[] = mb_substr($part, 0, 60);
    }
    return array_values(array_unique($result));
}
