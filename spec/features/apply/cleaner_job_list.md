# 清掃者向け案件一覧

## 概要

清掃者が自分の担当店舗の空き案件を確認し、応募できる専用ページ。
LINE経由でアクセスし、トークンまたはセッションで認証。

---

## ページ情報

| 項目 | 値 |
|------|-----|
| URL | `/apply/list` |
| アクセス元 | LINE通知内のリンク |
| 認証方式 | LINEトークン or セッション |
| 対象ユーザー | 清掃者（cleaners） |

---

## アクセスフロー

```
[LINE通知] 「空き案件一覧はこちら」
    ↓
[URL] /apply/list?token={list_token}
    ↓
[認証] トークンから清掃者を特定
    ↓
[表示] 担当店舗の空き案件一覧
```

---

## 認証方式

### トークン認証（LINE経由）

```
/apply/list?token={list_token}
```

| トークン情報 | 説明 |
|-------------|------|
| 生成タイミング | LINE友達追加時 or 案件通知時 |
| 有効期限 | 設定可能（デフォルト: 無期限） |
| 紐づけ | `cleaners.list_token` |

### セッション認証（再訪問時）

- 初回アクセス時にセッションを生成
- 以降はセッションで清掃者を特定
- LINE User ID での照合も可

---

## 表示項目

### 案件カード

| 項目 | 表示例 | 説明 |
|------|--------|------|
| 清掃日時 | 1/30（木）13:00〜 | scheduled_at |
| 店舗名 | 渋谷店 | stores.name |
| 営業区分 | Aルーム | sales_areas.name |
| 報酬 | ¥3,000 | base_reward |
| 急募フラグ | 【急募】 | is_urgent |
| 応募ボタン | [応募する] | 応募画面へ遷移 |

### 一覧表示

```
┌─────────────────────────────────┐
│ 【急募】1/28（火）14:00〜        │
│ 渋谷店 - Aルーム                │
│ 報酬: ¥3,000                    │
│ [応募する]                      │
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ 1/30（木）13:00〜               │
│ 新宿店 - Bルーム                │
│ 報酬: ¥3,500                    │
│ [応募する]                      │
└─────────────────────────────────┘
```

---

## フィルタ機能

### 日付フィルタ

| 選択肢 | 説明 |
|--------|------|
| 今日 | 本日の案件のみ |
| 明日 | 翌日の案件のみ |
| 今週 | 今週中の案件 |
| 全て | 全ての未来案件 |

### 店舗フィルタ

| 選択肢 | 説明 |
|--------|------|
| 全店舗 | 担当全店舗の案件 |
| 店舗名 | 特定店舗の案件のみ |

---

## 対象案件の条件

### SQL条件

```sql
SELECT cj.*, s.name AS store_name, sa.name AS area_name
FROM cleaning_jobs cj
JOIN reservations r ON cj.reservation_id = r.id
JOIN sales_areas sa ON cj.sales_area_id = sa.id
JOIN stores s ON cj.store_id = s.id
JOIN cleaner_stores cs ON s.id = cs.store_id
WHERE cs.cleaner_id = :cleaner_id
  AND cj.status IN ('unassigned', 'recruiting')
  AND cj.assigned_cleaner_id IS NULL
  AND cj.scheduled_at > NOW()
ORDER BY cj.scheduled_at ASC
```

### 条件詳細

| 条件 | 説明 |
|------|------|
| `cleaner_stores` 紐づけ | 清掃者の担当店舗のみ |
| `status` | unassigned または recruiting |
| `assigned_cleaner_id IS NULL` | 担当者未決定 |
| `scheduled_at > NOW()` | 未来の案件のみ |

---

## 応募処理

### 応募ボタン押下時

```
[応募する] ボタン押下
    ↓
[確認画面] /apply/confirm?job_id={id}&token={token}
    ↓
[応募確定] /apply/submit
    ↓
[結果] 応募完了 or 既に埋まった
```

### 競合チェック

- 応募ボタン押下時に再度空きチェック
- 同時応募の場合は先着順で決定
- 落選者には「既に決まりました」を表示

---

## 画面遷移

```
[LINE通知]
    ↓
[案件一覧] /apply/list
    ↓
[案件詳細・確認] /apply/confirm?job_id={id}
    ↓
[応募完了] /apply/thanks または /apply/sorry
```

---

## 表示状態

### 案件なしの場合

```
現在、募集中の案件はありません。

新しい案件が入り次第、LINEでお知らせします！
```

### エラー時

```
アクセスエラー

このURLは無効か期限切れです。
LINEから最新のリンクをご確認ください。
```

---

## 実装ファイル

| ファイル | 役割 |
|---------|------|
| `/public_html/pages/apply/list.php` | 案件一覧ページ |
| `/public_html/pages/apply/confirm.php` | 応募確認ページ |
| `/public_html/pages/apply/submit.php` | 応募処理API |
| `/public_html/pages/apply/thanks.php` | 応募完了ページ |

---

## セキュリティ考慮

| 項目 | 対策 |
|------|------|
| トークン漏洩 | 推測困難なランダム文字列 |
| CSRF | フォームにCSRFトークン |
| 権限チェック | 担当店舗以外の案件は非表示 |
| レート制限 | 連続応募の制限 |

---

## 関連ドキュメント

- [LINE 応募・通知フロー](../line/line_application.md)
- [自動通知システム](../notification/auto_notification.md)
- [清掃案件管理](../cleaning_job.md)

---

*Created: 2026-01-28*
