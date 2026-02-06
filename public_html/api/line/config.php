<?php
/**
 * LINE設定取得API（LIFF用）
 * GET /api/line/config?store_code={store_code}
 *
 * 後方互換: store_id パラメータも受け付けるが、store_code を推奨
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/line_helpers.php';
require_once __DIR__ . '/../../includes/audit_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// GET以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonErrorResponse('Method Not Allowed', 405);
}

// レート制限（IP単位: 30リクエスト/分）
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimit = checkRateLimit('line_config_' . md5($clientIp), 30, 60);

if (!$rateLimit['allowed']) {
    header('Retry-After: ' . $rateLimit['retry_after']);
    jsonErrorResponse('Rate limit exceeded', 429);
}

// store_code を優先、後方互換で store_id も受け付ける
$storeCode = trim($_GET['store_code'] ?? '');
$storeId = (int)($_GET['store_id'] ?? 0);

if (empty($storeCode) && $storeId <= 0) {
    jsonErrorResponse('store_code is required', 400);
}

// 店舗検索
if (!empty($storeCode)) {
    $store = dbSelectOne(
        "SELECT id, code, name FROM stores WHERE code = ? AND is_active = 1 AND deleted_at IS NULL",
        [$storeCode]
    );
} else {
    $store = dbSelectOne(
        "SELECT id, code, name FROM stores WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
        [$storeId]
    );
}

if (!$store) {
    jsonErrorResponse('Store not found', 404);
}

// LINE設定取得
$lineConfig = getStoreLineConfig($store['id']);

if (!$lineConfig || !$lineConfig['is_active']) {
    jsonResponse([
        'has_config' => false,
        'liff_id' => null
    ]);
}

// 公開可能な情報のみ返す
jsonResponse([
    'has_config' => true,
    'liff_id' => $lineConfig['liff_id'] ?? null
]);
