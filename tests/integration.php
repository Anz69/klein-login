<?php
/**
 * Integration test for klein-login. Run: php tests/integration.php
 * Requires PHP dev server: php -S 127.0.0.1:8765 -t .
 */
declare(strict_types=1);

$base = 'http://127.0.0.1:8765';
$linkId = 'testlink1';
$cookieFile = sys_get_temp_dir() . '/klein-login-test-cookies.txt';
@unlink($cookieFile);

require dirname(__DIR__) . '/lib/bootstrap.php';

function ok(bool $cond, string $msg): void
{
    echo ($cond ? '✅' : '❌') . ' ' . $msg . PHP_EOL;
    if (!$cond) {
        exit(1);
    }
}

function http(string $method, string $url, ?array $body = null, ?string $cookies = null): array
{
    $headers = [];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($cookies !== null && is_file($cookies)) {
        $headers[] = 'Cookie: ' . cookie_header_from_file($cookies);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '',
            'ignore_errors' => true,
        ],
    ]);

    $bodyRaw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
        $code = (int)$m[0];
    }

    foreach ($http_response_header ?? [] as $h) {
        if ($cookies !== null && stripos($h, 'Set-Cookie:') === 0) {
            save_cookie_from_header($cookies, $h);
        }
    }

    return ['code' => $code, 'body' => (string)$bodyRaw, 'headers' => implode("\n", $http_response_header ?? [])];
}

function cookie_header_from_file(string $file): string
{
    $parts = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, "\t")) continue;
        $cols = explode("\t", $line);
        if (count($cols) >= 7) {
            $parts[] = $cols[5] . '=' . $cols[6];
        }
    }
    return implode('; ', $parts);
}

function save_cookie_from_header(string $file, string $header): void
{
    if (!preg_match('/Set-Cookie:\s*([^=]+)=([^;]+)/i', $header, $m)) return;
    $name = trim($m[1]);
    $value = trim($m[2]);
    $expires = time() + 86400;
    $line = "#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t$expires\t$name\t$value\n";
    file_put_contents($file, $line, FILE_APPEND);
}

// Seed
db_run('DELETE FROM login_attempts WHERE link_id=?', [$linkId]);
db_run('DELETE FROM attempts WHERE link_id=?', [$linkId]);
db_run('DELETE FROM sessions WHERE link_id=?', [$linkId]);
db_run('DELETE FROM links WHERE id=?', [$linkId]);
db_run('INSERT INTO links (id, number_suffix, chat_id) VALUES (?,?,?)', [$linkId, '1234', 999999]);

$r = http('GET', "$base/");
ok($r['code'] === 404, '404 without id');

$r = http('GET', "$base/?id=bad");
ok($r['code'] === 404, '404 invalid link');

$r = http('GET', "$base/?id=$linkId", null, $cookieFile);
ok($r['code'] === 200, 'login page 200');
ok(str_contains($r['body'], 'login-card'), 'login page has login-card');
ok(str_contains($r['headers'], 'ka_sid'), 'login page sets cookie');

$r = http('POST', "$base/api/presence.php", [], $cookieFile);
ok($r['code'] === 200, 'presence 200');
$data = json_decode($r['body'], true);
ok(($data['ok'] ?? false) === true, 'presence ok');

$r = http('POST', "$base/api/login.php", [
    'id' => $linkId,
    'email' => 'user@test.com',
    'password' => 'secret123',
], $cookieFile);
ok($r['code'] === 200, 'login API 200');
ok(!str_contains($r['body'], '<br'), 'login API clean JSON');
$data = json_decode($r['body'], true);
ok(($data['status'] ?? '') === 'pending', 'login status pending');
$loginAttemptId = (int)($data['attempt_id'] ?? 0);
ok($loginAttemptId > 0, 'login attempt_id set');

$r = http('POST', "$base/api/verify.php", ['id' => $linkId, 'code' => '111111'], $cookieFile);
ok($r['code'] === 403, 'verify 403 before login approve');

db_run("UPDATE login_attempts SET status='approved', responded_at=NOW() WHERE id=?", [$loginAttemptId]);
$r = http('GET', "$base/api/login-status.php?attempt_id=$loginAttemptId");
$data = json_decode($r['body'], true);
ok(($data['status'] ?? '') === 'approved', 'login-status approved');

$session = db_one('SELECT * FROM sessions WHERE link_id=? ORDER BY created_at DESC LIMIT 1', [$linkId]);
ok($session['login_status'] === 'approved' && $session['stage'] === '2fa', 'session moved to 2fa');

$r = http('GET', "$base/?id=$linkId", null, $cookieFile);
ok(str_contains($r['body'], 'code-form'), '2FA page shown after approve');

$r = http('POST', "$base/api/verify.php", ['id' => $linkId, 'code' => '654321'], $cookieFile);
ok($r['code'] === 200, 'verify 2FA 200');
ok(!str_contains($r['body'], '<br'), 'verify API clean JSON');
$data = json_decode($r['body'], true);
$codeAttemptId = (int)($data['attempt_id'] ?? 0);
ok($codeAttemptId > 0, '2FA attempt_id set');

$att = db_one('SELECT session_id FROM attempts WHERE id=?', [$codeAttemptId]);
ok($att['session_id'] === $session['id'], '2FA attempt has session_id');

db_run("UPDATE attempts SET status='approved', responded_at=NOW() WHERE id=?", [$codeAttemptId]);
$r = http('GET', "$base/api/status.php?attempt_id=$codeAttemptId");
$data = json_decode($r['body'], true);
ok(($data['status'] ?? '') === 'approved', '2FA status approved');

@unlink($cookieFile);
http('GET', "$base/?id=$linkId", null, $cookieFile);
$r = http('POST', "$base/api/login.php", ['id' => $linkId, 'email' => 'x@y.com', 'password' => 'wrong'], $cookieFile);
$data = json_decode($r['body'], true);
$rejectId = (int)($data['attempt_id'] ?? 0);
db_run("UPDATE login_attempts SET status='rejected', responded_at=NOW() WHERE id=?", [$rejectId]);
$r = http('GET', "$base/api/login-status.php?attempt_id=$rejectId");
$data = json_decode($r['body'], true);
ok(($data['status'] ?? '') === 'rejected', 'login reject status');

db_run('UPDATE sessions SET last_seen=NOW() WHERE id=?', [$session['id']]);
ok(str_contains(presence_status_text($session['id']), 'Онлайн'), 'presence online text');

db_run('INSERT IGNORE INTO banned_ips (ip, reason) VALUES (?,?)', ['9.9.9.9', 'test']);
ok(is_ip_banned('9.9.9.9'), 'IP ban works');
db_run('DELETE FROM banned_ips WHERE ip=?', ['9.9.9.9']);

ok(function_exists('notify_login_attempt'), 'notify_login_attempt exists');

echo PHP_EOL . 'All tests passed.' . PHP_EOL;
