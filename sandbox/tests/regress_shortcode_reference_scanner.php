<?php
declare(strict_types=1);

/**
 * DUO-3354: characterize the extracted manifest-declared shortcode scanner
 * without loading Policy, Ledger, a database, or a WordPress runtime. The
 * three shortcode parsing primitives are deliberate syntax-only test stubs;
 * the real product-path coverage remains regress_shortcode_refs.php.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/support/wp-shortcode-stub.php';
require __DIR__ . '/../../agent/src/Shortcodes.php';
require __DIR__ . '/../../agent/src/Pending.php';
require __DIR__ . '/../../agent/src/LintFinding.php';
require __DIR__ . '/../../agent/src/ShortcodeReferenceScanner.php';

use Duo\ShortcodeReferenceScanner;

check(!class_exists(\Duo\Policy::class, false), 'shortcode scanner standalone load does not load Policy');
check(!class_exists(\Duo\Ledger::class, false), 'shortcode scanner standalone load does not load Ledger');
check(!function_exists('get_option'), 'shortcode scanner standalone load does not need WordPress runtime helpers');

$rules = [
    'gallery' => [
        ['kind' => 'post', 'path' => 'id'],
        ['cast' => 'csv', 'kind' => 'post', 'path' => 'ids'],
    ],
    'contact-form' => [
        ['kind' => 'post', 'lookup' => ['post_meta' => '_old_cf7_unit_id'], 'position' => 0],
    ],
];
$match = ['kind' => 'post', 'id' => 7, 'title' => 'Known post', 'post_type' => 'post'];
$resolve = static fn(int $id): ?array => $id === 7 ? $match : null;

$findings = ShortcodeReferenceScanner::scan(
    '[gallery id="7" ids="8,9" user_id="7"] [contact-form 77 "Legacy Form"] [[gallery id="7"]] [caption id="7"]',
    $rules,
    'posts/post/example.md',
    'meta.builder.content',
    $resolve
);

check(
    array_map(static fn(array $finding): array => [
        $finding['class'], $finding['locator'], $finding['value'], $finding['matches']['id'] ?? null,
    ], $findings) === [
        ['unrewritten_registered_shortcode_ref', 'meta.builder.content.shortcode.gallery.attrs.id', 7, 7],
        ['unrewritten_registered_shortcode_ref', 'meta.builder.content.shortcode.gallery.attrs.ids[csv:0]', 8, null],
        ['unrewritten_registered_shortcode_ref', 'meta.builder.content.shortcode.gallery.attrs.ids[csv:1]', 9, null],
        ['unregistered_shortcode_attr', 'meta.builder.content.shortcode.gallery.attrs.user_id', 7, 7],
        ['unrewritten_registered_shortcode_ref', 'meta.builder.content.shortcode.contact-form.positional[0]', 77, null],
    ],
    'scanner preserves declared/unregistered classes, locator spelling, csv ordering, resolver shape, positional rules, escaped-shortcode skipping, and declared-tag scope'
);

$lintSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Lint.php');
check(str_contains($lintSource, "require_once __DIR__ . '/ShortcodeReferenceScanner.php';"), 'Lint requires the extracted shortcode scanner');
check(str_contains($lintSource, 'ShortcodeReferenceScanner::scan('), 'Lint delegates shortcode findings to the extracted scanner');

fwrite(STDOUT, "ALL PASSED\n");
