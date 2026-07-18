<?php
/**
 * Re-encodes an uploaded forum image before it enters a post. The stored
 * JPG gets a random server-side name, matching the same pattern already
 * used for News/Book/World images -- the returned URL is inserted into the
 * post body as [img]<url>[/img], reusing the BBCode image rendering that
 * already exists rather than adding a second image path through the forum.
 * Any logged-in member may attach an image to their own post; there is no
 * separate forum-upload permission.
 */
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$csrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    pw_error('Invalid or expired session token. Please refresh the page and try again.', 403);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $error = isset($_FILES['image']) ? $_FILES['image']['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        pw_error('That image is too large. Please choose a file under 8MB.');
    }
    pw_error('No image was uploaded, or the upload failed.');
}

$tmpPath = $_FILES['image']['tmp_name'];
if ((int)$_FILES['image']['size'] > 8 * 1024 * 1024 || !is_uploaded_file($tmpPath)) {
    pw_error('That image could not be accepted. Please choose a valid file under 8MB.');
}

$info = @getimagesize($tmpPath);
if ($info === false) {
    pw_error('That file does not look like a valid image.');
}
switch ($info['mime']) {
    case 'image/jpeg': $source = @imagecreatefromjpeg($tmpPath); break;
    case 'image/png': $source = @imagecreatefrompng($tmpPath); break;
    case 'image/webp': $source = @imagecreatefromwebp($tmpPath); break;
    default: pw_error('Please upload a JPG, PNG, or WEBP image.');
}
if (!$source) {
    pw_error('That image could not be processed. Please try a different file.');
}

$sourceWidth = imagesx($source);
$sourceHeight = imagesy($source);
$maxDimension = 1600;
$scale = max($sourceWidth, $sourceHeight) > $maxDimension
    ? $maxDimension / max($sourceWidth, $sourceHeight) : 1;
$width = max(1, (int)round($sourceWidth * $scale));
$height = max(1, (int)round($sourceHeight * $scale));

// Flatten alpha onto the site's dark neutral, then emit one predictable JPG.
$destination = imagecreatetruecolor($width, $height);
$background = imagecolorallocate($destination, 20, 18, 28);
imagefilledrectangle($destination, 0, 0, $width, $height, $background);
imagecopyresampled($destination, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
imagedestroy($source);

$directory = __DIR__ . '/../../uploads/forum-images';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    imagedestroy($destination);
    pw_error('Could not prepare the forum image storage.');
}
$filename = 'img_' . bin2hex(random_bytes(8)) . '.jpg';
$path = $directory . '/' . $filename;
$temporaryPath = $path . '.tmp';
if (!imagejpeg($destination, $temporaryPath, 86)) {
    imagedestroy($destination);
    pw_error('Could not save the processed image.');
}
imagedestroy($destination);
rename($temporaryPath, $path);

pw_json([
    'ok' => true,
    'url' => '/uploads/forum-images/' . $filename,
    'name' => $filename,
    'width' => $width,
    'height' => $height,
]);
