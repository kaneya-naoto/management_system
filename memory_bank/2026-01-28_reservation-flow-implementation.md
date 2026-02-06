# 予約フロー改善 実装レポート

**実施日**: 2026-01-28
**ステータス**: ✅ デプロイ・テスト完了

---

## 1. 実装した機能

### 1.1 当日予約の即時LINE通知
- **トリガー**: 予約確定時（`complete.php`）に予約日が当日の場合
- **対象**: 該当店舗に紐づく清掃者（`cleaner_stores`）
- **処理**: `sendSameDayNotification()` で一括通知

### 1.2 定点通知バッチ（毎日実行）
- **ファイル**: `cron/send_daily_notifications.php`（新規作成）
- **対象案件**: `status IN ('unassigned', 'recruiting')` かつ未来の案件
- **対象清掃者**: 店舗紐づけあり、LINE連携済み
- **重複防止**: `daily_notification_logs` テーブルで管理

### 1.3 清掃者向け案件一覧ページ
- **URL**: `/pages/apply/list.php?token=xxx`
- **認証**: トークン認証 + セッション認証
- **機能**: 日付・店舗フィルタ、応募ボタン

### 1.4 清掃完了報告（LINE連携）
- **トリガー**: LINE webhook で「完了」「終了」などのキーワード
- **処理**: `completeCleaningJob()` で冪等性確保
- **連動**: 予約ブロック動的解除

### 1.5 予約ブロック改善
- **清掃時間考慮**: デフォルト60分を予約終了後にブロック
- **深夜跨ぎ対応**: 22:00-02:00 などの予約に対応
- **動的解除**: 清掃完了報告で即時解除

---

## 2. セキュリティ修正

### 2.1 レート制限の競合状態対策
- **問題**: ファイルベースのレート制限に race condition
- **修正**: `checkRateLimit()` 関数を新規作成
- **対策**: `flock(LOCK_EX)` で排他ロック

### 2.2 TOCTOU脆弱性対策
- **問題**: 空き確認と鍵割当の間で競合発生の可能性
- **修正**: `RuntimeException` を個別キャッチ
- **メッセージ**: 「他のお客様が先に予約されました」

### 2.3 セッション固定化攻撃対策
- **対象**: `list.php`, `complete.php`
- **修正**: 認証成功時に `session_regenerate_id(true)`

---

## 3. DBスキーマ変更

### 3.1 cleaning_jobs テーブル
```sql
ADD COLUMN completed_at DATETIME NULL;
ADD COLUMN scheduled_end_at DATETIME NULL;
ADD COLUMN duration_minutes INT NOT NULL DEFAULT 60;
CREATE INDEX idx_cleaning_jobs_completion ON cleaning_jobs(reservation_id, status, completed_at);
```

### 3.2 sales_areas テーブル
```sql
ADD COLUMN cleaning_duration_minutes INT NOT NULL DEFAULT 60;
```

### 3.3 cleaners テーブル
```sql
ADD COLUMN list_token VARCHAR(64) NULL;
CREATE UNIQUE INDEX idx_cleaners_list_token ON cleaners(list_token);
```

### 3.4 daily_notification_logs テーブル（新規）
```sql
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
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_job_cleaner_date (job_id, cleaner_id, notification_date)
);
```

### 3.5 reservations テーブル
```sql
CREATE INDEX idx_reservations_availability ON reservations(sales_area_id, reservation_date, start_time, end_time, status);
```

---

## 4. 新規・修正ファイル

### 4.1 新規作成
| ファイル | 用途 |
|----------|------|
| `cron/send_daily_notifications.php` | 定点通知バッチ |
| `public_html/pages/apply/list.php` | 清掃者向け案件一覧 |
| `database/013_reservation_flow_improvement.sql` | マイグレーション |
| `spec/features/notification/auto_notification.md` | 仕様書 |
| `spec/features/reservation/reservation_block.md` | 仕様書 |
| `spec/features/apply/cleaner_job_list.md` | 仕様書 |
| `spec/features/cleaning/completion_report.md` | 仕様書 |

### 4.2 修正
| ファイル | 修正内容 |
|----------|----------|
| `includes/functions.php` | 共通関数追加（約400行）|
| `api/booking/check-availability.php` | `checkRateLimit()` 使用、`getAvailableKeyCount()` 使用 |
| `pages/booking/complete.php` | 当日通知、RuntimeException対応、セッション再生成 |
| `api/line/webhook.php` | 完了報告ハンドリング追加 |

### 4.3 追加した共通関数
```php
checkRateLimit()                          // レート制限（ファイルロック付き）
isSameDayReservation()                    // 当日予約判定
getCleaningDuration()                     // 清掃時間取得
checkReservationConflict()                // 予約重複チェック（清掃時間考慮）
getAvailableKeyCount()                    // 空き鍵数取得
isCompletionKeyword()                     // 完了キーワード判定
completeCleaningJob()                     // 清掃完了処理（冪等性確保）
getCleanerTodayJobs()                     // 清掃者の当日案件取得
sendSameDayNotification()                 // 当日通知送信
sendNotificationWithDedup()               // 重複防止通知
createCleaningJobForReservationImproved() // 改良版清掃案件生成
```

---

## 5. デプロイ済みファイル

| ファイル | 状態 |
|----------|------|
| includes/functions.php | ✅ |
| api/booking/check-availability.php | ✅ |
| pages/booking/complete.php | ✅ |
| cron/send_daily_notifications.php | ✅ |
| pages/apply/list.php | ✅ |
| api/line/webhook.php | ✅ |
| database/013_reservation_flow_improvement.sql | ✅ |

---

## 6. テスト結果

| テスト項目 | 結果 |
|-----------|------|
| 空き状況API | ✅ `{"available":true,"available_rooms":2}` |
| レート制限 | ✅ 複数リクエスト正常通過 |
| 清掃者案件一覧 | ✅ トークン認証・ページ表示OK |
| cronバッチ構文 | ✅ 構文エラーなし |
| DBマイグレーション | ✅ 全カラム・テーブル作成完了 |

---

## 7. 設定情報

### 管理システムURL
```
https://xs151334.xsrv.jp/kakurema/
```

### テスト用清掃者トークン
```
cleaner_id: 1
token: 908eb1be6293c3420503985b8678bc9675e5d41dc650378a8986e8dcdf3b86f2
URL: https://xs151334.xsrv.jp/kakurema/pages/apply/list.php?token=908eb1be6293c3420503985b8678bc9675e5d41dc650378a8986e8dcdf3b86f2
```

---

*Created: 2026-01-28*
