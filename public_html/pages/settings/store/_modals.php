<?php
/**
 * 店舗設定 - モーダル群
 * 変数: $csrfToken, $store
 */
$currentCleaningDefault = (int)($store['cleaning_time_minutes'] ?? CLEANING_TIME_MINUTES);
?>
<!-- 営業区分追加モーダル -->
<div class="modal fade" id="addAreaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="add_area">

                <div class="modal-header">
                    <h5 class="modal-title">営業区分を追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">区分名 <span class="text-danger">*</span></label>
                        <input type="text" name="area_name" class="form-control" required maxlength="100"
                               placeholder="例: VIPルーム">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">時間単価 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="area_hourly_rate" class="form-control" value="2500" min="0" max="100000" step="100" required>
                                <span class="input-group-text">円</span>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">定員 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="area_capacity" class="form-control" value="4" min="1" max="100" required>
                                <span class="input-group-text">名</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">説明文</label>
                        <textarea name="area_description" class="form-control" rows="2" placeholder="部屋の特徴など"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">清掃所要時間</label>
                        <select name="area_cleaning_duration" class="form-select">
                            <option value="">店舗デフォルト（<?= $currentCleaningDefault ?>分）を使用</option>
                            <?php for ($m = 15; $m <= 180; $m += 15): ?>
                            <option value="<?= $m ?>"><?= $m ?>分</option>
                            <?php endfor; ?>
                        </select>
                        <div class="form-text">未設定の場合は店舗のデフォルト清掃時間が適用されます</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">追加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 営業区分編集モーダル -->
<div class="modal fade" id="editAreaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="editAreaForm">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update_area">
                <input type="hidden" name="area_id" id="editAreaId">

                <div class="modal-header">
                    <h5 class="modal-title">営業区分を編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">区分名 <span class="text-danger">*</span></label>
                        <input type="text" name="area_name" id="editAreaName" class="form-control" required maxlength="100">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">時間単価 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="area_hourly_rate" id="editAreaHourlyRate" class="form-control" min="0" max="100000" step="100" required>
                                <span class="input-group-text">円</span>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">定員 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="area_capacity" id="editAreaCapacity" class="form-control" min="1" max="100" required>
                                <span class="input-group-text">名</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">説明文</label>
                        <textarea name="area_description" id="editAreaDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">清掃所要時間</label>
                        <select name="area_cleaning_duration" id="editAreaCleaningDuration" class="form-select">
                            <option value="">店舗デフォルト（<?= $currentCleaningDefault ?>分）を使用</option>
                            <?php for ($m = 15; $m <= 180; $m += 15): ?>
                            <option value="<?= $m ?>"><?= $m ?>分</option>
                            <?php endfor; ?>
                        </select>
                        <div class="form-text">未設定の場合は店舗のデフォルト清掃時間が適用されます</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="editAreaActive">
                            <label class="form-check-label" for="editAreaActive">有効</label>
                        </div>
                    </div>

                    <!-- 延長用QRコード -->
                    <div id="qrCodeSection" class="border-top pt-3 mt-3" style="display: none;">
                        <h6 class="mb-3"><i class="bi bi-qr-code"></i> 延長申請用QRコード</h6>
                        <div class="text-center mb-3">
                            <div id="qrCodeContainer"></div>
                        </div>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control form-control-sm" id="extensionUrl" readonly>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyExtensionUrl()">
                                <i class="bi bi-clipboard" id="copyExtUrlIcon"></i>
                            </button>
                        </div>
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="downloadQRCode()">
                                <i class="bi bi-download"></i> QRコードをダウンロード
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger me-auto" onclick="deleteArea()">
                        削除
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary" form="editAreaForm">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 営業区分削除フォーム（モーダル外に配置してネスト回避） -->
<form method="post" id="deleteAreaForm" style="display: none;">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">
    <input type="hidden" name="action" value="delete_area">
    <input type="hidden" name="area_id" id="deleteAreaId" value="">
</form>
