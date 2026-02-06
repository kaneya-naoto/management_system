-- 延長リクエスト機能用テーブル

-- 延長リクエストテーブル
CREATE TABLE IF NOT EXISTS extension_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    job_id INT,
    requested_minutes INT NOT NULL COMMENT '延長時間（分）',
    status ENUM('pending', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'pending',
    response_token VARCHAR(64) COMMENT '回答用トークン',
    requested_at DATETIME NOT NULL,
    responded_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES cleaning_jobs(id) ON DELETE SET NULL,
    INDEX idx_reservation (reservation_id),
    INDEX idx_status (status),
    INDEX idx_response_token (response_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 予約テーブルに延長用トークンカラムを追加
ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS extension_token VARCHAR(64) AFTER notes,
ADD INDEX IF NOT EXISTS idx_extension_token (extension_token);

-- 清掃案件テーブルに延長報酬カラムを追加（既存でなければ）
ALTER TABLE cleaning_jobs
ADD COLUMN IF NOT EXISTS extension_reward INT DEFAULT 0 AFTER base_reward;
