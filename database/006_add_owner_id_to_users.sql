-- =============================================
-- 006: users テーブルに owner_id カラム追加
-- =============================================
-- 目的: OWNERロールのユーザーが所属オーナーを特定できるようにする
-- 背景: OWNERは自オーナー配下の全店舗にアクセス可能という仕様を実現
-- =============================================

-- owner_id カラムを追加（role の後に配置）
ALTER TABLE users
ADD COLUMN owner_id BIGINT NULL COMMENT '所属オーナーID（OWNERロール用）' AFTER role;

-- 外部キー制約を追加
ALTER TABLE users
ADD CONSTRAINT fk_users_owner
FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- インデックスを追加
CREATE INDEX idx_users_owner ON users(owner_id);

-- =============================================
-- 既存OWNERユーザーへの owner_id 設定
-- =============================================
-- 注意: 本番環境では適切なowner_idを手動で設定してください
-- 下記は開発環境用で、最初のオーナーを設定する例です

-- UPDATE users
-- SET owner_id = (SELECT id FROM owners ORDER BY id LIMIT 1)
-- WHERE role = 'OWNER' AND owner_id IS NULL;

-- =============================================
-- 完了
-- =============================================
