<?php
/**
 * Leaves the IT Portal. The main system session stays unless ?all=1
 * (used by "sign in as someone else").
 */
require_once __DIR__ . '/includes/it_core.php';
it_ensure_schema($conn);
if (!empty($_SESSION['it_portal_auth'])) {
    it_log($conn, 'logout', null, null, 'Signed out');
}
unset($_SESSION['it_portal_auth'], $_SESSION['it_vault_until'], $_SESSION['it_last_seen']);
if (!empty($_GET['all'])) {
    logout_and_redirect('login.php');
}
header('Location: login.php');
exit;
