<?php
/**
 * Avatar upload endpoint. Accepts multipart/form-data (not JSON, since it
 * carries a file), so it cannot use pw_input()/pw_require_csrf() — those only
 * read php://input as JSON and would miss $_POST entirely for this request.
 *
 * Security notes:
 * - The uploaded file is decoded and RE-ENCODED via GD (never stored raw).
 *   This strips any non-image payload smuggled inside a file with an image
 *   extension/mime type (e.g. disguised PHP).
 * - Output is always saved as uploads/avatars/{user_id}.jpg — the filename
 *   is derived from the authenticated session's user id, never from the
 *   client, so there is no path traversal surface.
 * - uploads/avatars/.htaccess denies .php execution in that directory as a
 *   defense-in-depth measure even though nothing there should ever be .php.
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();

// Manual CSRF check: multipart/form-data means the token arrives as a
// regular $_POST field, not inside a JSON body.
$csrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    pw_error('Invalid or expired session token. Please refresh the page and try again.', 403);
}

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $err = isset($_FILES['avatar']) ? $_FILES['avatar']['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        pw_error('That image is too large. Please choose a file under 5MB.');
    }
    pw_error('No image was uploaded, or the upload failed.');
}

$tmpPath = $_FILES['avatar']['tmp_name'];
$fileSize = $_FILES['avatar']['size'];

if ($fileSize > 5 * 1024 * 1024) {
    pw_error('That image is too large. Please choose a file under 5MB.');
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

// Flatten any transparency onto a solid background before we center-crop +
// resize, so PNG/WEBP images with alpha channels don't end up with a black
// square where the transparency was.
$srcWidth = imagesx($srcImage);
$srcHeight = imagesy($srcImage);

$flat = imagecreatetruecolor($srcWidth, $srcHeight);
$bg = imagecolorallocate($flat, 20, 18, 28); // matches the site's dark panel bg
imagefilledrectangle($flat, 0, 0, $srcWidth, $srcHeight, $bg);
imagealphablending($flat, true);
imagecopy($flat, $srcImage, 0, 0, 0, 0, $srcWidth, $srcHeight);
imagedestroy($srcImage);
$srcImage = $flat;

// Center-crop to a square.
$cropSize = min($srcWidth, $srcHeight);
$cropX = (int)(($srcWidth - $cropSize) / 2);
$cropY = (int)(($srcHeight - $cropSize) / 2);

$targetSize = 400;
$dest = imagecreatetruecolor($targetSize, $targetSize);
imagecopyresampled(
    $dest, $srcImage,
    0, 0, $cropX, $cropY,
    $targetSize, $targetSize, $cropSize, $cropSize
);
imagedestroy($srcImage);

$uploadDir = __DIR__ . '/../uploads/avatars';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$destPath = $uploadDir . '/' . (int)$user['id'] . '.jpg';
$tmpDestPath = $destPath . '.tmp';

if (!imagejpeg($dest, $tmpDestPath, 85)) {
    imagedestroy($dest);
    pw_error('Could not save the processed image. Please try again.');
}
imagedestroy($dest);

// Atomic-ish replace so a half-written file is never served mid-upload.
rename($tmpDestPath, $destPath);

pw_json(['ok' => true, 'avatarUrl' => '/uploads/avatars/' . (int)$user['id'] . '.jpg?v=' . time()]);
