<?php
/**
 * LINE清掃者 店舗選択（公開ページ）
 */
$isPublicPage = true;

// トークン取得
$token = input('token', '');

if (empty($token) || strlen($token) !== 64) {
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

// プロフィール未登録の場合はリダイレクト
if ($cleaner['registration_status'] === 'pending') {
    redirect('/register?token=' . $token);
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

// 登録可能な店舗一覧
$stores = dbSelect(
    "SELECT id, name, address FROM stores WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name"
);

$pageTitle = '対応可能店舗の選択';
$errors = [];

// 登録処理
if (isPost()) {
    // CSRFトークン検証
    requireCsrf();

    $storeIds = input('store_ids', []);
    if (!is_array($storeIds)) {
        $storeIds = [];
    }
    $storeIds = array_filter(array_map('intval', $storeIds));

    // バリデーション
    if (empty($storeIds)) {
        $errors[] = '1店舗以上選択してください';
    } else {
        // 有効な店舗IDかチェック
        $validStoreIds = array_column($stores, 'id');
        $invalidIds = array_diff($storeIds, $validStoreIds);
        if (!empty($invalidIds)) {
            $errors[] = '無効な店舗が選択されています';
        }
    }

    if (empty($errors)) {
        dbBegin();
        try {
            // 既存の店舗関連を削除
            dbExecute("DELETE FROM cleaner_stores WHERE cleaner_id = ?", [$cleaner['id']]);

            // 新しい店舗関連を追加
            foreach ($storeIds as $storeId) {
                dbInsert('cleaner_stores', [
                    'cleaner_id' => $cleaner['id'],
                    'store_id' => $storeId,
                ]);
            }

            // iPassコード生成（衝突安全なループ付き）
            $ipassCode = generateIPassCode();

            // 登録完了
            dbUpdate('cleaners', [
                'is_active' => 1,
                'registration_status' => 'completed',
                'registration_token' => null,
                'registration_token_expires_at' => null,
                'ipass_code' => $ipassCode,
                'registered_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$cleaner['id']]);

            dbCommit();

            // 完了メッセージをLINEに送信
            if ($cleaner['line_user_id']) {
                $message = "登録が完了しました！\n\n"
                    . "あなたのiPass: {$ipassCode}\n\n"
                    . "このコードは大切に保管してください。\n"
                    . "LINE以外からログインする際に使用します。\n\n"
                    . "清掃案件が届いた際はこちらでお知らせしますね！";
                sendLineTextPush($cleaner['line_user_id'], $message);
            }

            // 完了ページへ
            $pageTitle = '登録完了';
            require __DIR__ . '/../../includes/public_header.php';
            ?>
            <div class="container py-3">
                <div class="row justify-content-center">
                    <div class="col-12 col-md-6" style="max-width: 480px;">
                        <!-- 進捗ステップ（完了） -->
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
                            <div class="d-flex align-items-center opacity-50">
                                <span class="badge bg-success rounded-pill px-3 py-2">✓</span>
                            </div>
                            <div class="text-muted px-2">→</div>
                            <div class="d-flex align-items-center opacity-50">
                                <span class="badge bg-success rounded-pill px-3 py-2">✓</span>
                            </div>
                            <div class="text-muted px-2">→</div>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-success rounded-pill px-3 py-2">✓</span>
                                <span class="ms-2 fw-bold small text-success">完了</span>
                            </div>
                        </div>

                        <div class="text-center py-4">
                            <div class="mb-3">
                                <span class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle" style="width: 80px; height: 80px; font-size: 40px;">✓</span>
                            </div>
                            <h4 class="mb-4">登録完了！</h4>

                            <div class="bg-light rounded p-4 mb-4">
                                <p class="text-muted small mb-2">あなたのiPassコード</p>
                                <div class="display-5 fw-bold text-primary"><?= h($ipassCode) ?></div>
                                <p class="text-muted small mt-2 mb-0">
                                    LINE以外からログインする際に使用します
                                </p>
                            </div>

                            <p class="text-muted small">
                                LINEに戻って案件通知をお待ちください
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            require __DIR__ . '/../../includes/public_footer.php';
            exit;

        } catch (Exception $e) {
            dbRollback();
            $errors[] = '登録処理中にエラーが発生しました。もう一度お試しください。';
            error_log("Cleaner registration failed: " . $e->getMessage());
        }
    }
}

require __DIR__ . '/../../includes/public_header.php';
?>

<div class="container py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6" style="max-width: 480px;">
            <!-- 進捗ステップ -->
            <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
                <div class="d-flex align-items-center opacity-50">
                    <span class="badge bg-success rounded-pill px-3 py-2">✓</span>
                    <span class="ms-2 small">プロフィール</span>
                </div>
                <div class="text-muted px-2">→</div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary rounded-pill px-3 py-2">2</span>
                    <span class="ms-2 fw-bold small">店舗</span>
                </div>
                <div class="text-muted px-2">→</div>
                <div class="d-flex align-items-center opacity-50">
                    <span class="badge bg-secondary rounded-pill px-3 py-2">3</span>
                    <span class="ms-2 small">完了</span>
                </div>
            </div>

            <h4 class="text-center mb-2">対応店舗を選択</h4>
            <p class="text-center text-muted small mb-4">
                <strong><?= h($cleaner['name']) ?></strong>さん、清掃可能な店舗を選んでください
            </p>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2">
                <?php foreach ($errors as $error): ?>
                <p class="mb-0 small"><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">
                <div class="mb-4">
                    <?php if (empty($stores)): ?>
                    <p class="text-muted text-center">現在登録可能な店舗がありません。</p>
                    <?php else: ?>
                    <?php foreach ($stores as $store): ?>
                    <label class="d-block mb-2 p-3 border rounded bg-white position-relative"
                           for="store_<?= $store['id'] ?>"
                           style="cursor: pointer; transition: all 0.15s;">
                        <div class="d-flex align-items-center">
                            <input type="checkbox" name="store_ids[]" value="<?= $store['id'] ?>"
                                   class="form-check-input me-3" id="store_<?= $store['id'] ?>"
                                   style="width: 24px; height: 24px; margin: 0;">
                            <div>
                                <strong style="font-size: 16px;"><?= h($store['name']) ?></strong>
                                <?php if ($store['address']): ?>
                                <div class="text-muted small mt-1"><?= h($store['address']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($stores)): ?>
                <button type="submit" class="btn btn-primary btn-lg w-100 py-3"
                        style="font-size: 18px;">
                    登録を完了する
                </button>
                <p class="text-center text-muted small mt-3">
                    1店舗以上選択してください
                </p>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<style>
/* 店舗選択のスタイル */
.store-item {
    transition: all 0.15s ease;
    border: 2px solid #dee2e6 !important;
}
.store-item:hover {
    border-color: #adb5bd !important;
    background-color: #f8f9fa !important;
}
.store-item.selected {
    border-color: #0d6efd !important;
    background-color: #e7f1ff !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="store_ids[]"]').forEach(function(checkbox) {
        var label = checkbox.closest('label');
        if (label) {
            label.classList.add('store-item');
            // 初期状態
            if (checkbox.checked) {
                label.classList.add('selected');
            }
            // 変更時
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    label.classList.add('selected');
                } else {
                    label.classList.remove('selected');
                }
            });
        }
    });
});
</script>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
