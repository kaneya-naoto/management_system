<?php
/**
 * 支払い詳細
 */

// パラメータ検証
if (!isset($paymentId) || $paymentId <= 0) {
    flashError('支払いIDが不正です');
    redirect('/payments');
}

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

$inClause = buildInClause($accessibleStoreIds);

// 支払い情報取得
$payment = dbSelectOne(
    "SELECT cp.*, DATE(j.scheduled_at) as scheduled_date, TIME(j.scheduled_at) as scheduled_time,
            j.status as job_status, j.notes as job_notes,
            c.name as cleaner_name, c.phone as cleaner_phone, c.email as cleaner_email,
            s.name as store_name, sa.name as area_name,
            r.customer_name, r.reservation_date, r.start_time, r.end_time,
            u.name as paid_by_name
     FROM cleaner_payments cp
     INNER JOIN cleaning_jobs j ON cp.job_id = j.id
     INNER JOIN cleaners c ON cp.cleaner_id = c.id
     INNER JOIN stores s ON cp.store_id = s.id
     INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
     LEFT JOIN reservations r ON j.reservation_id = r.id
     LEFT JOIN users u ON cp.paid_by = u.id
     WHERE cp.id = ? AND cp.store_id IN ({$inClause['placeholders']})",
    array_merge([$paymentId], $inClause['params'])
);

if (!$payment) {
    flashError('支払い情報が見つかりません');
    redirect('/payments');
}

$pageTitle = '支払い詳細 - ' . h($payment['cleaner_name']);

// 支払い処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    if ($action === 'pay') {
        if ($payment['status'] === 'paid') {
            $errors[] = 'この支払いは既に処理済みです';
        } else {
            $paidAt = input('paid_at', date('Y-m-d'));
            $notes = trim(input('notes', ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt)) {
                $errors[] = '支払い日の形式が不正です';
            }

            if (empty($errors)) {
                dbBegin();
                try {
                    $updated = dbExecute(
                        "UPDATE cleaner_payments
                         SET status = 'paid', paid_at = ?, paid_by = ?, notes = ?
                         WHERE id = ? AND status = 'pending'",
                        [$paidAt, getCurrentUserId(), $notes ?: null, $paymentId]
                    );

                    if ($updated === 0) {
                        throw new Exception('支払い処理に失敗しました（既に処理済みの可能性があります）');
                    }

                    // 監査ログ記録
                    logAudit(
                        'payment_complete',
                        'cleaner_payment',
                        $paymentId,
                        ['status' => 'pending'],
                        ['status' => 'paid', 'paid_at' => $paidAt]
                    );

                    dbCommit();
                    flashSuccess('支払いを処理しました');
                    redirect('/payments/' . $paymentId);
                } catch (Exception $e) {
                    dbRollback();
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/payments') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 支払い一覧へ戻る</a>
        <h1 class="h3 mb-0">支払い詳細</h1>
    </div>
    <?php if ($payment['status'] === 'pending'): ?>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#payModal">
        支払い処理
    </button>
    <?php endif; ?>
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
    <div class="col-lg-8">
        <!-- 支払い情報 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">支払い情報</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">ステータス</th>
                                <td>
                                    <?php if ($payment['status'] === 'paid'): ?>
                                        <span class="badge bg-success">支払済</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">未払い</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">報酬</th>
                                <td><?= number_format($payment['base_amount']) ?>円</td>
                            </tr>
                            <tr>
                                <th class="text-muted">総報酬</th>
                                <td class="h5 text-primary"><?= number_format($payment['total_amount']) ?>円</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <?php if ($payment['status'] === 'paid'): ?>
                            <tr>
                                <th class="text-muted" style="width: 40%">支払い日</th>
                                <td><?= h(formatDate($payment['paid_at'], 'Y年n月j日')) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">処理者</th>
                                <td><?= h($payment['paid_by_name'] ?? '-') ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th class="text-muted">作成日時</th>
                                <td><?= h(formatDate($payment['created_at'], 'Y/n/j H:i')) ?></td>
                            </tr>
                            <?php if ($payment['notes']): ?>
                            <tr>
                                <th class="text-muted">備考</th>
                                <td><?= nl2br(h($payment['notes'])) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 案件情報 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">案件情報</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th class="text-muted" style="width: 20%">清掃日時</th>
                        <td>
                            <?= h(formatDate($payment['scheduled_date'], 'Y年n月j日')) ?>
                            <?= h(formatTime($payment['scheduled_time'])) ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">店舗・区分</th>
                        <td><?= h($payment['store_name']) ?> - <?= h($payment['area_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">案件ステータス</th>
                        <td>
                            <?php
                            $statusInfo = jobStatusLabel($payment['job_status']);
                            ?>
                            <span class="badge bg-<?= $statusInfo['class'] ?>"><?= $statusInfo['label'] ?></span>
                        </td>
                    </tr>
                    <?php if ($payment['customer_name']): ?>
                    <tr>
                        <th class="text-muted">予約者</th>
                        <td><?= h($payment['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">予約時間</th>
                        <td>
                            <?= h(formatDate($payment['reservation_date'], 'Y/n/j')) ?>
                            <?= h(formatTime($payment['start_time'])) ?> 〜 <?= h(formatTime($payment['end_time'])) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <a href="<?= url('/jobs/' . $payment['job_id']) ?>" class="btn btn-sm btn-outline-secondary">案件詳細を見る</a>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- 清掃者情報 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">担当清掃者</h5>
            </div>
            <div class="card-body">
                <h5><?= h($payment['cleaner_name']) ?></h5>
                <table class="table table-borderless table-sm">
                    <tr>
                        <th class="text-muted">電話番号</th>
                        <td><?= h($payment['cleaner_phone']) ?></td>
                    </tr>
                    <?php if ($payment['cleaner_email']): ?>
                    <tr>
                        <th class="text-muted">メール</th>
                        <td><?= h($payment['cleaner_email']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <a href="<?= url('/cleaners/' . $payment['cleaner_id']) ?>" class="btn btn-sm btn-outline-secondary">清掃者詳細</a>
            </div>
        </div>
    </div>
</div>

<!-- 支払い処理モーダル -->
<?php if ($payment['status'] === 'pending'): ?>
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="pay">

                <div class="modal-header">
                    <h5 class="modal-title">支払い処理</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong><?= h($payment['cleaner_name']) ?></strong>さんへの支払い<br>
                        金額: <strong><?= number_format($payment['total_amount']) ?>円</strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">支払い日</label>
                        <input type="date" name="paid_at" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="任意"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success">支払い完了</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
