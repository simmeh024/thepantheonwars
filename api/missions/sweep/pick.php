<?php
/** Turn over one cell. Everything about what is under it is decided here. */
require_once __DIR__ . '/../sweep-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$user = pw_require_login();
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_sweep_require_ready($db);
$userId = (int)$user['id'];
$cell = filter_var($input['cell'] ?? null, FILTER_VALIDATE_INT);
if ($cell === false || $cell < 0) pw_error('Choose a cell on the board.');

$db->beginTransaction();
try {
    /* Locked for the duration: two picks racing would each read picks_used
     * before the other wrote it, and the player would get a free reveal. */
    $stmt = $db->prepare('SELECT * FROM game_player_sweep_runs WHERE user_id = ? AND status = "active" FOR UPDATE');
    $stmt->execute([$userId]);
    $run = $stmt->fetch();
    if (!$run) throw new RuntimeException('No sweep is under way.');

    $rows = (int)$run['grid_rows'];
    $cols = (int)$run['grid_cols'];
    $cells = $rows * $cols;
    if ($cell >= $cells) throw new RuntimeException('That cell is not on this board.');
    $revealed = pw_sweep_revealed($run);
    if (isset($revealed[$cell])) throw new RuntimeException('That cell is already open.');
    if ((int)$run['picks_used'] >= (int)$run['picks_total']) throw new RuntimeException('No scans remain. Bank the haul.');

    $outcome = pw_sweep_resolve_cell($db, $run, $cell);
    $revealed[$cell] = true;
    $picksUsed = (int)$run['picks_used'] + 1;
    $creditsFound = (int)$run['credits_found'];
    $shrugUsed = (bool)$run['shrug_used'];
    $ended = '';
    $shrugged = false;
    $find = ['index' => $cell, 'type' => $outcome['type'], 'credits' => 0, 'label' => '', 'hint' => null];

    if ($outcome['type'] === 'hazard') {
        /* Strength buys exactly one escape, and only one: a second roll on the
         * same run would make a high-Strength board effectively hazard-free. */
        if (!$shrugUsed && (float)$run['shrug_percent'] > 0
            && random_int(1, 10000) <= (int)round((float)$run['shrug_percent'] * 100)) {
            $shrugUsed = true;
            $shrugged = true;
            $find['type'] = 'shrug';
        } else {
            $ended = 'collapse';
        }
    } elseif ($outcome['type'] === 'cache') {
        $creditsFound += (int)$outcome['credits'];
        $find['credits'] = (int)$outcome['credits'];
        $find['label'] = number_format((int)$outcome['credits']) . ' credits';
        $store = $db->prepare('INSERT INTO game_player_sweep_finds (run_id, cell_index, find_type, credits) VALUES (?, ?, "cache", ?)');
        $store->execute([(int)$run['id'], $cell, (int)$outcome['credits']]);
    } elseif ($outcome['type'] === 'find') {
        $entry = $outcome['entry'];
        $isGear = ($entry['entry_type'] ?? 'crew') === 'gear';
        /* Recorded, not granted. Nothing enters the player's inventory until
         * the run is banked -- that is the whole push-your-luck bargain, and
         * granting on reveal would make withdrawing meaningless. */
        $store = $db->prepare(
            'INSERT INTO game_player_sweep_finds (run_id, cell_index, find_type, loot_definition_id, crew_definition_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $store->execute([
            (int)$run['id'], $cell, $isGear ? 'gear' : 'crew',
            $isGear ? (int)$entry['loot_definition_id'] : null,
            $isGear ? null : (int)$entry['crew_definition_id'],
        ]);
        $find['label'] = (string)($isGear ? ($entry['gear_name'] ?? 'Recovered item') : ($entry['crew_name'] ?? 'Recovered contact'));
        $find['type'] = $isGear ? 'gear' : 'crew';
    }

    if ($ended === '' && $picksUsed >= (int)$run['picks_total']) $ended = 'spent';

    if ((int)$run['hint_radius'] > 0 && $find['type'] !== 'hazard') {
        $hazards = pw_sweep_hazard_cells((int)$run['grid_seed'], $cells, (int)$run['hazard_count']);
        $find['hint'] = pw_sweep_adjacent_hazards($cell, $rows, $cols, $hazards, (int)$run['hint_radius']);
    }

    $update = $db->prepare(
        'UPDATE game_player_sweep_runs
         SET revealed_cells = ?, picks_used = ?, credits_found = ?, shrug_used = ?,
             status = ?, ended_reason = ?, ended_at = ?
         WHERE id = ? AND status = "active"'
    );
    $now = pw_missions_utc_now($db);
    /* A collapse ends the run and pays nothing; running out of scans ends it
     * as "spent", which is still bankable. Both leave status active only when
     * neither happened. */
    $status = $ended === 'collapse' ? 'lost' : 'active';
    $update->execute([
        implode(',', array_keys($revealed)), $picksUsed, $creditsFound, $shrugUsed ? 1 : 0,
        $status, $ended, $status === 'active' ? null : pw_missions_datetime($now),
        (int)$run['id'],
    ]);
    if ($update->rowCount() !== 1) throw new RuntimeException('That sweep is no longer open.');

    if ($status !== 'active') {
        // The crew member comes home either way; only the haul is at stake.
        pw_sweep_release_crew($db, $userId, (int)$run['player_crew_id'], $now);
    }

    $runStmt = $db->prepare('SELECT * FROM game_player_sweep_runs WHERE id = ?');
    $runStmt->execute([(int)$run['id']]);
    $fresh = $runStmt->fetch();
    $db->commit();
    pw_json([
        'ok' => true,
        'find' => $find,
        'shrugged' => $shrugged,
        'ended' => $ended,
        'run' => pw_sweep_run_payload($db, $fresh),
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    pw_error($e instanceof RuntimeException ? $e->getMessage() : 'That scan could not be completed.', 400);
}
