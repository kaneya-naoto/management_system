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
        // 基本情報
        $name = trim(input('name', ''));
        $address = trim(input('address', ''));
        $phone = trim(input('phone', ''));
        $email = trim(input('email', ''));

        // 営業時間
        $is24hOpen = input('is_24h_open', '0') === '1';
        $openingTime = input('opening_time', '10:00');
        $closingTime = input('closing_time', '00:00');

        // 予約設定
        $defaultHourlyRate = (int) input('default_hourly_rate', DEFAULT_HOURLY_RATE);
        $minDurationHours = (int) input('min_duration_hours', MIN_BOOKING_DURATION_HOURS);
        $maxDurationHours = (int) input('max_duration_hours', MAX_BOOKING_DURATION_HOURS);
        $bookingDaysAhead = (int) input('booking_days_ahead', 30);

        // 清掃・延長設定
        $baseReward = (int) input('base_reward', DEFAULT_CLEANING_REWARD);
        $extensionPricePerHour = (int) input('extension_price_per_hour', EXTENSION_PRICE_PER_HOUR);
        $maxExtensionHours = (float) input('max_extension_hours', MAX_EXTENSION_HOURS);
        $cleaningTimeMinutes = (int) input('cleaning_time_minutes', CLEANING_TIME_MINUTES);

        // === バリデーション ===

        // 基本情報
        if (empty($name)) {
            $errors[] = '店舗名を入力してください';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = '店舗名は100文字以内で入力してください';
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません';
        }

        // 営業時間
        if (!$is24hOpen) {
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $openingTime)) {
                $errors[] = '営業開始時間の形式が正しくありません';
            }
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $closingTime)) {
                $errors[] = '営業終了時間の形式が正しくありません';
            }
        }

        // 予約設定
        if ($defaultHourlyRate < 0 || $defaultHourlyRate > 100000) {
            $errors[] = 'デフォルト時間単価は0〜100,000円の範囲で設定してください';
        }
        if ($minDurationHours < 1 || $minDurationHours > 24) {
            $errors[] = '最小利用時間は1〜24時間の範囲で設定してください';
        }
        if ($maxDurationHours < 1 || $maxDurationHours > 24) {
            $errors[] = '最大利用時間は1〜24時間の範囲で設定してください';
        }
        if ($minDurationHours > $maxDurationHours) {
            $errors[] = '最小利用時間は最大利用時間以下に設定してください';
        }
        if ($bookingDaysAhead < 1 || $bookingDaysAhead > 365) {
            $errors[] = '予約受付日数は1〜365日の範囲で設定してください';
        }

        // 清掃・延長設定
        if ($baseReward < 0 || $baseReward > 100000) {
            $errors[] = '基本清掃報酬は0〜100,000円の範囲で設定してください';
        }
        if ($extensionPricePerHour < 0 || $extensionPricePerHour > 100000) {
            $errors[] = '延長1時間あたり料金は0〜100,000円の範囲で設定してください';
        }
        // 刻み幅検証: UIはselect(0.5刻み/15分刻み)だが、改ざんPOST防止のためサーバー側でも検証
        $allowedExtHours = [];
        for ($v = 0.5; $v <= 8.0; $v += 0.5) { $allowedExtHours[] = $v; }
        if (!in_array($maxExtensionHours, $allowedExtHours, false)) {
            $errors[] = '最大延長時間は0.5時間刻みで0.5〜8.0時間の範囲で設定してください';
        }
        if ($cleaningTimeMinutes < 15 || $cleaningTimeMinutes > 180 || $cleaningTimeMinutes % 15 !== 0) {
            $errors[] = 'デフォルト清掃時間は15分刻みで15〜180分の範囲で設定してください';
        }

        if (empty($errors)) {
            dbUpdate('stores', [
                'name' => $name,
                'address' => $address ?: null,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'is_24h_open' => $is24hOpen ? 1 : 0,
                'opening_time' => $openingTime . ':00',
                'closing_time' => $closingTime . ':00',
                'default_hourly_rate' => $defaultHourlyRate,
                'min_duration_hours' => $minDurationHours,
                'max_duration_hours' => $maxDurationHours,
                'booking_days_ahead' => $bookingDaysAhead,
                'base_reward' => $baseReward,
                'extension_price_per_hour' => $extensionPricePerHour,
                'max_extension_hours' => $maxExtensionHours,
                'cleaning_time_minutes' => $cleaningTimeMinutes,
            ], 'id = ?', [$editStoreId]);

            flashSuccess('店舗情報を更新しました');
            redirect('/settings/store?store_id=' . $editStoreId);
        }

        // 入力値保持
        $store['name'] = $name;
        $store['address'] = $address;
        $store['phone'] = $phone;
        $store['email'] = $email;
        $store['is_24h_open'] = $is24hOpen ? 1 : 0;
        $store['opening_time'] = $openingTime . ':00';
        $store['closing_time'] = $closingTime . ':00';
        $store['default_hourly_rate'] = $defaultHourlyRate;
        $store['min_duration_hours'] = $minDurationHours;
        $store['max_duration_hours'] = $maxDurationHours;
        $store['booking_days_ahead'] = $bookingDaysAhead;
        $store['base_reward'] = $baseReward;
        $store['extension_price_per_hour'] = $extensionPricePerHour;
        $store['max_extension_hours'] = $maxExtensionHours;
        $store['cleaning_time_minutes'] = $cleaningTimeMinutes;
    }

    // 営業区分追加
    if ($action === 'add_area') {
        $areaName = trim(input('area_name', ''));
        $areaHourlyRate = (int) input('area_hourly_rate', 2500);
        $areaCapacity = (int) input('area_capacity', 4);
        $areaDescription = trim(input('area_description', ''));
        $areaCleaningDuration = input('area_cleaning_duration', '');

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
        if ($areaCleaningDuration !== '' && ((int)$areaCleaningDuration < 15 || (int)$areaCleaningDuration > 180 || (int)$areaCleaningDuration % 15 !== 0)) {
            $areaErrors[] = '清掃所要時間は15分刻みで15〜180分の範囲で設定してください';
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
                'cleaning_duration_minutes' => $areaCleaningDuration !== '' ? (int)$areaCleaningDuration : null,
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
        $areaCleaningDuration = input('area_cleaning_duration', '');
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
        if ($areaCleaningDuration !== '' && ((int)$areaCleaningDuration < 15 || (int)$areaCleaningDuration > 180 || (int)$areaCleaningDuration % 15 !== 0)) {
            $areaErrors[] = '清掃所要時間は15分刻みで15〜180分の範囲で設定してください';
        }

        if (empty($areaErrors)) {
            dbUpdate('sales_areas', [
                'name' => $areaName,
                'hourly_rate' => $areaHourlyRate,
                'capacity' => $areaCapacity,
                'description' => $areaDescription ?: null,
                'cleaning_duration_minutes' => $areaCleaningDuration !== '' ? (int)$areaCleaningDuration : null,
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
$storeCode = $store['code'] ?? '';
$bookingUrl = rtrim(APP_URL, '/') . '/booking/' . $storeCode;

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

<form method="post" id="storeSettingsForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="update_store">

    <div class="row">
        <div class="col-lg-6">
            <?php include __DIR__ . '/store/_basic_info.php'; ?>
            <?php include __DIR__ . '/store/_business_hours.php'; ?>
            <?php include __DIR__ . '/store/_booking_settings.php'; ?>
            <?php include __DIR__ . '/store/_cleaning_settings.php'; ?>

            <button type="submit" class="btn btn-primary mb-4">保存</button>
        </div>

        <div class="col-lg-6">
            <?php include __DIR__ . '/store/_sales_areas.php'; ?>
        </div>
    </div>
</form>

<?php include __DIR__ . '/store/_modals.php'; ?>
<?php include __DIR__ . '/store/_scripts.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
