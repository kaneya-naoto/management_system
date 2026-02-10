<?php
/**
 * LINE連携設定
 */
$pageTitle = 'LINE連携設定';

require_once __DIR__ . '/../../includes/line_helpers.php';

// OWNER権限チェック（内部でrequireLogin()も実施される）
requireRole('OWNER');
$user = currentUser();

// 店舗一覧取得（標準の認可パターンを使用）
if ($user['role'] === 'HQ') {
    $stores = dbSelect(
        "SELECT * FROM stores WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name"
    );
} else {
    // OWNER: getAccessibleStoreIds() で自オーナー配下店舗を取得
    $storeAccess = getAccessibleStoreIds();
    if (!empty($storeAccess['ids'])) {
        $placeholders = implode(',', array_fill(0, count($storeAccess['ids']), '?'));
        $stores = dbSelect(
            "SELECT * FROM stores WHERE id IN ({$placeholders})
             AND is_active = 1 AND deleted_at IS NULL ORDER BY name",
            $storeAccess['ids']
        );
    } else {
        $stores = [];
    }
}

// LINE設定一覧取得（OWNERは自分の店舗のみ、HQは全て）
// OWNERが管理可能な店舗IDリスト
$ownedStoreIds = array_column($stores, 'id');

if ($user['role'] === 'HQ') {
    $lineAccounts = dbSelect(
        "SELECT la.*, s.name as store_name
         FROM line_accounts la
         LEFT JOIN stores s ON la.store_id = s.id
         WHERE la.deleted_at IS NULL
         ORDER BY la.account_type, s.name"
    );
} else {
    // OWNERは自分の店舗のLINE設定 + 清掃者用（account_type='cleaner'）のみ
    if (!empty($ownedStoreIds)) {
        $placeholders = implode(',', array_fill(0, count($ownedStoreIds), '?'));
        $lineAccounts = dbSelect(
            "SELECT la.*, s.name as store_name
             FROM line_accounts la
             LEFT JOIN stores s ON la.store_id = s.id
             WHERE la.deleted_at IS NULL
               AND (la.store_id IN ({$placeholders}) OR la.account_type = 'cleaner')
             ORDER BY la.account_type, s.name",
            $ownedStoreIds
        );
    } else {
        // 店舗を持たないOWNERは清掃者用のみ
        $lineAccounts = dbSelect(
            "SELECT la.*, s.name as store_name
             FROM line_accounts la
             LEFT JOIN stores s ON la.store_id = s.id
             WHERE la.deleted_at IS NULL AND la.account_type = 'cleaner'
             ORDER BY la.account_type, s.name"
        );
    }
}

// 清掃者用アカウントの存在確認
$cleanerAccount = array_filter($lineAccounts, fn($a) => $a['account_type'] === 'cleaner');
$hasCleanerAccount = !empty($cleanerAccount);

// URLパラメータで店舗が指定された場合のデータを準備（stores.phpからのリンク用）
$targetStoreId = (int)input('store_id', 0);
$targetStoreAccount = null;
$targetStore = null;

if ($targetStoreId > 0) {
    // 指定された店舗を取得
    $targetStoreArr = array_filter($stores, fn($s) => (int)$s['id'] === $targetStoreId);
    $targetStore = $targetStoreArr ? array_values($targetStoreArr)[0] : null;

    if ($targetStore) {
        // その店舗のLINE設定を取得
        $targetStoreAccountArr = array_filter($lineAccounts, fn($a) =>
            $a['account_type'] === 'store' && (int)$a['store_id'] === $targetStoreId
        );
        $targetStoreAccount = $targetStoreAccountArr ? array_values($targetStoreAccountArr)[0] : null;
    }
}

// POSTリクエスト処理
if (isPost()) {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create':
            case 'update':
                $accountType = $_POST['account_type'] ?? 'store';
                $storeId = $accountType === 'store' ? (int)($_POST['store_id'] ?? 0) : null;
                $accountId = (int)($_POST['account_id'] ?? 0);

                // IDOR対策: OWNERは自分の店舗のみ操作可能
                if ($user['role'] !== 'HQ') {
                    if ($accountType === 'store' && $storeId > 0 && !in_array($storeId, $ownedStoreIds)) {
                        throw new Exception('この店舗へのアクセス権限がありません');
                    }
                    // 更新時は既存レコードの店舗も確認
                    if ($accountId > 0) {
                        $existingAccount = dbSelectOne(
                            "SELECT store_id, account_type FROM line_accounts WHERE id = ? AND deleted_at IS NULL",
                            [$accountId]
                        );
                        if ($existingAccount) {
                            if ($existingAccount['account_type'] === 'store' &&
                                $existingAccount['store_id'] &&
                                !in_array($existingAccount['store_id'], $ownedStoreIds)) {
                                throw new Exception('この設定へのアクセス権限がありません');
                            }
                        }
                    }
                }

                // 入力検証
                $name = trim($_POST['name'] ?? '');
                $channelId = trim($_POST['channel_id'] ?? '');
                $channelSecret = trim($_POST['channel_secret'] ?? '');
                $channelAccessToken = trim($_POST['channel_access_token'] ?? '');
                $liffId = trim($_POST['liff_id'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if (empty($name)) {
                    throw new Exception('アカウント名を入力してください');
                }

                // 店舗用の場合、重複チェック
                if ($accountType === 'store' && $storeId > 0) {
                    $existing = dbSelectOne(
                        "SELECT id FROM line_accounts
                         WHERE store_id = ? AND deleted_at IS NULL" .
                        ($accountId > 0 ? " AND id != ?" : ""),
                        $accountId > 0 ? [$storeId, $accountId] : [$storeId]
                    );
                    if ($existing) {
                        throw new Exception('この店舗には既にLINE設定があります');
                    }
                }

                // 清掃者用の重複チェック
                if ($accountType === 'cleaner') {
                    $existing = dbSelectOne(
                        "SELECT id FROM line_accounts
                         WHERE account_type = 'cleaner' AND deleted_at IS NULL" .
                        ($accountId > 0 ? " AND id != ?" : ""),
                        $accountId > 0 ? [$accountId] : []
                    );
                    if ($existing) {
                        throw new Exception('清掃者用LINE設定は1つのみ登録できます');
                    }
                }

                $data = [
                    'account_type' => $accountType,
                    'store_id' => $storeId ?: null,
                    'name' => $name,
                    'channel_id' => $channelId ?: null,
                    'liff_id' => $liffId ?: null,
                    'is_active' => $isActive,
                ];

                // 機密フィールドは空の場合、更新時は除外（既存値を保持）
                // 新規作成時は必須
                if ($accountId > 0) {
                    // 更新: 入力があった場合のみ上書き
                    if ($channelSecret !== '') {
                        $data['channel_secret'] = $channelSecret;
                    }
                    if ($channelAccessToken !== '') {
                        $data['channel_access_token'] = $channelAccessToken;
                    }
                } else {
                    // 新規作成: 必須チェック
                    if (empty($channelSecret) || empty($channelAccessToken)) {
                        throw new Exception('Channel SecretとChannel Access Tokenは必須です');
                    }
                    $data['channel_secret'] = $channelSecret;
                    $data['channel_access_token'] = $channelAccessToken;
                }

                // Webhook URL自動生成
                $data['webhook_url'] = generateWebhookUrl($accountType, $storeId ?: null);

                if ($accountId > 0) {
                    dbUpdate('line_accounts', $data, 'id = ?', [$accountId]);
                    flashSuccess('LINE設定を更新しました');
                } else {
                    dbInsert('line_accounts', $data);
                    flashSuccess('LINE設定を登録しました');
                }
                break;

            case 'delete':
                $accountId = (int)($_POST['account_id'] ?? 0);
                if ($accountId > 0) {
                    // IDOR対策: OWNERは自分の店舗のみ削除可能
                    if ($user['role'] !== 'HQ') {
                        $existingAccount = dbSelectOne(
                            "SELECT store_id, account_type FROM line_accounts WHERE id = ? AND deleted_at IS NULL",
                            [$accountId]
                        );
                        if ($existingAccount &&
                            $existingAccount['account_type'] === 'store' &&
                            $existingAccount['store_id'] &&
                            !in_array($existingAccount['store_id'], $ownedStoreIds)) {
                            throw new Exception('この設定へのアクセス権限がありません');
                        }
                    }
                    dbSoftDelete('line_accounts', 'id = ?', [$accountId]);
                    flashSuccess('LINE設定を削除しました');
                }
                break;
        }
    } catch (Exception $e) {
        flashError($e->getMessage());
    }

    redirect('/settings/line');
}

$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">LINE連携設定</h1>
    <a href="<?= url('/settings') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> 設定に戻る
    </a>
</div>

<!-- 清掃者用LINE設定 -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-person-badge"></i> 清掃者用LINE</h5>
    </div>
    <div class="card-body">
        <?php
        $cleanerAccountData = $hasCleanerAccount ? array_values($cleanerAccount)[0] : null;
        ?>
        <?php if ($cleanerAccountData): ?>
        <div class="table-responsive">
            <table class="table table-bordered mb-3">
                <tr>
                    <th style="width: 200px;">アカウント名</th>
                    <td><?= h($cleanerAccountData['name']) ?></td>
                </tr>
                <tr>
                    <th>Channel ID</th>
                    <td><code><?= h($cleanerAccountData['channel_id'] ?: '未設定') ?></code></td>
                </tr>
                <tr>
                    <th>Webhook URL</th>
                    <td>
                        <?php if ($cleanerAccountData['webhook_url']): ?>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm" readonly
                                   value="<?= h($cleanerAccountData['webhook_url']) ?>" id="cleanerWebhookUrl">
                            <button class="btn btn-outline-secondary btn-sm" type="button"
                                    onclick="copyToClipboard('cleanerWebhookUrl')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="text-muted">未生成</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>LIFF ID</th>
                    <td><code><?= h($cleanerAccountData['liff_id'] ?: '未設定') ?></code></td>
                </tr>
                <tr>
                    <th>ステータス</th>
                    <td>
                        <?php if ($cleanerAccountData['is_active']): ?>
                        <span class="badge bg-success">有効</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">無効</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
        // 機密情報を除外したデータを作成
        $cleanerAccountSafe = [
            'id' => $cleanerAccountData['id'],
            'account_type' => $cleanerAccountData['account_type'],
            'store_id' => $cleanerAccountData['store_id'],
            'name' => $cleanerAccountData['name'],
            'channel_id' => $cleanerAccountData['channel_id'],
            'liff_id' => $cleanerAccountData['liff_id'],
            'webhook_url' => $cleanerAccountData['webhook_url'],
            'is_active' => $cleanerAccountData['is_active'],
        ];
        ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#lineAccountModal"
                data-account='<?= json_encode($cleanerAccountSafe, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                onclick='editAccount(JSON.parse(this.dataset.account))'>
            <i class="bi bi-pencil"></i> 編集
        </button>
        <a href="<?= url('/settings/line/richmenu?account_id=' . $cleanerAccountData['id']) ?>"
           class="btn btn-outline-info btn-sm" title="リッチメニュー">
            <i class="bi bi-grid-3x2"></i> リッチメニュー
        </a>
        <?php else: ?>
        <p class="text-muted mb-3">清掃者用LINE設定がまだ登録されていません。</p>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#lineAccountModal"
                onclick="createAccount('cleaner')">
            <i class="bi bi-plus-circle"></i> 清掃者用LINE設定を追加
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- 店舗用LINE設定 -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-shop"></i> 店舗用LINE</h5>
        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#lineAccountModal"
                onclick="createAccount('store')">
            <i class="bi bi-plus-circle"></i> 追加
        </button>
    </div>
    <div class="card-body">
        <?php
        $storeAccounts = array_filter($lineAccounts, fn($a) => $a['account_type'] === 'store');
        ?>
        <?php if (empty($storeAccounts)): ?>
        <p class="text-muted">店舗用LINE設定がまだ登録されていません。</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>店舗名</th>
                        <th>アカウント名</th>
                        <th>Channel ID</th>
                        <th>LIFF ID</th>
                        <th>ステータス</th>
                        <th style="width: 150px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($storeAccounts as $account): ?>
                    <tr>
                        <td><?= h($account['store_name'] ?: '(未設定)') ?></td>
                        <td><?= h($account['name']) ?></td>
                        <td><code><?= h($account['channel_id'] ?: '-') ?></code></td>
                        <td><code><?= h($account['liff_id'] ?: '-') ?></code></td>
                        <td>
                            <?php if ($account['is_active']): ?>
                            <span class="badge bg-success">有効</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">無効</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            // 機密情報を除外したデータを作成
                            $accountSafe = [
                                'id' => $account['id'],
                                'account_type' => $account['account_type'],
                                'store_id' => $account['store_id'],
                                'name' => $account['name'],
                                'channel_id' => $account['channel_id'],
                                'liff_id' => $account['liff_id'],
                                'webhook_url' => $account['webhook_url'],
                                'is_active' => $account['is_active'],
                            ];
                            ?>
                            <button class="btn btn-outline-primary btn-sm"
                                    data-bs-toggle="modal" data-bs-target="#lineAccountModal"
                                    data-account='<?= json_encode($accountSafe, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                                    onclick='editAccount(JSON.parse(this.dataset.account))'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($account['id']): ?>
                            <a href="<?= url('/settings/line/richmenu?account_id=' . $account['id']) ?>"
                               class="btn btn-outline-info btn-sm" title="リッチメニュー">
                                <i class="bi bi-grid-3x2"></i>
                            </a>
                            <?php endif; ?>
                            <button class="btn btn-outline-danger btn-sm"
                                    onclick="confirmDelete(<?= (int)$account['id'] ?>, <?= json_encode($account['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- LINE設定 モーダル -->
<div class="modal fade" id="lineAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="lineAccountForm">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="account_id" id="accountId" value="">
                <input type="hidden" name="account_type" id="accountType" value="store">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">LINE設定</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3" id="storeSelectGroup">
                        <label class="form-label">店舗 <span class="text-danger">*</span></label>
                        <select name="store_id" id="storeId" class="form-select">
                            <option value="">選択してください</option>
                            <?php foreach ($stores as $store): ?>
                            <option value="<?= $store['id'] ?>"><?= h($store['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">アカウント名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="accountName" class="form-control" required
                               placeholder="例: ○○店 予約受付">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Channel ID</label>
                            <input type="text" name="channel_id" id="channelId" class="form-control"
                                   placeholder="LINE Developersで取得">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Channel Secret</label>
                            <input type="password" name="channel_secret" id="channelSecret" class="form-control"
                                   placeholder="LINE Developersで取得">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Channel Access Token</label>
                        <textarea name="channel_access_token" id="channelAccessToken" class="form-control" rows="3"
                                  placeholder="LINE Developersで発行"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">LIFF ID</label>
                        <input type="text" name="liff_id" id="liffId" class="form-control"
                               placeholder="例: 1234567890-abcdefgh">
                        <small class="text-muted">予約フォームをLINE内で表示する場合に設定</small>
                    </div>

                    <div class="mb-3" id="webhookUrlGroup" style="display: none;">
                        <label class="form-label">Webhook URL</label>
                        <div class="input-group">
                            <input type="text" id="webhookUrlDisplay" class="form-control" readonly>
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="copyToClipboard('webhookUrlDisplay')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <small class="text-muted">このURLをLINE Developersに設定してください</small>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="isActive" class="form-check-input" value="1">
                        <label class="form-check-label" for="isActive">有効にする</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 削除確認フォーム（hidden） -->
<form method="post" id="deleteForm" style="display: none;">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="account_id" id="deleteAccountId">
</form>

<script>
function createAccount(type) {
    document.getElementById('formAction').value = 'create';
    document.getElementById('accountId').value = '';
    document.getElementById('accountType').value = type;
    document.getElementById('accountName').value = '';
    document.getElementById('storeId').value = '';
    document.getElementById('channelId').value = '';
    document.getElementById('channelSecret').value = '';
    document.getElementById('channelSecret').placeholder = 'LINE Developersで取得';
    document.getElementById('channelAccessToken').value = '';
    document.getElementById('channelAccessToken').placeholder = 'LINE Developersで発行';
    document.getElementById('liffId').value = '';
    document.getElementById('isActive').checked = false;
    document.getElementById('webhookUrlGroup').style.display = 'none';

    if (type === 'cleaner') {
        document.getElementById('modalTitle').textContent = '清掃者用LINE設定';
        document.getElementById('storeSelectGroup').style.display = 'none';
        document.getElementById('storeId').removeAttribute('required');
    } else {
        document.getElementById('modalTitle').textContent = '店舗用LINE設定';
        document.getElementById('storeSelectGroup').style.display = 'block';
        document.getElementById('storeId').setAttribute('required', 'required');
    }
}

function editAccount(account) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('accountId').value = account.id;
    document.getElementById('accountType').value = account.account_type;
    document.getElementById('accountName').value = account.name || '';
    document.getElementById('storeId').value = account.store_id || '';
    document.getElementById('channelId').value = account.channel_id || '';
    document.getElementById('channelSecret').value = '';
    document.getElementById('channelSecret').placeholder = '変更する場合のみ入力（現在の値は保持されます）';
    document.getElementById('channelAccessToken').value = '';
    document.getElementById('channelAccessToken').placeholder = '変更する場合のみ入力（現在の値は保持されます）';
    document.getElementById('liffId').value = account.liff_id || '';
    document.getElementById('isActive').checked = Boolean(Number(account.is_active));

    if (account.webhook_url) {
        document.getElementById('webhookUrlGroup').style.display = 'block';
        document.getElementById('webhookUrlDisplay').value = account.webhook_url;
    } else {
        document.getElementById('webhookUrlGroup').style.display = 'none';
    }

    if (account.account_type === 'cleaner') {
        document.getElementById('modalTitle').textContent = '清掃者用LINE設定 編集';
        document.getElementById('storeSelectGroup').style.display = 'none';
        document.getElementById('storeId').removeAttribute('required');
    } else {
        document.getElementById('modalTitle').textContent = '店舗用LINE設定 編集';
        document.getElementById('storeSelectGroup').style.display = 'block';
        document.getElementById('storeId').setAttribute('required', 'required');
    }
}

function confirmDelete(accountId, accountName) {
    if (confirm('「' + accountName + '」を削除しますか？')) {
        document.getElementById('deleteAccountId').value = accountId;
        document.getElementById('deleteForm').submit();
    }
}

async function copyToClipboard(elementId) {
    const input = document.getElementById(elementId);
    const text = input.value;

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            showCopySuccess(input);
        } else {
            // フォールバック（古いブラウザ対応）
            input.select();
            document.execCommand('copy');
            showCopySuccess(input);
        }
    } catch (err) {
        input.select();
        alert('Ctrl+C (Cmd+C) でコピーしてください');
    }
}

function showCopySuccess(input) {
    const originalBg = input.style.backgroundColor;
    input.style.backgroundColor = '#d1fae5';
    setTimeout(() => {
        input.style.backgroundColor = originalBg;
    }, 500);
}

// 店舗指定で遷移してきた場合、自動でモーダルを開く
<?php if ($targetStoreId > 0 && $targetStore): ?>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($targetStoreAccount): ?>
    // 既存設定を編集
    const account = <?= json_encode([
        'id' => $targetStoreAccount['id'],
        'account_type' => $targetStoreAccount['account_type'],
        'store_id' => $targetStoreAccount['store_id'],
        'name' => $targetStoreAccount['name'],
        'channel_id' => $targetStoreAccount['channel_id'],
        'liff_id' => $targetStoreAccount['liff_id'],
        'webhook_url' => $targetStoreAccount['webhook_url'],
        'is_active' => $targetStoreAccount['is_active'],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    editAccount(account);
    <?php else: ?>
    // 新規作成（店舗選択済み）
    createAccount('store');
    document.getElementById('storeId').value = '<?= $targetStoreId ?>';
    document.getElementById('accountName').value = '<?= h($targetStore['name']) ?> LINE';
    <?php endif; ?>
    const modal = new bootstrap.Modal(document.getElementById('lineAccountModal'));
    modal.show();
});
<?php endif; ?>
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
