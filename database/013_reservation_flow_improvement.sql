-- ============================================
-- 予約フロー改善: DBマイグレーション
-- Created: 2026-01-28
-- ============================================

-- ============================================
-- 1. cleaning_jobs テーブル変更
-- ============================================

-- 清掃完了時刻カラム追加
ALTER TABLE cleaning_jobs
ADD COLUMN completed_at DATETIME NULL COMMENT '清掃完了時刻' AFTER status;

-- 清掃終了予定時刻カラム追加
ALTER TABLE cleaning_jobs
ADD COLUMN scheduled_end_at DATETIME NULL COMMENT '清掃終了予定時刻' AFTER scheduled_at;

-- 清掃所要時間カラム追加
ALTER TABLE cleaning_jobs
ADD COLUMN duration_minutes INT NOT NULL DEFAULT 60 COMMENT '清掃所要時間（分）' AFTER scheduled_end_at;

-- インデックス追加
CREATE INDEX idx_cleaning_jobs_completion
ON cleaning_jobs(reservation_id, status, completed_at);

-- ============================================
-- 2. sales_areas テーブル変更
-- ============================================

-- 清掃時間設定カラム追加
ALTER TABLE sales_areas
ADD COLUMN cleaning_duration_minutes INT NOT NULL DEFAULT 60
COMMENT '清掃所要時間（分）' AFTER hourly_rate;

-- ============================================
-- 3. daily_notification_logs テーブル作成
-- ============================================

CREATE TABLE IF NOT EXISTS daily_notification_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NOT NULL COMMENT '案件ID',
    cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
    notification_date DATE NOT NULL COMMENT '通知日',
    notification_type ENUM('immediate', 'daily') NOT NULL DEFAULT 'daily' COMMENT '通知種別',
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' COMMENT '送信状態',
    notified_at DATETIME NULL COMMENT '送信完了時刻',
    error_message TEXT NULL COMMENT 'エラーメッセージ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- ユニーク制約（重複通知防止）
    UNIQUE KEY uk_job_cleaner_date (job_id, cleaner_id, notification_date),

    -- インデックス
    INDEX idx_notification_date (notification_date),
    INDEX idx_status (status),
    INDEX idx_job_id (job_id),
    INDEX idx_cleaner_id (cleaner_id),

    -- 外部キー
    CONSTRAINT fk_daily_notif_job FOREIGN KEY (job_id)
        REFERENCES cleaning_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_daily_notif_cleaner FOREIGN KEY (cleaner_id)
        REFERENCES cleaners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='定点通知ログ（重複防止用）';

-- ============================================
-- 4. reservations テーブルのインデックス最適化
-- ============================================

-- 空き状況チェック用の複合インデックス
CREATE INDEX idx_reservations_availability
ON reservations(sales_area_id, reservation_date, start_time, end_time, status);

-- ============================================
-- 5. 既存データの更新（任意）
-- ============================================

-- 既に完了済みの案件にcompleted_atを設定（updated_atを使用）
UPDATE cleaning_jobs
SET completed_at = updated_at
WHERE status IN ('completed', 'paid')
  AND completed_at IS NULL;

-- scheduled_end_atを計算して設定
UPDATE cleaning_jobs cj
JOIN sales_areas sa ON cj.sales_area_id = sa.id
SET cj.scheduled_end_at = DATE_ADD(cj.scheduled_at, INTERVAL COALESCE(sa.cleaning_duration_minutes, 60) MINUTE),
    cj.duration_minutes = COALESCE(sa.cleaning_duration_minutes, 60)
WHERE cj.scheduled_end_at IS NULL;
