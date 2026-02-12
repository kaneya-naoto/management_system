<?php
/**
 * 一日分の空き状況バッチ取得API
 *
 * GET /api/booking/day-availability?sales_area_id=X&date=YYYY-MM-DD
 *
 * 営業時間内の全30分スロットの空き状況を一括で返す。
 * パフォーマンス最適化: 1クエリ（予約一括）で全スロットを計算。
 */

// 共通ファイル読み込み
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// GETのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonErrorResponse('Method not allowed', 405);
}

// レート制限（IP単位: 20リクエスト/分）
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitKey = 'day_avail_' . md5($clientIp);
$rateLimit = checkRateLimit($rateLimitKey, 20, 60);

if (!$rateLimit['allowed']) {
    header('Retry-After: ' . $rateLimit['retry_after']);
    jsonErrorResponse('Rate limit exceeded', 429);
}

// パラメータ取得・検証
$salesAreaId = filter_input(INPUT_GET, 'sales_area_id', FILTER_VALIDATE_INT);
$date = $_GET['date'] ?? '';

if (!$salesAreaId || $salesAreaId <= 0) {
    jsonErrorResponse('sales_area_id is required');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonErrorResponse('Invalid date format');
}

// 日付の実在性チェック
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    jsonErrorResponse('Invalid date');
}

// リクエスト処理中の時刻を固定（date()の複数呼び出しによるレースコンディション防止）
$now = new DateTime();
$today = $now->format('Y-m-d');
$nowMinutesFixed = (int)$now->format('H') * 60 + (int)$now->format('i');

// 過去日チェック
if ($date < $today) {
    jsonErrorResponse('Past date');
}

// 営業区分+店舗情報取得
$salesArea = dbSelectOne(
    "SELECT sa.id, sa.store_id, sa.cleaning_duration_minutes,
            s.opening_time, s.closing_time, s.is_24h_open, s.booking_days_ahead
     FROM sales_areas sa
     INNER JOIN stores s ON sa.store_id = s.id
     WHERE sa.id = ? AND sa.is_active = 1 AND sa.deleted_at IS NULL
       AND s.is_active = 1 AND s.deleted_at IS NULL",
    [$salesAreaId]
);

if (!$salesArea) {
    jsonErrorResponse('Sales area not found');
}

// 予約受付日数チェック
$maxDate = date('Y-m-d', strtotime('+' . (int)$salesArea['booking_days_ahead'] . ' days'));
if ($date > $maxDate) {
    jsonErrorResponse('Date too far in the future');
}

// === 営業時間内スロット生成 ===
$openingTime = substr($salesArea['opening_time'] ?? '10:00:00', 0, 5);
$closingTime = substr($salesArea['closing_time'] ?? '00:00:00', 0, 5);
$is24h = (bool)$salesArea['is_24h_open'];

$slotTimes = generateBusinessSlots($openingTime, $closingTime, $is24h);

// === next_dayフラグ算出（midnight境界以降を翌日扱い） ===
$isNextDay = false;
$prevSlotMin = -1;
$nextDayFlags = [];
foreach ($slotTimes as $slotTime) {
    $slotMin = timeToMinutes($slotTime);
    if ($prevSlotMin >= 0 && $slotMin < $prevSlotMin) {
        $isNextDay = true; // midnight境界を超えた
    }
    $nextDayFlags[$slotTime] = $isNextDay;
    $prevSlotMin = $slotMin;
}

// === 全予約を一括取得 ===
$cleaningMinutes = (int)($salesArea['cleaning_duration_minutes'] ?? CLEANING_TIME_MINUTES);

$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

$reservations = dbSelect(
    "SELECT r.id as reservation_id, r.reservation_date, r.start_time, r.end_time,
            cj.completed_at
     FROM reservations r
     LEFT JOIN cleaning_jobs cj ON cj.reservation_id = r.id
     WHERE r.sales_area_id = ?
       AND r.reservation_date IN (?, ?, ?)
       AND r.status NOT IN ('cancelled')
       AND r.deleted_at IS NULL",
    [$salesAreaId, $date, $prevDate, $nextDate]
);

// === ブロック区間を計算 ===
$blockIntervals = [];
foreach ($reservations as $r) {
    $blockStart = new DateTime($r['reservation_date'] . ' ' . $r['start_time']);

    // block_end: 予約終了時刻（深夜跨ぎ考慮）+ 清掃時間
    $endDt = new DateTime($r['reservation_date'] . ' ' . $r['end_time']);
    if ($r['end_time'] < $r['start_time']) {
        $endDt->modify('+1 day');
    }

    if (!empty($r['completed_at'])) {
        // 清掃完了済みならその時刻をblock_endに
        $blockEnd = new DateTime($r['completed_at']);
    } else {
        // 清掃未完了なら予約終了+清掃時間
        $blockEnd = clone $endDt;
        $blockEnd->modify("+{$cleaningMinutes} minutes");
    }

    $blockIntervals[] = [
        'start' => $blockStart,
        'end' => $blockEnd,
    ];
}

// === 各スロットの空き状況を計算 ===
$isToday = ($date === $today);
$nowMinutes = $isToday ? $nowMinutesFixed : 0;

// 深夜営業判定: closingTime < openingTime
$openMin = timeToMinutes($openingTime);
$closeMin = ($closingTime === '00:00') ? 1440 : timeToMinutes($closingTime);
$isOvernight = ($closeMin <= $openMin) && !$is24h;

$slots = [];
foreach ($slotTimes as $slotTime) {
    $slotMin = timeToMinutes($slotTime);

    // 当日の過去時間チェック
    // next_dayフラグがtrueのスロットは翌日扱いなのでpast判定スキップ
    if ($isToday && !$nextDayFlags[$slotTime]) {
        if (!$isOvernight && $slotMin < $nowMinutes) {
            $slots[] = [
                'time' => $slotTime,
                'status' => 'past',
                'next_day' => false,
            ];
            continue;
        }
        // 深夜営業の当日: 開始時間以降〜23:59のうち過去のもの
        if ($isOvernight && $slotMin >= $openMin && $slotMin < $nowMinutes) {
            $slots[] = [
                'time' => $slotTime,
                'status' => 'past',
                'next_day' => false,
            ];
            continue;
        }
    }

    // スロットのDateTimeを構築
    $slotStart = new DateTime($date . ' ' . $slotTime);
    // next_dayフラグがtrueのスロットは翌日（24h・深夜営業の両方で動作）
    if ($nextDayFlags[$slotTime]) {
        $slotStart->modify('+1 day');
    }
    $slotEnd = clone $slotStart;
    $slotEnd->modify('+30 minutes');

    // このスロットと衝突する予約があるか判定
    $hasConflict = false;
    foreach ($blockIntervals as $block) {
        // 区間の重なり判定: NOT (block_end <= slot_start OR block_start >= slot_end)
        if (!($block['end'] <= $slotStart || $block['start'] >= $slotEnd)) {
            $hasConflict = true;
            break;
        }
    }

    $slots[] = [
        'time' => $slotTime,
        'status' => $hasConflict ? 'full' : 'available',
        'next_day' => $nextDayFlags[$slotTime],
    ];
}

// Cache-Control: 空き状況は頻繁に変動するためキャッシュしない
// (ブラウザ/プロキシ/CDN のキャッシュによって「常に○」のような表示差が出るのを避ける)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

jsonResponse([
    'slots' => $slots,
]);

// === ヘルパー関数 ===

/**
 * 営業時間内の30分スロットを生成
 */
function generateBusinessSlots(string $openingTime, string $closingTime, bool $is24h): array
{
    if ($is24h) {
        $openMin = timeToMinutes($openingTime);
        // opening_time=00:00 の場合、midnight wrapが発生しないため06:00にフォールバック
        if ($openMin === 0) {
            $openMin = 360;
        }
        $slots = [];
        for ($i = 0; $i < 48; $i++) {
            $m = ($openMin + $i * 30) % 1440;
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        return $slots;
    }

    $openMin = timeToMinutes($openingTime);
    $closeMin = ($closingTime === '00:00') ? 1440 : timeToMinutes($closingTime);

    $slots = [];
    if ($closeMin > $openMin) {
        // 通常営業 (例: 10:00-22:00)
        for ($m = $openMin; $m < $closeMin; $m += 30) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
    } else {
        // 深夜営業 (例: 22:00-05:00)
        for ($m = $openMin; $m < 1440; $m += 30) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        for ($m = 0; $m < $closeMin; $m += 30) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
    }
    return $slots;
}
