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

// ビュー切替（テーブル / タイムライン）
$currentView = input('view', 'table');
if (!in_array($currentView, ['table', 'timeline'], true)) {
    $currentView = 'table';
}

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
            TIME(j.scheduled_end_at) as job_end_time,
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

// ビュー切替URL用パラメータ
$viewParams = ['date_from' => $filterDateFrom, 'date_to' => $filterDateTo];
if ($filterStatus !== '') {
    $viewParams['status'] = $filterStatus;
}
if ($filterCleanerId > 0) {
    $viewParams['cleaner_id'] = $filterCleanerId;
}
$tableUrl = url('/shifts') . '?' . http_build_query(array_merge($viewParams, ['view' => 'table']));
$timelineUrl = url('/shifts') . '?' . http_build_query(array_merge($viewParams, ['view' => 'timeline']));

// タイムライン用JSONデータ構築（フラット配列 + 日付リスト）
if ($currentView === 'timeline') {
    $tlAllJobs = [];
    foreach ($jobsByDate as $date => $dayJobs) {
        $dateLabel = formatDate($date, 'n/j') . ' ' . getDayName((int)date('w', strtotime($date)));
        foreach ($dayJobs as $job) {
            $tlAllJobs[] = [
                'id' => (int) $job['id'],
                'status' => $job['status'],
                'is_urgent' => (bool) ($job['is_urgent'] ?? false),
                'job_time' => $job['job_time'],
                'job_end_time' => $job['job_end_time'] ?? null,
                'duration_minutes' => (int) ($job['duration_minutes'] ?? 60),
                'area_name' => $job['area_name'] ?? '',
                'store_name' => $job['store_name'] ?? '',
                'cleaner_name' => $job['cleaner_name'] ?? '',
                'base_reward' => (int) ($job['base_reward'] ?? 0),
                'detail_url' => url('/jobs/' . $job['id']),
                'job_date' => $date,
                'date_label' => $dateLabel,
            ];
        }
    }
    $tlDates = [];
    foreach ($jobsByDate as $date => $dayJobs) {
        $tlDates[] = [
            'date' => $date,
            'label' => formatDate($date, 'n/j') . ' ' . getDayName((int)date('w', strtotime($date))),
        ];
    }
}

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
            <input type="hidden" name="view" value="<?= h($currentView) ?>">
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

<!-- ビュー切替タブ -->
<div class="tl-view-toggle">
    <div class="period-tabs">
        <a href="<?= h($tableUrl) ?>" class="<?= $currentView === 'table' ? 'active' : '' ?>">
            <i class="bi bi-table"></i> テーブル
        </a>
        <a href="<?= h($timelineUrl) ?>" class="<?= $currentView === 'timeline' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-steps"></i> タイムライン
        </a>
    </div>
    <?php if ($currentView === 'timeline'): ?>
    <div class="tl-group-toggle" id="tl-group-toggle">
        <button type="button" class="tl-group-btn active" data-group="date">日付</button>
        <button type="button" class="tl-group-btn" data-group="area">営業区分</button>
        <button type="button" class="tl-group-btn" data-group="cleaner">清掃者</button>
    </div>
    <?php endif; ?>
</div>

<?php if ($currentView === 'table'): ?>
<!-- シフト一覧（日付別テーブル） -->
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

<?php else: ?>
<!-- シフト一覧（タイムライン表示） -->
<?php
$tlJobsJson = json_encode($tlAllJobs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($tlJobsJson === false) { $tlJobsJson = '[]'; }
$tlDatesJson = json_encode($tlDates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($tlDatesJson === false) { $tlDatesJson = '[]'; }
$tlTodayJson = json_encode(date('Y-m-d'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<script>
window.__TL_JOBS__ = <?= $tlJobsJson ?>;
window.__TL_DATES__ = <?= $tlDatesJson ?>;
window.__TL_TODAY__ = <?= $tlTodayJson ?>;
</script>

<div class="card mb-3">
    <div class="card-header bg-light">
        <strong>タイムライン</strong>
        <span class="badge bg-secondary ms-2"><?= count($jobs) ?>件</span>
        <span class="text-muted ms-2"><?= h(formatDate($filterDateFrom, 'n/j')) ?> 〜 <?= h(formatDate($filterDateTo, 'n/j')) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="tl-scroll-hint"><i class="bi bi-arrows-expand"></i> 横スクロールで全時間帯を確認できます</div>
        <div class="tl-scroll-wrapper">
            <div class="tl-scroll-inner">
                <div id="tl-container"></div>
            </div>
        </div>
    </div>
</div>

<!-- タイムライン ツールチップ -->
<div id="tl-tooltip" class="tl-tooltip"></div>

<!-- タイムライン描画スクリプト -->
<script>
(function() {
    'use strict';

    var HOUR_START = 6;
    var HOUR_END = 26;
    var LANE_HEIGHT = 36;
    var BAR_TOP_PAD = 4;

    var STATUS_CFG = {
        unassigned:  { icon: 'bi-dash-circle',       label: '未割当' },
        recruiting:  { icon: 'bi-megaphone',          label: '募集中' },
        assigned:    { icon: 'bi-check-circle',       label: '確定' },
        completed:   { icon: 'bi-check-circle-fill',  label: '完了' },
        paid:        { icon: 'bi-check-circle-fill',  label: '支払済' },
        cancelled:   { icon: 'bi-x-circle',           label: 'キャンセル' }
    };

    var allJobs = window.__TL_JOBS__ || [];
    var tlDates = window.__TL_DATES__ || [];
    var today = window.__TL_TODAY__ || '';
    var container = document.getElementById('tl-container');
    var _escDiv = document.createElement('div');

    var params = new URLSearchParams(location.search);
    var currentGroupBy = params.get('group') || 'date';
    if (currentGroupBy !== 'date' && currentGroupBy !== 'area' && currentGroupBy !== 'cleaner') currentGroupBy = 'date';

    // グループボタンのactive状態を復元
    var groupBtns = document.querySelectorAll('.tl-group-btn');
    groupBtns.forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.group === currentGroupBy);
    });

    function renderAll() {
        if (!container) return;
        container.innerHTML = '';
        if (allJobs.length === 0) {
            container.innerHTML = '<div class="tl-empty">該当する案件はありません</div>';
            return;
        }
        var rows = buildRows(allJobs, currentGroupBy);
        var frag = document.createDocumentFragment();
        frag.appendChild(createHeader());
        for (var i = 0; i < rows.length; i++) {
            frag.appendChild(createRow(rows[i]));
        }
        container.appendChild(frag);
    }
    renderAll();

    // グルーピング切替
    groupBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (currentGroupBy === this.dataset.group) return;
            currentGroupBy = this.dataset.group;
            groupBtns.forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            var u = new URL(location.href);
            u.searchParams.set('group', currentGroupBy);
            history.replaceState(null, '', u);
            renderAll();
        });
    });

    // ツールチップ（イベント委譲）
    var tip = document.getElementById('tl-tooltip');
    document.addEventListener('mouseover', function(e) {
        var bar = e.target.closest('.tl-bar');
        if (!bar || !tip) return;
        var d = bar.dataset;
        tip.innerHTML =
            '<div class="tl-tooltip-row"><span class="tl-tooltip-label">日付</span><span class="tl-tooltip-value">' + esc(d.dateLabel || '') + '</span></div>' +
            '<div class="tl-tooltip-row"><span class="tl-tooltip-label">営業区分</span><span class="tl-tooltip-value">' + esc(d.area) + '</span></div>' +
            '<div class="tl-tooltip-row"><span class="tl-tooltip-label">時間</span><span class="tl-tooltip-value">' + esc(d.timeRange) + '</span></div>' +
            '<div class="tl-tooltip-row"><span class="tl-tooltip-label">ステータス</span><span class="tl-tooltip-value">' + esc(d.statusLabel) + '</span></div>' +
            '<div class="tl-tooltip-row"><span class="tl-tooltip-label">担当者</span><span class="tl-tooltip-value">' + esc(d.cleaner || '-') + '</span></div>' +
            '<div class="tl-tooltip-row"><span class="tl-tooltip-label">報酬</span><span class="tl-tooltip-value">' + (Number(d.reward) || 0).toLocaleString() + '円</span></div>';
        tip.classList.add('visible');
        var rect = bar.getBoundingClientRect();
        var top = rect.bottom + 8;
        var left = rect.left;
        if (top + 180 > window.innerHeight) top = rect.top - 180;
        if (left + 280 > window.innerWidth) left = window.innerWidth - 288;
        tip.style.top = Math.max(8, top) + 'px';
        tip.style.left = Math.max(8, left) + 'px';
    });
    document.addEventListener('mouseout', function(e) {
        var bar = e.target.closest('.tl-bar');
        if (!bar || !tip) return;
        var related = e.relatedTarget;
        if (related && bar.contains(related)) return;
        tip.classList.remove('visible');
    });

    // バークリック → 詳細遷移
    document.addEventListener('click', function(e) {
        var bar = e.target.closest('.tl-bar');
        if (bar && bar.dataset.href) location.href = bar.dataset.href;
    });

    // === グルーピング ===
    function buildRows(jobs, by) {
        if (by === 'date') {
            // 日付ごと（空の日も行として表示）
            var map = {};
            tlDates.forEach(function(d) { map[d.date] = { label: d.label, date: d.date, jobs: [] }; });
            jobs.forEach(function(j) { if (map[j.job_date]) map[j.job_date].jobs.push(j); });
            return tlDates.map(function(d) { return map[d.date]; });
        }
        // 営業区分 or 清掃者 → エンティティ×日付の入れ子行
        var entities = {};
        jobs.forEach(function(j) {
            var entity = by === 'area' ? (j.area_name || '(不明)') : (j.cleaner_name || '(未割当)');
            if (!entities[entity]) entities[entity] = {};
            if (!entities[entity][j.job_date]) entities[entity][j.job_date] = { dateLabel: j.date_label, jobs: [] };
            entities[entity][j.job_date].jobs.push(j);
        });
        var rows = [];
        Object.keys(entities).sort(function(a, b) { return a.localeCompare(b, 'ja'); }).forEach(function(entity) {
            var dates = entities[entity];
            Object.keys(dates).sort().forEach(function(d) {
                rows.push({
                    label: entity + '  ' + dates[d].dateLabel,
                    date: d,
                    jobs: dates[d].jobs
                });
            });
        });
        return rows;
    }

    // === 行描画 ===
    function createRow(rowData) {
        var lanes = rowData.jobs.length > 0 ? computeLanes(rowData.jobs) : [[]];
        var laneCount = Math.max(1, lanes.length);

        var row = document.createElement('div');
        row.className = 'tl-row';
        row.style.height = (laneCount * LANE_HEIGHT) + 'px';

        var labelEl = document.createElement('div');
        labelEl.className = 'tl-label';
        labelEl.textContent = rowData.label;
        row.appendChild(labelEl);

        var track = document.createElement('div');
        track.className = 'tl-track';
        track.style.height = (laneCount * LANE_HEIGHT) + 'px';
        addGridLines(track);

        for (var li = 0; li < lanes.length; li++) {
            for (var ji = 0; ji < lanes[li].length; ji++) {
                var bar = createBar(lanes[li][ji], li);
                if (bar) track.appendChild(bar);
            }
        }

        if (rowData.date === today) addNowLine(track);
        row.appendChild(track);
        return row;
    }

    // === ユーティリティ ===
    function timeToMin(t) {
        if (!t) return null;
        var p = t.split(':');
        var h = parseInt(p[0], 10);
        var m = parseInt(p[1], 10);
        if (h < HOUR_START) h += 24;
        return h * 60 + m;
    }

    function minToPct(min) {
        return ((min - HOUR_START * 60) / ((HOUR_END - HOUR_START) * 60)) * 100;
    }

    function computeLanes(jobs) {
        var sorted = jobs.slice().sort(function(a, b) {
            return (timeToMin(a.job_time) || 0) - (timeToMin(b.job_time) || 0);
        });
        var lanes = [];
        for (var i = 0; i < sorted.length; i++) {
            var job = sorted[i];
            var s = timeToMin(job.job_time) || HOUR_START * 60;
            var e = job.job_end_time ? timeToMin(job.job_end_time) : s + (job.duration_minutes || 60);
            if (e <= s) e += 24 * 60;
            var placed = false;
            for (var l = 0; l < lanes.length; l++) {
                if (s >= lanes[l].end) {
                    lanes[l].items.push(job);
                    lanes[l].end = e;
                    placed = true;
                    break;
                }
            }
            if (!placed) lanes.push({ items: [job], end: e });
        }
        return lanes.map(function(l) { return l.items; });
    }

    function createHeader() {
        var header = document.createElement('div');
        header.className = 'tl-header';
        var lbl = document.createElement('div');
        lbl.className = 'tl-header-label';
        header.appendChild(lbl);
        var axis = document.createElement('div');
        axis.className = 'tl-header-axis';
        for (var h = HOUR_START; h <= HOUR_END; h++) {
            var pct = minToPct(h * 60);
            var tick = document.createElement('div');
            tick.className = 'tl-header-tick';
            tick.style.left = pct + '%';
            var dh = h >= 24 ? h - 24 : h;
            tick.textContent = dh + ':00';
            axis.appendChild(tick);
            if (h < HOUR_END) {
                var mt = document.createElement('div');
                mt.className = 'tl-header-tick minor';
                mt.style.left = minToPct(h * 60 + 30) + '%';
                axis.appendChild(mt);
            }
        }
        header.appendChild(axis);
        return header;
    }

    function addGridLines(track) {
        var frag = document.createDocumentFragment();
        for (var h = HOUR_START; h <= HOUR_END; h++) {
            for (var m = 0; m < 60; m += 15) {
                if (h === HOUR_END && m > 0) break;
                var line = document.createElement('div');
                line.className = 'tl-grid-line' + (m === 15 || m === 45 ? ' minor' : '');
                line.style.left = minToPct(h * 60 + m) + '%';
                frag.appendChild(line);
            }
        }
        track.appendChild(frag);
    }

    function createBar(job, laneIdx) {
        var s = timeToMin(job.job_time);
        if (s === null) return null;
        var e = job.job_end_time ? timeToMin(job.job_end_time) : s + (job.duration_minutes || 60);
        if (e <= s) e += 24 * 60;
        var sMin = HOUR_START * 60, eMin = HOUR_END * 60;
        if (e <= sMin || s >= eMin) return null;
        var cs = Math.max(s, sMin), ce = Math.min(e, eMin);

        var bar = document.createElement('div');
        bar.className = 'tl-bar' + (job.is_urgent ? ' urgent' : '');
        bar.style.left = minToPct(cs) + '%';
        bar.style.width = Math.max(minToPct(ce) - minToPct(cs), 0.8) + '%';
        bar.style.top = (laneIdx * LANE_HEIGHT + BAR_TOP_PAD) + 'px';

        bar.dataset.jobId = job.id;
        bar.dataset.status = job.status;
        bar.dataset.area = job.area_name;
        bar.dataset.cleaner = job.cleaner_name || '';
        bar.dataset.reward = job.base_reward;
        bar.dataset.statusLabel = (STATUS_CFG[job.status] || {}).label || job.status;
        bar.dataset.href = job.detail_url;
        bar.dataset.dateLabel = job.date_label || '';

        var sh = Math.floor(s / 60), sm = s % 60;
        var eh = Math.floor(e / 60), em = e % 60;
        bar.dataset.timeRange = pad2(sh >= 24 ? sh - 24 : sh) + ':' + pad2(sm) + ' - ' + pad2(eh >= 24 ? eh - 24 : eh) + ':' + pad2(em);

        var cfg = STATUS_CFG[job.status] || { icon: 'bi-circle' };
        var barLabel;
        if (currentGroupBy === 'date') {
            barLabel = (job.area_name || '') + (job.cleaner_name ? ' ' + job.cleaner_name : '');
        } else if (currentGroupBy === 'area') {
            barLabel = job.cleaner_name || '';
        } else {
            barLabel = job.area_name || '';
        }
        bar.innerHTML = '<i class="bi ' + cfg.icon + '"></i> ' + esc(barLabel);
        return bar;
    }

    function addNowLine(track) {
        var now = new Date();
        var h = now.getHours(), m = now.getMinutes();
        if (h < HOUR_START) h += 24;
        var min = h * 60 + m;
        if (min < HOUR_START * 60 || min > HOUR_END * 60) return;
        var line = document.createElement('div');
        line.className = 'tl-now-line';
        line.style.left = minToPct(min) + '%';
        track.appendChild(line);
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function esc(s) {
        _escDiv.textContent = s || '';
        return _escDiv.innerHTML;
    }
})();
</script>
<?php endif; ?>

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
