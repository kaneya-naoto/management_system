# reservations テーブル

## 概要

予約情報を管理するテーブル

---

## テーブル定義

```sql
CREATE TABLE reservations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  store_id BIGINT NOT NULL,
  sales_area_id BIGINT NOT NULL,
  customer_name VARCHAR(100) NOT NULL,
  customer_email VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(20) NOT NULL,
  reservation_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
  source ENUM('web', 'line', 'phone', 'direct') NOT NULL,
  payment_status ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid',
  payment_amount INT NULL,
  coupon_code VARCHAR(50) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_reservations_store (store_id),
  KEY idx_reservations_date (reservation_date),
  KEY idx_reservations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## カラム詳細

| カラム | 型 | NULL | デフォルト | 説明 |
|--------|------|------|------------|------|
| id | BIGINT | NO | AUTO_INCREMENT | 主キー |
| store_id | BIGINT | NO | - | 店舗ID（FK） |
| sales_area_id | BIGINT | NO | - | 営業区分ID（FK） |
| customer_name | VARCHAR(100) | NO | - | 顧客名 |
| customer_email | VARCHAR(255) | NO | - | 顧客メールアドレス |
| customer_phone | VARCHAR(20) | NO | - | 顧客電話番号 |
| reservation_date | DATE | NO | - | 予約日 |
| start_time | TIME | NO | - | 開始時刻 |
| end_time | TIME | NO | - | 終了時刻 |
| status | ENUM | NO | 'pending' | 予約ステータス |
| source | ENUM | NO | - | 予約経路 |
| payment_status | ENUM | NO | 'unpaid' | 決済状態 |
| payment_amount | INT | YES | NULL | 決済金額 |
| coupon_code | VARCHAR(50) | YES | NULL | 使用クーポン |
| notes | TEXT | YES | NULL | 備考 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP | 更新日時 |
| deleted_at | DATETIME | YES | NULL | 論理削除日時 |

---

## ENUM定義

### status

| 値 | 日本語 | 説明 |
|----|--------|------|
| pending | 保留 | 予約受付、未決済 |
| confirmed | 確定 | 決済完了 |
| cancelled | キャンセル | 取消済み |
| completed | 完了 | 利用終了 |

### source（予約経路）

| 値 | 日本語 | 説明 |
|----|--------|------|
| web | Webフォーム | サイト予約フォーム |
| line | LINE | LINEトーク予約 |
| phone | 電話 | 電話予約 |
| direct | 直接 | 店頭予約 |

### payment_status

| 値 | 日本語 | 説明 |
|----|--------|------|
| unpaid | 未払い | 決済前 |
| paid | 支払済 | 決済完了 |
| refunded | 返金済 | キャンセルによる返金 |

---

## インデックス

| 名前 | カラム | 種別 |
|------|--------|------|
| PRIMARY | id | 主キー |
| idx_reservations_store | store_id | 通常 |
| idx_reservations_date | reservation_date | 通常 |
| idx_reservations_status | status | 通常 |

---

## リレーション

| 参照先 | 関係 | 説明 |
|--------|------|------|
| stores | N:1 | 店舗 |
| sales_areas | N:1 | 営業区分 |
| cleaning_jobs | 1:1 | 清掃案件 |
| key_assignments | 1:N | 鍵割当 |

---

## 状態遷移

```
pending ──→ confirmed ──→ completed
   │             │
   └──→ cancelled ←──┘
```

---

## 備考

- 予約確定（confirmed）時に清掃案件と鍵割当を自動生成
- キャンセル時は論理削除ではなくstatusをcancelledに変更
- メールアドレスは予約完了後も編集可能（再送機能）

---

*Last Updated: 2026-01-26*
