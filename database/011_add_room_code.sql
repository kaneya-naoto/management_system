-- 延長申請用 部屋コード追加
-- 営業区分（sales_areas）に固定のroom_codeを追加し、部屋ごとの固定URLでアクセス可能にする

-- room_code カラム追加
ALTER TABLE sales_areas
ADD COLUMN room_code VARCHAR(16) UNIQUE AFTER description;

-- 既存データにコード生成（16文字のランダム英数字、セキュリティ強化）
UPDATE sales_areas
SET room_code = LOWER(CONCAT(
    SUBSTRING(MD5(CONCAT(id, RAND(), NOW())), 1, 8),
    SUBSTRING(MD5(CONCAT(NOW(), RAND(), id)), 1, 8)
))
WHERE room_code IS NULL;

-- インデックス追加（検索高速化）
CREATE INDEX idx_sales_areas_room_code ON sales_areas(room_code);
