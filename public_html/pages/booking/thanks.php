<?php
/**
 * 公開予約フォーム - 完了画面
 */
$pageTitle = '予約完了';
$isPublicPage = true;

// 店舗コード対応（$storeCodeはルーターから設定される）
$storeCode = $storeCode ?? $_SESSION['booking_store_code'] ?? null;
$bookingBasePath = $storeCode ? "/booking/{$storeCode}" : '/booking';

// 完了した予約情報を取得
$completedBooking = $_SESSION['completed_booking'] ?? null;
if (!$completedBooking) {
    redirect($bookingBasePath);
}

// 2回目のアクセス（リフレッシュ）でセッションクリアするフラグ管理
if (isset($_SESSION['thanks_shown'])) {
    // 2回目以降: セッションクリアして表示続行
    unset($_SESSION['completed_booking']);
    unset($_SESSION['booking_session_initialized']);
    unset($_SESSION['booking_store_code']);
    unset($_SESSION['booking']);
    unset($_SESSION['thanks_shown']);
} else {
    // 初回表示: フラグを立てる（次回アクセスでクリア）
    $_SESSION['thanks_shown'] = true;
}

// 鍵情報を取得
$keyAssignment = dbSelectOne(
    "SELECT k.key_number FROM key_assignments ka
     INNER JOIN `keys` k ON ka.key_id = k.id
     WHERE ka.reservation_id = ?",
    [$completedBooking['reservation_id']]
);

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
        <div class="booking-step completed">
            <span class="booking-step-number"><i class="bi bi-check"></i></span>
            <span class="booking-step-label">確認・決済</span>
        </div>
        <div class="booking-step-connector completed"></div>
        <div class="booking-step active">
            <span class="booking-step-number">4</span>
            <span class="booking-step-label">完了</span>
        </div>
    </div>

    <div class="booking-card">
        <div class="booking-card-body text-center">
            <div class="booking-complete-icon">
                <i class="bi bi-check-lg"></i>
            </div>

            <h4 class="booking-complete-title">予約が完了しました</h4>
            <p class="booking-complete-subtitle">
                確認メールを <?= h($completedBooking['customer_email']) ?> にお送りしました。
            </p>

            <div class="text-start mb-4">
                <dl class="row mb-0">
                    <dt class="col-4 text-muted fw-normal">予約番号</dt>
                    <dd class="col-8 fw-bold" style="color: var(--booking-primary);">#<?= $completedBooking['reservation_id'] ?></dd>

                    <dt class="col-4 text-muted fw-normal">店舗</dt>
                    <dd class="col-8"><?= h($completedBooking['store_name']) ?><br><?= h($completedBooking['area_name']) ?></dd>

                    <dt class="col-4 text-muted fw-normal">日時</dt>
                    <dd class="col-8">
                        <?= h(formatDate($completedBooking['reservation_date'], 'n月j日')) ?>
                        <?= h(substr($completedBooking['start_time'], 0, 5)) ?>〜<?= h($completedBooking['end_time'] === '00:00' ? '24:00' : substr($completedBooking['end_time'], 0, 5)) ?>
                    </dd>

                    <?php if ($keyAssignment): ?>
                    <dt class="col-4 text-muted fw-normal">鍵番号</dt>
                    <dd class="col-8"><span class="badge" style="background: var(--booking-primary);"><?= h($keyAssignment['key_number']) ?></span></dd>
                    <?php endif; ?>

                    <dt class="col-4 text-muted fw-normal">お名前</dt>
                    <dd class="col-8"><?= h($completedBooking['customer_name']) ?></dd>

                    <dt class="col-4 text-muted fw-normal">金額</dt>
                    <dd class="col-8 fw-bold" style="color: var(--booking-primary);"><?= number_format($completedBooking['base_price']) ?>円</dd>
                </dl>
            </div>

            <a href="<?= url('/') ?>" class="btn-booking-primary d-block">
                <i class="bi bi-house me-2"></i>トップページへ
            </a>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../../includes/public_footer.php';
?>
