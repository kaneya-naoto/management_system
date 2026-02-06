-- =============================================
-- カクレマ 新基幹システム - 初期データ（シード）
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- 1. オーナー
-- =============================================
INSERT INTO owners (id, name, email, phone) VALUES
(1, '山田太郎', 'owner@kakurema.jp', '090-1234-5678');

-- =============================================
-- 2. 店舗
-- =============================================
INSERT INTO stores (id, owner_id, name, code, address, phone, email, base_reward) VALUES
(1, 1, 'カクレマ 新宿店', 'SHINJUKU', '東京都新宿区西新宿1-1-1', '03-1234-5678', 'shinjuku@kakurema.jp', 3000),
(2, 1, 'カクレマ 渋谷店', 'SHIBUYA', '東京都渋谷区渋谷2-2-2', '03-2345-6789', 'shibuya@kakurema.jp', 3500);

-- =============================================
-- 3. 営業区分
-- =============================================
INSERT INTO sales_areas (id, store_id, name) VALUES
(1, 1, '新宿本館'),
(2, 1, '新宿別館'),
(3, 2, '渋谷本館');

-- =============================================
-- 4. 管理者ユーザー
-- パスワード: password123 (bcrypt cost=12)
-- =============================================
INSERT INTO users (id, email, password, name, role, status, store_id) VALUES
(1, 'admin@kakurema.jp', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4.r.5e3qO.ld7J3C', '管理者', 'HQ', 'active', NULL),
(2, 'owner@kakurema.jp', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4.r.5e3qO.ld7J3C', '山田オーナー', 'OWNER', 'active', 1),
(3, 'staff@kakurema.jp', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4.r.5e3qO.ld7J3C', '佐藤スタッフ', 'STORE', 'active', 1);

-- ユーザー×店舗紐付け
INSERT INTO user_stores (user_id, store_id) VALUES
(2, 1), (2, 2),  -- オーナーは2店舗にアクセス可
(3, 1);          -- スタッフは1店舗のみ

-- =============================================
-- 5. 鍵番号
-- =============================================
INSERT INTO `keys` (id, sales_area_id, key_number, room_name) VALUES
(1, 1, 'A-101', '個室1'),
(2, 1, 'A-102', '個室2'),
(3, 1, 'A-103', '個室3'),
(4, 2, 'B-201', '別館個室1'),
(5, 2, 'B-202', '別館個室2'),
(6, 3, 'S-301', '渋谷個室1'),
(7, 3, 'S-302', '渋谷個室2');

-- =============================================
-- 6. 清掃者（サンプル）
-- =============================================
INSERT INTO cleaners (id, line_user_id, name, phone, ipass_code, is_active) VALUES
(1, 'U1234567890abcdef1234567890abcdef1', '田中花子', '090-1111-2222', 'IPASS001', 1),
(2, 'U2345678901abcdef2345678901abcdef2', '鈴木一郎', '090-3333-4444', 'IPASS002', 1),
(3, 'U3456789012abcdef3456789012abcdef3', '高橋美咲', '090-5555-6666', 'IPASS003', 1);

-- 清掃者×店舗紐付け
INSERT INTO cleaner_stores (cleaner_id, store_id) VALUES
(1, 1), (1, 2),  -- 田中さんは2店舗対応
(2, 1),          -- 鈴木さんは新宿のみ
(3, 2);          -- 高橋さんは渋谷のみ

-- 固定者
INSERT INTO fixed_cleaners (store_id, cleaner_id, priority) VALUES
(1, 1, 1),  -- 新宿店の固定者1位
(1, 2, 2),  -- 新宿店の固定者2位
(2, 3, 1);  -- 渋谷店の固定者1位

-- =============================================
-- 7. サンプル予約（本日〜1週間後）
-- =============================================
INSERT INTO reservations (id, store_id, sales_area_id, customer_name, customer_email, customer_phone, reservation_date, start_time, end_time, status, source, payment_status, payment_amount) VALUES
(1, 1, 1, '佐々木健一', 'sasaki@example.com', '090-1234-0001', CURDATE(), '14:00:00', '18:00:00', 'confirmed', 'web', 'paid', 8000),
(2, 1, 1, '伊藤美香', 'ito@example.com', '090-1234-0002', CURDATE(), '19:00:00', '23:00:00', 'confirmed', 'phone', 'paid', 8000),
(3, 1, 2, '渡辺太一', 'watanabe@example.com', '090-1234-0003', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', '14:00:00', 'pending', 'web', 'unpaid', NULL),
(4, 2, 3, '中村直美', 'nakamura@example.com', '090-1234-0004', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '15:00:00', '20:00:00', 'confirmed', 'line', 'paid', 10000),
(5, 1, 1, '小林洋平', 'kobayashi@example.com', '090-1234-0005', DATE_ADD(CURDATE(), INTERVAL 3 DAY), '12:00:00', '16:00:00', 'pending', 'direct', 'unpaid', NULL);

-- =============================================
-- 8. 清掃案件（予約に紐づく）
-- =============================================
INSERT INTO cleaning_jobs (id, reservation_id, store_id, sales_area_id, scheduled_at, status, assigned_cleaner_id, base_reward) VALUES
(1, 1, 1, 1, CONCAT(CURDATE(), ' 13:30:00'), 'assigned', 1, 3000),
(2, 2, 1, 1, CONCAT(CURDATE(), ' 18:30:00'), 'recruiting', NULL, 3000),
(3, 4, 2, 3, CONCAT(DATE_ADD(CURDATE(), INTERVAL 2 DAY), ' 14:30:00'), 'unassigned', NULL, 3500);

-- =============================================
-- 9. 案件応募（サンプル）
-- =============================================
INSERT INTO job_applications (job_id, cleaner_id, status) VALUES
(1, 1, 'accepted'),
(2, 2, 'applied'),
(2, 1, 'applied');

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- 完了
-- =============================================
