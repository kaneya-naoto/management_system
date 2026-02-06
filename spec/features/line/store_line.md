# 店舗用LINE連携 仕様書

## 概要

各店舗が独自のLINE公式アカウントを持ち、予約フォームをLIFFとして提供する機能。

---

## 機能一覧

| 機能 | 説明 |
|------|------|
| LINE設定管理 | 店舗ごとのChannel ID/Secret/Access Token/LIFF ID管理 |
| LIFF予約フォーム | LINE内で予約フォームを表示 |
| 流入元判別 | LINE経由の予約を識別・記録 |
| リッチメニュー | 店舗ごとにカスタマイズ可能なメニュー |

---

## DB設計

### line_accounts テーブル

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

### line_rich_menus テーブル

| カラム | 型 | 説明 |
|--------|-----|------|
| id | BIGINT | 主キー |
| line_account_id | BIGINT | LINE Account ID |
| name | VARCHAR(100) | メニュー名 |
| rich_menu_id | VARCHAR(50) | LINE RichMenu ID |
| image_filename | VARCHAR(100) | 画像ファイル名 |
| menu_config | JSON | ボタン配置設定 |
| is_default | TINYINT(1) | デフォルトメニュー |
| is_active | TINYINT(1) | 有効フラグ |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |
| deleted_at | DATETIME NULL | 論理削除日時 |

### reservations テーブル拡張

| カラム | 型 | 説明 |
|--------|-----|------|
| line_user_id | VARCHAR(50) NULL | LINE経由予約時のUserID |
| line_display_name | VARCHAR(100) NULL | LINE表示名 |

---

## API仕様

### LINE設定取得

```
GET /api/line/config?store_id={store_id}
```

**レスポンス:**
```json
{
  "liff_id": "1234567890-abcdefgh",
  "has_config": true
}
```

### Webhook（店舗用）

```
POST /api/line/webhook/store/{store_code}
```

**処理:**
1. レート制限チェック（IP単位: 60リクエスト/分、APCu or DB）
2. 店舗コード → storesテーブル → line_accountsテーブルで設定取得
3. X-Line-Signature検証（`verifyLineSignature()` / `hash_equals()`使用）
4. 冪等性チェック（`isWebhookEventProcessed()`）
5. イベント処理（follow, unfollow, message, postback）
6. 処理済み記録（`markWebhookEventProcessed($eventId, $type, 'store', $storeId)`）

**対応イベント:**

| イベント | 処理 |
|---------|------|
| follow | ウェルカムメッセージ送信、LIFF予約ボタン表示 |
| unfollow | ログ記録のみ |
| message | キーワード応答（予約、場所/住所、営業時間） |
| postback | アクション処理（booking等） |

---

## LIFF連携フロー

```
[顧客] LINE内でリッチメニュータップ
    ↓
[システム] LIFF起動（予約フォーム表示）
    ↓
[JavaScript] liff.init() → liff.getProfile()
    ↓
[顧客] 予約情報入力
    ↓
[システム] セッションにLINE情報保存
    ↓
[顧客] 予約確定
    ↓
[システム] reservationsに保存（source='line', line_user_id）
```

---

## リッチメニュー設定

### ボタン配置例

```json
{
  "size": { "width": 2500, "height": 1686 },
  "areas": [
    {
      "bounds": { "x": 0, "y": 0, "width": 1250, "height": 843 },
      "action": { "type": "uri", "uri": "https://liff.line.me/{LIFF_ID}" }
    },
    {
      "bounds": { "x": 1250, "y": 0, "width": 1250, "height": 843 },
      "action": { "type": "uri", "uri": "tel:0312345678" }
    }
  ]
}
```

### 画像サイズ

- 2500 x 1686（Large）
- 2500 x 843（Compact）
- 1200 x 810（Small）

---

## 管理画面

### LINE設定 (/settings/line)

- LINE公式アカウント一覧
- 店舗用アカウント追加/編集
- 清掃者用アカウント設定
- Webhook URL表示・コピー

### リッチメニュー設定 (/settings/line/richmenu?account_id=X)

- メニュー一覧
- 画像アップロード
- ボタン配置エディタ（ドラッグ&ドロップ）
- デフォルトメニュー設定
- LINEへの反映（API連携）

---

## セキュリティ

### 認証・認可
- 管理画面: OWNER権限必須
- Webhook: 署名検証（店舗ごとのChannel Secret）
- LIFF: ID Token検証（サーバーサイド）

### シークレット保存
- Channel Secret/Access Token: DBに保存
- 本番環境では暗号化推奨（AES-256-GCM）

### 冪等性
- webhookEventIdを`webhook_events`テーブルに保存
- `account_type='store'`, `store_id`も記録
- 重複イベントは処理スキップ
- 共通ヘルパー: `isWebhookEventProcessed()` / `markWebhookEventProcessed()`

---

*Last Updated: 2026-01-29*
