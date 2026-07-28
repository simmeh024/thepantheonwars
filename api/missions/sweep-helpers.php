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
/* Research effects raise the fatigue ceiling and its recovery rate, and this
 * file calls pw_research_player_effects() directly rather than behind a
 * function_exists() guard the way missions-helpers.php does -- so it has to
 * require the file that defines it. api/missions/overview.php requires both for
 * the same reason; missions-helpers.php does not, which is why merely being
 * loaded by a missions page was never enough. */
require_once __DIR__ . '/../research/research-helpers.php';

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

/**
 * Sector conditions are authored as a fixed set, rather than free text: each
 * one changes live rules, has a warning the player can act on, and owns one
 * visual template across Admin and the field screen. A hand-written warning
 * would invite an author to promise a penalty the engine does not enforce.
 */
function pw_sweep_condition_types(): array {
    return [
        'clear' => [
            'label' => 'Nominal field',
            'warning' => 'Nominal conditions. Field systems are operating within expected limits.',
            'effect' => 'No sector penalty.',
            'template' => 'nominal',
        ],
        'signal_interference' => [
            'label' => 'Signal interference',
            'warning' => 'Warning: this sector has heavy signal interference. Cache Recognition previews are not possible.',
            'effect' => 'Cache Recognition previews disabled.',
            'template' => 'interference',
        ],
        'unstable_structure' => [
            'label' => 'Unstable structure',
            'warning' => 'Danger: failing supports add one collapse after shoring has been applied.',
            'effect' => '+1 collapse after shoring.',
            'template' => 'unstable',
        ],
        'dense_debris' => [
            'label' => 'Dense debris',
            'warning' => 'Caution: dense debris slows every route through this sector. One scan is lost after crew and research bonuses.',
            'effect' => '-1 scan after bonuses.',
            'template' => 'debris',
        ],
    ];
}

/** Unknown or retired condition keys always fail safely to a nominal field. */
function pw_sweep_condition(string $key): array {
    $types = pw_sweep_condition_types();
    $key = strtolower(trim($key));
    return array_merge(['key' => 'clear'], $types[$key] ?? $types['clear'], ['key' => isset($types[$key]) ? $key : 'clear']);
}

/** The browser receives words and a visual template, never a client-editable rule. */
function pw_sweep_condition_public(string $key): array {
    return pw_sweep_condition($key);
}

function pw_sweep_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_missions_ready($db) || !pw_mission_fatigue_ready($db) || !pw_mission_loot_tables_ready($db)) {
        return $ready = false;
    }
    return $ready = pw_schema_has($db, 'game_sweep_tiers', ['rank_number', 'loot_table_id', 'condition_key'])
        && pw_schema_has($db, 'game_player_sweep_runs', ['grid_seed', 'revealed_cells', 'condition_key'])
        && pw_schema_has($db, 'game_player_sweep_finds', ['run_id', 'cell_index']);
}

/**
 * Which prerequisite is missing, or '' when the sweep is ready.
 *
 * Named rather than reported as one blanket "being prepared": the sweep sits
 * on top of four earlier migrations, and being told which one is absent is the
 * difference between a two-minute fix and a hunt.
 */
function pw_sweep_missing_requirement(PDO $db): string {
    if (!pw_missions_ready($db)) return 'the base Missions migration';
    if (!pw_mission_fatigue_ready($db)) return 'sql/migration_mission_fatigue.sql';
    if (!pw_mission_loot_tables_ready($db)) return 'the mission loot-table migration';
    if (!pw_schema_has($db, 'game_sweep_tiers', ['rank_number', 'loot_table_id'])
        || !pw_schema_has($db, 'game_player_sweep_runs', ['grid_seed', 'revealed_cells'])
        || !pw_schema_has($db, 'game_player_sweep_finds', ['run_id', 'cell_index'])) {
        return 'sql/migration_salvage_sweep.sql';
    }
    if (!pw_schema_has($db, 'game_sweep_tiers', ['condition_key'])
        || !pw_schema_has($db, 'game_player_sweep_runs', ['condition_key'])) {
        return 'sql/migration_sweep_sector_conditions.sql';
    }
    return '';
}

function pw_sweep_require_ready(PDO $db): void {
    $missing = pw_sweep_missing_requirement($db);
    if ($missing !== '') {
        pw_error('The Salvage Sweep needs ' . $missing . ' to be run first.', 503);
    }
}

/**
 * The sector a given rank opens: the highest authored tier at or below it.
 *
 * A ladder, not a lookup table. Matching the rank exactly meant a rank-12
 * commander had no sweep at all until rank 12 itself was authored, even with
 * eleven sectors filled in below -- and it contradicted what the tier editor
 * has always told the author, that a sector "opens for anyone holding rank N
 * or above, until a higher sector does".
 *
 * The practical consequence is that the ladder can be filled in sparsely: one
 * sector at rank 1 covers everybody until a second is written.
 *
 * Returns null only when no enabled tier sits at or below the rank.
 */
function pw_sweep_tier(PDO $db, int $rank): ?array {
    if ($rank < 1) return null;
    $stmt = $db->prepare(
        'SELECT tier.*, lt.name AS loot_table_name, lt.is_enabled AS loot_table_enabled
         FROM game_sweep_tiers tier
         LEFT JOIN game_loot_tables lt ON lt.id = tier.loot_table_id
         WHERE tier.rank_number <= ? AND tier.is_enabled = 1
         ORDER BY tier.rank_number DESC
         LIMIT 1'
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
        'condition_key' => pw_sweep_condition((string)($tier['condition_key'] ?? 'clear'))['key'],
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
function pw_sweep_crew_bonuses(array $crew, array $tier, array $research = []): array {
    $stat = static function ($key) use ($crew) {
        return max(0, min(PW_MISSION_MAX_GEAR_STAT, (int)($crew[$key] ?? 0)));
    };
    $effect = static function ($key) use ($research) {
        return max(0.0, (float)($research[$key] ?? 0));
    };
    /* Research adds scans on top of the sector base and Cunning, rather than
     * multiplying them: a flat grant is legible at every sector size, where a
     * percentage would be worth almost nothing on a small field. */
    $picks = $tier['base_picks'] + (int)floor($stat('cunning') / PW_SWEEP_CUNNING_PER_PICK)
        + (int)$effect('sweep_scans');
    $cells = $tier['grid_rows'] * $tier['grid_cols'];
    /* Survey tuning cuts the Science each ring costs. Floored at a third of the
     * base so the ceiling of two rings still has to be earned. */
    $perRing = max(PW_SWEEP_SCIENCE_PER_RING / 3, PW_SWEEP_SCIENCE_PER_RING * (1 - $effect('sweep_survey_percent') / 100));
    // Brace tuning is a percentage of the chance Strength already bought, so it
    // is worth nothing to a crew member with none -- it tunes, it does not grant.
    $shrug = $stat('strength') * PW_SWEEP_STRENGTH_SHRUG_PER_POINT * (1 + $effect('sweep_brace_percent') / 100);
    return [
        // Never more picks than there are cells: a board you cannot fail to
        // clear is not a decision.
        'picks_total' => max(1, min(PW_SWEEP_MAX_PICKS, min($cells - 1, $picks))),
        'hint_radius' => $perRing > 0 ? min(2, (int)floor($stat('science') / $perRing)) : 0,
        'shrug_percent' => round(min(PW_SWEEP_SHRUG_CAP, $shrug), 2),
        'xp_reward' => (int)round($tier['xp_reward'] * (1 + ($stat('charisma') * PW_SWEEP_CHARISMA_XP_PER_POINT) / 100)),
    ];
}

/**
 * The collapses a field actually carries once shoring is applied.
 *
 * One always remains. A field that cannot fall has no decision in it, which is
 * the whole mechanic, so the reduction is capped rather than allowed to reach
 * zero however many nodes are stacked.
 */
function pw_sweep_effective_hazards(array $tier, array $research = []): int {
    $hazards = max(0, (int)$tier['hazard_count']);
    if ($hazards <= 1) return $hazards;
    $removed = (int)floor($hazards * max(0.0, (float)($research['sweep_collapse_percent'] ?? 0)) / 100);
    return max(1, $hazards - $removed);
}

/** Freeze a sector condition beside the board values it changes at launch. */
function pw_sweep_apply_condition(array $tier, array $bonuses, int $hazards, float $recognitionPercent): array {
    $condition = pw_sweep_condition((string)($tier['condition_key'] ?? 'clear'));
    $cells = max(1, (int)$tier['grid_rows'] * (int)$tier['grid_cols']);
    if ($condition['key'] === 'signal_interference') {
        $recognitionPercent = 0.0;
    } elseif ($condition['key'] === 'unstable_structure') {
        /* Applied after Shoring deliberately: this is an environmental failure
         * at launch, not another authored collapse research could erase. */
        $hazards = min($cells - 2, max(0, $hazards + 1));
    } elseif ($condition['key'] === 'dense_debris') {
        $bonuses['picks_total'] = max(1, (int)$bonuses['picks_total'] - 1);
    }
    return [
        'condition' => $condition,
        'hazard_count' => $hazards,
        'bonuses' => $bonuses,
        'recognition_percent' => max(0.0, $recognitionPercent),
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

/**
 * A stable 0-1 roll for one cell and one purpose.
 *
 * Salted per purpose so the cache roll and the recognition roll of the same
 * cell are independent, and derived from the run's seed so both are stable
 * across requests. That stability is what lets Cache Recognition promise
 * anything at all: a preview has to still be true when the cell is opened.
 */
function pw_sweep_cell_roll(int $seed, int $index, string $salt): float {
    $hex = substr(hash('sha256', $seed . ':' . $salt . ':' . $index), 0, 12);
    return hexdec($hex) / 0xFFFFFFFFFFFF;
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
function pw_sweep_draw_entry(PDO $db, ?int $lootTableId, ?float $roll = null): ?array {
    if ($lootTableId === null || $lootTableId < 1) return null;
    $stmt = pw_missions_loot_entry_statement($db);
    $stmt->execute([$lootTableId]);
    $entries = $stmt->fetchAll();
    if (!$entries) return null;
    $total = 0.0;
    foreach ($entries as $entry) $total += max(0.0, (float)$entry['chance_percent']);
    if ($total <= 0) return null;
    /* The caller supplies a stable 0-1 roll so the same cell always draws the
     * same entry; without one this falls back to the CSPRNG the rest of the
     * mission engine uses. */
    $roll = $roll === null ? random_int(0, (int)round($total * 100)) / 100 : $roll * $total;
    $running = 0.0;
    foreach ($entries as $entry) {
        $running += max(0.0, (float)$entry['chance_percent']);
        if ($roll <= $running) return $entry;
    }
    return $entries[count($entries) - 1];
}

/**
 * What a cell turns out to be. The whole board is a function of the seed.
 *
 * It was not always: the contents used to be rolled at reveal time, on the
 * reasoning that a leaked seed should not give away the board's value. Cache
 * Recognition made that untenable -- a preview of a cell can only be honest if
 * the cell already has an answer -- and the reasoning was weak anyway, since
 * the seed never leaves the server and a fresh one is drawn per run.
 *
 * A cell is still only resolved when something asks about it, so nothing is
 * stored that a player has not earned the right to see.
 */
function pw_sweep_resolve_cell(PDO $db, array $run, int $index): array {
    $seed = (int)$run['grid_seed'];
    $hazards = pw_sweep_hazard_cells($seed, (int)$run['grid_rows'] * (int)$run['grid_cols'], (int)$run['hazard_count']);
    if (isset($hazards[$index])) return ['type' => 'hazard'];
    /* A third of safe cells hold a cache rather than an item, so a board has
     * some small guaranteed value even when the table rolls nothing. */
    if (pw_sweep_cell_roll($seed, $index, 'kind') <= 0.34) {
        $credits = max(0, (int)$run['cache_credits']);
        $spread = pw_sweep_cell_roll($seed, $index, 'cache');
        return ['type' => 'cache', 'credits' => $credits > 0 ? (int)round($credits * (0.6 + 0.4 * $spread)) : 0];
    }
    $entry = pw_sweep_draw_entry($db, $run['loot_table_id'] !== null ? (int)$run['loot_table_id'] : null,
        pw_sweep_cell_roll($seed, $index, 'entry'));
    if (!$entry) return ['type' => 'empty'];
    return ['type' => 'find', 'entry' => $entry];
}

/**
 * What Cache Recognition says about an unopened cell.
 *
 * Only safe cells are ever identified -- naming a collapse would replace the
 * decision the sweep is built on with a map. The four answers are deliberately
 * coarse: material, credits, equipment, or unknown. A crew find reads as
 * unknown, so the rarest thing on the board is never announced in advance.
 *
 * Returns '' when this cell is not identified, which is most of them.
 */
function pw_sweep_cell_preview(PDO $db, array $run, int $index, float $recognitionPercent): string {
    if ($recognitionPercent <= 0) return '';
    $seed = (int)$run['grid_seed'];
    if (pw_sweep_cell_roll($seed, $index, 'recognise') >= $recognitionPercent / 100) return '';
    $outcome = pw_sweep_resolve_cell($db, $run, $index);
    if ($outcome['type'] === 'hazard') return '';
    if ($outcome['type'] === 'cache') return 'credits';
    if ($outcome['type'] !== 'find') return 'unknown';
    $entry = $outcome['entry'];
    if (($entry['entry_type'] ?? 'crew') !== 'gear') return 'unknown';
    // A slotless item is salvage; anything that can be worn is equipment.
    return trim((string)($entry['gear_slot'] ?? '')) === '' ? 'material' : 'equipment';
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
    $revealed = pw_sweep_revealed($run);
    foreach (array_keys($revealed) as $index) {
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
        $detail = $names[$index] ?? ['label' => '', 'icon' => '', 'tier' => ''];
        $cells[$index]['label'] = $detail['label'];
        $cells[$index]['icon'] = $detail['icon'];
        $cells[$index]['tier'] = $detail['tier'];
        $cells[$index]['hint'] = $radius > 0 && $cell['type'] !== 'hazard'
            ? pw_sweep_adjacent_hazards((int)$index, $rows, $cols, $hazards, $radius)
            : null;
    }
    /* Cache Recognition. Only unopened cells carry a preview -- an opened one
     * already shows what it held -- and only safe ones are ever identified, so
     * this can never be read as a collapse detector. */
    $previews = [];
    $recognition = (float)($run['recognition_percent'] ?? 0);
    if ($recognition > 0 && (string)$run['status'] === 'active') {
        for ($index = 0; $index < $rows * $cols; $index++) {
            if (isset($cells[$index])) continue;
            $preview = pw_sweep_cell_preview($db, $run, $index, $recognition);
            if ($preview !== '') $previews[] = ['index' => $index, 'preview' => $preview];
        }
    }

    return [
        'id' => (int)$run['id'],
        'rank_number' => (int)$run['rank_number'],
        'player_crew_id' => (int)$run['player_crew_id'],
        'last_cell_index' => $revealed ? (int)array_key_last($revealed) : null,
        'condition' => pw_sweep_condition_public((string)($run['condition_key'] ?? 'clear')),
        'previews' => $previews,
        'recognition_percent' => $recognition,
        'momentum_percent' => (float)($run['momentum_percent'] ?? 0),
        'tether_percent' => (float)($run['tether_percent'] ?? 0),
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

/**
 * The complete field after a run has closed.
 *
 * Unlike pw_sweep_run_payload(), this is expressly an after-action report: it
 * names every reward and every collapse on the board. It must therefore only
 * ever be called for a terminal run owned by the player receiving it. Keeping
 * the reveal here, rather than teaching the active-run payload about masked
 * cells, prevents a response or a cache from becoming a map while the player
 * can still make decisions on the board.
 */
function pw_sweep_result_payload(PDO $db, array $run): array {
    $status = (string)($run['status'] ?? '');
    if (!in_array($status, ['banked', 'lost', 'abandoned'], true)) {
        throw new InvalidArgumentException('A field can only be revealed once the sweep has closed.');
    }

    $findStmt = $db->prepare(
        'SELECT cell_index, find_type, credits, loot_definition_id, crew_definition_id
         FROM game_player_sweep_finds WHERE run_id = ? ORDER BY cell_index ASC'
    );
    $findStmt->execute([(int)$run['id']]);
    $finds = [];
    foreach ($findStmt->fetchAll() as $row) $finds[(int)$row['cell_index']] = $row;
    $labels = pw_sweep_find_labels($db, $run);

    /* revealed_cells is intentionally written in scan order. The final scan
     * on a lost run is necessarily the collapse, which lets the debrief draw
     * that red cell faithfully without recording hazards as finds. */
    $revealed = pw_sweep_revealed($run);
    $revealedIndexes = array_keys($revealed);
    $firstRevealed = $revealedIndexes ? (int)$revealedIndexes[0] : -1;
    $lastRevealed = $revealedIndexes ? (int)$revealedIndexes[count($revealedIndexes) - 1] : -1;
    $rows = (int)$run['grid_rows'];
    $cols = (int)$run['grid_cols'];
    $cells = [];
    $rewardCount = 0;
    $unrecoveredCount = 0;

    for ($index = 0; $index < $rows * $cols; $index++) {
        $wasRevealed = isset($revealed[$index]);
        $stored = $finds[$index] ?? null;
        if ($stored !== null) {
            $type = (string)$stored['find_type'];
            $detail = $labels[$index] ?? ['label' => '', 'icon' => '', 'tier' => ''];
            $cell = [
                'index' => $index,
                'type' => $type,
                'label' => (string)($detail['label'] ?: ($type === 'cache' ? 'Credit cache' : 'Recovered find')),
                'icon' => (string)$detail['icon'],
                'tier' => (string)$detail['tier'],
                'credits' => (int)$stored['credits'],
                'revealed' => true,
            ];
        } else {
            $outcome = pw_sweep_resolve_cell($db, $run, $index);
            $type = (string)$outcome['type'];

            /* A stabilised opening hazard and a braced collapse are not stored
             * as finds. Both can be reconstructed from the frozen board and
             * scan order, so the result matches the round the player played. */
            if ($wasRevealed && $type === 'hazard') {
                if ($status === 'lost' && $index === $lastRevealed) {
                    $type = 'hazard';
                } elseif ($index === $firstRevealed && (float)($run['stabiliser_points'] ?? 0) > 0) {
                    $type = 'stabilised';
                } elseif (!empty($run['shrug_used'])) {
                    $type = 'shrug';
                }
            }

            $cell = [
                'index' => $index,
                'type' => $type,
                'label' => '',
                'icon' => '',
                'tier' => '',
                /* Credit caches only become a precise amount when their scan
                 * is made: Momentum depends on the scan order. An unopened
                 * cache is named honestly without inventing a payout. */
                'credits' => 0,
                'revealed' => $wasRevealed,
            ];
            if ($type === 'cache') {
                $cell['label'] = $wasRevealed ? 'Credit cache' : 'Unrecovered credit cache';
            } elseif ($type === 'gear' || $type === 'crew') {
                /* These values only occur below when a stored row exists; kept
                 * for clarity if a future outcome introduces a direct type. */
                $cell['label'] = 'Recovery';
            } elseif ($outcome['type'] === 'find') {
                $entry = $outcome['entry'];
                $isGear = ($entry['entry_type'] ?? 'crew') === 'gear';
                $cell['type'] = $isGear ? 'gear' : 'crew';
                $cell['label'] = (string)($isGear ? ($entry['gear_name'] ?? 'Field item') : ($entry['crew_name'] ?? 'Field contact'));
                $cell['icon'] = pw_missions_gear_icon_url(($isGear ? $entry['gear_icon_url'] : $entry['portrait_url']) ?? '');
                $cell['tier'] = strtolower((string)($isGear ? ($entry['tier'] ?? 'common') : ($entry['crew_tier'] ?? 'common')));
            } elseif ($type === 'hazard') {
                $cell['label'] = 'Collapse';
            } elseif ($type === 'shrug') {
                $cell['label'] = 'Braced collapse';
            } elseif ($type === 'stabilised') {
                $cell['label'] = 'Stabilised collapse';
            } else {
                $cell['type'] = 'empty';
                $cell['label'] = 'No recovery';
            }
        }

        if (in_array($cell['type'], ['gear', 'crew', 'cache'], true)) {
            $rewardCount++;
            if (!$cell['revealed']) $unrecoveredCount++;
        }
        $cells[] = $cell;
    }

    return [
        'id' => (int)$run['id'],
        'rank_number' => (int)$run['rank_number'],
        'condition' => pw_sweep_condition_public((string)($run['condition_key'] ?? 'clear')),
        'grid_rows' => $rows,
        'grid_cols' => $cols,
        'picks_used' => (int)$run['picks_used'],
        'picks_total' => (int)$run['picks_total'],
        'status' => $status,
        'ended_reason' => (string)($run['ended_reason'] ?? ''),
        'reward_count' => $rewardCount,
        'unrecovered_count' => $unrecoveredCount,
        'cells' => $cells,
    ];
}

/**
 * What each revealed cell held: its name, its artwork and its rarity.
 *
 * The find rows keep only the definition id, so the picture and the tier are
 * looked up here rather than copied into the run at pick time -- an item
 * re-arted or re-tiered later then shows correctly on a board already played,
 * and nothing is stored twice.
 *
 * @return array<int, array{label:string,icon:string,tier:string}>
 */
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
    $loot = [];
    if ($lootIds) {
        $ids = array_keys($lootIds);
        $q = $db->prepare('SELECT id, name, tier, icon_url FROM game_loot_definitions WHERE id IN (' . pw_missions_placeholders(count($ids)) . ')');
        $q->execute($ids);
        foreach ($q->fetchAll() as $row) {
            $loot[(int)$row['id']] = [
                'label' => (string)$row['name'],
                'icon' => pw_missions_gear_icon_url($row['icon_url'] ?? ''),
                'tier' => strtolower((string)($row['tier'] ?? 'common')),
            ];
        }
    }
    $crew = [];
    if ($crewIds) {
        $ids = array_keys($crewIds);
        $capacityReady = pw_mission_crew_capacity_ready($db);
        $q = $db->prepare('SELECT id, name, portrait_url, ' . ($capacityReady ? 'tier' : '"common" AS tier')
            . ' FROM game_crew_definitions WHERE id IN (' . pw_missions_placeholders(count($ids)) . ')');
        $q->execute($ids);
        foreach ($q->fetchAll() as $row) {
            $crew[(int)$row['id']] = [
                'label' => (string)$row['name'],
                // Same validator the gear icons use: only a path the upload
                // endpoint could have produced is echoed back.
                'icon' => pw_missions_gear_icon_url($row['portrait_url'] ?? ''),
                'tier' => strtolower((string)($row['tier'] ?? 'common')),
            ];
        }
    }
    $blank = ['label' => '', 'icon' => '', 'tier' => ''];
    $details = [];
    foreach ($rows as $row) {
        $index = (int)$row['cell_index'];
        if ($row['find_type'] === 'cache') {
            // A cache is credits, not an object, so it has art of its own and
            // no rarity to be shiny about.
            $details[$index] = ['label' => number_format((int)$row['credits']) . ' credits', 'icon' => '', 'tier' => ''];
        } elseif ($row['loot_definition_id'] !== null) {
            $details[$index] = $loot[(int)$row['loot_definition_id']] ?? array_merge($blank, ['label' => 'Recovered item']);
        } elseif ($row['crew_definition_id'] !== null) {
            $details[$index] = $crew[(int)$row['crew_definition_id']] ?? array_merge($blank, ['label' => 'Recovered contact']);
        } else {
            $details[$index] = $blank;
        }
    }
    return $details;
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

/**
 * The last few epic or legendary finds this command actually kept.
 *
 * Banked runs only. A legendary that was on the board when it collapsed was
 * never won, and listing it as a trophy would be a lie told by the one panel
 * whose whole job is to record what was won.
 *
 * Ordered by the run, not the find: the finds of a single sweep all belong to
 * the same moment, and their cell order is where they sat on the board rather
 * than when they were turned over.
 */
function pw_sweep_recent_trophies(PDO $db, int $userId, int $limit = 5): array {
    $capacityReady = pw_mission_crew_capacity_ready($db);
    $stmt = $db->prepare(
        'SELECT find.find_type, find.loot_definition_id, find.crew_definition_id,
                run.rank_number, run.ended_at,
                gear.name AS gear_name, gear.tier AS gear_tier, gear.icon_url AS gear_icon,
                crew.name AS crew_name, crew.portrait_url AS crew_icon, '
        . ($capacityReady ? 'crew.tier AS crew_tier' : '"common" AS crew_tier') . '
         FROM game_player_sweep_finds find
         JOIN game_player_sweep_runs run ON run.id = find.run_id
         LEFT JOIN game_loot_definitions gear ON gear.id = find.loot_definition_id
         LEFT JOIN game_crew_definitions crew ON crew.id = find.crew_definition_id
         WHERE run.user_id = ? AND run.status = "banked"
           AND (LOWER(gear.tier) IN ("epic", "legendary")'
        . ($capacityReady ? ' OR LOWER(crew.tier) IN ("epic", "legendary")' : '') . ')
         ORDER BY run.ended_at DESC, run.id DESC, find.cell_index ASC
         LIMIT ' . max(1, min(20, $limit))
    );
    $stmt->execute([$userId]);
    $trophies = [];
    foreach ($stmt->fetchAll() as $row) {
        $isGear = $row['loot_definition_id'] !== null;
        $trophies[] = [
            'name' => (string)($isGear ? $row['gear_name'] : $row['crew_name']),
            'tier' => strtolower((string)($isGear ? $row['gear_tier'] : $row['crew_tier'])),
            'kind' => $isGear ? 'gear' : 'crew',
            'icon' => pw_missions_gear_icon_url(($isGear ? $row['gear_icon'] : $row['crew_icon']) ?? ''),
            'rank_number' => (int)$row['rank_number'],
            'found_at' => (string)($row['ended_at'] ?? ''),
        ];
    }
    return $trophies;
}

/** A small public payload for a badge that unlocked in the just-finished run. */
function pw_sweep_achievement_notices(array $keys): array {
    $catalog = [];
    foreach (pw_reputation_achievement_catalog() as $achievement) {
        $catalog[$achievement['key']] = $achievement;
    }
    $notices = [];
    foreach ($keys as $key) {
        $achievement = $catalog[$key] ?? null;
        if (!$achievement) continue;
        $notices[] = [
            'key' => (string)$achievement['key'],
            'name' => (string)$achievement['name'],
            'description' => (string)$achievement['description'],
            'tier' => (string)$achievement['tier'],
            'icon' => (string)$achievement['icon'],
        ];
    }
    return $notices;
}

/**
 * A legendary only counts as lost when it was actually recovered during the
 * run and was not pulled clear by a successful emergency tether.
 */
function pw_sweep_has_lost_legendary_item(PDO $db, array $run, ?array $tether): bool {
    $stmt = $db->prepare(
        'SELECT find.cell_index
         FROM game_player_sweep_finds find
         JOIN game_loot_definitions gear ON gear.id = find.loot_definition_id
         WHERE find.run_id = ? AND LOWER(gear.tier) = "legendary"'
    );
    $stmt->execute([(int)$run['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $savedByTether = $tether
            && ($tether['kind'] ?? '') === 'gear'
            && strtolower((string)($tether['tier'] ?? '')) === 'legendary'
            && ($tether['state'] ?? '') !== 'no_room'
            && (int)($tether['cell_index'] ?? -1) === (int)$row['cell_index'];
        if (!$savedByTether) return true;
    }
    return false;
}

/**
 * Personal records for one sector. Haul value is deliberately an index rather
 * than currency: it combines credits with the tier of the recoveries, so a
 * rare discovery can matter beside a large credit cache without inventing a
 * second wallet or changing the payout rules.
 */
function pw_sweep_sector_records(PDO $db, int $userId, int $rankNumber): array {
    $records = [
        'runs_banked' => 0,
        'fastest_seconds' => null,
        'longest_safe_scans' => 0,
        'best_haul_value' => 0,
        'rarest_tier' => '',
    ];
    if ($userId <= 0 || $rankNumber <= 0) return $records;

    $capacityReady = pw_mission_crew_capacity_ready($db);
    $crewTier = $capacityReady ? 'crew.tier' : '"common"';
    $tierName = 'LOWER(COALESCE(gear.tier, ' . $crewTier . ', "common"))';
    $tierRank = 'CASE ' . $tierName
        . ' WHEN "legendary" THEN 4 WHEN "epic" THEN 3 WHEN "rare" THEN 2 WHEN "uncommon" THEN 1 ELSE 0 END';
    $tierValue = 'CASE ' . $tierName
        . ' WHEN "legendary" THEN 10000 WHEN "epic" THEN 2500 WHEN "rare" THEN 500 WHEN "uncommon" THEN 100 ELSE 0 END';
    $stmt = $db->prepare(
        'SELECT run.id, run.picks_used,
                GREATEST(1, TIMESTAMPDIFF(SECOND, run.created_at, run.ended_at)) AS duration_seconds,
                run.credits_found + COALESCE(SUM(' . $tierValue . '), 0) AS haul_value,
                COALESCE(MAX(' . $tierRank . '), 0) AS rarest_rank
         FROM game_player_sweep_runs run
         LEFT JOIN game_player_sweep_finds find ON find.run_id = run.id
         LEFT JOIN game_loot_definitions gear ON gear.id = find.loot_definition_id
         LEFT JOIN game_crew_definitions crew ON crew.id = find.crew_definition_id
         WHERE run.user_id = ? AND run.rank_number = ? AND run.status = "banked"
         GROUP BY run.id, run.picks_used, run.credits_found, run.created_at, run.ended_at'
    );
    $stmt->execute([$userId, $rankNumber]);
    $tierByRank = [1 => 'uncommon', 2 => 'rare', 3 => 'epic', 4 => 'legendary'];
    $highestRank = 0;
    foreach ($stmt->fetchAll() as $row) {
        $records['runs_banked']++;
        $duration = max(1, (int)$row['duration_seconds']);
        $records['fastest_seconds'] = $records['fastest_seconds'] === null
            ? $duration : min((int)$records['fastest_seconds'], $duration);
        $records['longest_safe_scans'] = max((int)$records['longest_safe_scans'], (int)$row['picks_used']);
        $records['best_haul_value'] = max((int)$records['best_haul_value'], (int)$row['haul_value']);
        $highestRank = max($highestRank, (int)$row['rarest_rank']);
    }
    $records['rarest_tier'] = $tierByRank[$highestRank] ?? '';
    return $records;
}

/**
 * One item pulled back out of a collapse, if the tether holds.
 *
 * Deliberately an item and never credits: credits are the sweep's steady
 * income and losing them is what a collapse means, while an item is the thing
 * a player was staying in for. Chosen uniformly from what was actually
 * recovered, so a long run that found more has more to save.
 *
 * Granted immediately, inside the caller's transaction. A lost run is never
 * banked, so there is nowhere else for it to go.
 *
 * @return array|null the rescued item, or null when nothing was saved
 */
function pw_sweep_tether_rescue(PDO $db, int $userId, array $run): ?array {
    if (random_int(1, 10000) > (int)round(min(100.0, (float)$run['tether_percent']) * 100)) return null;
    $finds = $db->prepare(
        'SELECT * FROM game_player_sweep_finds
         WHERE run_id = ? AND (loot_definition_id IS NOT NULL OR crew_definition_id IS NOT NULL)'
    );
    $finds->execute([(int)$run['id']]);
    $rows = $finds->fetchAll();
    if (!$rows) return null;
    $row = $rows[random_int(0, count($rows) - 1)];

    if ($row['crew_definition_id'] !== null) {
        $received = pw_missions_receive_crew($db, $userId, (int)$row['crew_definition_id'], 'sweep_tether', (int)$run['id']);
        $award = $received['crew'];
        $award['state'] = $received['state'];
        $award['kind'] = 'crew';
        $award['cell_index'] = (int)$row['cell_index'];
        return $award;
    }
    $definition = $db->prepare('SELECT * FROM game_loot_definitions WHERE id = ?');
    $definition->execute([(int)$row['loot_definition_id']]);
    $item = $definition->fetch();
    if (!$item) return null;
    $gear = [[
        'id' => (int)$item['id'],
        'name' => (string)$item['name'],
        'tier' => (string)$item['tier'],
        'upgraded' => false,
        'slot' => (string)($item['slot'] ?? ''),
        'icon_url' => pw_missions_gear_icon_url($item['icon_url'] ?? ''),
    ]];
    /* Through the same store path a claim uses, so the quartermaster ceilings
     * apply -- a rescued item can still be refused for want of room, and the
     * caller reports that rather than pretending it was kept. */
    $stored = pw_missions_store_loot($db, $userId, $gear, pw_research_player_effects($db, $userId), [
        'source_type' => 'sweep_tether',
        'source_id' => (int)$run['id'],
        'note' => 'Tethered from a collapsed Sweep cell',
    ]);
    return [
        'kind' => 'gear',
        'name' => (string)$item['name'],
        'tier' => strtolower((string)$item['tier']),
        'icon' => pw_missions_gear_icon_url($item['icon_url'] ?? ''),
        'state' => empty($stored['skipped']) ? 'granted' : 'no_room',
        'cell_index' => (int)$row['cell_index'],
    ];
}
