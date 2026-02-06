# cleaning_jobs テーブル

## 概要

清掃案件を管理するテーブル。予約確定時に自動生成される。

---

## テーブル定義

```sql
CREATE TABLE cleaning_jobs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT NOT NULL,
  store_id BIGINT NOT NULL,
  sales_area_id BIGINT NOT NULL,
  scheduled_at DATETIME NOT NULL,
  status ENUM('unassigned', 'recruiting', 'assigned', 'completed', 'paid') NOT NULL DEFAULT 'unassigned',
  assigned_cleaner_id BIGINT NULL,
  base_reward INT NOT NULL DEFAULT 0,
  extension_reward INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cleaning_jobs_status (status),
  KEY idx_cleaning_jobs_scheduled (scheduled_at),
  KEY idx_cleaning_jobs_store (store_id),
  CHECK (base_reward >= 0),
  CHECK (extension_reward >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## カラム詳細

| カラム | 型 | NULL | デフォルト | 説明 |
|--------|------|------|------------|------|
| id | BIGINT | NO | AUTO_INCREMENT | 主キー |
| reservation_id | BIGINT | NO | - | 予約ID（FK） |
| store_id | BIGINT | NO | - | 店舗ID（FK） |
| sales_area_id | BIGINT | NO | - | 営業区分ID（FK） |
| scheduled_at | DATETIME | NO | - | 清掃予定日時 |
| status | ENUM | NO | 'unassigned' | 案件ステータス |
| assigned_cleaner_id | BIGINT | YES | NULL | 担当清掃者ID（FK） |
| base_reward | INT | NO | 0 | 基本報酬 |
| extension_reward | INT | NO | 0 | 延長報酬 |
| notes | TEXT | YES | NULL | 注意事項 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP | 更新日時 |
| deleted_at | DATETIME | YES | NULL | 論理削除日時 |

---

## ENUM定義

### status

| 値 | 日本語 | 説明 | 次の状態 |
|----|--------|------|----------|
| unassigned | 未割当 | 案件生成直後 | recruiting |
| recruiting | 募集中 | LINE公募中 | assigned |
| assigned | 確定 | 担当者決定 | completed |
| completed | 完了 | 清掃完了 | paid |
| paid | 支払済 | 報酬支払済 | - |

---

## インデックス

| 名前 | カラム | 種別 |
|------|--------|------|
| PRIMARY | id | 主キー |
| idx_cleaning_jobs_status | status | 通常 |
| idx_cleaning_jobs_scheduled | scheduled_at | 通常 |
| idx_cleaning_jobs_store | store_id | 通常 |

---

## リレーション

| 参照先 | 関係 | 説明 |
|--------|------|------|
| reservations | N:1 | 予約（1予約 = 1案件） |
| stores | N:1 | 店舗 |
| sales_areas | N:1 | 営業区分 |
| cleaners | N:1 | 担当清掃者 |
| job_applications | 1:N | 応募 |
| extensions | 1:N | 延長 |
| cleaner_payments | 1:1 | 支払い |

---

## 状態遷移図

```
unassigned ──→ recruiting ──→ assigned ──→ completed ──→ paid
     │              │              │
     │              └──────────────┘
     │              （応募あり時はassignedへ直接遷移可）
     │
     └──→ recruiting
          （固定者NGの場合）
```

---

## 報酬計算

### 基本報酬

`base_reward` は店舗設定または区分設定から取得

### 延長報酬

`extension_reward` は延長時間に応じて計算：

```
延長報酬 = 基本報酬 × 0.5 × 延長時間（時間単位）
```

### 総報酬

```
総報酬 = base_reward + extension_reward
```

---

## 備考

- 予約確定時に自動生成
- 1予約につき1案件
- 固定者がNGの場合、`recruiting` に遷移しLINE公募
- 【急募】条件：固定者NG / 延長NO / 未充足

---

*Last Updated: 2026-01-26*
