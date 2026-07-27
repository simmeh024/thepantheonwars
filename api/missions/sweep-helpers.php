<?php
/**
 * Salvage Sweep: a played board rather than a timer.
 *
 * The governing rule is that the browser is shown a grid it cannot read. The
 * layout is a pure function of a seed that never leaves the server, every pick
 * is validated against the run's own row, and a cell's contents are resolved
 * here at the moment it is revealed. A player with the network tab open sees
 * only the cells they have already turned over.
 *
 * One tier per reputation rank. The rank a player holds picks the tier, the
 * tier names the loot table, and the tier's own numbers set the board -- so
 * rewards grow with standing without any of this code knowing what is in a
 * table. A rank with no tier row simply has no sweep, which is what makes the
 * ladder safe to fill in over time.
 */
require_once __DIR__ . '/missions-helpers.php';

/** Hard ceilings, so an authoring mistake cannot produce an unplayable board. */
const PW_SWEEP_MAX_ROWS = 8;
const PW_SWEEP_MAX_COLS = 8;
const PW_SWEEP_MAX_PICKS = 40;
/** Cunning buys picks: one more for every whole this-many points. */
const PW_SWEEP_CUNNING_PER_PICK = 12;
/** Science buys the hint radius: one ring for every whole this-many points. */
const PW_SWEEP_SCIENCE_PER_RING = 18;
/** Strength buys one chance to survive a collapse, at this much per point. */
const PW_SWEEP_STRENGTH_SHRUG_PER_POINT = 1.4;
const PW_SWEEP_SHRUG_CAP = 60.0;
/** Charisma adds to the XP a banked sweep pays. */
const PW_SWEEP_CHARISMA_XP_PER_POINT = 0.8;

function pw_sweep_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db) || !pw_mission_fatigue_ready($db) || !pw_mission_loot_tables_ready($db)) {
        return $ready = false;
    }
    return $ready = pw_schema_has($db, 'game_sweep_tiers', ['rank_number', 'loot_table_id'])
        && pw_schema_has($db, 'game_player_sweep_runs', ['grid_seed', 'revealed_cells'])
        && pw_schema_has($db, 'game_player_sweep_finds', ['run_id', 'cell_index']);
}

function pw_sweep_require_ready(PDO $db): void {
    if (!pw_sweep_ready($db)) {
        pw_error('The Salvage Sweep is being prepared. Run sql/migration_salvage_sweep.sql first.', 503);
    }
}

/**
 * The tier for one rank, clamped to something playable.
 *
 * Returns null when the rank has no enabled tier -- an unfilled rung of the
 * ladder, which is a normal state rather than an error.
 */
function pw_sweep_tier(PDO $db, int $rank): ?array {
    if ($rank < 1) return null;
    $stmt = $db->prepare(
        'SELECT tier.*, lt.name AS loot_table_name, lt.is_enabled AS loot_table_enabled
         FROM game_sweep_tiers tier
         LEFT JOIN game_loot_tables lt ON lt.id = tier.loot_table_id
         WHERE tier.rank_number = ? AND tier.is_enabled = 1'
    );
    $stmt->execute([$rank]);
    $tier = $stmt->fetch();
    if (!$tier) return null;
    return pw_sweep_normalise_tier($tier);
}

/** Every board dimension bounded, so no authored value can break the grid. */
function pw_sweep_normalise_tier(array $tier): array {
    $rows = max(2, min(PW_SWEEP_MAX_ROWS, (int)$tier['grid_rows']));
    $cols = max(2, min(PW_SWEEP_MAX_COLS, (int)$tier['grid_cols']));
    $cells = $rows * $cols;
    /* At least one cell has to survive being neither hazard nor spent pick, or
     * the board is a loss the moment it opens. */
    $hazards = max(0, min($cells - 2, (int)$tier['hazard_count']));
    return [
        'id' => (int)$tier['id'],
        'rank_number' => (int)$tier['rank_number'],
        'name' => (string)($tier['name'] ?? ''),
        'loot_table_id' => $tier['loot_table_id'] !== null ? (int)$tier['loot_table_id'] : null,
        'loot_table_name' => (string)($tier['loot_table_name'] ?? ''),
        'loot_table_enabled' => !empty($tier['loot_table_enabled']),
        'grid_rows' => $rows,
        'grid_cols' => $cols,
        'hazard_count' => $hazards,
        'base_picks' => max(1, min(PW_SWEEP_MAX_PICKS, (int)$tier['base_picks'])),
        'cache_credits' => max(0, (int)$tier['cache_credits']),
        'fatigue_cost' => max(0, (int)$tier['fatigue_cost']),
        'xp_reward' => max(0, (int)$tier['xp_reward']),
    ];
}

/**
 * What one crew member brings to a board.
 *
 * All four stats matter, and each buys a different kind of advantage rather
 * than more of the same one: picks are how long you can stay, the hint radius
 * is how much you know before choosing, the shrug is a second chance, and XP
 * is what the run is worth afterwards.
 */
function pw_sweep_crew_bonuses(array $crew, array $tier): array {
    $stat = static function ($key) use ($crew) {
        return max(0, min(PW_MISSION_MAX_GEAR_STAT, (int)($crew[$key] ?? 0)));
    };
    $picks = $tier['base_picks'] + (int)floor($stat('cunning') / PW_SWEEP_CUNNING_PER_PICK);
    $cells = $tier['grid_rows'] * $tier['grid_cols'];
    return [
        // Never more picks than there are cells: a board you cannot fail to
        // clear is not a decision.
        'picks_total' => max(1, min(PW_SWEEP_MAX_PICKS, min($cells - 1, $picks))),
        'hint_radius' => min(2, (int)floor($stat('science') / PW_SWEEP_SCIENCE_PER_RING)),
        'shrug_percent' => round(min(PW_SWEEP_SHRUG_CAP, $stat('strength') * PW_SWEEP_STRENGTH_SHRUG_PER_POINT), 2),
        'xp_reward' => (int)round($tier['xp_reward'] * (1 + ($stat('charisma') * PW_SWEEP_CHARISMA_XP_PER_POINT) / 100)),
    ];
}

/**
 * Where the hazards are, derived from the run's seed.
 *
 * A pure function of (seed, cells, hazards), so the layout never has to be
 * stored and can be recomputed on any request. The seed is the secret; it is
 * generated with random_int() and lives only in the run row.
 *
 * @return array<int, true> hazard cell indexes, keyed for O(1) lookup
 */
function pw_sweep_hazard_cells(int $seed, int $cells, int $hazards): array {
    $order = [];
    for ($index = 0; $index < $cells; $index++) {
        /* Sorting by a keyed hash rather than shuffling with a seeded RNG:
         * PHP's mt_srand sequence is a global, and any other seeded call in the
         * same request would change the board. This depends on nothing else.
         *
         * sha256, NOT crc32. crc32 is linear, so ordering a grid by
         * crc32(seed:index) is decided almost entirely by the seed's high bits
         * and collapses to a handful of layouts: measured at 2000 seeds
         * producing 91 distinct 5x5 boards out of 177,100 possible. The same
         * crc32 seeding is fine where this codebase already uses it -- the
         * weather forecast and the daily contract pick ONE item by modulo,
         * which is far less sensitive than sorting twenty-five values. */
        $order[$index] = hexdec(substr(hash('sha256', $seed . ':' . $index), 0, 12));
    }
    asort($order);
    $picked = array_slice(array_keys($order), 0, max(0, $hazards));
    $map = [];
    foreach ($picked as $index) $map[(int)$index] = true;
    return $map;
}

/** Hazards adjacent to one cell, within the crew's hint radius. */
function pw_sweep_adjacent_hazards(int $index, int $rows, int $cols, array $hazards, int $radius): int {
    if ($radius < 1) return 0;
    $row = intdiv($index, $cols);
    $col = $index % $cols;
    $count = 0;
    for ($r = $row - $radius; $r <= $row + $radius; $r++) {
        for ($c = $col - $radius; $c <= $col + $radius; $c++) {
            if ($r < 0 || $c < 0 || $r >= $rows || $c >= $cols) continue;
            if ($r === $row && $c === $col) continue;
            if (isset($hazards[$r * $cols + $c])) $count++;
        }
    }
    return $count;
}

/** The revealed list, stored as a bounded comma-separated string. */
function pw_sweep_revealed(array $run): array {
    $raw = trim((string)($run['revealed_cells'] ?? ''));
    if ($raw === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $value) {
        if ($value === '') continue;
        $out[(int)$value] = true;
    }
    return $out;
}

/**
 * Draw one entry from a loot table, weighted by the entries' own chances.
 *
 * A mission rolls every entry independently -- several can drop at once. A
 * sweep cell is a single find, so it picks one, using chance_percent as a
 * weight rather than as an independent probability. The rows come from
 * pw_missions_loot_entry_statement(), so a table means the same thing here as
 * it does on a mission.
 */
function pw_sweep_draw_entry(PDO $db, ?int $lootTableId): ?array {
    if ($lootTableId === null || $lootTableId < 1) return null;
    $stmt = pw_missions_loot_entry_statement($db);
    $stmt->execute([$lootTableId]);
    $entries = $stmt->fetchAll();
    if (!$entries) return null;
    $total = 0.0;
    foreach ($entries as $entry) $total += max(0.0, (float)$entry['chance_percent']);
    if ($total <= 0) return null;
    /* random_int over a scaled integer range: this is a reward roll, so it uses
     * the same CSPRNG the rest of the mission engine does rather than rand(). */
    $roll = random_int(0, (int)round($total * 100)) / 100;
    $running = 0.0;
    foreach ($entries as $entry) {
        $running += max(0.0, (float)$entry['chance_percent']);
        if ($roll <= $running) return $entry;
    }
    return $entries[count($entries) - 1];
}

/**
 * What a revealed cell turns out to be.
 *
 * Resolved at reveal time rather than baked into the seed: only the hazard
 * layout is deterministic, because that is the part the player is reasoning
 * about. Making the rewards deterministic too would mean a leaked seed gave
 * away the whole board's value, and it would stop the same tier feeling
 * different on a second run.
 */
function pw_sweep_resolve_cell(PDO $db, array $run, int $index): array {
    $hazards = pw_sweep_hazard_cells((int)$run['grid_seed'], (int)$run['grid_rows'] * (int)$run['grid_cols'], (int)$run['hazard_count']);
    if (isset($hazards[$index])) return ['type' => 'hazard'];
    /* A third of safe cells hold a cache rather than an item, so a board has
     * some small guaranteed value even when the table rolls nothing. */
    if (random_int(1, 100) <= 34) {
        $credits = max(0, (int)$run['cache_credits']);
        return ['type' => 'cache', 'credits' => $credits > 0 ? random_int((int)ceil($credits * 0.6), $credits) : 0];
    }
    $entry = pw_sweep_draw_entry($db, $run['loot_table_id'] !== null ? (int)$run['loot_table_id'] : null);
    if (!$entry) return ['type' => 'empty'];
    return ['type' => 'find', 'entry' => $entry];
}

/**
 * The run as the browser is allowed to see it.
 *
 * Revealed cells carry what they turned out to be; everything else is sent as
 * nothing at all -- not as a masked value, which would still tell the browser
 * how many cells hold something. The seed and the hazard layout never appear.
 */
function pw_sweep_public_run(PDO $db, int $userId): ?array {
    $stmt = $db->prepare('SELECT * FROM game_player_sweep_runs WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $run = $stmt->fetch();
    if (!$run) return null;
    return pw_sweep_run_payload($db, $run);
}

function pw_sweep_run_payload(PDO $db, array $run): array {
    $finds = $db->prepare('SELECT cell_index, find_type, credits, loot_definition_id, crew_definition_id FROM game_player_sweep_finds WHERE run_id = ? ORDER BY cell_index ASC');
    $finds->execute([(int)$run['id']]);
    $cells = [];
    foreach ($finds->fetchAll() as $row) {
        $cells[(int)$row['cell_index']] = [
            'index' => (int)$row['cell_index'],
            'type' => (string)$row['find_type'],
            'credits' => (int)$row['credits'],
        ];
    }
    /* A revealed cell with no find row was a hazard or an empty pocket. Both
     * are still revealed, so the board has to say so. */
    foreach (array_keys(pw_sweep_revealed($run)) as $index) {
        if (!isset($cells[$index])) $cells[$index] = ['index' => (int)$index, 'type' => 'empty', 'credits' => 0];
    }
    ksort($cells);
    $names = pw_sweep_find_labels($db, $run);
    /* The adjacency hint is recomputed from the seed rather than stored, and
     * only for cells already turned over. Sending it for an unrevealed cell
     * would hand the player the board -- the hint is the reward for spending a
     * pick next to something, not a free scan. */
    $rows = (int)$run['grid_rows'];
    $cols = (int)$run['grid_cols'];
    $radius = (int)$run['hint_radius'];
    $hazards = pw_sweep_hazard_cells((int)$run['grid_seed'], $rows * $cols, (int)$run['hazard_count']);
    foreach ($cells as $index => $cell) {
        $cells[$index]['label'] = $names[$index] ?? '';
        $cells[$index]['hint'] = $radius > 0 && $cell['type'] !== 'hazard'
            ? pw_sweep_adjacent_hazards((int)$index, $rows, $cols, $hazards, $radius)
            : null;
    }
    return [
        'id' => (int)$run['id'],
        'rank_number' => (int)$run['rank_number'],
        'grid_rows' => (int)$run['grid_rows'],
        'grid_cols' => (int)$run['grid_cols'],
        'picks_total' => (int)$run['picks_total'],
        'picks_used' => (int)$run['picks_used'],
        'picks_left' => max(0, (int)$run['picks_total'] - (int)$run['picks_used']),
        'hint_radius' => (int)$run['hint_radius'],
        'shrug_percent' => (float)$run['shrug_percent'],
        'shrug_used' => (bool)$run['shrug_used'],
        'hazard_count' => (int)$run['hazard_count'],
        'credits_found' => (int)$run['credits_found'],
        'xp_reward' => (int)$run['xp_reward'],
        'status' => (string)$run['status'],
        'ended_reason' => (string)$run['ended_reason'],
        'cells' => array_values($cells),
    ];
}

/** Reader-facing names for what each revealed cell held, plus stored hints. */
function pw_sweep_find_labels(PDO $db, array $run): array {
    $stmt = $db->prepare('SELECT cell_index, find_type, loot_definition_id, crew_definition_id, credits FROM game_player_sweep_finds WHERE run_id = ?');
    $stmt->execute([(int)$run['id']]);
    $rows = $stmt->fetchAll();
    $lootIds = [];
    $crewIds = [];
    foreach ($rows as $row) {
        if ($row['loot_definition_id'] !== null) $lootIds[(int)$row['loot_definition_id']] = true;
        if ($row['crew_definition_id'] !== null) $crewIds[(int)$row['crew_definition_id']] = true;
    }
    $lootNames = [];
    if ($lootIds) {
        $ids = array_keys($lootIds);
        $q = $db->prepare('SELECT id, name FROM game_loot_definitions WHERE id IN (' . pw_missions_placeholders(count($ids)) . ')');
        $q->execute($ids);
        foreach ($q->fetchAll() as $row) $lootNames[(int)$row['id']] = (string)$row['name'];
    }
    $crewNames = [];
    if ($crewIds) {
        $ids = array_keys($crewIds);
        $q = $db->prepare('SELECT id, name FROM game_crew_definitions WHERE id IN (' . pw_missions_placeholders(count($ids)) . ')');
        $q->execute($ids);
        foreach ($q->fetchAll() as $row) $crewNames[(int)$row['id']] = (string)$row['name'];
    }
    $labels = [];
    foreach ($rows as $row) {
        $index = (int)$row['cell_index'];
        if ($row['find_type'] === 'cache') $labels[$index] = number_format((int)$row['credits']) . ' credits';
        elseif ($row['loot_definition_id'] !== null) $labels[$index] = $lootNames[(int)$row['loot_definition_id']] ?? 'Recovered item';
        elseif ($row['crew_definition_id'] !== null) $labels[$index] = $crewNames[(int)$row['crew_definition_id']] ?? 'Recovered contact';
        else $labels[$index] = '';
    }
    return $labels;
}

/**
 * Send the crew member home.
 *
 * fatigue_updated_at is restamped so rest starts from the return rather than
 * from the launch -- the same rule claim.php follows, and the reason a long
 * operation is not free.
 */
function pw_sweep_release_crew(PDO $db, int $userId, int $crewId, DateTimeImmutable $now): void {
    $stmt = $db->prepare(
        'UPDATE game_player_crew SET status = "available", fatigue_updated_at = ?
         WHERE id = ? AND user_id = ? AND status = "on_mission"'
    );
    $stmt->execute([pw_missions_datetime($now), $crewId, $userId]);
}
