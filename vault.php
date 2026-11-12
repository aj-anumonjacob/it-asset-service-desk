<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
require_once __DIR__ . '/includes/sections_ui.php';
it_require_access();
$pageKey = 'vault';
if (!it_can('vault')) { http_response_code(403); $pageKey = 'dashboard'; }
include __DIR__ . '/includes/layout_header.php';

if (!it_can('vault')) {
    echo '<div class="card empty"><div class="e-ico"><i class="bi bi-shield-lock"></i></div><h3>No vault access</h3><p>Ask the IT admin to give you access.</p></div>';
    include __DIR__ . '/includes/layout_footer.php';
    exit;
}

$current = it_section($conn, 'vault', $_GET['section'] ?? '');
if ($current) $_GET['section'] = $current['name']; else unset($_GET['section']);

$top = '';
if (empty($GLOBALS['IT_ME']['pin_hash'])) {
    $top = '<div class="alert alert-warn"><i class="bi bi-key"></i><span style="flex:1">Create your vault PIN first. Every time you show or copy a password, the vault asks for it.</span><button class="btn btn-sm btn-primary" type="button" onclick="document.getElementById(\'vaultPill\').click()">Create PIN</button></div>';
}
$top .= it_section_bar('vault', 'vault.php', $current, it_section_counts($conn, 'vault'), 'All passwords', 'section');

it_list_page($pageKey, $current ? ['section' => $current['name']] : [], $top);
it_section_dialog('vault', 'section', 'Laptops & Computers, Wi-Fi, Email, CCTV', 'vault.php');
include __DIR__ . '/includes/layout_footer.php';
