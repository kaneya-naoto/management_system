<?php
/**
 * 公開予約フォーム - 顧客情報入力
 */
$pageTitle = '予約フォーム - お客様情報';
$isPublicPage = true;

// 店舗コード対応（$storeCodeはルーターから設定される）
$storeCode = $storeCode ?? $_SESSION['booking_store_code'] ?? null;
$bookingBasePath = $storeCode ? "/booking/{$storeCode}" : '/booking';

// POST or セッション復帰（confirm.phpからのバリデーションエラー戻り対応）
$isReturnFromConfirm = !isPost() && !empty($_SESSION['booking']);
if (!isPost() && !$isReturnFromConfirm) {
    redirect($bookingBasePath);
}
if (isPost()) {
    requireCsrf();
}

// 入力取得（POST時はフォームから、復帰時はセッションから）
if ($isReturnFromConfirm) {
    $salesAreaId = (int)($_SESSION['booking']['sales_area_id'] ?? 0);
    $reservationDate = $_SESSION['booking']['reservation_date'] ?? '';
    $startTime = $_SESSION['booking']['start_time'] ?? '';
    $endTime = $_SESSION['booking']['end_time'] ?? '';
} else {
    $salesAreaId = (int) input('sales_area_id', 0);
    $reservationDate = input('reservation_date', '');
    $startTime = input('start_time', '');
    $endTime = input('end_time', '');
}

// 店舗設定を取得（営業区分のstore_id経由）
$bookingStoreSettings = null;
if ($salesAreaId > 0) {
    $areaStore = dbSelectOne(
        "SELECT store_id FROM sales_areas WHERE id = ? AND deleted_at IS NULL",
        [$salesAreaId]
    );
    if ($areaStore) {
        $bookingStoreSettings = getStoreSettings((int)$areaStore['store_id']);
    }
}
$bookingDaysAhead = $bookingStoreSettings['booking_days_ahead'] ?? 30;
$maxDurationHours = $bookingStoreSettings['max_duration_hours'] ?? MAX_BOOKING_DURATION_HOURS;
$minDurationHours = $bookingStoreSettings['min_duration_hours'] ?? MIN_BOOKING_DURATION_HOURS;

// バリデーション
$errors = [];
if ($salesAreaId <= 0) {
    $errors[] = '店舗を選択してください';
}
if (empty($reservationDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
    $errors[] = '予約日を選択してください';
} else {
    // 日付の実在性チェック（2025-02-31などを弾く）
    $dateObj = DateTime::createFromFormat('Y-m-d', $reservationDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $reservationDate) {
        $errors[] = '無効な日付です';
    } elseif ($reservationDate < date('Y-m-d')) {
        $errors[] = '過去の日付は選択できません';
    } elseif ($reservationDate > date('Y-m-d', strtotime('+' . $bookingDaysAhead . ' days'))) {
        $errors[] = $bookingDaysAhead . '日以上先の日付は選択できません';
    }
}
if (empty($startTime) || empty($endTime)) {
    $errors[] = '時間を選択してください';
}
// 時間フォーマット検証（HH:MM形式）
if (!empty($startTime) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $startTime)) {
    $errors[] = '開始時間の形式が不正です';
}
if (!empty($endTime) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $endTime)) {
    $errors[] = '終了時間の形式が不正です';
}
// 時刻の前後関係チェックは最大利用時間チェックで実施（深夜またぎ対応）

// 30分刻みチェック
if (!empty($startTime)) {
    $startMin = (int)substr($startTime, 3, 2);
    if ($startMin !== 0 && $startMin !== 30) {
        $errors[] = '開始時間は30分刻みで選択してください';
    }
}
if (!empty($endTime)) {
    $endMinVal = (int)substr($endTime, 3, 2);
    if ($endMinVal !== 0 && $endMinVal !== 30) {
        $errors[] = '終了時間は30分刻みで選択してください';
    }
}

// 最大利用時間チェック（1〜8時間、深夜またぎ対応）
if (!empty($startTime) && !empty($endTime)) {
    $startMinutes = (int)substr($startTime, 0, 2) * 60 + (int)substr($startTime, 3, 2);
    $endMinutes = ($endTime === '00:00') ? 24 * 60 : (int)substr($endTime, 0, 2) * 60 + (int)substr($endTime, 3, 2);

    // 終了時間が開始時間以下の場合は翌日扱い（深夜またぎ）
    if ($endMinutes <= $startMinutes) {
        $endMinutes += 24 * 60;
    }

    $durationMinutes = $endMinutes - $startMinutes;
    if ($durationMinutes > $maxDurationHours * 60) {
        $errors[] = '最大' . $maxDurationHours . '時間までご利用いただけます';
    }
    if ($durationMinutes < $minDurationHours * 60) {
        $errors[] = '最低' . $minDurationHours . '時間からご利用いただけます';
    }
}

// 営業時間チェックは営業区分取得後に実施（店舗ごとの営業時間に基づく）

// 当日予約の場合、現在時刻より1時間以上後かチェック
if (!empty($reservationDate) && $reservationDate === date('Y-m-d') && !empty($startTime)) {
    $now = new DateTime();
    $minBookingDateTime = (clone $now)->modify('+1 hour');
    $bookingStartDateTime = new DateTime($reservationDate . ' ' . $startTime);
    if ($bookingStartDateTime < $minBookingDateTime) {
        $errors[] = '当日予約は1時間後以降の時間帯を選択してください';
    }
}

// 営業区分情報取得（店舗の営業時間情報も含む）
$salesArea = null;
if ($salesAreaId > 0) {
    $salesArea = dbSelectOne(
        "SELECT sa.*, s.name as store_name, s.code as store_code,
                s.opening_time, s.closing_time, s.is_24h_open
         FROM sales_areas sa
         INNER JOIN stores s ON sa.store_id = s.id
         WHERE sa.id = ? AND sa.is_active = 1 AND sa.deleted_at IS NULL
           AND s.is_active = 1 AND s.deleted_at IS NULL",
        [$salesAreaId]
    );
    if (!$salesArea) {
        $errors[] = '選択された店舗が見つかりません';
    }

    // 店舗コード境界検証: URLの店舗コードと選択された営業区分の店舗が一致するか確認
    if ($salesArea && $storeCode) {
        if (strtoupper($salesArea['store_code']) !== strtoupper($storeCode)) {
            $errors[] = '指定された部屋は選択できません';
        }
    }

    // 営業時間チェック（店舗の設定に基づく）
    if ($salesArea && !empty($startTime) && !empty($endTime)) {
        $is24hOpen = (bool)($salesArea['is_24h_open'] ?? false);

        if (!$is24hOpen) {
            // 営業時間を分単位に変換
            $openingTime = substr($salesArea['opening_time'] ?? '10:00:00', 0, 5);
            $closingTime = substr($salesArea['closing_time'] ?? '00:00:00', 0, 5);

            $openingMinutes = (int)substr($openingTime, 0, 2) * 60 + (int)substr($openingTime, 3, 2);
            // 00:00は24:00（1440分）として扱う
            $closingMinutes = ($closingTime === '00:00') ? 24 * 60 : (int)substr($closingTime, 0, 2) * 60 + (int)substr($closingTime, 3, 2);

            $bookingStartMinutes = (int)substr($startTime, 0, 2) * 60 + (int)substr($startTime, 3, 2);
            $bookingEndMinutes = ($endTime === '00:00') ? 24 * 60 : (int)substr($endTime, 0, 2) * 60 + (int)substr($endTime, 3, 2);

            // 深夜営業判定（終了時刻 < 開始時刻 = 日をまたぐ）
            $isOvernightBusiness = $closingMinutes < $openingMinutes;

            if ($isOvernightBusiness) {
                // 深夜営業: 例 18:00〜05:00
                // 予約可能時間: 18:00〜24:00 または 00:00〜05:00
                $isStartValid = ($bookingStartMinutes >= $openingMinutes) || ($bookingStartMinutes < $closingMinutes);
                $isEndValid = ($bookingEndMinutes > $openingMinutes) || ($bookingEndMinutes <= $closingMinutes);

                // 予約が営業時間をまたがないかチェック
                if ($bookingStartMinutes >= $openingMinutes && $bookingEndMinutes <= $closingMinutes + 24 * 60) {
                    // OK: 夜の部で完結（例: 20:00〜23:00）
                } elseif ($bookingStartMinutes < $closingMinutes && $bookingEndMinutes <= $closingMinutes) {
                    // OK: 朝の部で完結（例: 01:00〜04:00）
                } else {
                    $errors[] = sprintf('営業時間外です（%s〜翌%s）', $openingTime, $closingTime);
                }
            } else {
                // 通常営業: 例 10:00〜24:00
                if ($bookingStartMinutes < $openingMinutes || $bookingEndMinutes > $closingMinutes) {
                    $displayClosing = ($closingTime === '00:00') ? '24:00' : $closingTime;
                    $errors[] = sprintf('営業時間外です（%s〜%s）', $openingTime, $displayClosing);
                }
            }
        }
    }
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        flashError($error);
    }
    redirect($bookingBasePath);
}

// セッションに予約情報を保存
$_SESSION['booking'] = [
    'sales_area_id' => $salesAreaId,
    'store_id' => $salesArea['store_id'],
    'reservation_date' => $reservationDate,
    'start_time' => $startTime,
    'end_time' => $endTime,
    'store_name' => $salesArea['store_name'],
    'area_name' => $salesArea['name'],
    // LINE経由の場合の情報（フロントエンドから送信）
    'source' => input('source', 'web'),
    'line_user_id' => '',
    'line_display_name' => '',
];

// LINE user IDのフォーマット検証
$lineUserId = input('line_user_id', '');
if (!empty($lineUserId)) {
    if (preg_match('/^U[0-9a-f]{32}$/', $lineUserId)) {
        $_SESSION['booking']['line_user_id'] = $lineUserId;
        $_SESSION['booking']['line_display_name'] = mb_substr(input('line_display_name', ''), 0, 100);
        $_SESSION['booking']['source'] = 'line';
    } else {
        // 不正なline_user_idは無視（ログのみ）
        error_log("Invalid line_user_id format: " . substr($lineUserId, 0, 50));
    }
}

// 定員取得（selectの上限に使用）
$capacity = (int)($salesArea['capacity'] ?? 4);

// confirm.phpからの戻り時: 入力済み顧客データを復元
$prevInput = $_SESSION['customer_input'] ?? [];
unset($_SESSION['customer_input']);

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/public_header.php';
?>

<div class="container py-5 booking-container">
    <h1 class="h2 mb-4 text-center">予約フォーム</h1>

    <!-- ステップインジケーター（モダン版） -->
    <div class="booking-steps">
        <div class="booking-step completed">
            <span class="booking-step-number"><i class="bi bi-check"></i></span>
            <span class="booking-step-label">日時選択</span>
        </div>
        <div class="booking-step-connector completed"></div>
        <div class="booking-step active">
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

    <!-- 予約内容サマリー -->
    <div class="booking-summary">
        <div class="booking-summary-title"><i class="bi bi-calendar-check me-2"></i>ご予約内容</div>
        <div class="booking-summary-content">
            <strong><?= h($salesArea['store_name']) ?></strong> - <?= h($salesArea['name']) ?><br>
            <?= h(formatDate($reservationDate, 'Y年n月j日')) ?>
            <?= h(substr($startTime, 0, 5)) ?> 〜 <?= h(substr($endTime, 0, 5)) ?>
        </div>
    </div>

    <div class="booking-card">
        <div class="booking-card-header">
            <h5>お客様情報を入力してください</h5>
        </div>
        <div class="booking-card-body">
            <form action="<?= url($bookingBasePath . '/confirm') ?>" method="post" class="booking-form">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">

                <div class="mb-3">
                    <label class="form-label fw-bold">お名前 <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control form-control-lg"
                           required placeholder="山田 太郎" maxlength="100"
                           value="<?= h($prevInput['customer_name'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">メールアドレス <span class="text-danger">*</span></label>
                    <input type="email" name="customer_email" class="form-control form-control-lg"
                           required placeholder="example@email.com" maxlength="255"
                           value="<?= h($prevInput['customer_email'] ?? '') ?>">
                    <div class="form-text">予約確認メールをお送りします</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">電話番号 <span class="text-danger">*</span></label>
                    <input type="tel" name="customer_phone" class="form-control form-control-lg"
                           required placeholder="090-1234-5678" maxlength="20"
                           value="<?= h($prevInput['customer_phone'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">ご利用人数（定員<?= $capacity ?>名）</label>
                    <select name="num_people" class="form-select">
                        <?php $prevPeople = (int)($prevInput['num_people'] ?? 1); ?>
                        <?php for ($i = 1; $i <= $capacity; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === $prevPeople ? 'selected' : '' ?>><?= $i ?>名</option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label">クーポンコード</label>
                    <input type="text" name="coupon_code" class="form-control"
                           placeholder="現在クーポンはご利用いただけません" maxlength="50" disabled>
                    <div class="form-text text-muted">クーポン機能は準備中です</div>
                </div>

                <div class="d-flex gap-2">
                    <a href="<?= url($bookingBasePath) ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-1"></i>戻る
                    </a>
                    <button type="submit" class="btn-booking-primary flex-grow-1">
                        確認画面へ<i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
