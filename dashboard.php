<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
$me = it_require_access();
$pageKey = 'dashboard';
$ps = it_page($pageKey);
$G = it_page('_global');
$alertDays = max(1, (int)($G['alert_days'] ?? 30));
$pages = it_pages();
$acc = fn($k) => it_hex(it_page($k)['accent'] ?? '', '#6366f1');

$count = function (string $entity, string $extra = '', array $args = []) use ($conn): int {
    $st = $conn->prepare("SELECT COUNT(*) FROM it_records WHERE entity = ? AND deleted_at IS NULL $extra");
    $st->execute(array_merge([$entity], $args));
    return (int)$st->fetchColumn();
};
$soon = date('Y-m-d', strtotime("+$alertDays days"));
$week = date('Y-m-d', strtotime('+7 days'));
$today = date('Y-m-d');

$tiles = [];
if (it_can('vault')) {
    $n = $count('vault', "AND due_date IS NOT NULL AND due_date <= ?", [$soon]);
    $tiles[] = ['vault', 'Passwords saved', $count('vault'), $n ? "$n need changing" : 'All up to date', $n ? 'var(--amber)' : 'var(--green)'];
}
$n = $count('asset', "AND status = 'Repair'");
$tiles[] = ['inventory', 'Devices', $count('asset', "AND status NOT IN ('Retired','Lost')"), $n ? "$n in repair" : 'None in repair', $n ? 'var(--amber)' : 'var(--green)'];
$off = $count('camera', "AND status IN ('Offline','Faulty')") + $count('nvr', "AND status IN ('Offline','Faulty')");
$tiles[] = ['cameras', 'Cameras', $count('camera'), $off ? "$off offline / faulty" : 'All online', $off ? 'var(--red)' : 'var(--green)'];
$od = $count('maintenance', "AND status = 'Active' AND due_date < ?", [$today]);
$wk = $count('maintenance', "AND status = 'Active' AND due_date BETWEEN ? AND ?", [$today, $week]);
$tiles[] = ['maintenance', 'Tasks due this week', $wk + $od, $od ? "$od overdue" : 'Nothing overdue', $od ? 'var(--red)' : 'var(--green)'];
$n = $count('license', "AND status = 'Active' AND due_date <= ?", [$soon]);
$tiles[] = ['licenses', 'Renewals coming', $n, "next $alertDays days", $n ? 'var(--amber)' : 'var(--green)'];
$n = $count('request', "AND status = 'Open'");
$tiles[] = ['requests', 'Open requests', $count('request', "AND status <> 'Closed'"), $n ? "$n not started" : 'All picked up', $n ? 'var(--blue)' : 'var(--green)'];

// Needs attention: anything with a date coming up, plus broken things.
$st = $conn->prepare("SELECT id, entity, title, status, due_date FROM it_records
    WHERE deleted_at IS NULL AND due_date IS NOT NULL AND due_date <= ?
      AND COALESCE(status,'') NOT IN ('Paused','Completed','Retired','Lost','Cancelled','Disabled','Closed')
      " . (it_can('vault') ? '' : "AND entity <> 'vault'") . "
    ORDER BY due_date ASC LIMIT 40");
$st->execute([$soon]);
$attention = $st->fetchAll();
$st = $conn->query("SELECT id, entity, title, status, NULL due_date FROM it_records WHERE deleted_at IS NULL AND ((entity IN ('camera','nvr') AND status IN ('Offline','Faulty')) OR (entity = 'asset' AND status = 'Repair')) ORDER BY updated_at DESC LIMIT 20");
$attention = array_merge($st->fetchAll(), $attention);

$st = $conn->prepare("SELECT * FROM it_records WHERE entity = 'maintenance' AND deleted_at IS NULL AND status = 'Active' AND due_date <= ? ORDER BY due_date ASC LIMIT 12");
$st->execute([$week]);
$tasks = $st->fetchAll();

$reqs = $conn->query("SELECT * FROM it_records WHERE entity = 'request' AND deleted_at IS NULL AND status <> 'Closed' ORDER BY FIELD(JSON_UNQUOTE(JSON_EXTRACT(data,'$.priority')),'Urgent','High','Normal','Low'), created_at ASC LIMIT 8")->fetchAll();
foreach ($reqs as &$r) $r['data'] = json_decode($r['data'], true) ?: [];
unset($r);

$acts = $conn->query("SELECT a.*, u.fullname, u.username, r.title rtitle FROM it_activity a LEFT JOIN `user` u ON u.id = a.user_id LEFT JOIN it_records r ON r.id = a.record_id ORDER BY a.id DESC LIMIT 8")->fetchAll();

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = it_friendly_name($conn, (int)$me['user_id']) ?: $me['username'];

include __DIR__ . '/includes/layout_header.php';
$actions = it_can('settings') ? '<a class="btn btn-ghost" href="customize.php?page=dashboard"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>' : '';
it_page_head(['icon' => $ps['icon'], 'title' => "$greet, $first", 'subtitle' => $ps['subtitle'] . ' · ' . date('l, d F Y')], $actions);

$defs = it_entities();
$widgets = array_values(array_filter($ps['widgets'], fn($w) => !empty($w['on'])));
$half = [];

foreach ($widgets as $wi => $w):
    ob_start();
    switch ($w['key']):
    case 'stats': ?>
      <div class="grid grid-stats" style="margin-bottom:var(--gap)">
        <?php foreach ($tiles as $i => [$pk, $lbl, $num, $sub, $tone]): $tp = it_page($pk); ?>
          <a class="card stat rise" style="--i:<?= $i ?>;--c:<?= h($acc($pk)) ?>" href="<?= h($pages[$pk]['file']) ?>">
            <span class="stat-ico"><i class="bi <?= h($tp['icon']) ?>"></i></span>
            <span class="num" data-count="<?= (int)$num ?>">0</span>
            <span class="lbl"><?= h($lbl) ?></span>
            <span class="sub" style="color:<?= $tone ?>"><?= h($sub) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php break;
    case 'attention': ?>
      <div class="card rise">
        <div class="card-head"><i class="bi bi-bell"></i><h3>Needs attention</h3><span class="badge plain tone-<?= $attention ? 'amber' : 'green' ?>"><?= count($attention) ?></span></div>
        <?php if (!$attention): ?>
          <div class="empty" style="padding:34px 20px"><div class="e-ico" style="color:var(--green);background:var(--green-bg)"><i class="bi bi-emoji-sunglasses"></i></div><h3>All clear</h3><p>Nothing is due or broken in the next <?= $alertDays ?> days.</p></div>
        <?php else: ?><div class="list" style="margin-top:8px;max-height:420px;overflow-y:auto">
          <?php foreach ($attention as $a): $ad = $defs[$a['entity']]; $pk = it_entity_page($a['entity']); ?>
            <a class="list-item" href="record.php?id=<?= (int)$a['id'] ?>" style="--c:<?= h($acc($pk)) ?>">
              <span class="li-ico"><i class="bi <?= h($ad['icon']) ?>"></i></span>
              <span class="li-main"><strong><?= h($a['title']) ?></strong><small><?= h($ad['singular']) ?><?php if ($a['due_date']) echo ' · ' . h(it_fields_label($a['entity'], $ad['due_field'] ?? '')); ?></small></span>
              <?= $a['due_date'] ? it_due_chip($a['due_date']) : '<span class="badge tone-' . it_tone($a['status']) . '">' . h($a['status']) . '</span>' ?>
            </a>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    <?php break;
    case 'today': ?>
      <div class="card rise" style="--accent:<?= h($acc('maintenance')) ?>">
        <div class="card-head"><i class="bi bi-calendar2-check"></i><h3>Maintenance this week</h3><a class="btn btn-sm btn-soft" href="maintenance.php">Schedule</a></div>
        <?php if (!$tasks): ?>
          <div class="empty" style="padding:34px 20px"><div class="e-ico"><i class="bi bi-calendar2-check"></i></div><h3>Nothing due</h3><p>No maintenance due in the next 7 days.</p><?php if (it_can('edit')): ?><a class="btn btn-ghost" href="edit.php?entity=maintenance"><i class="bi bi-plus-lg"></i> Add a recurring task</a><?php endif; ?></div>
        <?php else: ?><div class="list" style="margin-top:8px">
          <?php foreach ($tasks as $t): ?>
            <div class="list-item" data-href="record.php?id=<?= (int)$t['id'] ?>" style="cursor:pointer">
              <span class="li-ico"><i class="bi bi-wrench-adjustable"></i></span>
              <span class="li-main"><strong><?= h($t['title']) ?></strong><small><?= it_due_chip($t['due_date']) ?></small></span>
              <?php if (it_can('edit')): ?><button class="btn btn-sm btn-success" type="button" data-done="<?= (int)$t['id'] ?>"><i class="bi bi-check2"></i><span class="hide-sm">Done</span></button><?php endif; ?>
            </div>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    <?php break;
    case 'requests': ?>
      <div class="card rise" style="--accent:<?= h($acc('requests')) ?>">
        <div class="card-head"><i class="bi bi-life-preserver"></i><h3>Open IT requests</h3><?php if (it_can('edit')): ?><a class="btn btn-sm btn-soft" href="edit.php?entity=request"><i class="bi bi-plus-lg"></i> Log</a><?php endif; ?></div>
        <?php if (!$reqs): ?><p class="muted" style="padding:6px 22px 20px;margin:0">No open requests. 🎉</p>
        <?php else: ?><div class="list" style="margin-top:8px">
          <?php foreach ($reqs as $r): $pr = $r['data']['priority'] ?? 'Normal'; ?>
            <a class="list-item" href="record.php?id=<?= (int)$r['id'] ?>">
              <span class="li-ico" style="--c:var(--<?= it_tone($pr) === 'red' ? 'red' : (it_tone($pr) === 'amber' ? 'amber' : 'blue') ?>)"><i class="bi bi-flag"></i></span>
              <span class="li-main"><strong><?= h($r['title']) ?></strong><small><?= h($r['ref_code']) ?> · <?= h($r['data']['requested_by'] ?? '') ?> · <?= h(it_due_text(substr($r['created_at'], 0, 10)) === 'Today' ? 'today' : date('d M', strtotime($r['created_at']))) ?></small></span>
              <span class="badge tone-<?= it_tone($r['status']) ?>"><?= h($r['status']) ?></span>
            </a>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    <?php break;
    case 'quick':
        if (!it_can('edit')) break; ?>
      <div class="card card-pad rise">
        <h3 style="font-size:16px;margin-bottom:14px"><i class="bi bi-lightning-charge" style="color:var(--accent)"></i> Quick add</h3>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px">
          <?php foreach (['vault' => 'Password', 'asset' => 'Device', 'camera' => 'Camera', 'maintenance' => 'Task', 'license' => 'License', 'request' => 'Request', 'ip' => 'IP address', 'doc' => 'Library file'] as $ek => $l):
              if ($ek === 'vault' && !it_can('vault')) continue; $pk = it_entity_page($ek); ?>
            <a class="sheet-item card" style="--c:<?= h($acc($pk)) ?>;box-shadow:none" href="<?= $ek === 'doc' ? 'library.php' : 'edit.php?entity=' . $ek ?>"><span><i class="bi <?= h($defs[$ek]['icon']) ?>"></i></span><?= h($l) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php break;
    case 'activity': ?>
      <div class="card rise">
        <div class="card-head"><i class="bi bi-clock-history"></i><h3>Recent activity</h3><a class="btn btn-sm btn-soft" href="activity.php">All</a></div>
        <div class="timeline">
          <?php foreach ($acts as $a): ?>
            <div class="tl-item"><strong><?= h($a['fullname'] ?: $a['username'] ?: 'System') ?></strong> <span class="muted"><?= h(it_activity_text($a)) ?></span><small><?= h(date('d M, H:i', strtotime($a['created_at']))) ?></small></div>
          <?php endforeach; ?>
          <?php if (!$acts): ?><p class="muted">Nothing yet.</p><?php endif; ?>
        </div>
      </div>
    <?php break;
    endswitch;
    $html = ob_get_clean();
    if ($w['key'] === 'stats') { echo $html; continue; }
    $half[] = $html;
endforeach;

if ($half) {
    echo '<div class="grid grid-2">';
    // Two columns, filled alternately so both stay about the same height.
    $cols = [[], []];
    foreach ($half as $i => $hcard) $cols[$i % 2][] = $hcard;
    foreach ($cols as $c) echo '<div class="grid" style="align-content:start">' . implode('', $c) . '</div>';
    echo '</div>';
}

include __DIR__ . '/includes/done_modal.php';
include __DIR__ . '/includes/layout_footer.php';
