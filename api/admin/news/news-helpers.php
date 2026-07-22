<?php
// Loaded for pw_dispatch_has_visibility_column(): the public News article
// path (api/news/get.php) reaches the attached-dispatch sidecard through this
// file, so the visibility check has to be available here too.
require_once __DIR__ . '/../../dispatch-helpers.php';
/**
 * Shared validation and slug utilities for News Management.
 *
 * News post bodies are stored as a deliberately small, server-sanitised HTML
 * subset. This lets the editorial UI use real headings, lists, links and
 * library images without turning staff-authored content into a stored-XSS
 * surface. Older plain-text bodies remain supported by the public renderer.
 */

function pw_news_input($input) {
    $title = isset($input['title']) ? trim((string)$input['title']) : '';
    $rawBody = isset($input['body']) ? trim((string)$input['body']) : '';
    $authorType = isset($input['author_type']) ? trim((string)$input['author_type']) : 'bh4';
    $rawHeaderImage = isset($input['header_image_url']) ? trim((string)$input['header_image_url']) : '';

    if ($title === '') {
        pw_error('Give the news post a title.');
    }
    if (mb_strlen($title) > 200) {
        pw_error('The title is too long (200 characters max).');
    }
    if ($rawBody === '') {
        pw_error('Write the public update before publishing it.');
    }
    if (mb_strlen($rawBody) > 15000) {
        pw_error('The update is too long (15,000 characters max).');
    }
    if (!in_array($authorType, ['bh4', 'member'], true)) {
        pw_error('Choose BH-4 or yourself as the author.');
    }

    $body = pw_news_sanitize_body($rawBody);
    if (trim(strip_tags($body)) === '' && stripos($body, '<img') === false) {
        pw_error('Write the public update before publishing it.');
    }

    // Optional. Reuses the same News library allowlist as inline body images
    // (pw_news_safe_image_url), so a header image can only ever point at a
    // re-encoded upload-image.php file, never an arbitrary external URL.
    $headerImageUrl = null;
    if ($rawHeaderImage !== '') {
        $headerImageUrl = pw_news_safe_image_url($rawHeaderImage);
        if ($headerImageUrl === null) {
            pw_error('The header image must be chosen from the News image library.');
        }
    }

    return [
        'title' => $title,
        'body' => $body,
        'header_image_url' => $headerImageUrl,
        'author_type' => $authorType,
        'comments_enabled' => !array_key_exists('comments_enabled', $input) || !empty($input['comments_enabled']),
        'tags' => pw_news_normalize_tags(isset($input['tags']) ? $input['tags'] : []),
    ];
}

function pw_news_is_rich_body($body) {
    return preg_match('~<(?:p|h2|h3|ul|ol|li|blockquote|figure|img)\b~i', (string)$body) === 1;
}

function pw_news_public_body($body) {
    $body = (string)$body;
    // Older records predate the editor and were rendered as text. Re-sanitise
    // anything that looks like rich markup on read as defence in depth, so a
    // legacy record can never become trusted merely because rendering changed.
    return pw_news_is_rich_body($body) ? pw_news_sanitize_body($body) : $body;
}

/**
 * Keep the editorial vocabulary intentionally compact. Attributes are rebuilt
 * instead of merely filtered, so event handlers, styles, embeds and data URLs
 * never reach a public response. Images may only point at the re-encoded News
 * library produced by upload-image.php.
 */
function pw_news_sanitize_body($rawBody) {
    $rawBody = trim((string)$rawBody);
    if (!class_exists('DOMDocument')) {
        // Shared hosting normally has DOM enabled. This safe fallback still
        // publishes readable paragraphs rather than ever trusting raw markup.
        return '<p>' . nl2br(htmlspecialchars($rawBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
    }

    $previousErrors = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $loaded = $dom->loadHTML(
        '<?xml encoding="utf-8" ?><div id="pw-news-root">' . $rawBody . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    if (!$loaded) {
        pw_error('The editor content could not be processed.');
    }

    $root = $dom->getElementById('pw-news-root');
    if (!$root) {
        pw_error('The editor content could not be processed.');
    }
    pw_news_sanitize_children($root, $dom);

    $html = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $html .= $dom->saveHTML($child);
    }
    return trim($html);
}

function pw_news_sanitize_children($parent, $dom) {
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'figure', 'img', 'figcaption'];
    $dropCompletely = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'video', 'audio', 'form', 'input', 'button'];

    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            continue;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            $parent->removeChild($node);
            continue;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, $dropCompletely, true)) {
            $parent->removeChild($node);
            continue;
        }
        if ($tag === 'div') {
            // contenteditable commonly emits divs on Enter; normalise those
            // into editorial paragraphs so the public style stays predictable.
            $paragraph = $dom->createElement('p');
            while ($node->firstChild) {
                $paragraph->appendChild($node->firstChild);
            }
            $parent->replaceChild($paragraph, $node);
            $node = $paragraph;
            $tag = 'p';
        } elseif (!in_array($tag, $allowed, true)) {
            // Unknown structural wrappers do not gain styling or attributes,
            // but their readable child content is retained.
            pw_news_sanitize_children($node, $dom);
            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
            continue;
        }

        $rawHref = $node->getAttribute('href');
        $rawSrc = $node->getAttribute('src');
        $rawAlt = $node->getAttribute('alt');
        $rawWidth = $node->getAttribute('width');
        $rawHeight = $node->getAttribute('height');
        $attributes = [];
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $attributes[] = $attribute->name;
        }
        foreach ($attributes as $attributeName) {
            $node->removeAttribute($attributeName);
        }

        if ($tag === 'a') {
            $href = pw_news_safe_link($rawHref);
            if ($href !== null) {
                $node->setAttribute('href', $href);
                $node->setAttribute('rel', 'noopener noreferrer');
            } else {
                pw_news_sanitize_children($node, $dom);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }
        }
        if ($tag === 'img') {
            $src = pw_news_safe_image_url($rawSrc);
            if ($src === null) {
                $parent->removeChild($node);
                continue;
            }
            $node->setAttribute('src', $src);
            $node->setAttribute('alt', mb_substr(trim((string)$rawAlt), 0, 240));
            $width = filter_var($rawWidth, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1600]]);
            $height = filter_var($rawHeight, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1600]]);
            if ($width !== false && $height !== false) {
                $node->setAttribute('width', (string)$width);
                $node->setAttribute('height', (string)$height);
            }
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('decoding', 'async');
        }

        pw_news_sanitize_children($node, $dom);
    }
}

function pw_news_safe_link($href) {
    $href = trim(html_entity_decode((string)$href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '') return null;
    if (preg_match('~^/(?!/)~', $href)) return $href;
    if (preg_match('~^(?:https?://|mailto:)~i', $href)) return $href;
    return null;
}

function pw_news_safe_image_url($src) {
    $src = trim(html_entity_decode((string)$src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return preg_match('~^/uploads/news-images/img_[a-f0-9]{16}\.jpg$~', $src) ? $src : null;
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

// Public-safe list of the Dispatches a Composer draft attached as source
// material, ordered the same way the editor's "Attached" panel shows them.
// Deliberately excludes admin_note (private, never published) and every
// other Composer-only field -- only what the public sidecard needs.
function pw_composer_attached_dispatches($db, $composerPostId) {
    // This feeds the public "Related Development" sidecard on a News article,
    // so a dispatch hidden after that article was published must drop out of
    // it too -- otherwise hiding would still leak the subject line, and the
    // card's links would point at a dispatch the feed no longer serves.
    $hiddenFilter = (function_exists('pw_dispatch_has_visibility_column') && pw_dispatch_has_visibility_column($db))
        ? ' AND d.is_hidden = 0'
        : '';
    $stmt = $db->prepare(
        'SELECT d.id, d.sha, d.subject, d.tag, d.committed_at
         FROM dispatch_composer_items ci
         JOIN dispatch_entries d ON d.id = ci.dispatch_id
         WHERE ci.composer_post_id = ?' . $hiddenFilter . '
         ORDER BY ci.sort_order ASC, ci.id ASC'
    );
    $stmt->execute([$composerPostId]);
    return array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'subject' => $r['subject'],
            'tag' => $r['tag'],
            'short_sha' => substr($r['sha'], 0, 7),
            'committed_at' => $r['committed_at'],
        ];
    }, $stmt->fetchAll());
}

// The single insertion path for a real news_posts row. Both News
// Management's own create.php and Dispatch Composer's publish.php call this
// -- neither duplicates the INSERT/tag-sync logic. Caller owns the
// transaction (create.php wraps just this; Composer's publish.php wraps
// this plus its own row lock and status update in one larger transaction).
function pw_news_create_post($db, $slug, $data, $authorUserId) {
    $stmt = $db->prepare(
        'INSERT INTO news_posts (slug, title, body, header_image_url, author_type, author_user_id, comments_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$slug, $data['title'], $data['body'], $data['header_image_url'], $data['author_type'], $authorUserId, $data['comments_enabled'] ? 1 : 0]);
    $id = (int)$db->lastInsertId();
    pw_news_sync_tags($db, $id, isset($data['tags']) ? $data['tags'] : []);
    return $id;
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
