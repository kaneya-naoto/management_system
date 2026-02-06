<?php
/**
 * 支払いCSVエクスポートAPI
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
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];

if (empty($accessibleStoreIds)) {
    http_response_code(403);
    exit('アクセス権限がありません');
}

// フィルタ
$filterStatus = input('status', '');
$filterCleanerId = (int) input('cleaner_id', 0);
$filterDateFrom = input('date_from', date('Y-m-01'));
$filterDateTo = input('date_to', date('Y-m-t'));

// クエリ構築
$whereConditions = [];
$params = [];

$inClause = buildInClause($accessibleStoreIds);
$whereConditions[] = "cp.store_id IN ({$inClause['placeholders']})";
$params = array_merge($params, $inClause['params']);

if ($filterStatus !== '') {
    $whereConditions[] = "cp.status = ?";
    $params[] = $filterStatus;
}

if ($filterCleanerId > 0) {
    $whereConditions[] = "cp.cleaner_id = ?";
    $params[] = $filterCleanerId;
}

if ($filterDateFrom) {
    $whereConditions[] = "DATE(j.scheduled_at) >= ?";
    $params[] = $filterDateFrom;
}

if ($filterDateTo) {
    $whereConditions[] = "DATE(j.scheduled_at) <= ?";
    $params[] = $filterDateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// 件数取得（監査ログ用）
$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt
     FROM cleaner_payments cp
     INNER JOIN cleaning_jobs j ON cp.job_id = j.id
     WHERE {$whereClause}",
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
logAudit('export', 'cleaner_payment', null, null, [
    'count' => $totalCount,
    'filters' => ['status' => $filterStatus, 'date_from' => $filterDateFrom, 'date_to' => $filterDateTo]
]);

// CSVヘッダー
$headers = [
    '案件日',
    '時間',
    '店舗',
    '営業区分',
    '清掃者名',
    '電話番号',
    '基本報酬',
    '延長報酬',
    '総報酬',
    'ステータス',
    '支払日',
];

// CSV出力
$filename = 'payments_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// BOM（Excel対応）
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// ヘッダー行
fputcsv($output, $headers);

// チャンク単位でデータ取得・出力（メモリ効率化）
$chunkSize = 500;
$offset = 0;

while (true) {
    $payments = dbSelect(
        "SELECT cp.*, DATE(j.scheduled_at) as scheduled_date, TIME(j.scheduled_at) as scheduled_time,
                c.name as cleaner_name, c.phone as cleaner_phone,
                s.name as store_name, sa.name as area_name
         FROM cleaner_payments cp
         INNER JOIN cleaning_jobs j ON cp.job_id = j.id
         INNER JOIN cleaners c ON cp.cleaner_id = c.id
         INNER JOIN stores s ON cp.store_id = s.id
         INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
         WHERE {$whereClause}
         ORDER BY j.scheduled_at DESC
         LIMIT {$chunkSize} OFFSET {$offset}",
        $params
    );

    if (empty($payments)) {
        break;
    }

    foreach ($payments as $row) {
        $statusLabel = $row['status'] === 'paid' ? '支払済' : '未払い';

        fputcsv($output, [
            $row['scheduled_date'],
            substr($row['scheduled_time'], 0, 5),
            sanitizeCsvValue($row['store_name']),
            sanitizeCsvValue($row['area_name']),
            sanitizeCsvValue($row['cleaner_name']),
            sanitizeCsvValue($row['cleaner_phone']),
            $row['base_amount'],
            $row['extension_amount'],
            $row['total_amount'],
            $statusLabel,
            $row['paid_at'] ?? '',
        ]);
    }

    $offset += $chunkSize;

    // メモリ解放
    unset($payments);
}

fclose($output);
