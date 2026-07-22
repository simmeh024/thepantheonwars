<?php
/**
 * Lists existing images for Timeline Control's "Choose from Library" picker.
 * Mirrors api/admin/known-figures/list-images.php: merges new admin uploads
 * with the existing hand-placed site art in images/. That second list matters
 * more here than elsewhere -- a lore event will often want to reuse artwork
 * that already exists for a world, book or figure rather than a new upload.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('timeline.view');

$type = isset($_GET['type']) ? $_GET['type'] : '';
$folders = [
    'timeline' => 'events',
];
if (!isset($folders[$type])) {
    pw_error('Unknown image type.');
}
$folderName = $folders[$type];

$exts = ['jpg', 'jpeg', 'png', 'webp'];

function pw_scan_timeline_images_dir($absDir, $urlPrefix, $exts) {
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

$uploaded = pw_scan_timeline_images_dir(
    $webroot . '/uploads/timeline-images/' . $folderName,
    '/uploads/timeline-images/' . $folderName,
    $exts
);
usort($uploaded, function ($a, $b) { return $b['modified'] <=> $a['modified']; });

// Existing site art lives flat in images/, and lore events frequently reuse
// it (a world map, a figure portrait) rather than needing new artwork.
$site = pw_scan_timeline_images_dir($webroot . '/images', '/images', $exts);
usort($site, function ($a, $b) { return strcmp($a['name'], $b['name']); });

pw_json(['ok' => true, 'uploaded' => $uploaded, 'site' => $site]);
