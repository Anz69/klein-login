<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
if ($attempt_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'missing attempt_id']);
    exit;
}

$att = db_one('SELECT id, status, created_at FROM attempts WHERE id = ?', [$attempt_id]);
if (!$att) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

// Авто-отказ по таймауту
if ($att['status'] === 'pending') {
    $timeout = (int)cfg_get('auto_reject_seconds');
    $age = time() - strtotime((string)$att['created_at']);
    if ($timeout > 0 && $age >= $timeout) {
        db_run("UPDATE attempts SET status = 'rejected', responded_at = NOW()
                WHERE id = ? AND status = 'pending'", [$attempt_id]);
        $att['status'] = 'rejected';
    }
}

echo json_encode(['status' => $att['status']]);
