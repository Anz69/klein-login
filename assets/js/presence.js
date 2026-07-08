(() => {
  const cfg = window.__CFG__ || {};
  if (cfg.not_found) return;

  const interval = Math.max(5000, cfg.presence_interval_ms || 12000);

  function ping() {
    fetch('/api/presence.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    }).catch(() => {});
  }

  ping();
  setInterval(ping, interval);
})();
