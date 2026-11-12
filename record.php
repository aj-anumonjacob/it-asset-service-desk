<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();

$id = (int)($_GET['id'] ?? 0);
$rec = it_record($conn, $id, true);
if (!$rec) { it_flash('That item was not found.', 'error'); header('Location: dashboard.php'); exit; }
$entity = $rec['entity'];
$defs = it_entities();
$def = $defs[$entity];
if ($entity === 'vault' && !it_can('vault')) { header('Location: dashboard.php'); exit; }
$pageKey = it_entity_page($entity);
$ps = it_page($pageKey);
$fields = array_values(array_filter(it_effective_fields($entity, $ps), fn($f) => !$f['hidden']));
$titles = it_row_titles($conn, [$rec], $fields);
$deleted = !empty($rec['deleted_at']);

// What points at this record (cameras on an NVR, tasks for a device, devices using a login…)
$related = [];
foreach ($defs as $ek => $ed) {
    foreach ($ed['fields'] as $f) {
        if ($f['type'] !== 'link' || $f['link'] !== $entity) continue;
        if ($ek === 'vault' && !it_can('vault')) continue;
        $st = $conn->prepare("SELECT id, title, status, entity, due_date FROM it_records WHERE entity = ? AND deleted_at IS NULL AND data LIKE ? ORDER BY title");
        $st->execute([$ek, '%"' . $f['key'] . '":"' . $id . '"%']);
        foreach ($st->fetchAll() as $r) $related[$ek][$r['id']] = $r;
    }
}

$history = [];
if (!empty($def['secret_field']) && it_can('vault')) {
    $st = $conn->prepare("SELECT h.id, h.changed_at, u.fullname, u.username FROM it_secret_history h LEFT JOIN `user` u ON u.id = h.changed_by WHERE h.record_id = ? ORDER BY h.id DESC LIMIT 20");
    $st->execute([$id]);
    $history = $st->fetchAll();
}
$logs = [];
if ($entity === 'maintenance') {
    $st = $conn->prepare("SELECT l.*, u.fullname, u.username FROM it_maintenance_logs l LEFT JOIN `user` u ON u.id = l.done_by WHERE l.record_id = ? ORDER BY l.done_at DESC LIMIT 50");
    $st->execute([$id]);
    $logs = $st->fetchAll();
}
$st = $conn->prepare("SELECT a.*, u.fullname, u.username FROM it_activity a LEFT JOIN `user` u ON u.id = a.user_id WHERE a.record_id = ? ORDER BY a.id DESC LIMIT 15");
$st->execute([$id]);
$acts = $st->fetchAll();

$pageTitle = $rec['title'];
include __DIR__ . '/includes/layout_header.php';

$actions = '';
if (!$deleted && it_can('edit')) {
    if ($entity === 'maintenance' && ($rec['status'] ?? '') !== 'Completed') $actions .= '<button class="btn btn-success" type="button" data-done="' . $id . '"><i class="bi bi-check2-circle"></i> Mark done</button>';
    if (!empty($def['qr'])) $actions .= '<a class="btn btn-ghost" href="label.php?id=' . $id . '" title="Print QR label"><i class="bi bi-qr-code"></i></a>';
    if (empty($def['library'])) $actions .= '<a class="btn btn-ghost" href="edit.php?duplicate=' . $id . '" title="Duplicate"><i class="bi bi-copy"></i></a>';
    $actions .= '<button class="btn btn-ghost" type="button" data-delete="' . $id . '" data-name="' . h($rec['title']) . '" data-after="' . h(it_pages()[$pageKey]['file']) . '" title="Delete"><i class="bi bi-trash3"></i></button>';
    $actions .= '<a class="btn btn-primary" href="edit.php?id=' . $id . '"><i class="bi bi-pencil"></i> Edit</a>';
}
$tabQ = count(it_pages()[$pageKey]['entities']) > 1 ? '?tab=' . $entity : '';
$crumbs = '<div class="crumbs"><a href="' . h(it_pages()[$pageKey]['file'] . $tabQ) . '">' . h($ps['title']) . '</a><i class="bi bi-chevron-right"></i><span>' . h(it_entity_label($entity, $ps)) . '</span></div>';
it_page_head(['icon' => it_category_icon($entity, $rec['data'][$def['category_field'] ?? ''] ?? null), 'title' => $rec['title'], 'subtitle' => ''], $actions, $crumbs);
?>
<?php if ($deleted): ?>
  <div class="alert alert-danger"><i class="bi bi-trash3"></i><span style="flex:1">This item is in the Recycle Bin.</span>
    <?php if (it_can('edit')): ?><button class="btn btn-sm btn-ghost" type="button" onclick="IT.post('restore',{id:<?= $id ?>}).then(r=>{if(r.ok){location.reload()}else{IT.toast(r.error,'error')}})"><i class="bi bi-arrow-counterclockwise"></i> Restore</button><?php endif; ?>
  </div>
<?php endif; ?>

<div class="split">
  <div class="grid">
    <?php if (!empty($def['library'])): $file = it_photos($conn, $id)[0] ?? null; ?>
      <div class="card rise lib-file">
        <?php if (!$file): ?>
          <div class="empty" style="padding:30px"><div class="e-ico"><i class="bi bi-file-earmark-x"></i></div><h3>No file</h3><p>This item has no file attached.</p></div>
        <?php else: [$fico, $fcol] = it_file_look($file['ext']); ?>
          <?php if ($file['kind'] === 'photo'): ?>
            <a class="lib-preview" href="file.php?id=<?= (int)$file['id'] ?>&amp;view=1" target="_blank" rel="noopener"><img src="photo.php?id=<?= (int)$file['id'] ?>" alt="<?= h($rec['title']) ?>"></a>
          <?php elseif ($file['ext'] === 'pdf'): ?>
            <iframe class="lib-pdf" src="file.php?id=<?= (int)$file['id'] ?>&amp;view=1" title="<?= h($rec['title']) ?>"></iframe>
          <?php else: ?>
            <div class="lib-bigicon" style="--c:<?= h($fcol) ?>"><i class="bi <?= h($fico) ?>"></i><b><?= h(strtoupper($file['ext'])) ?></b><small>No preview for this type — download to open it.</small></div>
          <?php endif; ?>
          <div class="lib-filebar">
            <span class="li-ico" style="--c:<?= h($fcol) ?>"><i class="bi <?= h($fico) ?>"></i></span>
            <span class="li-main"><strong><?= h($file['original_name']) ?></strong><small><?= h(strtoupper($file['ext'])) ?> · <?= h(it_file_size((int)$file['size_bytes'])) ?><?= $file['width'] ? ' · ' . (int)$file['width'] . '×' . (int)$file['height'] : '' ?> · added <?= h(date('d M Y', strtotime($file['created_at']))) ?></small></span>
            <?php if ($file['kind'] === 'photo' || $file['ext'] === 'pdf'): ?><a class="btn btn-sm btn-ghost" href="file.php?id=<?= (int)$file['id'] ?>&amp;view=1" target="_blank" rel="noopener"><i class="bi bi-arrows-fullscreen"></i><span class="hide-sm"> Open</span></a><?php endif; ?>
            <a class="btn btn-sm btn-primary" href="file.php?id=<?= (int)$file['id'] ?>"><i class="bi bi-download"></i> Download</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="card rise">
      <div class="card-head"><h3>Details</h3>
        <?php if ($rec['status']): ?><span class="badge tone-<?= it_tone($rec['status']) ?>"><?= h($rec['status']) ?></span><?php endif; ?>
        <?php if (!empty($def['due_field']) && $rec['due_date']) echo it_due_chip($rec['due_date']); ?>
      </div>
      <div class="details" style="margin-top:8px">
        <?php if ($rec['ref_code'] && $entity !== 'asset'): ?><div class="d"><span>Reference</span><b class="mono"><?= h($rec['ref_code']) ?></b></div><?php endif; ?>
        <?php foreach ($fields as $f):
            if ($f['type'] === 'secret') {
                if (!it_can('vault')) continue;
                echo '<div class="d full"><span>' . h($f['label']) . (!empty($rec['secret_changed_at']) ? ' · changed ' . h(date('d M Y', strtotime($rec['secret_changed_at']))) : '') . '</span>';
                echo !empty($rec['secret_enc']) ? it_secret_html($id) : '<b class="faint">Not saved</b>';
                echo '</div>';
                continue;
            }
            $v = $rec['data'][$f['key']] ?? '';
            if ($f['key'] === $def['title_field']) continue;
            $full = $f['type'] === 'textarea';
            echo '<div class="d' . ($full ? ' full' : '') . '"><span>' . h($f['label']) . '</span>';
            if ($v === '' || $v === null) { echo '<b class="faint">—</b>'; }
            elseif ($f['type'] === 'link' && !empty($titles[(int)$v])) { echo '<b><a href="record.php?id=' . (int)$v . '" style="color:var(--accent)"><i class="bi ' . h($defs[$f['link']]['icon']) . '"></i> ' . h($titles[(int)$v]['title']) . '</a></b>'; }
            elseif ($f['type'] === 'url') { echo '<b><a href="' . h($v) . '" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">' . h($v) . ' <i class="bi bi-box-arrow-up-right"></i></a></b>'; }
            elseif ($f['type'] === 'email') { echo '<b><a href="mailto:' . h($v) . '" style="color:var(--accent)">' . h($v) . '</a></b>'; }
            elseif ($f['type'] === 'phone') { echo '<b><a href="tel:' . h(preg_replace('/[^0-9+]/', '', $v)) . '" style="color:var(--accent)">' . h($v) . '</a></b>'; }
            elseif ($f['key'] === 'checklist') { echo '<b class="pre">' . h($v) . '</b>'; }
            elseif ($full) { echo '<b class="pre">' . h($v) . '</b>'; }
            else { echo '<b' . (in_array($f['type'], ['ip'], true) || in_array($f['key'], ['serial', 'mac_address', 'asset_tag'], true) ? ' class="mono"' : '') . '>' . h(it_fmt($f, $v, $titles)) . '</b>'; }
            echo '</div>';
        endforeach; ?>
      </div>
    </div>

    <?php if (!empty($def['photos'])): $photos = it_photos($conn, $id); $canPhoto = it_can('edit') && !$deleted; ?>
      <div class="card rise" style="--i:1" id="photos" data-record="<?= $id ?>" data-max="<?= IT_PHOTO_MAX_COUNT ?>" data-photos="<?= count($photos) ?>">
        <div class="card-head"><i class="bi bi-images"></i><h3>Photos <span class="faint">(<?= count($photos) ?>/<?= IT_PHOTO_MAX_COUNT ?>)</span></h3>
          <?php if ($canPhoto && count($photos) < IT_PHOTO_MAX_COUNT): ?>
            <label class="btn btn-sm btn-soft only-touch"><i class="bi bi-camera"></i> Take photo<input type="file" accept="image/*" capture="environment" hidden data-photo-input></label>
            <label class="btn btn-sm btn-soft"><i class="bi bi-upload"></i> Add photos<input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" multiple hidden data-photo-input></label>
          <?php endif; ?>
        </div>
        <div class="photo-grid" data-dropzone>
          <?php foreach ($photos as $ph): ?>
            <div class="photo" data-photo="<?= (int)$ph['id'] ?>">
              <a href="photo.php?id=<?= (int)$ph['id'] ?>" data-lightbox><img src="photo.php?id=<?= (int)$ph['id'] ?>&amp;t=1" alt="<?= h($ph['original_name']) ?>" loading="lazy"></a>
              <?php if ($ph['is_cover']): ?><span class="photo-cover"><i class="bi bi-star-fill"></i> Cover</span><?php endif; ?>
              <?php if ($canPhoto): ?><div class="photo-actions">
                <?php if (!$ph['is_cover']): ?><button type="button" class="mini-btn" data-photo-cover title="Use as cover"><i class="bi bi-star"></i></button><?php endif; ?>
                <button type="button" class="mini-btn" data-photo-delete title="Delete photo"><i class="bi bi-trash3"></i></button>
              </div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$photos): ?>
            <div class="photo-empty"><i class="bi bi-camera"></i><span><?= $canPhoto ? 'No photos yet. Add the device, its label or serial sticker' . '<span class="hide-touch"> — or drop photos here</span>.' : 'No photos.' ?></span></div>
          <?php endif; ?>
        </div>
        <div class="photo-progress" hidden></div>
        <?php if ($canPhoto): ?><p class="faint" style="font-size:12.5px;margin:0;padding:0 22px 16px">JPG, PNG or WebP · up to <?= IT_PHOTO_MAX_BYTES / 1048576 ?> MB each · up to <?= IT_PHOTO_MAX_COUNT ?> photos. Big photos are shrunk automatically.</p><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($related as $ek => $rows): $ed = $defs[$ek]; ?>
      <div class="card rise" style="--i:2">
        <div class="card-head"><i class="bi <?= h($ed['icon']) ?>"></i><h3><?= h(it_entity_label($ek)) ?> <span class="faint">(<?= count($rows) ?>)</span></h3>
          <?php if (it_can('edit') && !$deleted):
              $lk = array_values(array_filter($ed['fields'], fn($f) => ($f['link'] ?? '') === $entity))[0]['key']; ?>
            <a class="btn btn-sm btn-soft" href="<?= !empty($ed['library']) ? 'library.php?asset=' . $id : 'edit.php?entity=' . h($ek) . '&' . h($lk) . '=' . $id ?>"><i class="bi bi-plus-lg"></i> Add</a>
          <?php endif; ?>
        </div>
        <div class="list" style="margin-top:8px">
          <?php foreach ($rows as $r): ?>
            <a class="list-item" href="record.php?id=<?= (int)$r['id'] ?>"><span class="li-ico"><i class="bi <?= h($ed['icon']) ?>"></i></span>
              <span class="li-main"><strong><?= h($r['title']) ?></strong><?php if ($r['due_date']): ?><small><?= h(it_due_text($r['due_date'])) ?></small><?php endif; ?></span>
              <?php if ($r['status']): ?><span class="badge tone-<?= it_tone($r['status']) ?>"><?= h($r['status']) ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!empty($def['children']) && empty($related[$def['children']]) && it_can('edit') && !$deleted): ?>
      <div class="card empty" style="padding:30px"><div class="e-ico"><i class="bi <?= h($defs[$def['children']]['icon']) ?>"></i></div><h3>No <?= h(strtolower($defs[$def['children']]['label'])) ?> on this <?= h(strtolower($def['singular'])) ?> yet</h3>
        <p></p><a class="btn btn-primary" href="edit.php?entity=<?= h($def['children']) ?>&<?= h($defs[$def['children']]['parent_field']) ?>=<?= $id ?>"><i class="bi bi-plus-lg"></i> Add <?= h(strtolower($defs[$def['children']]['singular'])) ?></a></div>
    <?php endif; ?>

    <?php if ($entity === 'maintenance'): ?>
      <div class="card rise" style="--i:3">
        <div class="card-head"><i class="bi bi-journal-check"></i><h3>Done history</h3></div>
        <?php if (!$logs): ?><p class="muted" style="padding:0 22px 18px">Not done yet. Press <b>Mark done</b> each time you finish this task.</p>
        <?php else: ?><div class="timeline">
          <?php foreach ($logs as $l): $cl = json_decode((string)$l['checklist'], true) ?: []; $ticked = count(array_filter($cl, fn($c) => !empty($c['done']))); ?>
            <div class="tl-item <?= $l['result'] !== 'ok' ? 'warn' : '' ?>">
              <strong><?= h(date('d M Y, H:i', strtotime($l['done_at']))) ?></strong> — <?= $l['result'] === 'ok' ? 'All good' : 'Problem found' ?>
              <small>By <?= h($l['fullname'] ?: $l['username'] ?: '—') ?><?= $cl ? " · checklist {$ticked}/" . count($cl) : '' ?><?= $l['due_next'] ? ' · next due ' . h(date('d M Y', strtotime($l['due_next']))) : '' ?></small>
              <?php if ($l['note']): ?><div style="margin-top:4px;white-space:pre-wrap"><?= h($l['note']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid">
    <?php if ($history): ?>
      <div class="card rise">
        <div class="card-head"><i class="bi bi-clock-history"></i><h3>Old passwords</h3></div>
        <p class="muted" style="padding:4px 22px 0;margin:0;font-size:13px">Kept so you can check what it used to be.</p>
        <div class="list" style="margin-top:6px">
          <?php foreach ($history as $hrow): ?>
            <div class="list-item" style="flex-wrap:wrap"><span class="li-main"><small>Replaced <?= h(date('d M Y, H:i', strtotime($hrow['changed_at']))) ?> by <?= h($hrow['fullname'] ?: $hrow['username'] ?: '—') ?></small><?= it_secret_html($id, true, (int)$hrow['id']) ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <div class="card rise" style="--i:1">
      <div class="card-head"><i class="bi bi-activity"></i><h3>Activity</h3></div>
      <div class="timeline">
        <?php foreach ($acts as $a): ?>
          <div class="tl-item"><strong><?= h(ucfirst(str_replace('_', ' ', $a['action']))) ?></strong><small><?= h($a['fullname'] ?: $a['username'] ?: 'System') ?> · <?= h(date('d M Y, H:i', strtotime($a['created_at']))) ?></small>
            <?php if ($a['summary'] && !in_array($a['action'], ['reveal', 'copy'], true)): ?><div class="muted" style="font-size:13px"><?= h($a['summary']) ?></div><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="tl-item"><strong>Added</strong><small><?= h(date('d M Y, H:i', strtotime($rec['created_at']))) ?></small></div>
      </div>
    </div>
  </div>
</div>
<?php
if ($entity === 'maintenance') include __DIR__ . '/includes/done_modal.php';
if (!empty($def['photos'])) include __DIR__ . '/includes/photo_upload.php';
include __DIR__ . '/includes/layout_footer.php';
