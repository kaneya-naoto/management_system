# 自動通知システム

## 概要

清掃案件の自動通知システム。当日予約の即時通知と、定点通知（日次バッチ）の2種類。

---

## 当日予約の即時通知

### トリガー

予約確定時（`status='confirmed'`）かつ予約日 = 当日

### 処理フロー

```
[予約確定] /pages/booking/complete.php
    ↓
[日付チェック] 予約日 == 今日か？
    ↓
┌─ YES ──→ [即時通知] sendJobNotifications()
│                ↓
│          [LINE通知] 対象清掃者全員に送信
│                ↓
│          [ステータス更新] cleaning_jobs.status = 'recruiting'
│
└─ NO ───→ [定点通知待ち] status = 'unassigned' のまま
```

### 対象清掃者

| 条件 | 説明 |
|------|------|
| 店舗紐づけ | `cleaner_stores` で該当店舗に紐づいている |
| アクティブ | `cleaners.is_active = 1` |
| LINE連携済 | `cleaners.line_user_id IS NOT NULL` |

### 通知内容

```
【本日の案件】清掃スタッフ募集！

📍 場所: {sales_area_name}
📅 日時: 本日 {time}
💰 報酬: {reward}円

⚠️ 本日の案件です。お早めにご応募ください！

▼ 詳細・応募はこちら
{application_url}
```

---

## 定点通知（日次バッチ）

### 実行タイミング

- 1日1回（具体的な時刻は運用で調整）
- cron: `cron/send_daily_notifications.php`

### 対象案件の条件

| 条件 | SQL |
|------|-----|
| 未割当 or 募集中 | `status IN ('unassigned', 'recruiting')` |
| 担当者未決定 | `assigned_cleaner_id IS NULL` |
| 未来の案件 | `scheduled_at > NOW()` |

### 対象清掃者の条件

| 条件 | 説明 |
|------|------|
| 店舗紐づけ | `cleaner_stores` で対象案件の店舗に紐づいている |
| アクティブ | `cleaners.is_active = 1` |
| LINE連携済 | `cleaners.line_user_id IS NOT NULL` |

### 通知パターン

#### パターン A: 案件数 ≤ 5件

各案件を個別にリスト表示：

```
【空き案件のお知らせ】

現在、以下の案件が募集中です：

1. {sales_area_name} - {date} {time}（{reward}円）
2. {sales_area_name} - {date} {time}（{reward}円）
...

▼ 詳細・応募はこちら
{application_url}
```

#### パターン B: 案件数 > 5件

サマリー + 一覧ページへ誘導：

```
【空き案件のお知らせ】

現在 {count} 件の案件が募集中です！

▼ 案件一覧はこちら
{job_list_url}
```

### 重複通知防止

#### 通知ログテーブル

`daily_notification_logs` で送信済みをチェック：

| カラム | 説明 |
|--------|------|
| job_id | 案件ID |
| cleaner_id | 清掃者ID |
| notification_date | 通知日（DATE型） |
| notified_at | 送信日時 |

#### チェックロジック

```sql
-- 同一案件・同一清掃者への当日通知済みチェック
SELECT 1 FROM daily_notification_logs
WHERE job_id = :job_id
  AND cleaner_id = :cleaner_id
  AND notification_date = CURDATE()
```

- 同じ日に同じ案件を同じ清掃者に通知しない
- 翌日になれば再通知可能（案件が埋まるまで継続）

---

## ステータス遷移と通知の関係

```
unassigned ──→ recruiting ──→ assigned
     │              │
     │         [定点通知]
     │              │
     └─[当日即時通知]─┘
```

| トリガー | 遷移 | 通知 |
|----------|------|------|
| 当日予約確定 | unassigned → recruiting | 即時通知 |
| 定点バッチ | unassigned → recruiting | 日次通知 |
| 応募確定 | recruiting → assigned | 確定通知 |

---

## 実装ファイル

| ファイル | 役割 |
|---------|------|
| `public_html/pages/booking/complete.php` | 当日判定 + 即時通知呼び出し |
| `cron/send_daily_notifications.php` | 定点通知バッチ |
| `public_html/includes/functions.php` | `sendJobNotifications()` 関数 |
| `public_html/includes/line_helper.php` | LINE送信処理 |

---

## 設定値

| 項目 | デフォルト | 備考 |
|------|-----------|------|
| 定点通知時刻 | 運用で調整 | crontab で設定 |
| 個別表示上限 | 5件 | 超過時はサマリー |
| 再通知間隔 | 1日 | 同一案件・清掃者 |

---

## 関連ドキュメント

- [LINE 応募・通知フロー](../line/line_application.md)
- [清掃案件管理](../cleaning_job.md)
- [予約フロー](../reservation/reservation_flow.md)

---

*Created: 2026-01-28*
