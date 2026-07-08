<?php
declare(strict_types=1);

/**
 * Создаёт БД и пользователя по .env через root (работает и со старым volume).
 * CLI: php scripts/ensure-db.php
 */

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '3306';
$rootPass = getenv('DB_ROOT_PASSWORD');
$dbName = getenv('DB_NAME') ?: 'kleinanzeigen_login';
$dbUser = getenv('DB_USER') ?: 'klein';
$dbPass = getenv('DB_PASSWORD');

if ($rootPass === false || $rootPass === '' || $dbPass === false || $dbPass === '') {
    fwrite(STDERR, "[ensure-db] DB_ROOT_PASSWORD и DB_PASSWORD обязательны\n");
    exit(1);
}

$ident = static function (string $v): string {
    return '`' . str_replace('`', '``', $v) . '`';
};

$quote = static function (string $v): string {
    return "'" . str_replace("'", "''", $v) . "'";
};

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        'root',
        (string)$rootPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $ident($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    foreach (['%', 'localhost'] as $hostPart) {
        $user = $quote($dbUser) . '@' . $quote($hostPart);
        $pass = $quote((string)$dbPass);
        $pdo->exec("CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$pass}");
        $pdo->exec("ALTER USER {$user} IDENTIFIED BY {$pass}");
        $pdo->exec('GRANT ALL PRIVILEGES ON ' . $ident($dbName) . '.* TO ' . $user);
    }

    $pdo->exec('FLUSH PRIVILEGES');

    new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        (string)$dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "[ok] database `{$dbName}` and user `{$dbUser}` ready\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[ensure-db] ' . $e->getMessage() . "\n");
    exit(1);
}
