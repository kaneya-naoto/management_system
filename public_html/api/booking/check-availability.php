<?php
/**
 * 空き状況チェックAPI
 */

// 共通ファイル読み込み
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// POSTのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonErrorResponse('Method not allowed', 405);
}

// レート制限（IP単位: 30リクエスト/分、ファイルロック付き）
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitKey = 'booking_check_' . md5($clientIp);
$rateLimit = checkRateLimit($rateLimitKey, 30, 60);

if (!$rateLimit['allowed']) {
    header('Retry-After: ' . $rateLimit['retry_after']);
    jsonErrorResponse('Rate limit exceeded', 429, ['available' => false]);
}

// JSONリクエストボディ取得
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonErrorResponse('Invalid request');
}

$salesAreaId = (int) ($input['sales_area_id'] ?? 0);
$reservationDate = $input['reservation_date'] ?? '';
$startTime = $input['start_time'] ?? '';
$endTime = $input['end_time'] ?? '';

// バリデーション
if ($salesAreaId <= 0) {
    jsonResponse(['available' => false, 'message' => '店舗を選択してください']);
}

if (empty($reservationDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
    jsonResponse(['available' => false, 'message' => '日付の形式が不正です']);
}

if (empty($startTime) || empty($endTime)) {
    jsonResponse(['available' => false, 'message' => '時間を選択してください']);
}

// 時間フォーマット検証（HH:MM形式）
if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $startTime) ||
    !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $endTime)) {
    jsonResponse(['available' => false, 'message' => '時間の形式が不正です']);
}

// 時刻を分に変換（共通関数を使用）
$startMinutes = timeToMinutes($startTime);
$endMinutes = ($endTime === '00:00') ? 24 * 60 : timeToMinutes($endTime);

// 終了時間が開始時間以下の場合は翌日扱い（深夜またぎ）
if ($endMinutes <= $startMinutes) {
    $endMinutes += 24 * 60;
}

$durationMinutes = $endMinutes - $startMinutes;

// 予約時間は1〜8時間
if ($durationMinutes < 60 || $durationMinutes > 8 * 60) {
    jsonResponse(['available' => false, 'message' => '予約は1〜8時間の範囲で選択してください']);
}

// 過去日チェック
if ($reservationDate < date('Y-m-d')) {
    jsonResponse(['available' => false, 'message' => '過去の日付は選択できません']);
}

// 営業区分存在チェック
$salesArea = dbSelectOne(
    "SELECT sa.*, s.name as store_name
     FROM sales_areas sa
     INNER JOIN stores s ON sa.store_id = s.id
     WHERE sa.id = ? AND sa.is_active = 1 AND sa.deleted_at IS NULL",
    [$salesAreaId]
);

if (!$salesArea) {
    jsonResponse(['available' => false, 'message' => '選択された店舗は利用できません']);
}

// 利用可能な鍵数を取得（清掃時間考慮、深夜跨ぎ対応）
// 共通関数を使用してロジック統一
$availableKeys = getAvailableKeyCount($salesAreaId, $reservationDate, $startTime, $endTime);

if ($availableKeys <= 0) {
    jsonResponse([
        'available' => false,
        'message' => '選択された時間帯は満室です',
    ]);
}

// 予約可能
jsonResponse([
    'available' => true,
    'message' => '予約可能です',
    'available_rooms' => $availableKeys,
]);
