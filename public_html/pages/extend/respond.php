<?php
/**
 * 延長リクエスト回答画面（清掃者向け）
 */
$pageTitle = '延長リクエスト回答';
$isPublicPage = true;

// パラメータ検証
$requestId = (int) input('id', 0);
$token = input('token', '');
$errors = [];
$success = false;
$request = null;

if ($requestId <= 0 || empty($token)) {
    $errors[] = '無効なアクセスです';
} else {
    // リクエストを取得
    $request = dbSelectOne(
        "SELECT er.*, r.customer_name, r.reservation_date, r.start_time, r.end_time,
                r.sales_area_id, r.store_id,
                sa.name as area_name, c.name as cleaner_name
         FROM extension_requests er
         INNER JOIN reservations r ON er.reservation_id = r.id
         INNER JOIN cleaning_jobs cj ON er.job_id = cj.id
         INNER JOIN cleaners c ON cj.assigned_cleaner_id = c.id
         LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
         WHERE er.id = ?
           AND er.response_token = ?
           AND er.status = 'pending'",
        [$requestId, $token]
    );

    if (!$request) {
        $errors[] = 'このリクエストは既に回答済みか、無効です';
    }
}

// 回答処理
if (isPost() && $request) {
    requireCsrf();

    $response = input('response', '');

    if (!in_array($response, ['accept', 'decline'], true)) {
        $errors[] = '回答を選択してください';
    }

    if (empty($errors)) {
        dbBegin();
        try {
            $status = $response === 'accept' ? 'accepted' : 'declined';

            // リクエストのステータスを更新（status='pending'ガードで二重承諾防止）
            $updatedRows = dbUpdate('extension_requests', [
                'status' => $status,
                'responded_at' => date('Y-m-d H:i:s'),
            ], 'id = ? AND status = ?', [$requestId, 'pending']);

            if ($updatedRows === 0) {
                throw new Exception('このリクエストは既に回答済みです');
            }

            // 承諾の場合、予約と案件を更新
            if ($response === 'accept') {
                $extensionMinutes = $request['requested_minutes'];

                // 予約データをFOR UPDATEでロックして最新値を取得（競合対策・TOCTOU防止）
                $currentReservation = dbSelectOne(
                    "SELECT * FROM reservations WHERE id = ? FOR UPDATE",
                    [$request['reservation_id']]
                );

                // ロック取得後に延長可能時間を再検証（TOCTOU対策）
                $extensionInfo = calculateAvailableExtension(
                    (int) $currentReservation['sales_area_id'],
                    (int) $currentReservation['store_id'],
                    $currentReservation['start_time'],
                    $currentReservation['end_time'],
                    $currentReservation['reservation_date']
                );
                if ($extensionInfo['available_minutes'] < $extensionMinutes) {
                    throw new Exception('申請後に状況が変わりました。延長可能時間が不足しています。');
                }

                // 日跨ぎ対応: end_time < start_time の場合は翌日として計算
                $currentEndTimestamp = strtotime($currentReservation['reservation_date'] . ' ' . $currentReservation['end_time']);
                if ($currentReservation['end_time'] < $currentReservation['start_time']) {
                    $currentEndTimestamp = strtotime('+1 day', $currentEndTimestamp);
                }
                $newEndTimestamp = strtotime("+{$extensionMinutes} minutes", $currentEndTimestamp);
                $newEndTime = date('H:i:s', $newEndTimestamp);

                $currentExtensionPrice = $currentReservation['extension_price'] ?? 0;
                $additionalPrice = calculateExtensionPrice($extensionMinutes, (int) $currentReservation['store_id']);
                $newExtensionPrice = $currentExtensionPrice + $additionalPrice;
                $newTotalPrice = ($currentReservation['base_price'] ?? 0) + $newExtensionPrice;

                // 予約の終了時間を延長
                dbUpdate('reservations', [
                    'end_time' => $newEndTime,
                    'extension_price' => $newExtensionPrice,
                    'total_price' => $newTotalPrice,
                ], 'id = ?', [$request['reservation_id']]);

                // 清掃案件の時刻を更新（報酬は変更しない）
                $job = dbSelectOne(
                    "SELECT scheduled_at FROM cleaning_jobs WHERE id = ? FOR UPDATE",
                    [$request['job_id']]
                );
                if ($job) {
                    // 日跨ぎ対応: newEndTimestamp から日付も含めて算出
                    $newScheduledAt = date('Y-m-d H:i:s', $newEndTimestamp);

                    dbUpdate('cleaning_jobs', [
                        'scheduled_at' => $newScheduledAt,
                    ], 'id = ?', [$request['job_id']]);
                }

                // extensionsテーブルにも記録（管理画面延長と統一的な履歴管理）
                $extensionHours = $extensionMinutes / 60;
                dbInsert('extensions', [
                    'job_id' => $request['job_id'],
                    'extension_hours' => $extensionHours,
                    'additional_reward' => 0,
                    'requested_at' => $request['requested_at'],
                    'approved_at' => date('Y-m-d H:i:s'),
                    'status' => 'approved',
                ]);
            } else {
                // 拒否の場合: 元の担当者を解除し、急募を開始
                $job = dbSelectOne(
                    "SELECT assigned_cleaner_id FROM cleaning_jobs WHERE id = ?",
                    [$request['job_id']]
                );
                $currentCleanerId = $job['assigned_cleaner_id'] ?? null;

                // 案件を急募状態に更新
                dbUpdate('cleaning_jobs', [
                    'assigned_cleaner_id' => null,
                    'status' => 'recruiting',
                    'is_urgent' => 1,
                ], 'id = ?', [$request['job_id']]);

                // 元の担当者の応募を取り消し
                if ($currentCleanerId) {
                    dbUpdate('job_applications', [
                        'status' => 'cancelled',
                    ], 'job_id = ? AND cleaner_id = ? AND status = ?',
                    [$request['job_id'], $currentCleanerId, 'accepted']);
                }

                // 急募通知を送信
                sendJobNotifications($request['job_id'], 'urgent');
            }

            dbCommit();
            $success = true;

            // 延長結果通知（コミット後に実行 - 通知失敗しても処理結果には影響しない）
            try {
                if ($response === 'accept') {
                    // 顧客へメール通知（メールアドレスがある場合）
                    $reservation = dbSelectOne(
                        "SELECT r.*, sa.name as area_name, s.name as store_name
                         FROM reservations r
                         LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
                         LEFT JOIN stores s ON r.store_id = s.id
                         WHERE r.id = ?",
                        [$request['reservation_id']]
                    );
                    if ($reservation && !empty($reservation['customer_email'])) {
                        $extensionMinutes = $request['requested_minutes'];
                        $subject = '【カクレマ】延長が承認されました';
                        $body = "{$reservation['customer_name']} 様\n\n"
                            . "延長リクエストが承認されました。\n\n"
                            . "【延長内容】\n"
                            . "店舗: {$reservation['store_name']}\n"
                            . "延長時間: {$extensionMinutes}分\n\n"
                            . "引き続きごゆっくりお過ごしください。\n\n"
                            . "---\nカクレマ";
                        sendMail($reservation['customer_email'], $subject, $body, 'extension_approved', (int)$reservation['id']);
                    }
                } else {
                    // 拒否時: 顧客へメール通知（メールアドレスがある場合）
                    $reservation = dbSelectOne(
                        "SELECT r.*, sa.name as area_name, s.name as store_name
                         FROM reservations r
                         LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
                         LEFT JOIN stores s ON r.store_id = s.id
                         WHERE r.id = ?",
                        [$request['reservation_id']]
                    );
                    if ($reservation && !empty($reservation['customer_email'])) {
                        $subject = '【カクレマ】延長リクエストについて';
                        $body = "{$reservation['customer_name']} 様\n\n"
                            . "申し訳ございませんが、延長リクエストにお応えすることができませんでした。\n\n"
                            . "現在の終了時間までにご退室をお願いいたします。\n\n"
                            . "---\nカクレマ";
                        sendMail($reservation['customer_email'], $subject, $body, 'extension_declined', (int)$reservation['id']);
                    }
                }
            } catch (Exception $e) {
                error_log("Extension notification failed: " . $e->getMessage());
            }

        } catch (Exception $e) {
            dbRollback();
            error_log("Extension response failed: " . $e->getMessage());
            $errors[] = '回答の送信に失敗しました';
        }
    }
}

/**
 * 延長料金を計算（店舗設定のextension_price_per_hourを使用）
 */
function calculateExtensionPrice(int $minutes, int $storeId = 0): int
{
    if ($storeId > 0) {
        $storeSettings = getStoreSettings($storeId);
        $ratePerHour = $storeSettings['extension_price_per_hour'];
    } else {
        $ratePerHour = EXTENSION_PRICE_PER_HOUR;
    }
    return (int) ($ratePerHour * $minutes / 60);
}

$basePath = defined('BASE_PATH') ? BASE_PATH : '';
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
            <span>延長リクエスト</span>
        </div>

        <div class="login-card">
            <?php if ($success): ?>
            <div class="alert alert-success mb-4">
                <i class="bi bi-check-circle me-2"></i>
                回答を送信しました。
            </div>
            <p class="text-muted text-center">
                このページを閉じてください。
            </p>

            <?php elseif (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <?php foreach ($errors as $error): ?>
                <p class="mb-0"><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>

            <?php elseif ($request): ?>
            <div class="mb-4">
                <h6 class="text-muted mb-2">延長リクエスト内容</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width: 40%">場所</td>
                        <td><?= h($request['area_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">現在の終了</td>
                        <td><?= h(substr($request['end_time'], 0, 5)) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">延長時間</td>
                        <td><strong><?= $request['requested_minutes'] ?>分</strong></td>
                    </tr>
                </table>
            </div>

            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">

                <div class="d-grid gap-3">
                    <button type="submit" name="response" value="accept" class="btn btn-success btn-lg">
                        <i class="bi bi-check-lg"></i> 対応OK
                    </button>
                    <button type="submit" name="response" value="decline" class="btn btn-outline-danger btn-lg">
                        <i class="bi bi-x-lg"></i> 対応NG
                    </button>
                </div>
            </form>
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

.login-footer {
    text-align: center;
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    margin-top: var(--space-6);
}
</style>
</body>
</html>
