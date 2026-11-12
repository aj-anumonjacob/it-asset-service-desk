<?php
/**
 * IT Portal — page shell. Set $pageKey before including; $IT_ME comes from it_require_access().
 */
$G = it_page('_global');
$P = it_page($pageKey);
$pages = it_pages();
$primary = it_hex($G['primary'] ?? '', '#f97316');
$accent = it_hex($P['accent'] ?? '', $primary);
$me = $GLOBALS['IT_ME'];
$myName = trim($me['fullname'] ?: $me['username']);
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', $myName), 0, 2))));
$bodyClass = [
    'theme-' . (in_array($G['theme'] ?? '', ['light', 'auto', 'steel'], true) ? $G['theme'] : 'steel'),
    empty($G['animations']) ? 'no-anim' : 'anim',
    ($G['density'] ?? '') === 'compact' ? 'density-compact' : '',
    ($G['font'] ?? '') === 'large' ? 'font-large' : '',
];
$menu = [];
foreach ($pages as $k => $p) {
    $s = it_page($k);
    if ($k !== 'settings' && empty($s['in_menu'])) continue;
    if ($k === 'vault' && !it_can('vault')) continue;
    if (($k === 'settings' || !empty($p['admin'])) && !it_can('settings')) continue;
    $menu[$p['group']][$k] = ['title' => $s['title'], 'short' => $s['title'] === $p['title'] ? ($p['short'] ?? $p['title']) : explode(' ', $s['title'])[0], 'icon' => $s['icon'], 'file' => $p['file'], 'accent' => it_hex($s['accent'] ?? '', $primary)];
}
$flat = array_merge(...array_values($menu));
// Bottom tabs on phones: the four most used pages that are in the menu.
$bottom = [];
foreach (['dashboard', 'vault', 'inventory', 'maintenance', 'cameras'] as $k) {
    if (isset($flat[$k]) && count($bottom) < 4) $bottom[$k] = $flat[$k];
}
$docTitle = ($pageTitle ?? $P['title'] ?? '') . ' · ' . ($G['brand'] ?? 'IT Portal');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= h($primary) ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($G['brand']) ?>">
<title><?= h($docTitle) ?></title>
<link rel="manifest" href="manifest.php">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Barlow:wght@400;500;600;700&family=Rajdhani:wght@600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/it.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/it.css') ?>">
<style>:root{--primary:<?= h($primary) ?>;--accent:<?= h($accent) ?>;}</style>
</head>
<body class="<?= h(implode(' ', array_filter($bodyClass))) ?>" data-page="<?= h($pageKey) ?>">
<div class="bg-orbs" aria-hidden="true"><span></span><span></span><span></span></div>

<aside class="sidebar" id="sidebar">
  <a class="brand" href="dashboard.php">
    <span class="brand-mark"><i class="bi bi-cpu"></i></span>
    <span class="brand-text"><strong><?= h($G['brand']) ?></strong><small><?= h($G['tagline']) ?></small></span>
  </a>
  <nav class="side-nav">
    <?php foreach ($menu as $navGroup => $navItems): ?>
      <div class="nav-group"><?= h($navGroup) ?></div>
      <?php foreach ($navItems as $navKey => $navIt): ?>
        <a href="<?= h($navIt['file']) ?>" class="nav-item <?= $navKey === $pageKey ? 'active' : '' ?>" style="--c:<?= h($navIt['accent']) ?>">
          <i class="bi <?= h($navIt['icon']) ?>"></i><span><?= h($navIt['title']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <span class="avatar"><?= h($initials) ?></span>
    <span class="who"><strong><?= h($myName) ?></strong><small><?= h(ucfirst($me['role'])) ?></small></span>
    <a href="logout.php" class="icon-btn" title="Sign out"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</aside>

<div class="shell">
  <header class="topbar">
    <a class="brand brand-mobile" href="dashboard.php"><span class="brand-mark"><i class="bi bi-cpu"></i></span><strong><?= h($G['brand']) ?></strong></a>
    <button class="search-trigger" type="button" data-open-search>
      <i class="bi bi-search"></i><span>Search everything…</span><kbd>⌘K</kbd>
    </button>
    <div class="top-actions">
      <button class="icon-btn search-mobile" type="button" data-open-search title="Search"><i class="bi bi-search"></i></button>
      <?php if (it_can('vault')): ?>
      <button class="vault-pill <?= it_vault_unlocked() ? 'is-open' : '' ?>" type="button" id="vaultPill" title="Vault lock">
        <i class="bi <?= it_vault_unlocked() ? 'bi-unlock' : 'bi-lock' ?>"></i><span><?= it_vault_unlocked() ? 'Vault open' : 'Vault locked' ?></span>
      </button>
      <?php endif; ?>
      <a href="logout.php" class="icon-btn only-mobile" title="Sign out"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </header>

  <main class="content" id="content">
