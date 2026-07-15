<?php
/**
 * Shared validation and slug utilities for News Management.
 *
 * Public posts are deliberately stored as plain text. The public renderer
 * turns blank-line-separated text into paragraphs after escaping it, which
 * keeps a staff-authored update from becoming a stored-XSS surface.
 */

function pw_news_input($input) {
    $title = isset($input['title']) ? trim((string)$input['title']) : '';
    $body = isset($input['body']) ? trim((string)$input['body']) : '';
    $authorType = isset($input['author_type']) ? trim((string)$input['author_type']) : 'bh4';

    if ($title === '') {
        pw_error('Give the news post a title.');
    }
    if (mb_strlen($title) > 200) {
        pw_error('The title is too long (200 characters max).');
    }
    if ($body === '') {
        pw_error('Write the public update before publishing it.');
    }
    if (mb_strlen($body) > 15000) {
        pw_error('The update is too long (15,000 characters max).');
    }
    if (!in_array($authorType, ['bh4', 'member'], true)) {
        pw_error('Choose BH-4 or yourself as the author.');
    }

    return [
        'title' => $title,
        'body' => $body,
        'author_type' => $authorType,
        'tags' => pw_news_normalize_tags(isset($input['tags']) ? $input['tags'] : []),
    ];
}

function pw_news_normalize_tags($rawTags) {
    if (!is_array($rawTags)) {
        pw_error('News tags must be a list.');
    }
    $tags = [];
    $seen = [];
    foreach ($rawTags as $raw) {
        if (!is_string($raw)) continue;
        $label = trim(preg_replace('/\s+/', ' ', ltrim($raw, '#')));
        if ($label === '') continue;
        if (mb_strlen($label) > 40) {
            pw_error('Each news tag must be 40 characters or fewer.');
        }
        $key = mb_strtolower($label);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $tags[] = $label;
    }
    if (count($tags) > 10) {
        pw_error('Use at most 10 tags per news post.');
    }
    return $tags;
}

function pw_news_slug_base($title) {
    $slug = strtolower(trim((string)preg_replace('~[^a-z0-9]+~', '-',
        iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title
    ), '-'));
    return $slug !== '' ? substr($slug, 0, 100) : 'news-update';
}

function pw_news_unique_slug($db, $title, $exceptId = null) {
    $base = pw_news_slug_base($title);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT id FROM news_posts WHERE slug = ?';
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

// Tags are never deleted when a post stops using them. That intentionally
// preserves the editor's suggestion history, while the join table remains the
// single source of truth for which tags belong to a particular post.
function pw_news_sync_tags($db, $newsPostId, $labels) {
    $tagIds = [];
    $find = $db->prepare('SELECT id FROM news_tags WHERE slug = ?');
    $insert = $db->prepare('INSERT INTO news_tags (slug, label) VALUES (?, ?)');
    foreach ($labels as $label) {
        $slug = substr(pw_news_slug_base($label), 0, 80);
        $find->execute([$slug]);
        $row = $find->fetch();
        if ($row) {
            $tagIds[] = (int)$row['id'];
            continue;
        }
        $insert->execute([$slug, $label]);
        $tagIds[] = (int)$db->lastInsertId();
    }

    $db->prepare('DELETE FROM news_post_tags WHERE news_post_id = ?')->execute([$newsPostId]);
    if (!$tagIds) return;
    $attach = $db->prepare('INSERT INTO news_post_tags (news_post_id, news_tag_id) VALUES (?, ?)');
    foreach (array_values(array_unique($tagIds)) as $tagId) {
        $attach->execute([$newsPostId, $tagId]);
    }
}
