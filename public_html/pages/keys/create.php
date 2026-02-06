<?php
/**
 * 鍵番号新規登録
 */
$pageTitle = '鍵番号登録';

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// 営業区分一覧を取得
$salesAreas = getAccessibleSalesAreas($accessibleStoreIds);

if (empty($salesAreas)) {
    flashError('営業区分が登録されていません');
    redirect('/keys');
}

// 登録処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $keyNumber = trim(input('key_number', ''));
    $salesAreaId = (int) input('sales_area_id', 0);
    $notes = trim(input('notes', ''));

    // バリデーション
    if (empty($keyNumber)) {
        $errors[] = '鍵番号は必須です';
    } elseif (mb_strlen($keyNumber) > 50) {
        $errors[] = '鍵番号は50文字以内で入力してください';
    }

    if ($salesAreaId <= 0) {
        $errors[] = '営業区分を選択してください';
    } elseif (!findSalesArea($salesAreas, $salesAreaId)) {
        $errors[] = '選択された営業区分にアクセスできません';
    }

    if (mb_strlen($notes) > 500) {
        $errors[] = '備考は500文字以内で入力してください';
    }

    // 同一営業区分内で重複チェック
    if (empty($errors)) {
        $existing = dbSelectOne(
            "SELECT id FROM `keys` WHERE sales_area_id = ? AND key_number = ? AND deleted_at IS NULL",
            [$salesAreaId, $keyNumber]
        );
        if ($existing) {
            $errors[] = 'この鍵番号は既に登録されています';
        }
    }

    // 登録
    if (empty($errors)) {
        $newKeyId = dbInsert('keys', [
            'sales_area_id' => $salesAreaId,
            'key_number' => $keyNumber,
            'is_active' => 1,
            'notes' => $notes ?: null,
        ]);

        flashSuccess('鍵番号を登録しました');
        redirect("/keys/{$newKeyId}");
    } else {
        setOld([
            'sales_area_id' => $salesAreaId,
            'key_number' => $keyNumber,
            'notes' => $notes,
        ]);
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-4">
    <a href="<?= url('/keys') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 鍵一覧へ戻る</a>
    <h1 class="h3 mb-0">鍵番号登録</h1>
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
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">鍵情報</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">

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
                        <div class="form-text">鍵を使用する営業区分を選択</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">鍵番号 <span class="text-danger">*</span></label>
                        <input type="text" name="key_number" class="form-control"
                               value="<?= h(old('key_number')) ?>" placeholder="例: 001, A-01" required>
                        <div class="form-text">表示用の鍵番号</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="メモなど"><?= h(old('notes')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">登録</button>
                        <a href="<?= url('/keys') ?>" class="btn btn-outline-secondary">キャンセル</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
