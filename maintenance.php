<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
$pageKey = 'maintenance';
$ps = it_page($pageKey);
$mode = in_array($_GET['mode'] ?? '', ['board', 'calendar', 'history', 'list'], true) ? $_GET['mode'] : 'board';

if ($mode === 'list') {
    include __DIR__ . '/includes/layout_header.php';
    it_list_page($pageKey, ['mode' => 'list'], '<div class="tabs">' . maint_tabs($mode) . '</div>');
    include __DIR__ . '/includes/done_modal.php';
    include __DIR__ . '/includes/layout_footer.php';
    exit;
}

function maint_tabs(string $mode): string {
    $out = '';
    foreach (['board' => ['bi-kanban', 'Board'], 'calendar' => ['bi-calendar3', 'Calendar'], 'history' => ['bi-journal-check', 'Done history'], 'list' => ['bi-list-ul', 'All tasks']] as $k => [$i, $l]) {
        $out .= '<a class="tab ' . ($mode === $k ? 'active' : '') . '" href="?mode=' . $k . '"><i class="bi ' . $i . '"></i>' . $l . '</a>';
    }
    return $out;
}

$st = $conn->query("SELECT * FROM it_records WHERE entity = 'maintenance' AND deleted_at IS NULL ORDER BY due_date IS NULL, due_date, title");
$tasks = $st->fetchAll();
foreach ($tasks as &$t) $t['data'] = json_decode($t['data'], true) ?: [];
unset($t);

include __DIR__ . '/includes/layout_header.php';
$actions = '';
if (it_can('settings')) $actions .= '<a class="btn btn-ghost" href="customize.php?page=maintenance"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>';
if (it_can('edit')) $actions .= '<a class="btn btn-primary" href="edit.php?entity=maintenance"><i class="bi bi-plus-lg"></i> Add task</a>';
it_page_head($ps, $actions);
echo '<div class="tabs">' . maint_tabs($mode) . '</div>';

$canEdit = it_can('edit');
$taskCard = function (array $t, int $i) use ($canEdit) {
    $steps = count(array_filter(array_map('trim', preg_split('/\R/', (string)($t['data']['checklist'] ?? ''))), 'strlen'));
    ob_start(); ?>
    <div class="card task rise" style="--i:<?= min($i, 12) ?>" data-row data-href="record.php?id=<?= (int)$t['id'] ?>">
      <div class="task-top"><strong><?= h($t['title']) ?></strong><?php if ($t['due_date']) echo it_due_chip($t['due_date']); ?></div>
      <div class="task-meta">
        <span><i class="bi bi-arrow-repeat"></i> <?= h($t['data']['frequency'] ?? 'Once') ?></span>
        <?php if (!empty($t['data']['category'])): ?><span>· <?= h($t['data']['category']) ?></span><?php endif; ?>
        <?php if ($steps): ?><span>· <i class="bi bi-list-check"></i> <?= $steps ?> steps</span><?php endif; ?>
        <?php if (!empty($t['data']['assigned_to'])): ?><span>· <i class="bi bi-person"></i> <?= h($t['data']['assigned_to']) ?></span><?php endif; ?>
      </div>
      <?php if ($canEdit && $t['status'] === 'Active'): ?><button class="btn btn-sm btn-soft" type="button" data-done="<?= (int)$t['id'] ?>" style="align-self:flex-start"><i class="bi bi-check2-circle"></i> Mark done</button><?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

if ($mode === 'board'):
    $cols = ['overdue' => ['Overdue', 'bi-exclamation-octagon', []], 'week' => ['This week', 'bi-calendar-week', []], 'later' => ['Later', 'bi-calendar3', []], 'paused' => ['Paused / completed', 'bi-pause-circle', []]];
    foreach ($tasks as $t) {
        if ($t['status'] !== 'Active') { $cols['paused'][2][] = $t; continue; }
        $s = it_due_state($t['due_date'], 7);
        $cols[$s === 'overdue' ? 'overdue' : (in_array($s, ['today', 'soon'], true) ? 'week' : 'later')][2][] = $t;
    }
    if (!$tasks): ?>
      <div class="card empty"><div class="e-ico"><i class="bi bi-calendar2-check"></i></div><h3>No maintenance tasks yet</h3>
        <p>Add the checks you do again and again — backups, CCTV recording, printer service. The next date is set for you each time you mark one done.</p>
        <?php if ($canEdit): ?><a class="btn btn-primary" href="edit.php?entity=maintenance"><i class="bi bi-plus-lg"></i> Add the first task</a><?php endif; ?></div>
    <?php else: ?>
      <div class="board">
        <?php foreach ($cols as $k => [$label, $icon, $items]): ?>
          <div class="board-col">
            <h4><i class="bi <?= $icon ?>" style="color:<?= $k === 'overdue' ? 'var(--red)' : 'var(--accent)' ?>"></i><?= $label ?><span class="count"><?= count($items) ?></span></h4>
            <?php foreach ($items as $i => $t) echo $taskCard($t, $i); ?>
            <?php if (!$items): ?><div class="faint" style="font-size:13px;padding:10px 4px">Nothing here</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif;

elseif ($mode === 'calendar'):
    $ym = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
    $first = new DateTime($ym . '-01');
    $start = (clone $first)->modify('-' . (((int)$first->format('N')) - 1) . ' days'); // Monday first
    $end = (clone $first)->modify('last day of this month');
    $end->modify('+' . (7 - (int)$end->format('N')) . ' days');
    $events = [];
    // Future occurrences of repeating tasks, so the month shows the whole plan.
    foreach ($tasks as $t) {
        if ($t['status'] !== 'Active' || !$t['due_date']) continue;
        $d = new DateTime($t['due_date']);
        $freq = $t['data']['frequency'] ?? 'Once';
        $step = ['Daily' => '+1 day', 'Weekly' => '+1 week', 'Monthly' => '+1 month', 'Quarterly' => '+3 months', 'Half-yearly' => '+6 months', 'Yearly' => '+1 year'][$freq] ?? null;
        $n = 0;
        while ($d <= $end && $n++ < 400) {
            if ($d >= $start) $events[$d->format('Y-m-d')][] = ['t' => $t, 'cls' => $d < new DateTime('today') ? 'overdue' : ''];
            if (!$step) break;
            $d->modify($step);
        }
    }
    $st = $conn->prepare("SELECT l.done_at, r.title, r.id FROM it_maintenance_logs l JOIN it_records r ON r.id = l.record_id WHERE l.done_at BETWEEN ? AND ?");
    $st->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    foreach ($st->fetchAll() as $l) $events[substr($l['done_at'], 0, 10)][] = ['t' => ['id' => $l['id'], 'title' => $l['title']], 'cls' => 'done'];
    $prev = (clone $first)->modify('-1 month')->format('Y-m');
    $next = (clone $first)->modify('+1 month')->format('Y-m'); ?>
    <div class="card card-pad rise">
      <div class="cal-head">
        <a class="icon-btn" href="?mode=calendar&m=<?= $prev ?>"><i class="bi bi-chevron-left"></i></a>
        <h3><?= $first->format('F Y') ?></h3>
        <a class="btn btn-sm btn-ghost" href="?mode=calendar">Today</a>
        <a class="icon-btn" href="?mode=calendar&m=<?= $next ?>"><i class="bi bi-chevron-right"></i></a>
      </div>
      <div class="cal">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) echo '<div class="dow">' . $d . '</div>'; ?>
        <?php for ($d = clone $start; $d <= $end; $d->modify('+1 day')): $k = $d->format('Y-m-d'); ?>
          <div class="day <?= $d->format('m') !== $first->format('m') ? 'other' : '' ?> <?= $k === date('Y-m-d') ? 'today' : '' ?>">
            <span class="dn"><?= $d->format('j') ?></span>
            <?php foreach (array_slice($events[$k] ?? [], 0, 4) as $e): ?>
              <a class="ev <?= $e['cls'] ?>" href="record.php?id=<?= (int)$e['t']['id'] ?>" title="<?= h($e['t']['title']) ?>"><?= $e['cls'] === 'done' ? '✓ ' : '' ?><span><?= h($e['t']['title']) ?></span></a>
            <?php endforeach; ?>
            <?php if (count($events[$k] ?? []) > 4): ?><small class="faint">+<?= count($events[$k]) - 4 ?> more</small><?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
      <p class="faint" style="font-size:12.5px;margin:14px 0 0"><span class="ev" style="display:inline;padding:2px 6px;border-radius:6px;background:color-mix(in srgb,var(--accent) 13%,transparent);color:var(--accent)">Planned</span> &nbsp;<span style="padding:2px 6px;border-radius:6px;background:var(--red-bg);color:var(--red);font-weight:700">Overdue</span> &nbsp;<span style="padding:2px 6px;border-radius:6px;background:var(--green-bg);color:var(--green);font-weight:700">✓ Done</span></p>
    </div>
<?php
else: // history
    $st = $conn->query("SELECT l.*, r.title, u.fullname, u.username FROM it_maintenance_logs l JOIN it_records r ON r.id = l.record_id LEFT JOIN `user` u ON u.id = l.done_by ORDER BY l.done_at DESC LIMIT 200");
    $logs = $st->fetchAll(); ?>
    <div class="card rise">
      <?php if (!$logs): ?>
        <div class="empty"><div class="e-ico"><i class="bi bi-journal-check"></i></div><h3>Nothing done yet</h3><p>Each time you press “Mark done” it is recorded here.</p></div>
      <?php else: ?>
        <div class="table-wrap"><table class="tbl"><thead><tr><th>When</th><th>Task</th><th>Result</th><th>By</th><th>Checklist</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($logs as $l): $cl = json_decode((string)$l['checklist'], true) ?: []; ?>
          <tr data-href="record.php?id=<?= (int)$l['record_id'] ?>">
            <td style="white-space:nowrap"><?= h(date('d M Y, H:i', strtotime($l['done_at']))) ?></td>
            <td class="t-title"><?= h($l['title']) ?></td>
            <td><span class="badge tone-<?= $l['result'] === 'ok' ? 'green' : 'amber' ?>"><?= $l['result'] === 'ok' ? 'All good' : 'Problem' ?></span></td>
            <td><?= h($l['fullname'] ?: $l['username']) ?></td>
            <td><?= $cl ? count(array_filter($cl, fn($c) => !empty($c['done']))) . '/' . count($cl) : '—' ?></td>
            <td class="muted"><?= h(mb_strimwidth((string)$l['note'], 0, 80, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
<?php endif;

include __DIR__ . '/includes/done_modal.php';
include __DIR__ . '/includes/layout_footer.php';
