<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bot.php';

global $CONFIG;
$expected = $CONFIG['telegram']['webhook_secret'] ?? '';
$got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expected === '' || !hash_equals($expected, (string)$got)) {
    http_response_code(403);
    exit('forbidden');
}

$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);
if (!is_array($update)) {
    http_response_code(400);
    exit('bad request');
}

try {
    bot_handle_update($update);
} catch (Throwable $e) {
    error_log('webhook error: ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';
