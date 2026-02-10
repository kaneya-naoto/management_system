<?php
/**
 * 店舗設定 - 予約設定カード（新規）
 * 変数: $store
 */
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">予約設定</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-sm-6 mb-3">
                <label class="form-label">デフォルト時間単価</label>
                <div class="input-group">
                    <input type="number" name="default_hourly_rate" class="form-control"
                           value="<?= (int)($store['default_hourly_rate'] ?? DEFAULT_HOURLY_RATE) ?>"
                           min="0" max="100000" step="100">
                    <span class="input-group-text">円</span>
                </div>
                <div class="form-text">営業区分で未設定時に使用する時間単価</div>
            </div>
            <div class="col-sm-6 mb-3">
                <label class="form-label">予約受付日数</label>
                <div class="input-group">
                    <input type="number" name="booking_days_ahead" class="form-control"
                           value="<?= (int)($store['booking_days_ahead'] ?? 30) ?>"
                           min="1" max="365">
                    <span class="input-group-text">日先まで</span>
                </div>
                <div class="form-text">顧客が予約可能な日数の上限</div>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6 mb-3">
                <label class="form-label">最小利用時間</label>
                <div class="input-group">
                    <input type="number" name="min_duration_hours" id="minDurationHours" class="form-control"
                           value="<?= (int)($store['min_duration_hours'] ?? MIN_BOOKING_DURATION_HOURS) ?>"
                           min="1" max="24">
                    <span class="input-group-text">時間</span>
                </div>
            </div>
            <div class="col-sm-6 mb-3">
                <label class="form-label">最大利用時間</label>
                <div class="input-group">
                    <input type="number" name="max_duration_hours" id="maxDurationHours" class="form-control"
                           value="<?= (int)($store['max_duration_hours'] ?? MAX_BOOKING_DURATION_HOURS) ?>"
                           min="1" max="24">
                    <span class="input-group-text">時間</span>
                </div>
            </div>
        </div>
        <div class="form-text">
            <i class="bi bi-info-circle"></i>
            最小利用時間は最大利用時間以下に設定してください
        </div>
    </div>
</div>
