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
];

foreach ($cases as $case) {
    $draft = pw_dispatch_end_user_draft($case['subject'], $case['body'], $case['tag'])['draft'];
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
}

echo "Dispatch translator regression checks passed.\n";
