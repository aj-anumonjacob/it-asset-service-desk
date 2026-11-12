<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
$pageKey = 'activity';
$ps = it_page($pageKey);

$groups = [
    '' => 'Everything',
    'secrets' => ['Passwords viewed / copied', ['reveal', 'copy', 'vault_unlock', 'pin_failed', 'pin_set', 'pin_changed']],
    'changes' => ['Added / changed / deleted', ['create', 'update', 'delete', 'restore', 'purge', 'task_done']],
    'signin' => ['Sign-ins', ['login', 'logout', 'login_failed', 'login_denied']],
    'system' => ['Settings & access', ['settings', 'access', 'export']],
];
$g = isset($groups[$_GET['g'] ?? '']) ? (string)($_GET['g'] ?? '') : '';
$where = ['1=1'];
$args = [];
if ($g !== '') { $where[] = 'a.action IN (' . implode(',', array_fill(0, count($groups[$g][1]), '?')) . ')'; $args = $groups[$g][1]; }
if (!empty($_GET['from'])) { $where[] = 'a.created_at >= ?'; $args[] = $_GET['from'] . ' 00:00:00'; }
if (!empty($_GET['to'])) { $where[] = 'a.created_at <= ?'; $args[] = $_GET['to'] . ' 23:59:59'; }
$w = implode(' AND ', $where);
$per = 50;
$page = max(1, (int)($_GET['p'] ?? 1));
$st = $conn->prepare("SELECT COUNT(*) FROM it_activity a WHERE $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$st = $conn->prepare("SELECT a.*, u.fullname, u.username, r.title rtitle, r.entity rentity FROM it_activity a LEFT JOIN `user` u ON u.id = a.user_id LEFT JOIN it_records r ON r.id = a.record_id WHERE $w ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

$icons = ['reveal' => 'bi-eye', 'copy' => 'bi-clipboard', 'vault_unlock' => 'bi-unlock', 'pin_failed' => 'bi-shield-exclamation', 'login' => 'bi-box-arrow-in-right',
    'logout' => 'bi-box-arrow-right', 'login_failed' => 'bi-shield-x', 'login_denied' => 'bi-person-x', 'create' => 'bi-plus-circle', 'update' => 'bi-pencil',
    'delete' => 'bi-trash3', 'restore' => 'bi-arrow-counterclockwise', 'purge' => 'bi-x-octagon', 'task_done' => 'bi-check2-circle', 'settings' => 'bi-sliders', 'access' => 'bi-people', 'export' => 'bi-download'];
$warn = ['pin_failed', 'login_failed', 'login_denied', 'purge'];

include __DIR__ . '/includes/layout_header.php';
it_page_head($ps, it_can('settings') ? '<a class="btn btn-ghost" href="customize.php?page=activity"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>' : '');
?>
<div class="chips">
  <?php foreach ($groups as $k => $gv): ?>
    <a class="chip <?= $g === $k ? 'active' : '' ?>" href="?<?= h(http_build_query(['g' => $k, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''])) ?>"><?= h(is_array($gv) ? $gv[0] : $gv) ?></a>
  <?php endforeach; ?>
</div>
<form class="toolbar" method="get">
  <input type="hidden" name="g" value="<?= h($g) ?>">
  <label class="muted" style="font-size:13px;font-weight:700">From</label><input class="input" type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>" style="width:auto">
  <label class="muted" style="font-size:13px;font-weight:700">To</label><input class="input" type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>" style="width:auto">
  <button class="btn btn-ghost"><i class="bi bi-funnel"></i> Filter</button>
</form>
<div class="card rise">
  <?php if (!$rows): ?>
    <div class="empty"><div class="e-ico"><i class="bi bi-clock-history"></i></div><h3>Nothing recorded</h3><p>Try another filter.</p></div>
  <?php else: ?><div class="list">
    <?php foreach ($rows as $a): $isWarn = in_array($a['action'], $warn, true); ?>
      <<?= $a['record_id'] && $a['rtitle'] ? 'a href="record.php?id=' . (int)$a['record_id'] . '"' : 'div' ?> class="list-item" style="--c:<?= $isWarn ? 'var(--red)' : 'var(--accent)' ?>">
        <span class="li-ico"><i class="bi <?= h($icons[$a['action']] ?? 'bi-dot') ?>"></i></span>
        <span class="li-main"><strong><?= h($a['fullname'] ?: $a['username'] ?: 'Unknown') ?> <span class="muted" style="font-weight:500"><?= h(it_activity_text($a)) ?></span></strong>
          <small><?= h(date('D d M Y, H:i:s', strtotime($a['created_at']))) ?><?= $a['ip'] ? ' · IP ' . h($a['ip']) : '' ?><?= in_array($a['action'], ['update', 'create'], true) && $a['summary'] ? ' · ' . h($a['summary']) : '' ?></small></span>
      </<?= $a['record_id'] && $a['rtitle'] ? 'a' : 'div' ?>>
    <?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php
it_pager(['page' => $page, 'pages' => max(1, (int)ceil($total / $per))]);
include __DIR__ . '/includes/layout_footer.php';
