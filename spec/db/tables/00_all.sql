-- =============================================
-- カクレマ 新基幹システム - 本番環境用統合SQL
-- =============================================
-- 実行方法:
--   mysql -u xs151334_admin -p xs151334_kakurema < 00_all.sql
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- 1. 組織・ユーザー系
-- =============================================

-- オーナー
CREATE TABLE IF NOT EXISTS owners (
  id BIGINT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL COMMENT 'オーナー名',
  email VARCHAR(255) NOT NULL COMMENT 'メールアドレス',
  phone VARCHAR(20) NULL COMMENT '電話番号',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_owners_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='オーナー';

-- 店舗
CREATE TABLE IF NOT EXISTS stores (
  id BIGINT NOT NULL AUTO_INCREMENT,
  owner_id BIGINT NOT NULL COMMENT 'オーナーID',
  name VARCHAR(100) NOT NULL COMMENT '店舗名',
  code VARCHAR(20) NOT NULL COMMENT '店舗コード',
  address VARCHAR(255) NULL COMMENT '住所',
  phone VARCHAR(20) NULL COMMENT '電話番号',
  email VARCHAR(255) NULL COMMENT 'メールアドレス',
  base_reward INT NOT NULL DEFAULT 3000 COMMENT '基本報酬',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_stores_code (code),
  KEY idx_stores_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='店舗';

-- 内部営業区分
CREATE TABLE IF NOT EXISTS sales_areas (
  id BIGINT NOT NULL AUTO_INCREMENT,
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  name VARCHAR(100) NOT NULL COMMENT '区分名',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_sales_areas_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内部営業区分';

-- CMSユーザー
CREATE TABLE IF NOT EXISTS users (
  id BIGINT NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL COMMENT 'メールアドレス',
  password VARCHAR(255) NOT NULL COMMENT 'パスワードハッシュ',
  name VARCHAR(100) NOT NULL COMMENT '氏名',
  role ENUM('HQ', 'OWNER', 'STORE') NOT NULL DEFAULT 'STORE' COMMENT '権限ロール',
  owner_id BIGINT NULL COMMENT '所属オーナーID（OWNERロール用）',
  status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active' COMMENT 'アカウント状態',
  store_id BIGINT NULL COMMENT 'デフォルト店舗ID',
  last_login_at DATETIME NULL COMMENT '最終ログイン日時',
  password_changed_at DATETIME NULL COMMENT 'パスワード変更日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_status (status),
  KEY idx_users_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='CMSユーザー';

-- ユーザー×店舗紐付け
CREATE TABLE IF NOT EXISTS user_stores (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL COMMENT 'ユーザーID',
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_user_store (user_id, store_id),
  KEY idx_user_stores_user (user_id),
  KEY idx_user_stores_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ユーザー×店舗紐付け';

-- パスワードリセットトークン
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL COMMENT 'ユーザーID',
  token VARCHAR(255) NOT NULL COMMENT 'トークン',
  expires_at DATETIME NOT NULL COMMENT '有効期限',
  used_at DATETIME NULL COMMENT '使用日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_password_reset_token (token),
  KEY idx_password_reset_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='パスワードリセットトークン';

-- =============================================
-- 2. 予約・鍵系
-- =============================================

-- 鍵番号
CREATE TABLE IF NOT EXISTS `keys` (
  id BIGINT NOT NULL AUTO_INCREMENT,
  sales_area_id BIGINT NOT NULL COMMENT '営業区分ID',
  key_number VARCHAR(20) NOT NULL COMMENT '鍵番号',
  room_name VARCHAR(50) NULL COMMENT '部屋名',
  notes TEXT NULL COMMENT '備考',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_keys_area_number (sales_area_id, key_number),
  KEY idx_keys_area (sales_area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鍵番号';

-- 予約
CREATE TABLE IF NOT EXISTS reservations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  sales_area_id BIGINT NOT NULL COMMENT '営業区分ID',
  customer_name VARCHAR(100) NOT NULL COMMENT '顧客名',
  customer_email VARCHAR(255) NOT NULL COMMENT '顧客メールアドレス',
  customer_phone VARCHAR(20) NOT NULL COMMENT '顧客電話番号',
  num_people INT NOT NULL DEFAULT 1 COMMENT '利用人数',
  reservation_date DATE NOT NULL COMMENT '予約日',
  start_time TIME NOT NULL COMMENT '開始時刻',
  end_time TIME NOT NULL COMMENT '終了時刻',
  status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending' COMMENT '予約ステータス',
  source ENUM('web', 'line', 'phone', 'direct') NOT NULL DEFAULT 'web' COMMENT '予約経路',
  payment_status ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid' COMMENT '決済状態',
  coupon_code VARCHAR(50) NULL COMMENT '使用クーポン',
  base_price INT NOT NULL DEFAULT 0 COMMENT '基本料金',
  extension_price INT NOT NULL DEFAULT 0 COMMENT '延長料金',
  total_price INT NOT NULL DEFAULT 0 COMMENT '合計料金',
  notes TEXT NULL COMMENT '備考',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_reservations_store (store_id),
  KEY idx_reservations_area (sales_area_id),
  KEY idx_reservations_date (reservation_date),
  KEY idx_reservations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='予約';

-- 鍵割当
CREATE TABLE IF NOT EXISTS key_assignments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT NOT NULL COMMENT '予約ID',
  key_id BIGINT NOT NULL COMMENT '鍵ID',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '割当日時',
  returned_at DATETIME NULL COMMENT '返却日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_key_assignment (reservation_id, key_id),
  KEY idx_key_assignments_reservation (reservation_id),
  KEY idx_key_assignments_key (key_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鍵割当';

-- =============================================
-- 3. 清掃系
-- =============================================

-- 清掃者
CREATE TABLE IF NOT EXISTS cleaners (
  id BIGINT NOT NULL AUTO_INCREMENT,
  line_user_id VARCHAR(255) NOT NULL COMMENT 'LINE UserID',
  name VARCHAR(100) NOT NULL COMMENT '氏名',
  phone VARCHAR(20) NULL COMMENT '電話番号',
  ipass_code VARCHAR(10) NULL COMMENT 'iPassコード',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  registration_status ENUM('pending', 'profile_done', 'stores_done', 'completed') NOT NULL DEFAULT 'completed' COMMENT '登録状態',
  registration_token VARCHAR(64) NULL COMMENT '登録用トークン',
  registration_token_expires_at DATETIME NULL COMMENT 'トークン有効期限',
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '初回登録日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cleaners_line (line_user_id),
  UNIQUE KEY uk_cleaners_ipass (ipass_code),
  KEY idx_cleaner_registration_token (registration_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='清掃者';

-- 清掃者の対応店舗
CREATE TABLE IF NOT EXISTS cleaner_stores (
  id BIGINT NOT NULL AUTO_INCREMENT,
  cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cleaner_store (cleaner_id, store_id),
  KEY idx_cleaner_stores_cleaner (cleaner_id),
  KEY idx_cleaner_stores_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='清掃者の対応店舗';

-- 固定者
CREATE TABLE IF NOT EXISTS fixed_cleaners (
  id BIGINT NOT NULL AUTO_INCREMENT,
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
  priority INT NOT NULL DEFAULT 1 COMMENT '優先順位',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_fixed_cleaner (store_id, cleaner_id),
  KEY idx_fixed_cleaners_store (store_id),
  KEY idx_fixed_cleaners_cleaner (cleaner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='固定者';

-- 清掃案件
CREATE TABLE IF NOT EXISTS cleaning_jobs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT NOT NULL COMMENT '予約ID',
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  sales_area_id BIGINT NOT NULL COMMENT '営業区分ID',
  scheduled_at DATETIME NOT NULL COMMENT '清掃予定日時',
  status ENUM('unassigned', 'recruiting', 'assigned', 'completed', 'paid') NOT NULL DEFAULT 'unassigned' COMMENT '案件ステータス',
  job_type ENUM('regular', 'urgent') NOT NULL DEFAULT 'regular' COMMENT '案件種別',
  is_urgent TINYINT(1) NOT NULL DEFAULT 0 COMMENT '急募フラグ',
  notification_status ENUM('pending', 'fixed_waiting', 'public_recruiting', 'completed') NOT NULL DEFAULT 'pending' COMMENT '通知状態',
  fixed_notification_sent_at DATETIME NULL COMMENT '固定者通知送信日時',
  public_notification_sent_at DATETIME NULL COMMENT '公募通知送信日時',
  assigned_cleaner_id BIGINT NULL COMMENT '担当清掃者ID',
  base_reward INT NOT NULL DEFAULT 0 COMMENT '基本報酬',
  extension_reward INT NOT NULL DEFAULT 0 COMMENT '延長報酬',
  notes TEXT NULL COMMENT '注意事項',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cleaning_jobs_reservation (reservation_id),
  KEY idx_cleaning_jobs_store (store_id),
  KEY idx_cleaning_jobs_status (status),
  KEY idx_cleaning_jobs_scheduled (scheduled_at),
  KEY idx_cleaning_jobs_cleaner (assigned_cleaner_id),
  KEY idx_jobs_urgent (is_urgent),
  KEY idx_jobs_notification_status (notification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='清掃案件';

-- 案件応募
CREATE TABLE IF NOT EXISTS job_applications (
  id BIGINT NOT NULL AUTO_INCREMENT,
  job_id BIGINT NOT NULL COMMENT '案件ID',
  cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '応募日時',
  status ENUM('applied', 'accepted', 'rejected', 'cancelled') NOT NULL DEFAULT 'applied' COMMENT '応募ステータス',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_job_application (job_id, cleaner_id),
  KEY idx_job_applications_job (job_id),
  KEY idx_job_applications_cleaner (cleaner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='案件応募';

-- 延長
CREATE TABLE IF NOT EXISTS extensions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  job_id BIGINT NOT NULL COMMENT '案件ID',
  extension_hours DECIMAL(3,1) NOT NULL COMMENT '延長時間（時間単位）',
  additional_reward INT NOT NULL DEFAULT 0 COMMENT '追加報酬',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '依頼日時',
  approved_at DATETIME NULL COMMENT '承認日時',
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'ステータス',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_extensions_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='延長';

-- 清掃者支払い
CREATE TABLE IF NOT EXISTS cleaner_payments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  job_id BIGINT NOT NULL COMMENT '案件ID',
  cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
  store_id BIGINT NOT NULL COMMENT '店舗ID',
  base_amount INT NOT NULL DEFAULT 0 COMMENT '基本報酬',
  extension_amount INT NOT NULL DEFAULT 0 COMMENT '延長報酬',
  total_amount INT NOT NULL DEFAULT 0 COMMENT '合計金額',
  status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid' COMMENT '支払ステータス',
  paid_at DATE NULL COMMENT '支払日',
  paid_by BIGINT NULL COMMENT '支払い処理した管理者ID',
  notes TEXT NULL COMMENT '備考',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id),
  KEY idx_payments_status (status),
  KEY idx_payments_cleaner (cleaner_id),
  KEY idx_payments_store (store_id),
  KEY idx_payments_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='清掃者支払い';

-- =============================================
-- 4. 通知・トークン系
-- =============================================

-- 通知履歴（LINE）
CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
  job_id BIGINT NOT NULL COMMENT '案件ID',
  type ENUM('normal', 'urgent', 'fixed', 'confirmed', 'extension', 'cancel') NOT NULL DEFAULT 'normal' COMMENT '通知種別',
  message_id VARCHAR(255) NULL COMMENT 'LINE Message ID',
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '送信日時',
  response ENUM('ok', 'ng', 'timeout', 'none', 'pending') NULL DEFAULT 'pending' COMMENT '応答',
  responded_at DATETIME NULL COMMENT '応答日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notification_job (job_id),
  KEY idx_notification_cleaner (cleaner_id),
  KEY idx_notification_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知履歴';

-- 応募トークン
CREATE TABLE IF NOT EXISTS application_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT,
  token VARCHAR(64) NOT NULL COMMENT 'トークン',
  job_id BIGINT NOT NULL COMMENT '案件ID',
  cleaner_id BIGINT NULL COMMENT '清掃者ID（固定者通知時）',
  type ENUM('fixed', 'public') NOT NULL DEFAULT 'public' COMMENT 'トークン種別',
  expires_at DATETIME NOT NULL COMMENT '有効期限',
  used_at DATETIME NULL COMMENT '使用日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_token (token),
  KEY idx_token_job (job_id),
  KEY idx_token_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='応募トークン';

-- =============================================
-- 5. ログ・監査系
-- =============================================

-- 操作ログ（監査ログ）
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id BIGINT NULL COMMENT 'ユーザーID',
  action VARCHAR(100) NOT NULL COMMENT '操作種別',
  target_type VARCHAR(50) NOT NULL COMMENT '対象エンティティ',
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
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作ログ';

-- メール送信履歴
CREATE TABLE IF NOT EXISTS email_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  to_email VARCHAR(255) NOT NULL COMMENT '送信先メールアドレス',
  subject VARCHAR(255) NOT NULL COMMENT '件名',
  body TEXT NOT NULL COMMENT '本文',
  type VARCHAR(50) NOT NULL COMMENT 'メール種別',
  reservation_id BIGINT NULL COMMENT '関連予約ID',
  status ENUM('sent', 'failed', 'bounced') NOT NULL DEFAULT 'sent' COMMENT '送信ステータス',
  sent_at DATETIME NOT NULL COMMENT '送信日時',
  error_message TEXT NULL COMMENT 'エラーメッセージ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_to (to_email),
  KEY idx_email_reservation (reservation_id),
  KEY idx_email_type (type),
  KEY idx_email_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='メール送信履歴';

-- ログイン試行（セキュリティ）
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL COMMENT 'メールアドレス',
  ip_address VARCHAR(45) NOT NULL COMMENT 'IPアドレス',
  attempts INT NOT NULL DEFAULT 1 COMMENT '試行回数',
  last_attempt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最終試行日時',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_login_attempts_email (email),
  KEY idx_login_attempts_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ログイン試行';

-- =============================================
-- 6. 外部キー制約
-- =============================================

-- stores
ALTER TABLE stores ADD CONSTRAINT fk_stores_owner
  FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- sales_areas
ALTER TABLE sales_areas ADD CONSTRAINT fk_sales_areas_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- users
ALTER TABLE users ADD CONSTRAINT fk_users_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE users ADD CONSTRAINT fk_users_owner
  FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- user_stores
ALTER TABLE user_stores ADD CONSTRAINT fk_user_stores_user
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE user_stores ADD CONSTRAINT fk_user_stores_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- password_reset_tokens
ALTER TABLE password_reset_tokens ADD CONSTRAINT fk_password_reset_user
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- keys
ALTER TABLE `keys` ADD CONSTRAINT fk_keys_area
  FOREIGN KEY (sales_area_id) REFERENCES sales_areas(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- reservations
ALTER TABLE reservations ADD CONSTRAINT fk_reservations_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE reservations ADD CONSTRAINT fk_reservations_area
  FOREIGN KEY (sales_area_id) REFERENCES sales_areas(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- key_assignments
ALTER TABLE key_assignments ADD CONSTRAINT fk_key_assignments_reservation
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE key_assignments ADD CONSTRAINT fk_key_assignments_key
  FOREIGN KEY (key_id) REFERENCES `keys`(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- cleaner_stores
ALTER TABLE cleaner_stores ADD CONSTRAINT fk_cleaner_stores_cleaner
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE cleaner_stores ADD CONSTRAINT fk_cleaner_stores_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- fixed_cleaners
ALTER TABLE fixed_cleaners ADD CONSTRAINT fk_fixed_cleaners_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE fixed_cleaners ADD CONSTRAINT fk_fixed_cleaners_cleaner
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- cleaning_jobs
ALTER TABLE cleaning_jobs ADD CONSTRAINT fk_cleaning_jobs_reservation
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE cleaning_jobs ADD CONSTRAINT fk_cleaning_jobs_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE cleaning_jobs ADD CONSTRAINT fk_cleaning_jobs_area
  FOREIGN KEY (sales_area_id) REFERENCES sales_areas(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE cleaning_jobs ADD CONSTRAINT fk_cleaning_jobs_cleaner
  FOREIGN KEY (assigned_cleaner_id) REFERENCES cleaners(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- job_applications
ALTER TABLE job_applications ADD CONSTRAINT fk_job_applications_job
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE job_applications ADD CONSTRAINT fk_job_applications_cleaner
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- extensions
ALTER TABLE extensions ADD CONSTRAINT fk_extensions_job
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- cleaner_payments
ALTER TABLE cleaner_payments ADD CONSTRAINT fk_cleaner_payments_job
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE cleaner_payments ADD CONSTRAINT fk_cleaner_payments_cleaner
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE cleaner_payments ADD CONSTRAINT fk_cleaner_payments_store
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE cleaner_payments ADD CONSTRAINT fk_cleaner_payments_paid_by
  FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- notification_logs
ALTER TABLE notification_logs ADD CONSTRAINT fk_notification_logs_cleaner
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE notification_logs ADD CONSTRAINT fk_notification_logs_job
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- application_tokens
ALTER TABLE application_tokens ADD CONSTRAINT fk_application_tokens_job
  FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE application_tokens ADD CONSTRAINT fk_application_tokens_cleaner
  FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- audit_logs
ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_logs_user
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- email_logs
ALTER TABLE email_logs ADD CONSTRAINT fk_email_logs_reservation
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- 完了
-- =============================================
