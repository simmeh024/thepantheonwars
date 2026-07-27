<?php
/**
 * Consume one stim.
 *
 * A fatigue stim is applied to a named crew member immediately; the two timed
 * boosts open an account-wide window that every operation launched inside it
 * reads through the ordinary effects array.
 *
 * The refusals here are deliberately protective rather than permissive. Using a
 * fatigue stim on a rested crew member, or a second boost of a type already
 * running, would consume a finite item for nothing -- and the player cannot get
 * it back. Where the outcome would be zero, the request is rejected and the
 * item is left in the inventory.
 */
require_once __DIR__ . '/missions-helpers.php';
require_once __DIR__ . '/../research/research-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$itemId = filter_var($input['loot_definition_id'] ?? null, FILTER_VALIDATE_INT);
if ($itemId === false || $itemId < 1) pw_error('Choose a valid stim.');
$crewId = filter_var($input['crew_id'] ?? null, FILTER_VALIDATE_INT);
$crewId = ($crewId === false || $crewId < 1) ? null : $crewId;

$db = pw_db();
pw_missions_require_ready($db);
if (!pw_mission_stims_ready($db)) pw_error('Stims are not available yet.', 409);
$userId = (int)$user['id'];

try {
    $db->beginTransaction();

    /* Locked for the same reason destroy locks it: two tabs must not be able to
     * spend the same last copy. */
    $heldStmt = $db->prepare(
        'SELECT pl.quantity, l.name, l.slot, l.stim_effect, l.stim_value, l.stim_duration_seconds, l.is_enabled
         FROM game_player_loot pl
         JOIN game_loot_definitions l ON l.id = pl.loot_definition_id
         WHERE pl.user_id = ? AND pl.loot_definition_id = ?
         FOR UPDATE'
    );
    $heldStmt->execute([$userId, $itemId]);
    $held = $heldStmt->fetch();
    if (!$held || (int)$held['quantity'] < 1) throw new RuntimeException('That stim is no longer in your inventory.');
    if (!(int)$held['is_enabled']) throw new RuntimeException('That stim has been withdrawn from service.');

    $effect = (string)$held['stim_effect'];
    $types = pw_missions_stim_effect_types();
    /* Re-derived from the columns rather than trusted from the request: an
     * ordinary item and a stim differ only by these fields, and the browser
     * must not be able to decide which one it sent. */
    if (pw_missions_inventory_category($held) !== 'stim' || !isset($types[$effect])) {
        throw new RuntimeException('Only a stim can be used.');
    }
    $value = max(0.0, (float)$held['stim_value']);
    if ($value <= 0) throw new RuntimeException('That stim has no effect configured.');

    $now = pw_missions_utc_now($db);
    $researchEffects = pw_research_ready($db) ? pw_research_player_effects($db, $userId) : pw_research_default_effects();
    $message = '';
    $appliedTo = null;
    $expiresAt = null;

    if ($effect === 'fatigue') {
        if ($crewId === null) throw new RuntimeException('Choose which crew member should take this stim.');
        if (!pw_mission_fatigue_ready($db)) throw new RuntimeException('Crew fatigue is not available yet.');
        $crewStmt = $db->prepare(
            'SELECT pc.id, pc.status, pc.fatigue, pc.fatigue_updated_at, c.name
             FROM game_player_crew pc
             JOIN game_crew_definitions c ON c.id = pc.crew_definition_id
             WHERE pc.id = ? AND pc.user_id = ?
             FOR UPDATE'
        );
        $crewStmt->execute([$crewId, $userId]);
        $member = $crewStmt->fetch();
        if (!$member) throw new RuntimeException('That crew member is not on your roster.');
        /* Deployed crew are excluded because rest does not accrue while they
         * are in the field -- see pw_missions_resolve_fatigue(). Topping up a
         * pool that is about to be restamped on their return would be spent for
         * nothing. */
        if ((string)$member['status'] !== 'available') {
            throw new RuntimeException($member['name'] . ' is on an operation. Stims can only be given to crew standing by.');
        }
        $max = pw_missions_fatigue_max($db, $userId, $researchEffects);
        $current = pw_missions_resolve_fatigue($member, $max, $now, (float)($researchEffects['fatigue_recovery_percent'] ?? 0));
        if ($current >= $max) throw new RuntimeException($member['name'] . ' is already fully rested.');
        $restored = min((int)round($value), $max - $current);
        /* fatigue_updated_at is restamped so the rest accrued between the last
         * write and now is banked rather than counted twice on the next read. */
        $apply = $db->prepare('UPDATE game_player_crew SET fatigue = ?, fatigue_updated_at = ? WHERE id = ? AND user_id = ?');
        $apply->execute([$current + $restored, pw_missions_datetime($now), $crewId, $userId]);
        if ($apply->rowCount() !== 1) throw new RuntimeException('That stim could not be applied.');
        $appliedTo = ['id' => $crewId, 'name' => (string)$member['name'], 'fatigue' => $current + $restored, 'fatigue_max' => $max];
        $message = $member['name'] . ' recovered ' . $restored . ' fatigue.';
    } else {
        $duration = max(0, (int)$held['stim_duration_seconds']);
        if ($duration < 1) throw new RuntimeException('That stim has no duration configured.');
        /* Expired rows are cleared here rather than by a scheduled job: this is
         * the only path that creates them, reads already filter on expiry, and
         * the table would otherwise grow without ever being touched again. */
        $prune = $db->prepare('DELETE FROM game_player_stim_effects WHERE user_id = ? AND expires_at <= UTC_TIMESTAMP()');
        $prune->execute([$userId]);
        $runningStmt = $db->prepare(
            'SELECT expires_at FROM game_player_stim_effects
             WHERE user_id = ? AND effect_type = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $runningStmt->execute([$userId, $effect]);
        if ($runningStmt->fetch()) {
            throw new RuntimeException('A ' . strtolower($types[$effect]['label']) . ' is already running. Wait for it to finish first.');
        }
        $expiry = $now->modify('+' . $duration . ' seconds');
        $insert = $db->prepare(
            'INSERT INTO game_player_stim_effects (user_id, loot_definition_id, effect_type, effect_value, started_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$userId, $itemId, $effect, $value, pw_missions_datetime($now), pw_missions_datetime($expiry)]);
        $expiresAt = pw_missions_datetime($expiry);
        $minutes = max(1, (int)round($duration / 60));
        $message = $types[$effect]['label'] . ' active for ' . $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes') . '.';
    }

    $remaining = (int)$held['quantity'] - 1;
    if ($remaining > 0) {
        $spend = $db->prepare('UPDATE game_player_loot SET quantity = quantity - 1 WHERE user_id = ? AND loot_definition_id = ? AND quantity > 0');
        $spend->execute([$userId, $itemId]);
        if ($spend->rowCount() !== 1) throw new RuntimeException('That stim could not be used.');
    } else {
        $spend = $db->prepare('DELETE FROM game_player_loot WHERE user_id = ? AND loot_definition_id = ? AND quantity = 1');
        $spend->execute([$userId, $itemId]);
        if ($spend->rowCount() !== 1) throw new RuntimeException('That stim could not be used.');
    }

    $db->commit();
    pw_json([
        'ok' => true,
        'loot_definition_id' => $itemId,
        'effect_type' => $effect,
        'remaining_quantity' => $remaining,
        'expires_at' => $expiresAt,
        'crew' => $appliedTo,
        'message' => $message,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not use that stim. Please try again.', 409);
}
