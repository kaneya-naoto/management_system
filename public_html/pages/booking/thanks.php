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

    <div class="booking-card" style="border: 2px solid var(--booking-accent);">
        <div class="booking-card-header" style="background: linear-gradient(135deg, var(--booking-accent) 0%, #059669 100%);">
            <h5 class="text-center mb-0">予約が完了しました</h5>
        </div>
        <div class="booking-card-body text-center">
            <div class="booking-complete-icon">
                <i class="bi bi-check-lg"></i>
            </div>

            <h4 class="booking-complete-title">ありがとうございます</h4>
            <p class="booking-complete-subtitle">
                確認メールを <strong><?= h($completedBooking['customer_email']) ?></strong> にお送りしました。
            </p>

            <div class="bg-light p-4 rounded-3 text-start mb-4">
                <h5 class="mb-3"><i class="bi bi-calendar-check me-2"></i>ご予約内容</h5>
                <table class="table table-borderless mb-0">
                    <tr>
                        <th class="text-muted" style="width: 30%">予約番号</th>
                        <td><strong style="color: var(--booking-primary); font-size: 1.25rem;">#<?= $completedBooking['reservation_id'] ?></strong></td>
                    </tr>
                    <tr>
                        <th class="text-muted">店舗</th>
                        <td><?= h($completedBooking['store_name']) ?> - <?= h($completedBooking['area_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">日時</th>
                        <td>
                            <?= h(formatDate($completedBooking['reservation_date'], 'Y年n月j日')) ?><br>
                            <?= h(substr($completedBooking['start_time'], 0, 5)) ?> 〜 <?= h($completedBooking['end_time'] === '00:00' ? '24:00' : substr($completedBooking['end_time'], 0, 5)) ?>
                        </td>
                    </tr>
                    <?php if ($keyAssignment): ?>
                    <tr>
                        <th class="text-muted">鍵番号</th>
                        <td><span class="badge fs-5" style="background: var(--booking-primary);"><?= h($keyAssignment['key_number']) ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th class="text-muted">お名前</th>
                        <td><?= h($completedBooking['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">お支払い金額</th>
                        <td><strong style="color: var(--booking-primary);"><?= number_format($completedBooking['base_price']) ?>円</strong></td>
                    </tr>
                </table>
            </div>

            <div class="availability-alert available">
                <i class="bi bi-info-circle"></i>
                <span>
                    <strong>ご来店時のお願い</strong><br>
                    受付にて予約番号「<strong>#<?= $completedBooking['reservation_id'] ?></strong>」をお伝えください。
                </span>
            </div>

            <div class="mt-4">
                <a href="<?= url('/') ?>" class="btn-booking-primary">
                    <i class="bi bi-house me-2"></i>トップページへ
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../../includes/public_footer.php';
?>
