<?php
/**
 * 支払い管理一覧
 */
$pageTitle = '支払い管理';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];
$stores = $filteredStores['stores'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// フィルタ
$filterStatus = input('status', '');
$filterCleanerId = (int) input('cleaner_id', 0);
$filterDateFrom = input('date_from', date('Y-m-01'));
$filterDateTo = input('date_to', date('Y-m-t'));

// 一括支払い処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    if ($action === 'bulk_pay') {
        $paymentIds = input('payment_ids', []);
        if (!is_array($paymentIds)) {
            $paymentIds = [];
        }
        $paymentIds = array_filter(array_map('intval', $paymentIds));

        if (empty($paymentIds)) {
            $errors[] = '支払い対象を選択してください';
        } else {
            dbBegin();
            try {
                $inClause = buildInClause($paymentIds);
                $storeInClause = buildInClause($accessibleStoreIds);

                // 対象の支払いをロック
                $payments = dbSelect(
                    "SELECT id, status FROM cleaner_payments
                     WHERE id IN ({$inClause['placeholders']})
                       AND store_id IN ({$storeInClause['placeholders']})
                       AND status = 'pending'
                     FOR UPDATE",
                    array_merge($inClause['params'], $storeInClause['params'])
                );

                if (count($payments) !== count($paymentIds)) {
                    throw new Exception('一部の支払いが見つからないか、既に支払済です');
                }

                // 一括更新
                $updated = dbExecute(
                    "UPDATE cleaner_payments
                     SET status = 'paid', paid_at = CURDATE(), paid_by = ?
                     WHERE id IN ({$inClause['placeholders']}) AND status = 'pending'",
                    array_merge([getCurrentUserId()], $inClause['params'])
                );

                // 更新行数の検証
                if ($updated !== count($paymentIds)) {
                    throw new Exception('一部の支払いの更新に失敗しました。ページを更新して再度お試しください。');
                }

                dbCommit();
                flashSuccess($updated . '件の支払いを処理しました');
                redirect('/payments?' . http_build_query([
                    'status' => $filterStatus,
                    'date_from' => $filterDateFrom,
                    'date_to' => $filterDateTo,
                ]));
            } catch (Exception $e) {
                dbRollback();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// 支払い一覧取得
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

// ページネーション
$page = max(1, (int) input('page', 1));
$perPage = 30;

$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt
     FROM cleaner_payments cp
     INNER JOIN cleaning_jobs j ON cp.job_id = j.id
     WHERE {$whereClause}",
    $params
);
$totalCount = (int) ($countResult['cnt'] ?? 0);
$totalPages = max(1, ceil($totalCount / $perPage));
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;
$payments = dbSelectPaginated(
    "SELECT cp.*, DATE(j.scheduled_at) as scheduled_date, TIME(j.scheduled_at) as scheduled_time,
            j.base_reward,
            c.name as cleaner_name, c.phone as cleaner_phone,
            s.name as store_name, sa.name as area_name
     FROM cleaner_payments cp
     INNER JOIN cleaning_jobs j ON cp.job_id = j.id
     INNER JOIN cleaners c ON cp.cleaner_id = c.id
     INNER JOIN stores s ON cp.store_id = s.id
     INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
     WHERE {$whereClause}
     ORDER BY j.scheduled_at DESC",
    $params,
    $perPage,
    $offset
);

// 集計
$summary = dbSelectOne(
    "SELECT
        COUNT(*) as total_count,
        SUM(cp.total_amount) as total_amount,
        SUM(CASE WHEN cp.status = 'pending' THEN cp.total_amount ELSE 0 END) as unpaid_amount,
        SUM(CASE WHEN cp.status = 'paid' THEN cp.total_amount ELSE 0 END) as paid_amount,
        SUM(CASE WHEN cp.status = 'pending' THEN 1 ELSE 0 END) as unpaid_count,
        SUM(CASE WHEN cp.status = 'paid' THEN 1 ELSE 0 END) as paid_count
     FROM cleaner_payments cp
     INNER JOIN cleaning_jobs j ON cp.job_id = j.id
     WHERE {$whereClause}",
    $params
);

// 清掃者リスト（フィルタ用）
$cleaners = dbSelect(
    "SELECT DISTINCT c.id, c.name
     FROM cleaners c
     INNER JOIN cleaner_payments cp ON c.id = cp.cleaner_id
     WHERE cp.store_id IN ({$inClause['placeholders']})
     ORDER BY c.name",
    $inClause['params']
);

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">支払い管理</h1>
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

<!-- 集計サマリー -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-light">
            <div class="card-body text-center">
                <div class="text-muted small">総支払額</div>
                <div class="h4 mb-0"><?= number_format($summary['total_amount'] ?? 0) ?>円</div>
                <div class="small text-muted"><?= number_format($summary['total_count'] ?? 0) ?>件</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning bg-opacity-25">
            <div class="card-body text-center">
                <div class="text-muted small">未払い</div>
                <div class="h4 mb-0 text-warning"><?= number_format($summary['unpaid_amount'] ?? 0) ?>円</div>
                <div class="small text-muted"><?= number_format($summary['unpaid_count'] ?? 0) ?>件</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success bg-opacity-25">
            <div class="card-body text-center">
                <div class="text-muted small">支払済</div>
                <div class="h4 mb-0 text-success"><?= number_format($summary['paid_amount'] ?? 0) ?>円</div>
                <div class="small text-muted"><?= number_format($summary['paid_count'] ?? 0) ?>件</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted small">平均報酬</div>
                <div class="h4 mb-0">
                    <?= ($summary['total_count'] ?? 0) > 0 ? number_format(($summary['total_amount'] ?? 0) / $summary['total_count']) : 0 ?>円
                </div>
                <div class="small text-muted">1件あたり</div>
            </div>
        </div>
    </div>
</div>

<!-- フィルタ -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">ステータス</label>
                <select name="status" class="form-select">
                    <option value="">すべて</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>未払い</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>支払済</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">清掃者</label>
                <select name="cleaner_id" class="form-select">
                    <option value="">すべて</option>
                    <?php foreach ($cleaners as $cleaner): ?>
                    <option value="<?= $cleaner['id'] ?>" <?= $filterCleanerId === (int)$cleaner['id'] ? 'selected' : '' ?>>
                        <?= h($cleaner['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">期間（から）</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($filterDateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">期間（まで）</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($filterDateTo) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">検索</button>
            </div>
        </form>
    </div>
</div>

<!-- 支払い一覧 -->
<form method="post" id="bulkForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="bulk_pay">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">支払い一覧</h5>
            <div class="d-flex gap-2">
                <a href="<?= url('/api/export/payments') ?>?<?= http_build_query([
                    'status' => $filterStatus,
                    'cleaner_id' => $filterCleanerId,
                    'date_from' => $filterDateFrom,
                    'date_to' => $filterDateTo,
                    CSRF_TOKEN_NAME => $csrfToken,
                ]) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download"></i> CSV
                </a>
                <button type="submit" class="btn btn-success btn-sm" id="bulkPayBtn" disabled
                        onclick="return confirm('選択した支払いを一括処理しますか？')">
                    一括支払い処理
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($payments)): ?>
                <p class="text-muted text-center py-4 mb-0">支払いデータがありません</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px">
                                    <input type="checkbox" class="form-check-input" id="selectAll">
                                </th>
                                <th>案件日</th>
                                <th>営業区分</th>
                                <th>担当者</th>
                                <th class="text-end">報酬</th>
                                <th class="text-end">総報酬</th>
                                <th>ステータス</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <?php if ($payment['status'] === 'pending'): ?>
                                    <input type="checkbox" class="form-check-input payment-checkbox"
                                           name="payment_ids[]" value="<?= $payment['id'] ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= h(formatDate($payment['scheduled_date'], 'n/j')) ?></strong>
                                    <small class="text-muted"><?= h(formatTime($payment['scheduled_time'])) ?></small>
                                </td>
                                <td>
                                    <small><?= h($payment['store_name']) ?></small><br>
                                    <?= h($payment['area_name']) ?>
                                </td>
                                <td>
                                    <strong><?= h($payment['cleaner_name']) ?></strong>
                                    <small class="text-muted d-block"><?= h($payment['cleaner_phone']) ?></small>
                                </td>
                                <td class="text-end"><?= number_format($payment['base_amount']) ?>円</td>
                                <td class="text-end fw-bold"><?= number_format($payment['total_amount']) ?>円</td>
                                <td>
                                    <?php if ($payment['status'] === 'paid'): ?>
                                        <span class="badge bg-success">支払済</span>
                                        <?php if ($payment['paid_at']): ?>
                                        <small class="text-muted d-block"><?= h(formatDate($payment['paid_at'], 'n/j')) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">未払い</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= url('/payments/' . $payment['id']) ?>" class="btn btn-sm btn-outline-primary">詳細</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($totalPages > 1): ?>
<nav class="mt-4">
    <?= renderPagination($page, $totalPages, '/payments?' . http_build_query([
        'status' => $filterStatus,
        'cleaner_id' => $filterCleanerId,
        'date_from' => $filterDateFrom,
        'date_to' => $filterDateTo,
    ]) . '&page=') ?>
</nav>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.payment-checkbox');
    const bulkPayBtn = document.getElementById('bulkPayBtn');

    function updateBulkButton() {
        const checked = document.querySelectorAll('.payment-checkbox:checked').length;
        bulkPayBtn.disabled = checked === 0;
        bulkPayBtn.textContent = checked > 0
            ? `一括支払い処理 (${checked}件)`
            : '一括支払い処理';
    }

    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButton();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkButton);
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
