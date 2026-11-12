<?php /* Photo upload + viewer for the Photos card on record.php. */ ?>
<div class="overlay lightbox" id="lightbox" hidden>
  <button type="button" class="icon-btn lb-close" data-close title="Close"><i class="bi bi-x-lg"></i></button>
  <button type="button" class="icon-btn lb-prev" title="Previous"><i class="bi bi-chevron-left"></i></button>
  <img alt="">
  <button type="button" class="icon-btn lb-next" title="Next"><i class="bi bi-chevron-right"></i></button>
</div>
<script>
(function () {
  const card = document.getElementById('photos');
  if (!card) return;
  const MAX_ORIGINAL = 40 * 1024 * 1024; // bigger than this is not a normal phone/camera photo
  const MAX_SIDE = 1600;
  const max = +card.dataset.max;
  let count = +card.dataset.photos;
  const progress = card.querySelector('.photo-progress');

  /* ---- viewer ---- */
  const lb = document.getElementById('lightbox'), lbImg = lb.querySelector('img');
  const links = () => Array.from(card.querySelectorAll('[data-lightbox]'));
  let at = 0;
  const show = (i) => { const l = links(); if (!l.length) return; at = (i + l.length) % l.length; lbImg.src = l[at].href; };
  card.addEventListener('click', (e) => {
    const a = e.target.closest('[data-lightbox]');
    if (!a) return;
    e.preventDefault(); show(links().indexOf(a)); IT.openOverlay(lb);
  });
  lb.querySelector('.lb-prev').onclick = (e) => { e.stopPropagation(); show(at - 1); };
  lb.querySelector('.lb-next').onclick = (e) => { e.stopPropagation(); show(at + 1); };
  document.addEventListener('keydown', (e) => { if (!lb.hidden && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) show(at + (e.key === 'ArrowLeft' ? -1 : 1)); });

  /* ---- cover / delete ---- */
  card.addEventListener('click', async (e) => {
    const del = e.target.closest('[data-photo-delete]'), cov = e.target.closest('[data-photo-cover]');
    if (!del && !cov) return;
    const id = e.target.closest('[data-photo]').dataset.photo;
    if (del && !(await IT.confirm({ title: 'Delete this photo?', text: 'The photo is removed for good.', ok: 'Delete photo' }))) return;
    const r = await IT.post(del ? 'photo_delete' : 'photo_cover', { photo: id });
    if (!r.ok) { IT.toast(r.error, 'error'); return; }
    location.reload();
  });

  /* ---- upload ---- */
  function row(name) {
    progress.hidden = false;
    const d = document.createElement('div');
    d.className = 'pp-row';
    d.innerHTML = '<span class="pp-name"></span><span class="pp-bar"><i></i></span><span class="pp-state">Checking…</span>';
    d.querySelector('.pp-name').textContent = name;
    progress.appendChild(d);
    return {
      set(pct, text) { d.querySelector('i').style.width = pct + '%'; if (text) d.querySelector('.pp-state').textContent = text; },
      fail(text) { d.classList.add('bad'); d.querySelector('.pp-state').textContent = text; },
      done() { d.classList.add('good'); d.querySelector('i').style.width = '100%'; d.querySelector('.pp-state').textContent = 'Saved'; },
    };
  }
  const looksLikePhoto = (f) => f.type.startsWith('image/') || /\.(jpe?g|png|webp|heic|heif)$/i.test(f.name);

  // Draw the photo again at a sensible size; turns 8 MB phone photos into ~400 KB and fixes rotation.
  async function shrink(file) {
    let src;
    try {
      src = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (e) {
      src = await new Promise((ok, bad) => { const im = new Image(); im.onload = () => ok(im); im.onerror = bad; im.src = URL.createObjectURL(file); });
    }
    const w = src.width || src.naturalWidth, h = src.height || src.naturalHeight;
    if (!w || !h) throw new Error('unreadable');
    const k = Math.min(1, MAX_SIDE / Math.max(w, h));
    const c = document.createElement('canvas');
    c.width = Math.round(w * k); c.height = Math.round(h * k);
    const g = c.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
    g.drawImage(src, 0, 0, c.width, c.height);
    return new Promise((ok, bad) => c.toBlob((b) => b ? ok(b) : bad(new Error('encode')), 'image/jpeg', 0.86));
  }
  function send(blob, name, r) {
    return new Promise((resolve) => {
      const fd = new FormData();
      fd.append('do', 'photo_upload'); fd.append('_csrf', IT.csrf); fd.append('id', card.dataset.record);
      fd.append('photo', blob, name.replace(/\.[^.]+$/, '') + '.jpg');
      const x = new XMLHttpRequest();
      x.open('POST', 'action.php');
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.upload.onprogress = (e) => { if (e.lengthComputable) r.set(Math.round(e.loaded / e.total * 95), 'Uploading ' + Math.round(e.loaded / e.total * 100) + '%'); };
      x.onload = () => { let j; try { j = JSON.parse(x.responseText); } catch (e) { j = { ok: false, error: 'Unexpected reply from the server.' }; } resolve(j); };
      x.onerror = () => resolve({ ok: false, error: 'No connection. Try again.' });
      x.send(fd);
    });
  }
  async function handle(files) {
    files = Array.from(files || []);
    if (!files.length) return;
    const room = max - count;
    if (room <= 0) { IT.toast('This device already has ' + max + ' photos. Delete one first.', 'warn'); return; }
    if (files.length > room) { IT.toast('Only ' + room + ' more photo' + (room === 1 ? '' : 's') + ' fit — the first ' + room + ' will be added.', 'warn'); files = files.slice(0, room); }
    let saved = 0;
    for (const f of files) {
      const r = row(f.name);
      if (!looksLikePhoto(f)) { r.fail('Not a photo'); IT.toast('“' + f.name + '” is not a photo.', 'error'); continue; }
      if (f.size === 0) { r.fail('Empty file'); continue; }
      if (f.size > MAX_ORIGINAL) { r.fail('Too big (' + (f.size / 1048576).toFixed(0) + ' MB)'); IT.toast('“' + f.name + '” is over 40 MB.', 'error'); continue; }
      let blob;
      try { r.set(5, 'Preparing…'); blob = await shrink(f); }
      catch (e) { r.fail('Cannot read this photo'); IT.toast('“' + f.name + '” cannot be read here. Use a JPG or PNG photo.', 'error'); continue; }
      const res = await send(blob, f.name, r);
      if (res.ok) { r.done(); saved++; count++; } else { r.fail(res.error || 'Failed'); IT.toast(res.error || 'Upload failed', 'error'); }
    }
    if (saved) { IT.toast(saved + ' photo' + (saved === 1 ? '' : 's') + ' added', 'success'); setTimeout(() => location.reload(), 900); }
  }
  card.querySelectorAll('[data-photo-input]').forEach((inp) => inp.addEventListener('change', () => { handle(inp.files); inp.value = ''; }));
  const zone = card.querySelector('[data-dropzone]');
  if (card.querySelector('[data-photo-input]')) {
    ['dragenter', 'dragover'].forEach((t) => zone.addEventListener(t, (e) => { e.preventDefault(); zone.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((t) => zone.addEventListener(t, (e) => { e.preventDefault(); zone.classList.remove('drag'); }));
    zone.addEventListener('drop', (e) => handle(e.dataTransfer.files));
  }
})();
</script>
