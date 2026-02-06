# cleaners テーブル

## 概要

清掃者（LINEで応募する清掃スタッフ）を管理するテーブル

---

## テーブル定義

```sql
CREATE TABLE cleaners (
  id BIGINT NOT NULL AUTO_INCREMENT,
  line_user_id VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  registered_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cleaners_line (line_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## カラム詳細

| カラム | 型 | NULL | デフォルト | 説明 |
|--------|------|------|------------|------|
| id | BIGINT | NO | AUTO_INCREMENT | 主キー |
| line_user_id | VARCHAR(255) | NO | - | LINE UserID（ユニーク） |
| name | VARCHAR(100) | NO | - | 氏名 |
| phone | VARCHAR(20) | YES | NULL | 電話番号 |
| is_active | TINYINT(1) | NO | 1 | 有効フラグ |
| registered_at | DATETIME | NO | - | 初回登録日時 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP | 更新日時 |
| deleted_at | DATETIME | YES | NULL | 論理削除日時 |

---

## インデックス

| 名前 | カラム | 種別 |
|------|--------|------|
| PRIMARY | id | 主キー |
| uk_cleaners_line | line_user_id | ユニーク |

---

## リレーション

| 参照先 | 関係 | 中間テーブル |
|--------|------|--------------|
| stores | N:N | cleaner_stores |
| cleaning_jobs | 1:N | - (assigned_cleaner_id) |
| job_applications | 1:N | - |
| fixed_cleaners | 1:N | - |

---

## 関連テーブル

### cleaner_stores（清掃者の対応店舗）

```sql
CREATE TABLE cleaner_stores (
  id BIGINT NOT NULL AUTO_INCREMENT,
  cleaner_id BIGINT NOT NULL,
  store_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cleaner_store (cleaner_id, store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### fixed_cleaners（固定者）

```sql
CREATE TABLE fixed_cleaners (
  id BIGINT NOT NULL AUTO_INCREMENT,
  store_id BIGINT NOT NULL,
  cleaner_id BIGINT NOT NULL,
  priority INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_fixed_cleaner (store_id, cleaner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## LINE連携フロー

### 初回登録

1. LINE友達追加
2. プロフィール登録（名前、電話番号）
3. 対応店舗選択
4. iPass発行
5. 応募画面へ

### 2回目以降

1. LINE UserIDで自動識別
2. 応募画面へ（またはiPass入力）

---

## 備考

- LINE UserIDは33文字の文字列（Uから始まる）
- is_activeがfalseの場合、案件通知を送信しない
- 固定者は店舗単位で登録（priorityで優先順位を管理）

---

*Last Updated: 2026-01-26*
