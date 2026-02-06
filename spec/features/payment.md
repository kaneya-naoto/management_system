# 支払い管理

## 概要

清掃者への報酬支払いを案件単位で管理する機能

---

## 支払いデータ

### 管理項目

| 項目 | 説明 |
|------|------|
| 案件ID | 対象の清掃案件 |
| 店舗ID | 支払いが紐づく店舗（store_id） |
| 担当清掃者 | 支払い対象者 |
| 基本報酬 | 清掃の基本報酬 |
| 延長報酬 | 延長があった場合の追加報酬（常に0） |
| 総報酬 | 基本 + 延長（実質 base_amount のみ） |
| 支払いステータス | pending（未払い）/ paid（支払済） |
| 支払い日 | 実際に支払った日付 |
| 処理者 | 支払い処理を行ったユーザー（paid_by） |
| 備考 | 任意のメモ |

---

## 報酬計算

### 基本報酬

店舗設定（`stores.base_reward`）から取得。フォールバック: `DEFAULT_CLEANING_REWARD`

### 延長報酬

延長時の清掃報酬は **常に0**。延長しても報酬は変わらない。

### 総報酬

```
総報酬 = 基本報酬（延長報酬は常に0のため、実質 base_reward のみ）
```

### 金額確定保存

支払いレコード（`cleaner_payments`）は案件完了時に金額が確定され保存される。
作成後の支払いレコードの金額は変更しない（金額確定保存の方針）。

---

## 支払いフロー

```
[案件完了]
    ↓
[cleaner_payments生成] 支払いレコード作成（ステータス: pending）
    ↓
[支払い一覧に表示] ステータス: pending（未払い）
    ↓
[管理者確認] 内容確認・承認
    ↓
[支払い処理] 外部で振込等
    ↓
[ステータス更新] paid に変更（paid_at, paid_by を記録）
```

> ステータスENUM: `pending`（未払い）/ `paid`（支払済）の2値。
> `cancelled` は使用しない。

---

## CMS画面

### 支払い一覧

| カラム | 説明 |
|--------|------|
| 選択 | チェックボックス（未払いのみ、一括支払い用） |
| 案件日 | 清掃日・時刻 |
| 営業区分 | 店舗名 + 区分名 |
| 担当者 | 清掃者名・電話番号 |
| 報酬 | base_amount |
| 総報酬 | total_amount |
| ステータス | pending（未払い）/ paid（支払済 + 支払日） |
| 操作 | 詳細ボタン |

### サマリーカード

一覧上部に以下の集計サマリーを表示：

| カード | 内容 |
|--------|------|
| 総支払額 | フィルタ期間内の total_amount 合計・件数 |
| 未払い | pending の total_amount 合計・件数 |
| 支払済 | paid の total_amount 合計・件数 |
| 平均報酬 | 1件あたりの平均 total_amount |

### CSVエクスポート

一覧画面のヘッダーにCSVダウンロードボタンを配置。
現在のフィルタ条件（ステータス、清掃者、期間）を反映してエクスポートする。
エンドポイント: `/api/export/payments`

### フィルタ

| フィルタ | 選択肢 |
|----------|--------|
| ステータス | 全て / pending（未払い）/ paid（支払済） |
| 清掃者 | 全て / 清掃者名 |
| 期間（から） | 日付 |
| 期間（まで） | 日付（デフォルト: 当月末） |

---

## 店舗単位集計

### 集計項目

| 項目 | 説明 |
|------|------|
| 総支払額 | 期間内の支払い合計 |
| 未払い額 | 未払いの合計 |
| 支払済額 | 支払済みの合計 |
| 案件数 | 対象案件数 |

### 集計画面

```
店舗: 池袋店
期間: 2026年1月

総支払額:   150,000円
├ 未払い:    30,000円 (5件)
└ 支払済:   120,000円 (20件)
```

---

## 支払い処理

### 一括支払い

1. 未払い案件を複数選択
2. 「一括支払い処理」ボタン
3. 確認モーダル（対象者、金額、件数）
4. 確定 → 全てのステータスを `paid` に

### 個別支払い

1. 支払い詳細画面を開く
2. 「支払い完了」ボタン
3. 支払い日を入力
4. 確定 → ステータスを `paid` に

---

## cleaner_payments テーブル

```sql
CREATE TABLE cleaner_payments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  job_id BIGINT NOT NULL,
  cleaner_id BIGINT NOT NULL,
  store_id BIGINT NOT NULL,
  base_amount INT NOT NULL DEFAULT 0,
  extension_amount INT NOT NULL DEFAULT 0,
  total_amount INT NOT NULL DEFAULT 0,
  status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
  paid_at DATE NULL,
  paid_by BIGINT NULL,               -- 支払い処理を行ったユーザーID
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id),
  KEY idx_payments_status (status),
  KEY idx_payments_cleaner (cleaner_id),
  KEY idx_payments_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> ステータスは `pending` / `paid` の2値。`unpaid` や `cancelled` は使用しない。

---

## 重複防止

### ユニーク制約

```sql
UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id)
```

1案件につき1清掃者の支払いレコードのみ

---

## 関連ドキュメント

- [清掃案件管理](cleaning_job.md)
- [延長対応](extension.md)
- [売上・KPI](kpi_dashboard.md)

---

*Last Updated: 2026-01-29*
