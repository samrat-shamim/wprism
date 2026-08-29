<?php
declare(strict_types=1);

/**
 * issue #3354: characterize the extracted generic parsed-block scanner without
 * Policy, Ledger, a database, or WordPress parsing. Product-path coverage
 * remains regress_block_refs.php; this direct pin guards scanner registry
 * extraction from changing order, locator spelling, or declared-rule gates.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../../../agent/src/Review/Pending.php';
require __DIR__ . '/../../../../agent/src/Review/LintFinding.php';
require __DIR__ . '/../../../../agent/src/Review/BlockReferenceScanner.php';

use WPrism\BlockReferenceScanner;

check(!class_exists(\WPrism\Policy::class, false), 'block scanner standalone load does not load Policy');
check(!class_exists(\WPrism\Ledger::class, false), 'block scanner standalone load does not load Ledger');
check(!function_exists('get_option'), 'block scanner standalone load does not need WordPress runtime helpers');

$home = 'https://example.test';
$longHome = $home . '/' . str_repeat('x', 205);
$rules = [
    'core/image' => [
        ['kind' => 'post', 'path' => 'id'],
        ['lint_ok' => true, 'path' => 'queryId'],
        ['tokenize' => 'text', 'path' => 'opaque'],
        ['tokenize' => 'text', 'path' => 'tracks'],
    ],
    'core/gallery' => [
        ['cast' => 'csv', 'kind' => 'post', 'path' => 'ids'],
    ],
    'core/legacy-widget' => [
        ['path' => 'id', 'unsupported' => 'widget ids are environment-local'],
        ['path' => 'idBase', 'unsupported' => 'embedded widget ids are environment-local'],
        ['path' => 'instance', 'unsupported' => 'embedded widget settings are environment-bound'],
    ],
];
$match = ['kind' => 'post', 'id' => 7, 'title' => 'Known post', 'post_type' => 'post'];
$resolve = static fn(int $id): ?array => $id === 7 ? $match : null;

$findings = BlockReferenceScanner::scan([
    [
        'blockName' => 'core/image',
        'attrs' => [
            'id' => 7,
            'formID' => 8,
            'queryId' => 9,
            'opaque' => 10,
            'tracks' => [[
                'src' => $home . '/captions.vtt',
                'label' => 'বাংলা',
            ]],
            'source' => $longHome,
            'grid' => 11,
        ],
        'innerBlocks' => [[
            'blockName' => 'core/gallery',
            'attrs' => [
                'ids' => ['{{post:01980000-0001-7000-8000-000000000001}}', 12],
                'href' => $home . '/inside',
            ],
            'innerBlocks' => [],
        ]],
    ],
    [
        'blockName' => 'acme/widget',
        'attrs' => ['ref' => 13],
        'innerBlocks' => [],
    ],
    [
        'blockName' => 'core/legacy-widget',
        'attrs' => ['id' => 'text-2'],
        'innerBlocks' => [],
    ],
    [
        'blockName' => 'core/legacy-widget',
        'attrs' => [
            'idBase' => 'text',
            'instance' => ['encoded' => 'opaque', 'hash' => 'environment-bound'],
        ],
        'innerBlocks' => [],
    ],
], $rules, 'posts/post/example.md', $home, $resolve);

check(
    array_map(static fn(array $finding): array => [
        $finding['class'],
        $finding['locator'],
        $finding['value'],
        $finding['matches']['id'] ?? null,
    ], $findings) === [
        ['unrewritten_registered_ref', 'blocks.core/image.attrs.id', 7, 7],
        ['unregistered_block_attr', 'blocks.core/image.attrs.formID', 8, null],
        ['unrewritten_registered_text', 'blocks.core/image.attrs.tracks[0][src]', $home . '/captions.vtt', null],
        ['unregistered_block_attr', 'blocks.core/image.attrs.source', substr($longHome, 0, 200) . '…(truncated)', null],
        ['unrewritten_registered_ref', 'blocks.core/gallery.attrs.ids[1]', 12, null],
        ['unregistered_block_attr', 'blocks.core/gallery.attrs.href', $home . '/inside', null],
        ['unregistered_block_attr', 'blocks.acme/widget.attrs.ref', 13, null],
        ['unsupported_block_attr', 'blocks.core/legacy-widget.attrs.id', 'text-2', null],
        ['unsupported_block_attr', 'blocks.core/legacy-widget.attrs.idBase', 'text', null],
        ['unsupported_block_attr', 'blocks.core/legacy-widget.attrs.instance', '<structured-array>', null],
    ],
    'scanner preserves declared/unregistered gates, resolver shape, attr and inner-block order, csv/nested locators, URL leak truncation, unsupported structured values, lint_ok exemptions, and safe id-key heuristic'
);

$lintSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Review/Lint.php');
check(str_contains($lintSource, "require_once __DIR__ . '/BlockReferenceScanner.php';"), 'Lint requires the extracted block scanner');
check(str_contains($lintSource, 'BlockReferenceScanner::scan('), 'Lint delegates block findings to the extracted scanner');

fwrite(STDOUT, "ALL PASSED\n");
