# LINE連携 仕様書（実装ベース）

> 作成日: 2026-01-29
> 対象: カクレマ新基幹システム LINE連携機能全体
> ベース: 実装コード + spec仕様書の読み比べ

---

## 1. 概要

カクレマ新基幹システムにおけるLINE連携は、**2種類のLINE公式アカウント**を軸に構成される。

1. **清掃者用LINEアカウント**: 清掃スタッフの登録・案件通知・応募・完了報告を自動化
2. **店舗用LINEアカウント**: 各店舗の顧客向け予約受付・情報提供

LINE設定はすべて `line_accounts` テーブルでDB管理されており、管理画面から設定・変更が可能。

### 使用するLINE API

| API | 用途 |
|-----|------|
| Messaging API - Push Message | 案件通知、登録完了通知、iPassコード送信 |
| Messaging API - Reply Message | Webhook応答（店舗用で使用） |
| Messaging API - Rich Menu | リッチメニュー作成・画像アップロード・デフォルト設定 |
| LIFF | 店舗用予約フォーム（LINE内表示） |
| OAuth2 - ID Token検証 | LIFF認証のサーバーサイド検証 |

---

## 2. LINEアカウント構成

### 2.1 清掃者用アカウント

| 項目 | 内容 |
|------|------|
| account_type | `cleaner` |
| store_id | NULL（店舗横断） |
| 用途 | 清掃スタッフの登録・案件通知・応募管理 |
| Webhook URL | `{APP_URL}/api/line/webhook` |
| 制約 | **システム全体で1つのみ**（重複登録チェックあり） |

**設定取得関数:**
```php
// line_helper.php
function getCleanerLineConfig(): ?array
```

### 2.2 店舗用アカウント

| 項目 | 内容 |
|------|------|
| account_type | `store` |
| store_id | 紐づく店舗のID |
| 用途 | 顧客向け予約受付・店舗情報提供 |
| Webhook URL | `{APP_URL}/api/line/webhook/store/{store_code}` |
| 制約 | **1店舗につき1つ**（重複登録チェックあり） |

**設定取得関数:**
```php
// line_helper.php
function getStoreLineConfig(int $storeId): ?array
```

### 2.3 line_accounts テーブル

| カラム | 型 | 説明 |
|--------|-----|------|
| id | BIGINT | 主キー |
| account_type | ENUM('store', 'cleaner') | アカウント種別 |
| store_id | BIGINT NULL | 店舗ID（店舗用のみ） |
| name | VARCHAR(100) | アカウント名（表示用） |
| channel_id | VARCHAR(50) | LINE Channel ID |
| channel_secret | VARCHAR(100) | LINE Channel Secret |
| channel_access_token | TEXT | LINE Channel Access Token |
| liff_id | VARCHAR(50) | LIFF ID |
| webhook_url | VARCHAR(255) | Webhook URL（自動生成） |
| is_active | TINYINT(1) | 有効フラグ |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |
| deleted_at | DATETIME NULL | 論理削除日時 |

---

## 3. Webhook処理

### 3.1 署名検証

両Webhookとも `X-Line-Signature` ヘッダーによるHMAC-SHA256署名検証を行う。

**清掃者用 (`webhook.php`):**
```php
$hash = hash_hmac('sha256', $body, $channelSecret, true);
$expectedSignature = base64_encode($hash);
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(400);
    exit;
}
```

**店舗用 (`webhook_store.php`):**
```php
// line_helper.php の共通関数を使用
if (!verifyLineSignature($body, $signature, $lineConfig['channel_secret'])) {
    http_response_code(401);
    exit;
}
```

署名検証失敗時のログにはセキュリティ考慮があり、署名値そのものは出力せず、IPアドレスとContent-Lengthのみ記録する（清掃者用Webhook）。

### 3.2 レート制限

清掃者用Webhookのみ実装。IP単位で **60リクエスト/分** の制限。

| 優先度 | ストレージ | 方式 |
|--------|-----------|------|
| 1（推奨） | APCu | `apcu_store` / `apcu_inc` / `apcu_fetch`（TTL: 120秒） |
| 2（フォールバック） | DB | `webhook_rate_limits` テーブル（`FOR UPDATE` でrace condition対策） |

- レート超過時: HTTP 429 + `Retry-After: 60` ヘッダー
- DB接続エラー時: レート制限をスキップしてサービス継続を優先

**店舗用Webhookにはレート制限が未実装。**

### 3.3 冪等性チェック

**店舗用Webhookのみ実装。** `webhookEventId` による重複検出を行う。

```php
// line_helper.php
function isWebhookEventProcessed(string $eventId): bool
function markWebhookEventProcessed(string $eventId, string $eventType, string $accountType, ?int $storeId = null): void
```

保存先: `webhook_events` テーブル

| カラム | 説明 |
|--------|------|
| event_id | webhookEventId（UNIQUE） |
| event_type | イベント種別 |
| account_type | 'store' / 'cleaner' |
| store_id | 店舗ID |

**清掃者用Webhookには冪等性チェックが未実装。**

### 3.4 清掃者用Webhook - followイベント処理

友だち追加時の処理フロー:

```
[清掃者] LINE友だち追加
    |
    v
[システム] LINE UserIDでcleanersテーブル検索
    |
    +-- 登録済み(completed) --> "おかえりなさい"メッセージ送信
    |
    +-- 途中の登録あり --> registration_tokenを更新（1時間有効）
    |                       登録URLをボタンテンプレートで送信
    |
    +-- 新規 --> cleanersテーブルに仮登録（pending状態）
                 registration_tokenを生成（1時間有効）
                 ウェルカムメッセージ + 登録ボタンテンプレートを送信
```

**登録トークン仕様:**
- 形式: `bin2hex(random_bytes(32))` = 64文字の16進数文字列
- 有効期限: 1時間
- 登録URL: `{APP_URL}/register?token={token}`

### 3.5 清掃者用Webhook - messageイベント処理

テキストメッセージ受信時の分岐:

| 条件 | 処理 |
|------|------|
| 未登録 or 登録未完了 | 登録URLを再送信 |
| `ipass` or `コード`（大文字小文字不問） | iPassコード確認（ワンタイムトークン方式） |
| 完了キーワード（`完了`, `終了`, `終わりました`, `done`） | 清掃完了報告処理 |
| `ヘルプ` or `help` or `?` | ヘルプメッセージ表示 |
| その他 | デフォルト応答 |

### 3.6 清掃者用Webhook - postbackイベント処理

`parse_str` でデータをパースし、`action` パラメータで分岐:

| action | 処理 |
|--------|------|
| `check_jobs` | 募集中案件一覧（最大5件）を表示 |
| `complete_job` | 指定案件（`job_id`パラメータ）の清掃完了処理 |

### 3.7 店舗用Webhook - followイベント処理

友だち追加時:
- 店舗名入りウェルカムメッセージ送信
- LIFF IDが設定されている場合、予約ボタン（LIFF URL）を追加

### 3.8 店舗用Webhook - unfollowイベント処理

ブロック時:
- ログ記録のみ（`error_log`）

### 3.9 店舗用Webhook - messageイベント処理

テキストメッセージのキーワード応答:

| キーワード（部分一致） | 応答内容 |
|----------------------|----------|
| `予約` | LIFF予約ボタン or 予約URL |
| `場所` / `アクセス` / `住所` | 店舗住所 |
| `営業` / `時間` | 営業時間（24h営業対応） |
| その他 | 応答なし（無視） |

### 3.10 店舗用Webhook - postbackイベント処理

| action | 処理 |
|--------|------|
| `booking` | LIFF予約ボタン送信 |

### 3.11 メッセージ送信方式

**清掃者用Webhook:**
- `replyMessage()` 関数は、`userId` が渡された場合は **常にPush APIを使用**（Reply APIはタイムアウトしやすいため）
- `userId` がない場合のみReply APIを使用
- タイムアウト: 30秒

**店舗用Webhook:**
- `sendLineReplyMessage()` で Reply API を使用
- `line_helper.php` の共通 `sendLineApiRequest()` 関数経由

---

## 4. 清掃者登録フロー

3ステップの公開ページによるウィザード形式。

### 4.1 Step1: プロフィール登録

**URL:** `/register?token={token}`
**ファイル:** `public_html/pages/register/index.php`

**トークン検証:**
- 64文字の16進数文字列であること
- `cleaners.registration_token` と一致
- `registration_token_expires_at > NOW()` (期限内)
- `deleted_at IS NULL`

**入力項目:**

| 項目 | 必須 | バリデーション |
|------|------|---------------|
| 名前（name） | 必須 | 空でないこと、50文字以内 |
| 電話番号（phone） | 任意 | 数字のみ10-11桁（ハイフン等は除去して検証） |

**処理:**
1. バリデーション通過後、`cleaners` テーブルを更新
2. `registration_status` を `profile_done` に変更
3. `/register/stores?token={token}` へリダイレクト

**状態遷移ガード:**
- `registration_status === 'completed'` -> "登録済み"表示
- `registration_status === 'profile_done'` -> Step2へリダイレクト

### 4.2 Step2: 店舗選択

**URL:** `/register/stores?token={token}`
**ファイル:** `public_html/pages/register/stores.php`

**表示:**
- 全アクティブ店舗一覧（`stores WHERE deleted_at IS NULL`）
- チェックボックスによる複数選択UI

**バリデーション:**
- 1店舗以上選択必須
- 選択された店舗IDが有効な店舗IDかチェック
- CSRFトークン検証

**処理（トランザクション内）:**
1. 既存の `cleaner_stores` レコードを削除
2. 選択された店舗ごとに `cleaner_stores` レコードを挿入
3. iPassコードを生成
4. `cleaners` テーブルを更新:
   - `is_active = 1`
   - `registration_status = 'completed'`
   - `registration_token = NULL`（トークン無効化）
   - `registration_token_expires_at = NULL`
   - `ipass_code = {生成コード}`
   - `registered_at = NOW()`
5. LINEに完了メッセージ + iPassコードを送信
6. 完了画面を表示（iPassコード表示）

**状態遷移ガード:**
- `registration_status === 'pending'` -> Step1へリダイレクト
- `registration_status === 'completed'` -> "登録済み"表示

### 4.3 iPassコード発行

**生成関数:** `generateIPassCode()` (`user_helpers.php`)

| 項目 | 内容 |
|------|------|
| 形式 | 6文字の大文字16進数（`strtoupper(bin2hex(random_bytes(3)))`） |
| 例 | `A1B2C3` |
| 衝突回避 | 最大100回のリトライループで重複チェック |
| 有効期限 | なし（永続） |
| 用途 | LINE以外からのログイン認証 |

---

## 5. 応募フロー

### 5.1 応募トークン生成

**関数:** `generateApplicationToken()` (`job_helpers.php`)

```php
function generateApplicationToken(
    int $jobId,
    ?int $cleanerId,    // 固定者: cleaner_id指定、公募: null
    string $type = 'public',  // 'fixed' or 'public'
    int $expiresMinutes = 60
): string
```

| 項目 | 内容 |
|------|------|
| トークン形式 | `bin2hex(random_bytes(32))` = 64文字の16進数 |
| 保存先 | `application_tokens` テーブル |
| 応募URL | `{APP_URL}/apply?token={token}` |

### 5.2 固定者応募

**トークン生成:** `generateApplicationToken($jobId, $cleanerId, 'fixed', 30)`
- 有効期限: **30分**
- 清掃者IDが紐づいたパーソナルトークン

**応募画面 (`/apply?token={token}`):**
- 「{名前}さん専用のご案内」ヘッダー表示
- 「受ける（YES）」ボタン -> 応募処理
- 「受けない（NO）」ボタン -> 辞退処理

**辞退時の処理:**
1. `notification_logs` の `response` を `ng` に更新
2. トークンを使用済みに
3. （タイムアウト処理により次の固定者または公募に移行）

### 5.3 公募応募

**トークン生成:** `generateApplicationToken($jobId, null, 'public', 120)`
- 有効期限: **120分（2時間）**
- 全清掃者共通トークン（cleaner_id = NULL）

**応募画面:**
- 「清掃スタッフ募集」ヘッダー表示
- セッションの `line_user_id` から清掃者を特定
- LINE認証済み: 「応募する」ボタン表示
- LINE未認証: 「LINEから案件通知を受け取ってください」メッセージ

### 5.4 先着採用ロジック

応募時の処理（トランザクション + 行ロック）:

```
1. cleaning_jobs を FOR UPDATE でロック取得
2. assigned_cleaner_id が NULL かチェック（先着判定）
3. job_applications に 'accepted' ステータスで挿入
4. cleaning_jobs を更新:
   - assigned_cleaner_id = 応募者
   - status = 'assigned'
   - notification_status = 'completed'
5. application_tokens を used_at で無効化
6. (固定者の場合) notification_logs の response を 'ok' に更新
```

既に担当者がいる場合: 「残念ながら、この案件は他の方に決まりました」

### 5.5 固定者タイムアウト処理

**関数:** `processFixedCleanerTimeout()` (`notification_helpers.php`)

| 項目 | 内容 |
|------|------|
| デフォルトタイムアウト | 30分 |
| 対象 | `notification_status = 'fixed_waiting'` かつ `fixed_notification_sent_at` から指定時間経過 |
| 次の固定者がいる場合 | 次の優先度の固定者に通知 |
| 固定者が尽きた場合 | 公募通知を開始 |

---

## 6. 通知テンプレート

すべての通知は `sendLineNotification()` 関数経由でPush Message APIを使用。リトライ機能付き（最大2回、5xx系エラーのみ）。

### 6.1 案件通知（公募）

```
新しい清掃案件があります！

[emoji] 場所: {area_name}
[emoji] 日時: {n月j日} {H:i}
[emoji] 報酬: {base_reward}円

▼ 詳細・応募はこちら
{application_url}
```

### 6.2 急募通知

```
【急募】清掃スタッフ募集！

[emoji] 場所: {area_name}
[emoji] 日時: {n月j日} {H:i}
[emoji] 報酬: {base_reward}円

[emoji] お急ぎの案件です

▼ 詳細・応募はこちら
{application_url}
```

### 6.3 固定者通知

```
{cleaner_name}さん専用のご案内です

[emoji] 場所: {area_name}
[emoji] 日時: {n月j日} {H:i}
[emoji] 報酬: {base_reward}円

このまま対応可能ですか？

▼ 詳細・回答はこちら
{application_url}
```

### 6.4 完了通知（LINE Webhook経由）

```
お疲れ様でした！

[emoji] {area_name}
[emoji] {H:i}〜

清掃完了を受け付けました。
報酬は次回の支払い日にお支払いします。
```

### 6.5 登録完了通知

```
登録が完了しました！

あなたのiPass: {ipassCode}

このコードは大切に保管してください。
LINE以外からログインする際に使用します。

清掃案件が届いた際はこちらでお知らせしますね！
```

### 6.6 初回登録ウェルカムメッセージ

2メッセージ構成:
1. テキスト: 「はじめまして！カクレマ清掃スタッフへようこそ。」
2. ボタンテンプレート: 「登録を始める」ボタン（登録URL）

### 6.7 重複チェック付き通知

**関数:** `sendNotificationWithDedup()` (`notification_helpers.php`)

| 項目 | 内容 |
|------|------|
| 重複判定 | `daily_notification_logs` テーブルで job_id + cleaner_id + date の一意制約 |
| 方式 | `INSERT IGNORE` で挿入を試み、影響行数0なら既に通知済み |
| 状態管理 | pending -> sent / failed |

---

## 7. LINE設定管理画面

### 7.1 LINE連携設定

**URL:** `/settings/line`
**ファイル:** `public_html/pages/settings/line.php`

**アクセス権限:** OWNER または HQ ロール

**権限による表示範囲:**

| ロール | 表示範囲 |
|--------|---------|
| HQ | 全店舗のLINE設定 + 清掃者用 |
| OWNER | 自分の店舗のLINE設定 + 清掃者用（閲覧のみ） |

**IDOR対策:** OWNERは自分が所有する店舗（`owners.user_id` -> `stores.owner_id`）のLINE設定のみ操作可能。更新・削除時にも既存レコードの店舗所有者を再チェック。

**管理画面の構成:**

1. **清掃者用LINEセクション:**
   - 設定済み: アカウント名、Channel ID、Webhook URL（コピーボタン付き）、LIFF ID、ステータス表示
   - 編集ボタン、リッチメニューリンク
   - 未設定: 追加ボタン

2. **店舗用LINEセクション:**
   - テーブル一覧（店舗名、アカウント名、Channel ID、LIFF ID、ステータス）
   - 追加・編集・削除ボタン、リッチメニューリンク

**モーダルフォーム（create/update共通）:**

| フィールド | 説明 |
|-----------|------|
| 店舗（store型のみ） | プルダウン選択 |
| アカウント名 | 必須 |
| Channel ID | 任意 |
| Channel Secret | パスワード入力（編集時は空欄） |
| Channel Access Token | テキストエリア |
| LIFF ID | 任意 |
| 有効にする | チェックボックス |
| Webhook URL | 自動生成・読み取り専用（編集時のみ表示） |

**Webhook URL自動生成:**
```php
function generateWebhookUrl(string $accountType, ?int $storeId = null): string
// cleaner -> {APP_URL}/api/line/webhook
// store   -> {APP_URL}/api/line/webhook/store/{store_code}
```

**店舗設定ページからのディープリンク:**
`/settings/line?store_id={id}` でアクセスすると、該当店舗のLINE設定モーダルを自動表示。

### 7.2 リッチメニュー設定

**URL:** `/settings/line/richmenu?account_id={id}`
**ファイル:** `public_html/pages/settings/line_richmenu.php`

**アクセス権限:** OWNER または HQ（IDOR対策あり）

**設計:** 1アカウント1メニューのシンプル構成

**テンプレート配置パターン:**

| テンプレート | 説明 | ボタン数 |
|-------------|------|---------|
| `1` | 全面1つ | 1 |
| `2h` | 横2分割 | 2 |
| `2v` | 縦2分割 | 2 |
| `3` | 横3分割 | 3 |
| `4` | 2x2グリッド | 4 |
| `6` | 3x2グリッド | 6 |

**画像サイズ:** 2500 x 1686px（Large固定）
**画像制限:** 1MB以下、PNG/JPGのみ
**保存先:** `public_html/uploads/richmenu/`

**ボタンアクション種別:**

| 種別 | 説明 |
|------|------|
| message | テキストメッセージ送信 |
| uri | URL遷移 |
| postback | Postbackデータ送信 |

**LINE反映処理（deploy）:**
1. `createLineRichMenu()` - メニュー作成API
2. `uploadRichMenuImage()` - 画像アップロードAPI（`api-data.line.me`）
3. `setDefaultRichMenu()` - デフォルトメニュー設定API
4. 失敗時は作成済みメニューをロールバック削除

**削除処理:**
1. LINE APIでメニュー削除
2. ローカル画像ファイル削除
3. DBレコード論理削除

---

## 8. iPassコード管理

### 8.1 iPassコード閲覧（ワンタイムトークン方式）

**セキュリティ設計:**
iPassコードは直接LINEメッセージで送信せず、ワンタイムトークン付きWebページで表示する。

**フロー:**
```
[清掃者] LINEで「ipass」or「コード」と送信
    |
    v
[システム] ワンタイムトークン生成（5分有効）
    |
    v
[システム] 既存の未使用トークンを全て無効化
    |
    v
[システム] 新しいトークンをipass_view_tokensに挿入
    |
    v
[システム] ボタンテンプレートでURL送信
    |
    v
[清掃者] URLタップ -> Webページでコード表示
    |
    v
[システム] トークンを使用済み（used_at更新）
```

**URL:** `/ipass/view?token={token}`
**ファイル:** `public_html/pages/ipass/view.php`

**トークン検証:**
- 64文字の16進数 (`ctype_xdigit` チェック)
- `expires_at > NOW()` (期限内)
- `used_at IS NULL` (未使用)
- 紐づく清掃者が `deleted_at IS NULL`

**ipass_view_tokens テーブル:**

| カラム | 説明 |
|--------|------|
| token | 64文字の16進数トークン |
| cleaner_id | 清掃者ID |
| expires_at | 有効期限（5分後） |
| used_at | 使用日時（ワンタイム制御） |

---

## 9. 主要関数一覧

### line_helper.php

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `getCleanerLineConfig()` | なし | `?array` | 清掃者用LINE設定取得 |
| `getStoreLineConfig($storeId)` | int | `?array` | 店舗用LINE設定取得 |
| `getLineConfigByChannelId($channelId)` | string | `?array` | Channel IDでLINE設定取得 |
| `verifyLineSignature($body, $signature, $channelSecret)` | string, string, string | bool | Webhook署名検証 |
| `isWebhookEventProcessed($eventId)` | string | bool | 冪等性チェック |
| `markWebhookEventProcessed($eventId, $eventType, $accountType, $storeId)` | string, string, string, ?int | void | 処理済み記録 |
| `sendLinePushMessage($lineUserId, $messages, $accountType, $storeId)` | string, array, string, ?int | bool | Push Message送信 |
| `sendLineReplyMessage($replyToken, $messages, $accessToken)` | string, array, string | bool | Reply Message送信 |
| `sendLineApiRequest($url, $data, $accessToken)` | string, array, string | bool | LINE API共通リクエスト |
| `createLineRichMenu($menuData, $accessToken)` | array, string | `?string` | リッチメニュー作成 |
| `uploadRichMenuImage($richMenuId, $imagePath, $accessToken)` | string, string, string | bool | リッチメニュー画像アップロード |
| `setDefaultRichMenu($richMenuId, $accessToken)` | string, string | bool | デフォルトリッチメニュー設定 |
| `deleteLineRichMenu($richMenuId, $accessToken)` | string, string | bool | リッチメニュー削除 |
| `verifyLiffIdToken($idToken, $channelId)` | string, string | `?array` | LIFF ID Token検証 |
| `generateWebhookUrl($accountType, $storeId)` | string, ?int | string | Webhook URL生成 |
| `getRichMenuSize($sizeType)` | string | array | リッチメニューサイズ取得 |

### notification_helpers.php（LINE関連部分）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `sendLineNotification($lineUserId, $message, $maxRetries)` | string, string, int | bool | LINE通知送信（リトライ付き） |
| `sendJobNotifications($jobId, $type)` | int, string | int | 案件通知（固定者/公募） |
| `getNextFixedCleaner($storeId, $jobId)` | int, int | `?array` | 次の固定者取得 |
| `processFixedCleanerTimeout($timeoutMinutes)` | int | array | 固定者タイムアウト処理 |
| `sendNotificationWithDedup($jobId, $cleanerId, $lineUserId, $message, $type)` | int, int, string, string, string | bool | 重複チェック付き通知 |

### job_helpers.php（LINE関連部分）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `generateApplicationToken($jobId, $cleanerId, $type, $expiresMinutes)` | int, ?int, string, int | string | 応募トークン生成 |
| `getApplicationUrl($token)` | string | string | 応募URL生成 |
| `isCompletionKeyword($text)` | string | bool | 完了キーワード判定 |
| `completeCleaningJob($jobId, $cleanerId)` | int, int | array | 清掃完了処理 |
| `getCleanerTodayJobs($cleanerId, $status)` | int, string | array | 当日の担当案件取得 |

### user_helpers.php（LINE関連部分）

| 関数名 | 引数 | 戻り値 | 説明 |
|--------|------|--------|------|
| `generateIPassCode()` | なし | string | iPassコード生成（衝突回避付き） |

### webhook.php 内ローカル関数

| 関数名 | 説明 |
|--------|------|
| `handleFollowEvent()` | 友だち追加イベント処理 |
| `handleMessageEvent()` | メッセージイベント処理 |
| `handlePostbackEvent()` | ポストバックイベント処理 |
| `handleCompletionReport()` | 清掃完了報告処理 |
| `replyMessage()` | LINEリプライ送信（Push優先） |
| `pushMessage()` | LINEプッシュ送信 |

---

## 10. spec仕様書との乖離

以下は spec/features/line/ 配下の仕様書と実装コードを比較して検出した乖離事項。

### 10.1 署名検証の実装差異

| 項目 | spec | 実装 |
|------|------|------|
| 比較方法 | `$signature !== $expectedSignature`（厳密比較） | `hash_equals()`（タイミング攻撃対策） |

**評価:** 実装のほうがセキュリティ的に正しい。specを更新すべき。

### 10.2 冪等性の実装範囲

| 項目 | spec | 実装 |
|------|------|------|
| 清掃者用Webhook | 実装すべきと記載 | **未実装** |
| 店舗用Webhook | 記載あり | 実装済み |

**評価:** 清掃者用Webhookにも冪等性チェックを追加すべき。spec通り。

### 10.3 レート制限の範囲

| 項目 | spec | 実装 |
|------|------|------|
| 記載 | なし（specに記載なし） | 清掃者用Webhookのみ実装 |
| 店舗用Webhook | - | **未実装** |

**評価:** 店舗用Webhookにもレート制限を追加すべき。

### 10.4 応募URLの有効期限

| 項目 | spec (line_overview.md) | 実装 |
|------|------------------------|------|
| 有効期限 | 24時間 | 固定者: 30分、公募: 120分 |
| ワンタイム | 1回限り有効 | 1回限り有効 |

**評価:** specが古い。実装のほうが実態に即している（固定者は短時間、公募は2時間）。specを更新すべき。

### 10.5 通知メッセージ形式

| 項目 | spec (cleaner_line_extension.md) | 実装 |
|------|--------------------------------|------|
| 形式 | Flex Message（JSON構造体） | テキストメッセージ（プレーンテキスト） |

**評価:** specではFlex Messageを提案しているが、実装はプレーンテキスト。Flex Messageは見栄えが良いが、実装はシンプルで保守しやすい。将来的にFlex Message化を検討可能。

### 10.6 登録フローのパラメータ受け渡し

| 項目 | spec (line_registration.md) | 実装 |
|------|---------------------------|------|
| 識別方法 | LINE UserIDをパラメータで受け渡し | registration_token（ワンタイムトークン）で受け渡し |
| トークン有効期限 | 記載なし | 1時間 |

**評価:** 実装のほうがセキュリティ的に優れている。LINE UserIDをURLパラメータに含めるのはセキュリティリスク。specを更新すべき。

### 10.7 登録処理の差異

| 項目 | spec | 実装 |
|------|------|------|
| `is_active` 初期値 | true（即座にアクティブ） | 0（仮登録） -> 店舗選択完了後に1 |
| 登録ステータス管理 | なし | `registration_status`: pending -> profile_done -> completed |

**評価:** 実装は段階的な登録フローを実装しており、specより洗練されている。specを更新すべき。

### 10.8 sendPushMessage vs sendLineNotification

| 項目 | spec (cleaner_line_extension.md) | 実装 |
|------|--------------------------------|------|
| 関数名 | `sendPushMessage()` | `sendLineNotification()`（テキスト用）+ `sendLinePushMessage()`（汎用） |
| リトライ | 記載なし | 最大2回リトライ（5xx系のみ）、指数バックオフ |

**評価:** 実装はリトライ機能付きで堅牢。ただし関数が2つに分かれている点は整理の余地あり。

### 10.9 店舗用LINE - 未決定事項の解決状況

| 未決定事項 (spec) | 実装での決定 |
|-------------------|-------------|
| 応募画面: LIFF vs 外部Web | **外部Web**（`/apply?token={token}`） |
| 応募競合: 先着 vs 抽選 | **先着方式**（`FOR UPDATE` + 行ロック） |
| 通知タイミング: 即時 vs バッチ | **即時**（`sendLineNotification` 都度呼び出し） |

**評価:** すべて実装で解決済み。specに反映すべき。

### 10.10 webhook_events テーブルの差異

| 項目 | spec (cleaner_line_extension.md) | 実装 |
|------|--------------------------------|------|
| カラム | event_id, event_type, processed_at | event_id, event_type, account_type, store_id |
| processed_at | あり | **なし**（created_atで代用の想定） |

**評価:** 実装は `account_type` と `store_id` を追加しており、マルチアカウント対応。specを更新すべき。

### 10.11 通知種別の実装状況

| 種別 (spec) | 実装状況 |
|-------------|---------|
| 案件通知（公募） | 実装済み |
| 固定者通知 | 実装済み |
| 急募通知 | 実装済み |
| 延長確認 | **未確認**（コード上は延長テーブルは存在するが、LINE通知は見当たらない） |
| 確定通知 | **一部実装**（応募画面の成功表示のみ、LINE Push通知としては未実装） |

**評価:** 延長確認通知と確定通知（LINE Push）が未実装の可能性あり。

### 10.12 店舗用LINE - DB拡張

| 項目 | spec (store_line.md) | 実装 |
|------|---------------------|------|
| reservations.line_user_id | 記載あり | **未確認**（reservationsテーブルのマイグレーションに含まれるか要確認） |
| reservations.line_display_name | 記載あり | **未確認** |

### 10.13 シークレット保存

| 項目 | spec | 実装 |
|------|------|------|
| 暗号化 | AES-256-GCM推奨 | **平文保存**（DB直接格納） |

**評価:** 本番環境では暗号化を検討すべき。

---

## 付録: ファイル一覧

| ファイル | 説明 |
|---------|------|
| `/public_html/api/line/webhook.php` | 清掃者用Webhookエンドポイント |
| `/public_html/api/line/webhook_store.php` | 店舗用Webhookエンドポイント |
| `/public_html/api/line/config.php` | LINE設定取得API（LIFF用） |
| `/public_html/pages/register/index.php` | プロフィール登録（Step1） |
| `/public_html/pages/register/stores.php` | 店舗選択（Step2） |
| `/public_html/pages/apply/index.php` | 応募画面（公開） |
| `/public_html/pages/ipass/view.php` | iPassコード閲覧 |
| `/public_html/pages/settings/line.php` | LINE連携設定（管理画面） |
| `/public_html/pages/settings/line_richmenu.php` | リッチメニュー設定（管理画面） |
| `/public_html/includes/line_helper.php` | LINE API ヘルパー関数 |
| `/public_html/includes/notification_helpers.php` | 通知関連ヘルパー関数 |
| `/public_html/includes/job_helpers.php` | 案件関連ヘルパー関数 |
| `/public_html/includes/user_helpers.php` | ユーザー関連ヘルパー関数 |

---

*Last Updated: 2026-01-29*
