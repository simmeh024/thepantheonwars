<?php
/**
 * Lists existing images for the Book Control "Choose from Library" picker.
 * Two sources are merged so admins can reuse anything already on the site,
 * not just images uploaded through this feature:
 *   1. uploads/book-images/{covers,characters,heroes}/ -- new admin uploads.
 *   2. images/covers/ (for type=cover) or the flat images/ root (for
 *      type=character or type=hero) -- the original hand-placed site assets
 *      (e.g. images/char-kael.jpg, images/world-neoh.jpg) that predate this
 *      feature and were never moved into subfolders.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_admin();

$type = isset($_GET['type']) ? $_GET['type'] : '';
$folders = [
    'cover' => 'covers',
    'character' => 'characters',
    'hero' => 'heroes',
];
if (!isset($folders[$type])) {
    pw_error('Unknown image type.');
}
$folderName = $folders[$type];

$exts = ['jpg', 'jpeg', 'png', 'webp'];

function pw_scan_images_dir($absDir, $urlPrefix, $exts) {
    $out = [];
    if (!is_dir($absDir)) {
        return $out;
    }
    $entries = scandir($absDir);
    if ($entries === false) {
        return $out;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $abs = $absDir . '/' . $entry;
        if (!is_file($abs)) {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) {
            continue;
        }
        $out[] = [
            'url' => $urlPrefix . '/' . $entry,
            'name' => $entry,
            'modified' => filemtime($abs),
        ];
    }
    return $out;
}

$webroot = __DIR__ . '/../../..';

$uploaded = pw_scan_images_dir(
    $webroot . '/uploads/book-images/' . $folderName,
    '/uploads/book-images/' . $folderName,
    $exts
);
// Newest uploads first.
usort($uploaded, function ($a, $b) { return $b['modified'] <=> $a['modified']; });

if ($type === 'cover') {
    $site = pw_scan_images_dir($webroot . '/images/covers', '/images/covers', $exts);
} else {
    // character + hero portraits historically all live flat in images/
    $site = pw_scan_images_dir($webroot . '/images', '/images', $exts);
}
usort($site, function ($a, $b) { return strcmp($a['name'], $b['name']); });

pw_json(['ok' => true, 'uploaded' => $uploaded, 'site' => $site]);
