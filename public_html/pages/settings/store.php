<?php
/**
 * 店舗設定
 */
$pageTitle = '店舗設定';

// OWNER権限チェック
requireRole('OWNER');

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// 対象店舗（最初の1つ、または指定された店舗）
$editStoreId = (int) input('store_id', $accessibleStoreIds[0]);
if (!in_array($editStoreId, $accessibleStoreIds)) {
    $editStoreId = $accessibleStoreIds[0];
}

// 店舗情報取得
$store = dbSelectOne(
    "SELECT s.*, o.name as owner_name
     FROM stores s
     LEFT JOIN owners o ON s.owner_id = o.id
     WHERE s.id = ? AND s.deleted_at IS NULL",
    [$editStoreId]
);

if (!$store) {
    flashError('店舗が見つかりません');
    redirect('/settings');
}


// 営業区分一覧取得
$salesAreas = dbSelect(
    "SELECT * FROM sales_areas WHERE store_id = ? AND deleted_at IS NULL ORDER BY name",
    [$editStoreId]
);

$errors = [];
$areaErrors = [];

// 店舗情報更新処理
if (isPost()) {
    requireCsrf();
    $action = input('action', '');

    if ($action === 'update_store') {
        $name = trim(input('name', ''));
        $address = trim(input('address', ''));
        $phone = trim(input('phone', ''));
        $email = trim(input('email', ''));
        $baseReward = (int) input('base_reward', 3000);

        // 営業時間
        $is24hOpen = input('is_24h_open', '0') === '1';
        $openingTime = input('opening_time', '10:00');
        $closingTime = input('closing_time', '00:00');

        // バリデーション
        if (empty($name)) {
            $errors[] = '店舗名を入力してください';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = '店舗名は100文字以内で入力してください';
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません';
        }

        if ($baseReward < 0 || $baseReward > 100000) {
            $errors[] = '基本報酬は0〜100,000円の範囲で設定してください';
        }

        // 営業時間のバリデーション
        if (!$is24hOpen) {
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $openingTime)) {
                $errors[] = '営業開始時間の形式が正しくありません';
            }
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $closingTime)) {
                $errors[] = '営業終了時間の形式が正しくありません';
            }
        }

        if (empty($errors)) {
            dbUpdate('stores', [
                'name' => $name,
                'address' => $address ?: null,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'base_reward' => $baseReward,
                'is_24h_open' => $is24hOpen ? 1 : 0,
                'opening_time' => $openingTime . ':00',
                'closing_time' => $closingTime . ':00',
            ], 'id = ?', [$editStoreId]);

            flashSuccess('店舗情報を更新しました');
            redirect('/settings/store?store_id=' . $editStoreId);
        }

        // 入力値保持
        $store['name'] = $name;
        $store['address'] = $address;
        $store['phone'] = $phone;
        $store['email'] = $email;
        $store['base_reward'] = $baseReward;
        $store['is_24h_open'] = $is24hOpen ? 1 : 0;
        $store['opening_time'] = $openingTime . ':00';
        $store['closing_time'] = $closingTime . ':00';
    }

    // 営業区分追加
    if ($action === 'add_area') {
        $areaName = trim(input('area_name', ''));
        $areaHourlyRate = (int) input('area_hourly_rate', 2500);
        $areaCapacity = (int) input('area_capacity', 4);
        $areaDescription = trim(input('area_description', ''));

        if (empty($areaName)) {
            $areaErrors[] = '区分名を入力してください';
        } elseif (mb_strlen($areaName) > 100) {
            $areaErrors[] = '区分名は100文字以内で入力してください';
        } else {
            // 重複チェック
            $existing = dbSelectOne(
                "SELECT id FROM sales_areas WHERE store_id = ? AND name = ? AND deleted_at IS NULL",
                [$editStoreId, $areaName]
            );
            if ($existing) {
                $areaErrors[] = 'この区分名は既に登録されています';
            }
        }

        if ($areaHourlyRate < 0 || $areaHourlyRate > 100000) {
            $areaErrors[] = '時間単価は0〜100,000円の範囲で設定してください';
        }
        if ($areaCapacity < 1 || $areaCapacity > 100) {
            $areaErrors[] = '定員は1〜100名の範囲で設定してください';
        }

        if (empty($areaErrors)) {
            // room_code を自動生成（16文字のランダム英数字、セキュリティ強化）
            $roomCode = bin2hex(random_bytes(8)); // 16文字

            dbInsert('sales_areas', [
                'store_id' => $editStoreId,
                'name' => $areaName,
                'hourly_rate' => $areaHourlyRate,
                'capacity' => $areaCapacity,
                'description' => $areaDescription ?: null,
                'room_code' => $roomCode,
                'is_active' => 1,
            ]);

            flashSuccess('営業区分を追加しました');
            redirect('/settings/store?store_id=' . $editStoreId);
        }
    }

    // 営業区分更新
    if ($action === 'update_area') {
        $areaId = (int) input('area_id', 0);
        $areaName = trim(input('area_name', ''));
        $areaHourlyRate = (int) input('area_hourly_rate', 2500);
        $areaCapacity = (int) input('area_capacity', 4);
        $areaDescription = trim(input('area_description', ''));
        // チェックボックス未送信時は0（無効）
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // 対象の営業区分が現在の店舗のものか確認
        $targetArea = dbSelectOne(
            "SELECT id, name FROM sales_areas WHERE id = ? AND store_id = ? AND deleted_at IS NULL",
            [$areaId, $editStoreId]
        );

        if (!$targetArea) {
            $areaErrors[] = '営業区分が見つかりません';
        } elseif (empty($areaName)) {
            $areaErrors[] = '区分名を入力してください';
        } elseif (mb_strlen($areaName) > 100) {
            $areaErrors[] = '区分名は100文字以内で入力してください';
        } else {
            // 重複チェック（自分以外で同名があるか）
            if ($areaName !== $targetArea['name']) {
                $existing = dbSelectOne(
                    "SELECT id FROM sales_areas WHERE store_id = ? AND name = ? AND id != ? AND deleted_at IS NULL",
                    [$editStoreId, $areaName, $areaId]
                );
                if ($existing) {
                    $areaErrors[] = 'この区分名は既に登録されています';
                }
            }
        }

        if ($areaHourlyRate < 0 || $areaHourlyRate > 100000) {
            $areaErrors[] = '時間単価は0〜100,000円の範囲で設定してください';
        }
        if ($areaCapacity < 1 || $areaCapacity > 100) {
            $areaErrors[] = '定員は1〜100名の範囲で設定してください';
        }

        if (empty($areaErrors)) {
            dbUpdate('sales_areas', [
                'name' => $areaName,
                'hourly_rate' => $areaHourlyRate,
                'capacity' => $areaCapacity,
                'description' => $areaDescription ?: null,
                'is_active' => $isActive,
            ], 'id = ?', [$areaId]);

            flashSuccess('営業区分を更新しました');
            redirect('/settings/store?store_id=' . $editStoreId);
        }
    }

    // 営業区分削除（論理削除）
    if ($action === 'delete_area') {
        $areaId = (int) input('area_id', 0);

        dbBegin();
        try {
            // 対象の営業区分をロック付きで取得
            $targetArea = dbSelectOne(
                "SELECT id FROM sales_areas WHERE id = ? AND store_id = ? AND deleted_at IS NULL FOR UPDATE",
                [$areaId, $editStoreId]
            );

            if (!$targetArea) {
                throw new Exception('営業区分が見つかりません');
            }

            // 使用中の予約・案件があるかチェック（ロック付き）
            $inUse = dbSelectOne(
                "SELECT COUNT(*) as cnt FROM reservations WHERE sales_area_id = ? AND status IN ('pending', 'confirmed') FOR UPDATE",
                [$areaId]
            );
            if ($inUse && $inUse['cnt'] > 0) {
                throw new Exception('この営業区分は現在使用中のため削除できません');
            }

            // 論理削除
            dbUpdate('sales_areas', [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$areaId]);

            dbCommit();
            flashSuccess('営業区分を削除しました');
            redirect('/settings/store?store_id=' . $editStoreId);

        } catch (Exception $e) {
            dbRollback();
            $areaErrors[] = $e->getMessage();
        }
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="d-flex gap-2 mb-2">
            <a href="<?= url('/settings') ?>" class="btn btn-outline-secondary btn-sm">← 設定へ戻る</a>
            <?php if (hasRole('HQ')): ?>
            <a href="<?= url('/settings/stores') ?>" class="btn btn-outline-secondary btn-sm">← 店舗管理へ戻る</a>
            <?php endif; ?>
        </div>
        <h1 class="h3 mb-0">店舗設定</h1>
    </div>
</div>

<?php if (count($accessibleStoreIds) > 1): ?>
<div class="mb-4">
    <form method="get" class="d-inline-flex gap-2 align-items-center">
        <label class="form-label mb-0">編集する店舗:</label>
        <select name="store_id" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
            <?php foreach ($storeAccess['stores'] as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $editStoreId === (int)$s['id'] ? 'selected' : '' ?>>
                <?= h($s['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 mb-4">
        <!-- 店舗基本情報 -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">店舗基本情報</h5>
                <?php if ($store['is_active']): ?>
                    <span class="badge bg-success">有効</span>
                <?php else: ?>
                    <span class="badge bg-secondary">無効</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$store['is_active']): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>この店舗は現在「無効」状態です</strong><br>
                    <small class="text-muted">有効/無効の変更はHQに連絡してください</small>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_store">

                    <div class="mb-3">
                        <label class="form-label">店舗コード</label>
                        <input type="text" class="form-control" value="<?= h($store['code']) ?>" disabled>
                        <div class="form-text">店舗コードは変更できません</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">予約フォームURL</label>
                        <?php
                        $storeCode = $store['code'] ?? '';
                        $bookingUrl = rtrim(APP_URL, '/') . '/booking/' . $storeCode;
                        ?>
                        <div class="input-group">
                            <input type="text" class="form-control" value="<?= h($bookingUrl) ?>" id="bookingUrl" readonly>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyBookingUrl()" title="URLをコピー">
                                <i class="bi bi-clipboard" id="copyIcon"></i>
                            </button>
                            <a href="<?= h($bookingUrl) ?>" target="_blank" class="btn btn-outline-primary" title="新しいタブで開く">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                        <div class="form-text">このURLをお客様に共有してください</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">店舗名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= h($store['name']) ?>" required maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">住所</label>
                        <input type="text" name="address" class="form-control" value="<?= h($store['address']) ?>" maxlength="255">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="phone" class="form-control" value="<?= h($store['phone']) ?>" maxlength="20">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" name="email" class="form-control" value="<?= h($store['email']) ?>" maxlength="255">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">基本報酬</label>
                        <div class="input-group">
                            <input type="number" name="base_reward" class="form-control" value="<?= (int)$store['base_reward'] ?>" min="0" max="100000" step="100">
                            <span class="input-group-text">円</span>
                        </div>
                        <div class="form-text">清掃1回あたりの基本報酬額</div>
                    </div>

                    <hr class="my-4">
                    <h6 class="mb-3">営業時間設定</h6>

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

                    <button type="submit" class="btn btn-primary">保存</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <!-- 営業区分管理 -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">営業区分</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAreaModal">
                    <i class="bi bi-plus"></i> 追加
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($areaErrors)): ?>
                <div class="alert alert-danger m-3 mb-0">
                    <ul class="mb-0">
                        <?php foreach ($areaErrors as $error): ?>
                        <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (empty($salesAreas)): ?>
                <p class="text-muted text-center py-4 mb-0">営業区分がありません</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>区分名</th>
                                <th class="text-end">時間単価</th>
                                <th class="text-center">定員</th>
                                <th>状態</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($salesAreas as $area): ?>
                            <tr>
                                <td><?= h($area['name']) ?></td>
                                <td class="text-end"><?= number_format($area['hourly_rate'] ?? 2500) ?>円</td>
                                <td class="text-center"><?= (int)($area['capacity'] ?? 4) ?>名</td>
                                <td>
                                    <?php if ($area['is_active']): ?>
                                    <span class="badge bg-success">有効</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">無効</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#editAreaModal"
                                            data-id="<?= $area['id'] ?>"
                                            data-name="<?= h($area['name']) ?>"
                                            data-hourly-rate="<?= (int)($area['hourly_rate'] ?? 2500) ?>"
                                            data-capacity="<?= (int)($area['capacity'] ?? 4) ?>"
                                            data-description="<?= h($area['description'] ?? '') ?>"
                                            data-room-code="<?= h($area['room_code'] ?? '') ?>"
                                            data-active="<?= $area['is_active'] ?>">
                                        編集
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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
                    <!-- 削除フォーム（別フォームで分離） -->
                    <form method="post" class="me-auto">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="store_id" value="<?= h($editStoreId) ?>">
                        <input type="hidden" name="area_id" id="deleteAreaId" value="">
                        <button type="submit" class="btn btn-outline-danger" name="action" value="delete_area"
                                onclick="document.getElementById('deleteAreaId').value = document.getElementById('editAreaId').value; return confirm('この営業区分を削除しますか？')">
                            削除
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary" form="editAreaForm">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
// 24時間営業トグル
function toggle24hMode(is24h) {
    const fields = document.getElementById('businessHoursFields');
    fields.style.display = is24h ? 'none' : '';
}

// 延長URLをコピー
function copyExtensionUrl() {
    const urlInput = document.getElementById('extensionUrl');
    const copyIcon = document.getElementById('copyExtUrlIcon');

    navigator.clipboard.writeText(urlInput.value).then(() => {
        copyIcon.className = 'bi bi-check';
        setTimeout(() => {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    }).catch(() => {
        urlInput.select();
        document.execCommand('copy');
        copyIcon.className = 'bi bi-check';
        setTimeout(() => {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    });
}

// QRコードをダウンロード
function downloadQRCode() {
    const canvas = document.querySelector('#qrCodeContainer canvas');
    if (!canvas) return;

    const areaName = document.getElementById('editAreaName').value || 'room';
    const link = document.createElement('a');
    link.download = `qr_${areaName}.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
}

// QRコードを生成
function generateQRCode(roomCode) {
    const container = document.getElementById('qrCodeContainer');
    const urlInput = document.getElementById('extensionUrl');
    const qrSection = document.getElementById('qrCodeSection');

    if (!roomCode) {
        qrSection.style.display = 'none';
        return;
    }

    qrSection.style.display = 'block';
    const url = '<?= rtrim(APP_URL, "/") ?>/extend/room/' + roomCode;
    urlInput.value = url;

    // 既存のQRコードをクリア
    container.innerHTML = '';

    // QRコード生成
    QRCode.toCanvas(url, {
        width: 200,
        margin: 2,
        color: { dark: '#000000', light: '#ffffff' }
    }, function(error, canvas) {
        if (error) {
            console.error(error);
            container.innerHTML = '<p class="text-danger">QRコード生成エラー</p>';
            return;
        }
        container.appendChild(canvas);
    });
}

// 予約URLをコピー
function copyBookingUrl() {
    const urlInput = document.getElementById('bookingUrl');
    const copyIcon = document.getElementById('copyIcon');

    navigator.clipboard.writeText(urlInput.value).then(() => {
        // 成功時：アイコンを変更
        copyIcon.className = 'bi bi-check';
        setTimeout(() => {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    }).catch(() => {
        // フォールバック
        urlInput.select();
        document.execCommand('copy');
        copyIcon.className = 'bi bi-check';
        setTimeout(() => {
            copyIcon.className = 'bi bi-clipboard';
        }, 2000);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editAreaModal');
    editModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('editAreaId').value = button.dataset.id;
        document.getElementById('editAreaName').value = button.dataset.name;
        document.getElementById('editAreaHourlyRate').value = button.dataset.hourlyRate || 2500;
        document.getElementById('editAreaCapacity').value = button.dataset.capacity || 4;
        document.getElementById('editAreaDescription').value = button.dataset.description || '';
        document.getElementById('editAreaActive').checked = button.dataset.active === '1';

        // QRコード生成
        const roomCode = button.dataset.roomCode || '';
        generateQRCode(roomCode);
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
