<?php
/**
 * 通知関連ヘルパー関数
 *
 * LINE通知、案件通知、固定者タイムアウト処理、
 * 重複チェック付き通知、メール送信などの通知ドメイン関数群
 */

/**
 * 清掃案件の通知を送信
 * @param int $jobId 案件ID
 * @param string $type 通知種別（'normal', 'urgent', 'fixed'）
 * @return int 送信した通知数
 */
function sendJobNotifications(int $jobId, string $type = 'normal'): int
{
    $job = dbSelectOne(
        "SELECT j.*, sa.name as area_name, s.name as store_name
         FROM cleaning_jobs j
         INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
         INNER JOIN stores s ON j.store_id = s.id
         WHERE j.id = ?",
        [$jobId]
    );

    if (!$job) {
        return 0;
    }

    $sentCount = 0;

    if ($type === 'fixed') {
        // 固定者への優先通知
        $fixedCleaners = dbSelect(
            "SELECT fc.cleaner_id, fc.priority, c.name, c.line_user_id
             FROM fixed_cleaners fc
             INNER JOIN cleaners c ON fc.cleaner_id = c.id
             WHERE fc.store_id = ?
               AND fc.is_active = 1
               AND fc.deleted_at IS NULL
               AND c.is_active = 1
               AND c.deleted_at IS NULL
               AND c.line_user_id IS NOT NULL
             ORDER BY fc.priority
             LIMIT 1",
            [$job['store_id']]
        );

        foreach ($fixedCleaners as $cleaner) {
            $token = generateApplicationToken($jobId, $cleaner['cleaner_id'], 'fixed', 30);
            $url = getApplicationUrl($token);

            $scheduledDate = formatDate($job['scheduled_at'], 'n月j日');
            $scheduledTime = formatDate($job['scheduled_at'], 'H:i');

            $message = "{$cleaner['name']}さん専用のご案内です\n\n"
                . "📍 場所: {$job['area_name']}\n"
                . "📅 日時: {$scheduledDate} {$scheduledTime}\n"
                . "💰 報酬: " . number_format($job['base_reward']) . "円\n\n"
                . "このまま対応可能ですか？\n\n"
                . "▼ 詳細・回答はこちら\n{$url}";

            if (sendLineTextPush($cleaner['line_user_id'], $message)) {
                // 通知ログ記録
                dbInsert('notification_logs', [
                    'cleaner_id' => $cleaner['cleaner_id'],
                    'job_id' => $jobId,
                    'type' => 'fixed',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'response' => 'pending',
                ]);
                $sentCount++;
            }
        }

        // 固定者通知送信日時を記録
        if ($sentCount > 0) {
            dbUpdate('cleaning_jobs', [
                'notification_status' => 'fixed_waiting',
                'fixed_notification_sent_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$jobId]);
        }
    } else {
        // 公募通知（通常 or 急募）
        $cleaners = dbSelect(
            "SELECT c.id, c.name, c.line_user_id
             FROM cleaners c
             INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
             WHERE cs.store_id = ?
               AND c.is_active = 1
               AND c.deleted_at IS NULL
               AND c.line_user_id IS NOT NULL",
            [$job['store_id']]
        );

        $token = generateApplicationToken($jobId, null, 'public', 120);
        $url = getApplicationUrl($token);

        $isUrgent = $type === 'urgent' || $job['is_urgent'];
        $prefix = $isUrgent ? "【急募】清掃スタッフ募集！\n\n" : "新しい清掃案件があります！\n\n";
        $urgentNote = $isUrgent ? "\n⚠️ お急ぎの案件です\n" : "";

        $scheduledDate = formatDate($job['scheduled_at'], 'n月j日');
        $scheduledTime = formatDate($job['scheduled_at'], 'H:i');

        $baseMessage = $prefix
            . "📍 場所: {$job['area_name']}\n"
            . "📅 日時: {$scheduledDate} {$scheduledTime}\n"
            . "💰 報酬: " . number_format($job['base_reward']) . "円"
            . $urgentNote . "\n\n"
            . "▼ 詳細・応募はこちら\n{$url}";

        foreach ($cleaners as $cleaner) {
            if (sendLineTextPush($cleaner['line_user_id'], $baseMessage)) {
                dbInsert('notification_logs', [
                    'cleaner_id' => $cleaner['id'],
                    'job_id' => $jobId,
                    'type' => $isUrgent ? 'urgent' : 'normal',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'response' => 'pending',
                ]);
                $sentCount++;
            }
        }

        // 公募通知送信日時を記録
        if ($sentCount > 0) {
            dbUpdate('cleaning_jobs', [
                'notification_status' => 'public_recruiting',
                'public_notification_sent_at' => date('Y-m-d H:i:s'),
                'is_urgent' => $isUrgent ? 1 : $job['is_urgent'],
            ], 'id = ?', [$jobId]);
        }
    }

    return $sentCount;
}

/**
 * 次の固定者を取得
 * @param int $storeId 店舗ID
 * @param int $jobId 案件ID
 * @return array|null 次の固定者情報、いなければnull
 */
function getNextFixedCleaner(int $storeId, int $jobId): ?array
{
    // 既に通知済みの固定者を除外して、次の優先度の固定者を取得
    return dbSelectOne(
        "SELECT fc.cleaner_id, fc.priority, c.name, c.line_user_id
         FROM fixed_cleaners fc
         INNER JOIN cleaners c ON fc.cleaner_id = c.id
         WHERE fc.store_id = ?
           AND fc.is_active = 1
           AND fc.deleted_at IS NULL
           AND c.is_active = 1
           AND c.deleted_at IS NULL
           AND c.line_user_id IS NOT NULL
           AND fc.cleaner_id NOT IN (
               SELECT nl.cleaner_id FROM notification_logs nl
               WHERE nl.job_id = ? AND nl.type = 'fixed'
           )
         ORDER BY fc.priority
         LIMIT 1",
        [$storeId, $jobId]
    );
}

/**
 * 固定者タイムアウト処理
 * 30分経過した案件を次の固定者に通知、いなければ公募開始
 * @param int $timeoutMinutes タイムアウト時間（分）
 * @return array 処理結果 ['processed' => 処理件数, 'next_fixed' => 次固定者通知数, 'public' => 公募開始数]
 */
function processFixedCleanerTimeout(int $timeoutMinutes = 30): array
{
    $result = ['processed' => 0, 'next_fixed' => 0, 'public' => 0];

    // タイムアウトした案件を取得（固定者待ち状態で、指定時間経過）
    $timeoutAt = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));

    $timeoutJobs = dbSelect(
        "SELECT j.*, s.name as store_name, sa.name as area_name
         FROM cleaning_jobs j
         INNER JOIN stores s ON j.store_id = s.id
         INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
         WHERE j.notification_status = 'fixed_waiting'
           AND j.fixed_notification_sent_at <= ?
           AND j.status = 'recruiting'
           AND j.deleted_at IS NULL",
        [$timeoutAt]
    );

    foreach ($timeoutJobs as $job) {
        $result['processed']++;

        // 次の固定者を探す
        $nextFixed = getNextFixedCleaner($job['store_id'], $job['id']);

        if ($nextFixed) {
            // 次の固定者に通知
            $token = generateApplicationToken($job['id'], $nextFixed['cleaner_id'], 'fixed', 30);
            $url = getApplicationUrl($token);

            $scheduledDate = formatDate($job['scheduled_at'], 'n月j日');
            $scheduledTime = formatDate($job['scheduled_at'], 'H:i');

            $message = "{$nextFixed['name']}さん専用のご案内です\n\n"
                . "📍 場所: {$job['area_name']}\n"
                . "📅 日時: {$scheduledDate} {$scheduledTime}\n"
                . "💰 報酬: " . number_format($job['base_reward']) . "円\n\n"
                . "このまま対応可能ですか？\n\n"
                . "▼ 詳細・回答はこちら\n{$url}";

            if (sendLineTextPush($nextFixed['line_user_id'], $message)) {
                // 通知ログ記録
                dbInsert('notification_logs', [
                    'cleaner_id' => $nextFixed['cleaner_id'],
                    'job_id' => $job['id'],
                    'type' => 'fixed',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'response' => 'pending',
                ]);

                // 通知送信時刻を更新
                dbUpdate('cleaning_jobs', [
                    'fixed_notification_sent_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$job['id']]);

                $result['next_fixed']++;
            }
        } else {
            // 固定者がいなければ公募開始
            $sentCount = sendJobNotifications($job['id'], 'normal');
            if ($sentCount > 0) {
                $result['public']++;
            }
        }
    }

    return $result;
}

/**
 * 定点通知の重複チェック付き送信
 * @param int $jobId 案件ID
 * @param int $cleanerId 清掃者ID
 * @param string $lineUserId LINE User ID
 * @param string $message メッセージ
 * @param string $type 通知種別（immediate/daily）
 * @return bool 送信成功したらtrue
 */
function sendNotificationWithDedup(
    int $jobId,
    int $cleanerId,
    string $lineUserId,
    string $message,
    string $type = 'daily'
): bool {
    $today = date('Y-m-d');

    // 挿入を試みる（重複時は無視）
    try {
        $result = dbExecute(
            "INSERT IGNORE INTO daily_notification_logs
             (job_id, cleaner_id, notification_date, notification_type, status)
             VALUES (?, ?, ?, ?, 'pending')",
            [$jobId, $cleanerId, $today, $type]
        );

        if ($result === 0) {
            // 既に通知済み
            return false;
        }
    } catch (Exception $e) {
        // テーブルが存在しない場合などはスキップして送信
        error_log("sendNotificationWithDedup insert error: " . $e->getMessage());
    }

    // 通知送信
    $sent = sendLineTextPush($lineUserId, $message);

    // ステータス更新
    try {
        dbUpdate('daily_notification_logs', [
            'status' => $sent ? 'sent' : 'failed',
            'notified_at' => $sent ? date('Y-m-d H:i:s') : null,
            'error_message' => $sent ? null : 'LINE送信失敗',
        ], 'job_id = ? AND cleaner_id = ? AND notification_date = ?',
        [$jobId, $cleanerId, $today]);
    } catch (Exception $e) {
        error_log("sendNotificationWithDedup update error: " . $e->getMessage());
    }

    return $sent;
}

/**
 * メールを送信しログに記録
 * @param string $to 送信先メールアドレス
 * @param string $subject 件名
 * @param string $body 本文
 * @param string $type メール種別
 * @param int|null $reservationId 関連予約ID
 * @return bool 送信成功したらtrue
 */
function sendMail(string $to, string $subject, string $body, string $type = 'general', ?int $reservationId = null): bool
{
    $status = 'failed';
    $errorMessage = null;

    try {
        // ヘッダーインジェクション対策（改行除去）
        $to = str_replace(["\r", "\n"], '', $to);
        $subject = str_replace(["\r", "\n"], '', $subject);

        // メールアドレス形式チェック
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Invalid email address';
            throw new InvalidArgumentException($errorMessage);
        }

        // メールヘッダー
        $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@example.com';
        $from = str_replace(["\r", "\n"], '', $from);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from,
        ];

        // メール送信
        $result = mail($to, $subject, $body, implode("\r\n", $headers));

        if ($result) {
            $status = 'sent';
        } else {
            $errorMessage = 'mail() returned false';
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        error_log("sendMail error: " . $errorMessage);
    }

    // ログに記録
    try {
        dbInsert('email_logs', [
            'to_email' => $to,
            'subject' => $subject,
            'body' => $body,
            'type' => $type,
            'reservation_id' => $reservationId,
            'status' => $status,
            'sent_at' => date('Y-m-d H:i:s'),
            'error_message' => $errorMessage,
        ]);
    } catch (Exception $e) {
        error_log("email_logs insert error: " . $e->getMessage());
    }

    return $status === 'sent';
}

/**
 * 予約確認メールを送信
 * @param array $reservation 予約データ
 * @return bool 送信成功したらtrue
 */
function sendReservationConfirmMail(array $reservation): bool
{
    $to = $reservation['customer_email'] ?? '';
    if (empty($to)) {
        return false;
    }

    $subject = '【カクレマ】ご予約確認';
    $body = <<<EOT
{$reservation['customer_name']} 様

この度はご予約いただきありがとうございます。
以下の内容でご予約を承りました。

【ご予約内容】
日時: {$reservation['reservation_date']} {$reservation['start_time']} - {$reservation['end_time']}
料金: {$reservation['total_price']}円

ご来店をお待ちしております。

---
カクレマ
EOT;

    return sendMail($to, $subject, $body, 'reservation_confirm', (int)$reservation['id']);
}

/**
 * 予約キャンセルメールを送信
 * @param array $reservation 予約データ
 * @return bool 送信成功したらtrue
 */
function sendReservationCancelMail(array $reservation): bool
{
    $to = $reservation['customer_email'] ?? '';
    if (empty($to)) {
        return false;
    }

    $subject = '【カクレマ】ご予約キャンセルのお知らせ';
    $body = <<<EOT
{$reservation['customer_name']} 様

ご予約のキャンセルを承りました。

【キャンセルされた予約内容】
日時: {$reservation['reservation_date']} {$reservation['start_time']} - {$reservation['end_time']}

またのご利用をお待ちしております。

---
カクレマ
EOT;

    return sendMail($to, $subject, $body, 'reservation_cancel', (int)$reservation['id']);
}

/**
 * パスワードリセットメールを送信
 * @param string $email 送信先メールアドレス
 * @param string $token リセットトークン
 * @return bool 送信成功したらtrue
 */
function sendPasswordResetMail(string $email, string $token): bool
{
    // ユーザー名を取得
    $user = dbSelectOne(
        "SELECT name FROM users WHERE email = ? AND deleted_at IS NULL",
        [$email]
    );

    $userName = $user['name'] ?? 'ユーザー';
    $resetUrl = APP_URL . '/reset-password?token=' . $token;

    $subject = '【カクレマ】パスワードリセットのご案内';
    $body = <<<EOT
{$userName} 様

パスワードリセットのリクエストを受け付けました。

以下のURLをクリックして、新しいパスワードを設定してください。
このリンクは1時間で無効になります。

{$resetUrl}

このリクエストに心当たりがない場合は、このメールを無視してください。
パスワードは変更されません。

---
カクレマ
EOT;

    return sendMail($email, $subject, $body, 'password_reset');
}
