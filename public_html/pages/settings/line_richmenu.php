<?php
/**
 * リッチメニュー設定（シンプル版：1アカウント1メニュー）
 */
$pageTitle = 'リッチメニュー設定';

require __DIR__ . '/../../includes/line_helpers.php';

requireLogin();
$user = currentUser();

if ($user['role'] !== 'OWNER' && $user['role'] !== 'HQ') {
    flashError('この機能はオーナーまたはHQ権限が必要です');
    redirect('/settings');
}

$accountId = (int)($_GET['account_id'] ?? 0);
if (!$accountId) {
    flashError('LINE設定が指定されていません');
    redirect('/settings/line');
}

$lineAccount = dbSelectOne(
    "SELECT la.*, s.name as store_name FROM line_accounts la
     LEFT JOIN stores s ON la.store_id = s.id
     WHERE la.id = ? AND la.deleted_at IS NULL",
    [$accountId]
);

if (!$lineAccount) {
    flashError('LINE設定が見つかりません');
    redirect('/settings/line');
}

// IDOR対策
if ($user['role'] !== 'HQ') {
    $ownedStores = dbSelect(
        "SELECT s.id FROM stores s INNER JOIN owners o ON s.owner_id = o.id
         WHERE o.user_id = ? AND s.is_active = 1 AND s.deleted_at IS NULL",
        [$user['id']]
    );
    $ownedStoreIds = array_column($ownedStores, 'id');
    if ($lineAccount['account_type'] === 'store' && $lineAccount['store_id'] && !in_array($lineAccount['store_id'], $ownedStoreIds)) {
        flashError('アクセス権限がありません');
        redirect('/settings/line');
    }
}

// 既存のリッチメニュー（1つだけ）
$existingMenu = dbSelectOne(
    "SELECT * FROM line_rich_menus WHERE line_account_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
    [$accountId]
);

$uploadDir = __DIR__ . '/../../uploads/richmenu/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$sizes = [
    'large' => ['width' => 2500, 'height' => 1686],
];

function generateAreas($template, $w, $h) {
    $areas = [];
    switch ($template) {
        case '1':
            $areas[] = ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'label' => 'ボタン1'];
            break;
        case '2h':
            $areas[] = ['x' => 0, 'y' => 0, 'w' => $w/2, 'h' => $h, 'label' => 'ボタン1'];
            $areas[] = ['x' => $w/2, 'y' => 0, 'w' => $w/2, 'h' => $h, 'label' => 'ボタン2'];
            break;
        case '2v':
            $areas[] = ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h/2, 'label' => 'ボタン1'];
            $areas[] = ['x' => 0, 'y' => $h/2, 'w' => $w, 'h' => $h/2, 'label' => 'ボタン2'];
            break;
        case '3':
            $areas[] = ['x' => 0, 'y' => 0, 'w' => $w/3, 'h' => $h, 'label' => 'ボタン1'];
            $areas[] = ['x' => $w/3, 'y' => 0, 'w' => $w/3, 'h' => $h, 'label' => 'ボタン2'];
            $areas[] = ['x' => $w*2/3, 'y' => 0, 'w' => $w/3, 'h' => $h, 'label' => 'ボタン3'];
            break;
        case '4':
            $areas[] = ['x' => 0, 'y' => 0, 'w' => $w/2, 'h' => $h/2, 'label' => 'ボタン1'];
            $areas[] = ['x' => $w/2, 'y' => 0, 'w' => $w/2, 'h' => $h/2, 'label' => 'ボタン2'];
            $areas[] = ['x' => 0, 'y' => $h/2, 'w' => $w/2, 'h' => $h/2, 'label' => 'ボタン3'];
            $areas[] = ['x' => $w/2, 'y' => $h/2, 'w' => $w/2, 'h' => $h/2, 'label' => 'ボタン4'];
            break;
        case '6':
            for ($r = 0; $r < 2; $r++) {
                for ($c = 0; $c < 3; $c++) {
                    $areas[] = ['x' => $c*$w/3, 'y' => $r*$h/2, 'w' => $w/3, 'h' => $h/2, 'label' => 'ボタン'.($r*3+$c+1)];
                }
            }
            break;
    }
    return $areas;
}

function areasToLineFormat($areas, $actions) {
    $result = [];
    foreach ($areas as $i => $area) {
        $action = $actions[$i] ?? ['type' => 'message', 'text' => $area['label']];
        $result[] = [
            'bounds' => ['x' => (int)$area['x'], 'y' => (int)$area['y'], 'width' => (int)$area['w'], 'height' => (int)$area['h']],
            'action' => $action
        ];
    }
    return $result;
}

// POST処理
if (isPost()) {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'save':
                $name = trim($_POST['name'] ?? '');
                $template = $_POST['template'] ?? '1';
                $chatBarText = trim($_POST['chat_bar_text'] ?? 'メニュー');
                $deployNow = isset($_POST['deploy']);

                if (empty($name)) throw new Exception('メニュー名を入力してください');

                $size = $sizes['large'];
                $areas = generateAreas($template, $size['width'], $size['height']);

                // アクション取得
                $actions = [];
                foreach ($_POST['action_type'] ?? [] as $i => $type) {
                    $act = ['type' => $type];
                    if ($type === 'uri') {
                        $uri = trim($_POST['action_uri'][$i] ?? '');
                        $act = empty($uri) ? ['type' => 'message', 'text' => 'ボタン'.($i+1)] : ['type' => 'uri', 'uri' => $uri];
                    } elseif ($type === 'message') {
                        $text = trim($_POST['action_text'][$i] ?? '');
                        $act['text'] = $text !== '' ? $text : 'ボタン'.($i+1);
                    } elseif ($type === 'postback') {
                        $act['data'] = trim($_POST['action_data'][$i] ?? '') ?: 'action=button'.($i+1);
                        $displayText = trim($_POST['action_display'][$i] ?? '');
                        if ($displayText !== '') $act['displayText'] = $displayText;
                    }
                    $actions[$i] = $act;
                }

                $lineAreas = areasToLineFormat($areas, $actions);
                $config = json_encode(['template' => $template, 'areas' => $areas, 'actions' => $actions, 'lineAreas' => $lineAreas], JSON_UNESCAPED_UNICODE);

                // 画像処理
                $filename = null;
                if (!empty($_FILES['image']['tmp_name'])) {
                    $file = $_FILES['image'];
                    if ($file['size'] > 1024 * 1024) throw new Exception('画像は1MB以下');
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png', 'jpg', 'jpeg'])) throw new Exception('PNG/JPGのみ');
                    $filename = 'rm_' . $accountId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                        throw new Exception('画像保存失敗');
                    }
                }

                if ($existingMenu) {
                    // 更新
                    $updateData = [
                        'name' => $name,
                        'chat_bar_text' => $chatBarText,
                        'menu_config' => $config,
                    ];
                    if ($filename) {
                        if ($existingMenu['image_filename']) @unlink($uploadDir . basename($existingMenu['image_filename']));
                        $updateData['image_filename'] = $filename;
                    }
                    dbUpdate('line_rich_menus', $updateData, 'id = ?', [$existingMenu['id']]);
                    $menuId = $existingMenu['id'];
                    $currentFilename = $filename ?: $existingMenu['image_filename'];
                } else {
                    // 新規
                    if (!$filename) throw new Exception('画像を選択してください');

                    $menuId = dbInsert('line_rich_menus', [
                        'line_account_id' => $accountId,
                        'name' => $name,
                        'size_type' => 'large',
                        'chat_bar_text' => $chatBarText,
                        'image_filename' => $filename,
                        'menu_config' => $config,
                        'is_default' => 1,
                        'is_active' => 1,
                    ]);
                    $currentFilename = $filename;
                }

                // LINE反映
                if ($deployNow) {
                    if (empty($lineAccount['channel_access_token'])) throw new Exception('Access Token未設定');

                    $imagePath = $uploadDir . basename($currentFilename);
                    if (!file_exists($imagePath)) throw new Exception('画像ファイルが見つかりません');

                    $menuData = [
                        'size' => ['width' => $size['width'], 'height' => $size['height']],
                        'selected' => false,
                        'name' => $name,
                        'chatBarText' => $chatBarText ?: 'メニュー',
                        'areas' => $lineAreas,
                    ];

                    $richMenuId = createLineRichMenu($menuData, $lineAccount['channel_access_token']);
                    if (!$richMenuId) throw new Exception('LINEへのメニュー作成に失敗');

                    if (!uploadRichMenuImage($richMenuId, $imagePath, $lineAccount['channel_access_token'])) {
                        deleteLineRichMenu($richMenuId, $lineAccount['channel_access_token']);
                        throw new Exception('画像アップロード失敗');
                    }

                    // 常にデフォルトに設定
                    setDefaultRichMenu($richMenuId, $lineAccount['channel_access_token']);

                    dbUpdate('line_rich_menus', ['rich_menu_id' => $richMenuId], 'id = ?', [$menuId]);
                    flashSuccess('LINEに反映しました！');
                } else {
                    flashSuccess('保存しました');
                }

                redirect('/settings/line/richmenu?account_id=' . $accountId);
                break;

            case 'delete':
                if ($existingMenu) {
                    if ($existingMenu['rich_menu_id'] && $lineAccount['channel_access_token']) {
                        deleteLineRichMenu($existingMenu['rich_menu_id'], $lineAccount['channel_access_token']);
                    }
                    if ($existingMenu['image_filename']) @unlink($uploadDir . basename($existingMenu['image_filename']));
                    dbSoftDelete('line_rich_menus', 'id = ?', [$existingMenu['id']]);
                    flashSuccess('削除しました');
                }
                redirect('/settings/line/richmenu?account_id=' . $accountId);
                break;
        }
    } catch (Exception $e) {
        flashError($e->getMessage());
    }
}

// 編集データ準備
$config = $existingMenu ? (json_decode($existingMenu['menu_config'], true) ?: []) : [];
$currentTemplate = $config['template'] ?? '1';
$currentActions = $config['actions'] ?? [];

$csrfToken = generateCsrfToken();
require __DIR__ . '/../../includes/header.php';
?>

<style>
.template-grid { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.template-btn { border: 2px solid #dee2e6; border-radius: 6px; padding: 8px 12px; cursor: pointer; background: #fff; transition: all 0.2s; }
.template-btn:hover { border-color: #0d6efd; }
.template-btn.active { border-color: #0d6efd; background: #e7f1ff; }
.template-btn .preview { display: grid; gap: 2px; width: 48px; height: 32px; margin-bottom: 4px; }
.template-btn .preview div { background: #6c757d; border-radius: 2px; }
.template-btn .name { font-size: 11px; color: #666; text-align: center; }

.editor-layout { display: grid; grid-template-columns: 300px 1fr; gap: 24px; }
@media (max-width: 768px) { .editor-layout { grid-template-columns: 1fr; } }

.preview-box { background: #f8f9fa; border-radius: 8px; padding: 16px; }
.preview-container { position: relative; background: #e9ecef; border-radius: 4px; overflow: hidden; aspect-ratio: 2500/1686; }
.preview-container img { width: 100%; height: 100%; object-fit: cover; }
.preview-overlay { position: absolute; inset: 0; }
.preview-area { position: absolute; border: 2px dashed rgba(255,255,255,0.8); background: rgba(0,123,255,0.15); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.5); font-size: 14px; cursor: pointer; }
.preview-area:hover { background: rgba(0,123,255,0.3); }
.preview-area.active { border-color: #ffc107; background: rgba(255,193,7,0.3); }

.action-card { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; margin-bottom: 8px; }
.action-card.active { border-color: #0d6efd; box-shadow: 0 0 0 2px rgba(13,110,253,0.25); }
.action-header { font-weight: 600; font-size: 13px; margin-bottom: 8px; color: #495057; }

.status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
.status-badge.active { background: #d1e7dd; color: #0f5132; }
.status-badge.inactive { background: #f8d7da; color: #842029; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">リッチメニュー設定</h1>
        <small class="text-muted"><?= h($lineAccount['name']) ?></small>
    </div>
    <a href="<?= url('/settings/line') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> 戻る
    </a>
</div>

<?php if ($existingMenu && $existingMenu['rich_menu_id']): ?>
<div class="alert alert-success py-2 mb-3">
    <i class="bi bi-check-circle"></i> LINEに反映済み
</div>
<?php elseif ($existingMenu): ?>
<div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-exclamation-circle"></i> 未反映（「保存してLINEに反映」で反映してください）
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="template" id="templateInput" value="<?= h($currentTemplate) ?>">

    <div class="card mb-4">
        <div class="card-body">
            <!-- Step 1: テンプレート -->
            <div class="mb-4">
                <label class="form-label fw-bold">1. ボタン配置</label>
                <div class="template-grid" id="templateGrid">
                    <?php
                    $templates = [
                        '1' => ['name' => '1つ', 'cols' => '1fr', 'rows' => '1fr', 'count' => 1],
                        '2h' => ['name' => '2つ横', 'cols' => '1fr 1fr', 'rows' => '1fr', 'count' => 2],
                        '2v' => ['name' => '2つ縦', 'cols' => '1fr', 'rows' => '1fr 1fr', 'count' => 2],
                        '3' => ['name' => '3つ', 'cols' => '1fr 1fr 1fr', 'rows' => '1fr', 'count' => 3],
                        '4' => ['name' => '4つ', 'cols' => '1fr 1fr', 'rows' => '1fr 1fr', 'count' => 4],
                        '6' => ['name' => '6つ', 'cols' => '1fr 1fr 1fr', 'rows' => '1fr 1fr', 'count' => 6],
                    ];
                    foreach ($templates as $key => $tpl): ?>
                    <div class="template-btn <?= $currentTemplate === $key ? 'active' : '' ?>" data-template="<?= $key ?>" data-count="<?= $tpl['count'] ?>">
                        <div class="preview" style="grid-template-columns: <?= $tpl['cols'] ?>; grid-template-rows: <?= $tpl['rows'] ?>;">
                            <?php for ($i = 0; $i < $tpl['count']; $i++): ?><div></div><?php endfor; ?>
                        </div>
                        <div class="name"><?= $tpl['name'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="editor-layout">
                <!-- 左: プレビュー -->
                <div>
                    <label class="form-label fw-bold">2. 画像</label>
                    <div class="preview-box">
                        <div class="preview-container" id="previewContainer">
                            <img src="<?= $existingMenu && $existingMenu['image_filename'] ? url('/uploads/richmenu/' . rawurlencode(basename($existingMenu['image_filename']))) : '' ?>"
                                 id="previewImg"
                                 style="<?= ($existingMenu && $existingMenu['image_filename']) ? '' : 'display:none;' ?>">
                            <div id="previewPlaceholder" class="d-flex align-items-center justify-content-center text-muted"
                                 style="position:absolute;inset:0;<?= ($existingMenu && $existingMenu['image_filename']) ? 'display:none;' : '' ?>">
                                <span>画像を選択</span>
                            </div>
                            <div class="preview-overlay" id="previewOverlay"></div>
                        </div>
                        <input type="file" name="image" id="imageInput" class="form-control form-control-sm mt-2" accept="image/png,image/jpeg">
                        <small class="text-muted">2500×1686px推奨</small>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">メニュー名</label>
                        <input type="text" name="name" class="form-control form-control-sm" required value="<?= h($existingMenu['name'] ?? '') ?>" placeholder="メインメニュー">
                    </div>
                    <div class="mt-2">
                        <label class="form-label">チャットバー</label>
                        <input type="text" name="chat_bar_text" class="form-control form-control-sm" value="<?= h($existingMenu['chat_bar_text'] ?? 'メニュー') ?>">
                    </div>
                </div>

                <!-- 右: アクション設定 -->
                <div>
                    <label class="form-label fw-bold">3. ボタンのアクション</label>
                    <div id="actionList">
                        <!-- JSで生成 -->
                    </div>
                </div>
            </div>

            <hr class="my-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-save"></i> 保存のみ
                </button>
                <button type="submit" name="deploy" value="1" class="btn btn-primary">
                    <i class="bi bi-cloud-upload"></i> 保存してLINEに反映
                </button>
                <?php if ($existingMenu): ?>
                <button type="button" class="btn btn-outline-danger ms-auto" onclick="deleteMenu()">
                    <i class="bi bi-trash"></i> 削除
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- 削除用フォーム -->
<form method="post" id="deleteForm" style="display:none;">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="delete">
</form>

<script>
const SIZE = { width: 2500, height: 1686 };
let currentTemplate = <?= json_encode($currentTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let currentActions = <?= json_encode($currentActions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// テンプレート選択
document.querySelectorAll('.template-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.template-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentTemplate = this.dataset.template;
        document.getElementById('templateInput').value = currentTemplate;
        renderAreas();
        renderActionList();
    });
});

// 画像プレビュー
document.getElementById('imageInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev) {
        const img = document.getElementById('previewImg');
        img.src = ev.target.result;
        img.style.display = 'block';
        const placeholder = document.getElementById('previewPlaceholder');
        if (placeholder) placeholder.style.display = 'none';
    };
    reader.readAsDataURL(file);
});

function getAreas(template) {
    const w = SIZE.width, h = SIZE.height;
    switch (template) {
        case '1': return [{x:0,y:0,w:w,h:h}];
        case '2h': return [{x:0,y:0,w:w/2,h:h},{x:w/2,y:0,w:w/2,h:h}];
        case '2v': return [{x:0,y:0,w:w,h:h/2},{x:0,y:h/2,w:w,h:h/2}];
        case '3': return [{x:0,y:0,w:w/3,h:h},{x:w/3,y:0,w:w/3,h:h},{x:w*2/3,y:0,w:w/3,h:h}];
        case '4': return [{x:0,y:0,w:w/2,h:h/2},{x:w/2,y:0,w:w/2,h:h/2},{x:0,y:h/2,w:w/2,h:h/2},{x:w/2,y:h/2,w:w/2,h:h/2}];
        case '6': {
            const areas = [];
            for (let r=0;r<2;r++) for (let c=0;c<3;c++) areas.push({x:c*w/3,y:r*h/2,w:w/3,h:h/2});
            return areas;
        }
        default: return [{x:0,y:0,w:w,h:h}];
    }
}

function renderAreas() {
    const overlay = document.getElementById('previewOverlay');
    const areas = getAreas(currentTemplate);
    overlay.innerHTML = areas.map((a, i) => `
        <div class="preview-area" data-index="${i}"
             style="left:${a.x/SIZE.width*100}%;top:${a.y/SIZE.height*100}%;width:${a.w/SIZE.width*100}%;height:${a.h/SIZE.height*100}%"
             onclick="selectArea(${i})">${i+1}</div>
    `).join('');
}

function renderActionList() {
    const list = document.getElementById('actionList');
    const areas = getAreas(currentTemplate);
    list.innerHTML = areas.map((_, i) => {
        const act = currentActions[i] || {type:'message',text:''};
        return `
        <div class="action-card" id="actionCard${i}">
            <div class="action-header"><i class="bi bi-hand-index"></i> ボタン${i+1}</div>
            <div class="row g-2">
                <div class="col-4">
                    <select name="action_type[${i}]" class="form-select form-select-sm" onchange="toggleField(${i},this.value)">
                        <option value="message" ${act.type==='message'?'selected':''}>メッセージ</option>
                        <option value="uri" ${act.type==='uri'?'selected':''}>リンク</option>
                        <option value="postback" ${act.type==='postback'?'selected':''}>Postback</option>
                    </select>
                </div>
                <div class="col-8">
                    <div id="field_message_${i}" class="${act.type!=='message'?'d-none':''}">
                        <input type="text" name="action_text[${i}]" class="form-control form-control-sm" placeholder="送信テキスト" value="${escapeHtml(act.text||'')}">
                    </div>
                    <div id="field_uri_${i}" class="${act.type!=='uri'?'d-none':''}">
                        <input type="text" name="action_uri[${i}]" class="form-control form-control-sm" placeholder="https://..." value="${escapeHtml(act.uri||'')}">
                    </div>
                    <div id="field_postback_${i}" class="${act.type!=='postback'?'d-none':''}">
                        <input type="text" name="action_data[${i}]" class="form-control form-control-sm mb-1" placeholder="data" value="${escapeHtml(act.data||'')}">
                        <input type="text" name="action_display[${i}]" class="form-control form-control-sm" placeholder="表示テキスト" value="${escapeHtml(act.displayText||'')}">
                    </div>
                </div>
            </div>
        </div>`;
    }).join('');
}

function selectArea(i) {
    document.querySelectorAll('.preview-area').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.action-card').forEach(el => el.classList.remove('active'));
    document.querySelector(`.preview-area[data-index="${i}"]`)?.classList.add('active');
    document.getElementById('actionCard'+i)?.classList.add('active');
    document.getElementById('actionCard'+i)?.scrollIntoView({behavior:'smooth',block:'center'});
}

function toggleField(i, type) {
    ['message','uri','postback'].forEach(t => {
        document.getElementById(`field_${t}_${i}`).classList.toggle('d-none', t !== type);
    });
}

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function deleteMenu() {
    if (confirm('リッチメニューを削除しますか？')) {
        document.getElementById('deleteForm').submit();
    }
}

// 初期描画
renderAreas();
renderActionList();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
