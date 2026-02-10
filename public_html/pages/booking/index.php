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

        <!-- 日時選択 -->
        <div class="booking-card mb-4">
            <div class="booking-card-header">
                <h5>日時を選択</h5>
            </div>
            <div class="booking-card-body">
                <div class="mb-4">
                    <label class="form-label fw-bold">ご利用日 <span class="text-danger">*</span></label>
                    <input type="date" name="reservation_date" class="form-control form-control-lg"
                           id="reservationDate" required
                           min="<?= date('Y-m-d') ?>"
                           max="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>

                <div class="time-select-grid">
                    <div>
                        <label class="form-label fw-bold">開始時間 <span class="text-danger">*</span></label>
                        <select name="start_time" class="form-select form-select-lg" required id="startTime">
                            <option value="">--:--</option>
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <?php for ($m = 0; $m < 60; $m += 30): ?>
                                    <option value="<?= sprintf('%02d:%02d', $h, $m) ?>">
                                        <?= sprintf('%02d:%02d', $h, $m) ?>
                                    </option>
                                <?php endfor; ?>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-bold">終了時間 <span class="text-danger">*</span></label>
                        <select name="end_time" class="form-select form-select-lg" required id="endTime">
                            <option value="">--:--</option>
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <?php for ($m = 0; $m < 60; $m += 30): ?>
                                    <?php if ($h === 0 && $m === 0) continue; // 00:00は24:00として最後に追加 ?>
                                    <option value="<?= sprintf('%02d:%02d', $h, $m) ?>">
                                        <?= sprintf('%02d:%02d', $h, $m) ?>
                                    </option>
                                <?php endfor; ?>
                            <?php endfor; ?>
                            <!-- 24:00（=00:00）を最後に追加 -->
                            <option value="00:00">24:00</option>
                        </select>
                    </div>
                </div>

                <!-- 空き状況表示エリア -->
                <div id="availabilityStatus" style="display: none;">
                    <div class="availability-alert" id="availabilityAlert">
                        <i class="bi" id="availabilityIcon"></i>
                        <span id="availabilityMessage"></span>
                    </div>
                </div>
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
    const roomCards = document.querySelectorAll('.room-card');
    const salesAreaInput = document.getElementById('salesAreaInput');
    const reservationDate = document.getElementById('reservationDate');
    const startTime = document.getElementById('startTime');
    const endTime = document.getElementById('endTime');
    const submitBtn = document.getElementById('submitBtn');
    const availabilityStatus = document.getElementById('availabilityStatus');
    const availabilityAlert = document.getElementById('availabilityAlert');
    const availabilityIcon = document.getElementById('availabilityIcon');
    const availabilityMessage = document.getElementById('availabilityMessage');

    // LIFF初期化（LIFF SDKが読み込まれている場合）
    if (typeof liff !== 'undefined' && window.LIFF_ID) {
        liff.init({ liffId: window.LIFF_ID })
            .then(() => {
                if (liff.isInClient()) {
                    // LINEアプリ内で開かれている場合
                    document.getElementById('sourceInput').value = 'line';

                    // プロフィール取得
                    if (liff.isLoggedIn()) {
                        liff.getProfile()
                            .then(profile => {
                                document.getElementById('lineUserIdInput').value = profile.userId;
                                document.getElementById('lineDisplayNameInput').value = profile.displayName;
                            })
                            .catch(err => {
                                console.error('Failed to get LINE profile:', err);
                            });
                    }
                }
            })
            .catch(err => {
                console.error('LIFF init failed:', err);
            });
    }

    // 現在選択中の営業時間情報
    let currentBusinessHours = {
        openingTime: '10:00',
        closingTime: '00:00',
        is24h: false
    };

    // 現在選択中の店舗設定
    let currentStoreSettings = {
        minDuration: <?= MIN_BOOKING_DURATION_HOURS ?>,
        maxDuration: <?= MAX_BOOKING_DURATION_HOURS ?>,
        bookingDaysAhead: 30
    };

    // カード選択処理
    roomCards.forEach(card => {
        card.addEventListener('click', function() {
            // 全カードの選択を解除
            roomCards.forEach(c => c.classList.remove('selected'));
            // クリックしたカードを選択
            this.classList.add('selected');
            // hidden inputに値を設定
            salesAreaInput.value = this.dataset.areaId;
            // ラジオボタンもチェック
            this.querySelector('input[type="radio"]').checked = true;

            // 営業時間情報を更新
            currentBusinessHours = {
                openingTime: this.dataset.openingTime || '10:00',
                closingTime: this.dataset.closingTime || '00:00',
                is24h: this.dataset.is24h === '1'
            };

            // 店舗設定を更新
            currentStoreSettings = {
                minDuration: parseInt(this.dataset.minDuration) || <?= MIN_BOOKING_DURATION_HOURS ?>,
                maxDuration: parseInt(this.dataset.maxDuration) || <?= MAX_BOOKING_DURATION_HOURS ?>,
                bookingDaysAhead: parseInt(this.dataset.bookingDaysAhead) || 30
            };

            // 予約日の最大値を店舗設定に合わせて更新（タイムゾーン考慮）
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + currentStoreSettings.bookingDaysAhead);
            const y = maxDate.getFullYear();
            const m = String(maxDate.getMonth() + 1).padStart(2, '0');
            const d = String(maxDate.getDate()).padStart(2, '0');
            reservationDate.max = `${y}-${m}-${d}`;

            // 時間選択肢を営業時間に基づいてフィルタリング
            updateTimeOptions();

            // 空き状況チェック
            checkAvailability();
        });
    });

    // 時間選択肢を営業時間に基づいて更新
    function updateTimeOptions() {
        const { openingTime, closingTime, is24h } = currentBusinessHours;

        // 24時間営業の場合は全て有効
        if (is24h) {
            enableAllTimeOptions();
            return;
        }

        const openingMinutes = timeToMinutes(openingTime);
        // 00:00は24:00（1440分）として扱う
        const closingMinutes = (closingTime === '00:00') ? 24 * 60 : timeToMinutes(closingTime);
        const isOvernight = closingMinutes < openingMinutes;

        // 開始時間の選択肢を更新
        Array.from(startTime.options).forEach(option => {
            if (!option.value) return; // 空の選択肢はスキップ
            const optionMinutes = timeToMinutes(option.value);

            if (isOvernight) {
                // 深夜営業: 開始時刻以降 OR 終了時刻より前
                option.disabled = !(optionMinutes >= openingMinutes || optionMinutes < closingMinutes);
            } else {
                // 通常営業: 開始〜終了-30分の範囲
                option.disabled = optionMinutes < openingMinutes || optionMinutes >= closingMinutes;
            }
        });

        // 終了時間の選択肢を更新
        Array.from(endTime.options).forEach(option => {
            if (!option.value) return;
            const optionMinutes = (option.value === '00:00') ? 24 * 60 : timeToMinutes(option.value);

            if (isOvernight) {
                // 深夜営業
                option.disabled = !(optionMinutes > openingMinutes || optionMinutes <= closingMinutes);
            } else {
                // 通常営業
                option.disabled = optionMinutes <= openingMinutes || optionMinutes > closingMinutes;
            }
        });

        // 選択中の値が無効になった場合はリセット
        if (startTime.selectedOptions[0]?.disabled) {
            startTime.value = '';
        }
        if (endTime.selectedOptions[0]?.disabled) {
            endTime.value = '';
        }
    }

    function enableAllTimeOptions() {
        Array.from(startTime.options).forEach(option => option.disabled = false);
        Array.from(endTime.options).forEach(option => option.disabled = false);
    }

    function timeToMinutes(timeStr) {
        const [hours, minutes] = timeStr.split(':').map(Number);
        return hours * 60 + minutes;
    }

    function checkAvailability() {
        const areaId = salesAreaInput.value;
        const date = reservationDate.value;
        const start = startTime.value;
        const end = endTime.value;

        if (!areaId || !date || !start || !end) {
            submitBtn.disabled = true;
            availabilityStatus.style.display = 'none';
            return;
        }

        // 時間の妥当性チェック（深夜またぎ対応）
        const startMinutes = timeToMinutes(start);
        let endMinutes = (end === '00:00') ? 24 * 60 : timeToMinutes(end);

        // 終了時間が開始時間より小さい場合は翌日扱い（例: 22:00→02:00）
        if (endMinutes <= startMinutes && end !== '00:00') {
            endMinutes += 24 * 60;
        }

        // 予約時間の計算
        const durationMinutes = endMinutes - startMinutes;

        // 店舗設定に基づく利用時間制限
        const minMinutes = currentStoreSettings.minDuration * 60;
        const maxMinutes = currentStoreSettings.maxDuration * 60;
        if (durationMinutes < minMinutes || durationMinutes > maxMinutes) {
            availabilityStatus.style.display = 'block';
            availabilityAlert.className = 'availability-alert error';
            availabilityIcon.className = 'bi bi-exclamation-circle';
            availabilityMessage.textContent = '予約は' + currentStoreSettings.minDuration + '〜' + currentStoreSettings.maxDuration + '時間の範囲で選択してください';
            submitBtn.disabled = true;
            return;
        }

        // 空き状況チェックAPI呼び出し
        fetch(<?= json_encode(url('/api/booking/check-availability'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                sales_area_id: areaId,
                reservation_date: date,
                start_time: start,
                end_time: end
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            availabilityStatus.style.display = 'block';
            if (data.available) {
                availabilityAlert.className = 'availability-alert available';
                availabilityIcon.className = 'bi bi-check-circle-fill';
                availabilityMessage.textContent = '予約可能です';
                submitBtn.disabled = false;
            } else {
                availabilityAlert.className = 'availability-alert unavailable';
                availabilityIcon.className = 'bi bi-exclamation-triangle';
                availabilityMessage.textContent = data.message || '選択された時間帯は予約できません';
                submitBtn.disabled = true;
            }
        })
        .catch(error => {
            availabilityStatus.style.display = 'block';
            availabilityAlert.className = 'availability-alert error';
            availabilityIcon.className = 'bi bi-x-circle';
            availabilityMessage.textContent = '空き状況の確認に失敗しました';
            submitBtn.disabled = true;
        });
    }

    reservationDate.addEventListener('change', checkAvailability);
    startTime.addEventListener('change', checkAvailability);
    endTime.addEventListener('change', checkAvailability);
});
</script>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
