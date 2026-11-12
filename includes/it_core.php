<?php
/**
 * IT Portal — bootstrap: schema, access, encryption, settings, records.
 *
 * Access is an explicit list (it_access), seeded with the admin user only.
 * Being signed in to the main system is not enough: the portal asks for the
 * password itself (it_portal_auth) and locks again after the idle timeout.
 * Vault secrets are AES-256-GCM encrypted with a key kept in a file, never in
 * the database, and shown only after the vault PIN.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/it_entities.php';

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

const IT_SEED_USERNAMES = ['admin'];

/* ------------------------------------------------------------------ schema */

function it_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // The newest table: when it exists, everything before it does too.
        if ($conn->query("SHOW TABLES LIKE 'it\\_files'")->fetchColumn()) {
            it_upgrade_schema($conn);
            return;
        }
    } catch (Throwable $e) { /* fall through and create */ }

    $sql = file_get_contents(__DIR__ . '/../../migrations/it_portal_tables.sql');
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
        if ($stmt !== '') $conn->exec($stmt);
    }
}

/** Columns added after a table first shipped. Cheap: one SHOW COLUMNS per request. */
function it_upgrade_schema(PDO $conn): void {
    if (!$conn->query("SHOW COLUMNS FROM it_files LIKE 'sha1'")->fetchColumn()) {
        $conn->exec("ALTER TABLE it_files ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'photo' AFTER record_id,
                     MODIFY mime VARCHAR(100) NOT NULL DEFAULT 'image/jpeg',
                     ADD COLUMN ext VARCHAR(10) NOT NULL DEFAULT 'jpg' AFTER mime, ADD COLUMN sha1 CHAR(40) NULL AFTER ext, ADD KEY idx_sha1 (sha1)");
    }
}

/* ------------------------------------------------------------------ access */

function it_user_id(): int {
    return (int)(current_user_id() ?? 0);
}

function it_access_row(PDO $conn, ?int $uid = null): ?array {
    $uid = $uid ?? it_user_id();
    if (!$uid) return null;
    $st = $conn->prepare("SELECT a.*, u.username, u.fullname, u.email FROM it_access a JOIN `user` u ON u.id = a.user_id WHERE a.user_id = ? AND a.is_active = 1 LIMIT 1");
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** Roles: admin = everything; staff = no Settings/Access; viewer = read-only, no Vault. */
function it_can(string $what): bool {
    $role = $GLOBALS['IT_ME']['role'] ?? '';
    return match ($what) {
        'settings' => $role === 'admin',
        'edit'     => in_array($role, ['admin', 'staff'], true),
        'vault'    => in_array($role, ['admin', 'staff'], true),
        'purge'    => $role === 'admin',
        default    => $role !== '',
    };
}

function it_idle_minutes(): int {
    $g = it_settings($GLOBALS['conn'], '_global');
    return max(5, min(240, (int)($g['idle_minutes'] ?? 30)));
}

/**
 * Gate for every page. JSON endpoints pass $json = true to get a 401 instead
 * of a redirect.
 */
function it_require_access(bool $json = false): array {
    global $conn;
    it_ensure_schema($conn);
    $me = it_access_row($conn);
    $fresh = !empty($_SESSION['it_portal_auth']) && (int)$_SESSION['it_portal_auth'] === it_user_id();
    $idle = time() - (int)($_SESSION['it_last_seen'] ?? 0) > it_idle_minutes() * 60;

    if (!$me || !$fresh || $idle) {
        if ($fresh && $idle) {
            unset($_SESSION['it_portal_auth'], $_SESSION['it_vault_until']);
            $_SESSION['it_login_msg'] = 'Locked after ' . it_idle_minutes() . ' minutes of no activity. Sign in again.';
        }
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Your session ended. Please sign in again.', 'login' => true]);
            exit;
        }
        $next = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
    $_SESSION['it_last_seen'] = time();
    $GLOBALS['IT_ME'] = $me;
    return $me;
}

/* -------------------------------------------------------------- encryption */

function it_vault_key_file(): string {
    return defined('IT_VAULT_KEY_FILE') ? IT_VAULT_KEY_FILE : dirname(__DIR__, 2) . '/storage/it_vault/vault_key.php';
}

/**
 * The 32-byte key. Created on first use. Never recreated once secrets exist —
 * a new key would make every saved password unreadable.
 */
function it_vault_key(PDO $conn): string {
    static $key = null;
    if ($key !== null) return $key;
    $file = it_vault_key_file();
    if (is_file($file)) {
        $raw = base64_decode((string)(include $file), true);
        if ($raw === false || strlen($raw) !== 32) throw new RuntimeException('The vault key file is damaged: ' . $file);
        return $key = $raw;
    }
    $has = (int)$conn->query("SELECT COUNT(*) FROM it_records WHERE secret_enc IS NOT NULL")->fetchColumn()
         + (int)$conn->query("SELECT COUNT(*) FROM it_secret_history")->fetchColumn();
    if ($has > 0) {
        throw new RuntimeException('The vault key file is missing (' . $file . '). Restore it from your backup — saved passwords cannot be read without it.');
    }
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create ' . $dir);
    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    $raw = random_bytes(32);
    // A .php file that returns the key: even if the folder were served, it prints nothing.
    if (file_put_contents($file, "<?php\n// IT Portal vault key. BACK THIS FILE UP. Without it, saved passwords cannot be read.\nreturn '" . base64_encode($raw) . "';\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . $file);
    }
    @chmod($file, 0600);
    return $key = $raw;
}

function it_encrypt(PDO $conn, string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', it_vault_key($conn), OPENSSL_RAW_DATA, $iv, $tag, 'itvault');
    if ($ct === false) throw new RuntimeException('Encryption failed');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function it_decrypt(PDO $conn, ?string $blob): ?string {
    if ($blob === null || $blob === '') return null;
    if (!str_starts_with($blob, 'v1:')) return null;
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 28) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', it_vault_key($conn), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'itvault');
    return $plain === false ? null : $plain;
}

function it_vault_unlocked(): bool {
    return (int)($_SESSION['it_vault_until'] ?? 0) > time();
}

/* ---------------------------------------------------------------- activity */

function it_log(PDO $conn, string $action, ?string $entity = null, ?int $recordId = null, string $summary = ''): void {
    try {
        $conn->prepare("INSERT INTO it_activity (user_id, action, entity, record_id, summary, ip, created_at) VALUES (?,?,?,?,?,?,NOW())")
             ->execute([it_user_id() ?: null, $action, $entity, $recordId, mb_substr($summary, 0, 500), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { error_log('it_log: ' . $e->getMessage()); }
}

/* ---------------------------------------------------------------- settings */

function it_global_defaults(): array {
    return [
        'brand' => 'IT Portal', 'tagline' => 'AR Properties · IT', 'primary' => '#f97316',
        'theme' => 'steel', 'animations' => true, 'density' => 'comfortable', 'font' => 'normal',
        'idle_minutes' => 30, 'reveal_seconds' => 30, 'vault_unlock_minutes' => 5, 'alert_days' => 30,
    ];
}

function it_dashboard_widgets(): array {
    return [
        'stats' => 'Summary tiles', 'attention' => 'Needs attention', 'today' => 'Maintenance this week',
        'requests' => 'Open IT requests', 'quick' => 'Quick add', 'activity' => 'Recent activity',
    ];
}

function it_page_defaults(string $pageKey): array {
    if ($pageKey === '_global') return it_global_defaults();
    $p = it_pages()[$pageKey] ?? null;
    if (!$p) return [];
    $d = [
        'title' => $p['title'], 'subtitle' => $p['subtitle'], 'icon' => $p['icon'], 'accent' => $p['accent'],
        'in_menu' => true, 'view' => 'cards', 'per_page' => 24, 'sort' => 'updated_desc', 'show_stats' => true,
        'entities' => [],
    ];
    if ($pageKey === 'dashboard') {
        $d['widgets'] = array_map(fn($k) => ['key' => $k, 'on' => true], array_keys(it_dashboard_widgets()));
    }
    return $d;
}

function it_settings(PDO $conn, string $pageKey): array {
    static $cache = [];
    if (isset($cache[$pageKey])) return $cache[$pageKey];
    $stored = [];
    try {
        $st = $conn->prepare("SELECT settings FROM it_settings WHERE page_key = ?");
        $st->execute([$pageKey]);
        $stored = json_decode((string)$st->fetchColumn(), true) ?: [];
    } catch (Throwable $e) { }
    $s = array_replace(it_page_defaults($pageKey), $stored);
    if ($pageKey === 'dashboard') {
        // Keep widgets added in later versions.
        $known = array_column($s['widgets'] ?? [], 'key');
        foreach (array_keys(it_dashboard_widgets()) as $k) {
            if (!in_array($k, $known, true)) $s['widgets'][] = ['key' => $k, 'on' => true];
        }
    }
    return $cache[$pageKey] = $s;
}

function it_save_settings(PDO $conn, string $pageKey, array $settings): int {
    $st = $conn->prepare("SELECT version FROM it_settings WHERE page_key = ?");
    $st->execute([$pageKey]);
    $version = (int)$st->fetchColumn() + 1;
    $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
    $conn->prepare("INSERT INTO it_settings (page_key, settings, version, updated_by, updated_at) VALUES (?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE settings = VALUES(settings), version = VALUES(version), updated_by = VALUES(updated_by), updated_at = NOW()")
         ->execute([$pageKey, $json, $version, it_user_id()]);
    $conn->prepare("INSERT INTO it_settings_history (page_key, settings, version, saved_by, saved_at) VALUES (?,?,?,?,NOW())")
         ->execute([$pageKey, $json, $version, it_user_id()]);
    return $version;
}

function it_page(string $pageKey): array {
    return it_settings($GLOBALS['conn'], $pageKey);
}

/**
 * Fields of an entity as this page shows them: base definition, then the
 * page's overrides (label, hidden, required, list, order), then custom fields.
 */
function it_effective_fields(string $entity, ?array $pageSettings = null): array {
    $def = it_entities()[$entity];
    $pageSettings = $pageSettings ?? it_page(it_entity_page($entity));
    $cfg = $pageSettings['entities'][$entity] ?? [];
    $over = $cfg['fields'] ?? [];
    $out = [];
    $i = 0;
    foreach ($def['fields'] as $f) {
        $f += ['required' => false, 'list' => false, 'options' => [], 'hidden' => false];
        $f['base_label'] = $f['label'];
        // Built-in required fields (and the title) can be renamed but never hidden or made optional.
        $f['locked'] = $f['required'] || $f['key'] === $def['title_field'];
        $o = $over[$f['key']] ?? [];
        if (isset($o['label']) && trim($o['label']) !== '') $f['label'] = trim($o['label']);
        if (!$f['locked']) {
            if (isset($o['hidden'])) $f['hidden'] = (bool)$o['hidden'];
            if (isset($o['required'])) $f['required'] = (bool)$o['required'];
        }
        if (isset($o['list'])) $f['list'] = (bool)$o['list'];
        if (!empty($o['options']) && $f['type'] === 'select') $f['options'] = array_values(array_filter(array_map('trim', (array)$o['options']), 'strlen'));
        if (!empty($def['sections']) && $f['key'] === $def['category_field']) {
            $f['options'] = array_column(it_sections($GLOBALS['conn'], $entity), 'name');
            $f['sections'] = true;
        }
        $f['order'] = isset($o['order']) ? (int)$o['order'] : $i * 10;
        $f['custom'] = false;
        $out[] = $f;
        $i++;
    }
    foreach ($cfg['custom'] ?? [] as $c) {
        if (empty($c['key']) || trim($c['label'] ?? '') === '') continue;
        $out[] = [
            'key' => $c['key'], 'label' => trim($c['label']), 'base_label' => trim($c['label']), 'type' => $c['type'] ?? 'text',
            'options' => array_values(array_filter(array_map('trim', (array)($c['options'] ?? [])), 'strlen')),
            'required' => !empty($c['required']), 'list' => !empty($c['list']), 'hidden' => !empty($c['hidden']),
            'order' => isset($c['order']) ? (int)$c['order'] : 1000 + $i * 10, 'custom' => true, 'locked' => false,
        ];
        $i++;
    }
    usort($out, fn($a, $b) => $a['order'] <=> $b['order']);
    return $out;
}

function it_entity_label(string $entity, ?array $pageSettings = null): string {
    $pageSettings = $pageSettings ?? it_page(it_entity_page($entity));
    $l = trim($pageSettings['entities'][$entity]['label'] ?? '');
    return $l !== '' ? $l : it_entities()[$entity]['label'];
}

/* ----------------------------------------------------------------- records */

function it_record(PDO $conn, int $id, bool $withDeleted = false): ?array {
    $st = $conn->prepare("SELECT * FROM it_records WHERE id = ?" . ($withDeleted ? '' : ' AND deleted_at IS NULL'));
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['data'] = json_decode((string)$r['data'], true) ?: [];
    return $r;
}

/** id => title for the given ids (link fields). */
function it_titles(PDO $conn, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $st = $conn->prepare("SELECT id, title, entity, deleted_at FROM it_records WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = $r;
    return $out;
}

function it_link_options(PDO $conn, string $entity): array {
    $st = $conn->prepare("SELECT id, title FROM it_records WHERE entity = ? AND deleted_at IS NULL ORDER BY title");
    $st->execute([$entity]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

function it_next_code(PDO $conn, string $entity, string $prefix): string {
    $st = $conn->prepare("SELECT ref_code FROM it_records WHERE entity = ? AND ref_code LIKE ? ORDER BY CAST(SUBSTRING(ref_code, ?) AS UNSIGNED) DESC LIMIT 1");
    $st->execute([$entity, $prefix . '%', strlen($prefix) + 1]);
    $n = (int)substr((string)$st->fetchColumn(), strlen($prefix));
    return $prefix . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/** Date status for a due date: overdue / soon / ok / null. */
function it_due_state(?string $date, ?int $soonDays = null): ?string {
    if (!$date) return null;
    $soonDays = $soonDays ?? (int)(it_page('_global')['alert_days'] ?? 30);
    $d = (new DateTime($date))->setTime(0, 0);
    $today = (new DateTime('today'));
    if ($d < $today) return 'overdue';
    if ($d == $today) return 'today';
    if ($d <= (clone $today)->modify("+{$soonDays} days")) return 'soon';
    return 'ok';
}

function it_due_text(?string $date): string {
    if (!$date) return '';
    $days = (int)(new DateTime('today'))->diff((new DateTime($date))->setTime(0, 0))->format('%r%a');
    if ($days === 0) return 'Today';
    if ($days === 1) return 'Tomorrow';
    if ($days === -1) return 'Yesterday';
    if ($days < 0) return abs($days) . ' days overdue';
    if ($days < 60) return 'in ' . $days . ' days';
    return date('d M Y', strtotime($date));
}

/** Next due date after a maintenance task is done. Never lands in the past. */
function it_advance_due(?string $due, string $frequency): ?string {
    $step = ['Daily' => '+1 day', 'Weekly' => '+1 week', 'Monthly' => '+1 month', 'Quarterly' => '+3 months', 'Half-yearly' => '+6 months', 'Yearly' => '+1 year'][$frequency] ?? null;
    if ($step === null) return null;
    $d = new DateTime($due ?: 'today');
    $today = new DateTime('today');
    do { $d->modify($step); } while ($d <= $today);
    return $d->format('Y-m-d');
}

function it_tone(?string $status): string {
    return it_status_tones()[$status ?? ''] ?? 'gray';
}

function it_fmt(array $f, $v, array $titles = []): string {
    if ($v === null || $v === '') return '';
    return match ($f['type']) {
        'date'   => date('d M Y', strtotime((string)$v)),
        'yesno'  => $v ? 'Yes' : 'No',
        'number' => is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 2), '0'), '.') : (string)$v,
        'link'   => (string)($titles[(int)$v]['title'] ?? ''),
        'company' => (string)(it_companies($GLOBALS['conn'])[(int)$v] ?? ''),
        'user'   => (string)(it_staff_users($GLOBALS['conn'])[(int)$v] ?? 'Account #' . (int)$v . ' (disabled)'),
        default  => (string)$v,
    };
}

/**
 * What to call someone: their nickname from HR (employees.nickname), else
 * their first name, else the first word of their full name.
 */
function it_friendly_name(PDO $conn, int $userId): string {
    $st = $conn->prepare("SELECT u.username, u.fullname, e.nickname, e.first_name
        FROM `user` u
        LEFT JOIN employees e ON e.id = COALESCE(u.employee_id, (SELECT e2.id FROM employees e2 WHERE e2.user_id = u.id ORDER BY e2.id LIMIT 1))
        WHERE u.id = ?");
    try {
        $st->execute([$userId]);
        $r = $st->fetch();
    } catch (Throwable $e) {
        $r = null; // no employees table (or older schema): fall back to the account name
    }
    if (!$r) return '';
    foreach ([$r['nickname'] ?? '', $r['first_name'] ?? ''] as $n) {
        if (trim((string)$n) !== '') return trim((string)$n);
    }
    $full = trim((string)($r['fullname'] ?? ''));
    return $full !== '' ? preg_split('/\s+/', $full)[0] : (string)$r['username'];
}

/* -------------------------------------------------------------- sections */
/*
 * Sections group the items of an entity that has 'sections' => true: the
 * Password Vault (computers, Wi-Fi, email…) and the Library (folders). The
 * entity's category field holds the section name; the list lives in it_settings
 * under "<entity>_sections" and is managed on the page itself.
 */

function it_section_icons(): array {
    return ['bi-laptop', 'bi-pc-display', 'bi-wifi', 'bi-envelope', 'bi-router', 'bi-hdd-stack', 'bi-camera-video', 'bi-window',
        'bi-globe', 'bi-cloud', 'bi-bank', 'bi-person-badge', 'bi-phone', 'bi-printer', 'bi-shield-lock', 'bi-key', 'bi-building',
        'bi-credit-card', 'bi-server', 'bi-fingerprint', 'bi-people', 'bi-folder2', 'bi-star', 'bi-lightning-charge',
        'bi-file-earmark-text', 'bi-journal-text', 'bi-diagram-3', 'bi-tools', 'bi-images', 'bi-book'];
}

function it_section_defaults(string $entity): array {
    return match ($entity) {
        'vault' => [
            ['Computers & Laptops', 'bi-laptop', '#3b82f6'], ['Email', 'bi-envelope', '#ef4444'], ['Wi-Fi', 'bi-wifi', '#14b8a6'],
            ['Router / Network', 'bi-router', '#0ea5e9'], ['Server', 'bi-hdd-stack', '#94a3b8'], ['CCTV / NVR', 'bi-camera-video', '#f97316'],
            ['Software', 'bi-window', '#6366f1'], ['Website / Hosting', 'bi-globe', '#22c55e'], ['Cloud / Social', 'bi-cloud', '#0ea5e9'],
            ['Banking', 'bi-bank', '#eab308'], ['System Login', 'bi-person-badge', '#f59e0b'], ['Other', 'bi-folder2', '#64748b'],
        ],
        'doc' => [
            ['Manuals & Guides', 'bi-book', '#3b82f6'], ['Network Diagrams', 'bi-diagram-3', '#14b8a6'], ['CCTV Layouts', 'bi-camera-video', '#ef4444'],
            ['Wiring & Racks', 'bi-hdd-stack', '#f97316'], ['Invoices & Warranties', 'bi-file-earmark-text', '#eab308'],
            ['How-to / Procedures', 'bi-tools', '#22c55e'], ['Reference Photos', 'bi-images', '#a855f7'], ['Other', 'bi-folder2', '#64748b'],
        ],
        default => [['General', 'bi-folder2', '#64748b']],
    };
}

/** Sections of an entity: [{key, name, icon, color}], in display order. */
function it_sections(PDO $conn, string $entity): array {
    static $cache = [];
    if (isset($cache[$entity])) return $cache[$entity];
    $st = $conn->prepare("SELECT settings FROM it_settings WHERE page_key = ?");
    $st->execute([$entity . '_sections']);
    $s = json_decode((string)$st->fetchColumn(), true);
    if (!empty($s['sections'])) return $cache[$entity] = $s['sections'];
    $out = [];
    foreach (it_section_defaults($entity) as $i => [$n, $ic, $c]) $out[] = ['key' => 's' . ($i + 1), 'name' => $n, 'icon' => $ic, 'color' => $c];
    return $cache[$entity] = $out;
}

function it_save_sections(PDO $conn, string $entity, array $sections): void {
    $conn->prepare("INSERT INTO it_settings (page_key, settings, version, updated_by, updated_at) VALUES (?, ?, 1, ?, NOW())
                    ON DUPLICATE KEY UPDATE settings = VALUES(settings), version = version + 1, updated_by = VALUES(updated_by), updated_at = NOW()")
         ->execute([$entity . '_sections', json_encode(['sections' => array_values($sections)], JSON_UNESCAPED_UNICODE), it_user_id()]);
}

function it_section(PDO $conn, string $entity, ?string $name): ?array {
    foreach (it_sections($conn, $entity) as $s) if (strcasecmp($s['name'], (string)$name) === 0) return $s;
    return null;
}

/** Active companies, id => name (the main system's companies table). */
function it_companies(PDO $conn): array {
    static $c = null;
    if ($c === null) {
        try { $c = $conn->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (Throwable $e) { $c = []; }
    }
    return $c;
}

/** Active staff logins, id => "Full name (username)" — for employee fields. */
function it_staff_users(PDO $conn): array {
    static $u = null;
    if ($u === null) {
        $u = [];
        foreach ($conn->query("SELECT id, username, fullname FROM `user` WHERE user_type = 'internal' AND is_active = 1 ORDER BY fullname, username") as $r) {
            $u[(int)$r['id']] = trim($r['fullname'] ?: $r['username']) . ' (' . $r['username'] . ')';
        }
    }
    return $u;
}

/** Extra detail per active staff login, for the employee picker: id => [nick, sub]. */
function it_staff_user_details(PDO $conn): array {
    static $d = null;
    if ($d !== null) return $d;
    $d = [];
    try {
        $rows = $conn->query("SELECT u.id, u.username, e.nickname,
                COALESCE(NULLIF(TRIM(e.position_title), ''), NULLIF(TRIM(u.job_title), '')) AS position,
                COALESCE(dp.name, NULLIF(TRIM(u.department), '')) AS department,
                (SELECT GROUP_CONCAT(c.name ORDER BY uc.is_primary DESC, c.name SEPARATOR ', ') FROM user_companies uc JOIN companies c ON c.id = uc.company_id WHERE uc.user_id = u.id) AS companies
            FROM `user` u
            LEFT JOIN employees e ON e.id = COALESCE(u.employee_id, (SELECT e2.id FROM employees e2 WHERE e2.user_id = u.id ORDER BY e2.id LIMIT 1))
            LEFT JOIN departments dp ON dp.id = e.department_id
            WHERE u.user_type = 'internal' AND u.is_active = 1")->fetchAll();
    } catch (Throwable $e) { $rows = []; }
    foreach ($rows as $r) {
        $role = trim(($r['position'] ?? '') . (($r['position'] ?? '') && ($r['department'] ?? '') ? ' · ' : '') . ($r['department'] ?? ''));
        $cos = array_values(array_filter(explode(', ', (string)($r['companies'] ?? ''))));
        $coText = implode(', ', array_slice($cos, 0, 2)) . (count($cos) > 2 ? ' +' . (count($cos) - 2) . ' more' : '');
        $d[(int)$r['id']] = ['nick' => trim((string)$r['nickname']), 'sub' => implode(' — ', array_filter([$r['username'], $role, $coText]))];
    }
    return $d;
}

/** Current label of one field on its page. */
function it_fields_label(string $entity, string $key): string {
    foreach (it_effective_fields($entity) as $f) if ($f['key'] === $key) return $f['label'];
    return '';
}

/** One activity row as a short sentence (without the person's name). */
function it_activity_text(array $a): string {
    $t = $a['rtitle'] ?? '';
    $q = $t !== '' ? ' “' . $t . '”' : '';
    return match ($a['action']) {
        'login' => 'signed in',
        'logout' => 'signed out',
        'login_failed' => 'failed sign-in (' . $a['summary'] . ')',
        'login_denied' => 'was refused (no IT access)',
        'create' => 'added' . $q,
        'update' => 'updated' . $q,
        'delete' => 'deleted' . $q,
        'restore' => 'restored' . $q,
        'purge' => $a['summary'],
        'reveal' => 'viewed the password of' . $q,
        'copy' => 'copied the password of' . $q,
        'task_done' => 'completed' . $q,
        'vault_unlock' => 'opened the vault',
        'pin_set' => 'created a vault PIN',
        'pin_changed' => 'changed the vault PIN',
        'pin_failed' => 'entered a wrong vault PIN',
        'settings' => $a['summary'],
        'access' => $a['summary'],
        'export' => $a['summary'],
        default => $a['summary'] ?: $a['action'],
    };
}

/* ----------------------------------------------------------------- helpers */

function it_flash(string $msg, string $type = 'success'): void {
    $_SESSION['it_flash'] = ['msg' => $msg, 'type' => $type];
}

function it_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function it_csrf_ok(): bool {
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($t) && hash_equals($_SESSION['_csrf'] ?? '', $t);
}

function it_hex(string $c, string $fallback): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $fallback;
}

// Pages are personal and sensitive: never cache them anywhere.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}
