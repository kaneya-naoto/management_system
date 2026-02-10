<?php
/**
 * 店舗設定 - 基本情報カード
 * 変数: $store, $errors, $csrfToken, $bookingUrl
 */
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">店舗基本情報</h5>
        <?php if ($store['is_active']): ?>
            <span class="badge bg-success">有効</span>
        <?php else: ?>
            <span class="badge bg-secondary">無効</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$store['is_active']): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>この店舗は現在「無効」状態です</strong><br>
            <small class="text-muted">有効/無効の変更はHQに連絡してください</small>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <label class="form-label">店舗コード</label>
            <input type="text" class="form-control" value="<?= h($store['code']) ?>" disabled>
            <div class="form-text">店舗コードは変更できません</div>
        </div>

        <div class="mb-3">
            <label class="form-label">予約フォームURL</label>
            <div class="input-group">
                <input type="text" class="form-control" value="<?= h($bookingUrl) ?>" id="bookingUrl" readonly>
                <button type="button" class="btn btn-outline-secondary" onclick="copyBookingUrl()" title="URLをコピー">
                    <i class="bi bi-clipboard" id="copyIcon"></i>
                </button>
                <a href="<?= h($bookingUrl) ?>" target="_blank" class="btn btn-outline-primary" title="新しいタブで開く">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </div>
            <div class="form-text">このURLをお客様に共有してください</div>
        </div>

        <div class="mb-3">
            <label class="form-label">店舗名 <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= h($store['name']) ?>" required maxlength="100">
        </div>

        <div class="mb-3">
            <label class="form-label">住所</label>
            <input type="text" name="address" class="form-control" value="<?= h($store['address']) ?>" maxlength="255">
        </div>

        <div class="mb-3">
            <label class="form-label">電話番号</label>
            <input type="tel" name="phone" class="form-control" value="<?= h($store['phone']) ?>" maxlength="20">
        </div>

        <div class="mb-3">
            <label class="form-label">メールアドレス</label>
            <input type="email" name="email" class="form-control" value="<?= h($store['email']) ?>" maxlength="255">
        </div>
    </div>
</div>
