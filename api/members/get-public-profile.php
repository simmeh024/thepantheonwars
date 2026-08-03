<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../missions/missions-helpers.php';

// Public read (no login required) -- this is the profile members.js links
// to from the nav dropdown, the member list, and forum post author names.
// Deliberately excludes anything private: email, password stuff, "My Posts"
// edit/delete affordances, and the Re-Sync-affinity control all stay on the
// gated profile.html. This only ever returns what a stranger could already
// see elsewhere on the site (member list row + public post history).

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing member id.');
}

$db = pw_db();

$stmt = $db->prepare(
    'SELECT u.id, u.display_name, u.role, u.overlord_affinity, u.created_at, u.last_login_at, u.last_active_at, u.presence_status, u.reputation, u.selected_icon, r.color AS role_color,
       (u.last_active_at IS NOT NULL AND u.last_active_at >= (NOW() - INTERVAL 5 MINUTE)) AS is_online
     FROM users u
     LEFT JOIN roles r ON r.slug = u.role
     WHERE u.id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    pw_error('That member no longer exists.', 404);
}

$crewPower = pw_missions_crew_power_summaries($db, [$id])[$id] ?? pw_missions_crew_power_empty();

// Reading progress is intentionally private except for this one active title.
// Keep public profiles available during the short code-before-migration window
// by treating the optional reading shelf as empty when its table is absent.
$currentlyReading = null;
$lastFinishedBook = null;
$booksFinishedCount = 0;
$booksTotal = 0;
try {
    $readingStmt = $db->prepare(
        "SELECT b.id, b.book_number, b.title, b.cover_image_url
         FROM user_book_progress p
         JOIN books b ON b.id = p.book_id
         WHERE p.user_id = ? AND p.status = 'reading'
         ORDER BY p.updated_at DESC
         LIMIT 1"
    );
    $readingStmt->execute([$id]);
    if ($reading = $readingStmt->fetch()) {
        $currentlyReading = [
            'id' => (int)$reading['id'],
            'book_number' => (int)$reading['book_number'],
            'title' => $reading['title'],
            'cover_image_url' => $reading['cover_image_url'],
        ];
    }

    // Fallback for the reading-status pill when nothing is actively "reading"
    // (most commonly because every book has been finished) -- the most
    // recently finished title, plus the aggregate count needed to tell
    // "finished one book" apart from "finished the whole saga".
    $finishedStmt = $db->prepare(
        "SELECT b.id, b.book_number, b.title, b.cover_image_url
         FROM user_book_progress p
         JOIN books b ON b.id = p.book_id
         WHERE p.user_id = ? AND p.status = 'finished'
         ORDER BY p.finished_at DESC
         LIMIT 1"
    );
    $finishedStmt->execute([$id]);
    if ($finished = $finishedStmt->fetch()) {
        $lastFinishedBook = [
            'id' => (int)$finished['id'],
            'book_number' => (int)$finished['book_number'],
            'title' => $finished['title'],
            'cover_image_url' => $finished['cover_image_url'],
        ];
    }

    $finishedCountStmt = $db->prepare("SELECT COUNT(*) FROM user_book_progress WHERE user_id = ? AND status = 'finished'");
    $finishedCountStmt->execute([$id]);
    $booksFinishedCount = (int)$finishedCountStmt->fetchColumn();
    $booksTotal = (int)$db->query('SELECT COUNT(*) FROM books')->fetchColumn();
} catch (PDOException $e) {
    // migration_reading_progress.sql may be applied after the code deploy.
}

$countStmt = $db->prepare(
    'SELECT
       (SELECT COUNT(*) FROM comments WHERE user_id = ? AND is_deleted = 0) +
       (SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0) AS cnt'
);
$countStmt->execute([$id, $id]);
$postCount = (int)$countStmt->fetch()['cnt'];

// Recent activity feed: topics and comments merged, newest first. Kept small
// (10) since this is a public "recent posts" list, not the full paginated
// history the owner sees on their own profile.
// comments has no board column (only topics does), so it's left out of
// this feed entirely -- the front-end doesn't render it anyway.
$stmt = $db->prepare(
    "(SELECT 'topic' AS kind, id, title AS heading, body, created_at
        FROM topics WHERE user_id = ? AND is_deleted = 0)
     UNION ALL
     (SELECT 'comment' AS kind, id, NULL AS heading, body, created_at
        FROM comments WHERE user_id = ? AND is_deleted = 0)
     ORDER BY created_at DESC
     LIMIT 10"
);
$stmt->execute([$id, $id]);
$recentPosts = $stmt->fetchAll();

/**
 * Operations this member has actually finished and collected.
 *
 * Counted on claimed_at rather than status: a run sitting at 'completed' is
 * one whose timer expired, which the five-minute cron sets whether or not the
 * player ever came back for it. Claiming is the act that finished it.
 *
 * Guarded like the reading shelf above -- the missions tables are optional
 * against an older database, and a public profile must not 500 because a game
 * feature has not been migrated.
 */
$missionsCompleted = 0;
try {
    $missionStmt = $db->prepare('SELECT COUNT(*) FROM game_player_missions WHERE user_id = ? AND claimed_at IS NOT NULL');
    $missionStmt->execute([$id]);
    $missionsCompleted = (int)$missionStmt->fetchColumn();
} catch (PDOException $e) {
    // migration_missions_v0.sql may not have been applied.
}

/**
 * How far up the material this member has climbed, as a count per tier.
 *
 * Deliberately a count and not a list: the full achievement grid is the
 * Reputation page's job, and this is a four-number summary meant to be read at
 * a glance beside the rank. The four tiers are emitted in fixed order and
 * always all four, even at zero -- an omitted tier would read as one that does
 * not exist rather than one still to reach, which is the same rule the
 * Reputation page's own tier row follows.
 *
 * Totals come from the catalogue rather than the database, so a tier whose
 * achievements this member has none of still reports its real denominator.
 */
$tierOrder = ['bronze', 'silver', 'gold', 'prismatic'];
$tierCounts = [];
foreach ($tierOrder as $tier) {
    $tierCounts[$tier] = ['tier' => $tier, 'unlocked' => 0, 'total' => 0];
}
$catalogByKey = [];
foreach (pw_reputation_achievement_catalog() as $achievement) {
    $catalogByKey[$achievement['key']] = $achievement;
    $tier = strtolower((string)$achievement['tier']);
    if (isset($tierCounts[$tier])) $tierCounts[$tier]['total']++;
}
try {
    $unlockedStmt = $db->prepare('SELECT achievement_key FROM user_reputation_achievements WHERE user_id = ?');
    $unlockedStmt->execute([$id]);
    foreach ($unlockedStmt->fetchAll() as $row) {
        $achievement = $catalogByKey[$row['achievement_key']] ?? null;
        if (!$achievement) continue;
        $tier = strtolower((string)$achievement['tier']);
        if (isset($tierCounts[$tier])) $tierCounts[$tier]['unlocked']++;
    }
} catch (PDOException $e) {
    // Achievements stay at zero rather than removing the row entirely: the
    // tiers still exist and are still something to reach.
}

$showcase = [];
try {
    $showcaseStmt = $db->prepare('SELECT achievement_key FROM user_reputation_achievement_showcase WHERE user_id = ? ORDER BY position ASC, id ASC');
    $showcaseStmt->execute([$id]);
    $catalog = [];
    foreach (pw_reputation_achievement_catalog() as $achievement) $catalog[$achievement['key']] = $achievement;
    foreach ($showcaseStmt->fetchAll() as $row) {
        if (!isset($catalog[$row['achievement_key']])) continue;
        $achievement = $catalog[$row['achievement_key']];
        $showcase[] = [
            'key' => $achievement['key'],
            'name' => $achievement['name'],
            'description' => $achievement['description'],
            'tier' => $achievement['tier'],
            'icon' => $achievement['icon'],
        ];
    }
} catch (PDOException $e) {
    // The optional showcase migration may be applied after this code deploy.
}

pw_json([
    'ok' => true,
    'member' => [
        'id' => (int)$user['id'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
        'role_color' => $user['role_color'] ?: '#c7ccd6',
        'overlord_affinity' => $user['overlord_affinity'],
        'created_at' => $user['created_at'],
        'last_login_at' => $user['last_login_at'],
        'last_active_at' => $user['last_active_at'],
        'is_online' => (bool)$user['is_online'],
        'presence_status' => pw_public_presence_status($user['presence_status'], $user['last_active_at']),
        'reputation' => pw_reputation_info((int)$user['reputation']),
        'selected_icon' => $user['selected_icon'],
        'post_count' => $postCount,
        'currently_reading' => $currentlyReading,
        'last_finished_book' => $lastFinishedBook,
        'books_finished_count' => $booksFinishedCount,
        'books_total' => $booksTotal,
        'crew_power' => $crewPower,
        'missions_completed' => $missionsCompleted,
        'achievement_tiers' => array_values($tierCounts),
        'achievement_showcase' => $showcase,
    ],
    'recentPosts' => array_map(function ($r) {
        return [
            'kind' => $r['kind'],
            'id' => (int)$r['id'],
            'heading' => $r['heading'],
            'body' => $r['body'],
            'created_at' => $r['created_at'],
        ];
    }, $recentPosts),
]);
