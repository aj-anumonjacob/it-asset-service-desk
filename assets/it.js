/* IT Portal — shared behaviour. window.IT is set by layout_footer.php. */
(function () {
  'use strict';
  const IT = window.IT || {};
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- server calls ---------- */
  IT.post = async function (action, data = {}) {
    const body = new FormData();
    body.append('do', action);
    body.append('_csrf', IT.csrf);
    Object.entries(data).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x));
      else body.append(k, v);
    });
    let res;
    try {
      res = await fetch('action.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    } catch (e) {
      throw new Error('No connection. Check the internet and try again.');
    }
    const json = await res.json().catch(() => ({ ok: false, error: 'Unexpected reply from the server.' }));
    if (json.login) { location.href = 'login.php?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search); }
    return json;
  };

  /* ---------- toasts ---------- */
  IT.toast = function (msg, type = 'success', opts = {}) {
    const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', warn: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = `<i class="bi ${icons[type] || icons.info}"></i><span>${esc(msg)}</span>`;
    if (opts.action) {
      const b = document.createElement('button');
      b.textContent = opts.action.label;
      b.onclick = () => { opts.action.run(); close(); };
      t.appendChild(b);
    }
    $('#toasts').appendChild(t);
    const close = () => { t.classList.add('out'); setTimeout(() => t.remove(), 300); };
    setTimeout(close, opts.ms || (opts.action ? 7000 : 3600));
  };
  if (IT.flash) IT.toast(IT.flash.msg, IT.flash.type || 'success');

  /* ---------- overlays ---------- */
  function openOverlay(el) { el.hidden = false; document.body.style.overflow = 'hidden'; }
  function closeOverlay(el) { el.hidden = true; document.body.style.overflow = ''; }
  $$('.overlay, .sheet').forEach((o) => {
    o.addEventListener('click', (e) => { if (e.target === o || e.target.closest('[data-close]')) closeOverlay(o); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') $$('.overlay:not([hidden]), .sheet:not([hidden])').forEach(closeOverlay);
  });
  IT.openOverlay = openOverlay;
  IT.closeOverlay = closeOverlay;

  $$('[data-open-sheet]').forEach((b) => b.addEventListener('click', () => openOverlay($('#moreSheet'))));

  /* ---------- confirm ---------- */
  IT.confirm = function ({ title = 'Are you sure?', text = '', ok = 'Yes, continue', danger = true, typeWord = '' } = {}) {
    return new Promise((resolve) => {
      const o = $('#confirmOverlay');
      $('#confirmTitle').textContent = title;
      $('#confirmText').textContent = text;
      const btn = $('#confirmOk');
      btn.textContent = ok;
      btn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
      const typed = $('#confirmType');
      typed.hidden = !typeWord;
      typed.value = '';
      typed.placeholder = typeWord ? `Type ${typeWord} to confirm` : '';
      btn.disabled = !!typeWord;
      typed.oninput = () => { btn.disabled = typed.value.trim().toUpperCase() !== typeWord.toUpperCase(); };
      let done = false;
      const finish = (v) => { if (done) return; done = true; closeOverlay(o); resolve(v); };
      btn.onclick = () => finish(true);
      o.onclick = (e) => { if (e.target === o || e.target.closest('[data-close]')) finish(false); };
      openOverlay(o);
      (typeWord ? typed : btn).focus();
    });
  };

  /* ---------- vault PIN ---------- */
  let unlockTimer = null;
  function setVaultState(open, left) {
    IT.unlocked = open;
    const pill = $('#vaultPill');
    if (pill) {
      pill.classList.toggle('is-open', open);
      pill.querySelector('i').className = 'bi ' + (open ? 'bi-unlock' : 'bi-lock');
      pill.querySelector('span').textContent = open ? 'Vault open' : 'Vault locked';
    }
    clearTimeout(unlockTimer);
    if (open && left > 0) unlockTimer = setTimeout(() => { setVaultState(false); hideAllSecrets(); IT.toast('Vault locked again', 'info'); }, left * 1000);
  }
  setVaultState(!!IT.unlocked, IT.unlockLeft || 0);

  function askPin() {
    return new Promise((resolve) => {
      const o = $('#pinOverlay');
      const setMode = !IT.hasPin;
      const input = $('#pinInput');
      const confirm = $('#pinConfirm');
      const dots = $$('#pinDots span');
      $('#pinTitle').textContent = setMode ? 'Create your vault PIN' : 'Enter vault PIN';
      $('#pinHint').textContent = setMode ? 'Choose 4–6 digits. You will type it each time you open the vault.' : 'The vault opens for a few minutes, then locks by itself.';
      $('#pinSubmit').textContent = setMode ? 'Save PIN & unlock' : 'Unlock';
      confirm.hidden = !setMode;
      input.value = ''; confirm.value = ''; $('#pinError').textContent = '';
      const paint = () => dots.forEach((d, i) => d.classList.toggle('on', i < input.value.length));
      input.oninput = () => { input.value = input.value.replace(/\D/g, ''); paint(); };
      confirm.oninput = () => { confirm.value = confirm.value.replace(/\D/g, ''); };
      paint();
      let settled = false;
      const end = (v) => { if (settled) return; settled = true; closeOverlay(o); resolve(v); };
      o.onclick = (e) => { if (e.target === o || e.target.closest('[data-close]')) end(false); };
      $('#pinForm').onsubmit = async (e) => {
        e.preventDefault();
        const pin = input.value;
        if (pin.length < 4) { $('#pinError').textContent = 'PIN must be 4 to 6 digits.'; return; }
        if (setMode && pin !== confirm.value) { $('#pinError').textContent = 'The two PINs do not match.'; return; }
        $('#pinSubmit').disabled = true;
        const r = await IT.post(setMode ? 'set_pin' : 'unlock', { pin, pin_confirm: confirm.value }).catch((err) => ({ ok: false, error: err.message }));
        $('#pinSubmit').disabled = false;
        if (r.ok) {
          IT.hasPin = true;
          setVaultState(true, r.left);
          end(true);
          IT.toast(setMode ? 'PIN saved. Vault open.' : 'Vault open', 'success');
        } else {
          $('#pinError').textContent = r.error || 'Wrong PIN.';
          $('#pinDots').classList.remove('shake'); void $('#pinDots').offsetWidth; $('#pinDots').classList.add('shake');
          input.value = ''; paint(); input.focus();
        }
      };
      openOverlay(o);
      setTimeout(() => input.focus(), 50);
    });
  }
  IT.withVault = async function (fn) {
    if (!IT.canVault) { IT.toast('You do not have vault access.', 'error'); return; }
    if (!IT.unlocked && !(await askPin())) return;
    const r = await fn();
    if (r && r.need_pin) { setVaultState(false); if (await askPin()) return fn(); }
    return r;
  };
  const pill = $('#vaultPill');
  if (pill) pill.addEventListener('click', async () => {
    if (IT.unlocked) {
      await IT.post('lock');
      setVaultState(false); hideAllSecrets();
      IT.toast('Vault locked', 'info');
    } else {
      await askPin();
    }
  });

  /* ---------- reveal / copy secrets ---------- */
  const shown = new Map();
  function hideSecret(box) {
    const v = box.querySelector('.val');
    if (!v) return;
    v.textContent = '••••••••••';
    v.classList.remove('shown');
    const t = box.querySelector('.timer-ring'); if (t) t.textContent = '';
    const eye = box.querySelector('[data-reveal] i'); if (eye) eye.className = 'bi bi-eye';
    clearInterval(shown.get(box)); shown.delete(box);
  }
  function hideAllSecrets() { $$('[data-secret-box]').forEach(hideSecret); }
  async function fetchSecret(id, hist, purpose) {
    return IT.withVault(() => IT.post('reveal', { id, history_id: hist || '', purpose }));
  }
  document.addEventListener('click', async (e) => {
    const rv = e.target.closest('[data-reveal]');
    const cp = e.target.closest('[data-copy]');
    if (!rv && !cp) return;
    e.preventDefault(); e.stopPropagation();
    const btn = rv || cp;
    const box = btn.closest('[data-secret-box]');
    const id = box.dataset.id, hist = box.dataset.history || '';
    if (rv && shown.has(box)) { hideSecret(box); return; }
    const r = await fetchSecret(id, hist, rv ? 'view' : 'copy');
    if (!r) return;
    if (!r.ok) { IT.toast(r.error || 'Could not open this password.', 'error'); return; }
    if (rv) {
      const v = box.querySelector('.val');
      v.textContent = r.secret; v.classList.add('shown');
      btn.querySelector('i').className = 'bi bi-eye-slash';
      let left = IT.revealSeconds;
      const t = box.querySelector('.timer-ring');
      if (t) t.textContent = left;
      shown.set(box, setInterval(() => { left--; if (t) t.textContent = left; if (left <= 0) hideSecret(box); }, 1000));
    } else {
      try {
        await navigator.clipboard.writeText(r.secret);
        IT.toast(`Copied. The clipboard clears in ${IT.revealSeconds} seconds.`, 'success');
        setTimeout(() => navigator.clipboard.writeText('').catch(() => {}), IT.revealSeconds * 1000);
      } catch (err) {
        // Clipboard needs https; show it instead so the user can copy by hand.
        const v = box.querySelector('.val');
        v.textContent = r.secret; v.classList.add('shown');
        IT.toast('Copy is blocked by the browser here — the password is shown instead.', 'warn');
        setTimeout(() => hideSecret(box), IT.revealSeconds * 1000);
      }
    }
  });

  /* ---------- delete / restore with undo ---------- */
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-delete]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const name = b.dataset.name || 'this item';
    const ok = await IT.confirm({ title: 'Move to Recycle Bin?', text: `“${name}” can be restored from the Recycle Bin at any time.`, ok: 'Delete' });
    if (!ok) return;
    const r = await IT.post('delete', { id: b.dataset.delete });
    if (!r.ok) { IT.toast(r.error, 'error'); return; }
    const after = b.dataset.after;
    if (after) {
      sessionStorage.setItem('it_undo', JSON.stringify({ id: b.dataset.delete, name }));
      location.href = after;
    } else {
      const card = b.closest('[data-row]');
      if (card) card.remove();
      undoToast(b.dataset.delete, name);
    }
  });
  function undoToast(id, name) {
    IT.toast(`“${name}” deleted`, 'info', { action: { label: 'Undo', run: async () => {
      const r = await IT.post('restore', { id });
      if (r.ok) { IT.toast('Restored', 'success'); setTimeout(() => location.reload(), 500); } else IT.toast(r.error, 'error');
    } } });
  }
  try {
    const u = JSON.parse(sessionStorage.getItem('it_undo') || 'null');
    if (u) { sessionStorage.removeItem('it_undo'); undoToast(u.id, u.name); }
  } catch (e) {}

  /* ---------- clickable rows / cards ---------- */
  document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-href]');
    if (!row || e.target.closest('a, button, input, label, select, textarea')) return;
    location.href = row.dataset.href;
  });

  /* ---------- ripple ---------- */
  if (document.body.classList.contains('anim')) {
    document.addEventListener('pointerdown', (e) => {
      const b = e.target.closest('.btn');
      if (!b) return;
      const r = b.getBoundingClientRect(), s = Math.max(r.width, r.height);
      const sp = document.createElement('span');
      sp.className = 'ripple';
      sp.style.cssText = `width:${s}px;height:${s}px;left:${e.clientX - r.left - s / 2}px;top:${e.clientY - r.top - s / 2}px`;
      b.appendChild(sp);
      setTimeout(() => sp.remove(), 600);
    });
  }

  /* ---------- count-up ---------- */
  $$('[data-count]').forEach((el) => {
    const end = parseInt(el.dataset.count, 10) || 0;
    if (!document.body.classList.contains('anim') || end === 0) { el.textContent = end.toLocaleString(); return; }
    const t0 = performance.now(), dur = 900;
    const step = (t) => {
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });

  /* ---------- global search ---------- */
  const so = $('#searchOverlay'), si = $('#searchInput'), sr = $('#searchResults');
  let sTimer = null, sel = -1;
  function openSearch() { openOverlay(so); si.value = ''; sel = -1; sr.innerHTML = '<div class="search-hint">Type at least 2 letters. Passwords themselves are never searched.</div>'; setTimeout(() => si.focus(), 30); }
  $$('[data-open-search]').forEach((b) => b.addEventListener('click', openSearch));
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openSearch(); }
    else if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) { e.preventDefault(); openSearch(); }
  });
  si && si.addEventListener('input', () => {
    clearTimeout(sTimer);
    const q = si.value.trim();
    if (q.length < 2) { sr.innerHTML = '<div class="search-hint">Type at least 2 letters.</div>'; return; }
    sTimer = setTimeout(async () => {
      const res = await fetch('action.php?do=search&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((r) => r.json()).catch(() => ({ results: [] }));
      sel = -1;
      if (!res.results || !res.results.length) { sr.innerHTML = `<div class="search-hint">Nothing found for “${esc(q)}”.</div>`; return; }
      sr.innerHTML = res.results.map((r) => `<a class="list-item" href="record.php?id=${r.id}" style="--c:${esc(r.accent)}"><span class="li-ico"><i class="bi ${esc(r.icon)}"></i></span><span class="li-main"><strong>${esc(r.title)}</strong><small>${esc(r.kind)}${r.sub ? ' · ' + esc(r.sub) : ''}</small></span>${r.status ? `<span class="badge tone-${esc(r.tone)}">${esc(r.status)}</span>` : ''}</a>`).join('');
    }, 180);
  });
  si && si.addEventListener('keydown', (e) => {
    const items = $$('.list-item', sr);
    if (!items.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach((it, i) => it.classList.toggle('sel', i === sel));
      items[sel].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      location.href = items[Math.max(0, sel)].href;
    }
  });

  /* ---------- quick filter on the current page ---------- */
  $$('[data-quickfilter]').forEach((inp) => {
    const scope = $(inp.dataset.quickfilter);
    if (!scope) return;
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      let n = 0;
      $$('[data-row]', scope).forEach((row) => {
        const hit = !q || row.textContent.toLowerCase().includes(q);
        row.style.display = hit ? '' : 'none';
        if (hit) n++;
      });
      const none = $('[data-noresults]', scope.parentElement);
      if (none) none.hidden = n > 0;
    });
  });

  /* ---------- view toggle (remembered per page) ---------- */
  $$('[data-view]').forEach((b) => b.addEventListener('click', () => {
    document.cookie = `it_view_${document.body.dataset.page}=${b.dataset.view}; path=/; max-age=31536000; samesite=lax`;
    location.reload();
  }));

  /* ---------- forms: password tools, drafts, unsaved guard ---------- */
  IT.strength = function (pw) {
    let s = 0;
    if (pw.length >= 8) s++;
    if (pw.length >= 12) s++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
    if (/\d/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    return Math.min(4, s);
  };
  $$('[data-pw]').forEach((wrap) => {
    const inp = $('input', wrap), bar = $('.strength i', wrap), lbl = $('.strength-label', wrap);
    const paint = () => {
      const s = inp.value ? IT.strength(inp.value) : -1;
      const c = ['#dc2626', '#f97316', '#eab308', '#22c55e', '#16a34a'][s] || 'transparent';
      bar.style.width = s < 0 ? '0' : ((s + 1) * 20) + '%';
      bar.style.background = c;
      lbl.textContent = s < 0 ? '' : ['Very weak', 'Weak', 'OK', 'Strong', 'Very strong'][s];
      lbl.style.color = c;
    };
    inp.addEventListener('input', paint);
    $('[data-toggle-pw]', wrap)?.addEventListener('click', (e) => {
      inp.type = inp.type === 'password' ? 'text' : 'password';
      e.currentTarget.querySelector('i').className = 'bi ' + (inp.type === 'password' ? 'bi-eye' : 'bi-eye-slash');
    });
    $('[data-gen]', wrap)?.addEventListener('click', () => {
      const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*?-_+='];
      const all = sets.join('');
      const rnd = (n) => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };
      let pw = sets.map((s) => s[rnd(s.length)]);
      while (pw.length < 16) pw.push(all[rnd(all.length)]);
      for (let i = pw.length - 1; i > 0; i--) { const j = rnd(i + 1); [pw[i], pw[j]] = [pw[j], pw[i]]; }
      inp.value = pw.join(''); inp.type = 'text';
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      IT.toast('Strong password generated', 'success');
    });
    paint();
  });

  $$('form[data-draft]').forEach((form) => {
    const key = 'it_draft_' + form.dataset.draft;
    // Secrets are never written to the draft.
    const fields = () => $$('input[name], select[name], textarea[name]', form).filter((f) => (f.type !== 'hidden' || f.dataset.dp) && f.type !== 'password' && !f.closest('[data-pw]'));
    let dirty = false;
    const save = () => {
      const d = {};
      fields().forEach((f) => { d[f.name] = f.type === 'checkbox' ? f.checked : f.value; });
      try { localStorage.setItem(key, JSON.stringify({ t: Date.now(), d })); } catch (e) {}
    };
    let stored = null;
    try { stored = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) {}
    if (stored && Date.now() - stored.t < 7 * 864e5 && !form.dataset.noRestore) {
      const bar = document.createElement('div');
      bar.className = 'alert alert-info';
      bar.innerHTML = '<i class="bi bi-clock-history"></i><span style="flex:1">You have an unsaved draft from ' + new Date(stored.t).toLocaleString() + '.</span><button type="button" class="btn btn-sm btn-soft" data-r>Restore</button><button type="button" class="btn btn-sm btn-ghost" data-x>Discard</button>';
      form.prepend(bar);
      $('[data-r]', bar).onclick = () => {
        fields().forEach((f) => { if (f.name in stored.d) { if (f.type === 'checkbox') f.checked = !!stored.d[f.name]; else f.value = stored.d[f.name]; if (f.dataset.dp || f.dataset.comboReady) f.dispatchEvent(new Event('change')); } });
        bar.remove(); IT.toast('Draft restored', 'success');
      };
      $('[data-x]', bar).onclick = () => { localStorage.removeItem(key); bar.remove(); };
    }
    form.addEventListener('input', () => { dirty = true; clearTimeout(form._t); form._t = setTimeout(save, 400); });
    form.addEventListener('submit', () => { dirty = false; try { localStorage.removeItem(key); } catch (e) {} });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  });

  // Stop double submits.
  $$('form[data-once]').forEach((f) => f.addEventListener('submit', () => {
    const b = $('button[type=submit]', f);
    if (b) { setTimeout(() => { b.disabled = true; b.innerHTML = '<i class="bi bi-arrow-repeat"></i> Saving…'; }, 0); }
  }));

  /* ---------- date picker (replaces the browser's own) ---------- */
  const DP_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const DP_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const parseIso = (v) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
  const today0 = () => { const t = new Date(); t.setHours(0, 0, 0, 0); return t; };
  const addMonths = (d, n) => { const r = new Date(d.getFullYear(), d.getMonth() + n, 1); r.setDate(Math.min(d.getDate(), new Date(r.getFullYear(), r.getMonth() + 1, 0).getDate())); return r; };
  const relText = (d) => {
    const n = Math.round((d - today0()) / 864e5);
    if (n === 0) return 'Today'; if (n === 1) return 'Tomorrow'; if (n === -1) return 'Yesterday';
    if (n > 1 && n < 60) return 'in ' + n + ' days'; if (n < 0 && n > -60) return -n + ' days ago';
    return '';
  };

  let dpPop = null, dpBackdrop = null, dpState = null;
  function dpBuild() {
    dpBackdrop = document.createElement('div');
    dpBackdrop.className = 'dp-backdrop';
    dpBackdrop.hidden = true;
    dpPop = document.createElement('div');
    dpPop.className = 'dp-pop';
    dpPop.hidden = true;
    dpPop.setAttribute('role', 'dialog');
    dpPop.setAttribute('aria-label', 'Choose a date');
    dpPop.innerHTML =
      '<div class="dp-grip"></div>' +
      '<div class="dp-head"><button type="button" class="dp-nav" data-dp-nav="-1" aria-label="Previous"><i class="bi bi-chevron-left"></i></button>' +
      '<button type="button" class="dp-title" data-dp-zoom></button>' +
      '<button type="button" class="dp-nav" data-dp-nav="1" aria-label="Next"><i class="bi bi-chevron-right"></i></button></div>' +
      '<div class="dp-body"></div>' +
      '<div class="dp-quick"></div>' +
      '<div class="dp-foot"><button type="button" class="dp-link" data-dp-clear>Clear</button><button type="button" class="btn btn-sm btn-primary" data-dp-today>Today</button></div>';
    document.body.append(dpBackdrop, dpPop);
    dpBackdrop.addEventListener('click', dpClose);
    dpPop.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b || !dpState) return;
      e.stopPropagation();
      const st = dpState;
      if (b.dataset.dpNav) {
        const n = +b.dataset.dpNav;
        st.view === 'days' ? (st.cursor = addMonths(st.cursor, n)) : st.view === 'months' ? st.cursor.setFullYear(st.cursor.getFullYear() + n) : st.cursor.setFullYear(st.cursor.getFullYear() + n * 12);
        st.cursor = new Date(st.cursor); dpRender();
      } else if (b.hasAttribute('data-dp-zoom')) {
        st.view = st.view === 'days' ? 'months' : 'years'; dpRender();
      } else if (b.dataset.month) {
        st.cursor = new Date(st.cursor.getFullYear(), +b.dataset.month, 1); st.view = 'days'; dpRender();
      } else if (b.dataset.year) {
        st.cursor = new Date(+b.dataset.year, st.cursor.getMonth(), 1); st.view = 'months'; dpRender();
      } else if (b.dataset.date) {
        dpPick(parseIso(b.dataset.date));
      } else if (b.dataset.quick) {
        const t = today0(), q = b.dataset.quick;
        dpPick(q.endsWith('d') ? new Date(t.getFullYear(), t.getMonth(), t.getDate() + parseInt(q, 10)) : addMonths(t, parseInt(q, 10)));
      } else if (b.hasAttribute('data-dp-today')) {
        dpPick(today0());
      } else if (b.hasAttribute('data-dp-clear')) {
        dpSet(st.input, ''); dpClose();
      }
    });
    dpPop.addEventListener('keydown', (e) => {
      const st = dpState;
      if (!st) return;
      if (e.key === 'Escape') { e.preventDefault(); dpClose(); return; }
      if (st.view !== 'days') return;
      const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
      if (step) { e.preventDefault(); st.cursor = new Date(st.cursor.getFullYear(), st.cursor.getMonth(), st.cursor.getDate() + step); dpRender(true); }
      else if (e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); st.cursor = addMonths(st.cursor, e.key === 'PageUp' ? -1 : 1); dpRender(true); }
      else if (e.key === 'Enter' && e.target.dataset.date) { e.preventDefault(); dpPick(parseIso(e.target.dataset.date)); }
    });
    window.addEventListener('resize', () => dpState && dpPlace());
    window.addEventListener('scroll', () => dpState && !dpPop.classList.contains('sheet-mode') && dpPlace(), true);
  }
  function dpLimits(st) { return { min: parseIso(st.input.min), max: parseIso(st.input.max) }; }
  function dpRender(focusCursor) {
    const st = dpState, body = dpPop.querySelector('.dp-body'), title = dpPop.querySelector('.dp-title');
    const { min, max } = dpLimits(st);
    const sel = parseIso(st.input.value), t = today0();
    body.className = 'dp-body view-' + st.view;
    if (st.view === 'days') {
      title.innerHTML = DP_MONTHS[st.cursor.getMonth()] + ' <b>' + st.cursor.getFullYear() + '</b>';
      const first = new Date(st.cursor.getFullYear(), st.cursor.getMonth(), 1);
      const start = new Date(first); start.setDate(1 - ((first.getDay() + 6) % 7));
      let h = DP_DAYS.map((d, i) => '<span class="dp-dow' + (i > 4 ? ' we' : '') + '">' + d.slice(0, 2) + '</span>').join('');
      for (let i = 0; i < 42; i++) {
        const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        const k = iso(d);
        const cls = ['dp-day'];
        if (d.getMonth() !== st.cursor.getMonth()) cls.push('other');
        if (+d === +t) cls.push('today');
        if (sel && +d === +sel) cls.push('sel');
        if ((d.getDay() + 6) % 7 > 4) cls.push('we');
        if (+d === +new Date(st.cursor.getFullYear(), st.cursor.getMonth(), st.cursor.getDate())) cls.push('cur');
        const off = (min && d < min) || (max && d > max);
        h += '<button type="button" class="' + cls.join(' ') + '" data-date="' + k + '"' + (off ? ' disabled' : '') + ' tabindex="' + (cls.includes('cur') ? 0 : -1) + '" aria-label="' + d.toDateString() + '">' + d.getDate() + '</button>';
      }
      body.innerHTML = h;
    } else if (st.view === 'months') {
      title.innerHTML = '<b>' + st.cursor.getFullYear() + '</b>';
      body.innerHTML = DP_MONTHS.map((m, i) => '<button type="button" class="dp-cell' + (sel && sel.getFullYear() === st.cursor.getFullYear() && sel.getMonth() === i ? ' sel' : '') + (t.getFullYear() === st.cursor.getFullYear() && t.getMonth() === i ? ' today' : '') + '" data-month="' + i + '">' + m.slice(0, 3) + '</button>').join('');
    } else {
      const y0 = st.cursor.getFullYear() - 5;
      title.innerHTML = '<b>' + y0 + ' – ' + (y0 + 11) + '</b>';
      let h = '';
      for (let y = y0; y < y0 + 12; y++) h += '<button type="button" class="dp-cell' + (sel && sel.getFullYear() === y ? ' sel' : '') + (t.getFullYear() === y ? ' today' : '') + '" data-year="' + y + '">' + y + '</button>';
      body.innerHTML = h;
    }
    title.disabled = st.view === 'years';
    if (focusCursor) { const c = body.querySelector('.cur'); if (c) c.focus(); }
  }
  function dpPlace() {
    const sheet = window.matchMedia('(max-width: 640px)').matches;
    dpPop.classList.toggle('sheet-mode', sheet);
    dpBackdrop.hidden = !sheet;
    if (sheet) { dpPop.style.left = dpPop.style.top = ''; return; }
    const r = dpState.display.getBoundingClientRect(), pw = dpPop.offsetWidth, ph = dpPop.offsetHeight;
    let top = r.bottom + 8, left = r.left;
    if (top + ph > window.innerHeight - 8 && r.top - ph - 8 > 8) top = r.top - ph - 8;
    left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
    dpPop.style.left = left + 'px'; dpPop.style.top = top + 'px';
  }
  function dpOpen(input, display) {
    if (!dpPop) dpBuild();
    if (dpState && dpState.input === input) { dpClose(); return; }
    const v = parseIso(input.value);
    dpState = { input, display, view: 'days', cursor: v || today0() };
    const quick = input.dataset.quick !== 'off';
    dpPop.querySelector('.dp-quick').innerHTML = quick ? [['1d', 'Tomorrow'], ['7d', '+1 week'], ['1m', '+1 month'], ['3m', '+3 months'], ['6m', '+6 months'], ['12m', '+1 year']].map(([k, l]) => '<button type="button" class="dp-chip" data-quick="' + k + '">' + l + '</button>').join('') : '';
    dpPop.querySelector('[data-dp-clear]').hidden = input.required;
    dpRender();
    dpPop.hidden = false;
    display.setAttribute('aria-expanded', 'true');
    dpPlace();
    requestAnimationFrame(() => { dpPop.classList.add('open'); const c = dpPop.querySelector('.cur'); if (c) c.focus({ preventScroll: true }); });
  }
  function dpClose() {
    if (!dpState) return;
    const d = dpState.display;
    dpPop.classList.remove('open');
    dpPop.hidden = true; dpBackdrop.hidden = true;
    d.setAttribute('aria-expanded', 'false');
    dpState = null;
    d.focus({ preventScroll: true });
  }
  function dpPick(d) {
    const { min, max } = dpLimits(dpState);
    if ((min && d < min) || (max && d > max)) { IT.toast('That date is outside the allowed range.', 'warn'); return; }
    dpSet(dpState.input, iso(d));
    dpClose();
  }
  function dpSet(input, v) {
    input.value = v;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
  document.addEventListener('click', (e) => { if (dpState && !dpPop.contains(e.target) && !e.target.closest('.dp')) dpClose(); });

  IT.datePicker = function (input) {
    if (input.dataset.dp) return;
    input.dataset.dp = '1';
    const wrap = document.createElement('div');
    wrap.className = 'dp';
    if (input.style.width) { wrap.style.width = input.style.width; input.style.width = ''; }
    input.parentNode.insertBefore(wrap, input);
    const display = document.createElement('button');
    display.type = 'button';
    display.className = 'input dp-display';
    display.id = input.id ? input.id + '_dp' : '';
    display.setAttribute('aria-haspopup', 'dialog');
    display.innerHTML = '<i class="bi bi-calendar3"></i><span class="dp-text"></span><span class="dp-rel"></span>';
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'dp-clear';
    clear.title = 'Clear date';
    clear.innerHTML = '<i class="bi bi-x-lg"></i>';
    input.type = 'hidden';
    wrap.append(input, display, clear);
    const lbl = input.id && document.querySelector('label[for="' + input.id + '"]');
    if (lbl) lbl.htmlFor = display.id;
    const paint = () => {
      const d = parseIso(input.value);
      display.querySelector('.dp-text').textContent = d ? d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : (input.placeholder || 'Pick a date');
      display.classList.toggle('is-empty', !d);
      const rel = d ? relText(d) : '';
      const past = d && d < today0();
      display.querySelector('.dp-rel').textContent = rel;
      display.querySelector('.dp-rel').className = 'dp-rel' + (past ? ' past' : '');
      clear.hidden = !d || input.required;
    };
    input.addEventListener('change', paint);
    display.addEventListener('click', () => dpOpen(input, display));
    display.addEventListener('keydown', (e) => { if (e.key === 'ArrowDown') { e.preventDefault(); dpOpen(input, display); } });
    clear.addEventListener('click', () => { dpSet(input, ''); display.focus(); });
    paint();
  };
  $$('input[type=date]').forEach(IT.datePicker);

  /* ---------- searchable picker for long dropdowns (select[data-combo]) ---------- */
  const norm = (t) => String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const mark = (text, q) => {
    if (!q) return esc(text);
    const i = norm(text).indexOf(q);
    return i < 0 ? esc(text) : esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + q.length)) + '</mark>' + esc(text.slice(i + q.length));
  };
  let cbPop = null, cbBack = null, cbState = null;
  function cbBuild() {
    cbBack = document.createElement('div'); cbBack.className = 'dp-backdrop'; cbBack.hidden = true;
    cbPop = document.createElement('div'); cbPop.className = 'combo-pop'; cbPop.hidden = true;
    cbPop.innerHTML = '<div class="dp-grip"></div><div class="combo-search"><i class="bi bi-search"></i><input type="text" autocomplete="off" spellcheck="false"><button type="button" class="combo-x" title="Clear search"><i class="bi bi-x-lg"></i></button></div>' +
      '<div class="combo-list" role="listbox"></div><div class="combo-foot"></div>';
    document.body.append(cbBack, cbPop);
    const inp = cbPop.querySelector('input');
    cbBack.addEventListener('click', () => cbClose());
    inp.addEventListener('input', () => { cbState.active = 0; cbRender(); });
    cbPop.querySelector('.combo-x').addEventListener('click', () => { inp.value = ''; inp.focus(); cbState.active = 0; cbRender(); });
    inp.addEventListener('keydown', (e) => {
      const n = cbState.shown.length;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (n) { cbState.active = (cbState.active + (e.key === 'ArrowDown' ? 1 : -1) + n) % n; cbPaintActive(true); } }
      else if (e.key === 'Enter') { e.preventDefault(); if (n) cbPick(cbState.shown[cbState.active]); }
      else if (e.key === 'Escape') { e.preventDefault(); cbClose(); }
      else if (e.key === 'Tab') cbClose(false);
    });
    cbPop.querySelector('.combo-list').addEventListener('mousedown', (e) => e.preventDefault());
    cbPop.querySelector('.combo-list').addEventListener('click', (e) => {
      const it = e.target.closest('[data-i]');
      if (it) cbPick(cbState.shown[+it.dataset.i]);
    });
    window.addEventListener('resize', () => cbState && cbPlace());
    window.addEventListener('scroll', (e) => { if (cbState && !cbPop.contains(e.target) && !cbPop.classList.contains('sheet-mode')) cbPlace(); }, true);
    document.addEventListener('mousedown', (e) => { if (cbState && !cbPop.contains(e.target) && !e.target.closest('.combo')) cbClose(false); });
  }
  function cbOptions(sel) {
    return Array.from(sel.options).map((o) => ({ value: o.value, label: o.textContent.trim(), sub: o.dataset.sub || '', nick: o.dataset.nick || '', empty: o.value === '' }));
  }
  function cbRender() {
    const st = cbState, q = norm(cbPop.querySelector('input').value.trim());
    const words = q.split(/\s+/).filter(Boolean);
    let list = st.options.filter((o) => !o.empty);
    if (words.length) {
      list = list.map((o) => {
        const hay = norm(o.label + ' ' + o.nick + ' ' + o.sub);
        if (!words.every((w) => hay.includes(w))) return null;
        const l = norm(o.label), nk = norm(o.nick);
        // Best first: name starts with it, then a word of the name, then nickname, then anything.
        const score = l.startsWith(q) ? 0 : (' ' + l).includes(' ' + words[0]) ? 1 : nk.startsWith(words[0]) ? 2 : 3;
        return { o, score };
      }).filter(Boolean).sort((a, b) => a.score - b.score || a.o.label.localeCompare(b.o.label)).map((x) => x.o);
    }
    const emptyOpt = st.options.find((o) => o.empty);
    st.shown = (emptyOpt && !st.select.required && !words.length && st.select.value !== '' ? [emptyOpt] : []).concat(list);
    const box = cbPop.querySelector('.combo-list');
    const cur = st.select.value;
    const MAX = 150;
    box.innerHTML = st.shown.length ? st.shown.slice(0, MAX).map((o, i) => {
      if (o.empty) return '<div class="combo-item combo-none" data-i="' + i + '" role="option"><i class="bi bi-x-circle"></i><span>' + esc(o.label.replace(/[—-]\s*/g, '').trim() || 'None') + '</span></div>';
      const initial = esc((o.nick || o.label).charAt(0).toUpperCase());
      return '<div class="combo-item' + (o.value === cur ? ' sel' : '') + '" data-i="' + i + '" role="option" aria-selected="' + (o.value === cur) + '">' +
        (st.avatars ? '<span class="combo-av">' + initial + '</span>' : '') +
        '<span class="combo-txt"><b>' + mark(o.label, words[0] || '') + (o.nick && norm(o.label).indexOf(norm(o.nick)) !== 0 ? ' <em>“' + mark(o.nick, words[0] || '') + '”</em>' : '') + '</b>' +
        (o.sub ? '<small>' + mark(o.sub, words[0] || '') + '</small>' : '') + '</span>' +
        (o.value === cur ? '<i class="bi bi-check2 combo-tick"></i>' : '') + '</div>';
    }).join('') : '<div class="combo-empty"><i class="bi bi-search"></i>No ' + esc(st.noun) + ' matches “' + esc(cbPop.querySelector('input').value.trim()) + '”</div>';
    const total = list.length;
    cbPop.querySelector('.combo-foot').textContent = words.length ? total + ' found' + (total > MAX ? ' — showing ' + MAX + ', type more to narrow it down' : '') : total + ' ' + st.noun + (total === 1 ? '' : 's') + ' · type to filter · ↑↓ Enter';
    if (st.active >= st.shown.length) st.active = 0;
    cbPaintActive(false);
  }
  function cbPaintActive(scroll) {
    cbPop.querySelectorAll('.combo-item').forEach((el) => el.classList.toggle('active', +el.dataset.i === cbState.active));
    const a = cbPop.querySelector('.combo-item.active');
    if (a && scroll) a.scrollIntoView({ block: 'nearest' });
  }
  function cbPlace() {
    const sheet = window.matchMedia('(max-width: 640px)').matches;
    cbPop.classList.toggle('sheet-mode', sheet);
    cbBack.hidden = !sheet;
    if (sheet) { cbPop.style.left = cbPop.style.top = cbPop.style.width = ''; return; }
    const r = cbState.display.getBoundingClientRect();
    cbPop.style.width = Math.max(r.width, 320) + 'px';
    const ph = cbPop.offsetHeight;
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8 && r.top - ph - 6 > 8) top = r.top - ph - 6;
    cbPop.style.top = top + 'px';
    cbPop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - Math.max(r.width, 320) - 8)) + 'px';
  }
  function cbOpen(sel, display) {
    if (!cbPop) cbBuild();
    if (cbState && cbState.select === sel) { cbClose(); return; }
    if (dpState) dpClose();
    const options = cbOptions(sel);
    cbState = { select: sel, display, options, shown: [], active: 0, noun: sel.dataset.noun || 'item', avatars: sel.dataset.noun === 'employee' };
    const inp = cbPop.querySelector('input');
    inp.value = '';
    inp.placeholder = sel.dataset.placeholder || 'Type to search…';
    cbRender();
    const i = cbState.shown.findIndex((o) => o.value === sel.value && !o.empty);
    cbState.active = i >= 0 ? i : 0;
    cbPop.hidden = false;
    display.setAttribute('aria-expanded', 'true');
    cbPlace();
    cbPaintActive(true);
    requestAnimationFrame(() => { cbPop.classList.add('open'); inp.focus({ preventScroll: true }); });
  }
  function cbClose(refocus = true) {
    if (!cbState) return;
    const d = cbState.display;
    cbPop.classList.remove('open'); cbPop.hidden = true; cbBack.hidden = true;
    d.setAttribute('aria-expanded', 'false');
    cbState = null;
    if (refocus) d.focus({ preventScroll: true });
  }
  function cbPick(o) {
    const sel = cbState.select;
    sel.value = o.value;
    sel.dispatchEvent(new Event('input', { bubbles: true }));
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    cbClose();
  }

  IT.combo = function (sel) {
    if (sel.dataset.comboReady) return;
    sel.dataset.comboReady = '1';
    const wrap = document.createElement('div');
    wrap.className = 'combo';
    sel.parentNode.insertBefore(wrap, sel);
    const display = document.createElement('button');
    display.type = 'button';
    display.className = 'input combo-display';
    display.id = sel.id ? sel.id + '_combo' : '';
    display.setAttribute('aria-haspopup', 'listbox');
    display.innerHTML = '<span class="combo-av" hidden></span><span class="combo-val"></span><i class="bi bi-chevron-expand combo-caret"></i>';
    sel.classList.add('combo-native');
    sel.tabIndex = -1;
    wrap.append(sel, display);
    const lbl = sel.id && document.querySelector('label[for="' + sel.id + '"]');
    if (lbl) lbl.htmlFor = display.id;
    const paint = () => {
      const o = sel.options[sel.selectedIndex];
      const empty = !o || o.value === '';
      const val = display.querySelector('.combo-val');
      const av = display.querySelector('.combo-av');
      if (empty) { val.innerHTML = '<span class="combo-ph">' + esc(sel.dataset.placeholder || 'Choose…') + '</span>'; av.hidden = true; }
      else {
        val.innerHTML = '<b>' + esc(o.textContent.trim()) + '</b>' + (o.dataset.sub ? '<small>' + esc(o.dataset.sub) + '</small>' : '');
        av.hidden = sel.dataset.noun !== 'employee';
        av.textContent = (o.dataset.nick || o.textContent.trim()).charAt(0).toUpperCase();
      }
      display.classList.toggle('is-empty', empty);
    };
    sel.addEventListener('change', paint);
    display.addEventListener('click', () => cbOpen(sel, display));
    display.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cbOpen(sel, display); }
      else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        // Typing on the closed field starts the search with that letter.
        e.preventDefault(); cbOpen(sel, display);
        const inp = cbPop.querySelector('input'); inp.value = e.key; cbState.active = 0; cbRender();
      }
    });
    paint();
  };
  $$('select[data-combo]').forEach(IT.combo);

  /* ---------- PWA ---------- */
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }

  window.IT = IT;
})();
