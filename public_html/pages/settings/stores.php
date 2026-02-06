<?php
/**
 * 店舗管理（HQ専用）
 */
$pageTitle = '店舗管理';

// HQ権限チェック
requireRole('HQ');

// オーナー一覧取得（セレクトボックス用）
$owners = dbSelect(
    "SELECT id, name FROM owners WHERE deleted_at IS NULL ORDER BY name"
);

// 店舗一覧取得（LINE設定状態も含む）
$stores = dbSelect(
    "SELECT s.*, o.name as owner_name,
            (SELECT COUNT(*) FROM sales_areas sa WHERE sa.store_id = s.id AND sa.deleted_at IS NULL) as area_count,
            (SELECT COUNT(*) FROM reservations r WHERE r.store_id = s.id AND r.deleted_at IS NULL) as reservation_count,
            la.id as line_account_id,
            la.is_active as line_is_active,
            la.liff_id as line_liff_id
     FROM stores s
     LEFT JOIN owners o ON s.owner_id = o.id
     LEFT JOIN line_accounts la ON la.store_id = s.id AND la.account_type = 'store' AND la.deleted_at IS NULL
     WHERE s.deleted_at IS NULL
     ORDER BY o.name, s.name"
);

$errors = [];
$editStore = null;

// 編集対象取得
$editId = (int) input('edit', 0);
if ($editId > 0) {
    $editStore = dbSelectOne(
        "SELECT s.*,
                (SELECT COUNT(*) FROM reservations r WHERE r.store_id = s.id AND r.deleted_at IS NULL) as reservation_count
         FROM stores s
         WHERE s.id = ? AND s.deleted_at IS NULL",
        [$editId]
    );
}

// フォーム処理
if (isPost()) {
    requireCsrf();
    $action = input('action', '');

    // 新規追加
    if ($action === 'create') {
        $ownerId = (int) input('owner_id', 0);
        $name = trim(input('name', ''));
        $code = trim(input('code', ''));
        $address = trim(input('address', ''));
        $phone = trim(input('phone', ''));
        $email = trim(input('email', ''));

        // バリデーション
        if ($ownerId <= 0) {
            $errors[] = 'オーナーを選択してください';
        } else {
            $owner = dbSelectOne("SELECT id FROM owners WHERE id = ? AND deleted_at IS NULL", [$ownerId]);
            if (!$owner) {
                $errors[] = '選択したオーナーが見つかりません';
            }
        }

        if (empty($name)) {
            $errors[] = '店舗名を入力してください';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = '店舗名は100文字以内で入力してください';
        }

        if (empty($code)) {
            $errors[] = '店舗コードを入力してください';
        } elseif (!preg_match('/^[A-Z0-9_-]{2,20}$/i', $code)) {
            $errors[] = '店舗コードは2〜20文字の英数字・ハイフン・アンダースコアで入力してください';
        } else {
            // 重複チェック
            $existing = dbSelectOne(
                "SELECT id FROM stores WHERE code = ? AND deleted_at IS NULL",
                [$code]
            );
            if ($existing) {
                $errors[] = 'この店舗コードは既に使用されています';
            }
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません';
        }

        if (empty($errors)) {
            dbInsert('stores', [
                'owner_id' => $ownerId,
                'name' => $name,
                'code' => strtoupper($code),
                'address' => $address ?: null,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'base_reward' => 3000, // デフォルト値（詳細はstore.phpで設定）
                'is_active' => 1,
            ]);
            flashSuccess('店舗を追加しました');
            redirect('/settings/stores');
        }
    }

    // 更新
    if ($action === 'update') {
        $storeId = (int) input('store_id', 0);
        $ownerId = (int) input('owner_id', 0);
        $name = trim(input('name', ''));
        $address = trim(input('address', ''));
        $phone = trim(input('phone', ''));
        $email = trim(input('email', ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $targetStore = dbSelectOne(
            "SELECT * FROM stores WHERE id = ? AND deleted_at IS NULL",
            [$storeId]
        );

        if (!$targetStore) {
            $errors[] = '店舗が見つかりません';
        } else {
            // バリデーション
            if ($ownerId <= 0) {
                $errors[] = 'オーナーを選択してください';
            } else {
                $owner = dbSelectOne("SELECT id FROM owners WHERE id = ? AND deleted_at IS NULL", [$ownerId]);
                if (!$owner) {
                    $errors[] = '選択したオーナーが見つかりません';
                }
            }

            if (empty($name)) {
                $errors[] = '店舗名を入力してください';
            } elseif (mb_strlen($name) > 100) {
                $errors[] = '店舗名は100文字以内で入力してください';
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'メールアドレスの形式が正しくありません';
            }

            if (empty($errors)) {
                dbUpdate('stores', [
                    'owner_id' => $ownerId,
                    'name' => $name,
                    'address' => $address ?: null,
                    'phone' => $phone ?: null,
                    'email' => $email ?: null,
                    'is_active' => $isActive,
                ], 'id = ?', [$storeId]);
                flashSuccess('店舗情報を更新しました');
                redirect('/settings/stores');
            }

            // エラー時は編集モードを維持
            $editStore = $targetStore;
            $editStore['owner_id'] = $ownerId;
            $editStore['name'] = $name;
            $editStore['address'] = $address;
            $editStore['phone'] = $phone;
            $editStore['email'] = $email;
            $editStore['is_active'] = $isActive;
        }
    }

    // 削除
    if ($action === 'delete') {
        $storeId = (int) input('store_id', 0);

        dbBegin();
        try {
            $targetStore = dbSelectOne(
                "SELECT * FROM stores WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$storeId]
            );

            if (!$targetStore) {
                throw new Exception('店舗が見つかりません');
            }

            // 予約があるかチェック
            $reservationCount = dbSelectOne(
                "SELECT COUNT(*) as cnt FROM reservations WHERE store_id = ? AND deleted_at IS NULL",
                [$storeId]
            );
            if ($reservationCount && $reservationCount['cnt'] > 0) {
                throw new Exception('この店舗には予約データがあるため削除できません');
            }

            // 清掃案件があるかチェック
            $jobCount = dbSelectOne(
                "SELECT COUNT(*) as cnt FROM cleaning_jobs WHERE store_id = ? AND deleted_at IS NULL",
                [$storeId]
            );
            if ($jobCount && $jobCount['cnt'] > 0) {
                throw new Exception('この店舗には清掃案件があるため削除できません');
            }

            // 関連データを削除（営業区分、固定者、鍵、LINE設定など）
            dbUpdate('sales_areas', ['deleted_at' => date('Y-m-d H:i:s')], 'store_id = ?', [$storeId]);
            dbUpdate('fixed_cleaners', ['deleted_at' => date('Y-m-d H:i:s')], 'store_id = ?', [$storeId]);
            dbUpdate('line_accounts', ['deleted_at' => date('Y-m-d H:i:s')], 'store_id = ? AND deleted_at IS NULL', [$storeId]);
            dbExecute("DELETE FROM cleaner_stores WHERE store_id = ?", [$storeId]);
            dbExecute("DELETE FROM user_stores WHERE store_id = ?", [$storeId]);

            // 論理削除
            dbUpdate('stores', [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$storeId]);

            dbCommit();
            flashSuccess('店舗を削除しました');
            redirect('/settings/stores');

        } catch (Exception $e) {
            dbRollback();
            $errors[] = $e->getMessage();
        }
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/settings') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 設定へ戻る</a>
        <h1 class="h3 mb-0">店舗管理</h1>
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

<?php if (empty($owners)): ?>
<div class="alert alert-warning">
    <strong>オーナーが登録されていません</strong><br>
    店舗を追加するには、まず<a href="<?= url('/settings/owners') ?>">オーナー管理</a>でオーナーを登録してください。
</div>
<?php endif; ?>

<div class="row">
    <!-- 店舗一覧 -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">店舗一覧</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($stores)): ?>
                    <p class="text-muted text-center py-4 mb-0">店舗が登録されていません</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>店舗コード</th>
                                    <th>店舗名</th>
                                    <th>オーナー</th>
                                    <th>基本報酬</th>
                                    <th>状態</th>
                                    <th>営業区分</th>
                                    <th>LINE</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stores as $store): ?>
                                <tr>
                                    <td><code><?= h($store['code']) ?></code></td>
                                    <td><strong><?= h($store['name']) ?></strong></td>
                                    <td><?= h($store['owner_name']) ?></td>
                                    <td><?= number_format($store['base_reward']) ?>円</td>
                                    <td>
                                        <?php if ($store['is_active']): ?>
                                            <span class="badge bg-success">有効</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">無効</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= (int)$store['area_count'] ?>区分</span>
                                    </td>
                                    <td>
                                        <?php if ($store['line_account_id']): ?>
                                            <?php if ($store['line_is_active']): ?>
                                                <span class="badge bg-success" title="LIFF: <?= h($store['line_liff_id'] ?: '未設定') ?>">
                                                    <i class="bi bi-line"></i> 有効
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-line"></i> 無効
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">未設定</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="<?= url('/settings/stores?edit=' . $store['id']) ?>"
                                           class="btn btn-sm btn-outline-primary">編集</a>
                                        <a href="<?= url('/settings/store?store_id=' . $store['id']) ?>"
                                           class="btn btn-sm btn-outline-secondary">営業区分</a>
                                        <a href="<?= url('/settings/line?store_id=' . $store['id']) ?>"
                                           class="btn btn-sm btn-outline-success">
                                           <i class="bi bi-line"></i> LINE
                                        </a>
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

    <!-- 店舗追加/編集 -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= $editStore ? '店舗を編集' : '店舗を追加' ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($owners) && !$editStore): ?>
                    <p class="text-muted">オーナーを登録してから店舗を追加してください</p>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="<?= $editStore ? 'update' : 'create' ?>">
                    <?php if ($editStore): ?>
                    <input type="hidden" name="store_id" value="<?= $editStore['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">オーナー <span class="text-danger">*</span></label>
                        <select name="owner_id" class="form-select" required>
                            <option value="">-- 選択 --</option>
                            <?php foreach ($owners as $owner): ?>
                            <option value="<?= $owner['id'] ?>"
                                <?= ($editStore && (int)$editStore['owner_id'] === (int)$owner['id']) ? 'selected' : '' ?>>
                                <?= h($owner['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$editStore): ?>
                    <div class="mb-3">
                        <label class="form-label">店舗コード <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control" required maxlength="20"
                               pattern="[A-Za-z0-9_-]{2,20}"
                               placeholder="例: STORE01" style="text-transform: uppercase;">
                        <div class="form-text">英数字・ハイフン・アンダースコア（2〜20文字）</div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label">店舗コード</label>
                        <input type="text" class="form-control" value="<?= h($editStore['code']) ?>" disabled>
                        <div class="form-text">店舗コードは変更できません</div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">店舗名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               value="<?= h($editStore['name'] ?? '') ?>"
                               placeholder="例: 渋谷店">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">住所</label>
                        <input type="text" name="address" class="form-control" maxlength="255"
                               value="<?= h($editStore['address'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="phone" class="form-control" maxlength="20"
                               value="<?= h($editStore['phone'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" name="email" class="form-control" maxlength="255"
                               value="<?= h($editStore['email'] ?? '') ?>">
                    </div>

                    <?php if ($editStore): ?>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input"
                                   id="isActive" <?= ($editStore['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isActive">有効</label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <?= $editStore ? '更新' : '追加' ?>
                        </button>
                        <?php if ($editStore): ?>
                        <a href="<?= url('/settings/stores') ?>" class="btn btn-secondary">キャンセル</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($editStore): ?>
                <div class="mt-3 pt-3 border-top">
                    <a href="<?= url('/settings/store?store_id=' . $editStore['id']) ?>" class="btn btn-outline-info w-100 mb-2">
                        <i class="bi bi-grid"></i> 営業区分・基本報酬を管理
                    </a>
                    <a href="<?= url('/settings/line?store_id=' . $editStore['id']) ?>" class="btn btn-outline-success w-100">
                        <i class="bi bi-line"></i> LINE公式アカウント設定
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($editStore && (int)$editStore['reservation_count'] === 0): ?>
                <hr class="my-3">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="store_id" value="<?= $editStore['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger w-100"
                            onclick="return confirm('この店舗を削除しますか？\n関連する営業区分・固定者なども削除されます。')">
                        この店舗を削除
                    </button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
