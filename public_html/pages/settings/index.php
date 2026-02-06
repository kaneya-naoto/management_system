<?php
/**
 * 設定画面
 */
$pageTitle = '設定';

requireLogin();
requireRole('OWNER');

$user = currentUser();
$isHQ = $user && $user['role'] === 'HQ';

require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h3 mb-4">設定</h1>

<?php if ($isHQ): ?>
<!-- HQ専用メニュー -->
<h5 class="text-muted mb-3">システム管理（HQ専用）</h5>
<div class="row mb-4">
    <div class="col-md-6 mb-4">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">オーナー管理</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">オーナーの登録・編集を行います</p>
                <a href="<?= url('/settings/owners') ?>" class="btn btn-primary btn-sm">管理する</a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">店舗管理</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">店舗の新規追加・編集を行います</p>
                <a href="<?= url('/settings/stores') ?>" class="btn btn-primary btn-sm">管理する</a>
            </div>
        </div>
    </div>
</div>

<hr class="my-4">
<h5 class="text-muted mb-3">一般設定</h5>
<?php endif; ?>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">店舗設定</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">店舗の基本情報・営業区分を設定します</p>
                <a href="<?= url('/settings/store') ?>" class="btn btn-outline-primary btn-sm">設定する</a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">鍵番号管理</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">鍵番号の登録・編集を行います</p>
                <a href="<?= url('/keys') ?>" class="btn btn-outline-primary btn-sm">管理する</a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">固定者管理</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">固定清掃者の登録・編集を行います</p>
                <a href="<?= url('/settings/fixed-cleaners') ?>" class="btn btn-outline-primary btn-sm">管理する</a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">ユーザー管理</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">管理者アカウントの管理を行います</p>
                <a href="<?= url('/settings/users') ?>" class="btn btn-outline-primary btn-sm">管理する</a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">LINE連携設定</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">LINE公式アカウント・リッチメニューを管理します</p>
                <a href="<?= url('/settings/line') ?>" class="btn btn-outline-primary btn-sm">設定する</a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
