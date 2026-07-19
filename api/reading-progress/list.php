<?php
// A member's own reading shelf. The public catalog remains public; statuses
// are deliberately private except for the single active "reading" selection.
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$stmt = pw_db()->prepare(
    "SELECT b.id, b.book_number, b.title, b.cover_image_url,
            COALESCE(p.status, 'not_started') AS status
     FROM books b
     LEFT JOIN user_book_progress p ON p.book_id = b.id AND p.user_id = ?
     ORDER BY b.book_number ASC"
);
$stmt->execute([(int)$user['id']]);
$books = array_map(function ($book) {
    return [
        'id' => (int)$book['id'],
        'book_number' => (int)$book['book_number'],
        'title' => $book['title'],
        'cover_image_url' => $book['cover_image_url'],
        'status' => $book['status'],
    ];
}, $stmt->fetchAll());

$currentBookId = null;
foreach ($books as $book) {
    if ($book['status'] === 'reading') {
        $currentBookId = $book['id'];
        break;
    }
}

pw_json(['ok' => true, 'current_book_id' => $currentBookId, 'books' => $books]);
