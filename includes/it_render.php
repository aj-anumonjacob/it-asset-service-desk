<?php
/**
 * IT Portal — list pages, cards, tables and form inputs, all driven by
 * it_entities() plus the page's Customize settings.
 */

require_once __DIR__ . '/it_photos.php';

function it_category_icon(string $entity, ?string $cat): string {
    if (!empty(it_entities()[$entity]['sections']) && ($s = it_section($GLOBALS['conn'], $entity, $cat))) return $s['icon'];
    $map = [
        'Laptop' => 'bi-laptop', 'Desktop' => 'bi-pc-display', 'Monitor' => 'bi-display', 'Printer / Scanner' => 'bi-printer',
        'Mobile Phone' => 'bi-phone', 'Tablet' => 'bi-tablet', 'Router' => 'bi-router', 'Switch' => 'bi-hdd-network',
        'Access Point' => 'bi-wifi', 'UPS' => 'bi-battery-charging', 'Server / NAS' => 'bi-hdd-stack', 'Biometric' => 'bi-fingerprint',
        'POS Terminal' => 'bi-credit-card', 'Accessory' => 'bi-mouse',
        'System Login' => 'bi-person-badge', 'Email' => 'bi-envelope', 'Wi-Fi' => 'bi-wifi', 'Router / Network' => 'bi-router',
        'Server' => 'bi-hdd-stack', 'CCTV / NVR' => 'bi-camera-video', 'Software' => 'bi-window', 'Website / Hosting' => 'bi-globe',
        'Cloud / Social' => 'bi-cloud', 'Banking' => 'bi-bank',
        'Domain' => 'bi-globe2', 'SSL Certificate' => 'bi-shield-check', 'Hosting' => 'bi-server', 'Email / Microsoft 365 / Google' => 'bi-envelope-at',
        'Antivirus' => 'bi-shield-shaded', 'Subscription' => 'bi-arrow-repeat',
    ];
    return $map[$cat ?? ''] ?? it_entities()[$entity]['icon'];
}

/** Rows of one entity for a list page, with filters from the query string. */
function it_query_rows(PDO $conn, string $entity, array $ps, array $opts = []): array {
    $where = ['entity = ?', 'deleted_at IS NULL'];
    $args = [$entity];
    $q = trim((string)($opts['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(title LIKE ? OR ref_code LIKE ? OR data LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, '%' . trim(json_encode($q), '"') . '%');
    }
    if (($opts['status'] ?? '') !== '') { $where[] = 'status = ?'; $args[] = $opts['status']; }
    if (($opts['section'] ?? '') !== '') { $where[] = "JSON_UNQUOTE(JSON_EXTRACT(data, '$.category')) = ?"; $args[] = $opts['section']; }
    if (!empty($opts['parent'])) { $where[] = 'parent_id = ?'; $args[] = (int)$opts['parent']; }
    $sort = match ($opts['sort'] ?? $ps['sort'] ?? 'updated_desc') {
        'title_asc' => 'title ASC',
        'due_asc' => 'due_date IS NULL, due_date ASC, title ASC',
        'created_desc' => 'id DESC',
        default => 'updated_at DESC, id DESC',
    };
    $per = max(6, min(200, (int)($ps['per_page'] ?? 24)));
    $page = max(1, (int)($opts['p'] ?? 1));
    $w = implode(' AND ', $where);
    $st = $conn->prepare("SELECT COUNT(*) FROM it_records WHERE $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $conn->prepare("SELECT * FROM it_records WHERE $w ORDER BY $sort LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['data'] = json_decode((string)$r['data'], true) ?: [];
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
}

function it_status_counts(PDO $conn, string $entity): array {
    $st = $conn->prepare("SELECT COALESCE(status,'') s, COUNT(*) c FROM it_records WHERE entity = ? AND deleted_at IS NULL GROUP BY status ORDER BY c DESC");
    $st->execute([$entity]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Titles for every link field value in these rows. */
function it_row_titles(PDO $conn, array $rows, array $fields): array {
    $ids = [];
    foreach ($fields as $f) if ($f['type'] === 'link') foreach ($rows as $r) $ids[] = $r['data'][$f['key']] ?? 0;
    return it_titles($conn, $ids);
}

function it_secret_html(int $id, bool $inline = false, ?int $historyId = null): string {
    $attrs = 'data-secret-box data-id="' . $id . '"' . ($historyId ? ' data-history="' . $historyId . '"' : '');
    $cls = $inline ? 'secret-inline' : 'secret-box';
    return '<span class="' . $cls . '" ' . $attrs . '><span class="val">••••••••••</span><span class="timer-ring"></span>'
        . '<button type="button" class="mini-btn" data-reveal title="Show"><i class="bi bi-eye"></i></button>'
        . '<button type="button" class="mini-btn" data-copy title="Copy"><i class="bi bi-clipboard"></i></button></span>';
}

function it_due_chip(?string $date): string {
    $s = it_due_state($date);
    if (!$s) return '';
    $icon = $s === 'ok' ? 'bi-calendar3' : 'bi-alarm';
    return '<span class="due-chip due-' . $s . '"><i class="bi ' . $icon . '"></i>' . h(it_due_text($date)) . '</span>';
}

function it_cell(array $f, array $r, array $titles, string $entity): string {
    $def = it_entities()[$entity];
    if ($f['type'] === 'secret') {
        return !empty($r['secret_enc']) && it_can('vault') ? it_secret_html((int)$r['id'], true) : '<span class="faint">—</span>';
    }
    $v = $r['data'][$f['key']] ?? '';
    if ($f['key'] === ($def['status_field'] ?? '')) {
        return $v === '' ? '' : '<span class="badge tone-' . it_tone($v) . '">' . h($v) . '</span>';
    }
    if ($f['key'] === ($def['due_field'] ?? '') && $v) {
        return it_due_chip($v);
    }
    $out = it_fmt($f, $v, $titles);
    return $out === '' ? '<span class="faint">—</span>' : h($out);
}

function it_render_cards(string $entity, array $rows, array $fields, array $titles): void {
    $def = it_entities()[$entity];
    $listFields = array_values(array_filter($fields, fn($f) => $f['list'] && !$f['hidden'] && $f['key'] !== $def['title_field'] && $f['key'] !== ($def['status_field'] ?? '') && $f['key'] !== ($def['due_field'] ?? '') && $f['key'] !== ($def['category_field'] ?? '') && $f['type'] !== 'secret'));
    $secret = array_values(array_filter($fields, fn($f) => $f['type'] === 'secret' && $f['list'] && !$f['hidden']));
    $covers = !empty($def['photos']) ? it_photo_covers($GLOBALS['conn'], array_column($rows, 'id')) : [];
    echo '<div class="grid grid-cards" id="rows">';
    foreach ($rows as $i => $r) {
        $status = $r['status'];
        $icon = it_category_icon($entity, $r['data'][$def['category_field'] ?? ''] ?? null);
        echo '<div class="card rec-card rise" style="--i:' . min($i, 14) . '" data-row data-href="record.php?id=' . (int)$r['id'] . '">';
        $ico = isset($covers[$r['id']]) ? '<span class="rec-ico rec-photo"><img src="photo.php?id=' . $covers[$r['id']] . '&amp;t=1" alt="" loading="lazy"></span>' : '<span class="rec-ico"><i class="bi ' . h($icon) . '"></i></span>';
        echo '<div class="rec-top">' . $ico . '<div class="rec-title"><strong>' . h($r['title']) . '</strong>';
        echo '<small>' . h($r['ref_code'] ?: ($r['data'][$def['category_field'] ?? ''] ?? '')) . '</small></div>';
        if ($status) echo '<span class="badge tone-' . it_tone($status) . '">' . h($status) . '</span>';
        echo '</div>';
        if ($secret && !empty($r['secret_enc']) && it_can('vault')) {
            echo it_secret_html((int)$r['id']);
        }
        if ($listFields) {
            echo '<div class="rec-fields">';
            foreach (array_slice($listFields, 0, 6) as $f) {
                $val = it_fmt($f, $r['data'][$f['key']] ?? '', $titles);
                echo '<div><span>' . h($f['label']) . '</span><b>' . ($val === '' ? '<span class="faint">—</span>' : h($val)) . '</b></div>';
            }
            echo '</div>';
        }
        echo '<div class="rec-foot">';
        if (!empty($def['due_field']) && !empty($r['due_date'])) echo it_due_chip($r['due_date']);
        echo '<span style="margin-left:auto"><i class="bi bi-clock"></i> ' . h(date('d M', strtotime($r['updated_at']))) . '</span>';
        echo '</div></div>';
    }
    echo '</div>';
}

function it_render_table(string $entity, array $rows, array $fields, array $titles): void {
    $def = it_entities()[$entity];
    $cols = array_values(array_filter($fields, fn($f) => ($f['list'] || $f['key'] === $def['title_field']) && !$f['hidden']));
    usort($cols, fn($a, $b) => ($b['key'] === $def['title_field']) <=> ($a['key'] === $def['title_field']));
    $covers = !empty($def['photos']) ? it_photo_covers($GLOBALS['conn'], array_column($rows, 'id')) : [];
    echo '<div class="card table-wrap"><table class="tbl"><thead><tr>';
    foreach ($cols as $f) echo '<th>' . h($f['label']) . '</th>';
    echo '<th></th></tr></thead><tbody id="rows">';
    foreach ($rows as $r) {
        echo '<tr data-row data-href="record.php?id=' . (int)$r['id'] . '">';
        foreach ($cols as $f) {
            if ($f['key'] === $def['title_field']) {
                echo '<td class="t-title">' . (isset($covers[$r['id']]) ? '<img class="t-thumb" src="photo.php?id=' . $covers[$r['id']] . '&amp;t=1" alt="" loading="lazy">' : '') . h($r['title']) . ($r['ref_code'] ? ' <small class="faint mono">' . h($r['ref_code']) . '</small>' : '') . '</td>';
            } else {
                echo '<td>' . it_cell($f, $r, $titles, $entity) . '</td>';
            }
        }
        echo '<td style="text-align:right;white-space:nowrap">';
        if (it_can('edit')) echo '<a class="mini-btn" href="edit.php?id=' . (int)$r['id'] . '" title="Edit"><i class="bi bi-pencil"></i></a>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function it_empty_state(string $entity, string $label, bool $filtered, string $clearQs = '', string $prefill = ''): void {
    $def = it_entities()[$entity];
    echo '<div class="card empty"><div class="e-ico"><i class="bi ' . h($def['icon']) . '"></i></div>';
    if ($filtered) {
        echo '<h3>Nothing matches</h3><p>Try a different word, or clear the filters.</p><a class="btn btn-ghost" href="?' . $clearQs . '"><i class="bi bi-x-circle"></i> Clear filters</a>';
    } else {
        echo '<h3>No ' . h($label) . ' yet</h3><p>Add the first one — it takes less than a minute.</p>';
        if (it_can('edit')) echo '<a class="btn btn-primary" href="edit.php?entity=' . h($entity) . h($prefill) . '"><i class="bi bi-plus-lg"></i> Add ' . h(strtolower($def['singular'])) . '</a>';
    }
    echo '</div>';
}

function it_page_head(array $ps, string $actionsHtml = '', string $crumbs = ''): void {
    echo '<div class="page-head"><span class="page-icon"><i class="bi ' . h($ps['icon']) . '"></i></span>';
    echo '<div class="grow"><h1>' . h($ps['title']) . '</h1>' . ($ps['subtitle'] !== '' ? '<p>' . h($ps['subtitle']) . '</p>' : '') . $crumbs . '</div>';
    echo '<div class="head-actions">' . $actionsHtml . '</div></div>';
}

/** A complete list page: header, tabs, status chips, toolbar, cards/table, pager. */
function it_list_page(string $pageKey, array $keep = [], string $afterHead = ''): void {
    global $conn;
    $ps = it_page($pageKey);
    $entities = it_pages()[$pageKey]['entities'];
    $entity = in_array($_GET['tab'] ?? '', $entities, true) ? $_GET['tab'] : $entities[0];
    $def = it_entities()[$entity];
    $fields = it_effective_fields($entity, $ps);
    $label = it_entity_label($entity, $ps);
    $view = $_COOKIE['it_view_' . $pageKey] ?? $ps['view'];
    $view = in_array($view, ['cards', 'table'], true) ? $view : 'cards';
    $opts = ['q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? '', 'sort' => $_GET['sort'] ?? null, 'p' => $_GET['p'] ?? 1,
             'section' => !empty($def['sections']) ? (string)($_GET['section'] ?? '') : ''];
    $res = it_query_rows($conn, $entity, $ps, $opts);
    $titles = it_row_titles($conn, $res['rows'], $fields);

    $actions = '';
    if (it_can('settings')) $actions .= '<a class="btn btn-ghost" href="customize.php?page=' . h($pageKey) . '" title="Customize this page"><i class="bi bi-sliders"></i><span class="hide-sm">Customize</span></a>';
    if (it_can('edit')) $actions .= '<a class="btn btn-ghost" href="export.php?entity=' . h($entity) . '" title="Download as CSV"><i class="bi bi-download"></i></a>';
    $prefill = $opts['section'] !== '' ? '&' . $def['category_field'] . '=' . rawurlencode($opts['section']) : '';
    if (it_can('edit')) $actions .= '<a class="btn btn-primary" href="edit.php?entity=' . h($entity) . h($prefill) . '"><i class="bi bi-plus-lg"></i> Add ' . h(strtolower($def['singular'])) . '</a>';
    it_page_head($ps, $actions);
    echo $afterHead;
    $qs = fn(array $a) => h(http_build_query(array_merge($keep, $a)));

    if (count($entities) > 1) {
        echo '<div class="tabs">';
        foreach ($entities as $e) {
            $c = (int)$conn->query("SELECT COUNT(*) FROM it_records WHERE deleted_at IS NULL AND entity = " . $conn->quote($e))->fetchColumn();
            echo '<a class="tab ' . ($e === $entity ? 'active' : '') . '" href="?' . $qs(['tab' => $e]) . '"><i class="bi ' . h(it_entities()[$e]['icon']) . '"></i>' . h(it_entity_label($e, $ps)) . '<span class="count">' . $c . '</span></a>';
        }
        echo '</div>';
    }

    if (!empty($ps['show_stats']) && empty($def['sections'])) {
        $counts = it_status_counts($conn, $entity);
        if ($counts) {
            $all = array_sum($counts);
            echo '<div class="chips">';
            echo '<a class="chip ' . ($opts['status'] === '' ? 'active' : '') . '" href="?' . $qs(['tab' => $entity, 'q' => $opts['q']]) . '">All <b>' . $all . '</b></a>';
            foreach ($counts as $s => $c) {
                if ($s === '') continue;
                echo '<a class="chip ' . ($opts['status'] === $s ? 'active' : '') . '" href="?' . $qs(['tab' => $entity, 'status' => $s, 'q' => $opts['q']]) . '"><span class="badge plain tone-' . it_tone($s) . '" style="padding:0;background:none">●</span>' . h($s) . ' <b>' . $c . '</b></a>';
            }
            echo '</div>';
        }
    }

    $sort = $opts['sort'] ?? $ps['sort'];
    echo '<form class="toolbar" method="get"><input type="hidden" name="tab" value="' . h($entity) . '">';
    foreach ($keep as $k => $v) echo '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    if ($opts['status'] !== '') echo '<input type="hidden" name="status" value="' . h($opts['status']) . '">';
    echo '<div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="q" value="' . h($opts['q']) . '" placeholder="Search ' . h(strtolower($label)) . '… (Enter searches all pages of results)" data-quickfilter="#listArea"></div>';
    echo '<select class="input" name="sort" style="width:auto" onchange="this.form.submit()">';
    foreach (['updated_desc' => 'Recently changed', 'title_asc' => 'Name A–Z', 'due_asc' => 'Due date', 'created_desc' => 'Newest added'] as $k => $l) {
        if ($k === 'due_asc' && empty($def['due_field'])) continue;
        echo '<option value="' . $k . '"' . ($sort === $k ? ' selected' : '') . '>' . $l . '</option>';
    }
    echo '</select>';
    echo '<div class="seg"><button type="button" data-view="cards" class="' . ($view === 'cards' ? 'active' : '') . '" title="Cards"><i class="bi bi-grid-3x2-gap"></i></button><button type="button" data-view="table" class="' . ($view === 'table' ? 'active' : '') . '" title="Table"><i class="bi bi-list-ul"></i></button></div>';
    echo '</form>';

    echo '<div id="listArea">';
    if (!$res['rows']) {
        it_empty_state($entity, $opts['section'] !== '' ? $label . ' in ' . $opts['section'] : $label, $opts['q'] !== '' || $opts['status'] !== '', $qs(['tab' => $entity]), $prefill);
    } else {
        $view === 'table' ? it_render_table($entity, $res['rows'], $fields, $titles) : it_render_cards($entity, $res['rows'], $fields, $titles);
        echo '<div class="card empty" data-noresults hidden><div class="e-ico"><i class="bi bi-search"></i></div><h3>Not on this page</h3><p>Press Enter to search everything.</p></div>';
    }
    echo '</div>';
    it_pager($res);
}

function it_pager(array $res): void {
    if ($res['pages'] <= 1) return;
    echo '<div class="pager">';
    for ($i = 1; $i <= $res['pages']; $i++) {
        if ($i !== 1 && $i !== $res['pages'] && abs($i - $res['page']) > 2) {
            if (abs($i - $res['page']) === 3) echo '<span>…</span>';
            continue;
        }
        $qs = http_build_query(array_merge($_GET, ['p' => $i]));
        echo $i === $res['page'] ? '<span class="cur">' . $i . '</span>' : '<a href="?' . h($qs) . '">' . $i . '</a>';
    }
    echo '</div>';
}

/** One form input. */
function it_input(array $f, $value, array $linkOptions, ?string $error, bool $hasSecret = false): string {
    $name = 'f[' . $f['key'] . ']';
    $id = 'f_' . $f['key'];
    $req = $f['required'] ? ' required' : '';
    $ph = isset($f['placeholder']) ? ' placeholder="' . h($f['placeholder']) . '"' : '';
    $v = (string)($value ?? '');
    $full = in_array($f['type'], ['textarea'], true) ? ' full' : '';
    $html = '<div class="field' . $full . ($error ? ' has-error' : '') . '">';
    $html .= '<label for="' . $id . '">' . h($f['label']) . ($f['required'] ? '<span class="req">*</span>' : '') . '</label>';
    switch ($f['type']) {
        case 'textarea':
            $html .= '<textarea class="input" id="' . $id . '" name="' . $name . '"' . $req . $ph . '>' . h($v) . '</textarea>';
            break;
        case 'select':
            $html .= '<select class="input" id="' . $id . '" name="' . $name . '"' . $req . '><option value="">— Choose —</option>';
            $opts = $f['options'];
            if ($v !== '' && !in_array($v, $opts, true)) $opts[] = $v; // keep a value whose option was removed
            foreach ($opts as $o) $html .= '<option' . ($o === $v ? ' selected' : '') . '>' . h($o) . '</option>';
            $html .= '</select>';
            break;
        case 'link':
            $html .= '<select class="input" id="' . $id . '" name="' . $name . '"' . $req . ' data-combo data-placeholder="Type to search…" data-noun="' . h(strtolower(it_entities()[$f['link']]['singular'])) . '"><option value="">— None —</option>';
            foreach ($linkOptions as $lid => $lt) $html .= '<option value="' . (int)$lid . '"' . ((int)$v === (int)$lid ? ' selected' : '') . '>' . h($lt) . '</option>';
            $html .= '</select>';
            if (!$linkOptions) $html .= '<div class="help">Nothing to choose yet — add one on the ' . h(it_entities()[$f['link']]['label']) . ' page first.</div>';
            break;
        case 'user':
            $html .= '<select class="input" id="' . $id . '" name="' . $name . '"' . $req . ' data-combo data-placeholder="Type a name to search…" data-noun="employee"><option value="">— Choose employee —</option>';
            $users = it_staff_users($GLOBALS['conn']);
            $info = it_staff_user_details($GLOBALS['conn']);
            if ($v !== '' && !isset($users[(int)$v])) $html .= '<option value="' . (int)$v . '" selected>Account #' . (int)$v . ' (disabled)</option>';
            foreach ($users as $uid => $un) {
                $label = preg_replace('/ \([^)]*\)$/', '', $un);
                $html .= '<option value="' . (int)$uid . '"' . ((int)$v === (int)$uid ? ' selected' : '') . ' data-sub="' . h($info[$uid]['sub'] ?? '') . '" data-nick="' . h($info[$uid]['nick'] ?? '') . '">' . h($label) . '</option>';
            }
            $html .= '</select>';
            break;
        case 'company':
            $html .= '<select class="input" id="' . $id . '" name="' . $name . '"' . $req . ' data-combo data-placeholder="Type to search companies…" data-noun="company"><option value="">— Choose company —</option>';
            foreach (it_companies($GLOBALS['conn']) as $cid => $cn) $html .= '<option value="' . (int)$cid . '"' . ((int)$v === (int)$cid ? ' selected' : '') . '>' . h($cn) . '</option>';
            $html .= '</select>';
            break;
        case 'yesno':
            $html .= '<input type="hidden" name="' . $name . '" value="0"><label class="switch" style="margin-top:6px"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ($v === '1' || $v === 'true' ? ' checked' : '') . '><span class="track"></span><span>Yes</span></label>';
            break;
        case 'secret':
            $html .= '<div data-pw><div class="input-group"><input class="input mono" type="password" id="' . $id . '" name="secret" autocomplete="new-password"'
                . ($f['required'] && !$hasSecret ? ' required' : '') . ($hasSecret ? ' placeholder="Leave empty to keep the current one"' : '') . '>'
                . '<button type="button" class="btn btn-ghost" data-toggle-pw title="Show"><i class="bi bi-eye"></i></button>'
                . '<button type="button" class="btn btn-soft" data-gen title="Generate a strong password"><i class="bi bi-magic"></i></button></div>'
                . '<div class="strength"><i></i></div><div class="strength-label"></div></div>';
            if ($hasSecret) $html .= '<div class="help"><i class="bi bi-shield-lock"></i> Saved and encrypted. The old one is kept in history when you change it.</div>';
            break;
        default:
            $type = ['date' => 'date', 'number' => 'number', 'email' => 'email', 'url' => 'url', 'phone' => 'tel'][$f['type']] ?? 'text';
            $html .= '<input class="input' . ($f['type'] === 'ip' ? ' mono' : '') . '" type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . h($v) . '"' . $req . $ph . ($f['type'] === 'number' ? ' step="any" inputmode="decimal"' : '') . '>';
    }
    if (!empty($f['help'])) $html .= '<div class="help">' . h($f['help']) . '</div>';
    if ($error) $html .= '<div class="err"><i class="bi bi-exclamation-circle"></i> ' . h($error) . '</div>';
    return $html . '</div>';
}
