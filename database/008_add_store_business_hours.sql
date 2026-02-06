-- 店舗の営業時間カラムを追加
-- 2026-01-27

-- 営業時間カラム追加
ALTER TABLE stores
  ADD COLUMN opening_time TIME NOT NULL DEFAULT '10:00:00' COMMENT '営業開始時間' AFTER email,
  ADD COLUMN closing_time TIME NOT NULL DEFAULT '00:00:00' COMMENT '営業終了時間（00:00は24:00扱い）' AFTER opening_time,
  ADD COLUMN is_24h_open TINYINT(1) NOT NULL DEFAULT 0 COMMENT '24時間営業フラグ' AFTER closing_time;

-- 営業時間設定例：
-- 通常営業（10:00〜24:00）: opening_time='10:00:00', closing_time='00:00:00', is_24h_open=0
-- 深夜営業（18:00〜翌05:00）: opening_time='18:00:00', closing_time='05:00:00', is_24h_open=0
-- 24時間営業: is_24h_open=1（opening_time, closing_timeは無視）

-- インデックス（必要に応じて）
-- ALTER TABLE stores ADD INDEX idx_stores_24h (is_24h_open);
