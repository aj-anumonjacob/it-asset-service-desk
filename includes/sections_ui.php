<?php
/**
 * Section tiles + the create/edit dialog, shared by the Vault (sections) and
 * the Library (folders). Call it_section_bar() for the tiles and
 * it_section_dialog() once, before the layout footer.
 */

/** Item count per section name for an entity. */
function it_section_counts(PDO $conn, string $entity, array $extraWhere = []): array {
    $w = "entity = ? AND deleted_at IS NULL";
    $args = [$entity];
    foreach ($extraWhere as $sql => $val) { $w .= " AND $sql"; $args[] = $val; }
    $st = $conn->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')) c, COUNT(*) n FROM it_records WHERE $w GROUP BY c");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

function it_section_bar(string $entity, string $baseUrl, ?array $current, array $counts, string $allLabel, string $noun): string {
    global $conn;
    $sections = it_sections($conn, $entity);
    $canEdit = it_can('edit');
    ob_start(); ?>
    <div class="sections">
      <a class="section-tile <?= $current ? '' : 'active' ?>" href="<?= h($baseUrl) ?>" style="--c:var(--accent)">
        <span class="st-ico"><i class="bi bi-grid-3x3-gap"></i></span><span class="st-name"><?= h($allLabel) ?></span><span class="st-count"><?= array_sum($counts) ?></span>
      </a>
      <?php foreach ($sections as $s): $n = (int)($counts[$s['name']] ?? 0); $on = $current && $current['key'] === $s['key']; ?>
        <a class="section-tile <?= $on ? 'active' : '' ?>" href="<?= h($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'section=' . rawurlencode($s['name'])) ?>" style="--c:<?= h($s['color']) ?>">
          <span class="st-ico"><i class="bi <?= h($s['icon']) ?>"></i></span><span class="st-name"><?= h($s['name']) ?></span><span class="st-count"><?= $n ?></span>
          <?php if ($canEdit && $on): ?><button type="button" class="st-edit" title="Edit" data-section-edit='<?= h(json_encode($s + ['count' => $n])) ?>'><i class="bi bi-pencil"></i></button><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($canEdit): ?>
        <button type="button" class="section-tile add" data-section-new><span class="st-ico"><i class="bi bi-plus-lg"></i></span><span class="st-name">New <?= h($noun) ?></span></button>
      <?php endif; ?>
    </div>
    <?php
    $known = array_map('strtolower', array_column($sections, 'name'));
    $orphans = array_sum(array_filter($counts, fn($n, $c) => !in_array(strtolower((string)$c), $known, true), ARRAY_FILTER_USE_BOTH));
    if ($orphans && !$current): ?>
      <div class="alert alert-info"><i class="bi bi-info-circle"></i><span><?= $orphans ?> item<?= $orphans === 1 ? ' is' : 's are' ?> in a <?= h($noun) ?> that no longer exists. Open and save <?= $orphans === 1 ? 'it' : 'them' ?> with a new <?= h($noun) ?>.</span></div>
    <?php endif;
    if ($current): ?>
      <div class="section-banner" style="--c:<?= h($current['color']) ?>"><i class="bi <?= h($current['icon']) ?>"></i><strong><?= h($current['name']) ?></strong><span class="muted"><?= (int)($counts[$current['name']] ?? 0) ?> saved</span></div>
    <?php endif;
    return ob_get_clean();
}

function it_section_dialog(string $entity, string $noun, string $examples, string $baseUrl): void {
    global $conn;
    if (!it_can('edit')) return;
    $sections = it_sections($conn, $entity); ?>
<div class="overlay" id="sectionOverlay" hidden>
  <form class="modal" id="sectionForm" autocomplete="off" data-group="<?= h($entity) ?>" data-base="<?= h($baseUrl) ?>">
    <div class="modal-icon" id="secPreview"><i class="bi bi-folder2"></i></div>
    <h3 style="text-align:center" id="secTitle" data-noun="<?= h($noun) ?>">New <?= h($noun) ?></h3>
    <p class="muted" style="text-align:center;margin:0 0 16px">For example: <?= h($examples) ?>.</p>
    <input type="hidden" name="key">
    <div class="field"><label for="secName"><?= h(ucfirst($noun)) ?> name</label><input class="input" id="secName" name="name" maxlength="40" required></div>
    <div class="field" style="margin-top:14px"><label>Icon</label>
      <div class="icon-pick"><?php foreach (it_section_icons() as $i => $ic): ?><label><input type="radio" name="icon" value="<?= h($ic) ?>" <?= $i === 0 ? 'checked' : '' ?>><i class="bi <?= h($ic) ?>"></i></label><?php endforeach; ?></div>
    </div>
    <div class="field" style="margin-top:14px"><label>Colour</label>
      <div class="swatches"><?php foreach (it_accent_choices() as $i => $c): ?><label class="swatch" style="background:<?= $c ?>"><input type="radio" name="color" value="<?= $c ?>" <?= $i === 6 ? 'checked' : '' ?>></label><?php endforeach; ?></div>
    </div>
    <div id="secDelete" hidden style="margin-top:18px;padding-top:16px;border-top:1px solid var(--line)">
      <div class="field" id="secMoveWrap"><label for="secMove">When deleting, move what is inside to</label>
        <select class="input" id="secMove"><?php foreach ($sections as $s): ?><option value="<?= h($s['name']) ?>" data-key="<?= h($s['key']) ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <button type="button" class="btn btn-sm btn-ghost" data-move-dir="left"><i class="bi bi-arrow-left"></i> Move left</button>
        <button type="button" class="btn btn-sm btn-ghost" data-move-dir="right">Move right <i class="bi bi-arrow-right"></i></button>
        <button type="button" class="btn btn-sm btn-ghost" id="secDeleteBtn" style="margin-left:auto;color:var(--red)"><i class="bi bi-trash3"></i> Delete <?= h($noun) ?></button>
      </div>
    </div>
    <div class="form-error" id="secError" style="margin-top:10px"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" id="secSave">Create</button>
    </div>
  </form>
</div>
<script>
(function () {
  const o = document.getElementById('sectionOverlay');
  const f = document.getElementById('sectionForm');
  const group = f.dataset.group, base = f.dataset.base, noun = document.getElementById('secTitle').dataset.noun;
  const go = (name) => { location.href = base + (base.includes('?') ? '&' : '?') + (name ? 'section=' + encodeURIComponent(name) : ''); };
  const preview = () => {
    const ic = f.querySelector('input[name=icon]:checked'), c = f.querySelector('input[name=color]:checked');
    const p = document.getElementById('secPreview');
    p.innerHTML = '<i class="bi ' + (ic ? ic.value : 'bi-folder2') + '"></i>';
    if (c) { p.style.color = c.value; p.style.background = 'color-mix(in srgb, ' + c.value + ' 16%, transparent)'; }
  };
  f.addEventListener('change', preview);
  let editing = null;
  function open(sec) {
    editing = sec;
    f.elements.key.value = sec ? sec.key : '';
    f.elements.name.value = sec ? sec.name : '';
    const pick = (name, val) => { const r = Array.from(f.querySelectorAll('input[name=' + name + ']')).find(x => x.value === val); if (r) r.checked = true; };
    if (sec) { pick('icon', sec.icon); pick('color', sec.color); }
    document.getElementById('secTitle').textContent = (sec ? 'Edit ' : 'New ') + noun;
    document.getElementById('secSave').textContent = sec ? 'Save' : 'Create';
    document.getElementById('secDelete').hidden = !sec;
    if (sec) {
      document.getElementById('secMoveWrap').hidden = !sec.count;
      const opts = Array.from(document.getElementById('secMove').options);
      opts.forEach(op => { op.disabled = op.dataset.key === sec.key; });
      const first = opts.find(op => op.dataset.key !== sec.key);
      if (first) first.selected = true;
    }
    document.getElementById('secError').textContent = '';
    preview();
    IT.openOverlay(o);
    setTimeout(() => f.elements.name.focus(), 50);
  }
  document.addEventListener('click', (e) => {
    const n = e.target.closest('[data-section-new]'), ed = e.target.closest('[data-section-edit]');
    if (n) { e.preventDefault(); open(null); }
    if (ed) { e.preventDefault(); e.stopPropagation(); open(JSON.parse(ed.dataset.sectionEdit)); }
  });
  f.onsubmit = async (e) => {
    e.preventDefault();
    const r = await IT.post('section_save', { group, key: f.elements.key.value, name: f.elements.name.value,
      icon: (f.querySelector('input[name=icon]:checked') || {}).value || '', color: (f.querySelector('input[name=color]:checked') || {}).value || '' });
    if (!r.ok) { document.getElementById('secError').textContent = r.error; return; }
    go(r.name);
  };
  o.querySelectorAll('[data-move-dir]').forEach(b => b.onclick = async () => {
    await IT.post('section_move', { group, key: editing.key, dir: b.dataset.moveDir });
    location.reload();
  });
  document.getElementById('secDeleteBtn').onclick = async () => {
    const to = document.getElementById('secMove').value;
    const text = editing.count ? 'Its ' + editing.count + ' item(s) move to “' + to + '”. Nothing is deleted.' : 'It is empty.';
    if (!(await IT.confirm({ title: 'Delete “' + editing.name + '”?', text, ok: 'Delete ' + noun }))) { IT.openOverlay(o); return; }
    const r = await IT.post('section_delete', { group, key: editing.key, move_to: to });
    if (!r.ok) { IT.openOverlay(o); document.getElementById('secError').textContent = r.error; return; }
    go('');
  };
})();
</script>
<?php
}
