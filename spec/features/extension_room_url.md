# 延長機能 部屋ベースURL仕様書

## 概要

延長申請機能のURLを「予約ごとのトークン」から「部屋ごとの固定URL」に変更する。
QRコードを部屋に貼っておく運用を想定。

---

## 現行方式と新方式の比較

| 項目 | 現行方式 | 新方式 |
|------|----------|--------|
| URL形式 | `/extend/?token={64文字のトークン}` | `/extend/room/{16文字のコード}` |
| 識別対象 | 予約（reservations.extension_token） | 部屋（sales_areas.room_code） |
| QR運用 | 予約ごとに変わる（不便） | 部屋に固定で貼れる |
| 予約特定 | トークンで直接特定 | 部屋＋本日＋現在時刻で自動特定 |
| 後方互換性 | - | 従来のtoken方式も維持 |

---

## データベース変更

### sales_areas テーブル

新規カラム追加：

| カラム名 | 型 | 制約 | 説明 |
|---------|-----|------|------|
| room_code | VARCHAR(16) | UNIQUE, NULL許可 | 部屋識別コード（16文字の英数字） |

**マイグレーションSQL:**

```sql
ALTER TABLE sales_areas
ADD COLUMN room_code VARCHAR(16) UNIQUE AFTER description;

-- 既存データにコード生成
UPDATE sales_areas
SET room_code = LOWER(SUBSTRING(MD5(CONCAT(id, RAND())), 1, 8))
WHERE room_code IS NULL;
```

---

## URL設計

### 新規エンドポイント

```
GET /extend/room/{room_code}
```

**パラメータ:**
- `room_code`: 16文字の英数字（sales_areas.room_code）

**レスポンス:**
- 成功: 延長申請フォーム表示
- 失敗: エラーメッセージ表示

### 従来エンドポイント（維持）

```
GET /extend/?token={extension_token}
```

後方互換性のため維持する。

---

## 予約自動特定ロジック

### 検索条件

**重要**: 日時はPHP側で取得し、MySQLの `CURDATE()` / `CURTIME()` は使用しない。
これはPHPとMySQLのタイムゾーン設定が異なる場合の不一致を防止するため。

```php
// PHP側で現在日時を取得
$timezone = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $timezone);
$today = $now->format('Y-m-d');
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$currentTime = $now->format('H:i:s');
```

```sql
SELECT r.*, sa.name as area_name, ...
FROM reservations r
INNER JOIN sales_areas sa ON r.sales_area_id = sa.id
WHERE sa.room_code = :room_code
  AND r.status = 'confirmed'
  AND r.deleted_at IS NULL
  AND (
      -- パターン1: 通常（日跨ぎなし）- 今日の予約で開始後～終了前
      (r.reservation_date = :today AND r.end_time >= r.start_time
       AND r.start_time <= :currentTime AND r.end_time > :currentTime)
      OR
      -- パターン2: 深夜跨ぎ・当日側 - 今日開始で終了が翌日、現在は開始後
      (r.reservation_date = :today AND r.end_time < r.start_time
       AND r.start_time <= :currentTime)
      OR
      -- パターン3: 深夜跨ぎ・翌日側 - 昨日開始で終了が今日、現在は終了前
      (r.reservation_date = :yesterday AND r.end_time < r.start_time
       AND :currentTime < r.end_time)
  )
ORDER BY r.reservation_date DESC, r.start_time DESC
LIMIT 1
```

**条件の意味:**
- `room_code`: 指定された部屋
- `status = 'confirmed'`: 確定済み予約のみ
- 3パターンの深夜跨ぎ対応で「現在利用中」を判定

### エッジケース対応

| ケース | 対応 |
|--------|------|
| 該当予約なし | 「現在利用中の予約がありません」メッセージ表示 |
| 複数予約あり | reservation_date DESC, start_time DESC で最新を優先 |
| 既に延長申請済み | 「既に延長申請中です」メッセージ表示 |
| 無効なroom_code | 「無効なアクセスです」メッセージ表示 |
| 深夜跨ぎ予約 | 3パターンで正しく判定（上記SQL参照） |

---

## QRコード機能

### 表示場所

店舗設定 > 営業区分詳細画面

### 機能

1. **QRコード表示**: 延長申請URLのQRコードを画面に表示
2. **ダウンロード**: PNG形式でダウンロード可能
3. **URL表示**: テキストでURLも表示（コピー可能）

### 技術実装

- ライブラリ: qrcode.js（CDN経由）
- 生成方式: クライアントサイドJavaScript
- 出力形式: Canvas → PNG

---

## セキュリティ考慮

### room_code の設計

- 16文字のランダム英数字（例: `a3f82b1c`）
- MD5ハッシュから生成（推測困難）
- UNIQUE制約で重複防止

### アクセス制御

- room_code が漏洩しても、「本日の現在利用中の予約」しか特定できない
- 過去・未来の予約情報は取得不可
- 延長申請後の回答URLは別途セキュアなトークンで保護

---

## 画面仕様

### 延長申請フォーム（/extend/room/{room_code}）

**正常時の表示内容:**
- 部屋名（営業区分名）
- 現在の終了時間
- 延長時間選択ボタン（30分/60分/90分/120分）
- 申請ボタン

**エラー時の表示:**
- エラーメッセージ
- 「現在利用中の予約がありません」等

### 営業区分詳細画面（QRコード表示）

**追加要素:**
- 延長用QRコードセクション
  - QRコード画像（200x200px程度）
  - URL表示（コピーボタン付き）
  - ダウンロードボタン

---

## 影響範囲

### 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `database/008_add_room_code.sql` | room_codeカラム追加 |
| `public_html/index.php` | ルーティング追加 |
| `public_html/pages/extend/index.php` | room_code対応ロジック追加 |
| `public_html/pages/settings/store.php` | room_code生成、QRコード表示 |

### 変更しないファイル

| ファイル | 理由 |
|---------|------|
| `public_html/pages/extend/respond.php` | 回答処理は変更不要 |
| `public_html/pages/reservations/create.php` | extension_token生成は維持（後方互換） |

---

## テスト項目

### 機能テスト

1. [ ] 新規営業区分作成時にroom_codeが自動生成される
2. [ ] 営業区分詳細画面でQRコードが表示される
3. [ ] QRコードをダウンロードできる
4. [ ] `/extend/room/{room_code}` で延長フォームが表示される
5. [ ] 現在利用中の予約が自動で特定される
6. [ ] 該当予約がない場合、適切なエラーメッセージが表示される
7. [ ] 延長申請が正常に送信される
8. [ ] LINE通知が清掃者に届く
9. [ ] 従来の`/extend/?token=xxx`も引き続き動作する

### セキュリティテスト

1. [ ] 無効なroom_codeでアクセスした場合、エラーになる
2. [ ] 他の日の予約情報は取得できない
3. [ ] 利用時間外にアクセスした場合、予約は表示されない

---

## 移行計画

1. DBマイグレーション実行（room_code追加）
2. 既存営業区分にroom_code設定
3. 新機能デプロイ
4. 各部屋にQRコード掲示
5. 従来のextension_token方式は当面維持（将来的に廃止検討）
