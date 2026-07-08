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

$att = db_one('SELECT * FROM login_attempts WHERE id = ?', [$attempt_id]);
if (!$att) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

if ($att['status'] === 'pending') {
    $timeout = (int)cfg_get('auto_reject_seconds');
    $age = time() - strtotime((string)$att['created_at']);
    if ($timeout > 0 && $age >= $timeout) {
        db_run(
            "UPDATE login_attempts SET status = 'rejected', responded_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$attempt_id]
        );
        db_run(
            "UPDATE sessions SET login_status = 'rejected' WHERE id = ?",
            [$att['session_id']]
        );
        $att['status'] = 'rejected';
    }
}

if ($att['status'] === 'approved') {
    db_run(
        "UPDATE sessions SET login_status = 'approved', stage = '2fa', email = ? WHERE id = ?",
        [$att['email'], $att['session_id']]
    );
}

if ($att['status'] === 'rejected') {
    db_run(
        "UPDATE sessions SET login_status = 'rejected' WHERE id = ? AND login_status != 'approved'",
        [$att['session_id']]
    );
}

echo json_encode(['status' => $att['status']]);
