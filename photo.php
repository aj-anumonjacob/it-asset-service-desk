<?php
/** Shows one device photo (?id=, &t=1 for the thumbnail) to signed-in portal users only. */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_photos.php';
it_require_access();
$st = $conn->prepare("SELECT f.file_name, r.entity FROM it_files f JOIN it_records r ON r.id = f.record_id WHERE f.id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$row = $st->fetch();
$path = $row ? it_photo_dir() . '/' . $row['file_name'] . (!empty($_GET['t']) ? '_t' : '') . '.jpg' : '';
if (!$row || !is_file($path) || ($row['entity'] === 'vault' && !it_can('vault'))) { http_response_code(404); exit; }
header_remove('Pragma');
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
