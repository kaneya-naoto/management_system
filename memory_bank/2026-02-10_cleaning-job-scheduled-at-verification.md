# 清掃案件 scheduled_at 正確性検証レポート

**日付**: 2026-02-10
**結論**: コードに問題なし。テストデータのSQL手動投入ミスのみ（修正済み）。

---

## 検証対象

清掃案件（cleaning_jobs）の `scheduled_at` が、予約の `end_time`（予約終了時刻）を正しく使用しているかの確認。

## 検証結果

### 1. `createCleaningJobForReservation()` — `job_helpers.php:62-99`

```php
// line 72-76: 深夜跨ぎ対応付きで $endTime を scheduled_at に使用
$cleaningDate = $date;
if ($endTime < $startTime) {
    $cleaningDate = date('Y-m-d', strtotime($date . ' +1 day'));
}
$scheduledAt = $cleaningDate . ' ' . $endTime;
```

- 第5引数: `$startTime`（深夜跨ぎ判定にのみ使用）
- 第6引数: `$endTime`（`scheduled_at` に使用） → **正しい**

### 2. 呼び出し元3箇所

| 場所 | 第5引数 (startTime) | 第6引数 (endTime) | 判定 |
|------|---------------------|-------------------|------|
| `pages/reservations/create.php:172` | `$startTime` | `$endTime` | OK |
| `pages/reservations/detail.php:135-142` | `$resData['start_time']` | `$resData['end_time']` | OK |
| `pages/booking/complete.php:98-105` | `$booking['start_time']` | `$booking['end_time']` | OK |

3箇所とも予約の `start_time` と `end_time` を正しく渡しており、関数側も `$endTime` を `scheduled_at` に使用。

## テストデータ修正（実施済み）

SQLで27件の `scheduled_at` を予約の `end_time` に合わせて修正完了。
原因: テストデータ投入時のSQL手動作成ミス。

## 結論

- 自動生成ロジック: **問題なし**
- 深夜跨ぎ対応: **問題なし**（end_time < start_time 時に翌日日付を適用）
- 呼び出し元の引数: **全3箇所正しい**
- 今後の予約作成時: 自動生成される `scheduled_at` は正しい値になる
