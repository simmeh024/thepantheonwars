<?php
/**
 * One-off backfill: computes and caches a sentence embedding for every
 * already-approved Dispatch translation that doesn't have one yet.
 * Run once on the server after applying migration_dispatch_translation_embeddings.sql
 * and standing up the embedding service (see docs/dispatch-embeddings.md):
 *
 *   php tools/backfill-dispatch-embeddings.php
 *
 * Safe to re-run: pw_dispatch_update_translation_embedding() skips any
 * translation whose cached hash already matches its current text, and the
 * cache table itself is keyed by dispatch_id (INSERT ... ON DUPLICATE KEY
 * UPDATE), so this never creates duplicate rows.
 */
require_once dirname(__DIR__) . '/api/db.php';
require_once dirname(__DIR__) . '/api/dispatch-embeddings.php';

$db = pw_db();

if (!defined('DISPATCH_EMBEDDING_SERVICE_URL') || trim((string)DISPATCH_EMBEDDING_SERVICE_URL) === '') {
    fwrite(STDERR, "DISPATCH_EMBEDDING_SERVICE_URL is not configured. Set it in the outside-webroot secrets config first.\n");
    exit(1);
}

$rows = $db->query('SELECT dispatch_id, translation FROM dispatch_translations ORDER BY dispatch_id ASC')->fetchAll();
$total = count($rows);
$updated = 0;
$skipped = 0;

$failed = 0;
$hashLookup = $db->prepare('SELECT translation_hash FROM dispatch_translation_embeddings WHERE dispatch_id = ?');

foreach ($rows as $row) {
    $dispatchId = (int)$row['dispatch_id'];
    $newHash = hash('sha256', trim((string)$row['translation']));

    $hashLookup->execute([$dispatchId]);
    $existingHash = $hashLookup->fetchColumn();
    if ($existingHash === $newHash) {
        $skipped++;
        continue;
    }

    pw_dispatch_update_translation_embedding($db, $dispatchId, (string)$row['translation']);

    // Re-check rather than trust the call succeeded silently -- a downed
    // embedding service makes pw_dispatch_update_translation_embedding()
    // return early without writing anything.
    $hashLookup->execute([$dispatchId]);
    if ($hashLookup->fetchColumn() === $newHash) {
        $updated++;
    } else {
        $failed++;
    }
}

echo "Processed {$total} approved translations: {$updated} embedded, {$skipped} already cached, {$failed} failed.\n";
if ($failed > 0) {
    fwrite(STDERR, "Some translations could not be embedded -- check that the embedding service is reachable (System Status > Embedding Service), then re-run this script.\n");
    exit(1);
}
