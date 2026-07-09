<?php
/**
 * Book Control image upload endpoint. Accepts multipart/form-data (not
 * JSON, since it carries a file), same reasoning as api/upload-avatar.php:
 * pw_input()/pw_require_csrf() only read php://input as JSON and would miss
 * $_POST entirely for this request type.
 *
 * Security notes (mirrors upload-avatar.php):
 * - The uploaded file is decoded and RE-ENCODED via GD (never stored raw),
 *   stripping any non-image payload smuggled inside a file with an image
 *   extension/mime type.
 * - The destination filename is always server-generated (random hex), never
 *   taken from the client, so there is no path traversal surface.
 * - Each uploads/book-images/* subdirectory has a .htaccess denying .php
 *   execution as defense-in-depth, matching uploads/avatars/.
 *
 * Unlike avatars (always cropped to a 400x400 square), book art keeps its
 * original aspect ratio -- covers, hero banners, and character portraits are
 * all non-square. Images are only downscaled (never upscaled) so a large
 * source photo doesn't bloat storage.
 */

require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

pw_require_admin();

// Manual CSRF check: multipart/form-data means the token arrives as a
// regular $_POST field, not inside a JSON body.
$csrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    pw_error('Invalid or expired session token. Please refresh the page and try again.', 403);
}

$type = isset($_POST['type']) ? $_POST['type'] : '';
$folders = [
    'cover' => 'covers',
    'character' => 'characters',
    'hero' => 'heroes',
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

// Flatten any transparency onto a solid background (matches upload-avatar.php)
// so PNG/WEBP images with alpha channels don't end up with a black background.
$srcWidth = imagesx($srcImage);
$srcHeight = imagesy($srcImage);

$flat = imagecreatetruecolor($srcWidth, $srcHeight);
$bg = imagecolorallocate($flat, 20, 18, 28); // matches the site's dark panel bg
imagefilledrectangle($flat, 0, 0, $srcWidth, $srcHeight, $bg);
imagealphablending($flat, true);
imagecopy($flat, $srcImage, 0, 0, 0, 0, $srcWidth, $srcHeight);
imagedestroy($srcImage);
$srcImage = $flat;

// Downscale only (never upscale), preserving aspect ratio, capped at 1600px
// on the longest side -- plenty for cover art and hero banners at web size.
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

$uploadDir = __DIR__ . '/../../../uploads/book-images/' . $folderName;
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

pw_json(['ok' => true, 'url' => '/uploads/book-images/' . $folderName . '/' . $filename]);
