<?php
/**
 * 清掃者向け案件一覧ページ
 *
 * LINE経由でアクセスし、トークンまたはセッションで認証
 */

// 共通ファイル読み込み
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = '募集中の案件';
$isPublicPage = true;

// 認証処理
$cleaner = null;
$authError = null;

// 1. トークン認証
$token = $_GET['token'] ?? '';
if (!empty($token)) {
    // トークンで清掃者を特定
    $cleaner = dbSelectOne(
        "SELECT c.* FROM cleaners c
         WHERE c.list_token = ?
           AND c.is_active = 1
           AND c.deleted_at IS NULL
           AND c.registration_status = 'completed'",
        [$token]
    );

    if ($cleaner) {
        // セッション固定化攻撃対策: 認証成功時にセッションIDを再生成
        session_regenerate_id(true);

        // セッションに保存（次回以降はトークン不要）
        $_SESSION['cleaner_id'] = $cleaner['id'];
        $_SESSION['cleaner_name'] = $cleaner['name'];
    }
}

// 2. セッション認証
if (!$cleaner && !empty($_SESSION['cleaner_id'])) {
    $cleaner = dbSelectOne(
        "SELECT c.* FROM cleaners c
         WHERE c.id = ?
           AND c.is_active = 1
           AND c.deleted_at IS NULL
           AND c.registration_status = 'completed'",
        [$_SESSION['cleaner_id']]
    );
}

// 認証失敗
if (!$cleaner) {
    $authError = 'アクセス権限がありません。LINEから最新のリンクをご確認ください。';
}

// フィルタパラメータ
$filterDate = $_GET['date'] ?? '';
$filterStoreId = (int)($_GET['store_id'] ?? 0);

// 担当店舗を取得
$stores = [];
if ($cleaner) {
    $stores = dbSelect(
        "SELECT s.id, s.name
         FROM stores s
         INNER JOIN cleaner_stores cs ON s.id = cs.store_id
         WHERE cs.cleaner_id = ?
           AND s.is_active = 1
           AND s.deleted_at IS NULL
         ORDER BY s.name",
        [$cleaner['id']]
    );
}

// 案件を取得
$jobs = [];
if ($cleaner && !empty($stores)) {
    $storeIds = array_column($stores, 'id');
    $inClause = buildInClause($storeIds);

    $whereConditions = [
        "cj.store_id IN ({$inClause['placeholders']})",
        "cj.status IN ('unassigned', 'recruiting')",
        "cj.assigned_cleaner_id IS NULL",
        "cj.scheduled_at > NOW()",
        "cj.deleted_at IS NULL",
    ];
    $params = $inClause['params'];

    // 日付フィルタ
    if (!empty($filterDate)) {
        $whereConditions[] = "DATE(cj.scheduled_at) = ?";
        $params[] = $filterDate;
    }

    // 店舗フィルタ
    if ($filterStoreId > 0 && in_array($filterStoreId, $storeIds)) {
        $whereConditions[] = "cj.store_id = ?";
        $params[] = $filterStoreId;
    }

    $whereClause = implode(' AND ', $whereConditions);

    $jobs = dbSelect(
        "SELECT cj.*, sa.name as area_name, s.name as store_name
         FROM cleaning_jobs cj
         INNER JOIN sales_areas sa ON cj.sales_area_id = sa.id
         INNER JOIN stores s ON cj.store_id = s.id
         WHERE {$whereClause}
         ORDER BY cj.scheduled_at ASC
         LIMIT 50",
        $params
    );
}

// 日付選択肢を生成
$dateOptions = [];
$today = new DateTime();
for ($i = 0; $i < 14; $i++) {
    $date = clone $today;
    $date->modify("+{$i} days");
    $dateOptions[] = [
        'value' => $date->format('Y-m-d'),
        'label' => $date->format('n/j') . '(' . ['日','月','火','水','木','金','土'][$date->format('w')] . ')',
    ];
}

// ページ出力
require_once __DIR__ . '/../../includes/public_header.php';
?>

<div class="container py-4">
    <?php if ($authError): ?>
        <div class="alert alert-danger">
            <h5>アクセスエラー</h5>
            <p class="mb-0"><?= h($authError) ?></p>
        </div>
    <?php else: ?>
        <h1 class="h4 mb-4">募集中の案件</h1>

        <!-- フィルタ -->
        <form method="get" class="mb-4">
            <?php if (!empty($token)): ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">
            <?php endif; ?>

            <div class="row g-2">
                <div class="col-6">
                    <select name="date" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">全ての日付</option>
                        <?php foreach ($dateOptions as $opt): ?>
                            <option value="<?= h($opt['value']) ?>" <?= $filterDate === $opt['value'] ? 'selected' : '' ?>>
                                <?= h($opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <select name="store_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">全店舗</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?= h($store['id']) ?>" <?= $filterStoreId === (int)$store['id'] ? 'selected' : '' ?>>
                                <?= h($store['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <?php if (empty($jobs)): ?>
            <div class="text-center py-5">
                <p class="text-muted mb-3">現在、募集中の案件はありません</p>
                <p class="small text-muted">新しい案件が入り次第、LINEでお知らせします！</p>
            </div>
        <?php else: ?>
            <div class="list-group">
                <?php foreach ($jobs as $job): ?>
                    <?php
                    $scheduledDate = new DateTime($job['scheduled_at']);
                    $isUrgent = $job['is_urgent'] ?? false;
                    $isToday = $scheduledDate->format('Y-m-d') === date('Y-m-d');
                    ?>
                    <div class="list-group-item <?= $isUrgent ? 'list-group-item-warning' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <?php if ($isUrgent): ?>
                                    <span class="badge bg-danger me-1">急募</span>
                                <?php endif; ?>
                                <?php if ($isToday): ?>
                                    <span class="badge bg-info me-1">本日</span>
                                <?php endif; ?>
                                <span class="fw-bold"><?= h($job['area_name']) ?></span>
                            </div>
                            <span class="text-success fw-bold">
                                ¥<?= number_format($job['base_reward']) ?>
                            </span>
                        </div>

                        <div class="small text-muted mb-2">
                            <i class="bi bi-geo-alt"></i> <?= h($job['store_name']) ?>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-calendar"></i>
                                <?= $scheduledDate->format('n/j') ?>(<?= ['日','月','火','水','木','金','土'][$scheduledDate->format('w')] ?>)
                                <i class="bi bi-clock ms-2"></i>
                                <?= $scheduledDate->format('H:i') ?>〜
                            </div>
                            <a href="<?= h(url('/apply?job_id=' . $job['id'])) ?>"
                               class="btn btn-primary btn-sm">
                                応募する
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="text-center text-muted small mt-3">
                <?= count($jobs) ?>件の案件があります
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/public_footer.php'; ?>
