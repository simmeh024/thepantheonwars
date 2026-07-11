<?php
/**
 * Lists existing images for Overlord Control's "Choose from Library" picker.
 * Mirrors api/admin/worlds/list-images.php: merges new admin uploads with
 * the original hand-placed site assets that predate this feature (e.g.
 * images/char-syn.jpg), all of which live flat in images/ today.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('overlords.view');

$type = isset($_GET['type']) ? $_GET['type'] : '';
$folders = [
    'portrait' => 'portraits',
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
    $webroot . '/uploads/overlord-images/' . $folderName,
    '/uploads/overlord-images/' . $folderName,
    $exts
);
usort($uploaded, function ($a, $b) { return $b['modified'] <=> $a['modified']; });

// All of the original hand-placed overlord portrait art lives flat in
// images/ today -- same historical layout world/book images inherited
// before this feature existed.
$site = pw_scan_images_dir($webroot . '/images', '/images', $exts);
usort($site, function ($a, $b) { return strcmp($a['name'], $b['name']); });

pw_json(['ok' => true, 'uploaded' => $uploaded, 'site' => $site]);
