<?php
// Referrer-Policyヘッダー（トークン漏洩防止）
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?= h($pageTitle ?? 'カクレマ') ?> | カクレマ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= url('/assets/css/booking.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/booking.css') ?>" rel="stylesheet">
    <?php
    // LIFF SDK読み込み（店舗コードがあり、LINE設定がある場合）
    $liffId = null;
    if (!empty($storeCode)) {
        require_once __DIR__ . '/line_helpers.php';
        $targetStoreForLiff = dbSelectOne(
            "SELECT id FROM stores WHERE code = ? AND is_active = 1 AND deleted_at IS NULL",
            [$storeCode]
        );
        if ($targetStoreForLiff) {
            $lineConfigForLiff = getStoreLineConfig($targetStoreForLiff['id']);
            $liffId = $lineConfigForLiff['liff_id'] ?? null;
        }
    }
    ?>
    <?php if ($liffId): ?>
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script>
        window.LIFF_ID = <?= json_encode($liffId) ?>;
    </script>
    <?php endif; ?>
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        .navbar-brand {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-building"></i> カクレマ
            </a>
        </div>
    </nav>

    <!-- フラッシュメッセージ -->
    <?php $flash = getFlash(); ?>
    <?php if ($flash): ?>
    <div class="container">
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
            <?= h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>
