<?php
/**
 * Add / edit / duplicate any record. ?entity=asset (new), ?id=12 (edit), ?duplicate=12.
 * Prefill links with ?nvr=3, ?asset=7 etc.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
if (!it_can('edit')) { it_flash('You can view only — editing is not allowed for your account.', 'error'); header('Location: dashboard.php'); exit; }

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$dupOf = (int)($_GET['duplicate'] ?? 0);
$rec = $id ? it_record($conn, $id) : null;
$src = $dupOf ? it_record($conn, $dupOf) : null;
if ($id && !$rec) { it_flash('That item no longer exists (it may be in the Recycle Bin).', 'error'); header('Location: dashboard.php'); exit; }
$entity = $rec['entity'] ?? $src['entity'] ?? (string)($_GET['entity'] ?? $_POST['entity'] ?? '');
$defs = it_entities();
if (!isset($defs[$entity])) { header('Location: dashboard.php'); exit; }
if ($entity === 'vault' && !it_can('vault')) { header('Location: dashboard.php'); exit; }
$def = $defs[$entity];
if (!empty($def['library']) && !$rec) { it_flash('Upload the file here — it is added to the Library straight away.', 'info'); header('Location: library.php' . (isset($_GET['asset']) ? '?asset=' . (int)$_GET['asset'] : '')); exit; }
$pageKey = it_entity_page($entity);
$ps = it_page($pageKey);
$fields = array_values(array_filter(it_effective_fields($entity, $ps), fn($f) => !$f['hidden']));

// Starting values
$data = $rec['data'] ?? [];
if ($src) {
    $data = $src['data'];
    foreach ($def['fields'] as $f) if (!empty($f['unique'])) unset($data[$f['key']]); // serials etc. must not be copied
    $data[$def['title_field']] = ($data[$def['title_field']] ?? '') . ' (copy)';
}
if (!$rec && !$src) {
    foreach ($def['fields'] as $f) {
        if (isset($f['default'])) $data[$f['key']] = $f['default'];
        if (isset($_GET[$f['key']])) $data[$f['key']] = (string)$_GET[$f['key']];
    }
}

$errors = [];
$dupes = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!it_csrf_ok()) { $errors['_'] = 'The page expired. Please press Save again.'; }
    $in = (array)($_POST['f'] ?? []);
    $new = $rec['data'] ?? [];  // keeps values of fields that are hidden on this page
    foreach ($fields as $f) {
        if ($f['type'] === 'secret') continue;
        $v = trim((string)($in[$f['key']] ?? ''));
        if (is_array($in[$f['key']] ?? null)) $v = (string)end($in[$f['key']]);
        if ($f['type'] === 'yesno') $v = $v === '1' ? '1' : '0';
        if ($v === '' && $f['required']) { $errors[$f['key']] = 'Please fill this in.'; }
        elseif ($v !== '') {
            switch ($f['type']) {
                case 'email': if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$f['key']] = 'This does not look like an email address.'; break;
                case 'url':
                    if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
                    if (!filter_var($v, FILTER_VALIDATE_URL)) $errors[$f['key']] = 'This does not look like a website address.';
                    break;
                case 'ip': if (!filter_var(preg_replace('/:\d+$/', '', $v), FILTER_VALIDATE_IP)) $errors[$f['key']] = 'Use the form 192.168.1.20 (a :port at the end is fine).'; break;
                case 'number': $v = str_replace(',', '', $v); if (!is_numeric($v)) $errors[$f['key']] = 'Numbers only, please.'; break;
                case 'date': $d = DateTime::createFromFormat('Y-m-d', $v); if (!$d || $d->format('Y-m-d') !== $v) $errors[$f['key']] = 'Pick a valid date.'; break;
                case 'user':
                    if (!isset(it_staff_users($conn)[(int)$v]) && (int)$v !== (int)($rec['data'][$f['key']] ?? 0)) $errors[$f['key']] = 'Choose an employee from the list.';
                    $v = (string)(int)$v;
                    break;
                case 'company':
                    if (!isset(it_companies($conn)[(int)$v])) $errors[$f['key']] = 'Choose a company from the list.';
                    $v = (string)(int)$v;
                    break;
                case 'select':
                    // Only listed choices; a value saved before its choice was removed may stay.
                    if (!in_array($v, $f['options'], true) && $v !== (string)($rec['data'][$f['key']] ?? '')) $errors[$f['key']] = 'Pick one of the choices in the list.';
                    break;
                case 'link':
                    $v = (string)(int)$v;
                    if ($v === '0') { $v = ''; break; }
                    $t = it_titles($conn, [(int)$v]);
                    if (empty($t[(int)$v]) || $t[(int)$v]['entity'] !== $f['link']) $errors[$f['key']] = 'Choose an item from the list.';
                    break;
            }
        }
        $new[$f['key']] = $v;
    }
    $secretIn = (string)($_POST['secret'] ?? '');
    $sf = $def['secret_field'] ?? null;
    $secretField = $sf ? (array_values(array_filter($fields, fn($f) => $f['key'] === $sf))[0] ?? null) : null;
    if ($secretField && $secretField['required'] && $secretIn === '' && empty($rec['secret_enc'])) {
        $errors[$sf] = 'Please fill this in.';
    }

    // Duplicate check (serial, IP, MAC, asset tag, account no., username + URL)
    if (!$errors && empty($_POST['confirm_dup'])) {
        foreach ($def['fields'] as $f) {
            $val = trim((string)($new[$f['key']] ?? ''));
            if ($val === '' || (empty($f['unique']) && empty($f['unique_with']))) continue;
            $st = $conn->prepare("SELECT id, title, data FROM it_records WHERE entity = ? AND deleted_at IS NULL AND id <> ? AND data LIKE ?");
            $st->execute([$entity, $id, '%' . trim(json_encode($val), '"') . '%']);
            foreach ($st->fetchAll() as $o) {
                $od = json_decode($o['data'], true) ?: [];
                if (strcasecmp(trim((string)($od[$f['key']] ?? '')), $val) !== 0) continue;
                if (!empty($f['unique_with']) && strcasecmp(trim((string)($od[$f['unique_with']] ?? '')), trim((string)($new[$f['unique_with']] ?? ''))) !== 0) continue;
                $dupes[] = ['id' => $o['id'], 'title' => $o['title'], 'field' => $f['label'], 'value' => $val];
            }
            // The same value may not be used on another list either (e.g. a company email that is also a shared mailbox).
            foreach ($f['also_unique_in'] ?? [] as $otherEntity => $otherKey) {
                $st->execute([$otherEntity, 0, '%' . trim(json_encode($val), '"') . '%']);
                foreach ($st->fetchAll() as $o) {
                    $od = json_decode($o['data'], true) ?: [];
                    if (strcasecmp(trim((string)($od[$otherKey] ?? '')), $val) === 0) $dupes[] = ['id' => $o['id'], 'title' => $o['title'] . ' (' . $defs[$otherEntity]['singular'] . ')', 'field' => $f['label'], 'value' => $val];
                }
            }
        }
    }

    if (!$errors && !$dupes) {
        $title = trim((string)($new[$def['title_field']] ?? '')) ?: 'Untitled';
        $status = !empty($def['status_field']) ? (($new[$def['status_field']] ?? '') ?: null) : null;
        $due = !empty($def['due_field']) ? (($new[$def['due_field']] ?? '') ?: null) : null;
        $parent = !empty($def['parent_field']) ? ((int)($new[$def['parent_field']] ?? 0) ?: null) : null;
        $ref = $rec['ref_code'] ?? null;
        if (!empty($def['code_prefix'])) {
            if ($entity === 'asset') {
                if (trim((string)($new['asset_tag'] ?? '')) === '') $new['asset_tag'] = it_next_code($conn, $entity, $def['code_prefix']);
                $ref = $new['asset_tag'];
            } elseif (!$ref) {
                $ref = it_next_code($conn, $entity, $def['code_prefix']);
            }
        }
        $json = json_encode($new, JSON_UNESCAPED_UNICODE);
        $uid = it_user_id();

        $conn->beginTransaction();
        try {
            if ($rec) {
                $changed = [];
                foreach ($fields as $f) {
                    if ($f['type'] !== 'secret' && (string)($rec['data'][$f['key']] ?? '') !== (string)($new[$f['key']] ?? '')) $changed[] = $f['label'];
                }
                $conn->prepare("UPDATE it_records SET title=?, status=?, due_date=?, parent_id=?, ref_code=?, data=?, updated_by=?, updated_at=NOW() WHERE id=?")
                     ->execute([$title, $status, $due, $parent, $ref, $json, $uid, $id]);
                if ($secretIn !== '') {
                    $plainOld = it_decrypt($conn, $rec['secret_enc']);
                    if ($plainOld !== $secretIn) {
                        if (!empty($rec['secret_enc'])) {
                            $conn->prepare("INSERT INTO it_secret_history (record_id, secret_enc, changed_by, changed_at) VALUES (?,?,?,NOW())")->execute([$id, $rec['secret_enc'], $uid]);
                        }
                        $conn->prepare("UPDATE it_records SET secret_enc=?, secret_changed_at=NOW() WHERE id=?")->execute([it_encrypt($conn, $secretIn), $id]);
                        $changed[] = $secretField['label'] ?? 'Password';
                    }
                }
                it_log($conn, 'update', $entity, $id, $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved with no changes');
                $msg = 'Saved';
            } else {
                $conn->prepare("INSERT INTO it_records (entity, title, status, ref_code, parent_id, due_date, data, secret_enc, secret_changed_at, created_by, updated_by, created_at, updated_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                     ->execute([$entity, $title, $status, $ref, $parent, $due, $json, $secretIn !== '' ? it_encrypt($conn, $secretIn) : null, $secretIn !== '' ? date('Y-m-d H:i:s') : null, $uid, $uid]);
                $id = (int)$conn->lastInsertId();
                it_log($conn, 'create', $entity, $id, 'Added ' . $def['singular'] . ' “' . $title . '”' . ($src ? ' (copy of #' . $src['id'] . ')' : ''));
                $msg = $def['singular'] . ' added' . (!empty($def['photos']) ? ' — add photos below' : '');
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            error_log('it edit: ' . $e->getMessage());
            $errors['_'] = 'Could not save: ' . $e->getMessage();
        }
        if (!$errors) {
            it_flash($msg);
            if (!empty($_POST['again'])) {
                $keep = $parent && !empty($def['parent_field']) ? '&' . $def['parent_field'] . '=' . $parent : '';
                header('Location: edit.php?entity=' . $entity . $keep);
            } else {
                header('Location: record.php?id=' . $id);
            }
            exit;
        }
    }
    $data = $new;
}

$linkOpts = [];
foreach ($fields as $f) if ($f['type'] === 'link') $linkOpts[$f['key']] = it_link_options($conn, $f['link']);
if ($rec) foreach ($linkOpts as $k => $o) unset($linkOpts[$k][$id]);

$pageTitle = ($rec ? 'Edit ' : 'Add ') . strtolower($def['singular']);
include __DIR__ . '/includes/layout_header.php';
$back = $rec ? 'record.php?id=' . $id : it_pages()[$pageKey]['file'] . (count(it_pages()[$pageKey]['entities']) > 1 ? '?tab=' . $entity : '');
$crumbs = '<div class="crumbs"><a href="' . h(it_pages()[$pageKey]['file']) . '">' . h($ps['title']) . '</a><i class="bi bi-chevron-right"></i><span>' . ($rec ? h($rec['title']) : 'New') . '</span></div>';
it_page_head(['icon' => $rec ? 'bi-pencil-square' : 'bi-plus-lg', 'title' => $pageTitle, 'subtitle' => ''], '', $crumbs);
?>
<form method="post" class="card card-pad rise" data-draft="<?= h($entity . '_' . ($id ?: 'new')) ?>" <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'data-no-restore="1"' : '' ?> novalidate>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="entity" value="<?= h($entity) ?>">
  <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

  <?php if (!empty($errors['_'])): ?><div class="alert alert-danger"><i class="bi bi-x-circle"></i><span><?= h($errors['_']) ?></span></div><?php endif; ?>
  <?php if (count(array_diff_key($errors, ['_' => 1]))): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><span>Please check the fields marked in red.</span></div><?php endif; ?>
  <?php if ($dupes): ?>
    <div class="alert alert-warn"><i class="bi bi-files"></i><div style="flex:1">
      <strong>This looks like something you already have:</strong>
      <ul style="margin:6px 0 10px;padding-left:18px">
        <?php foreach ($dupes as $d): ?><li><?= h($d['field']) ?> “<?= h($d['value']) ?>” is on <a href="record.php?id=<?= (int)$d['id'] ?>" target="_blank" style="text-decoration:underline"><?= h($d['title']) ?></a></li><?php endforeach; ?>
      </ul>
      <label class="switch"><input type="checkbox" name="confirm_dup" value="1"><span class="track"></span><span>It is different — save anyway</span></label>
    </div></div>
  <?php endif; ?>

  <div class="form-grid">
    <?php foreach ($fields as $f): ?>
      <?= it_input($f, $data[$f['key']] ?? '', $linkOpts[$f['key']] ?? [], $errors[$f['key']] ?? null, !empty($rec['secret_enc'])) ?>
    <?php endforeach; ?>
  </div>

  <div class="form-actions sticky-actions">
    <a href="<?= h($back) ?>" class="btn btn-ghost">Cancel</a>
    <?php if (!$rec): ?><button type="submit" name="again" value="1" class="btn btn-soft"><i class="bi bi-plus-lg"></i> Save & add another</button><?php endif; ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> <?= $rec ? 'Save changes' : 'Save' ?></button>
  </div>
</form>
<script>
// Browser checks first, so a missing field is pointed at before the page reloads.
document.querySelector('form[data-draft]').addEventListener('submit', function (e) {
  const bad = Array.from(this.querySelectorAll('[required]')).find(el => !String(el.value).trim());
  if (bad) {
    e.preventDefault();
    bad.closest('.field').classList.add('has-error');
    (bad.type === 'hidden' ? bad.parentElement.querySelector('.dp-display') || bad : bad.hasAttribute('data-combo') ? bad.parentElement.querySelector('.combo-display') : bad).focus();
    IT.toast('Please fill in “' + bad.closest('.field').querySelector('label').textContent.replace('*', '').trim() + '”', 'warn');
  }
});
document.querySelectorAll('.field .input').forEach(el => el.addEventListener('input', () => el.closest('.field').classList.remove('has-error')));
</script>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
