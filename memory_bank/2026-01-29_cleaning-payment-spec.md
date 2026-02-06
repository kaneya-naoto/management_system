# 清掃業務・支払い管理 仕様書（実装ベース）

## 1. 概要

カクレマ新基幹システムにおける清掃業務管理は、予約確定時に自動生成される清掃案件（cleaning_jobs）を中心に、担当者割当・通知・完了報告・支払い処理までのライフサイクル全体を管理する。

### 基本原則

- **顧客料金（reservations）と清掃報酬（cleaning_jobs）は完全に独立**。一方を変更しても他方に影響しない。
- 延長が発生しても清掃報酬は変わらない（extension_reward = 0）。
- 支払いレコードは案件完了時に金額確定保存され、以後変更しない。

### 関連テーブル

| テーブル | 役割 |
|----------|------|
| `cleaning_jobs` | 清掃案件（メイン） |
| `job_applications` | 応募管理 |
| `extensions` | 延長記録 |
| `cleaner_payments` | 支払い管理 |
| `notification_logs` | 通知履歴 |
| `daily_notification_logs` | 重複チェック付き通知ログ |
| `application_tokens` | 応募用トークン |
| `fixed_cleaners` | 店舗固定者設定 |

---

## 2. 清掃案件ライフサイクル

### 2.1 案件自動生成

**トリガー**: 予約ステータスが `confirmed` に変更された時点

**実装関数**: `createCleaningJobForReservation()`（`/public_html/includes/job_helpers.php`）

**生成処理**:

1. 清掃開始時刻 = 予約終了時刻（`end_time`）
2. 深夜跨ぎ対応: `end_time < start_time` の場合は翌日の日付を使用
3. `scheduled_at` = 清掃開始日 + 予約終了時刻
4. 清掃時間を `getCleaningDuration()` で取得（`sales_areas.cleaning_duration_minutes` 優先、フォールバック: `CLEANING_TIME_MINUTES` 定数またはデフォルト60分）
5. `scheduled_end_at` = `scheduled_at` + 清掃時間
6. 報酬は `stores.base_reward` から取得（フォールバック: `DEFAULT_CLEANING_REWARD` = 2,000円）

**生成される案件データ**:

| カラム | 値 |
|--------|-----|
| `reservation_id` | 元の予約ID |
| `store_id` | 予約の店舗ID |
| `sales_area_id` | 予約の営業区分ID |
| `scheduled_at` | 予約終了時刻（清掃開始予定） |
| `scheduled_end_at` | 清掃終了予定時刻 |
| `duration_minutes` | 清掃時間（分） |
| `base_reward` | 店舗の基本報酬額 |
| `status` | `unassigned` |
| `job_type` | `regular` |

### 2.2 固定者通知フロー

**実装関数**: `sendJobNotifications($jobId, 'fixed')`（`/public_html/includes/notification_helpers.php`）

**処理フロー**:

1. 該当店舗の有効な固定者を `priority` 順に検索（`LIMIT 1`）
2. 固定者専用トークンを生成（有効期限: 30分）
3. LINE Push通知を送信（個人宛メッセージ）
4. `notification_logs` に記録
5. `cleaning_jobs.notification_status` を `fixed_waiting` に更新
6. `cleaning_jobs.fixed_notification_sent_at` を記録

**固定者タイムアウト処理**: `processFixedCleanerTimeout()`

- 30分経過（デフォルト）で応答なしと判定
- `getNextFixedCleaner()` で次の固定者を探す（通知済み固定者を除外）
- 次の固定者がいれば再通知、いなければ公募開始（`sendJobNotifications($jobId, 'normal')`）

### 2.3 公募フロー

**実装関数**: `sendJobNotifications($jobId, 'normal')`

**処理フロー**:

1. 該当店舗に登録済みの全清掃者（`cleaner_stores`経由、`is_active=1`、`line_user_id`あり）を取得
2. 公開トークンを生成（`cleaner_id=null`、有効期限: 120分）
3. 全対象者に同一メッセージをLINE Push配信
4. 各送信を `notification_logs` に記録
5. `cleaning_jobs.notification_status` を `public_recruiting` に更新
6. `cleaning_jobs.public_notification_sent_at` を記録

### 2.4 応募・採用処理

**実装場所**: `/public_html/pages/jobs/detail.php` の `accept_application` アクション

**採用処理**（トランザクション内）:

1. 案件が `recruiting` 状態であることを確認（楽観ロック）
2. `assigned_cleaner_id` が未設定であることを確認
3. 応募者が有効な清掃者であることを確認
4. `cleaning_jobs` を更新: `assigned_cleaner_id` 設定、`status` → `assigned`
5. 採用した応募の `job_applications.status` → `accepted`
6. 他の応募を `rejected` に更新
7. 監査ログ記録
8. 全てコミット

### 2.5 完了処理

#### 管理画面からの完了

**実装場所**: `/public_html/pages/jobs/detail.php` の `update_status` アクション

1. 楽観ロック付きでステータスを `completed` に更新
2. `createPaymentForJob($jobId)` を呼び出して支払いレコードを自動生成

#### LINE経由での完了報告

**実装関数**: `completeCleaningJob($jobId, $cleanerId)`（`/public_html/includes/job_helpers.php`）

1. `FOR UPDATE` ロック付きで案件取得
2. ステータスが `assigned` であることを確認（冪等性: `completed`/`paid` の場合はエラーではなく重複メッセージを返す）
3. 早すぎる完了報告ガード: `scheduled_at` の15分前以降のみ許可
4. `status` → `completed`、`completed_at` 記録
5. `createPaymentForJob()` で支払いレコード自動生成
6. コミット

**完了キーワード判定**: `isCompletionKeyword()`
- 「完了」「終了」「終わりました」「done」にマッチ（大文字小文字不問）

### 2.6 急募フロー

**実装場所**: `/public_html/pages/jobs/detail.php` の `send_urgent` アクション

**条件**: `unassigned` または `recruiting` で `assigned_cleaner_id` が未設定

**処理**:

1. 楽観ロック付きで `is_urgent = 1`、`status = 'recruiting'` に更新
2. `sendJobNotifications($jobId, 'urgent')` で通知送信
3. 通知メッセージに「【急募】」プレフィックスと「お急ぎの案件です」注記を追加

**通知対象**: 該当店舗の全清掃者（固定者限定ではない）

---

## 3. ステータス遷移（cleaning_jobs）

```
unassigned ──→ recruiting ──→ assigned ──→ completed ──→ paid
     │              │
     └──────────────┘ （急募時: unassigned → recruiting）
```

### ステータス定義

| ステータス | DB値 | 日本語 | 説明 |
|------------|------|--------|------|
| 未割当 | `unassigned` | 未割当 | 案件生成直後 |
| 募集中 | `recruiting` | 募集中 | LINE公募中（固定者待ちも含む） |
| 確定 | `assigned` | 確定 | 担当者決定済み |
| 完了 | `completed` | 完了 | 清掃完了報告済み |
| 支払済 | `paid` | 支払済 | 報酬支払処理済み |

### 遷移ルール（実装）

| 操作 | 遷移元 | 遷移先 | 制約 |
|------|--------|--------|------|
| 募集開始 | `unassigned` | `recruiting` | 楽観ロック |
| 公募開始 | `unassigned`/`recruiting` | `recruiting` | 楽観ロック、`assigned_cleaner_id IS NULL` |
| 急募送信 | `unassigned`/`recruiting` | `recruiting` | 楽観ロック、`assigned_cleaner_id IS NULL`、`is_urgent=1` |
| 担当者割当 | `unassigned`/`recruiting` | `assigned` | `completed`/`paid`以外で可 |
| 応募者採用 | `recruiting` | `assigned` | トランザクション内、楽観ロック |
| 完了 | `assigned` | `completed` | 支払いレコード自動生成 |
| 支払済 | `completed` | `paid` | 支払い管理画面から |
| ステータス変更 | 任意（`paid`除く） | 任意 | `paid` → 他は不可 |

---

## 4. 清掃案件管理画面

### 4.1 一覧画面

**ファイル**: `/public_html/pages/jobs/list.php`

#### 表示カラム

| カラム | ソース |
|--------|--------|
| 予定日時 | `cj.scheduled_at` |
| 店舗 | `stores.name` + `sales_areas.name` |
| ステータス | バッジ表示 |
| 担当者 | `cleaners.name` |
| 応募数 | `job_applications` のCOUNT |
| 報酬 | `cj.base_reward`（円表記） |
| 操作 | 詳細リンク |

#### フィルタ

| フィルタ | デフォルト値 |
|----------|-------------|
| ステータス | タブ切替（すべて/未割当/募集中/確定/完了/支払済） |
| 開始日 | 当日 |
| 終了日 | 7日後 |

**ページネーション**: 20件/ページ

**ステータス別件数**: 画面上部にステータスタブとして表示（各タブに件数バッジ）

**店舗アクセス制御**: `getFilteredStoreIds()` によるサイドバー選択店舗ベースのフィルタリング

### 4.2 詳細画面（担当者割当・ステータス変更）

**ファイル**: `/public_html/pages/jobs/detail.php`

#### 表示セクション

1. **案件情報**: 予定日時、店舗、営業区分、種別（regular/urgent）、報酬、備考
2. **担当者**: 担当者名、電話番号、詳細リンク、変更ボタン（`completed`/`paid`時は非表示）
3. **応募者一覧**: 清掃者名、実績（完了件数）、応募日時、ステータス、採用ボタン
4. **延長履歴**: 延長時間、ステータス（承認済/保留/却下）、登録日時
5. **関連予約**: 顧客名、予約ステータス、日時、予約詳細リンク

#### サイドバー操作

1. **ステータス変更**: プルダウンで任意のステータスに変更可能（`paid`からの変更は不可）
2. **LINE通知**（`unassigned`/`recruiting`時のみ表示）:
   - 「募集を開始（固定者優先）」: 固定者がいれば優先通知、なければ公募
   - 「公募で開始（固定者スキップ）」: 全清掃者に公募通知
   - 「【急募】通知を送信」: confirm付き、全清掃者に急募通知
   - 通知状態表示（fixed_waiting/public_recruiting/completed）
3. **報酬設定**: base_reward を数値入力で更新（0〜1,000,000円）
4. **延長登録**（`assigned`/`completed`時のみ表示）:
   - 0.5〜5.0時間（0.5刻み）のセレクトボックス
   - 延長料金: `EXTENSION_PRICE_PER_HOUR`（1,000円/時間）を顧客請求に加算
   - 清掃報酬は変更しない
   - 予約の `end_time` と `extension_price`、`total_price` を更新
   - 日跨ぎ対応あり（end_time < start_time 判定）
   - `calculateAvailableExtension()` による延長可能時間の検証（TOCTOU対策: `FOR UPDATE`ロック後に再検証）

#### 担当者割当モーダル

- 該当店舗に登録済み（`cleaner_stores`）かつアクティブな清掃者のプルダウン選択
- 割当時にステータスを自動的に `assigned` に変更

---

## 5. 支払い管理

### 5.1 支払いレコード生成

**実装関数**: `createPaymentForJob($jobId)`（`/public_html/includes/job_helpers.php`）

**生成タイミング**:
- 案件ステータスを `completed` に変更した時
- `completeCleaningJob()` による完了報告時

**処理**:

1. `FOR UPDATE` ロック付きで案件取得（`assigned_cleaner_id IS NOT NULL` が必須）
2. `FOR UPDATE` ロック付きで既存支払いレコードの重複チェック
3. 重複がなければ `cleaner_payments` にINSERT

**生成される支払いデータ**:

| カラム | 値 |
|--------|-----|
| `job_id` | 案件ID |
| `cleaner_id` | 担当清掃者ID |
| `store_id` | 店舗ID |
| `base_amount` | `cleaning_jobs.base_reward` |
| `extension_amount` | 0（仕様: 延長報酬は常に0） |
| `total_amount` | `base_amount`（= `base_reward`） |
| `status` | `pending` |

**重複防止**:
- `FOR UPDATE` 行ロックによる排他制御
- `UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id)` によるDB制約

### 5.2 一覧画面

**ファイル**: `/public_html/pages/payments/list.php`

#### 集計サマリー（画面上部カード4枚）

| カード | 表示内容 |
|--------|---------|
| 総支払額 | 合計金額 + 件数 |
| 未払い | 未払い合計 + 件数 |
| 支払済 | 支払済合計 + 件数 |
| 平均報酬 | 総支払額 / 件数 |

#### フィルタ

| フィルタ | デフォルト値 |
|----------|-------------|
| ステータス | すべて（pending/paid） |
| 清掃者 | すべて（プルダウン） |
| 期間（から） | 当月1日 |
| 期間（まで） | 当月末日 |

#### テーブルカラム

| カラム | ソース |
|--------|--------|
| チェックボックス | `pending`の場合のみ表示 |
| 案件日 | `DATE(j.scheduled_at)` |
| 営業区分 | 店舗名 + `sales_areas.name` |
| 担当者 | `cleaners.name` + 電話番号 |
| 報酬 | `base_amount` |
| 総報酬 | `total_amount` |
| ステータス | 支払済（+支払日）/ 未払い |
| 操作 | 詳細リンク |

**ページネーション**: 30件/ページ

#### 一括支払い処理機能

- 全選択/個別選択チェックボックス
- 「一括支払い処理」ボタン（選択件数表示）
- JavaScript: 選択数に応じてボタンの有効/無効を切替
- 処理: `FOR UPDATE`ロック → ステータス検証 → 一括UPDATE（`paid_at=CURDATE()`, `paid_by=現在ユーザー`）
- 更新行数の厳密検証（期待数と一致しなければロールバック）

### 5.3 詳細画面（個別支払い）

**ファイル**: `/public_html/pages/payments/detail.php`

#### 表示セクション

1. **支払い情報**: ステータス、報酬、総報酬、支払い日、処理者、作成日時、備考
2. **案件情報**: 清掃日時、店舗・区分、案件ステータス、予約者、予約時間、案件詳細リンク
3. **担当清掃者**: 名前、電話番号、メール、清掃者詳細リンク

#### 個別支払い処理

- 「支払い処理」ボタン → モーダルダイアログ
- 入力項目: 支払い日（デフォルト: 当日）、備考（任意）
- 処理: `UPDATE cleaner_payments SET status='paid', paid_at=?, paid_by=?, notes=? WHERE id=? AND status='pending'`
- 更新行数が0の場合はエラー（既に処理済み）
- 監査ログ記録（`payment_complete`）

### 5.4 CSVエクスポート

**ファイル**: `/public_html/api/export/payments.php`

#### CSVカラム

| ヘッダー | 値 |
|----------|-----|
| 案件日 | `DATE(j.scheduled_at)` |
| 時間 | `TIME(j.scheduled_at)` (HH:MM) |
| 店舗 | `stores.name` |
| 営業区分 | `sales_areas.name` |
| 清掃者名 | `cleaners.name` |
| 電話番号 | `cleaners.phone` |
| 基本報酬 | `base_amount` |
| 延長報酬 | `extension_amount` |
| 総報酬 | `total_amount` |
| ステータス | 支払済/未払い |
| 支払日 | `paid_at` |

#### 仕様詳細

- フィルタ: 一覧画面と同一条件をクエリパラメータで受け取り
- 上限: 10,000件（超過時は400エラー）
- エンコーディング: UTF-8（BOM付き、Excel対応）
- チャンク処理: 500件単位でデータ取得・出力（メモリ効率化）
- 監査ログ: エクスポート実行を `logAudit('export', 'cleaner_payment', ...)` で記録
- 認証: `requireLogin()` 必須

---

## 6. シフト管理画面

**ファイル**: `/public_html/pages/shifts/list.php`

### 概要

清掃案件を日付別のタイムライン形式で表示する。シフト管理というよりは「日別案件ビュー」に近い。

### フィルタ

| フィルタ | デフォルト値 |
|----------|-------------|
| 期間（から） | 当日 |
| 期間（まで） | 6日後 |
| ステータス | すべて（unassigned/recruiting/assigned/completed/cancelled） |
| 担当者 | すべて（プルダウン） |

- 日付範囲: 最大31日制限
- 日付バリデーション: 正規表現チェック、前後矛盾チェック

### アラートサマリー

画面上部に以下のアラートを表示:

| アラート | 条件 | 表示色 |
|----------|------|--------|
| 緊急 | `recruiting` かつ（2時間以内 or `is_urgent`） | danger（赤） |
| 要対応 | `unassigned` | warning（黄） |
| 募集中 | `recruiting` かつ緊急でない | info（青） |

### 日別表示

- 日付ごとにカード分割
- 各日のヘッダー: 日付（Y年n月j日）+ 曜日 + 件数バッジ
- テーブルカラム: 時間、営業区分（急募バッジ付き）、担当者、報酬、ステータス、詳細リンク
- 行色分け:
  - `unassigned` → `table-warning`（黄色背景）
  - 緊急（recruiting + 2時間以内 or urgent） → `table-danger`（赤色背景）

### 凡例

画面下部にステータスバッジの凡例を表示

---

## 7. 報酬計算ロジック

### 定数一覧

| 定数名 | 値 | 説明 | 定義場所 |
|--------|-----|------|----------|
| `DEFAULT_CLEANING_REWARD` | 2,000円 | 店舗に`base_reward`未設定時のフォールバック | config.php |
| `EXTENSION_PRICE_PER_HOUR` | 1,000円 | 延長1時間あたりの顧客料金 | config.php |
| `DEFAULT_HOURLY_RATE` | 2,500円 | 営業区分に時間単価が未設定時のフォールバック | config.php |
| `MAX_EXTENSION_HOURS` | 5.0時間 | 最大延長時間 | config.php |

### 清掃報酬の決定

```
base_reward = stores.base_reward ?? DEFAULT_CLEANING_REWARD (2,000円)
```

- 案件生成時に `stores.base_reward` を参照
- 管理画面から `base_reward` を個別に変更可能（0〜1,000,000円）
- **延長しても清掃報酬は不変**（extension_reward = 0 が仕様決定事項）

### 顧客料金の計算

```
base_price     = sales_areas.hourly_rate × 利用時間
extension_price = EXTENSION_PRICE_PER_HOUR × 延長時間(時間)
total_price    = base_price + extension_price
```

### 支払い金額の算出

```
cleaner_payments.base_amount      = cleaning_jobs.base_reward
cleaner_payments.extension_amount = 0（常に0）
cleaner_payments.total_amount     = base_amount + extension_amount = base_amount
```

---

## 8. 楽観ロック・トランザクション処理

### 楽観ロック

全てのステータス変更操作で、WHERE条件に現在のステータスを含める楽観ロックを実装:

```sql
-- 例: ステータス変更
UPDATE cleaning_jobs SET status = ? WHERE id = ? AND status = ?
-- 更新行数が0なら競合として処理
```

**適用箇所**:
- ステータス変更（`update_status`）
- 募集開始（`start_recruiting`）
- 公募開始（`start_public`）
- 急募送信（`send_urgent`）
- 担当者割当（`assign_cleaner`）: `status NOT IN ('completed', 'paid')`
- 応募者採用（`accept_application`）: トランザクション内で `status = 'recruiting'`

### トランザクション処理

以下の操作は `dbBegin()` / `dbCommit()` / `dbRollback()` でトランザクション管理:

1. **応募者採用**: 案件更新 + 応募ステータス更新 + 他応募却下 + 監査ログ
2. **延長登録**: 延長レコード挿入 + 予約更新（end_time, extension_price, total_price） + 案件のscheduled_at更新
3. **支払いレコード生成**: `FOR UPDATE` ロック → 重複チェック → INSERT
4. **完了処理（LINE経由）**: `FOR UPDATE` ロック → ステータス更新 → 支払い生成
5. **一括支払い処理**: `FOR UPDATE` ロック → ステータス検証 → 一括UPDATE → 更新行数検証
6. **個別支払い処理**: 楽観ロック（`WHERE status = 'pending'`）

### TOCTOU対策

延長登録時に `FOR UPDATE` ロック取得後、延長可能時間を再検証する `calculateAvailableExtension()` を呼び出し、ロック取得前と後で条件が変化していないことを確認。

---

## 9. 主要関数一覧

### job_helpers.php

| 関数 | 説明 |
|------|------|
| `jobTypeLabel($type)` | 案件種別ラベル（regular→通常清掃, urgent→急募） |
| `applicationStatusInfo($status)` | 応募状態の表示情報（class + label） |
| `jobStatusLabel($status)` | 案件ステータスのラベルとCSSクラス |
| `createCleaningJobForReservation(...)` | 予約から清掃案件を自動生成 |
| `generateApplicationToken(...)` | 応募用トークン生成（固定者用30分、公開用120分） |
| `getApplicationUrl($token)` | 応募URL生成（`APP_URL/apply?token=...`） |
| `createPaymentForJob($jobId)` | 支払いレコード生成（FOR UPDATE + 重複チェック） |
| `isCompletionKeyword($text)` | 完了キーワード判定 |
| `completeCleaningJob($jobId, $cleanerId)` | 清掃完了処理（冪等性確保） |
| `getCleanerTodayJobs($cleanerId, $status)` | 清掃者の当日担当案件取得 |
| `sendSameDayNotification(...)` | 当日予約時の即時通知送信 |

### notification_helpers.php

| 関数 | 説明 |
|------|------|
| `sendLineNotification($lineUserId, $message, $maxRetries)` | LINE Push通知送信（リトライ付き、5xx系のみリトライ） |
| `sendJobNotifications($jobId, $type)` | 案件通知送信（fixed/normal/urgent） |
| `getNextFixedCleaner($storeId, $jobId)` | 次の固定者取得（通知済み除外） |
| `processFixedCleanerTimeout($timeoutMinutes)` | 固定者タイムアウト処理（cron用） |
| `sendNotificationWithDedup(...)` | 重複チェック付き通知送信 |
| `sendMail(...)` | メール送信 + email_logsログ記録 |
| `sendReservationConfirmMail($reservation)` | 予約確認メール |
| `sendReservationCancelMail($reservation)` | 予約キャンセルメール |
| `sendPasswordResetMail($email, $token)` | パスワードリセットメール |

### helpers.php（共通）

| 関数 | 清掃・支払い関連で使用 |
|------|----------------------|
| `statusLabel($status, $type)` | ステータス日本語表示 |
| `statusClass($status)` | ステータスCSSクラス |
| `formatMoney($amount)` | 金額フォーマット（円表記） |
| `formatDateTime($datetime)` | 日時フォーマット |
| `renderPagination(...)` | ページネーションHTML |
| `calculatePagination(...)` | ページネーション計算 |

---

## 10. spec仕様書との乖離

### 10.1 支払いステータス値の不一致

| 項目 | spec仕様書（payment.md） | 実装 |
|------|--------------------------|------|
| 支払いステータス | `unpaid` / `paid` | `pending` / `paid` |
| ENUMの記載 | `ENUM('unpaid', 'paid')` | 実際は `pending` を使用 |

**詳細**: spec仕様書 `payment.md` のテーブル定義とフロー図では `unpaid` を使用しているが、実装コード（`createPaymentForJob()`、`list.php`、`detail.php`）では全て `pending` を使用している。

### 10.2 延長報酬の記述矛盾

| 項目 | spec仕様書（cleaning_job.md） | spec仕様書（payment.md/pricing.md） | 実装 |
|------|-------------------------------|--------------------------------------|------|
| 延長報酬計算 | `base_reward × 0.5 × 延長時間` | 「常に0」 | 常に0 |

**詳細**: `cleaning_job.md` の「報酬計算」セクションでは `extension_reward = base_reward × 0.5 × 延長時間` と記載されているが、`payment.md` と `pricing.md`（統合仕様）では「延長時の清掃報酬は**常に0**」と記載。実装も常に0。`cleaning_job.md` の報酬計算セクションが古い仕様のまま残っている。

### 10.3 一覧画面のフィルタ項目差異

| フィルタ | spec仕様書（cleaning_job.md） | 実装 |
|----------|-------------------------------|------|
| 営業区分フィルタ | あり | **なし** |
| 担当者フィルタ | あり | **なし** |

**詳細**: spec仕様書では案件一覧に「営業区分」「担当者」フィルタが記載されているが、実装ではステータスタブ + 日付範囲フィルタのみ。

### 10.4 cleaner_paymentsテーブルのカラム差異

| カラム | spec仕様書 | 実装 |
|--------|-----------|------|
| `store_id` | 記載なし | **あり**（店舗アクセス制御に使用） |
| `paid_by` | 記載なし | **あり**（支払い処理者のユーザーID） |

**詳細**: 実装では `store_id` と `paid_by` カラムが追加されている。`store_id` は店舗ベースのアクセス制御に必要、`paid_by` は誰が支払い処理をしたかの監査情報。

### 10.5 案件一覧の表示項目差異

| 項目 | spec仕様書 | 実装 |
|------|-----------|------|
| 案件ID | あり | **なし**（一覧には非表示） |
| 予約日時 | あり | **なし**（scheduled_atのみ） |
| 報酬の内訳 | 「基本 + 延長」 | base_rewardのみ（延長報酬は常に0） |
| 店舗名 | 記載なし | **あり** |
| 応募数 | 記載なし | **あり** |

### 10.6 通知履歴の画面表示

| 項目 | spec仕様書 | 実装 |
|------|-----------|------|
| 通知履歴表示 | 案件詳細画面に記載あり | **通知ステータスのみ表示**（詳細な通知履歴一覧は未実装） |

**詳細**: spec仕様書では案件詳細画面に「通知履歴」セクションがあると記載されているが、実装では `notification_status` の現在状態表示（fixed_waiting/public_recruiting/completed）のみ。個々の通知ログの一覧表示はない。

### 10.7 支払い一覧のカラム差異

| カラム | spec仕様書（payment.md） | 実装 |
|--------|--------------------------|------|
| 延長報酬 | あり | **なし**（一覧テーブルには非表示） |
| CSVエクスポート | 記載なし | **あり**（ヘッダーにCSVダウンロードボタン） |
| 一括支払い | 記載あり | **あり**（チェックボックス選択式） |
| 集計サマリー | 記載あり | **あり** + 平均報酬（specにない追加情報） |

### 10.8 固定者通知の実装差異

| 項目 | spec仕様書（fixed_cleaner.md） | 実装 |
|------|--------------------------------|------|
| 固定者通知 | 全固定者に順次通知（ループ） | **最優先1名のみ通知**（LIMIT 1） |
| タイムアウト後 | 次の固定者 or 公募 | 同左（`processFixedCleanerTimeout()`で実装） |

**詳細**: spec仕様書のフロー図では全固定者に対して順次通知のループが描かれているが、実装では最優先の1名のみにまず通知し、タイムアウト後にcronで次の固定者に通知する仕組み。結果的に同じ動作になるが、初回通知が1名のみである点が異なる。

### 10.9 急募の自動発動条件の未実装

| 条件 | spec仕様書 | 実装 |
|------|-----------|------|
| 清掃予定2時間前で未割当 → 自動急募 | 記載あり | **未実装**（手動操作のみ） |
| 延長依頼を担当者が拒否 → 急募 | 記載あり | **未実装** |

**詳細**: spec仕様書では急募条件として「未充足（清掃予定時刻の2時間前でも未割当）」「延長NO（延長依頼を担当者が拒否）」が記載されているが、実装では管理画面からの手動操作のみ。シフト管理画面で2時間以内の案件を「緊急」としてハイライト表示する機能はあるが、自動で急募通知を送信する機能はない。

---

*作成日: 2026-01-29*
*ソース: 実装コード読解 + spec仕様書比較*
