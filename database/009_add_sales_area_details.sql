-- 営業区分（部屋）の詳細設定カラムを追加
-- 2026-01-27

-- 営業区分に詳細設定を追加
ALTER TABLE sales_areas
  ADD COLUMN hourly_rate INT NOT NULL DEFAULT 2500 COMMENT '時間単価（税込）' AFTER name,
  ADD COLUMN capacity INT NOT NULL DEFAULT 4 COMMENT '定員' AFTER hourly_rate,
  ADD COLUMN description TEXT NULL COMMENT '説明文' AFTER capacity;

-- 店舗にもデフォルト設定を追加（営業区分で未設定の場合に使用）
ALTER TABLE stores
  ADD COLUMN default_hourly_rate INT NOT NULL DEFAULT 2500 COMMENT 'デフォルト時間単価' AFTER is_24h_open,
  ADD COLUMN max_duration_hours INT NOT NULL DEFAULT 8 COMMENT '最大利用時間' AFTER default_hourly_rate,
  ADD COLUMN min_duration_hours INT NOT NULL DEFAULT 1 COMMENT '最低利用時間' AFTER max_duration_hours,
  ADD COLUMN booking_days_ahead INT NOT NULL DEFAULT 30 COMMENT '予約受付日数' AFTER min_duration_hours;
