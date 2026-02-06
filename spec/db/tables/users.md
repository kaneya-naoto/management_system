# users テーブル

## 概要

CMSユーザー（管理者）を管理するテーブル

---

## テーブル定義

```sql
CREATE TABLE users (
  id BIGINT NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL,
  role ENUM('HQ', 'OWNER', 'STORE') NOT NULL DEFAULT 'STORE',
  status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## カラム詳細

| カラム | 型 | NULL | デフォルト | 説明 |
|--------|------|------|------------|------|
| id | BIGINT | NO | AUTO_INCREMENT | 主キー |
| email | VARCHAR(255) | NO | - | メールアドレス（ユニーク） |
| password | VARCHAR(255) | NO | - | パスワードハッシュ（bcrypt） |
| name | VARCHAR(100) | NO | - | 氏名 |
| role | ENUM | NO | 'STORE' | 権限ロール |
| status | ENUM | NO | 'active' | アカウント状態 |
| last_login_at | DATETIME | YES | NULL | 最終ログイン日時 |
| password_changed_at | DATETIME | YES | NULL | パスワード変更日時 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP | 更新日時 |
| deleted_at | DATETIME | YES | NULL | 論理削除日時 |

---

## ENUM定義

### role

| 値 | 日本語 | 説明 |
|----|--------|------|
| HQ | 統括本部 | 全店舗にアクセス可能 |
| OWNER | オーナー | 配下店舗にアクセス可能 |
| STORE | 店舗 | 自店舗のみ |

### status

| 値 | 日本語 | 説明 |
|----|--------|------|
| active | 有効 | ログイン可能 |
| inactive | 無効 | ログイン不可 |
| suspended | 停止 | 一時停止（管理者操作） |

---

## インデックス

| 名前 | カラム | 種別 |
|------|--------|------|
| PRIMARY | id | 主キー |
| uk_users_email | email | ユニーク |

---

## リレーション

| 参照先 | 関係 | 中間テーブル |
|--------|------|--------------|
| stores | N:N | user_stores |

---

## 備考

- パスワードはbcrypt (cost=12) でハッシュ化
- 論理削除時はdeleted_atにタイムスタンプを設定
- ソフトデリート対応のユニーク制約が必要な場合はactive_flag生成列を追加

---

*Last Updated: 2026-01-26*
