<?php
/**
 * シフト管理画面（タイムライン表示）
 */
$pageTitle = 'シフト管理';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];
$stores = $filteredStores['stores'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// ステータスホワイトリスト
$validStatuses = ['unassigned', 'recruiting', 'assigned', 'completed', 'cancelled'];

// フィルタ
$filterDateFrom = input('date_from', date('Y-m-d'));
$filterDateTo = input('date_to', date('Y-m-d', strtotime('+6 days')));
$filterStatus = input('status', '');
$filterCleanerId = (int) input('cleaner_id', 0);

// ステータス値バリデーション
if ($filterStatus !== '' && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

// 日付バリデーション
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $filterDateFrom = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $filterDateTo = date('Y-m-d', strtotime('+6 days'));
}

// 日付範囲チェック（最大31日）
$dateFromTs = strtotime($filterDateFrom);
$dateToTs = strtotime($filterDateTo);
if ($dateToTs < $dateFromTs) {
    $filterDateTo = $filterDateFrom;
    $dateToTs = $dateFromTs;
}
if (($dateToTs - $dateFromTs) > 31 * 86400) {
    $filterDateTo = date('Y-m-d', $dateFromTs + 30 * 86400);
}

// 案件データ取得
$whereConditions = [];
$params = [];

$inClause = buildInClause($accessibleStoreIds);
$whereConditions[] = "j.store_id IN ({$inClause['placeholders']})";
$params = array_merge($params, $inClause['params']);

$whereConditions[] = "DATE(j.scheduled_at) >= ?";
$params[] = $filterDateFrom;

$whereConditions[] = "DATE(j.scheduled_at) <= ?";
$params[] = $filterDateTo;

$whereConditions[] = "j.deleted_at IS NULL";

if ($filterStatus !== '') {
    $whereConditions[] = "j.status = ?";
    $params[] = $filterStatus;
}

if ($filterCleanerId > 0) {
    $whereConditions[] = "j.assigned_cleaner_id = ?";
    $params[] = $filterCleanerId;
}

$whereClause = implode(' AND ', $whereConditions);

$jobs = dbSelect(
    "SELECT j.*, DATE(j.scheduled_at) as job_date, TIME(j.scheduled_at) as job_time,
            sa.name as area_name, s.name as store_name,
            c.name as cleaner_name
     FROM cleaning_jobs j
     INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
     INNER JOIN stores s ON j.store_id = s.id
     LEFT JOIN cleaners c ON j.assigned_cleaner_id = c.id
     WHERE {$whereClause}
     ORDER BY j.scheduled_at ASC",
    $params
);

// 日付ごとにグループ化
$jobsByDate = [];
$currentDate = $filterDateFrom;
while (strtotime($currentDate) <= strtotime($filterDateTo)) {
    $jobsByDate[$currentDate] = [];
    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
}

foreach ($jobs as $job) {
    $jobsByDate[$job['job_date']][] = $job;
}

// 未割当・急募アラートのカウント
$alertCounts = [
    'unassigned' => 0,
    'recruiting_urgent' => 0,
    'recruiting_normal' => 0,
];

foreach ($jobs as $job) {
    if ($job['status'] === 'unassigned') {
        $alertCounts['unassigned']++;
    } elseif ($job['status'] === 'recruiting') {
        $jobTime = strtotime($job['scheduled_at']);
        $hoursUntil = ($jobTime - time()) / 3600;
        if ($hoursUntil <= 2 || $job['is_urgent']) {
            $alertCounts['recruiting_urgent']++;
        } else {
            $alertCounts['recruiting_normal']++;
        }
    }
}

// 清掃者リスト（フィルタ用）
$cleaners = dbSelect(
    "SELECT DISTINCT c.id, c.name
     FROM cleaners c
     INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
     WHERE cs.store_id IN ({$inClause['placeholders']})
       AND c.is_active = 1 AND c.deleted_at IS NULL
     ORDER BY c.name",
    $inClause['params']
);

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">シフト管理</h1>
</div>

<!-- アラートサマリー -->
<?php if ($alertCounts['unassigned'] > 0 || $alertCounts['recruiting_urgent'] > 0): ?>
<div class="row mb-4">
    <?php if ($alertCounts['recruiting_urgent'] > 0): ?>
    <div class="col-md-4">
        <div class="alert alert-danger py-2 mb-0">
            <strong>緊急</strong> 募集中（2時間以内または急募）: <?= $alertCounts['recruiting_urgent'] ?>件
        </div>
    </div>
    <?php endif; ?>
    <?php if ($alertCounts['unassigned'] > 0): ?>
    <div class="col-md-4">
        <div class="alert alert-warning py-2 mb-0">
            <strong>要対応</strong> 未割当: <?= $alertCounts['unassigned'] ?>件
        </div>
    </div>
    <?php endif; ?>
    <?php if ($alertCounts['recruiting_normal'] > 0): ?>
    <div class="col-md-4">
        <div class="alert alert-info py-2 mb-0">
            募集中: <?= $alertCounts['recruiting_normal'] ?>件
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- フィルタ -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">期間（から）</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($filterDateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">期間（まで）</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($filterDateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">ステータス</label>
                <select name="status" class="form-select">
                    <option value="">すべて</option>
                    <option value="unassigned" <?= $filterStatus === 'unassigned' ? 'selected' : '' ?>>未割当</option>
                    <option value="recruiting" <?= $filterStatus === 'recruiting' ? 'selected' : '' ?>>募集中</option>
                    <option value="assigned" <?= $filterStatus === 'assigned' ? 'selected' : '' ?>>確定</option>
                    <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>完了</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">担当者</label>
                <select name="cleaner_id" class="form-select">
                    <option value="">すべて</option>
                    <?php foreach ($cleaners as $cleaner): ?>
                    <option value="<?= $cleaner['id'] ?>" <?= $filterCleanerId === (int)$cleaner['id'] ? 'selected' : '' ?>>
                        <?= h($cleaner['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">表示</button>
            </div>
        </form>
    </div>
</div>

<!-- シフト一覧（日付別） -->
<?php foreach ($jobsByDate as $date => $dayJobs): ?>
<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><?= h(formatDate($date, 'Y年n月j日')) ?></strong>
        <span class="text-muted ms-2"><?= getDayName((int)date('w', strtotime($date))) ?>曜日</span>
        <?php if (!empty($dayJobs)): ?>
        <span class="badge bg-secondary ms-2"><?= count($dayJobs) ?>件</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($dayJobs)): ?>
        <p class="text-muted text-center py-3 mb-0">この日の案件はありません</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px">時間</th>
                        <th>営業区分</th>
                        <th>担当者</th>
                        <th class="text-end">報酬</th>
                        <th style="width: 100px">ステータス</th>
                        <th style="width: 80px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dayJobs as $job):
                        $statusInfo = jobStatusLabel($job['status']);
                        $jobTime = strtotime($job['scheduled_at']);
                        $hoursUntil = ($jobTime - time()) / 3600;
                        $isUrgent = ($job['status'] === 'recruiting' && ($hoursUntil <= 2 || $job['is_urgent']));
                        $rowClass = '';
                        if ($job['status'] === 'unassigned') {
                            $rowClass = 'table-warning';
                        } elseif ($isUrgent) {
                            $rowClass = 'table-danger';
                        }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td>
                            <strong><?= h(formatTime($job['job_time'])) ?></strong>
                        </td>
                        <td>
                            <?php if ($job['is_urgent']): ?>
                            <span class="badge bg-danger me-1">急募</span>
                            <?php endif; ?>
                            <?= h($job['area_name']) ?>
                            <br><small class="text-muted"><?= h($job['store_name']) ?></small>
                        </td>
                        <td>
                            <?php if ($job['cleaner_name']): ?>
                            <strong><?= h($job['cleaner_name']) ?></strong>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= number_format($job['base_reward']) ?>円
                        </td>
                        <td>
                            <span class="badge bg-<?= $statusInfo['class'] ?>"><?= $statusInfo['label'] ?></span>
                        </td>
                        <td>
                            <a href="<?= url('/jobs/' . $job['id']) ?>" class="btn btn-sm btn-outline-primary">詳細</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- 凡例 -->
<div class="card">
    <div class="card-body">
        <h6 class="card-title">凡例</h6>
        <div class="d-flex flex-wrap gap-3">
            <div><span class="badge bg-secondary">未割当</span> 案件生成直後</div>
            <div><span class="badge bg-warning text-dark">募集中</span> 清掃者を募集中</div>
            <div><span class="badge bg-danger">急募</span> 2時間以内または急募設定</div>
            <div><span class="badge bg-primary">確定</span> 担当者決定済み</div>
            <div><span class="badge bg-success">完了</span> 清掃完了</div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
