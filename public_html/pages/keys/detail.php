<?php
/**
 * 鍵番号詳細・編集
 */
$pageTitle = '鍵番号詳細';

// IDは index.php で設定済み
if (!isset($keyId) || $keyId <= 0) {
    redirect('/keys');
}

// アクセス可能な店舗を取得
$storeAccess = getAccessibleStoreIds();
$accessibleStoreIds = $storeAccess['ids'];

// 鍵データ取得
$inClause = buildInClause($accessibleStoreIds);
$key = dbSelectOne(
    "SELECT k.*, sa.name as area_name, sa.store_id, s.name as store_name
     FROM `keys` k
     INNER JOIN sales_areas sa ON k.sales_area_id = sa.id
     INNER JOIN stores s ON sa.store_id = s.id
     WHERE k.id = ? AND k.deleted_at IS NULL
       AND sa.store_id IN ({$inClause['placeholders']})",
    array_merge([$keyId], $inClause['params'])
);

if (!$key) {
    flashError('鍵が見つかりません');
    redirect('/keys');
}

// 使用履歴（直近30件）
$usageHistory = dbSelectPaginated(
    "SELECT ka.*, r.reservation_date, r.start_time, r.end_time, r.customer_name, r.status as reservation_status
     FROM key_assignments ka
     INNER JOIN reservations r ON ka.reservation_id = r.id
     WHERE ka.key_id = ?
     ORDER BY r.reservation_date DESC, r.start_time DESC",
    [$keyId],
    30,
    0
);

// 今日の割当状況
$today = date('Y-m-d');
$todayAssignments = dbSelect(
    "SELECT ka.*, r.start_time, r.end_time, r.customer_name, r.status
     FROM key_assignments ka
     INNER JOIN reservations r ON ka.reservation_id = r.id
     WHERE ka.key_id = ?
       AND r.reservation_date = ?
       AND r.status NOT IN ('cancelled')
     ORDER BY r.start_time",
    [$keyId, $today]
);

// 更新処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    // 情報更新
    if ($action === 'update_info') {
        $keyNumber = trim(input('key_number', ''));
        $notes = trim(input('notes', ''));

        // バリデーション
        if (empty($keyNumber)) {
            $errors[] = '鍵番号は必須です';
        } elseif (mb_strlen($keyNumber) > 50) {
            $errors[] = '鍵番号は50文字以内で入力してください';
        }

        if (mb_strlen($notes) > 500) {
            $errors[] = '備考は500文字以内で入力してください';
        }

        // 重複チェック（自分以外）
        if (empty($errors)) {
            $existing = dbSelectOne(
                "SELECT id FROM `keys` WHERE sales_area_id = ? AND key_number = ? AND id != ? AND deleted_at IS NULL",
                [$key['sales_area_id'], $keyNumber, $keyId]
            );
            if ($existing) {
                $errors[] = 'この鍵番号は既に使用されています';
            }
        }

        if (empty($errors)) {
            dbUpdate('keys', [
                'key_number' => $keyNumber,
                'notes' => $notes ?: null,
            ], 'id = ?', [$keyId]);

            flashSuccess('情報を更新しました');
            redirect("/keys/{$keyId}");
        }
    }

    // 有効/無効切り替え
    if ($action === 'toggle_active') {
        $currentStatus = $key['is_active'] ? 1 : 0;
        $newStatus = $currentStatus ? 0 : 1;

        // 楽観ロック
        $rowCount = dbUpdate(
            'keys',
            ['is_active' => $newStatus],
            'id = ? AND is_active = ?',
            [$keyId, $currentStatus]
        );

        if ($rowCount === 0) {
            $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
        } else {
            $statusMsg = $newStatus ? '有効にしました' : '無効にしました';
            flashSuccess($statusMsg);
            redirect("/keys/{$keyId}");
        }
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/keys') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 鍵一覧へ戻る</a>
        <h1 class="h3 mb-0">鍵番号: <?= h($key['key_number']) ?></h1>
    </div>
    <div>
        <?php if ($key['is_active']): ?>
            <span class="badge bg-success fs-6">有効</span>
        <?php else: ?>
            <span class="badge bg-secondary fs-6">無効</span>
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

<div class="row">
    <!-- 鍵情報 -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">基本情報</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal">
                    編集
                </button>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th class="text-muted" style="width: 30%">鍵番号</th>
                        <td><strong><?= h($key['key_number']) ?></strong></td>
                    </tr>
                    <tr>
                        <th class="text-muted">店舗</th>
                        <td><?= h($key['store_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">営業区分</th>
                        <td><?= h($key['area_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">備考</th>
                        <td><?= h($key['notes'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">登録日時</th>
                        <td><?= h(formatDateTime($key['created_at'])) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 本日の割当状況 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">本日の割当状況 (<?= $today ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($todayAssignments)): ?>
                    <p class="text-muted text-center py-4 mb-0">本日の割当はありません</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>時間</th>
                                    <th>顧客</th>
                                    <th>ステータス</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayAssignments as $assign): ?>
                                <tr>
                                    <td>
                                        <?= h(formatTime($assign['start_time'])) ?> - <?= h(formatTime($assign['end_time'])) ?>
                                    </td>
                                    <td><?= h($assign['customer_name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= statusClass($assign['status']) ?>">
                                            <?= statusLabel($assign['status'], 'reservation') ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 使用履歴 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">使用履歴（直近30件）</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($usageHistory)): ?>
                    <p class="text-muted text-center py-4 mb-0">使用履歴がありません</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>予約日</th>
                                    <th>時間</th>
                                    <th>顧客</th>
                                    <th>ステータス</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usageHistory as $history): ?>
                                <tr>
                                    <td><?= h(formatDate($history['reservation_date'])) ?></td>
                                    <td>
                                        <?= h(formatTime($history['start_time'])) ?> - <?= h(formatTime($history['end_time'])) ?>
                                    </td>
                                    <td><?= h($history['customer_name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= statusClass($history['reservation_status']) ?>">
                                            <?= statusLabel($history['reservation_status'], 'reservation') ?>
                                        </span>
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
                    <?php if ($key['is_active']): ?>
                        <span class="badge bg-success">有効</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">無効</span>
                    <?php endif; ?>
                </p>
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="toggle_active">
                    <?php if ($key['is_active']): ?>
                        <button type="submit" class="btn btn-outline-secondary w-100"
                                onclick="return confirm('この鍵を無効にしますか？新規予約への割当ができなくなります。')">
                            無効にする
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-success w-100">有効にする</button>
                    <?php endif; ?>
                </form>
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
                    <h5 class="modal-title">鍵情報編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">鍵番号 <span class="text-danger">*</span></label>
                        <input type="text" name="key_number" class="form-control"
                               value="<?= h($key['key_number']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="notes" class="form-control" rows="3"><?= h($key['notes'] ?? '') ?></textarea>
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
