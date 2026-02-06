-- =============================================
-- 予約テーブルに不足カラムを追加
-- =============================================

-- 人数、料金関連カラム追加
ALTER TABLE reservations
  ADD COLUMN num_people INT NOT NULL DEFAULT 1 COMMENT '利用人数' AFTER customer_phone,
  ADD COLUMN base_price INT NOT NULL DEFAULT 0 COMMENT '基本料金' AFTER coupon_code,
  ADD COLUMN extension_price INT NOT NULL DEFAULT 0 COMMENT '延長料金' AFTER base_price,
  ADD COLUMN total_price INT NOT NULL DEFAULT 0 COMMENT '合計料金' AFTER extension_price;

-- payment_amountを削除（total_priceに統合）
-- ALTER TABLE reservations DROP COLUMN payment_amount;

-- 鍵テーブルにnotesカラム追加
ALTER TABLE `keys`
  ADD COLUMN notes TEXT NULL COMMENT '備考' AFTER room_name;

-- cleaning_jobsにjob_typeカラム追加（通常/急募区分）
ALTER TABLE cleaning_jobs
  ADD COLUMN job_type ENUM('regular', 'urgent') NOT NULL DEFAULT 'regular' COMMENT '案件種別' AFTER status;

-- extensionsテーブルのカラム修正
ALTER TABLE extensions
  CHANGE COLUMN extension_reward additional_reward INT NOT NULL DEFAULT 0 COMMENT '追加報酬',
  CHANGE COLUMN accepted_at approved_at DATETIME NULL COMMENT '承認日時',
  CHANGE COLUMN status status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'ステータス';
