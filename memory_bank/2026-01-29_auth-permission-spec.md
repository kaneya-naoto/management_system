# 認証・権限・ユーザー管理 仕様書（実装ベース）

## 1. 概要

カクレマ新基幹システムにおける認証・権限・ユーザー管理機能の実装仕様を記述する。
本システムはPHPベースのWebアプリケーションであり、メール+パスワードによる認証、3階層ロールベースの権限制御、セッション管理、CSRF対策を実装している。

### 関連ファイル

| ファイル | 役割 |
|----------|------|
| `public_html/includes/auth.php` | 認証・セッション・権限管理の中核関数群 |
| `public_html/includes/config.php` | アプリケーション設定・定数定義 |
| `public_html/includes/user_helpers.php` | パスワードリセットトークン関連関数 |
| `public_html/pages/login.php` | ログイン画面 |
| `public_html/pages/forgot-password.php` | パスワードリセット申請画面 |
| `public_html/pages/reset-password.php` | パスワード再設定画面 |
| `public_html/pages/settings/users.php` | ユーザー管理（CRUD）画面 |
| `public_html/pages/settings/owners.php` | オーナー管理（HQ専用）画面 |
| `database/012_add_login_attempts.sql` | ログイン試行管理テーブルDDL |

---

## 2. 認証フロー

### 2.1 ログイン処理

**画面**: `/login`（`public_html/pages/login.php`）

**処理フロー**:

1. ログイン済みの場合は `/dashboard` へリダイレクト
2. POST送信時、CSRF検証を実施（`requireCsrf()`）
3. メールアドレスとパスワードを受け取り `login()` 関数を呼び出す
4. `login()` 関数の内部処理:
   - **ログインロックチェック**: `isLoginLocked()` でIP単位のロック状態を確認
   - ロック中の場合は残り時間を含むエラーメッセージ文字列を返す
   - **ユーザー検索**: `users` テーブルから `email` が一致し、`status = 'active'` かつ `deleted_at IS NULL` のユーザーを取得
   - **Timing Attack対策**: ユーザーが存在しない場合でもダミーハッシュに対して `password_verify()` を実行
   - **認証失敗時**: `recordLoginAttempt($email, false)` で失敗を記録し、`false` を返す
   - **認証成功時**:
     - `recordLoginAttempt($email, true)` でIP単位の失敗記録をリセット（DELETE）
     - `session_regenerate_id(true)` でセッション固定化攻撃を防止
     - セッション変数に `user_id`, `login_time`, `last_activity` を設定
     - `users.last_login_at` を更新
     - `selected_store_id` を `null`（全店舗）で初期化
     - `true` を返す

**`login()` 関数の戻り値**:

| 戻り値 | 意味 |
|--------|------|
| `true` | ログイン成功 |
| `false` | メールアドレスまたはパスワード不一致 |
| `string` | ロック中のエラーメッセージ |

### 2.2 ログアウト

**関数**: `logout()`（`auth.php`）

**処理フロー**:

1. `$_SESSION` を空配列でクリア
2. セッションCookieが有効な場合、Cookieを過去の時刻で上書きして削除
3. `session_destroy()` でサーバー側セッションを破棄

### 2.3 パスワードリセット

#### 2.3.1 リセット申請（`/forgot-password`）

**画面**: `public_html/pages/forgot-password.php`

**処理フロー**:

1. ログイン済みの場合は `/dashboard` へリダイレクト
2. POST送信時、CSRF検証を実施
3. メールアドレスのバリデーション（空チェック、形式チェック）
4. `generatePasswordResetToken($email)` を呼び出し:
   - ユーザーが存在し `status = 'active'` かつ `deleted_at IS NULL` であればトークン生成
   - 既存の未使用トークンを無効化（`used_at = NOW()`）（1ユーザー1トークンポリシー）
   - `bin2hex(random_bytes(32))` で64文字の平文トークンを生成
   - SHA-256ハッシュ化してDBに保存（`password_reset_tokens` テーブル）
   - 有効期限: 1時間
   - 平文トークンを返す
5. トークンが生成されたら `sendPasswordResetMail()` でメール送信
6. **列挙攻撃対策**: ユーザーの存在有無に関わらず同一の成功メッセージを表示

#### 2.3.2 パスワード再設定（`/reset-password`）

**画面**: `public_html/pages/reset-password.php`

**処理フロー**:

1. ログイン済みの場合は `/dashboard` へリダイレクト
2. URLパラメータからトークンを取得
3. `verifyPasswordResetToken($token)` でトークン検証:
   - トークン長の検証（64文字）
   - SHA-256ハッシュ化して `password_reset_tokens` テーブルを検索
   - 条件: `used_at IS NULL`, `expires_at > NOW()`, ユーザーが `active` かつ未削除
4. トークンが無効な場合はエラー表示
5. POST送信時のバリデーション:
   - パスワード: 必須、8文字以上、72文字以内
   - パスワード確認: 一致チェック
6. `resetPassword($token, $password)` でリセット実行:
   - トークン再検証
   - トランザクション内でパスワード更新 + トークン使用済み化
   - `password_changed_at` を更新

### 2.4 ログイン試行制限

**テーブル**: `login_attempts`（IP単位管理）

```sql
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,  -- IPv4/IPv6対応
    attempts INT UNSIGNED DEFAULT 1,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_ip_address (ip_address)
);
```

**設定定数**:

| 定数 | 値 | 説明 |
|------|-----|------|
| `LOGIN_MAX_ATTEMPTS` | 5 | 最大試行回数 |
| `LOGIN_LOCKOUT_TIME` | 900（秒） | ロックアウト時間（15分） |

**ロジック（`recordLoginAttempt()`）**:

1. **成功時**: 該当IPのレコードをDELETE（リセット）
2. **失敗時**:
   - 既存レコードがある場合:
     - 最終試行から `LOGIN_LOCKOUT_TIME` 経過していればカウントリセット（attempts=1）
     - 経過していなければ `attempts` をインクリメント
     - `attempts >= LOGIN_MAX_ATTEMPTS` になったら `locked_until` を設定
   - レコードがない場合: 新規作成（attempts=1）

**ロック判定（`isLoginLocked()`）**:

- `locked_until` が設定されていて現在時刻より未来の場合、ロック中と判定
- IPアドレスが空の場合は `false`（ロックしない）

---

## 3. セッション管理

### 3.1 セッション設定

`config.php` で以下のセッション設定を行っている:

| 設定 | 値 | 説明 |
|------|-----|------|
| `session.cookie_httponly` | `1` | JavaScriptからCookieアクセス不可 |
| `session.cookie_secure` | HTTPS時 `1` | HTTPS接続時のみCookie送信 |
| `session.use_strict_mode` | `1` | 未初期化セッションIDを拒否 |
| `session.cookie_samesite` | `Lax` | クロスサイトリクエスト制限 |

**セッション有効期限**: `SESSION_LIFETIME = 28800`（8時間）

### 3.2 セッション固定化対策

- ログイン成功時に `session_regenerate_id(true)` を実行
- 旧セッションファイルを削除（`true` パラメータ）

### 3.3 セッション有効期限チェック

`isLoggedIn()` 関数内で毎リクエスト確認:

1. `$_SESSION['user_id']` の存在チェック
2. `$_SESSION['last_activity']` から経過時間を計算
3. `SESSION_LIFETIME`（8時間）を超過した場合は `logout()` を呼び出して `false` を返す
4. 超過していない場合は `last_activity` を現在時刻に更新

### 3.4 セッションに格納される情報

| キー | 型 | 設定タイミング | 説明 |
|------|-----|----------------|------|
| `user_id` | int | ログイン時 | ログインユーザーのID |
| `login_time` | int | ログイン時 | ログイン時刻（UNIXタイムスタンプ） |
| `last_activity` | int | 毎リクエスト更新 | 最終アクセス時刻 |
| `selected_store_id` | int\|null | ログイン時・店舗切替時 | 選択中の店舗ID（nullは全店舗） |
| `_token` | string | CSRF生成時 | CSRFトークン |
| `csrf_token_time` | int | CSRF生成時 | CSRFトークン生成時刻 |

---

## 4. 権限モデル

### 4.1 ロール定義（HQ / OWNER / STORE）

3階層の階層型ロールモデル。上位ロールは下位ロールの権限を包含する。

| レベル | ロール名 | 日本語名 | 表示ラベル | バッジカラー |
|--------|----------|----------|------------|--------------|
| 3 | `HQ` | 統括本部 | 本部 | danger（赤） |
| 2 | `OWNER` | オーナー | オーナー | primary（青） |
| 1 | `STORE` | 店舗 | 店舗スタッフ | secondary（灰） |

**権限レベル判定（`hasRole()` 関数）**:

```php
$roles = ['STORE' => 1, 'OWNER' => 2, 'HQ' => 3];
// ユーザーのレベル >= 要求レベル であれば権限あり
```

### 4.2 各ロールのアクセス範囲

#### HQ（統括本部）

- 全店舗へのアクセス権
- `canAccessStore()`: 常に `true`
- `getAccessibleStoreIds()`: `stores` テーブルの全アクティブレコード
- オーナー管理（`/settings/owners`）へのアクセス権

#### OWNER（オーナー）

- 自オーナー（`users.owner_id`）配下の店舗にアクセス可能
- `canAccessStore()`: `stores.owner_id` が自身の `owner_id` と一致するか確認
- `getAccessibleStoreIds()`: `stores WHERE owner_id = ?` で取得
- ユーザー管理（`/settings/users`）へのアクセス権（OWNER以上）
- `owner_id` が未設定の場合はアクセス可能店舗なし

#### STORE（店舗スタッフ）

- `user_stores` 中間テーブルで紐付けられた店舗のみアクセス可能
- `canAccessStore()`: `user_stores` テーブルで紐付けを確認
- `getAccessibleStoreIds()`: `user_stores INNER JOIN stores` で取得

### 4.3 店舗スコープ制御

**店舗選択機能**:

- セッション変数 `selected_store_id` で管理
- ログイン時は `null`（全店舗）で初期化
- `setSelectedStore($storeId)`: アクセス権チェック付きで店舗を選択
- `getSelectedStore()`: 選択中店舗IDを取得（型チェック・権限チェック付き）
- `getFilteredStoreIds()`: クエリ用の店舗IDリストを取得（選択店舗 or 全アクセス可能店舗）

**セキュリティ対策**:

- `getSelectedStore()` で型チェック（セッション改ざん対策）
- アクセス権がなくなった店舗が選択されていた場合は自動的に `null` にリセット

---

## 5. ユーザー管理機能（CRUD）

**画面**: `/settings/users`（`public_html/pages/settings/users.php`）
**必要権限**: `OWNER` 以上（`requireRole('OWNER')` で制御）

### 5.1 一覧

- アクセス可能な店舗に紐づくユーザーを一覧表示
- 取得条件: `users.store_id IN (アクセス可能店舗IDs)` または `user_stores` で紐付きあり
- 論理削除されたユーザーは除外（`deleted_at IS NULL`）
- 表示項目: 氏名、メールアドレス、権限バッジ、デフォルト店舗、状態バッジ、最終ログイン日時
- 自分自身には「あなた」バッジを表示
- ソート順: ロール順、氏名順

### 5.2 追加

**操作**: モーダルダイアログで入力

**入力項目**:

| 項目 | 必須 | バリデーション |
|------|------|----------------|
| メールアドレス | 必須 | 空チェック、形式チェック、重複チェック |
| 氏名 | 必須 | 空チェック、100文字以内 |
| パスワード | 必須 | 8文字以上 |
| 権限 | 任意 | `OWNER` または `STORE`（デフォルト: `STORE`） |
| 所属オーナー | 条件付き必須 | OWNERロール時は必須、owners テーブル存在確認 |
| デフォルト店舗 | 任意 | アクセス可能な店舗のみ選択可 |

**処理**:

1. バリデーション実行
2. トランザクション開始
3. `users` テーブルにINSERT（パスワードは `password_hash()` で `PASSWORD_DEFAULT` を使用）
4. 店舗指定時は `user_stores` テーブルにもINSERT
5. 監査ログ記録（`logAudit('create', 'user', ...)`）
6. コミット

**注意**: OWNERロールでもHQロールの作成はUIから不可（select要素にHQの選択肢がない）。

### 5.3 編集

**操作**: モーダルダイアログで編集

**編集可能項目**:

| 項目 | 制約 |
|------|------|
| 氏名 | 必須 |
| 新しいパスワード | 任意（変更時のみ入力、8文字以上） |
| 権限 | `OWNER` / `STORE` のみ選択可。自分自身は変更不可 |
| 所属オーナー | OWNERロール時は必須 |
| 状態 | `active` / `inactive` / `suspended`。自分自身は変更不可 |
| デフォルト店舗 | アクセス可能な店舗のみ |

**メールアドレスは変更不可**（disabled表示）。

**IDOR対策**: 対象ユーザーがアクセス可能な店舗に属しているかを確認してから更新。

**自分自身の保護**: 自分自身のロール・ステータスは強制的に元の値を維持。

### 5.4 削除

**処理**:

1. 自分自身の削除は不可
2. IDOR対策: 対象ユーザーのアクセス権確認
3. トランザクション内で:
   - `users.deleted_at` に現在日時を設定（論理削除）
   - `user_stores` のレコードを物理DELETE
   - 監査ログ記録
4. 確認ダイアログ（JavaScript `confirm()`）あり

---

## 6. 主要関数・定数一覧

### 定数（`config.php`）

| 定数名 | 値 | 説明 |
|--------|-----|------|
| `APP_NAME` | `'カクレマ管理システム'` | アプリケーション名 |
| `PASSWORD_COST` | `12` | bcryptコスト |
| `SESSION_LIFETIME` | `28800` | セッション有効期限（8時間、秒） |
| `CSRF_TOKEN_NAME` | `'_token'` | CSRFトークンのフォームフィールド名 |
| `CSRF_TOKEN_LIFETIME` | `3600` | CSRFトークン有効期限（1時間、秒） |
| `CSRF_TOKEN_LENGTH` | `64` | CSRFトークンの16進数文字列長 |
| `LOGIN_MAX_ATTEMPTS` | `5` | ログイン最大試行回数 |
| `LOGIN_LOCKOUT_TIME` | `900` | ログインロックアウト時間（15分、秒） |

### 認証・セッション関数（`auth.php`）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `startSession()` | なし | `void` | セッション開始（未開始時のみ） |
| `login($email, $password)` | string, string | `bool\|string` | ログイン処理。成功時true、失敗時false、ロック中は文字列 |
| `logout()` | なし | `void` | ログアウト（セッション・Cookie破棄） |
| `isLoggedIn()` | なし | `bool` | ログイン状態チェック（有効期限考慮） |
| `currentUser($refresh)` | bool=false | `?array` | ログインユーザー情報取得（リクエスト内キャッシュ付き） |
| `requireLogin()` | なし | `void` | ログイン必須（未ログインで `/login` リダイレクト） |
| `getCurrentUserId()` | なし | `?int` | ログインユーザーIDを取得 |

### 権限関数（`auth.php`）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `hasRole($requiredRole)` | string | `bool` | 階層型権限チェック（上位ロールは下位の権限を包含） |
| `requireRole($role)` | string | `void` | 権限必須チェック（403でexit） |
| `canAccessStore($storeId)` | int | `bool` | 店舗アクセス権チェック |
| `requireStoreAccess($storeId)` | int | `void` | 店舗アクセス権必須チェック（403でexit） |
| `getAccessibleStoreIds()` | なし | `array{ids, stores}` | アクセス可能な全店舗IDリスト |
| `setSelectedStore($storeId)` | `?int` | `bool` | 店舗選択をセッションに保存 |
| `getSelectedStore()` | なし | `?int` | 選択中店舗ID取得（権限チェック付き） |
| `getFilteredStoreIds()` | なし | `array{ids, stores, selected}` | クエリ用店舗IDリスト |

### CSRF関数（`auth.php`）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `generateCsrfToken()` | なし | `string` | CSRFトークン生成（有効期限内は既存を返す） |
| `verifyCsrfToken($token)` | `?string` | `bool` | CSRF検証（成功後トークン削除=リプレイ攻撃対策） |
| `requireCsrf()` | なし | `void` | CSRF必須検証（403でexit） |

### パスワード関連関数

| 関数名 | ファイル | 引数 | 戻り値 | 説明 |
|--------|----------|------|--------|------|
| `hashPassword($password)` | `auth.php` | string | `string` | bcrypt cost=12でハッシュ生成 |
| `generatePasswordResetToken($email)` | `user_helpers.php` | string | `?string` | リセットトークン生成（SHA-256でDB保存） |
| `verifyPasswordResetToken($token)` | `user_helpers.php` | string | `?array` | トークン検証（有効期限・使用済みチェック） |
| `resetPassword($token, $newPassword)` | `user_helpers.php` | string, string | `bool` | パスワードリセット実行 |

### ログイン試行制限関数（`auth.php`）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `isLoginLocked($email)` | string | `bool` | ログインロック判定（IP単位） |
| `getLoginLockRemaining($email)` | string | `int` | ロック解除までの残り秒数 |
| `recordLoginAttempt($email, $success)` | string, bool | `void` | ログイン試行を記録 |

---

## 7. セキュリティ対策一覧

| 対策 | 実装箇所 | 詳細 |
|------|----------|------|
| **パスワードハッシュ** | `auth.php` / `user_helpers.php` | bcrypt（cost=12）。`hashPassword()` は `PASSWORD_BCRYPT` 指定、ユーザー管理画面のパスワード生成は `PASSWORD_DEFAULT` を使用 |
| **Timing Attack対策** | `login()` | ユーザー未存在時もダミーハッシュで `password_verify()` を実行 |
| **セッション固定化攻撃対策** | `login()` | ログイン成功時に `session_regenerate_id(true)` |
| **セッションCookieセキュリティ** | `config.php` | `httponly=1`, `secure=HTTPS時`, `samesite=Lax`, `strict_mode=1` |
| **セッション有効期限** | `isLoggedIn()` | 最終アクセスから8時間で自動ログアウト |
| **CSRF対策** | `auth.php` | トークンベース。`bin2hex(random_bytes(32))` で64文字生成。有効期限1時間。検証成功後にトークン削除（リプレイ攻撃対策）。`hash_equals()` でタイミング安全比較。POST本文 or `X-CSRF-TOKEN` ヘッダーで受信 |
| **CSRFトークン形式検証** | `verifyCsrfToken()` | 長さチェック（64文字）+ 16進文字列チェック（`ctype_xdigit`） |
| **ブルートフォース対策** | `auth.php` / `login_attempts` テーブル | IP単位で5回失敗後15分ロック。DBベースで管理（セッション破棄による回避を防止） |
| **列挙攻撃対策** | `forgot-password.php` | パスワードリセット申請時、ユーザー存在有無に関わらず同一メッセージを表示 |
| **リセットトークンのハッシュ保存** | `user_helpers.php` | DB保存時はSHA-256ハッシュ化。平文はメールでユーザーに送信のみ |
| **リセットトークン有効期限** | `user_helpers.php` | 1時間。使用後は `used_at` を設定して無効化 |
| **1ユーザー1トークンポリシー** | `generatePasswordResetToken()` | 新トークン生成時に既存未使用トークンを無効化 |
| **IDOR対策** | `users.php` | ユーザー更新・削除時に対象ユーザーがアクセス可能店舗に属しているか確認 |
| **自己変更制限** | `users.php` | 自分自身のロール・ステータス変更不可、自分自身の削除不可 |
| **論理削除** | `users.php` / `owners.php` | ユーザー・オーナーは `deleted_at` による論理削除 |
| **監査ログ** | `users.php` | ユーザーのCRUD操作を `audit_logs` テーブルに記録 |
| **入力バリデーション** | 各画面 | メール形式チェック、パスワード長チェック（8文字以上、リセット時72文字以内）、氏名長チェック（100文字以内） |
| **出力エスケープ** | 各画面 | `h()` 関数（`htmlspecialchars` ラッパー）による出力エスケープ |
| **HTTPS判定** | `config.php` | ロードバランサー経由（`X-Forwarded-Proto`）も考慮 |
| **セッション型チェック** | `getSelectedStore()` | セッション改ざんに備えた `selected_store_id` の型検証 |

---

## 8. spec仕様書との乖離

以下にspec仕様書（`organization_model.md`, `system_overview.md`, `schema.md`）と実装コードの相違点を記載する。

### 8.1 実装クラス名の乖離

**spec記載**（`organization_model.md`）:
> - `Auth.php`: 認証・セッション管理
> - `AuthzService.php`: 3階層権限チェック

**実装**:
- `auth.php`: 認証・セッション管理 **および** 権限チェックの両方を含む
- `AuthzService.php` は存在しない

**分析**: specでは認証（Auth）と認可（Authz）を別クラスに分離する設計だったが、実装では `auth.php` に全て統合されている。関数ベースの実装であり、クラスは使用されていない。

### 8.2 権限の細分化の未実装

**spec記載**（`organization_model.md`）:
> 内部的に以下の権限を分離可能な設計とする：
> - **表示可**: データの閲覧のみ
> - **編集可**: データの作成・更新
> - **設定可**: システム設定の変更

**実装**:
- 表示可/編集可/設定可の分離は実装されていない
- ロールレベル（HQ=3 > OWNER=2 > STORE=1）のみによる制御

**分析**: specでは将来的な拡張として権限の細分化を想定していたが、現時点の実装では3階層のロールレベル判定のみ。`hasRole()` 関数は上位ロールが下位の全権限を包含する単純な階層型であり、操作種別（表示/編集/設定）による制御は行っていない。

### 8.3 ユーザーステータスの差異

**spec記載**（`schema.md`）:
- `users.status` に関する ENUM 定義の明示的な記載なし（主要カラムに `status` のみ記載）

**実装**:
- `users.status` は3値: `active`, `inactive`, `suspended`
- ログイン時は `status = 'active'` のユーザーのみ認証可能

**分析**: specでのステータス定義が明確でないため、実装側が3値を定義した形。乖離というより仕様の補完。

### 8.4 その他の整合状態

以下の点はspecと実装で整合性が取れている:

- **組織3階層構造**: `owners > stores > sales_areas` の階層はspec通り
- **権限モデル3階層**: HQ / OWNER / STORE のロールと操作範囲はspec通り
- **認証方式**: メール + パスワード、bcrypt (cost=12) はspec通り
- **パスワードリセット**: メールリンク送信方式はspec通り
- **セッション管理**: PHPセッション使用はspec通り
- **DB構造**: `users`, `user_stores`, `password_reset_tokens` テーブル構成はspec通り
- **ロールENUM**: `HQ`, `OWNER`, `STORE` の3値はspec通り

---

*作成日: 2026-01-29*
*対象コミット: 10ce139 (Initial commit)*
