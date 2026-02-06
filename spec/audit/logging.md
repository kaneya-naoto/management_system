# 操作ログ・通知履歴

## 概要

システムの操作ログ、通知履歴、メール送信履歴を管理する機能

---

## ログ種別

| 種別 | テーブル | 説明 |
|------|----------|------|
| 操作ログ | audit_logs | CMS操作の履歴 |
| 通知履歴 | notification_logs | LINE通知の履歴 |
| メール履歴 | email_logs | メール送信の履歴 |

---

## 操作ログ（audit_logs）

### 記録対象

| 操作 | 説明 |
|------|------|
| 予約作成・編集・キャンセル | 予約に関する全操作 |
| 案件ステータス変更 | 清掃案件の状態遷移 |
| 担当者割当・変更 | 清掃者のアサイン |
| 鍵番号割当 | 鍵の割当操作 |
| 支払い処理 | 支払いステータス変更 |
| ユーザー作成・編集 | CMSユーザー管理 |
| 設定変更 | システム設定の変更 |

### テーブル定義

```sql
CREATE TABLE audit_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id BIGINT NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(50) NOT NULL,
  target_id BIGINT NULL,
  old_value JSON NULL,
  new_value JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### カラム詳細

| カラム | 説明 |
|--------|------|
| user_id | 操作者のユーザーID（NULL=システム） |
| action | 操作種別（create/update/delete等） |
| target_type | 対象エンティティ（reservation/job等） |
| target_id | 対象のID |
| old_value | 変更前の値（JSON） |
| new_value | 変更後の値（JSON） |
| ip_address | 操作元IPアドレス |
| user_agent | ブラウザ情報 |

### 例

```json
{
  "user_id": 1,
  "action": "update",
  "target_type": "reservation",
  "target_id": 123,
  "old_value": {"status": "pending"},
  "new_value": {"status": "confirmed"},
  "ip_address": "192.168.1.1"
}
```

---

## 通知履歴（notification_logs）

### 記録対象

| 通知種別 | 説明 |
|----------|------|
| 案件通知 | 新規案件の通知 |
| 急募通知 | 【急募】案件の通知 |
| 固定者通知 | 固定者への優先通知 |
| 確定通知 | 担当確定の連絡 |
| 延長確認 | 延長可否の確認 |

### テーブル定義

```sql
CREATE TABLE notification_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  cleaner_id BIGINT NOT NULL,
  job_id BIGINT NULL,
  type ENUM('normal', 'urgent', 'fixed', 'confirmed', 'extension') NOT NULL,
  message_id VARCHAR(255) NULL,
  sent_at DATETIME NOT NULL,
  response ENUM('ok', 'ng', 'timeout', 'none') NULL,
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notification_cleaner (cleaner_id),
  KEY idx_notification_job (job_id),
  KEY idx_notification_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### カラム詳細

| カラム | 説明 |
|--------|------|
| cleaner_id | 通知先清掃者 |
| job_id | 関連する案件ID |
| type | 通知種別 |
| message_id | LINE Message ID |
| sent_at | 送信日時 |
| response | 応答（ok/ng/timeout/none） |
| responded_at | 応答日時 |

---

## メール送信履歴（email_logs）

### 記録対象

| メール種別 | 説明 |
|------------|------|
| 予約確認 | 予約確認メール |
| パスワードリセット | リセットリンク送信 |
| キャンセル通知 | 予約キャンセル通知 |

### テーブル定義

```sql
CREATE TABLE email_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  to_email VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  type VARCHAR(50) NOT NULL,
  reservation_id BIGINT NULL,
  status ENUM('sent', 'failed', 'bounced') NOT NULL DEFAULT 'sent',
  sent_at DATETIME NOT NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_to (to_email),
  KEY idx_email_reservation (reservation_id),
  KEY idx_email_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## CMS画面

### 操作ログ一覧

| カラム | 説明 |
|--------|------|
| 日時 | 操作日時 |
| 操作者 | ユーザー名 |
| 操作 | 操作種別 |
| 対象 | エンティティと名前 |
| 詳細 | 変更内容 |

### フィルタ

| フィルタ | 選択肢 |
|----------|--------|
| 期間 | 日付範囲 |
| 操作者 | 全て / ユーザー名 |
| 操作種別 | 全て / 作成 / 更新 / 削除 |
| 対象 | 全て / 予約 / 案件 / 支払い |

---

## 保持期間

| ログ種別 | 保持期間 |
|----------|----------|
| 操作ログ | 3年 |
| 通知履歴 | 1年 |
| メール履歴 | 1年 |

※保持期間を超えたログは月次バッチで削除

---

## 関連ドキュメント

- [設計方針](../db/design_policy.md)
- [DBスキーマ](../db/schema.md)

---

*Last Updated: 2026-01-26*
