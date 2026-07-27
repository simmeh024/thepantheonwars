<?php
require_once __DIR__ . '/../helpers.php';

$user = pw_require_login();
$page = max(1, min(500, (int)($_GET['page'] ?? 1)));
$perPage = 12;
$offset = ($page - 1) * $perPage;
$db = pw_db();
try {
    $count = $db->prepare('SELECT COUNT(*) FROM reputation_ledger WHERE user_id = ?');
    $count->execute([$user['id']]);
    $total = (int)$count->fetchColumn();
    $stmt = $db->prepare("SELECT l.id, l.label, l.points, l.multiplier, l.note, l.created_at, l.source_type, l.source_id, c.topic_id AS comment_topic_id
        FROM reputation_ledger l
        LEFT JOIN comments c ON l.source_type = 'comment' AND c.id = l.source_id
        WHERE l.user_id = ? ORDER BY l.id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $entries = array_map(function ($row) {
        $sourceUrl = null;
        if ($row['source_type'] === 'topic' && $row['source_id']) $sourceUrl = 'community.html?topic=' . (int)$row['source_id'];
        if ($row['source_type'] === 'comment' && $row['comment_topic_id']) $sourceUrl = 'community.html?topic=' . (int)$row['comment_topic_id'];
        if ($row['source_type'] === 'book' && $row['source_id']) $sourceUrl = 'books.html#book-' . (int)$row['source_id'];
        if ($row['source_type'] === 'quiz_result') $sourceUrl = 'quiz.html';
        /* Missions award reputation through pw_award_reputation() like anything
         * else, but had no route back to where they happened -- so an operation
         * was the one signal in the feed the reader could not click. Both kinds
         * land on the same command view: source_id is a mission definition, and
         * the page has no deep link for one. */
        if ($row['source_type'] === 'mission' || $row['source_type'] === 'mission_daily') $sourceUrl = 'missions.html';
        if ($row['source_type'] === 'severe_weather') $sourceUrl = 'worlds.html';
        if ($row['source_type'] === 'news_comment' && $row['source_id']) $sourceUrl = 'news.html';
        $row['source_url'] = $sourceUrl;
        unset($row['comment_topic_id']);
        return $row;
    }, $stmt->fetchAll());
    pw_json(['ok' => true, 'entries' => $entries, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)), 'total' => $total]);
} catch (Throwable $e) {
    pw_json(['ok' => true, 'entries' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'migration_required' => true]);
}
