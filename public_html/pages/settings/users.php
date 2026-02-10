<?php
/**
 * ユーザー管理
 */
$pageTitle = 'ユーザー管理';

// OWNER権限チェック
requireRole('OWNER');

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];
$stores = $storeAccess['stores'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

$inClause = buildInClause($accessibleStoreIds);

// 現在のユーザー情報（権限チェック用）
$currentUser = currentUser();

// ロール階層レベル（数値が大きいほど上位）
$roleLevel = ['STORE' => 1, 'OWNER' => 2, 'HQ' => 3];

// オーナー一覧を取得（OWNERロール作成時に使用）
// OWNERは自分の所属オーナーのみ選択可能
if ($currentUser['role'] === 'HQ') {
    $owners = dbSelect("SELECT id, name FROM owners WHERE deleted_at IS NULL ORDER BY name");
} else {
    $owners = $currentUser['owner_id']
        ? dbSelect("SELECT id, name FROM owners WHERE id = ? AND deleted_at IS NULL", [$currentUser['owner_id']])
        : [];
}

// OWNERが作成・編集可能なロール
$allowedRoles = ($currentUser['role'] === 'HQ')
    ? ['STORE' => '店舗スタッフ', 'OWNER' => 'オーナー']
    : ['STORE' => '店舗スタッフ'];

// ユーザー一覧取得（自分の店舗に紐づくユーザー）
// OWNERはHQユーザーを閲覧不可
$roleFilter = ($currentUser['role'] !== 'HQ') ? "AND u.role != 'HQ'" : '';
$users = dbSelect(
    "SELECT DISTINCT u.*, s.name as default_store_name, o.name as owner_name
     FROM users u
     LEFT JOIN stores s ON u.store_id = s.id
     LEFT JOIN owners o ON u.owner_id = o.id
     WHERE (u.store_id IN ({$inClause['placeholders']})
            OR EXISTS (SELECT 1 FROM user_stores us WHERE us.user_id = u.id AND us.store_id IN ({$inClause['placeholders']})))
       AND u.deleted_at IS NULL
       {$roleFilter}
     ORDER BY u.role, u.name",
    array_merge($inClause['params'], $inClause['params'])
);

// ロール表示
$roleLabels = [
    'HQ' => ['label' => '本部', 'class' => 'danger'],
    'OWNER' => ['label' => 'オーナー', 'class' => 'primary'],
    'STORE' => ['label' => '店舗スタッフ', 'class' => 'secondary'],
];

// ステータス表示
$statusLabels = [
    'active' => ['label' => '有効', 'class' => 'success'],
    'inactive' => ['label' => '無効', 'class' => 'secondary'],
    'suspended' => ['label' => '停止', 'class' => 'danger'],
];

$errors = [];

// ユーザー操作処理
if (isPost()) {
    requireCsrf();
    $action = input('action', '');

    // 新規ユーザー追加
    if ($action === 'add_user') {
        $email = trim(input('email', ''));
        $name = trim(input('name', ''));
        $password = input('password', '');
        $role = input('role', 'STORE');
        $ownerId = (int) input('owner_id', 0);
        $storeId = (int) input('store_id', 0);

        // バリデーション
        if (empty($email)) {
            $errors[] = 'メールアドレスを入力してください';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません';
        }

        if (empty($name)) {
            $errors[] = '氏名を入力してください';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = '氏名は100文字以内で入力してください';
        }

        if (empty($password)) {
            $errors[] = 'パスワードを入力してください';
        } elseif (strlen($password) < 8) {
            $errors[] = 'パスワードは8文字以上で入力してください';
        }

        if (!in_array($role, ['OWNER', 'STORE'], true)) {
            $role = 'STORE';
        }

        // OWNERロールの場合、STOREユーザーのみ作成可能
        if ($currentUser['role'] !== 'HQ' && $role !== 'STORE') {
            $errors[] = 'この権限では店舗スタッフのみ追加できます';
            $role = 'STORE';
        }

        // OWNERロールの場合はowner_idが必須
        if ($role === 'OWNER' && $ownerId <= 0) {
            $errors[] = 'OWNERロールの場合は所属オーナーを選択してください';
        }

        // owner_idの存在確認
        if ($ownerId > 0) {
            $ownerExists = dbSelectOne("SELECT id FROM owners WHERE id = ? AND deleted_at IS NULL", [$ownerId]);
            if (!$ownerExists) {
                $errors[] = '指定されたオーナーが存在しません';
            }
        }

        // OWNERは自分のowner_id以外を指定不可
        if ($currentUser['role'] !== 'HQ' && $ownerId > 0 && $ownerId !== (int)$currentUser['owner_id']) {
            $errors[] = '他のオーナーへのユーザー追加はできません';
        }

        if ($storeId > 0 && !in_array($storeId, $accessibleStoreIds)) {
            $errors[] = '店舗の指定が不正です';
        }

        // メール重複チェック
        if (empty($errors)) {
            $existing = dbSelectOne("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$email]);
            if ($existing) {
                $errors[] = 'このメールアドレスは既に登録されています';
            }
        }

        if (empty($errors)) {
            dbBegin();
            try {
                $userId = dbInsert('users', [
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'name' => $name,
                    'role' => $role,
                    'owner_id' => ($role === 'OWNER' && $ownerId > 0) ? $ownerId : null,
                    'store_id' => $storeId > 0 ? $storeId : null,
                    'status' => 'active',
                ]);

                // 店舗が指定されている場合はuser_storesにも追加
                if ($storeId > 0) {
                    dbInsert('user_stores', [
                        'user_id' => $userId,
                        'store_id' => $storeId,
                    ]);
                }

                // 監査ログ記録
                logAudit(
                    'create',
                    'user',
                    $userId,
                    null,
                    ['email' => $email, 'name' => $name, 'role' => $role]
                );

                dbCommit();
                flashSuccess('ユーザーを追加しました');
                redirect('/settings/users');
            } catch (Exception $e) {
                dbRollback();
                $errors[] = 'ユーザーの追加に失敗しました';
                error_log("User add error: " . $e->getMessage());
            }
        }
    }

    // ユーザー更新
    if ($action === 'update_user') {
        $userId = (int) input('user_id', 0);
        $name = trim(input('name', ''));
        $role = input('role', 'STORE');
        $ownerId = (int) input('owner_id', 0);
        $status = input('status', 'active');
        $storeId = (int) input('store_id', 0);
        $newPassword = input('new_password', '');

        // 対象ユーザーがアクセス可能な店舗に属しているか確認（IDOR対策）
        $targetUser = dbSelectOne(
            "SELECT DISTINCT u.* FROM users u
             LEFT JOIN stores s ON u.store_id = s.id
             LEFT JOIN user_stores us ON u.id = us.user_id
             WHERE u.id = ? AND u.deleted_at IS NULL
               AND (u.store_id IN ({$inClause['placeholders']})
                    OR EXISTS (SELECT 1 FROM user_stores us2 WHERE us2.user_id = u.id AND us2.store_id IN ({$inClause['placeholders']})))",
            array_merge([$userId], $inClause['params'], $inClause['params'])
        );

        if (!$targetUser) {
            $errors[] = 'ユーザーが見つかりません';
        }

        if ($targetUser) {
            // ロール階層チェック: 自分より上位または同格のロールは編集不可（自分自身は除く）
            if ($targetUser['id'] !== $currentUser['id']
                && ($roleLevel[$targetUser['role']] ?? 0) >= ($roleLevel[$currentUser['role']] ?? 0)) {
                $errors[] = 'この権限のユーザーは編集できません';
            }

            // 自分自身のロール・ステータスは変更不可
            if ($targetUser['id'] === $currentUser['id']) {
                $role = $targetUser['role'];
                $status = $targetUser['status'];
            }
        }

        // store_idがアクセス可能な店舗かチェック
        if ($storeId > 0 && !in_array($storeId, $accessibleStoreIds)) {
            $errors[] = '指定された店舗へのアクセス権限がありません';
        }

        if (empty($name)) {
            $errors[] = '氏名を入力してください';
        }

        if (!in_array($role, ['OWNER', 'STORE'], true)) {
            $role = 'STORE';
        }

        // OWNERロールの場合、STOREユーザーのみに変更可能
        if ($currentUser['role'] !== 'HQ' && $role !== 'STORE') {
            $errors[] = 'この権限では店舗スタッフのみ設定できます';
            $role = 'STORE';
        }

        // OWNERロールの場合はowner_idが必須
        if ($role === 'OWNER' && $ownerId <= 0) {
            $errors[] = 'OWNERロールの場合は所属オーナーを選択してください';
        }

        // owner_idの存在確認
        if ($ownerId > 0) {
            $ownerExists = dbSelectOne("SELECT id FROM owners WHERE id = ? AND deleted_at IS NULL", [$ownerId]);
            if (!$ownerExists) {
                $errors[] = '指定されたオーナーが存在しません';
            }
        }

        // OWNERは自分のowner_id以外を指定不可
        if ($currentUser['role'] !== 'HQ' && $ownerId > 0 && $ownerId !== (int)$currentUser['owner_id']) {
            $errors[] = '他のオーナーへのユーザー移動はできません';
        }

        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $status = 'active';
        }

        if (!empty($newPassword) && strlen($newPassword) < 8) {
            $errors[] = 'パスワードは8文字以上で入力してください';
        }

        if (empty($errors)) {
            $updateData = [
                'name' => $name,
                'role' => $role,
                'owner_id' => ($role === 'OWNER' && $ownerId > 0) ? $ownerId : null,
                'status' => $status,
                'store_id' => $storeId > 0 ? $storeId : null,
            ];

            if (!empty($newPassword)) {
                $updateData['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateData['password_changed_at'] = date('Y-m-d H:i:s');
            }

            dbUpdate('users', $updateData, 'id = ?', [$userId]);

            // 監査ログ記録
            logAudit(
                'update',
                'user',
                $userId,
                ['name' => $targetUser['name'], 'role' => $targetUser['role'], 'status' => $targetUser['status']],
                ['name' => $name, 'role' => $role, 'status' => $status]
            );

            flashSuccess('ユーザー情報を更新しました');
            redirect('/settings/users');
        }
    }

    // ユーザー削除
    if ($action === 'delete_user') {
        $userId = (int) input('user_id', 0);

        if ($userId === $currentUser['id']) {
            $errors[] = '自分自身は削除できません';
        } else {
            // 対象ユーザーがアクセス可能な店舗に属しているか確認（IDOR対策）
            $targetUser = dbSelectOne(
                "SELECT DISTINCT u.id, u.role FROM users u
                 WHERE u.id = ? AND u.deleted_at IS NULL
                   AND (u.store_id IN ({$inClause['placeholders']})
                        OR EXISTS (SELECT 1 FROM user_stores us WHERE us.user_id = u.id AND us.store_id IN ({$inClause['placeholders']})))",
                array_merge([$userId], $inClause['params'], $inClause['params'])
            );

            // ロール階層チェック: 自分より上位または同格のロールは削除不可
            if ($targetUser && ($roleLevel[$targetUser['role']] ?? 0) >= ($roleLevel[$currentUser['role']] ?? 0)) {
                $errors[] = 'この権限のユーザーは削除できません';
                $targetUser = null; // 以降の処理をスキップ
            }

            if ($targetUser) {
                dbBegin();
                try {
                    // ユーザー論理削除
                    dbUpdate('users', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$userId]);

                    // 関連するuser_storesも論理削除相当の処理（レコード削除）
                    dbExecute("DELETE FROM user_stores WHERE user_id = ?", [$userId]);

                    // 監査ログ記録
                    logAudit('delete', 'user', $userId, null, null);

                    dbCommit();
                    flashSuccess('ユーザーを削除しました');
                    redirect('/settings/users');
                } catch (Exception $e) {
                    dbRollback();
                    $errors[] = 'ユーザーの削除に失敗しました';
                    error_log("User delete error: " . $e->getMessage());
                }
            } else {
                $errors[] = 'ユーザーが見つかりません';
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$currentUserId = $currentUser['id'];

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/settings') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 設定へ戻る</a>
        <h1 class="h3 mb-0">ユーザー管理</h1>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-plus"></i> ユーザー追加
    </button>
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

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
        <p class="text-muted text-center py-4 mb-0">ユーザーがいません</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>氏名</th>
                        <th>メールアドレス</th>
                        <th>権限</th>
                        <th>デフォルト店舗</th>
                        <th>状態</th>
                        <th>最終ログイン</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user):
                        $roleInfo = $roleLabels[$user['role']] ?? ['label' => $user['role'], 'class' => 'secondary'];
                        $statusInfo = $statusLabels[$user['status']] ?? ['label' => $user['status'], 'class' => 'secondary'];
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($user['name']) ?></strong>
                            <?php if ($user['id'] === $currentUserId): ?>
                            <span class="badge bg-info">あなた</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($user['email']) ?></td>
                        <td><span class="badge bg-<?= $roleInfo['class'] ?>"><?= $roleInfo['label'] ?></span></td>
                        <td><?= h($user['default_store_name'] ?? '-') ?></td>
                        <td><span class="badge bg-<?= $statusInfo['class'] ?>"><?= $statusInfo['label'] ?></span></td>
                        <td>
                            <?php if ($user['last_login_at']): ?>
                            <small><?= h(formatDate($user['last_login_at'], 'Y/n/j H:i')) ?></small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                                    data-id="<?= $user['id'] ?>"
                                    data-email="<?= h($user['email']) ?>"
                                    data-name="<?= h($user['name']) ?>"
                                    data-role="<?= h($user['role']) ?>"
                                    data-owner-id="<?= (int)$user['owner_id'] ?>"
                                    data-status="<?= h($user['status']) ?>"
                                    data-store-id="<?= (int)$user['store_id'] ?>"
                                    data-is-current="<?= $user['id'] === $currentUserId ? '1' : '0' ?>">
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

<!-- ユーザー追加モーダル -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="add_user">

                <div class="modal-header">
                    <h5 class="modal-title">ユーザーを追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">メールアドレス <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">氏名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">パスワード <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                        <div class="form-text">8文字以上</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">権限</label>
                        <select name="role" id="addUserRole" class="form-select">
                            <?php foreach ($allowedRoles as $roleKey => $roleLabel): ?>
                            <option value="<?= $roleKey ?>"><?= h($roleLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="addOwnerGroup" style="display: none;">
                        <label class="form-label">所属オーナー <span class="text-danger">*</span></label>
                        <select name="owner_id" id="addOwnerId" class="form-select">
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($owners as $owner): ?>
                            <option value="<?= $owner['id'] ?>"><?= h($owner['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">OWNERロールの場合は必須です</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">デフォルト店舗</label>
                        <select name="store_id" class="form-select">
                            <option value="">指定なし</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?= $store['id'] ?>"><?= h($store['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
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

<!-- ユーザー編集モーダル -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">

                <div class="modal-header">
                    <h5 class="modal-title">ユーザーを編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" id="editUserEmail" class="form-control" disabled>
                        <div class="form-text">メールアドレスは変更できません</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">氏名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editUserName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">新しいパスワード</label>
                        <input type="password" name="new_password" class="form-control" minlength="8">
                        <div class="form-text">変更する場合のみ入力（8文字以上）</div>
                    </div>
                    <div class="mb-3" id="editRoleGroup">
                        <label class="form-label">権限</label>
                        <select name="role" id="editUserRole" class="form-select">
                            <?php foreach ($allowedRoles as $roleKey => $roleLabel): ?>
                            <option value="<?= $roleKey ?>"><?= h($roleLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="editOwnerGroup" style="display: none;">
                        <label class="form-label">所属オーナー <span class="text-danger">*</span></label>
                        <select name="owner_id" id="editUserOwnerId" class="form-select">
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($owners as $owner): ?>
                            <option value="<?= $owner['id'] ?>"><?= h($owner['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">OWNERロールの場合は必須です</div>
                    </div>
                    <div class="mb-3" id="editStatusGroup">
                        <label class="form-label">状態</label>
                        <select name="status" id="editUserStatus" class="form-select">
                            <option value="active">有効</option>
                            <option value="inactive">無効</option>
                            <option value="suspended">停止</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">デフォルト店舗</label>
                        <select name="store_id" id="editUserStoreId" class="form-select">
                            <option value="">指定なし</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?= $store['id'] ?>"><?= h($store['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="submit" class="btn btn-outline-danger" id="deleteUserBtn" name="action" value="delete_user"
                            onclick="return confirm('このユーザーを削除しますか？')">
                        削除
                    </button>
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">保存</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ロール選択時のオーナー表示切替関数
    function toggleOwnerSelect(roleSelect, ownerGroup) {
        ownerGroup.style.display = roleSelect.value === 'OWNER' ? 'block' : 'none';
    }

    // 追加フォームのロール切替
    const addUserRole = document.getElementById('addUserRole');
    const addOwnerGroup = document.getElementById('addOwnerGroup');
    addUserRole.addEventListener('change', function() {
        toggleOwnerSelect(this, addOwnerGroup);
    });

    // 編集フォームのロール切替
    const editUserRole = document.getElementById('editUserRole');
    const editOwnerGroup = document.getElementById('editOwnerGroup');
    editUserRole.addEventListener('change', function() {
        toggleOwnerSelect(this, editOwnerGroup);
    });

    // 編集モーダル表示時の処理
    const editModal = document.getElementById('editUserModal');
    editModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const isCurrent = button.dataset.isCurrent === '1';
        const role = button.dataset.role;

        document.getElementById('editUserId').value = button.dataset.id;
        document.getElementById('editUserEmail').value = button.dataset.email;
        document.getElementById('editUserName').value = button.dataset.name;
        document.getElementById('editUserRole').value = role;
        document.getElementById('editUserOwnerId').value = button.dataset.ownerId || '';
        document.getElementById('editUserStatus').value = button.dataset.status;
        document.getElementById('editUserStoreId').value = button.dataset.storeId;

        // 自分自身の場合は権限・状態・削除を無効化
        document.getElementById('editRoleGroup').style.display = isCurrent ? 'none' : 'block';
        document.getElementById('editStatusGroup').style.display = isCurrent ? 'none' : 'block';
        document.getElementById('deleteUserBtn').style.display = isCurrent ? 'none' : 'inline-block';

        // OWNERロールの場合はオーナー選択を表示
        editOwnerGroup.style.display = (role === 'OWNER' && !isCurrent) ? 'block' : 'none';
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
