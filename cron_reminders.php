<?php
/**
 * IT Portal daily reminder email. Run once a day from cron (CLI only):
 *   php /path/to/it_portal/cron_reminders.php
 * Emails everyone on the IT access list who has an email address, listing
 * overdue items, items due in the next 7 days, and items exactly
 * "alert days" away (Settings → Security). Sends nothing when all is clear,
 * and never twice on the same day. Add --dry to print instead of sending.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/../includes/mailer.php';
it_ensure_schema($conn);
$dry = in_array('--dry', $argv ?? [], true);

$today = date('Y-m-d');
$st = $conn->prepare("SELECT COUNT(*) FROM it_activity WHERE action = 'reminder' AND created_at >= ?");
$st->execute([$today . ' 00:00:00']);
if (!$dry && (int)$st->fetchColumn() > 0) { echo "Already sent today.\n"; exit; }

$G = it_settings($conn, '_global');
$alertDays = max(1, (int)($G['alert_days'] ?? 30));
$st = $conn->prepare("SELECT id, entity, title, due_date FROM it_records
    WHERE deleted_at IS NULL AND due_date IS NOT NULL
      AND COALESCE(status,'') NOT IN ('Paused','Completed','Retired','Lost','Cancelled','Disabled','Closed')
      AND (due_date <= ? OR due_date = ?)
    ORDER BY due_date");
$st->execute([date('Y-m-d', strtotime('+7 days')), date('Y-m-d', strtotime("+$alertDays days"))]);
$items = $st->fetchAll();
$broken = $conn->query("SELECT id, entity, title, status FROM it_records WHERE deleted_at IS NULL AND entity IN ('camera','nvr') AND status IN ('Offline','Faulty')")->fetchAll();

if (!$items && !$broken) { echo "Nothing due.\n"; exit; }

$scheme = getenv('IT_PORTAL_URL') ?: (defined('APP_BASE_URL') && APP_BASE_URL ? rtrim(APP_BASE_URL, '/') . '/it_portal' : '');
$link = fn(int $id) => $scheme ? '<a href="' . h($scheme . '/record.php?id=' . $id) . '">open</a>' : '';
$defs = it_entities();
$rows = '';
foreach ($items as $i) {
    $s = it_due_state($i['due_date'], 7);
    $color = in_array($s, ['overdue', 'today'], true) ? '#dc2626' : ($s === 'soon' ? '#c27c0e' : '#5b6275');
    $rows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee">' . h($defs[$i['entity']]['singular'] ?? $i['entity']) . '</td>'
           . '<td style="padding:8px 10px;border-bottom:1px solid #eee"><b>' . h($i['title']) . '</b></td>'
           . '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:' . $color . ';font-weight:700">' . h(it_due_text($i['due_date'])) . '</td>'
           . '<td style="padding:8px 10px;border-bottom:1px solid #eee">' . $link((int)$i['id']) . '</td></tr>';
}
foreach ($broken as $b) {
    $rows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee">' . h($defs[$b['entity']]['singular']) . '</td><td style="padding:8px 10px;border-bottom:1px solid #eee"><b>' . h($b['title']) . '</b></td>'
           . '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:#dc2626;font-weight:700">' . h($b['status']) . '</td><td style="padding:8px 10px;border-bottom:1px solid #eee">' . $link((int)$b['id']) . '</td></tr>';
}
$count = count($items) + count($broken);
$subject = $G['brand'] . ': ' . $count . ' item' . ($count === 1 ? '' : 's') . ' need attention';
$html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1e2235;max-width:640px">'
      . '<h2 style="margin:0 0 6px">' . h($G['brand']) . '</h2><p style="color:#6b7189;margin:0 0 16px">' . h(date('l, d F Y')) . ' — these need you:</p>'
      . '<table style="border-collapse:collapse;width:100%;font-size:14px">' . $rows . '</table>'
      . '<p style="color:#9aa0b4;font-size:12px;margin-top:18px">Sent once a day by the IT Portal. Passwords are never included in emails.</p></div>';

$people = $conn->query("SELECT u.email, u.username FROM it_access a JOIN `user` u ON u.id = a.user_id WHERE a.is_active = 1 AND u.email IS NOT NULL AND u.email <> ''")->fetchAll();
if ($dry) { echo $subject . "\nTo: " . implode(', ', array_column($people, 'email')) . "\n\n" . strip_tags(str_replace('</tr>', "\n", $html)) . "\n"; exit; }

$settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1")->fetch();
if (!$settings || (int)$settings['is_enabled'] !== 1) { echo "Email sending is turned off in the main system settings.\n"; exit(1); }
$sent = 0;
foreach ($people as $p) {
    $r = send_smtp_mail($settings, $p['email'], $subject, $html);
    if (!empty($r['ok'])) $sent++;
    else echo 'Failed for ' . $p['email'] . ': ' . ($r['error'] ?? 'unknown') . "\n";
}
$conn->prepare("INSERT INTO it_activity (user_id, action, summary, created_at) VALUES (NULL, 'reminder', ?, NOW())")
     ->execute(['Daily reminder: ' . $count . ' items, sent to ' . $sent . ' of ' . count($people)]);
echo "Sent to $sent of " . count($people) . ".\n";
