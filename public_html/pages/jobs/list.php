<?php
/**
 * 清掃案件一覧
 */
$pageTitle = '清掃案件一覧';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

// フィルタパラメータ
$filterStatus = input('status', '');
$filterDateFrom = input('date_from', date('Y-m-d'));
$filterDateTo = input('date_to', date('Y-m-d', strtotime('+7 days')));
$page = max(1, (int) input('page', 1));
$perPage = 20;

// クエリ構築
$where = ["cj.deleted_at IS NULL"];
$params = [];

// 店舗アクセス制御（サイドバー選択に基づく）
$inClause = buildInClause($accessibleStoreIds);
$where[] = "cj.store_id IN ({$inClause['placeholders']})";
$params = array_merge($params, $inClause['params']);

if ($filterStatus !== '') {
    $where[] = "cj.status = ?";
    $params[] = $filterStatus;
}

if ($filterDateFrom) {
    $where[] = "DATE(cj.scheduled_at) >= ?";
    $params[] = $filterDateFrom;
}

if ($filterDateTo) {
    $where[] = "DATE(cj.scheduled_at) <= ?";
    $params[] = $filterDateTo;
}

$whereClause = implode(' AND ', $where);

// 総件数
$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM cleaning_jobs cj WHERE {$whereClause}",
    $params
);
$totalCount = $countResult['cnt'] ?? 0;

// ページネーション計算
$pagination = calculatePagination($totalCount, $perPage, $page);
$page = $pagination['page'];
$totalPages = $pagination['totalPages'];

// データ取得
$jobs = dbSelectPaginated(
    "SELECT cj.*, s.name as store_name, sa.name as area_name, c.name as cleaner_name,
     (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = cj.id) as application_count
     FROM cleaning_jobs cj
     LEFT JOIN stores s ON cj.store_id = s.id
     LEFT JOIN sales_areas sa ON cj.sales_area_id = sa.id
     LEFT JOIN cleaners c ON cj.assigned_cleaner_id = c.id
     WHERE {$whereClause}
     ORDER BY cj.scheduled_at DESC",
    $params,
    $perPage,
    $pagination['offset']
);

// ステータス別件数（サイドバー選択に基づく）
$statusCounts = [];
$statusCountsResult = dbSelect(
    "SELECT status, COUNT(*) as cnt FROM cleaning_jobs
     WHERE store_id IN ({$inClause['placeholders']}) AND deleted_at IS NULL
     GROUP BY status",
    $inClause['params']
);
foreach ($statusCountsResult as $row) {
    $statusCounts[$row['status']] = $row['cnt'];
}

require __DIR__ . '/../../includes/header.php';
?>

<!-- Status Tabs -->
<div class="status-tabs mb-4">
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => '', 'page' => 1])) ?>"
       class="status-tab <?= $filterStatus === '' ? 'active' : '' ?>">
        すべて <span class="count"><?= array_sum($statusCounts) ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'unassigned', 'page' => 1])) ?>"
       class="status-tab rose <?= $filterStatus === 'unassigned' ? 'active' : '' ?>">
        未割当 <span class="count"><?= $statusCounts['unassigned'] ?? 0 ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'recruiting', 'page' => 1])) ?>"
       class="status-tab amber <?= $filterStatus === 'recruiting' ? 'active' : '' ?>">
        募集中 <span class="count"><?= $statusCounts['recruiting'] ?? 0 ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'assigned', 'page' => 1])) ?>"
       class="status-tab blue <?= $filterStatus === 'assigned' ? 'active' : '' ?>">
        確定 <span class="count"><?= $statusCounts['assigned'] ?? 0 ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'completed', 'page' => 1])) ?>"
       class="status-tab emerald <?= $filterStatus === 'completed' ? 'active' : '' ?>">
        完了 <span class="count"><?= $statusCounts['completed'] ?? 0 ?></span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'paid', 'page' => 1])) ?>"
       class="status-tab cyan <?= $filterStatus === 'paid' ? 'active' : '' ?>">
        支払済 <span class="count"><?= $statusCounts['paid'] ?? 0 ?></span>
    </a>
</div>

<!-- Filter -->
<div class="filter-card">
    <form method="get">
        <input type="hidden" name="status" value="<?= h($filterStatus) ?>">
        <div class="filter-row">
            <div class="filter-group">
                <label>開始日</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($filterDateFrom) ?>">
            </div>
            <div class="filter-group">
                <label>終了日</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($filterDateTo) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">検索</button>
                <a href="<?= url('/jobs') ?>" class="btn btn-outline-secondary btn-sm">リセット</a>
            </div>
        </div>
    </form>
</div>

<!-- Data Table -->
<div class="data-table-card">
    <div class="data-table-header">
        <span class="record-count">全 <?= number_format($totalCount) ?> 件</span>
    </div>

    <?php if (empty($jobs)): ?>
        <div class="empty-state">
            <i class="bi bi-briefcase"></i>
            <p>案件データがありません</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>予定日時</th>
                        <th>店舗</th>
                        <th>ステータス</th>
                        <th>担当者</th>
                        <th>応募数</th>
                        <th>報酬</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= h(formatDateTime($job['scheduled_at'])) ?></td>
                        <td>
                            <div class="cell-main"><?= h($job['store_name']) ?></div>
                            <div class="cell-sub"><?= h($job['area_name']) ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?= statusClass($job['status']) ?>">
                                <?= statusLabel($job['status'], 'job') ?>
                            </span>
                        </td>
                        <td><?= h($job['cleaner_name'] ?? '-') ?></td>
                        <td>
                            <?php if ($job['application_count'] > 0): ?>
                                <span class="badge bg-info"><?= $job['application_count'] ?> 人</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-main"><?= formatMoney($job['base_reward']) ?></div>
                        </td>
                        <td>
                            <a href="<?= url('/jobs/' . $job['id']) ?>" class="btn btn-sm btn-outline-secondary">詳細</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <div class="pagination-wrapper">
        <?= renderPagination($page, $totalPages, $_GET) ?>
    </div>
    <?php endif; ?>
</div>

<style>
.status-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.status-tab {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--color-text-secondary);
    text-decoration: none;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    transition: all 0.15s;
}

.status-tab:hover {
    border-color: var(--color-border-strong);
    color: var(--color-text);
}

.status-tab.active {
    background: var(--color-text);
    border-color: var(--color-text);
    color: #fff;
}

.status-tab.rose.active { background: #e11d48; border-color: #e11d48; }
.status-tab.amber.active { background: #d97706; border-color: #d97706; }
.status-tab.blue.active { background: #2563eb; border-color: #2563eb; }
.status-tab.emerald.active { background: #059669; border-color: #059669; }
.status-tab.cyan.active { background: #0891b2; border-color: #0891b2; }

.status-tab .count {
    padding: 2px 6px;
    font-size: var(--text-xs);
    background: rgba(0,0,0,0.1);
    border-radius: var(--radius-sm);
}

.status-tab.active .count {
    background: rgba(255,255,255,0.2);
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
