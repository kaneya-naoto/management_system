<?php
/**
 * 清掃者新規登録（CMS側）
 */
$pageTitle = '清掃者登録';

// OWNER権限が必要（清掃者の手動登録はOWNER限定）
requireRole('OWNER');

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];
$stores = $storeAccess['stores'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

$errors = [];

// 登録処理
if (isPost()) {
    requireCsrf();

    $name = trim(input('name', ''));
    $phone = trim(input('phone', ''));
    $lineUserId = trim(input('line_user_id', ''));
    $storeIds = input('store_ids', []);

    if (!is_array($storeIds)) {
        $storeIds = [];
    }
    $storeIds = array_filter(array_map('intval', $storeIds));

    // バリデーション
    if (empty($name)) {
        $errors[] = '氏名を入力してください';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = '氏名は100文字以内で入力してください';
    }

    if (!empty($phone)) {
        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneClean) < 10 || strlen($phoneClean) > 11) {
            $errors[] = '電話番号の形式が正しくありません';
        }
    }

    // LINE User IDの重複チェック
    if (!empty($lineUserId)) {
        $existing = dbSelectOne(
            "SELECT id FROM cleaners WHERE line_user_id = ? AND deleted_at IS NULL",
            [$lineUserId]
        );
        if ($existing) {
            $errors[] = 'このLINE User IDは既に登録されています';
        }
    }

    // 店舗選択チェック
    if (empty($storeIds)) {
        $errors[] = '対応店舗を1つ以上選択してください';
    } else {
        // 有効な店舗IDかチェック
        $invalidIds = array_diff($storeIds, $accessibleStoreIds);
        if (!empty($invalidIds)) {
            $errors[] = '無効な店舗が選択されています';
        }
    }

    if (empty($errors)) {
        dbBegin();
        try {
            // iPassコード生成
            $ipassCode = generateIPassCode();

            // LINE User IDがない場合は一時的なIDを生成
            $lineUserIdFinal = $lineUserId ?: 'manual_' . bin2hex(random_bytes(16));

            // 清掃者登録
            $cleanerId = dbInsert('cleaners', [
                'line_user_id' => $lineUserIdFinal,
                'name' => $name,
                'phone' => $phone ?: null,
                'ipass_code' => $ipassCode,
                'is_active' => 1,
                'registration_status' => 'completed',
                'registered_at' => date('Y-m-d H:i:s'),
            ]);

            // 対応店舗を登録
            foreach ($storeIds as $storeId) {
                dbInsert('cleaner_stores', [
                    'cleaner_id' => $cleanerId,
                    'store_id' => $storeId,
                ]);
            }

            dbCommit();
            flashSuccess('清掃者を登録しました（iPassコード: ' . $ipassCode . '）');
            redirect('/cleaners/' . $cleanerId);

        } catch (Exception $e) {
            dbRollback();
            $errors[] = '登録処理中にエラーが発生しました';
            error_log("Cleaner create error: " . $e->getMessage());
        }
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/cleaners') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 清掃者一覧へ戻る</a>
        <h1 class="h3 mb-0">清掃者登録</h1>
    </div>
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

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">基本情報</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">

                    <div class="mb-4">
                        <label class="form-label">氏名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= h(input('name', '')) ?>"
                               required maxlength="100" placeholder="山田 太郎">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= h(input('phone', '')) ?>"
                               placeholder="090-1234-5678">
                        <div class="form-text">緊急連絡先として使用します（任意）</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">LINE User ID</label>
                        <input type="text" name="line_user_id" class="form-control"
                               value="<?= h(input('line_user_id', '')) ?>"
                               placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        <div class="form-text">
                            LINE経由で登録した場合は自動で設定されます。<br>
                            手動登録の場合は空欄でも登録できます（後からLINE連携可能）。
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">対応可能店舗 <span class="text-danger">*</span></label>
                        <?php
                        $selectedStoreIds = input('store_ids', []);
                        if (!is_array($selectedStoreIds)) {
                            $selectedStoreIds = [];
                        }
                        ?>
                        <?php foreach ($stores as $store): ?>
                        <div class="form-check mb-2">
                            <input type="checkbox" name="store_ids[]" value="<?= $store['id'] ?>"
                                   class="form-check-input" id="store_<?= $store['id'] ?>"
                                   <?= in_array((int)$store['id'], $selectedStoreIds) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="store_<?= $store['id'] ?>">
                                <?= h($store['name']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <div class="form-text">清掃可能な店舗にチェックを入れてください</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">登録する</button>
                        <a href="<?= url('/cleaners') ?>" class="btn btn-outline-secondary">キャンセル</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">登録について</h5>
            </div>
            <div class="card-body">
                <h6>LINE連携について</h6>
                <p class="small text-muted">
                    清掃者がLINEで友だち追加した場合、自動で登録されます。
                    この画面は、LINE登録前に手動で清掃者を登録する場合に使用してください。
                </p>

                <h6>iPassコードについて</h6>
                <p class="small text-muted mb-0">
                    登録完了後、6桁の英数字コード（iPassコード）が自動生成されます。
                    清掃者がLINE以外からログインする際に使用します。
                </p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
