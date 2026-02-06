# DBスキーマ概要

## ER図

```
[owners] ──→ [stores] ──→ [sales_areas]
                │
                ├── [users] ──→ [user_stores]
                │      └── [password_reset_tokens]
                │
                ├── [reservations] ──→ [key_assignments] ──→ [keys]
                │         │
                │         ├── [cleaning_jobs] ──→ [job_applications]
                │         │         │                    │
                │         │         ├── [extensions]     └── [cleaners]
                │         │         │
                │         │         ├── [cleaner_payments]
                │         │         │
                │         │         ├── [notification_logs]
                │         │         │
                │         │         ├── [application_tokens]
                │         │         │
                │         │         └── [daily_notification_logs]
                │         │
                │         └── [extension_requests]
                │
                ├── [fixed_cleaners] ──→ [cleaners]
                │
                └── [line_accounts] ──→ [line_rich_menus]

[cleaner_stores] (cleaners × stores)
[audit_logs]
[email_logs]
[login_attempts]
[webhook_events]
[webhook_rate_limits]
[ipass_view_tokens]
```

---

## テーブル一覧

### 組織・ユーザー系

| テーブル | 説明 | 主要カラム |
|----------|------|------------|
| `owners` | オーナー | id, name, email, phone |
| `stores` | 店舗 | id, owner_id, name, code, base_reward, opening_time, closing_time, is_24h_open, default_hourly_rate, max_duration_hours, min_duration_hours, booking_days_ahead |
| `sales_areas` | 内部営業区分 | id, store_id, name, hourly_rate, cleaning_duration_minutes, capacity, description, room_code |
| `users` | CMSユーザー | id, email, role, owner_id, status, store_id |
| `user_stores` | ユーザー×店舗紐付け | id, user_id, store_id |
| `password_reset_tokens` | パスワードリセット | id, user_id, token, expires_at |
| `login_attempts` | ログイン試行回数管理 | id, ip_address, attempts, last_attempt_at, locked_until |

### 予約・鍵系

| テーブル | 説明 | 主要カラム |
|----------|------|------------|
| `reservations` | 予約 | id, store_id, sales_area_id, status, source, line_user_id, line_display_name, payment_status, num_people, base_price, extension_price, total_price, extension_token |
| `keys` | 鍵番号 | id, sales_area_id, key_number, room_name, notes |
| `key_assignments` | 鍵割当 | id, reservation_id, key_id |
| `extension_requests` | 延長リクエスト | id, reservation_id, job_id, requested_minutes, status, response_token |

### 清掃系

| テーブル | 説明 | 主要カラム |
|----------|------|------------|
| `cleaners` | 清掃者 | id, line_user_id, name, ipass_code, registration_status, registration_token |
| `cleaner_stores` | 清掃者の対応店舗 | id, cleaner_id, store_id |
| `fixed_cleaners` | 固定者 | id, store_id, cleaner_id, priority |
| `cleaning_jobs` | 清掃案件 | id, reservation_id, store_id, status, completed_at, job_type, is_urgent, notification_status, scheduled_at, scheduled_end_at, duration_minutes, base_reward, extension_reward |
| `job_applications` | 案件応募 | id, job_id, cleaner_id, status |
| `extensions` | 延長 | id, job_id, extension_hours, additional_reward, status |
| `cleaner_payments` | 清掃者支払い | id, job_id, cleaner_id, store_id, base_amount, extension_amount, total_amount, status, paid_at, paid_by |
| `application_tokens` | 応募トークン（LINE経由） | id, token, job_id, cleaner_id, type, expires_at, used_at |

### LINE連携系

| テーブル | 説明 | 主要カラム |
|----------|------|------------|
| `line_accounts` | LINE公式アカウント設定 | id, account_type, store_id, name, channel_id, channel_secret, channel_access_token, liff_id, webhook_url |
| `line_rich_menus` | LINEリッチメニュー設定 | id, line_account_id, name, rich_menu_id, image_filename, size_type, menu_config, chat_bar_text, is_default |
| `webhook_events` | Webhook冪等性管理 | id, event_id, account_type, store_id, event_type, processed_at |
| `webhook_rate_limits` | Webhookレート制限 | id, ip_address, minute_bucket, request_count |

### ログ・監査系

| テーブル | 説明 | 主要カラム |
|----------|------|------------|
| `audit_logs` | 操作ログ | id, user_id, action, target_type, target_id, old_value, new_value, ip_address |
| `notification_logs` | LINE通知履歴 | id, cleaner_id, job_id, type, message_id, sent_at, response, responded_at |
| `email_logs` | メール送信履歴 | id, to_email, subject, type, reservation_id, status, sent_at |
| `daily_notification_logs` | 定点通知ログ | id, job_id, cleaner_id, notification_date, notification_type, status, notified_at |
| `ipass_view_tokens` | iPassコード閲覧用トークン | id, token, cleaner_id, expires_at, used_at |

---

## リレーション概要

### 組織階層

```
owners (1) ──→ (N) stores (1) ──→ (N) sales_areas
```

### ユーザー管理

```
users (N) ←──→ (N) stores （user_stores中間テーブル）
users.owner_id ──→ owners.id （OWNERロール用）
```

### 予約 → 清掃案件

```
reservations (1) ──→ (1) cleaning_jobs (1) ──→ (N) job_applications
                                        └──→ (N) extensions
                                        └──→ (1) cleaner_payments
                                        └──→ (N) notification_logs
                                        └──→ (N) application_tokens
                                        └──→ (N) daily_notification_logs
reservations (1) ──→ (N) extension_requests
```

### 鍵番号管理

```
sales_areas (1) ──→ (N) keys (1) ←── (N) key_assignments ──→ (1) reservations
```

### LINE連携

```
stores (1) ──→ (1) line_accounts (1) ──→ (N) line_rich_menus
```

### 清掃者管理

```
cleaners (N) ←──→ (N) stores （cleaner_stores中間テーブル）
stores (1) ──→ (N) fixed_cleaners ──→ cleaners
```

---

## ステータス定義（ENUM）

### users.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `active` | 有効 | 通常利用可能 |
| `inactive` | 無効 | 利用停止中 |
| `suspended` | 停止 | 管理者による利用停止 |

### users.role

| 値 | 日本語 | 説明 |
|----|--------|------|
| `HQ` | 統括本部 | 全店舗アクセス |
| `OWNER` | オーナー | 配下店舗アクセス（owner_idで紐付け） |
| `STORE` | 店舗 | 自店舗のみ |

### reservations.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `pending` | 保留 | 予約受付、未決済 |
| `confirmed` | 確定 | 決済完了 |
| `cancelled` | キャンセル | 取消済み |
| `completed` | 完了 | 利用終了 |

### reservations.source

| 値 | 日本語 | 説明 |
|----|--------|------|
| `web` | Web | Web経由予約 |
| `line` | LINE | LINE経由予約 |
| `phone` | 電話 | 電話予約 |
| `direct` | 直接 | 直接来店 |

### reservations.payment_status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `unpaid` | 未払い | 未決済 |
| `paid` | 支払済 | 決済完了 |
| `refunded` | 返金済 | 返金処理済み |

### cleaning_jobs.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `unassigned` | 未割当 | 案件生成直後 |
| `recruiting` | 募集中 | LINE公募中 |
| `assigned` | 確定 | 担当者決定 |
| `completed` | 完了 | 清掃完了 |
| `paid` | 支払済 | 報酬支払済 |

### cleaning_jobs.job_type

| 値 | 日本語 | 説明 |
|----|--------|------|
| `regular` | 通常 | 通常案件 |
| `urgent` | 急募 | 急募案件 |

### cleaning_jobs.notification_status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `pending` | 未送信 | 通知未開始 |
| `fixed_waiting` | 固定者待ち | 固定者への通知送信済み、回答待ち |
| `public_recruiting` | 公募中 | 公募通知送信済み |
| `completed` | 完了 | 担当者決定済み |

### job_applications.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `applied` | 応募中 | 応募済み |
| `accepted` | 採用 | 採用決定 |
| `rejected` | 不採用 | 不採用 |
| `cancelled` | 取消 | 応募取消 |

### extensions.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `pending` | 保留 | 申請中 |
| `approved` | 承認 | 承認済み |
| `rejected` | 却下 | 却下 |

### cleaner_payments.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `unpaid` | 未払い | 支払い未処理 |
| `paid` | 支払済 | 支払い完了 |

### notification_logs.type

| 値 | 日本語 | 説明 |
|----|--------|------|
| `normal` | 通常 | 通常通知 |
| `urgent` | 急募 | 急募通知 |
| `fixed` | 固定者 | 固定者向け通知 |
| `confirmed` | 確定 | 案件確定通知 |
| `extension` | 延長 | 延長通知 |
| `cancel` | キャンセル | キャンセル通知 |

### notification_logs.response

| 値 | 日本語 | 説明 |
|----|--------|------|
| `ok` | 承諾 | 応募承諾 |
| `ng` | 辞退 | 応募辞退 |
| `timeout` | タイムアウト | 回答期限切れ |
| `none` | 未回答 | 回答不要 |
| `pending` | 待機中 | 回答待ち |

### email_logs.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `sent` | 送信済 | 正常送信 |
| `failed` | 失敗 | 送信失敗 |
| `bounced` | バウンス | メール不達 |

### extension_requests.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `pending` | 保留 | リクエスト中 |
| `accepted` | 承認 | 承認済み |
| `declined` | 辞退 | 辞退 |
| `expired` | 期限切れ | 期限切れ |

### application_tokens.type

| 値 | 日本語 | 説明 |
|----|--------|------|
| `fixed` | 固定者 | 固定者向けトークン |
| `public` | 公募 | 公募向けトークン |

### line_accounts.account_type

| 値 | 日本語 | 説明 |
|----|--------|------|
| `store` | 店舗用 | 店舗向けLINE公式アカウント |
| `cleaner` | 清掃者用 | 清掃者向けLINE公式アカウント |

### line_rich_menus.size_type

| 値 | 日本語 | 説明 |
|----|--------|------|
| `large` | 大 | 大サイズメニュー |
| `compact` | コンパクト | コンパクトサイズ |
| `small` | 小 | 小サイズメニュー |

### cleaners.registration_status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `pending` | 未開始 | 登録開始前 |
| `profile_done` | プロフィール完了 | 基本情報入力済み |
| `stores_done` | 店舗選択完了 | 対応店舗選択済み |
| `completed` | 完了 | 登録完了 |

### daily_notification_logs.notification_type

| 値 | 日本語 | 説明 |
|----|--------|------|
| `immediate` | 即時 | 即時通知 |
| `daily` | 定点 | 定点通知 |

### daily_notification_logs.status

| 値 | 日本語 | 説明 |
|----|--------|------|
| `pending` | 未送信 | 送信待ち |
| `sent` | 送信済 | 送信完了 |
| `failed` | 失敗 | 送信失敗 |

---

## 外部キー制約一覧

### 組織・ユーザー系

| テーブル | カラム | 参照先 | ON DELETE | ON UPDATE |
|----------|--------|--------|-----------|-----------|
| `stores` | owner_id | owners(id) | RESTRICT | CASCADE |
| `sales_areas` | store_id | stores(id) | RESTRICT | CASCADE |
| `users` | store_id | stores(id) | SET NULL | CASCADE |
| `users` | owner_id | owners(id) | SET NULL | CASCADE |
| `user_stores` | user_id | users(id) | CASCADE | CASCADE |
| `user_stores` | store_id | stores(id) | CASCADE | CASCADE |
| `password_reset_tokens` | user_id | users(id) | CASCADE | CASCADE |

### 予約・鍵系

| テーブル | カラム | 参照先 | ON DELETE | ON UPDATE |
|----------|--------|--------|-----------|-----------|
| `reservations` | store_id | stores(id) | RESTRICT | CASCADE |
| `reservations` | sales_area_id | sales_areas(id) | RESTRICT | CASCADE |
| `keys` | sales_area_id | sales_areas(id) | RESTRICT | CASCADE |
| `key_assignments` | reservation_id | reservations(id) | CASCADE | CASCADE |
| `key_assignments` | key_id | keys(id) | RESTRICT | CASCADE |
| `extension_requests` | reservation_id | reservations(id) | CASCADE | - |
| `extension_requests` | job_id | cleaning_jobs(id) | SET NULL | - |

### 清掃系

| テーブル | カラム | 参照先 | ON DELETE | ON UPDATE |
|----------|--------|--------|-----------|-----------|
| `cleaner_stores` | cleaner_id | cleaners(id) | CASCADE | CASCADE |
| `cleaner_stores` | store_id | stores(id) | CASCADE | CASCADE |
| `fixed_cleaners` | store_id | stores(id) | CASCADE | CASCADE |
| `fixed_cleaners` | cleaner_id | cleaners(id) | CASCADE | CASCADE |
| `cleaning_jobs` | reservation_id | reservations(id) | CASCADE | CASCADE |
| `cleaning_jobs` | store_id | stores(id) | RESTRICT | CASCADE |
| `cleaning_jobs` | sales_area_id | sales_areas(id) | RESTRICT | CASCADE |
| `cleaning_jobs` | assigned_cleaner_id | cleaners(id) | SET NULL | CASCADE |
| `job_applications` | job_id | cleaning_jobs(id) | CASCADE | CASCADE |
| `job_applications` | cleaner_id | cleaners(id) | CASCADE | CASCADE |
| `extensions` | job_id | cleaning_jobs(id) | CASCADE | CASCADE |
| `cleaner_payments` | job_id | cleaning_jobs(id) | - | - |
| `cleaner_payments` | cleaner_id | cleaners(id) | - | - |
| `cleaner_payments` | store_id | stores(id) | - | - |
| `cleaner_payments` | paid_by | users(id) | - | - |
| `application_tokens` | job_id | cleaning_jobs(id) | - | - |
| `application_tokens` | cleaner_id | cleaners(id) | - | - |

### LINE連携系

| テーブル | カラム | 参照先 | ON DELETE | ON UPDATE |
|----------|--------|--------|-----------|-----------|
| `line_accounts` | store_id | stores(id) | SET NULL | - |
| `line_rich_menus` | line_account_id | line_accounts(id) | CASCADE | - |

### ログ・監査系

| テーブル | カラム | 参照先 | ON DELETE | ON UPDATE |
|----------|--------|--------|-----------|-----------|
| `audit_logs` | user_id | users(id) | SET NULL | CASCADE |
| `notification_logs` | cleaner_id | cleaners(id) | - | - |
| `notification_logs` | job_id | cleaning_jobs(id) | - | - |
| `email_logs` | reservation_id | reservations(id) | SET NULL | - |
| `daily_notification_logs` | job_id | cleaning_jobs(id) | CASCADE | - |
| `daily_notification_logs` | cleaner_id | cleaners(id) | CASCADE | - |
| `ipass_view_tokens` | cleaner_id | cleaners(id) | CASCADE | - |

---

## 関連ドキュメント

- [設計方針](design_policy.md)
- [主要テーブル定義](tables/)
- [組織・権限モデル](../overview/organization_model.md)

---

*Last Updated: 2026-01-29*
