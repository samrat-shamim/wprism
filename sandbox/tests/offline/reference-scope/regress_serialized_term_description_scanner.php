<?php
declare(strict_types=1);

/**
 * issue #3354: characterize the pure serialized term-description detector.
 * Product-path lint smoke still verifies the term-file facade and taxonomy
 * declaration gate; this pin keeps parser/result semantics registry-ready.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../../../agent/src/Review/Pending.php';
require __DIR__ . '/../../../../agent/src/Review/LintFinding.php';
require __DIR__ . '/../../../../agent/src/Review/SerializedTermDescriptionScanner.php';

use WPrism\SerializedTermDescriptionScanner;

check(!class_exists(\WPrism\Policy::class, false), 'serialized-description scanner standalone load does not load Policy');
check(!class_exists(\WPrism\Ledger::class, false), 'serialized-description scanner standalone load does not load Ledger');
check(!function_exists('get_option'), 'serialized-description scanner standalone load does not need WordPress runtime helpers');

$match = ['kind' => 'post', 'id' => 7, 'title' => 'Known post', 'post_type' => 'post'];
$resolve = static fn(int $id): ?array => $id === 7 ? $match : null;
$expectedNote = 'this term\'s description unserializes to PHP data containing an integer that matches an existing '
    . 'post id (#7 "Known post", post); taxonomy \'category\' has no \'description_refs\' declaration, so '
    . 'nothing rewrites this term\'s description (Capture tokenize_text()\'s it as an opaque string) and '
    . 'this id is silently environment-bound — a serialized map of entity ids stored in a term\'s description, '
    . 'before a description_refs declaration covers it.';
$findings = SerializedTermDescriptionScanner::scan(
    'a:4:{s:2:"en";i:7;s:2:"fr";s:1:"8";s:2:"de";s:3:"7.5";s:2:"it";s:3:"007";}',
    'category',
    'terms/category/example.json',
    $resolve
);

check(
    array_map(static fn(array $finding): array => [
        $finding['class'], $finding['locator'], $finding['value'], $finding['matches']['id'] ?? null, $finding['note'],
    ], $findings) === [
        ['serialized_desc_ids', 'description[en]', 7, 7, $expectedNote],
        ['serialized_desc_ids', 'description[it]', 7, 7, $expectedNote],
    ],
    'scanner preserves serialized map insertion order, scalar integer coercion, locator spelling, resolver shape, exact public note bytes, unresolved exclusion, and decimal exclusion'
);
check(
    SerializedTermDescriptionScanner::scan('b:0;', 'category', 'terms/category/example.json', $resolve) === [],
    'serialized false remains a valid scalar with no numeric finding'
);
check(
    SerializedTermDescriptionScanner::scan('not serialized', 'category', 'terms/category/example.json', $resolve) === [],
    'unserialized text is ignored without a warning or exception'
);

$lintSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Review/Lint.php');
check(str_contains($lintSource, "require_once __DIR__ . '/SerializedTermDescriptionScanner.php';"), 'Lint requires the extracted serialized-description scanner');
check(str_contains($lintSource, 'SerializedTermDescriptionScanner::scan('), 'Lint delegates serialized-description findings to the extracted scanner');

fwrite(STDOUT, "ALL PASSED\n");
