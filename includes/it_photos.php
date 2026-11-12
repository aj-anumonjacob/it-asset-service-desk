<?php
/**
 * IT Portal — device photos.
 *
 * Files are kept in storage/it_photos/ (never served directly) and shown only
 * through photo.php after the portal's sign-in. Every upload is checked for
 * upload errors, size, real file type (by content, not by name) and image
 * dimensions, then re-drawn with GD into a fresh JPEG: that drops anything
 * hidden in the original file, fixes phone rotation and strips location data.
 */

const IT_PHOTO_MAX_BYTES = 15 * 1024 * 1024;   // per original file
const IT_PHOTO_MAX_COUNT = 8;                  // per device
const IT_PHOTO_MAX_PIXELS = 40000000;          // 40 MP, protects server memory
const IT_PHOTO_MIN_SIDE = 64;
const IT_PHOTO_SIZE = 1600;                    // longest side kept
const IT_PHOTO_THUMB = 420;

function it_photo_types(): array {
    $t = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (function_exists('imagecreatefromwebp')) $t['image/webp'] = 'webp';
    return $t;
}

function it_photo_dir(): string {
    $dir = defined('IT_PHOTO_DIR') ? IT_PHOTO_DIR : dirname(__DIR__, 2) . '/storage/it_photos';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the photo folder.');
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function it_photos(PDO $conn, int $recordId): array {
    $st = $conn->prepare("SELECT * FROM it_files WHERE record_id = ? ORDER BY is_cover DESC, id ASC");
    $st->execute([$recordId]);
    return $st->fetchAll();
}

/** record id => cover photo id, for list cards. */
function it_photo_covers(PDO $conn, array $recordIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
    if (!$ids) return [];
    $st = $conn->prepare("SELECT record_id, id FROM it_files WHERE record_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY is_cover DESC, id ASC");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['record_id']] ??= (int)$r['id'];
    return $out;
}

function it_photo_upload_error(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The photo is bigger than the server allows (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_PARTIAL => 'The upload was cut off. Check the connection and try again.',
        UPLOAD_ERR_NO_FILE => 'No photo was chosen.',
        default => 'The server could not receive the photo (error ' . $code . ').',
    };
}

/**
 * Checks and stores one uploaded photo. Returns ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function it_photo_store(PDO $conn, int $recordId, array $file, int $maxSide = IT_PHOTO_SIZE, bool $limitCount = true): array {
    if (!isset($file['error']) || is_array($file['error'])) return ['ok' => false, 'error' => 'Send one photo at a time.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => it_photo_upload_error((int)$file['error'])];
    if (!is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'error' => 'That upload is not valid.'];

    $size = (int)filesize($file['tmp_name']);
    if ($size <= 0) return ['ok' => false, 'error' => 'The photo file is empty.'];
    if ($size > IT_PHOTO_MAX_BYTES) return ['ok' => false, 'error' => 'The photo is ' . round($size / 1048576, 1) . ' MB. The limit is ' . (IT_PHOTO_MAX_BYTES / 1048576) . ' MB.'];

    $st = $conn->prepare("SELECT COUNT(*) FROM it_files WHERE record_id = ?");
    $st->execute([$recordId]);
    if ($limitCount && (int)$st->fetchColumn() >= IT_PHOTO_MAX_COUNT) return ['ok' => false, 'error' => 'This device already has ' . IT_PHOTO_MAX_COUNT . ' photos. Delete one first.'];

    // Real type from the file's content; the name and the browser's claim are not trusted.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $types = it_photo_types();
    if (!isset($types[$mime])) {
        $hint = in_array($mime, ['image/heic', 'image/heif'], true) ? ' iPhone HEIC photos: choose “Most Compatible” in Camera settings, or take the photo from this page.' : '';
        return ['ok' => false, 'error' => 'Only ' . strtoupper(implode(', ', array_unique($types))) . ' photos can be added.' . $hint];
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || ($info['mime'] ?? '') !== $mime) return ['ok' => false, 'error' => 'The file is damaged or is not a real photo.'];
    [$w, $h] = $info;
    if ($w < IT_PHOTO_MIN_SIDE || $h < IT_PHOTO_MIN_SIDE) return ['ok' => false, 'error' => 'The photo is too small (' . $w . '×' . $h . ' pixels).'];
    if ($w * $h > IT_PHOTO_MAX_PIXELS) return ['ok' => false, 'error' => 'The photo is too large (' . round($w * $h / 1e6) . ' megapixels). The limit is 40.'];

    @ini_set('memory_limit', '512M');
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png' => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default => false,
    };
    if (!$src) return ['ok' => false, 'error' => 'The photo could not be read. Try another one.'];

    // Phones store the picture sideways and save the turn in EXIF.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $turn = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($turn) { $r = imagerotate($src, $turn, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }

    $dir = it_photo_dir();
    $name = bin2hex(random_bytes(16));
    $sha1 = sha1_file($file['tmp_name']);
    $big = it_photo_resample($src, $maxSide);
    $small = it_photo_resample($src, IT_PHOTO_THUMB);
    imagedestroy($src);
    $ok = imagejpeg($big, "$dir/$name.jpg", 84) && imagejpeg($small, "$dir/{$name}_t.jpg", 78);
    $bw = imagesx($big); $bh = imagesy($big);
    imagedestroy($big); imagedestroy($small);
    if (!$ok) { @unlink("$dir/$name.jpg"); @unlink("$dir/{$name}_t.jpg"); return ['ok' => false, 'error' => 'The photo could not be saved on the server.']; }

    $st = $conn->prepare("SELECT COUNT(*) FROM it_files WHERE record_id = ? AND is_cover = 1");
    $st->execute([$recordId]);
    $cover = (int)$st->fetchColumn() === 0 ? 1 : 0;
    $original = mb_substr(preg_replace('/[^\w .()-]+/u', '_', basename((string)($file['name'] ?? 'photo'))), 0, 200);
    $conn->prepare("INSERT INTO it_files (record_id, kind, file_name, original_name, mime, ext, sha1, size_bytes, width, height, is_cover, created_by, created_at) VALUES (?,'photo',?,?,'image/jpeg','jpg',?,?,?,?,?,?,NOW())")
         ->execute([$recordId, $name, $original, $sha1, (int)filesize("$dir/$name.jpg"), $bw, $bh, $cover, it_user_id()]);
    return ['ok' => true, 'id' => (int)$conn->lastInsertId()];
}

/** Scaled copy, longest side at most $max, transparent areas on white. */
function it_photo_resample($src, int $max) {
    $w = imagesx($src); $h = imagesy($src);
    $k = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

function it_photo_delete(PDO $conn, array $row): void {
    $dir = it_photo_dir();
    @unlink($dir . '/' . $row['file_name'] . '.' . ($row['ext'] ?? 'jpg'));
    @unlink($dir . '/' . $row['file_name'] . '_t.jpg');
    $conn->prepare("DELETE FROM it_files WHERE id = ?")->execute([$row['id']]);
    if ($row['is_cover']) {
        // The next photo becomes the cover.
        $conn->prepare("UPDATE it_files SET is_cover = 1 WHERE record_id = ? ORDER BY id LIMIT 1")->execute([$row['record_id']]);
    }
}

/* ------------------------------------------------------------------ Library */

const IT_DOC_MAX_BYTES = 25 * 1024 * 1024;
const IT_LIBRARY_PHOTO_SIZE = 2560; // reference photos keep more detail (labels, wiring)

/** Allowed document types: ext => [canonical mime, mimes finfo may report]. */
function it_doc_types(): array {
    $zip = ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'];
    $ole = ['application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream'];
    return [
        'pdf'  => ['application/pdf', ['application/pdf']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $zip)],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $zip)],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $zip)],
        'doc'  => ['application/msword', array_merge(['application/msword'], $ole)],
        'xls'  => ['application/vnd.ms-excel', array_merge(['application/vnd.ms-excel'], $ole)],
        'ppt'  => ['application/vnd.ms-powerpoint', array_merge(['application/vnd.ms-powerpoint'], $ole)],
        'txt'  => ['text/plain', ['text/plain']],
        'csv'  => ['text/csv', ['text/csv', 'text/plain', 'application/csv']],
    ];
}

/** Extensions the Library accepts, for the page's hints and the browser check. */
function it_library_exts(): array {
    return array_merge(['jpg', 'jpeg', 'png'], function_exists('imagecreatefromwebp') ? ['webp'] : [], array_keys(it_doc_types()));
}

/** Content checks a document must pass beyond its type. Returns an error or null. */
function it_doc_inspect(string $path, string $ext): ?string {
    $head = (string)file_get_contents($path, false, null, 0, 8);
    if ($ext === 'pdf' && !str_starts_with($head, '%PDF-')) return 'This is not a real PDF file.';
    if (in_array($ext, ['doc', 'xls', 'ppt'], true) && $head !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return 'This is not a real Office file.';
    if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
        if (!str_starts_with($head, "PK\x03\x04")) return 'This is not a real Office file.';
        if (!class_exists('ZipArchive')) return null;
        $z = new ZipArchive();
        if ($z->open($path) !== true) return 'The Office file is damaged.';
        $need = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$ext];
        $hasTypes = false; $hasPart = false; $total = 0;
        if ($z->numFiles > 5000) { $z->close(); return 'The Office file is not valid.'; }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $n = $st['name'];
            $total += (int)$st['size'];
            if ($n === '[Content_Types].xml') $hasTypes = true;
            if (str_starts_with($n, $need)) $hasPart = true;
            if (stripos($n, 'vbaProject.bin') !== false) { $z->close(); return 'Office files with macros are not allowed. Save it without macros (as .' . $ext . ') and try again.'; }
        }
        $z->close();
        if ($total > 300 * 1024 * 1024) return 'The Office file unpacks to an unsafe size.';
        if (!$hasTypes || !$hasPart) return 'The file name says .' . $ext . ' but the content is something else.';
    }
    if (in_array($ext, ['txt', 'csv'], true)) {
        $chunk = (string)file_get_contents($path, false, null, 0, 1048576);
        if (str_contains($chunk, "\0")) return 'This text file contains binary data.';
    }
    return null;
}

/**
 * Checks one Library upload and saves it as a new Library item in $folder.
 * Returns ['ok' => true, 'id' => record id] or ['ok' => false, 'error' => text, 'existing' => id?].
 */
function it_library_store(PDO $conn, array $file, string $folder, ?int $assetId = null): array {
    if (!isset($file['error']) || is_array($file['error'])) return ['ok' => false, 'error' => 'Send one file at a time.'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => it_photo_upload_error((int)$file['error'])];
    if (!is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'error' => 'That upload is not valid.'];
    $size = (int)filesize($file['tmp_name']);
    if ($size <= 0) return ['ok' => false, 'error' => 'The file is empty.'];

    $original = mb_substr(preg_replace('/[^\w .()&,+-]+/u', '_', basename((string)($file['name'] ?? 'file'))), 0, 200);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, it_library_exts(), true)) {
        return ['ok' => false, 'error' => '.' . ($ext ?: '?') . ' files cannot be added. Allowed: ' . strtoupper(implode(', ', array_unique(array_merge(['jpg', 'png'], array_keys(it_doc_types()))))) . '.'];
    }

    $sha1 = sha1_file($file['tmp_name']);
    $st = $conn->prepare("SELECT r.id, r.title FROM it_files f JOIN it_records r ON r.id = f.record_id WHERE f.sha1 = ? AND r.entity = 'doc' AND r.deleted_at IS NULL LIMIT 1");
    $st->execute([$sha1]);
    if ($dup = $st->fetch()) return ['ok' => false, 'error' => 'Already in the Library as “' . $dup['title'] . '”.', 'existing' => (int)$dup['id']];

    $isImage = in_array($ext, ['jpg', 'png', 'webp'], true);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!$isImage) {
        if ($size > IT_DOC_MAX_BYTES) return ['ok' => false, 'error' => 'The file is ' . round($size / 1048576, 1) . ' MB. The limit for documents is ' . (IT_DOC_MAX_BYTES / 1048576) . ' MB.'];
        [$canonical, $okMimes] = it_doc_types()[$ext];
        if (!in_array($mime, $okMimes, true)) return ['ok' => false, 'error' => 'The file name says .' . $ext . ' but the content is ' . ($mime ?: 'unknown') . '. It was not added.'];
        if ($err = it_doc_inspect($file['tmp_name'], $ext)) return ['ok' => false, 'error' => $err];
    } elseif (!isset(it_photo_types()[$mime])) {
        return ['ok' => false, 'error' => 'The file name says .' . $ext . ' but it is not a real photo.'];
    }

    $title = trim(preg_replace('/[_]+/', ' ', pathinfo($original, PATHINFO_FILENAME))) ?: 'Untitled';
    $data = ['title' => $title, 'category' => $folder, 'description' => '', 'asset' => $assetId ? (string)$assetId : '', 'tags' => ''];
    $conn->beginTransaction();
    try {
        $conn->prepare("INSERT INTO it_records (entity, title, data, created_by, updated_by, created_at, updated_at) VALUES ('doc', ?, ?, ?, ?, NOW(), NOW())")
             ->execute([$title, json_encode($data, JSON_UNESCAPED_UNICODE), it_user_id(), it_user_id()]);
        $rid = (int)$conn->lastInsertId();
        if ($isImage) {
            $res = it_photo_store($conn, $rid, $file, IT_LIBRARY_PHOTO_SIZE, false);
            if (!$res['ok']) { $conn->rollBack(); return $res; }
        } else {
            $dir = it_photo_dir();
            $name = bin2hex(random_bytes(16));
            if (!move_uploaded_file($file['tmp_name'], "$dir/$name.$ext")) { $conn->rollBack(); return ['ok' => false, 'error' => 'The file could not be saved on the server.']; }
            @chmod("$dir/$name.$ext", 0644);
            $conn->prepare("INSERT INTO it_files (record_id, kind, file_name, original_name, mime, ext, sha1, size_bytes, is_cover, created_by, created_at) VALUES (?,'doc',?,?,?,?,?,?,1,?,NOW())")
                 ->execute([$rid, $name, $original, $canonical, $ext, $sha1, $size, it_user_id()]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if (isset($dir, $name, $ext) && is_file("$dir/$name.$ext")) @unlink("$dir/$name.$ext");
        throw $e;
    }
    it_log($conn, 'create', 'doc', $rid, 'Added “' . $title . '” to the Library (' . strtoupper($ext) . ', ' . it_file_size($size) . ')');
    return ['ok' => true, 'id' => $rid];
}

/** Icon and colour for a file type. */
function it_file_look(string $ext): array {
    return match ($ext) {
        'pdf' => ['bi-file-earmark-pdf', '#ef4444'],
        'doc', 'docx' => ['bi-file-earmark-word', '#3b82f6'],
        'xls', 'xlsx', 'csv' => ['bi-file-earmark-excel', '#22c55e'],
        'ppt', 'pptx' => ['bi-file-earmark-ppt', '#f97316'],
        'txt' => ['bi-file-earmark-text', '#94a3b8'],
        default => ['bi-file-earmark-image', '#a855f7'],
    };
}

function it_file_size(int $b): string {
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
}
