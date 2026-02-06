<?php
/**
 * 定点通知バッチ（日次実行）
 *
 * 未割当・募集中の清掃案件を対象清掃者にLINE通知する
 *
 * 実行: crontab -e で以下を設定（例: 毎日9:00に実行）
 * 0 9 * * * php /path/to/cron/send_daily_notifications.php >> /var/log/daily_notifications.log 2>&1
 */

// エラー表示（cron実行時のデバッグ用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 共通ファイル読み込み
require_once __DIR__ . '/../public_html/includes/config.php';
require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/functions.php';

// 実行ログ
$startTime = microtime(true);
echo date('Y-m-d H:i:s') . " === 定点通知バッチ開始 ===\n";

// 統計情報
$stats = [
    'total_jobs' => 0,
    'total_cleaners' => 0,
    'notifications_sent' => 0,
    'notifications_skipped' => 0,
    'notifications_failed' => 0,
];

try {
    // 未割当・募集中の未来の案件を取得
    $jobs = dbSelect(
        "SELECT cj.*, sa.name as area_name, s.name as store_name
         FROM cleaning_jobs cj
         INNER JOIN sales_areas sa ON cj.sales_area_id = sa.id
         INNER JOIN stores s ON cj.store_id = s.id
         WHERE cj.status IN ('unassigned', 'recruiting')
           AND cj.assigned_cleaner_id IS NULL
           AND cj.scheduled_at > NOW()
           AND cj.deleted_at IS NULL
         ORDER BY cj.scheduled_at ASC"
    );

    $stats['total_jobs'] = count($jobs);
    echo "対象案件数: {$stats['total_jobs']}\n";

    if (empty($jobs)) {
        echo "通知対象の案件がありません\n";
        exit(0);
    }

    // 店舗IDごとにグループ化
    $jobsByStore = [];
    foreach ($jobs as $job) {
        $storeId = $job['store_id'];
        if (!isset($jobsByStore[$storeId])) {
            $jobsByStore[$storeId] = [];
        }
        $jobsByStore[$storeId][] = $job;
    }

    // 各店舗の対象清掃者に通知
    foreach ($jobsByStore as $storeId => $storeJobs) {
        // この店舗に紐づく清掃者を取得
        $cleaners = dbSelect(
            "SELECT c.id, c.name, c.line_user_id
             FROM cleaners c
             INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
             WHERE cs.store_id = ?
               AND c.is_active = 1
               AND c.deleted_at IS NULL
               AND c.line_user_id IS NOT NULL
               AND c.registration_status = 'completed'",
            [$storeId]
        );

        if (empty($cleaners)) {
            echo "店舗ID {$storeId}: 対象清掃者なし\n";
            continue;
        }

        echo "店舗ID {$storeId}: 案件 " . count($storeJobs) . "件, 清掃者 " . count($cleaners) . "人\n";
        $stats['total_cleaners'] += count($cleaners);

        // 各清掃者に通知（トークン付きURLを生成）
        foreach ($cleaners as $cleaner) {
            // 清掃者ごとにlist_tokenを取得（なければ生成）
            $listToken = $cleaner['list_token'] ?? null;
            if (empty($listToken)) {
                $listToken = bin2hex(random_bytes(32));
                dbUpdate('cleaners', ['list_token' => $listToken], 'id = ?', [$cleaner['id']]);
            }

            $listUrl = APP_URL . '/apply/list?token=' . $listToken;

            // 案件数に応じてメッセージ形式を変更
            if (count($storeJobs) <= 5) {
                // 個別案件をリスト表示
                $jobList = "";
                foreach ($storeJobs as $index => $job) {
                    $date = date('n/j', strtotime($job['scheduled_at']));
                    $time = date('H:i', strtotime($job['scheduled_at']));
                    $reward = number_format($job['base_reward']);
                    $jobList .= ($index + 1) . ". {$job['area_name']}\n   {$date} {$time}〜 / {$reward}円\n";
                }

                $message = "【空き案件のお知らせ】\n\n"
                    . "現在、以下の案件が募集中です：\n\n"
                    . $jobList . "\n"
                    . "▼ 詳細・応募はこちら\n{$listUrl}";
            } else {
                // サマリー表示
                $message = "【空き案件のお知らせ】\n\n"
                    . "現在 " . count($storeJobs) . " 件の案件が募集中です！\n\n"
                    . "▼ 案件一覧はこちら\n{$listUrl}";
            }

            // 重複チェック付きで通知送信
            $firstJobId = $storeJobs[0]['id'];

            $sent = sendNotificationWithDedup(
                $firstJobId,
                $cleaner['id'],
                $cleaner['line_user_id'],
                $message,
                'daily'
            );

            if ($sent) {
                $stats['notifications_sent']++;
                echo "  ✓ {$cleaner['name']}: 送信成功\n";
            } else {
                // 重複またはエラー
                $stats['notifications_skipped']++;
                echo "  - {$cleaner['name']}: スキップ（重複または失敗）\n";
            }
        }
    }

    // 未通知の案件のステータスを recruiting に更新
    $updatedCount = dbExecute(
        "UPDATE cleaning_jobs
         SET status = 'recruiting'
         WHERE status = 'unassigned'
           AND assigned_cleaner_id IS NULL
           AND scheduled_at > NOW()
           AND deleted_at IS NULL"
    );
    echo "ステータス更新: {$updatedCount}件を recruiting に変更\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Daily notification batch error: " . $e->getMessage());
    exit(1);
}

// 実行時間計測
$elapsedTime = round(microtime(true) - $startTime, 2);

// サマリー出力
echo "\n=== 実行結果 ===\n";
echo "対象案件数: {$stats['total_jobs']}\n";
echo "対象清掃者数: {$stats['total_cleaners']}\n";
echo "通知送信: {$stats['notifications_sent']}\n";
echo "スキップ: {$stats['notifications_skipped']}\n";
echo "実行時間: {$elapsedTime}秒\n";
echo date('Y-m-d H:i:s') . " === 定点通知バッチ完了 ===\n";

exit(0);
