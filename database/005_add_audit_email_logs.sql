-- 操作ログ・メール送信履歴テーブル追加
-- Created: 2026-01-26

-- 操作ログ（監査ログ）
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id BIGINT NULL,
  action VARCHAR(100) NOT NULL COMMENT '操作種別（create/update/delete等）',
  target_type VARCHAR(50) NOT NULL COMMENT '対象エンティティ（reservation/job等）',
  target_id BIGINT NULL COMMENT '対象ID',
  old_value JSON NULL COMMENT '変更前の値',
  new_value JSON NULL COMMENT '変更後の値',
  ip_address VARCHAR(45) NULL COMMENT '操作元IPアドレス',
  user_agent TEXT NULL COMMENT 'ブラウザ情報',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メール送信履歴
CREATE TABLE IF NOT EXISTS email_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  to_email VARCHAR(255) NOT NULL COMMENT '送信先メールアドレス',
  subject VARCHAR(255) NOT NULL COMMENT '件名',
  body TEXT NOT NULL COMMENT '本文',
  type VARCHAR(50) NOT NULL COMMENT 'メール種別（reservation_confirm/password_reset等）',
  reservation_id BIGINT NULL COMMENT '関連予約ID',
  status ENUM('sent', 'failed', 'bounced') NOT NULL DEFAULT 'sent' COMMENT '送信ステータス',
  sent_at DATETIME NOT NULL COMMENT '送信日時',
  error_message TEXT NULL COMMENT 'エラーメッセージ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_to (to_email),
  KEY idx_email_reservation (reservation_id),
  KEY idx_email_type (type),
  KEY idx_email_sent (sent_at),
  CONSTRAINT fk_email_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 清掃者登録状態管理用カラム追加
ALTER TABLE cleaners
  ADD COLUMN registration_status ENUM('pending', 'profile_done', 'stores_done', 'completed') NOT NULL DEFAULT 'completed' COMMENT '登録状態' AFTER is_active,
  ADD COLUMN registration_token VARCHAR(64) NULL COMMENT '登録用トークン' AFTER registration_status,
  ADD COLUMN registration_token_expires_at DATETIME NULL COMMENT 'トークン有効期限' AFTER registration_token;

-- 登録トークンインデックス
ALTER TABLE cleaners ADD KEY idx_cleaner_registration_token (registration_token);

-- notification_logsに確定通知タイプ追加（ENUMの拡張）
ALTER TABLE notification_logs
  MODIFY COLUMN type ENUM('normal', 'urgent', 'fixed', 'confirmed', 'extension', 'cancel') NOT NULL;

-- notification_logsにpendingレスポンス追加（ENUMの拡張）
ALTER TABLE notification_logs
  MODIFY COLUMN response ENUM('ok', 'ng', 'timeout', 'none', 'pending') NULL;

-- ipassコードの一意性制約（衝突防止）
ALTER TABLE cleaners ADD UNIQUE KEY idx_cleaner_ipass_unique (ipass_code);
