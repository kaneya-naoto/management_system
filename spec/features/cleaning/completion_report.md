# 清掃完了報告

## 概要

清掃者が清掃完了を報告する機能。LINE経由とCMS経由の2つの報告方法を提供。
完了報告により予約ブロックが動的に解除される。

---

## 報告方法

### 方法1: LINE経由

清掃者がLINEで完了報告を送信。

```
[清掃者] LINEで「完了」メッセージ送信
    ↓
[Webhook] /api/line/webhook.php で受信
    ↓
[案件特定] 担当案件を特定（当日の assigned 案件）
    ↓
[ステータス更新] status = 'completed', completed_at = NOW()
    ↓
[応答] 「完了を受け付けました」メッセージ返信
```

### 方法2: CMS経由

管理者がCMS画面から完了マーク。

```
[管理者] /jobs/{id} 詳細画面を開く
    ↓
[操作] 「完了」ボタン押下
    ↓
[ステータス更新] status = 'completed', completed_at = NOW()
    ↓
[画面更新] ステータス表示を更新
```

---

## LINE完了報告

### トリガーワード

| キーワード | 処理 |
|-----------|------|
| 完了 | 完了報告 |
| 終了 | 完了報告 |
| 終わりました | 完了報告 |
| done | 完了報告 |

### Webhook処理フロー

```php
// /api/line/webhook.php

// メッセージ判定
if (isCompletionKeyword($message)) {
    // 清掃者を特定
    $cleaner = getCleanerByLineUserId($lineUserId);

    // 当日の担当案件を取得
    $job = getCurrentAssignedJob($cleaner['id']);

    if ($job) {
        // 完了処理
        completeCleaningJob($job['id']);

        // 返信
        replyMessage($replyToken, '清掃完了を受け付けました！お疲れ様でした。');
    } else {
        replyMessage($replyToken, '現在担当中の案件がありません。');
    }
}
```

### 案件特定ロジック

```sql
-- 当日の担当案件（status = 'assigned'）を取得
SELECT * FROM cleaning_jobs
WHERE assigned_cleaner_id = :cleaner_id
  AND status = 'assigned'
  AND DATE(scheduled_at) = CURDATE()
ORDER BY scheduled_at ASC
LIMIT 1
```

### 複数案件がある場合

- 最も早い時間の案件から順に完了処理
- 「次の案件: {時間} {場所}」を返信に含める

---

## CMS完了マーク

### 画面: /jobs/{id}（案件詳細）

| 要素 | 説明 |
|------|------|
| 完了ボタン | status = 'assigned' の時のみ表示 |
| 確認ダイアログ | 「この案件を完了にしますか？」 |
| 完了時刻 | 自動で現在時刻を記録 |

### 操作権限

| ロール | 操作可否 |
|--------|---------|
| admin | ○ |
| manager | ○ |
| staff | ○ |
| cleaner | × （LINE経由のみ） |

---

## DB更新

### cleaning_jobs テーブル

```sql
UPDATE cleaning_jobs
SET status = 'completed',
    completed_at = NOW(),
    updated_at = NOW()
WHERE id = :job_id
```

### 更新されるカラム

| カラム | 値 | 説明 |
|--------|-----|------|
| status | 'completed' | 完了ステータス |
| completed_at | NOW() | 完了時刻（新規カラム） |
| updated_at | NOW() | 更新時刻 |

---

## 連動処理

### 予約ブロック解除

完了報告により、即座に次の予約が可能になる。

```
[完了報告] completed_at = 13:30
    ↓
[ブロック解除] 13:30 以降は予約可能
    ↓
[空き反映] check-availability.php で反映
```

詳細は [予約ブロック](../reservation/reservation_block.md) を参照。

### 通知（オプション）

| 通知先 | 内容 |
|--------|------|
| 清掃者 | 「完了を受け付けました。お疲れ様でした！」 |
| 管理者 | （設定により）完了通知 |

---

## 完了報告のタイミング

### 想定フロー

```
[予約終了] 13:00（客退室）
    ↓
[清掃開始] 13:00〜
    ↓
[清掃終了] 13:30〜14:00（想定）
    ↓
[完了報告] LINE or CMS
    ↓
[ブロック解除] 報告時刻で即座に解除
```

### 遅延報告のケース

| ケース | 対応 |
|--------|------|
| 報告忘れ | 翌日のバッチで警告、管理者が手動完了 |
| 清掃が長引いた | 報告時刻でブロック解除 |

---

## エラーハンドリング

### LINE報告時

| エラー | 返信メッセージ |
|--------|---------------|
| 案件なし | 「現在担当中の案件がありません」 |
| 既に完了 | 「この案件は既に完了しています」 |
| システムエラー | 「エラーが発生しました。管理者にお問い合わせください」 |

### CMS操作時

| エラー | 画面表示 |
|--------|---------|
| 権限なし | 403エラー |
| 案件なし | 404エラー |
| ステータス不正 | 「この案件は完了にできません」 |

---

## 実装ファイル

| ファイル | 役割 |
|---------|------|
| `/public_html/api/line/webhook.php` | LINE Webhook（完了報告受付） |
| `/public_html/pages/jobs/detail.php` | CMS案件詳細（完了ボタン） |
| `/public_html/api/jobs/complete.php` | 完了処理API |
| `/public_html/includes/functions.php` | `completeCleaningJob()` 関数 |

---

## 監査ログ

完了報告は監査ログに記録：

| 項目 | 値 |
|------|-----|
| action | job_completed |
| entity_type | cleaning_job |
| entity_id | 案件ID |
| user_id | 操作者（清掃者 or 管理者） |
| details | 報告方法（line/cms）、完了時刻 |

---

## 関連ドキュメント

- [予約ブロック](../reservation/reservation_block.md)
- [清掃案件管理](../cleaning_job.md)
- [LINE概要](../line/line_overview.md)

---

*Created: 2026-01-28*
