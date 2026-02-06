<?php
/**
 * 公開予約フォーム - 予約完了
 */
$pageTitle = '予約完了';
$isPublicPage = true;

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

// 予約登録
dbBegin();
try {
    // サーバー側で空き状況を再検証（FOR UPDATE ロックで競合防止）
    $salesAreaId = (int)$booking['sales_area_id'];
    $storeId = (int)$booking['store_id'];
    $reservationDate = $booking['reservation_date'];
    $startTime = $booking['start_time'];
    $endTime = $booking['end_time'];

    // セッション改ざん対策: sales_area_idとstore_idの整合性を再検証
    $salesAreaCheck = dbSelectOne(
        "SELECT store_id FROM sales_areas WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
        [$salesAreaId]
    );

    if (!$salesAreaCheck || (int)$salesAreaCheck['store_id'] !== $storeId) {
        dbRollback();
        error_log("Session tampering detected: sales_area_id={$salesAreaId}, session_store_id={$storeId}");
        flashError('不正なリクエストが検出されました。最初からやり直してください。');
        redirect($bookingBasePath);
    }

    // 空き鍵チェックは省略し、assignKeyToReservationで直接割当を試行
    // TOCTOU対策: 空きチェックと割当の間で他の予約が入る可能性があるため、
    // assignKeyToReservation内のFOR UPDATEロックで原子的に処理する

    // 予約データ作成（LINE経由の場合はsource='line'とline_user_id/line_display_nameを保存）
    $reservationData = [
        'store_id' => $booking['store_id'],
        'sales_area_id' => $booking['sales_area_id'],
        'reservation_date' => $booking['reservation_date'],
        'start_time' => $booking['start_time'],
        'end_time' => $booking['end_time'],
        'customer_name' => $booking['customer_name'],
        'customer_email' => $booking['customer_email'],
        'customer_phone' => $booking['customer_phone'],
        'num_people' => $booking['num_people'],
        'base_price' => $booking['base_price'],
        'total_price' => $booking['base_price'],
        'payment_status' => 'unpaid', // 決済未実装のため仮
        'status' => 'pending', // 決済完了後にconfirmedに変更
        'source' => $booking['source'] ?? 'web',
        'coupon_code' => $booking['coupon_code'] ?: null,
    ];

    // LINE経由の場合は追加情報を保存
    if (!empty($booking['line_user_id'])) {
        $reservationData['line_user_id'] = $booking['line_user_id'];
    }
    if (!empty($booking['line_display_name'])) {
        $reservationData['line_display_name'] = $booking['line_display_name'];
    }

    $reservationId = dbInsert('reservations', $reservationData);

    // [MVP版] 決済機能未実装のため、予約は自動確定
    // 本番運用時は決済Webhook経由でステータス更新を行う
    dbUpdate('reservations', [
        'status' => 'confirmed',
        'payment_status' => 'paid',
    ], 'id = ?', [$reservationId]);

    // 鍵割当
    assignKeyToReservation(
        $reservationId,
        $booking['sales_area_id'],
        $booking['reservation_date'],
        $booking['start_time'],
        $booking['end_time']
    );

    // 清掃案件生成（清掃終了時刻設定、深夜跨ぎ対応）
    $cleaningJobId = createCleaningJobForReservation(
        $reservationId,
        $booking['store_id'],
        $booking['sales_area_id'],
        $booking['reservation_date'],
        $booking['start_time'],
        $booking['end_time']
    );

    dbCommit();

    // 当日予約の場合は即時LINE通知を送信
    if (isSameDayReservation($booking['reservation_date'])) {
        try {
            $notificationCount = sendSameDayNotification($reservationId, $cleaningJobId);
            if ($notificationCount > 0) {
                error_log("Same-day notification sent: reservation_id={$reservationId}, job_id={$cleaningJobId}, sent={$notificationCount}");
            }
        } catch (Exception $notifError) {
            // 通知失敗してもユーザー体験に影響させない
            error_log("Same-day notification failed: reservation_id={$reservationId}, error=" . $notifError->getMessage());
        }
    }

    // セッション固定化攻撃対策: 予約完了時にセッションIDを再生成
    session_regenerate_id(true);

    // 予約確認メール送信
    $reservationForMail = dbSelectOne(
        "SELECT * FROM reservations WHERE id = ?",
        [$reservationId]
    );
    $mailSent = false;
    if ($reservationForMail) {
        try {
            $mailSent = sendReservationConfirmMail($reservationForMail);
            if (!$mailSent) {
                error_log("Failed to send reservation confirmation email: reservation_id={$reservationId}");
            }
        } catch (Exception $mailError) {
            error_log("Exception sending reservation email: reservation_id={$reservationId}, error=" . $mailError->getMessage());
        }
    }

    // セッションから予約情報を削除（完了表示用にIDは保持）
    $completedBooking = $booking;
    $completedBooking['reservation_id'] = $reservationId;
    unset($_SESSION['booking']);
    $_SESSION['completed_booking'] = $completedBooking;

} catch (RuntimeException $e) {
    // 鍵割当失敗（競合による空き不足）
    dbRollback();
    error_log("Public booking key assignment failed: " . $e->getMessage());
    flashError('申し訳ございません。選択された時間帯は他のお客様が先に予約されました。別の時間帯をお選びください。');
    redirect($bookingBasePath);
} catch (Exception $e) {
    dbRollback();
    error_log("Public booking failed: " . $e->getMessage());
    flashError('予約の登録に失敗しました。しばらくしてから再度お試しください。');
    redirect($bookingBasePath);
}

// 完了ページにリダイレクト
redirect($bookingBasePath . '/thanks');
