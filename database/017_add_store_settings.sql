-- 店舗設定の拡充: 延長・清掃関連カラムの追加
-- 2026-02-10

-- ============================================
-- 1. storesテーブルに3カラム追加
--    config.php定数を店舗ごとに設定可能にする
-- ============================================

ALTER TABLE stores
  ADD COLUMN extension_price_per_hour INT NOT NULL DEFAULT 1000 COMMENT '延長1時間あたり料金（円）' AFTER booking_days_ahead,
  ADD COLUMN max_extension_hours DECIMAL(3,1) NOT NULL DEFAULT 5.0 COMMENT '最大延長時間（時間）' AFTER extension_price_per_hour,
  ADD COLUMN cleaning_time_minutes INT NOT NULL DEFAULT 60 COMMENT 'デフォルト清掃時間（分）' AFTER max_extension_hours;

-- ============================================
-- 2. sales_areas.cleaning_duration_minutes をnullable化
--    NULL = 店舗デフォルトを使用、値あり = 個別指定
-- ============================================

ALTER TABLE sales_areas
  MODIFY COLUMN cleaning_duration_minutes INT NULL DEFAULT NULL COMMENT '清掃所要時間（分）- NULLは店舗デフォルト使用';

-- 注意: 既存データの一律NULL化はしない
-- 「意図して60分に設定した区分」と「未カスタマイズ区分」を区別できないため
-- 新規追加時のみNULL（店舗デフォルト使用）がデフォルトとなる
