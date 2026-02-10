<?php
/**
 * 店舗設定 - 営業時間カード
 * 変数: $store
 */
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">営業時間</h5>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="is_24h_open" id="is24hOpen" value="1"
                       <?= !empty($store['is_24h_open']) ? 'checked' : '' ?>
                       onchange="toggle24hMode(this.checked)">
                <label class="form-check-label" for="is24hOpen">24時間営業</label>
            </div>
        </div>

        <div id="businessHoursFields" style="<?= !empty($store['is_24h_open']) ? 'display:none;' : '' ?>">
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">営業開始時間</label>
                    <select name="opening_time" class="form-select" id="openingTime">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <?php for ($m = 0; $m < 60; $m += 30): ?>
                                <?php $time = sprintf('%02d:%02d', $h, $m); ?>
                                <option value="<?= $time ?>" <?= substr($store['opening_time'] ?? '10:00:00', 0, 5) === $time ? 'selected' : '' ?>>
                                    <?= $time ?>
                                </option>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">営業終了時間</label>
                    <select name="closing_time" class="form-select" id="closingTime">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <?php for ($m = 0; $m < 60; $m += 30): ?>
                                <?php $time = sprintf('%02d:%02d', $h, $m); ?>
                                <?php $display = ($h === 0 && $m === 0) ? '24:00' : $time; ?>
                                <option value="<?= $time ?>" <?= substr($store['closing_time'] ?? '00:00:00', 0, 5) === $time ? 'selected' : '' ?>>
                                    <?= $display ?>
                                </option>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="form-text mb-3">
                <i class="bi bi-info-circle"></i>
                終了時間が開始時間より早い場合は深夜営業（翌日までの営業）となります。<br>
                例: 18:00〜05:00 = 18時から翌朝5時まで
            </div>
        </div>
    </div>
</div>
