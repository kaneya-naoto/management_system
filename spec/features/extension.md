# 延長対応

## 概要

利用者が延長を希望した際の処理フロー。QRコードから延長フォームにアクセスし、担当清掃者に確認を取る。

---

## 延長フロー

```
[利用者] 部屋設置QRをスキャン（/extend/room/{room_code}）
    ↓
[システム] room_code + 現在日時で予約を自動特定
    ↓
[システム] 延長フォーム表示（選択肢: 30分/60分/90分/120分）
    ↓
[利用者] 延長時間を選択・送信
    ↓
[システム] extension_requestsに記録
    ↓
[システム] 担当清掃者にLINE通知（response_token付きURL）
    ↓
[担当者] 回答URLを開いて YES / NO を回答
    ↓
┌─ YES ─→ [案件更新] 清掃時間を更新（報酬は変更なし）
│              ↓
│         [利用者通知] 延長承認メール
│
└─ NO ──→ [担当者解除] 元の担当者を解除
              ↓
         [急募開始] 急募フラグON・全清掃者に通知
              ↓
         [利用者通知] 延長不可メール
```

---

## 延長フォーム

### アクセス方法

部屋に設置されたQRコード → `https://example.com/extend/room/{room_code}`

※ 従来のトークン方式 `/extend/?token={extension_token}` も後方互換で維持。
詳細は [延長機能 部屋ベースURL仕様書](extension_room_url.md) を参照。

### 入力項目

| 項目 | 必須 | 説明 |
|------|------|------|
| 延長時間 | ○ | 30分/60分/90分/120分から選択 |

※ 予約番号の入力は不要。room_code + 現在日時から自動的に「現在利用中の予約」を特定する。

### バリデーション

| ルール | エラーメッセージ |
|--------|------------------|
| 無効なroom_code/token | 無効なアクセスです |
| 現在利用中の予約なし | 現在利用中の予約がありません |
| 既に延長申請中 | 既に延長申請中です。担当者からの回答をお待ちください。 |
| 次の予約あり（延長不可） | 次のご予約があるため、これ以上延長できません。 |
| 閉店時間（延長不可） | 閉店時間のため、これ以上延長できません。 |
| 延長可能時間不足（30分未満） | 最大N分まで延長可能ですが、最小延長時間（30分）に満たないため申請できません。 |

---

## 予約自動特定ロジック（深夜跨ぎ対応）

room_code + 現在日時から「現在利用中の予約」を特定する。日時取得はPHP側で行い、MySQLのCURDATE()/CURTIME()との不一致を防止する。

### 3パターンの判定条件

| パターン | 条件 | 例 |
|----------|------|-----|
| 通常（日跨ぎなし） | 今日の予約、end_time >= start_time、start_time <= 現在 かつ end_time > 現在 | 10:00-18:00の予約で14:00にアクセス |
| 深夜跨ぎ・当日側 | 今日の予約、end_time < start_time、start_time <= 現在 | 23:00-01:00の予約で23:30にアクセス |
| 深夜跨ぎ・翌日側 | 昨日の予約、end_time < start_time、現在 < end_time | 23:00-01:00の予約で00:30にアクセス |

---

## 担当者への通知

### 通知メッセージ（LINE）

```
延長依頼が来ています！

📍 場所: {sales_area_name}
📅 元の終了: {original_end_time}
⏱️ 延長希望: {extension_hours}時間

対応可能ですか？

▼ 回答はこちら
{response_url}
```

### 回答画面

回答URLは `response_token` で認証される。清掃者IDではなく、`extension_requests.response_token`（64文字のhex、`random_bytes(32)`で生成）でリクエストを特定・認証する。

URL形式: `/extend/respond?id={request_id}&token={response_token}`

| ボタン | 処理 |
|--------|------|
| 対応OK（accept） | 延長承認 |
| 対応NG（decline） | 急募トリガー |

---

## 延長承認時の処理

### 案件更新

```php
// cleaning_jobsを更新（報酬は変更しない）
$jobRepository->update($jobId, [
    'scheduled_at' => $newScheduledAt,  // 清掃時間を後ろにずらす
]);

// extensionsテーブルに記録
$extensionRepository->create([
    'job_id' => $jobId,
    'extension_hours' => $hours,
    'requested_at' => now(),
    'approved_at' => now(),
    'status' => 'approved'
]);
```

### 予約更新

```php
// reservationsのend_timeを更新
$reservationRepository->update($reservationId, [
    'end_time' => $newEndTime
]);
```

### 延長時の報酬について

延長時、**清掃者への報酬は変更されない**。
作業開始時間が後ろにずれるのみで、追加報酬は発生しない。

（お客様への延長料金は請求されるが、清掃者報酬には反映されない）

---

## 延長料金（お客様向け）

延長時のお客様への請求は以下の計算式で算出される。

### 計算式

```
延長料金 = 時間単価 × 延長時間（時間単位）
```

※ 清掃者への報酬は延長時に変更されない（延長報酬は加算されない）

---

## 担当者が拒否（NO）した場合

### 急募フロー

```
[担当者NO]
    ↓
[担当者解除] assigned_cleaner_id = NULL
    ↓
[ステータス変更] status = 'recruiting'
    ↓
[急募フラグON] is_urgent = 1
    ↓
[応募取り消し] 元担当者の応募を 'cancelled' に
    ↓
[急募通知] 該当店舗の全清掃者へLINE通知
    ↓
[応募受付] 先着で新担当者を決定
```

### 処理詳細

1. **元の担当者を解除**: `cleaning_jobs.assigned_cleaner_id = NULL`
2. **ステータスを「募集中」に変更**: `cleaning_jobs.status = 'recruiting'`
3. **急募フラグを設定**: `cleaning_jobs.is_urgent = 1`
4. **元担当者の応募を取り消し**: `job_applications.status = 'cancelled'`
5. **急募通知を送信**: `sendJobNotifications($jobId, 'urgent')`

### 注意点

- 元の担当者は案件全体から外れる
- 案件全体を新担当者が担当する（延長前後を含む）
- 急募通知は該当店舗に紐づく全清掃者に送信される

---

## テーブル定義

### extension_requests テーブル（延長申請・回答）

延長の申請と回答を管理するメインテーブル。`response_token` で清掃者の回答を認証する。

```sql
CREATE TABLE IF NOT EXISTS extension_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    job_id INT,
    requested_minutes INT NOT NULL COMMENT '延長時間（分）',
    status ENUM('pending', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'pending',
    response_token VARCHAR(64) COMMENT '回答用トークン（bin2hex(random_bytes(32))）',
    requested_at DATETIME NOT NULL,
    responded_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE SET NULL,
    INDEX idx_reservation (reservation_id),
    INDEX idx_status (status),
    INDEX idx_response_token (response_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### extensions テーブル（延長履歴記録）

承認された延長を統一的に履歴管理するテーブル。`responder_cleaner_id` カラムは持たず、`extension_requests` の `response_token` で回答者を追跡する。

```sql
CREATE TABLE extensions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  job_id BIGINT NOT NULL,
  extension_hours DECIMAL(3,1) NOT NULL,
  additional_reward INT NOT NULL DEFAULT 0,
  requested_at DATETIME NOT NULL,
  approved_at DATETIME NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_extensions_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**注意**: 延長承認時の清掃者特定は `extension_requests.response_token` を用いて認証する設計であり、`extensions` テーブルに `responder_cleaner_id` カラムは不要。

---

## 関連ドキュメント

- [清掃案件管理](cleaning_job.md)
- [固定者・急募](fixed_cleaner.md)
- [支払い管理](payment.md)

---

*Last Updated: 2026-01-29*
