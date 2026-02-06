<?php
/**
 * LINE API ヘルパー関数
 */

/**
 * 清掃者用LINE設定を取得
 */
function getCleanerLineConfig(): ?array
{
    return dbSelectOne(
        "SELECT * FROM line_accounts
         WHERE account_type = 'cleaner' AND is_active = 1 AND deleted_at IS NULL"
    );
}

/**
 * 店舗用LINE設定を取得
 */
function getStoreLineConfig(int $storeId): ?array
{
    return dbSelectOne(
        "SELECT * FROM line_accounts
         WHERE account_type = 'store' AND store_id = ? AND is_active = 1 AND deleted_at IS NULL",
        [$storeId]
    );
}

/**
 * Channel IDからLINE設定を取得
 */
function getLineConfigByChannelId(string $channelId): ?array
{
    return dbSelectOne(
        "SELECT * FROM line_accounts
         WHERE channel_id = ? AND is_active = 1 AND deleted_at IS NULL",
        [$channelId]
    );
}

/**
 * Webhook署名を検証
 */
function verifyLineSignature(string $body, string $signature, string $channelSecret): bool
{
    $hash = hash_hmac('sha256', $body, $channelSecret, true);
    $expectedSignature = base64_encode($hash);
    return hash_equals($expectedSignature, $signature);
}

/**
 * Webhook冪等性チェック（重複イベント検出）
 */
function isWebhookEventProcessed(string $eventId): bool
{
    $existing = dbSelectOne(
        "SELECT id FROM webhook_events WHERE event_id = ?",
        [$eventId]
    );
    return $existing !== null;
}

/**
 * Webhookイベントを処理済みとして記録
 */
function markWebhookEventProcessed(string $eventId, string $eventType, string $accountType, ?int $storeId = null): void
{
    dbInsert('webhook_events', [
        'event_id' => $eventId,
        'account_type' => $accountType,
        'store_id' => $storeId,
        'event_type' => $eventType
    ]);
}

/**
 * Push Messageを送信
 */
function sendLinePushMessage(
    string $lineUserId,
    array $messages,
    string $accountType = 'cleaner',
    ?int $storeId = null
): bool {
    // store用の場合、storeIdは必須
    if ($accountType !== 'cleaner' && $storeId === null) {
        error_log('LINE sendLinePushMessage: storeId is required for accountType=' . $accountType);
        return false;
    }

    $config = $accountType === 'cleaner'
        ? getCleanerLineConfig()
        : getStoreLineConfig($storeId);

    if (!$config || empty($config['channel_access_token'])) {
        error_log("LINE Push Message failed: No config for {$accountType}" . ($storeId ? " store:{$storeId}" : ""));
        return false;
    }

    return sendLineApiRequest(
        'https://api.line.me/v2/bot/message/push',
        ['to' => $lineUserId, 'messages' => $messages],
        $config['channel_access_token']
    );
}

/**
 * テキストメッセージをPush送信（sendLineNotification の置き換え）
 *
 * cleaner用アカウントでテキストメッセージを送信するショートカット。
 * 内部で sendLinePushMessage() を使用するためリトライ機能も適用される。
 *
 * @param string $lineUserId LINE User ID
 * @param string $message テキストメッセージ
 * @param string $accountType アカウント種別（'cleaner' or 'store'）
 * @param int|null $storeId 店舗ID（store の場合必須）
 * @return bool 成功したらtrue
 */
function sendLineTextPush(
    string $lineUserId,
    string $message,
    string $accountType = 'cleaner',
    ?int $storeId = null
): bool {
    if (empty($lineUserId) || empty($message)) {
        error_log('LINE sendLineTextPush: empty lineUserId or message');
        return false;
    }

    return sendLinePushMessage(
        $lineUserId,
        [['type' => 'text', 'text' => $message]],
        $accountType,
        $storeId
    );
}

/**
 * Reply Messageを送信
 */
function sendLineReplyMessage(string $replyToken, array $messages, string $accessToken): bool
{
    return sendLineApiRequest(
        'https://api.line.me/v2/bot/message/reply',
        ['replyToken' => $replyToken, 'messages' => $messages],
        $accessToken
    );
}

/**
 * Reply送信を試み、失敗時はPushにフォールバック
 *
 * webhook.php の replyMessage() ロジックを統合。
 * userIdがある場合は最初からPush APIを使用（Reply APIはタイムアウトしやすいため）。
 *
 * @param string $replyToken Reply Token
 * @param array|array[] $messages メッセージ（単一メッセージまたはメッセージ配列）
 * @param string $accessToken Channel Access Token
 * @param string|null $lineUserId LINE User ID（あればPush優先）
 * @return bool 成功したらtrue
 */
function sendLineReplyOrPush(string $replyToken, $messages, string $accessToken, ?string $lineUserId = null): bool
{
    if (empty($messages)) {
        error_log('LINE sendLineReplyOrPush: empty messages');
        return false;
    }

    // 単一メッセージを配列に正規化
    if (!is_array($messages) || !isset($messages[0])) {
        $messages = [$messages];
    }

    if (empty($accessToken)) {
        error_log('LINE sendLineReplyOrPush: empty accessToken');
        return false;
    }

    // userIdがあれば最初からPush APIを使う（Reply APIはタイムアウトしやすいため）
    if ($lineUserId) {
        return sendLineApiRequest(
            'https://api.line.me/v2/bot/message/push',
            ['to' => $lineUserId, 'messages' => $messages],
            $accessToken
        );
    }

    // userIdがない場合のみReply APIを試す
    return sendLineReplyMessage($replyToken, $messages, $accessToken);
}

/**
 * LINE API リクエスト共通処理（指数バックオフ付きリトライ）
 *
 * @param string $url APIエンドポイントURL
 * @param array $data リクエストボディ
 * @param string $accessToken Channel Access Token
 * @param int $maxRetries 最大リトライ回数（cURLエラーまたは5xx系エラー時のみリトライ）
 * @return bool 成功したらtrue
 */
function sendLineApiRequest(string $url, array $data, string $accessToken, int $maxRetries = 2): bool
{
    $lastError = '';

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // cURLエラー: リトライ可能
        if ($curlErrno !== 0) {
            $lastError = "cURL error ({$curlErrno}): {$curlError}";
            error_log("LINE API cURL error (attempt {$attempt}): {$lastError}");
            if ($attempt < $maxRetries) {
                usleep(500000 * ($attempt + 1)); // 0.5s, 1s, ...
                continue;
            }
            return false;
        }

        // 成功
        if ($httpCode === 200) {
            return true;
        }

        // 5xx系サーバーエラー: リトライ可能
        if ($httpCode >= 500 && $attempt < $maxRetries) {
            $lastError = "HTTP {$httpCode}, Response: {$response}";
            error_log("LINE API server error (attempt {$attempt}): {$lastError}");
            usleep(500000 * ($attempt + 1));
            continue;
        }

        // 4xx系クライアントエラー: リトライ不可
        error_log("LINE API error: HTTP {$httpCode}, Response: {$response}");
        return false;
    }

    error_log("LINE API failed after {$maxRetries} retries: {$lastError}");
    return false;
}

/**
 * リッチメニューを作成
 */
function createLineRichMenu(array $menuData, string $accessToken): ?string
{
    $ch = curl_init('https://api.line.me/v2/bot/richmenu');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS => json_encode($menuData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['richMenuId'] ?? null;
    }

    error_log("Create RichMenu failed: HTTP {$httpCode} - {$response}");
    return null;
}

/**
 * リッチメニューに画像をアップロード
 */
function uploadRichMenuImage(string $richMenuId, string $imagePath, string $accessToken): bool
{
    if (!file_exists($imagePath)) {
        error_log("RichMenu image not found: {$imagePath}");
        return false;
    }

    $ch = curl_init("https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: image/png',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS => file_get_contents($imagePath),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * リッチメニューをデフォルトに設定
 */
function setDefaultRichMenu(string $richMenuId, string $accessToken): bool
{
    $ch = curl_init("https://api.line.me/v2/bot/user/all/richmenu/{$richMenuId}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * リッチメニューを削除
 */
function deleteLineRichMenu(string $richMenuId, string $accessToken): bool
{
    $ch = curl_init("https://api.line.me/v2/bot/richmenu/{$richMenuId}");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * LIFFのID Token検証（サーバーサイド）
 */
function verifyLiffIdToken(string $idToken, string $channelId): ?array
{
    $ch = curl_init('https://api.line.me/oauth2/v2.1/verify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'id_token' => $idToken,
            'client_id' => $channelId
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return json_decode($response, true);
    }

    return null;
}

/**
 * Webhook URLを生成
 */
function generateWebhookUrl(string $accountType, ?int $storeId = null): string
{
    $baseUrl = rtrim(APP_URL, '/');

    if ($accountType === 'cleaner') {
        return $baseUrl . '/api/line/webhook';
    }

    // 店舗用は店舗コードを使用
    if ($storeId) {
        $store = dbSelectOne("SELECT code FROM stores WHERE id = ?", [$storeId]);
        if ($store && $store['code']) {
            return $baseUrl . '/api/line/webhook/store/' . $store['code'];
        }
    }

    return $baseUrl . '/api/line/webhook/store/' . $storeId;
}

/**
 * リッチメニューサイズを取得
 */
function getRichMenuSize(string $sizeType): array
{
    return match($sizeType) {
        'large' => ['width' => 2500, 'height' => 1686],
        'compact' => ['width' => 2500, 'height' => 843],
        'small' => ['width' => 1200, 'height' => 810],
        default => ['width' => 2500, 'height' => 1686]
    };
}
