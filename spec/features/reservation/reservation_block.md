# 予約ブロック（清掃時間考慮）

## 概要

予約の空き判定において、客利用時間だけでなく清掃時間も考慮したブロック制御を行う。

---

## 基本ブロックルール

### 従来の判定（清掃時間未考慮）

```
予約不可時間 = 既存予約の start_time 〜 end_time
```

### 新しい判定（清掃時間考慮）

```
予約不可時間 = 既存予約の start_time 〜 (end_time + 清掃時間)
```

### 清掃時間

- 営業区分ごとに設定可能: `sales_areas.cleaning_duration_minutes`
- 未設定時のフォールバック: `CLEANING_TIME_MINUTES` 定数（デフォルト60分）
- 取得関数: `getCleaningDuration($salesAreaId)` で動的取得（結果はキャッシュ）

### 具体例

```
既存予約: 10:00 - 13:00

【従来】
10:00 - 13:00 が予約不可
→ 13:00 からの予約は可能

【新ルール】
10:00 - 14:00 が予約不可（13:00 + 1時間）
→ 14:00 からの予約が可能
```

---

## 動的ブロック解除

### 清掃完了報告による解除

清掃完了報告が入った時点で、即座にブロックを解除する。

```
[清掃完了報告] cleaning_jobs.status = 'completed'
    ↓
[完了時刻記録] cleaning_jobs.completed_at = NOW()
    ↓
[ブロック解除] 完了時刻以降は次の予約が可能
```

### 具体例

```
既存予約: 10:00 - 13:00
清掃予定: 13:00 - 14:00（1時間）

【完了報告前】
10:00 - 14:00 が予約不可

【13:30に完了報告】
13:30 で清掃完了
→ 13:30 以降から次の予約可能
→ 30分早く開放
```

---

## 空き判定ロジック

### check-availability.php での判定

```php
// 清掃時間を営業区分から動的取得
$cleaning_duration = getCleaningDuration($salesAreaId); // 分

// 既存予約の終了時刻 + 清掃時間
$block_end = $reservation['end_time'] + $cleaning_duration;

// ただし清掃完了済みの場合は完了時刻を使用
if ($cleaning_job && $cleaning_job['status'] === 'completed') {
    $block_end = $cleaning_job['completed_at'];
}
```

> **実装詳細**: 空き判定は `getAvailableKeyCount()` 共通関数を使用。
> 内部で `getCleaningDuration()` → `checkReservationConflict()` を呼び出し、
> 清掃時間・深夜跨ぎ・清掃完了報告を考慮した統一的な空き判定を実施。

### SQL例

```sql
-- 重複チェック（清掃時間動的取得版）
-- 清掃時間は getCleaningDuration() でPHP側から取得し、パラメータとして渡す
SELECT r.*, cj.status AS job_status, cj.completed_at
FROM reservations r
LEFT JOIN cleaning_jobs cj ON r.id = cj.reservation_id
WHERE r.sales_area_id = :sales_area_id
  AND r.status NOT IN ('cancelled')
  AND r.reservation_date = :date
  AND (
    -- 通常のブロック（清掃未完了）
    (cj.status IS NULL OR cj.status != 'completed')
    AND :start_time < DATE_ADD(r.end_time, INTERVAL :cleaning_duration MINUTE)
    AND :end_time > r.start_time
  ) OR (
    -- 清掃完了済みの場合は完了時刻でブロック
    cj.status = 'completed'
    AND :start_time < cj.completed_at
    AND :end_time > r.start_time
  )
```

---

## 影響する画面・API

| 画面/API | 変更内容 |
|---------|---------|
| `/api/booking/check-availability.php` | 清掃時間を考慮した空き判定 |
| `/pages/booking/index.php` | 空きカレンダー表示の調整 |
| `/pages/reservations/calendar.php` | CMS予約カレンダー表示 |
| `/pages/reservations/gantt.php` | ガントチャートでの可視化 |

---

## DB変更

### cleaning_jobs テーブル

```sql
-- 完了時刻カラム追加
ALTER TABLE cleaning_jobs
ADD COLUMN completed_at DATETIME NULL AFTER status;
```

### sales_areas テーブル（実装済み）

```sql
-- 営業区分ごとの清掃時間設定（013_reservation_flow_improvement.sql で追加済み）
ALTER TABLE sales_areas
ADD COLUMN cleaning_duration_minutes INT NOT NULL DEFAULT 60;
```

---

## タイムライン図

```
時間軸 →

【予約】
10:00 ========== 13:00
      客利用時間

【ブロック範囲（清掃未完了時）】
10:00 ==================== 14:00
      予約不可範囲（+1時間）

【ブロック範囲（13:30に清掃完了時）】
10:00 ================ 13:30
      予約不可範囲（完了時刻まで）

【次の予約可能時刻】
                    13:30 ～
                    または 14:00 ～
```

---

## エッジケース

### ケース1: 清掃が長引いた場合

```
予定: 13:00 - 14:00（1時間）
実際: 13:00 - 14:30（完了報告が14:30）

→ 14:30 まで予約ブロック継続
→ 次の予約は 14:30 以降から可能
```

### ケース2: 清掃が早く終わった場合

```
予定: 13:00 - 14:00（1時間）
実際: 13:00 - 13:30（完了報告が13:30）

→ 13:30 でブロック解除
→ 次の予約は 13:30 以降から可能
```

### ケース3: 清掃未着手の場合

```
予約終了: 13:00
清掃案件: status = 'unassigned'（担当者未定）

→ 14:00 まで予約ブロック（デフォルト1時間）
→ 清掃完了報告があれば動的に解除
```

---

## 関連ドキュメント

- [予約フロー](reservation_flow.md)
- [清掃案件管理](../cleaning_job.md)
- [清掃完了報告](../cleaning/completion_report.md)

---

*Created: 2026-01-28*
*Last Updated: 2026-01-29*
