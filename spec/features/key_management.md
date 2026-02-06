# 鍵番号管理

## 概要

営業区分ごとに複数の鍵番号を管理し、予約確定時にID昇順で空き鍵を割り当てる機能

---

## 基本仕様

### 鍵番号の登録

- 実店舗 × 内部営業区分ごとに複数の鍵番号を登録
- 例：池袋東口に10個の鍵番号（001〜010）

### 登録項目

| 項目 | 必須 | 説明 |
|------|------|------|
| 鍵番号 | ○ | 表示用の番号（例：001, A-01） |
| 営業区分 | ○ | 所属する営業区分 |
| 有効フラグ | ○ | 使用可否 |
| 備考 | - | メモ |

---

## 割当ロジック

### フロー

```
[予約確定]
    ↓
[空き鍵検索] 同一時間帯で使用されていない鍵を抽出（FOR UPDATE）
    ↓
[ID順選択] 候補からID昇順で最初の1つを選択
    ↓
[割当記録] key_assignmentsに登録
    ↓
[予約メール] 鍵番号を含めて送信
```

### 空き鍵の判定

ID昇順で最初の空き鍵を1つ取得する（ランダムではなく決定的な選択）。

```sql
SELECT k.* FROM `keys` k
WHERE k.sales_area_id = ?
  AND k.is_active = 1
  AND k.deleted_at IS NULL
  AND k.id NOT IN (
    SELECT ka.key_id FROM key_assignments ka
    INNER JOIN reservations r ON ka.reservation_id = r.id
    WHERE r.sales_area_id = ?
      AND r.reservation_date = ?
      AND r.status NOT IN ('cancelled')
      AND r.id != ?  -- 自分自身を除外
      AND NOT (r.end_time <= ? OR r.start_time >= ?)  -- 時間帯重複チェック
  )
ORDER BY k.id
LIMIT 1
FOR UPDATE;  -- 悲観的ロック
```

**選択方式**: ランダムではなくID昇順。これにより同一条件で常に同じ鍵が選ばれ、動作が予測可能になる。

---

## 競合防止

### 悲観的ロック

同一時間帯に同一鍵番号が複数予約へ割り当てられることを防止：

```php
// assignKeyToReservation() - reservation_helpers.php
// トランザクション内で呼び出すこと

// 空き鍵を1つ取得（FOR UPDATE でロック、ID昇順）
$availableKey = dbSelectOne(
    "SELECT k.* FROM `keys` k
     WHERE k.sales_area_id = ?
       AND k.is_active = 1
       AND k.deleted_at IS NULL
       AND k.id NOT IN (
           SELECT ka.key_id FROM key_assignments ka
           INNER JOIN reservations r ON ka.reservation_id = r.id
           WHERE r.sales_area_id = ?
             AND r.reservation_date = ?
             AND r.status NOT IN ('cancelled')
             AND r.id != ?
             AND NOT (r.end_time <= ? OR r.start_time >= ?)
       )
     ORDER BY k.id
     LIMIT 1
     FOR UPDATE",
    [$salesAreaId, $salesAreaId, $date, $reservationId, $startTime, $endTime]
);

if (!$availableKey) {
    throw new RuntimeException('空き鍵がありません');
}

// 割当記録
dbInsert('key_assignments', [
    'reservation_id' => $reservationId,
    'key_id' => $availableKey['id'],
    'assigned_at' => date('Y-m-d H:i:s'),
]);
```

---

## 履歴管理

### key_assignments テーブルの記録項目

| 項目 | カラム | 説明 |
|------|--------|------|
| 割当日時 | assigned_at | 鍵を割り当てた日時 |
| 予約ID | reservation_id | 割り当て先の予約 |
| 鍵ID | key_id | 割り当てた鍵 |
| 返却日時 | returned_at | 鍵返却日時（NULL許可） |

**注意**: 「操作者」カラムは実装していない。割当は全て予約確定時の自動処理で行われるため、操作者の記録は不要。

---

## CMS画面

### 鍵一覧

| カラム | 説明 |
|--------|------|
| 鍵番号 | 表示用番号 |
| 営業区分 | 所属区分 |
| 有効 | 有効/無効 |
| 使用中 | 現在割当中の予約件数 |
| 操作 | 編集/無効化 |

### フィルタ

- 営業区分
- 有効/無効

### 割当状況確認

日付・時間帯別の鍵使用状況をガント表示：

```
      | 10:00 | 11:00 | 12:00 | 13:00 | ...
------+-------+-------+-------+-------+----
001   | ■■■■■ |       | ■■■■■■■■■■ |
002   |       | ■■■■■■■■■■■■■ |       |
003   |       |       |       |       |
...
```

---

## エラーハンドリング

### 鍵不足時

```
空き鍵がありません。
- 鍵を追加登録する
- 予約時間を変更する
- 他の営業区分を案内する
```

### 競合発生時

リトライ（最大3回）後もロック取得できない場合：

```
予約処理が混雑しています。しばらく待ってから再度お試しください。
```

---

## 関連ドキュメント

- [予約フロー](reservation/reservation_flow.md)
- [DBスキーマ](../db/schema.md)

---

*Last Updated: 2026-01-29*
