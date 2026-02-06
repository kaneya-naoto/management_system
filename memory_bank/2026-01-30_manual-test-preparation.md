# 手動テスト事前検証・修正レポート（2026-01-30）

## 概要

手動テスト計画書（全147テストケース / 5フェーズ）に基づき、コードの事前検証を実施。6件の問題を発見し、全件修正済み。

## 修正サマリー

| # | カテゴリ | 内容 | ファイル | 重要度 |
|---|---------|------|----------|--------|
| 1 | KNOWN-01 | `/ipass/view` ルーティング追加 | `index.php` | High |
| 2 | KNOWN-02 | `/apply/list` ルーティング追加 | `index.php` | High |
| 3 | RSV-10 | CMS予約作成の電話番号形式バリデーション追加 | `reservations/create.php` | Medium |
| 4 | SEC-14 | Referrer-Policy ヘッダー追加（.htaccess） | `.htaccess` | Medium |
| 5 | 多層防御 | `/cleaners/new` にルーティングレベルの `requireRole('OWNER')` 追加、`/settings/fixed-cleaners` にページレベルの `requireLogin(); requireRole('OWNER')` 追加 | `index.php`, `fixed-cleaners.php` | Medium |
| 6 | BKG-08 | 予約確定ボタンにJS二重送信防止追加 | `confirm.php` | Low |

---

## 修正詳細

### 1. KNOWN-01: /ipass/view ルーティング追加

**問題**: `pages/ipass/view.php` が存在し、LINE Webhookから `/ipass/view?token=xxx` リンクが生成されるが、`index.php` にルーティングが未登録のため404。

**修正**: `index.php` に以下を追加:
```php
case '/ipass/view':
    require __DIR__ . '/pages/ipass/view.php';
    break;
```

### 2. KNOWN-02: /apply/list ルーティング追加

**問題**: `pages/apply/list.php`（清掃者向け案件一覧）が存在し、cronジョブから `/apply/list?token=xxx` リンクが生成されるが、`index.php` にルーティングが未登録のため404。

**修正**: `index.php` に以下を追加:
```php
case '/apply/list':
    require __DIR__ . '/pages/apply/list.php';
    break;
```

### 3. RSV-10: 電話番号バリデーション追加

**問題**: `reservations/create.php` で電話番号の文字数チェック（20文字以内）のみ実装。英字等の不正文字列がバリデーションを通過する。

**修正**: 正規表現 `/^[0-9\-\+\s\(\)]+$/` による形式チェックを追加（公開予約フォーム `confirm.php` と同一パターン）。

### 4. SEC-14: Referrer-Policy ヘッダー追加

**問題**: 公開ページ（`public_header.php`）にはReferrer-Policyが設定済みだが、管理画面（`header.php`）と `.htaccess` には未設定。

**修正**: `.htaccess` に `Header set Referrer-Policy "strict-origin-when-cross-origin"` を追加。サイト全体に適用されるため、管理画面にも自動で効く。

### 5. 多層防御: 権限チェック追加

**問題**:
- `/cleaners/new`: `index.php` のルーティングに `requireRole('OWNER')` がない（ページファイル内にはある）
- `/settings/fixed-cleaners`: ページファイル内に `requireRole()` がない（ルーティングにはある）

**修正**: 両方に権限チェックを追加して多層防御を徹底。

### 6. BKG-08: 予約フォーム二重送信防止

**問題**: 確認画面の「予約を確定する」ボタンにフロントエンド側の二重送信防止がない。サーバー側CSRFトークン消費で2回目は403になるが、UXが悪い。

**修正**: `onclick` イベントでボタン無効化 + テキスト変更「処理中...」+ `form.submit()` を追加。

---

## 検証結果サマリー（事前コード検証）

### Phase 1: 認証 (AUTH-01〜11)
全テストケースのコード実装を確認 → **問題なし**

### Phase 1: 予約管理 (RSV-01〜17)
- RSV-01〜09: **問題なし**（バリデーション、店舗フィルタ、ステータス遷移ルール全て実装済み）
- RSV-10: **修正済み**（電話番号バリデーション追加）
- RSV-11〜17: **問題なし**

### Phase 1: 清掃案件 (JOB-01〜06)
全テストケースのコード実装を確認 → **問題なし**（楽観ロック、トランザクション、報酬上限チェック全て実装済み）

### Phase 2: 主要機能
- BKG-01〜09: **1件修正済み**（二重送信防止UI追加）
- SFT, LOG, PAY, SET: **問題なし**

### Phase 3: セキュリティ (SEC-01〜14)
- SEC-01〜13: **問題なし**（CSRF、SQLi、XSS、Open Redirect、セッション再生成、レート制限全て実装済み）
- SEC-14: **修正済み**（Referrer-Policy追加）

### Phase 4: 権限テスト
- マトリクスとコード実装が一致 → **問題なし**
- `/cleaners/new`: STOREは403（ページ内の`requireRole('OWNER')`で制御 + ルーティングにも追加済み）
- 多層防御を強化 → **修正済み**

### Phase 5: エッジケース
- EDGE-01〜07: コード上、適切なハンドリングが実装されていることを確認

---

## テスト時の注意事項

| テストケース | 注意事項 |
|-------------|---------|
| RSV-06 | 鍵自動割当・清掃案件自動生成は `payment_status=paid`（確定済み）の場合のみ動作。テスト時は決済状態を「支払済」に設定すること |
| RSV-09 | 深夜跨ぎの判定は18時以降開始 & 6時以前終了に限定。テスト計画の開始18:00/終了14:00 はエラーになることを確認 |
| AUTH-07 | ログインロックはIP+Email複合キー。テスト時は同一IP・同一メールで5回連続失敗が必要 |

---

## 変更ファイル一覧

| ファイル | 変更内容 |
|---------|---------|
| `public_html/index.php` | `/ipass/view`, `/apply/list` ルート追加、`/cleaners/new` に `requireRole('OWNER')` 追加 |
| `public_html/pages/reservations/create.php` | 電話番号形式バリデーション追加 |
| `public_html/.htaccess` | `Referrer-Policy` ヘッダー追加 |
| `public_html/pages/settings/fixed-cleaners.php` | `requireLogin(); requireRole('OWNER')` 追加 |
| `public_html/pages/booking/confirm.php` | 二重送信防止UI（ボタン無効化）追加 |
