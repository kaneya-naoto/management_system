<?php
/**
 * 清掃者詳細・編集
 */
$pageTitle = '清掃者詳細';

// IDは index.php で設定済み
if (!isset($cleanerId) || $cleanerId <= 0) {
    redirect('/cleaners');
}

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];
$stores = $storeAccess['stores'];

// ユーザー権限取得
$user = currentUser();
$isHighRole = $user && in_array($user['role'], ['HQ', 'OWNER'], true);

// 清掃者データ取得
if ($user && $user['role'] === 'HQ') {
    // HQは全清掃者にアクセス可能
    $cleaner = dbSelectOne(
        "SELECT * FROM cleaners WHERE id = ? AND deleted_at IS NULL",
        [$cleanerId]
    );
} elseif ($user && $user['role'] === 'OWNER') {
    // OWNERは自オーナー配下の店舗に紐づいた清掃者のみアクセス可能
    if (empty($user['owner_id'])) {
        // owner_idが未設定の場合はアクセス不可
        $cleaner = null;
    } else {
        $cleaner = dbSelectOne(
            "SELECT c.* FROM cleaners c
             WHERE c.id = ? AND c.deleted_at IS NULL
             AND EXISTS (
                 SELECT 1 FROM cleaner_stores cs
                 INNER JOIN stores s ON cs.store_id = s.id
                 WHERE cs.cleaner_id = c.id
                   AND s.owner_id = ?
                   AND s.deleted_at IS NULL
             )",
            [$cleanerId, $user['owner_id']]
        );
    }
} else {
    // STOREはアクセス可能な店舗に紐づいているか確認
    if (empty($accessibleStoreIds)) {
        $cleaner = null;
    } else {
        $inClause = buildInClause($accessibleStoreIds);
        $cleaner = dbSelectOne(
            "SELECT c.* FROM cleaners c
             WHERE c.id = ? AND c.deleted_at IS NULL
             AND EXISTS (SELECT 1 FROM cleaner_stores cs WHERE cs.cleaner_id = c.id AND cs.store_id IN ({$inClause['placeholders']}))",
            array_merge([$cleanerId], $inClause['params'])
        );
    }
}

if (!$cleaner) {
    flashError('清掃者が見つかりません');
    redirect('/cleaners');
}

// 対応店舗一覧
$cleanerStores = dbSelect(
    "SELECT s.* FROM stores s
     INNER JOIN cleaner_stores cs ON s.id = cs.store_id
     WHERE cs.cleaner_id = ? AND s.deleted_at IS NULL
     ORDER BY s.name",
    [$cleanerId]
);

// 案件履歴
$jobHistory = dbSelectPaginated(
    "SELECT cj.*, s.name as store_name
     FROM cleaning_jobs cj
     LEFT JOIN stores s ON cj.store_id = s.id
     WHERE cj.assigned_cleaner_id = ? AND cj.deleted_at IS NULL
     ORDER BY cj.scheduled_at DESC",
    [$cleanerId],
    10,
    0
);

// 統計
$stats = dbSelectOne(
    "SELECT
        COUNT(*) as total_jobs,
        SUM(CASE WHEN status = 'completed' OR status = 'paid' THEN 1 ELSE 0 END) as completed_jobs,
        SUM(CASE WHEN status = 'paid' THEN base_reward ELSE 0 END) as total_earned
     FROM cleaning_jobs
     WHERE assigned_cleaner_id = ? AND deleted_at IS NULL",
    [$cleanerId]
);

// 固定者として登録されている店舗
$fixedStores = dbSelect(
    "SELECT s.*, fc.priority FROM stores s
     INNER JOIN fixed_cleaners fc ON s.id = fc.store_id
     WHERE fc.cleaner_id = ? AND fc.deleted_at IS NULL AND s.deleted_at IS NULL
     ORDER BY s.name, fc.priority",
    [$cleanerId]
);

// 更新処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    // 基本情報更新
    if ($action === 'update_info') {
        $name = trim(input('name', ''));
        $phone = trim(input('phone', ''));
        $ipassCode = trim(input('ipass_code', ''));

        // バリデーション
        if (empty($name)) {
            $errors[] = '名前は必須です';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = '名前は100文字以内で入力してください';
        }

        if (mb_strlen($phone) > 20) {
            $errors[] = '電話番号は20文字以内で入力してください';
        }

        if (mb_strlen($ipassCode) > 50) {
            $errors[] = 'iPassコードは50文字以内で入力してください';
        }

        if (empty($errors)) {
            dbUpdate('cleaners', [
                'name' => $name,
                'phone' => $phone,
                'ipass_code' => $ipassCode,
            ], 'id = ?', [$cleanerId]);

            flashSuccess('情報を更新しました');
            redirect("/cleaners/{$cleanerId}");
        }
    }

    // ステータス変更（原子的更新で競合を防止）
    if ($action === 'toggle_active') {
        $currentStatus = $cleaner['is_active'] ? 1 : 0;
        $newStatus = $currentStatus ? 0 : 1;

        // 稼働中にする場合は対応店舗が1つ以上必要
        if ($newStatus === 1) {
            $storeCount = dbSelectOne(
                "SELECT COUNT(*) as cnt FROM cleaner_stores WHERE cleaner_id = ?",
                [$cleanerId]
            );

            if (!$storeCount || $storeCount['cnt'] == 0) {
                $errors[] = '対応店舗が設定されていないため、稼働中にできません。先に対応店舗を設定してください。';
            }
        }

        if (empty($errors)) {
            // 楽観ロック: 現在のステータスを条件に含めて更新
            $rowCount = dbUpdate(
                'cleaners',
                ['is_active' => $newStatus],
                'id = ? AND is_active = ?',
                [$cleanerId, $currentStatus]
            );

            if ($rowCount === 0) {
                $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
            } else {
                $statusMsg = $newStatus ? '稼働中に変更しました' : '休止中に変更しました';
                flashSuccess($statusMsg);
                redirect("/cleaners/{$cleanerId}");
            }
        }
    }

    // 対応店舗更新
    if ($action === 'update_stores') {
        $selectedStoreIds = input('store_ids', []);

        if (!is_array($selectedStoreIds)) {
            $selectedStoreIds = [];
        }

        // 整数型に変換＆バリデーション＆重複排除
        $validStoreIds = [];
        foreach ($selectedStoreIds as $storeId) {
            $storeId = (int)$storeId;
            if ($storeId > 0 && in_array($storeId, $accessibleStoreIds, true)) {
                $validStoreIds[] = $storeId;
            }
        }
        // 重複排除
        $validStoreIds = array_unique($validStoreIds);

        // トランザクションで一括処理（レースコンディション対策）
        dbBegin();
        try {
            // 一括削除（アクセス可能な店舗のみ）
            $deleteInClause = buildInClause($accessibleStoreIds);
            getDb()->prepare(
                "DELETE FROM cleaner_stores
                 WHERE cleaner_id = ? AND store_id IN ({$deleteInClause['placeholders']})"
            )->execute(array_merge([$cleanerId], $deleteInClause['params']));

            // 一括挿入
            if (!empty($validStoreIds)) {
                $values = [];
                $params = [];
                foreach ($validStoreIds as $storeId) {
                    $values[] = "(?, ?)";
                    $params[] = $cleanerId;
                    $params[] = $storeId;
                }
                $sql = "INSERT INTO cleaner_stores (cleaner_id, store_id) VALUES " . implode(", ", $values);
                getDb()->prepare($sql)->execute($params);
            }

            dbCommit();
            flashSuccess('対応店舗を更新しました');
            redirect("/cleaners/{$cleanerId}");
        } catch (Exception $e) {
            dbRollback();
            error_log("Store update failed for cleaner {$cleanerId}: " . $e->getMessage());
            $errors[] = '店舗設定の更新に失敗しました。時間をおいて再度お試しください。';
        }
    }
}

// 対応店舗IDリスト（モーダル用）
$cleanerStoreIds = array_column($cleanerStores, 'id');

// CSRFトークンを1回だけ生成
$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/cleaners') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 清掃者一覧へ戻る</a>
        <h1 class="h3 mb-0"><?= h($cleaner['name']) ?></h1>
    </div>
    <div>
        <?php if ($cleaner['is_active']): ?>
            <span class="badge bg-success fs-6">稼働中</span>
        <?php else: ?>
            <span class="badge bg-secondary fs-6">休止中</span>
        <?php endif; ?>
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

<!-- 統計カード -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-body text-center">
                <h4 class="mb-0"><?= $stats['total_jobs'] ?? 0 ?></h4>
                <small class="text-muted">総案件数</small>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <h4 class="mb-0 text-success"><?= $stats['completed_jobs'] ?? 0 ?></h4>
                <small class="text-muted">完了案件</small>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <h4 class="mb-0 text-info"><?= formatMoney($stats['total_earned'] ?? 0) ?></h4>
                <small class="text-muted">累計報酬（支払済）</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- 基本情報 -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">基本情報</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal">
                    編集
                </button>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">名前</th>
                                <td><strong><?= h($cleaner['name']) ?></strong></td>
                            </tr>
                            <tr>
                                <th class="text-muted">電話番号</th>
                                <td><?= h($cleaner['phone'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">iPassコード</th>
                                <td>
                                    <?php if ($cleaner['ipass_code']): ?>
                                    <code><?= h($cleaner['ipass_code']) ?></code>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">LINE ID</th>
                                <td>
                                    <?php if ($cleaner['line_user_id']): ?>
                                    <span class="badge bg-success">連携済</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">未連携</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">登録日</th>
                                <td><?= h(formatDate($cleaner['registered_at'])) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">最終更新</th>
                                <td><?= h(formatDateTime($cleaner['updated_at'])) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 対応店舗 -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">対応店舗</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#storeModal">
                    編集
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($cleanerStores)): ?>
                    <p class="text-muted mb-0">対応店舗が設定されていません</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($cleanerStores as $store): ?>
                        <span class="badge bg-primary"><?= h($store['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 固定者設定 -->
        <?php if (!empty($fixedStores)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">固定者として登録</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>店舗</th>
                                <th>優先度</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fixedStores as $fs): ?>
                            <tr>
                                <td><?= h($fs['name']) ?></td>
                                <td><?= (int)$fs['priority'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 案件履歴 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">最近の案件履歴</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($jobHistory)): ?>
                    <p class="text-muted text-center py-4 mb-0">案件履歴がありません</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>日時</th>
                                    <th>店舗</th>
                                    <th>ステータス</th>
                                    <th>報酬</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobHistory as $job): ?>
                                <tr>
                                    <td><?= h(formatDateTime($job['scheduled_at'])) ?></td>
                                    <td><?= h($job['store_name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= statusClass($job['status']) ?>">
                                            <?= statusLabel($job['status'], 'job') ?>
                                        </span>
                                    </td>
                                    <td><?= formatMoney($job['base_reward']) ?></td>
                                    <td>
                                        <a href="<?= url('/jobs/' . $job['id']) ?>" class="btn btn-sm btn-outline-primary">詳細</a>
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

    <!-- サイドバー -->
    <div class="col-lg-4">
        <!-- ステータス変更 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">ステータス</h5>
            </div>
            <div class="card-body">
                <p class="mb-3">
                    現在:
                    <?php if ($cleaner['is_active']): ?>
                        <span class="badge bg-success">稼働中</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">休止中</span>
                    <?php endif; ?>
                </p>
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="toggle_active">
                    <?php if ($cleaner['is_active']): ?>
                        <button type="submit" class="btn btn-outline-secondary w-100">休止中にする</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-success w-100">稼働中にする</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- LINE連携 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">LINE連携</h5>
            </div>
            <div class="card-body">
                <?php if ($cleaner['line_user_id']): ?>
                    <p class="text-success mb-2"><i class="bi bi-check-circle"></i> 連携済み</p>
                    <small class="text-muted">LINE ID: <?= h(substr($cleaner['line_user_id'], 0, 10)) ?>...</small>
                <?php else: ?>
                    <p class="text-muted mb-0">LINE未連携</p>
                    <small class="text-muted">清掃者がLINEで友だち追加すると自動的に連携されます。</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 編集モーダル -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update_info">
                <div class="modal-header">
                    <h5 class="modal-title">基本情報編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">名前 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= h($cleaner['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">電話番号</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= h($cleaner['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">iPassコード</label>
                        <input type="text" name="ipass_code" class="form-control"
                               value="<?= h($cleaner['ipass_code'] ?? '') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 対応店舗モーダル -->
<div class="modal fade" id="storeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update_stores">
                <div class="modal-header">
                    <h5 class="modal-title">対応店舗設定</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">この清掃者が対応可能な店舗を選択してください。</p>
                    <?php foreach ($stores as $store): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="store_ids[]"
                               value="<?= $store['id'] ?>" id="store_<?= $store['id'] ?>"
                               <?= in_array($store['id'], $cleanerStoreIds) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="store_<?= $store['id'] ?>">
                            <?= h($store['name']) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
