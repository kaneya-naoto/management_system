# LINE連携 概要

## 目的

清掃業務応募者専用のLINE公式アカウントを通じて、案件通知・応募管理を自動化

---

## 全体フロー

```
[清掃者] LINE友達追加
    ↓
[システム] 初回登録フロー開始
    ↓
[清掃者] プロフィール登録
    ↓
[清掃者] 対応店舗選択
    ↓
[システム] iPass発行
    ↓
[清掃者] 応募可能状態

--- 案件発生時 ---

[システム] 案件URL発行・Push通知
    ↓
[清掃者] 応募画面で YES/NO
    ↓
[システム] 応募処理 → CMS反映
```

---

## LINE Messaging API

### 使用機能

| 機能 | 用途 |
|------|------|
| Push Message | 案件通知、急募通知 |
| Reply Message | 初回登録時の対話 |
| LIFF または 外部Web | 応募画面、プロフィール登録 |

### Webhook

```
POST /api/line/webhook
X-Line-Signature: {署名}
```

---

## セキュリティ

### 署名検証

```php
$channelSecret = $lineAccount['channel_secret']; // line_accountsテーブルから取得
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

$hash = hash_hmac('sha256', $body, $channelSecret, true);
$expectedSignature = base64_encode($hash);

// タイミング攻撃対策: hash_equals()を使用
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(400);
    exit;
}
```

共通ヘルパー関数（`line_helper.php`）:

```php
function verifyLineSignature(string $body, string $signature, string $channelSecret): bool
{
    $hash = hash_hmac('sha256', $body, $channelSecret, true);
    $expectedSignature = base64_encode($hash);
    return hash_equals($expectedSignature, $signature);
}
```

### 冪等性

同一リクエストの重複処理を防止（`webhook_events`テーブル使用）：

```php
// webhookEventIdで重複チェック（line_helper.php）
function isWebhookEventProcessed(string $eventId): bool
{
    $existing = dbSelectOne(
        "SELECT id FROM webhook_events WHERE event_id = ?",
        [$eventId]
    );
    return $existing !== null;
}

// 処理済みとして記録
function markWebhookEventProcessed(string $eventId, string $eventType, string $accountType, ?int $storeId = null): void
{
    dbInsert('webhook_events', [
        'event_id' => $eventId,
        'account_type' => $accountType,  // 'cleaner' or 'store'
        'store_id' => $storeId,          // 店舗用の場合のみ
        'event_type' => $eventType
    ]);
}
```

### 応募URLのワンタイムトークン

```
https://example.com/apply?token={token}

- tokenは64文字のランダム文字列（bin2hex(random_bytes(32))）
- tokenは1回限り有効（使用後 used_at にタイムスタンプ記録）
- 有効期限: 固定者向け30分 / 公募向け120分
- application_tokensテーブルで管理
```

---

## 識別方法

### LINE UserID

- 33文字の文字列（Uから始まる）
- ユーザーごとに固有
- `cleaners.line_user_id` に保存

### iPass

- 初回登録時に発行される認証コード
- 2回目以降のログインに使用可能
- LINE UserIDで自動識別できない場合のフォールバック

---

## 通知種別

| 種別 | トリガー | 内容 |
|------|----------|------|
| 案件通知 | 公募開始 | 案件URL、日時、場所 |
| 固定者通知 | 案件生成（固定者あり） | 優先案内 |
| 急募通知 | 急募条件該当 | 【急募】マーク付き |
| 延長確認 | 延長申請 | 延長可否の確認 |
| 確定通知 | 担当決定 | 担当確定の連絡 |

---

## 決定済み事項

| 項目 | 決定内容 |
|------|----------|
| 応募画面 | 外部Web（`/apply?token={token}`） |
| 応募競合 | 先着方式（SELECT ... FOR UPDATEで排他制御） |
| 通知タイミング | 即時（Push Message） |
| 通知形式 | プレーンテキスト（Push Message API） |
| 登録画面 | 外部Web（トークン方式、`/register?token={token}`） |

---

## 関連ドキュメント

- [LINE初回登録](line_registration.md)
- [LINE応募](line_application.md)
- [cleanersテーブル](../../db/tables/cleaners.md)

---

*Last Updated: 2026-01-29*
