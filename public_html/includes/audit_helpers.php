<?php
/**
 * 監査・セキュリティ関連ヘルパー関数
 *
 * 監査ログ記録、機密データマスク、レート制限の関数群
 */

/**
 * 監査ログから機密情報をマスクする
 * @param array|null $data データ配列
 * @return array|null マスク済みデータ
 */
function maskSensitiveData(?array $data): ?array
{
    if ($data === null) {
        return null;
    }

    // マスク対象のキー（機密情報）
    $sensitiveKeys = [
        'password', 'password_hash', 'secret', 'token', 'access_token',
        'channel_access_token', 'channel_secret', 'api_key', 'api_secret',
        'ipass_code', 'registration_token', 'reset_token', 'line_user_id',
        'credit_card', 'card_number', 'cvv', 'pin',
    ];

    $masked = [];
    foreach ($data as $key => $value) {
        $lowerKey = strtolower($key);

        // 機密キーかチェック
        $isSensitive = false;
        foreach ($sensitiveKeys as $sensitiveKey) {
            if (str_contains($lowerKey, $sensitiveKey)) {
                $isSensitive = true;
                break;
            }
        }

        if ($isSensitive) {
            // 値があることだけ記録（実際の値はマスク）
            $masked[$key] = $value !== null ? '[REDACTED]' : null;
        } elseif (is_array($value)) {
            // ネストした配列も再帰的にマスク
            $masked[$key] = maskSensitiveData($value);
        } else {
            $masked[$key] = $value;
        }
    }

    return $masked;
}

/**
 * 操作ログを記録
 * @param string $action 操作種別（create/update/delete等）
 * @param string $targetType 対象エンティティ（reservation/job等）
 * @param int|null $targetId 対象ID
 * @param array|null $oldValue 変更前の値
 * @param array|null $newValue 変更後の値
 * @return int|null 挿入されたログID
 */
function logAudit(string $action, string $targetType, ?int $targetId = null, ?array $oldValue = null, ?array $newValue = null): ?int
{
    try {
        $userId = getCurrentUserId();

        // 機密情報をマスク
        $oldValue = maskSensitiveData($oldValue);
        $newValue = maskSensitiveData($newValue);

        // JSONエンコード（失敗時はnull）
        $oldJson = null;
        $newJson = null;

        if ($oldValue !== null) {
            $oldJson = json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($newValue !== null) {
            $newJson = json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return dbInsert('audit_logs', [
            'user_id' => $userId ?: null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_value' => $oldJson,
            'new_value' => $newJson,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (JsonException $e) {
        error_log("logAudit JSON encode error: " . $e->getMessage());
        return null;
    } catch (Exception $e) {
        error_log("logAudit error: " . $e->getMessage());
        return null;
    }
}

/**
 * レート制限チェック（APCu優先、ファイルベースフォールバック）
 *
 * APCuが利用可能な場合はAPCuを使用（高速・アトミック）。
 * APCuが利用不可の場合はファイルロック方式にフォールバック。
 *
 * @param string $key レート制限のキー（例: 'booking_check_' . md5($ip)）
 * @param int $maxRequests 許容リクエスト数/分
 * @param int $windowSeconds 時間窓（秒）
 * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
 */
function checkRateLimit(string $key, int $maxRequests = 30, int $windowSeconds = 60): array
{
    // APCuが使える場合はAPCuを優先（高速・アトミック）
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        return checkRateLimitApcu($key, $maxRequests, $windowSeconds);
    }

    // APCuが使えない場合はファイルベースにフォールバック
    return checkRateLimitFile($key, $maxRequests, $windowSeconds);
}

/**
 * APCuベースのレート制限チェック
 */
function checkRateLimitApcu(string $key, int $maxRequests, int $windowSeconds): array
{
    $currentWindow = (int) (time() / $windowSeconds);
    $apcuKey = 'rate_limit_' . $key . '_' . $currentWindow;

    // apcu_add はキーが存在しない場合のみ設定するアトミック操作
    apcu_add($apcuKey, 0, $windowSeconds + 10);
    // apcu_inc はアトミックなインクリメント
    $requestCount = apcu_inc($apcuKey);

    $allowed = ($requestCount <= $maxRequests);
    $remaining = max(0, $maxRequests - $requestCount);
    $retryAfter = $allowed ? 0 : $windowSeconds - (time() % $windowSeconds);

    return [
        'allowed' => $allowed,
        'remaining' => $remaining,
        'retry_after' => $retryAfter,
    ];
}

/**
 * ファイルベースのレート制限チェック（ファイルロック付き、競合状態対策）
 */
function checkRateLimitFile(string $key, int $maxRequests, int $windowSeconds): array
{
    $rateLimitDir = sys_get_temp_dir() . '/rate_limits';
    if (!is_dir($rateLimitDir)) {
        @mkdir($rateLimitDir, 0755, true);
    }

    $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
    if ($safeKey === '') {
        $safeKey = md5($key);
    }
    $rateLimitFile = $rateLimitDir . '/' . $safeKey;
    $currentWindow = (int) (time() / $windowSeconds);

    // ファイルを排他ロックで開く
    $fp = @fopen($rateLimitFile, 'c+');
    if (!$fp) {
        // ファイルオープン失敗時は拒否（フェイルクローズ）
        error_log('checkRateLimitFile: failed to open rate limit file: ' . $rateLimitFile);
        return ['allowed' => false, 'remaining' => 0, 'retry_after' => $windowSeconds];
    }

    // 排他ロック取得（ブロッキング）
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        error_log('checkRateLimitFile: failed to acquire lock: ' . $rateLimitFile);
        return ['allowed' => false, 'remaining' => 0, 'retry_after' => $windowSeconds];
    }

    // ファイル内容を読み取り
    $data = '';
    $size = filesize($rateLimitFile);
    if ($size > 0) {
        $data = fread($fp, $size);
    }

    $storedWindow = 0;
    $requestCount = 0;

    if ($data) {
        $parts = explode(':', $data);
        $storedWindow = (int) ($parts[0] ?? 0);
        $requestCount = (int) ($parts[1] ?? 0);
    }

    // 時間窓が変わったらカウントリセット
    if ($storedWindow !== $currentWindow) {
        $requestCount = 0;
    }

    $requestCount++;
    $allowed = ($requestCount <= $maxRequests);
    $remaining = max(0, $maxRequests - $requestCount);
    $retryAfter = $allowed ? 0 : $windowSeconds - (time() % $windowSeconds);

    // 新しいカウントを書き込み
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, "{$currentWindow}:{$requestCount}");
    fflush($fp);

    // ロック解除してクローズ
    flock($fp, LOCK_UN);
    fclose($fp);

    return [
        'allowed' => $allowed,
        'remaining' => $remaining,
        'retry_after' => $retryAfter,
    ];
}
