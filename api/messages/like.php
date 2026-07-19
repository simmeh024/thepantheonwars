<?php
require_once __DIR__ . '/../helpers.php';

// Polymorphic like toggle -- works on both topics and comments.
// Unlike moderation actions, this is open to any logged-in user.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pw_error('Method not allowed.', 405);
}

$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);

$targetType = isset($input['target_type']) ? trim($input['target_type']) : '';
$targetId = isset($input['target_id']) ? (int)$input['target_id'] : 0;

if (!in_array($targetType, ['topic', 'comment'], true)) {
    pw_error('Unknown target type.');
}
if ($targetId <= 0) {
    pw_error('Missing target id.');
}

$db = pw_db();

if ($targetType === 'topic') {
    $stmt = $db->prepare('SELECT id FROM topics WHERE id = ? AND is_deleted = 0');
} else {
    $stmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND is_deleted = 0');
}
$stmt->execute([$targetId]);
if (!$stmt->fetch()) {
    pw_error('That message no longer exists.', 404);
}

try {
    $stmt = $db->prepare('SELECT id, reputation_awarded FROM message_likes WHERE target_type = ? AND target_id = ? AND user_id = ?');
    $stmt->execute([$targetType, $targetId, $user['id']]);
    $existing = $stmt->fetch();
} catch (PDOException $e) {
    // The multiplier migration may be run shortly after the code deploy.
    $stmt = $db->prepare('SELECT id FROM message_likes WHERE target_type = ? AND target_id = ? AND user_id = ?');
    $stmt->execute([$targetType, $targetId, $user['id']]);
    $existing = $stmt->fetch();
}

if ($existing) {
    $stmt = $db->prepare('DELETE FROM message_likes WHERE id = ?');
    $stmt->execute([$existing['id']]);
    $liked = false;

    // Reverse the exact amount granted at like time, not today's multiplier.
    // Older likes predate the stored ledger amount and therefore retain their
    // original standard 2-point reversal.
    $unlikeOwnerId = null;
    if ($targetType === 'topic') {
        $ownerStmt = $db->prepare('SELECT user_id FROM topics WHERE id = ?');
        $ownerStmt->execute([$targetId]);
        $owner = $ownerStmt->fetch();
        $unlikeOwnerId = $owner ? (int)$owner['user_id'] : null;
    } else {
        $ownerStmt = $db->prepare('SELECT user_id FROM comments WHERE id = ?');
        $ownerStmt->execute([$targetId]);
        $owner = $ownerStmt->fetch();
        $unlikeOwnerId = $owner ? (int)$owner['user_id'] : null;
    }
    if ($unlikeOwnerId !== null && $unlikeOwnerId !== (int)$user['id']) {
        try {
            $awardedAtLikeTime = isset($existing['reputation_awarded']) && (int)$existing['reputation_awarded'] > 0
                ? (int)$existing['reputation_awarded'] : 2;
            pw_remove_reputation($db, $unlikeOwnerId, $awardedAtLikeTime, ['reward_key' => 'content_liked_reversed', 'label' => 'Like removed', 'source_type' => $targetType, 'source_id' => $targetId]);
        } catch (PDOException $e) {
            // migration_reputation.sql may be run after code deployment.
        }
    }
} else {
    $stmt = $db->prepare('INSERT INTO message_likes (target_type, target_id, user_id) VALUES (?, ?, ?)');
    $stmt->execute([$targetType, $targetId, $user['id']]);
    $likeId = (int)$db->lastInsertId();
    $liked = true;

    // Notify the post's author (never on unlike, and never for a self-like
    // -- pw_notify_like() no-ops when actor === recipient). Collapsed into
    // one evolving notification per target rather than one row per liker --
    // see pw_notify_like()'s doc comment in helpers.php.
    if ($targetType === 'topic') {
        $ownerStmt = $db->prepare('SELECT user_id FROM topics WHERE id = ?');
        $ownerStmt->execute([$targetId]);
        $owner = $ownerStmt->fetch();
        if ($owner) {
            pw_notify_like((int)$owner['user_id'], $user['id'], $targetId, null);
        }
    } else {
        $ownerStmt = $db->prepare('SELECT user_id, topic_id FROM comments WHERE id = ?');
        $ownerStmt->execute([$targetId]);
        $owner = $ownerStmt->fetch();
        if ($owner) {
            pw_notify_like((int)$owner['user_id'], $user['id'], (int)$owner['topic_id'], $targetId);
        }
    }

    // +2 base reputation to the liked content's author, never for a self-like.
    // Persist the actual (possibly boosted) amount so unlike can reverse it.
    if ($owner && (int)$owner['user_id'] !== (int)$user['id']) {
        try {
            $likeReputationAwarded = pw_award_reputation($db, (int)$owner['user_id'], 2, 'content_liked', ['source_type' => $targetType, 'source_id' => $targetId]);
            try {
                $awardStmt = $db->prepare('UPDATE message_likes SET reputation_awarded = ? WHERE id = ?');
                $awardStmt->execute([$likeReputationAwarded, $likeId]);
            } catch (PDOException $e) {
                // Safe during the code-before-migration window; old likes use
                // the standard two-point fallback on reversal.
            }
        } catch (PDOException $e) {
            // migration_reputation.sql may be run after code deployment.
        }
    }

    // Logged only on the like, never the unlike -- same asymmetry as the
    // notification above. Note this can get high-volume on an active forum;
    // filter by the content_liked action type on the Audit Log page.
    pw_log_admin_activity(
        'content_liked',
        'Liked ' . $targetType . ' #' . $targetId . '.',
        $user
    );
}

$countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM message_likes WHERE target_type = ? AND target_id = ?');
$countStmt->execute([$targetType, $targetId]);
$count = (int)$countStmt->fetch()['cnt'];

pw_json(['ok' => true, 'liked' => $liked, 'likeCount' => $count]);
