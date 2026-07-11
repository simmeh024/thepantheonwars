<?php
/**
 * Lists existing images for World Control's "Choose from Library" picker.
 * Mirrors api/admin/books/list-images.php: merges new admin uploads with
 * the original hand-placed site assets that predate this feature (e.g.
 * images/world-neoh.jpg, images/char-syn.jpg, images/neoh-map.jpg), all of
 * which live flat in images/ today.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('worlds.view');

$type = isset($_GET['type']) ? $_GET['type'] : '';
$folders = [
    'thumb' => 'thumbs',
    'portrait' => 'portraits',
    'map' => 'maps',
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
    $webroot . '/uploads/world-images/' . $folderName,
    '/uploads/world-images/' . $folderName,
    $exts
);
usort($uploaded, function ($a, $b) { return $b['modified'] <=> $a['modified']; });

// All of the original hand-placed world/overlord/map art lives flat in
// images/ today -- same historical layout books-images inherited before
// this feature existed.
$site = pw_scan_images_dir($webroot . '/images', '/images', $exts);
usort($site, function ($a, $b) { return strcmp($a['name'], $b['name']); });

pw_json(['ok' => true, 'uploaded' => $uploaded, 'site' => $site]);
