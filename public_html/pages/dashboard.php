<?php
/**
 * ダッシュボード（KPI付き）
 */
$pageTitle = 'ダッシュボード';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];
$stores = $filteredStores['stores'];

// IN句構築
$inClause = buildInClause($accessibleStoreIds);
$storeIdPlaceholders = $inClause['placeholders'];
$storeParams = $inClause['params'];

// 期間フィルタ
$period = input('period', 'today');
$allowedPeriods = ['today', 'week', 'month'];
if (!in_array($period, $allowedPeriods)) {
    $period = 'today';
}

// 期間の日付範囲を計算
switch ($period) {
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        $periodLabel = '今週';
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        $periodLabel = '今月';
        break;
    default:
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        $periodLabel = '今日';
}

// KPI: 売上
$salesResult = dbSelectOne(
    "SELECT
        COUNT(*) as reservation_count,
        COALESCE(SUM(total_price), 0) as total_sales,
        COALESCE(AVG(total_price), 0) as avg_price
     FROM reservations
     WHERE store_id IN ({$storeIdPlaceholders})
       AND reservation_date BETWEEN ? AND ?
       AND status NOT IN ('cancelled')
       AND payment_status = 'paid'
       AND deleted_at IS NULL",
    array_merge($storeParams, [$startDate, $endDate])
);
$totalSales = (int) ($salesResult['total_sales'] ?? 0);
$reservationCount = (int) ($salesResult['reservation_count'] ?? 0);
$avgPrice = (int) ($salesResult['avg_price'] ?? 0);

// KPI: 清掃コスト
$costResult = dbSelectOne(
    "SELECT
        COUNT(*) as job_count,
        COALESCE(SUM(base_reward), 0) as total_cost
     FROM cleaning_jobs
     WHERE store_id IN ({$storeIdPlaceholders})
       AND DATE(scheduled_at) BETWEEN ? AND ?
       AND status IN ('completed', 'paid')
       AND deleted_at IS NULL",
    array_merge($storeParams, [$startDate, $endDate])
);
$totalCost = (int) ($costResult['total_cost'] ?? 0);
$completedJobs = (int) ($costResult['job_count'] ?? 0);

// KPI: 粗利
$grossProfit = $totalSales - $totalCost;
$grossMargin = $totalSales > 0 ? round(($grossProfit / $totalSales) * 100, 1) : 0;

// 予約件数（ステータス別）
$todayReservations = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM reservations
     WHERE store_id IN ({$storeIdPlaceholders})
       AND reservation_date = CURDATE()
       AND status NOT IN ('cancelled')
       AND deleted_at IS NULL",
    $storeParams
)['cnt'] ?? 0;

$weekReservations = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM reservations
     WHERE store_id IN ({$storeIdPlaceholders})
       AND reservation_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       AND status NOT IN ('cancelled')
       AND deleted_at IS NULL",
    $storeParams
)['cnt'] ?? 0;

// 案件ステータス
$recruitingJobs = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM cleaning_jobs
     WHERE store_id IN ({$storeIdPlaceholders})
       AND status = 'recruiting'
       AND deleted_at IS NULL",
    $storeParams
)['cnt'] ?? 0;

$unassignedJobs = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM cleaning_jobs
     WHERE store_id IN ({$storeIdPlaceholders})
       AND status = 'unassigned'
       AND deleted_at IS NULL",
    $storeParams
)['cnt'] ?? 0;

// 今日の案件一覧
$todayJobs = dbSelect(
    "SELECT cj.*, s.name as store_name, sa.name as area_name, c.name as cleaner_name
     FROM cleaning_jobs cj
     LEFT JOIN stores s ON cj.store_id = s.id
     LEFT JOIN sales_areas sa ON cj.sales_area_id = sa.id
     LEFT JOIN cleaners c ON cj.assigned_cleaner_id = c.id
     WHERE cj.store_id IN ({$storeIdPlaceholders})
       AND DATE(cj.scheduled_at) = CURDATE()
       AND cj.deleted_at IS NULL
     ORDER BY cj.scheduled_at ASC
     LIMIT 10",
    $storeParams
);

// 最近の予約
$recentReservations = dbSelect(
    "SELECT r.*, s.name as store_name
     FROM reservations r
     LEFT JOIN stores s ON r.store_id = s.id
     WHERE r.store_id IN ({$storeIdPlaceholders})
       AND r.deleted_at IS NULL
     ORDER BY r.created_at DESC
     LIMIT 5",
    $storeParams
);

// 経路別予約数（期間内）
$sourceStats = dbSelect(
    "SELECT source, COUNT(*) as cnt
     FROM reservations
     WHERE store_id IN ({$storeIdPlaceholders})
       AND reservation_date BETWEEN ? AND ?
       AND status NOT IN ('cancelled')
       AND deleted_at IS NULL
     GROUP BY source",
    array_merge($storeParams, [$startDate, $endDate])
);
$sourceMap = [];
foreach ($sourceStats as $stat) {
    $sourceMap[$stat['source']] = $stat['cnt'];
}

require __DIR__ . '/../includes/header.php';
?>

<!-- Period Filter -->
<div class="d-flex justify-content-end mb-4">
    <div class="period-tabs">
        <a href="?period=today" class="<?= $period === 'today' ? 'active' : '' ?>">今日</a>
        <a href="?period=week" class="<?= $period === 'week' ? 'active' : '' ?>">今週</a>
        <a href="?period=month" class="<?= $period === 'month' ? 'active' : '' ?>">今月</a>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label"><?= $periodLabel ?>の売上</div>
                <div class="kpi-value"><?= number_format($totalSales) ?><small>円</small></div>
                <div class="kpi-sub"><?= $reservationCount ?>件の予約</div>
            </div>
            <div class="kpi-icon blue"><i class="bi bi-currency-yen"></i></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label"><?= $periodLabel ?>の清掃コスト</div>
                <div class="kpi-value"><?= number_format($totalCost) ?><small>円</small></div>
                <div class="kpi-sub"><?= $completedJobs ?>件の清掃</div>
            </div>
            <div class="kpi-icon amber"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label"><?= $periodLabel ?>の粗利</div>
                <div class="kpi-value <?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= number_format($grossProfit) ?><small>円</small>
                </div>
                <div class="kpi-sub">粗利率 <?= $grossMargin ?>%</div>
            </div>
            <div class="kpi-icon emerald"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="kpi-label">客単価</div>
                <div class="kpi-value"><?= number_format($avgPrice) ?><small>円</small></div>
                <div class="kpi-sub"><?= $periodLabel ?>平均</div>
            </div>
            <div class="kpi-icon purple"><i class="bi bi-person-badge"></i></div>
        </div>
    </div>
</div>

<!-- Action Cards -->
<div class="action-cards">
    <a href="<?= url('/reservations?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d')) ?>" class="action-card blue">
        <div class="action-icon"><i class="bi bi-calendar-check"></i></div>
        <div class="action-value"><?= $todayReservations ?></div>
        <div class="action-label">今日の予約</div>
    </a>
    <a href="<?= url('/reservations') ?>" class="action-card cyan">
        <div class="action-icon"><i class="bi bi-calendar-week"></i></div>
        <div class="action-value"><?= $weekReservations ?></div>
        <div class="action-label">今週の予約</div>
    </a>
    <a href="<?= url('/jobs?status=recruiting') ?>" class="action-card amber">
        <div class="action-icon"><i class="bi bi-megaphone"></i></div>
        <div class="action-value"><?= $recruitingJobs ?></div>
        <div class="action-label">募集中の案件</div>
    </a>
    <a href="<?= url('/jobs?status=unassigned') ?>" class="action-card rose">
        <div class="action-icon"><i class="bi bi-exclamation-triangle"></i></div>
        <div class="action-value"><?= $unassignedJobs ?></div>
        <div class="action-label">未割当の案件</div>
    </a>
</div>

<!-- Two Column Layout -->
<div class="two-column">
    <!-- Main Column -->
    <div>
        <div class="section-header">
            <h2>今日の清掃案件</h2>
            <a href="<?= url('/jobs') ?>" class="btn btn-sm btn-outline-secondary">すべて見る</a>
        </div>
        <div class="data-table-card">
            <?php if (empty($todayJobs)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <p>本日の案件はありません</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>時間</th>
                            <th>店舗</th>
                            <th>ステータス</th>
                            <th>担当者</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todayJobs as $job): ?>
                        <tr>
                            <td><?= h(formatDateTime($job['scheduled_at'], 'H:i')) ?></td>
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
                                <a href="<?= url('/jobs/' . $job['id']) ?>" class="btn btn-sm btn-outline-secondary">詳細</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Side Column -->
    <div>
        <!-- 経路別予約 -->
        <div class="section-header">
            <h2><?= $periodLabel ?>の予約経路</h2>
        </div>
        <div class="list-card mb-4">
            <div class="p-4">
                <?php
                $totalBySource = array_sum($sourceMap);
                $sources = [
                    'web' => ['label' => 'Web予約', 'color' => 'blue'],
                    'line' => ['label' => 'LINE', 'color' => 'emerald'],
                    'phone' => ['label' => '電話', 'color' => 'amber'],
                    'direct' => ['label' => '直接来店', 'color' => 'gray'],
                ];
                ?>
                <?php if ($totalBySource > 0): ?>
                    <?php foreach ($sources as $key => $source): ?>
                        <?php
                        $count = $sourceMap[$key] ?? 0;
                        $percent = round(($count / $totalBySource) * 100);
                        ?>
                        <div class="progress-bar-wrapper">
                            <div class="progress-label">
                                <span><?= $source['label'] ?></span>
                                <span><?= $count ?>件 (<?= $percent ?>%)</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill <?= $source['color'] ?>" style="width: <?= $percent ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: var(--space-4);">
                        <p>データがありません</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 最近の予約 -->
        <div class="section-header">
            <h2>最近の予約</h2>
            <a href="<?= url('/reservations') ?>" class="btn btn-sm btn-outline-secondary">すべて見る</a>
        </div>
        <div class="list-card">
            <?php if (empty($recentReservations)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <p>予約データがありません</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentReservations as $res): ?>
                <div class="list-item">
                    <div class="list-item-content">
                        <div class="list-item-title"><?= h($res['customer_name']) ?></div>
                        <div class="list-item-sub">
                            <?= h($res['store_name']) ?> / <?= h(formatDate($res['reservation_date'])) ?>
                        </div>
                    </div>
                    <span class="badge bg-<?= statusClass($res['status']) ?>">
                        <?= statusLabel($res['status'], 'reservation') ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
