<?php
/**
 * 予約カレンダーデータAPI
 * FullCalendar用のJSON形式でデータを返す
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

startSession();
requireLogin();

// アクセス可能な店舗を取得
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

if (empty($accessibleStoreIds)) {
    jsonResponse(['error' => 'アクセス権限がありません'], 403);
}

// パラメータ取得
$start = input('start', date('Y-m-01'));
$end = input('end', date('Y-m-t', strtotime('+1 month')));

// 日付バリデーション
if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $end)) {
    jsonResponse(['error' => '日付形式が不正です'], 400);
}

// クエリ構築
$inClause = buildInClause($accessibleStoreIds);

$reservations = dbSelect(
    "SELECT r.id, r.reservation_date, r.start_time, r.end_time,
            r.customer_name, r.status, r.num_people,
            s.name as store_name, sa.name as area_name
     FROM reservations r
     LEFT JOIN stores s ON r.store_id = s.id
     LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
     WHERE r.store_id IN ({$inClause['placeholders']})
       AND r.reservation_date >= ?
       AND r.reservation_date <= ?
       AND r.deleted_at IS NULL
     ORDER BY r.reservation_date, r.start_time",
    array_merge($inClause['params'], [$start, $end])
);

// FullCalendar用の形式に変換
$events = [];
$statusColors = [
    'pending' => '#6c757d',    // グレー
    'confirmed' => '#0d6efd',  // 青
    'completed' => '#198754',  // 緑
    'cancelled' => '#dc3545',  // 赤
];

foreach ($reservations as $res) {
    $startDateTime = $res['reservation_date'] . 'T' . $res['start_time'];
    $endDateTime = $res['reservation_date'] . 'T' . $res['end_time'];

    $events[] = [
        'id' => $res['id'],
        'title' => $res['customer_name'] . ' (' . $res['num_people'] . '名)',
        'start' => $startDateTime,
        'end' => $endDateTime,
        'color' => $statusColors[$res['status']] ?? '#6c757d',
        'url' => url('/reservations/' . $res['id']),
        'extendedProps' => [
            'status' => $res['status'],
            'store' => $res['store_name'],
            'area' => $res['area_name'],
        ],
    ];
}

jsonResponse($events);
