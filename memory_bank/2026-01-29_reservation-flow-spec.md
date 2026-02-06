# 予約フロー 仕様書（実装ベース）

## 1. 概要

カクレマ新基幹システムの予約フローは、以下の2系統で構成される。

### 顧客向け予約フロー（公開ページ）

4ステップのウィザード形式で、顧客がWebブラウザまたはLINE LIFF経由で予約を完了する。

```
Step1: 日時選択 → Step2: 顧客情報入力 → Step3: 確認画面 → Step4: 完了処理 → Thanks画面
```

### CMS手動予約登録（管理画面）

スタッフがCMS上で手動登録する。電話・LINE・直接来店による予約に対応。

### 予約管理画面（CMS）

一覧・詳細・カレンダー・ガントチャートの4ビューで予約を管理する。

### 予約経路（source）

| 経路 | source値 | 登録方法 |
|------|----------|----------|
| Webフォーム | `web` | 顧客が公開フォームから自動登録 |
| LINE | `line` | LIFF経由の自動登録 or スタッフ手動登録 |
| 電話 | `phone` | スタッフがCMSで手動登録 |
| 直接来店 | `direct` | スタッフがCMSで手動登録 |

---

## 2. 顧客向け予約フロー（4ステップウィザード）

### 2.1 Step1: 日時選択（booking/index.php）

**パス**: `/booking/{store_code}` または `/booking`（ログイン済み）

#### アクセス制御

- **店舗コード指定時**: 認証不要（公開ページ）。店舗コードで `stores` テーブルを検索し、`is_active=1` かつ `deleted_at IS NULL` の店舗のみ表示。存在しない場合は404。
- **店舗コードなし**: ログイン必須。未ログインなら403。ログイン済みの場合はユーザーがアクセス可能な全店舗の営業区分を表示。

#### セキュリティ

- セッション固定化攻撃対策: フロー開始時に `session_regenerate_id(true)` でセッションIDを再生成
- CSRFトークンをhiddenフィールドに埋め込み

#### 画面構成

1. **部屋タイプ選択**（カード形式）
   - 営業区分（`sales_areas`）を一覧表示
   - 表示情報: 部屋名、店舗名（複数店舗時）、説明、定員、営業時間、時間単価
   - 営業時間: `stores.opening_time` / `stores.closing_time` / `stores.is_24h_open` から取得
   - 時間単価: `sales_areas.hourly_rate`（デフォルト2,500円）

2. **日時選択**
   - 利用日: dateピッカー（当日～30日後）
   - 開始時間: 30分刻みセレクト（00:00～23:30）
   - 終了時間: 30分刻みセレクト（00:30～24:00）
   - 営業時間に基づく選択肢フィルタリング（部屋選択時にJSで動的制御）
   - 深夜営業（終了 < 開始）に対応

3. **空き状況リアルタイムチェック**
   - 部屋・日付・開始/終了時間の全てが入力された時点でAPIを呼び出し
   - 予約時間は1～8時間の範囲のみ許可（クライアント側チェック）
   - 結果に応じて「予約可能」「満室」「エラー」を表示
   - 空きがない場合は送信ボタンを無効化

4. **LINE LIFF対応**
   - LIFF SDKが読み込まれている場合、LINEアプリ内であればプロフィール情報を自動取得
   - `source=line`, `line_user_id`, `line_display_name` をhiddenフィールドに設定

#### フォーム送信先

POST → `/booking/{store_code}/customer`

---

### 2.2 Step2: 顧客情報入力（booking/customer.php）

**パス**: `/booking/{store_code}/customer`（POSTのみ）

#### バリデーション（サーバーサイド）

| 項目 | ルール |
|------|--------|
| sales_area_id | 正の整数、有効な営業区分であること |
| reservation_date | Y-m-d形式、実在する日付、当日～30日後 |
| start_time | HH:MM形式（00:00～23:59）、30分刻み |
| end_time | HH:MM形式（00:00～23:59）、30分刻み |
| 利用時間 | 1～8時間（深夜またぎ対応） |
| 当日予約 | 現在時刻＋1時間以降のみ |
| 営業時間 | 店舗の営業時間内であること（深夜営業対応） |
| 店舗コード境界 | URLの店舗コードと営業区分の店舗コードが一致すること |

#### セッション保存データ

```php
$_SESSION['booking'] = [
    'sales_area_id' => $salesAreaId,
    'store_id' => $salesArea['store_id'],
    'reservation_date' => $reservationDate,
    'start_time' => $startTime,
    'end_time' => $endTime,
    'store_name' => $salesArea['store_name'],
    'area_name' => $salesArea['name'],
    'source' => 'web' or 'line',
    'line_user_id' => '...',
    'line_display_name' => '...',
];
```

#### 画面構成

- 予約内容サマリー表示（店舗名・部屋名・日時）
- 入力フォーム:
  - お名前（必須、maxlength=100）
  - メールアドレス（必須、maxlength=255）
  - 電話番号（必須、maxlength=20）
  - ご利用人数（1～10名セレクト）
  - クーポンコード（任意、maxlength=50）

#### フォーム送信先

POST → `/booking/{store_code}/confirm`

---

### 2.3 Step3: 確認画面（booking/confirm.php）

**パス**: `/booking/{store_code}/confirm`（POSTのみ）

#### バリデーション（サーバーサイド）

| 項目 | ルール |
|------|--------|
| customer_name | 必須、100文字以内 |
| customer_email | 必須、メール形式 |
| customer_phone | 必須、20文字以内、`[0-9\-\+\s\(\)]+` |
| num_people | 1～10（範囲外は1に補正） |

#### 料金計算

```
base_price = hourly_rate × 利用時間（時間単位）
```

- `hourly_rate`: `sales_areas.hourly_rate`（未設定時は `DEFAULT_HOURLY_RATE = 2500`）
- 利用時間: `(end_minutes - start_minutes) / 60`（深夜またぎ対応）
- 利用時間範囲チェック: `MIN_BOOKING_DURATION_HOURS(1)` ～ `MAX_BOOKING_DURATION_HOURS(8)`

#### セッション追加保存データ

```php
$booking['customer_name'] = $customerName;
$booking['customer_email'] = $customerEmail;
$booking['customer_phone'] = $customerPhone;
$booking['num_people'] = $numPeople;
$booking['coupon_code'] = $couponCode;
$booking['base_price'] = $basePrice;
```

#### セキュリティ

- 店舗IDの整合性チェック（`sales_area.store_id` とセッションの `store_id` が一致するか）
- メールアドレスを部分マスクして表示（例: `t***t@example.com`）

#### 画面構成

- 予約内容確認テーブル（店舗、日時、利用時間）
- 顧客情報確認テーブル（名前、メール、電話、人数、クーポン）
- 合計金額表示
- 決済未実装注記: 「決済サービスは準備中です。現時点では仮予約として登録されます。」

#### フォーム送信先

POST → `/booking/{store_code}/complete`

---

### 2.4 Step4: 予約完了処理（booking/complete.php）

**パス**: `/booking/{store_code}/complete`（POSTのみ）

#### トランザクション処理

以下の処理を1つのDBトランザクション内で実行する。

1. **セッション改ざん検証**: `sales_area_id` と `store_id` の整合性を再検証
2. **空き状況再検証**: `getAvailableKeyCount()` で空き鍵数を確認（競合防止）
3. **予約データ登録**: `reservations` テーブルにINSERT
   - 初期ステータス: `pending`
   - 決済状態: `unpaid`
4. **自動確定処理（MVP版）**: 決済未実装のため即座に `confirmed` / `paid` に更新
5. **鍵割当**: `assignKeyToReservation()` で空き鍵をFOR UPDATEロック付きで取得・割当
6. **清掃案件生成**: `createCleaningJobForReservation()` で案件レコード作成

#### 予約データ構造

```php
$reservationData = [
    'store_id' => ...,
    'sales_area_id' => ...,
    'reservation_date' => ...,
    'start_time' => ...,
    'end_time' => ...,
    'customer_name' => ...,
    'customer_email' => ...,
    'customer_phone' => ...,
    'num_people' => ...,
    'base_price' => ...,
    'total_price' => ...,       // base_priceと同額（延長なし時点）
    'payment_status' => 'unpaid',
    'status' => 'pending',
    'source' => 'web' or 'line',
    'coupon_code' => ... or null,
    'line_user_id' => ...,      // LINE経由の場合
    'line_display_name' => ..., // LINE経由の場合
];
```

#### トランザクション後処理

1. **当日予約通知**: 当日予約の場合、LINE経由で清掃者に即時通知（`sendSameDayNotification()`）
   - 通知失敗してもユーザー体験に影響させない（try-catch）
2. **セッションID再生成**: `session_regenerate_id(true)`
3. **予約確認メール送信**: `sendReservationConfirmMail()`
   - 送信失敗してもフローは中断しない
4. **セッション整理**: `$_SESSION['booking']` 削除、`$_SESSION['completed_booking']` に完了情報を保存

#### エラーハンドリング

| 例外 | 対応 |
|------|------|
| RuntimeException（鍵割当失敗） | ロールバック → 「他のお客様が先に予約されました」 |
| Exception（一般エラー） | ロールバック → 「予約の登録に失敗しました」 |

#### リダイレクト先

成功時 → `/booking/{store_code}/thanks`

---

### 2.5 完了ページ（booking/thanks.php）

**パス**: `/booking/{store_code}/thanks`

#### 表示内容

- 予約番号（`#ID`）
- 店舗名・部屋名
- 予約日時
- 鍵番号（`key_assignments` → `keys.key_number` を取得して表示）
- 顧客名
- お支払い金額
- 「受付にて予約番号をお伝えください」の案内

#### セッションクリーンアップ

ページ表示完了後、以下のセッション変数を全て削除:
- `$_SESSION['completed_booking']`
- `$_SESSION['booking_session_initialized']`
- `$_SESSION['booking_store_code']`
- `$_SESSION['booking']`

---

## 3. CMS手動予約登録（reservations/create.php）

**パス**: `/reservations/new`（要ログイン）

### アクセス制御

- `getAccessibleStoreIds()` でユーザーのアクセス可能店舗を取得
- アクセス可能店舗がない場合はダッシュボードにリダイレクト
- 営業区分がない場合は予約一覧にリダイレクト

### 入力フォーム

| 項目 | 必須 | 入力タイプ | バリデーション |
|------|------|------------|----------------|
| 営業区分 | * | セレクト | アクセス可能な区分のみ |
| 予約日 | * | date | Y-m-d形式 |
| 開始時間 | * | time | HH:MM形式 |
| 終了時間 | * | time | HH:MM形式 |
| 予約経路 | - | セレクト | phone/line/direct/web |
| 基本料金 | - | number | 0～10,000,000円 |
| 決済状態 | - | セレクト | unpaid/paid |
| 顧客名 | * | text | 100文字以内 |
| 電話番号 | - | tel | 20文字以内 |
| メールアドレス | - | email | 255文字以内、メール形式 |
| 人数 | - | number | 1～10 |
| 備考 | - | textarea | 1000文字以内 |

### 顧客予約との差異

| 機能 | 顧客予約 | CMS手動登録 |
|------|----------|-------------|
| メール・電話 | 必須 | 任意 |
| 料金計算 | 自動（hourly_rate × 時間） | 手動入力 |
| 決済状態 | 自動（MVP版: 即paid） | 選択可能 |
| 予約日制限 | 当日～30日後 | 制限なし（過去日も可） |
| 備考 | なし | あり |
| 延長トークン | なし | 自動生成 |
| 鍵割当・案件生成 | 常に実行 | `paid` 選択時のみ実行 |

### 深夜またぎルール（CMS）

- 開始18時以降 + 終了6時以前 → 深夜跨ぎとして許可
- それ以外で終了 < 開始 → エラー

### 登録成功時

`/reservations/{id}` にリダイレクト

---

## 4. 空き状況チェックAPI

### 4.1 リクエスト/レスポンス仕様

**エンドポイント**: `POST /api/booking/check-availability`

#### リクエスト

```json
{
    "sales_area_id": 1,
    "reservation_date": "2026-01-30",
    "start_time": "14:00",
    "end_time": "16:00"
}
```

Content-Type: `application/json`

#### レスポンス（成功）

```json
{
    "available": true,
    "message": "予約可能です",
    "available_rooms": 3
}
```

#### レスポンス（空きなし）

```json
{
    "available": false,
    "message": "選択された時間帯は満室です"
}
```

#### バリデーションエラー

各種バリデーションエラー時は `available: false` + `message` を返す。

#### HTTPエラー

| ステータス | 条件 |
|------------|------|
| 405 | POST以外のメソッド |
| 429 | レート制限超過 |
| 400 | JSONパース失敗 |

### 4.2 レート制限

- **制限**: IPアドレス単位で30リクエスト/分
- **キー**: `booking_check_` + MD5(REMOTE_ADDR)
- **ファイルロック付き** `checkRateLimit()` 関数で実装
- 超過時: HTTP 429 + `Retry-After` ヘッダ

### 4.3 空き判定ロジック

`getAvailableKeyCount()` 関数を使用:

```
空き鍵数 = 総鍵数 - 使用中鍵数
```

- 総鍵数: `keys` テーブルで `sales_area_id` / `is_active=1` のカウント
- 使用中鍵数: `checkReservationConflict()` で清掃時間を考慮した重複チェック
  - 清掃時間: `sales_areas.cleaning_duration_minutes`（未設定時は `CLEANING_TIME_MINUTES=60`分）
  - 深夜跨ぎ対応: 前日分も含めて検索
  - 清掃完了済みの場合は `completed_at` 時刻でブロック解除

---

## 5. 予約管理画面（CMS）

### 5.1 一覧画面（reservations/list.php）

**パス**: `/reservations`（要ログイン）

#### フィルタ

| フィルタ | デフォルト |
|----------|-----------|
| ステータス | 全て（pending/confirmed/completed/cancelled） |
| 開始日 | 当日 |
| 終了日 | 7日後 |

#### 一覧カラム

| カラム | 内容 |
|--------|------|
| 予約日 | reservation_date |
| 時間 | start_time - end_time |
| 顧客名 | customer_name（電話番号サブ表示） |
| 店舗 | store_name（area_nameサブ表示） |
| ステータス | バッジ表示 |
| 決済 | 支払済/未払い/返金済バッジ |
| 経路 | Web/LINE/電話/直接 |
| 操作 | 詳細リンク |

#### 機能

- ページネーション（20件/ページ）
- CSVエクスポート（`/api/export/reservations`）
- 新規登録ボタン（`/reservations/new`）
- 表示切替タブ: リスト / カレンダー / ガント

#### アクセス制御

- `getFilteredStoreIds()` でサイドバーの店舗選択に基づくフィルタリング
- ソフトデリート（`deleted_at IS NULL`）考慮

---

### 5.2 詳細・編集画面（reservations/detail.php）

**パス**: `/reservations/{id}`（要ログイン）

#### 表示内容

- **予約情報**: 予約日、時間、店舗、営業区分、予約経路、顧客名、電話、メール、人数、備考
- **決済情報**: 基本料金、延長料金、合計、決済状態、決済方法
- **鍵割当情報**: 鍵番号
- **清掃案件**: ステータス、担当者名・電話、案件詳細リンク
- **延長申請URL**: confirmed + 当日予約の場合に表示（`extension_token` ベース）

#### 操作

##### ステータス変更（`action=update_status`）

- 楽観ロック: 現在のステータスを条件に含めてUPDATE
- FOR UPDATEロックで競合防止

| 遷移 | 自動処理 |
|------|----------|
| pending → confirmed | 鍵割当（未割当の場合）+ 清掃案件生成（未生成の場合） |
| * → cancelled | 鍵解除（returned_at設定）+ 清掃案件ソフトデリート |

##### 状態遷移ルール

| 制約 | 内容 |
|------|------|
| cancelled → 他 | 不可（キャンセル済みからは復帰不可） |
| completed → cancelled | 不可（完了済みはキャンセル不可） |

##### 顧客情報編集（`action=update_info`、モーダル）

編集可能項目: 顧客名、電話番号、メールアドレス、人数、備考

##### キャンセル処理（`action=cancel`）

1. FOR UPDATEロックで予約取得
2. pending/confirmed のみキャンセル可能（楽観ロック）
3. 鍵割当解除
4. 清掃案件ソフトデリート（completed/paid以外）
5. キャンセルメール送信（`sendReservationCancelMail()`）
6. 監査ログ記録

---

### 5.3 カレンダー表示（reservations/calendar.php）

**パス**: `/reservations/calendar`（要ログイン）

- **ライブラリ**: FullCalendar v6.1.8
- **表示形式**: 月表示 / 週表示 / 日表示
- **データソース**: `/api/reservations/calendar-data`（JSON API）
- **イベントクリック**: 予約詳細ページに遷移
- **ツールチップ**: タイトル + 店舗名 + 区分名
- **凡例**: 保留(灰) / 確定(青) / 完了(緑) / キャンセル(赤)

---

### 5.4 ガントチャート（reservations/gantt.php）

**パス**: `/reservations/gantt`（要ログイン）

- **日付選択**: 前日/翌日ボタン + dateピッカー
- **行**: 営業区分ごと（店舗名 + 区分名）
- **タイムライン**: 8:00～24:00（16時間幅）
- **バー表示**: 各予約を色付きバー（ステータス色）で表示
  - 位置計算: `left = (startHour - 8) / 16 * 100%`
  - 幅計算: `width = duration / 16 * 100%`
- **バークリック**: 予約詳細ページに遷移
- **凡例**: 保留(灰) / 確定(青) / 完了(緑) / キャンセル(赤)

---

## 6. 予約ステータス遷移

```
pending ──→ confirmed ──→ completed
   │             │
   └──→ cancelled ←──┘
```

| ステータス | 日本語 | 説明 | 遷移先 |
|------------|--------|------|--------|
| `pending` | 保留 | 予約受付、未決済 | confirmed, cancelled |
| `confirmed` | 確定 | 決済完了 | completed, cancelled |
| `completed` | 完了 | 利用終了 | （終端） |
| `cancelled` | キャンセル | 取消済み | （終端） |

### 遷移ルール（実装）

- `cancelled` → 他: **不可**（復帰不可）
- `completed` → `cancelled`: **不可**
- 楽観ロック: UPDATE時に現在ステータスを条件に含める
- FOR UPDATEロック: 二重実行防止

### ステータス変更時の自動処理

| 遷移 | 自動処理 |
|------|----------|
| pending → confirmed | 鍵割当 + 清掃案件生成 |
| * → cancelled | 鍵解除 + 清掃案件ソフトデリート + キャンセルメール |
| 監査ログ | 全てのステータス変更で `logAudit()` 記録 |

---

## 7. 料金計算ロジック

### 基本料金

```
base_price = sales_areas.hourly_rate × 利用時間（時間単位）
```

- `hourly_rate`: 営業区分テーブルから取得
- フォールバック: `DEFAULT_HOURLY_RATE = 2,500円`
- 利用時間: 分単位で計算後、60で割る（深夜またぎ対応）

### 定数一覧（config.php）

| 定数名 | 値 | 説明 |
|--------|-----|------|
| `DEFAULT_HOURLY_RATE` | 2,500 | デフォルト時間単価（円） |
| `EXTENSION_PRICE_PER_HOUR` | 1,000 | 延長1時間あたりの顧客料金（円） |
| `MAX_EXTENSION_HOURS` | 5.0 | 最大延長時間（時間） |
| `CLEANING_TIME_MINUTES` | 60 | 清掃時間（分） |
| `MAX_BOOKING_DURATION_HOURS` | 8 | 最大予約時間 |
| `MIN_BOOKING_DURATION_HOURS` | 1 | 最小予約時間 |

### 合計料金

```
total_price = base_price + extension_price
```

- 初期状態: `total_price = base_price`
- 延長発生時: `extension_price += EXTENSION_PRICE_PER_HOUR × 延長時間`

### CMS手動登録時

- 基本料金は手入力（デフォルト5,000円）
- `total_price = base_price`（延長なし時点）

---

## 8. 自動処理（鍵割当・案件生成・通知）

### 8.1 鍵割当（assignKeyToReservation）

**定義**: `reservation_helpers.php`

1. 同一営業区分・同一日の同一時間帯で使用されていない鍵を検索
2. `FOR UPDATE` ロックで競合防止
3. キャンセル済み予約は除外
4. 時間重複判定: `NOT (r.end_time <= ? OR r.start_time >= ?)`
5. `key_assignments` テーブルにINSERT（`assigned_at` = 現在時刻）
6. 空き鍵がない場合は `RuntimeException` をスロー

### 8.2 清掃案件生成（createCleaningJobForReservation）

**定義**: `job_helpers.php`

1. 清掃開始時刻 = 予約終了時刻（深夜跨ぎ対応: end < start なら翌日）
2. 清掃時間 = `getCleaningDuration($salesAreaId)`（sales_areas優先、なければ `CLEANING_TIME_MINUTES`）
3. 清掃終了予定時刻 = 開始時刻 + 清掃時間
4. 報酬 = `stores.base_reward`（未設定時は `DEFAULT_CLEANING_REWARD = 2,000円`）
5. ステータス = `unassigned`
6. 案件タイプ = `regular`

### 8.3 通知処理

| 通知 | タイミング | 処理 |
|------|-----------|------|
| 当日予約通知 | 予約完了時に当日の場合 | `sendSameDayNotification()` - LINE経由 |
| 予約確認メール | 予約完了後 | `sendReservationConfirmMail()` |
| キャンセルメール | キャンセル処理後 | `sendReservationCancelMail()` |

通知・メール送信失敗時はエラーログに記録するが、ユーザー体験には影響させない。

---

## 9. バリデーション一覧

### 顧客予約フォーム（Step1→Step2 サーバーサイド）

| 項目 | ルール | エラーメッセージ |
|------|--------|------------------|
| sales_area_id | 正の整数 | 店舗を選択してください |
| reservation_date | Y-m-d形式 | 予約日を選択してください |
| reservation_date | 実在する日付 | 無効な日付です |
| reservation_date | 当日以降 | 過去の日付は選択できません |
| reservation_date | 30日以内 | 30日以上先の日付は選択できません |
| start_time / end_time | 非空 | 時間を選択してください |
| start_time / end_time | HH:MM形式 | 開始/終了時間の形式が不正です |
| start_time / end_time | 30分刻み | 開始/終了時間は30分刻みで選択してください |
| 利用時間 | 1～8時間 | 最大8時間まで/最低1時間から |
| 当日予約 | 現在＋1時間以降 | 当日予約は1時間後以降の時間帯を選択してください |
| 営業時間 | 店舗営業時間内 | 営業時間外です（HH:MM〜HH:MM） |
| 店舗コード | URL店舗 = 区分店舗 | 指定された部屋は選択できません |

### 顧客予約フォーム（Step2→Step3 サーバーサイド）

| 項目 | ルール | エラーメッセージ |
|------|--------|------------------|
| customer_name | 必須 | お名前を入力してください |
| customer_name | 100文字以内 | お名前は100文字以内で入力してください |
| customer_email | 必須 | メールアドレスを入力してください |
| customer_email | メール形式 | メールアドレスの形式が正しくありません |
| customer_phone | 必須 | 電話番号を入力してください |
| customer_phone | 20文字以内 | 電話番号は20文字以内で入力してください |
| customer_phone | `[0-9\-\+\s\(\)]+` | 電話番号に使用できない文字が含まれています |

### CMS手動登録

| 項目 | ルール |
|------|--------|
| sales_area_id | 必須、アクセス可能 |
| reservation_date | 必須、Y-m-d形式（過去日も可） |
| start_time / end_time | 必須、HH:MM形式 |
| 時間前後関係 | 深夜跨ぎは18時以降～6時以前のみ許可 |
| customer_name | 必須、100文字以内 |
| customer_phone | 任意、20文字以内 |
| customer_email | 任意、255文字以内、メール形式 |
| num_people | 1～10 |
| source | phone/line/direct/web |
| payment_status | unpaid/paid |
| base_price | 0～10,000,000 |
| notes | 1000文字以内 |

### 空き状況チェックAPI

| 項目 | ルール |
|------|--------|
| sales_area_id | 正の整数 |
| reservation_date | Y-m-d形式、当日以降 |
| start_time / end_time | 非空、HH:MM形式 |
| 利用時間 | 1～8時間 |
| 営業区分 | 有効な営業区分であること |

---

## 10. セキュリティ対策

| 対策 | 実装箇所 | 詳細 |
|------|----------|------|
| CSRF保護 | 全POST処理 | `generateCsrfToken()` / `requireCsrf()` |
| セッション固定化対策 | フロー開始時・完了時 | `session_regenerate_id(true)` |
| SQLインジェクション対策 | DB操作全般 | プリペアドステートメント |
| XSS対策 | 全出力 | `h()` 関数でHTMLエスケープ |
| セッション改ざん検出 | complete.php | sales_area_idとstore_idの整合性を再検証 |
| 店舗コード境界検証 | customer.php | URLの店舗コードと営業区分の店舗コードの一致チェック |
| レート制限 | check-availability API | IP単位30リクエスト/分 |
| 楽観ロック | ステータス変更 | UPDATE WHERE status = 現在値 |
| 悲観ロック | 鍵割当・予約完了 | SELECT ... FOR UPDATE |
| 入力値検証 | 全入力 | 正規表現、型チェック、範囲チェック |
| エラー情報隠蔽 | 例外処理 | 本番環境では詳細エラーを非表示 |

---

## 11. 主要関数一覧

### reservation_helpers.php

| 関数名 | 説明 |
|--------|------|
| `reservationSourceLabel($source)` | 予約経路のラベル変換 |
| `paymentStatusInfo($status)` | 決済状態のCSSクラス・ラベル取得 |
| `assignKeyToReservation($resId, $areaId, $date, $start, $end)` | 鍵割当（FOR UPDATEロック） |
| `isSameDayReservation($date)` | 当日予約判定（Asia/Tokyo） |
| `getCleaningDuration($salesAreaId)` | 清掃時間取得（静的キャッシュ付き） |
| `checkReservationConflict($areaId, $date, $start, $end, $excludeId)` | 清掃時間考慮の時間重複チェック |
| `getAvailableKeyCount($areaId, $date, $start, $end, $excludeId)` | 空き鍵数取得 |
| `calculateAvailableExtension($areaId, $storeId, $start, $end, $date)` | 延長可能時間計算 |
| `getExtensionOptions($availableMinutes)` | 延長選択肢生成（30/60/90/120分） |

### job_helpers.php

| 関数名 | 説明 |
|--------|------|
| `createCleaningJobForReservation($resId, $storeId, $areaId, $date, $start, $end)` | 清掃案件生成 |

### notification_helpers.php

| 関数名 | 説明 |
|--------|------|
| `sendSameDayNotification($resId, $jobId)` | 当日予約LINE通知 |
| `sendReservationConfirmMail($reservation)` | 予約確認メール送信 |
| `sendReservationCancelMail($reservation)` | キャンセルメール送信 |

---

## 12. spec仕様書との乖離

### 乖離1: 決済フロー（重大）

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| 決済方式 | Webhook受信 → 自動登録 (`POST /api/payment/webhook`) | **決済未実装**。MVP版として予約即確定（pending → confirmed を即座に実行） |
| 決済状態 | 決済完了で confirmed | 常に paid に即更新 |
| 確認画面注記 | なし | 「決済サービスは準備中です。現時点では仮予約として登録されます。」 |

### 乖離2: 予約完了ページの機能

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| メールアドレス編集 | 「誤入力時に修正可能」と記載 | **未実装**。完了ページは表示のみ |
| 再送ボタン | 「確認メールを再送」と記載 | **未実装** |
| 修正履歴ログ | 「修正履歴をログに記録」と記載 | **未実装** |

### 乖離3: LINE予約の扱い

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| LINE予約 | 「スタッフが手動入力」と記載 | LIFF対応により**顧客自身がLINE内から予約可能**（自動フロー）。CMS手動登録も可能 |

### 乖離4: CMS手動登録のフィールド必須性

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| メールアドレス | 「必須」と記載 | **任意** |
| 電話番号 | 「必須」と記載 | **任意** |
| クーポン | 入力項目として記載 | **CMS登録フォームには未実装**（顧客フォームのみ） |

### 乖離5: 一覧フィルタ

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| 予約経路フィルタ | フィルタ項目として記載 | **未実装**（ステータス・期間のみ） |
| 鍵番号カラム | 一覧カラムとして記載 | **未表示**（詳細画面でのみ表示） |
| 操作カラム | 「詳細/編集/キャンセル」と記載 | 「詳細」リンクのみ |

### 乖離6: 予約詳細の編集機能

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| 日時編集 | 「確定前のみ」と記載 | **未実装**（日時の編集UIなし） |
| 営業区分変更 | 「確定前のみ」と記載 | **未実装** |
| ステータス履歴 | 表示項目として記載 | **未実装**（現在ステータスのみ表示、履歴なし） |

### 乖離7: 予約ブロック（清掃時間考慮）の実装差異

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| SQL判定ロジック | 単純な `end_time + 60分` のSQL | `checkReservationConflict()` でDATETIME変換＋深夜跨ぎ対応の複合SQL |
| 清掃時間設定 | 「デフォルト1時間固定」と記載 | `sales_areas.cleaning_duration_minutes` 対応済み（`getCleaningDuration()` で動的取得） |

### 乖離8: 固定者通知

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| confirmed時の通知 | 「固定者がいれば優先通知」と記載 | 当日予約の場合のみ `sendSameDayNotification()` で通知。confirmed時の固定者優先通知は別フロー |

### 乖離9: 4ステップウィザード構成

| 項目 | spec仕様書 | 実装 |
|------|------------|------|
| ウィザードステップ | 記載なし（概念のみ） | 4ステップ構成を明示的に実装（ステップインジケーター付き） |
| 店舗コードURL | 記載なし | `/booking/{store_code}` 形式で店舗別URLを実装 |

---

*作成日: 2026-01-29*
*作成元: 実装コード + spec仕様書の読み比べ*
