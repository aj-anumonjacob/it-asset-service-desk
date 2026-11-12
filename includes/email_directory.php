<?php
/**
 * Email Directory: staff addresses (from the main system's logins and their
 * companies) and shared mailboxes (it_records entity 'mailbox'), grouped by
 * company, plus the PDF and the "share by email" sending.
 */

/**
 * Staff with a login, grouped by company: [company id => ['name' => .., 'people' => [..]]].
 * Someone in two companies appears in both. $missing = true lists only people with no email.
 */
function it_staff_emails(PDO $conn, ?int $companyId = null, bool $missing = false): array {
    $companies = it_companies($conn);
    $args = [];
    $where = "u.user_type = 'internal' AND u.is_active = 1";
    if ($companyId) { $where .= ' AND uc.company_id = ?'; $args[] = $companyId; }
    $email = "COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(e.email), ''))";
    $where .= $missing ? " AND $email IS NULL" : " AND $email IS NOT NULL";
    $st = $conn->prepare("SELECT uc.company_id, u.id, u.username, u.fullname, e.nickname, $email AS email,
            COALESCE(NULLIF(TRIM(u.contactnumber), ''), NULLIF(TRIM(e.phone_work), ''), NULLIF(TRIM(e.phone), '')) AS phone,
            COALESCE(NULLIF(TRIM(e.position_title), ''), NULLIF(TRIM(u.job_title), '')) AS position,
            COALESCE(d.name, NULLIF(TRIM(u.department), '')) AS department
        FROM `user` u
        JOIN user_companies uc ON uc.user_id = u.id
        LEFT JOIN employees e ON e.id = COALESCE(u.employee_id, (SELECT e2.id FROM employees e2 WHERE e2.user_id = u.id ORDER BY e2.id LIMIT 1))
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE $where
        ORDER BY u.fullname, u.username");
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $cid = (int)$r['company_id'];
        if (!isset($companies[$cid])) continue;
        $out[$cid] ??= ['name' => $companies[$cid], 'people' => []];
        $out[$cid]['people'][] = $r;
    }
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Shared mailboxes grouped by company, same shape as it_staff_emails(). */
function it_shared_emails(PDO $conn, ?int $companyId = null, bool $activeOnly = true): array {
    $companies = it_companies($conn);
    $st = $conn->prepare("SELECT id, title, status, data FROM it_records WHERE entity = 'mailbox' AND deleted_at IS NULL" . ($activeOnly ? " AND COALESCE(status, 'Active') = 'Active'" : '') . " ORDER BY title");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode($r['data'], true) ?: [];
        $cid = (int)($d['company'] ?? 0);
        if ($companyId && $cid !== $companyId) continue;
        $out[$cid] ??= ['name' => $companies[$cid] ?? 'No company', 'boxes' => []];
        $out[$cid]['boxes'][] = ['id' => (int)$r['id'], 'status' => $r['status'], 'address' => $r['title']] + $d;
    }
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * Company email addresses given to employees (entity 'staffmail'), grouped by
 * company: [company id => ['name' => .., 'people' => [..]]], each with the
 * employee's name, nickname, position and department.
 */
function it_company_emails(PDO $conn, ?int $companyId = null, bool $activeOnly = true): array {
    $companies = it_companies($conn);
    $rows = $conn->query("SELECT id, title, status, data FROM it_records WHERE entity = 'staffmail' AND deleted_at IS NULL" . ($activeOnly ? " AND COALESCE(status, 'Active') = 'Active'" : '') . " ORDER BY title")->fetchAll();
    $uids = [];
    foreach ($rows as &$r) { $r['d'] = json_decode($r['data'], true) ?: []; $uids[] = (int)($r['d']['employee'] ?? 0); }
    unset($r);
    $people = [];
    if ($uids = array_values(array_unique(array_filter($uids)))) {
        $st = $conn->prepare("SELECT u.id, u.username, u.fullname, u.is_active, e.nickname,
                COALESCE(NULLIF(TRIM(e.position_title), ''), NULLIF(TRIM(u.job_title), '')) AS position,
                COALESCE(d.name, NULLIF(TRIM(u.department), '')) AS department,
                COALESCE(NULLIF(TRIM(u.contactnumber), ''), NULLIF(TRIM(e.phone_work), ''), NULLIF(TRIM(e.phone), '')) AS phone
            FROM `user` u
            LEFT JOIN employees e ON e.id = COALESCE(u.employee_id, (SELECT e2.id FROM employees e2 WHERE e2.user_id = u.id ORDER BY e2.id LIMIT 1))
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE u.id IN (" . implode(',', array_fill(0, count($uids), '?')) . ")");
        $st->execute($uids);
        foreach ($st->fetchAll() as $p) $people[(int)$p['id']] = $p;
    }
    $out = [];
    foreach ($rows as $r) {
        $cid = (int)($r['d']['company'] ?? 0);
        if ($companyId && $cid !== $companyId) continue;
        $p = $people[(int)($r['d']['employee'] ?? 0)] ?? ['username' => '', 'fullname' => 'Unknown employee', 'nickname' => '', 'position' => '', 'department' => '', 'phone' => '', 'is_active' => 0];
        $out[$cid] ??= ['name' => $companies[$cid] ?? 'No company', 'people' => []];
        $out[$cid]['people'][] = $p + ['record_id' => (int)$r['id'], 'email' => $r['title'], 'status' => $r['status'], 'aliases' => $r['d']['aliases'] ?? '', 'user_id' => (int)($r['d']['employee'] ?? 0)];
    }
    foreach ($out as &$g) usort($g['people'], fn($a, $b) => strcasecmp($a['fullname'] ?: $a['username'], $b['fullname'] ?: $b['username']));
    unset($g);
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

function it_email_list_types(): array {
    return ['company' => 'Company emails', 'staff' => 'Staff login emails', 'shared' => 'Shared mailboxes', 'both' => 'Everything'];
}

/** The directory as print HTML (PDF body). $type: company, staff, shared or both (= all three). */
function it_email_directory_html(PDO $conn, string $type, ?int $companyId): string {
    $G = it_page('_global');
    $staff = in_array($type, ['staff', 'both'], true) ? it_staff_emails($conn, $companyId) : [];
    $corp = in_array($type, ['company', 'both'], true) ? it_company_emails($conn, $companyId) : [];
    $shared = in_array($type, ['shared', 'both'], true) ? it_shared_emails($conn, $companyId) : [];
    $cids = array_unique(array_merge(array_keys($corp), array_keys($staff), array_keys($shared)));
    $companies = it_companies($conn);
    usort($cids, fn($a, $b) => strcasecmp($companies[$a] ?? '', $companies[$b] ?? ''));
    $title = ['company' => 'Company Email List', 'staff' => 'Staff Email List', 'shared' => 'Shared Email List', 'both' => 'Email Directory'][$type];
    ob_start(); ?>
<style>
  body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #1e2235; }
  .top { border-bottom: 3px solid #f97316; padding-bottom: 6px; margin-bottom: 14px; }
  .top h1 { font-size: 18pt; margin: 0; } .top .sub { color: #6b7189; font-size: 9pt; }
  h2 { font-size: 12.5pt; margin: 16px 0 4px; padding: 6px 8px; background: #1e2235; color: #fff; }
  h3 { font-size: 10pt; margin: 10px 0 4px; color: #f97316; text-transform: uppercase; letter-spacing: 1px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #6b7189; border-bottom: 1px solid #c8ccd8; padding: 5px 6px; }
  td { padding: 5px 6px; border-bottom: 1px solid #eceef4; vertical-align: top; }
  tr:nth-child(even) td { background: #f7f8fb; }
  .mail { font-weight: bold; color: #0b5cad; }
  .muted { color: #8a90a6; }
  .empty { color: #8a90a6; font-style: italic; padding: 4px 6px; }
</style>
<div class="top">
  <h1><?= h($title) ?></h1>
  <div class="sub"><?= h($companyId ? ($companies[$companyId] ?? '') : 'All companies') ?> · <?= h($G['tagline'] ?? '') ?> · <?= h(date('d M Y, H:i')) ?></div>
</div>
<?php if (!$cids): ?><p class="empty">No email addresses to show.</p><?php endif; ?>
<?php foreach ($cids as $cid): ?>
  <h2><?= h($companies[$cid] ?? 'No company') ?></h2>
  <?php if (in_array($type, ['company', 'both'], true)): $cp = $corp[$cid]['people'] ?? []; ?>
    <h3>Company emails (<?= count($cp) ?>)</h3>
    <?php if (!$cp): ?><div class="empty">No company email addresses.</div><?php else: ?>
    <table><thead><tr><th style="width:28%">Name</th><th style="width:32%">Company email</th><th style="width:24%">Position / department</th><th>Phone</th></tr></thead><tbody>
      <?php foreach ($cp as $p): ?>
        <tr><td><?= h($p['fullname'] ?: $p['username']) ?><?= $p['nickname'] ? ' <span class="muted">(' . h($p['nickname']) . ')</span>' : '' ?></td>
          <td class="mail"><?= h($p['email']) ?><?= $p['aliases'] ? '<br><span class="muted">' . h($p['aliases']) . '</span>' : '' ?></td>
          <td><?= h(trim(($p['position'] ?? '') . (($p['position'] ?? '') && ($p['department'] ?? '') ? ' · ' : '') . ($p['department'] ?? ''))) ?: '<span class="muted">—</span>' ?></td>
          <td><?= h($p['phone'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (in_array($type, ['staff', 'both'], true)): $people = $staff[$cid]['people'] ?? []; ?>
    <h3>Staff login emails (<?= count($people) ?>)</h3>
    <?php if (!$people): ?><div class="empty">No staff email addresses.</div><?php else: ?>
    <table><thead><tr><th style="width:28%">Name</th><th style="width:30%">Email</th><th style="width:24%">Position / department</th><th>Phone</th></tr></thead><tbody>
      <?php foreach ($people as $p): ?>
        <tr><td><?= h($p['fullname'] ?: $p['username']) ?><?= $p['nickname'] ? ' <span class="muted">(' . h($p['nickname']) . ')</span>' : '' ?></td>
          <td class="mail"><?= h($p['email']) ?></td>
          <td><?= h(trim(($p['position'] ?? '') . (($p['position'] ?? '') && ($p['department'] ?? '') ? ' · ' : '') . ($p['department'] ?? ''))) ?: '<span class="muted">—</span>' ?></td>
          <td><?= h($p['phone'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (in_array($type, ['shared', 'both'], true)): $boxes = $shared[$cid]['boxes'] ?? []; ?>
    <h3>Shared mailboxes (<?= count($boxes) ?>)</h3>
    <?php if (!$boxes): ?><div class="empty">No shared mailboxes.</div><?php else: ?>
    <table><thead><tr><th style="width:30%">Email</th><th style="width:24%">Name</th><th style="width:18%">Type</th><th>Department / who reads it</th></tr></thead><tbody>
      <?php foreach ($boxes as $b): ?>
        <tr><td class="mail"><?= h($b['address']) ?></td><td><?= h($b['display_name'] ?? '') ?></td><td><?= h($b['kind'] ?? '') ?></td>
          <td><?= h($b['department'] ?? '') ?><?php $m = trim((string)($b['members'] ?? '')); if ($m !== '') echo '<br><span class="muted">' . nl2br(h($m)) . '</span>'; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  <?php endif; ?>
<?php endforeach; ?>
<p class="muted" style="margin-top:14px;font-size:8pt">Contact list only — no passwords. Produced by <?= h($G['brand'] ?? 'IT Portal') ?>.</p>
<?php
    return ob_get_clean();
}

/** Writes the PDF to a temp file and returns its path. */
function it_email_directory_pdf(PDO $conn, string $type, ?int $companyId): string {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $root = dirname(__DIR__, 2);
    $tmp = '';
    foreach ([$root . '/uploads/temp/mpdf', $root . '/uploads/mpdf_tmp', rtrim(sys_get_temp_dir(), '/') . '/it_portal_mpdf'] as $cand) {
        if (!is_dir($cand)) @mkdir($cand, 0777, true);
        if (is_dir($cand) && is_writable($cand)) { $tmp = $cand; break; }
    }
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 14, 'tempDir' => $tmp ?: sys_get_temp_dir()]);
    $mpdf->SetTitle('Email Directory');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML(it_email_directory_html($conn, $type, $companyId));
    $file = ($tmp ?: sys_get_temp_dir()) . '/emails_' . bin2hex(random_bytes(6)) . '.pdf';
    $mpdf->Output($file, \Mpdf\Output\Destination::FILE);
    return $file;
}

function it_email_directory_filename(PDO $conn, string $type, ?int $companyId): string {
    $c = $companyId ? (it_companies($conn)[$companyId] ?? 'company') : 'all-companies';
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($c)), '-');
    return ['company' => 'company-emails', 'staff' => 'staff-emails', 'shared' => 'shared-emails', 'both' => 'email-directory'][$type] . '-' . $slug . '-' . date('Y-m-d') . '.pdf';
}
