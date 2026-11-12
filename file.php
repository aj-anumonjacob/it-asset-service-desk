<?php
/**
 * Opens or downloads a Library file (?id=, &view=1 to open a PDF or photo in
 * the browser). Signed-in portal users only. Everything except PDFs and photos
 * is always downloaded, never opened by the browser.
 */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_photos.php';
it_require_access();
$st = $conn->prepare("SELECT f.*, r.entity, r.title FROM it_files f JOIN it_records r ON r.id = f.record_id WHERE f.id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$path = $f ? it_photo_dir() . '/' . $f['file_name'] . '.' . $f['ext'] : '';
if (!$f || !is_file($path) || ($f['entity'] === 'vault' && !it_can('vault'))) { http_response_code(404); exit('File not found.'); }

$inline = !empty($_GET['view']) && in_array($f['ext'], ['pdf', 'jpg'], true);
$name = pathinfo((string)$f['original_name'], PATHINFO_FILENAME) ?: 'file';
$name .= '.' . ($f['kind'] === 'photo' ? 'jpg' : $f['ext']);
if (empty($_GET['view'])) it_log($conn, 'export', $f['entity'], (int)$f['record_id'], 'Downloaded “' . $name . '”');

header_remove('Pragma');
header('Content-Type: ' . ($f['kind'] === 'photo' ? 'image/jpeg' : $f['mime']));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace(['"', "\r", "\n"], '', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
// Anything opened here runs no scripts. PDFs are left to the browser's own viewer,
// which will not draw them under a sandbox policy.
if ($f['ext'] !== 'pdf') header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
readfile($path);
