<?php
/**
 * Publishes a Composer draft as a real news_posts record, using the exact
 * same insertion helper (pw_news_create_post) News Management itself uses --
 * this endpoint never duplicates that logic, only decides when to call it.
 *
 * Duplicate-publish safety: the composer row is locked (FOR UPDATE) for the
 * whole transaction, the already-published and news_post_id-already-set
 * checks both happen inside that lock, and news_post_id carries its own
 * UNIQUE constraint as a last-resort database-level guarantee. A failed
 * validation or insert rolls the whole transaction back, so the composer
 * post is left exactly as it was (draft/ready) with no partial state.
 */
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../news/news-helpers.php';
require_once __DIR__ . '/composer-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$adminUser = pw_require_permission('dispatch_composer.publish');
$input = pw_input();
pw_require_csrf($input);

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    pw_error('Missing Composer post id.');
}

$db = pw_db();
$post = null;
$newsPostId = null;
$slug = null;

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT * FROM dispatch_composer_posts WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) {
        $db->rollBack();
        pw_error('That Composer draft no longer exists.', 404);
    }
    if ($post['status'] === 'published' || $post['news_post_id'] !== null) {
        $db->rollBack();
        pw_json([
            'ok' => false,
            'error' => 'This article has already been published.',
            'already_published' => true,
            'news_post_id' => $post['news_post_id'] !== null ? (int)$post['news_post_id'] : null,
        ], 409);
    }
    if ($post['status'] === 'archived') {
        $db->rollBack();
        pw_error('An archived draft cannot be published directly. Duplicate it into a new draft first.', 409);
    }

    $itemsStmt = $db->prepare('SELECT dispatch_id FROM dispatch_composer_items WHERE composer_post_id = ?');
    $itemsStmt->execute([$id]);
    $itemCount = count($itemsStmt->fetchAll());

    $errors = pw_composer_publish_errors($db, $post, $itemCount);
    if ($errors) {
        $db->rollBack();
        pw_json(['ok' => false, 'error' => $errors[0], 'errors' => $errors], 400);
    }

    $slug = pw_news_unique_slug($db, $post['title']);
    $newsData = [
        'title' => $post['title'],
        'body' => $post['body'],
        'header_image_url' => $post['featured_image_url'],
        'author_type' => 'member',
        'comments_enabled' => true,
        'tags' => [],
    ];
    $newsPostId = pw_news_create_post($db, $slug, $newsData, (int)$adminUser['id']);

    $db->prepare(
        "UPDATE dispatch_composer_posts
         SET status = 'published', news_post_id = ?, published_by = ?, published_at = NOW(), updated_by = ?
         WHERE id = ?"
    )->execute([$newsPostId, (int)$adminUser['id'], (int)$adminUser['id'], $id]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

pw_log_admin_activity(
    'dispatch_composer.published',
    'Published Composer article "' . $post['title'] . '" as News post #' . $newsPostId . '.',
    $adminUser
);
pw_notify_news_published((int)$adminUser['id'], $post['title'], $slug);

pw_json(['ok' => true, 'news_post_id' => $newsPostId, 'news_slug' => $slug]);
