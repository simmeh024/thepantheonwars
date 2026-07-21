<?php
/**
 * Shared validation for Forum Control's create/update endpoints.
 */

// Closed icon-key enum -- must match the ICONS lookup in community.html and
// admin/index.html exactly. Not free-text/SVG (see forum_boards.icon_key
// comment in sql/schema.sql for why).
const PW_FORUM_BOARD_ICON_KEYS = ['megaphone', 'scroll', 'globe', 'chat', 'flag', 'book'];

function pw_validate_forum_board_input($input, $requireSlug) {
    $name = isset($input['name']) ? trim($input['name']) : '';
    $description = isset($input['description']) ? trim($input['description']) : '';
    $iconKey = isset($input['icon_key']) ? trim($input['icon_key']) : '';
    $accentColor = isset($input['accent_color']) ? trim((string)$input['accent_color']) : '';
    $categoryId = isset($input['category_id']) ? (int)$input['category_id'] : 0;
    $isPublic = !empty($input['is_public']);
    $roleSlugs = [];
    if (!$isPublic && isset($input['role_slugs']) && is_array($input['role_slugs'])) {
        foreach ($input['role_slugs'] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $roleSlugs[] = $slug;
            }
        }
        $roleSlugs = array_values(array_unique($roleSlugs));
    }

    if ($name === '') {
        pw_error('Give the board a name.');
    }
    if (mb_strlen($name) > 100) {
        pw_error('That name is too long (100 characters max).');
    }
    if (mb_strlen($description) > 255) {
        pw_error('That description is too long (255 characters max).');
    }
    if (!in_array($iconKey, PW_FORUM_BOARD_ICON_KEYS, true)) {
        pw_error('Choose a valid icon.');
    }
    if ($accentColor === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $accentColor)) {
        pw_error('Accent color must be a hex color like #a279ec.');
    }
    if ($categoryId <= 0) {
        pw_error('Choose a category.');
    }
    $categoryStmt = pw_db()->prepare('SELECT id FROM forum_categories WHERE id = ?');
    $categoryStmt->execute([$categoryId]);
    if (!$categoryStmt->fetch()) {
        pw_error('Choose a valid category.');
    }

    $data = [
        'name' => $name,
        'description' => $description,
        'icon_key' => $iconKey,
        'accent_color' => $accentColor,
        'category_id' => $categoryId,
        'is_public' => $isPublic ? 1 : 0,
        'role_slugs' => $roleSlugs,
    ];

    if ($requireSlug) {
        $slug = isset($input['slug']) ? trim($input['slug']) : '';
        if (!preg_match('/^[a-z0-9\-]{1,50}$/', $slug)) {
            pw_error('Slug must be lowercase letters, numbers, and hyphens only.');
        }
        $data['slug'] = $slug;
    }

    return $data;
}

// Replaces a board's role restrictions wholesale -- simplest correct
// approach for a handful of rows, no need for a diff/patch. Validates
// every slug against real rows in `roles` first so a bad slug becomes a
// normal error, not a raw FK-constraint failure surfaced to the client.
function pw_set_forum_board_roles($db, $boardId, $roleSlugs) {
    $db->prepare('DELETE FROM forum_board_roles WHERE board_id = ?')->execute([$boardId]);
    if (empty($roleSlugs)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($roleSlugs), '?'));
    $stmt = $db->prepare("SELECT slug FROM roles WHERE slug IN ($placeholders)");
    $stmt->execute($roleSlugs);
    $validSlugs = array_column($stmt->fetchAll(), 'slug');
    if (empty($validSlugs)) {
        return;
    }
    $insertStmt = $db->prepare('INSERT INTO forum_board_roles (board_id, role_slug) VALUES (?, ?)');
    foreach ($validSlugs as $slug) {
        $insertStmt->execute([$boardId, $slug]);
    }
}
