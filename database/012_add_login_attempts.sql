-- =====================================================
-- マイグレーション 012: ログイン試行回数管理テーブル
-- =====================================================
-- セッションベースではなくDBベースでブルートフォース攻撃を防止

-- ログイン試行回数テーブル（IP単位）
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPv4/IPv6対応',
    attempts INT UNSIGNED DEFAULT 1 COMMENT '試行回数',
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最終試行日時',
    locked_until DATETIME NULL COMMENT 'ロック解除予定日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_ip_address (ip_address),
    KEY idx_last_attempt (last_attempt_at),
    KEY idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ログイン試行回数管理';

-- iPassコード閲覧用ワンタイムトークンテーブル
CREATE TABLE IF NOT EXISTS ipass_view_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL COMMENT 'ワンタイムトークン',
    cleaner_id BIGINT NOT NULL COMMENT '清掃者ID',
    expires_at DATETIME NOT NULL COMMENT '有効期限',
    used_at DATETIME NULL COMMENT '使用日時',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_token (token),
    KEY idx_cleaner_id (cleaner_id),
    KEY idx_expires_at (expires_at),

    FOREIGN KEY (cleaner_id) REFERENCES cleaners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='iPassコード閲覧用ワンタイムトークン';

-- Webhookレート制限テーブル（APCuがない環境用）
CREATE TABLE IF NOT EXISTS webhook_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPv4/IPv6対応',
    minute_bucket INT UNSIGNED NOT NULL COMMENT 'UNIXタイムスタンプ/60',
    request_count INT UNSIGNED DEFAULT 1 COMMENT 'リクエスト数',

    UNIQUE KEY idx_ip_minute (ip_address, minute_bucket),
    KEY idx_minute_bucket (minute_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhookレート制限';

-- 古いログイン試行記録を定期削除するためのイベント（任意）
-- SET GLOBAL event_scheduler = ON;
-- CREATE EVENT IF NOT EXISTS cleanup_login_attempts
-- ON SCHEDULE EVERY 1 DAY
-- DO
--   DELETE FROM login_attempts WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
