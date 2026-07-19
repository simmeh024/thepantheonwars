<?php
/**
 * Re-encodes editorial images before they enter the News library. The stored
 * JPG has a random server-side name, so News body markup can safely whitelist
 * this one directory without accepting arbitrary external image URLs.
 */
require_once __DIR__ . '/../../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

pw_require_permission('news.edit');
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

$directory = __DIR__ . '/../../../uploads/news-images';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    imagedestroy($destination);
    pw_error('Could not prepare the News image library.');
}
$filename = 'img_' . bin2hex(random_bytes(8)) . '.jpg';
$path = $directory . '/' . $filename;
$temporaryPath = $path . '.tmp';
if (!imagejpeg($destination, $temporaryPath, 86)) {
    imagedestroy($destination);
    pw_error('Could not save the processed image.');
}
rename($temporaryPath, $path);

// Homepage dispatch cards use this smaller companion only on phone-sized
// screens.  Keeping the original 1600px editorial image preserves the
// desktop/library source, while the 720px variant avoids downloading a large
// image for a roughly 356px-wide mobile card.  It is intentionally best-effort:
// the original upload remains valid even if a shared-hosting filesystem hiccup
// prevents the optional derivative from being written.
$mobileFilename = substr($filename, 0, -4) . '-mobile.jpg';
$mobilePath = $directory . '/' . $mobileFilename;
$mobileMaxWidth = 720;
if ($width > $mobileMaxWidth) {
    $mobileWidth = $mobileMaxWidth;
    $mobileHeight = max(1, (int)round($height * ($mobileWidth / $width)));
    $mobileImage = imagecreatetruecolor($mobileWidth, $mobileHeight);
    if ($mobileImage) {
        imagecopyresampled($mobileImage, $destination, 0, 0, 0, 0, $mobileWidth, $mobileHeight, $width, $height);
        $mobileTemporaryPath = $mobilePath . '.tmp';
        if (imagejpeg($mobileImage, $mobileTemporaryPath, 78)) {
            rename($mobileTemporaryPath, $mobilePath);
        } else {
            @unlink($mobileTemporaryPath);
        }
        imagedestroy($mobileImage);
    }
}

// Dispatch cards render around 356px wide on a phone. Generate a second,
// closely sized companion so the homepage does not fetch the larger 720px
// fallback for newly uploaded editorial images.
$cardFilename = substr($filename, 0, -4) . '-card.jpg';
$cardPath = $directory . '/' . $cardFilename;
$cardMaxWidth = 400;
if ($width > $cardMaxWidth) {
    $cardWidth = $cardMaxWidth;
    $cardHeight = max(1, (int)round($height * ($cardWidth / $width)));
    $cardImage = imagecreatetruecolor($cardWidth, $cardHeight);
    if ($cardImage) {
        imagecopyresampled($cardImage, $destination, 0, 0, 0, 0, $cardWidth, $cardHeight, $width, $height);
        $cardTemporaryPath = $cardPath . '.tmp';
        if (imagejpeg($cardImage, $cardTemporaryPath, 78)) {
            rename($cardTemporaryPath, $cardPath);
        } else {
            @unlink($cardTemporaryPath);
        }
        imagedestroy($cardImage);
    }
}
imagedestroy($destination);

pw_json([
    'ok' => true,
    'url' => '/uploads/news-images/' . $filename,
    'mobile_url' => is_file($mobilePath) ? '/uploads/news-images/' . $mobileFilename : null,
    'card_url' => is_file($cardPath) ? '/uploads/news-images/' . $cardFilename : null,
    'name' => $filename,
    'width' => $width,
    'height' => $height,
]);
