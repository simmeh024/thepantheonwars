<?php
/**
 * "Content Popularity" card: which specific World/Book/Overlord visitors
 * actually looked at, using the query_string column added alongside this
 * feature (see sql/migration_visitor_stats_content_tracking.sql). Older
 * page_views rows predate that column and are simply excluded -- there is
 * no way to recover which item they were about after the fact.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('analytics.view');
$db = pw_db();

$range = isset($_GET['range']) ? (int)$_GET['range'] : 30;
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}

$includeAdmin = isset($_GET['include_admin']) && $_GET['include_admin'] === '1';
$adminFilterSql = $includeAdmin ? '1=1' : pw_admin_view_filter_sql();

function pw_visitor_stats_query_string_counts(PDO $db, string $path, int $range, string $adminFilterSql): array
{
    try {
        $stmt = $db->prepare(
            "SELECT query_string, COUNT(*) AS views
             FROM page_views
             WHERE path = ? AND query_string IS NOT NULL
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY) AND $adminFilterSql
             GROUP BY query_string"
        );
        $stmt->execute([$path, $range]);
    } catch (PDOException $e) {
        // The query_string migration may not be applied yet.
        return [];
    }
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        parse_str(ltrim((string)$row['query_string'], '?'), $params);
        $counts[] = ['params' => $params, 'views' => (int)$row['views']];
    }
    return $counts;
}

// Worlds: world.html?slug=<slug>
$worldCounts = [];
foreach (pw_visitor_stats_query_string_counts($db, '/world.html', $range, $adminFilterSql) as $row) {
    $slug = $row['params']['slug'] ?? null;
    if ($slug) {
        $worldCounts[$slug] = ($worldCounts[$slug] ?? 0) + $row['views'];
    }
}
$worlds = [];
if ($worldCounts) {
    $placeholders = implode(',', array_fill(0, count($worldCounts), '?'));
    $stmt = $db->prepare("SELECT slug, name FROM worlds WHERE slug IN ($placeholders)");
    $stmt->execute(array_keys($worldCounts));
    foreach ($stmt->fetchAll() as $row) {
        $worlds[] = ['label' => $row['name'], 'views' => $worldCounts[$row['slug']]];
    }
    usort($worlds, fn($a, $b) => $b['views'] <=> $a['views']);
}

// Overlords: overlord.html?slug=<slug>
$overlordCounts = [];
foreach (pw_visitor_stats_query_string_counts($db, '/overlord.html', $range, $adminFilterSql) as $row) {
    $slug = $row['params']['slug'] ?? null;
    if ($slug) {
        $overlordCounts[$slug] = ($overlordCounts[$slug] ?? 0) + $row['views'];
    }
}
$overlords = [];
if ($overlordCounts) {
    $placeholders = implode(',', array_fill(0, count($overlordCounts), '?'));
    $stmt = $db->prepare("SELECT slug, name FROM overlords WHERE slug IN ($placeholders)");
    $stmt->execute(array_keys($overlordCounts));
    foreach ($stmt->fetchAll() as $row) {
        $overlords[] = ['label' => $row['name'], 'views' => $overlordCounts[$row['slug']]];
    }
    usort($overlords, fn($a, $b) => $b['views'] <=> $a['views']);
}

// Books: chapter-one.html?book=<book_number> -- the only per-book public URL.
$bookCounts = [];
foreach (pw_visitor_stats_query_string_counts($db, '/chapter-one.html', $range, $adminFilterSql) as $row) {
    $bookNumber = isset($row['params']['book']) ? (int)$row['params']['book'] : null;
    if ($bookNumber) {
        $bookCounts[$bookNumber] = ($bookCounts[$bookNumber] ?? 0) + $row['views'];
    }
}
$books = [];
if ($bookCounts) {
    $placeholders = implode(',', array_fill(0, count($bookCounts), '?'));
    $stmt = $db->prepare("SELECT book_number, title FROM books WHERE book_number IN ($placeholders)");
    $stmt->execute(array_keys($bookCounts));
    foreach ($stmt->fetchAll() as $row) {
        $books[] = ['label' => $row['title'], 'views' => $bookCounts[$row['book_number']]];
    }
    usort($books, fn($a, $b) => $b['views'] <=> $a['views']);
}

pw_json([
    'ok' => true,
    'range' => $range,
    'include_admin' => $includeAdmin,
    'worlds' => array_slice($worlds, 0, 10),
    'overlords' => array_slice($overlords, 0, 10),
    'books' => array_slice($books, 0, 10),
]);
