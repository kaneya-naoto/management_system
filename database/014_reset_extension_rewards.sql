-- 延長報酬リセットマイグレーション
-- 仕様変更: 延長時の清掃報酬は常に0（延長しても報酬不変）
-- 既存データを仕様に合わせてリセットする

-- 清掃案件の延長報酬をリセット
UPDATE cleaning_jobs SET extension_reward = 0 WHERE extension_reward > 0;

-- 支払いレコードの延長報酬をリセット（未払い分）
UPDATE cleaner_payments SET
    extension_amount = 0,
    total_amount = base_amount
WHERE extension_amount > 0 AND status = 'pending';

-- 支払い済みレコードもリセット（全リセット）
UPDATE cleaner_payments SET
    extension_amount = 0,
    total_amount = base_amount
WHERE extension_amount > 0 AND status = 'paid';
