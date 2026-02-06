<?php
/**
 * 認証・セッション・権限管理
 */

/**
 * セッション開始
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * ログイン試行回数をチェック（DBベース・IP単位）
 * セッションベースだとセッション破棄で回避されるため、DBで管理
 */
function isLoginLocked(string $email): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) {
        return false;
    }

    // IP+Email単位でロック判定
    $attempt = dbSelectOne(
        "SELECT attempts, locked_until FROM login_attempts
         WHERE ip_address = ? AND email = ?",
        [$ip, $email]
    );

    if ($attempt && $attempt['locked_until'] && strtotime($attempt['locked_until']) > time()) {
        return true;
    }

    return false;
}

/**
 * ログインロック解除までの残り秒数を取得
 */
function getLoginLockRemaining(string $email): int
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) {
        return 0;
    }

    $attempt = dbSelectOne(
        "SELECT locked_until FROM login_attempts WHERE ip_address = ? AND email = ?",
        [$ip, $email]
    );

    if (!$attempt || !$attempt['locked_until']) {
        return 0;
    }

    $remaining = strtotime($attempt['locked_until']) - time();
    return max(0, $remaining);
}

/**
 * ログイン試行回数を記録（DBベース・IP単位）
 */
function recordLoginAttempt(string $email, bool $success): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) {
        return;
    }

    if ($success) {
        // ログイン成功時はこのIP+Emailの記録をリセット
        dbExecute("DELETE FROM login_attempts WHERE ip_address = ? AND email = ?", [$ip, $email]);
        return;
    }

    // 既存レコードを取得（IP+Email単位）
    $attempt = dbSelectOne(
        "SELECT id, attempts, last_attempt_at FROM login_attempts WHERE ip_address = ? AND email = ?",
        [$ip, $email]
    );

    $now = date('Y-m-d H:i:s');

    if ($attempt) {
        // 最終試行から一定時間経過していたらリセット
        $lastAttemptTime = strtotime($attempt['last_attempt_at']);
        if (time() - $lastAttemptTime > LOGIN_LOCKOUT_TIME) {
            // 古いレコードなのでリセット
            dbUpdate('login_attempts', [
                'attempts' => 1,
                'last_attempt_at' => $now,
                'locked_until' => null,
            ], 'id = ?', [$attempt['id']]);
            return;
        }

        // 試行回数をインクリメント
        $newAttempts = $attempt['attempts'] + 1;
        $lockedUntil = null;

        // 上限に達したらロック
        if ($newAttempts >= LOGIN_MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
        }

        dbUpdate('login_attempts', [
            'attempts' => $newAttempts,
            'last_attempt_at' => $now,
            'locked_until' => $lockedUntil,
        ], 'id = ?', [$attempt['id']]);
    } else {
        // 新規レコード作成
        dbInsert('login_attempts', [
            'ip_address' => $ip,
            'email' => $email,
            'attempts' => 1,
            'last_attempt_at' => $now,
            'locked_until' => null,
        ]);
    }
}

/**
 * ログイン処理
 */
function login(string $email, string $password): bool|string
{
    // ログインロックチェック
    if (isLoginLocked($email)) {
        $remaining = getLoginLockRemaining($email);
        $minutes = (int) ceil($remaining / 60);
        return "ログイン試行回数が上限に達しました。約{$minutes}分後に再試行してください。";
    }

    $user = dbSelectOne(
        "SELECT * FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL",
        [$email]
    );

    // Timing Attack対策: ユーザーが存在しない場合でもpassword_verifyを実行
    // 有効なbcryptハッシュ（事前生成済み、対応する平文パスワードは存在しない）
    $dummyHash = '$2y$12$K4IzKSGE7LBJYGX0g0eTku6VxS0Rm9Fc5pGlKsAiON2MUb.HdMXsW';
    $passwordValid = $user
        ? password_verify($password, $user['password'])
        : password_verify($password, $dummyHash);

    if (!$user || !$passwordValid) {
        recordLoginAttempt($email, false);
        return false;
    }

    // ログイン成功
    recordLoginAttempt($email, true);

    // セッション再生成（セッション固定攻撃対策）
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    // 最終ログイン日時を更新
    dbUpdate('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

    // 店舗選択を「全店舗」で初期化
    $_SESSION['selected_store_id'] = null;

    return true;
}

/**
 * ログアウト処理
 */
function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * ログイン済みか確認
 */
function isLoggedIn(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    // セッション有効期限チェック（最終アクセス時刻ベース）
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        logout();
        return false;
    }

    // 最終アクセス時刻を更新
    $_SESSION['last_activity'] = time();

    return true;
}

/**
 * ログインユーザー情報取得（DBから取得、リクエスト内キャッシュ）
 * @param bool $refresh trueの場合はキャッシュを無視して再取得
 */
function currentUser(bool $refresh = false): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    static $user = null;

    if ($user === null || $refresh) {
        $user = dbSelectOne(
            "SELECT id, name, email, role, owner_id, store_id FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL",
            [$_SESSION['user_id']]
        );

        // ユーザーが無効化されていた場合
        if (!$user) {
            logout();
            return null;
        }
    }

    return $user;
}

/**
 * ログイン必須チェック（未ログインならリダイレクト）
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('/login');
    }
}

/**
 * 権限チェック（3階層: HQ > OWNER > STORE）
 */
function hasRole(string $requiredRole): bool
{
    $user = currentUser();
    if (!$user) {
        return false;
    }

    $roles = ['STORE' => 1, 'OWNER' => 2, 'HQ' => 3];
    $userLevel = $roles[$user['role']] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;

    return $userLevel >= $requiredLevel;
}

/**
 * 権限必須チェック（権限なしなら403）
 */
function requireRole(string $role): void
{
    requireLogin();

    if (!hasRole($role)) {
        http_response_code(403);
        exit('アクセス権限がありません');
    }
}

/**
 * 店舗アクセス権チェック
 */
function canAccessStore(int $storeId): bool
{
    $user = currentUser();
    if (!$user) {
        return false;
    }

    // HQは全店舗アクセス可
    if ($user['role'] === 'HQ') {
        return true;
    }

    // OWNERは自オーナー配下店舗にアクセス可
    if ($user['role'] === 'OWNER') {
        if (empty($user['owner_id'])) {
            return false;
        }
        $store = dbSelectOne(
            "SELECT 1 FROM stores WHERE id = ? AND owner_id = ? AND deleted_at IS NULL LIMIT 1",
            [$storeId, $user['owner_id']]
        );
        return $store !== null;
    }

    // STOREは紐付けテーブルで確認
    $access = dbSelectOne(
        "SELECT 1 FROM user_stores WHERE user_id = ? AND store_id = ? LIMIT 1",
        [$user['id'], $storeId]
    );

    return $access !== null;
}

/**
 * 店舗アクセス権必須チェック
 */
function requireStoreAccess(int $storeId): void
{
    requireLogin();

    if (!canAccessStore($storeId)) {
        http_response_code(403);
        exit('この店舗へのアクセス権限がありません');
    }
}

/**
 * CSRFトークン生成（有効期限付き）
 */
function generateCsrfToken(): string
{
    // 既存トークンの有効期限チェック
    if (!empty($_SESSION[CSRF_TOKEN_NAME]) && !empty($_SESSION['csrf_token_time'])) {
        if (time() - $_SESSION['csrf_token_time'] < CSRF_TOKEN_LIFETIME) {
            return $_SESSION[CSRF_TOKEN_NAME];
        }
    }

    // 新しいトークン生成
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();

    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * CSRFトークン検証
 * 検証成功後にトークンを削除（リプレイ攻撃対策）
 */
function verifyCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }

    // トークン長と形式の検証
    if (strlen($token) !== CSRF_TOKEN_LENGTH || !ctype_xdigit($token)) {
        return false;
    }

    // 有効期限チェック
    if (empty($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_LIFETIME) {
        unset($_SESSION[CSRF_TOKEN_NAME], $_SESSION['csrf_token_time']);
        return false;
    }

    $valid = hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);

    // 検証成功後にトークンを削除（リプレイ攻撃対策）
    // 次回のフォーム表示時に新しいトークンが生成される
    if ($valid) {
        unset($_SESSION[CSRF_TOKEN_NAME], $_SESSION['csrf_token_time']);
    }

    return $valid;
}

/**
 * CSRFトークン読み取り専用検証（トークンを消費しない）
 * GETリクエスト（CSVエクスポートリンク等）向け
 *
 * ReadOnly版のため、期限切れトークンの削除も行わない。
 * トークンのライフサイクル管理は verifyCsrfToken() に委ねる。
 */
function verifyCsrfTokenReadOnly(?string $token): bool
{
    if (empty($token) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }

    if (strlen($token) !== CSRF_TOKEN_LENGTH || !ctype_xdigit($token)) {
        return false;
    }

    if (empty($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_LIFETIME) {
        return false;
    }

    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * CSRF検証必須（失敗なら403）
 */
function requireCsrf(): void
{
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        exit('不正なリクエストです');
    }
}

/**
 * パスワードハッシュ生成
 */
function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
}

/**
 * 現在のログインユーザーIDを取得
 * @return int|null ユーザーID、未ログインならnull
 */
function getCurrentUserId(): ?int
{
    if (!isLoggedIn()) {
        return null;
    }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * ログインユーザーがアクセス可能な店舗IDリストを取得
 * @return array{ids: array, stores: array}
 */
function getAccessibleStoreIds(): array
{
    $user = currentUser();
    if (!$user) {
        return ['ids' => [], 'stores' => []];
    }

    if ($user['role'] === 'HQ') {
        // HQは全店舗アクセス可能
        $stores = dbSelect("SELECT id, name FROM stores WHERE deleted_at IS NULL ORDER BY name");
    } elseif ($user['role'] === 'OWNER') {
        // OWNERは自オーナー配下店舗
        if (empty($user['owner_id'])) {
            // owner_id未設定の場合は空
            $stores = [];
        } else {
            $stores = dbSelect(
                "SELECT id, name FROM stores
                 WHERE owner_id = ? AND deleted_at IS NULL
                 ORDER BY name",
                [$user['owner_id']]
            );
        }
    } else {
        // STOREは user_stores テーブルを参照
        $stores = dbSelect(
            "SELECT s.id, s.name FROM stores s
             INNER JOIN user_stores us ON s.id = us.store_id
             WHERE us.user_id = ? AND s.deleted_at IS NULL
             ORDER BY s.name",
            [$user['id']]
        );
    }

    return [
        'ids' => array_column($stores, 'id'),
        'stores' => $stores,
    ];
}

/**
 * 選択中の店舗をセッションに保存
 * @param int|null $storeId 店舗ID（nullで全店舗）
 * @return bool 設定成功時true
 */
function setSelectedStore(?int $storeId): bool
{
    // nullは「全店舗」を意味する
    if ($storeId === null) {
        $_SESSION['selected_store_id'] = null;
        return true;
    }

    // 指定店舗へのアクセス権をチェック
    if (!canAccessStore($storeId)) {
        return false;
    }

    $_SESSION['selected_store_id'] = $storeId;
    return true;
}

/**
 * 選択中の店舗IDを取得（権限チェック付き）
 * アクセス権がない店舗が選択されていた場合はnull（全店舗）にリセット
 * @return int|null 店舗ID、またはnull（全店舗）
 */
function getSelectedStore(): ?int
{
    $selectedId = $_SESSION['selected_store_id'] ?? null;

    if ($selectedId === null) {
        return null;
    }

    // 型チェック（セッション改ざん対策）
    if (!is_int($selectedId) && !is_numeric($selectedId)) {
        $_SESSION['selected_store_id'] = null;
        return null;
    }

    $selectedId = (int)$selectedId;

    // 権限チェック（アクセス権がなくなった場合はリセット）
    if (!canAccessStore($selectedId)) {
        $_SESSION['selected_store_id'] = null;
        return null;
    }

    return $selectedId;
}

/**
 * クエリ用の店舗ID配列を取得
 * セッションで店舗が選択されている場合はその店舗のみ、
 * 選択されていない場合はアクセス可能な全店舗を返す
 *
 * @return array{ids: array, stores: array, selected: int|null}
 */
function getFilteredStoreIds(): array
{
    $storeAccess = getAccessibleStoreIds();
    $selectedId = getSelectedStore();

    // 単一店舗選択時
    if ($selectedId !== null) {
        // 選択店舗がアクセス可能リストにあるか確認
        $selectedStore = null;
        foreach ($storeAccess['stores'] as $store) {
            if ((int)$store['id'] === $selectedId) {
                $selectedStore = $store;
                break;
            }
        }

        if ($selectedStore) {
            return [
                'ids' => [$selectedId],
                'stores' => $storeAccess['stores'],
                'selected' => $selectedId,
            ];
        }

        // 選択店舗が見つからない場合はリセット
        $_SESSION['selected_store_id'] = null;
    }

    // 全店舗（アクセス可能な全店舗）
    return [
        'ids' => $storeAccess['ids'],
        'stores' => $storeAccess['stores'],
        'selected' => null,
    ];
}
