<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
$pageKey = 'recycle';
$ps = it_page($pageKey);
$defs = it_entities();
$rows = $conn->query("SELECT r.id, r.entity, r.title, r.deleted_at, u.fullname, u.username FROM it_records r LEFT JOIN `user` u ON u.id = r.deleted_by WHERE r.deleted_at IS NOT NULL" . (it_can('vault') ? '' : " AND r.entity <> 'vault'") . " ORDER BY r.deleted_at DESC LIMIT 500")->fetchAll();

include __DIR__ . '/includes/layout_header.php';
it_page_head($ps, it_can('settings') ? '<a class="btn btn-ghost" href="customize.php?page=recycle"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>' : '');
?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i><span>Nothing is lost when you delete. Items wait here until you restore them<?= it_can('purge') ? ' or delete them forever' : '' ?>.</span></div>
<div class="card rise">
  <?php if (!$rows): ?>
    <div class="empty"><div class="e-ico"><i class="bi bi-trash3"></i></div><h3>Recycle Bin is empty</h3><p>Deleted items will show here.</p></div>
  <?php else: ?><div class="list">
    <?php foreach ($rows as $r): $d = $defs[$r['entity']] ?? ['icon' => 'bi-box', 'singular' => $r['entity']]; ?>
      <div class="list-item" data-row style="flex-wrap:wrap">
        <span class="li-ico"><i class="bi <?= h($d['icon']) ?>"></i></span>
        <span class="li-main"><strong><a href="record.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></strong><small><?= h($d['singular']) ?> · deleted <?= h(date('d M Y, H:i', strtotime($r['deleted_at']))) ?> by <?= h($r['fullname'] ?: $r['username'] ?: '—') ?></small></span>
        <?php if (it_can('edit')): ?><button class="btn btn-sm btn-soft" type="button" data-restore="<?= (int)$r['id'] ?>"><i class="bi bi-arrow-counterclockwise"></i> Restore</button><?php endif; ?>
        <?php if (it_can('purge')): ?><button class="btn btn-sm btn-ghost" type="button" data-purge="<?= (int)$r['id'] ?>" data-name="<?= h($r['title']) ?>" title="Delete forever"><i class="bi bi-x-octagon" style="color:var(--red)"></i></button><?php endif; ?>
      </div>
    <?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php
$pageScripts = <<<'JS'
<script>
document.addEventListener('click', async (e) => {
  const r = e.target.closest('[data-restore]'), p = e.target.closest('[data-purge]');
  if (r) {
    const res = await IT.post('restore', { id: r.dataset.restore });
    if (res.ok) { r.closest('[data-row]').remove(); IT.toast('Restored', 'success'); } else IT.toast(res.error, 'error');
  }
  if (p) {
    const ok = await IT.confirm({ title: 'Delete forever?', text: '“' + p.dataset.name + '” and its history will be gone for good. This cannot be undone.', ok: 'Delete forever', typeWord: 'DELETE' });
    if (!ok) return;
    const res = await IT.post('purge', { id: p.dataset.purge });
    if (res.ok) { p.closest('[data-row]').remove(); IT.toast('Deleted forever', 'info'); } else IT.toast(res.error, 'error');
  }
});
</script>
JS;
include __DIR__ . '/includes/layout_footer.php';
