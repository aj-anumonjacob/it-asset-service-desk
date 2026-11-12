<?php
require_once __DIR__ . '/includes/it_core.php';
require_once __DIR__ . '/includes/it_render.php';
it_require_access();
$pageKey = 'vendors';
include __DIR__ . '/includes/layout_header.php';
it_list_page($pageKey);
include __DIR__ . '/includes/layout_footer.php';
