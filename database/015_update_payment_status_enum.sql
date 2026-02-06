-- 015: cleaner_payments ステータスENUM変更
-- unpaid → pending に統一（specとの整合性）
--
-- 変更理由:
-- specではpending/paid/cancelledだったが、実装ではunpaid/paidだった。
-- ユーザー確認の結果、pending/paidに統一することに決定。

-- Step 1: ENUMに一時的にpendingを追加（unpaidも残す）
ALTER TABLE cleaner_payments
  MODIFY COLUMN status ENUM('unpaid', 'pending', 'paid') NOT NULL DEFAULT 'unpaid';

-- Step 2: 既存データの更新（unpaidをpendingに変換）
UPDATE cleaner_payments SET status = 'pending' WHERE status = 'unpaid';

-- Step 3: ENUMからunpaidを削除し、デフォルトをpendingに変更
ALTER TABLE cleaner_payments
  MODIFY COLUMN status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending';
