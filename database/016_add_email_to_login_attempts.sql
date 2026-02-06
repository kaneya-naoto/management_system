-- =====================================================
-- マイグレーション 016: ログイン試行にメールアドレス追加
-- =====================================================
-- IP単位からIP+Email単位でロックを管理するように変更

-- emailカラム追加
ALTER TABLE login_attempts
    ADD COLUMN email VARCHAR(255) NULL COMMENT '試行対象メールアドレス' AFTER ip_address;

-- 既存のIP単独UNIQUEキーを削除し、IP+Email複合UNIQUEキーに変更
-- これにより同一IPから異なるメールアドレスでの試行を個別に記録できる
ALTER TABLE login_attempts
    DROP INDEX idx_ip_address,
    ADD UNIQUE KEY idx_ip_email (ip_address, email),
    ADD KEY idx_email (email);
