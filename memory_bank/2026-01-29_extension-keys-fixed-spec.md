# 延長・鍵管理・固定者管理 仕様書（実装ベース）

## 1. 概要

本ドキュメントは、カクレマ新基幹システムにおける「延長申請」「鍵番号管理」「固定者管理」の3機能について、実装コードを精読した上で仕様を記述し、spec仕様書との乖離を整理したものである。

### 対象機能

| 機能 | 概要 | 主な利用者 |
|------|------|-----------|
| 延長申請 | 利用中の顧客が延長を申請し、清掃者が承諾/拒否する | 顧客（公開画面）、清掃者（LINE経由） |
| 鍵番号管理 | 営業区分ごとに鍵を登録し、予約確定時に自動割当 | 管理者（CMS画面） |
| 固定者管理 | 店舗ごとに優先清掃者を登録し、案件発生時に優先通知 | 管理者（CMS画面） |

---

## 2. 延長フロー

### 2.1 延長申請（顧客側）

**画面**: `/extend/room/{room_code}` または `/extend/?token={token}`
**ファイル**: `/public_html/pages/extend/index.php`

顧客が店舗に設置されたQRコードをスキャンし、延長申請フォームにアクセスする。認証不要の公開ページ（`$isPublicPage = true`）。

**画面の表示内容（正常時）**:
- 部屋名（営業区分名）
- 現在の終了時間（深夜跨ぎ時は「(翌日)」表記）
- 延長可能時間と最大延長後の終了時刻
- 延長時間選択ボタン（30分/60分/90分/120分から選択可能な分のみ表示）
- 申請ボタン

**申請処理の流れ**:
1. CSRFトークン検証
2. 選択された延長時間がオプション内に存在するか検証
3. トランザクション開始
4. `FOR UPDATE` で予約をロック
5. 延長可能時間を再計算（レースコンディション対策）
6. `extension_requests` テーブルに pending ステータスで記録
7. 担当清掃者にLINE通知を送信（response_token付きURL）
8. トランザクションコミット

**重複申請防止**: 同一予約に対して `status = 'pending'` の extension_request が既にある場合、申請を拒否する。トランザクション内でも `FOR UPDATE` で再確認。

### 2.2 アクセス方式（部屋コード / トークン）

| 方式 | URL | 識別カラム | 予約特定方法 |
|------|-----|-----------|-------------|
| 部屋コード方式（推奨） | `/extend/room/{room_code}` | `sales_areas.room_code` | 部屋 + 本日 + 現在時刻で自動特定 |
| トークン方式（後方互換） | `/extend/?token={token}` | `reservations.extension_token` | トークンで直接特定 |

**入力値検証（ブルートフォース対策）**:
- `room_code`: 8-32文字の英数字のみ（正規表現 `/^[a-zA-Z0-9]{8,32}$/`）
- `token`: 32-128文字の英数字のみ（正規表現 `/^[a-zA-Z0-9]{32,128}$/`）

**深夜跨ぎ対応**: 現在利用中の予約を特定するSQL は3パターンで判定する。

```
パターン1: 通常（日跨ぎなし）
  reservation_date = 今日 AND end_time >= start_time AND start_time <= 現在時刻 AND end_time > 現在時刻

パターン2: 深夜跨ぎ・当日側（例: 23:00-01:00 で現在 23:30）
  reservation_date = 今日 AND end_time < start_time AND start_time <= 現在時刻

パターン3: 深夜跨ぎ・翌日側（例: 23:00-01:00 で現在 00:30）
  reservation_date = 昨日 AND end_time < start_time AND 現在時刻 < end_time
```

**PHP側で日時取得**: `$now = new DateTime('now', $timezone)` でPHP側の現在時刻を使用し、MySQLの `CURDATE()/CURTIME()` との不一致を防止。

**セキュリティ**: `Referrer-Policy: strict-origin-when-cross-origin` ヘッダーを設定し、トークン/ルームコードの外部漏洩を防止。

### 2.3 延長可能時間の計算ロジック

**関数**: `calculateAvailableExtension($salesAreaId, $storeId, $startTime, $endTime, $reservationDate)`
**ファイル**: `/public_html/includes/reservation_helpers.php`

**返り値**: `['available_minutes' => int, 'reason' => string|null, 'max_end_time' => string|null, 'is_next_day' => bool]`

**計算ロジック**:

1. **次の予約を検索**:
   - 深夜跨ぎでない場合: 同日で現在の終了後に開始する予約を検索
   - 見つからない場合、または深夜跨ぎの場合: 翌日の予約も検索
   - 次の予約がある場合: `maxEndTime = 次の予約開始時刻 - 清掃時間(CLEANING_TIME_MINUTES)`

2. **閉店時間チェック**（24時間営業でない場合）:
   - 店舗の `closing_time` を取得
   - 深夜営業判定: `closing_time < opening_time` なら閉店は翌日
   - 閉店が既に終了時刻より前なら、さらに翌日に補正
   - 次の予約制限と閉店制限の早い方を採用

3. **延長可能時間を算出**:
   - 制限なし（24時間営業 + 次の予約なし）: `MAX_EXTENSION_HOURS * 60` 分
   - 制限あり: `maxEndTime - endDateTime`（分数に変換）
   - `MAX_EXTENSION_HOURS` で上限キャップ

**選択肢生成**: `getExtensionOptions($availableMinutes)`

| 延長時間 | ラベル | 条件 |
|---------|--------|------|
| 30分 | 30分 | available_minutes >= 30 |
| 60分 | 1時間 | available_minutes >= 60 |
| 90分 | 1時間30分 | available_minutes >= 90 |
| 120分 | 2時間 | available_minutes >= 120 |

**延長不可時のエラーメッセージ**:
- `reason = 'next_reservation'`: 「次のご予約があるため、これ以上延長できません。」
- `reason = 'closing_time'`: 「閉店時間のため、これ以上延長できません。」
- 30分未満の余裕あり: 「最大X分まで延長可能ですが、最小延長時間（30分）に満たないため申請できません。」
- その他: 「延長可能な時間がありません。」

### 2.4 清掃者への通知・回答

**申請時のLINE通知メッセージ**:
```
【延長リクエスト】

🏠 {店舗名}
📍 {営業区分名}

⏰ 現在の終了: {HH:MM}
➡️ 延長後: {HH:MM}{翌日なら「（翌日）」}
⏱️ 延長時間: {X}分

対応可能ですか？

▼ 回答はこちら
{APP_URL}/extend/respond?id={requestId}&token={responseToken}
```

**response_token**: `bin2hex(random_bytes(32))` = 64文字の16進数。`extension_requests.response_token` に保存。

**回答画面**: `/extend/respond?id={id}&token={token}`
**ファイル**: `/public_html/pages/extend/respond.php`

- リクエストID + response_token + status='pending' で認証
- 「対応OK」ボタン（accept）と「対応NG」ボタン（decline）を表示
- 回答はPOSTで送信（CSRF保護あり）

### 2.5 承諾時の処理（予約時間更新・料金加算）

清掃者が「対応OK」を選択した場合の処理:

1. **ステータスガード**: `status = 'pending'` の条件付きUPDATEで二重承諾を防止（`updatedRows === 0` なら例外）
2. **予約ロック**: `FOR UPDATE` で予約をロック
3. **延長可能時間を再検証**（TOCTOU対策）: 承諾時点で `calculateAvailableExtension()` を再実行
4. **終了時間の更新**:
   - 深夜跨ぎ対応: `end_time < start_time` なら翌日基準で計算
   - `reservations.end_time` を新終了時刻に更新
5. **料金の更新**:
   - `extension_price += calculateExtensionPrice(extensionMinutes)`
   - `total_price = base_price + extension_price`
6. **清掃案件の更新**:
   - `cleaning_jobs.scheduled_at` を新しい終了時刻に更新（清掃開始時刻 = 予約終了時刻）
   - **報酬は変更しない**
7. **延長履歴の記録**: `extensions` テーブルに `additional_reward = 0`, `status = 'approved'` で記録

### 2.6 拒否時の処理（急募トリガー）

清掃者が「対応NG」を選択した場合の処理:

1. **担当者解除**: `cleaning_jobs.assigned_cleaner_id = NULL`
2. **ステータス変更**: `cleaning_jobs.status = 'recruiting'`
3. **急募フラグON**: `cleaning_jobs.is_urgent = 1`
4. **応募取り消し**: 元担当者の `job_applications.status = 'cancelled'`
5. **急募通知送信**: `sendJobNotifications($jobId, 'urgent')` で該当店舗の全清掃者にLINE通知

**注意**: 元の担当者は案件全体から外れ、新担当者が延長前後を含む全体を担当する。

### 2.7 レースコンディション対策

| タイミング | 対策 |
|-----------|------|
| 申請作成時 | `FOR UPDATE` で予約をロック → 延長可否を再計算 |
| 申請作成時 | `FOR UPDATE` で pending リクエストの重複チェック |
| 承諾時 | `status = 'pending'` 条件付きUPDATEで二重承諾防止 |
| 承諾時 | `FOR UPDATE` で予約をロック → 延長可否を再検証（TOCTOU対策） |
| 承諾時 | `FOR UPDATE` で清掃案件をロック |

---

## 3. 料金・報酬計算

### 3.1 延長料金（顧客側）

**関数**: `calculateExtensionPrice($minutes)` （`respond.php` 内で定義）

```
延長料金 = EXTENSION_PRICE_PER_HOUR × (延長分数 / 60)
```

**例**: 30分延長 = 1000 * 30 / 60 = **500円**、60分延長 = **1000円**

**累積計算**: 複数回延長した場合、`extension_price` に加算される。
- `reservations.extension_price = 既存extension_price + 今回の追加料金`
- `reservations.total_price = base_price + extension_price`

### 3.2 清掃報酬（清掃者側）

**延長時の清掃報酬は変更されない（常に0）**。

- `extensions.additional_reward = 0` で記録
- `cleaning_jobs.extension_reward` は更新しない
- 清掃開始時刻（`scheduled_at`）が後ろにずれるのみ

### 3.3 定数一覧

| 定数名 | 値 | 説明 | 定義場所 |
|--------|-----|------|----------|
| `EXTENSION_PRICE_PER_HOUR` | 1,000円 | 延長1時間あたりの顧客料金 | config.php:114 |
| `MAX_EXTENSION_HOURS` | 5.0 | 最大延長時間（時間） | config.php:115 |
| `CLEANING_TIME_MINUTES` | 60 | 清掃時間（分）- 次の予約までの必要間隔 | config.php:116 |
| `DEFAULT_HOURLY_RATE` | 2,500円 | 営業区分に時間単価が未設定時のフォールバック | config.php:119 |
| `DEFAULT_CLEANING_REWARD` | 2,000円 | 店舗にbase_rewardが未設定時のフォールバック | config.php:113 |
| `DEFAULT_CAPACITY` | 4名 | デフォルト定員 | config.php:120 |
| `MAX_BOOKING_DURATION_HOURS` | 8時間 | 最大予約時間 | config.php:121 |
| `MIN_BOOKING_DURATION_HOURS` | 1時間 | 最小予約時間 | config.php:122 |

---

## 4. 鍵番号管理

### 4.1 鍵一覧画面

**URL**: `/keys`
**ファイル**: `/public_html/pages/keys/list.php`

**表示カラム**:
| カラム | 説明 |
|--------|------|
| 鍵番号 | `keys.key_number`（太字表示） |
| 店舗 | `stores.name` |
| 営業区分 | `sales_areas.name` |
| ステータス | 有効（緑バッジ）/ 無効（グレーバッジ） |
| 本日の使用 | `key_assignments` から当日のカウント。使用中なら件数バッジ表示 |
| 備考 | `keys.notes` |
| 操作 | 詳細リンク |

**フィルタ**:
- 営業区分（ドロップダウン選択）
- ステータス（すべて / 有効 / 無効）

**ページネーション**: 20件/ページ。ソート順は `店舗名 → 営業区分名 → 鍵番号`。

**アクセス制御**: `getFilteredStoreIds()` でサイドバー選択の店舗に限定。

### 4.2 鍵新規登録

**URL**: `/keys/new`
**ファイル**: `/public_html/pages/keys/create.php`

**入力項目**:
| 項目 | 必須 | バリデーション |
|------|------|---------------|
| 営業区分 | ○ | アクセス可能な営業区分に限定 |
| 鍵番号 | ○ | 50文字以内、同一営業区分内で重複不可 |
| 備考 | - | 500文字以内 |

**登録時のデフォルト値**: `is_active = 1`

**重複チェック**: `SELECT id FROM keys WHERE sales_area_id = ? AND key_number = ? AND deleted_at IS NULL`

### 4.3 鍵詳細・編集

**URL**: `/keys/{id}`
**ファイル**: `/public_html/pages/keys/detail.php`

**表示セクション**:
1. **基本情報**: 鍵番号、店舗、営業区分、備考、登録日時
2. **本日の割当状況**: 当日の `key_assignments` をJOINで取得（時間、顧客名、ステータス）
3. **使用履歴**: 直近30件の割当履歴（予約日、時間、顧客名、ステータス）
4. **サイドバー**: ステータス変更ボタン（有効/無効切替）

**編集機能**: モーダルダイアログで鍵番号と備考を変更可能。重複チェック（自分以外）付き。

### 4.4 有効/無効切替

**アクション**: `toggle_active`

- **楽観ロック**: `UPDATE keys SET is_active = {new} WHERE id = ? AND is_active = {current}` で競合検出
- 競合時: 「他の操作と競合しました。ページを更新して再度お試しください。」

無効にする際は確認ダイアログ: 「この鍵を無効にしますか？新規予約への割当ができなくなります。」

### 4.5 使用履歴

**取得クエリ**: `key_assignments` JOIN `reservations` で直近30件を取得。
ソート順: `reservation_date DESC, start_time DESC`

**今日の割当**: 別途、当日の `reservation_date = TODAY` かつ `status NOT IN ('cancelled')` で取得。

### 4.6 自動割当ロジック（assignKeyToReservation）

**関数**: `assignKeyToReservation($reservationId, $salesAreaId, $date, $startTime, $endTime)`
**ファイル**: `/public_html/includes/reservation_helpers.php`

**処理フロー**:

1. 同一営業区分の有効な鍵から、同一時間帯に使用されていない鍵を検索
2. `FOR UPDATE` で悲観的ロック
3. 空き鍵がなければ `RuntimeException('空き鍵がありません')` をスロー
4. `key_assignments` テーブルに割当記録を挿入

**空き鍵判定SQL**:
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
      AND r.id != ?
      AND NOT (r.end_time <= ? OR r.start_time >= ?)
  )
ORDER BY k.id
LIMIT 1
FOR UPDATE
```

**選択方式**: `ORDER BY k.id LIMIT 1` でID順に最初の空き鍵を選択（spec仕様のランダム選択とは異なる。後述「乖離」参照）。

**関連関数**:

| 関数名 | 説明 |
|--------|------|
| `checkReservationConflict()` | 時間重複する使用中鍵数を取得（清掃時間も考慮、深夜跨ぎ対応） |
| `getAvailableKeyCount()` | 指定時間帯の空き鍵数を取得（総鍵数 - 使用中鍵数） |

---

## 5. 固定者管理

### 5.1 一覧表示

**URL**: `/settings/fixed-cleaners`
**ファイル**: `/public_html/pages/settings/fixed-cleaners.php`

**表示カラム**:
| カラム | 説明 |
|--------|------|
| 優先度 | 数字入力（1-99）。変更時にonchangeで自動submit |
| 清掃者 | 名前（太字）+ 休止中バッジ（cleaners.is_active=0の場合）+ 電話番号 |
| 店舗 | stores.name |
| ステータス | 有効（緑バッジ）/ 無効（グレーバッジ） |
| 操作 | 有効化/無効化ボタン + 削除ボタン |

**ソート順**: 店舗名 → 優先度 → 清掃者名

**アクセス制御**: `getFilteredStoreIds()` でサイドバー選択の店舗に限定。単一店舗選択時は自動でその店舗を対象にする。

### 5.2 追加（重複防止）

**追加フォーム**（サイドバーカード）:
| 項目 | 必須 | 説明 |
|------|------|------|
| 店舗 | ○ | ドロップダウン選択 |
| 清掃者 | ○ | 店舗選択後にAPIで動的取得（`/api/settings/available-cleaners`） |
| 優先順位 | ○ | 1-99の数値（デフォルト: 1）。小さい数字ほど優先度が高い |

**バリデーション**:
- 清掃者が該当店舗に対応しているか（`cleaner_stores` テーブルで確認）
- 清掃者がアクティブか（`cleaners.is_active = 1 AND deleted_at IS NULL`）

**重複防止**: トランザクション + `FOR UPDATE` でロック付き重複チェック:
```sql
SELECT id FROM fixed_cleaners
WHERE store_id = ? AND cleaner_id = ? AND deleted_at IS NULL
FOR UPDATE
```

**清掃者リスト動的取得**: 店舗選択時にJavaScriptで `/api/settings/available-cleaners?store_id={id}` をfetch。既に固定者登録済みの清掃者は除外される:
```sql
AND c.id NOT IN (
  SELECT cleaner_id FROM fixed_cleaners
  WHERE store_id = ? AND deleted_at IS NULL
)
```

### 5.3 優先順位設定

**アクション**: `update_priority`

- 数値入力フィールドの `onchange` で自動submit（UIの即時反映）
- 範囲制限: `max(1, min(99, $priority))`
- アクセス権チェック: 該当固定者レコードの `store_id` がアクセス可能か検証

### 5.4 有効/無効切替

**アクション**: `toggle_active`

- 楽観ロック: `WHERE id = ? AND is_active = ?`（現在値を条件に含める）
- 切替後にフラッシュメッセージ表示

### 5.5 固定者通知ロジック

**関数**: `sendJobNotifications($jobId, 'fixed')`
**ファイル**: `/public_html/includes/notification_helpers.php`

**固定者通知フロー（`type = 'fixed'`）**:

1. 該当店舗の有効な固定者を `priority` 順で **1件** 取得（`LIMIT 1`）
2. 応募トークンを生成（`generateApplicationToken()`, 有効期限30分）
3. LINE通知を送信（専用メッセージ「{名前}さん専用のご案内です」）
4. `notification_logs` テーブルに記録（`type = 'fixed'`, `response = 'pending'`）
5. `cleaning_jobs.notification_status = 'fixed_waiting'`、`fixed_notification_sent_at` を更新

**タイムアウト処理**: `processFixedCleanerTimeout($timeoutMinutes = 30)`

1. `notification_status = 'fixed_waiting'` かつ `fixed_notification_sent_at` から指定時間経過した案件を検索
2. `getNextFixedCleaner()` で次の固定者を取得（既に通知済みの固定者を `notification_logs` で除外）
3. 次の固定者がいれば通知送信、いなければ公募開始（`sendJobNotifications($jobId, 'normal')`）

**公募通知（`type = 'normal'` or `'urgent'`）**:
- 該当店舗の全アクティブ清掃者に一斉送信
- 急募の場合は「【急募】」プレフィックスと「お急ぎの案件です」注記を追加

### 5.6 削除

**アクション**: `delete`

- 論理削除: `deleted_at = NOW()`
- 確認ダイアログ付き
- アクセス権チェック済み

---

## 6. 主要関数一覧

### 延長関連

| 関数名 | ファイル | 説明 |
|--------|---------|------|
| `calculateAvailableExtension()` | reservation_helpers.php | 延長可能時間を計算（次予約・閉店時間考慮） |
| `getExtensionOptions()` | reservation_helpers.php | 延長可能な選択肢配列を返す（30/60/90/120分） |
| `calculateExtensionPrice()` | extend/respond.php | 延長料金を計算（`EXTENSION_PRICE_PER_HOUR * minutes / 60`） |

### 鍵関連

| 関数名 | ファイル | 説明 |
|--------|---------|------|
| `assignKeyToReservation()` | reservation_helpers.php | 予約に鍵を自動割当（FOR UPDATE ロック） |
| `checkReservationConflict()` | reservation_helpers.php | 時間重複する使用中鍵数を取得（清掃時間考慮） |
| `getAvailableKeyCount()` | reservation_helpers.php | 指定時間帯の空き鍵数を取得 |
| `getCleaningDuration()` | reservation_helpers.php | 清掃時間を取得（sales_areas優先、キャッシュ付き） |

### 通知関連

| 関数名 | ファイル | 説明 |
|--------|---------|------|
| `sendJobNotifications()` | notification_helpers.php | 案件通知を送信（fixed/normal/urgent対応） |
| `sendLineNotification()` | notification_helpers.php | LINE Push API でメッセージ送信（リトライ付き） |
| `getNextFixedCleaner()` | notification_helpers.php | 次の未通知固定者を取得 |
| `processFixedCleanerTimeout()` | notification_helpers.php | 固定者タイムアウト処理（cron用） |
| `generateApplicationToken()` | job_helpers.php | 応募用トークンを生成 |
| `getApplicationUrl()` | job_helpers.php | 応募URLを生成 |

### 案件関連

| 関数名 | ファイル | 説明 |
|--------|---------|------|
| `createCleaningJobForReservation()` | job_helpers.php | 予約から清掃案件を生成（深夜跨ぎ対応） |
| `createPaymentForJob()` | job_helpers.php | 案件完了時の支払いレコード生成（FOR UPDATE ロック） |
| `completeCleaningJob()` | job_helpers.php | 清掃完了処理（冪等性確保、早期完了ガード） |

### 汎用ヘルパー

| 関数名 | ファイル | 説明 |
|--------|---------|------|
| `getAccessibleSalesAreas()` | helpers.php | アクセス可能な営業区分一覧を取得 |
| `findSalesArea()` | helpers.php | 営業区分配列から指定IDを検索 |
| `calculatePagination()` | helpers.php | ページネーション計算 |
| `getPagedList()` | helpers.php | ページネーション付きリスト取得の定型処理 |

---

## 7. spec仕様書との乖離

以下は、spec仕様書の記述と実装コードの間に見つかった差異をまとめたものである。

### 7.1 延長機能の乖離

| # | spec記述 | 実装 | 影響度 | 備考 |
|---|---------|------|--------|------|
| 1 | 延長フォームのURL形式は `/extend/{store_token}` | `/extend/room/{room_code}` + `/extend/?token={token}` の2方式 | 低 | extension_room_url.md で更新済み。extension.md 側が古い |
| 2 | 入力項目に「現在の予約番号」がある | 実装では予約番号入力なし（room_codeまたはtokenで自動特定） | 中 | spec(extension.md)が古い。部屋コード方式導入で不要になった |
| 3 | 延長時間の選択肢に「2時間」あり | 実装では30分/60分/90分/120分の4段階 | 低 | 実装の方がより細かい選択肢を提供 |
| 4 | 通知先が「オーナー＆担当清掃者」 | 実装は担当清掃者のみにLINE通知 | 中 | specではオーナー通知も記載あるが、実装にはない |
| 5 | フロー図に「利用者通知（延長承認の連絡）」がある | 実装では承諾後の利用者通知なし | 中 | 利用者はフォーム画面上でのみ申請完了を確認 |
| 6 | room_code は「16文字のランダム英数字」 | 実装のバリデーションは「8-32文字の英数字」 | 低 | 実装が柔軟な範囲。既存データは8文字（MD5先頭8文字） |
| 7 | spec（extension_room_url.md）の予約特定SQLは `CURDATE()/CURTIME()` 使用 | 実装はPHP側で日時取得し、パラメータバインド | 低 | 実装の方が正確（MySQL/PHPのタイムゾーン不一致防止） |
| 8 | spec（extension_room_url.md）に深夜跨ぎ対応の記載なし | 実装は3パターンで深夜跨ぎに対応 | 中 | spec未記載だが実装済み |

### 7.2 鍵管理の乖離

| # | spec記述 | 実装 | 影響度 | 備考 |
|---|---------|------|--------|------|
| 1 | 空き鍵を「ランダムに1つ選択」 | `ORDER BY k.id LIMIT 1` でID順に最初の1件 | 低 | specはランダム、実装はID順。運用上の大きな差異はなし |
| 2 | 割当履歴に「操作者」カラムあり | `key_assignments` テーブルに操作者カラムなし | 低 | 手動割当機能が未実装のため不要 |
| 3 | 「再送履歴」機能の記載あり | 実装なし | 低 | メール再送機能自体が未実装 |
| 4 | ガント表示による割当状況確認画面 | 実装なし | 中 | 鍵詳細画面に本日の割当一覧はあるが、ガントチャートはない |
| 5 | 競合時の「リトライ（最大3回）」 | 実装にリトライロジックなし | 低 | `FOR UPDATE` によるロックで代替 |
| 6 | 空き鍵判定の時間重複チェックSQL | 実装は `NOT (r.end_time <= ? OR r.start_time >= ?)` で判定 | 低 | spec側のSQLは簡略化版、実装は否定条件で正確 |

### 7.3 固定者管理の乖離

| # | spec記述 | 実装 | 影響度 | 備考 |
|---|---------|------|--------|------|
| 1 | 固定者NG → 「次の固定者へ」の順次通知 | 実装はタイムアウト（30分）で次の固定者へ | 低 | NGの明示的応答ではなくタイムアウトベース |
| 2 | 固定者の応答記録に `response` カラム（ok/ng/timeout） | `notification_logs.response` は `'pending'` で記録、明示的なok/ng更新ロジックが限定的 | 中 | タイムアウト判定は `fixed_notification_sent_at` ベース |
| 3 | CMS画面に「編集」操作の記載 | 実装は「優先順位変更」「有効/無効切替」「削除」の3操作 | 低 | specの「編集」は実質的に優先順位変更に相当 |
| 4 | 急募条件に「未充足: 清掃予定の2時間前でも未割当」 | 実装にこの自動急募ロジックなし（cron側で要確認） | 中 | cron/配下に実装がある可能性 |
| 5 | 固定者通知は「固定者あり→1人目に通知→NG→2人目→...→全員NG→公募」 | 実装は「LIMIT 1で最優先の1人に通知→30分タイムアウト→次→...→全員通知済み→公募」 | 低 | 明示的なNG回答ではなくタイムアウト方式 |

### 7.4 料金関連の乖離

| # | spec記述 | 実装 | 影響度 | 備考 |
|---|---------|------|--------|------|
| 1 | pricing.md は包括的に整理済み | 実装と一致 | - | pricing.md が最新の正確なspec |
| 2 | extension.md に `responder_cleaner_id` カラムあり | 実装の `extension_requests` テーブルにはこのカラムなし | 低 | 回答者は `response_token` で認証、IDは保存していない |

### 7.5 総合所見

- **pricing.md** と **extension_room_url.md** は比較的最新で、実装との整合性が高い
- **extension.md** は部屋コード方式導入以前の古い記述が残っており、更新が必要
- **key_management.md** のランダム選択とガント表示は未実装
- **fixed_cleaner.md** のNG回答ベースのフローは、実装ではタイムアウトベースに変更されている
- 深夜跨ぎ対応はspec側に十分な記載がないが、実装では徹底的に対応済み

---

*作成日: 2026-01-29*
*作成元: 実装コード精読 + spec仕様書比較*
