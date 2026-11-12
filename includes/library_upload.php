<?php /* Library upload: browser-side checks, photo shrinking, one request per file with progress. */ ?>
<script>
(function () {
  const zone = document.getElementById('libDrop');
  if (!zone) return;
  const exts = zone.dataset.exts.split(',');
  const DOC_MAX = +zone.dataset.docMax, PHOTO_MAX = 40 * 1024 * 1024, SIDE = +zone.dataset.photoSide;
  const progress = zone.querySelector('.photo-progress');
  const folder = document.getElementById('libFolder');
  const extOf = (n) => { const m = /\.([a-z0-9]+)$/i.exec(n); return m ? m[1].toLowerCase().replace('jpeg', 'jpg') : ''; };
  const isPhoto = (f) => ['jpg', 'png', 'webp', 'heic', 'heif'].includes(extOf(f.name)) || (f.type.startsWith('image/') && !extOf(f.name));

  function row(name) {
    progress.hidden = false;
    const d = document.createElement('div');
    d.className = 'pp-row';
    d.innerHTML = '<span class="pp-name"></span><span class="pp-bar"><i></i></span><span class="pp-state">Checking…</span>';
    d.querySelector('.pp-name').textContent = name;
    progress.appendChild(d);
    return {
      set(p, t) { d.querySelector('i').style.width = p + '%'; if (t) d.querySelector('.pp-state').textContent = t; },
      fail(t, link) { d.classList.add('bad'); const s = d.querySelector('.pp-state'); s.textContent = t; if (link) { const a = document.createElement('a'); a.href = link; a.textContent = ' Open'; a.style.textDecoration = 'underline'; s.appendChild(a); } },
      done() { d.classList.add('good'); d.querySelector('i').style.width = '100%'; d.querySelector('.pp-state').textContent = 'Saved'; },
    };
  }
  async function shrink(file) {
    let src;
    try { src = await createImageBitmap(file, { imageOrientation: 'from-image' }); }
    catch (e) { src = await new Promise((ok, bad) => { const im = new Image(); im.onload = () => ok(im); im.onerror = bad; im.src = URL.createObjectURL(file); }); }
    const w = src.width || src.naturalWidth, h = src.height || src.naturalHeight;
    const k = Math.min(1, SIDE / Math.max(w, h));
    const c = document.createElement('canvas');
    c.width = Math.round(w * k); c.height = Math.round(h * k);
    const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(src, 0, 0, c.width, c.height);
    return new Promise((ok, bad) => c.toBlob((b) => b ? ok(b) : bad(new Error('encode')), 'image/jpeg', 0.88));
  }
  function send(blob, name, r) {
    return new Promise((resolve) => {
      const fd = new FormData();
      fd.append('do', 'library_upload'); fd.append('_csrf', IT.csrf); fd.append('folder', folder.value); fd.append('asset', zone.dataset.asset || '');
      fd.append('file', blob, name);
      const x = new XMLHttpRequest();
      x.open('POST', 'action.php');
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.upload.onprogress = (e) => { if (e.lengthComputable) r.set(Math.round(e.loaded / e.total * 95), 'Uploading ' + Math.round(e.loaded / e.total * 100) + '%'); };
      x.onload = () => { let j; try { j = JSON.parse(x.responseText); } catch (e) { j = { ok: false, error: x.status === 413 ? 'Too big for the server.' : 'Unexpected reply from the server.' }; } resolve(j); };
      x.onerror = () => resolve({ ok: false, error: 'No connection. Try again.' });
      x.send(fd);
    });
  }
  async function handle(files) {
    files = Array.from(files || []);
    if (!files.length) return;
    if (files.length > 30) { IT.toast('Upload up to 30 files at a time.', 'warn'); files = files.slice(0, 30); }
    let saved = 0, last = null;
    for (const f of files) {
      const r = row(f.name);
      const ext = extOf(f.name);
      const photo = isPhoto(f);
      if (!photo && !exts.includes(ext)) { r.fail('.' + (ext || '?') + ' not allowed'); continue; }
      if (f.size === 0) { r.fail('Empty file'); continue; }
      if (photo && f.size > PHOTO_MAX) { r.fail('Photo over 40 MB'); continue; }
      if (!photo && f.size > DOC_MAX) { r.fail('Over ' + Math.round(DOC_MAX / 1048576) + ' MB (' + (f.size / 1048576).toFixed(1) + ' MB)'); continue; }
      let blob = f, name = f.name;
      if (photo) {
        try { r.set(5, 'Preparing…'); blob = await shrink(f); name = f.name.replace(/\.[^.]+$/, '') + '.jpg'; }
        catch (e) { r.fail('Cannot read this photo — use JPG or PNG'); continue; }
      }
      const res = await send(blob, name, r);
      if (res.ok) { r.done(); saved++; last = res.id; }
      else r.fail(res.error || 'Failed', res.existing ? 'record.php?id=' + res.existing : null);
    }
    if (saved) {
      IT.toast(saved + ' file' + (saved === 1 ? '' : 's') + ' added to “' + folder.value + '”', 'success');
      setTimeout(() => { location.href = saved === 1 && files.length === 1 ? 'record.php?id=' + last : location.href; }, 1100);
    }
  }
  document.querySelectorAll('[data-lib-input]').forEach((inp) => inp.addEventListener('change', () => { handle(inp.files); inp.value = ''; }));
  // Drop anywhere on the page.
  let depth = 0;
  document.addEventListener('dragenter', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { depth++; zone.classList.add('drag'); } });
  document.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; zone.classList.remove('drag'); } });
  document.addEventListener('dragover', (e) => e.preventDefault());
  document.addEventListener('drop', (e) => { e.preventDefault(); depth = 0; zone.classList.remove('drag'); if (e.dataTransfer.files.length) handle(e.dataTransfer.files); });
})();
</script>
