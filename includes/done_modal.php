<?php /* "Mark done" dialog for maintenance tasks. Buttons: data-done="<record id>". */ ?>
<div class="overlay" id="doneOverlay" hidden>
  <form class="modal" id="doneForm">
    <div class="modal-icon" style="color:var(--green);background:var(--green-bg)"><i class="bi bi-check2-circle"></i></div>
    <h3 id="doneTitle" style="text-align:center">Mark as done</h3>
    <p class="muted" id="doneSub" style="text-align:center;margin:0"></p>
    <div class="checklist" id="doneChecklist"></div>
    <div class="field" style="margin-top:12px">
      <label>Result</label>
      <div class="seg" style="width:100%">
        <button type="button" class="active" data-result="ok" style="flex:1;width:auto"><i class="bi bi-emoji-smile"></i>&nbsp; All good</button>
        <button type="button" data-result="issue" style="flex:1;width:auto"><i class="bi bi-exclamation-triangle"></i>&nbsp; Problem found</button>
      </div>
    </div>
    <div class="field" style="margin-top:12px"><label for="doneNote">Note <span class="faint">(optional)</span></label><textarea class="input" id="doneNote" placeholder="What did you find or fix?"></textarea></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="submit" class="btn btn-success" id="doneSubmit"><i class="bi bi-check2"></i> Save</button>
    </div>
  </form>
</div>
<script>
(function () {
  const o = document.getElementById('doneOverlay');
  let current = null, result = 'ok';
  o.querySelectorAll('[data-result]').forEach(b => b.onclick = () => {
    result = b.dataset.result;
    o.querySelectorAll('[data-result]').forEach(x => x.classList.toggle('active', x === b));
  });
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-done]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const r = await fetch('action.php?do=task&id=' + b.dataset.done, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(x => x.json()).catch(() => null);
    if (!r || !r.ok) { IT.toast((r && r.error) || 'Could not load the task.', 'error'); return; }
    current = r;
    document.getElementById('doneTitle').textContent = r.name;
    document.getElementById('doneSub').textContent = r.frequency === 'Once' ? 'One-time task' : 'Repeats ' + r.frequency.toLowerCase() + ' — next date is set for you.';
    const cl = document.getElementById('doneChecklist');
    cl.innerHTML = '';
    r.checklist.forEach((s, i) => {
      const l = document.createElement('label');
      l.className = 'check';
      l.innerHTML = '<input type="checkbox" value="' + i + '"><span></span>';
      l.querySelector('span').textContent = s;
      cl.appendChild(l);
    });
    document.getElementById('doneNote').value = '';
    o.querySelector('[data-result="ok"]').click();
    IT.openOverlay(o);
  });
  document.getElementById('doneForm').onsubmit = async (e) => {
    e.preventDefault();
    const boxes = Array.from(o.querySelectorAll('#doneChecklist input'));
    const unticked = boxes.filter(x => !x.checked).length;
    if (unticked && result === 'ok' && !(await IT.confirm({ title: unticked + ' step(s) not ticked', text: 'Save as done anyway?', ok: 'Save anyway', danger: false }))) { IT.openOverlay(o); return; }
    const btn = document.getElementById('doneSubmit');
    btn.disabled = true;
    const r = await IT.post('complete', { id: current.id, result, note: document.getElementById('doneNote').value, ticked: boxes.filter(x => x.checked).map(x => x.value) });
    btn.disabled = false;
    if (!r.ok) { IT.toast(r.error, 'error'); return; }
    IT.closeOverlay(o);
    IT.toast(r.message, 'success');
    setTimeout(() => location.reload(), 700);
  };
})();
</script>
