<?php
/**
 * 共通ヘッダー（サイドバーレイアウト）
 * 使用変数: $pageTitle (オプション)
 */
$pageTitle = $pageTitle ?? APP_NAME;
$user = currentUser();
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = defined('BASE_PATH') ? BASE_PATH : '';

// 現在のパスがナビゲーションアイテムにマッチするか
function isNavActive(string $path, string $basePath, string $currentPath): bool {
    $fullPath = $basePath . $path;
    if ($path === '/dashboard') {
        return $currentPath === $fullPath || $currentPath === $basePath || $currentPath === $basePath . '/';
    }
    return strpos($currentPath, $fullPath) === 0;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= url('/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
<?php
// 店舗セレクタ用のデータを取得
$filteredStores = getFilteredStoreIds();
$accessibleStores = $filteredStores['stores'];
$selectedStoreId = $filteredStores['selected'];
$isSingleStore = count($accessibleStores) === 1;
?>
<div class="app-wrapper">
    <!-- サイドバー -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="<?= url('/dashboard') ?>" class="sidebar-brand">
                <i class="bi bi-building"></i>
                <span><?= h(APP_NAME) ?></span>
            </a>
        </div>

        <!-- 店舗セレクタ -->
        <?php if (!empty($accessibleStores)): ?>
        <div class="store-selector">
            <form method="post" action="<?= url('/switch-store') ?>">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                <label class="store-selector-label">
                    <i class="bi bi-shop"></i>
                    店舗
                </label>
                <select name="store_id" class="store-selector-select" onchange="this.form.submit()" <?= $isSingleStore ? 'disabled' : '' ?>>
                    <?php if (!$isSingleStore): ?>
                    <option value="">全店舗</option>
                    <?php endif; ?>
                    <?php foreach ($accessibleStores as $_s): ?>
                    <option value="<?= h($_s['id']) ?>" <?= $selectedStoreId === (int)$_s['id'] ? 'selected' : '' ?>>
                        <?= h($_s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php endif; ?>

        <nav class="sidebar-nav">
            <ul class="nav-list">
                <li class="nav-item">
                    <a href="<?= url('/dashboard') ?>" class="nav-link <?= isNavActive('/dashboard', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-grid-1x2"></i>
                        <span>ダッシュボード</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/reservations') ?>" class="nav-link <?= isNavActive('/reservations', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-calendar"></i>
                        <span>予約</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/jobs') ?>" class="nav-link <?= isNavActive('/jobs', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-briefcase"></i>
                        <span>清掃案件</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/cleaners') ?>" class="nav-link <?= isNavActive('/cleaners', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-people"></i>
                        <span>清掃者</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/keys') ?>" class="nav-link <?= isNavActive('/keys', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-key"></i>
                        <span>鍵管理</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/shifts') ?>" class="nav-link <?= isNavActive('/shifts', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-calendar-week"></i>
                        <span>シフト</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/payments') ?>" class="nav-link <?= isNavActive('/payments', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-cash-stack"></i>
                        <span>支払い</span>
                    </a>
                </li>
                <?php if (hasRole('OWNER')): ?>
                <li class="nav-section">管理</li>
                <li class="nav-item">
                    <a href="<?= url('/logs') ?>" class="nav-link <?= isNavActive('/logs', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-journal-text"></i>
                        <span>操作ログ</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('/settings') ?>" class="nav-link <?= isNavActive('/settings', $basePath, $currentPath) ? 'active' : '' ?>">
                        <i class="bi bi-gear"></i>
                        <span>設定</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <i class="bi bi-person-circle"></i>
                </div>
                <div class="user-details">
                    <span class="user-name"><?= h($user['name']) ?></span>
                    <span class="user-role"><?= h($user['role']) ?></span>
                </div>
            </div>
            <form method="post" action="<?= url('/logout') ?>">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">
                <button type="submit" class="logout-btn" title="ログアウト">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </aside>

    <!-- メインコンテンツ -->
    <main class="main-content">
        <header class="content-header">
            <button class="sidebar-toggle d-lg-none" type="button" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="page-title"><?= h($pageTitle) ?></h1>
        </header>

        <div class="content-body">
            <?php
            $flash = getFlash();
            if ($flash):
            ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= h($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
<?php else: ?>
<div class="guest-wrapper">
<?php endif; ?>
