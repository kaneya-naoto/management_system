# 深夜跨ぎ予約対応 & 空き状況ロジック簡素化

**日付**: 2026-02-12
**対象ファイル**:
- `public_html/api/booking/day-availability.php`
- `public_html/pages/booking/index.php`
- `public_html/assets/css/booking.css`

---

## 1. 深夜跨ぎ予約（overnight booking）対応

### 問題
- 24h営業店舗でスロットが00:00始まりだったため、23:00→02:00のような深夜跨ぎ予約ができなかった
- 翌日スロット（00:00以降）が「過去」扱いされていた

### 対応内容

#### API (`day-availability.php`)
- `generateBusinessSlots()` の24hモード: `opening_time` 始まりに変更（00:00の場合は06:00にフォールバック）
- `nextDayFlags` 算出: スロット配列を走査し、分数が減少した時点（midnight境界）以降を `next_day: true` にマーク
- past判定: `next_day=true` のスロットは翌日扱いなのでpast判定をスキップ
- DateTime構築: `nextDayFlags` ベースで `+1 day` を適用（24h・深夜営業の両方で動作）
- `date()` レースコンディション修正: `new DateTime()` でリクエスト開始時に時刻を固定

#### JS (`booking/index.php`)
- `renderSlotGrid()`: midnight境界に「翌日」セパレータを挿入
- `handleSlotClick()`: 開始スロットが `next_day` の場合、`reservation_date` を+1日に調整
- サマリー表示: 深夜跨ぎ時に「翌XX:XX」と表示

#### CSS (`booking.css`)
- `.slot-grid-separator` スタイル追加（翌日ラベル用）

---

## 2. 空き状況ロジック簡素化（鍵ベース判定の撤廃）

### 問題
- 各 sales_area に鍵（keys）が2〜3個あり、1予約では1鍵分しかブロックされないため、予約があっても○（空きあり）と表示されていた
- ユーザーの期待: 予約が入った時間帯は×（満室）にしたい

### 対応内容 (`day-availability.php`)
- 鍵テーブル(`keys`)へのクエリを削除（2クエリ→1クエリに最適化）
- `key_assignments` の LEFT JOIN を削除
- 判定ロジック変更: 予約が1件でもスロットに重なれば → `full` (×)
- レスポンスから `available_keys` / `total_keys` フィールドを削除
- `status` は `available` / `full` / `past` の3値（変更なし）

### 残した機能
- `key_assignments` テーブル・`assignKeyToReservation()` 等の鍵割当機能自体はそのまま残存
- 清掃ジョブ（`cleaning_jobs`）の `completed_at` による清掃完了判定はそのまま機能

---

## 変更しなかったファイル
以下は既にovernight対応済みで変更不要だった:
- `customer.php`: duration計算で `$endMinutes += 24*60` when end <= start
- `confirm.php`: 料金計算同上
- `complete.php`: ヘルパーに委譲
- `reservation_helpers.php`: `checkReservationConflict()` end < start で+1day、`assignKeyToReservation()` 3日間検索+overnight対応、`createCleaningJobForReservation()` end < start で+1day

---

## 検証結果
- `php -l` 構文チェック: OK
- 本番API確認:
  - area 2 (2/13): 予約 11:00-13:00 → 11:00〜13:30 が `full`、それ以外 `available`（清掃60min含む）
  - area 12 (2/13): 予約 07:00-15:00 → 07:00〜15:30 が `full`、それ以外 `available`（清掃60min含む）
  - area 8 24h (2/13): 予約 07:00-09:00 → 07:00〜09:30 が `full`
- コードレビュー実施（code-improvement-reviewer + security-auditor）
