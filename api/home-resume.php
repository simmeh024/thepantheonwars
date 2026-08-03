<?php
/**
 * "Jump back in" — what a returning member has waiting for them.
 *
 * The homepage renders identically for a first-time visitor and for someone
 * who has been reading for a year, has three unread topics, a finished
 * operation and an unclaimed research protocol. Every one of those facts is
 * already in the database and none of it reaches the page they actually land
 * on. This is the one request that answers "what was I doing?".
 *
 * Design rules, in order of how easily each could have gone wrong:
 *
 * 1. **Cheap, or absent.** This runs on the homepage, which is the most
 *    requested page on the site and the one with the strictest render budget
 *    (see the LCP notes in CLAUDE.md). Every query below is a bounded COUNT or
 *    a LIMIT 1 against an indexed column. Nothing here calls
 *    api/missions/overview.php or its helpers -- that endpoint settles runs,
 *    grants starter crew and resolves research effects, which is entirely
 *    right for the missions page and far too much work for a strip of links.
 *
 * 2. **Every section is independently guarded.** The forum, missions,
 *    research, reading and messaging tables each arrive with their own
 *    migration. A member whose database predates any of them gets a strip
 *    without that item, never a 500 on the homepage.
 *
 * 3. **Counts, never contents.** Only totals and one currently-reading title
 *    leave this endpoint. Nothing here needs to name a topic, an operation or
 *    a protocol, and a strip that did would have to repeat every visibility
 *    rule those surfaces already enforce for themselves.
 *
 * 4. **Signed out is not an error.** A guest gets ok:true with an empty list,
 *    so js/home-resume.js can treat "nothing to show" the same way whether the
 *    visitor has no session or simply has nothing waiting.
 */
require_once __DIR__ . '/helpers.php';

$user = pw_current_user();
if (!$user) {
    pw_json(['ok' => true, 'signed_in' => false, 'items' => []]);
}

$db = pw_db();
$userId = (int)$user['id'];
$items = [];

/* ---- Continue reading ------------------------------------------------- */
try {
    $stmt = $db->prepare(
        "SELECT b.book_number, b.title
         FROM user_book_progress p
         JOIN books b ON b.id = p.book_id
         WHERE p.user_id = ? AND p.status = 'reading'
         ORDER BY p.updated_at DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    if ($row = $stmt->fetch()) {
        $items[] = [
            'key' => 'reading',
            'label' => 'Continue reading',
            'value' => 'Book ' . (int)$row['book_number'],
            'detail' => $row['title'],
            'href' => 'books.html',
            'count' => null,
        ];
    }
} catch (PDOException $e) {
    // migration_reading_progress.sql not applied.
}

/* ---- Unread forum topics ----------------------------------------------
 * The same shape as api/topics/unread.php, reduced to a count. Board
 * visibility is resolved first and the query is scoped to the slugs this
 * member may actually see, so a restricted board can never inflate the
 * number -- the count would otherwise be a side channel telling them a
 * hidden board exists and is busy. */
try {
    $visibleSlugs = [];
    foreach ($db->query('SELECT * FROM forum_boards')->fetchAll() as $board) {
        if (pw_can_see_board($user, $board)) $visibleSlugs[] = $board['slug'];
    }
    if ($visibleSlugs) {
        $placeholders = implode(',', array_fill(0, count($visibleSlugs), '?'));
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT t.id
                FROM topics t
                JOIN comments c ON c.topic_id = t.id AND c.is_deleted = 0
                LEFT JOIN forum_topic_seen fts ON fts.topic_id = t.id AND fts.user_id = ?
                WHERE t.board IN ($placeholders) AND t.is_deleted = 0
                GROUP BY t.id, fts.seen_at
                HAVING fts.seen_at IS NULL OR MAX(c.created_at) > fts.seen_at
             ) unread"
        );
        $stmt->execute(array_merge([$userId], $visibleSlugs));
        $unread = (int)$stmt->fetchColumn();
        if ($unread > 0) {
            $items[] = [
                'key' => 'forum',
                'label' => 'Unread topics',
                'value' => (string)$unread,
                'detail' => $unread === 1 ? 'topic has new replies' : 'topics have new replies',
                'href' => 'community.html',
                'count' => $unread,
            ];
        }
    }
} catch (PDOException $e) {
    // forum_topic_seen or forum_boards not present.
}

/* ---- Operations waiting to be claimed ----------------------------------
 * Counts a run as ready when its timer has passed, whether or not the
 * five-minute cron has got round to marking it 'completed' yet -- exactly the
 * rule api/missions/claim.php already applies. Reading only 'completed' here
 * would mean a run that finished four minutes ago was invisible on the
 * homepage while being perfectly claimable on the missions page. */
try {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM game_player_missions
         WHERE user_id = ? AND claimed_at IS NULL
           AND (status = 'completed' OR (status = 'active' AND completes_at <= UTC_TIMESTAMP()))"
    );
    $stmt->execute([$userId]);
    $ready = (int)$stmt->fetchColumn();
    if ($ready > 0) {
        $items[] = [
            'key' => 'missions',
            'label' => 'Crew returned',
            'value' => (string)$ready,
            'detail' => $ready === 1 ? 'operation ready to claim' : 'operations ready to claim',
            'href' => 'missions.html',
            'count' => $ready,
        ];
    }
} catch (PDOException $e) {
    // migration_missions_v0.sql not applied.
}

/* ---- Research protocols ready ------------------------------------------
 * Reuses pw_research_unlockable_node_ids(), which is the single source of
 * truth for what counts as unlockable -- rank, credits, salvage,
 * prerequisites, owned set and the sealed final branch. Re-deriving any part
 * of that here is exactly the drift the helper was extracted to prevent, and
 * a homepage that offered a protocol the Research Facility then refused would
 * be worse than one that said nothing. A count only, never which ones: naming
 * them would announce the contents of the sealed branch. */
$researchHelpers = __DIR__ . '/research/research-helpers.php';
if (is_file($researchHelpers)) {
    try {
        require_once $researchHelpers;
        if (function_exists('pw_research_unlockable_node_ids')) {
            $ready = count(pw_research_unlockable_node_ids($db, $userId));
            if ($ready > 0) {
                $items[] = [
                    'key' => 'research',
                    'label' => 'Protocols ready',
                    'value' => (string)$ready,
                    'detail' => $ready === 1 ? 'protocol can be activated' : 'protocols can be activated',
                    'href' => 'research.html',
                    'count' => $ready,
                ];
            }
        }
    } catch (Throwable $e) {
        // The helper already returns empty on a missing migration; this only
        // covers the research tables being absent entirely.
    }
}

/* ---- Unread private messages ------------------------------------------- */
try {
    /* The same statement api/direct-messages/unread-count.php runs. Unread is
       a per-conversation high-water mark on the conversation row, not a
       read_at on each message, so this cannot be simplified into a flag test
       without changing what it means. */
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM direct_messages dm
         JOIN direct_conversations c ON c.id = dm.conversation_id
         WHERE dm.sender_user_id != ?
           AND (c.user_low_id = ? OR c.user_high_id = ?)
           AND dm.id > CASE
               WHEN c.user_low_id = ? THEN c.user_low_last_read_message_id
               ELSE c.user_high_last_read_message_id
           END'
    );
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $unreadMessages = (int)$stmt->fetchColumn();
    if ($unreadMessages > 0) {
        $items[] = [
            'key' => 'messages',
            'label' => 'Private messages',
            'value' => (string)$unreadMessages,
            'detail' => $unreadMessages === 1 ? 'unread message' : 'unread messages',
            'href' => 'messages.html',
            'count' => $unreadMessages,
        ];
    }
} catch (PDOException $e) {
    // migration_direct_messages.sql not applied.
}

pw_json([
    'ok' => true,
    'signed_in' => true,
    'display_name' => $user['display_name'],
    'items' => $items,
]);
