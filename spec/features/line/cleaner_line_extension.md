# 清掃者用LINE連携 拡張仕様書

## 概要

既存の清掃者用LINE連携をDB管理に移行し、Push Message送信機能を追加する。

---

## 現状

### 実装済み
- `config.php`にグローバルLINE設定
- `webhook.php`でfollow/message/postbackイベント処理
- 清掃者登録フロー（プロフィール・店舗選択）
- 応募画面（application_tokens）
- 通知履歴（notification_logs）

### 課題
- グローバル定数で設定が固定
- Push Message未実装（replyMessageのみ）
- Webhook冪等性未実装

---

## 拡張内容

### 1. DB管理への移行

`line_accounts`テーブルで清掃者用アカウントも管理：

```sql
INSERT INTO line_accounts (
  account_type, store_id, name, channel_id, channel_secret, channel_access_token, is_active
) VALUES (
  'cleaner', NULL, '清掃者募集用', '現在のCHANNEL_ID', '現在のSECRET', '現在のTOKEN', 1
);
```

### 2. 設定読み込み変更

**Before (config.php):**
```php
define('LINE_CHANNEL_ID', getenv('LINE_CHANNEL_ID') ?: '');
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');
define('LINE_CHANNEL_ACCESS_TOKEN', getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
```

**After (line_helper.php):**
```php
function getCleanerLineConfig(): ?array {
    return dbSelectOne(
        "SELECT * FROM line_accounts WHERE account_type = 'cleaner' AND is_active = 1 AND deleted_at IS NULL"
    );
}
```

### 3. Push Message実装

```php
/**
 * Push Messageを送信
 * @param string $lineUserId 送信先LINE User ID
 * @param array $messages メッセージ配列
 * @param string $accountType 'store' or 'cleaner'
 * @param int|null $storeId 店舗ID（店舗用の場合）
 * @return bool
 */
function sendPushMessage(
    string $lineUserId,
    array $messages,
    string $accountType = 'cleaner',
    ?int $storeId = null
): bool {
    $config = $accountType === 'cleaner'
        ? getCleanerLineConfig()
        : getStoreLineConfig($storeId);

    if (!$config || empty($config['channel_access_token'])) {
        return false;
    }

    $response = httpPost('https://api.line.me/v2/bot/message/push', [
        'to' => $lineUserId,
        'messages' => $messages
    ], [
        'Authorization: Bearer ' . $config['channel_access_token'],
        'Content-Type: application/json'
    ]);

    return $response['statusCode'] === 200;
}
```

### 4. Webhook冪等性

**webhook_eventsテーブル:**
```sql
CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'webhookEventId',
  event_type VARCHAR(50) NOT NULL COMMENT 'イベント種別',
  account_type VARCHAR(20) NOT NULL DEFAULT 'cleaner' COMMENT 'アカウント種別（cleaner/store）',
  store_id BIGINT NULL COMMENT '店舗ID（店舗用の場合）',
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook冪等性管理';
```

**処理フロー（line_helper.php）:**
```php
// チェック
function isWebhookEventProcessed(string $eventId): bool
{
    $existing = dbSelectOne(
        "SELECT id FROM webhook_events WHERE event_id = ?",
        [$eventId]
    );
    return $existing !== null;
}

// 記録（account_type と store_id を追加記録）
function markWebhookEventProcessed(string $eventId, string $eventType, string $accountType, ?int $storeId = null): void
{
    dbInsert('webhook_events', [
        'event_id' => $eventId,
        'account_type' => $accountType,
        'store_id' => $storeId,
        'event_type' => $eventType
    ]);
}
```

**使用例:**
```php
// 清掃者用Webhook
markWebhookEventProcessed($eventId, $type, 'cleaner');

// 店舗用Webhook
markWebhookEventProcessed($eventId, $eventType, 'store', $store['id']);
```

---

## 通知テンプレート

### 通知形式

通知はすべて**プレーンテキスト**（`type: text`）で送信する。
Flex Messageは使用しない（シンプルさ・互換性を優先）。

### 案件通知（プレーンテキスト）

**通常案件:**
```
新しい清掃案件があります！

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

▼ 詳細・応募はこちら
{application_url}
```

**急募案件:**
```
【急募】清掃スタッフ募集！

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

⚠️ お急ぎの案件です

▼ 詳細・応募はこちら
{application_url}
```

**固定者通知:**
```
{cleaner_name}さん専用のご案内です

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

このまま対応可能ですか？

▼ 詳細・回答はこちら
{application_url}
```

### 送信方法

通知は `sendLineNotification()` 関数（`notification_helpers.php`）経由で送信。
Push Message APIを使用し、リトライ機能付き（最大2回リトライ、5xx系のみ）。

---

## 管理画面

### 清掃者用LINE設定

`/settings/line` の清掃者用セクション：

- Channel ID/Secret/Access Token編集
- 現在の設定確認
- テスト送信機能（管理者宛）

---

## 移行手順

1. `line_accounts`テーブル作成
2. 現在の`.env`設定をDBに移行
3. `webhook.php`をDB読み込みに変更
4. `sendLineNotification`を`sendPushMessage`に統合
5. 動作確認後、`.env`のLINE設定を削除可能に
