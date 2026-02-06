<?php
/**
 * 予約カレンダー表示
 */
$pageTitle = '予約カレンダー';

// フィルタ済みの店舗IDを取得
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

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
            <a class="nav-link active" href="<?= url('/reservations/calendar') ?>">
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

<!-- 凡例 -->
<div class="mb-3 d-flex gap-3 flex-wrap">
    <span class="badge bg-secondary">保留</span>
    <span class="badge bg-primary">確定</span>
    <span class="badge bg-success">完了</span>
    <span class="badge bg-danger">キャンセル</span>
</div>

<!-- カレンダー -->
<div class="card">
    <div class="card-body">
        <div id="calendar"></div>
    </div>
</div>

<!-- FullCalendar -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/locales/ja.global.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    const basePath = '<?= defined("BASE_PATH") ? BASE_PATH : "" ?>';

    const calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'ja',
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        height: 'auto',
        events: {
            url: basePath + '/api/reservations/calendar-data',
            failure: function() {
                alert('予約データの取得に失敗しました');
            }
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) {
                window.location.href = info.event.url;
            }
        },
        eventDidMount: function(info) {
            // ツールチップ
            const props = info.event.extendedProps;
            info.el.title = `${info.event.title}\n${props.store} - ${props.area}`;
        }
    });

    calendar.render();
});
</script>

<style>
#calendar {
    max-width: 100%;
}
.fc-event {
    cursor: pointer;
}
.fc-event-title {
    font-size: 0.85em;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
