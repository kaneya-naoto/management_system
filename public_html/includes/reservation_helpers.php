<?php
/**
 * 予約関連ヘルパー関数
 *
 * 予約経路ラベル、決済状態、鍵割当、重複チェック、
 * 延長計算、清掃時間取得などの予約ドメイン関数群
 */

/**
 * 予約経路のラベル
 */
function reservationSourceLabel(string $source): string
{
    return match($source) {
        'web' => 'Web予約',
        'line' => 'LINE',
        'phone' => '電話',
        'direct' => '直接来店',
        default => '-'
    };
}

/**
 * 決済状態の情報
 * @return array{class: string, label: string}
 */
function paymentStatusInfo(string $status): array
{
    return match($status) {
        'paid' => ['class' => 'success', 'label' => '支払済'],
        'refunded' => ['class' => 'secondary', 'label' => '返金済'],
        default => ['class' => 'warning', 'label' => '未払い']
    };
}

/**
 * 予約キャンセル時の副作用（鍵解除＋清掃案件キャンセル）
 * @param int $reservationId 予約ID
 */
function cancelReservationSideEffects(int $reservationId): void
{
    // 鍵割当を解除（返却扱い）
    dbUpdate(
        'key_assignments',
        ['returned_at' => date('Y-m-d H:i:s')],
        'reservation_id = ? AND returned_at IS NULL',
        [$reservationId]
    );

    // 清掃案件をキャンセル（完了・支払済み以外）
    dbUpdate(
        'cleaning_jobs',
        ['deleted_at' => date('Y-m-d H:i:s')],
        'reservation_id = ? AND status NOT IN (?, ?) AND deleted_at IS NULL',
        [$reservationId, 'completed', 'paid']
    );
}

/**
 * 鍵を予約に割り当てる
 * @param int $reservationId 予約ID
 * @param int $salesAreaId 営業区分ID
 * @param string $date 予約日 (Y-m-d)
 * @param string $startTime 開始時間 (H:i:s)
 * @param string $endTime 終了時間 (H:i:s)
 * @return bool 成功したらtrue
 * @throws RuntimeException 空き鍵がない場合
 */
function assignKeyToReservation(int $reservationId, int $salesAreaId, string $date, string $startTime, string $endTime): bool
{
    $cleaningMinutes = getCleaningDuration($salesAreaId);

    // リクエスト時間をDATETIME化
    $requestStartDt = $date . ' ' . $startTime;
    $requestEndDt = $date . ' ' . $endTime;

    // 深夜跨ぎ: 終了 < 開始なら翌日
    if ($endTime < $startTime) {
        $requestEndDt = date('Y-m-d', strtotime($date . ' +1 day')) . ' ' . $endTime;
    }

    $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

    // 同一時間帯で使用されていない鍵を取得（清掃バッファ考慮）
    // 前日・当日・翌日を検索対象にすることで深夜跨ぎ予約との重複を正しく検知
    $availableKey = dbSelectOne(
        "SELECT k.* FROM `keys` k
         WHERE k.sales_area_id = ?
           AND k.is_active = 1
           AND k.deleted_at IS NULL
           AND k.id NOT IN (
               SELECT ka.key_id FROM key_assignments ka
               INNER JOIN reservations r ON ka.reservation_id = r.id
               LEFT JOIN cleaning_jobs cj ON cj.reservation_id = r.id
               WHERE r.sales_area_id = ?
                 AND r.reservation_date IN (?, ?, ?)
                 AND r.status NOT IN ('cancelled')
                 AND r.deleted_at IS NULL
                 AND r.id != ?
                 AND NOT (
                     COALESCE(
                         cj.completed_at,
                         DATE_ADD(
                             CASE
                                 WHEN r.end_time < r.start_time
                                 THEN CONCAT(DATE_ADD(r.reservation_date, INTERVAL 1 DAY), ' ', r.end_time)
                                 ELSE CONCAT(r.reservation_date, ' ', r.end_time)
                             END,
                             INTERVAL ? MINUTE
                         )
                     ) <= ?
                     OR
                     CONCAT(r.reservation_date, ' ', r.start_time) >= ?
                 )
           )
         ORDER BY k.id
         LIMIT 1
         FOR UPDATE",
        [$salesAreaId, $salesAreaId, $date, $prevDate, $nextDate, $reservationId, $cleaningMinutes, $requestStartDt, $requestEndDt]
    );

    if (!$availableKey) {
        throw new RuntimeException('空き鍵がありません');
    }

    // 割当記録
    dbInsert('key_assignments', [
        'reservation_id' => $reservationId,
        'key_id' => $availableKey['id'],
        'assigned_at' => date('Y-m-d H:i:s'),
    ]);

    return true;
}

/**
 * 当日予約かどうかを判定
 * @param string $reservationDate 予約日 (Y-m-d)
 * @return bool 当日予約の場合true
 */
function isSameDayReservation(string $reservationDate): bool
{
    // タイムゾーンを明示的に設定
    $timezone = new DateTimeZone('Asia/Tokyo');
    $today = (new DateTime('now', $timezone))->format('Y-m-d');
    return $reservationDate === $today;
}

/**
 * 清掃時間を取得（sales_areas優先、なければデフォルト）
 * @param int $salesAreaId 営業区分ID
 * @return int 清掃時間（分）
 */
function getCleaningDuration(int $salesAreaId): int
{
    static $cache = [];

    if (isset($cache[$salesAreaId])) {
        return $cache[$salesAreaId];
    }

    $salesArea = dbSelectOne(
        "SELECT cleaning_duration_minutes FROM sales_areas WHERE id = ? AND deleted_at IS NULL",
        [$salesAreaId]
    );

    $duration = $salesArea['cleaning_duration_minutes']
        ?? (defined('CLEANING_TIME_MINUTES') ? CLEANING_TIME_MINUTES : 60);

    $cache[$salesAreaId] = $duration;
    return $duration;
}

/**
 * 予約の時間重複をチェック（清掃時間考慮、深夜跨ぎ対応）
 * @param int $salesAreaId 営業区分ID
 * @param string $reservationDate 予約日 (Y-m-d)
 * @param string $startTime 開始時刻 (H:i or H:i:s)
 * @param string $endTime 終了時刻 (H:i or H:i:s)
 * @param int|null $excludeReservationId 除外する予約ID
 * @return int 重複している予約で使用中の鍵数
 */
function checkReservationConflict(
    int $salesAreaId,
    string $reservationDate,
    string $startTime,
    string $endTime,
    ?int $excludeReservationId = null
): int {
    $cleaningMinutes = getCleaningDuration($salesAreaId);

    // 前日・翌日も含めて検索（深夜跨ぎ対応）
    $prevDate = date('Y-m-d', strtotime($reservationDate . ' -1 day'));
    $nextDate = date('Y-m-d', strtotime($reservationDate . ' +1 day'));

    // リクエスト時間をDATETIME化
    $requestStartDt = $reservationDate . ' ' . $startTime;
    $requestEndDt = $reservationDate . ' ' . $endTime;

    // 深夜跨ぎ: 終了 < 開始なら翌日
    if ($endTime < $startTime) {
        $requestEndDt = date('Y-m-d', strtotime($reservationDate . ' +1 day')) . ' ' . $endTime;
    }

    $excludeCondition = '';
    // バインド順: sales_area_id, dates(3), [exclude_id], cleaningMinutes, startDt, endDt
    $params = [$salesAreaId, $reservationDate, $prevDate, $nextDate];

    if ($excludeReservationId !== null) {
        $excludeCondition = 'AND r.id != ?';
        $params[] = $excludeReservationId;
    }

    $params[] = $cleaningMinutes;
    $params[] = $requestStartDt;
    $params[] = $requestEndDt;

    $result = dbSelectOne(
        "SELECT COUNT(DISTINCT ka.key_id) as cnt
         FROM key_assignments ka
         JOIN reservations r ON ka.reservation_id = r.id
         LEFT JOIN cleaning_jobs cj ON cj.reservation_id = r.id
         WHERE r.sales_area_id = ?
           AND r.reservation_date IN (?, ?, ?)
           AND r.status NOT IN ('cancelled')
           AND r.deleted_at IS NULL
           {$excludeCondition}
           AND NOT (
               -- block_end: 清掃完了時刻 or 予約終了+清掃時間
               COALESCE(
                   cj.completed_at,
                   DATE_ADD(
                       CASE
                           WHEN r.end_time < r.start_time
                           THEN CONCAT(DATE_ADD(r.reservation_date, INTERVAL 1 DAY), ' ', r.end_time)
                           ELSE CONCAT(r.reservation_date, ' ', r.end_time)
                       END,
                       INTERVAL ? MINUTE
                   )
               ) <= ?
               OR
               -- block_start: 予約開始時刻
               CONCAT(r.reservation_date, ' ', r.start_time) >= ?
           )",
        $params
    );

    return (int)($result['cnt'] ?? 0);
}

/**
 * 営業区分の空き鍵数を取得
 * @param int $salesAreaId 営業区分ID
 * @param string $reservationDate 予約日 (Y-m-d)
 * @param string $startTime 開始時刻
 * @param string $endTime 終了時刻
 * @param int|null $excludeReservationId 除外する予約ID
 * @return int 空き鍵数
 */
function getAvailableKeyCount(
    int $salesAreaId,
    string $reservationDate,
    string $startTime,
    string $endTime,
    ?int $excludeReservationId = null
): int {
    // 総鍵数を取得
    $totalKeys = dbSelectOne(
        "SELECT COUNT(*) as cnt FROM `keys`
         WHERE sales_area_id = ?
           AND is_active = 1
           AND deleted_at IS NULL",
        [$salesAreaId]
    );

    $total = (int)($totalKeys['cnt'] ?? 0);

    // 使用中の鍵数を取得
    $usedCount = checkReservationConflict(
        $salesAreaId,
        $reservationDate,
        $startTime,
        $endTime,
        $excludeReservationId
    );

    return max(0, $total - $usedCount);
}

/**
 * 延長可能時間を計算
 *
 * @param int $salesAreaId 営業区分ID
 * @param int $storeId 店舗ID
 * @param string $startTime 予約開始時刻 (HH:MM:SS) - 深夜跨ぎ判定に使用
 * @param string $endTime 予約終了時刻 (HH:MM:SS)
 * @param string $reservationDate 予約日 (YYYY-MM-DD)
 * @return array ['available_minutes' => int, 'reason' => string|null, 'max_end_time' => string|null, 'is_next_day' => bool]
 */
function calculateAvailableExtension(int $salesAreaId, int $storeId, string $startTime, string $endTime, string $reservationDate): array
{
    $cleaningMinutes = getCleaningDuration($salesAreaId);
    $timezone = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Tokyo');

    // 予約日のDateTime基準
    $baseDate = DateTime::createFromFormat('Y-m-d', $reservationDate, $timezone);
    if (!$baseDate) {
        error_log("calculateAvailableExtension: Invalid date format: {$reservationDate}");
        return [
            'available_minutes' => 0,
            'reason' => 'invalid_datetime',
            'max_end_time' => null,
            'is_next_day' => false,
        ];
    }
    $baseDate->setTime(0, 0, 0);

    // 開始時刻をパース
    $startParts = explode(':', $startTime);
    $startDateTime = clone $baseDate;
    $startDateTime->setTime((int)$startParts[0], (int)($startParts[1] ?? 0), (int)($startParts[2] ?? 0));

    // 終了時刻をパース
    $endParts = explode(':', $endTime);
    $endDateTime = clone $baseDate;
    $endDateTime->setTime((int)$endParts[0], (int)($endParts[1] ?? 0), (int)($endParts[2] ?? 0));

    // 深夜跨ぎ判定: 終了時刻 < 開始時刻 なら終了は翌日
    $isOvernightReservation = ($endTime < $startTime);
    if ($isOvernightReservation) {
        $endDateTime->modify('+1 day');
    }

    // 次の予約を取得（同日 + 翌日も検索）
    $nextDay = (clone $baseDate)->modify('+1 day')->format('Y-m-d');

    // 同日の次の予約（終了時刻より後に開始する予約）
    $nextReservation = null;
    $nextReservationDate = null;

    // 深夜跨ぎでない場合: 同日で終了後の予約を探す
    if (!$isOvernightReservation) {
        $nextReservation = dbSelectOne(
            "SELECT id, start_time, reservation_date
             FROM reservations
             WHERE sales_area_id = ?
               AND reservation_date = ?
               AND start_time > ?
               AND status IN ('confirmed', 'pending')
               AND deleted_at IS NULL
             ORDER BY start_time ASC
             LIMIT 1",
            [$salesAreaId, $reservationDate, $endTime]
        );
        if ($nextReservation) {
            $nextReservationDate = $reservationDate;
        }
    }

    // 同日に見つからない、または深夜跨ぎの場合は翌日も検索
    if (!$nextReservation) {
        $nextReservation = dbSelectOne(
            "SELECT id, start_time, reservation_date
             FROM reservations
             WHERE sales_area_id = ?
               AND reservation_date = ?
               AND status IN ('confirmed', 'pending')
               AND deleted_at IS NULL
             ORDER BY start_time ASC
             LIMIT 1",
            [$salesAreaId, $nextDay]
        );
        if ($nextReservation) {
            $nextReservationDate = $nextDay;
        }
    }

    // 店舗の営業時間を取得
    $store = dbSelectOne(
        "SELECT is_24h_open, opening_time, closing_time FROM stores WHERE id = ?",
        [$storeId]
    );

    $maxEndTime = null;
    $reason = null;

    if ($nextReservation) {
        // 次の予約がある場合: 次の予約開始 - 清掃時間
        $nextStartParts = explode(':', $nextReservation['start_time']);
        $nextStartDateTime = DateTime::createFromFormat('Y-m-d', $nextReservationDate, $timezone);
        $nextStartDateTime->setTime((int)$nextStartParts[0], (int)($nextStartParts[1] ?? 0), (int)($nextStartParts[2] ?? 0));

        // 次の予約が現在の終了より前なら（日跨ぎ検索で翌日の早朝予約が見つかった場合など）
        // その場合は次の予約を有効とする
        if ($nextStartDateTime > $endDateTime) {
            $maxEndTime = clone $nextStartDateTime;
            $maxEndTime->modify("-{$cleaningMinutes} minutes");
            $reason = 'next_reservation';
        }
    }

    // 閉店時間チェック（24時間営業でない場合）
    if ($store && !$store['is_24h_open'] && $store['closing_time']) {
        $closingTime = $store['closing_time'];
        $openingTime = $store['opening_time'] ?? '00:00:00';

        $closingParts = explode(':', $closingTime);
        $closingDateTime = clone $baseDate;
        $closingDateTime->setTime((int)$closingParts[0], (int)($closingParts[1] ?? 0), (int)($closingParts[2] ?? 0));

        // 深夜営業判定: 閉店時刻 < 開店時刻 なら閉店は翌日
        if ($closingTime < $openingTime) {
            $closingDateTime->modify('+1 day');
        }

        // 閉店時刻が終了時刻より前なら（既に閉店時刻を過ぎている深夜営業のケース）
        // 翌日の閉店時刻を使う
        if ($closingDateTime <= $endDateTime) {
            $closingDateTime->modify('+1 day');
        }

        // 閉店時刻と次の予約、どちらか早い方を採用
        if ($maxEndTime === null || $closingDateTime < $maxEndTime) {
            $maxEndTime = $closingDateTime;
            $reason = 'closing_time';
        }
    }

    // 延長可能時間を計算
    if ($maxEndTime === null) {
        // 制限なし（24時間営業で次の予約もない）- MAX_EXTENSION_HOURSで制限
        $maxMinutes = (int) (MAX_EXTENSION_HOURS * 60);
        return [
            'available_minutes' => $maxMinutes,
            'reason' => null,
            'max_end_time' => null,
            'is_next_day' => false,
        ];
    }

    // 時間差を計算
    $diff = $endDateTime->diff($maxEndTime);

    // invertが1の場合はmaxEndTimeがendDateTimeより前（延長不可）
    if ($diff->invert === 1) {
        return [
            'available_minutes' => 0,
            'reason' => $reason,
            'max_end_time' => $maxEndTime->format('H:i'),
            'is_next_day' => ($maxEndTime->format('Y-m-d') !== $reservationDate),
        ];
    }

    // 分数を計算
    $availableMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

    // MAX_EXTENSION_HOURSで制限
    $maxMinutes = (int) (MAX_EXTENSION_HOURS * 60);
    $availableMinutes = min($availableMinutes, $maxMinutes);

    return [
        'available_minutes' => max(0, $availableMinutes),
        'reason' => $reason,
        'max_end_time' => $maxEndTime->format('H:i'),
        'is_next_day' => ($maxEndTime->format('Y-m-d') !== $reservationDate),
    ];
}

/**
 * 延長可能な選択肢を取得
 *
 * @param int $availableMinutes 延長可能時間（分）
 * @return array 選択可能な延長時間の配列 [['value' => 30, 'label' => '30分'], ...]
 */
function getExtensionOptions(int $availableMinutes): array
{
    $allOptions = [
        ['value' => 30, 'label' => '30分'],
        ['value' => 60, 'label' => '1時間'],
        ['value' => 90, 'label' => '1時間30分'],
        ['value' => 120, 'label' => '2時間'],
    ];

    $options = [];
    foreach ($allOptions as $option) {
        if ($option['value'] <= $availableMinutes) {
            $options[] = $option;
        }
    }

    return $options;
}
