<?php
/**
 * Printable QR asset labels. ?id=5 for one, ?ids=1,2,3 or ?all=1 for many.
 * The QR opens the device's page in the portal (sign-in still required).
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
$pageKey = 'inventory';
$ids = !empty($_GET['all']) ? [] : array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))));
$sql = "SELECT * FROM it_records WHERE entity = 'asset' AND deleted_at IS NULL" . ($ids ? ' AND id IN (' . implode(',', $ids) . ')' : '') . " ORDER BY ref_code";
$rows = $conn->query($sql)->fetchAll();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/record.php?id=';
$G = it_page('_global');
$pageTitle = 'QR labels';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="page-head no-print">
  <span class="page-icon"><i class="bi bi-qr-code"></i></span>
  <div class="grow"><h1>QR labels</h1><p>Stick one on each device. Scanning it opens the device here.</p></div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="<?= count($rows) === 1 ? 'record.php?id=' . (int)$rows[0]['id'] : 'inventory.php' ?>">Back</a>
    <?php if (count($rows) === 1): ?><a class="btn btn-ghost" href="label.php?all=1"><i class="bi bi-collection"></i> All devices</a><?php endif; ?>
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>
<?php if (!$rows): ?><div class="card empty"><div class="e-ico"><i class="bi bi-qr-code"></i></div><h3>No devices</h3><p>Add devices on the IT Inventory page first.</p></div><?php endif; ?>
<div class="label-sheet">
  <?php foreach ($rows as $r): $d = json_decode($r['data'], true) ?: []; ?>
    <div class="qr-label">
      <div class="qr" data-qr="<?= h($base . (int)$r['id']) ?>"></div>
      <div>
        <small><?= h($G['tagline']) ?></small>
        <strong class="mono"><?= h($r['ref_code']) ?></strong>
        <div style="font-weight:700;font-size:14px;margin:4px 0"><?= h($r['title']) ?></div>
        <small><?= h(trim(($d['brand'] ?? '') . ' ' . ($d['model'] ?? ''))) ?></small>
        <?php if (!empty($d['serial'])): ?><small class="mono">S/N <?= h($d['serial']) ?></small><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php
$pageScripts = '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>document.querySelectorAll("[data-qr]").forEach(el => new QRCode(el, { text: el.dataset.qr, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M }));</script>';
include __DIR__ . '/includes/layout_footer.php';
