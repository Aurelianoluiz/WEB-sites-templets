(() => {
  'use strict';

  const root = document.documentElement;
  const toast = document.querySelector('#toast');
  const polling = new Map();

  const notify = (message) => {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    window.clearTimeout(notify.timer);
    notify.timer = window.setTimeout(() => toast.classList.remove('show'), 2400);
  };

  document.querySelectorAll('[data-copy-pix]').forEach((button) => {
    button.addEventListener('click', async () => {
      const selector = button.getAttribute('data-copy-pix');
      const input = selector ? document.querySelector(selector) : null;
      if (!input || !input.value) return;
      try {
        await navigator.clipboard.writeText(input.value);
        notify('Código PIX copiado.');
      } catch (error) {
        input.focus();
        input.select();
        document.execCommand('copy');
        notify('Código PIX copiado.');
      }
    });
  });

  const statusLabel = (status) => ({
    pending: 'Aguardando pagamento',
    authorized: 'Pagamento autorizado',
    paid: 'Pagamento confirmado',
    failed: 'Pagamento recusado',
    cancelled: 'Pagamento cancelado',
    refunded: 'Pagamento estornado'
  })[status] || 'Status do pagamento';

  const renderStatus = (container, payload) => {
    const status = String(payload.status || 'pending');
    container.dataset.status = status;
    const text = container.querySelector('[data-payment-status-text]');
    if (text) text.textContent = statusLabel(status);
    if (status === 'paid') {
      notify('Pagamento confirmado.');
      const redirect = container.dataset.redirect;
      if (redirect) window.setTimeout(() => { window.location.href = redirect; }, 900);
    } else if (status === 'failed' || status === 'cancelled') {
      notify(statusLabel(status));
    }
  };

  const startPolling = (container) => {
    const orderId = Number(container.dataset.orderId || 0);
    const interval = Number(container.dataset.pollInterval || 4000);
    if (!orderId || polling.has(orderId)) return;

    const tick = async () => {
      try {
        const response = await fetch(`api/order_status.php?order_id=${encodeURIComponent(orderId)}`, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          cache: 'no-store'
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const payload = await response.json();
        renderStatus(container, payload);
        if (!['paid', 'failed', 'cancelled', 'refunded'].includes(String(payload.status))) {
          polling.set(orderId, window.setTimeout(tick, interval));
        } else {
          polling.delete(orderId);
        }
      } catch (error) {
        polling.set(orderId, window.setTimeout(tick, interval));
      }
    };
    tick();
  };

  document.querySelectorAll('[data-payment-poll]').forEach(startPolling);

  document.querySelectorAll('[data-theme]').forEach((button) => {
    button.addEventListener('click', () => {
      const theme = button.getAttribute('data-theme');
      if (theme === 'light' || theme === 'dark') {
        root.dataset.theme = theme;
        localStorage.setItem('cm-theme', theme);
      } else {
        delete root.dataset.theme;
        localStorage.removeItem('cm-theme');
      }
    });
  });

  const storedTheme = localStorage.getItem('cm-theme');
  if (storedTheme === 'light' || storedTheme === 'dark') root.dataset.theme = storedTheme;
})();
