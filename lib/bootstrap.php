<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$CONFIG = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/session.php';
