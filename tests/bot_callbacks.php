<?php
/**
 * Bot callback unit tests (no Telegram). Run: php tests/bot_callbacks.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bot.php';

$linkId = 'testlink1';
$chatId = 999999;

function ok(bool $c, string $m): void { echo ($c?'✅':'❌')." $m\n"; if(!$c) exit(1); }

db_run('INSERT INTO admins (chat_id, is_super) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_super = 1', [$chatId]);

// Fresh session + login attempt
db_run('DELETE FROM login_attempts WHERE link_id=?', [$linkId]);
db_run('DELETE FROM sessions WHERE link_id=?', [$linkId]);
$sid = gen_session_id();
db_run(
    'INSERT INTO sessions (id, link_id, stage, login_status, ip) VALUES (?,?,?,?,?)',
    [$sid, $linkId, 'login', 'pending', '1.2.3.4']
);
db_run(
    'INSERT INTO login_attempts (link_id, session_id, email, password, ip, status) VALUES (?,?,?,?,?,"pending")',
    [$linkId, $sid, 'bot@test.com', 'pass123', '1.2.3.4']
);
$loginId = (int)db()->lastInsertId();

// log:approve
bot_handle_callback([
    'id' => 'cb1',
    'from' => ['id' => $chatId],
    'message' => ['chat' => ['id' => $chatId], 'message_id' => 1],
    'data' => "log:approve:$loginId",
]);
$att = db_one('SELECT status FROM login_attempts WHERE id=?', [$loginId]);
$sess = db_one('SELECT login_status, stage FROM sessions WHERE id=?', [$sid]);
ok($att['status'] === 'approved', 'log:approve sets attempt approved');
ok($sess['login_status'] === 'approved' && $sess['stage'] === '2fa', 'log:approve moves session to 2fa');

// log:reject on new attempt
db_run(
    'INSERT INTO login_attempts (link_id, session_id, email, password, status) VALUES (?,?,?,?,"pending")',
    [$linkId, $sid, 'x@y.com', 'wrong']
);
$rejectId = (int)db()->lastInsertId();
bot_handle_callback([
    'id' => 'cb2',
    'from' => ['id' => $chatId],
    'message' => ['chat' => ['id' => $chatId], 'message_id' => 2],
    'data' => "log:reject:$rejectId",
]);
$att = db_one('SELECT status FROM login_attempts WHERE id=?', [$rejectId]);
ok($att['status'] === 'rejected', 'log:reject sets rejected');
// session stays approved from prior approve
$sess = db_one('SELECT login_status FROM sessions WHERE id=?', [$sid]);
ok($sess['login_status'] === 'approved', 'log:reject does not downgrade approved session');

// pres:online
db_run('UPDATE sessions SET last_seen=NOW() WHERE id=?', [$sid]);
ok(str_contains(presence_status_text($sid), 'Онлайн'), 'pres:online sees online');

db_run('UPDATE sessions SET last_seen=DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE id=?', [$sid]);
ok(str_contains(presence_status_text($sid), 'Оффлайн'), 'pres:online sees offline');

// att:approve for 2FA
db_run(
    'INSERT INTO attempts (link_id, session_id, code, ip, status) VALUES (?,?,?,?,"pending")',
    [$linkId, $sid, '123456', '1.2.3.4']
);
$codeId = (int)db()->lastInsertId();
bot_handle_callback([
    'id' => 'cb3',
    'from' => ['id' => $chatId],
    'message' => ['chat' => ['id' => $chatId], 'message_id' => 3],
    'data' => "att:approve:$codeId",
]);
$att = db_one('SELECT status FROM attempts WHERE id=?', [$codeId]);
ok($att['status'] === 'approved', 'att:approve sets 2FA approved');

echo "\nAll bot callback tests passed.\n";
