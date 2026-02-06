<?php
/**
 * 延長申請フォーム（顧客向け）
 *
 * アクセス方式:
 * 1. 部屋コード方式: /extend/room/{room_code} - QRコードを部屋に貼って固定URL
 * 2. トークン方式: /extend/?token={token} - 予約ごとのトークン（後方互換）
 */
$pageTitle = '延長申請';
$isPublicPage = true;

$success = false;
$errors = [];
$reservation = null;
$salesArea = null;
$extensionInfo = null; // 延長可能情報
$extensionOptions = []; // 選択可能な延長時間

// アクセス方式の判定
// $roomCode はルーティング（index.php）で設定される
$roomCode = $roomCode ?? null;
$token = input('token', '');

// 入力値検証（ブルートフォース対策）
if ($roomCode !== null) {
    // room_code: 8-32文字の英数字のみ許可
    if (!preg_match('/^[a-zA-Z0-9]{8,32}$/', $roomCode)) {
        $errors[] = '無効なアクセスです';
        $roomCode = null;
    }
}
if (!empty($token)) {
    // token: 32-128文字の英数字のみ許可
    if (!preg_match('/^[a-zA-Z0-9]{32,128}$/', $token)) {
        $errors[] = '無効なアクセスです';
        $token = '';
    }
}

// PHP側で現在日時を取得（MySQL CURDATE/CURTIMEとの不一致を防止）
$timezone = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Tokyo');
$now = new DateTime('now', $timezone);
$today = $now->format('Y-m-d');
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$currentTime = $now->format('H:i:s');

if ($roomCode && empty($errors)) {
    // 部屋コード方式: room_code から営業区分を取得し、現在利用中の予約を特定
    $salesArea = dbSelectOne(
        "SELECT sa.*, s.name as store_name
         FROM sales_areas sa
         INNER JOIN stores s ON sa.store_id = s.id
         WHERE sa.room_code = ?
           AND sa.is_active = 1
           AND sa.deleted_at IS NULL
           AND s.is_active = 1
           AND s.deleted_at IS NULL",
        [$roomCode]
    );

    if (!$salesArea) {
        $errors[] = '無効なアクセスです';
    } else {
        // その部屋の「現在利用中の予約」を取得
        // 深夜跨ぎ対応: 3パターンで判定
        $reservation = dbSelectOne(
            "SELECT r.*, s.name as store_name, sa.name as area_name,
                    cj.id as job_id, cj.assigned_cleaner_id, c.name as cleaner_name, c.line_user_id
             FROM reservations r
             INNER JOIN stores s ON r.store_id = s.id
             INNER JOIN sales_areas sa ON r.sales_area_id = sa.id
             LEFT JOIN cleaning_jobs cj ON r.id = cj.reservation_id AND cj.deleted_at IS NULL
             LEFT JOIN cleaners c ON cj.assigned_cleaner_id = c.id
             WHERE sa.room_code = ?
               AND r.status = 'confirmed'
               AND r.deleted_at IS NULL
               AND (
                   -- パターン1: 通常（日跨ぎなし）- 今日の予約で開始後〜終了前
                   (r.reservation_date = ? AND r.end_time >= r.start_time AND r.start_time <= ? AND r.end_time > ?)
                   OR
                   -- パターン2: 深夜跨ぎ・当日側 - 今日開始で終了が翌日、現在は開始後（23:00-01:00で23:30）
                   (r.reservation_date = ? AND r.end_time < r.start_time AND r.start_time <= ?)
                   OR
                   -- パターン3: 深夜跨ぎ・翌日側 - 昨日開始で終了が今日、現在は終了前（23:00-01:00で00:30）
                   (r.reservation_date = ? AND r.end_time < r.start_time AND ? < r.end_time)
               )
             ORDER BY r.reservation_date DESC, r.start_time DESC
             LIMIT 1",
            [$roomCode, $today, $currentTime, $currentTime, $today, $currentTime, $yesterday, $currentTime]
        );

        if (!$reservation) {
            $errors[] = '現在利用中の予約がありません';
        } else {
            // 既に延長申請中かチェック
            $pendingRequest = dbSelectOne(
                "SELECT id FROM extension_requests
                 WHERE reservation_id = ? AND status = 'pending'",
                [$reservation['id']]
            );
            if ($pendingRequest) {
                $errors[] = '既に延長申請中です。担当者からの回答をお待ちください。';
                $reservation = null; // フォーム表示を抑制
            }
        }
    }
} elseif (!empty($token) && empty($errors)) {
    // トークン方式（後方互換）: トークンから予約を直接取得
    // 深夜跨ぎ対応: 3パターンで判定
    $reservation = dbSelectOne(
        "SELECT r.*, s.name as store_name, sa.name as area_name,
                cj.id as job_id, cj.assigned_cleaner_id, c.name as cleaner_name, c.line_user_id
         FROM reservations r
         LEFT JOIN stores s ON r.store_id = s.id
         LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
         LEFT JOIN cleaning_jobs cj ON r.id = cj.reservation_id AND cj.deleted_at IS NULL
         LEFT JOIN cleaners c ON cj.assigned_cleaner_id = c.id
         WHERE r.extension_token = ?
           AND r.status = 'confirmed'
           AND r.deleted_at IS NULL
           AND (
               -- パターン1: 通常（日跨ぎなし）- 今日の予約で開始後〜終了前
               (r.reservation_date = ? AND r.end_time >= r.start_time AND r.start_time <= ? AND r.end_time > ?)
               OR
               -- パターン2: 深夜跨ぎ・当日側 - 今日開始で終了が翌日、現在は開始後（23:00-01:00で23:30）
               (r.reservation_date = ? AND r.end_time < r.start_time AND r.start_time <= ?)
               OR
               -- パターン3: 深夜跨ぎ・翌日側 - 昨日開始で終了が今日、現在は終了前（23:00-01:00で00:30）
               (r.reservation_date = ? AND r.end_time < r.start_time AND ? < r.end_time)
           )",
        [$token, $today, $currentTime, $currentTime, $today, $currentTime, $yesterday, $currentTime]
    );

    if (!$reservation) {
        $errors[] = '現在利用中の予約がありません';
    } else {
        // 既に延長申請中かチェック（重複申請防止）
        $pendingRequest = dbSelectOne(
            "SELECT id FROM extension_requests
             WHERE reservation_id = ? AND status = 'pending'",
            [$reservation['id']]
        );
        if ($pendingRequest) {
            $errors[] = '既に延長申請中です。担当者からの回答をお待ちください。';
            $reservation = null;
        }
    }
} elseif (empty($errors)) {
    $errors[] = '無効なアクセスです';
}

// 延長可能時間を計算
if ($reservation && empty($errors)) {
    $extensionInfo = calculateAvailableExtension(
        (int) $reservation['sales_area_id'],
        (int) $reservation['store_id'],
        $reservation['start_time'],
        $reservation['end_time'],
        $reservation['reservation_date']
    );

    $extensionOptions = getExtensionOptions($extensionInfo['available_minutes']);

    // 延長不可の場合
    if (empty($extensionOptions)) {
        if ($extensionInfo['reason'] === 'next_reservation') {
            $errors[] = '次のご予約があるため、これ以上延長できません。';
        } elseif ($extensionInfo['reason'] === 'closing_time') {
            $errors[] = '閉店時間のため、これ以上延長できません。';
        } elseif ($extensionInfo['available_minutes'] > 0 && $extensionInfo['available_minutes'] < 30) {
            // 30分未満だが延長可能時間はある場合
            $errors[] = '最大' . $extensionInfo['available_minutes'] . '分まで延長可能ですが、最小延長時間（30分）に満たないため申請できません。';
        } else {
            $errors[] = '延長可能な時間がありません。';
        }
        $reservation = null; // フォーム表示を抑制
    }
}

// 延長申請処理
if (isPost() && $reservation) {
    requireCsrf();

    $extensionMinutes = (int) input('extension_minutes', 0);

    // 選択された延長時間が有効かチェック
    $validOption = false;
    foreach ($extensionOptions as $option) {
        if ($option['value'] === $extensionMinutes) {
            $validOption = true;
            break;
        }
    }

    if (!$validOption) {
        $errors[] = '選択された延長時間は無効です。';
    }

    if (empty($errors)) {
        dbBegin();
        try {
            // レースコンディション対策: 申請作成時に延長可否を再検証
            // FOR UPDATEで予約をロックして再計算
            $lockedReservation = dbSelectOne(
                "SELECT id, sales_area_id, store_id, start_time, end_time, reservation_date
                 FROM reservations
                 WHERE id = ?
                 FOR UPDATE",
                [$reservation['id']]
            );

            if (!$lockedReservation) {
                throw new Exception('予約が見つかりません');
            }

            // 延長可能時間を再計算
            $revalidatedInfo = calculateAvailableExtension(
                (int) $lockedReservation['sales_area_id'],
                (int) $lockedReservation['store_id'],
                $lockedReservation['start_time'],
                $lockedReservation['end_time'],
                $lockedReservation['reservation_date']
            );

            if ($revalidatedInfo['available_minutes'] < $extensionMinutes) {
                throw new Exception('申請中に状況が変わりました。延長可能時間が不足しています。');
            }

            // 重複申請チェック（トランザクション内で再確認、FOR UPDATEでロック）
            $existingPending = dbSelectOne(
                "SELECT id FROM extension_requests
                 WHERE reservation_id = ? AND status = 'pending'
                 FOR UPDATE",
                [$reservation['id']]
            );
            if ($existingPending) {
                throw new Exception('既に延長申請中です。担当者からの回答をお待ちください。');
            }

            // 延長申請を記録
            $requestId = dbInsert('extension_requests', [
                'reservation_id' => $reservation['id'],
                'job_id' => $reservation['job_id'],
                'requested_minutes' => $extensionMinutes,
                'status' => 'pending',
                'requested_at' => date('Y-m-d H:i:s'),
            ]);

            // 担当清掃者にLINE通知
            if ($reservation['line_user_id']) {
                // トークンを先に生成（32バイト = 64文字のhex、セキュリティ強化）
                $responseToken = bin2hex(random_bytes(32));
                $respondUrl = APP_URL . '/extend/respond?id=' . $requestId . '&token=' . $responseToken;

                // トークンを保存
                dbUpdate('extension_requests', [
                    'response_token' => $responseToken,
                ], 'id = ?', [$requestId]);

                // 延長後の終了時間を計算（深夜跨ぎ対応）
                $originalEndTime = substr($reservation['end_time'], 0, 5);
                $endDateTime = new DateTime($reservation['reservation_date'] . ' ' . $reservation['end_time']);
                $endDateTime->modify("+{$extensionMinutes} minutes");
                $newEndTime = $endDateTime->format('H:i');

                // 日付が変わる場合は翌日表示
                $originalDate = $reservation['reservation_date'];
                $newDate = $endDateTime->format('Y-m-d');
                $nextDayNote = ($originalDate !== $newDate) ? '（翌日）' : '';

                $message = "【延長リクエスト】\n\n"
                    . "🏠 {$reservation['store_name']}\n"
                    . "📍 {$reservation['area_name']}\n\n"
                    . "⏰ 現在の終了: {$originalEndTime}\n"
                    . "➡️ 延長後: {$newEndTime}{$nextDayNote}\n"
                    . "⏱️ 延長時間: {$extensionMinutes}分\n\n"
                    . "対応可能ですか？\n\n"
                    . "▼ 回答はこちら\n{$respondUrl}";

                sendLineTextPush($reservation['line_user_id'], $message);
            }

            dbCommit();
            $success = true;

        } catch (Exception $e) {
            dbRollback();
            error_log("Extension request failed: " . $e->getMessage());
            $errors[] = $e->getMessage() ?: '延長申請に失敗しました。しばらくしてからお試しください。';
        }
    }
}

$basePath = defined('BASE_PATH') ? BASE_PATH : '';

// Referrer-Policy を設定（トークン/ルームコードの外部漏洩防止）
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $basePath ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="guest-wrapper">
    <div class="login-container">
        <div class="login-logo">
            <i class="bi bi-clock-history"></i>
            <span>延長申請</span>
        </div>

        <div class="login-card">
            <?php if ($success): ?>
            <div class="alert alert-success mb-4">
                <i class="bi bi-check-circle me-2"></i>
                延長申請を送信しました。<br>
                清掃担当者から回答があるまでお待ちください。
            </div>
            <p class="text-muted text-center small">
                担当者が対応可能な場合、延長が確定します。<br>
                しばらくお待ちください。
            </p>

            <?php elseif (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <?php foreach ($errors as $error): ?>
                <p class="mb-0"><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
            <?php if ($salesArea && !$reservation): ?>
            <p class="text-muted text-center small">
                <?= h($salesArea['name']) ?>（<?= h($salesArea['store_name']) ?>）
            </p>
            <?php endif; ?>

            <?php elseif ($reservation): ?>
            <div class="mb-4">
                <h6 class="text-muted mb-2">現在のご予約</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width: 30%">場所</td>
                        <td><?= h($reservation['area_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">終了時間</td>
                        <td><?= h(substr($reservation['end_time'], 0, 5)) ?><?php
                            // 深夜跨ぎの場合は翌日表示
                            if ($reservation['end_time'] < $reservation['start_time']) {
                                echo ' <span class="text-muted small">(翌日)</span>';
                            }
                        ?></td>
                    </tr>
                    <?php if ($extensionInfo): ?>
                    <tr>
                        <td class="text-muted">延長可能</td>
                        <td>
                            <?php if ($extensionInfo['max_end_time']): ?>
                                <?= h($extensionInfo['max_end_time']) ?><?php
                                    if (!empty($extensionInfo['is_next_day'])) {
                                        echo ' <span class="text-muted small">(翌日)</span>';
                                    }
                                ?>まで
                                <span class="text-muted small">(最大<?= h($extensionInfo['available_minutes']) ?>分)</span>
                            <?php else: ?>
                                最大<?= h($extensionInfo['available_minutes']) ?>分
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">

                <div class="mb-4">
                    <label class="form-label">延長時間を選択</label>
                    <div class="d-grid gap-2">
                        <?php foreach ($extensionOptions as $i => $option): ?>
                        <input type="radio" class="btn-check" name="extension_minutes" id="ext<?= $option['value'] ?>" value="<?= $option['value'] ?>" <?= $i === 0 ? 'required' : '' ?>>
                        <label class="btn btn-outline-primary" for="ext<?= $option['value'] ?>"><?= h($option['label']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-send"></i> 延長を申請する
                </button>
            </form>

            <p class="text-muted small mt-3 mb-0">
                ※ 担当者の都合により、ご希望に添えない場合があります。
            </p>
            <?php endif; ?>
        </div>

        <p class="login-footer">
            &copy; <?= date('Y') ?> <?= h(APP_NAME) ?>
        </p>
    </div>
</div>

<style>
.login-container {
    width: 100%;
    max-width: 380px;
    padding: var(--space-4);
}

.login-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text);
    margin-bottom: var(--space-6);
}

.login-logo i {
    font-size: 1.75rem;
    color: var(--color-primary);
}

.login-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-6);
}

.btn-check:checked + .btn-outline-primary {
    background-color: var(--color-primary);
    color: white;
}

.login-footer {
    text-align: center;
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    margin-top: var(--space-6);
}
</style>
</body>
</html>
