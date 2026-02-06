<?php
/**
 * LINE清掃者プロフィール登録（公開ページ）
 */
$isPublicPage = true;

// トークン取得
$token = input('token', '');

if (empty($token) || strlen($token) !== 64) {
    $error = 'このURLは無効です。LINEから再度アクセスしてください。';
    $pageTitle = '登録エラー';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-3">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6" style="max-width: 480px;">
                <div class="text-center py-5">
                    <div class="mb-3 text-danger" style="font-size: 48px;">!</div>
                    <h5 class="mb-3">URLが無効です</h5>
                    <p class="text-muted small">LINEから再度アクセスしてください</p>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// トークン検証
$cleaner = dbSelectOne(
    "SELECT * FROM cleaners
     WHERE registration_token = ?
       AND registration_token_expires_at > NOW()
       AND deleted_at IS NULL",
    [$token]
);

if (!$cleaner) {
    $error = 'このURLは無効または期限切れです。LINEから再度登録をお願いします。';
    $pageTitle = '登録エラー';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-3">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6" style="max-width: 480px;">
                <div class="text-center py-5">
                    <div class="mb-3 text-warning" style="font-size: 48px;">⏰</div>
                    <h5 class="mb-3">リンク期限切れ</h5>
                    <p class="text-muted small">LINEから再度登録をお願いします</p>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// 既に登録完了済み
if ($cleaner['registration_status'] === 'completed') {
    $pageTitle = '登録済み';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-3">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6" style="max-width: 480px;">
                <div class="text-center py-5">
                    <div class="mb-3 text-success" style="font-size: 48px;">✓</div>
                    <h5 class="mb-3">登録済みです</h5>
                    <p class="text-muted small">LINEに戻って案件通知をお待ちください</p>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// 店舗選択フェーズに進んでいる場合はリダイレクト
if ($cleaner['registration_status'] === 'profile_done') {
    redirect('/register/stores?token=' . $token);
}

$pageTitle = 'プロフィール登録';
$errors = [];

// 登録処理
if (isPost()) {
    $name = trim(input('name', ''));
    $phone = trim(input('phone', ''));

    // バリデーション
    if (empty($name)) {
        $errors[] = 'お名前を入力してください';
    } elseif (mb_strlen($name) > 50) {
        $errors[] = 'お名前は50文字以内で入力してください';
    }

    if (!empty($phone)) {
        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneClean) < 10 || strlen($phoneClean) > 11) {
            $errors[] = '電話番号の形式が正しくありません';
        }
    }

    if (empty($errors)) {
        dbUpdate('cleaners', [
            'name' => $name,
            'phone' => $phone ?: null,
            'registration_status' => 'profile_done',
        ], 'id = ?', [$cleaner['id']]);

        redirect('/register/stores?token=' . $token);
    }
}

require __DIR__ . '/../../includes/public_header.php';
?>

<div class="container py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6" style="max-width: 480px;">
            <!-- 進捗ステップ -->
            <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary rounded-pill px-3 py-2">1</span>
                    <span class="ms-2 fw-bold small">プロフィール</span>
                </div>
                <div class="text-muted px-2">→</div>
                <div class="d-flex align-items-center opacity-50">
                    <span class="badge bg-secondary rounded-pill px-3 py-2">2</span>
                    <span class="ms-2 small">店舗</span>
                </div>
                <div class="text-muted px-2">→</div>
                <div class="d-flex align-items-center opacity-50">
                    <span class="badge bg-secondary rounded-pill px-3 py-2">3</span>
                    <span class="ms-2 small">完了</span>
                </div>
            </div>

            <h4 class="text-center mb-4">清掃スタッフ登録</h4>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2">
                <?php foreach ($errors as $error): ?>
                <p class="mb-0 small"><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-4">
                    <label class="form-label fw-bold">お名前 <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control form-control-lg"
                           value="<?= h($cleaner['name'] ?? '') ?>"
                           placeholder="山田 太郎" required maxlength="50"
                           style="font-size: 18px; padding: 14px 16px;">
                    <div class="form-text small">本名をフルネームで入力</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">電話番号 <span class="text-muted small fw-normal">(任意)</span></label>
                    <input type="tel" name="phone" class="form-control form-control-lg"
                           value="<?= h($cleaner['phone'] ?? '') ?>"
                           placeholder="090-1234-5678"
                           style="font-size: 18px; padding: 14px 16px;">
                    <div class="form-text small">緊急連絡先として使用</div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 py-3"
                        style="font-size: 18px;">
                    次へ
                </button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
