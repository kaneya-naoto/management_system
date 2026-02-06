-- 清掃者支払い管理テーブル
CREATE TABLE IF NOT EXISTS cleaner_payments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  job_id BIGINT NOT NULL,
  cleaner_id BIGINT NOT NULL,
  store_id BIGINT NOT NULL,
  base_amount INT NOT NULL DEFAULT 0,
  extension_amount INT NOT NULL DEFAULT 0,
  total_amount INT NOT NULL DEFAULT 0,
  status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
  paid_at DATE NULL,
  paid_by BIGINT NULL COMMENT '支払い処理した管理者ID',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id),
  KEY idx_payments_status (status),
  KEY idx_payments_cleaner (cleaner_id),
  KEY idx_payments_store (store_id),
  KEY idx_payments_paid_at (paid_at),
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id),
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id),
  FOREIGN KEY (store_id) REFERENCES stores(id),
  FOREIGN KEY (paid_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通知ログテーブル（LINE通知・応募記録用）
CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  cleaner_id BIGINT NOT NULL,
  job_id BIGINT NOT NULL,
  type ENUM('normal', 'urgent', 'fixed', 'extension') NOT NULL DEFAULT 'normal',
  message_id VARCHAR(255) NULL COMMENT 'LINE Message ID',
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  response ENUM('pending', 'ok', 'ng', 'timeout') NOT NULL DEFAULT 'pending',
  responded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notification_job (job_id),
  KEY idx_notification_cleaner (cleaner_id),
  KEY idx_notification_type (type),
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id),
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 応募トークンテーブル（LINE経由の応募URL用）
CREATE TABLE IF NOT EXISTS application_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT,
  token VARCHAR(64) NOT NULL,
  job_id BIGINT NOT NULL,
  cleaner_id BIGINT NULL COMMENT '固定者通知時はNULLでない、公募時はNULL',
  type ENUM('fixed', 'public') NOT NULL DEFAULT 'public',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_token (token),
  KEY idx_token_job (job_id),
  KEY idx_token_expires (expires_at),
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id),
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cleaning_jobs テーブルに急募フラグと通知状態を追加
ALTER TABLE cleaning_jobs
  ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 COMMENT '急募フラグ' AFTER job_type,
  ADD COLUMN notification_status ENUM('pending', 'fixed_waiting', 'public_recruiting', 'completed') NOT NULL DEFAULT 'pending' COMMENT '通知状態' AFTER is_urgent,
  ADD COLUMN fixed_notification_sent_at DATETIME NULL COMMENT '固定者通知送信日時' AFTER notification_status,
  ADD COLUMN public_notification_sent_at DATETIME NULL COMMENT '公募通知送信日時' AFTER fixed_notification_sent_at;

-- cleaning_jobs にインデックス追加
ALTER TABLE cleaning_jobs
  ADD KEY idx_jobs_urgent (is_urgent),
  ADD KEY idx_jobs_notification_status (notification_status);
