(() => {
  'use strict';
  const $ = (selector) => document.querySelector(selector);
  const loginPanel = $('#login-panel');
  const dashboard = $('#dashboard');
  const notice = $('#notice');
  const connection = $('#connection');
  let resumeChallenge = null;

  function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)__Host-kp_csrf=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function showNotice(message, isError = false) {
    notice.textContent = message;
    notice.classList.toggle('error', isError);
    notice.hidden = false;
  }

  function clearNotice() { notice.hidden = true; notice.textContent = ''; }

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    if (options.method && options.method !== 'GET') {
      headers.set('Content-Type', 'application/json');
      headers.set('X-CSRF-Token', csrfToken());
    } else if (path === '/api/auth/session' || path === '/api/control/status' || path === '/api/vault/credentials') {
      headers.set('X-CSRF-Token', csrfToken());
    }
    const response = await fetch(path, {
      ...options, headers, credentials: 'same-origin', cache: 'no-store',
      signal: AbortSignal.timeout(10000),
    });
    let body = {};
    if (response.status !== 204) {
      try { body = await response.json(); } catch { body = {}; }
    }
    if (!response.ok) {
      const message = body.error === 'authentication_required' || body.authenticated === false
        ? 'ਮਾਲਕ ਦਾ ਸੈਸ਼ਨ ਮੁੱਕ ਗਿਆ। ਮੁੜ ਸਾਈਨ ਇਨ ਕਰੋ।'
        : `ਬੇਨਤੀ ਪੂਰੀ ਨਹੀਂ ਹੋਈ (HTTP ${response.status})।`;
      const error = new Error(message);
      error.status = response.status;
      throw error;
    }
    return body;
  }

  function money(cents) {
    const value = Number(cents);
    if (!Number.isSafeInteger(value)) return 'ਉਪਲਬਧ ਨਹੀਂ';
    return new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD' }).format(value / 100);
  }

  function renderSummary(control) {
    $('#system-state').textContent = control.emergency_stopped ? 'ਰੋਕਿਆ ਹੋਇਆ' : 'ਚੱਲ ਰਿਹਾ ਹੈ';
    $('#system-state').classList.toggle('stopped-text', control.emergency_stopped);
    $('#mode-line').textContent = `${String(control.operating_mode).toUpperCase()} mode · dispatch epoch ${control.dispatch_epoch}`;
    $('#cash').textContent = money(control.settled_cash_cents);
    $('#reserves').textContent = `Customer ${money(control.customer_liabilities_cents)} · tax ${money(control.tax_reserve_cents)} · refund ${money(control.refund_reserve_cents)}`;
    $('#reserved').textContent = money(control.discretionary_reserved_cents);
    $('#open-work').textContent = `${control.open_reservation_count} reservations · ${control.job_queue_actions} jobs`;
    connection.textContent = control.emergency_stopped ? 'STOP ਲਾਗੂ ਹੈ' : 'ਜੁੜਿਆ ਹੋਇਆ';
    connection.classList.toggle('stopped', control.emergency_stopped);
    connection.classList.toggle('good', !control.emergency_stopped);
    $('#stop-button').disabled = Boolean(control.emergency_stopped);
    $('#resume-panel').hidden = !control.emergency_stopped || control.operating_mode === 'shutdown';
  }

  function renderCredentials(credentials) {
    const target = $('#credentials');
    target.replaceChildren();
    if (!credentials.length) {
      const empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'ਕੋਈ credential ਸੰਭਾਲਿਆ ਨਹੀਂ।';
      target.append(empty);
      return;
    }
    for (const credential of credentials) {
      const row = document.createElement('div'); row.className = 'credential-row';
      const identity = document.createElement('div');
      const name = document.createElement('strong'); name.textContent = `${credential.provider} · ${credential.label}`;
      const key = document.createElement('small'); key.className = 'key-mask'; key.textContent = credential.masked_key;
      identity.append(name, key);
      const test = document.createElement('span'); test.className = 'tag'; test.textContent = credential.test_status;
      const state = document.createElement('span'); state.className = 'tag'; state.textContent = credential.status;
      row.append(identity, test, state); target.append(row);
    }
  }

  async function refresh() {
    clearNotice();
    let session;
    try {
      session = await api('/api/auth/session');
    } catch (error) {
      if (error instanceof TypeError || error.name === 'TimeoutError') {
        showNotice('ਮਾਲਕ API ਨਾਲ ਸੰਪਰਕ ਨਹੀਂ ਹੋਇਆ। ਸੁਰੱਖਿਅਤ ਕਨੈਕਸ਼ਨ ਜਾਂਚ ਕੇ ਮੁੜ ਕੋਸ਼ਿਸ਼ ਕਰੋ।', true);
        connection.textContent = 'ਕਨੈਕਸ਼ਨ ਉਪਲਬਧ ਨਹੀਂ';
        return;
      }
      if (error.status === 401) {
        dashboard.hidden = true; loginPanel.hidden = false; connection.textContent = 'ਸਾਈਨ ਇਨ ਲੋੜੀਂਦਾ ਹੈ';
      } else {
        showNotice('ਮਾਲਕ ਦਾ ਸੈਸ਼ਨ ਨਹੀਂ ਮਿਲਿਆ। ਸੇਵਾ ਸ਼ਾਇਦ ਉਪਲਬਧ ਨਹੀਂ।', true);
        connection.textContent = 'ਮਾਲਕ API ਉਪਲਬਧ ਨਹੀਂ';
      }
      return;
    }
    dashboard.hidden = false; loginPanel.hidden = true; $('#owner-email').textContent = session.email;
    let status;
    try { status = await api('/api/control/status'); renderSummary(status.control); }
    catch (error) {
      if (error.status === 401) { dashboard.hidden = true; loginPanel.hidden = false; connection.textContent = 'ਸਾਈਨ ਇਨ ਲੋੜੀਂਦਾ ਹੈ'; }
      else { showNotice('ਸਿਸਟਮ ਦੀ ਹਾਲਤ ਨਹੀਂ ਮਿਲੀ। ਕੋਈ ਕੰਟਰੋਲ ਕਾਰਵਾਈ ਨਹੀਂ ਹੋਈ।', true); connection.textContent = 'ਹਾਲਤ ਉਪਲਬਧ ਨਹੀਂ'; }
      return;
    }
    try { const vault = await api('/api/vault/credentials'); renderCredentials(vault.credentials); }
    catch { $('#credentials').textContent = 'ਕੁੰਜੀਆਂ ਦੀ ਸੇਵਾ ਉਪਲਬਧ ਨਹੀਂ।'; }
  }

  $('#login-form').addEventListener('submit', async (event) => {
    event.preventDefault(); clearNotice();
    const form = new FormData(event.currentTarget);
    const submit = event.currentTarget.querySelector('button'); submit.disabled = true;
    try {
      await api('/api/auth/login', { method: 'POST', body: JSON.stringify({ email: form.get('email'), password: form.get('password'), totp: form.get('totp') }) });
      event.currentTarget.reset();
      await refresh();
    } catch (error) { showNotice(error.message, true); }
    finally { submit.disabled = false; }
  });

  $('#refresh-button').addEventListener('click', refresh);

  $('#stop-button').addEventListener('click', async () => {
    if (!window.confirm('ਐਮਰਜੈਂਸੀ STOP ਨਵਾਂ ਗੈਰ-ਜ਼ਰੂਰੀ ਕੰਮ ਰੋਕੇਗਾ। ਜਾਰੀ ਰੱਖੀਏ?')) return;
    clearNotice();
    try { await api('/api/control/stop', { method: 'POST', body: '{}' }); showNotice('ਐਮਰਜੈਂਸੀ STOP ਲਾਗੂ ਹੈ।'); await refresh(); }
    catch (error) { showNotice(error.message, true); }
  });

  $('#step-up-form').addEventListener('submit', async (event) => {
    event.preventDefault(); clearNotice();
    const form = new FormData(event.currentTarget); const submit = event.currentTarget.querySelector('button'); submit.disabled = true;
    try {
      const result = await api('/api/control/resume/challenge', { method: 'POST', body: JSON.stringify({ password: form.get('password'), totp: form.get('totp') }) });
      event.currentTarget.reset(); resumeChallenge = result.challenge;
      const summary = result.control;
      const items = [
        ['ਮੋਡ', summary.operating_mode], ['ਪੱਕੀ ਪ੍ਰਾਪਤ ਰਕਮ', money(summary.settled_cash_cents)],
        ['ਗਾਹਕ ਦੀ ਦੇਣਦਾਰੀ', money(summary.customer_liabilities_cents)], ['ਟੈਕਸ ਰਾਖਵਾਂ', money(summary.tax_reserve_cents)],
        ['ਰਿਫੰਡ ਰਾਖਵਾਂ', money(summary.refund_reserve_cents)], ['ਖਰਚ ਲਈ ਰਾਖਵਾਂ', money(summary.discretionary_reserved_cents)],
        ['ਖੁੱਲ੍ਹੀਆਂ ਰਾਖਵਾਂ', summary.open_reservation_count], ['ਖੁੱਲ੍ਹੇ ਜਾਂ ਅਣਪੱਕੇ ਕੰਮ', summary.job_queue_actions],
        ['ਡਿਸਪੈਚ epoch', summary.dispatch_epoch],
      ];
      const target = $('#review-summary'); target.replaceChildren();
      for (const [label, value] of items) {
        const item = document.createElement('div'); item.className = 'review-item';
        const caption = document.createElement('span'); caption.textContent = label;
        const amount = document.createElement('strong'); amount.textContent = String(value);
        item.append(caption, amount); target.append(item);
      }
      $('#confirmation-phrase').textContent = result.confirmation_phrase;
      $('#resume-review').hidden = false;
    } catch (error) { showNotice(error.message, true); }
    finally { submit.disabled = false; }
  });

  $('#confirm-form').addEventListener('submit', async (event) => {
    event.preventDefault(); clearNotice();
    const confirmation = new FormData(event.currentTarget).get('confirmation');
    const submit = event.currentTarget.querySelector('button'); submit.disabled = true;
    try {
      await api('/api/control/resume/confirm', { method: 'POST', body: JSON.stringify({ challenge: resumeChallenge, confirmation }) });
      resumeChallenge = null; event.currentTarget.reset(); $('#resume-review').hidden = true;
      showNotice('ਮੁੜ ਚਲਾਉਣ ਦੀ ਪੁਸ਼ਟੀ ਹੋ ਗਈ। ਸਿਸਟਮ ਦੀ ਹਾਲਤ ਮੁੜ ਵੇਖ ਰਹੇ ਹਾਂ।'); await refresh();
    } catch (error) { resumeChallenge = null; $('#resume-review').hidden = true; showNotice(error.message, true); }
    finally { submit.disabled = false; }
  });

  $('#logout-button').addEventListener('click', async () => {
    clearNotice();
    try { await api('/api/auth/logout', { method: 'POST', body: '{}' }); }
    catch { /* Clear the local view even if the server session already expired. */ }
    dashboard.hidden = true; loginPanel.hidden = false; connection.textContent = 'ਸਾਈਨ ਆਉਟ ਹੋ ਗਿਆ';
  });

  refresh();
})();
