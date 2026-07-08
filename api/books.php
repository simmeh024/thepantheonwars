<?php
/**
 * Public read for the book catalog. Powers books.html (full list, grouped
 * client-side by saga_phase) and chapter-one.html's preview (?id=N, or
 * ?book_number=N for a friendlier query param from public pages).
 *
 * No auth required -- this is marketing copy, same as every other page on
 * the public site.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$fields = 'id, book_number, saga_phase, writing_stage, title, status_label, meta_text, description,
           cover_image_url, character_image_url, character_alt,
           preview_enabled, preview_eyebrow, preview_lede, preview_hero_image_url, preview_body,
           preview_quote, preview_quote_cite,
           buy_kobo_url, buy_amazon_url, buy_apple_url, buy_bn_url';

$bookNumber = isset($_GET['book_number']) ? (int)$_GET['book_number'] : 0;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($bookNumber > 0 || $id > 0) {
    if ($bookNumber > 0) {
        $stmt = $db->prepare("SELECT $fields FROM books WHERE book_number = ?");
        $stmt->execute([$bookNumber]);
    } else {
        $stmt = $db->prepare("SELECT $fields FROM books WHERE id = ?");
        $stmt->execute([$id]);
    }
    $row = $stmt->fetch();
    if (!$row) {
        pw_error('Book not found.', 404);
    }
    $row['id'] = (int)$row['id'];
    $row['book_number'] = (int)$row['book_number'];
    $row['saga_phase'] = (int)$row['saga_phase'];
    $row['writing_stage'] = (int)$row['writing_stage'];
    $row['preview_enabled'] = (bool)$row['preview_enabled'];
    pw_json(['ok' => true, 'book' => $row]);
}

$stmt = $db->query("SELECT $fields FROM books ORDER BY book_number ASC");
$rows = $stmt->fetchAll();

$out = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['book_number'] = (int)$r['book_number'];
    $r['saga_phase'] = (int)$r['saga_phase'];
    $r['writing_stage'] = (int)$r['writing_stage'];
    $r['preview_enabled'] = (bool)$r['preview_enabled'];
    return $r;
}, $rows);

pw_json(['ok' => true, 'books' => $out]);
