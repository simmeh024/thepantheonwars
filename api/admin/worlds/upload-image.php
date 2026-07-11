<?php
/**
 * World Control image upload endpoint. Mirrors
 * api/admin/books/upload-image.php exactly (multipart/form-data, GD
 * re-encode, server-generated filename, per-folder .htaccess denying PHP
 * execution) -- see that file's comments for the full security rationale.
 */

require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('worlds.edit');

$csrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    pw_error('Invalid or expired session token. Please refresh the page and try again.', 403);
}

$type = isset($_POST['type']) ? $_POST['type'] : '';
$folders = [
    'thumb' => 'thumbs',
    'portrait' => 'portraits',
    'map' => 'maps',
];
if (!isset($folders[$type])) {
    pw_error('Unknown image type.');
}
$folderName = $folders[$type];

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $err = isset($_FILES['image']) ? $_FILES['image']['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        pw_error('That image is too large. Please choose a file under 8MB.');
    }
    pw_error('No image was uploaded, or the upload failed.');
}

$tmpPath = $_FILES['image']['tmp_name'];
$fileSize = $_FILES['image']['size'];

if ($fileSize > 8 * 1024 * 1024) {
    pw_error('That image is too large. Please choose a file under 8MB.');
}

if (!is_uploaded_file($tmpPath)) {
    pw_error('Upload failed. Please try again.');
}

$info = @getimagesize($tmpPath);
if ($info === false) {
    pw_error('That file does not look like a valid image.');
}

$mime = $info['mime'];
switch ($mime) {
    case 'image/jpeg':
        $srcImage = @imagecreatefromjpeg($tmpPath);
        break;
    case 'image/png':
        $srcImage = @imagecreatefrompng($tmpPath);
        break;
    case 'image/webp':
        $srcImage = @imagecreatefromwebp($tmpPath);
        break;
    default:
        pw_error('Please upload a JPG, PNG, or WEBP image.');
}

if (!$srcImage) {
    pw_error('That image could not be processed. Please try a different file.');
}

$srcWidth = imagesx($srcImage);
$srcHeight = imagesy($srcImage);

$flat = imagecreatetruecolor($srcWidth, $srcHeight);
$bg = imagecolorallocate($flat, 20, 18, 28);
imagefilledrectangle($flat, 0, 0, $srcWidth, $srcHeight, $bg);
imagealphablending($flat, true);
imagecopy($flat, $srcImage, 0, 0, 0, 0, $srcWidth, $srcHeight);
imagedestroy($srcImage);
$srcImage = $flat;

$maxDim = 1600;
$longest = max($srcWidth, $srcHeight);
if ($longest > $maxDim) {
    $scale = $maxDim / $longest;
    $destWidth = (int)round($srcWidth * $scale);
    $destHeight = (int)round($srcHeight * $scale);
} else {
    $destWidth = $srcWidth;
    $destHeight = $srcHeight;
}

$dest = imagecreatetruecolor($destWidth, $destHeight);
imagecopyresampled(
    $dest, $srcImage,
    0, 0, 0, 0,
    $destWidth, $destHeight, $srcWidth, $srcHeight
);
imagedestroy($srcImage);

// uploads/world-images/{thumbs,portraits,maps} ship with a committed
// .htaccess (same as uploads/book-images/*) -- not generated here, so the
// directory is never briefly unprotected between creation and first upload.
$uploadDir = __DIR__ . '/../../../uploads/world-images/' . $folderName;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'img_' . bin2hex(random_bytes(8)) . '.jpg';
$destPath = $uploadDir . '/' . $filename;
$tmpDestPath = $destPath . '.tmp';

if (!imagejpeg($dest, $tmpDestPath, 85)) {
    imagedestroy($dest);
    pw_error('Could not save the processed image. Please try again.');
}
imagedestroy($dest);

rename($tmpDestPath, $destPath);

pw_json(['ok' => true, 'url' => '/uploads/world-images/' . $folderName . '/' . $filename]);
