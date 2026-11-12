<?php
// Web app manifest, named and coloured from Settings → Appearance.
require_once __DIR__ . '/includes/it_core.php';
it_ensure_schema($conn);
$G = it_settings($conn, '_global');
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache');
echo json_encode([
    'name' => $G['brand'],
    'short_name' => mb_substr($G['brand'], 0, 12),
    'description' => 'Passwords, IT inventory, CCTV, network, licenses and maintenance.',
    'start_url' => './dashboard.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => ($G['theme'] ?? 'steel') === 'steel' ? '#101318' : '#f5f6fb',
    'theme_color' => it_hex($G['primary'] ?? '', '#f97316'),
    'icons' => [
        ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
