<?php
/**
 * iPassコード閲覧ページ（ワンタイムトークン認証）
 * LINEから送信されたリンク経由でのみアクセス可能
 */
$pageTitle = 'iPassコード確認';
$isPublicPage = true;

// トークン取得
$token = input('token', '');

if (empty($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $pageTitle = 'エラー';
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

// トークン検証（有効期限と未使用をチェック）
$tokenData = dbSelectOne(
    "SELECT ivt.*, c.ipass_code, c.name as cleaner_name
     FROM ipass_view_tokens ivt
     INNER JOIN cleaners c ON ivt.cleaner_id = c.id
     WHERE ivt.token = ?
       AND ivt.expires_at > NOW()
       AND ivt.used_at IS NULL
       AND c.deleted_at IS NULL",
    [$token]
);

if (!$tokenData) {
    $pageTitle = 'リンク期限切れ';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-3">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6" style="max-width: 480px;">
                <div class="text-center py-5">
                    <div class="mb-3 text-warning" style="font-size: 48px;">⏰</div>
                    <h5 class="mb-3">リンクの有効期限が切れています</h5>
                    <p class="text-muted small">LINEで「ipass」と送信して、新しいリンクを取得してください</p>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// トークンを使用済みにする（ワンタイム）
dbUpdate('ipass_view_tokens', ['used_at' => date('Y-m-d H:i:s')], 'id = ?', [$tokenData['id']]);

require __DIR__ . '/../../includes/public_header.php';
?>

<div class="container py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6" style="max-width: 480px;">
            <div class="text-center py-4">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle" style="width: 80px; height: 80px; font-size: 36px;">
                        <i class="bi bi-key"></i>
                    </span>
                </div>
                <h4 class="mb-4">iPassコード</h4>

                <div class="bg-light rounded p-4 mb-4">
                    <p class="text-muted small mb-2"><?= h($tokenData['cleaner_name']) ?>さんのiPassコード</p>
                    <div class="display-4 fw-bold text-primary" style="letter-spacing: 4px;"><?= h($tokenData['ipass_code']) ?></div>
                </div>

                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-shield-lock"></i>
                    このコードは大切に保管してください。<br>
                    LINE以外からログインする際に使用します。
                </div>

                <p class="text-muted small mt-4">
                    このページは1回限り有効です。<br>
                    再度確認するにはLINEで「ipass」と送信してください。
                </p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
