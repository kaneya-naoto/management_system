<?php
/**
 * オーナー管理（HQ専用）
 */
$pageTitle = 'オーナー管理';

// HQ権限チェック
requireRole('HQ');

// オーナー一覧取得
$owners = dbSelect(
    "SELECT o.*,
            (SELECT COUNT(*) FROM stores s WHERE s.owner_id = o.id AND s.deleted_at IS NULL) as store_count
     FROM owners o
     WHERE o.deleted_at IS NULL
     ORDER BY o.name"
);

$errors = [];
$editOwner = null;

// 編集対象取得
$editId = (int) input('edit', 0);
if ($editId > 0) {
    $editOwner = dbSelectOne(
        "SELECT * FROM owners WHERE id = ? AND deleted_at IS NULL",
        [$editId]
    );
}

// フォーム処理
if (isPost()) {
    requireCsrf();
    $action = input('action', '');

    // 新規追加
    if ($action === 'create') {
        $name = trim(input('name', ''));
        $email = trim(input('email', ''));
        $phone = trim(input('phone', ''));

        // バリデーション
        if (empty($name)) {
            $errors[] = 'オーナー名を入力してください';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'オーナー名は100文字以内で入力してください';
        }

        if (empty($email)) {
            $errors[] = 'メールアドレスを入力してください';
        } elseif (strlen($email) > 255) {
            $errors[] = 'メールアドレスは255文字以内で入力してください';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません';
        } else {
            // 重複チェック
            $existing = dbSelectOne(
                "SELECT id FROM owners WHERE email = ? AND deleted_at IS NULL",
                [$email]
            );
            if ($existing) {
                $errors[] = 'このメールアドレスは既に登録されています';
            }
        }

        if (empty($errors)) {
            dbInsert('owners', [
                'name' => $name,
                'email' => $email,
                'phone' => $phone ?: null,
            ]);
            flashSuccess('オーナーを追加しました');
            redirect('/settings/owners');
        }
    }

    // 更新
    if ($action === 'update') {
        $ownerId = (int) input('owner_id', 0);
        $name = trim(input('name', ''));
        $email = trim(input('email', ''));
        $phone = trim(input('phone', ''));

        $targetOwner = dbSelectOne(
            "SELECT * FROM owners WHERE id = ? AND deleted_at IS NULL",
            [$ownerId]
        );

        if (!$targetOwner) {
            $errors[] = 'オーナーが見つかりません';
        } else {
            // バリデーション
            if (empty($name)) {
                $errors[] = 'オーナー名を入力してください';
            } elseif (mb_strlen($name) > 100) {
                $errors[] = 'オーナー名は100文字以内で入力してください';
            }

            if (empty($email)) {
                $errors[] = 'メールアドレスを入力してください';
            } elseif (strlen($email) > 255) {
                $errors[] = 'メールアドレスは255文字以内で入力してください';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'メールアドレスの形式が正しくありません';
            } else {
                // 重複チェック（自分以外）
                $existing = dbSelectOne(
                    "SELECT id FROM owners WHERE email = ? AND id != ? AND deleted_at IS NULL",
                    [$email, $ownerId]
                );
                if ($existing) {
                    $errors[] = 'このメールアドレスは既に使用されています';
                }
            }

            if (empty($errors)) {
                dbUpdate('owners', [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone ?: null,
                ], 'id = ?', [$ownerId]);
                flashSuccess('オーナー情報を更新しました');
                redirect('/settings/owners');
            }

            // エラー時は編集モードを維持
            $editOwner = $targetOwner;
            $editOwner['name'] = $name;
            $editOwner['email'] = $email;
            $editOwner['phone'] = $phone;
        }
    }

    // 削除
    if ($action === 'delete') {
        $ownerId = (int) input('owner_id', 0);

        dbBegin();
        try {
            $targetOwner = dbSelectOne(
                "SELECT * FROM owners WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$ownerId]
            );

            if (!$targetOwner) {
                throw new Exception('オーナーが見つかりません');
            }

            // 紐付け店舗があるかチェック
            $storeCount = dbSelectOne(
                "SELECT COUNT(*) as cnt FROM stores WHERE owner_id = ? AND deleted_at IS NULL",
                [$ownerId]
            );
            if ($storeCount && $storeCount['cnt'] > 0) {
                throw new Exception('このオーナーには店舗が紐付いているため削除できません');
            }

            // 論理削除
            dbUpdate('owners', [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$ownerId]);

            dbCommit();
            flashSuccess('オーナーを削除しました');
            redirect('/settings/owners');

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
        <h1 class="h3 mb-0">オーナー管理</h1>
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
    <!-- オーナー一覧 -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">オーナー一覧</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($owners)): ?>
                    <p class="text-muted text-center py-4 mb-0">オーナーが登録されていません</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>オーナー名</th>
                                    <th>メールアドレス</th>
                                    <th>電話番号</th>
                                    <th>店舗数</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($owners as $owner): ?>
                                <tr>
                                    <td><strong><?= h($owner['name']) ?></strong></td>
                                    <td><?= h($owner['email']) ?></td>
                                    <td><?= h($owner['phone']) ?: '-' ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?= (int)$owner['store_count'] ?>店舗</span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= url('/settings/owners?edit=' . $owner['id']) ?>"
                                           class="btn btn-sm btn-outline-primary">編集</a>
                                        <?php if ((int)$owner['store_count'] === 0): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="owner_id" value="<?= $owner['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('このオーナーを削除しますか？')">
                                                削除
                                            </button>
                                        </form>
                                        <?php endif; ?>
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

    <!-- オーナー追加/編集 -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= $editOwner ? 'オーナーを編集' : 'オーナーを追加' ?></h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="<?= $editOwner ? 'update' : 'create' ?>">
                    <?php if ($editOwner): ?>
                    <input type="hidden" name="owner_id" value="<?= $editOwner['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">オーナー名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               value="<?= h($editOwner['name'] ?? '') ?>"
                               placeholder="例: 山田太郎">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">メールアドレス <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required maxlength="255"
                               value="<?= h($editOwner['email'] ?? '') ?>"
                               placeholder="例: owner@example.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="phone" class="form-control" maxlength="20"
                               value="<?= h($editOwner['phone'] ?? '') ?>"
                               placeholder="例: 090-1234-5678">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <?= $editOwner ? '更新' : '追加' ?>
                        </button>
                        <?php if ($editOwner): ?>
                        <a href="<?= url('/settings/owners') ?>" class="btn btn-secondary">キャンセル</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
