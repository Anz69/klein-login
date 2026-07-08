<?php
declare(strict_types=1);

function cfg_all(): array
{
    global $CONFIG;
    $defaults = $CONFIG['defaults'];
    $rows = db_all('SELECT `key`, `value` FROM config');
    $result = $defaults;
    foreach ($rows as $r) {
        $result[$r['key']] = $r['value'];
    }
    return $result;
}

function cfg_get(string $key, ?string $default = null): ?string
{
    global $CONFIG;
    $row = db_one('SELECT `value` FROM config WHERE `key` = ?', [$key]);
    if ($row !== null) return $row['value'];
    return $CONFIG['defaults'][$key] ?? $default;
}

function cfg_set(string $key, string $value): void
{
    db_run(
        'INSERT INTO config (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        [$key, $value]
    );
}

function cfg_reset(string $key): void
{
    db_run('DELETE FROM config WHERE `key` = ?', [$key]);
}

function cfg_editable_keys(): array
{
    global $CONFIG;
    return array_keys($CONFIG['defaults']);
}
