<?php
/**
 * データベース接続・クエリ
 */

// 許可されたテーブル名のホワイトリスト
const ALLOWED_TABLES = [
    'owners',
    'stores',
    'sales_areas',
    'users',
    'user_stores',
    'password_reset_tokens',
    'keys',
    'reservations',
    'key_assignments',
    'cleaners',
    'cleaner_stores',
    'fixed_cleaners',
    'cleaning_jobs',
    'job_applications',
    'application_tokens',
    'extensions',
    'extension_requests',
    'cleaner_payments',
    'audit_logs',
    'notification_logs',
    'email_logs',
    'login_attempts',
    // LINE連携
    'line_accounts',
    'line_rich_menus',
    'webhook_events',
];

/**
 * テーブル名の検証
 */
function validateTableName(string $table): void
{
    if (!in_array($table, ALLOWED_TABLES, true)) {
        throw new InvalidArgumentException("Invalid table name: {$table}");
    }
}

/**
 * カラム名の検証（英数字とアンダースコアのみ許可）
 */
function validateColumnName(string $column): void
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
        throw new InvalidArgumentException("Invalid column name: {$column}");
    }
}

/**
 * WHERE句の安全性検証（基本的なSQLインジェクションパターンを検出）
 */
function validateWhereClause(string $where): void
{
    // セミコロン（複数ステートメント）を禁止
    if (strpos($where, ';') !== false) {
        throw new InvalidArgumentException('Invalid WHERE clause: semicolon not allowed');
    }

    // コメントを禁止
    if (preg_match('/(--|\/\*|\*\/)/', $where)) {
        throw new InvalidArgumentException('Invalid WHERE clause: comments not allowed');
    }

    // UNION を禁止
    if (preg_match('/\bUNION\b/i', $where)) {
        throw new InvalidArgumentException('Invalid WHERE clause: UNION not allowed');
    }

    // 明らかな常にTRUEになるパターンを検出
    if (preg_match('/\bOR\s+[\d\'"]+\s*=\s*[\d\'"]+/i', $where)) {
        throw new InvalidArgumentException('Invalid WHERE clause: suspicious pattern detected');
    }
}

/**
 * PDO接続を取得（シングルトン）
 */
function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

/**
 * SELECT クエリ実行（複数行）
 */
function dbSelect(string $sql, array $params = []): array
{
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * SELECT クエリ実行（1行）
 */
function dbSelectOne(string $sql, array $params = []): ?array
{
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * INSERT 実行
 */
function dbInsert(string $table, array $data): int
{
    validateTableName($table);

    foreach (array_keys($data) as $column) {
        validateColumnName($column);
    }

    // カラム名をバッククォートで囲む（予約語対策）
    $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));

    // テーブル名もバッククォートで囲む（keys等の予約語対策）
    $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

    $stmt = getDb()->prepare($sql);
    $stmt->execute(array_values($data));

    return (int) getDb()->lastInsertId();
}

/**
 * UPDATE 実行
 */
function dbUpdate(string $table, array $data, string $where, array $whereParams = []): int
{
    validateTableName($table);
    validateWhereClause($where);

    foreach (array_keys($data) as $column) {
        validateColumnName($column);
    }

    // カラム名をバッククォートで囲む（予約語対策）
    $set = implode(' = ?, ', array_map(fn($col) => "`{$col}`", array_keys($data))) . ' = ?';
    // テーブル名もバッククォートで囲む（keys等の予約語対策）
    $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";

    $stmt = getDb()->prepare($sql);
    $stmt->execute(array_merge(array_values($data), $whereParams));

    return $stmt->rowCount();
}

/**
 * DELETE 実行（論理削除）
 */
function dbSoftDelete(string $table, string $where, array $whereParams = []): int
{
    validateTableName($table);
    validateWhereClause($where);

    // テーブル名をバッククォートで囲む（keys等の予約語対策）
    $sql = "UPDATE `{$table}` SET `deleted_at` = NOW() WHERE {$where} AND `deleted_at` IS NULL";

    $stmt = getDb()->prepare($sql);
    $stmt->execute($whereParams);

    return $stmt->rowCount();
}

/**
 * トランザクション開始
 */
function dbBegin(): void
{
    getDb()->beginTransaction();
}

/**
 * トランザクションコミット
 */
function dbCommit(): void
{
    getDb()->commit();
}

/**
 * トランザクションロールバック
 */
function dbRollback(): void
{
    if (getDb()->inTransaction()) {
        getDb()->rollBack();
    }
}

/**
 * IN句用のプレースホルダと値を構築
 * @return array{placeholders: string, params: array}
 */
function buildInClause(array $values): array
{
    if (empty($values)) {
        return [
            'placeholders' => '?',
            'params' => [0],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($values), '?'));
    return [
        'placeholders' => $placeholders,
        'params' => array_values($values),
    ];
}

/**
 * 汎用SQL実行（UPDATE/DELETE等で影響行数を返す）
 * @return int 影響を受けた行数
 */
function dbExecute(string $sql, array $params = []): int
{
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * ページネーション付きSELECT
 * LIMIT/OFFSETをプレースホルダで安全に処理
 */
function dbSelectPaginated(string $sql, array $params, int $limit, int $offset): array
{
    $limit = max(1, min($limit, 100));
    $offset = max(0, $offset);

    $sql .= ' LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
