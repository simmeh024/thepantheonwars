<?php
/**
 * Admin listing for Forum Control (Community > Forum Control). Small,
 * fixed-size dataset (a handful of boards) so this is a flat unpaginated
 * list -- same pattern as Book Control. Each row includes its live topic
 * count (for the delete-guard) and its visibility (is_public + the role
 * slugs allowed to see it when hidden), so the edit modal can pre-populate
 * without a second round trip.
 */
require_once __DIR__ . '/../../helpers.php';

pw_require_permission('forum_boards.view');
$db = pw_db();

$boards = $db->query('SELECT * FROM forum_boards ORDER BY sort_order')->fetchAll();

$roleRows = $db->query('SELECT board_id, role_slug FROM forum_board_roles')->fetchAll();
$rolesByBoard = [];
foreach ($roleRows as $r) {
    $rolesByBoard[(int)$r['board_id']][] = $r['role_slug'];
}

$out = array_map(function ($b) use ($db, $rolesByBoard) {
    $countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM topics WHERE board = ? AND is_deleted = 0');
    $countStmt->execute([$b['slug']]);
    $topicCount = (int)$countStmt->fetch()['cnt'];

    return [
        'id' => (int)$b['id'],
        'slug' => $b['slug'],
        'name' => $b['name'],
        'description' => $b['description'],
        'icon_key' => $b['icon_key'],
        'accent_color' => $b['accent_color'],
        'is_protected' => (bool)$b['is_protected'],
        'is_public' => (bool)$b['is_public'],
        'sort_order' => (int)$b['sort_order'],
        'topic_count' => $topicCount,
        'role_slugs' => $rolesByBoard[(int)$b['id']] ?? [],
    ];
}, $boards);

pw_json(['ok' => true, 'entries' => $out, 'total' => count($out)]);
