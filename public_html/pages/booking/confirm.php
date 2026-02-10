<?php
/**
 * 公開予約フォーム - 確認・決済
 */
$pageTitle = '予約フォーム - 確認';
$isPublicPage = true;

// 料金はsales_areasテーブルから動的取得

// 店舗コード対応（$storeCodeはルーターから設定される）
$storeCode = $storeCode ?? $_SESSION['booking_store_code'] ?? null;
$bookingBasePath = $storeCode ? "/booking/{$storeCode}" : '/booking';

// POST検証
if (!isPost()) {
    redirect($bookingBasePath);
}
requireCsrf();

// セッションから予約情報取得
$booking = $_SESSION['booking'] ?? null;
if (!$booking) {
    flashError('セッションが切れました。最初からやり直してください。');
    redirect($bookingBasePath);
}

// 店舗設定を取得
$storeSettings = getStoreSettings((int)($booking['store_id'] ?? 0));

// 顧客情報取得
$customerName = trim(input('customer_name', ''));
$customerEmail = trim(input('customer_email', ''));
$customerPhone = trim(input('customer_phone', ''));
$numPeople = (int) input('num_people', 1);
$couponCode = trim(input('coupon_code', ''));

// バリデーション
$errors = validateCustomerInput([
    'customer_name' => $customerName,
    'customer_phone' => $customerPhone,
    'customer_email' => $customerEmail,
    'num_people' => $numPeople,
], ['require_email' => true, 'require_phone' => true]);

if ($numPeople < 1 || $numPeople > 10) {
    $numPeople = 1;
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        flashError($error);
    }
    // customer.phpはPOST専用のため、エラー時はステップ1（日時選択）に戻す
    redirect($bookingBasePath);
}

// セッション時刻データ検証
$timePattern = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
if (empty($booking['start_time']) || empty($booking['end_time'])
    || !preg_match($timePattern, $booking['start_time'])
    || (!preg_match($timePattern, $booking['end_time']) && $booking['end_time'] !== '00:00')) {
    flashError('予約データが不正です。最初からやり直してください。');
    redirect($bookingBasePath);
}

// 料金計算（時刻ベースで安全に計算）
$startMinutes = timeToMinutes($booking['start_time']);
$endMinutes = $booking['end_time'] === '00:00' ? 24 * 60 : timeToMinutes($booking['end_time']);

// 深夜営業対応（終了時刻 < 開始時刻の場合は翌日扱い）
if ($endMinutes <= $startMinutes) {
    $endMinutes += 24 * 60;
}

$hours = ($endMinutes - $startMinutes) / 60;

// 時間の妥当性チェック（最小・最大予約時間 — 店舗設定参照）
if ($hours < $storeSettings['min_duration_hours'] || $hours > $storeSettings['max_duration_hours']) {
    flashError('利用時間が不正です。' . $storeSettings['min_duration_hours'] . '〜' . $storeSettings['max_duration_hours'] . '時間の範囲で選択してください。');
    redirect($bookingBasePath);
}

// 営業区分から時間単価・定員を取得（店舗IDと有効フラグもチェック）
$salesArea = dbSelectOne(
    "SELECT sa.hourly_rate, sa.store_id, sa.capacity
     FROM sales_areas sa
     WHERE sa.id = ? AND sa.is_active = 1 AND sa.deleted_at IS NULL",
    [$booking['sales_area_id']]
);

if (!$salesArea) {
    flashError('選択された部屋が見つかりません。最初からやり直してください。');
    redirect($bookingBasePath);
}

// 店舗IDの整合性チェック（セッション改ざん対策）
if ((int)$salesArea['store_id'] !== (int)$booking['store_id']) {
    flashError('不正なリクエストです。最初からやり直してください。');
    redirect($bookingBasePath);
}

// 定員チェック
$capacity = (int)($salesArea['capacity'] ?? 0);
if ($capacity > 0 && $numPeople > $capacity) {
    flashError("この部屋の定員は{$capacity}名です。人数を変更してください。");
    redirect($bookingBasePath);
}

$pricePerHour = (int)($salesArea['hourly_rate'] ?? $storeSettings['default_hourly_rate']);
$basePrice = (int) ($hours * $pricePerHour);

// セッションに顧客情報追加
$booking['customer_name'] = $customerName;
$booking['customer_email'] = $customerEmail;
$booking['customer_phone'] = $customerPhone;
$booking['num_people'] = $numPeople;
$booking['coupon_code'] = $couponCode;
$booking['base_price'] = $basePrice;
$_SESSION['booking'] = $booking;

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
        <div class="booking-step completed">
            <span class="booking-step-number"><i class="bi bi-check"></i></span>
            <span class="booking-step-label">お客様情報</span>
        </div>
        <div class="booking-step-connector completed"></div>
        <div class="booking-step active">
            <span class="booking-step-number">3</span>
            <span class="booking-step-label">確認・決済</span>
        </div>
        <div class="booking-step-connector"></div>
        <div class="booking-step">
            <span class="booking-step-number">4</span>
            <span class="booking-step-label">完了</span>
        </div>
    </div>

    <div class="booking-card">
        <div class="booking-card-header">
            <h5>ご予約内容をご確認ください</h5>
        </div>
        <div class="booking-card-body">
            <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-building me-2"></i>ご利用内容</h6>
            <table class="table table-borderless">
                <tr>
                    <th class="text-muted" style="width: 30%">店舗</th>
                    <td><?= h($booking['store_name']) ?> - <?= h($booking['area_name']) ?></td>
                </tr>
                <tr>
                    <th class="text-muted">日時</th>
                    <td>
                        <?= h(formatDate($booking['reservation_date'], 'Y年n月j日（') . getDayName((int)date('w', strtotime($booking['reservation_date']))) . '）') ?><br>
                        <?= h(substr($booking['start_time'], 0, 5)) ?> 〜 <?= h($booking['end_time'] === '00:00' ? '24:00' : substr($booking['end_time'], 0, 5)) ?>
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">ご利用時間</th>
                    <td><?= $hours ?>時間</td>
                </tr>
            </table>

            <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-person me-2"></i>お客様情報</h6>
            <table class="table table-borderless">
                <tr>
                    <th class="text-muted" style="width: 30%">お名前</th>
                    <td><?= h($customerName) ?></td>
                </tr>
                <tr>
                    <th class="text-muted">メールアドレス</th>
                    <td><?= h($customerEmail) ?></td>
                </tr>
                <tr>
                    <th class="text-muted">電話番号</th>
                    <td><?= h($customerPhone) ?></td>
                </tr>
                <tr>
                    <th class="text-muted">ご利用人数</th>
                    <td><?= $numPeople ?>名</td>
                </tr>
                <?php if ($couponCode): ?>
                <tr>
                    <th class="text-muted">クーポン</th>
                    <td><?= h($couponCode) ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-credit-card me-2"></i>お支払い金額</h6>
            <div class="bg-light p-4 rounded-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="h5 mb-0">合計金額</span>
                    <span class="h3 mb-0" style="color: var(--booking-primary)"><?= number_format($basePrice) ?>円</span>
                </div>
                <small class="text-muted">（税込）</small>
            </div>

            <?php
            // メールアドレスを部分マスク（例: t***t@example.com）
            $emailParts = explode('@', $customerEmail);
            $localPart = $emailParts[0];
            $domain = $emailParts[1] ?? '';
            if (strlen($localPart) > 2) {
                $maskedLocal = $localPart[0] . str_repeat('*', strlen($localPart) - 2) . substr($localPart, -1);
            } else {
                $maskedLocal = $localPart[0] . '*';
            }
            $maskedEmail = $maskedLocal . '@' . $domain;
            ?>
            <div class="availability-alert available mt-4">
                <i class="bi bi-info-circle"></i>
                <span>
                    決済完了後、予約が確定します。<br>
                    確認メールを <?= h($maskedEmail) ?> にお送りします。
                </span>
            </div>

            <form action="<?= url($bookingBasePath . '/complete') ?>" method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">

                <div class="d-flex gap-2 mt-4">
                    <a href="<?= url($bookingBasePath) ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>最初から
                    </a>
                    <button type="submit" class="btn-booking-success flex-grow-1" id="submitBtn" onclick="this.disabled=true;this.innerHTML='<i class=\'bi bi-hourglass-split me-2\'></i>処理中...';this.form.submit();">
                        <i class="bi bi-check-circle me-2"></i>予約を確定する
                    </button>
                </div>

                <p class="text-center text-muted mt-3">
                    <small>※ 決済サービスは準備中です。現時点では仮予約として登録されます。</small>
                </p>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
