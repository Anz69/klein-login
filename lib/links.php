<?php
declare(strict_types=1);

function link_public_url(array|string $linkOrSuffix): string
{
    global $CONFIG;
    $base = rtrim($CONFIG['base_url'], '/');

    if (is_array($linkOrSuffix)) {
        if (!empty($linkOrSuffix['number_suffix'])) {
            return $base . '/' . rawurlencode((string)$linkOrSuffix['number_suffix']);
        }
        if (!empty($linkOrSuffix['id'])) {
            $row = db_one('SELECT number_suffix FROM links WHERE id = ?', [$linkOrSuffix['id']]);
            if ($row && $row['number_suffix'] !== '') {
                return $base . '/' . rawurlencode((string)$row['number_suffix']);
            }
        }
        return $base;
    }

    return $base . '/' . rawurlencode((string)$linkOrSuffix);
}

function resolve_active_link(bool $is_banned): ?array
{
    if ($is_banned) {
        return null;
    }

    $link_id = trim((string)($_GET['id'] ?? ''));
    $path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

    if ($link_id !== '' && preg_match('/^[a-zA-Z0-9]{4,32}$/', $link_id)) {
        $link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$link_id]);
        if ($link !== null) {
            return $link;
        }
    }

    if ($path === '' || preg_match('#^(api|assets)(/|$)#', $path)) {
        return null;
    }

    if (!preg_match('/^[a-zA-Z0-9]{4,32}$/', $path)) {
        return null;
    }

    $link = db_one('SELECT * FROM links WHERE number_suffix = ? AND is_active = 1', [$path]);
    if ($link !== null) {
        return $link;
    }

    return db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$path]);
}
