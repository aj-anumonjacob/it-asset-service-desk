<?php
$flash = $_SESSION['it_flash'] ?? null;
unset($_SESSION['it_flash']);
$cfg = [
    'csrf' => csrf_token(),
    'revealSeconds' => max(5, (int)($G['reveal_seconds'] ?? 30)),
    'unlocked' => it_vault_unlocked(),
    'unlockLeft' => max(0, (int)($_SESSION['it_vault_until'] ?? 0) - time()),
    'hasPin' => !empty($GLOBALS['IT_ME']['pin_hash']),
    'canVault' => it_can('vault'),
    'flash' => $flash,
];
?>
  </main>
</div>

<nav class="bottom-nav">
  <?php foreach ($bottom as $k => $it): ?>
    <a href="<?= h($it['file']) ?>" class="<?= $k === $pageKey ? 'active' : '' ?>"><i class="bi <?= h($it['icon']) ?>"></i><span><?= h($it['short']) ?></span></a>
  <?php endforeach; ?>
  <button type="button" data-open-sheet class="<?= isset($bottom[$pageKey]) ? '' : 'active' ?>"><i class="bi bi-grid-3x3-gap"></i><span>More</span></button>
</nav>

<div class="sheet" id="moreSheet" hidden>
  <div class="sheet-panel">
    <div class="sheet-grip"></div>
    <div class="sheet-grid">
      <?php foreach ($flat as $k => $it): ?>
        <a href="<?= h($it['file']) ?>" class="sheet-item <?= $k === $pageKey ? 'active' : '' ?>" style="--c:<?= h($it['accent']) ?>"><span><i class="bi <?= h($it['icon']) ?>"></i></span><?= h($it['title']) ?></a>
      <?php endforeach; ?>
    </div>
    <a href="logout.php" class="btn btn-ghost btn-block"><i class="bi bi-box-arrow-right"></i> Sign out</a>
  </div>
</div>

<!-- Search -->
<div class="overlay" id="searchOverlay" hidden>
  <div class="search-box">
    <div class="search-input"><i class="bi bi-search"></i><input type="search" id="searchInput" placeholder="Name, serial, IP, person, camera…" autocomplete="off"><kbd>Esc</kbd></div>
    <div class="search-results" id="searchResults"><div class="search-hint">Type at least 2 letters. Passwords themselves are never searched.</div></div>
  </div>
</div>

<!-- Vault PIN -->
<div class="overlay" id="pinOverlay" hidden>
  <form class="modal modal-sm" id="pinForm" autocomplete="off">
    <div class="modal-icon"><i class="bi bi-shield-lock"></i></div>
    <h3 id="pinTitle">Enter vault PIN</h3>
    <p class="muted" id="pinHint">The vault opens for a few minutes, then locks by itself.</p>
    <div class="pin-dots" id="pinDots"><span></span><span></span><span></span><span></span><span></span><span></span></div>
    <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" id="pinInput" class="pin-input" aria-label="PIN">
    <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" id="pinConfirm" class="pin-input" placeholder="Repeat PIN" hidden aria-label="Repeat PIN">
    <div class="form-error" id="pinError"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" id="pinSubmit">Unlock</button>
    </div>
  </form>
</div>

<!-- Confirm -->
<div class="overlay" id="confirmOverlay" hidden>
  <div class="modal modal-sm">
    <div class="modal-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
    <h3 id="confirmTitle">Are you sure?</h3>
    <p class="muted" id="confirmText"></p>
    <input type="text" class="input" id="confirmType" hidden autocomplete="off">
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="button" class="btn btn-danger" id="confirmOk">Yes, continue</button>
    </div>
  </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script>window.IT = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/it.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/it.js') ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
