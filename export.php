<?php
/**
 * CSV download of one entity, using the page's current field labels.
 * Passwords and license keys are never exported.
 */
require_once __DIR__ . '/includes/it_core.php';
it_require_access();
if (!it_can('edit')) { http_response_code(403); exit('Not allowed'); }
$entity = (string)($_GET['entity'] ?? '');
$defs = it_entities();
if (!isset($defs[$entity]) || ($entity === 'vault' && !it_can('vault'))) { http_response_code(404); exit('Unknown list'); }
$fields = array_values(array_filter(it_effective_fields($entity), fn($f) => !$f['hidden'] && $f['type'] !== 'secret'));
$st = $conn->prepare("SELECT * FROM it_records WHERE entity = ? AND deleted_at IS NULL ORDER BY title");
$st->execute([$entity]);
$rows = $st->fetchAll();
foreach ($rows as &$r) { $r['data'] = json_decode($r['data'], true) ?: []; }
unset($r);
$ids = [];
foreach ($fields as $f) if ($f['type'] === 'link') foreach ($rows as $r) $ids[] = $r['data'][$f['key']] ?? 0;
$titles = it_titles($conn, $ids);

it_log($conn, 'export', $entity, null, 'Downloaded ' . $defs[$entity]['label'] . ' as CSV (' . count($rows) . ' rows, no passwords)');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="it-' . $entity . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 with the BOM
fputcsv($out, array_merge(['Ref'], array_column($fields, 'label'), ['Last changed']));
foreach ($rows as $r) {
    $line = [$r['ref_code']];
    foreach ($fields as $f) {
        // Spreadsheet formula injection: a cell starting with = + - @ is run by Excel.
        $v = it_fmt($f, $r['data'][$f['key']] ?? '', $titles);
        $line[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    }
    $line[] = $r['updated_at'];
    fputcsv($out, $line);
}
fclose($out);
