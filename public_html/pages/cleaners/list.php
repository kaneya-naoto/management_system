<?php
/**
 * 清掃者一覧
 */
$pageTitle = '清掃者一覧';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

// フィルタパラメータ
$filterActive = input('active', '');
$filterSearch = input('search', '');
$page = max(1, (int) input('page', 1));
$perPage = 20;

// クエリ構築
$where = ["c.deleted_at IS NULL"];
$params = [];

// 店舗フィルタ（サイドバー選択に基づく）
$inClause = buildInClause($accessibleStoreIds);
$where[] = "EXISTS (SELECT 1 FROM cleaner_stores cs WHERE cs.cleaner_id = c.id AND cs.store_id IN ({$inClause['placeholders']}))";
$params = array_merge($params, $inClause['params']);

if ($filterActive !== '') {
    $where[] = "c.is_active = ?";
    $params[] = $filterActive;
}

if ($filterSearch !== '') {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.ipass_code LIKE ?)";
    $searchTerm = '%' . $filterSearch . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $where);

// 総件数
$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM cleaners c WHERE {$whereClause}",
    $params
);
$totalCount = $countResult['cnt'] ?? 0;

// ページネーション計算
$pagination = calculatePagination($totalCount, $perPage, $page);
$page = $pagination['page'];
$totalPages = $pagination['totalPages'];

// データ取得
$cleaners = dbSelectPaginated(
    "SELECT c.*,
     (SELECT COUNT(*) FROM cleaning_jobs cj WHERE cj.assigned_cleaner_id = c.id AND cj.status = 'completed') as job_count,
     (SELECT GROUP_CONCAT(s.name SEPARATOR ', ')
      FROM cleaner_stores cs
      INNER JOIN stores s ON cs.store_id = s.id
      WHERE cs.cleaner_id = c.id) as store_names
     FROM cleaners c
     WHERE {$whereClause}
     ORDER BY c.name ASC",
    $params,
    $perPage,
    $pagination['offset']
);

// 統計（サイドバー選択に基づく）
$activeCount = 0;
$inactiveCount = 0;
$statsResult = dbSelect(
    "SELECT c.is_active, COUNT(*) as cnt FROM cleaners c
     WHERE c.deleted_at IS NULL
     AND EXISTS (SELECT 1 FROM cleaner_stores cs WHERE cs.cleaner_id = c.id AND cs.store_id IN ({$inClause['placeholders']}))
     GROUP BY c.is_active",
    $inClause['params']
);
foreach ($statsResult as $row) {
    if ($row['is_active']) {
        $activeCount = $row['cnt'];
    } else {
        $inactiveCount = $row['cnt'];
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<!-- Stats -->
<div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: var(--space-4);">
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label">登録者数</div>
                <div class="kpi-value"><?= $activeCount + $inactiveCount ?><small>人</small></div>
            </div>
            <div class="kpi-icon blue"><i class="bi bi-people"></i></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label">稼働中</div>
                <div class="kpi-value text-success"><?= $activeCount ?><small>人</small></div>
            </div>
            <div class="kpi-icon emerald"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label">休止中</div>
                <div class="kpi-value"><?= $inactiveCount ?><small>人</small></div>
            </div>
            <div class="kpi-icon gray"><i class="bi bi-pause-circle"></i></div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="filter-card">
    <form method="get">
        <div class="filter-row">
            <div class="filter-group">
                <label>ステータス</label>
                <select name="active" class="form-select form-select-sm">
                    <option value="">すべて</option>
                    <option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>稼働中</option>
                    <option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>休止中</option>
                </select>
            </div>
            <div class="filter-group" style="flex: 2;">
                <label>検索</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="名前・電話番号・iPass" value="<?= h($filterSearch) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">検索</button>
                <a href="<?= url('/cleaners') ?>" class="btn btn-outline-secondary btn-sm">リセット</a>
            </div>
        </div>
    </form>
</div>

<!-- Data Table -->
<div class="data-table-card">
    <div class="data-table-header">
        <span class="record-count">全 <?= number_format($totalCount) ?> 件</span>
    </div>

    <?php if (empty($cleaners)): ?>
        <div class="empty-state">
            <i class="bi bi-people"></i>
            <p>清掃者データがありません</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>名前</th>
                        <th>電話番号</th>
                        <th>iPass</th>
                        <th>対応店舗</th>
                        <th>完了案件</th>
                        <th>ステータス</th>
                        <th>登録日</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cleaners as $cleaner): ?>
                    <tr>
                        <td>
                            <div class="cell-main"><?= h($cleaner['name']) ?></div>
                        </td>
                        <td><?= h($cleaner['phone'] ?? '-') ?></td>
                        <td>
                            <?php if ($cleaner['ipass_code']): ?>
                                <code style="font-size: var(--text-xs); background: var(--color-bg-secondary); padding: 2px 6px; border-radius: var(--radius-sm);">
                                    <?= h($cleaner['ipass_code']) ?>
                                </code>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-sub"><?= h($cleaner['store_names'] ?? '-') ?></div>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= $cleaner['job_count'] ?> 件</span>
                        </td>
                        <td>
                            <?php if ($cleaner['is_active']): ?>
                                <span class="badge bg-success">稼働中</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">休止中</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h(formatDate($cleaner['registered_at'])) ?></td>
                        <td>
                            <a href="<?= url('/cleaners/' . $cleaner['id']) ?>" class="btn btn-sm btn-outline-secondary">詳細</a>
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
.kpi-icon.gray { background: #f1f5f9; color: #64748b; }
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
