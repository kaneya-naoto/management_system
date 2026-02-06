<?php
/**
 * 予約詳細・編集
 */
$pageTitle = '予約詳細';

// IDは index.php で設定済み
if (!isset($reservationId) || $reservationId <= 0) {
    redirect('/reservations');
}

// 予約データ取得
$reservation = dbSelectOne(
    "SELECT r.*, s.name as store_name, sa.name as area_name
     FROM reservations r
     LEFT JOIN stores s ON r.store_id = s.id
     LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
     WHERE r.id = ? AND r.deleted_at IS NULL",
    [$reservationId]
);

if (!$reservation) {
    flashError('予約が見つかりません');
    redirect('/reservations');
}

// 店舗アクセス権チェック
requireStoreAccess($reservation['store_id']);

// 関連する清掃案件
$cleaningJob = dbSelectOne(
    "SELECT cj.*, c.name as cleaner_name, c.phone as cleaner_phone
     FROM cleaning_jobs cj
     LEFT JOIN cleaners c ON cj.assigned_cleaner_id = c.id
     WHERE cj.reservation_id = ? AND cj.deleted_at IS NULL",
    [$reservationId]
);

// 鍵割当情報
$keyAssignment = dbSelectOne(
    "SELECT ka.*, k.key_number
     FROM key_assignments ka
     INNER JOIN `keys` k ON ka.key_id = k.id
     WHERE ka.reservation_id = ?",
    [$reservationId]
);

// 更新処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    if ($action === 'update_status') {
        $newStatus = input('status', '');
        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = '不正なステータスです';
        } else {
            // 現在のステータスを取得
            $currentReservation = dbSelectOne(
                "SELECT status FROM reservations WHERE id = ? AND deleted_at IS NULL",
                [$reservationId]
            );

            if (!$currentReservation) {
                $errors[] = '予約が見つかりません';
            } else {
                // 状態遷移ルール（明示的マップ）
                $currentStatus = $currentReservation['status'];
                $allowedTransitions = [
                    'pending'   => ['confirmed', 'cancelled'],
                    'confirmed' => ['pending', 'completed', 'cancelled'],
                    'completed' => [], // 完了からの変更は不可
                    'cancelled' => [], // キャンセルからの変更は不可
                ];

                $allowedTransition = true;
                $allowed = $allowedTransitions[$currentStatus] ?? [];
                if (!in_array($newStatus, $allowed, true)) {
                    $errors[] = "「{$currentStatus}」から「{$newStatus}」への変更はできません";
                    $allowedTransition = false;
                }

                if ($allowedTransition && empty($errors)) {
                    dbBegin();
                    try {
                        // 予約詳細を再取得（鍵・案件処理用）
                        $resData = dbSelectOne(
                            "SELECT * FROM reservations WHERE id = ? FOR UPDATE",
                            [$reservationId]
                        );

                        if (!$resData) {
                            throw new Exception('予約が見つかりません');
                        }

                        // 楽観ロック: 現在のステータスを条件に含める
                        $rowCount = dbUpdate(
                            'reservations',
                            ['status' => $newStatus],
                            'id = ? AND status = ?',
                            [$reservationId, $currentStatus]
                        );

                        if ($rowCount === 0) {
                            throw new Exception('他の操作と競合しました。ページを更新して再度お試しください。');
                        }

                        // pending→confirmed: 鍵割当 + 清掃案件生成
                        if ($currentStatus === 'pending' && $newStatus === 'confirmed') {
                            // 鍵がまだ割り当てられていない場合のみ
                            $existingKey = dbSelectOne(
                                "SELECT id FROM key_assignments WHERE reservation_id = ?",
                                [$reservationId]
                            );
                            if (!$existingKey) {
                                assignKeyToReservation(
                                    $reservationId,
                                    $resData['sales_area_id'],
                                    $resData['reservation_date'],
                                    $resData['start_time'],
                                    $resData['end_time']
                                );
                            }

                            // 清掃案件がまだ生成されていない場合のみ
                            $existingJob = dbSelectOne(
                                "SELECT id FROM cleaning_jobs WHERE reservation_id = ? AND deleted_at IS NULL",
                                [$reservationId]
                            );
                            if (!$existingJob) {
                                createCleaningJobForReservation(
                                    $reservationId,
                                    $resData['store_id'],
                                    $resData['sales_area_id'],
                                    $resData['reservation_date'],
                                    $resData['start_time'],
                                    $resData['end_time']
                                );
                            }
                        }

                        // キャンセル時: 鍵解除 + 案件キャンセル
                        if ($newStatus === 'cancelled') {
                            cancelReservationSideEffects($reservationId);
                        }

                        // 監査ログ記録
                        logAudit(
                            'update_status',
                            'reservation',
                            $reservationId,
                            ['status' => $currentStatus],
                            ['status' => $newStatus]
                        );

                        dbCommit();
                        flashSuccess('ステータスを更新しました');
                        redirect("/reservations/{$reservationId}");

                    } catch (Exception $e) {
                        dbRollback();
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }
    }

    if ($action === 'update_info') {
        $customerName = trim(input('customer_name', ''));
        $customerPhone = trim(input('customer_phone', ''));
        $customerEmail = trim(input('customer_email', ''));
        $numPeople = (int) input('num_people', 1);
        $notes = trim(input('notes', ''));

        // バリデーション
        $errors = validateCustomerInput([
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'num_people' => $numPeople,
            'notes' => $notes,
        ]);

        if (empty($errors)) {
            // 楽観ロック: 画面表示時のupdated_atと現在のupdated_atを比較
            $expectedUpdatedAt = input('expected_updated_at', '');
            $oldData = dbSelectOne("SELECT customer_name, customer_phone, customer_email, num_people, notes, updated_at FROM reservations WHERE id = ?", [$reservationId]);

            if ($expectedUpdatedAt && $oldData && $oldData['updated_at'] !== $expectedUpdatedAt) {
                $errors[] = '他のユーザーが既にこの予約を更新しています。ページを再読み込みしてから再度お試しください。';
            }
        }

        if (empty($errors)) {
            dbUpdate('reservations', [
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_email' => $customerEmail,
                'num_people' => $numPeople,
                'notes' => $notes,
            ], 'id = ?', [$reservationId]);

            // 監査ログ記録
            logAudit(
                'update',
                'reservation',
                $reservationId,
                $oldData,
                [
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'customer_email' => $customerEmail,
                    'num_people' => $numPeople,
                    'notes' => $notes,
                ]
            );

            flashSuccess('予約情報を更新しました');
            redirect("/reservations/{$reservationId}");
        }
    }

    if ($action === 'cancel') {
        // 完了済み・キャンセル済みの場合は処理不可
        dbBegin();
        try {
            // 行ロック付きで予約を取得（二重実行防止）
            $lockedRes = dbSelectOne(
                "SELECT id, status FROM reservations WHERE id = ? FOR UPDATE",
                [$reservationId]
            );

            if (!$lockedRes) {
                throw new Exception('予約が見つかりません');
            }

            // 既にキャンセル済みまたは完了済みの場合はスキップ
            if ($lockedRes['status'] === 'cancelled') {
                dbRollback();
                flashSuccess('この予約は既にキャンセル済みです');
                redirect("/reservations/{$reservationId}");
            }

            if ($lockedRes['status'] === 'completed') {
                throw new Exception('完了済みの予約はキャンセルできません');
            }

            // 楽観ロック: pending または confirmed の場合のみキャンセル可能
            $rowCount = dbUpdate(
                'reservations',
                ['status' => 'cancelled'],
                'id = ? AND status IN (?, ?)',
                [$reservationId, 'pending', 'confirmed']
            );

            if ($rowCount === 0) {
                throw new Exception('この予約はキャンセルできません');
            }

            cancelReservationSideEffects($reservationId);

            // キャンセルメール送信
            $cancelledReservation = dbSelectOne(
                "SELECT * FROM reservations WHERE id = ?",
                [$reservationId]
            );
            if ($cancelledReservation) {
                sendReservationCancelMail($cancelledReservation);
            }

            // 監査ログ記録
            logAudit('cancel', 'reservation', $reservationId, null, ['status' => 'cancelled']);

            dbCommit();
            flashSuccess('予約をキャンセルしました');
            redirect("/reservations/{$reservationId}");

        } catch (Exception $e) {
            dbRollback();
            $errors[] = $e->getMessage();
        }
    }
}

// CSRFトークンを1回だけ生成
$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/reservations') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 予約一覧へ戻る</a>
        <h1 class="h3 mb-0">予約詳細 #<?= $reservation['id'] ?></h1>
    </div>
    <div>
        <span class="badge bg-<?= statusClass($reservation['status']) ?> fs-6">
            <?= statusLabel($reservation['status'], 'reservation') ?>
        </span>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row">
    <!-- 予約情報 -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">予約情報</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal">
                    編集
                </button>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">予約日</th>
                                <td><?= h(formatDate($reservation['reservation_date'])) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">時間</th>
                                <td><?= h(substr($reservation['start_time'], 0, 5)) ?> - <?= h(substr($reservation['end_time'], 0, 5)) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">店舗</th>
                                <td><?= h($reservation['store_name']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">営業区分</th>
                                <td><?= h($reservation['area_name'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">予約経路</th>
                                <td><?= reservationSourceLabel($reservation['source']) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">顧客名</th>
                                <td><strong><?= h($reservation['customer_name']) ?></strong></td>
                            </tr>
                            <tr>
                                <th class="text-muted">電話番号</th>
                                <td><?= h($reservation['customer_phone'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">メール</th>
                                <td><?= h($reservation['customer_email'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">人数</th>
                                <td><?= $reservation['num_people'] ?>名</td>
                            </tr>
                            <tr>
                                <th class="text-muted">備考</th>
                                <td><?= h($reservation['notes'] ?? '-') ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 決済情報 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">決済情報</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">基本料金</th>
                                <td><?= formatMoney($reservation['base_price']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">延長料金</th>
                                <td><?= formatMoney($reservation['extension_price'] ?? 0) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">合計</th>
                                <td><strong><?= formatMoney($reservation['total_price']) ?></strong></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <?php $paymentInfo = paymentStatusInfo($reservation['payment_status']); ?>
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">決済状態</th>
                                <td>
                                    <span class="badge bg-<?= $paymentInfo['class'] ?>"><?= $paymentInfo['label'] ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">決済方法</th>
                                <td><?= h($reservation['payment_method'] ?? '-') ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 鍵情報 -->
        <?php if ($keyAssignment): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">鍵割当情報</h5>
            </div>
            <div class="card-body">
                <p class="mb-0">
                    鍵番号: <strong class="fs-4"><?= h($keyAssignment['key_number']) ?></strong>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- サイドバー -->
    <div class="col-lg-4">
        <!-- ステータス変更 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">ステータス変更</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_status">
                    <div class="mb-3">
                        <select name="status" class="form-select">
                            <option value="pending" <?= $reservation['status'] === 'pending' ? 'selected' : '' ?>>保留</option>
                            <option value="confirmed" <?= $reservation['status'] === 'confirmed' ? 'selected' : '' ?>>確定</option>
                            <option value="completed" <?= $reservation['status'] === 'completed' ? 'selected' : '' ?>>完了</option>
                            <option value="cancelled" <?= $reservation['status'] === 'cancelled' ? 'selected' : '' ?>>キャンセル</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">変更を保存</button>
                </form>
            </div>
        </div>

        <!-- 清掃案件 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">清掃案件</h5>
            </div>
            <div class="card-body">
                <?php if ($cleaningJob): ?>
                    <p class="mb-2">
                        ステータス:
                        <span class="badge bg-<?= statusClass($cleaningJob['status']) ?>">
                            <?= statusLabel($cleaningJob['status'], 'job') ?>
                        </span>
                    </p>
                    <?php if ($cleaningJob['cleaner_name']): ?>
                    <p class="mb-2">
                        担当者: <strong><?= h($cleaningJob['cleaner_name']) ?></strong>
                        <?php if ($cleaningJob['cleaner_phone']): ?>
                        <br><small class="text-muted"><?= h($cleaningJob['cleaner_phone']) ?></small>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    <a href="<?= url('/jobs/' . $cleaningJob['id']) ?>" class="btn btn-outline-primary btn-sm">案件詳細を見る</a>
                <?php else: ?>
                    <p class="text-muted mb-0">清掃案件はまだ作成されていません</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 延長用URL（confirmedかつ本日の予約の場合） -->
        <?php if ($reservation['status'] === 'confirmed' && $reservation['extension_token'] && $reservation['reservation_date'] === date('Y-m-d')): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">延長申請URL</h5>
            </div>
            <div class="card-body">
                <?php $extensionUrl = APP_URL . '/extend/?token=' . $reservation['extension_token']; ?>
                <p class="text-muted small mb-2">お客様向けの延長申請URLです。</p>
                <div class="input-group">
                    <input type="text" class="form-control form-control-sm" value="<?= h($extensionUrl) ?>" readonly id="extensionUrl">
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('extensionUrl').value); this.innerHTML='コピー済'; setTimeout(() => this.innerHTML='コピー', 2000);">コピー</button>
                </div>
            </div>
        </div>
        <?php elseif ($reservation['extension_token']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">延長用トークン</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">予約日当日のみ延長申請が可能です。</p>
                <code class="small"><?= h(substr($reservation['extension_token'], 0, 16)) ?>...</code>
            </div>
        </div>
        <?php endif; ?>

        <!-- キャンセル -->
        <?php if ($reservation['status'] !== 'cancelled' && $reservation['status'] !== 'completed'): ?>
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">キャンセル</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">この予約をキャンセルします。この操作は取り消せません。</p>
                <form method="post" onsubmit="return confirm('本当にキャンセルしますか？');">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-danger w-100">予約をキャンセル</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 編集モーダル -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update_info">
                <input type="hidden" name="expected_updated_at" value="<?= h($reservation['updated_at'] ?? '') ?>">
                <div class="modal-header">
                    <h5 class="modal-title">予約情報編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">顧客名 <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" class="form-control"
                               value="<?= h($reservation['customer_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="customer_phone" class="form-control"
                               value="<?= h($reservation['customer_phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" name="customer_email" class="form-control"
                               value="<?= h($reservation['customer_email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">人数</label>
                        <input type="number" name="num_people" class="form-control"
                               value="<?= $reservation['num_people'] ?>" min="1" max="10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="notes" class="form-control" rows="3"><?= h($reservation['notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
