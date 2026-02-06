<?php
/**
 * 鍵番号一覧
 */
$pageTitle = '鍵番号管理';

// フィルタ済みの店舗IDを取得（サイドバーで選択された店舗）
$filteredStores = getFilteredStoreIds();
$accessibleStoreIds = $filteredStores['ids'];

if (empty($accessibleStoreIds)) {
    flashError('アクセス可能な店舗がありません');
    redirect('/dashboard');
}

// 営業区分一覧を取得
$salesAreas = getAccessibleSalesAreas($accessibleStoreIds);

// フィルタ
$filterSalesAreaId = (int) input('sales_area_id', 0);
$filterStatus = input('status', '');
$page = max(1, (int) input('page', 1));
$perPage = 20;

// クエリ構築
$whereConditions = ["k.deleted_at IS NULL"];
$params = [];

// 営業区分フィルタ
if ($filterSalesAreaId > 0) {
    $whereConditions[] = "k.sales_area_id = ?";
    $params[] = $filterSalesAreaId;
} else {
    // アクセス可能な営業区分に限定
    $areaIds = array_column($salesAreas, 'id');
    if (empty($areaIds)) {
        // 営業区分がない場合は結果なし
        $whereConditions[] = "0 = 1";
    } else {
        $areaInClause = buildInClause($areaIds);
        $whereConditions[] = "k.sales_area_id IN ({$areaInClause['placeholders']})";
        $params = array_merge($params, $areaInClause['params']);
    }
}

// 有効/無効フィルタ
if ($filterStatus === 'active') {
    $whereConditions[] = "k.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $whereConditions[] = "k.is_active = 0";
}

$whereClause = implode(' AND ', $whereConditions);

// 件数取得
$countResult = dbSelectOne(
    "SELECT COUNT(*) as cnt FROM `keys` k WHERE {$whereClause}",
    $params
);
$totalCount = $countResult['cnt'] ?? 0;
$pagination = calculatePagination($totalCount, $perPage, $page);

// データ取得
$keys = dbSelectPaginated(
    "SELECT k.*, sa.name as area_name, s.name as store_name
     FROM `keys` k
     INNER JOIN sales_areas sa ON k.sales_area_id = sa.id
     INNER JOIN stores s ON sa.store_id = s.id
     WHERE {$whereClause}
     ORDER BY s.name, sa.name, k.key_number",
    $params,
    $perPage,
    $pagination['offset']
);

// 各鍵の今日の使用状況を取得
$today = date('Y-m-d');
$keyIds = array_column($keys, 'id');
$usageMap = [];
if (!empty($keyIds)) {
    $keyInClause = buildInClause($keyIds);
    $usages = dbSelect(
        "SELECT ka.key_id, COUNT(*) as usage_count
         FROM key_assignments ka
         INNER JOIN reservations r ON ka.reservation_id = r.id
         WHERE ka.key_id IN ({$keyInClause['placeholders']})
           AND r.reservation_date = ?
           AND r.status NOT IN ('cancelled')
         GROUP BY ka.key_id",
        array_merge($keyInClause['params'], [$today])
    );
    foreach ($usages as $usage) {
        $usageMap[$usage['key_id']] = $usage['usage_count'];
    }
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">鍵番号管理</h1>
    <a href="<?= url('/keys/new') ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> 新規登録
    </a>
</div>

<!-- フィルタ -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">営業区分</label>
                <select name="sales_area_id" class="form-select">
                    <option value="">すべて</option>
                    <?php foreach ($salesAreas as $area): ?>
                    <option value="<?= $area['id'] ?>" <?= $filterSalesAreaId === (int)$area['id'] ? 'selected' : '' ?>>
                        <?= h($area['store_name']) ?> - <?= h($area['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">ステータス</label>
                <select name="status" class="form-select">
                    <option value="">すべて</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>有効</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>無効</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">絞り込み</button>
            </div>
        </form>
    </div>
</div>

<!-- 鍵一覧 -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>検索結果: <?= number_format($totalCount) ?>件</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($keys)): ?>
            <p class="text-muted text-center py-4 mb-0">鍵が登録されていません</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>鍵番号</th>
                            <th>店舗</th>
                            <th>営業区分</th>
                            <th>ステータス</th>
                            <th>本日の使用</th>
                            <th>備考</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $key): ?>
                        <tr>
                            <td>
                                <strong><?= h($key['key_number']) ?></strong>
                            </td>
                            <td><?= h($key['store_name']) ?></td>
                            <td><?= h($key['area_name']) ?></td>
                            <td>
                                <?php if ($key['is_active']): ?>
                                    <span class="badge bg-success">有効</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">無効</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $usage = $usageMap[$key['id']] ?? 0; ?>
                                <?php if ($usage > 0): ?>
                                    <span class="badge bg-info"><?= $usage ?>件使用中</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= h($key['notes'] ?? '-') ?></small>
                            </td>
                            <td>
                                <a href="<?= url('/keys/' . $key['id']) ?>" class="btn btn-sm btn-outline-primary">詳細</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['totalPages'] > 1): ?>
    <div class="card-footer">
        <?= renderPagination($pagination['page'], $pagination['totalPages'], [
            'sales_area_id' => $filterSalesAreaId,
            'status' => $filterStatus,
        ]) ?>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
