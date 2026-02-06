-- =============================================
-- カクレマ 本番環境用 - 初期管理者データ
-- =============================================
-- 注意: パスワードは初回ログイン後に必ず変更してください
-- デフォルトパスワード: password123
-- =============================================

SET NAMES utf8mb4;

-- 初期オーナー（必要に応じて変更）
INSERT INTO owners (id, name, email, phone) VALUES
(1, 'カクレマ運営', 'info@kakurema.jp', NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 初期店舗（必要に応じて変更）
INSERT INTO stores (id, owner_id, name, code, base_reward) VALUES
(1, 1, 'カクレマ本店', 'MAIN', 3000)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 初期営業区分
INSERT INTO sales_areas (id, store_id, name) VALUES
(1, 1, '本館')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 管理者ユーザー（HQ権限）
-- パスワード: password123 (bcrypt cost=12)
INSERT INTO users (id, email, password, name, role, status, store_id) VALUES
(1, 'admin@kakurema.jp', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4.r.5e3qO.ld7J3C', '管理者', 'HQ', 'active', NULL)
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- ユーザー×店舗紐付け（HQは全店舗アクセス可だが、明示的にも紐付け）
INSERT INTO user_stores (user_id, store_id) VALUES
(1, 1)
ON DUPLICATE KEY UPDATE store_id = VALUES(store_id);

-- =============================================
-- 完了
-- =============================================
