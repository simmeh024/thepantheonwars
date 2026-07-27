<?php
/**
 * Withdraw with the haul, or abandon it.
 *
 * Nothing found during a sweep exists until this runs: pick.php records what a
 * cell held, and only here does it become inventory, credits and XP. That is
 * the whole bargain -- one more scan is one more chance to lose the lot.
 */
require_once __DIR__ . '/../sweep-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_sweep_require_ready($db);
$userId = (int)$user['id'];
$abandon = !empty($input['abandon']);

$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT * FROM game_player_sweep_runs WHERE user_id = ? AND status = "active" FOR UPDATE');
    $stmt->execute([$userId]);
    $run = $stmt->fetch();
    if (!$run) throw new RuntimeException('No sweep is under way.');
    $now = pw_missions_utc_now($db);

    if ($abandon) {
        $db->prepare('UPDATE game_player_sweep_runs SET status = "abandoned", ended_reason = "abandoned", ended_at = ? WHERE id = ? AND status = "active"')
            ->execute([pw_missions_datetime($now), (int)$run['id']]);
        pw_sweep_release_crew($db, $userId, (int)$run['player_crew_id'], $now);
        $db->commit();
        pw_json(['ok' => true, 'abandoned' => true]);
    }

    $finds = $db->prepare('SELECT * FROM game_player_sweep_finds WHERE run_id = ? ORDER BY cell_index ASC');
    $finds->execute([(int)$run['id']]);
    $rows = $finds->fetchAll();

    /* Gear goes through pw_missions_store_loot() rather than a direct insert,
     * so the quartermaster ceilings, the salvage/equipment split and the
     * "stored what fits and reported the rest" rule all apply exactly as they
     * do on a mission claim. */
    $gear = [];
    $recruited = [];
    $duplicates = [];
    $pending = [];
    $lootIds = [];
    foreach ($rows as $row) {
        if ($row['loot_definition_id'] !== null) $lootIds[(int)$row['loot_definition_id']] = true;
    }
    if ($lootIds) {
        $ids = array_keys($lootIds);
        $q = $db->prepare('SELECT * FROM game_loot_definitions WHERE id IN (' . pw_missions_placeholders(count($ids)) . ')');
        $q->execute($ids);
        $byId = [];
        foreach ($q->fetchAll() as $definition) $byId[(int)$definition['id']] = $definition;
        foreach ($rows as $row) {
            if ($row['loot_definition_id'] === null) continue;
            $definition = $byId[(int)$row['loot_definition_id']] ?? null;
            if (!$definition) continue;
            $gear[] = [
                'id' => (int)$definition['id'],
                'name' => (string)$definition['name'],
                'tier' => (string)$definition['tier'],
                'upgraded' => false,
                'slot' => (string)($definition['slot'] ?? ''),
                'icon_url' => pw_missions_gear_icon_url($definition['icon_url'] ?? ''),
            ];
        }
    }

    foreach ($rows as $row) {
        if ($row['crew_definition_id'] === null) continue;
        $received = pw_missions_receive_crew($db, $userId, (int)$row['crew_definition_id'], 'sweep', (int)$run['id']);
        if ($received['state'] === 'granted') $recruited[] = $received['crew'];
        elseif ($received['state'] === 'pending') $pending[] = $received['crew'];
        else {
            // Same rule as a mission: crew is a roster, not a stack, so a
            // second copy pays its rarity in credits instead.
            $award = $received['crew'];
            $award['duplicate_credits'] = pw_missions_crew_duplicate_credits($award['tier'] ?? 'common');
            $duplicates[] = $award;
        }
    }

    $research = pw_research_player_effects($db, $userId);
    $skipped = $gear ? (pw_missions_store_loot($db, $userId, $gear, $research)['skipped'] ?? []) : [];

    $credits = (int)$run['credits_found'];
    foreach ($duplicates as $duplicate) $credits += (int)($duplicate['duplicate_credits'] ?? 0);
    $balance = pw_missions_credit_balance($db, $userId);
    if ($credits > 0) $balance = pw_missions_add_credits($db, $userId, $credits);

    /* XP goes to the one crew member who ran the board, which is what makes
     * choosing who to send a progression decision and not only a stat check. */
    $xp = max(0, (int)$run['xp_reward']);
    if ($xp > 0) {
        $db->prepare('UPDATE game_player_crew SET xp = xp + ? WHERE id = ? AND user_id = ?')
            ->execute([$xp, (int)$run['player_crew_id'], $userId]);
    }

    $update = $db->prepare('UPDATE game_player_sweep_runs SET status = "banked", ended_reason = "banked", ended_at = ? WHERE id = ? AND status = "active"');
    $update->execute([pw_missions_datetime($now), (int)$run['id']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('That sweep was already closed.');
    pw_sweep_release_crew($db, $userId, (int)$run['player_crew_id'], $now);

    $db->commit();
    pw_json([
        'ok' => true,
        'banked' => true,
        'gear' => $gear,
        'skipped' => $skipped,
        'crew_recruited' => $recruited,
        'crew_duplicates' => $duplicates,
        'crew_pending' => $pending,
        'credits' => $credits,
        'credit_balance' => $balance,
        'xp' => $xp,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'The haul could not be banked.', 400);
}
