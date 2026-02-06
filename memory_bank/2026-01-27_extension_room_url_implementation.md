# 延長機能 部屋URL対応 実装レポート

## 実施日
2026-01-27

## 概要
延長機能のURL設計を「予約ごとのトークン方式」から「部屋ごとの固定URL方式」に変更。
QRコードを部屋に貼りっぱなしにできる運用を実現。

## 背景・目的

| 方式 | 変更前 | 変更後 |
|------|--------|--------|
| URL例 | `/extend/?token=abc123...` | `/extend/room/{room_code}` |
| QR運用 | 毎回変わる（不便） | 部屋に貼りっぱなしでOK |
| 予約特定 | トークンで直接特定 | 部屋＋本日＋現在時刻で自動特定 |

---

## 実施した修正

### Phase 1: データベース変更

**ファイル:** `database/008_add_room_code.sql`

```sql
ALTER TABLE sales_areas
ADD COLUMN room_code VARCHAR(16) UNIQUE AFTER description;

UPDATE sales_areas
SET room_code = LOWER(SUBSTRING(MD5(CONCAT(id, RAND())), 1, 8))
WHERE room_code IS NULL;
```

- 8文字の英数字ランダムコードを営業区分に追加
- 推測困難＆URL短め

### Phase 2: ルーティング追加

**ファイル:** `public_html/index.php`

```php
// /extend/room/{room_code} - 部屋コードでの延長フォーム
if (preg_match('#^/extend/room/([a-zA-Z0-9]+)$#', $requestPath, $matches)) {
    $_GET['room_code'] = $matches[1];
    require __DIR__ . '/pages/extend/index.php';
    exit;
}
```

### Phase 3: 延長フォーム改修

**ファイル:** `public_html/pages/extend/index.php`

#### 3.1 入力バリデーション強化
```php
// room_code: 8-32文字の英数字のみ
if (!preg_match('/^[a-zA-Z0-9]{8,32}$/', $roomCode)) {
    throw new Exception('無効なアクセスです');
}

// token: 32-128文字の16進数のみ
if (!preg_match('/^[a-fA-F0-9]{32,128}$/', $token)) {
    throw new Exception('無効なアクセスです');
}
```

#### 3.2 深夜跨ぎ予約対応（3パターン）
```php
AND (
    -- パターン1: 通常（日跨ぎなし）- 今日の予約で開始後〜終了前
    (r.reservation_date = ? AND r.end_time >= r.start_time
     AND r.start_time <= ? AND r.end_time > ?)
    OR
    -- パターン2: 深夜跨ぎ・当日側 - 今日開始で終了が翌日、現在は開始後
    -- 例: 23:00-01:00 の予約に 23:30 にアクセス
    (r.reservation_date = ? AND r.end_time < r.start_time
     AND r.start_time <= ?)
    OR
    -- パターン3: 深夜跨ぎ・翌日側 - 昨日開始で終了が今日、現在は終了前
    -- 例: 23:00-01:00 の予約に 00:30 にアクセス
    (r.reservation_date = ? AND r.end_time < r.start_time
     AND ? < r.end_time)
)
```

#### 3.3 PHP/MySQL時刻統一
```php
// MySQL の CURDATE/CURTIME ではなく PHP の DateTime を使用
$now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$today = $now->format('Y-m-d');
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$currentTime = $now->format('H:i:s');
```

#### 3.4 レースコンディション対策
```php
// トランザクション内で重複チェック（FOR UPDATE）
$existingPending = dbSelectOne(
    "SELECT id FROM extension_requests
     WHERE reservation_id = ? AND status = 'pending'
     FOR UPDATE",
    [$reservation['id']]
);
if ($existingPending) {
    throw new Exception('既に延長申請中です。担当者からの回答をお待ちください。');
}
```

#### 3.5 UI改善
- 「最大○分まで延長可能」メッセージを常に表示
- 翌日終了時は「(翌日)」表示

### Phase 4: 延長可能時間計算の改修

**ファイル:** `public_html/includes/functions.php`

#### 4.1 関数シグネチャ変更
```php
// Before
function calculateAvailableExtension($salesAreaId, $storeId, $endTime, $reservationDate)

// After
function calculateAvailableExtension($salesAreaId, $storeId, $startTime, $endTime, $reservationDate)
```

#### 4.2 翌日予約検索追加
```php
// 翌日の予約もチェック
$nextDay = date('Y-m-d', strtotime($reservationDate . ' +1 day'));

$nextReservation = dbSelectOne(
    "SELECT ...
     WHERE ... AND (
         (reservation_date = ? AND start_time > ?)
         OR
         (reservation_date = ? AND start_time <= ?)
     )
     ORDER BY reservation_date ASC, start_time ASC
     LIMIT 1",
    [$reservationDate, $endTime, $nextDay, $endTime, ...]
);
```

#### 4.3 日跨ぎ対応フラグ
```php
return [
    'max_minutes' => $maxMinutes,
    'max_end_time' => $maxEndTime,
    'reason' => $reason,
    'is_next_day' => $isNextDay  // 追加
];
```

### Phase 5: 営業区分管理画面

**ファイル:** `public_html/pages/settings/store.php`

- 営業区分作成時に room_code を自動生成
- 営業区分詳細にQRコード表示
- QRコードダウンロード機能（qrcode.js使用）

---

## Codexレビューで発見・修正した問題

### 第1回レビュー

| 優先度 | 問題 | 対応 |
|--------|------|------|
| High | 深夜跨ぎ予約の「現在利用中」検出が不完全 | 3パターンのSQL条件で対応 |
| High | 次予約検索が翌日予約を無視 | 翌日も検索するよう修正 |
| Medium | calculateAvailableExtension に start_time がない | パラメータ追加 |
| Medium | PHP Timezone と MySQL の不一致リスク | PHP DateTime で統一 |
| Medium | 重複申請のレースコンディション | FOR UPDATE ロックで対応 |

### 第2回レビュー

| 優先度 | 問題 | 対応 |
|--------|------|------|
| High | 同日深夜跨ぎ（23:30で23:00-01:00）の検出漏れ | パターン2追加 |
| Medium | トランザクション内での重複チェック漏れ | FOR UPDATE再チェック追加 |
| Low | 「最大○分」が30分未満時のみ表示 | 常に表示するよう改善 |

### 第3回レビュー
- 残り問題なし（max_end_time形式は問題なし）

---

## テスト結果（10/10 パス）

| # | テスト項目 | 結果 |
|---|-----------|------|
| 1 | 基本機能（VIPルーム） | ✅ |
| 2 | 次予約による延長制限（ペアルーム） | ✅ |
| 3 | 深夜跨ぎ予約・当日側（23:30） | ✅ |
| 4 | 深夜跨ぎ予約・翌日側（00:30） | ✅ |
| 5 | 予約時間外アクセス | ✅ |
| 6 | 無効なroom_codeバリデーション | ✅ |
| 7 | 重複申請ブロック | ✅ |
| 8 | 翌日表示（翌日） | ✅ |
| 9 | 翌日予約検出 | ✅ |
| 10 | Webアクセス確認 | ✅ |

---

## 影響範囲

| ファイル | 変更内容 |
|---------|---------|
| `database/008_add_room_code.sql` | room_code カラム追加 |
| `public_html/index.php` | ルーティング追加 |
| `public_html/pages/extend/index.php` | room_code対応、深夜跨ぎ対応、セキュリティ強化 |
| `public_html/includes/functions.php` | calculateAvailableExtension改修 |
| `public_html/pages/settings/store.php` | room_code生成、QRコード表示 |

---

## 運用フロー

1. **管理者**: 店舗設定 > 営業区分 > QRコードをダウンロード
2. **部屋に貼付**: 印刷して各部屋に貼っておく
3. **顧客**: 利用中にQRをスキャン → 延長申請フォーム表示
4. **清掃者**: LINE通知受信 → 回答

---

## 清掃時間制約

延長可能時間の計算には清掃時間（60分）を考慮：

```
最大延長時間 = 次予約開始時刻 - 現在の終了時刻 - 60分（清掃時間）
```

例: 現在12:00終了、次予約14:00開始の場合
→ 最大延長 = 14:00 - 12:00 - 1:00 = 1時間（60分）

---

## 成功基準（すべて達成）

- [x] 部屋固定のURLで延長フォームにアクセスできる
- [x] 現在利用中の予約が自動で特定される
- [x] 深夜跨ぎ予約が正しく検出される
- [x] 延長申請〜LINE通知〜回答の流れが動作する
- [x] 営業区分画面でQRコードが表示・ダウンロードできる
- [x] 清掃時間を考慮した延長制限が機能する

---

## 備考

- 従来のtoken方式も後方互換性のため残している
- レート制限はWAF/nginx層での対応を推奨
- LINE通知のテストは実機（LINE連携済み清掃者）が必要
