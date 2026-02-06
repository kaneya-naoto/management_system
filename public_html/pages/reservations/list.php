<?php
/**
 * 予約一覧
 */
$pageTitle = '予約一覧';

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
$where = ["r.deleted_at IS NULL"];
$params = [];

// 店舗アクセス制御（サイドバー選択に基づく）
$inClause = buildInClause($accessibleStoreIds);
$where[] = "r.store_id IN ({$inClause['placeholders']})";
$params = array_merge($params, $inClause['params']);

if ($filterStatus !== '') {
    $where[] = "r.status = ?";
    $params[] = $filterStatus;
}

if ($filterDateFrom) {
    $where[] = "r.reservation_date >= ?";
    $params[] = $filterDateFrom;
}

if ($filterDateTo) {
    $where[] = "r.reservation_date <= ?";
    $params[] = $filterDateTo;
}

$whereClause = implode(' AND ', $where);

// 総件数
$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM reservations r WHERE {$whereClause}",
    $params
);
$totalCount = $countResult['cnt'] ?? 0;

// ページネーション計算
$pagination = calculatePagination($totalCount, $perPage, $page);
$page = $pagination['page'];
$totalPages = $pagination['totalPages'];

// データ取得
$reservations = dbSelectPaginated(
    "SELECT r.*, s.name as store_name, sa.name as area_name
     FROM reservations r
     LEFT JOIN stores s ON r.store_id = s.id
     LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
     WHERE {$whereClause}
     ORDER BY r.reservation_date DESC, r.start_time DESC",
    $params,
    $perPage,
    $pagination['offset']
);

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<!-- 表示切替タブ -->
<div class="mb-4">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/reservations') ?>">
                <i class="bi bi-list-ul"></i> リスト
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/reservations/calendar') ?>">
                <i class="bi bi-calendar3"></i> カレンダー
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/reservations/gantt') ?>">
                <i class="bi bi-bar-chart-steps"></i> ガント
            </a>
        </li>
    </ul>
</div>

<!-- Filter -->
<div class="filter-card">
    <form method="get">
        <div class="filter-row">
            <div class="filter-group">
                <label>ステータス</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">すべて</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>保留</option>
                    <option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>確定</option>
                    <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>完了</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>キャンセル</option>
                </select>
            </div>
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
                <a href="<?= url('/reservations') ?>" class="btn btn-outline-secondary btn-sm">リセット</a>
            </div>
        </div>
    </form>
</div>

<!-- Data Table -->
<div class="data-table-card">
    <div class="data-table-header">
        <span class="record-count">全 <?= number_format($totalCount) ?> 件</span>
        <div class="d-flex gap-2">
            <a href="<?= url('/api/export/reservations') ?>?<?= http_build_query([
                'status' => $filterStatus,
                'date_from' => $filterDateFrom,
                'date_to' => $filterDateTo,
                CSRF_TOKEN_NAME => $csrfToken,
            ]) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-download"></i> CSV
            </a>
            <a href="<?= url('/reservations/new') ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 新規登録
            </a>
        </div>
    </div>

    <?php if (empty($reservations)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <p>予約データがありません</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>予約日</th>
                        <th>時間</th>
                        <th>顧客名</th>
                        <th>店舗</th>
                        <th>ステータス</th>
                        <th>決済</th>
                        <th>経路</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservations as $res): ?>
                    <tr>
                        <td><?= h(formatDate($res['reservation_date'])) ?></td>
                        <td><?= h(substr($res['start_time'], 0, 5)) ?> - <?= h(substr($res['end_time'], 0, 5)) ?></td>
                        <td>
                            <div class="cell-main"><?= h($res['customer_name']) ?></div>
                            <div class="cell-sub"><?= h($res['customer_phone']) ?></div>
                        </td>
                        <td>
                            <div class="cell-main"><?= h($res['store_name']) ?></div>
                            <div class="cell-sub"><?= h($res['area_name']) ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?= statusClass($res['status']) ?>">
                                <?= statusLabel($res['status'], 'reservation') ?>
                            </span>
                        </td>
                        <td>
                            <?php $payment = paymentStatusInfo($res['payment_status']); ?>
                            <span class="badge bg-<?= $payment['class'] ?>"><?= $payment['label'] ?></span>
                        </td>
                        <td>
                            <?php
                            $sourceLabel = match($res['source']) {
                                'web' => 'Web',
                                'line' => 'LINE',
                                'phone' => '電話',
                                'direct' => '直接',
                                default => '-'
                            };
                            ?>
                            <?= $sourceLabel ?>
                        </td>
                        <td>
                            <a href="<?= url('/reservations/' . $res['id']) ?>" class="btn btn-sm btn-outline-secondary">詳細</a>
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
