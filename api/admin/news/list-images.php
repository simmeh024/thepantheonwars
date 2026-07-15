<?php
/** Lists re-encoded, server-generated News library images newest first. */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('news.view');

$directory = __DIR__ . '/../../../uploads/news-images';
$images = [];
if (is_dir($directory)) {
    $entries = scandir($directory);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if (!preg_match('/^img_[a-f0-9]{16}\.jpg$/', $entry)) continue;
            $path = $directory . '/' . $entry;
            if (!is_file($path)) continue;
            $size = @getimagesize($path);
            $images[] = [
                'url' => '/uploads/news-images/' . $entry,
                'name' => $entry,
                'modified' => filemtime($path),
                'width' => $size ? (int)$size[0] : null,
                'height' => $size ? (int)$size[1] : null,
            ];
        }
    }
}
usort($images, function ($left, $right) { return $right['modified'] <=> $left['modified']; });
pw_json(['ok' => true, 'images' => $images]);
