<?php
declare(strict_types=1);

/**
 * DUO-3354: characterize the extracted menu-item scanner without Policy,
 * Ledger, a database, or WordPress. The live/product lint path remains
 * covered by the menu lint suites; this direct pin guards registry-ready
 * traversal, policy gates, resolver injection, and historical finding order.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../agent/src/MenuReferenceScanner.php';

use Duo\MenuReferenceScanner;

check(!class_exists(\Duo\Policy::class, false), 'menu scanner standalone load does not load Policy');
check(!class_exists(\Duo\Ledger::class, false), 'menu scanner standalone load does not load Ledger');
check(!function_exists('get_option'), 'menu scanner standalone load does not need WordPress runtime helpers');

$home = 'https://example.test';
$homeEscaped = str_replace('/', '\/', $home);
$match = ['kind' => 'post', 'id' => 7, 'title' => 'Known post', 'post_type' => 'post'];
$resolve = static fn(int $id): ?array => $id === 7 ? $match : null;
$rules = [
    'declared_ref' => ['ref' => 'post'],
    'lint_ok' => ['lint_ok' => true],
    'structured' => [
        'json_refs' => [['kind' => 'post', 'path' => '$.owner']],
    ],
    'keyed' => [
        'key_refs' => ['kind' => 'post', 'path' => '$.map'],
    ],
];
$metaRuleForPost = static fn(string $key, array $_meta): ?array => $rules[$key] ?? null;
$rel = 'menus/main.json';

$findings = MenuReferenceScanner::scan([
    [
        'type' => 'post_type',
        'ref' => 7,
        'meta' => [
            'plain' => 7,
            'declared_ref' => 7,
            'lint_ok' => 7,
            'structured' => [
                'owner' => 7,
                'nested' => ['ref' => 7],
            ],
            'keyed' => ['map' => [7 => 'token?']],
            'escaped' => ['url' => $homeEscaped . '/inside'],
        ],
    ],
    [
        'type' => 'taxonomy',
        'ref' => [7, 8],
        'meta' => [],
    ],
    [
        'type' => 'custom',
        'ref' => $homeEscaped . '/custom',
    ],
    'malformed item',
], $rel, $metaRuleForPost, $home, $homeEscaped, $resolve);

check(
    array_map(static fn(array $finding): array => [
        $finding['class'],
        $finding['locator'],
        $finding['value'],
        $finding['matches']['id'] ?? null,
    ], $findings) === [
        ['unrewritten_registered_ref', 'items[0].ref', 7, 7],
        ['bare_id', 'items[0].meta.plain', 7, 7],
        ['unrewritten_registered_ref', 'items[0].meta.structured.owner', 7, 7],
        ['bare_id', 'items[0].meta.structured.nested.ref', 7, 7],
        ['unrewritten_registered_ref', 'items[0].meta.keyed.map KEY 7', 7, 7],
        ['escaped_home', 'items[0].meta.escaped.url', $homeEscaped . '/inside', null],
        ['unrewritten_registered_ref', 'items[1].ref[0]', 7, 7],
        ['unrewritten_registered_ref', 'items[1].ref[1]', 8, null],
        ['escaped_home', 'items[2].ref', $homeEscaped . '/custom', null],
    ],
    'scanner preserves schema ref classes, policy gates, structured paths, custom URL handling, malformed-item skip, resolver shape, and historical order'
);

$lintSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Lint.php');
check(str_contains($lintSource, "require_once __DIR__ . '/MenuReferenceScanner.php';"), 'Lint requires the extracted menu scanner');
check(str_contains($lintSource, 'MenuReferenceScanner::scan('), 'Lint delegates menu findings to the extracted scanner');

fwrite(STDOUT, "ALL PASSED\n");
