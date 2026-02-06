# LINE 応募・通知フロー

## 概要

案件発生時のLINE通知と、清掃者の応募フロー

---

## 案件通知フロー

### 通常案件

```
[予約確定] → [案件生成]
    ↓
[固定者チェック]
    ↓
┌─ 固定者あり ─→ [固定者通知] → [固定者応答待ち]
│                                    ↓
│                              ┌─ OK → [担当確定]
│                              └─ NG → [公募通知]
│
└─ 固定者なし ─→ [公募通知]
```

### 急募案件

```
[急募条件該当]
    ↓
[【急募】通知] → [応募待ち] → [担当確定]
```

---

## 通知メッセージ

通知はすべてプレーンテキスト（Push Message API）で送信。
`sendLineNotification()` 関数（`notification_helpers.php`）経由で、リトライ機能付き。

### 通常案件通知

```
新しい清掃案件があります！

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

▼ 詳細・応募はこちら
{application_url}
```

### 急募通知

```
【急募】清掃スタッフ募集！

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

⚠️ お急ぎの案件です

▼ 詳細・応募はこちら
{application_url}
```

### 固定者通知

```
{cleaner_name}さん専用のご案内です

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

このまま対応可能ですか？

▼ 詳細・回答はこちら
{application_url}
```

### 応募URLの生成

```php
// notification_helpers.php
$token = generateApplicationToken($jobId, $cleanerId, $type, $expiresMinutes);
// 固定者: generateApplicationToken($jobId, $cleanerId, 'fixed', 30)
// 公募:   generateApplicationToken($jobId, null, 'public', 120)

$url = getApplicationUrl($token);
// → https://example.com/apply?token={token}
```

---

## 応募画面

### 実装

- URL: `/apply?token={token}`（外部Web）
- トークン: 64文字のランダム文字列（`application_tokens`テーブル）
- 有効期限: 固定者向け30分 / 公募向け120分
- CSRF保護あり（セッションベース）

### 表示内容

| 項目 | 説明 |
|------|------|
| 場所 | 店舗名 + 営業区分名 |
| 日時 | 清掃予定日時（曜日付き） |
| 報酬 | 基本報酬額 |
| 備考 | 案件の備考（ある場合のみ） |

### 操作

**固定者向け画面:**

| ボタン | 処理 |
|--------|------|
| 受ける（YES） | 応募処理（即時担当確定） |
| 受けない（NO） | 辞退処理（notification_logsを'ng'更新） |

**公募向け画面:**

| ボタン | 処理 |
|--------|------|
| 応募する | 応募処理（先着で担当確定） |

公募の場合、清掃者はセッションのLINE User IDで特定する。

---

## 応募処理

### YESの場合（先着方式）

```
[応募ボタン押下]
    ↓
[CSRF検証]
    ↓
[案件を排他ロック] SELECT ... FOR UPDATE
    ↓
┌─ 空き ──→ [job_applicationsにacceptedで記録]
│            → [cleaning_jobs.assigned_cleaner_id設定]
│            → [cleaning_jobs.status='assigned']
│            → [application_tokens.used_at記録]
│            → [通知ログ更新（固定者の場合）]
│            → [担当確定通知 LINE Push送信]
│            → [完了画面表示]
│
└─ 埋まり ─→ [「他の方に決まりました」表示]
```

### NOの場合（固定者のみ）

```
[辞退ボタン押下]
    ↓
[CSRF検証]
    ↓
[notification_logs.response='ng'に更新]
[application_tokens.used_at記録]
    ↓
[辞退完了画面表示]
    ↓
（タイムアウト処理で次の固定者 or 公募に自動遷移）
```

---

## 応募競合の解決

### 先着方式（採用）

- `SELECT ... FOR UPDATE`で排他ロックを取得
- `assigned_cleaner_id IS NULL`を条件にUPDATE
- 最初に応募した人が担当に決定
- 2人目以降は「残念ながら、この案件は他の方に決まりました」を表示

---

## 応募結果通知

通知はプレーンテキスト（Push Message API）で送信。

### 担当確定時（LINE Push送信）

```
【担当確定】

{cleaner_name}さん、担当が確定しました！

📍 場所: {area_name}
📅 日時: {date} {time}
💰 報酬: {reward}円

当日よろしくお願いします！
```

### 落選時（Web画面表示のみ）

```
残念ながら、この案件は他の方に決まりました。
また次の案件でお待ちしています！
```

---

## 通知履歴

### 記録項目

| 項目 | 説明 |
|------|------|
| cleaner_id | 通知先清掃者 |
| job_id | 案件ID |
| type | 通知種別（normal/urgent/fixed） |
| sent_at | 送信日時 |
| message_id | LINE Message ID |

---

## 応募データ

### job_applications

| カラム | 説明 |
|--------|------|
| job_id | 案件ID |
| cleaner_id | 応募者ID |
| status | applied/rejected/selected |
| applied_at | 応募日時 |
| responded_at | 応答日時 |

---

## エラーハンドリング

### トークン無効（無効な文字列 or 存在しない）

```
このURLは無効です。最新の案件情報はLINEからご確認ください。
```

### トークン使用済み

```
このURLは無効または使用済みです。最新の案件情報はLINEからご確認ください。
```

### 応募期限切れ

```
この案件の応募受付は終了しました。また次の案件でお待ちしています！
```

### 既に担当者決定済み

```
残念ながら、この案件は他の方に決まりました。また次の案件でお待ちしています！
```

---

## 関連ドキュメント

- [LINE概要](line_overview.md)
- [LINE初回登録](line_registration.md)
- [清掃案件管理](../cleaning_job.md)
- [固定者・急募](../fixed_cleaner.md)

---

*Last Updated: 2026-01-29*
