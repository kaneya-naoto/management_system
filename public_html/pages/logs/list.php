<?php
/**
 * 操作ログ一覧
 */
$pageTitle = '操作ログ';

// OWNER権限チェック
requireRole('OWNER');

$user = currentUser();

// OWNER権限の場合、アクセス可能な店舗に紐づくユーザーのログのみ表示
$ownerUserIds = null; // null = フィルタなし（HQ）
if ($user['role'] !== 'HQ') {
    $storeAccess = getAccessibleStoreIds();
    $accessibleStoreIds = $storeAccess['ids'];

    if (empty($accessibleStoreIds)) {
        $ownerUserIds = [$user['id']]; // 店舗なしの場合は自分のログのみ
    } else {
        // アクセス可能な店舗に紐づくユーザーを取得（HQユーザーは除外）
        $inClause = buildInClause($accessibleStoreIds);
        $relatedUsers = dbSelect(
            "SELECT DISTINCT u.id FROM users u
             LEFT JOIN user_stores us ON u.id = us.user_id
             LEFT JOIN owners o ON u.owner_id = o.id
             LEFT JOIN stores s ON o.id = s.owner_id
             WHERE (us.store_id IN ({$inClause['placeholders']})
                OR s.id IN ({$inClause['placeholders']})
                OR u.id = ?)
               AND u.role != 'HQ'",
            array_merge($inClause['params'], $inClause['params'], [$user['id']])
        );
        $ownerUserIds = array_column($relatedUsers, 'id');
        if (empty($ownerUserIds)) {
            $ownerUserIds = [$user['id']];
        }
    }
}

// アクション種別ホワイトリスト
$validActions = ['create', 'update', 'delete', 'login', 'logout', 'assign', 'cancel', 'pay'];

// ターゲット種別ホワイトリスト
$validTargetTypes = ['reservation', 'cleaning_job', 'cleaner', 'user', 'key', 'payment', 'setting'];

// フィルタ
$filterDateFrom = input('date_from', date('Y-m-d', strtotime('-7 days')));
$filterDateTo = input('date_to', date('Y-m-d'));
$filterUserId = (int) input('user_id', 0);
$filterAction = input('action', '');
$filterTargetType = input('target_type', '');

// フィルタ値のバリデーション
if ($filterAction !== '' && !in_array($filterAction, $validActions, true)) {
    $filterAction = '';
}
if ($filterTargetType !== '' && !in_array($filterTargetType, $validTargetTypes, true)) {
    $filterTargetType = '';
}

// 日付バリデーション
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $filterDateFrom = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $filterDateTo = date('Y-m-d');
}

// クエリ条件構築
$whereConditions = [];
$params = [];

$whereConditions[] = "DATE(al.created_at) >= ?";
$params[] = $filterDateFrom;

$whereConditions[] = "DATE(al.created_at) <= ?";
$params[] = $filterDateTo;

if ($filterUserId === -1 && $ownerUserIds === null) {
    // システム（user_id = NULL）— HQのみ使用可能
    $whereConditions[] = "al.user_id IS NULL";
} elseif ($filterUserId > 0) {
    $whereConditions[] = "al.user_id = ?";
    $params[] = $filterUserId;
}

if ($filterAction !== '') {
    $whereConditions[] = "al.action = ?";
    $params[] = $filterAction;
}

if ($filterTargetType !== '') {
    $whereConditions[] = "al.target_type = ?";
    $params[] = $filterTargetType;
}

// OWNER権限: アクセス可能なユーザーのログのみ
if ($ownerUserIds !== null) {
    $ownerInClause = buildInClause($ownerUserIds);
    $whereConditions[] = "al.user_id IN ({$ownerInClause['placeholders']})";
    $params = array_merge($params, $ownerInClause['params']);
}

$whereClause = implode(' AND ', $whereConditions);

// ページネーション
$page = max(1, (int) input('page', 1));
$perPage = 50;

$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM audit_logs al WHERE {$whereClause}",
    $params
);
$totalCount = (int) ($countResult['cnt'] ?? 0);
$pagination = calculatePagination($totalCount, $perPage, $page);

// ログ一覧取得
$logs = dbSelectPaginated(
    "SELECT al.*, u.name as user_name
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE {$whereClause}
     ORDER BY al.created_at DESC",
    $params,
    $perPage,
    $pagination['offset']
);

// ユーザー一覧（フィルタ用）— OWNERはスコープ内のユーザーのみ
if ($ownerUserIds !== null) {
    $filterUserInClause = buildInClause($ownerUserIds);
    $filterUsers = dbSelect(
        "SELECT id, name FROM users WHERE id IN ({$filterUserInClause['placeholders']}) AND deleted_at IS NULL ORDER BY name",
        $filterUserInClause['params']
    );
} else {
    $filterUsers = dbSelect("SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name");
}

// アクション種別
$actionTypes = [
    'create' => '作成',
    'update' => '更新',
    'delete' => '削除',
    'login' => 'ログイン',
    'logout' => 'ログアウト',
    'assign' => '割当',
    'cancel' => 'キャンセル',
    'pay' => '支払い',
];

// ターゲット種別
$targetTypes = [
    'reservation' => '予約',
    'cleaning_job' => '清掃案件',
    'cleaner' => '清掃者',
    'user' => 'ユーザー',
    'key' => '鍵番号',
    'payment' => '支払い',
    'setting' => '設定',
];

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">操作ログ</h1>
</div>

<!-- フィルタ -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">期間（から）</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($filterDateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">期間（まで）</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($filterDateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">操作者</label>
                <select name="user_id" class="form-select">
                    <option value="">すべて</option>
                    <?php if ($ownerUserIds === null): ?>
                    <option value="-1" <?= $filterUserId === -1 ? 'selected' : '' ?>>システム</option>
                    <?php endif; ?>
                    <?php foreach ($filterUsers as $fu): ?>
                    <option value="<?= $fu['id'] ?>" <?= $filterUserId === (int)$fu['id'] ? 'selected' : '' ?>>
                        <?= h($fu['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">操作種別</label>
                <select name="action" class="form-select">
                    <option value="">すべて</option>
                    <?php foreach ($actionTypes as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filterAction === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">対象</label>
                <select name="target_type" class="form-select">
                    <option value="">すべて</option>
                    <?php foreach ($targetTypes as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filterTargetType === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">検索</button>
            </div>
        </form>
    </div>
</div>

<!-- ログ一覧 -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">操作履歴</h5>
        <span class="text-muted"><?= number_format($totalCount) ?>件</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
        <p class="text-muted text-center py-4 mb-0">該当するログがありません</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 150px">日時</th>
                        <th style="width: 120px">操作者</th>
                        <th style="width: 80px">操作</th>
                        <th style="width: 100px">対象</th>
                        <th>詳細</th>
                        <th style="width: 120px">IPアドレス</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $actionLabel = $actionTypes[$log['action']] ?? $log['action'];
                        $targetLabel = $targetTypes[$log['target_type']] ?? $log['target_type'];
                        $actionClass = match($log['action']) {
                            'create' => 'success',
                            'delete', 'cancel' => 'danger',
                            'update', 'assign' => 'primary',
                            'login' => 'info',
                            'pay' => 'warning',
                            default => 'secondary'
                        };

                        // 変更内容の要約
                        $changesSummary = '';
                        if ($log['old_value'] || $log['new_value']) {
                            $oldVal = $log['old_value'] ? json_decode($log['old_value'], true) : [];
                            $newVal = $log['new_value'] ? json_decode($log['new_value'], true) : [];

                            $changes = [];
                            foreach ($newVal as $key => $value) {
                                $oldValue = $oldVal[$key] ?? '(なし)';
                                if ($oldValue !== $value) {
                                    $changes[] = "{$key}: {$oldValue} → {$value}";
                                }
                            }
                            $changesSummary = implode(', ', array_slice($changes, 0, 3));
                            if (count($changes) > 3) {
                                $changesSummary .= ' ...他';
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <small><?= h(formatDateTime($log['created_at'], 'Y/m/d H:i:s')) ?></small>
                        </td>
                        <td>
                            <?php if ($log['user_name']): ?>
                            <?= h($log['user_name']) ?>
                            <?php else: ?>
                            <span class="text-muted">システム</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $actionClass ?>"><?= h($actionLabel) ?></span>
                        </td>
                        <td>
                            <?= h($targetLabel) ?>
                            <?php if ($log['target_id']): ?>
                            <small class="text-muted">#<?= $log['target_id'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= h($changesSummary) ?></small>
                        </td>
                        <td>
                            <small class="text-muted"><?= h($log['ip_address'] ?? '-') ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($pagination['totalPages'] > 1): ?>
<nav class="mt-4">
    <?= renderPagination($pagination['page'], $pagination['totalPages'], [
        'date_from' => $filterDateFrom,
        'date_to' => $filterDateTo,
        'user_id' => $filterUserId,
        'action' => $filterAction,
        'target_type' => $filterTargetType,
    ]) ?>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
