# 総合監査・修正レポート（2026-01-30）

## 概要

コードベース全体の監査を実施し、31件の問題を検出。5フェーズに分けて修正を実施後、Claude + Codex のデュアルレビューループで追加9件を修正。最終的に MUST-FIX 0件を達成。

## 修正サマリー

| カテゴリ | 件数 | 状態 |
|---------|------|------|
| Phase 1: CRITICAL | 3件 | 完了 |
| Phase 2: HIGH | 6件 | 完了 |
| Phase 3: MEDIUM セキュリティ | 6件 | 完了 |
| Phase 4: MEDIUM ロジック | 5件 | 完了 |
| Phase 5: LOW 品質改善 | 10件 | 完了（5-F スキップ） |
| レビュー初回修正 | 3件 | 完了 |
| レビューLoop Round 1 | 6件 | 完了 |
| **合計** | **39件** | **Round 2: LGTM** |

スキップ: 5-F（深夜跨ぎ判定の共通化）— リスクが高く、現行ロジックで正常動作しているため見送り。

---

## Phase 1: CRITICAL（3件）

### 1-A. デバッグログの本番除去
- **ファイル**: `public_html/api/line/webhook.php`
- **問題**: `file_put_contents()` で LINE userId・APIレスポンスを `webhook_debug.log` に平文保存。Webアクセス可能な場所に個人情報が露出
- **修正**: 全 `file_put_contents($logFile, ...)` を `error_log()` に置換。個人情報はハッシュ化/省略

### 1-B. LINE設定の空上書き防止
- **ファイル**: `public_html/pages/settings/line.php`
- **問題**: 編集モーダルで `channel_secret` / `channel_access_token` が空クリアされ、他フィールドだけ更新しても機密値が空で上書き → LINE連携全停止
- **修正**: PHP側で空値の場合はDB更新対象から除外。JS側にplaceholder「変更する場合のみ入力」追加

### 1-C. 未認証APIエンドポイント
- **ファイル**: `public_html/index.php`
- **問題**: `/api/export/payments`, `/api/export/reservations`, `/api/reservations/calendar-data` にルーターレベルの `requireLogin()` がない
- **修正**: ルーターに `requireLogin()` を追加（多層防御）

---

## Phase 2: HIGH（6件）

### 2-A. 無効なダミーbcryptハッシュ
- **ファイル**: `public_html/includes/auth.php` (行148付近)
- **問題**: タイミング攻撃対策のダミーハッシュが不正なbcryptフォーマット → `password_verify()` が即座にfalse返却
- **修正**: `password_hash('dummy', PASSWORD_BCRYPT)` で事前生成した有効なハッシュに置換

### 2-B. 報酬更新のステータスチェック不足
- **ファイル**: `public_html/pages/jobs/detail.php`
- **問題**: 支払済み/キャンセル済み案件でも報酬変更可能
- **修正**: `paid` / `cancelled` ステータスの案件は報酬変更を拒否

### 2-C. 監査ログの店舗スコープ不足
- **ファイル**: `public_html/pages/logs/list.php`
- **問題**: OWNER権限ユーザーが全店舗の監査ログを閲覧可能
- **修正**: OWNERは `getAccessibleStoreIds()` でフィルタリング

### 2-D. LINE config APIの情報漏洩
- **ファイル**: `public_html/api/line/config.php`
- **問題**: 認証なしで任意store_idのLINE設定を照会可能
- **修正**: store_id → store_code受付に変更、レート制限追加

### 2-E. LINEメッセージのHTMLエスケープ
- **ファイル**: `public_html/api/line/webhook_store.php`
- **問題**: LINE APIプレーンテキストに `h()` 適用 → `&amp;` 等が表示
- **修正**: LINEメッセージ内の `h()` を除去

### 2-F. settings/index.php ページレベル認証追加
- **ファイル**: `public_html/pages/settings/index.php`
- **修正**: 先頭に `requireLogin(); requireRole('OWNER');` を追加

---

## Phase 3: MEDIUM セキュリティ（6件）

### 3-A. 予約完了のレースコンディション
- **ファイル**: `public_html/pages/booking/complete.php`
- **問題**: 空きチェックと鍵割当間のTOCTOU問題
- **修正**: 直接 `assignKeyToReservation()` を試行、RuntimeExceptionで分岐

### 3-B. CMS予約作成の空きチェック追加
- **ファイル**: `public_html/pages/reservations/create.php`
- **修正**: 空き鍵チェック追加。空きなし時は警告表示 + force確認

### 3-C. LINE user data のクライアント検証
- **ファイル**: `public_html/pages/booking/customer.php`
- **修正**: `line_user_id` のフォーマット検証 (`/^U[0-9a-f]{32}$/`)

### 3-D. 削除済み案件のトークン有効性
- **ファイル**: `public_html/pages/apply/index.php`
- **修正**: SQLに `AND j.deleted_at IS NULL` 追加

### 3-E. 非アクティブ店舗の登録表示
- **ファイル**: `public_html/pages/register/stores.php`
- **修正**: `WHERE` に `is_active = 1` 追加

### 3-F. 店舗削除時のLINE設定クリーンアップ
- **ファイル**: `public_html/pages/settings/stores.php`
- **修正**: 削除トランザクション内に `line_accounts` の論理削除追加

---

## Phase 4: MEDIUM ロジック（5件）

### 4-A. 鍵割当の清掃バッファ不整合
- **ファイル**: `public_html/includes/reservation_helpers.php`
- **問題**: `getAvailableKeyCount` と `assignKeyToReservation` で異なる重複チェックロジック
- **修正**: `assignKeyToReservation` のSQLにCOALESCE/DATE_ADD清掃バッファを統一

### 4-B. 完了→保留ステータス遷移を禁止
- **ファイル**: `public_html/pages/reservations/detail.php`
- **修正**: 許可する遷移ルールを明示的マップで定義
```php
$allowedTransitions = [
    'pending'   => ['confirmed', 'cancelled'],
    'confirmed' => ['pending', 'completed', 'cancelled'],
    'completed' => [],
    'cancelled' => [],
];
```

### 4-C. 確認画面エラー時のリダイレクト先
- **ファイル**: `public_html/pages/booking/confirm.php`
- **修正**: customer.phpがPOST-onlyのため、booking indexへのリダイレクトを維持（コメント修正）

### 4-D. 未登録ユーザーのトークンスパム防止
- **ファイル**: `public_html/api/line/webhook.php`
- **修正**: 有効期限内の既存トークンを再利用する処理を追加

### 4-E. 404ページの url() 関数使用
- **ファイル**: `public_html/index.php`
- **修正**: `href="/dashboard"` → `href="<?= url('/dashboard') ?>"`

---

## Phase 5: LOW 品質改善（10件）

| # | 内容 | ファイル | 修正内容 |
|---|------|----------|----------|
| 5-A | HTTPS リダイレクト有効化 | `.htaccess` | localhost除外でHTTPSリダイレクト有効化 |
| 5-B | ログインロックにEmail追加 | `auth.php`, `016_*.sql` | IP+Emailの複合キーでロック管理 |
| 5-C | クーポンコード入力無効化 | `confirm.php`, `complete.php` | disabled + 「準備中」メッセージ |
| 5-D | Thanks ページリフレッシュ対応 | `thanks.php` | thanks_shownフラグでリフレッシュ対応 |
| 5-E | CMS過去日バリデーション | `reservations/create.php` | 非ブロッキング警告表示 |
| 5-G | 定員チェック追加 | `confirm.php` | sales_areas.capacity照合 |
| 5-H | 予約情報更新の楽観ロック | `reservations/detail.php` | expected_updated_at比較 |
| 5-I | リッチメニューファイル名衝突防止 | `line_richmenu.php` | random_bytes(4)追加 |
| 5-J | keys/create.php の old() 修正 | `keys/create.php` | setOld()呼び出し追加 |
| 5-K | keys/list.php 空配列ハンドリング | `keys/list.php` | empty()ガード追加 |

---

## レビューループ修正（9件）

### 初回レビュー修正（3件）

| # | 内容 | ファイル |
|---|------|----------|
| R1 | login_attempts UNIQUE KEY修正 | `016_*.sql`, `001_*.sql` |
| R2 | 鍵割当の翌日チェック追加 | `reservation_helpers.php` |
| R3 | auth.php email IS NULL条件除去 | `auth.php` |

### Round 1 修正（6件）

| # | 内容 | ファイル | 発見者 |
|---|------|----------|--------|
| R4 | calculateAvailableExtension清掃時間 | `reservation_helpers.php` | Claude |
| R9 | .htaccess拡張子ブロック追加 | `.htaccess` | Claude |
| N1 | line_richmenu.php認証順序 | `line_richmenu.php` | Codex |
| N2 | line.php認証順序 | `line.php` | Codex |
| C1 | 001_create_tables.sql定義不整合 | `001_create_tables.sql` | Claude |
| **C2** | **checkReservationConflict バインド順序バグ** | **reservation_helpers.php** | **Claude (CRITICAL)** |

### C2 詳細（最重要修正）
`checkReservationConflict` で `$excludeReservationId` が `$params` 配列の末尾に追加されていたが、SQL上の `r.id != ?` プレースホルダは `INTERVAL ? MINUTE` より前に出現。`$excludeReservationId` が非nullの場合、パラメータの順序不整合でSQLが誤動作する潜在バグ。

修正: パラメータ配列をSQL出現順に構築するよう変更。

---

## DBマイグレーション

| ファイル | 内容 |
|---------|------|
| `database/016_add_email_to_login_attempts.sql` | login_attemptsにemail列追加、複合UNIQUE KEY |

※ `001_create_tables.sql` のlogin_attempts定義も整合性のため更新済み

---

## 変更ファイル一覧

### 修正されたファイル
- `public_html/api/line/webhook.php` — デバッグログ除去、トークン再利用
- `public_html/api/line/webhook_store.php` — HTMLエスケープ除去
- `public_html/api/line/config.php` — store_code化、レート制限
- `public_html/api/booking/check-availability.php` — 関連修正
- `public_html/includes/auth.php` — ダミーハッシュ、Email対応、認証順序
- `public_html/includes/config.php` — 設定関連
- `public_html/includes/functions.php` — ヘルパー修正
- `public_html/includes/reservation_helpers.php` — 清掃バッファ統一、バインド順序修正、翌日チェック
- `public_html/pages/booking/complete.php` — TOCTOU修正
- `public_html/pages/booking/confirm.php` — 定員チェック、クーポン無効化
- `public_html/pages/booking/customer.php` — LINE ID検証
- `public_html/pages/booking/thanks.php` — リフレッシュ対応
- `public_html/pages/reservations/create.php` — 空きチェック、過去日警告
- `public_html/pages/reservations/detail.php` — 遷移マップ、楽観ロック
- `public_html/pages/jobs/detail.php` — 報酬更新制限
- `public_html/pages/cleaners/detail.php` — 関連修正
- `public_html/pages/register/stores.php` — is_active フィルタ
- `public_html/pages/settings/index.php` — 認証追加
- `public_html/pages/settings/line.php` — 空上書き防止、認証順序
- `public_html/pages/settings/line_richmenu.php` — ファイル名衝突防止、認証順序
- `public_html/pages/settings/stores.php` — LINE設定クリーンアップ
- `public_html/pages/apply/index.php` — 削除済み案件フィルタ
- `public_html/pages/logs/list.php` — 店舗スコープ
- `public_html/pages/keys/create.php` — setOld()修正
- `public_html/pages/keys/list.php` — 空配列ガード
- `public_html/index.php` — ルーター認証追加、404修正
- `public_html/.htaccess` — HTTPS、拡張子ブロック
- `database/001_create_tables.sql` — login_attempts定義整合
- `database/016_add_email_to_login_attempts.sql` — 新規マイグレーション

---

## 残存する任意改善項目（MUST-FIXではない）

| # | 内容 | リスク |
|---|------|--------|
| R5 | webhook レート制限のトランザクション化 | LOW |
| R6 | 報酬更新のTOCTOU（楽観ロック化） | LOW |
| R7 | UI側にも遷移マップ反映 | LOW |
| R12 | goto文のリファクタリング | LOW |
| N3 | apply CSRF トークンの統一 | LOW |
| 5-F | 深夜跨ぎ判定の共通化 | LOW（現行動作OK） |

---

## 検証結果

- **Round 1**: Claude 4件 + Codex 2件 = 6件 MUST-FIX → 全件修正
- **Round 2**: Claude LGTM (0件) + Codex LGTM (0件) = **合格**
