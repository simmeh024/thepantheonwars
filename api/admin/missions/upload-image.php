<?php
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
pw_require_permission('missions.edit');
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) pw_error('Invalid or expired session token. Please refresh the page and try again.', 403);

/* Two libraries share this endpoint: crew portraits, and the Missions page
 * watermark. They are kept in separate folders because they are browsed
 * separately -- a page background has no business appearing in the portrait
 * picker -- and because a watermark keeps its transparency while a portrait is
 * always flattened onto the card background. */
$kind = ($_POST['kind'] ?? 'crew') === 'watermark' ? 'watermark' : 'crew';
$folder = $kind === 'watermark' ? 'mission-images' : 'mission-crew-images';
$maxEdge = $kind === 'watermark' ? 1600 : 1200;

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) pw_error('No image was uploaded, or the upload failed.');
if ($_FILES['image']['size'] > 8 * 1024 * 1024 || !is_uploaded_file($_FILES['image']['tmp_name'])) pw_error('Choose a valid image under 8MB.');
$info = @getimagesize($_FILES['image']['tmp_name']);
if (!$info) pw_error('That file does not look like a valid image.');
switch ($info['mime']) {
    case 'image/jpeg': $source = @imagecreatefromjpeg($_FILES['image']['tmp_name']); break;
    case 'image/png': $source = @imagecreatefrompng($_FILES['image']['tmp_name']); break;
    case 'image/webp': $source = @imagecreatefromwebp($_FILES['image']['tmp_name']); break;
    default: pw_error('Please upload a JPG, PNG, or WEBP image.');
}
if (!$source) pw_error('That image could not be processed.');
$sourceWidth = imagesx($source); $sourceHeight = imagesy($source);
$scale = min(1, $maxEdge / max($sourceWidth, $sourceHeight));
$width = max(1, (int)round($sourceWidth * $scale)); $height = max(1, (int)round($sourceHeight * $scale));
$destination = imagecreatetruecolor($width, $height);

/* A watermark is normally a cut-out emblem, so flattening it onto an opaque
 * panel the way a portrait is would fill the whole page with a rectangle. Alpha
 * is preserved instead and the result written as PNG. */
$keepAlpha = $kind === 'watermark';
if ($keepAlpha) {
    imagealphablending($destination, false);
    imagesavealpha($destination, true);
    imagefilledrectangle($destination, 0, 0, $width, $height, imagecolorallocatealpha($destination, 0, 0, 0, 127));
    imagealphablending($destination, true);
} else {
    imagefilledrectangle($destination, 0, 0, $width, $height, imagecolorallocate($destination, 20, 18, 28));
}
imagecopyresampled($destination, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight); imagedestroy($source);

$directory = __DIR__ . '/../../../uploads/' . $folder;
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) { imagedestroy($destination); pw_error('Could not prepare the mission image library.'); }
$filename = 'img_' . bin2hex(random_bytes(8)) . ($keepAlpha ? '.png' : '.jpg');
$destinationPath = $directory . '/' . $filename;
$written = $keepAlpha ? imagepng($destination, $destinationPath . '.tmp', 6) : imagejpeg($destination, $destinationPath . '.tmp', 85);
if (!$written) { imagedestroy($destination); pw_error('Could not save the processed image.'); }
imagedestroy($destination); rename($destinationPath . '.tmp', $destinationPath);
pw_json(['ok' => true, 'url' => '/uploads/' . $folder . '/' . $filename]);
