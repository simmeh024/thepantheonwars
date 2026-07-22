<?php
/**
 * Public read for the Lore Timeline. Powers timeline.html (js/timeline.js).
 *
 * No authentication is required, but the session IS consulted: an event may be
 * gated behind a reputation level, and whether it is unlocked depends on who is
 * asking. A signed-out visitor sees exactly what a zero-reputation member sees.
 *
 * THE UNLOCK CHECK HERE IS THE SECURITY BOUNDARY. A locked event's title,
 * summary, body and image are never placed in the response at all -- only its
 * position on the bar and the name of the level needed to reach it. Hiding that
 * content in CSS or JS instead would leak every sealed piece of lore to anyone
 * who opened DevTools, which is the same reason a locked world's record stays
 * sealed server-side rather than being dimmed in the atlas.
 */
require_once __DIR__ . '/helpers.php';

$db = pw_db();

$currentUser = pw_current_user();
$reputation = $currentUser ? (int)($currentUser['reputation'] ?? 0) : 0;

try {
    $stmt = $db->query(
        'SELECT t.id, t.slug, t.title, t.era_label, t.date_label, t.summary, t.body,
                t.image_url, t.accent_color, t.sort_order,
                t.required_level_id, r.name AS required_level_name,
                r.threshold AS required_level_threshold, r.color AS required_level_color
         FROM timeline_events t
         LEFT JOIN reputation_levels r ON r.id = t.required_level_id
         WHERE t.is_published = 1
         ORDER BY t.sort_order ASC, t.id ASC'
    );
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    // The page renders an empty-state rather than an error if the migration
    // has not been run yet, matching how other optional lore surfaces degrade.
    pw_json(['ok' => true, 'events' => [], 'unlocked_count' => 0, 'total_count' => 0]);
}

$unlockedCount = 0;
$events = [];
foreach ($rows as $r) {
    // A gate with no surviving level row (deleted level -> SET NULL) is open.
    $threshold = $r['required_level_id'] !== null && $r['required_level_threshold'] !== null
        ? (int)$r['required_level_threshold']
        : null;
    $unlocked = $threshold === null || $reputation >= $threshold;

    if ($unlocked) {
        $unlockedCount++;
        $events[] = [
            'id' => (int)$r['id'],
            'slug' => $r['slug'],
            'sort_order' => (int)$r['sort_order'],
            'locked' => false,
            'title' => $r['title'],
            'era_label' => $r['era_label'],
            'date_label' => $r['date_label'],
            'summary' => $r['summary'],
            'body' => $r['body'],
            'image_url' => $r['image_url'],
            'accent_color' => $r['accent_color'],
        ];
        continue;
    }

    // Sealed: position and the requirement only. Deliberately no slug either --
    // a slug is a readable name and would give the lore away by itself.
    $events[] = [
        'id' => (int)$r['id'],
        'sort_order' => (int)$r['sort_order'],
        'locked' => true,
        'required_level_name' => $r['required_level_name'],
        'required_level_threshold' => $threshold,
        'required_level_color' => $r['required_level_color'],
    ];
}

pw_json([
    'ok' => true,
    'events' => $events,
    'unlocked_count' => $unlockedCount,
    'total_count' => count($events),
    'reputation' => $reputation,
]);
