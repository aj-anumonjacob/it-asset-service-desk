<?php
/**
 * Customize one page: look, list defaults, dashboard widgets, and per-tab
 * fields (rename, hide, required, show in list, order, dropdown options,
 * custom fields). Every save is a new version that can be restored.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
if (!it_can('settings')) { it_flash('Only the IT admin can customize pages.', 'error'); header('Location: dashboard.php'); exit; }

$pages = it_pages();
$target = (string)($_GET['page'] ?? $_POST['page'] ?? '');
if (!isset($pages[$target])) { header('Location: settings.php'); exit; }
$tp = $pages[$target];
$defs = it_entities();
$cur = it_settings($conn, $target);
$defaults = it_page_defaults($target);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!it_csrf_ok()) { it_flash('The page expired. Please try again.', 'error'); header('Location: customize.php?page=' . $target); exit; }
    $act = $_POST['act'] ?? 'save';
    if ($act === 'reset') {
        $v = it_save_settings($conn, $target, $defaults);
        it_log($conn, 'settings', null, null, 'Reset “' . $tp['title'] . '” page to defaults (version ' . $v . ')');
        it_flash('Page reset to default. You can undo this from Saved versions.');
    } elseif ($act === 'restore') {
        $st = $conn->prepare("SELECT settings FROM it_settings_history WHERE page_key = ? AND version = ?");
        $st->execute([$target, (int)$_POST['version']]);
        $old = json_decode((string)$st->fetchColumn(), true);
        if (is_array($old)) {
            $v = it_save_settings($conn, $target, $old);
            it_log($conn, 'settings', null, null, 'Restored “' . $tp['title'] . '” page to version ' . (int)$_POST['version'] . ' (now version ' . $v . ')');
            it_flash('Version ' . (int)$_POST['version'] . ' restored.');
        }
    } else {
        $in = $_POST;
        $s = $defaults;
        $s['title'] = mb_substr(trim((string)($in['title'] ?? '')), 0, 60) ?: $defaults['title'];
        $s['subtitle'] = mb_substr(trim((string)($in['subtitle'] ?? '')), 0, 160);
        $s['icon'] = in_array($in['icon'] ?? '', it_icon_choices(), true) ? $in['icon'] : $defaults['icon'];
        $s['accent'] = it_hex((string)($in['accent_custom'] ?? '') ?: (string)($in['accent'] ?? ''), $defaults['accent']);
        $s['in_menu'] = !empty($tp['locked']) || !empty($in['in_menu']);
        $s['view'] = ($in['view'] ?? '') === 'table' ? 'table' : 'cards';
        $s['per_page'] = in_array((int)($in['per_page'] ?? 0), [12, 24, 48, 96], true) ? (int)$in['per_page'] : 24;
        $s['sort'] = in_array($in['sort'] ?? '', ['updated_desc', 'title_asc', 'due_asc', 'created_desc'], true) ? $in['sort'] : 'updated_desc';
        $s['show_stats'] = !empty($in['show_stats']);
        if ($target === 'dashboard') {
            $w = [];
            foreach ((array)($in['widgets'] ?? []) as $k) if (isset(it_dashboard_widgets()[$k])) $w[] = ['key' => $k, 'on' => !empty($in['widget_on'][$k])];
            $s['widgets'] = $w ?: $defaults['widgets'];
        }
        $s['entities'] = [];
        foreach ($tp['entities'] as $ek) {
            $baseByKey = array_column($defs[$ek]['fields'], null, 'key');
            $e = ['label' => mb_substr(trim((string)($in['ent'][$ek]['label'] ?? '')), 0, 40), 'fields' => [], 'custom' => []];
            if ($e['label'] === $defs[$ek]['label']) $e['label'] = '';
            foreach ((array)($in['fld'][$ek] ?? []) as $key => $f) {
                if (!isset($baseByKey[$key])) continue;
                $b = $baseByKey[$key];
                $o = ['order' => (int)($f['order'] ?? 0)];
                $label = mb_substr(trim((string)($f['label'] ?? '')), 0, 60);
                if ($label !== '' && $label !== $b['label']) $o['label'] = $label;
                $o['hidden'] = !isset($f['show']);
                $o['required'] = isset($f['required']);
                $o['list'] = isset($f['list']);
                if ($b['type'] === 'select' && isset($f['options'])) {
                    $opts = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string)$f['options'])), 'strlen')));
                    if ($opts && $opts !== $b['options']) $o['options'] = array_slice($opts, 0, 60);
                }
                $e['fields'][$key] = $o;
            }
            foreach ((array)($in['cus'][$ek] ?? []) as $c) {
                $label = mb_substr(trim((string)($c['label'] ?? '')), 0, 60);
                $key = (string)($c['key'] ?? '');
                if ($label === '' || !preg_match('/^c_[a-z0-9]{3,12}$/', $key)) continue;
                $type = isset(it_field_types()[$c['type'] ?? '']) ? $c['type'] : 'text';
                $e['custom'][] = [
                    'key' => $key, 'label' => $label, 'type' => $type, 'order' => (int)($c['order'] ?? 9999),
                    'options' => $type === 'select' ? array_slice(array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string)($c['options'] ?? ''))), 'strlen'))), 0, 60) : [],
                    'required' => isset($c['required']), 'list' => isset($c['list']), 'hidden' => !isset($c['show']),
                ];
            }
            $s['entities'][$ek] = $e;
        }
        // Keep fields of custom definitions the browser did not send (none today, but never drop silently).
        $v = it_save_settings($conn, $target, $s);
        it_log($conn, 'settings', null, null, 'Customized the “' . $s['title'] . '” page (version ' . $v . ')');
        it_flash('Saved. “' . $s['title'] . '” now uses your settings.');
    }
    header('Location: customize.php?page=' . $target . '&tab=' . rawurlencode((string)($_POST['tab'] ?? '')));
    exit;
}

$st = $conn->prepare("SELECT h.version, h.saved_at, u.fullname, u.username FROM it_settings_history h LEFT JOIN `user` u ON u.id = h.saved_by WHERE h.page_key = ? ORDER BY h.version DESC LIMIT 12");
$st->execute([$target]);
$versions = $st->fetchAll();
$curVersion = $versions[0]['version'] ?? 0;

$pageKey = 'settings';
$pageTitle = 'Customize ' . $cur['title'];
include __DIR__ . '/includes/layout_header.php';
$crumbs = '<div class="crumbs"><a href="settings.php">Settings</a><i class="bi bi-chevron-right"></i><span>Customize</span><i class="bi bi-chevron-right"></i><a href="' . h($tp['file']) . '">' . h($cur['title']) . '</a></div>';
it_page_head(['icon' => 'bi-sliders', 'title' => 'Customize: ' . $cur['title'], 'subtitle' => 'Changes here apply to this page only.'], '<a class="btn btn-ghost" href="' . h($tp['file']) . '" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Open page</a>', $crumbs);
$accentNow = it_hex($cur['accent'] ?? '', '#6366f1');
$activeTab = in_array($_GET['tab'] ?? '', $tp['entities'], true) ? $_GET['tab'] : ($tp['entities'][0] ?? '');
?>
<form method="post" id="czForm" data-once>
<input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="page" value="<?= h($target) ?>">
<input type="hidden" name="act" value="save">
<input type="hidden" name="tab" id="czTab" value="<?= h($activeTab) ?>">

<div class="cz-layout">
<div class="grid">

  <div class="card card-pad rise">
    <h3 style="font-size:17px;margin-bottom:16px"><i class="bi bi-brush" style="color:var(--accent)"></i> Look</h3>
    <div class="form-grid">
      <div class="field"><label>Page title</label><input class="input" name="title" value="<?= h($cur['title']) ?>" maxlength="60" data-pv="title" required></div>
      <div class="field"><label>Subtitle</label><input class="input" name="subtitle" value="<?= h($cur['subtitle']) ?>" maxlength="160" data-pv="subtitle"></div>
      <div class="field full"><label>Colour</label>
        <div class="swatches">
          <?php foreach (it_accent_choices() as $c): ?>
            <label class="swatch" style="background:<?= $c ?>"><input type="radio" name="accent" value="<?= $c ?>" <?= $c === $accentNow ? 'checked' : '' ?> data-pv="accent"></label>
          <?php endforeach; ?>
          <label class="swatch" style="background:conic-gradient(red,yellow,lime,cyan,blue,magenta,red)" title="Any colour"><input type="color" name="accent_custom" value="<?= h($accentNow) ?>" data-pv="accent_custom" <?= in_array($accentNow, it_accent_choices(), true) ? 'disabled' : '' ?>></label>
        </div>
      </div>
      <div class="field full"><label>Icon</label>
        <div class="icon-pick">
          <?php foreach (it_icon_choices() as $ic): ?>
            <label title="<?= h($ic) ?>"><input type="radio" name="icon" value="<?= h($ic) ?>" <?= $ic === $cur['icon'] ? 'checked' : '' ?> data-pv="icon"><i class="bi <?= h($ic) ?>"></i></label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if (empty($tp['locked'])): ?>
      <div class="field full"><label class="switch"><input type="checkbox" name="in_menu" value="1" <?= !empty($cur['in_menu']) ? 'checked' : '' ?>><span class="track"></span><span>Show this page in the menu</span></label></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($tp['entities']): ?>
  <div class="card card-pad rise" style="--i:1">
    <h3 style="font-size:17px;margin-bottom:16px"><i class="bi bi-list-columns-reverse" style="color:var(--accent)"></i> List</h3>
    <div class="form-grid">
      <div class="field"><label>Default view</label><select class="input" name="view" data-pv="view"><option value="cards" <?= $cur['view'] === 'cards' ? 'selected' : '' ?>>Cards</option><option value="table" <?= $cur['view'] === 'table' ? 'selected' : '' ?>>Table</option></select><div class="help">Each person can still switch; their choice is remembered on their device.</div></div>
      <div class="field"><label>Items per page</label><select class="input" name="per_page"><?php foreach ([12, 24, 48, 96] as $n): ?><option <?= (int)$cur['per_page'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Default order</label><select class="input" name="sort">
        <?php foreach (['updated_desc' => 'Recently changed', 'title_asc' => 'Name A–Z', 'due_asc' => 'Due date (soonest first)', 'created_desc' => 'Newest added'] as $k => $l): ?><option value="<?= $k ?>" <?= $cur['sort'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label>&nbsp;</label><label class="switch"><input type="checkbox" name="show_stats" value="1" <?= !empty($cur['show_stats']) ? 'checked' : '' ?>><span class="track"></span><span>Show status counters</span></label></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($target === 'dashboard'): ?>
  <div class="card rise" style="--i:1">
    <div class="card-head"><i class="bi bi-grid-1x2"></i><h3>Dashboard blocks</h3></div>
    <p class="muted" style="padding:4px 22px 0;margin:0;font-size:13.5px">Turn blocks on or off and move them up or down.</p>
    <div id="widgetList" style="margin-top:10px">
      <?php foreach ($cur['widgets'] as $w): ?>
        <div class="field-row" data-move style="grid-template-columns:30px 1fr auto auto">
          <span class="handle"><i class="bi bi-grip-vertical"></i></span>
          <span><b><?= h(it_dashboard_widgets()[$w['key']] ?? $w['key']) ?></b><input type="hidden" name="widgets[]" value="<?= h($w['key']) ?>"></span>
          <label class="switch" title="Show"><input type="checkbox" name="widget_on[<?= h($w['key']) ?>]" value="1" <?= !empty($w['on']) ? 'checked' : '' ?>><span class="track"></span></label>
          <span class="moves"><button type="button" class="mini-btn" data-up><i class="bi bi-arrow-up"></i></button><button type="button" class="mini-btn" data-down><i class="bi bi-arrow-down"></i></button></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tp['entities']): ?>
  <div class="card rise" style="--i:2">
    <div class="card-head"><i class="bi bi-ui-checks"></i><h3>Fields</h3></div>
    <p class="muted" style="padding:4px 22px 0;margin:0;font-size:13.5px">Rename, hide, make required, choose what shows on the cards, and add your own fields. Fields with a <i class="bi bi-lock-fill"></i> are needed by the system and can only be renamed. Hiding a field never deletes what is already saved in it.</p>
    <?php if (count($tp['entities']) > 1): ?>
      <div class="tabs" style="margin:14px 22px 0">
        <?php foreach ($tp['entities'] as $ek): ?><a href="#" class="tab <?= $ek === $activeTab ? 'active' : '' ?>" data-cz-tab="<?= h($ek) ?>"><i class="bi <?= h($defs[$ek]['icon']) ?>"></i><?= h(it_entity_label($ek, $cur)) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($tp['entities'] as $ek): $fields = it_effective_fields($ek, $cur); $baseByKey = array_column($defs[$ek]['fields'], null, 'key'); ?>
      <div class="cz-ent" data-ent="<?= h($ek) ?>" <?= $ek === $activeTab ? '' : 'hidden' ?>>
        <div style="padding:14px 22px 6px"><div class="field" style="max-width:340px"><label>Tab / list name</label><input class="input" name="ent[<?= h($ek) ?>][label]" value="<?= h(it_entity_label($ek, $cur)) ?>" maxlength="40"></div></div>
        <div class="field-row fr-head"><span></span><span>Label</span><span>Show</span><span>Required</span><span>On card</span><span>Order</span></div>
        <div class="fields-list" data-fields="<?= h($ek) ?>">
          <?php foreach ($fields as $f):
              $isCustom = $f['custom'];
              $n = $isCustom ? 'cus[' . $ek . '][' . h($f['key']) . ']' : 'fld[' . $ek . '][' . h($f['key']) . ']';
              $typeLabel = $f['type'] === 'user' ? 'Employee' : ($f['type'] === 'company' ? 'Company' : ($f['type'] === 'secret' ? 'Encrypted' : ($f['type'] === 'link' ? 'Link to ' . $defs[$f['link']]['label'] : (it_field_types()[$f['type']] ?? $f['type'])))); ?>
            <div class="field-row <?= $f['hidden'] ? 'is-hidden' : '' ?>" data-move data-field <?= $f['key'] === $defs[$ek]['title_field'] ? 'data-title' : '' ?>>
              <span class="handle"><i class="bi <?= $f['locked'] ? 'bi-lock-fill' : 'bi-grip-vertical' ?>"></i></span>
              <span class="lbl-cell">
                <input class="input" name="<?= $n ?>[label]" value="<?= h($f['label']) ?>" maxlength="60" data-pv-label <?= $isCustom ? 'required' : '' ?>>
                <small><?= h($typeLabel) ?><?= !$isCustom && $f['label'] !== $f['base_label'] ? ' · was “' . h($f['base_label']) . '”' : '' ?><?= $isCustom ? ' · your field' : '' ?>
                  <?php if (!empty($f['sections'])): ?> · choices are the vault sections (managed on the Vault page)<?php elseif ($f['type'] === 'select'): ?> · <a href="#" data-opts style="color:var(--accent)">Edit choices</a><?php endif; ?>
                  <?php if ($isCustom): ?> · <a href="#" data-remove style="color:var(--red)">Remove</a><?php endif; ?></small>
                <?php if ($f['type'] === 'select' && empty($f['sections'])): ?><textarea class="input" name="<?= $n ?>[options]" rows="4" hidden placeholder="One choice per line" style="margin-top:6px;min-height:90px"><?= h(implode("\n", $f['options'])) ?></textarea><?php endif; ?>
                <?php if ($isCustom): ?><input type="hidden" name="<?= $n ?>[key]" value="<?= h($f['key']) ?>"><input type="hidden" name="<?= $n ?>[type]" value="<?= h($f['type']) ?>"><?php endif; ?>
              </span>
              <label class="switch" title="Show"><input type="checkbox" name="<?= $n ?>[show]" value="1" <?= !$f['hidden'] ? 'checked' : '' ?> <?= $f['locked'] ? 'disabled checked' : '' ?> data-show><span class="track"></span></label>
              <label class="switch" title="Required"><input type="checkbox" name="<?= $n ?>[required]" value="1" <?= $f['required'] ? 'checked' : '' ?> <?= $f['locked'] ? 'disabled' : '' ?>><span class="track"></span></label>
              <label class="switch" title="Show on cards / table"><input type="checkbox" name="<?= $n ?>[list]" value="1" <?= $f['list'] ? 'checked' : '' ?> data-pv-list><span class="track"></span></label>
              <span class="moves"><button type="button" class="mini-btn" data-up title="Move up"><i class="bi bi-arrow-up"></i></button><button type="button" class="mini-btn" data-down title="Move down"><i class="bi bi-arrow-down"></i></button><input type="hidden" name="<?= $n ?>[order]" value="<?= (int)$f['order'] ?>" data-order></span>
              <?php if ($f['locked']): ?><input type="hidden" name="<?= $n ?>[show]" value="1"><?php if ($f['required']): ?><input type="hidden" name="<?= $n ?>[required]" value="1"><?php endif; ?><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="padding:16px 22px 20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <select class="input" style="width:auto" data-newtype><?php foreach (it_field_types() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
          <button type="button" class="btn btn-soft" data-add-field="<?= h($ek) ?>"><i class="bi bi-plus-lg"></i> Add my own field</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="form-actions sticky-actions">
    <a href="settings.php" class="btn btn-ghost">Back</a>
    <button type="button" class="btn btn-ghost" id="czReset"><i class="bi bi-arrow-counterclockwise"></i> Reset to default</button>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save this page</button>
  </div>
</div>

<div class="cz-preview grid">
  <div class="card card-pad">
    <h3 style="font-size:15px;margin-bottom:12px"><i class="bi bi-eye" style="color:var(--accent)"></i> Live preview</h3>
    <div class="preview-frame" id="pv" style="--accent:<?= h($accentNow) ?>">
      <div class="page-head" style="margin:0 0 14px;animation:none">
        <span class="page-icon" style="width:44px;height:44px;font-size:20px"><i class="bi <?= h($cur['icon']) ?>" id="pvIcon"></i></span>
        <div class="grow" style="min-width:0"><h1 style="font-size:18px" id="pvTitle"><?= h($cur['title']) ?></h1><p style="font-size:12.5px" id="pvSub"><?= h($cur['subtitle']) ?></p></div>
      </div>
      <?php if ($tp['entities']): ?>
        <div class="card rec-card" style="cursor:default">
          <div class="rec-top"><span class="rec-ico"><i class="bi <?= h($defs[$activeTab]['icon']) ?>"></i></span><div class="rec-title"><strong>Sample <?= h(strtolower($defs[$activeTab]['singular'])) ?></strong><small>Example</small></div><span class="badge tone-green">Active</span></div>
          <div class="rec-fields" id="pvFields"></div>
          <button type="button" class="btn btn-primary btn-sm" style="align-self:flex-start;pointer-events:none"><i class="bi bi-plus-lg"></i> Add</button>
        </div>
      <?php else: ?>
        <div class="card card-pad muted" style="font-size:13px">The page content shows here.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><i class="bi bi-clock-history"></i><h3>Saved versions</h3></div>
    <?php if (!$versions): ?><p class="muted" style="padding:6px 22px 18px;margin:0;font-size:13.5px">This page uses the default settings. Every save is kept here so you can go back.</p>
    <?php else: ?><div class="list" style="margin-top:6px">
      <?php foreach ($versions as $v): ?>
        <div class="list-item"><span class="li-main"><strong>Version <?= (int)$v['version'] ?><?= (int)$v['version'] === (int)$curVersion ? ' <span class="badge tone-green">current</span>' : '' ?></strong><small><?= h(date('d M Y, H:i', strtotime($v['saved_at']))) ?> · <?= h($v['fullname'] ?: $v['username'] ?: '—') ?></small></span>
          <?php if ((int)$v['version'] !== (int)$curVersion): ?><button type="button" class="btn btn-sm btn-ghost" data-restore-version="<?= (int)$v['version'] ?>">Restore</button><?php endif; ?></div>
      <?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</div>
</div>
</form>

<form method="post" id="czAlt" hidden><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="page" value="<?= h($target) ?>"><input type="hidden" name="act" value=""><input type="hidden" name="version" value=""></form>

<template id="customRow">
  <div class="field-row" data-move data-field>
    <span class="handle"><i class="bi bi-grip-vertical"></i></span>
    <span class="lbl-cell">
      <input class="input" name="__N__[label]" placeholder="Field name, e.g. RAM size" maxlength="60" data-pv-label required>
      <small><span data-typename></span> · your field · <a href="#" data-remove style="color:var(--red)">Remove</a></small>
      <textarea class="input" name="__N__[options]" rows="4" placeholder="One choice per line" style="margin-top:6px;min-height:90px" hidden></textarea>
      <input type="hidden" name="__N__[key]" value="__K__"><input type="hidden" name="__N__[type]" value="__T__">
    </span>
    <label class="switch" title="Show"><input type="checkbox" name="__N__[show]" value="1" checked data-show><span class="track"></span></label>
    <label class="switch" title="Required"><input type="checkbox" name="__N__[required]" value="1"><span class="track"></span></label>
    <label class="switch" title="Show on cards / table"><input type="checkbox" name="__N__[list]" value="1" data-pv-list><span class="track"></span></label>
    <span class="moves"><button type="button" class="mini-btn" data-up><i class="bi bi-arrow-up"></i></button><button type="button" class="mini-btn" data-down><i class="bi bi-arrow-down"></i></button><input type="hidden" name="__N__[order]" value="0" data-order></span>
  </div>
</template>

<?php
$types = json_encode(it_field_types());
$pageScripts = <<<JS
<script>
(function () {
  const form = document.getElementById('czForm');
  const pv = document.getElementById('pv');
  const el = (n) => form.elements[n];
  const types = $types;
  let dirty = false;
  form.addEventListener('input', () => { dirty = true; paint(); });
  form.addEventListener('change', () => { dirty = true; paint(); });
  form.addEventListener('submit', () => { dirty = false; renumber(); });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  function renumber() {
    document.querySelectorAll('[data-fields]').forEach(list => {
      list.querySelectorAll('[data-order]').forEach((o, i) => { o.value = i * 10; });
    });
  }

  // Up / down (works the same on phones; no fiddly dragging)
  form.addEventListener('click', (e) => {
    const up = e.target.closest('[data-up]'), down = e.target.closest('[data-down]');
    if (up || down) {
      const row = e.target.closest('[data-move]');
      if (up && row.previousElementSibling && row.previousElementSibling.matches('[data-move]')) row.parentNode.insertBefore(row, row.previousElementSibling);
      if (down && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
      row.animate([{ background: 'color-mix(in srgb, var(--accent) 15%, transparent)' }, { background: 'transparent' }], 600);
      renumber(); dirty = true; paint();
    }
    const opts = e.target.closest('[data-opts]');
    if (opts) { e.preventDefault(); const t = opts.closest('.lbl-cell').querySelector('textarea'); t.hidden = !t.hidden; if (!t.hidden) t.focus(); }
    const rm = e.target.closest('[data-remove]');
    if (rm) {
      e.preventDefault();
      IT.confirm({ title: 'Remove this field?', text: 'It disappears from the forms. Values already typed into it stay saved, and Restore under Saved versions brings it back.', ok: 'Remove' })
        .then(ok => { if (ok) { rm.closest('[data-field]').remove(); dirty = true; paint(); } });
    }
    const tab = e.target.closest('[data-cz-tab]');
    if (tab) {
      e.preventDefault();
      document.querySelectorAll('[data-cz-tab]').forEach(t => t.classList.toggle('active', t === tab));
      document.querySelectorAll('.cz-ent').forEach(x => x.hidden = x.dataset.ent !== tab.dataset.czTab);
      document.getElementById('czTab').value = tab.dataset.czTab;
      paint();
    }
    const add = e.target.closest('[data-add-field]');
    if (add) {
      const ent = add.dataset.addField;
      const type = add.parentElement.querySelector('[data-newtype]').value;
      const key = 'c_' + Math.random().toString(36).slice(2, 9).replace(/[^a-z0-9]/g, 'x');
      const html = document.getElementById('customRow').innerHTML.replaceAll('__N__', 'cus[' + ent + '][' + key + ']').replaceAll('__K__', key).replaceAll('__T__', type);
      const list = document.querySelector('[data-fields="' + ent + '"]');
      list.insertAdjacentHTML('beforeend', html);
      const row = list.lastElementChild;
      row.querySelector('[data-typename]').textContent = types[type];
      if (type === 'select') row.querySelector('textarea').hidden = false;
      row.querySelector('input').focus();
      row.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], 300);
      renumber(); dirty = true; paint();
    }
    const rv = e.target.closest('[data-restore-version]');
    if (rv) {
      IT.confirm({ title: 'Restore version ' + rv.dataset.restoreVersion + '?', text: 'The current settings are kept as a version too, so you can switch back.', ok: 'Restore', danger: false })
        .then(ok => { if (!ok) return; const f = document.getElementById('czAlt'); f.act.value = 'restore'; f.version.value = rv.dataset.restoreVersion; dirty = false; f.submit(); });
    }
  });
  document.getElementById('czReset').onclick = () => {
    IT.confirm({ title: 'Reset this page to default?', text: 'Titles, colours and fields go back to how they came. Your current settings are kept under Saved versions.', ok: 'Reset' })
      .then(ok => { if (!ok) return; const f = document.getElementById('czAlt'); f.act.value = 'reset'; dirty = false; f.submit(); });
  };

  // Colour: a preset or the colour wheel
  form.querySelectorAll('input[name=accent]').forEach(r => r.addEventListener('change', () => { el('accent_custom').disabled = true; }));
  el('accent_custom').closest('label').addEventListener('click', () => { el('accent_custom').disabled = false; form.querySelectorAll('input[name=accent]').forEach(r => r.checked = false); });

  // Live preview
  function paint() {
    const acc = (!el('accent_custom').disabled && el('accent_custom').value) || (form.querySelector('input[name=accent]:checked') || {}).value;
    if (acc) { pv.style.setProperty('--accent', acc); document.documentElement.style.setProperty('--accent', acc); }
    document.getElementById('pvTitle').textContent = el('title').value || '—';
    document.getElementById('pvSub').textContent = el('subtitle').value;
    const ic = form.querySelector('input[name=icon]:checked');
    if (ic) document.getElementById('pvIcon').className = 'bi ' + ic.value;
    document.querySelectorAll('.field-row[data-field]').forEach(r => { const s = r.querySelector('[data-show]'); if (s) r.classList.toggle('is-hidden', !s.checked); });
    const box = document.getElementById('pvFields');
    if (!box) return;
    const ent = document.querySelector('.cz-ent:not([hidden])');
    const rows = Array.from(ent.querySelectorAll('.field-row[data-field]')).filter(r => !r.hasAttribute('data-title') && r.querySelector('[data-show]').checked && r.querySelector('[data-pv-list]').checked).slice(0, 6);
    box.innerHTML = '';
    rows.forEach(r => { const d = document.createElement('div'); d.innerHTML = '<span></span><b>Sample</b>'; d.querySelector('span').textContent = r.querySelector('[data-pv-label]').value || 'New field'; box.appendChild(d); });
  }
  renumber(); paint();
})();
</script>
JS;
include __DIR__ . '/includes/layout_footer.php';
