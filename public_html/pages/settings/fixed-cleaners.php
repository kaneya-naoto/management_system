<?php
/**
 * 固定者管理
 */
requireLogin();
requireRole('OWNER');

$pageTitle = '固定者管理';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];
$stores = $filteredStores['stores'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// サイドバーで単一店舗選択時は自動でその店舗を対象にする
$filterStoreId = count($accessibleStoreIds) === 1 ? $accessibleStoreIds[0] : 0;

// 更新処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    // 固定者追加
    if ($action === 'add') {
        $storeId = (int) input('store_id', 0);
        $cleanerId = (int) input('cleaner_id', 0);
        $priority = (int) input('priority', 1);

        if ($storeId <= 0 || !in_array($storeId, $accessibleStoreIds)) {
            $errors[] = '店舗を選択してください';
        }

        if ($cleanerId <= 0) {
            $errors[] = '清掃者を選択してください';
        } else {
            // 清掃者が該当店舗に対応しているか確認
            $cleanerStore = dbSelectOne(
                "SELECT cs.* FROM cleaner_stores cs
                 INNER JOIN cleaners c ON cs.cleaner_id = c.id
                 WHERE cs.cleaner_id = ? AND cs.store_id = ?
                   AND c.is_active = 1 AND c.deleted_at IS NULL",
                [$cleanerId, $storeId]
            );
            if (!$cleanerStore) {
                $errors[] = 'この清掃者は選択した店舗に対応していません';
            }
        }

        if (empty($errors)) {
            // 重複チェックとINSERTをトランザクションで保護
            dbBegin();
            try {
                // FOR UPDATE で重複チェック（ロック付き）
                $existing = dbSelectOne(
                    "SELECT id FROM fixed_cleaners WHERE store_id = ? AND cleaner_id = ? AND deleted_at IS NULL FOR UPDATE",
                    [$storeId, $cleanerId]
                );
                if ($existing) {
                    dbRollback();
                    $errors[] = 'この清掃者は既に固定者として登録されています';
                } else {
                    dbInsert('fixed_cleaners', [
                        'store_id' => $storeId,
                        'cleaner_id' => $cleanerId,
                        'priority' => max(1, min(99, $priority)),
                        'is_active' => 1,
                    ]);
                    dbCommit();
                    flashSuccess('固定者を追加しました');
                    redirect('/settings/fixed-cleaners');
                }
            } catch (Exception $e) {
                dbRollback();
                error_log("Fixed cleaner add failed: " . $e->getMessage());
                $errors[] = '固定者の追加に失敗しました';
            }
        }
    }

    // 優先順位更新
    if ($action === 'update_priority') {
        $fixedCleanerId = (int) input('fixed_cleaner_id', 0);
        $priority = (int) input('priority', 1);

        $fixedCleaner = dbSelectOne(
            "SELECT fc.* FROM fixed_cleaners fc WHERE fc.id = ? AND fc.deleted_at IS NULL",
            [$fixedCleanerId]
        );

        if (!$fixedCleaner || !in_array($fixedCleaner['store_id'], $accessibleStoreIds)) {
            $errors[] = '固定者が見つかりません';
        } else {
            dbUpdate('fixed_cleaners', [
                'priority' => max(1, min(99, $priority)),
            ], 'id = ?', [$fixedCleanerId]);
            flashSuccess('優先順位を更新しました');
            redirect('/settings/fixed-cleaners');
        }
    }

    // 有効/無効切替
    if ($action === 'toggle_active') {
        $fixedCleanerId = (int) input('fixed_cleaner_id', 0);

        $fixedCleaner = dbSelectOne(
            "SELECT fc.* FROM fixed_cleaners fc WHERE fc.id = ? AND fc.deleted_at IS NULL",
            [$fixedCleanerId]
        );

        if (!$fixedCleaner || !in_array($fixedCleaner['store_id'], $accessibleStoreIds)) {
            $errors[] = '固定者が見つかりません';
        } else {
            $newStatus = $fixedCleaner['is_active'] ? 0 : 1;
            dbUpdate('fixed_cleaners', [
                'is_active' => $newStatus,
            ], 'id = ? AND is_active = ?', [$fixedCleanerId, $fixedCleaner['is_active']]);
            flashSuccess($newStatus ? '有効にしました' : '無効にしました');
            redirect('/settings/fixed-cleaners');
        }
    }

    // 削除
    if ($action === 'delete') {
        $fixedCleanerId = (int) input('fixed_cleaner_id', 0);

        $fixedCleaner = dbSelectOne(
            "SELECT fc.* FROM fixed_cleaners fc WHERE fc.id = ? AND fc.deleted_at IS NULL",
            [$fixedCleanerId]
        );

        if (!$fixedCleaner || !in_array($fixedCleaner['store_id'], $accessibleStoreIds)) {
            $errors[] = '固定者が見つかりません';
        } else {
            dbUpdate('fixed_cleaners', [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$fixedCleanerId]);
            flashSuccess('固定者を削除しました');
            redirect('/settings/fixed-cleaners');
        }
    }
}

// 固定者一覧取得
$whereConditions = ["fc.deleted_at IS NULL"];
$params = [];

$inClause = buildInClause($accessibleStoreIds);
$whereConditions[] = "fc.store_id IN ({$inClause['placeholders']})";
$params = array_merge($params, $inClause['params']);

if ($filterStoreId > 0) {
    $whereConditions[] = "fc.store_id = ?";
    $params[] = $filterStoreId;
}

$whereClause = implode(' AND ', $whereConditions);

$fixedCleaners = dbSelect(
    "SELECT fc.*, c.name as cleaner_name, c.phone as cleaner_phone,
            c.is_active as cleaner_active, s.name as store_name
     FROM fixed_cleaners fc
     INNER JOIN cleaners c ON fc.cleaner_id = c.id
     INNER JOIN stores s ON fc.store_id = s.id
     WHERE {$whereClause}
     ORDER BY s.name, fc.priority, c.name",
    $params
);

// 追加用：対応清掃者一覧
// 単一店舗選択時はその店舗、複数店舗時は追加フォームで店舗を選んでからAjaxで取得する想定だが
// 簡易的にPHPで事前取得（単一店舗時のみ）
$availableCleaners = [];
if ($filterStoreId > 0) {
    $availableCleaners = dbSelect(
        "SELECT c.id, c.name, c.phone
         FROM cleaners c
         INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
         WHERE cs.store_id = ?
           AND c.is_active = 1
           AND c.deleted_at IS NULL
           AND c.id NOT IN (
               SELECT cleaner_id FROM fixed_cleaners
               WHERE store_id = ? AND deleted_at IS NULL
           )
         ORDER BY c.name",
        [$filterStoreId, $filterStoreId]
    );
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/settings') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 設定へ戻る</a>
        <h1 class="h3 mb-0">固定者管理</h1>
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
    <!-- 固定者一覧 -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">固定者一覧</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($fixedCleaners)): ?>
                    <p class="text-muted text-center py-4 mb-0">固定者が登録されていません</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>優先度</th>
                                    <th>清掃者</th>
                                    <th>店舗</th>
                                    <th>ステータス</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fixedCleaners as $fc): ?>
                                <tr>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="update_priority">
                                            <input type="hidden" name="fixed_cleaner_id" value="<?= $fc['id'] ?>">
                                            <input type="number" name="priority" value="<?= $fc['priority'] ?>"
                                                   class="form-control form-control-sm" style="width: 60px"
                                                   min="1" max="99" onchange="this.form.submit()">
                                        </form>
                                    </td>
                                    <td>
                                        <strong><?= h($fc['cleaner_name']) ?></strong>
                                        <?php if (!$fc['cleaner_active']): ?>
                                            <span class="badge bg-secondary">休止中</span>
                                        <?php endif; ?>
                                        <small class="text-muted d-block"><?= h($fc['cleaner_phone']) ?></small>
                                    </td>
                                    <td><?= h($fc['store_name']) ?></td>
                                    <td>
                                        <?php if ($fc['is_active']): ?>
                                            <span class="badge bg-success">有効</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">無効</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="fixed_cleaner_id" value="<?= $fc['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <?= $fc['is_active'] ? '無効化' : '有効化' ?>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="fixed_cleaner_id" value="<?= $fc['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('この固定者を削除しますか？')">
                                                削除
                                            </button>
                                        </form>
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

    <!-- 固定者追加 -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">固定者を追加</h5>
            </div>
            <div class="card-body">
                <form method="post" id="addFixedCleanerForm">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label class="form-label">店舗</label>
                        <select name="store_id" id="storeSelect" class="form-select"
                                data-api-url="<?= h(url('/api/settings/available-cleaners')) ?>" required>
                            <option value="">-- 店舗を選択 --</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?= $store['id'] ?>" <?= $filterStoreId == $store['id'] ? 'selected' : '' ?>>
                                <?= h($store['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">清掃者</label>
                        <select name="cleaner_id" id="cleanerSelect" class="form-select" required disabled>
                            <option value="">-- 店舗を先に選択 --</option>
                        </select>
                        <div id="cleanerLoading" class="form-text d-none">読み込み中...</div>
                        <div id="cleanerEmpty" class="form-text text-muted d-none">追加可能な清掃者がいません</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">優先順位</label>
                        <input type="number" name="priority" class="form-control"
                               value="1" min="1" max="99">
                        <div class="form-text">小さい数字ほど優先度が高い</div>
                    </div>

                    <button type="submit" id="submitBtn" class="btn btn-primary w-100" disabled>追加</button>
                </form>
            </div>
        </div>

        <!-- 説明 -->
        <div class="card mt-4">
            <div class="card-body">
                <h6>固定者とは</h6>
                <p class="small text-muted mb-2">
                    店舗ごとに登録された優先清掃者です。新規案件発生時、通常の募集より先に固定者へ優先通知されます。
                </p>
                <h6>優先順位について</h6>
                <p class="small text-muted mb-0">
                    数字が小さいほど優先度が高く、先に通知されます。同じ優先度の場合は登録順です。
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const storeSelect = document.getElementById('storeSelect');
    const cleanerSelect = document.getElementById('cleanerSelect');
    const cleanerLoading = document.getElementById('cleanerLoading');
    const cleanerEmpty = document.getElementById('cleanerEmpty');
    const submitBtn = document.getElementById('submitBtn');
    const apiUrl = storeSelect.dataset.apiUrl;

    // 店舗選択時に清掃者リストを動的取得
    storeSelect.addEventListener('change', async function() {
        const storeId = this.value;

        // リセット
        cleanerSelect.innerHTML = '<option value="">-- 選択 --</option>';
        cleanerSelect.disabled = true;
        submitBtn.disabled = true;
        cleanerLoading.classList.add('d-none');
        cleanerEmpty.classList.add('d-none');

        if (!storeId) {
            cleanerSelect.innerHTML = '<option value="">-- 店舗を先に選択 --</option>';
            return;
        }

        // ローディング表示
        cleanerLoading.classList.remove('d-none');

        try {
            const response = await fetch(apiUrl + '?store_id=' + encodeURIComponent(storeId));
            const data = await response.json();

            cleanerLoading.classList.add('d-none');

            if (data.error) {
                cleanerSelect.innerHTML = '<option value="">-- エラー --</option>';
                return;
            }

            if (data.cleaners.length === 0) {
                cleanerSelect.innerHTML = '<option value="">-- 選択可能な清掃者なし --</option>';
                cleanerEmpty.classList.remove('d-none');
                return;
            }

            // 清掃者リストを構築
            cleanerSelect.innerHTML = '<option value="">-- 選択 --</option>';
            data.cleaners.forEach(function(cleaner) {
                const option = document.createElement('option');
                option.value = cleaner.id;
                option.textContent = cleaner.name;
                cleanerSelect.appendChild(option);
            });
            cleanerSelect.disabled = false;

        } catch (error) {
            cleanerLoading.classList.add('d-none');
            cleanerSelect.innerHTML = '<option value="">-- 読み込みエラー --</option>';
            console.error('Error fetching cleaners:', error);
        }
    });

    // 清掃者選択時に送信ボタンを有効化
    cleanerSelect.addEventListener('change', function() {
        submitBtn.disabled = !this.value;
    });

    // 初期状態で店舗が選択されている場合は自動ロード
    if (storeSelect.value) {
        storeSelect.dispatchEvent(new Event('change'));
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
