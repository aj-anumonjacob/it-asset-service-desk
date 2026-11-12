<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
$me = it_require_access();
if (!it_can('settings')) { it_flash('Only the IT admin can open Settings.', 'error'); header('Location: dashboard.php'); exit; }
$pageKey = 'settings';
$G = it_settings($conn, '_global');
$back = function (string $anchor = ''): never {
    header('Location: settings.php' . ($anchor ? '#' . $anchor : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!it_csrf_ok()) { it_flash('The page expired. Please try again.', 'error'); $back(); }
    $form = $_POST['form'] ?? '';

    if ($form === 'global') {
        $d = it_global_defaults();
        $pick = fn($v, array $ok, $def) => in_array($v, $ok, false) ? $v : $def;
        $s = [
            'brand' => mb_substr(trim((string)$_POST['brand']), 0, 40) ?: $d['brand'],
            'tagline' => mb_substr(trim((string)$_POST['tagline']), 0, 60),
            'primary' => it_hex((string)($_POST['primary_custom'] ?? '') ?: (string)($_POST['primary'] ?? ''), $d['primary']),
            'theme' => $pick($_POST['theme'] ?? '', ['steel', 'light', 'auto'], 'steel'),
            'animations' => !empty($_POST['animations']),
            'density' => $pick($_POST['density'] ?? '', ['comfortable', 'compact'], 'comfortable'),
            'font' => $pick($_POST['font'] ?? '', ['normal', 'large'], 'normal'),
            'idle_minutes' => (int)$pick((int)($_POST['idle_minutes'] ?? 0), [10, 15, 30, 60, 120], 30),
            'reveal_seconds' => (int)$pick((int)($_POST['reveal_seconds'] ?? 0), [10, 20, 30, 60], 30),
            'vault_unlock_minutes' => (int)$pick((int)($_POST['vault_unlock_minutes'] ?? 0), [1, 2, 5, 10, 15], 5),
            'alert_days' => (int)$pick((int)($_POST['alert_days'] ?? 0), [7, 14, 30, 60, 90], 30),
        ];
        $v = it_save_settings($conn, '_global', $s);
        it_log($conn, 'settings', null, null, 'Changed appearance / security settings (version ' . $v . ')');
        it_flash('Settings saved');
        $back('appearance');
    }

    if ($form === 'pin') {
        $curPin = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!empty($me['pin_hash']) && !password_verify($curPin, $me['pin_hash'])) { it_flash('Your current PIN is wrong.', 'error'); $back('pin'); }
        if (!preg_match('/^\d{4,6}$/', $new)) { it_flash('The new PIN must be 4 to 6 digits.', 'error'); $back('pin'); }
        if ($new !== (string)($_POST['confirm'] ?? '')) { it_flash('The two new PINs do not match.', 'error'); $back('pin'); }
        if (preg_match('/^(\d)\1+$/', $new) || in_array($new, ['1234', '12345', '123456', '4321', '654321'], true)) { it_flash('That PIN is too easy to guess.', 'error'); $back('pin'); }
        $conn->prepare("UPDATE it_access SET pin_hash = ?, pin_fails = 0, pin_locked_until = NULL WHERE user_id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me['user_id']]);
        unset($_SESSION['it_vault_until']);
        it_log($conn, empty($me['pin_hash']) ? 'pin_set' : 'pin_changed', null, null, 'Vault PIN ' . (empty($me['pin_hash']) ? 'created' : 'changed'));
        it_flash('Vault PIN ' . (empty($me['pin_hash']) ? 'created' : 'changed'));
        $back('pin');
    }

    if ($form === 'access_add') {
        $st = $conn->prepare("SELECT id, username FROM `user` WHERE username = ? AND user_type = 'internal' LIMIT 1");
        $st->execute([trim((string)$_POST['username'])]);
        $u = $st->fetch();
        $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'viewer'], true) ? $_POST['role'] : 'viewer';
        if (!$u) { it_flash('No staff account with that username.', 'error'); $back('access'); }
        $conn->prepare("INSERT INTO it_access (user_id, role, is_active, added_by) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE role = VALUES(role), is_active = 1")->execute([$u['id'], $role, it_user_id()]);
        it_log($conn, 'access', null, null, 'Gave ' . $u['username'] . ' IT Portal access as ' . $role);
        it_flash($u['username'] . ' can now sign in to the IT Portal');
        $back('access');
    }

    if ($form === 'access_update') {
        $uid = (int)$_POST['user_id'];
        if ($uid === it_user_id()) { it_flash('You cannot change your own access — ask another admin.', 'error'); $back('access'); }
        $st = $conn->prepare("SELECT username FROM `user` WHERE id = ?");
        $st->execute([$uid]);
        $uname = (string)$st->fetchColumn();
        if (($_POST['do'] ?? '') === 'remove') {
            $conn->prepare("UPDATE it_access SET is_active = 0 WHERE user_id = ?")->execute([$uid]);
            it_log($conn, 'access', null, null, 'Removed IT Portal access for ' . $uname);
            it_flash('Access removed for ' . $uname);
        } else {
            $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'viewer'], true) ? $_POST['role'] : 'viewer';
            $conn->prepare("UPDATE it_access SET role = ? WHERE user_id = ?")->execute([$role, $uid]);
            it_log($conn, 'access', null, null, 'Changed ' . $uname . ' to ' . $role);
            it_flash($uname . ' is now ' . $role);
        }
        $back('access');
    }
    $back();
}

$people = $conn->query("SELECT a.*, u.username, u.fullname, u.is_active u_active FROM it_access a JOIN `user` u ON u.id = a.user_id WHERE a.is_active = 1 ORDER BY a.role, u.fullname")->fetchAll();
$keyFile = it_vault_key_file();
$keyOk = is_file($keyFile);
$encCount = (int)$conn->query("SELECT COUNT(*) FROM it_records WHERE secret_enc IS NOT NULL AND deleted_at IS NULL")->fetchColumn();

include __DIR__ . '/includes/layout_header.php';
it_page_head(it_page('settings'));
$primaryNow = it_hex($G['primary'] ?? '', '#f97316');
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
?>
<div class="section-title">Pages — customize each one on its own</div>
<div class="grid grid-cards" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
  <?php $i = 0; foreach (it_pages() as $k => $p): $s = it_page($k); $nCustom = 0; foreach ($s['entities'] ?? [] as $e) $nCustom += count($e['custom'] ?? []); ?>
    <a class="card page-tile rise" style="--i:<?= $i++ ?>;--c:<?= h(it_hex($s['accent'] ?? '', '#6366f1')) ?>" href="customize.php?page=<?= h($k) ?>">
      <span class="rec-ico"><i class="bi <?= h($s['icon']) ?>"></i></span>
      <span style="flex:1;min-width:0"><strong style="display:block"><?= h($s['title']) ?></strong>
        <small class="faint"><?= $nCustom ? $nCustom . ' own field' . ($nCustom > 1 ? 's' : '') . ' · ' : '' ?><?= empty($s['in_menu']) && empty($p['locked']) ? 'hidden from menu' : 'in menu' ?></small></span>
      <i class="bi bi-chevron-right faint"></i>
    </a>
  <?php endforeach; ?>
</div>

<form method="post" data-once id="appearance">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form" value="global">
  <div class="section-title">Whole portal</div>
  <div class="grid grid-2">
    <div class="card card-pad">
      <h3 style="font-size:17px;margin-bottom:16px"><i class="bi bi-palette" style="color:var(--accent)"></i> Appearance</h3>
      <div class="form-grid">
        <div class="field"><label>Portal name</label><input class="input" name="brand" value="<?= h($G['brand']) ?>" maxlength="40" required></div>
        <div class="field"><label>Line under the name</label><input class="input" name="tagline" value="<?= h($G['tagline']) ?>" maxlength="60"></div>
        <div class="field full"><label>Main colour</label>
          <div class="swatches">
            <?php foreach (it_accent_choices() as $c): ?><label class="swatch" style="background:<?= $c ?>"><input type="radio" name="primary" value="<?= $c ?>" <?= $c === $primaryNow ? 'checked' : '' ?>></label><?php endforeach; ?>
            <label class="swatch" style="background:conic-gradient(red,yellow,lime,cyan,blue,magenta,red)" title="Any colour"><input type="color" name="primary_custom" value="<?= h($primaryNow) ?>" <?= in_array($primaryNow, it_accent_choices(), true) ? 'disabled' : '' ?>></label>
          </div>
        </div>
        <div class="field"><label>Theme</label><select class="input" name="theme"><option value="steel" <?= $sel($G['theme'], 'steel') ?>>Steel — dark, bold</option><option value="light" <?= $sel($G['theme'], 'light') ?>>Light</option><option value="auto" <?= $sel($G['theme'], 'auto') ?>>Follow the device (light / dark)</option></select></div>
        <div class="field"><label>Spacing</label><select class="input" name="density"><option value="comfortable" <?= $sel($G['density'], 'comfortable') ?>>Comfortable</option><option value="compact" <?= $sel($G['density'], 'compact') ?>>Compact</option></select></div>
        <div class="field"><label>Text size</label><select class="input" name="font"><option value="normal" <?= $sel($G['font'], 'normal') ?>>Normal</option><option value="large" <?= $sel($G['font'], 'large') ?>>Large</option></select></div>
        <div class="field"><label>&nbsp;</label><label class="switch"><input type="checkbox" name="animations" value="1" <?= !empty($G['animations']) ? 'checked' : '' ?>><span class="track"></span><span>Animations</span></label></div>
      </div>
    </div>
    <div class="card card-pad">
      <h3 style="font-size:17px;margin-bottom:16px"><i class="bi bi-shield-check" style="color:var(--accent)"></i> Security & reminders</h3>
      <div class="form-grid">
        <div class="field"><label>Lock the portal after no activity for</label><select class="input" name="idle_minutes"><?php foreach ([10, 15, 30, 60, 120] as $n): ?><option value="<?= $n ?>" <?= $sel($G['idle_minutes'], $n) ?>><?= $n ?> minutes</option><?php endforeach; ?></select></div>
        <div class="field"><label>Vault stays open for</label><select class="input" name="vault_unlock_minutes"><?php foreach ([1, 2, 5, 10, 15] as $n): ?><option value="<?= $n ?>" <?= $sel($G['vault_unlock_minutes'], $n) ?>><?= $n ?> minute<?= $n > 1 ? 's' : '' ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Hide a shown password after</label><select class="input" name="reveal_seconds"><?php foreach ([10, 20, 30, 60] as $n): ?><option value="<?= $n ?>" <?= $sel($G['reveal_seconds'], $n) ?>><?= $n ?> seconds</option><?php endforeach; ?></select><div class="help">The clipboard is cleared after the same time.</div></div>
        <div class="field"><label>Warn me about renewals / expiry</label><select class="input" name="alert_days"><?php foreach ([7, 14, 30, 60, 90] as $n): ?><option value="<?= $n ?>" <?= $sel($G['alert_days'], $n) ?>><?= $n ?> days before</option><?php endforeach; ?></select></div>
      </div>
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button></div>
</form>

<div class="grid grid-2">
  <form method="post" class="card card-pad" id="pin" data-once autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form" value="pin">
    <h3 style="font-size:17px;margin-bottom:6px"><i class="bi bi-key" style="color:var(--accent)"></i> Vault PIN</h3>
    <p class="muted" style="margin:0 0 16px;font-size:13.5px"><?= empty($me['pin_hash']) ? 'You have no PIN yet. Create one to use the vault.' : 'Asked each time you open the vault. 5 wrong tries lock it for 15 minutes.' ?></p>
    <div class="form-grid">
      <?php if (!empty($me['pin_hash'])): ?><div class="field full"><label>Current PIN</label><input class="input" type="password" inputmode="numeric" maxlength="6" name="current" required></div><?php endif; ?>
      <div class="field"><label>New PIN (4–6 digits)</label><input class="input" type="password" inputmode="numeric" maxlength="6" pattern="\d{4,6}" name="new" required></div>
      <div class="field"><label>Repeat new PIN</label><input class="input" type="password" inputmode="numeric" maxlength="6" pattern="\d{4,6}" name="confirm" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= empty($me['pin_hash']) ? 'Create PIN' : 'Change PIN' ?></button></div>
  </form>

  <div class="card card-pad">
    <h3 style="font-size:17px;margin-bottom:6px"><i class="bi bi-safe" style="color:var(--accent)"></i> Encryption key — back it up</h3>
    <?php if ($keyOk): ?>
      <div class="alert alert-ok" style="margin:12px 0"><i class="bi bi-check-circle"></i><span>Key file found. <?= $encCount ?> password<?= $encCount === 1 ? '' : 's' ?> / key<?= $encCount === 1 ? '' : 's' ?> encrypted with it.</span></div>
    <?php else: ?>
      <div class="alert alert-info" style="margin:12px 0"><i class="bi bi-info-circle"></i><span>The key is created the first time you save a password.</span></div>
    <?php endif; ?>
    <p class="muted" style="font-size:13.5px;margin:0 0 8px">Passwords are encrypted with a key kept in this file on the server — not in the database:</p>
    <div class="secret-box mono" style="font-size:12px;word-break:break-all"><?= h($keyFile) ?></div>
    <p class="muted" style="font-size:13.5px;margin:10px 0 0"><b>Copy this file somewhere safe</b> (with your database backups). If the server is rebuilt without it, saved passwords cannot be opened. Never send it by email or chat.</p>
  </div>
</div>

<div class="section-title" id="access">Who can open the IT Portal</div>
<div class="card">
  <div class="list">
    <?php foreach ($people as $p): $self = (int)$p['user_id'] === it_user_id(); ?>
      <form method="post" class="list-item" style="flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form" value="access_update"><input type="hidden" name="user_id" value="<?= (int)$p['user_id'] ?>">
        <span class="avatar"><?= h(strtoupper(mb_substr($p['fullname'] ?: $p['username'], 0, 1))) ?></span>
        <span class="li-main"><strong><?= h($p['fullname'] ?: $p['username']) ?><?= $self ? ' <span class="badge tone-blue plain">you</span>' : '' ?></strong><small><?= h($p['username']) ?> · <?= $p['pin_hash'] ? 'PIN set' : 'no PIN yet' ?><?= !$p['u_active'] ? ' · system account disabled' : '' ?></small></span>
        <?php if ($self): ?>
          <span class="badge tone-green"><?= h(ucfirst($p['role'])) ?></span>
        <?php else: ?>
          <select class="input" name="role" style="width:auto" onchange="this.form.submit()">
            <?php foreach (['admin' => 'Admin — everything', 'staff' => 'Staff — no Settings', 'viewer' => 'Viewer — read only, no vault'] as $rk => $rl): ?><option value="<?= $rk ?>" <?= $sel($p['role'], $rk) ?>><?= $rl ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-ghost" name="do" value="remove" onclick="return confirm('Remove IT Portal access for <?= h($p['username']) ?>?')"><i class="bi bi-person-x" style="color:var(--red)"></i></button>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </div>
  <form method="post" class="toolbar" style="padding:16px 22px;margin:0;border-top:1px solid var(--line-2)" data-once>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="form" value="access_add">
    <div class="search"><i class="bi bi-person-plus"></i><input class="input" name="username" placeholder="Username of the staff member" required></div>
    <select class="input" name="role" style="width:auto"><option value="viewer">Viewer — read only</option><option value="staff">Staff</option><option value="admin">Admin</option></select>
    <button class="btn btn-soft" type="submit"><i class="bi bi-plus-lg"></i> Give access</button>
  </form>
</div>
<p class="faint" style="font-size:12.5px;margin-top:10px">Only people listed here can sign in. Everyone else is refused, and the attempt is recorded in the Activity Log.</p>

<script>
document.querySelectorAll('input[name=primary]').forEach(r => r.addEventListener('change', () => { document.querySelector('[name=primary_custom]').disabled = true; }));
document.querySelector('[name=primary_custom]').closest('label').addEventListener('click', () => { const c = document.querySelector('[name=primary_custom]'); c.disabled = false; document.querySelectorAll('input[name=primary]').forEach(r => r.checked = false); });
</script>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
