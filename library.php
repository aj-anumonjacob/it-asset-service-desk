<?php
/**
 * Library: reference photos and documents (manuals, diagrams, rack photos,
 * warranties…) in folders. Each upload becomes one Library item; title,
 * folder, description, device and tags are edited on its page.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
require_once __DIR__ . '/includes/sections_ui.php';
it_require_access();
$pageKey = 'library';
$ps = it_page($pageKey);

$current = it_section($conn, 'doc', $_GET['section'] ?? '');
$kind = in_array($_GET['kind'] ?? '', ['photo', 'doc'], true) ? $_GET['kind'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$assetId = (int)($_GET['asset'] ?? 0);
$asset = $assetId ? it_record($conn, $assetId) : null;
if ($asset && $asset['entity'] !== 'asset') $asset = null;

$where = ["r.entity = 'doc'", 'r.deleted_at IS NULL'];
$args = [];
if ($current) { $where[] = "JSON_UNQUOTE(JSON_EXTRACT(r.data, '$.category')) = ?"; $args[] = $current['name']; }
if ($kind) { $where[] = 'f.kind = ?'; $args[] = $kind; }
if ($asset) { $where[] = "JSON_UNQUOTE(JSON_EXTRACT(r.data, '$.asset')) = ?"; $args[] = (string)$asset['id']; }
if ($q !== '') { $where[] = '(r.title LIKE ? OR r.data LIKE ? OR f.original_name LIKE ?)'; array_push($args, "%$q%", '%' . trim(json_encode($q), '"') . '%', "%$q%"); }
$w = implode(' AND ', $where);
$per = max(12, (int)($ps['per_page'] ?? 24));
$page = max(1, (int)($_GET['p'] ?? 1));
$st = $conn->prepare("SELECT COUNT(*) FROM it_records r LEFT JOIN it_files f ON f.record_id = r.id WHERE $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$order = ($ps['sort'] ?? '') === 'title_asc' ? 'r.title ASC' : (($ps['sort'] ?? '') === 'created_desc' ? 'r.id DESC' : 'r.updated_at DESC, r.id DESC');
$st = $conn->prepare("SELECT r.id, r.title, r.data, r.updated_at, f.id fid, f.kind, f.ext, f.size_bytes FROM it_records r LEFT JOIN it_files f ON f.record_id = r.id WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$items = $st->fetchAll();
$kinds = $conn->query("SELECT f.kind, COUNT(*) FROM it_records r JOIN it_files f ON f.record_id = r.id WHERE r.entity = 'doc' AND r.deleted_at IS NULL GROUP BY f.kind")->fetchAll(PDO::FETCH_KEY_PAIR);
$canEdit = it_can('edit');
$sections = it_sections($conn, 'doc');
$keep = array_filter(['section' => $current['name'] ?? '', 'kind' => $kind, 'q' => $q, 'asset' => $asset ? $asset['id'] : ''], 'strlen');
$url = fn(array $a) => 'library.php?' . http_build_query(array_filter(array_merge($keep, $a), fn($v) => $v !== '' && $v !== null));

include __DIR__ . '/includes/layout_header.php';
$actions = '';
if (it_can('settings')) $actions .= '<a class="btn btn-ghost" href="customize.php?page=library"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>';
if ($canEdit) $actions .= '<label class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload<input type="file" multiple hidden data-lib-input accept="' . h(implode(',', array_map(fn($e) => '.' . $e, it_library_exts()))) . ',image/*"></label>';
it_page_head($ps, $actions);

echo it_section_bar('doc', $asset ? 'library.php?asset=' . $asset['id'] : 'library.php', $current, it_section_counts($conn, 'doc'), 'All files', 'folder');
?>
<?php if ($asset): ?>
  <div class="alert alert-info"><i class="bi bi-link-45deg"></i><span style="flex:1">Showing files for <a href="record.php?id=<?= (int)$asset['id'] ?>" style="text-decoration:underline"><?= h($asset['title']) ?></a>. Files you upload now are linked to it.</span><a class="btn btn-sm btn-ghost" href="<?= h($url(['asset' => null])) ?>">Show all</a></div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="card dropzone rise" id="libDrop" data-asset="<?= $asset ? (int)$asset['id'] : '' ?>" data-exts="<?= h(implode(',', it_library_exts())) ?>"
     data-doc-max="<?= IT_DOC_MAX_BYTES ?>" data-photo-side="<?= IT_LIBRARY_PHOTO_SIZE ?>">
  <i class="bi bi-cloud-arrow-up dz-ico"></i>
  <div class="dz-text">
    <strong><span class="hide-touch">Drop photos or documents here</span><span class="only-touch-inline">Add photos or documents</span></strong>
    <small>PDF, Word, Excel, PowerPoint, TXT, CSV up to <?= IT_DOC_MAX_BYTES / 1048576 ?> MB · JPG / PNG photos (shrunk to <?= IT_LIBRARY_PHOTO_SIZE ?> px)</small>
  </div>
  <div class="dz-actions">
    <label class="dz-folder"><span>Save into</span>
      <select class="input" id="libFolder"><?php foreach ($sections as $s): ?><option <?= $current && $current['key'] === $s['key'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label class="btn btn-soft only-touch"><i class="bi bi-camera"></i> Take photo<input type="file" accept="image/*" capture="environment" hidden data-lib-input></label>
    <label class="btn btn-ghost"><i class="bi bi-folder2-open"></i> Choose files<input type="file" multiple hidden data-lib-input accept="<?= h(implode(',', array_map(fn($e) => '.' . $e, it_library_exts()))) ?>,image/*"></label>
  </div>
  <div class="photo-progress" hidden style="grid-column:1/-1;padding:0"></div>
</div>
<?php endif; ?>

<div class="toolbar">
  <div class="chips" style="margin:0">
    <a class="chip <?= $kind === '' ? 'active' : '' ?>" href="<?= h($url(['kind' => null, 'p' => null])) ?>">All <b><?= array_sum($kinds) ?></b></a>
    <a class="chip <?= $kind === 'photo' ? 'active' : '' ?>" href="<?= h($url(['kind' => 'photo', 'p' => null])) ?>"><i class="bi bi-image"></i> Photos <b><?= (int)($kinds['photo'] ?? 0) ?></b></a>
    <a class="chip <?= $kind === 'doc' ? 'active' : '' ?>" href="<?= h($url(['kind' => 'doc', 'p' => null])) ?>"><i class="bi bi-file-earmark-text"></i> Documents <b><?= (int)($kinds['doc'] ?? 0) ?></b></a>
  </div>
  <form class="search" method="get">
    <?php foreach ($keep as $k => $v) if ($k !== 'q') echo '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; ?>
    <i class="bi bi-search"></i><input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Search titles, descriptions, tags, file names…">
  </form>
</div>

<?php if (!$items): ?>
  <div class="card empty"><div class="e-ico"><i class="bi bi-folder2-open"></i></div>
    <h3><?= $q !== '' || $kind ? 'Nothing matches' : 'Nothing here yet' ?></h3>
    <p><?= $q !== '' || $kind ? 'Try another word or clear the filter.' : 'Upload manuals, network diagrams, rack and wiring photos, warranty cards — anything that helps on the job.' ?></p>
  </div>
<?php else: ?>
  <div class="lib-grid">
    <?php foreach ($items as $i => $it):
        $d = json_decode($it['data'], true) ?: [];
        $sec = it_section($conn, 'doc', $d['category'] ?? '');
        [$ico, $col] = it_file_look((string)$it['ext']); ?>
      <a class="lib-tile rise" style="--i:<?= min($i, 14) ?>;--c:<?= h($col) ?>" href="record.php?id=<?= (int)$it['id'] ?>">
        <span class="lib-thumb">
          <?php if ($it['kind'] === 'photo'): ?><img src="photo.php?id=<?= (int)$it['fid'] ?>&amp;t=1" alt="" loading="lazy">
          <?php else: ?><i class="bi <?= h($ico) ?>"></i><b><?= h(strtoupper((string)$it['ext'])) ?></b><?php endif; ?>
        </span>
        <span class="lib-meta">
          <strong><?= h($it['title']) ?></strong>
          <small><?php if ($sec): ?><i class="bi <?= h($sec['icon']) ?>" style="color:<?= h($sec['color']) ?>"></i> <?= h($sec['name']) ?> · <?php endif; ?><?= $it['size_bytes'] ? h(it_file_size((int)$it['size_bytes'])) : 'no file' ?></small>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif;
it_pager(['page' => $page, 'pages' => max(1, (int)ceil($total / $per))]);
it_section_dialog('doc', 'folder', 'Manuals, Network Diagrams, Rack Photos, Warranties', $asset ? 'library.php?asset=' . $asset['id'] : 'library.php');
include __DIR__ . '/includes/library_upload.php';
include __DIR__ . '/includes/layout_footer.php';
