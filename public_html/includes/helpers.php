<?php
/**
 * 汎用ヘルパー関数
 *
 * HTTP、フォーマット、フラッシュメッセージ、ページネーション、
 * セッション、営業区分の汎用関数群
 */

/**
 * リダイレクト（内部URLのみ許可）
 */
function redirect(string $url): void
{
    // 相対パス（/で始まるが//ではない）は許可
    // // は protocol-relative URL（Open Redirect の脆弱性）
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        // サブディレクトリ対応: ベースパスを先頭に追加
        $basePath = defined('BASE_PATH') ? BASE_PATH : '';
        header('Location: ' . $basePath . $url);
        exit;
    }

    // 絶対URLの場合はドメインを検証
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        $parsedUrl = parse_url($url);
        $parsedAppUrl = parse_url(APP_URL);

        // ドメインが一致する場合のみ許可
        if (isset($parsedUrl['host']) && isset($parsedAppUrl['host']) &&
            $parsedUrl['host'] === $parsedAppUrl['host']) {
            header('Location: ' . $url);
            exit;
        }

        // 外部URLへのリダイレクトは禁止
        http_response_code(400);
        exit('Invalid redirect URL');
    }

    // その他の形式も許可しない
    http_response_code(400);
    exit('Invalid redirect URL');
}

/**
 * URL生成（サブディレクトリ対応）
 */
function url(string $path): string
{
    $basePath = defined('BASE_PATH') ? BASE_PATH : '';
    return $basePath . $path;
}

/**
 * HTMLエスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * JSON レスポンス
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * POSTリクエストか判定
 */
function isPost(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * GETリクエストか判定
 */
function isGet(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * リクエストパラメータ取得（GET/POST）
 */
function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/**
 * 日付フォーマット
 */
function formatDate(?string $datetime, string $format = 'Y/m/d'): string
{
    if (!$datetime) {
        return '';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return '';
    }

    return date($format, $timestamp);
}

/**
 * 日時フォーマット
 */
function formatDateTime(?string $datetime, string $format = 'Y/m/d H:i'): string
{
    return formatDate($datetime, $format);
}

/**
 * 金額フォーマット
 */
function formatMoney(?int $amount): string
{
    if ($amount === null) {
        return '';
    }
    return number_format($amount) . '円';
}

/**
 * フラッシュメッセージ設定
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * フラッシュメッセージ取得（取得後削除）
 */
function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * 成功メッセージ設定
 */
function flashSuccess(string $message): void
{
    setFlash('success', $message);
}

/**
 * エラーメッセージ設定
 */
function flashError(string $message): void
{
    setFlash('error', $message);
}

/**
 * バリデーションエラー取得
 */
function getErrors(): array
{
    return $_SESSION['errors'] ?? [];
}

/**
 * バリデーションエラー設定
 */
function setErrors(array $errors): void
{
    $_SESSION['errors'] = $errors;
}

/**
 * バリデーションエラークリア
 */
function clearErrors(): void
{
    unset($_SESSION['errors']);
}

/**
 * 古い入力値取得（バリデーション失敗時用）
 */
function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

/**
 * 古い入力値設定
 */
function setOld(array $data): void
{
    $_SESSION['old'] = $data;
}

/**
 * 古い入力値クリア
 */
function clearOld(): void
{
    unset($_SESSION['old']);
}

/**
 * 予約ステータスの情報（ラベル + CSSクラス）
 * @return array{class: string, label: string}
 */
function reservationStatusInfo(string $status): array
{
    return match($status) {
        'pending'   => ['class' => 'secondary', 'label' => '保留'],
        'confirmed' => ['class' => 'primary',   'label' => '確定'],
        'cancelled' => ['class' => 'danger',    'label' => 'キャンセル'],
        'completed' => ['class' => 'success',   'label' => '完了'],
        default     => ['class' => 'secondary', 'label' => $status],
    };
}

/**
 * ステータスの日本語表示（後方互換）
 */
function statusLabel(string $status, string $type = 'job'): string
{
    if ($type === 'reservation') {
        return reservationStatusInfo($status)['label'];
    }

    $labels = [
        'unassigned' => '未割当',
        'recruiting' => '募集中',
        'assigned' => '確定',
        'completed' => '完了',
        'paid' => '支払済',
    ];

    return $labels[$status] ?? $status;
}

/**
 * ステータスのCSSクラス（後方互換）
 */
function statusClass(string $status): string
{
    $classes = [
        'unassigned' => 'secondary',
        'recruiting' => 'warning',
        'assigned' => 'primary',
        'completed' => 'success',
        'paid' => 'info',
        'pending' => 'secondary',
        'confirmed' => 'primary',
        'cancelled' => 'danger',
    ];

    return $classes[$status] ?? 'secondary';
}

/**
 * JSON エラーレスポンス
 */
function jsonErrorResponse(string $message, int $statusCode = 400, array $extra = []): void
{
    jsonResponse(array_merge(['error' => $message], $extra), $statusCode);
}

/**
 * 時刻文字列(HH:MM)を分に変換
 */
function timeToMinutes(string $time): int
{
    if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
        return 0;
    }
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)$parts[1];
}

/**
 * CSV出力値のサニタイズ（Formula Injection対策）
 */
function sanitizeCsvValue(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    // Excel等で式として解釈される文字で始まる場合、先頭にシングルクォートを付与
    if (preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

/**
 * 顧客入力バリデーション
 * @param array $input ['customer_name'=>..., 'customer_phone'=>..., 'customer_email'=>..., 'num_people'=>..., 'notes'=>...]
 * @param array $options ['require_email'=>bool, 'require_phone'=>bool]
 * @return array エラーメッセージ配列（空なら正常）
 */
function validateCustomerInput(array $input, array $options = []): array
{
    $errors = [];
    $requireEmail = $options['require_email'] ?? false;
    $requirePhone = $options['require_phone'] ?? false;

    $customerName = $input['customer_name'] ?? '';
    $customerPhone = $input['customer_phone'] ?? '';
    $customerEmail = $input['customer_email'] ?? '';
    $numPeople = (int)($input['num_people'] ?? 1);
    $notes = $input['notes'] ?? '';

    // 顧客名
    if (empty($customerName)) {
        $errors[] = $requireEmail ? 'お名前を入力してください' : '顧客名は必須です';
    } elseif (mb_strlen($customerName) > 100) {
        $errors[] = $requireEmail ? 'お名前は100文字以内で入力してください' : '顧客名は100文字以内で入力してください';
    }

    // 電話番号
    if ($requirePhone && empty($customerPhone)) {
        $errors[] = '電話番号を入力してください';
    }
    if (!empty($customerPhone)) {
        if (mb_strlen($customerPhone) > 20) {
            $errors[] = '電話番号は20文字以内で入力してください';
        } elseif (!preg_match('/^[0-9\-\+\s\(\)]+$/', $customerPhone)) {
            $errors[] = '電話番号に使用できない文字が含まれています';
        }
    }

    // メール
    if ($requireEmail && empty($customerEmail)) {
        $errors[] = 'メールアドレスを入力してください';
    }
    if (!empty($customerEmail)) {
        if (mb_strlen($customerEmail) > 255) {
            $errors[] = 'メールアドレスは255文字以内で入力してください';
        } elseif (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません';
        }
    }

    // 人数
    if ($numPeople < 1 || $numPeople > 10) {
        $errors[] = '人数は1〜10人で指定してください';
    }

    // 備考
    if (mb_strlen($notes) > 1000) {
        $errors[] = '備考は1000文字以内で入力してください';
    }

    return $errors;
}

/**
 * ページネーションHTML生成
 */
function renderPagination(int $currentPage, int $totalPages, array $queryParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav><ul class="pagination mb-0 justify-content-center">';

    // 前へ
    if ($currentPage > 1) {
        $prevUrl = '?' . http_build_query(array_merge($queryParams, ['page' => $currentPage - 1]));
        $html .= '<li class="page-item"><a class="page-link" href="' . h($prevUrl) . '">前へ</a></li>';
    }

    // ページ番号
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    for ($i = $start; $i <= $end; $i++) {
        $pageUrl = '?' . http_build_query(array_merge($queryParams, ['page' => $i]));
        $activeClass = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . h($pageUrl) . '">' . $i . '</a></li>';
    }

    // 次へ
    if ($currentPage < $totalPages) {
        $nextUrl = '?' . http_build_query(array_merge($queryParams, ['page' => $currentPage + 1]));
        $html .= '<li class="page-item"><a class="page-link" href="' . h($nextUrl) . '">次へ</a></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

/**
 * ページネーション計算
 * @return array{page: int, offset: int, totalPages: int}
 */
function calculatePagination(int $totalCount, int $perPage, int $requestedPage): array
{
    $perPage = max(1, min($perPage, 100));
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = max(1, min($requestedPage, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'page' => $page,
        'offset' => $offset,
        'totalPages' => $totalPages,
    ];
}

/**
 * 時間フォーマット（HH:MM形式）
 */
function formatTime(?string $time): string
{
    return $time ? substr($time, 0, 5) : '-';
}

/**
 * 時間範囲フォーマット
 */
function formatTimeRange(?string $start, ?string $end): string
{
    return formatTime($start) . ' - ' . formatTime($end);
}

/**
 * 曜日名取得
 */
function getDayName(int $dayOfWeek): string
{
    $dayNames = ['日', '月', '火', '水', '木', '金', '土'];
    return $dayNames[$dayOfWeek] ?? '-';
}

/**
 * アクセス可能な営業区分一覧を取得
 * @param array $storeIds アクセス可能な店舗ID配列
 * @return array 営業区分配列
 */
function getAccessibleSalesAreas(array $storeIds): array
{
    if (empty($storeIds)) {
        return [];
    }

    $inClause = buildInClause($storeIds);
    return dbSelect(
        "SELECT sa.*, s.name as store_name FROM sales_areas sa
         INNER JOIN stores s ON sa.store_id = s.id
         WHERE sa.store_id IN ({$inClause['placeholders']}) AND sa.deleted_at IS NULL
         ORDER BY s.name, sa.name",
        $inClause['params']
    );
}

/**
 * 営業区分配列から指定IDの営業区分を取得
 * @param array $salesAreas 営業区分配列
 * @param int $salesAreaId 検索する営業区分ID
 * @return array|null 見つかった営業区分、なければnull
 */
function findSalesArea(array $salesAreas, int $salesAreaId): ?array
{
    foreach ($salesAreas as $area) {
        if ((int)$area['id'] === $salesAreaId) {
            return $area;
        }
    }
    return null;
}

/**
 * ページネーション付きリスト取得の定型処理
 * @param string $countQuery カウント用クエリ（SELECT COUNT(*) as cnt ...）
 * @param string $selectQuery データ取得用クエリ（SELECT ...）
 * @param array $params バインドパラメータ配列
 * @param int $perPage 1ページあたりの件数
 * @param int $page リクエストされたページ番号
 * @return array ['data' => array, 'pagination' => array, 'totalCount' => int]
 */
function getPagedList(string $countQuery, string $selectQuery, array $params, int $perPage = 20, int $page = 1): array
{
    $countResult = dbSelectOne($countQuery, $params);
    $totalCount = (int) ($countResult['cnt'] ?? 0);

    $pagination = calculatePagination($totalCount, $perPage, $page);

    $data = dbSelectPaginated(
        $selectQuery,
        $params,
        $perPage,
        $pagination['offset']
    );

    return [
        'data' => $data,
        'pagination' => $pagination,
        'totalCount' => $totalCount,
    ];
}

/**
 * 店舗設定を取得（リクエスト内キャッシュ付き）
 *
 * storesテーブルから設定値を取得し、config.php定数をフォールバックとして使用。
 * フォールバック優先順位:
 *   清掃時間: 営業区分(nullable) > 店舗設定 > config定数
 *   その他: 店舗設定 > config定数
 *
 * @param int $storeId 店舗ID
 * @return array 設定値の連想配列
 */
function getStoreSettings(int $storeId): array
{
    static $cache = [];

    if (isset($cache[$storeId])) {
        return $cache[$storeId];
    }

    $store = dbSelectOne(
        "SELECT default_hourly_rate, max_duration_hours, min_duration_hours,
                booking_days_ahead, extension_price_per_hour, max_extension_hours,
                cleaning_time_minutes, base_reward
         FROM stores WHERE id = ? AND deleted_at IS NULL",
        [$storeId]
    );

    $cache[$storeId] = [
        'default_hourly_rate'     => (int)($store['default_hourly_rate'] ?? DEFAULT_HOURLY_RATE),
        'max_duration_hours'      => (int)($store['max_duration_hours'] ?? MAX_BOOKING_DURATION_HOURS),
        'min_duration_hours'      => (int)($store['min_duration_hours'] ?? MIN_BOOKING_DURATION_HOURS),
        'booking_days_ahead'      => (int)($store['booking_days_ahead'] ?? 30),
        'extension_price_per_hour' => (int)($store['extension_price_per_hour'] ?? EXTENSION_PRICE_PER_HOUR),
        'max_extension_hours'     => (float)($store['max_extension_hours'] ?? MAX_EXTENSION_HOURS),
        'cleaning_time_minutes'   => (int)($store['cleaning_time_minutes'] ?? CLEANING_TIME_MINUTES),
        'base_reward'             => (int)($store['base_reward'] ?? DEFAULT_CLEANING_REWARD),
    ];

    return $cache[$storeId];
}
