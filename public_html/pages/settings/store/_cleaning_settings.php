<?php
/**
 * 店舗設定 - 清掃・延長設定カード（新規）
 * 変数: $store
 */
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">清掃・延長設定</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-sm-6 mb-3">
                <label class="form-label">基本清掃報酬</label>
                <div class="input-group">
                    <input type="number" name="base_reward" class="form-control"
                           value="<?= (int)($store['base_reward'] ?? DEFAULT_CLEANING_REWARD) ?>"
                           min="0" max="100000" step="100">
                    <span class="input-group-text">円</span>
                </div>
                <div class="form-text">清掃1回あたりの基本報酬額</div>
            </div>
            <div class="col-sm-6 mb-3">
                <label class="form-label">延長1時間あたり料金</label>
                <div class="input-group">
                    <input type="number" name="extension_price_per_hour" class="form-control"
                           value="<?= (int)($store['extension_price_per_hour'] ?? EXTENSION_PRICE_PER_HOUR) ?>"
                           min="0" max="100000" step="100">
                    <span class="input-group-text">円</span>
                </div>
                <div class="form-text">顧客が延長する際の1時間あたり料金</div>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6 mb-3">
                <label class="form-label">最大延長時間</label>
                <select name="max_extension_hours" class="form-select">
                    <?php
                    $currentMaxExt = (float)($store['max_extension_hours'] ?? MAX_EXTENSION_HOURS);
                    for ($h = 0.5; $h <= 8.0; $h += 0.5):
                    ?>
                    <option value="<?= $h ?>" <?= abs($currentMaxExt - $h) < 0.01 ? 'selected' : '' ?>>
                        <?= $h ?>時間
                    </option>
                    <?php endfor; ?>
                </select>
                <div class="form-text">顧客が延長申請できる最大時間</div>
            </div>
            <div class="col-sm-6 mb-3">
                <label class="form-label">デフォルト清掃時間</label>
                <select name="cleaning_time_minutes" class="form-select">
                    <?php
                    $currentCleanMin = (int)($store['cleaning_time_minutes'] ?? CLEANING_TIME_MINUTES);
                    for ($m = 15; $m <= 180; $m += 15):
                    ?>
                    <option value="<?= $m ?>" <?= $currentCleanMin === $m ? 'selected' : '' ?>>
                        <?= $m ?>分
                    </option>
                    <?php endfor; ?>
                </select>
                <div class="form-text">予約間に確保する清掃時間（営業区分で個別指定も可能）</div>
            </div>
        </div>
    </div>
</div>
