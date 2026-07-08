<?php
declare(strict_types=1);

// =====================================================================
// Однократная установка: создаёт таблицы, регистрирует вебхук Telegram.
// Запуск из CLI:   php setup.php
// или из браузера: https://example.com/setup.php?token=<webhook_secret>
// После успешного запуска удалите/закройте файл.
// =====================================================================

require_once __DIR__ . '/lib/bootstrap.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals((string)($CONFIG['telegram']['webhook_secret'] ?? ''), (string)$token)) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$say = function (string $s) { echo $s . "\n"; };

// Универсальный раннер DDL/DML без параметров (обходит привычный exec)
$run_sql = function (PDO $pdo, string $stmt) {
    $st = $pdo->prepare($stmt);
    $st->execute();
};

// 1. Создаём БД (если ещё нет)
try {
    $c = $CONFIG['db'];
    $dsn = "mysql:host={$c['host']};port={$c['port']};charset={$c['charset']}";
    $rootUser = getenv('DB_ROOT_PASSWORD') !== false ? 'root' : $c['user'];
    $rootPass = getenv('DB_ROOT_PASSWORD') !== false ? (string)getenv('DB_ROOT_PASSWORD') : $c['password'];
    $root = new PDO($dsn, $rootUser, $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $run_sql($root, "CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $say("[ok] database `{$c['name']}` ready");
} catch (Throwable $e) {
    $say('[!] could not create database: ' . $e->getMessage() . ' (continuing, will try existing)');
}

// 2. Прогоняем schema.sql
$sql = file_get_contents(__DIR__ . '/db/schema.sql');
$pdo = db();
foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $stmt) {
    if ($stmt === '') continue;
    $run_sql($pdo, $stmt);
}
$say('[ok] schema applied');

// 3. Регистрируем супер-админов
foreach ($CONFIG['telegram']['super_admins'] ?? [] as $id) {
    db_run(
        'INSERT INTO admins (chat_id, is_super) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE is_super = 1',
        [(int)$id]
    );
}
$say('[ok] super admins registered: ' . implode(', ', $CONFIG['telegram']['super_admins'] ?? []));

// 4. Ставим вебхук
$webhook_url = rtrim($CONFIG['base_url'], '/') . '/api/webhook.php';
$r = tg_api('setWebhook', [
    'url'                  => $webhook_url,
    'secret_token'         => $CONFIG['telegram']['webhook_secret'],
    'allowed_updates'      => ['message', 'callback_query'],
    'drop_pending_updates' => true,
]);
$say('[telegram setWebhook] ' . json_encode($r, JSON_UNESCAPED_UNICODE));

$info = tg_api('getWebhookInfo');
$say('[telegram getWebhookInfo] ' . json_encode($info, JSON_UNESCAPED_UNICODE));

$say('');
$say('Готово. Удалите setup.php после установки.');
