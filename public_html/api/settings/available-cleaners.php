<?php
/**
 * 店舗に対応している清掃者（固定者未登録）を取得するAPI
 * GET /api/settings/available-cleaners?store_id={id}
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

startSession();
requireLogin();
requireRole('OWNER');

// アクセス可能な店舗を取得
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

if (empty($accessibleStoreIds)) {
    jsonResponse(['error' => 'アクセス権限がありません'], 403);
}

// パラメータ取得
$storeId = (int) input('store_id', 0);

if ($storeId <= 0) {
    jsonResponse(['error' => '店舗IDが指定されていません'], 400);
}

// 指定された店舗へのアクセス権限チェック
if (!in_array($storeId, $accessibleStoreIds)) {
    jsonResponse(['error' => 'この店舗へのアクセス権限がありません'], 403);
}

// その店舗に対応していて、まだ固定者として未登録の清掃者を取得
$cleaners = dbSelect(
    "SELECT c.id, c.name, c.phone
     FROM cleaners c
     INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
     WHERE cs.store_id = ?
       AND c.is_active = 1
       AND c.deleted_at IS NULL
       AND c.id NOT IN (
           SELECT cleaner_id FROM fixed_cleaners
           WHERE store_id = ? AND deleted_at IS NULL
       )
     ORDER BY c.name",
    [$storeId, $storeId]
);

jsonResponse(['cleaners' => $cleaners]);
