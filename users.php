<?php
/**
 * User Logins: staff accounts of the main system, with a password reset.
 * Admins only. A reset needs the vault PIN, shows the new password once,
 * and signs the person out of "remember me" sessions. Nothing is stored here.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
if (!it_can('settings')) { it_flash('Only the IT admin can manage user logins.', 'error'); header('Location: dashboard.php'); exit; }
$pageKey = 'users';
$ps = it_page($pageKey);

$q = trim((string)($_GET['q'] ?? ''));
$show = in_array($_GET['show'] ?? '', ['active', 'disabled', 'all'], true) ? $_GET['show'] : 'active';
$where = ["u.user_type = 'internal'"];
$args = [];
if ($show === 'active') $where[] = 'u.is_active = 1';
if ($show === 'disabled') $where[] = 'u.is_active = 0';
if ($q !== '') { $where[] = '(u.username LIKE ? OR u.fullname LIKE ? OR u.email LIKE ? OR e.nickname LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
$w = implode(' AND ', $where);
$per = 40;
$page = max(1, (int)($_GET['p'] ?? 1));
$from = "FROM `user` u LEFT JOIN employees e ON e.id = COALESCE(u.employee_id, (SELECT e2.id FROM employees e2 WHERE e2.user_id = u.id ORDER BY e2.id LIMIT 1))";
$st = $conn->prepare("SELECT COUNT(*) $from WHERE $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$st = $conn->prepare("SELECT u.id, u.username, u.fullname, u.email, u.is_active, u.last_login, e.nickname,
        (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) roles
    $from WHERE $w ORDER BY u.is_active DESC, u.fullname, u.username LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$people = $st->fetchAll();
$counts = $conn->query("SELECT SUM(is_active = 1) a, SUM(is_active = 0) d, COUNT(*) t FROM `user` WHERE user_type = 'internal'")->fetch();
$st = $conn->prepare("SELECT summary, created_at FROM it_activity WHERE action = 'access' AND summary LIKE 'Reset the system password of %' ORDER BY id DESC LIMIT 200");
$st->execute();
$lastReset = [];
foreach ($st->fetchAll() as $r) {
    if (preg_match('/^Reset the system password of (\S+)/', $r['summary'], $m)) $lastReset[$m[1]] ??= $r['created_at'];
}

include __DIR__ . '/includes/layout_header.php';
it_page_head($ps, '<a class="btn btn-ghost" href="customize.php?page=users"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>');
$url = fn(array $a) => 'users.php?' . http_build_query(array_filter(array_merge(['show' => $show, 'q' => $q], $a), fn($v) => $v !== '' && $v !== null));
?>
<div class="alert alert-info"><i class="bi bi-shield-lock"></i><span>Resetting gives the person a new password for the main system. It is shown to you <b>once</b> — it is not saved anywhere, and the old password stops working straight away. Each reset needs your vault PIN and is recorded in the Activity Log.</span></div>

<div class="toolbar">
  <div class="chips" style="margin:0">
    <a class="chip <?= $show === 'active' ? 'active' : '' ?>" href="<?= h($url(['show' => 'active', 'p' => null])) ?>">Active <b><?= (int)$counts['a'] ?></b></a>
    <a class="chip <?= $show === 'disabled' ? 'active' : '' ?>" href="<?= h($url(['show' => 'disabled', 'p' => null])) ?>">Disabled <b><?= (int)$counts['d'] ?></b></a>
    <a class="chip <?= $show === 'all' ? 'active' : '' ?>" href="<?= h($url(['show' => 'all', 'p' => null])) ?>">All <b><?= (int)$counts['t'] ?></b></a>
  </div>
  <form class="search" method="get"><input type="hidden" name="show" value="<?= h($show) ?>"><i class="bi bi-search"></i><input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, username, email, nickname…"></form>
</div>

<div class="card rise">
  <?php if (!$people): ?>
    <div class="empty"><div class="e-ico"><i class="bi bi-person-lock"></i></div><h3>No one found</h3><p>Try another name or filter.</p></div>
  <?php else: ?><div class="list">
    <?php foreach ($people as $p):
        $self = (int)$p['id'] === it_user_id();
        $name = trim($p['fullname'] ?: $p['username']);
        $nick = trim((string)$p['nickname']); ?>
      <div class="list-item user-row" style="flex-wrap:wrap">
        <span class="avatar"><?= h(strtoupper(mb_substr($nick ?: $name, 0, 1))) ?></span>
        <span class="li-main">
          <strong><?= h($name) ?><?= $nick !== '' && stripos($name, $nick) !== 0 ? ' <span class="muted" style="font-weight:500">“' . h($nick) . '”</span>' : '' ?><?= $self ? ' <span class="badge plain tone-blue">you</span>' : '' ?></strong>
          <small><span class="mono"><?= h($p['username']) ?></span><?= $p['roles'] ? ' · ' . h($p['roles']) : '' ?></small>
          <small class="faint">Last sign-in: <?= $p['last_login'] ? h(date('d M Y, H:i', strtotime($p['last_login']))) : 'never' ?><?= isset($lastReset[$p['username']]) ? ' · password reset ' . h(date('d M Y', strtotime($lastReset[$p['username']]))) : '' ?></small>
        </span>
        <span class="badge tone-<?= $p['is_active'] ? 'green' : 'gray' ?>"><?= $p['is_active'] ? 'Active' : 'Disabled' ?></span>
        <?php if ($self): ?>
          <span class="faint" style="font-size:12.5px">Use your profile</span>
        <?php else: ?>
          <button type="button" class="btn btn-sm btn-ghost" data-reset='<?= h(json_encode(['id' => (int)$p['id'], 'name' => $name, 'username' => $p['username'], 'active' => (bool)$p['is_active']])) ?>'><i class="bi bi-key"></i> Reset password</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php it_pager(['page' => $page, 'pages' => max(1, (int)ceil($total / $per))]); ?>

<div class="overlay" id="resetOverlay" hidden>
  <form class="modal" id="resetForm" autocomplete="off">
    <div class="modal-icon" style="color:var(--red);background:var(--red-bg)"><i class="bi bi-key"></i></div>
    <h3 style="text-align:center">Reset password</h3>
    <p class="muted" style="text-align:center;margin:0 0 14px" id="resetWho"></p>
    <div class="alert alert-warn" id="resetInactive" hidden><i class="bi bi-person-slash"></i><span>This account is disabled — the person still cannot sign in until it is turned on again in HR.</span></div>
    <label class="check"><input type="radio" name="mode" value="auto" checked><span><b>Create a strong password for me</b><br><small class="muted">12 characters, easy to read out (no 0/O or 1/l).</small></span></label>
    <label class="check" style="margin-top:8px"><input type="radio" name="mode" value="own"><span><b>I will type the password</b></span></label>
    <div class="field" id="ownWrap" hidden style="margin-top:12px" data-pw>
      <label for="ownPw">New password</label>
      <div class="input-group"><input class="input mono" type="password" id="ownPw" autocomplete="new-password" minlength="8"><button type="button" class="btn btn-ghost" data-toggle-pw><i class="bi bi-eye"></i></button></div>
      <div class="strength"><i></i></div><div class="strength-label"></div>
      <div class="help">At least 8 characters, with letters and numbers.</div>
    </div>
    <label class="switch" style="margin-top:14px"><input type="checkbox" id="resetAck"><span class="track"></span><span>I will give the new password to this person myself</span></label>
    <div class="form-error" id="resetError" style="margin-top:10px"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="submit" class="btn btn-danger" id="resetGo" disabled><i class="bi bi-arrow-repeat"></i> Reset now</button>
    </div>
  </form>
</div>

<div class="overlay" id="doneOverlayPw" hidden>
  <div class="modal modal-sm">
    <div class="modal-icon" style="color:var(--green);background:var(--green-bg)"><i class="bi bi-check2-circle"></i></div>
    <h3>New password ready</h3>
    <p class="muted" id="pwWho" style="margin:0 0 12px"></p>
    <div class="secret-box" style="justify-content:center"><span class="val shown mono" id="pwValue" style="font-size:22px;letter-spacing:.06em;text-align:center"></span></div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button type="button" class="btn btn-soft" style="flex:1" id="pwCopy"><i class="bi bi-clipboard"></i> Copy password</button>
      <button type="button" class="btn btn-ghost" style="flex:1" id="pwCopyMsg"><i class="bi bi-chat-text"></i> Copy message</button>
    </div>
    <div class="alert alert-warn" style="margin:14px 0 0;text-align:left"><i class="bi bi-eye-slash"></i><span>This is the only time it is shown. Their old password no longer works, and they are signed out of saved logins.</span></div>
    <div class="modal-actions"><button type="button" class="btn btn-primary" id="pwDone">Done — hide it</button></div>
  </div>
</div>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const ov = document.getElementById('resetOverlay'), f = document.getElementById('resetForm');
  const done = document.getElementById('doneOverlayPw');
  const own = document.getElementById('ownPw'), ack = document.getElementById('resetAck'), go = document.getElementById('resetGo');
  let who = null, result = null;
  const refresh = () => { go.disabled = !ack.checked || (f.mode.value === 'own' && own.value.length < 8); };
  f.querySelectorAll('input[name=mode]').forEach(r => r.addEventListener('change', () => { document.getElementById('ownWrap').hidden = f.mode.value !== 'own'; if (f.mode.value === 'own') own.focus(); refresh(); }));
  own.addEventListener('input', refresh); ack.addEventListener('change', refresh);
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-reset]');
    if (!b) return;
    who = JSON.parse(b.dataset.reset);
    document.getElementById('resetWho').textContent = who.name + ' (' + who.username + ')';
    document.getElementById('resetInactive').hidden = who.active;
    f.mode.value = 'auto'; document.getElementById('ownWrap').hidden = true; own.value = ''; ack.checked = false;
    document.getElementById('resetError').textContent = '';
    refresh();
    IT.openOverlay(ov);
  });
  f.onsubmit = async (e) => {
    e.preventDefault();
    if (go.disabled) return;
    go.disabled = true;
    const r = await IT.withVault(() => IT.post('user_reset', { user_id: who.id, mode: f.mode.value, password: f.mode.value === 'own' ? own.value : '' }));
    refresh();
    if (!r) return;
    if (!r.ok) { document.getElementById('resetError').textContent = r.error; IT.openOverlay(ov); return; }
    result = r; own.value = '';
    IT.closeOverlay(ov);
    document.getElementById('pwWho').textContent = r.name + ' · username ' + r.username;
    document.getElementById('pwValue').textContent = r.password;
    IT.openOverlay(done);
  };
  const copy = async (text, msg) => {
    try { await navigator.clipboard.writeText(text); IT.toast(msg + ' The clipboard clears in ' + IT.revealSeconds + ' seconds.', 'success'); setTimeout(() => navigator.clipboard.writeText('').catch(() => {}), IT.revealSeconds * 1000); }
    catch (e) { IT.toast('Copy is blocked here — read it from the screen.', 'warn'); }
  };
  document.getElementById('pwCopy').onclick = () => copy(result.password, 'Password copied.');
  document.getElementById('pwCopyMsg').onclick = () => copy('Your system login has been reset.\nUsername: ' + result.username + '\nNew password: ' + result.password + '\nPlease change it from your profile after signing in.', 'Message copied.');
  const wipe = () => { result = null; document.getElementById('pwValue').textContent = ''; IT.closeOverlay(done); location.reload(); };
  document.getElementById('pwDone').onclick = wipe;
  done.addEventListener('click', (e) => { if (e.target === done) wipe(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && result) wipe(); });
})();
</script>
JS;
include __DIR__ . '/includes/layout_footer.php';
