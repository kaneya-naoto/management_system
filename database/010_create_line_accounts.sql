-- LINE公式アカウント設定
CREATE TABLE IF NOT EXISTS line_accounts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  account_type ENUM('store', 'cleaner') NOT NULL COMMENT '店舗用 or 清掃者用',
  store_id BIGINT NULL COMMENT '店舗ID（店舗用のみ）',
  name VARCHAR(100) NOT NULL COMMENT 'アカウント名（表示用）',
  channel_id VARCHAR(50) NULL COMMENT 'LINE Channel ID',
  channel_secret VARCHAR(100) NULL COMMENT 'LINE Channel Secret',
  channel_access_token TEXT NULL COMMENT 'LINE Channel Access Token',
  liff_id VARCHAR(50) NULL COMMENT 'LIFF ID',
  webhook_url VARCHAR(255) NULL COMMENT 'Webhook URL（自動生成）',
  is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '有効フラグ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL COMMENT '論理削除日時',
  PRIMARY KEY (id),
  UNIQUE KEY uk_line_accounts_store (store_id),
  KEY idx_line_accounts_type (account_type),
  FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='LINE公式アカウント設定';

-- LINEリッチメニュー設定
CREATE TABLE IF NOT EXISTS line_rich_menus (
  id BIGINT NOT NULL AUTO_INCREMENT,
  line_account_id BIGINT NOT NULL COMMENT 'LINE Account ID',
  name VARCHAR(100) NOT NULL COMMENT 'メニュー名',
  rich_menu_id VARCHAR(50) NULL COMMENT 'LINE RichMenu ID（API登録後）',
  image_filename VARCHAR(100) NULL COMMENT '画像ファイル名',
  size_type ENUM('large', 'compact', 'small') NOT NULL DEFAULT 'large' COMMENT 'サイズ種別',
  menu_config JSON NULL COMMENT 'ボタン配置設定（areas）',
  chat_bar_text VARCHAR(20) NOT NULL DEFAULT 'メニュー' COMMENT 'チャットバーテキスト',
  is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'デフォルトメニュー',
  is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '有効フラグ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL COMMENT '論理削除日時',
  PRIMARY KEY (id),
  KEY idx_rich_menus_account (line_account_id),
  FOREIGN KEY (line_account_id) REFERENCES line_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='LINEリッチメニュー設定';

-- Webhook冪等性管理
CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(50) NOT NULL COMMENT 'webhookEventId',
  account_type ENUM('store', 'cleaner') NOT NULL COMMENT 'アカウント種別',
  store_id BIGINT NULL COMMENT '店舗ID（店舗用のみ）',
  event_type VARCHAR(50) NOT NULL COMMENT 'イベント種別',
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_webhook_events_event (event_id),
  KEY idx_webhook_events_type (event_type),
  KEY idx_webhook_events_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook冪等性管理';

-- reservationsテーブル拡張
ALTER TABLE reservations
  ADD COLUMN line_user_id VARCHAR(50) NULL COMMENT 'LINE経由予約時のUserID' AFTER source,
  ADD COLUMN line_display_name VARCHAR(100) NULL COMMENT 'LINE表示名' AFTER line_user_id;

-- インデックス追加
ALTER TABLE reservations
  ADD KEY idx_reservations_line_user (line_user_id);
