<?php
/** Downloads the Email Directory as PDF. ?type=staff|shared|both&company=<id or empty for all> */
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/email_directory.php';
it_require_access();
$type = in_array($_GET['type'] ?? '', ['company', 'staff', 'shared', 'both'], true) ? $_GET['type'] : 'both';
$cid = (int)($_GET['company'] ?? 0);
$cid = isset(it_companies($conn)[$cid]) ? $cid : null;
try {
    $file = it_email_directory_pdf($conn, $type, $cid);
} catch (Throwable $e) {
    error_log('it email pdf: ' . $e->getMessage());
    it_flash('The PDF could not be made: ' . $e->getMessage(), 'error');
    header('Location: emails.php');
    exit;
}
$name = it_email_directory_filename($conn, $type, $cid);
it_log($conn, 'export', 'mailbox', null, 'Downloaded ' . $name);
header_remove('Pragma');
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . (!empty($_GET['view']) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, no-store');
readfile($file);
@unlink($file);
