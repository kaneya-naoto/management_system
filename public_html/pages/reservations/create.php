<?php
/**
 * 予約新規登録（CMS手動登録）
 */
$pageTitle = '予約登録';

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];
$stores = $storeAccess['stores'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// 営業区分一覧を取得
$salesAreas = getAccessibleSalesAreas($accessibleStoreIds);

if (empty($salesAreas)) {
    flashError('営業区分が登録されていません');
    redirect('/reservations');
}

// 登録処理
$errors = [];
$pastDateWarning = '';
$renderFormOnly = false;
if (isPost()) {
    requireCsrf();

    // 入力取得
    $salesAreaId = (int) input('sales_area_id', 0);
    $reservationDate = input('reservation_date', '');
    $startTime = input('start_time', '');
    $endTime = input('end_time', '');
    $customerName = trim(input('customer_name', ''));
    $customerPhone = trim(input('customer_phone', ''));
    $customerEmail = trim(input('customer_email', ''));
    $numPeople = (int) input('num_people', 1);
    $source = input('source', 'phone');
    $paymentStatus = input('payment_status', 'unpaid');
    $basePrice = (int) input('base_price', 0);
    $notes = trim(input('notes', ''));

    // バリデーション
    $selectedArea = null;
    if ($salesAreaId <= 0) {
        $errors[] = '営業区分を選択してください';
    } else {
        // 営業区分がアクセス可能か＆store_idを取得
        $selectedArea = findSalesArea($salesAreas, $salesAreaId);
        if (!$selectedArea) {
            $errors[] = '選択された営業区分にアクセスできません';
        }
    }

    if (empty($reservationDate)) {
        $errors[] = '予約日は必須です';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
        $errors[] = '予約日の形式が不正です';
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $reservationDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $reservationDate) {
            $errors[] = '無効な日付です';
        } elseif ($reservationDate < date('Y-m-d')) {
            $pastDateWarning = '過去の日付が選択されています。';
        }
    }

    // 時間フォーマット検証（HH:MM形式）
    $timePattern = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    if (empty($startTime)) {
        $errors[] = '開始時間は必須です';
    } elseif (!preg_match($timePattern, $startTime)) {
        $errors[] = '開始時間の形式が不正です（HH:MM形式で入力してください）';
    }

    if (empty($endTime)) {
        $errors[] = '終了時間は必須です';
    } elseif (!preg_match($timePattern, $endTime)) {
        $errors[] = '終了時間の形式が不正です（HH:MM形式で入力してください）';
    }

    // 深夜営業対応: 終了時間 < 開始時間の場合は翌日扱いとして許可
    if (!empty($startTime) && !empty($endTime) && preg_match($timePattern, $startTime) && preg_match($timePattern, $endTime)) {
        if ($startTime >= $endTime && $endTime !== '00:00') {
            // 日跨ぎ予約の場合、店舗の営業時間を確認して判定
            $allowMidnightCrossing = false;
            if ($selectedArea) {
                $storeHours = dbSelectOne(
                    "SELECT opening_time, closing_time, is_24h_open FROM stores WHERE id = ?",
                    [$selectedArea['store_id']]
                );
                if ($storeHours) {
                    if ($storeHours['is_24h_open']) {
                        $allowMidnightCrossing = true;
                    } else {
                        $storeClose = substr($storeHours['closing_time'], 0, 5);
                        $storeOpen = substr($storeHours['opening_time'], 0, 5);
                        // 店舗の営業時間が日跨ぎ（closing < opening、例: 18:00-05:00）なら許可
                        if ($storeClose < $storeOpen || $storeClose === '00:00') {
                            $allowMidnightCrossing = true;
                        }
                    }
                }
            }
            if (!$allowMidnightCrossing) {
                $errors[] = '終了時間は開始時間より後にしてください（この店舗では深夜跨ぎの予約はできません）';
            }
        }
    }

    $errors = array_merge($errors, validateCustomerInput([
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'customer_email' => $customerEmail,
        'num_people' => $numPeople,
        'notes' => $notes,
    ]));

    $allowedSources = ['web', 'line', 'phone', 'direct'];
    if (!in_array($source, $allowedSources, true)) {
        $errors[] = '不正な予約経路です';
    }

    $allowedPaymentStatuses = ['unpaid', 'paid'];
    if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
        $errors[] = '不正な決済状態です';
    }

    if ($basePrice < 0 || $basePrice > 10000000) {
        $errors[] = '基本料金は0〜1000万円の範囲で入力してください';
    }

    // 登録実行
    if (empty($errors) && $selectedArea) {
        dbBegin();
        try {
            // 予約のステータス（支払済みならconfirmed）
            $status = ($paymentStatus === 'paid') ? 'confirmed' : 'pending';

            // 延長用トークンを生成（64文字のhex）
            $extensionToken = bin2hex(random_bytes(32));

            // 予約を登録
            $reservationId = dbInsert('reservations', [
                'store_id' => $selectedArea['store_id'],
                'sales_area_id' => $salesAreaId,
                'reservation_date' => $reservationDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone ?: null,
                'customer_email' => $customerEmail ?: null,
                'num_people' => $numPeople,
                'base_price' => $basePrice,
                'total_price' => $basePrice,
                'payment_status' => $paymentStatus,
                'status' => $status,
                'source' => $source,
                'notes' => $notes ?: null,
                'extension_token' => $extensionToken,
            ]);

            // 確定済みなら鍵割当＆清掃案件生成
            if ($status === 'confirmed') {
                // 空き鍵チェック（割当前の事前確認）
                $availableKeys = getAvailableKeyCount($salesAreaId, $reservationDate, $startTime, $endTime);
                if ($availableKeys <= 0 && !input('force_no_key', false)) {
                    // force パラメータがない場合は警告を出して中断
                    dbRollback();
                    $errors[] = '空き鍵がありません。鍵なしで予約を登録する場合は再度送信してください。';
                    $_SESSION['force_no_key_next'] = true;
                    $renderFormOnly = true;
                } else {
                    // 鍵割当（空きがない場合でもforceなら続行）
                    try {
                        $keyAssigned = assignKeyToReservation($reservationId, $salesAreaId, $reservationDate, $startTime, $endTime);
                    } catch (RuntimeException $e) {
                        // 鍵割当失敗しても予約自体は登録（CMSでは手動対応可能）
                        error_log("CMS reservation key assignment failed: " . $e->getMessage());
                    }

                    // 清掃案件生成
                    createCleaningJobForReservation($reservationId, $selectedArea['store_id'], $salesAreaId, $reservationDate, $startTime, $endTime);
                }
            }

            if (!$renderFormOnly) {
                dbCommit();
                flashSuccess('予約を登録しました');
                redirect("/reservations/{$reservationId}");
            }
        } catch (Exception $e) {
            dbRollback();
            error_log("Reservation create failed: " . $e->getMessage());
            // 本番環境では詳細エラーを隠す
            $errors[] = '予約の登録に失敗しました。しばらくしてから再度お試しください。';
        }
    }

    // old値を保存
    setOld([
        'sales_area_id' => $salesAreaId,
        'reservation_date' => $reservationDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'customer_email' => $customerEmail,
        'num_people' => $numPeople,
        'source' => $source,
        'payment_status' => $paymentStatus,
        'base_price' => $basePrice,
        'notes' => $notes,
    ]);
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-4">
    <a href="<?= url('/reservations') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 予約一覧へ戻る</a>
    <h1 class="h3 mb-0">予約登録</h1>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($pastDateWarning)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> <?= h($pastDateWarning) ?>
</div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
    <?php if (!empty($_SESSION['force_no_key_next'])): ?>
    <input type="hidden" name="force_no_key" value="1">
    <?php unset($_SESSION['force_no_key_next']); endif; ?>

    <div class="row">
        <!-- 予約情報 -->
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">予約情報</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">営業区分 <span class="text-danger">*</span></label>
                        <select name="sales_area_id" class="form-select" required>
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($salesAreas as $area): ?>
                            <option value="<?= $area['id'] ?>" <?= old('sales_area_id') == $area['id'] ? 'selected' : '' ?>>
                                <?= h($area['store_name']) ?> - <?= h($area['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">予約日 <span class="text-danger">*</span></label>
                        <input type="date" name="reservation_date" class="form-control"
                               value="<?= h(old('reservation_date', date('Y-m-d'))) ?>" required>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">開始時間 <span class="text-danger">*</span></label>
                                <input type="time" name="start_time" class="form-control"
                                       value="<?= h(old('start_time', '10:00')) ?>" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">終了時間 <span class="text-danger">*</span></label>
                                <input type="time" name="end_time" class="form-control"
                                       value="<?= h(old('end_time', '12:00')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">予約経路</label>
                        <select name="source" class="form-select">
                            <option value="phone" <?= old('source', 'phone') === 'phone' ? 'selected' : '' ?>>電話</option>
                            <option value="line" <?= old('source') === 'line' ? 'selected' : '' ?>>LINE</option>
                            <option value="direct" <?= old('source') === 'direct' ? 'selected' : '' ?>>直接来店</option>
                            <option value="web" <?= old('source') === 'web' ? 'selected' : '' ?>>Web予約</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 料金・決済 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">料金・決済</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">基本料金（円）</label>
                        <input type="number" name="base_price" class="form-control"
                               value="<?= h(old('base_price', 5000)) ?>" min="0" step="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">決済状態</label>
                        <select name="payment_status" class="form-select">
                            <option value="unpaid" <?= old('payment_status', 'unpaid') === 'unpaid' ? 'selected' : '' ?>>未払い</option>
                            <option value="paid" <?= old('payment_status') === 'paid' ? 'selected' : '' ?>>支払済</option>
                        </select>
                        <div class="form-text">「支払済」を選択すると、予約確定＋鍵割当＋清掃案件が自動生成されます</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 顧客情報 -->
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">顧客情報</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">顧客名 <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" class="form-control"
                               value="<?= h(old('customer_name')) ?>" placeholder="山田 太郎" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="customer_phone" class="form-control"
                               value="<?= h(old('customer_phone')) ?>" placeholder="090-1234-5678">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" name="customer_email" class="form-control"
                               value="<?= h(old('customer_email')) ?>" placeholder="example@email.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">人数</label>
                        <input type="number" name="num_people" class="form-control"
                               value="<?= h(old('num_people', 1)) ?>" min="1" max="10">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="特記事項など"><?= h(old('notes')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-lg">予約を登録</button>
                <a href="<?= url('/reservations') ?>" class="btn btn-outline-secondary btn-lg">キャンセル</a>
            </div>
        </div>
    </div>
</form>

<?php
clearOld();
require __DIR__ . '/../../includes/footer.php';
?>
