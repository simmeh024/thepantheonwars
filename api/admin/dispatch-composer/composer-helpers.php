<?php
/**
 * Shared validation, slug, and news-post-mapping helpers for the Dispatch
 * Composer. Body sanitisation, image validation, and slug-base generation
 * are deliberately reused from News Management's own helpers (not
 * duplicated) so a published Composer article can never drift from what
 * News Management itself would have produced for the same input.
 */

require_once __DIR__ . '/../news/news-helpers.php';

// Draft-time input: permissive. Title/excerpt/body/featured image may all be
// empty -- only enforces the same upper bounds News itself enforces, so a
// draft can never be saved in a shape that would surprise the writer later
// at publish time.
function pw_composer_input($input) {
    $title = isset($input['title']) ? trim((string)$input['title']) : '';
    if (mb_strlen($title) > 200) {
        pw_error('The title is too long (200 characters max).');
    }

    $excerpt = isset($input['excerpt']) ? trim((string)$input['excerpt']) : '';
    if (mb_strlen($excerpt) > 500) {
        pw_error('The excerpt is too long (500 characters max).');
    }

    $rawBody = isset($input['body']) ? trim((string)$input['body']) : '';
    if (mb_strlen($rawBody) > 15000) {
        pw_error('The article is too long (15,000 characters max).');
    }
    $body = $rawBody === '' ? '' : pw_news_sanitize_body($rawBody);

    $rawImage = isset($input['featured_image_url']) ? trim((string)$input['featured_image_url']) : '';
    $featuredImageUrl = null;
    if ($rawImage !== '') {
        $featuredImageUrl = pw_news_safe_image_url($rawImage);
        if ($featuredImageUrl === null) {
            pw_error('The featured image must be chosen from the News image library.');
        }
    }

    return [
        'title' => $title,
        'excerpt' => $excerpt,
        'body' => $body,
        'featured_image_url' => $featuredImageUrl,
    ];
}

// The Composer's own slug is only a working preview value scoped to this
// table -- it never has to match, and is never checked against, news_posts.
// The real published slug is (re)resolved from the title against news_posts
// by pw_news_unique_slug() at publish time, so an editor renaming the title
// after a long draft period can never collide with a slug some other
// unrelated news post claimed in the meantime.
function pw_composer_unique_slug($db, $title, $exceptId = null) {
    if (trim((string)$title) === '') {
        return null;
    }
    $base = pw_news_slug_base($title);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT id FROM dispatch_composer_posts WHERE slug = ?';
        $params = [$candidate];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = (int)$exceptId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = substr($base, 0, max(1, 100 - strlen((string)$suffix) - 1)) . '-' . $suffix;
        $suffix++;
    }
}

// Shared guard for every write endpoint that touches a draft's own fields or
// its attached dispatches: the post must exist, and must not already be
// published/archived (those are permanently read-only from this point on --
// see the class-level note in update.php).
function pw_composer_require_editable_post($db, $id) {
    $stmt = $db->prepare('SELECT * FROM dispatch_composer_posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) {
        pw_error('That Composer draft no longer exists.', 404);
    }
    if (in_array($post['status'], ['published', 'archived'], true)) {
        pw_error('This article is ' . $post['status'] . ' and is read-only. Duplicate it into a new draft to keep editing.', 409);
    }
    return $post;
}

// Publish-time validation is strict: every field the reader will actually
// see must be present and valid. Returns a list of hard-blocking error
// strings (empty = clear to publish).
function pw_composer_publish_errors($db, $post, $itemCount) {
    $errors = [];

    if (trim((string)$post['title']) === '') {
        $errors[] = 'Give the article a title.';
    } elseif (mb_strlen($post['title']) > 200) {
        $errors[] = 'The title is too long (200 characters max).';
    }

    $bodyText = trim((string)$post['body']);
    if ($bodyText === '' || (trim(strip_tags($bodyText)) === '' && stripos($bodyText, '<img') === false)) {
        $errors[] = 'Write the article body before publishing.';
    }

    if (!empty($post['featured_image_url']) && pw_news_safe_image_url($post['featured_image_url']) === null) {
        $errors[] = 'The featured image is no longer valid. Choose one from the News image library again.';
    }

    if ($itemCount < 1) {
        $errors[] = 'Attach at least one Development Dispatch as reference material before publishing.';
    }

    return $errors;
}

// Warnings are advisory only -- shown in the UI but never block publication.
function pw_composer_publish_warnings($db, $post, $items) {
    $warnings = [];
    if (empty($post['featured_image_url'])) {
        $warnings[] = 'This article has no featured image.';
    }
    if (empty($items)) {
        return $warnings;
    }

    $dispatchIds = array_column($items, 'dispatch_id');
    $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));
    $stmt = $db->prepare(
        "SELECT DISTINCT ci.dispatch_id
         FROM dispatch_composer_items ci
         JOIN dispatch_composer_posts cp ON cp.id = ci.composer_post_id
         WHERE cp.status = 'published' AND cp.id != ? AND ci.dispatch_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([(int)$post['id']], $dispatchIds));
    $reusedIds = array_column($stmt->fetchAll(), 'dispatch_id');
    if ($reusedIds) {
        $warnings[] = count($reusedIds) . ' attached dispatch(es) were previously used in another published article.';
    }

    return $warnings;
}
