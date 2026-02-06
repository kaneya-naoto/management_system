<?php
/**
 * 予約CSVエクスポートAPI
 */

// 共通ファイル読み込み
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

startSession();
requireLogin();

// GETメソッド制限
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// CSRFトークン検証（読み取り専用: トークンを消費しない）
$csrfToken = $_GET[CSRF_TOKEN_NAME] ?? null;
if (!verifyCsrfTokenReadOnly($csrfToken)) {
    http_response_code(403);
    exit('不正なリクエストです');
}

// アクセス可能な店舗を取得
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

if (empty($accessibleStoreIds)) {
    http_response_code(403);
    exit('アクセス権限がありません');
}

// フィルタ
$filterStatus = input('status', '');
$filterDateFrom = input('date_from', date('Y-m-01'));
$filterDateTo = input('date_to', date('Y-m-t'));

// クエリ構築
$whereConditions = [];
$params = [];

$inClause = buildInClause($accessibleStoreIds);
$whereConditions[] = "r.store_id IN ({$inClause['placeholders']})";
$whereConditions[] = "r.deleted_at IS NULL";
$params = array_merge($params, $inClause['params']);

if ($filterStatus !== '') {
    $whereConditions[] = "r.status = ?";
    $params[] = $filterStatus;
}

if ($filterDateFrom) {
    $whereConditions[] = "r.reservation_date >= ?";
    $params[] = $filterDateFrom;
}

if ($filterDateTo) {
    $whereConditions[] = "r.reservation_date <= ?";
    $params[] = $filterDateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// 件数取得（監査ログ用）
$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM reservations r WHERE {$whereClause}",
    $params
);
$totalCount = $countResult['cnt'] ?? 0;

// 大量データ対策: 10000件を超える場合は制限
$maxRows = 10000;
if ($totalCount > $maxRows) {
    http_response_code(400);
    exit("エクスポート件数が上限（{$maxRows}件）を超えています。日付範囲を狭めてください。");
}

// 監査ログ
logAudit('export', 'reservation', null, null, [
    'count' => $totalCount,
    'filters' => ['status' => $filterStatus, 'date_from' => $filterDateFrom, 'date_to' => $filterDateTo]
]);

// CSVヘッダー
$headers = [
    '予約日',
    '開始時間',
    '終了時間',
    '店舗',
    '営業区分',
    '顧客名',
    '電話番号',
    'メール',
    '人数',
    '基本料金',
    '合計料金',
    '予約経路',
    '決済状態',
    'ステータス',
    '備考',
];

// CSV出力
$filename = 'reservations_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// BOM（Excel対応）
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// ヘッダー行
fputcsv($output, $headers);

// ラベル変換は共通関数を使用（reservationStatusInfo, reservationSourceLabel, paymentStatusInfo）

// チャンク単位でデータ取得・出力（メモリ効率化）
$chunkSize = 500;
$offset = 0;

while (true) {
    $reservations = dbSelect(
        "SELECT r.*, s.name as store_name, sa.name as area_name
         FROM reservations r
         LEFT JOIN stores s ON r.store_id = s.id
         LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
         WHERE {$whereClause}
         ORDER BY r.reservation_date DESC, r.start_time DESC
         LIMIT {$chunkSize} OFFSET {$offset}",
        $params
    );

    if (empty($reservations)) {
        break;
    }

    foreach ($reservations as $row) {
        fputcsv($output, [
            $row['reservation_date'],
            substr($row['start_time'], 0, 5),
            substr($row['end_time'], 0, 5),
            sanitizeCsvValue($row['store_name']),
            sanitizeCsvValue($row['area_name'] ?? ''),
            sanitizeCsvValue($row['customer_name']),
            sanitizeCsvValue($row['customer_phone'] ?? ''),
            sanitizeCsvValue($row['customer_email'] ?? ''),
            $row['num_people'],
            $row['base_price'],
            $row['total_price'],
            reservationSourceLabel($row['source']),
            paymentStatusInfo($row['payment_status'])['label'],
            reservationStatusInfo($row['status'])['label'],
            sanitizeCsvValue($row['notes'] ?? ''),
        ]);
    }

    $offset += $chunkSize;

    // メモリ解放
    unset($reservations);
}

fclose($output);
