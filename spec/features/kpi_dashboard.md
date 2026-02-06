# 売上・KPIダッシュボード

## 概要

店舗/営業区分別の売上、稼働率、清掃コスト、粗利を可視化するダッシュボード

---

## 集計項目

### 売上

| 項目 | 計算方法 |
|------|----------|
| 総売上 | 期間内の予約金額合計 |
| 日別売上 | 日ごとの予約金額 |
| 区分別売上 | 営業区分ごとの予約金額 |
| 経路別売上 | 予約経路（Web/LINE/電話）ごとの金額 |

### 稼働率

| 項目 | 計算方法 |
|------|----------|
| 稼働率 | 予約時間 ÷ 営業時間 × 100% |
| 区分別稼働率 | 区分ごとの稼働率 |
| 時間帯別稼働率 | 時間帯ごとの稼働状況 |

### 清掃コスト

| 項目 | 計算方法 |
|------|----------|
| 総清掃コスト | 期間内の支払い報酬合計 |
| 基本報酬 | 基本報酬の合計 |
| 延長報酬 | 延長報酬の合計 |
| 案件単価 | 総コスト ÷ 案件数 |

### 粗利

| 項目 | 計算方法 |
|------|----------|
| 粗利 | 売上 − 清掃コスト |
| 粗利率 | 粗利 ÷ 売上 × 100% |

---

## ダッシュボード画面

### サマリーカード

```
┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ 売上         │ │ 稼働率       │ │ 清掃コスト   │ │ 粗利         │
│ ¥1,250,000  │ │ 68.5%       │ │ ¥375,000    │ │ ¥875,000    │
│ +12% vs先月 │ │ +5.2% vs先月│ │ +8% vs先月  │ │ +15% vs先月 │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘
```

### グラフ

| グラフ | 内容 |
|--------|------|
| 売上推移 | 日別/週別/月別の折れ線グラフ |
| 区分別売上 | 営業区分ごとの棒グラフ |
| 経路別構成 | Web/LINE/電話の円グラフ |
| 稼働率ヒートマップ | 時間帯×曜日の稼働状況 |

---

## フィルタ

| フィルタ | 選択肢 |
|----------|--------|
| 期間 | 今日/今週/今月/カスタム |
| 店舗 | 全店舗 / 個別店舗 |
| 営業区分 | 全て / 各区分 |

---

## 詳細テーブル

### 区分別集計

| 区分 | 売上 | 稼働率 | コスト | 粗利 | 粗利率 |
|------|------|--------|--------|------|--------|
| 池袋東口 | ¥500,000 | 72% | ¥150,000 | ¥350,000 | 70% |
| 池袋プレミアム | ¥300,000 | 65% | ¥100,000 | ¥200,000 | 67% |
| 合計 | ¥800,000 | 68% | ¥250,000 | ¥550,000 | 69% |

### 予約経路別集計

| 経路 | 件数 | 売上 | 構成比 |
|------|------|------|--------|
| Web | 45 | ¥500,000 | 63% |
| LINE | 20 | ¥200,000 | 25% |
| 電話 | 10 | ¥100,000 | 12% |

---

## 計算SQL例

### 売上集計

```sql
SELECT
  sa.id AS sales_area_id,
  sa.name AS sales_area_name,
  COUNT(r.id) AS reservation_count,
  SUM(r.payment_amount) AS total_sales
FROM reservations r
INNER JOIN sales_areas sa ON r.sales_area_id = sa.id
WHERE r.store_id = ?
  AND r.status = 'completed'
  AND r.reservation_date BETWEEN ? AND ?
  AND r.deleted_at IS NULL
GROUP BY sa.id, sa.name;
```

### 稼働率計算

```sql
-- 予約時間の合計（分）
SELECT
  SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)) AS booked_minutes
FROM reservations
WHERE store_id = ?
  AND status IN ('confirmed', 'completed')
  AND reservation_date = ?;

-- 営業時間（分）は店舗設定から取得
-- 稼働率 = booked_minutes / operating_minutes * 100
```

### 清掃コスト集計

```sql
SELECT
  SUM(cp.base_amount) AS total_base,
  SUM(cp.extension_amount) AS total_extension,
  SUM(cp.total_amount) AS total_cost
FROM cleaner_payments cp
INNER JOIN cleaning_jobs cj ON cp.job_id = cj.id
WHERE cj.store_id = ?
  AND cj.scheduled_at BETWEEN ? AND ?;
```

---

## 権限による表示

| 権限 | 表示範囲 |
|------|----------|
| HQ | 全店舗のKPI |
| OWNER | 配下店舗のKPI |
| STORE | 自店舗のKPIのみ |

---

## 関連ドキュメント

- [支払い管理](payment.md)
- [予約フロー](reservation/reservation_flow.md)

---

*Last Updated: 2026-01-26*
