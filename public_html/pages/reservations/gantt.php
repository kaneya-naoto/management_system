<?php
/**
 * 予約ガント表示（タイムライン）
 */
$pageTitle = '予約タイムライン';

// フィルタ済みの店舗IDを取得
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

// 日付パラメータ
$targetDate = input('date', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    $targetDate = date('Y-m-d');
}

// 営業区分を取得
$salesAreas = getAccessibleSalesAreas($accessibleStoreIds);

// 予約データを取得
$inClause = buildInClause($accessibleStoreIds);
$reservations = dbSelect(
    "SELECT r.id, r.sales_area_id, r.start_time, r.end_time,
            r.customer_name, r.status, r.num_people,
            sa.name as area_name
     FROM reservations r
     LEFT JOIN sales_areas sa ON r.sales_area_id = sa.id
     WHERE r.store_id IN ({$inClause['placeholders']})
       AND r.reservation_date = ?
       AND r.deleted_at IS NULL
     ORDER BY r.sales_area_id, r.start_time",
    array_merge($inClause['params'], [$targetDate])
);

// 営業区分ごとにグループ化
$groupedReservations = [];
foreach ($reservations as $res) {
    $areaId = $res['sales_area_id'];
    if (!isset($groupedReservations[$areaId])) {
        $groupedReservations[$areaId] = [];
    }
    $groupedReservations[$areaId][] = $res;
}

// 時間帯（8:00〜24:00）
$hours = range(8, 24);
$timelineHours = count($hours) - 1; // 時間範囲は16時間（8時〜24時）

require __DIR__ . '/../../includes/header.php';
?>

<!-- 表示切替タブ -->
<div class="mb-4">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/reservations') ?>">
                <i class="bi bi-list-ul"></i> リスト
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/reservations/calendar') ?>">
                <i class="bi bi-calendar3"></i> カレンダー
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/reservations/gantt') ?>">
                <i class="bi bi-bar-chart-steps"></i> ガント
            </a>
        </li>
    </ul>
</div>

<!-- 日付選択 -->
<div class="mb-4 d-flex align-items-center gap-3">
    <a href="<?= url('/reservations/gantt') ?>?date=<?= date('Y-m-d', strtotime($targetDate . ' -1 day')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-chevron-left"></i>
    </a>
    <input type="date" id="datePicker" class="form-control form-control-sm" style="width: auto;" value="<?= h($targetDate) ?>">
    <a href="<?= url('/reservations/gantt') ?>?date=<?= date('Y-m-d', strtotime($targetDate . ' +1 day')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-chevron-right"></i>
    </a>
    <span class="text-muted"><?= date('Y年n月j日', strtotime($targetDate)) ?> (<?= ['日', '月', '火', '水', '木', '金', '土'][date('w', strtotime($targetDate))] ?>)</span>
</div>

<!-- 凡例 -->
<div class="mb-3 d-flex gap-3 flex-wrap">
    <span class="badge bg-secondary">保留</span>
    <span class="badge bg-primary">確定</span>
    <span class="badge bg-success">完了</span>
    <span class="badge bg-danger">キャンセル</span>
</div>

<!-- ガントチャート -->
<div class="card">
    <div class="card-body p-0">
        <div class="gantt-container">
            <table class="gantt-table">
                <thead>
                    <tr>
                        <th class="gantt-area-header">営業区分</th>
                        <?php foreach ($hours as $hour): ?>
                        <th class="gantt-hour-header"><?= $hour ?>:00</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesAreas)): ?>
                    <tr>
                        <td colspan="<?= count($hours) + 1 ?>" class="text-center text-muted py-4">
                            営業区分が登録されていません
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($salesAreas as $area): ?>
                        <tr>
                            <td class="gantt-area-cell">
                                <small class="text-muted"><?= h($area['store_name']) ?></small><br>
                                <?= h($area['name']) ?>
                            </td>
                            <td colspan="<?= count($hours) ?>" class="gantt-timeline-cell">
                                <div class="gantt-timeline">
                                    <?php if (isset($groupedReservations[$area['id']])): ?>
                                        <?php foreach ($groupedReservations[$area['id']] as $res): ?>
                                        <?php
                                            $startHour = (int) substr($res['start_time'], 0, 2);
                                            $startMin = (int) substr($res['start_time'], 3, 2);
                                            $endHour = (int) substr($res['end_time'], 0, 2);
                                            $endMin = (int) substr($res['end_time'], 3, 2);

                                            $startOffset = ($startHour - 8) + ($startMin / 60);
                                            $duration = ($endHour - $startHour) + (($endMin - $startMin) / 60);

                                            // 16時間の範囲で計算（8時〜24時 = 16時間）
                                            $left = ($startOffset / $timelineHours) * 100;
                                            $width = ($duration / $timelineHours) * 100;

                                            $statusClass = match($res['status']) {
                                                'pending' => 'bg-secondary',
                                                'confirmed' => 'bg-primary',
                                                'completed' => 'bg-success',
                                                'cancelled' => 'bg-danger',
                                                default => 'bg-secondary'
                                            };
                                        ?>
                                        <a href="<?= url('/reservations/' . $res['id']) ?>"
                                           class="gantt-bar <?= $statusClass ?>"
                                           style="left: <?= $left ?>%; width: <?= $width ?>%;"
                                           title="<?= h($res['customer_name']) ?> (<?= h(substr($res['start_time'], 0, 5)) ?>-<?= h(substr($res['end_time'], 0, 5)) ?>)">
                                            <span class="gantt-bar-text"><?= h($res['customer_name']) ?></span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('datePicker').addEventListener('change', function() {
    const basePath = '<?= defined("BASE_PATH") ? BASE_PATH : "" ?>';
    window.location.href = basePath + '/reservations/gantt?date=' + this.value;
});
</script>

<style>
.gantt-container {
    overflow-x: auto;
}

.gantt-table {
    width: 100%;
    min-width: 1200px;
    border-collapse: collapse;
}

.gantt-area-header {
    width: 150px;
    min-width: 150px;
    padding: 8px;
    text-align: left;
    background: var(--color-surface-alt);
    border-bottom: 1px solid var(--color-border);
    font-weight: 500;
    font-size: var(--text-sm);
}

.gantt-hour-header {
    padding: 8px 4px;
    text-align: center;
    background: var(--color-surface-alt);
    border-bottom: 1px solid var(--color-border);
    border-left: 1px solid var(--color-border-light);
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

.gantt-area-cell {
    width: 150px;
    min-width: 150px;
    padding: 8px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    font-size: var(--text-sm);
}

.gantt-timeline-cell {
    padding: 0;
    border-bottom: 1px solid var(--color-border);
    height: 50px;
}

.gantt-timeline {
    position: relative;
    height: 100%;
    background: repeating-linear-gradient(
        90deg,
        transparent,
        transparent calc(100% / 16 - 1px),
        var(--color-border-light) calc(100% / 16 - 1px),
        var(--color-border-light) calc(100% / 16)
    );
}

.gantt-bar {
    position: absolute;
    top: 8px;
    height: calc(100% - 16px);
    border-radius: var(--radius-sm);
    padding: 2px 6px;
    color: white;
    text-decoration: none;
    overflow: hidden;
    white-space: nowrap;
    font-size: var(--text-xs);
    display: flex;
    align-items: center;
    transition: opacity 0.2s;
}

.gantt-bar:hover {
    opacity: 0.85;
    color: white;
}

.gantt-bar-text {
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
