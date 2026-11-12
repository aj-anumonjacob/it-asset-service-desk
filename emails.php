<?php
/**
 * Email Directory, company by company:
 *   company — official company addresses given to some employees (entity 'staffmail')
 *   staff   — the email on each staff login / HR record (read from the main system)
 *   shared  — shared mailboxes, groups and aliases (entity 'mailbox')
 * with PDF download and share-by-email.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
require_once __DIR__ . '/includes/email_directory.php';
it_require_access();
$pageKey = 'emails';
$ps = it_page($pageKey);
$companies = it_companies($conn);
// Record pages link back with the entity name as the tab.
$tab = ['staffmail' => 'company', 'mailbox' => 'shared'][$_GET['tab'] ?? ''] ?? ($_GET['tab'] ?? '');
$tab = in_array($tab, ['company', 'staff', 'shared'], true) ? $tab : 'company';
$cid = (int)($_GET['company'] ?? 0);
$cid = isset($companies[$cid]) ? $cid : null;
$missing = in_array($tab, ['company', 'staff'], true) && !empty($_GET['missing']);
$canEdit = it_can('edit');

$corpAll = it_company_emails($conn, null, false);
$staffAll = it_staff_emails($conn);
$sharedAll = it_shared_emails($conn, null, false);
$countOf = fn(array $groups, string $k) => array_map(fn($g) => count($g[$k]), $groups);
$tabCounts = ['company' => $countOf($corpAll, 'people'), 'staff' => $countOf($staffAll, 'people'), 'shared' => $countOf($sharedAll, 'boxes')];

// user id => [company id => company address], to mark who already has one
$hasCorp = [];
foreach ($corpAll as $gid => $g) foreach ($g['people'] as $p) $hasCorp[$p['user_id']][$gid] = $p['email'];

// Staff of each company (with or without a login email), for "without a company email".
$everyone = it_staff_emails($conn, null, false);
foreach (it_staff_emails($conn, null, true) as $gid => $g) {
    $everyone[$gid] ??= ['name' => $g['name'], 'people' => []];
    $everyone[$gid]['people'] = array_merge($everyone[$gid]['people'], $g['people']);
}
$withoutCorp = [];
foreach ($everyone as $gid => $g) {
    $list = array_values(array_filter($g['people'], fn($p) => !isset($hasCorp[(int)$p['id']][$gid])));
    usort($list, fn($a, $b) => strcasecmp($a['fullname'] ?: $a['username'], $b['fullname'] ?: $b['username']));
    if ($list) $withoutCorp[$gid] = ['name' => $g['name'], 'people' => $list];
}

if ($tab === 'company') {
    $groups = $missing ? $withoutCorp : $corpAll;
    $counts = $tabCounts['company'];
    $missingCount = array_sum(array_map(fn($g) => count($g['people']), $cid ? array_intersect_key($withoutCorp, [$cid => 1]) : $withoutCorp));
} elseif ($tab === 'staff') {
    $groups = $missing ? it_staff_emails($conn, null, true) : $staffAll;
    $counts = $tabCounts['staff'];
    $missingCount = array_sum(array_map(fn($g) => count($g['people']), it_staff_emails($conn, $cid, true)));
} else {
    $groups = $sharedAll;
    $counts = $tabCounts['shared'];
    $missingCount = 0;
}
if ($cid) $groups = array_intersect_key($groups, [$cid => 1]);
$url = fn(array $a) => 'emails.php?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'company' => $cid, 'missing' => $missing ? 1 : null], $a), fn($v) => $v !== null && $v !== ''));
$addUrl = fn(string $entity, array $pre = []) => 'edit.php?' . http_build_query(['entity' => $entity] + array_filter($pre));

include __DIR__ . '/includes/layout_header.php';
$actions = '';
if (it_can('settings')) $actions .= '<a class="btn btn-ghost" href="customize.php?page=emails" title="Customize"><i class="bi bi-sliders"></i></a>';
$actions .= '<a class="btn btn-ghost" href="email_pdf.php?type=' . $tab . '&amp;company=' . (int)$cid . '"><i class="bi bi-file-earmark-pdf"></i> PDF</a>';
if ($canEdit) $actions .= '<button type="button" class="btn btn-ghost" data-share><i class="bi bi-send"></i> Share by email</button>';
if ($canEdit && $tab === 'shared') $actions .= '<a class="btn btn-primary" href="' . h($addUrl('mailbox', ['company' => $cid])) . '"><i class="bi bi-plus-lg"></i> Add shared mailbox</a>';
if ($canEdit && $tab !== 'shared') $actions .= '<a class="btn btn-primary" href="' . h($addUrl('staffmail', ['company' => $cid])) . '"><i class="bi bi-plus-lg"></i> Add company email</a>';
it_page_head($ps, $actions);

$initials = fn(string $name) => strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim(preg_replace('/\b(L\.?L\.?C\.?)/i', '', $name))), 0, 2))));
$roleLine = fn(array $p) => trim(($p['position'] ?? '') . (($p['position'] ?? '') && ($p['department'] ?? '') ? ' · ' : '') . ($p['department'] ?? ''));
?>
<div class="tabs">
  <a class="tab <?= $tab === 'company' ? 'active' : '' ?>" href="<?= h($url(['tab' => 'company', 'missing' => null])) ?>"><i class="bi bi-person-badge"></i>Company emails<span class="count"><?= array_sum($tabCounts['company']) ?></span></a>
  <a class="tab <?= $tab === 'staff' ? 'active' : '' ?>" href="<?= h($url(['tab' => 'staff', 'missing' => null])) ?>"><i class="bi bi-people"></i>Staff login emails<span class="count"><?= array_sum($tabCounts['staff']) ?></span></a>
  <a class="tab <?= $tab === 'shared' ? 'active' : '' ?>" href="<?= h($url(['tab' => 'shared', 'missing' => null])) ?>"><i class="bi bi-envelope-at"></i>Shared mailboxes<span class="count"><?= array_sum($tabCounts['shared']) ?></span></a>
</div>

<div class="sections company-tiles">
  <a class="section-tile <?= $cid ? '' : 'active' ?>" href="<?= h($url(['company' => null])) ?>" style="--c:var(--accent)"><span class="st-ico"><i class="bi bi-buildings"></i></span><span class="st-name">All companies</span><span class="st-count"><?= array_sum($counts) ?></span></a>
  <?php foreach ($companies as $id => $name): ?>
    <a class="section-tile <?= $cid === $id ? 'active' : '' ?>" href="<?= h($url(['company' => $id])) ?>" style="--c:var(--accent)">
      <span class="st-ico" style="font-size:12px;font-weight:800"><?= h($initials($name)) ?></span>
      <span class="st-name"><?= h($name) ?></span><span class="st-count"><?= (int)($counts[$id] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <div class="search"><i class="bi bi-search"></i><input class="input" type="search" placeholder="Filter by name, email, department…" data-quickfilter="#dirList"></div>
  <?php if ($tab !== 'shared'): ?>
    <a class="chip <?= $missing ? 'active' : '' ?>" href="<?= h($url(['missing' => $missing ? null : 1])) ?>"><i class="bi bi-exclamation-circle"></i> <?= $tab === 'company' ? 'Without a company email' : 'No email yet' ?> <b><?= $missingCount ?></b></a>
  <?php endif; ?>
</div>

<?php if ($tab === 'company' && !$missing): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle"></i><span>Official addresses on the company's own domain, for the employees who have one. They are kept here only — the email on each person's login is not changed.</span></div>
<?php elseif ($missing && $tab === 'company'): ?>
  <div class="alert alert-warn"><i class="bi bi-person-plus"></i><span>Staff of this company with no company email. Not everyone needs one — press <b>Add</b> for those who do.</span></div>
<?php elseif ($missing): ?>
  <div class="alert alert-warn"><i class="bi bi-envelope-x"></i><span>These staff can sign in but have no email on their login or HR record. Add one in HR (employee record) or on their profile.</span></div>
<?php endif; ?>

<div id="dirList" class="grid" style="gap:var(--gap)">
  <?php if (!$groups): ?>
    <div class="card empty"><div class="e-ico"><i class="bi <?= $tab === 'company' ? 'bi-person-badge' : 'bi-envelope-at' ?>"></i></div>
      <?php if ($tab === 'company' && !$missing): ?>
        <h3>No company emails yet</h3><p>Add the employees who have an address on the company domain, e.g. admin@example.com.</p>
        <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= h($addUrl('staffmail', ['company' => $cid])) ?>"><i class="bi bi-plus-lg"></i> Add company email</a><?php endif; ?>
      <?php elseif ($missing): ?>
        <h3>Nobody missing</h3><p><?= $tab === 'company' ? 'Everyone in this company has a company email.' : 'Everyone has an email.' ?></p>
      <?php elseif ($tab === 'staff'): ?>
        <h3>No staff emails</h3><p>Staff appear here once they have a login and an email address.</p>
      <?php else: ?>
        <h3>No shared mailboxes yet</h3><p>Add info@, accounts@, hr@ and other shared addresses, company by company.</p>
        <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= h($addUrl('mailbox', ['company' => $cid])) ?>"><i class="bi bi-plus-lg"></i> Add shared mailbox</a><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($groups as $gid => $g): $rows = $tab === 'shared' ? $g['boxes'] : $g['people']; ?>
    <div class="card rise">
      <div class="card-head"><i class="bi bi-building"></i><h3><?= h($g['name']) ?> <span class="faint">(<?= count($rows) ?>)</span></h3>
        <?php if (!$missing): ?><button type="button" class="btn btn-sm btn-soft" data-copy-all="<?= h(implode(', ', array_column($rows, $tab === 'shared' ? 'address' : 'email'))) ?>"><i class="bi bi-clipboard"></i><span class="hide-sm"> Copy all</span></button>
          <a class="btn btn-sm btn-ghost" href="email_pdf.php?type=<?= $tab ?>&amp;company=<?= (int)$gid ?>" title="PDF for this company"><i class="bi bi-file-earmark-pdf"></i></a><?php endif; ?>
        <?php if ($canEdit && $tab === 'company' && !$missing): ?><a class="btn btn-sm btn-ghost" href="<?= h($addUrl('staffmail', ['company' => $gid])) ?>" title="Add company email"><i class="bi bi-plus-lg"></i></a><?php endif; ?>
      </div>
      <div class="list" style="margin-top:8px">
        <?php foreach ($rows as $r): ?>
          <?php if ($tab === 'company' && !$missing): ?>
            <a class="list-item dir-row" data-row href="record.php?id=<?= (int)$r['record_id'] ?>">
              <span class="avatar"><?= h(strtoupper(mb_substr($r['nickname'] ?: ($r['fullname'] ?: $r['username']), 0, 1))) ?></span>
              <span class="li-main"><strong><?= h($r['fullname'] ?: $r['username']) ?><?= $r['nickname'] ? ' <span class="muted" style="font-weight:500">“' . h($r['nickname']) . '”</span>' : '' ?></strong>
                <small><?= h($roleLine($r) ?: $r['username']) ?><?= $r['aliases'] ? ' · also ' . h($r['aliases']) : '' ?></small></span>
              <?php if (($r['status'] ?? 'Active') !== 'Active'): ?><span class="badge tone-gray"><?= h($r['status']) ?></span><?php endif; ?>
              <?php if (empty($r['is_active'])): ?><span class="badge tone-amber" title="The person's login is disabled">Login disabled</span><?php endif; ?>
              <span class="dir-mail mono"><?= h($r['email']) ?></span>
              <button type="button" class="mini-btn" data-copy-one="<?= h($r['email']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button>
            </a>
          <?php elseif ($tab === 'shared'): ?>
            <a class="list-item dir-row" data-row href="record.php?id=<?= (int)$r['id'] ?>">
              <span class="li-ico"><i class="bi bi-envelope-at"></i></span>
              <span class="li-main"><strong class="mono"><?= h($r['address']) ?></strong><small><?= h(implode(' · ', array_filter([$r['display_name'] ?? '', $r['kind'] ?? '', $r['department'] ?? '']))) ?></small></span>
              <?php if (($r['status'] ?? 'Active') !== 'Active'): ?><span class="badge tone-gray"><?= h($r['status']) ?></span><?php endif; ?>
              <button type="button" class="mini-btn" data-copy-one="<?= h($r['address']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button>
            </a>
          <?php else: $corpMail = $hasCorp[(int)$r['id']][$gid] ?? null; ?>
            <div class="list-item dir-row" data-row>
              <span class="avatar"><?= h(strtoupper(mb_substr($r['nickname'] ?: ($r['fullname'] ?: $r['username']), 0, 1))) ?></span>
              <span class="li-main"><strong><?= h($r['fullname'] ?: $r['username']) ?><?= $r['nickname'] ? ' <span class="muted" style="font-weight:500">“' . h($r['nickname']) . '”</span>' : '' ?></strong>
                <small><?= h($roleLine($r) ?: $r['username']) ?><?= $r['phone'] ? ' · ' . h($r['phone']) : '' ?><?= $corpMail && $tab === 'staff' ? ' · <i class="bi bi-person-badge"></i> ' . h($corpMail) : '' ?></small></span>
              <?php if ($tab === 'company'): ?>
                <?php if ($r['email']): ?><span class="faint mono" style="font-size:12.5px" title="Login email"><?= h($r['email']) ?></span><?php endif; ?>
                <?php if ($canEdit): ?><a class="btn btn-sm btn-soft" href="<?= h($addUrl('staffmail', ['company' => $gid, 'employee' => (int)$r['id']])) ?>"><i class="bi bi-plus-lg"></i> Add</a><?php endif; ?>
              <?php elseif ($r['email']): ?>
                <a class="dir-mail mono" href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a>
                <button type="button" class="mini-btn" data-copy-one="<?= h($r['email']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button>
              <?php else: ?><span class="badge tone-amber">No email</span><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="card empty" data-noresults hidden><div class="e-ico"><i class="bi bi-search"></i></div><h3>No match</h3><p>Try another word.</p></div>
</div>

<?php if ($canEdit): ?>
<div class="overlay" id="shareOverlay" hidden>
  <form class="modal" id="shareForm" autocomplete="off">
    <div class="modal-icon"><i class="bi bi-send"></i></div>
    <h3 style="text-align:center">Share by email</h3>
    <p class="muted" style="text-align:center;margin:0 0 16px">The list goes as a PDF attachment. Contact details only — no passwords.</p>
    <div class="form-grid">
      <div class="field"><label for="shType">List</label><select class="input" id="shType" name="type">
        <?php foreach (it_email_list_types() as $k => $l): ?><option value="<?= $k ?>" <?= $k === $tab ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="shCompany">Company</label><select class="input" id="shCompany" name="company">
        <option value="">All companies</option>
        <?php foreach ($companies as $id => $name): ?><option value="<?= (int)$id ?>" <?= $cid === $id ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></div>
      <div class="field full"><label for="shTo">Send to<span class="req">*</span></label><input class="input" id="shTo" name="to" placeholder="name@company.com, other@company.com" required>
        <div class="help">Up to 10 addresses, separated by commas.</div></div>
      <div class="field full"><label for="shSubject">Subject</label><input class="input" id="shSubject" name="subject" maxlength="150" placeholder="Leave empty for an automatic subject"></div>
      <div class="field full"><label for="shMsg">Message</label><textarea class="input" id="shMsg" name="message" rows="3" placeholder="Optional note"></textarea></div>
    </div>
    <p style="margin:12px 0 0;font-size:13px"><a href="#" id="shPreview" style="color:var(--accent)"><i class="bi bi-eye"></i> Preview the PDF</a></p>
    <div class="form-error" id="shError" style="margin-top:10px"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" id="shSend"><i class="bi bi-send"></i> Send</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const copy = async (t, msg) => { try { await navigator.clipboard.writeText(t); IT.toast(msg, 'success'); } catch (e) { IT.toast('Copy is blocked here.', 'warn'); } };
  document.addEventListener('click', (e) => {
    const one = e.target.closest('[data-copy-one]'), all = e.target.closest('[data-copy-all]'), sh = e.target.closest('[data-share]');
    if (one) { e.preventDefault(); e.stopPropagation(); copy(one.dataset.copyOne, 'Email copied'); }
    if (all) { e.preventDefault(); copy(all.dataset.copyAll, 'All ' + all.dataset.copyAll.split(',').length + ' emails copied — paste into “To”'); }
    if (sh) { document.getElementById('shError').textContent = ''; IT.openOverlay(document.getElementById('shareOverlay')); setTimeout(() => document.getElementById('shTo').focus(), 50); }
  });
  const f = document.getElementById('shareForm');
  if (!f) return;
  document.getElementById('shPreview').onclick = (e) => {
    e.preventDefault();
    window.open('email_pdf.php?view=1&type=' + f.elements.type.value + '&company=' + f.elements.company.value, '_blank', 'noopener');
  };
  f.onsubmit = async (e) => {
    e.preventDefault();
    const to = f.elements.to.value;
    const bad = to.split(/[\s,;]+/).filter(Boolean).find(a => !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(a));
    if (!to.trim()) { document.getElementById('shError').textContent = 'Type at least one email address.'; return; }
    if (bad) { document.getElementById('shError').textContent = '“' + bad + '” is not a valid email address.'; return; }
    const btn = document.getElementById('shSend');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sending…';
    const r = await IT.post('email_share', { type: f.elements.type.value, company: f.elements.company.value, to, subject: f.elements.subject.value, message: f.elements.message.value });
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i> Send';
    if (!r.ok) { document.getElementById('shError').textContent = r.error; return; }
    IT.closeOverlay(document.getElementById('shareOverlay'));
    IT.toast(r.message, 'success', { ms: 6000 });
    f.elements.to.value = ''; f.elements.message.value = '';
  };
})();
</script>
JS;
include __DIR__ . '/includes/layout_footer.php';
