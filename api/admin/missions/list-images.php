<?php
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('missions.view');
$webroot = __DIR__ . '/../../..';
$extensions = ['jpg', 'jpeg', 'png', 'webp'];
$scan = static function ($directory, $prefix) use ($extensions) {
    $images = [];
    if (!is_dir($directory)) return $images;
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !is_file($directory . '/' . $entry)) continue;
        if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $extensions, true)) continue;
        $images[] = ['url' => $prefix . '/' . $entry, 'name' => $entry, 'modified' => filemtime($directory . '/' . $entry)];
    }
    return $images;
};
$uploaded = $scan($webroot . '/uploads/mission-crew-images', '/uploads/mission-crew-images');
$site = $scan($webroot . '/images', 'images');
usort($uploaded, static function ($a, $b) { return $b['modified'] <=> $a['modified']; });
usort($site, static function ($a, $b) { return strcmp($a['name'], $b['name']); });
pw_json(['ok' => true, 'uploaded' => $uploaded, 'site' => $site]);
