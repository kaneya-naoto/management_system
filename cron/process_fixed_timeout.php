<?php
/**
 * 固定者タイムアウト処理
 *
 * 固定者通知から30分経過した案件を自動処理：
 * - 次の優先度の固定者がいれば通知
 * - いなければ公募開始
 *
 * Cron設定例（5分間隔）:
 * */5 * * * * /usr/bin/php /path/to/cron/process_fixed_timeout.php >> /path/to/logs/cron.log 2>&1
 */

// 二重実行防止（アトミックなロック処理）
$lockFile = __DIR__ . '/process_fixed_timeout.lock';
$lockHandle = null;

// flockを使用したアトミックなロック取得
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle) {
    echo date('Y-m-d H:i:s') . " [ERROR] Cannot open lock file\n";
    exit(1);
}

// 非ブロッキングでロック取得を試行
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Another process is running\n";
    fclose($lockHandle);
    exit(0);
}

// ロックファイルにタイムスタンプを書き込み
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) time());
fflush($lockHandle);

try {
    // 共通ファイル読み込み
    require_once __DIR__ . '/../public_html/includes/config.php';
    require_once __DIR__ . '/../public_html/includes/db.php';
    require_once __DIR__ . '/../public_html/includes/functions.php';

    echo date('Y-m-d H:i:s') . " [START] Fixed cleaner timeout processing\n";

    // タイムアウト処理実行（30分）
    $result = processFixedCleanerTimeout(30);

    echo date('Y-m-d H:i:s') . " [DONE] Processed: {$result['processed']}, Next Fixed: {$result['next_fixed']}, Public: {$result['public']}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] " . $e->getMessage() . "\n";
    error_log("Cron process_fixed_timeout error: " . $e->getMessage());
} finally {
    // ロック解放とファイルクローズ
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    // ロックファイル削除
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
}
