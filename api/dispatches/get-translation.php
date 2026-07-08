<?php
require_once __DIR__ . '/../helpers.php';

// Public read (no login required) -- same visibility as the dispatch list
// itself. Kept as its own lightweight endpoint so the list response doesn't
// have to carry every translation's text for entries nobody expands.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing dispatch id.');
}

$db = pw_db();
$stmt = $db->prepare('SELECT translation FROM dispatch_translations WHERE dispatch_id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    pw_error('No translation available for that dispatch.', 404);
}

pw_json(['ok' => true, 'translation' => $row['translation']]);
