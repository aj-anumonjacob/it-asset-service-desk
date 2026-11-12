<?php
/**
 * IT Portal sign-in. Uses the main system's username and password, but only
 * people on the IT access list (it_access) get in.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/../includes/account_status.php';
it_ensure_schema($conn);

$G = it_settings($conn, '_global');
$primary = it_hex($G['primary'] ?? '', '#f97316');
$next = preg_replace('/[^A-Za-z0-9_.?=&%-]/', '', (string)($_GET['next'] ?? $_POST['next'] ?? 'dashboard.php'));
if ($next === '' || !preg_match('/^[a-z_]+\.php/', $next)) $next = 'dashboard.php';

$error = $_SESSION['it_login_msg'] ?? '';
unset($_SESSION['it_login_msg']);
$info = '';

// Signed in to the main system already: ask for the password only.
$knownUser = null;
if (current_user_id()) {
    $st = $conn->prepare("SELECT id, username, fullname FROM `user` WHERE id = ?");
    $st->execute([current_user_id()]);
    $knownUser = $st->fetch() ?: null;
}

$_SESSION['it_rate'] = array_filter($_SESSION['it_rate'] ?? [], fn($t) => $t > time() - 600);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ($knownUser['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if (!csrf_verify(false)) {
        $error = 'The page expired. Please try again.';
    } elseif (count($_SESSION['it_rate']) >= 5) {
        $error = 'Too many attempts. Wait 10 minutes and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $st = $conn->prepare("SELECT id, username, fullname, password FROM `user` WHERE username = ? LIMIT 1");
        $st->execute([$username]);
        $u = $st->fetch();
        $ok = false;
        if ($u) {
            $stored = (string)$u['password'];
            if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) $ok = password_verify($password, $stored);
            elseif (preg_match('/^[a-f0-9]{32}$/i', $stored)) $ok = hash_equals(strtolower($stored), md5($password));
            elseif ($stored !== '') $ok = hash_equals($stored, $password);
        }
        $access = $ok ? it_access_row($conn, (int)$u['id']) : null;
        $blocked = $ok ? account_login_block_reason($conn, (int)$u['id']) : null;

        if (!$ok) {
            $_SESSION['it_rate'][] = time();
            $error = 'Wrong username or password.';
            it_log($conn, 'login_failed', null, null, 'Username: ' . $username);
        } elseif ($blocked) {
            $error = $blocked['message'];
        } elseif (!$access) {
            $_SESSION['it_rate'][] = time();
            $error = 'This account does not have access to the IT Portal.';
            it_log($conn, 'login_denied', null, null, 'User #' . $u['id'] . ' (' . $u['username'] . ') is not on the IT access list');
        } else {
            if (current_user_id() !== (int)$u['id']) {
                session_regenerate_id(true);
                set_logged_in_user((int)$u['id'], $u['username'], $u['fullname']);
            }
            $_SESSION['it_portal_auth'] = (int)$u['id'];
            $_SESSION['it_last_seen'] = time();
            $_SESSION['it_rate'] = [];
            $GLOBALS['IT_ME'] = $access;
            it_log($conn, 'login', null, null, 'Signed in');
            header('Location: ' . $next);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= h($primary) ?>">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · <?= h($G['brand']) ?></title>
<link rel="manifest" href="manifest.php">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Barlow:wght@400;500;600;700&family=Rajdhani:wght@600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/it.css?v=<?= (int)@filemtime(__DIR__ . '/assets/it.css') ?>">
<style>:root{--primary:<?= h($primary) ?>;--accent:<?= h($primary) ?>;}</style>
</head>
<body class="<?= empty($G['animations']) ? 'no-anim' : 'anim' ?> theme-<?= h(in_array($G['theme'] ?? '', ['light', 'auto', 'steel'], true) ? $G['theme'] : 'steel') ?>">
<div class="bg-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="on">
    <?php csrf_field(); ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <span class="brand-mark"><i class="bi bi-cpu"></i></span>
    <h1><?= h($G['brand']) ?></h1>
    <?php if ($knownUser): ?>
      <p>Hi <?= h(it_friendly_name($conn, (int)$knownUser['id']) ?: $knownUser['username']) ?>, confirm your password to open the IT Portal.</p>
    <?php else: ?>
      <p>Sign in with your system account.</p>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i><span><?= h($error) ?></span></div><?php endif; ?>

    <?php if ($knownUser): ?>
      <input type="hidden" name="username" value="<?= h($knownUser['username']) ?>">
      <div class="field"><label>Account</label><div class="input" style="display:flex;align-items:center;gap:10px"><i class="bi bi-person-circle"></i><?= h($knownUser['username']) ?></div></div>
    <?php else: ?>
      <div class="field"><label for="u">Username</label><input class="input" id="u" name="username" autocomplete="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>"></div>
    <?php endif; ?>
    <div class="field" data-pw>
      <label for="p">Password</label>
      <div class="input-group">
        <input class="input" id="p" type="password" name="password" autocomplete="current-password" required <?= $knownUser ? 'autofocus' : '' ?>>
        <button type="button" class="btn btn-ghost" data-toggle-pw title="Show password"><i class="bi bi-eye"></i></button>
      </div>
    </div>
    <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
    <?php if ($knownUser): ?>
      <p style="margin:16px 0 0;text-align:center;font-size:13px"><a href="logout.php?all=1" class="muted">Not <?= h($knownUser['username']) ?>? Sign in as someone else</a></p>
    <?php endif; ?>
    <p class="faint" style="margin:18px 0 0;text-align:center;font-size:12px"><i class="bi bi-shield-check"></i> Every sign-in is recorded.</p>
  </form>
</div>
<script>
document.querySelectorAll('[data-toggle-pw]').forEach(b => b.onclick = () => {
  const i = b.parentElement.querySelector('input');
  i.type = i.type === 'password' ? 'text' : 'password';
  b.querySelector('i').className = 'bi ' + (i.type === 'password' ? 'bi-eye' : 'bi-eye-slash');
});
</script>
</body>
</html>
