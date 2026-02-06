# 予約フロー改善 - コードレビュー結果レポート

## レビュー実施日
2026-01-28

## レビュー対象ファイル
- `public_html/pages/booking/complete.php`
- `public_html/api/booking/check-availability.php`
- `public_html/api/line/webhook.php`
- `public_html/includes/functions.php`

## レビュー方法
- Claude Code code-improvement-reviewer エージェント
- OpenAI Codex セカンドオピニオン

---

## 1. 当日予約の即時通知（complete.php）

### 問題点

| 優先度 | 問題 | 詳細 |
|--------|------|------|
| **高** | DBコミット前の通知リスク | 現状、通知はコミット後だが明示的でない。トランザクション内で通知するとロールバック時に誤通知 |
| **高** | ステータス更新不足 | `sendJobNotifications()`は`status`を`recruiting`に更新しない。当日予約でも`unassigned`のまま |
| **高** | 深夜跨ぎ未対応 | `createCleaningJobForReservation()`で`end_time < start_time`の場合、翌日に繰り越す処理がない |
| **中** | 冪等性未確保 | 二重投稿時に再通知される可能性 |
| **中** | タイムゾーン未統一 | `date('Y-m-d')`の前提がAsia/Tokyoであることが不明確 |

### 改善案

```php
// DBコミット後に通知処理
dbCommit();

// 当日予約の即時通知
$today = date('Y-m-d'); // タイムゾーン明示が望ましい
if ($booking['reservation_date'] === $today) {
    // ステータスを recruiting に更新
    dbUpdate('cleaning_jobs', [
        'status' => 'recruiting',
    ], 'reservation_id = ?', [$reservationId]);

    // 通知送信（冪等性チェック付き）
    sendJobNotifications($cleaningJobId, 'normal');
}
```

---

## 2. 清掃時間を考慮した空き判定（check-availability.php）

### 問題点

| 優先度 | 問題 | 詳細 |
|--------|------|------|
| **高** | 清掃時間未考慮 | 予約終了後の清掃時間中に次の予約が可能 |
| **高** | 前日跨ぎ未対応 | `reservation_date = :date`固定で、前日夜の予約が翌日に食い込むケースを拾えない |
| **中** | ロジック重複 | complete.php、functions.phpと深夜跨ぎロジックが異なる |
| **低** | インデックス未最適化 | 複合インデックスの活用が不明確 |

### 改善案（Codex提案）

```sql
-- 清掃時間を考慮した予約ブロック判定
SELECT COUNT(DISTINCT ka.key_id) as cnt
FROM key_assignments ka
JOIN reservations r ON ka.reservation_id = r.id
LEFT JOIN cleaning_jobs cj ON cj.reservation_id = r.id
WHERE r.sales_area_id = :sales_area_id
  AND r.reservation_date IN (:date, DATE_SUB(:date, INTERVAL 1 DAY))
  AND r.status NOT IN ('cancelled')
  AND r.deleted_at IS NULL
  AND NOT (
    -- block_end: 清掃完了時刻 or 予約終了+清掃時間
    COALESCE(
      cj.completed_at,
      DATE_ADD(
        CONCAT(r.reservation_date, ' ', r.end_time),
        INTERVAL :cleaning_minutes MINUTE
      )
    ) <= :request_start_dt
    OR
    CONCAT(r.reservation_date, ' ', r.start_time) >= :request_end_dt
  )
```

### 推奨インデックス

```sql
CREATE INDEX idx_reservations_availability
ON reservations(sales_area_id, reservation_date, start_time, end_time, status);

CREATE INDEX idx_cleaning_jobs_completion
ON cleaning_jobs(reservation_id, status, completed_at);
```

---

## 3. LINE Webhook完了報告（webhook.php）

### 問題点

| 優先度 | 問題 | 詳細 |
|--------|------|------|
| **高** | 完了報告機能未実装 | メッセージ/postbackで完了報告を受け付ける処理がない |
| **中** | デバッグログがファイル出力 | `webhook_debug.log`が公開ディレクトリに作成されるリスク |
| **中** | 二重処理防止なし | LINE webhookは再送されるが、message.idでの重複チェックがない |

### 改善案（Codex提案）

```php
// キーワード判定（完全一致）
function isCompletionKeyword(string $text): bool
{
    $normalized = trim(mb_strtolower($text));
    return preg_match('/^(完了|終了|終わりました|done)$/u', $normalized);
}

// 完了処理（冪等性確保）
function completeCleaningJob(int $jobId, int $cleanerId): array
{
    $affected = dbExecute(
        "UPDATE cleaning_jobs
         SET status = 'completed', completed_at = NOW()
         WHERE id = ? AND assigned_cleaner_id = ? AND status = 'assigned'",
        [$jobId, $cleanerId]
    );

    if ($affected === 0) {
        return ['success' => false, 'message' => '既に完了済みか権限がありません'];
    }

    // 支払いレコード生成
    createPaymentForJob($jobId);

    return ['success' => true, 'message' => '完了しました'];
}
```

### 複数案件対応（postback方式推奨）

```php
// 当日の担当案件が複数ある場合
$jobs = getCurrentAssignedJobs($cleanerId); // 複数取得

if (count($jobs) > 1) {
    // クイックリプライで選択させる
    $quickReply = buildJobSelectionQuickReply($jobs);
    replyMessage($replyToken, [...], $quickReply);
} else {
    // 1件なら即座に完了
    completeCleaningJob($jobs[0]['id'], $cleanerId);
}
```

---

## 4. 定点通知バッチの重複防止

### 問題点

| 優先度 | 問題 | 詳細 |
|--------|------|------|
| **高** | 重複防止テーブル未作成 | `daily_notification_logs`テーブルが存在しない |
| **中** | 即時通知との統合不足 | 即時通知した清掃者に定点通知も送られる可能性 |

### 改善案

```sql
-- 重複防止テーブル（ユニーク制約付き）
CREATE TABLE daily_notification_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NOT NULL,
    cleaner_id BIGINT NOT NULL,
    notification_date DATE NOT NULL,
    notification_type ENUM('immediate', 'daily') NOT NULL DEFAULT 'daily',
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    notified_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_job_cleaner_date (job_id, cleaner_id, notification_date),
    INDEX idx_notification_date (notification_date),
    INDEX idx_status (status)
);
```

```php
// 重複防止付き通知（INSERT IGNORE方式）
function sendDailyNotificationWithDedup(int $jobId, int $cleanerId): bool
{
    $today = date('Y-m-d');

    // 挿入を試みる（重複時は無視）
    $result = dbExecute(
        "INSERT IGNORE INTO daily_notification_logs
         (job_id, cleaner_id, notification_date, status)
         VALUES (?, ?, ?, 'pending')",
        [$jobId, $cleanerId, $today]
    );

    if ($result === 0) {
        // 既に通知済み
        return false;
    }

    // 通知送信
    $sent = sendLineNotification($lineUserId, $message);

    // ステータス更新
    dbUpdate('daily_notification_logs', [
        'status' => $sent ? 'sent' : 'failed',
        'notified_at' => $sent ? date('Y-m-d H:i:s') : null,
    ], 'job_id = ? AND cleaner_id = ? AND notification_date = ?',
    [$jobId, $cleanerId, $today]);

    return $sent;
}
```

---

## 5. 共通の改善提案

### 共通関数の作成

両レビュアーが指摘：重複ロジックを共通関数化すべき

```php
// functions.php に追加

/**
 * 時間重複チェック（清掃時間考慮、深夜跨ぎ対応）
 */
function checkReservationConflict(
    int $salesAreaId,
    string $reservationDate,
    string $startTime,
    string $endTime,
    ?int $excludeReservationId = null
): int;

/**
 * 営業区分の空き鍵数を取得
 */
function getAvailableKeyCount(
    int $salesAreaId,
    string $reservationDate,
    string $startTime,
    string $endTime
): int;

/**
 * 当日予約かどうかを判定
 */
function isSameDayReservation(string $reservationDate): bool;

/**
 * 清掃時間を取得（sales_areas優先、なければデフォルト）
 */
function getCleaningDuration(int $salesAreaId): int;
```

---

## 6. DB変更案

### 新規テーブル

```sql
-- 定点通知ログ（重複防止）
CREATE TABLE daily_notification_logs (...);

-- API レート制限（check-availability.php用）
CREATE TABLE api_rate_limits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    minute_bucket INT NOT NULL,
    api_endpoint VARCHAR(100) NOT NULL,
    request_count INT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ip_minute_endpoint (ip_address, minute_bucket, api_endpoint)
);
```

### 既存テーブル変更

```sql
-- cleaning_jobs: 完了時刻カラム追加
ALTER TABLE cleaning_jobs ADD COLUMN completed_at DATETIME NULL AFTER status;

-- cleaning_jobs: 清掃終了予定時刻追加
ALTER TABLE cleaning_jobs ADD COLUMN scheduled_end_at DATETIME NULL AFTER scheduled_at;

-- sales_areas: 清掃時間設定
ALTER TABLE sales_areas ADD COLUMN cleaning_duration_minutes INT NOT NULL DEFAULT 60;
```

---

## 7. 実装優先度

### Phase 1（最優先）
1. `cleaning_jobs.completed_at` カラム追加
2. 共通関数の作成（重複ロジック排除）
3. check-availability.php の清掃時間対応

### Phase 2（高優先）
1. 当日予約の即時LINE通知（complete.php）
2. LINE完了報告機能（webhook.php）
3. 定点通知バッチ作成（cron/send_daily_notifications.php）

### Phase 3（中優先）
1. 清掃者向け案件一覧ページ（/apply/list）
2. レート制限の統一（APCu/DB方式）
3. デバッグログの本番対応

---

## 8. 両レビュアーの一致点

| 項目 | Claude | Codex | 結論 |
|------|--------|-------|------|
| DBコミット後に通知 | ○ | ○ | 必須 |
| 深夜跨ぎ対応 | ○ | ○ | 必須 |
| 清掃時間考慮 | ○ | ○ | 必須 |
| ステータス更新追加 | ○ | ○ | 必須 |
| 冪等性確保 | ○ | ○ | 必須 |
| ユニーク制約での重複防止 | ○ | ○ | 必須 |
| postback方式での完了報告 | ○ | ○ | 推奨 |

---

*Reviewed by: Claude Code + OpenAI Codex*
*Created: 2026-01-28*
