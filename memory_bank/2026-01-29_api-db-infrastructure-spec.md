# API・DB・共通基盤 仕様書（実装ベース）

## 1. 概要

本ドキュメントは「カクレマ 新基幹システム」のAPI、データベース、共通基盤レイヤーの実装仕様を、実際のソースコードから抽出・整理したものである。spec仕様書との乖離も合わせて記載する。

### システム基本情報

| 項目 | 内容 |
|------|------|
| システム名 | カクレマ管理システム（`APP_NAME` 定数） |
| 言語 | PHP 8.x（フレームワークなし・フルスクラッチ） |
| DB | MySQL 8.0（文字セット: `utf8mb4`） |
| フロントエンド | Bootstrap 5 + Bootstrap Icons |
| 外部連携 | LINE Messaging API（清掃者通知・店舗用予約誘導） |
| インフラ | Xserver（共有レンタルサーバー） |

---

## 2. ルーティング（index.php）

### 2.1 URL → ファイルマッピング一覧

エントリーポイント `public_html/index.php` で全リクエストを受け、`switch` 文とデフォルトの正規表現マッチングでルーティングする。

#### 静的ルート（固定パス）

| URL パス | 認証 | 権限 | ファイル | 説明 |
|----------|------|------|----------|------|
| `/` | 不要 | - | `pages/login.php` | ログインページ |
| `/login` | 不要 | - | `pages/login.php` | ログインページ |
| `/forgot-password` | 不要 | - | `pages/forgot-password.php` | パスワード忘れ |
| `/reset-password` | 不要 | - | `pages/reset-password.php` | パスワードリセット |
| `/logout` | 要（POST+CSRF） | - | （インライン処理） | ログアウト |
| `/switch-store` | 要（POST+CSRF） | - | （インライン処理） | 店舗切替 |
| `/dashboard` | 要 | - | `pages/dashboard.php` | ダッシュボード |
| `/reservations` | 要 | - | `pages/reservations/list.php` | 予約一覧 |
| `/reservations/new` | 要 | - | `pages/reservations/create.php` | 予約新規作成 |
| `/reservations/calendar` | 要 | - | `pages/reservations/calendar.php` | 予約カレンダー |
| `/reservations/gantt` | 要 | - | `pages/reservations/gantt.php` | 予約ガントチャート |
| `/jobs` | 要 | - | `pages/jobs/list.php` | 清掃案件一覧 |
| `/cleaners` | 要 | - | `pages/cleaners/list.php` | 清掃者一覧 |
| `/cleaners/new` | 要 | - | `pages/cleaners/create.php` | 清掃者新規登録 |
| `/keys` | 要 | - | `pages/keys/list.php` | 鍵番号一覧 |
| `/keys/new` | 要 | - | `pages/keys/create.php` | 鍵番号新規登録 |
| `/shifts` | 要 | - | `pages/shifts/list.php` | シフト管理 |
| `/payments` | 要 | - | `pages/payments/list.php` | 支払い管理 |
| `/logs` | 要 | OWNER | `pages/logs/list.php` | 操作ログ |
| `/settings` | 要 | OWNER | `pages/settings/index.php` | 設定トップ |
| `/settings/fixed-cleaners` | 要 | OWNER | `pages/settings/fixed-cleaners.php` | 固定者管理 |
| `/settings/store` | 要 | OWNER | `pages/settings/store.php` | 店舗設定 |
| `/settings/users` | 要 | OWNER | `pages/settings/users.php` | ユーザー管理 |
| `/settings/line` | 要 | OWNER | `pages/settings/line.php` | LINE連携設定 |
| `/settings/line/richmenu` | 要 | OWNER | `pages/settings/line_richmenu.php` | リッチメニュー管理 |
| `/settings/owners` | 要 | HQ | `pages/settings/owners.php` | オーナー管理 |
| `/settings/stores` | 要 | HQ | `pages/settings/stores.php` | 店舗管理 |

#### 公開ルート（認証不要）

| URL パス | ファイル | 説明 |
|----------|----------|------|
| `/booking` | `pages/booking/index.php` | 公開予約フォーム |
| `/booking/customer` | `pages/booking/customer.php` | 予約顧客情報入力 |
| `/booking/confirm` | `pages/booking/confirm.php` | 予約確認 |
| `/booking/complete` | `pages/booking/complete.php` | 予約完了処理 |
| `/booking/thanks` | `pages/booking/thanks.php` | 予約完了画面 |
| `/apply` | `pages/apply/index.php` | LINE清掃者応募画面 |
| `/extend` | `pages/extend/index.php` | 延長申請画面 |
| `/extend/respond` | `pages/extend/respond.php` | 延長申請応答 |
| `/register` | `pages/register/index.php` | LINE清掃者登録 |
| `/register/stores` | `pages/register/stores.php` | 登録時の店舗選択 |

#### APIルート

| URL パス | 認証 | メソッド | ファイル | 説明 |
|----------|------|---------|----------|------|
| `/api/line/webhook` | 署名検証 | POST | `api/line/webhook.php` | 清掃者用LINE Webhook |
| `/api/line/config` | 不要 | GET | `api/line/config.php` | LINE設定取得（LIFF用） |
| `/api/booking/check-availability` | 不要 | POST | `api/booking/check-availability.php` | 空き状況チェック |
| `/api/export/payments` | 要 | GET | `api/export/payments.php` | 支払いCSVエクスポート |
| `/api/export/reservations` | 要 | GET | `api/export/reservations.php` | 予約CSVエクスポート |
| `/api/reservations/calendar-data` | 要 | GET | `api/reservations/calendar-data.php` | カレンダーJSON取得 |
| `/api/settings/available-cleaners` | 要（OWNER） | GET | `api/settings/available-cleaners.php` | 利用可能清掃者取得 |

#### 動的ルート（正規表現マッチ）

| URL パターン | 認証 | ファイル | 変数 | 説明 |
|-------------|------|----------|------|------|
| `/booking/{STORE_CODE}` | 不要 | `pages/booking/index.php` | `$storeCode` | 店舗コード付き予約 |
| `/booking/{STORE_CODE}/{step}` | 不要 | `pages/booking/{step}.php` | `$storeCode`, `$step` | 店舗コード付き予約ステップ |
| `/reservations/{id}` | 要 | `pages/reservations/detail.php` | `$reservationId` | 予約詳細 |
| `/jobs/{id}` | 要 | `pages/jobs/detail.php` | `$jobId` | 清掃案件詳細 |
| `/cleaners/{id}` | 要 | `pages/cleaners/detail.php` | `$cleanerId` | 清掃者詳細 |
| `/keys/{id}` | 要 | `pages/keys/detail.php` | `$keyId` | 鍵詳細 |
| `/payments/{id}` | 要 | `pages/payments/detail.php` | `$paymentId` | 支払い詳細 |
| `/api/line/webhook/store/{store_code}` | 署名検証 | `api/line/webhook_store.php` | `$storeCode` | 店舗用LINE Webhook |
| `/extend/room/{room_code}` | 不要 | `pages/extend/index.php` | `$roomCode` | 部屋コード方式延長申請 |

**動的ルートのバリデーション**:
- `{STORE_CODE}`: `[A-Za-z0-9_-]+`（予約ステップ名 customer/confirm/complete/thanks を除外）
- `{id}`: `\d+`（数値のみ）
- `{room_code}`: `[a-z0-9]{8,32}`（8-32文字の英数字、DoS対策の長さ制限付き）

### 2.2 認証バイパスルート（公開ページ）

以下のルートは `requireLogin()` を呼ばないため、認証不要でアクセス可能:

1. `/`, `/login`, `/forgot-password`, `/reset-password` -- 認証系
2. `/booking/**` -- 公開予約フォーム
3. `/apply` -- LINE清掃者応募
4. `/extend`, `/extend/respond`, `/extend/room/{room_code}` -- 延長申請
5. `/register`, `/register/stores` -- LINE清掃者登録
6. `/api/line/webhook`, `/api/line/webhook/store/{store_code}` -- LINE Webhook（署名検証あり）
7. `/api/line/config` -- LINE設定取得
8. `/api/booking/check-availability` -- 空き状況チェック（レート制限あり）

### 2.3 .htaccess設定

**ファイル**: `public_html/.htaccess`

```
RewriteEngine On

# 存在するファイル/ディレクトリはそのまま配信
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# それ以外はすべて index.php へ転送
RewriteRule ^(.*)$ index.php [QSA,L]
```

**セキュリティヘッダー**:

| ヘッダー | 値 |
|---------|-----|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-XSS-Protection` | `1; mode=block` |

**アクセス制限**:
- `includes/` ディレクトリへの直接アクセスは 403 Forbidden

**PHP設定**:
- `upload_max_filesize`: 10M
- `post_max_size`: 10M

**HTTPS**: コメントアウト状態（本番で有効化予定）

---

## 3. DB接続・クエリ関数（db.php）

### 3.1 接続設定

**接続方式**: PDO（シングルトンパターン）

```php
function getDb(): PDO // staticで接続を保持
```

**PDOオプション**:

| オプション | 値 | 説明 |
|-----------|-----|------|
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | エラー時に例外 |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | 連想配列で取得 |
| `ATTR_EMULATE_PREPARES` | `false` | ネイティブプリペアドステートメント |

**DSN形式**: `mysql:host={DB_HOST};dbname={DB_NAME};charset=utf8mb4`

### 3.2 CRUD関数一覧

| 関数 | 戻り値 | 説明 |
|------|--------|------|
| `dbSelect(string $sql, array $params = []): array` | 全行の連想配列 | SELECT（複数行） |
| `dbSelectOne(string $sql, array $params = []): ?array` | 1行 or null | SELECT（1行） |
| `dbInsert(string $table, array $data): int` | 挿入ID | INSERT |
| `dbUpdate(string $table, array $data, string $where, array $whereParams = []): int` | 影響行数 | UPDATE |
| `dbSoftDelete(string $table, string $where, array $whereParams = []): int` | 影響行数 | 論理削除（`deleted_at = NOW()`） |
| `dbExecute(string $sql, array $params = []): int` | 影響行数 | 汎用SQL実行 |
| `buildInClause(array $values): array` | `['placeholders' => string, 'params' => array]` | IN句構築 |
| `dbSelectPaginated(string $sql, array $params, int $limit, int $offset): array` | 全行 | ページネーション付きSELECT |

**セキュリティ検証機能**:

| 関数 | 目的 |
|------|------|
| `validateTableName(string $table)` | テーブル名をホワイトリストで検証 |
| `validateColumnName(string $column)` | カラム名を英数字+アンダースコアのみ許可 |
| `validateWhereClause(string $where)` | セミコロン、コメント、UNION、常時TRUE条件を禁止 |

**許可テーブル一覧** (`ALLOWED_TABLES` 定数):
`owners`, `stores`, `sales_areas`, `users`, `user_stores`, `password_reset_tokens`, `keys`, `reservations`, `key_assignments`, `cleaners`, `cleaner_stores`, `fixed_cleaners`, `cleaning_jobs`, `job_applications`, `application_tokens`, `extensions`, `extension_requests`, `cleaner_payments`, `audit_logs`, `notification_logs`, `email_logs`, `login_attempts`, `line_accounts`, `line_rich_menus`, `webhook_events`

### 3.3 ページネーション

**制約**:
- `limit`: 最小1、最大100
- `offset`: 最小0

**ヘルパー関数**:

```php
function calculatePagination(int $totalCount, int $perPage, int $requestedPage): array
// 戻り値: ['page' => int, 'offset' => int, 'totalPages' => int]

function getPagedList(string $countQuery, string $selectQuery, array $params, int $perPage = 20, int $page = 1): array
// 戻り値: ['data' => array, 'pagination' => array, 'totalCount' => int]
```

**ページネーションUI**: `renderPagination()` でBootstrapベースのページネーションHTMLを生成

### 3.4 トランザクション

| 関数 | 説明 |
|------|------|
| `dbBegin()` | トランザクション開始 |
| `dbCommit()` | コミット |
| `dbRollback()` | ロールバック（`inTransaction()` チェック付き） |

---

## 4. 設定（config.php）

### 4.1 環境変数

`.env` ファイルを手動パースして `putenv()` で登録する方式。既存環境変数は上書きしない。

**.envパーサーの仕様**:
- `KEY=VALUE` 形式
- `#` 始まりの行はコメント
- シングル/ダブルクォートのサポート
- クォートなしの場合 ` #` でインラインコメント除去

**DB接続環境変数**:

| 環境変数 | デフォルト | 説明 |
|---------|-----------|------|
| `DB_HOST` | `localhost` | DBホスト |
| `DB_NAME` | `kakurema_db` | DB名 |
| `DB_USER` | `kakurema_user` | DBユーザー |
| `DB_PASS` | （本番必須） | DBパスワード（本番未設定時500エラー） |
| `APP_DEBUG` | `false` | デバッグモード |
| `APP_URL` | `https://example.com` | アプリケーションURL |

**LINE設定環境変数**:

| 環境変数 | 説明 |
|---------|------|
| `LINE_CHANNEL_ID` | LINEチャネルID |
| `LINE_CHANNEL_SECRET` | LINEチャネルシークレット |
| `LINE_CHANNEL_ACCESS_TOKEN` | LINEアクセストークン |

### 4.2 定数一覧

| 定数名 | 値 | 説明 |
|--------|-----|------|
| `APP_NAME` | `'カクレマ管理システム'` | アプリケーション名 |
| `APP_URL` | 環境変数 | アプリケーションURL |
| `DEBUG_MODE` | `APP_DEBUG === 'true'` | デバッグモード |
| `DB_HOST` | 環境変数 | DBホスト |
| `DB_NAME` | 環境変数 | DB名 |
| `DB_USER` | 環境変数 | DBユーザー |
| `DB_PASS` | 環境変数 | DBパスワード |
| `DB_CHARSET` | `'utf8mb4'` | DB文字セット |
| `PASSWORD_COST` | `12` | bcryptコスト |
| `SESSION_LIFETIME` | `28800`（8時間） | セッション有効期限（秒） |
| `CSRF_TOKEN_NAME` | `'_token'` | CSRFトークンフィールド名 |
| `CSRF_TOKEN_LIFETIME` | `3600`（1時間） | CSRFトークン有効期限（秒） |
| `CSRF_TOKEN_LENGTH` | `64` | CSRFトークン長（16進数文字列） |
| `LOGIN_MAX_ATTEMPTS` | `5` | ログイン最大試行回数 |
| `LOGIN_LOCKOUT_TIME` | `900`（15分） | ロックアウト時間（秒） |
| `DEFAULT_CLEANING_REWARD` | `2000` | デフォルト清掃基本報酬（円） |
| `EXTENSION_PRICE_PER_HOUR` | `1000` | 延長1時間あたりの顧客料金（円） |
| `MAX_EXTENSION_HOURS` | `5.0` | 最大延長時間（時間） |
| `CLEANING_TIME_MINUTES` | `60` | 清掃時間（分） |
| `DEFAULT_HOURLY_RATE` | `2500` | デフォルト時間単価（円） |
| `DEFAULT_CAPACITY` | `4` | デフォルト定員（名） |
| `MAX_BOOKING_DURATION_HOURS` | `8` | 最大予約時間 |
| `MIN_BOOKING_DURATION_HOURS` | `1` | 最小予約時間 |
| `BASE_PATH` | 実行時算出 | サブディレクトリ対応用ベースパス |

**セッション設定** (php.ini 上書き):

| 設定 | 値 |
|------|-----|
| `session.cookie_httponly` | `1` |
| `session.cookie_secure` | HTTPS時 `1` |
| `session.use_strict_mode` | `1` |
| `session.cookie_samesite` | `Lax` |

**タイムゾーン**: `Asia/Tokyo`

---

## 5. 共通関数（functions.php / helpers.php）

### 5.0 functions.php（ハブファイル）

`functions.php` は後方互換ラッパーとして以下を `require_once` するのみ:
- `helpers.php`
- `reservation_helpers.php`
- `job_helpers.php`
- `notification_helpers.php`
- `audit_helpers.php`
- `user_helpers.php`

### 5.1 helpers.php 関数一覧

#### HTTP・リダイレクト

| 関数 | 説明 |
|------|------|
| `redirect(string $url): void` | 安全なリダイレクト（Open Redirect対策: 相対パスまたは同一ドメインのみ） |
| `url(string $path): string` | サブディレクトリ対応URL生成 |
| `jsonResponse(array $data, int $statusCode = 200): void` | JSONレスポンス出力 |
| `isPost(): bool` | POSTリクエスト判定 |
| `isGet(): bool` | GETリクエスト判定 |
| `input(string $key, $default = null)` | POST/GETパラメータ取得 |

#### エスケープ・フォーマット

| 関数 | 説明 |
|------|------|
| `h(?string $str): string` | HTMLエスケープ（`ENT_QUOTES`, `UTF-8`） |
| `formatDate(?string $datetime, string $format = 'Y/m/d'): string` | 日付フォーマット |
| `formatDateTime(?string $datetime, string $format = 'Y/m/d H:i'): string` | 日時フォーマット |
| `formatMoney(?int $amount): string` | 金額フォーマット（`number_format + '円'`） |
| `formatTime(?string $time): string` | 時間フォーマット（`HH:MM`） |
| `formatTimeRange(?string $start, ?string $end): string` | 時間範囲フォーマット |
| `getDayName(int $dayOfWeek): string` | 曜日名取得（日〜土） |

#### フラッシュメッセージ・エラー

| 関数 | 説明 |
|------|------|
| `setFlash(string $type, string $message): void` | フラッシュメッセージ設定 |
| `getFlash(): ?array` | フラッシュメッセージ取得（取得後削除） |
| `flashSuccess(string $message): void` | 成功メッセージ設定 |
| `flashError(string $message): void` | エラーメッセージ設定 |
| `getErrors(): array` | バリデーションエラー取得 |
| `setErrors(array $errors): void` | バリデーションエラー設定 |
| `clearErrors(): void` | バリデーションエラークリア |
| `old(string $key, $default = '')` | 古い入力値取得 |
| `setOld(array $data): void` | 古い入力値設定 |
| `clearOld(): void` | 古い入力値クリア |

#### ステータスラベル

| 関数 | 説明 |
|------|------|
| `statusLabel(string $status, string $type = 'job'): string` | ステータスの日本語表示 |
| `statusClass(string $status): string` | ステータスのBootstrap CSSクラス |

ステータスラベルマッピング:

| type | ステータス | 日本語 |
|------|----------|--------|
| job | unassigned | 未割当 |
| job | recruiting | 募集中 |
| job | assigned | 確定 |
| job | completed | 完了 |
| job | paid | 支払済 |
| reservation | pending | 保留 |
| reservation | confirmed | 確定 |
| reservation | cancelled | キャンセル |
| reservation | completed | 完了 |

#### ページネーション

| 関数 | 説明 |
|------|------|
| `renderPagination(int $currentPage, int $totalPages, array $queryParams = []): string` | ページネーションHTML生成 |
| `calculatePagination(int $totalCount, int $perPage, int $requestedPage): array` | ページネーション計算 |
| `getPagedList(...)` | ページネーション付きリスト取得定型処理 |

#### 営業区分

| 関数 | 説明 |
|------|------|
| `getAccessibleSalesAreas(array $storeIds): array` | アクセス可能な営業区分一覧取得 |
| `findSalesArea(array $salesAreas, int $salesAreaId): ?array` | 配列から営業区分検索 |

### 5.2 auth.php 関数一覧

#### セッション・認証

| 関数 | 説明 |
|------|------|
| `startSession(): void` | セッション開始（未開始時のみ） |
| `login(string $email, string $password): bool\|string` | ログイン処理 |
| `logout(): void` | ログアウト処理（セッション破棄+Cookie削除） |
| `isLoggedIn(): bool` | ログイン状態確認（有効期限チェック付き） |
| `currentUser(bool $refresh = false): ?array` | ログインユーザー情報取得（リクエスト内キャッシュ） |
| `requireLogin(): void` | ログイン必須チェック（未ログインでリダイレクト） |
| `getCurrentUserId(): ?int` | 現在のユーザーID取得 |

**ログイン処理の詳細**:
1. ログインロックチェック（DBベース・IP単位）
2. ユーザー検索（`email` + `status = 'active'` + `deleted_at IS NULL`）
3. Timing Attack 対策（ユーザー不在でも `password_verify` 実行）
4. 失敗時: `recordLoginAttempt()` でIP単位の試行回数を記録
5. 成功時: セッション再生成（固定攻撃対策）、`last_login_at` 更新

#### 権限管理

| 関数 | 説明 |
|------|------|
| `hasRole(string $requiredRole): bool` | 権限チェック（3階層: HQ > OWNER > STORE） |
| `requireRole(string $role): void` | 権限必須チェック（403エラー） |
| `canAccessStore(int $storeId): bool` | 店舗アクセス権チェック |
| `requireStoreAccess(int $storeId): void` | 店舗アクセス権必須チェック |
| `getAccessibleStoreIds(): array` | アクセス可能な店舗ID一覧取得 |
| `setSelectedStore(?int $storeId): bool` | 店舗選択セッション保存 |
| `getSelectedStore(): ?int` | 選択中店舗ID取得（権限チェック付き） |
| `getFilteredStoreIds(): array` | クエリ用店舗ID配列取得 |

**権限階層**:

| ロール | レベル | アクセス範囲 |
|--------|--------|-------------|
| `HQ` | 3 | 全店舗 |
| `OWNER` | 2 | 自オーナー配下店舗（`owner_id` ベース） |
| `STORE` | 1 | `user_stores` で紐付く店舗のみ |

#### CSRF保護

| 関数 | 説明 |
|------|------|
| `generateCsrfToken(): string` | CSRFトークン生成（有効期限内は再利用） |
| `verifyCsrfToken(?string $token): bool` | CSRFトークン検証（成功後に削除=リプレイ攻撃対策） |
| `requireCsrf(): void` | CSRF検証必須（403エラー） |
| `hashPassword(string $password): string` | パスワードハッシュ生成（bcrypt, cost=12） |

#### ブルートフォース対策

| 関数 | 説明 |
|------|------|
| `isLoginLocked(string $email): bool` | ログインロック判定（IP単位） |
| `getLoginLockRemaining(string $email): int` | ロック解除残り秒数 |
| `recordLoginAttempt(string $email, bool $success): void` | 試行記録（成功時リセット） |

### 5.3 user_helpers.php 関数一覧

| 関数 | 説明 |
|------|------|
| `generateIPassCode(): string` | iPassコード生成（6桁英数字、重複チェック、最大100回試行） |
| `generatePasswordResetToken(string $email): ?string` | パスワードリセットトークン生成（1ユーザー1トークンポリシー、SHA-256ハッシュでDB保存） |
| `verifyPasswordResetToken(string $token): ?array` | トークン検証（有効期限+未使用+アクティブユーザー） |
| `resetPassword(string $token, string $newPassword): bool` | パスワードリセット実行（トランザクション内） |

### 5.4 audit_helpers.php 関数一覧

| 関数 | 説明 |
|------|------|
| `maskSensitiveData(?array $data): ?array` | 機密データのマスク（再帰対応） |
| `logAudit(string $action, string $targetType, ?int $targetId, ?array $oldValue, ?array $newValue): ?int` | 操作ログ記録 |
| `checkRateLimit(string $key, int $maxRequests = 30, int $windowSeconds = 60): array` | ファイルロック付きレート制限チェック |

**マスク対象キー**: `password`, `password_hash`, `secret`, `token`, `access_token`, `channel_access_token`, `channel_secret`, `api_key`, `api_secret`, `ipass_code`, `registration_token`, `reset_token`, `line_user_id`, `credit_card`, `card_number`, `cvv`, `pin`

**レート制限の仕組み**:
- ファイルベース（`sys_get_temp_dir() . '/rate_limits/'`）
- 排他ファイルロック（`LOCK_EX`）で競合状態対策
- フェイルオープン（ファイル操作失敗時は許可）

---

## 6. API エンドポイント一覧

### 6.1 LINE API

#### POST /api/line/webhook（清掃者用）

**認証**: LINE署名検証（X-Line-Signature ヘッダー、HMAC-SHA256）

**LINE設定取得元**: `line_accounts` テーブル（`account_type = 'cleaner'`）

**レート制限**: IP単位 60リクエスト/分
- APCu利用可能時: APCuベース
- APCu不可時: `webhook_rate_limits` DBテーブルベース

**処理イベント**:

| イベント | 処理内容 |
|---------|---------|
| `follow`（友だち追加） | 既存登録チェック → 登録済みなら挨拶 / 未登録なら登録URL送信 |
| `message`（メッセージ） | 未登録→登録URL / `ipass`→ワンタイムトークンでiPass表示 / 完了キーワード→清掃完了報告 / `ヘルプ`→使い方案内 |
| `postback`（ボタン） | `check_jobs`→募集中案件一覧 / `complete_job`→清掃完了報告 |

**メッセージ送信**: Push API優先（Reply APIはタイムアウトしやすいため）

#### POST /api/line/webhook/store/{store_code}（店舗用）

**認証**: LINE署名検証（店舗ごとの `channel_secret`）

**冪等性制御**: `webhookEventId` で処理済みチェック（`webhook_events` テーブル）

**処理イベント**:

| イベント | 処理内容 |
|---------|---------|
| `follow` | ウェルカムメッセージ + LIFF ID があれば予約ボタン |
| `unfollow` | ログ記録のみ |
| `message` | キーワード応答: 「予約」→予約URL / 「場所/アクセス/住所」→住所情報 / 「営業/時間」→営業時間 |
| `postback` | `booking`→予約URL |

#### GET /api/line/config

**認証**: なし

**パラメータ**: `store_id`（必須）

**レスポンス**: `{ "has_config": bool, "liff_id": string|null }`（機密情報は含まない）

### 6.2 Booking API

#### POST /api/booking/check-availability

**認証**: なし（レート制限あり: 30リクエスト/分/IP）

**リクエスト**（JSON）:
```json
{
  "sales_area_id": 1,
  "reservation_date": "2026-01-29",
  "start_time": "14:00",
  "end_time": "18:00"
}
```

**バリデーション**:
- `sales_area_id`: 正の整数
- `reservation_date`: `YYYY-MM-DD` 形式、過去日不可
- `start_time` / `end_time`: `HH:MM` 形式（00-23:00-59）
- 予約時間: 1-8時間
- 深夜またぎ対応（`endMinutes <= startMinutes` の場合 +24h）

**レスポンス**:
```json
{
  "available": true,
  "message": "予約可能です",
  "available_rooms": 3
}
```

### 6.3 Reservations API

#### GET /api/reservations/calendar-data

**認証**: ログイン必須

**パラメータ**: `start`, `end`（日付、デフォルト: 当月初日〜翌月末日）

**レスポンス**: FullCalendar互換JSON

```json
[
  {
    "id": 1,
    "title": "顧客名 (N名)",
    "start": "2026-01-29T14:00:00",
    "end": "2026-01-29T18:00:00",
    "color": "#0d6efd",
    "url": "/reservations/1",
    "extendedProps": { "status": "confirmed", "store": "新宿店", "area": "本館" }
  }
]
```

**ステータス色**:
- pending: `#6c757d`（グレー）
- confirmed: `#0d6efd`（青）
- completed: `#198754`（緑）
- cancelled: `#dc3545`（赤）

### 6.4 Export API

#### GET /api/export/payments

**認証**: ログイン必須

**フィルタパラメータ**: `status`, `cleaner_id`, `date_from`, `date_to`

**上限**: 10,000件（超過時は400エラー）

**出力**: BOM付きUTF-8 CSV（Excel対応）、チャンク単位出力（500件ずつ、メモリ効率化）

**CSVカラム**: 案件日, 時間, 店舗, 営業区分, 清掃者名, 電話番号, 基本報酬, 延長報酬, 総報酬, ステータス, 支払日

**監査ログ**: エクスポート実行を `logAudit()` で記録

#### GET /api/export/reservations

**認証**: ログイン必須

**フィルタパラメータ**: `status`, `date_from`, `date_to`

**上限**: 10,000件

**CSVカラム**: 予約日, 開始時間, 終了時間, 店舗, 営業区分, 顧客名, 電話番号, メール, 人数, 基本料金, 合計料金, 予約経路, 決済状態, ステータス, 備考

**予約経路ラベル**: web→Web予約, line→LINE, phone→電話, direct→直接来店
**決済状態ラベル**: unpaid→未払い, paid→支払済, refunded→返金済

### 6.5 Settings API

#### GET /api/settings/available-cleaners

**認証**: ログイン必須 + OWNERロール以上

**パラメータ**: `store_id`（必須）

**レスポンス**: 指定店舗に対応していて、固定者未登録の清掃者一覧

```json
{
  "cleaners": [
    { "id": 1, "name": "田中花子", "phone": "090-1111-2222" }
  ]
}
```

---

## 7. DBスキーマ（マイグレーション順）

### 7.1 テーブル一覧

マイグレーション001〜014で定義される全テーブル:

| カテゴリ | テーブル名 | 説明 | 初出マイグレーション |
|---------|-----------|------|---------------------|
| 組織・ユーザー系 | `owners` | オーナー | 001 |
| | `stores` | 店舗 | 001 |
| | `sales_areas` | 内部営業区分 | 001 |
| | `users` | CMSユーザー | 001 |
| | `user_stores` | ユーザー×店舗紐付け | 001 |
| | `password_reset_tokens` | パスワードリセットトークン | 001 |
| 予約・鍵系 | `keys` | 鍵番号 | 001 |
| | `reservations` | 予約 | 001 |
| | `key_assignments` | 鍵割当 | 001 |
| 清掃系 | `cleaners` | 清掃者 | 001 |
| | `cleaner_stores` | 清掃者の対応店舗 | 001 |
| | `fixed_cleaners` | 固定者 | 001 |
| | `cleaning_jobs` | 清掃案件 | 001 |
| | `job_applications` | 案件応募 | 001 |
| | `extensions` | 延長 | 001 |
| | `cleaner_payments` | 清掃者支払い | 001（004で再定義） |
| ログ・監査系 | `audit_logs` | 操作ログ | 001（005で再定義） |
| | `notification_logs` | 通知履歴 | 001（004で再定義） |
| | `email_logs` | メール送信履歴 | 001（005で再定義） |
| | `login_attempts` | ログイン試行 | 001（012で再定義） |
| LINE連携 | `line_accounts` | LINE公式アカウント設定 | 010 |
| | `line_rich_menus` | LINEリッチメニュー設定 | 010 |
| | `webhook_events` | Webhook冪等性管理 | 010 |
| 応募管理 | `application_tokens` | 応募トークン | 004 |
| 延長リクエスト | `extension_requests` | 延長リクエスト | 007 |
| セキュリティ | `ipass_view_tokens` | iPassコード閲覧用ワンタイムトークン | 012 |
| | `webhook_rate_limits` | Webhookレート制限 | 012 |
| 通知管理 | `daily_notification_logs` | 定点通知ログ | 013 |

### 7.2 主要テーブルのカラム定義

#### owners

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| name | VARCHAR(100) | NOT NULL | オーナー名 |
| email | VARCHAR(255) | NOT NULL, UNIQUE | メールアドレス |
| phone | VARCHAR(20) | NULL | 電話番号 |
| created_at | DATETIME | NOT NULL, DEFAULT NOW | |
| updated_at | DATETIME | NOT NULL, ON UPDATE | |
| deleted_at | DATETIME | NULL | 論理削除 |

#### stores

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| owner_id | BIGINT | NOT NULL, FK→owners | オーナーID |
| name | VARCHAR(100) | NOT NULL | 店舗名 |
| code | VARCHAR(20) | NOT NULL, UNIQUE | 店舗コード |
| address | VARCHAR(255) | NULL | 住所 |
| phone | VARCHAR(20) | NULL | 電話番号 |
| email | VARCHAR(255) | NULL | メールアドレス |
| opening_time | TIME | NOT NULL, DEFAULT '10:00:00' | 営業開始時間 [008] |
| closing_time | TIME | NOT NULL, DEFAULT '00:00:00' | 営業終了時間 [008] |
| is_24h_open | TINYINT(1) | NOT NULL, DEFAULT 0 | 24時間営業フラグ [008] |
| default_hourly_rate | INT | NOT NULL, DEFAULT 2500 | デフォルト時間単価 [009] |
| max_duration_hours | INT | NOT NULL, DEFAULT 8 | 最大利用時間 [009] |
| min_duration_hours | INT | NOT NULL, DEFAULT 1 | 最低利用時間 [009] |
| booking_days_ahead | INT | NOT NULL, DEFAULT 30 | 予約受付日数 [009] |
| base_reward | INT | NOT NULL, DEFAULT 3000 | 基本報酬 |
| is_active | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

#### sales_areas

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| store_id | BIGINT | NOT NULL, FK→stores | 店舗ID |
| name | VARCHAR(100) | NOT NULL | 区分名 |
| hourly_rate | INT | NOT NULL, DEFAULT 2500 | 時間単価 [009] |
| cleaning_duration_minutes | INT | NOT NULL, DEFAULT 60 | 清掃所要時間 [013] |
| capacity | INT | NOT NULL, DEFAULT 4 | 定員 [009] |
| description | TEXT | NULL | 説明文 [009] |
| room_code | VARCHAR(16) | UNIQUE | 部屋コード [011] |
| is_active | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

#### users

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| email | VARCHAR(255) | NOT NULL, UNIQUE | メールアドレス |
| password | VARCHAR(255) | NOT NULL | パスワードハッシュ |
| name | VARCHAR(100) | NOT NULL | 氏名 |
| role | ENUM('HQ','OWNER','STORE') | NOT NULL, DEFAULT 'STORE' | 権限ロール |
| owner_id | BIGINT | NULL, FK→owners | 所属オーナーID [006] |
| status | ENUM('active','inactive','suspended') | NOT NULL, DEFAULT 'active' | アカウント状態 |
| store_id | BIGINT | NULL, FK→stores | デフォルト店舗ID |
| last_login_at | DATETIME | NULL | 最終ログイン日時 |
| password_changed_at | DATETIME | NULL | パスワード変更日時 |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

#### reservations

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| store_id | BIGINT | NOT NULL, FK→stores | 店舗ID |
| sales_area_id | BIGINT | NOT NULL, FK→sales_areas | 営業区分ID |
| customer_name | VARCHAR(100) | NOT NULL | 顧客名 |
| customer_email | VARCHAR(255) | NOT NULL | 顧客メール |
| customer_phone | VARCHAR(20) | NOT NULL | 顧客電話番号 |
| num_people | INT | NOT NULL, DEFAULT 1 | 利用人数 [003] |
| reservation_date | DATE | NOT NULL | 予約日 |
| start_time | TIME | NOT NULL | 開始時刻 |
| end_time | TIME | NOT NULL | 終了時刻 |
| status | ENUM('pending','confirmed','cancelled','completed') | NOT NULL, DEFAULT 'pending' | ステータス |
| source | ENUM('web','line','phone','direct') | NOT NULL, DEFAULT 'web' | 予約経路 |
| line_user_id | VARCHAR(50) | NULL | LINE UserID [010] |
| line_display_name | VARCHAR(100) | NULL | LINE表示名 [010] |
| payment_status | ENUM('unpaid','paid','refunded') | NOT NULL, DEFAULT 'unpaid' | 決済状態 |
| payment_amount | INT | NULL | 決済金額 |
| coupon_code | VARCHAR(50) | NULL | 使用クーポン |
| base_price | INT | NOT NULL, DEFAULT 0 | 基本料金 [003] |
| extension_price | INT | NOT NULL, DEFAULT 0 | 延長料金 [003] |
| total_price | INT | NOT NULL, DEFAULT 0 | 合計料金 [003] |
| notes | TEXT | NULL | 備考 |
| extension_token | VARCHAR(64) | NULL | 延長用トークン [007] |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

#### cleaning_jobs

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| reservation_id | BIGINT | NOT NULL, FK→reservations | 予約ID |
| store_id | BIGINT | NOT NULL, FK→stores | 店舗ID |
| sales_area_id | BIGINT | NOT NULL, FK→sales_areas | 営業区分ID |
| scheduled_at | DATETIME | NOT NULL | 清掃予定日時 |
| scheduled_end_at | DATETIME | NULL | 清掃終了予定時刻 [013] |
| duration_minutes | INT | NOT NULL, DEFAULT 60 | 清掃所要時間 [013] |
| status | ENUM('unassigned','recruiting','assigned','completed','paid') | NOT NULL, DEFAULT 'unassigned' | ステータス |
| completed_at | DATETIME | NULL | 清掃完了時刻 [013] |
| job_type | ENUM('regular','urgent') | NOT NULL, DEFAULT 'regular' | 案件種別 [003] |
| is_urgent | TINYINT(1) | NOT NULL, DEFAULT 0 | 急募フラグ [004] |
| notification_status | ENUM('pending','fixed_waiting','public_recruiting','completed') | NOT NULL, DEFAULT 'pending' | 通知状態 [004] |
| fixed_notification_sent_at | DATETIME | NULL | 固定者通知送信日時 [004] |
| public_notification_sent_at | DATETIME | NULL | 公募通知送信日時 [004] |
| assigned_cleaner_id | BIGINT | NULL, FK→cleaners | 担当清掃者ID |
| base_reward | INT | NOT NULL, DEFAULT 0 | 基本報酬 |
| extension_reward | INT | NOT NULL, DEFAULT 0 | 延長報酬（仕様変更で常に0） |
| notes | TEXT | NULL | 注意事項 |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

#### cleaners

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| line_user_id | VARCHAR(255) | NOT NULL, UNIQUE | LINE UserID |
| name | VARCHAR(100) | NOT NULL | 氏名 |
| phone | VARCHAR(20) | NULL | 電話番号 |
| ipass_code | VARCHAR(10) | NULL, UNIQUE | iPassコード |
| is_active | TINYINT(1) | NOT NULL, DEFAULT 1 | 有効フラグ |
| registration_status | ENUM('pending','profile_done','stores_done','completed') | NOT NULL, DEFAULT 'completed' | 登録状態 [005] |
| registration_token | VARCHAR(64) | NULL | 登録用トークン [005] |
| registration_token_expires_at | DATETIME | NULL | トークン有効期限 [005] |
| registered_at | DATETIME | NOT NULL, DEFAULT NOW | 初回登録日時 |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

#### cleaner_payments（マイグレーション004で再定義）

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| job_id | BIGINT | NOT NULL, FK→cleaning_jobs | 案件ID |
| cleaner_id | BIGINT | NOT NULL, FK→cleaners | 清掃者ID |
| store_id | BIGINT | NOT NULL, FK→stores | 店舗ID |
| base_amount | INT | NOT NULL, DEFAULT 0 | 基本報酬 |
| extension_amount | INT | NOT NULL, DEFAULT 0 | 延長報酬（仕様変更で常に0） |
| total_amount | INT | NOT NULL, DEFAULT 0 | 合計金額 |
| status | ENUM('unpaid','paid') | NOT NULL, DEFAULT 'unpaid' | 支払ステータス |
| paid_at | DATE | NULL | 支払日 |
| paid_by | BIGINT | NULL, FK→users | 支払い処理した管理者ID |
| notes | TEXT | NULL | |
| created_at / updated_at | DATETIME | | 標準カラム |

#### line_accounts

| カラム | 型 | 制約 | 説明 |
|--------|-----|------|------|
| id | BIGINT | PK, AUTO_INCREMENT | |
| account_type | ENUM('store','cleaner') | NOT NULL | 店舗用 or 清掃者用 |
| store_id | BIGINT | NULL, FK→stores, UNIQUE | 店舗ID |
| name | VARCHAR(100) | NOT NULL | アカウント名 |
| channel_id | VARCHAR(50) | NULL | LINE Channel ID |
| channel_secret | VARCHAR(100) | NULL | LINE Channel Secret |
| channel_access_token | TEXT | NULL | LINE Channel Access Token |
| liff_id | VARCHAR(50) | NULL | LIFF ID |
| webhook_url | VARCHAR(255) | NULL | Webhook URL |
| is_active | TINYINT(1) | NOT NULL, DEFAULT 0 | 有効フラグ |
| created_at / updated_at / deleted_at | DATETIME | | 標準カラム |

### 7.3 マイグレーション履歴（001〜014）

| # | ファイル | 概要 |
|---|---------|------|
| 001 | `001_create_tables.sql` | 全基本テーブル作成（18テーブル）+ 外部キー制約 |
| 002 | `002_seed_data.sql` | 初期データ投入（オーナー1件、店舗2件、営業区分3件、ユーザー3件、鍵7件、清掃者3件、予約5件、案件3件） |
| 003 | `003_add_reservation_columns.sql` | 予約に `num_people`, `base_price`, `extension_price`, `total_price` 追加 / 鍵に `notes` 追加 / cleaning_jobs に `job_type` 追加 / extensions のカラム名変更 |
| 004 | `004_add_cleaner_payments.sql` | `cleaner_payments` 再定義（`store_id`, `paid_by` 追加） / `notification_logs` 再定義 / `application_tokens` 新規作成 / cleaning_jobs に通知関連カラム追加 |
| 005 | `005_add_audit_email_logs.sql` | `audit_logs` 再定義 / `email_logs` 再定義 / cleaners に登録ステータスカラム追加 / notification_logs のENUM拡張 |
| 006 | `006_add_owner_id_to_users.sql` | users に `owner_id` カラム追加（OWNERロール用） |
| 007 | `007_add_extension_requests.sql` | `extension_requests` テーブル新規作成 / reservations に `extension_token` 追加 |
| 008 | `008_add_store_business_hours.sql` | stores に `opening_time`, `closing_time`, `is_24h_open` 追加 |
| 009 | `009_add_sales_area_details.sql` | sales_areas に `hourly_rate`, `capacity`, `description` 追加 / stores に `default_hourly_rate`, `max_duration_hours`, `min_duration_hours`, `booking_days_ahead` 追加 |
| 010 | `010_create_line_accounts.sql` | `line_accounts`, `line_rich_menus`, `webhook_events` 新規作成 / reservations に `line_user_id`, `line_display_name` 追加 |
| 011 | `011_add_room_code.sql` | sales_areas に `room_code` 追加（延長申請用部屋コード） |
| 012 | `012_add_login_attempts.sql` | `login_attempts` 再定義（IP単位、`locked_until` 追加） / `ipass_view_tokens` 新規作成 / `webhook_rate_limits` 新規作成 |
| 013 | `013_reservation_flow_improvement.sql` | cleaning_jobs に `completed_at`, `scheduled_end_at`, `duration_minutes` 追加 / sales_areas に `cleaning_duration_minutes` 追加 / `daily_notification_logs` 新規作成 / 複合インデックス追加 |
| 014 | `014_reset_extension_rewards.sql` | 延長報酬リセット（仕様変更: 延長時の清掃報酬は常に0） |

---

## 8. 共通UIコンポーネント

### 8.1 header.php（管理画面サイドバーレイアウト）

**使用変数**: `$pageTitle`（オプション、デフォルトは `APP_NAME`）

**レイアウト構造**:
- ログイン済み: `.app-wrapper` > `.sidebar` + `.main-content`
- 未ログイン: `.guest-wrapper`

**サイドバー構成**:

| セクション | 内容 |
|-----------|------|
| ヘッダー | ブランドロゴ + アプリ名（ダッシュボードリンク） |
| 店舗セレクタ | 店舗切替ドロップダウン（`/switch-store` へPOST、CSRFトークン付き） |
| ナビゲーション | 以下のメニュー項目 |
| フッター | ユーザー情報 + ログアウトボタン |

**ナビゲーション項目**:

| アイコン | ラベル | パス | 権限 |
|---------|--------|------|------|
| `bi-grid-1x2` | ダッシュボード | `/dashboard` | 全員 |
| `bi-calendar` | 予約 | `/reservations` | 全員 |
| `bi-briefcase` | 清掃案件 | `/jobs` | 全員 |
| `bi-people` | 清掃者 | `/cleaners` | 全員 |
| `bi-key` | 鍵管理 | `/keys` | 全員 |
| `bi-calendar-week` | シフト | `/shifts` | 全員 |
| `bi-cash-stack` | 支払い | `/payments` | 全員 |
| `bi-journal-text` | 操作ログ | `/logs` | OWNER以上 |
| `bi-gear` | 設定 | `/settings` | OWNER以上 |

**アクティブ判定**: `isNavActive()` 関数でパスの前方一致判定（ダッシュボードは完全一致）

**CSSフレームワーク**: Bootstrap 5.3.0 + Bootstrap Icons 1.10.0

**カスタムCSS**: `assets/css/style.css`

### 8.2 footer.php

- ログイン済み: `.content-body` + `.main-content` + `.app-wrapper` を閉じる
- 未ログイン: `.guest-wrapper` を閉じる
- Bootstrap JS（5.3.0 bundle）
- カスタムJS: `assets/js/app.js`
- サイドバートグル機能（モバイル対応: 外クリックで閉じる）

### 8.3 public_header.php / public_footer.php

**用途**: 公開ページ（予約フォーム等）用のレイアウト

**public_header.php の特徴**:
- `Referrer-Policy: no-referrer` ヘッダー（トークン漏洩防止）
- `<meta name="referrer" content="no-referrer">` タグ
- カスタムCSS: `assets/css/booking.css`
- LIFF SDK: 店舗コードがあり、LINE設定（`liff_id`）が有効な場合に読み込み
- ナビゲーション: シンプルなナビバー（ブランド名 + スタッフログインリンク）
- フラッシュメッセージ表示

**public_footer.php**:
- ダークフッター（コピーライト表示）
- Bootstrap JS
- LIFF SDK関連のスクリプトなし

---

## 9. spec仕様書との乖離

### 9.1 テーブル構造の乖離

| 項目 | spec記載 | 実装 | 乖離の重大度 |
|------|---------|------|-------------|
| `cleaner_payments` のステータスENUM | spec（schema.md）に `pending/paid/cancelled` と記載 | 004マイグレーションで `unpaid/paid` に再定義 | 中（specが古い） |
| `notification_logs` 構造 | spec（schema.md）に `type: job_offer/job_assigned/...`, `status: sent/failed/pending` | 004マイグレーションで `type: normal/urgent/fixed/confirmed/extension/cancel`, `response: ok/ng/timeout/none/pending` に再定義 | 高（spec更新必要） |
| `email_logs` 構造 | spec（schema.md）に `status: sent/failed/pending` | 005マイグレーションで `status: sent/failed/bounced` + `type`, `reservation_id` カラム追加 | 中 |
| `audit_logs` カラム名 | spec（schema.md）に `old_values`, `new_values` | 005マイグレーションで `old_value`, `new_value`（単数形）に再定義 | 低（実装と一致すれば問題なし） |
| `login_attempts` 構造 | 001マイグレーションに `email` ベースで定義 | 012マイグレーションで `ip_address` ベースに再定義（`locked_until` 追加） | 高（セキュリティ方針変更） |
| LINE連携テーブル群 | spec（schema.md）に未記載 | 010マイグレーションで `line_accounts`, `line_rich_menus`, `webhook_events` 新規作成 | 高（spec更新必要） |
| `extension_requests` テーブル | spec未記載 | 007マイグレーションで追加 | 中 |
| `application_tokens` テーブル | spec未記載 | 004マイグレーションで追加 | 中 |
| `ipass_view_tokens` テーブル | spec未記載 | 012マイグレーションで追加 | 低 |
| `webhook_rate_limits` テーブル | spec未記載 | 012マイグレーションで追加 | 低 |
| `daily_notification_logs` テーブル | spec未記載 | 013マイグレーションで追加 | 低 |

### 9.2 ディレクトリ構成の乖離

| 項目 | spec記載（tech_stack.md） | 実装 |
|------|--------------------------|------|
| API配下 | `api/line/webhook.php` のみ記載 | `api/line/`, `api/booking/`, `api/reservations/`, `api/export/`, `api/settings/` の5ディレクトリ |
| pages配下 | `login.php`, `dashboard.php`, `reservations/`, `jobs/`, `cleaners/`, `settings/` | 追加: `booking/`, `apply/`, `extend/`, `register/`, `keys/`, `shifts/`, `payments/`, `logs/`, `forgot-password.php`, `reset-password.php` |
| includes配下 | `config.php`, `db.php`, `auth.php`, `functions.php`, `header.php`, `footer.php` | 追加: `helpers.php`, `reservation_helpers.php`, `job_helpers.php`, `notification_helpers.php`, `audit_helpers.php`, `user_helpers.php`, `line_helper.php`, `public_header.php`, `public_footer.php` |

### 9.3 設計方針の乖離

| 項目 | spec記載（design_policy.md） | 実装 |
|------|---------------------------|------|
| CASCADE削除 | 「CASCADE削除は使用しない（論理削除のため）」 | 001マイグレーションで `ON DELETE CASCADE` を多数使用（`user_stores`, `key_assignments`, `cleaner_stores`, `fixed_cleaners`, `cleaning_jobs`, `job_applications`, `extensions`, `cleaner_payments`） |
| CHECK制約 | 時間整合性チェック、金額正値チェックを規定 | 実装では CHECK 制約が定義されていない |
| ソフトデリート用ユニーク制約 | `active_flag` 生成列方式を規定 | 実装では未導入（通常のUNIQUE制約のみ） |
| JSON型の使用 | 「動的設定はJSON型で対応」と規定 | `audit_logs` の `old_value`/`new_value` と `line_rich_menus` の `menu_config` で使用。`stores` の営業時間はJSON型ではなく個別カラム（008マイグレーション） |
| 全テーブルに `deleted_at` | 規定あり | `login_attempts`, `ipass_view_tokens`, `webhook_rate_limits`, `daily_notification_logs`, `user_stores`, `key_assignments`, `job_applications`, `application_tokens` 等のテーブルには `deleted_at` なし |

### 9.4 機能面の乖離

| 項目 | spec記載（system_overview.md） | 実装 |
|------|------------------------------|------|
| 延長報酬計算 | 「延長1時間ごとに基本報酬の50%加算」 | 014マイグレーションで「延長時の清掃報酬は常に0」に仕様変更 |
| 予約確定時の清掃案件自動生成 | 記載あり | 実装あり（reservation_helpers.php で実装と推定） |
| 売上・KPI | 「区分別集計、予約経路別集計」 | ダッシュボードページにて実装状況は未確認（本調査スコープ外） |

### 9.5 乖離の要約

spec仕様書（2026-01-26最終更新）は初期設計段階の情報であり、その後のマイグレーション003〜014で多数の追加・変更が行われている。主な乖離は以下の3点:

1. **テーブル追加**: spec未記載のテーブルが10以上追加されている（LINE連携、応募トークン、延長リクエスト、セキュリティ関連）
2. **設計方針の実装差異**: CASCADE削除の使用、CHECK制約の未実装、ソフトデリートユニーク制約の未導入
3. **仕様変更の未反映**: 延長報酬の計算方式が変更されたがspec未更新、通知ログ・メールログの構造変更もspec未反映

---

*Generated: 2026-01-29*
*調査対象: 実装コード（public_html/, database/) + spec仕様書（spec/db/, spec/overview/）*
