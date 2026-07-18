<?php
require_once __DIR__ . '/../helpers.php';

// Rendering note: title/body/display name below are RAW text, not HTML-escaped.
// The front-end must render them with textContent (never innerHTML) to stay XSS-safe.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pw_error('Missing topic id.');
}

$db = pw_db();
$stmt = $db->prepare(
    'SELECT t.id, t.board, t.title, t.body, t.created_at, t.is_pinned, t.is_locked,
            t.edited_at, t.edited_by, editor.display_name AS edited_by_name,
            t.user_id, u.display_name, u.role, u.last_active_at, u.presence_status, u.reputation, r.color AS role_color
     FROM topics t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN roles r ON r.slug = u.role
     LEFT JOIN users editor ON editor.id = t.edited_by
     WHERE t.id = ? AND t.is_deleted = 0'
);
$stmt->execute([$id]);
$topic = $stmt->fetch();

if (!$topic) {
    pw_error('That topic no longer exists.', 404);
}

$boardRow = pw_forum_board_by_slug($topic['board']);
if (!$boardRow || !pw_can_see_board(pw_current_user(), $boardRow)) {
    // Same error as a nonexistent topic -- a direct link into a hidden
    // board's topic must not leak that the topic (or board) exists.
    pw_error('That topic no longer exists.', 404);
}

$postCountStmt = $db->prepare(
    'SELECT
       (SELECT COUNT(*) FROM comments WHERE user_id = ? AND is_deleted = 0) +
       (SELECT COUNT(*) FROM topics WHERE user_id = ? AND is_deleted = 0) AS cnt'
);
$postCountStmt->execute([(int)$topic['user_id'], (int)$topic['user_id']]);
$postCountRow = $postCountStmt->fetch();

$likeCountStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM message_likes WHERE target_type = 'topic' AND target_id = ?");
$likeCountStmt->execute([$id]);
$likeCount = (int)$likeCountStmt->fetch()['cnt'];

$currentUser = pw_current_user();
$currentId = $currentUser ? (int)$currentUser['id'] : null;
$canModerate = $currentUser ? pw_has_permission($currentUser, 'community.edit_any') : false;
$canDeleteAny = $currentUser ? pw_has_permission($currentUser, 'community.delete_any') : false;

$likedByMe = false;
$bookmarked = false;
$watched = false;
if ($currentId !== null) {
    $myLikeStmt = $db->prepare("SELECT id FROM message_likes WHERE target_type = 'topic' AND target_id = ? AND user_id = ?");
    $myLikeStmt->execute([$id, $currentId]);
    $likedByMe = (bool)$myLikeStmt->fetch();

    $myBookmarkStmt = $db->prepare('SELECT id FROM topic_bookmarks WHERE topic_id = ? AND user_id = ?');
    $myBookmarkStmt->execute([$id, $currentId]);
    $bookmarked = (bool)$myBookmarkStmt->fetch();

    $myWatchStmt = $db->prepare('SELECT id FROM topic_subscriptions WHERE topic_id = ? AND user_id = ?');
    $myWatchStmt->execute([$id, $currentId]);
    $watched = (bool)$myWatchStmt->fetch();
}

$canEditOwn = $currentId !== null
    && $currentId === (int)$topic['user_id']
    && (time() - strtotime($topic['created_at'])) <= 30 * 60;

// A topic may have at most one poll (topic_polls.topic_id is UNIQUE).
// Options and their live vote counts are read in the same request so the
// client never has to make a second round trip just to render results.
$poll = null;
try {
    $pollStmt = $db->prepare('SELECT id, question FROM topic_polls WHERE topic_id = ?');
    $pollStmt->execute([$id]);
    $pollRow = $pollStmt->fetch();
    if ($pollRow) {
        $pollId = (int)$pollRow['id'];
        $optionsStmt = $db->prepare(
            'SELECT o.id, o.label, (SELECT COUNT(*) FROM topic_poll_votes v WHERE v.option_id = o.id) AS vote_count
             FROM topic_poll_options o WHERE o.poll_id = ? ORDER BY o.sort_order ASC, o.id ASC'
        );
        $optionsStmt->execute([$pollId]);
        $options = $optionsStmt->fetchAll();
        $totalVotes = array_sum(array_map(function ($o) { return (int)$o['vote_count']; }, $options));
        $myVote = null;
        if ($currentId !== null) {
            $myVoteStmt = $db->prepare('SELECT option_id FROM topic_poll_votes WHERE poll_id = ? AND user_id = ?');
            $myVoteStmt->execute([$pollId, $currentId]);
            $myVoteRow = $myVoteStmt->fetch();
            $myVote = $myVoteRow ? (int)$myVoteRow['option_id'] : null;
        }
        $poll = [
            'id' => $pollId,
            'question' => $pollRow['question'],
            'options' => array_map(function ($o) {
                return ['id' => (int)$o['id'], 'label' => $o['label'], 'vote_count' => (int)$o['vote_count']];
            }, $options),
            'total_votes' => $totalVotes,
            'my_vote' => $myVote,
        ];
    }
} catch (PDOException $e) {
    // migration_forum_features_batch.sql may be run after code deployment.
}

pw_json([
    'ok' => true,
    'topic' => [
        'id' => (int)$topic['id'],
        'board' => $topic['board'],
        'title' => $topic['title'],
        'body' => $topic['body'],
        'created_at' => $topic['created_at'],
        'is_pinned' => (bool)$topic['is_pinned'],
        'is_locked' => (bool)$topic['is_locked'],
        'edited_at' => $topic['edited_at'],
        'edited_by_name' => $topic['edited_by_name'],
        'user_id' => (int)$topic['user_id'],
        'display_name' => $topic['display_name'],
        'role' => $topic['role'],
        'role_color' => $topic['role_color'] ?: '#c7ccd6',
        'presence_status' => pw_public_presence_status($topic['presence_status'], $topic['last_active_at']),
        'reputation' => pw_reputation_info((int)$topic['reputation']),
        'post_count' => (int)$postCountRow['cnt'],
        'canDelete' => $canDeleteAny || ($currentId !== null && $currentId === (int)$topic['user_id']),
        'canModerate' => $canModerate,
        'canEditOwn' => $canEditOwn,
        'like_count' => $likeCount,
        'likedByMe' => $likedByMe,
        'bookmarked' => $bookmarked,
        'watched' => $watched,
        'poll' => $poll,
    ],
]);
