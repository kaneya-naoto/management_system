# DB設計方針

## 基本方針

### 論理削除

- **必要なテーブルのみ** `deleted_at` カラムを持つ（全テーブル一律ではない）
- 論理削除が必要なテーブル: `users`, `stores`, `sales_areas`, `keys`, `cleaners`, `reservations`, `cleaning_jobs` 等のマスタ・トランザクション系
- ログ系・中間テーブル（`key_assignments`, `extension_requests`, `daily_notification_logs` 等）には `deleted_at` を設けない
- 削除時は `deleted_at = CURRENT_TIMESTAMP` で更新

```sql
-- 論理削除の例
UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = 1;

-- 取得時は deleted_at IS NULL で絞り込み
SELECT * FROM users WHERE deleted_at IS NULL;
```

### ソフトデリート用ユニーク制約

emailなどのユニーク制約は、論理削除を考慮した設計が必要：

```sql
-- active_flag 生成列を使用
ALTER TABLE users ADD COLUMN active_flag TINYINT
  GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED;

-- ユニーク制約（削除済みは除外）
CREATE UNIQUE INDEX uk_users_email_active ON users(email, active_flag);
```

---

## 日時管理

### タイムゾーン

- 全てJST（Asia/Tokyo）で保存
- DATETIME型を使用（TIMESTAMP型は使用しない）

### 標準カラム

マスタ・トランザクション系テーブルには以下のカラムを設置：

| カラム | 型 | 説明 | 必須 |
|--------|------|------|------|
| `created_at` | DATETIME | 作成日時 | 全テーブル |
| `updated_at` | DATETIME | 更新日時（ON UPDATE CURRENT_TIMESTAMP） | マスタ・トランザクション系 |
| `deleted_at` | DATETIME NULL | 論理削除日時 | 論理削除が必要なテーブルのみ |

```sql
-- マスタ・トランザクション系
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
deleted_at DATETIME NULL

-- ログ系（deleted_at, updated_at 不要な場合）
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
```

---

## ENUM型の使用

### ステータス系はENUM型で厳密管理

```sql
-- 清掃案件ステータス
status ENUM('unassigned', 'recruiting', 'assigned', 'completed', 'paid') NOT NULL DEFAULT 'unassigned'

-- 予約ステータス
status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending'

-- ユーザーロール
role ENUM('HQ', 'OWNER', 'STORE') NOT NULL DEFAULT 'STORE'
```

### ENUMの利点

- 不正な値の挿入を防止
- アプリケーション側でのバリデーションと二重チェック
- ドキュメントとしての役割

---

## JSON型の使用

### 動的設定はJSON型で対応

頻繁に変更される設定や、スキーマが流動的なデータには `settings` カラム（JSON型）を使用：

```sql
settings JSON NULL
```

### 使用例

```sql
-- 店舗の営業時間設定
UPDATE stores SET settings = JSON_SET(
  COALESCE(settings, '{}'),
  '$.business_hours', JSON_OBJECT('open', '10:00', 'close', '22:00')
);

-- 取得
SELECT JSON_EXTRACT(settings, '$.business_hours.open') FROM stores;
```

---

## インデックス設計

### 基本ルール

1. 外部キーには必ずインデックス
2. 検索条件に頻繁に使用するカラムにインデックス
3. 複合インデックスはカーディナリティの高い順

### 命名規則

| 種別 | 命名 | 例 |
|------|------|-----|
| プライマリキー | `PRIMARY KEY` | - |
| ユニークキー | `uk_{テーブル}_{カラム}` | `uk_users_email` |
| インデックス | `idx_{テーブル}_{カラム}` | `idx_reservations_status` |
| 外部キー | `fk_{テーブル}_{参照先}` | `fk_reservations_store` |

---

## 外部キー制約

### 基本方針

- 参照整合性を維持するため外部キー制約を設定
- ON UPDATE CASCADE は許容
- **CASCADE DELETE の使用方針**:
  - マスタデータ（stores, users等）の外部キー: CASCADE DELETE は使用しない（論理削除のため）
  - ログ・履歴系テーブルの外部キー: 親レコード削除時にデータが不要になる場合は `ON DELETE CASCADE` を許容
  - 現在 CASCADE DELETE を使用しているテーブル: `extension_requests`（reservation_id）、`daily_notification_logs`（job_id, cleaner_id）

```sql
-- マスタ系: CASCADE DELETE なし
FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE CASCADE

-- ログ系: CASCADE DELETE 許容
FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE SET NULL
```

---

## 競合防止（悲観的ロック）

### 鍵割当での使用

同一時間帯に同一鍵番号が複数予約へ割り当てられることを防止：

```sql
-- トランザクション内で SELECT FOR UPDATE
START TRANSACTION;

SELECT * FROM keys
WHERE sales_area_id = ?
  AND id NOT IN (
    SELECT key_id FROM key_assignments
    WHERE start_time < ? AND end_time > ?
  )
FOR UPDATE;

-- 空いている鍵を割当
INSERT INTO key_assignments (...) VALUES (...);

COMMIT;
```

---

## CHECK制約

### 期間の整合性チェック

```sql
-- 開始時刻 < 終了時刻
CHECK (start_time < end_time)
```

### 金額の正値チェック

```sql
-- 報酬は0以上
CHECK (base_reward >= 0)
CHECK (extension_reward >= 0)
```

---

## Codexレビュー対応

### 指摘と対応

| 指摘 | 対応 |
|------|------|
| user_stores のPK設計が無効 | AUTO_INCREMENT PKに変更 |
| ソフトデリート用ユニーク制約未実装 | active_flag生成列追加 |
| cleaner_payments の重複防止 | UNIQUE KEY追加 |
| key_assignments の期間チェック | CHECK制約追加 |

---

## 関連ドキュメント

- [スキーマ概要](schema.md)
- [主要テーブル定義](tables/)
- [Codexレビュー結果](../review/codex_review.md)

---

*Last Updated: 2026-01-29*
