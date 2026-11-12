<?php
/**
 * IT Portal — JSON actions. GET: search, task. POST (CSRF): unlock, set_pin,
 * lock, reveal, delete, restore, purge, complete.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_photos.php';
$me = it_require_access(true);
$do = (string)($_REQUEST['do'] ?? '');

/* ---------------- reads ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($do === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) it_json(['ok' => true, 'results' => []]);
        $like = '%' . $q . '%';
        $sql = "SELECT id, entity, title, status, ref_code, data FROM it_records WHERE deleted_at IS NULL AND (title LIKE ? OR ref_code LIKE ? OR data LIKE ?)"
             . (it_can('vault') ? '' : " AND entity <> 'vault'") . " ORDER BY (title LIKE ?) DESC, updated_at DESC LIMIT 25";
        $st = $conn->prepare($sql);
        $st->execute([$like, $like, '%' . trim(json_encode($q), '"') . '%', $q . '%']);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $def = it_entities()[$r['entity']] ?? null;
            if (!$def) continue;
            $d = json_decode($r['data'], true) ?: [];
            // Say which field matched, so "192.168.1.20" shows where it was found.
            $hit = '';
            foreach (it_effective_fields($r['entity']) as $f) {
                $v = (string)($d[$f['key']] ?? '');
                if ($f['type'] !== 'link' && $v !== '' && $f['key'] !== $def['title_field'] && mb_stripos($v, $q) !== false) { $hit = $f['label'] . ': ' . mb_strimwidth($v, 0, 60, '…'); break; }
            }
            $ps = it_page(it_entity_page($r['entity']));
            $out[] = [
                'id' => (int)$r['id'], 'title' => $r['title'], 'kind' => $def['singular'], 'icon' => $def['icon'],
                'accent' => it_hex($ps['accent'] ?? '', '#6366f1'), 'sub' => $hit ?: ($r['ref_code'] ?: ($d[$def['category_field'] ?? ''] ?? '')),
                'status' => $r['status'], 'tone' => it_tone($r['status']),
            ];
        }
        it_json(['ok' => true, 'results' => $out]);
    }
    if ($do === 'task') {
        $r = it_record($conn, (int)($_GET['id'] ?? 0));
        if (!$r || $r['entity'] !== 'maintenance') it_json(['ok' => false, 'error' => 'Task not found.'], 404);
        $steps = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($r['data']['checklist'] ?? ''))), 'strlen'));
        it_json(['ok' => true, 'id' => (int)$r['id'], 'name' => $r['title'], 'frequency' => $r['data']['frequency'] ?? 'Once', 'checklist' => $steps]);
    }
    it_json(['ok' => false, 'error' => 'Unknown action'], 400);
}

/* ---------------- writes ---------------- */
/** Which page's sections a section action changes; checks the person may. */
function it_section_group(): string {
    $g = (string)($_POST['group'] ?? 'vault');
    if (empty(it_entities()[$g]['sections'])) it_json(['ok' => false, 'error' => 'Unknown list.'], 400);
    if (!it_can('edit') || ($g === 'vault' && !it_can('vault'))) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
    return $g;
}

if (!it_csrf_ok()) it_json(['ok' => false, 'error' => 'The page expired. Reload it and try again.'], 419);
$G = it_page('_global');
$unlockSeconds = max(1, min(60, (int)($G['vault_unlock_minutes'] ?? 5))) * 60;

switch ($do) {
    case 'set_pin':
        if (!it_can('vault')) it_json(['ok' => false, 'error' => 'No vault access.'], 403);
        if (!empty($me['pin_hash'])) it_json(['ok' => false, 'error' => 'A PIN already exists. Change it in Settings.']);
        $pin = (string)($_POST['pin'] ?? '');
        if (!preg_match('/^\d{4,6}$/', $pin)) it_json(['ok' => false, 'error' => 'PIN must be 4 to 6 digits.']);
        if ($pin !== (string)($_POST['pin_confirm'] ?? '')) it_json(['ok' => false, 'error' => 'The two PINs do not match.']);
        if (preg_match('/^(\d)\1+$/', $pin) || in_array($pin, ['1234', '12345', '123456', '4321', '654321'], true)) it_json(['ok' => false, 'error' => 'That PIN is too easy to guess. Pick another.']);
        $conn->prepare("UPDATE it_access SET pin_hash = ?, pin_fails = 0, pin_locked_until = NULL WHERE user_id = ?")->execute([password_hash($pin, PASSWORD_DEFAULT), $me['user_id']]);
        $_SESSION['it_vault_until'] = time() + $unlockSeconds;
        it_log($conn, 'pin_set', null, null, 'Vault PIN created');
        it_json(['ok' => true, 'left' => $unlockSeconds]);

    case 'unlock':
        if (!it_can('vault')) it_json(['ok' => false, 'error' => 'No vault access.'], 403);
        if (empty($me['pin_hash'])) it_json(['ok' => false, 'error' => 'Create a PIN first.']);
        if ($me['pin_locked_until'] && strtotime($me['pin_locked_until']) > time()) {
            it_json(['ok' => false, 'error' => 'Too many wrong PINs. Try again after ' . date('H:i', strtotime($me['pin_locked_until'])) . '.']);
        }
        if (password_verify((string)($_POST['pin'] ?? ''), $me['pin_hash'])) {
            $conn->prepare("UPDATE it_access SET pin_fails = 0, pin_locked_until = NULL WHERE user_id = ?")->execute([$me['user_id']]);
            $_SESSION['it_vault_until'] = time() + $unlockSeconds;
            it_log($conn, 'vault_unlock', null, null, 'Vault opened');
            it_json(['ok' => true, 'left' => $unlockSeconds]);
        }
        $fails = (int)$me['pin_fails'] + 1;
        $lock = $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $conn->prepare("UPDATE it_access SET pin_fails = ?, pin_locked_until = ? WHERE user_id = ?")->execute([$lock ? 0 : $fails, $lock, $me['user_id']]);
        it_log($conn, 'pin_failed', null, null, 'Wrong vault PIN' . ($lock ? ' — locked for 15 minutes' : ''));
        it_json(['ok' => false, 'error' => $lock ? 'Too many wrong PINs. The vault is locked for 15 minutes.' : 'Wrong PIN. ' . (5 - $fails) . ' tries left.']);

    case 'lock':
        unset($_SESSION['it_vault_until']);
        it_json(['ok' => true]);

    case 'reveal':
        if (!it_can('vault')) it_json(['ok' => false, 'error' => 'No vault access.'], 403);
        if (!it_vault_unlocked()) it_json(['ok' => false, 'need_pin' => true, 'error' => 'Vault is locked.']);
        $r = it_record($conn, (int)($_POST['id'] ?? 0));
        if (!$r) it_json(['ok' => false, 'error' => 'Not found.'], 404);
        $hid = (int)($_POST['history_id'] ?? 0);
        $blob = $r['secret_enc'];
        if ($hid) {
            $st = $conn->prepare("SELECT secret_enc FROM it_secret_history WHERE id = ? AND record_id = ?");
            $st->execute([$hid, $r['id']]);
            $blob = $st->fetchColumn() ?: null;
        }
        try { $plain = it_decrypt($conn, $blob); } catch (Throwable $e) { it_json(['ok' => false, 'error' => $e->getMessage()]); }
        if ($plain === null) it_json(['ok' => false, 'error' => 'This password cannot be read. The vault key may have changed.']);
        $purpose = ($_POST['purpose'] ?? '') === 'copy' ? 'copy' : 'reveal';
        it_log($conn, $purpose, $r['entity'], (int)$r['id'], ($purpose === 'copy' ? 'Copied ' : 'Viewed ') . ($hid ? 'an old password of ' : 'the secret of ') . '“' . $r['title'] . '”');
        it_json(['ok' => true, 'secret' => $plain]);

    case 'delete':
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $r = it_record($conn, (int)($_POST['id'] ?? 0));
        if (!$r) it_json(['ok' => false, 'error' => 'Already deleted.']);
        $conn->prepare("UPDATE it_records SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")->execute([it_user_id(), $r['id']]);
        it_log($conn, 'delete', $r['entity'], (int)$r['id'], 'Moved “' . $r['title'] . '” to the Recycle Bin');
        it_json(['ok' => true]);

    case 'restore':
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $r = it_record($conn, (int)($_POST['id'] ?? 0), true);
        if (!$r || !$r['deleted_at']) it_json(['ok' => false, 'error' => 'Nothing to restore.']);
        $conn->prepare("UPDATE it_records SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$r['id']]);
        it_log($conn, 'restore', $r['entity'], (int)$r['id'], 'Restored “' . $r['title'] . '”');
        it_json(['ok' => true]);

    case 'purge':
        if (!it_can('purge')) it_json(['ok' => false, 'error' => 'Only the IT admin can delete forever.'], 403);
        $r = it_record($conn, (int)($_POST['id'] ?? 0), true);
        if (!$r || !$r['deleted_at']) it_json(['ok' => false, 'error' => 'Move it to the Recycle Bin first.']);
        $conn->beginTransaction();
        $conn->prepare("DELETE FROM it_secret_history WHERE record_id = ?")->execute([$r['id']]);
        $conn->prepare("DELETE FROM it_maintenance_logs WHERE record_id = ?")->execute([$r['id']]);
        foreach (it_photos($conn, (int)$r['id']) as $ph) it_photo_delete($conn, $ph);
        $conn->prepare("DELETE FROM it_records WHERE id = ?")->execute([$r['id']]);
        $conn->commit();
        it_log($conn, 'purge', $r['entity'], (int)$r['id'], 'Deleted “' . $r['title'] . '” forever');
        it_json(['ok' => true]);

    case 'photo_upload':
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $r = it_record($conn, (int)($_POST['id'] ?? 0));
        if (!$r || empty(it_entities()[$r['entity']]['photos'])) it_json(['ok' => false, 'error' => 'Photos cannot be added here.']);
        if (empty($_FILES['photo'])) it_json(['ok' => false, 'error' => 'No photo arrived. It may be larger than the server allows (' . ini_get('post_max_size') . ').']);
        try { $res = it_photo_store($conn, (int)$r['id'], $_FILES['photo']); } catch (Throwable $e) { error_log('it photo: ' . $e->getMessage()); $res = ['ok' => false, 'error' => 'The photo could not be saved.']; }
        if ($res['ok']) it_log($conn, 'update', $r['entity'], (int)$r['id'], 'Added a photo');
        it_json($res);

    case 'library_upload':
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $folder = it_section($conn, 'doc', (string)($_POST['folder'] ?? ''));
        if (!$folder) it_json(['ok' => false, 'error' => 'Choose a folder first.']);
        $asset = (int)($_POST['asset'] ?? 0);
        if ($asset && (it_titles($conn, [$asset])[$asset]['entity'] ?? '') !== 'asset') $asset = 0;
        if (empty($_FILES['file'])) it_json(['ok' => false, 'error' => 'No file arrived. It may be larger than the server allows (' . ini_get('post_max_size') . ').']);
        try { $res = it_library_store($conn, $_FILES['file'], $folder['name'], $asset ?: null); }
        catch (Throwable $e) { error_log('it library: ' . $e->getMessage()); $res = ['ok' => false, 'error' => 'The file could not be saved.']; }
        it_json($res);

    case 'photo_delete':
    case 'photo_cover':
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $st = $conn->prepare("SELECT * FROM it_files WHERE id = ?");
        $st->execute([(int)($_POST['photo'] ?? 0)]);
        $ph = $st->fetch();
        if (!$ph) it_json(['ok' => false, 'error' => 'Photo not found.']);
        if ($do === 'photo_delete') {
            it_photo_delete($conn, $ph);
            it_log($conn, 'update', null, (int)$ph['record_id'], 'Deleted a photo');
        } else {
            $conn->prepare("UPDATE it_files SET is_cover = (id = ?) WHERE record_id = ?")->execute([$ph['id'], $ph['record_id']]);
        }
        it_json(['ok' => true]);

    case 'user_reset':
        // Main-system login reset for one staff member. Admins only, vault PIN required.
        // The new password is returned once and never stored anywhere in the portal.
        if (!it_can('settings')) it_json(['ok' => false, 'error' => 'Only the IT admin can reset passwords.'], 403);
        if (!it_vault_unlocked()) it_json(['ok' => false, 'need_pin' => true, 'error' => 'Enter your vault PIN first.']);
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === it_user_id()) it_json(['ok' => false, 'error' => 'Change your own password from your profile in the main system.']);
        $st = $conn->prepare("SELECT id, username, fullname, is_active FROM `user` WHERE id = ? AND user_type = 'internal'");
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u) it_json(['ok' => false, 'error' => 'Staff account not found.']);
        if (($_POST['mode'] ?? 'auto') === 'own') {
            $pw = (string)($_POST['password'] ?? '');
            if (mb_strlen($pw) < 8) it_json(['ok' => false, 'error' => 'Use at least 8 characters.']);
            if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) it_json(['ok' => false, 'error' => 'Use letters and numbers.']);
            if (stripos($pw, (string)$u['username']) !== false) it_json(['ok' => false, 'error' => 'The password must not contain the username.']);
            if (preg_match('/^(password|12345678|qwerty)/i', $pw)) it_json(['ok' => false, 'error' => 'That password is too easy to guess.']);
        } else {
            // No 0/O/1/l/I: it is read out or written down for the person.
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
            do {
                $pw = '';
                for ($i = 0; $i < 12; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
            } while (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/\d/', $pw));
        }
        $conn->beginTransaction();
        $conn->prepare("UPDATE `user` SET password = ?, updated_at = NOW() WHERE id = ?")->execute([password_hash($pw, PASSWORD_BCRYPT), $uid]);
        // "Remember me" sign-ins made with the old password stop working.
        try { $conn->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$uid]); } catch (Throwable $e) { }
        $conn->commit();
        it_log($conn, 'access', null, null, 'Reset the system password of ' . $u['username'] . ' (' . ($u['fullname'] ?: '—') . ')');
        it_json(['ok' => true, 'password' => $pw, 'username' => $u['username'], 'name' => $u['fullname'] ?: $u['username'], 'inactive' => !(int)$u['is_active']]);

    case 'email_share':
        // Emails the Email Directory as a PDF attachment. Contact details only — never passwords.
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        require_once __DIR__ . '/includes/email_directory.php';
        require_once __DIR__ . '/../includes/mailer.php';
        $to = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['to'] ?? ''))), 'strlen')));
        if (!$to) it_json(['ok' => false, 'error' => 'Type at least one email address.']);
        if (count($to) > 10) it_json(['ok' => false, 'error' => 'Send to 10 people or fewer at a time.']);
        foreach ($to as $addr) if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) it_json(['ok' => false, 'error' => '“' . $addr . '” is not a valid email address.']);
        $type = in_array($_POST['type'] ?? '', ['company', 'staff', 'shared', 'both'], true) ? $_POST['type'] : 'both';
        $cid = (int)($_POST['company'] ?? 0);
        $cid = isset(it_companies($conn)[$cid]) ? $cid : null;
        $settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1")->fetch();
        if (!$settings || (int)$settings['is_enabled'] !== 1) it_json(['ok' => false, 'error' => 'Email sending is turned off in the main system settings.']);
        $label = ['company' => 'Company email list', 'staff' => 'Staff email list', 'shared' => 'Shared email list', 'both' => 'Email directory'][$type] . ' — ' . ($cid ? it_companies($conn)[$cid] : 'all companies');
        $subject = trim((string)($_POST['subject'] ?? '')) ?: $label;
        $note = trim((string)($_POST['message'] ?? ''));
        $sender = it_friendly_name($conn, it_user_id()) ?: ($me['fullname'] ?: $me['username']);
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1e2235;font-size:14px">'
              . ($note !== '' ? '<p>' . nl2br(h($note)) . '</p>' : '<p>Hello,</p><p>Please find the ' . h(strtolower($label)) . ' attached as a PDF.</p>')
              . '<p style="color:#6b7189">— ' . h($sender) . '</p></div>';
        try { $file = it_email_directory_pdf($conn, $type, $cid); }
        catch (Throwable $e) { error_log('it email share: ' . $e->getMessage()); it_json(['ok' => false, 'error' => 'The PDF could not be made.']); }
        $name = it_email_directory_filename($conn, $type, $cid);
        $sent = []; $failed = [];
        foreach ($to as $addr) {
            $r = send_smtp_mail_with_attachments($settings, $addr, mb_substr($subject, 0, 150), $html, [['path' => $file, 'name' => $name]]);
            if (!empty($r['ok'])) $sent[] = $addr; else $failed[] = $addr . ' (' . ($r['error'] ?? 'failed') . ')';
        }
        @unlink($file);
        it_log($conn, 'export', 'mailbox', null, 'Emailed ' . $name . ' to ' . ($sent ? implode(', ', $sent) : 'nobody') . ($failed ? ' — failed: ' . implode(', ', $failed) : ''));
        if (!$sent) it_json(['ok' => false, 'error' => 'Could not send: ' . implode('; ', $failed)]);
        it_json(['ok' => true, 'message' => 'Sent to ' . implode(', ', $sent) . ($failed ? '. Failed: ' . implode('; ', $failed) : '.')]);

    case 'section_save':
        $grp = it_section_group();
        $sections = it_sections($conn, $grp);
        $key = (string)($_POST['key'] ?? '');
        $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 40) it_json(['ok' => false, 'error' => 'Give the section a name (up to 40 letters).']);
        foreach ($sections as $s) {
            if ($s['key'] !== $key && strcasecmp($s['name'], $name) === 0) it_json(['ok' => false, 'error' => 'There is already a section called “' . $s['name'] . '”.']);
        }
        $icon = in_array($_POST['icon'] ?? '', it_section_icons(), true) ? $_POST['icon'] : 'bi-folder2';
        $color = it_hex((string)($_POST['color'] ?? ''), '#64748b');
        if ($key === '') {
            $sections[] = ['key' => 's' . bin2hex(random_bytes(4)), 'name' => $name, 'icon' => $icon, 'color' => $color];
            it_save_sections($conn, $grp, $sections);
            it_log($conn, 'settings', null, null, 'Added ' . $grp . ' section “' . $name . '”');
            it_json(['ok' => true, 'name' => $name]);
        }
        $old = null;
        foreach ($sections as &$s) {
            if ($s['key'] === $key) { $old = $s['name']; $s = ['key' => $key, 'name' => $name, 'icon' => $icon, 'color' => $color]; }
        }
        unset($s);
        if ($old === null) it_json(['ok' => false, 'error' => 'Section not found.']);
        $conn->beginTransaction();
        it_save_sections($conn, $grp, $sections);
        if ($old !== $name) {
            // Items keep their section when it is renamed.
            $conn->prepare("UPDATE it_records SET data = JSON_SET(data, '$.category', ?) WHERE entity = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')) = ?")->execute([$name, $grp, $old]);
        }
        $conn->commit();
        it_log($conn, 'settings', null, null, $old !== $name ? 'Renamed ' . $grp . ' section “' . $old . '” to “' . $name . '”' : 'Changed ' . $grp . ' section “' . $name . '”');
        it_json(['ok' => true, 'name' => $name]);

    case 'section_move':
        $grp = it_section_group();
        $sections = it_sections($conn, $grp);
        $i = array_search((string)($_POST['key'] ?? ''), array_column($sections, 'key'), true);
        $j = $i === false ? false : $i + (($_POST['dir'] ?? '') === 'left' ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($sections)) it_json(['ok' => true]);
        [$sections[$i], $sections[$j]] = [$sections[$j], $sections[$i]];
        it_save_sections($conn, $grp, $sections);
        it_json(['ok' => true]);

    case 'section_delete':
        $grp = it_section_group();
        $sections = it_sections($conn, $grp);
        $key = (string)($_POST['key'] ?? '');
        $sec = array_values(array_filter($sections, fn($s) => $s['key'] === $key))[0] ?? null;
        if (!$sec) it_json(['ok' => false, 'error' => 'Section not found.']);
        if (count($sections) < 2) it_json(['ok' => false, 'error' => 'Keep at least one section.']);
        $st = $conn->prepare("SELECT COUNT(*) FROM it_records WHERE entity = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')) = ?");
        $st->execute([$grp, $sec['name']]);
        $n = (int)$st->fetchColumn();
        $move = it_section($conn, $grp, (string)($_POST['move_to'] ?? ''));
        if ($n > 0 && (!$move || $move['key'] === $key)) it_json(['ok' => false, 'error' => 'Choose where its ' . $n . ' item' . ($n === 1 ? '' : 's') . ' should go.']);
        $conn->beginTransaction();
        if ($n > 0) {
            // Deleted items too, so a restored item never lands in a section that no longer exists.
            $conn->prepare("UPDATE it_records SET data = JSON_SET(data, '$.category', ?) WHERE entity = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')) = ?")->execute([$move['name'], $grp, $sec['name']]);
        }
        it_save_sections($conn, $grp, array_filter($sections, fn($s) => $s['key'] !== $key));
        $conn->commit();
        it_log($conn, 'settings', null, null, 'Deleted ' . $grp . ' section “' . $sec['name'] . '”' . ($n ? ' — moved ' . $n . ' to “' . $move['name'] . '”' : ''));
        it_json(['ok' => true]);

    case 'complete':
        if (!it_can('edit')) it_json(['ok' => false, 'error' => 'Not allowed.'], 403);
        $r = it_record($conn, (int)($_POST['id'] ?? 0));
        if (!$r || $r['entity'] !== 'maintenance') it_json(['ok' => false, 'error' => 'Task not found.']);
        if (($r['status'] ?? '') === 'Completed') it_json(['ok' => false, 'error' => 'This task is already completed.']);
        $steps = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($r['data']['checklist'] ?? ''))), 'strlen'));
        $ticked = array_map('intval', (array)($_POST['ticked'] ?? []));
        $cl = [];
        foreach ($steps as $i => $s) $cl[] = ['step' => $s, 'done' => in_array($i, $ticked, true)];
        $result = ($_POST['result'] ?? 'ok') === 'issue' ? 'issue' : 'ok';
        $freq = (string)($r['data']['frequency'] ?? 'Once');
        $next = it_advance_due($r['due_date'], $freq);
        $data = $r['data'];
        $data['next_due'] = $next ?? ($r['due_date'] ?? '');
        $status = $next ? ($r['status'] ?: 'Active') : 'Completed';
        $data['status'] = $status;
        $conn->beginTransaction();
        $conn->prepare("INSERT INTO it_maintenance_logs (record_id, done_at, done_by, result, note, checklist, due_was, due_next) VALUES (?,NOW(),?,?,?,?,?,?)")
             ->execute([$r['id'], it_user_id(), $result, trim((string)($_POST['note'] ?? '')) ?: null, json_encode($cl, JSON_UNESCAPED_UNICODE), $r['due_date'], $next]);
        $conn->prepare("UPDATE it_records SET data = ?, due_date = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
             ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $next ?? $r['due_date'], $status, it_user_id(), $r['id']]);
        $conn->commit();
        it_log($conn, 'task_done', 'maintenance', (int)$r['id'], 'Done' . ($result === 'issue' ? ' — problem found' : '') . ($next ? '; next due ' . date('d M Y', strtotime($next)) : '; task completed'));
        it_json(['ok' => true, 'message' => $next ? 'Done. Next due ' . date('d M Y', strtotime($next)) . '.' : 'Done. This one-time task is now completed.']);
}

it_json(['ok' => false, 'error' => 'Unknown action'], 400);
