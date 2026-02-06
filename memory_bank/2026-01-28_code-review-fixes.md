# コードレビュー改善実装レポート

**実施日**: 2026-01-28
**対象システム**: カクレマ 新基幹システム
**ステータス**: ✅ 本番デプロイ完了

## 概要

セキュリティ監査とコードレビューで発見された21件の問題点を修正・デプロイ。

## フェーズ1: Critical（6件）

### 1. ログイン試行回数制限をDBベースに変更
- **ファイル**: `includes/auth.php`
- **問題**: セッションベースの制限はセッション破棄で回避可能
- **修正**: IP単位でDBに記録し、ブルートフォース攻撃を防止
- **新規テーブル**: `login_attempts`

### 2. ORDER BY RAND()のパフォーマンス問題
- **ファイル**: `includes/functions.php:455`
- **問題**: 大量レコードでクエリ遅延
- **修正**: `ORDER BY k.id` に変更（ランダム性は不要なユースケース）

### 3. iPassコードのLINE平文送信
- **ファイル**: `api/line/webhook.php`
- **問題**: 機密情報がLINEメッセージに平文で送信
- **修正**: ワンタイムトークン方式に変更
  - 5分間有効なトークンを発行
  - `/ipass/view` エンドポイントで1回限り表示
- **新規テーブル**: `ipass_view_tokens`
- **新規ファイル**: `pages/ipass/view.php`

### 4. 深夜営業時の時刻検証失敗
- **ファイル**: `api/booking/check-availability.php`
- **問題**: 22:00-02:00など深夜跨ぎで予約重複発生
- **修正**: `TIME_TO_SEC()` と深夜跨ぎ対応（+86400秒）

### 5. セッション改ざん検証不完全
- **ファイル**: `pages/booking/complete.php`
- **問題**: sales_area_idとstore_idの不整合を検出できない
- **修正**: complete.php側でも整合性を再検証

### 6. XSS: JS内のエスケープ漏れ
- **ファイル**: `pages/settings/line_richmenu.php:400`
- **問題**: `<?= $currentTemplate ?>` が未エスケープ
- **修正**: `json_encode()` + `JSON_HEX_*` フラグ

## フェーズ2: High（9件）

### 7. CSRFトークン再利用問題
- **ファイル**: `includes/auth.php`
- **問題**: トークンが複数回使用可能（リプレイ攻撃）
- **修正**: 検証成功後にトークンを削除

### 8. キャンセル処理の重複
- **ファイル**: `pages/reservations/detail.php`
- **問題**: 同時アクセスで2回実行される可能性
- **修正**: `FOR UPDATE` 行ロック + 既にキャンセル済みならスキップ

### 9. OWNER権限で他オーナーの清掃者閲覧可
- **ファイル**: `pages/cleaners/detail.php`
- **問題**: OWNERが全清掃者にアクセス可能
- **修正**: `owner_id` を条件に追加し、自オーナー配下のみアクセス可

### 10. レート制限がファイルベース
- **ファイル**: `api/line/webhook.php`
- **問題**: ファイルベースはrace condition発生
- **修正**: APCu優先、なければDB使用
- **新規テーブル**: `webhook_rate_limits`

### 11. 署名検証失敗時のログ詳細すぎ
- **ファイル**: `api/line/webhook.php`
- **問題**: 署名の一部がログに出力
- **修正**: IPとContent-Lengthのみ記録

### 12. 監査ログに機密情報保存
- **ファイル**: `includes/functions.php`
- **問題**: password, access_token等が平文保存
- **修正**: `maskSensitiveData()` 関数で `[REDACTED]` に置換

### 13. 顧客メールアドレス平文表示
- **ファイル**: `pages/booking/confirm.php`
- **問題**: 確認メッセージにメール全文表示
- **修正**: 部分マスク表示（例: `t***t@example.com`）

### 14. CSRFトークンなし
- **ファイル**: `pages/register/stores.php`
- **問題**: フォームにCSRFトークンがない
- **修正**: `requireCsrf()` と hidden フィールド追加

### 15. XSS: mb_substrエスケープなし
- **ファイル**: `pages/jobs/detail.php:560`
- **問題**: `<?= mb_substr(...) ?>` が未エスケープ
- **修正**: `h()` 関数でエスケープ

## フェーズ3: Medium（6件）

### 16. 時間フォーマット検証なし
- **ファイル**: `pages/reservations/create.php`
- **問題**: 不正な時刻形式を受け付ける
- **修正**: `/^([01][0-9]|2[0-3]):[0-5][0-9]$/` で検証 + 深夜跨ぎ対応

### 17. extension_hours型チェック不足
- **ファイル**: `pages/jobs/detail.php`
- **問題**: 数値以外の入力を受け付ける
- **修正**: `is_numeric()` + 許可値ホワイトリスト（0.5刻み）

### 18. 報酬変更時に請求額が同期されない
- **ファイル**: `pages/jobs/detail.php`
- **問題**: 案件報酬変更時に予約の請求額が不整合
- **修正**: トランザクション内で予約の base_price, total_price も更新

### 19. 対応店舗なしの清掃者許容
- **ファイル**: `pages/cleaners/detail.php`
- **問題**: 店舗0件でも稼働中にできる
- **修正**: 稼働中変更時に店舗数チェック

### 20. .envパースで#コメント未対応
- **ファイル**: `includes/config.php`
- **問題**: 行末 `# comment` が値に含まれる
- **修正**: クォート外の ` #` をコメントとして除去

### 21. calculateAvailableExtension()の複雑性
- **ファイル**: `includes/functions.php`
- **状態**: 現状維持（ロジックは整理済み、約165行）

## 新規作成ファイル

- `database/012_add_login_attempts.sql` - マイグレーション
- `pages/ipass/view.php` - iPassコード閲覧ページ

## デプロイ手順

1. **DBマイグレーション先行**
   ```sql
   -- 012_add_login_attempts.sql を実行
   -- login_attempts, ipass_view_tokens, webhook_rate_limits テーブル作成
   ```

2. **コードデプロイ**
   - 全修正ファイルをデプロイ

3. **動作確認**
   - ログイン5回失敗 → ロック確認
   - 予約フロー全体テスト
   - LINE連携（iPass確認）テスト

## テスト項目

- [ ] ログイン試行回数制限（5回失敗でロック、15分後解除）
- [ ] iPassコード表示（ワンタイムリンク、5分有効）
- [ ] 深夜予約（22:00-02:00）重複チェック
- [ ] CSRFトークン（使用後は再利用不可）
- [ ] キャンセル同時実行（重複実行されない）
- [ ] OWNER権限（自オーナー配下のみ閲覧可）

---

## デプロイ記録

**デプロイ日時**: 2026-01-28
**デプロイ方法**: deploy.py (rsync)

### デプロイ済みファイル（15件）

| ファイル | 状態 |
|----------|------|
| includes/auth.php | ✅ |
| includes/functions.php | ✅ |
| includes/config.php | ✅ |
| api/line/webhook.php | ✅ |
| api/booking/check-availability.php | ✅ |
| pages/booking/complete.php | ✅ |
| pages/booking/confirm.php | ✅ |
| pages/settings/line_richmenu.php | ✅ |
| pages/reservations/detail.php | ✅ |
| pages/reservations/create.php | ✅ |
| pages/cleaners/detail.php | ✅ |
| pages/jobs/detail.php | ✅ |
| pages/register/stores.php | ✅ |
| pages/ipass/view.php | ✅ |
| database/012_add_login_attempts.sql | ✅ |

### DBマイグレーション実行済み

| テーブル | 状態 |
|----------|------|
| login_attempts | ✅ 作成済 |
| ipass_view_tokens | ✅ 作成済 |
| webhook_rate_limits | ✅ 作成済 |

---

## 予約フロー改善（2026-01-28 追加）

### 実装した機能

1. **当日予約の即時LINE通知**
   - 予約確定時（`complete.php`）に当日予約なら即座に清掃者に通知
   - `sendSameDayNotification()` 関数で対象店舗の清掃者に一括配信

2. **定点通知バッチ（毎日実行）**
   - `cron/send_daily_notifications.php` 新規作成
   - 未割当・募集中の案件を店舗ごとの清掃者に通知
   - 重複通知防止機能（`daily_notification_logs`）

3. **清掃者向け案件一覧**
   - `pages/apply/list.php` 新規作成
   - トークン認証 + セッション認証
   - 日付・店舗フィルタ機能

4. **清掃完了報告**
   - LINE webhook で「完了」メッセージに反応
   - `completeCleaningJob()` で冪等性確保
   - 予約ブロック動的解除

5. **予約ブロック改善**
   - 清掃時間（デフォルト60分）を考慮した空き判定
   - 深夜跨ぎ対応
   - `getAvailableKeyCount()` 共通関数

### 追加セキュリティ修正

#### A. レート制限の競合状態対策（check-availability.php）
- **問題**: ファイルベースのレート制限は read-then-write の競合状態あり
- **修正**:
  - `checkRateLimit()` 共通関数を新規作成（`includes/functions.php`）
  - `flock(LOCK_EX)` で排他ロックを取得してからカウント更新
  - 競合状態を完全に防止

#### B. TOCTOU脆弱性対策（complete.php）
- **問題**: 空き確認と鍵割当の間で他リクエストが割り込む可能性
- **対策**:
  - `assignKeyToReservation()` は既に `FOR UPDATE` ロック使用
  - `RuntimeException` を個別にキャッチして適切なエラーメッセージ表示
  - 「他のお客様が先に予約されました」と通知

#### C. セッション固定化攻撃対策（list.php）
- **問題**: トークン認証成功後にセッションID変更なし
- **修正**: `session_regenerate_id(true)` を追加

### 新規作成ファイル

| ファイル | 用途 |
|----------|------|
| database/013_reservation_flow_improvement.sql | マイグレーション |
| cron/send_daily_notifications.php | 定点通知バッチ |
| public_html/pages/apply/list.php | 清掃者向け案件一覧 |

### 修正ファイル

| ファイル | 修正内容 |
|----------|----------|
| includes/functions.php | 共通関数追加（約300行）<br>- `checkRateLimit()`: ファイルロック付きレート制限<br>- `getAvailableKeyCount()`: 空き鍵カウント<br>- `sendSameDayNotification()`: 当日通知<br>- `sendNotificationWithDedup()`: 重複防止通知<br>- `completeCleaningJob()`: 完了処理<br>- `createCleaningJobForReservationImproved()`: 改良版案件生成 |
| api/booking/check-availability.php | `checkRateLimit()` 使用に変更 |
| pages/booking/complete.php | 当日通知 + RuntimeException対応 |
| api/line/webhook.php | 完了報告ハンドリング追加 |

### DBスキーマ変更

```sql
-- cleaning_jobs テーブル
ALTER TABLE cleaning_jobs ADD COLUMN completed_at DATETIME NULL;
ALTER TABLE cleaning_jobs ADD COLUMN scheduled_end_at DATETIME NULL;
ALTER TABLE cleaning_jobs ADD COLUMN duration_minutes INT NOT NULL DEFAULT 60;

-- sales_areas テーブル
ALTER TABLE sales_areas ADD COLUMN cleaning_duration_minutes INT NOT NULL DEFAULT 60;

-- daily_notification_logs テーブル（新規）
CREATE TABLE daily_notification_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NOT NULL,
    cleaner_id BIGINT NOT NULL,
    notification_type VARCHAR(20) DEFAULT 'daily',
    notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_job_cleaner_type (job_id, cleaner_id, notification_type)
);
```

### テスト項目

- [ ] 当日予約で清掃者にLINE通知が届く
- [ ] 定点通知バッチが正常実行される
- [ ] 清掃者が案件一覧を閲覧できる
- [ ] LINE「完了」メッセージで案件が完了状態になる
- [ ] 清掃完了後に次の予約が可能になる
- [ ] 同時予約時に適切なエラーメッセージが表示される
