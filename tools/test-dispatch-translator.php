<?php
/**
 * Lightweight regression checks for the local Dispatch Draft Translator.
 * Run on the server with: php tools/test-dispatch-translator.php
 *
 * This has no database or network dependency. It protects the essential rule
 * that a source action never survives inside a reader-facing object phrase
 * after a title has been converted into safer public wording.
 */
require_once dirname(__DIR__) . '/api/dispatch-translation-drafts.php';

$cases = [
    [
        'subject' => 'Unlock Asmecu on the worlds page with a full district map',
        'body' => 'New Asmecu section has 7 clickable districts and key landmarks.',
        'tag' => 'refactor',
        'expected' => 'BH-4 has opened Asmecu for exploration on the Worlds page. Visitors can now follow a full district map through 7 clickable districts and key landmarks.',
    ],
    [
        'subject' => 'Add one click copy button for raw commit message',
        'body' => '',
        'tag' => 'feature',
        'forbidden' => ['adds add one click'],
    ],
    [
        'subject' => 'Fix chapter one.html hero staying on Book One when preview unavailable',
        'body' => '',
        'tag' => 'fix',
        'forbidden' => ['fixes fix chapter', 'around fix chapter'],
    ],
    [
        'subject' => 'Preserve action intent across all Dispatch title rewrites',
        'body' => 'Safe replacements now retain the original action when a technical title becomes a concise reader-facing phrase.',
        'tag' => 'refactor',
        'contains' => ['development updates', 'original purpose'],
        'evidence' => ['reader safe dictionary'],
        'forbidden' => ['record around action intent', 'made action intent'],
    ],
];

foreach ($cases as $case) {
    $result = pw_dispatch_end_user_draft($case['subject'], $case['body'], $case['tag']);
    $draft = $result['draft'];
    if (isset($case['expected']) && $draft !== $case['expected']) {
        fwrite(STDERR, "Unexpected world-release draft:\n" . $draft . "\n");
        exit(1);
    }
    foreach ($case['forbidden'] ?? [] as $fragment) {
        if (stripos($draft, $fragment) !== false) {
            fwrite(STDERR, "Action leaked into reader-facing object: " . $draft . "\n");
            exit(1);
        }
    }
    foreach ($case['contains'] ?? [] as $fragment) {
        if (stripos($draft, $fragment) === false) {
            fwrite(STDERR, "Expected reader-facing context is missing: " . $draft . "\n");
            exit(1);
        }
    }
    foreach ($case['evidence'] ?? [] as $label) {
        if (!in_array($label, $result['confidence']['evidence'] ?? [], true)) {
            fwrite(STDERR, "Expected confidence evidence is missing: " . $label . "\n");
            exit(1);
        }
    }
}

echo "Dispatch translator regression checks passed.\n";
