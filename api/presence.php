<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!touch_session_presence()) {
    http_response_code(400);
    echo json_encode(['error' => 'no session']);
    exit;
}

echo json_encode(['ok' => true]);
