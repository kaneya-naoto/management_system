<?php
/**
 * 急募自動発動スクリプト
 *
 * 清掃予定2時間前で未割当の案件を自動的に急募状態に切り替え、
 * 全清掃者に急募通知を送信する。
 *
 * Cron設定例（10分間隔）:
 * 0,10,20,30,40,50 * * * * /usr/bin/php /path/to/cron/auto_urgent_recruitment.php >> /path/to/logs/cron.log 2>&1
 */

// 二重実行防止（アトミックなロック処理）
$lockFile = __DIR__ . '/auto_urgent_recruitment.lock';
$lockHandle = null;

$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle) {
    echo date('Y-m-d H:i:s') . " [ERROR] Cannot open lock file\n";
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Another process is running\n";
    fclose($lockHandle);
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) time());
fflush($lockHandle);

try {
    require_once __DIR__ . '/../public_html/includes/config.php';
    require_once __DIR__ . '/../public_html/includes/db.php';
    require_once __DIR__ . '/../public_html/includes/functions.php';

    echo date('Y-m-d H:i:s') . " [START] Auto urgent recruitment processing\n";

    $urgentThreshold = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $now = date('Y-m-d H:i:s');

    // 2時間以内に予定されている未割当案件を取得
    // - 未割当（recruiting）で担当者がいない
    // - まだ急募になっていない
    // - 予定時刻が現在から2時間以内
    // - 予定時刻が未来
    $unassignedJobs = dbSelect(
        "SELECT j.id, j.scheduled_at, sa.name as area_name, s.name as store_name
         FROM cleaning_jobs j
         INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
         INNER JOIN stores s ON j.store_id = s.id
         WHERE j.status IN ('unassigned', 'recruiting')
           AND j.assigned_cleaner_id IS NULL
           AND j.is_urgent = 0
           AND j.scheduled_at <= ?
           AND j.scheduled_at > ?
           AND j.deleted_at IS NULL",
        [$urgentThreshold, $now]
    );

    $processedCount = 0;
    $notifiedCount = 0;

    foreach ($unassignedJobs as $job) {
        // 急募状態に更新
        $updated = dbUpdate('cleaning_jobs', [
            'is_urgent' => 1,
            'status' => 'recruiting',
        ], 'id = ? AND assigned_cleaner_id IS NULL', [$job['id']]);

        if ($updated > 0) {
            $processedCount++;

            // 急募通知を送信
            $sent = sendJobNotifications($job['id'], 'urgent');
            if ($sent > 0) {
                $notifiedCount++;
            }

            echo date('Y-m-d H:i:s') . " [URGENT] Job #{$job['id']} ({$job['area_name']}) - scheduled: {$job['scheduled_at']}, notifications: {$sent}\n";
        }
    }

    echo date('Y-m-d H:i:s') . " [DONE] Processed: {$processedCount}, Notified: {$notifiedCount}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] " . $e->getMessage() . "\n";
    error_log("Cron auto_urgent_recruitment error: " . $e->getMessage());
} finally {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
}
