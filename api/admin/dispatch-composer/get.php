<?php
/** Fetches one Composer draft plus its attached dispatches, in sort order. */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('dispatch_composer.view');
$db = pw_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing Composer post id.');
}

$stmt = $db->prepare(
    "SELECT cp.*, creator.username AS creator_username, updater.username AS updater_username,
            publisher.username AS publisher_username, np.slug AS news_slug
     FROM dispatch_composer_posts cp
     LEFT JOIN users creator ON creator.id = cp.created_by
     LEFT JOIN users updater ON updater.id = cp.updated_by
     LEFT JOIN users publisher ON publisher.id = cp.published_by
     LEFT JOIN news_posts np ON np.id = cp.news_post_id
     WHERE cp.id = ?"
);
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    pw_error('That Composer draft no longer exists.', 404);
}

$itemsStmt = $db->prepare(
    "SELECT ci.id AS item_id, ci.dispatch_id, ci.sort_order, ci.admin_note,
            d.sha, d.subject, d.body, d.tag, d.author, d.committed_at, d.url,
            dt.translation, dt.updated_at AS translation_updated_at
     FROM dispatch_composer_items ci
     JOIN dispatch_entries d ON d.id = ci.dispatch_id
     LEFT JOIN dispatch_translations dt ON dt.dispatch_id = d.id
     WHERE ci.composer_post_id = ?
     ORDER BY ci.sort_order ASC, ci.id ASC"
);
$itemsStmt->execute([$id]);

$items = array_map(function ($r) {
    return [
        'item_id' => (int)$r['item_id'],
        'dispatch_id' => (int)$r['dispatch_id'],
        'sort_order' => (int)$r['sort_order'],
        'admin_note' => $r['admin_note'],
        'sha' => $r['sha'],
        'short_sha' => substr($r['sha'], 0, 7),
        'subject' => $r['subject'],
        'body' => $r['body'],
        'tag' => $r['tag'],
        'author' => $r['author'],
        'committed_at' => $r['committed_at'],
        'url' => $r['url'],
        'translation' => $r['translation'],
        'has_translation' => $r['translation'] !== null,
    ];
}, $itemsStmt->fetchAll());

pw_json(['ok' => true, 'post' => [
    'id' => (int)$post['id'],
    'title' => $post['title'],
    'slug' => $post['slug'],
    'excerpt' => $post['excerpt'],
    'body' => $post['body'],
    'featured_image_url' => $post['featured_image_url'],
    'status' => $post['status'],
    'news_post_id' => $post['news_post_id'] !== null ? (int)$post['news_post_id'] : null,
    'news_slug' => $post['news_slug'],
    'created_by' => (int)$post['created_by'],
    'updated_by' => (int)$post['updated_by'],
    'published_by' => $post['published_by'] !== null ? (int)$post['published_by'] : null,
    'creator_username' => $post['creator_username'],
    'updater_username' => $post['updater_username'],
    'publisher_username' => $post['publisher_username'],
    'created_at' => $post['created_at'],
    'updated_at' => $post['updated_at'],
    'published_at' => $post['published_at'],
], 'items' => $items]);
