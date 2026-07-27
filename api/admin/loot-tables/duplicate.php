<?php
/**
 * Copy a loot table and everything in it.
 *
 * Built for authoring a ladder of near-identical tables -- forty Salvage Sweep
 * sector manifests differ from each other by a few entries and a name, and
 * rebuilding each from nothing is the kind of work that produces mistakes.
 *
 * Two deliberate omissions, both because copying them would be a silent
 * change to live behaviour rather than a convenience:
 *
 *   Mission attachments are NOT copied. A table attached to three operations,
 *   duplicated, would leave those operations rolling both copies and paying
 *   roughly double, with nothing on screen to say so.
 *
 *   The copy starts disabled. It is a half-finished table by definition -- the
 *   whole point is to rename and adjust it -- and a live one that is not yet
 *   what its author intends is worse than one they have to switch on.
 */
require_once __DIR__ . '/loot-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pw_error('Method not allowed.', 405);
$admin = pw_require_permission('loot_tables.edit');
$input = pw_input();
pw_require_csrf($input);
$db = pw_db();
pw_missions_require_loot_table_gear_ready($db);

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id < 1) pw_error('Choose a loot table to copy.');

$researchLocksReady = pw_mission_loot_table_research_locks_ready($db);
$sweepFlagReady = pw_mission_loot_table_sweep_flag_ready($db);

try {
    $db->beginTransaction();
    $source = $db->prepare('SELECT * FROM game_loot_tables WHERE id = ?');
    $source->execute([$id]);
    $table = $source->fetch();
    if (!$table) { $db->rollBack(); pw_error('That loot table no longer exists.', 404); }

    /* A unique slug without a guess-and-retry loop: read the siblings once and
     * step until one is free. The unique key is still the real guarantee. */
    $base = substr(preg_replace('/-copy(-\d+)?$/', '', (string)$table['slug']), 0, 120);
    $existing = $db->prepare('SELECT slug FROM game_loot_tables WHERE slug LIKE ?');
    $existing->execute([$base . '-copy%']);
    $taken = [];
    foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $slug) $taken[strtolower($slug)] = true;
    $slug = $base . '-copy';
    $suffix = 2;
    while (isset($taken[strtolower($slug)])) {
        $slug = $base . '-copy-' . $suffix;
        $suffix++;
        if ($suffix > 200) { $db->rollBack(); pw_error('Too many copies of that table already exist.', 409); }
    }
    $name = mb_substr((string)$table['name'] . ' (copy)', 0, 150);

    $columns = ['name', 'slug', 'description', 'is_enabled'];
    $values = [$name, $slug, (string)($table['description'] ?? ''), 0];
    if ($researchLocksReady) {
        /* The rare-research flag copies, but the lock does not: the lock is set
         * by a protocol pointing at the original, and no protocol points here. */
        $columns[] = 'is_research_rare';
        $values[] = (int)($table['is_research_rare'] ?? 0);
        $columns[] = 'requires_research_unlock';
        $values[] = 0;
    }
    if ($sweepFlagReady) {
        $columns[] = 'is_sweep_only';
        $values[] = (int)($table['is_sweep_only'] ?? 0);
    }
    $insert = $db->prepare('INSERT INTO game_loot_tables (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $insert->execute($values);
    $newId = (int)$db->lastInsertId();

    /* The entries copy in one statement rather than a read-then-write loop, so
     * a table with fifty rows costs one round trip and cannot half-copy. */
    $copyEntries = $db->prepare(
        'INSERT INTO game_loot_table_entries (loot_table_id, entry_type, crew_definition_id, loot_definition_id, chance_percent, sort_order)
         SELECT ?, entry_type, crew_definition_id, loot_definition_id, chance_percent, sort_order
         FROM game_loot_table_entries WHERE loot_table_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $copyEntries->execute([$newId, $id]);
    $entryCount = $copyEntries->rowCount();

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

pw_log_admin_activity(
    'loot_table_duplicated',
    'Copied loot table "' . $table['name'] . '" to "' . $name . '" (' . $entryCount . ' ' . ($entryCount === 1 ? 'entry' : 'entries') . ').',
    $admin
);
pw_json(['ok' => true, 'id' => $newId, 'name' => $name, 'slug' => $slug, 'entries' => $entryCount]);
