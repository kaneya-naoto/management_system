<?php
/**
 * 公開予約フォーム - 日時選択
 */
$pageTitle = '予約フォーム';
$isPublicPage = true; // 認証不要マーカー

// 料金はsales_areasテーブルから動的取得

// セッション固定化攻撃対策: 予約フロー開始時にセッションIDを再生成
if (empty($_SESSION['booking_session_initialized'])) {
    session_regenerate_id(true);
    $_SESSION['booking_session_initialized'] = true;
}

// 店舗コード対応（$storeCodeはルーターから設定される）
$storeCode = $storeCode ?? null;
$targetStore = null;

if ($storeCode) {
    // 店舗コードから店舗情報取得
    $targetStore = dbSelectOne(
        "SELECT * FROM stores WHERE code = ? AND is_active = 1 AND deleted_at IS NULL",
        [$storeCode]
    );
    if (!$targetStore) {
        http_response_code(404);
        $pageTitle = '店舗が見つかりません';
        require __DIR__ . '/../../includes/public_header.php';
        echo '<div class="container py-5 text-center">';
        echo '<h1 class="display-4">404</h1>';
        echo '<p class="lead">指定された店舗が見つかりませんでした</p>';
        echo '</div>';
        require __DIR__ . '/../../includes/public_footer.php';
        exit;
    }
    $_SESSION['booking_store_code'] = $storeCode;
} else {
    // 店舗コードなしの場合はログイン必須
    if (!isLoggedIn()) {
        http_response_code(403);
        $pageTitle = 'アクセスできません';
        require __DIR__ . '/../../includes/public_header.php';
        echo '<div class="container py-5 text-center">';
        echo '<h1 class="display-4">403</h1>';
        echo '<p class="lead">予約フォームへは店舗専用URLからアクセスしてください</p>';
        echo '<p class="text-muted">管理者の方は<a href="' . url('/login') . '">ログイン</a>してください</p>';
        echo '</div>';
        require __DIR__ . '/../../includes/public_footer.php';
        exit;
    }
}

// 営業区分取得（店舗指定時はその店舗のみ、営業時間情報も含む）
if ($targetStore) {
    $salesAreas = dbSelect(
        "SELECT sa.*, s.name as store_name,
                s.opening_time, s.closing_time, s.is_24h_open
         FROM sales_areas sa
         INNER JOIN stores s ON sa.store_id = s.id
         WHERE sa.store_id = ? AND sa.is_active = 1 AND sa.deleted_at IS NULL
           AND s.is_active = 1 AND s.deleted_at IS NULL
         ORDER BY sa.name",
        [$targetStore['id']]
    );
} else {
    // ログイン済みの場合はアクセス可能な店舗のみに絞る
    $user = currentUser();
    if ($user) {
        $filteredStores = getFilteredStoreIds();
        $accessibleStoreIds = array_column($filteredStores['stores'], 'id');

        if (!empty($accessibleStoreIds)) {
            $placeholders = implode(',', array_fill(0, count($accessibleStoreIds), '?'));
            $salesAreas = dbSelect(
                "SELECT sa.*, s.name as store_name,
                        s.opening_time, s.closing_time, s.is_24h_open
                 FROM sales_areas sa
                 INNER JOIN stores s ON sa.store_id = s.id
                 WHERE sa.store_id IN ({$placeholders})
                   AND sa.is_active = 1 AND sa.deleted_at IS NULL
                   AND s.is_active = 1 AND s.deleted_at IS NULL
                 ORDER BY s.name, sa.name",
                $accessibleStoreIds
            );
        } else {
            $salesAreas = [];
        }
    } else {
        // 未ログインで店舗コードなし → 403表示済み（ここには到達しない）
        $salesAreas = [];
    }
}

if (empty($salesAreas)) {
    $errorMessage = '現在予約を受け付けておりません';
}

// CSRFトークン生成
$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/public_header.php';
?>

<?php $bookingBasePath = $storeCode ? "/booking/{$storeCode}" : '/booking'; ?>
<div class="container py-5 booking-container">
    <h1 class="h2 mb-4 text-center"><?= $targetStore ? h($targetStore['name']) . ' ' : '' ?>予約フォーム</h1>

    <?php if (!empty($errorMessage)): ?>
    <div class="alert alert-warning text-center">
        <?= h($errorMessage) ?>
    </div>
    <?php else: ?>

    <!-- ステップインジケーター（モダン版） -->
    <div class="booking-steps">
        <div class="booking-step active">
            <span class="booking-step-number">1</span>
            <span class="booking-step-label">日時選択</span>
        </div>
        <div class="booking-step-connector"></div>
        <div class="booking-step">
            <span class="booking-step-number">2</span>
            <span class="booking-step-label">お客様情報</span>
        </div>
        <div class="booking-step-connector"></div>
        <div class="booking-step">
            <span class="booking-step-number">3</span>
            <span class="booking-step-label">確認・決済</span>
        </div>
        <div class="booking-step-connector"></div>
        <div class="booking-step">
            <span class="booking-step-number">4</span>
            <span class="booking-step-label">完了</span>
        </div>
    </div>

    <form action="<?= url($bookingBasePath . '/customer') ?>" method="post" id="dateTimeForm" class="booking-form">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
        <input type="hidden" name="sales_area_id" id="salesAreaInput" value="">
        <!-- LINE LIFF用hidden fields -->
        <input type="hidden" name="source" id="sourceInput" value="web">
        <input type="hidden" name="line_user_id" id="lineUserIdInput" value="">
        <input type="hidden" name="line_display_name" id="lineDisplayNameInput" value="">

        <!-- 部屋選択（カード形式） -->
        <div class="booking-card mb-4">
            <div class="booking-card-header">
                <h5>部屋タイプを選択</h5>
            </div>
            <div class="booking-card-body">
                <div class="room-cards" id="roomCards">
                    <?php foreach ($salesAreas as $area): ?>
                    <?php
                        $openingTime = substr($area['opening_time'] ?? '10:00:00', 0, 5);
                        $closingTime = substr($area['closing_time'] ?? '00:00:00', 0, 5);
                        $is24hOpen = (bool)($area['is_24h_open'] ?? false);
                        $hourlyRate = (int)($area['hourly_rate'] ?? 2500);
                        $capacity = (int)($area['capacity'] ?? 4);
                        $description = $area['description'] ?? '快適なプライベート空間をご用意しております。';
                        $storeSettings = getStoreSettings((int)$area['store_id']);
                    ?>
                    <label class="room-card" data-area-id="<?= $area['id'] ?>" data-store-id="<?= $area['store_id'] ?>"
                           data-opening-time="<?= h($openingTime) ?>"
                           data-closing-time="<?= h($closingTime) ?>"
                           data-is-24h="<?= $is24hOpen ? '1' : '0' ?>"
                           data-hourly-rate="<?= $hourlyRate ?>"
                           data-min-duration="<?= $storeSettings['min_duration_hours'] ?>"
                           data-max-duration="<?= $storeSettings['max_duration_hours'] ?>"
                           data-booking-days-ahead="<?= $storeSettings['booking_days_ahead'] ?>">
                        <input type="radio" name="sales_area_radio" value="<?= $area['id'] ?>">
                        <div class="room-card-image">
                            <i class="bi bi-door-open"></i>
                        </div>
                        <div class="room-card-body">
                            <div class="room-card-title"><?= h($area['name']) ?></div>
                            <?php if (!$targetStore): ?>
                            <div class="room-card-store"><?= h($area['store_name']) ?></div>
                            <?php endif; ?>
                            <div class="room-card-description">
                                <?= h($description) ?>
                            </div>
                            <div class="room-card-info">
                                <span><i class="bi bi-people"></i> 1〜<?= $capacity ?>名</span>
                                <span><i class="bi bi-clock"></i>
                                    <?php if ($is24hOpen): ?>
                                        24時間営業
                                    <?php else: ?>
                                        <?= h($openingTime) ?>〜<?= h($closingTime === '00:00' ? '24:00' : $closingTime) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="room-card-price">
                                <?= number_format($hourlyRate) ?>円<small>/時間（税込）</small>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Hidden inputs for date/time (set by tile selection JS) -->
        <input type="hidden" name="reservation_date" id="reservationDate" value="">
        <input type="hidden" name="start_time" id="startTime" value="">
        <input type="hidden" name="end_time" id="endTime" value="">

        <!-- 日付選択（ピル形式） -->
        <div class="booking-card mb-4" id="dateSection" style="display: none;">
            <div class="booking-card-header">
                <h5>日付を選択</h5>
            </div>
            <div class="booking-card-body">
                <div class="date-pills-wrapper">
                    <button type="button" class="date-pills-arrow" id="datePillsLeft"><i class="bi bi-chevron-left"></i></button>
                    <div class="date-pills-scroll">
                        <div class="date-pills" id="datePills"></div>
                    </div>
                    <button type="button" class="date-pills-arrow" id="datePillsRight"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <!-- 時間選択（タイルグリッド） -->
        <div class="booking-card mb-4" id="timeSection" style="display: none;">
            <div class="booking-card-header">
                <h5>時間を選択 <span class="booking-card-header-sub" id="selectedDateLabel"></span></h5>
            </div>
            <div class="booking-card-body">
                <div class="slot-legend">
                    <span class="slot-legend-item"><span class="slot-legend-dot dot-available"></span>空き</span>
                    <span class="slot-legend-item"><span class="slot-legend-dot dot-full"></span>満室</span>
                    <span class="slot-legend-item"><span class="slot-legend-dot dot-selected"></span>選択中</span>
                </div>
                <div class="slot-grid-loading" id="slotLoading" style="display: none;">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span>空き状況を取得中...</span>
                </div>
                <div class="slot-error" id="slotError" style="display: none;"></div>
                <div class="slot-grid" id="slotGrid"></div>
                <div class="slot-guide" id="slotGuide" style="display: none;"></div>
                <div class="slot-summary" id="slotSummary" style="display: none;"></div>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn-booking-primary" id="submitBtn" disabled>
                <i class="bi bi-arrow-right-circle me-2"></i>次へ進む
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // === DOM elements ===
    const roomCards = document.querySelectorAll('.room-card');
    const salesAreaInput = document.getElementById('salesAreaInput');
    const reservationDate = document.getElementById('reservationDate');
    const startTimeInput = document.getElementById('startTime');
    const endTimeInput = document.getElementById('endTime');
    const submitBtn = document.getElementById('submitBtn');
    const dateSection = document.getElementById('dateSection');
    const datePills = document.getElementById('datePills');
    const datePillsLeft = document.getElementById('datePillsLeft');
    const datePillsRight = document.getElementById('datePillsRight');
    const datePillsScroll = document.querySelector('.date-pills-scroll');
    const timeSection = document.getElementById('timeSection');
    const selectedDateLabel = document.getElementById('selectedDateLabel');
    const slotGrid = document.getElementById('slotGrid');
    const slotLoading = document.getElementById('slotLoading');
    const slotError = document.getElementById('slotError');
    const slotGuide = document.getElementById('slotGuide');
    const slotSummary = document.getElementById('slotSummary');

    // === State ===
    let currentAreaId = null;
    let currentHourlyRate = 2500;
    let currentMinDuration = <?= MIN_BOOKING_DURATION_HOURS ?>;
    let currentMaxDuration = <?= MAX_BOOKING_DURATION_HOURS ?>;
    let currentBookingDaysAhead = 30;
    let selectedDate = null;
    let slotData = [];
    let selectionStart = null;
    let selectionEnd = null;

    // API URL
    const DAY_AVAILABILITY_URL = <?= json_encode(url('/api/booking/day-availability'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // === LIFF initialization ===
    if (typeof liff !== 'undefined' && window.LIFF_ID) {
        liff.init({ liffId: window.LIFF_ID })
            .then(function() {
                if (liff.isInClient()) {
                    document.getElementById('sourceInput').value = 'line';
                    if (liff.isLoggedIn()) {
                        liff.getProfile()
                            .then(function(profile) {
                                document.getElementById('lineUserIdInput').value = profile.userId;
                                document.getElementById('lineDisplayNameInput').value = profile.displayName;
                            })
                            .catch(function(err) { console.error('Failed to get LINE profile:', err); });
                    }
                }
            })
            .catch(function(err) { console.error('LIFF init failed:', err); });
    }

    // === Room card selection ===
    roomCards.forEach(function(card) {
        card.addEventListener('click', function() {
            roomCards.forEach(function(c) { c.classList.remove('selected'); });
            this.classList.add('selected');
            this.querySelector('input[type="radio"]').checked = true;

            salesAreaInput.value = this.dataset.areaId;
            currentAreaId = this.dataset.areaId;
            currentHourlyRate = parseInt(this.dataset.hourlyRate) || 2500;
            currentMinDuration = parseInt(this.dataset.minDuration) || <?= MIN_BOOKING_DURATION_HOURS ?>;
            currentMaxDuration = parseInt(this.dataset.maxDuration) || <?= MAX_BOOKING_DURATION_HOURS ?>;
            currentBookingDaysAhead = parseInt(this.dataset.bookingDaysAhead) || 30;

            // Reset time selection
            resetTimeSelection();
            timeSection.style.display = 'none';

            // Generate date pills & show
            generateDatePills();
            dateSection.style.display = '';
            dateSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    // === Date pills generation ===
    var dayNames = ['日', '月', '火', '水', '木', '金', '土'];
    var monthNames = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];

    function generateDatePills() {
        datePills.innerHTML = '';
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        for (var i = 0; i <= currentBookingDaysAhead; i++) {
            var d = new Date(today);
            d.setDate(d.getDate() + i);
            var dateStr = formatDate(d);
            var dow = d.getDay();
            var isWeekend = (dow === 0 || dow === 6);

            var pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'date-pill';
            pill.dataset.date = dateStr;
            if (i === 0) pill.classList.add('today');
            if (selectedDate === dateStr) pill.classList.add('selected');

            var wkSpan = document.createElement('span');
            wkSpan.className = 'date-pill-weekday' + (isWeekend ? ' weekend' : '');
            wkSpan.textContent = dayNames[dow];

            var daySpan = document.createElement('span');
            daySpan.className = 'date-pill-day';
            daySpan.textContent = d.getDate();

            var moSpan = document.createElement('span');
            moSpan.className = 'date-pill-month';
            moSpan.textContent = monthNames[d.getMonth()];

            pill.appendChild(wkSpan);
            pill.appendChild(daySpan);
            pill.appendChild(moSpan);

            pill.addEventListener('click', (function(ds, el) {
                return function() { selectDate(ds, el); };
            })(dateStr, pill));

            datePills.appendChild(pill);
        }
    }

    // === Date selection ===
    function selectDate(dateStr, pillElement) {
        datePills.querySelectorAll('.date-pill').forEach(function(p) { p.classList.remove('selected'); });
        pillElement.classList.add('selected');

        selectedDate = dateStr;
        reservationDate.value = dateStr;

        var d = new Date(dateStr + 'T00:00:00');
        selectedDateLabel.textContent = dateStr + '（' + dayNames[d.getDay()] + '）';

        resetTimeSelection();
        fetchDayAvailability(dateStr);
        timeSection.style.display = '';
        timeSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // === Fetch day availability ===
    function fetchDayAvailability(dateStr) {
        slotGrid.innerHTML = '';
        slotLoading.style.display = '';
        slotError.style.display = 'none';
        slotGuide.style.display = 'none';

        var url = DAY_AVAILABILITY_URL + '?sales_area_id=' + encodeURIComponent(currentAreaId) + '&date=' + encodeURIComponent(dateStr);

        fetch(url)
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function(data) {
                slotLoading.style.display = 'none';
                if (!data.slots || data.slots.length === 0) {
                    slotError.style.display = '';
                    slotError.textContent = 'この日は予約を受け付けていません';
                    return;
                }
                slotData = data.slots;
                renderSlotGrid();
                showGuide('info', '開始時間をタップしてください');
            })
            .catch(function(err) {
                slotLoading.style.display = 'none';
                slotError.style.display = '';
                slotError.textContent = '空き状況の取得に失敗しました';
                console.error('Availability fetch error:', err);
            });
    }

    // === Render slot grid ===
    function renderSlotGrid() {
        slotGrid.innerHTML = '';

        slotData.forEach(function(slot, index) {
            var tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'slot-tile';
            tile.dataset.index = index;
            tile.dataset.time = slot.time;

            var statusIcon = slot.status === 'available' ? '○' : (slot.status === 'full' ? '×' : '−');

            var timeSpan = document.createElement('span');
            timeSpan.className = 'slot-tile-time';
            timeSpan.textContent = slot.time;

            var statusSpan = document.createElement('span');
            statusSpan.className = 'slot-tile-status';
            statusSpan.textContent = statusIcon;

            tile.appendChild(timeSpan);
            tile.appendChild(statusSpan);

            if (slot.status === 'available') {
                tile.classList.add('slot-available');
                tile.addEventListener('click', (function(idx) {
                    return function() { handleSlotClick(idx); };
                })(index));
            } else if (slot.status === 'full') {
                tile.classList.add('slot-full');
                tile.disabled = true;
            } else {
                tile.classList.add('slot-past');
                tile.disabled = true;
            }

            slotGrid.appendChild(tile);
        });
    }

    // === Two-tap slot selection ===
    function handleSlotClick(index) {
        if (selectionStart === null || selectionEnd !== null) {
            // First tap or reset after complete selection
            selectionStart = index;
            selectionEnd = null;
            updateSlotHighlights();
            showGuide('info', '終了時間をタップしてください');
            slotSummary.style.display = 'none';
            submitBtn.disabled = true;
            clearHiddenInputs();
            return;
        }

        // Selecting end time
        if (index <= selectionStart) {
            // Tapped before/on start → new start
            selectionStart = index;
            selectionEnd = null;
            updateSlotHighlights();
            showGuide('info', '終了時間をタップしてください');
            slotSummary.style.display = 'none';
            submitBtn.disabled = true;
            clearHiddenInputs();
            return;
        }

        // Validate continuous availability
        for (var i = selectionStart; i <= index; i++) {
            if (slotData[i].status !== 'available') {
                showGuide('warn', '範囲内に予約済みの時間があります。別の時間を選んでください');
                return;
            }
        }

        // Validate duration
        var slotCount = index - selectionStart + 1;
        var durationMinutes = slotCount * 30;
        var minMin = currentMinDuration * 60;
        var maxMin = currentMaxDuration * 60;

        if (durationMinutes < minMin) {
            showGuide('warn', '最低' + currentMinDuration + '時間以上を選択してください');
            return;
        }
        if (durationMinutes > maxMin) {
            showGuide('warn', '最大' + currentMaxDuration + '時間まで選択できます');
            return;
        }

        // Valid selection!
        selectionEnd = index;
        updateSlotHighlights();

        var startStr = slotData[selectionStart].time;
        var endStr = addMinutes(slotData[selectionEnd].time, 30);
        var durationHours = durationMinutes / 60;
        var price = Math.round(currentHourlyRate * durationHours);

        startTimeInput.value = startStr;
        endTimeInput.value = endStr;

        slotGuide.style.display = 'none';
        slotSummary.style.display = '';
        slotSummary.innerHTML =
            '<div class="slot-summary-time">' +
                '<span>' + startStr + '</span>' +
                '<i class="bi bi-arrow-right"></i>' +
                '<span>' + (endStr === '00:00' ? '24:00' : endStr) + '</span>' +
            '</div>' +
            '<div class="slot-summary-detail">' +
                durationHours + '時間 / 約 ' + price.toLocaleString() + '円（税込）' +
            '</div>';

        submitBtn.disabled = false;
        submitBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // === Update slot highlights ===
    function updateSlotHighlights() {
        var tiles = slotGrid.querySelectorAll('.slot-tile');
        var maxSlots = (currentMaxDuration * 60) / 30;

        tiles.forEach(function(tile, i) {
            tile.classList.remove('slot-selected', 'slot-range', 'slot-out-of-range');

            if (selectionStart !== null && selectionEnd === null) {
                // Start only
                if (i === selectionStart) {
                    tile.classList.add('slot-selected');
                } else if (i > selectionStart && slotData[i].status === 'available') {
                    var dist = i - selectionStart;
                    if (dist >= maxSlots) {
                        tile.classList.add('slot-out-of-range');
                    } else {
                        var reachable = true;
                        for (var j = selectionStart + 1; j <= i; j++) {
                            if (slotData[j].status !== 'available') {
                                reachable = false;
                                break;
                            }
                        }
                        if (!reachable) tile.classList.add('slot-out-of-range');
                    }
                }
            } else if (selectionStart !== null && selectionEnd !== null) {
                // Range selected
                if (i >= selectionStart && i <= selectionEnd) {
                    tile.classList.add(
                        (i === selectionStart || i === selectionEnd) ? 'slot-selected' : 'slot-range'
                    );
                }
            }
        });
    }

    // === Helper functions ===
    function resetTimeSelection() {
        selectionStart = null;
        selectionEnd = null;
        slotData = [];
        slotGrid.innerHTML = '';
        slotGuide.style.display = 'none';
        slotSummary.style.display = 'none';
        slotError.style.display = 'none';
        submitBtn.disabled = true;
        clearHiddenInputs();
    }

    function clearHiddenInputs() {
        startTimeInput.value = '';
        endTimeInput.value = '';
    }

    function showGuide(type, message) {
        slotGuide.style.display = '';
        var icon = type === 'warn' ? 'bi-exclamation-circle' : 'bi-info-circle';
        slotGuide.innerHTML = '<i class="bi ' + icon + '"></i> ' + message;
    }

    function formatDate(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function addMinutes(timeStr, minutes) {
        var parts = timeStr.split(':');
        var totalMin = parseInt(parts[0]) * 60 + parseInt(parts[1]) + minutes;
        var h = Math.floor(totalMin / 60) % 24;
        var m = totalMin % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    // === Date pills scroll buttons ===
    datePillsLeft.addEventListener('click', function() {
        datePillsScroll.scrollBy({ left: -200, behavior: 'smooth' });
    });
    datePillsRight.addEventListener('click', function() {
        datePillsScroll.scrollBy({ left: 200, behavior: 'smooth' });
    });

    // === Form submit validation ===
    document.getElementById('dateTimeForm').addEventListener('submit', function(e) {
        if (!reservationDate.value || !startTimeInput.value || !endTimeInput.value) {
            e.preventDefault();
        }
    });
});
</script>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
