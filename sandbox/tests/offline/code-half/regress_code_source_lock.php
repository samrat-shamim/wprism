<?php
/**
 * Offline grammar characterization for `wprism-code-lock/v2` (issue #3499, then the
 * no-third-party-bytes invariant).
 *
 * The lock is the only wire the code-half split adds, and everything
 * downstream — the compile gate, `wprism init`, `wprism code-classify`, and the
 * resolver — trusts it to have already refused a malformed declaration by
 * name. This suite pins each refusal to the fact it names, pins the v2 shape
 * (`components` Git does not carry + `first_party` it carries by declaration),
 * pins that the issue #3499 `vendored-archive` origin is refused BY NAME with its
 * remedy and that a legacy v1 document still parses, and pins the two
 * identities that would otherwise drift silently: the payload source prefix
 * CodeSourceLock spells as a literal, and the tree digest algorithm it shares
 * with CodeDescriptorCompiler's per-file rows.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/PathSafety.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeDescriptorCompiler.php';

use WPrism\Canon;
use WPrism\CodeDescriptorCompiler;
use WPrism\CodeSourceLock;

/** @return array<string,mixed> */
function lock_entry(array $overrides = []): array {
    $entry = [
        'root' => 'plugins',
        'component' => 'woocommerce',
        'version' => '11.0.0',
        'origin' => [
            'kind' => 'wp-org-release',
            'url' => 'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
            'archive_sha256' => str_repeat('a', 64),
        ],
        'tree_sha256' => str_repeat('b', 64),
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($entry[$key]);
            continue;
        }
        $entry[$key] = $value;
    }
    return $entry;
}

/** @param list<array<string,mixed>> $components @param list<string> $firstParty @return array<string,mixed> */
function lock_of(array $components, array $firstParty = []): array {
    return ['format' => CodeSourceLock::FORMAT, 'components' => $components, 'first_party' => $firstParty];
}

/** The issue #3499 shape: components only. @param list<array<string,mixed>> $components @return array<string,mixed> */
function legacy_lock_of(array $components): array {
    return ['format' => CodeSourceLock::LEGACY_FORMAT, 'components' => $components];
}

/** @return array<string,mixed> */
function imported_origin(array $overrides = []): array {
    return array_merge(['kind' => 'imported-archive', 'archive_sha256' => str_repeat('c', 64)], $overrides);
}

function refuses(array $lock, string $needle, string $message): void {
    wprism_check_throws(
        static fn() => CodeSourceLock::assert_lock($lock),
        RuntimeException::class,
        $message,
        $needle
    );
}

// ---------------------------------------------------------------------------
// The two identities that must not drift.
// ---------------------------------------------------------------------------

wprism_check_same(
    CodeDescriptorCompiler::SOURCE,
    CodeSourceLock::SOURCE,
    'CodeSourceLock::SOURCE is the descriptor compiler payload prefix, spelled out to keep the grammar loadable alone'
);
wprism_check_same('wprism-code-lock/v2', CodeSourceLock::FORMAT, 'the lock wire format is wprism-code-lock/v2');
wprism_check_same('wprism-code-lock/v1', CodeSourceLock::LEGACY_FORMAT, 'the issue #3499 format is still named, as the legacy it reads');
wprism_check_same('code/wprism-code.lock.json', CodeSourceLock::PATH, 'exactly one lock path is legal');
wprism_check_same(['plugins', 'themes'], CodeSourceLock::ROOTS, 'only plugins/ and themes/ components are lockable');
wprism_check_same(['imported-archive', 'wp-org-release'], CodeSourceLock::KINDS, 'two origin kinds: a wp.org release, or an archive imported on the host');
wprism_check_same('vendored-archive', CodeSourceLock::REMOVED_KIND, 'the removed kind is named, so its refusal can carry the remedy');

// ---------------------------------------------------------------------------
// Accepted shapes.
// ---------------------------------------------------------------------------

CodeSourceLock::assert_lock(lock_of([lock_entry()]));
wprism_check(true, 'a minimal wp-org-release entry is accepted');

CodeSourceLock::assert_lock(lock_of([
    lock_entry(['origin' => imported_origin(['archive_root' => 'acme-premium-1.4.2']), 'component' => 'acme-premium']),
]));
wprism_check(true, 'an imported-archive entry with an archive_root is accepted');
CodeSourceLock::assert_lock(lock_of([lock_entry(['origin' => imported_origin(), 'component' => 'acme-premium'])]));
wprism_check(true, 'an imported-archive entry is identified by archive_sha256 alone: no url, no path');

CodeSourceLock::assert_lock(lock_of([], ['plugins/wprism-agency', 'themes/agency-child']));
wprism_check(true, 'a lock with no locked component and only first-party declarations is accepted: the declaration is the point');
CodeSourceLock::assert_lock(lock_of([lock_entry()], ['plugins/wprism-agency']));
wprism_check(true, 'locked components and first-party declarations coexist');

CodeSourceLock::assert_lock(legacy_lock_of([lock_entry()]));
wprism_check(true, 'a legacy wprism-code-lock/v1 document (components only) still parses');
wprism_check_same([], CodeSourceLock::first_party(legacy_lock_of([lock_entry()])), 'a legacy v1 lock declares no first-party component');
wprism_check_same(
    ['plugins/wprism-agency' => true],
    CodeSourceLock::first_party(lock_of([], ['plugins/wprism-agency'])),
    'first_party() indexes the declarations by identity'
);
wprism_check(CodeSourceLock::is_identity('plugins/woocommerce') && !CodeSourceLock::is_identity('mu-plugins/wprism')
    && !CodeSourceLock::is_identity('plugins/../x') && !CodeSourceLock::is_identity('woocommerce'),
    'is_identity() admits exactly <lockable root>/<safe component>');

CodeSourceLock::assert_lock(lock_of([
    lock_entry(['root' => 'plugins', 'component' => 'akismet']),
    lock_entry(['root' => 'plugins', 'component' => 'woocommerce']),
    lock_entry(['root' => 'themes', 'component' => 'storefront']),
]));
wprism_check(true, 'entries sorted by root then component are accepted');

// ---------------------------------------------------------------------------
// Every malformed shape, refused by the fact it names.
// ---------------------------------------------------------------------------

refuses(
    ['format' => CodeSourceLock::FORMAT, 'components' => [], 'first_party' => [], 'extra' => 1],
    'must contain exactly components, first_party, format',
    'an extra top-level key is refused'
);
refuses(
    ['format' => CodeSourceLock::FORMAT, 'components' => []],
    'must contain exactly components, first_party, format',
    'a v2 lock without first_party is refused: the declaration list is not optional'
);
refuses(
    ['format' => CodeSourceLock::LEGACY_FORMAT, 'components' => [], 'first_party' => []],
    'must contain exactly components, format',
    'a v1 lock carrying first_party is refused: the legacy shape is read exactly as written'
);
refuses(
    ['format' => 'wprism-code-lock/v3', 'components' => [], 'first_party' => []],
    'format must be "wprism-code-lock/v2" (or the legacy "wprism-code-lock/v1")',
    'an unknown lock format is refused by name'
);
refuses(
    ['format' => CodeSourceLock::FORMAT, 'components' => [], 'first_party' => 'plugins/wprism-agency'],
    'first_party must be a list',
    'a non-list first_party is refused'
);
refuses(
    lock_of([], ['mu-plugins/wprism']),
    "first_party[0] must be one '{root}/{component}' identity",
    'a first-party identity outside the lockable roots is refused'
);
refuses(
    lock_of([], ['plugins/../escape']),
    "first_party[0] must be one '{root}/{component}' identity",
    'a traversing first-party identity is refused'
);
refuses(
    lock_of([], ['plugins/wprism-agency', 'plugins/wprism-agency']),
    "first_party declares 'plugins/wprism-agency' more than once",
    'a duplicate first-party identity is refused'
);
refuses(
    lock_of([], ['themes/agency-child', 'plugins/wprism-agency']),
    'first_party is not deterministically sorted',
    'an unsorted first_party list is refused'
);
refuses(
    lock_of([lock_entry()], ['plugins/woocommerce']),
    "declares 'plugins/woocommerce' both as a locked component and as first-party",
    'one identity cannot be both carried and not carried by Git'
);
refuses(
    ['format' => CodeSourceLock::FORMAT, 'components' => ['plugins/woocommerce' => lock_entry()], 'first_party' => []],
    'components must be a list',
    'a keyed components map is refused'
);
refuses(
    lock_of([lock_entry(['version' => null])]),
    'must contain exactly component, origin, root, tree_sha256, and version',
    'a missing entry key is refused by name'
);
refuses(
    lock_of([lock_entry(['root' => 'mu-plugins'])]),
    'root must be one of plugins/themes',
    'a root outside plugins/themes is refused'
);
refuses(
    lock_of([lock_entry(['component' => '../escape'])]),
    'component must be one safe path segment',
    'a traversing component name is refused'
);
refuses(
    lock_of([lock_entry(['component' => 'nested/slug'])]),
    'component must be one safe path segment',
    'a multi-segment component name is refused'
);
refuses(
    lock_of([lock_entry(['version' => ''])]),
    'version must be a non-empty single-line string',
    'an empty version is refused'
);
refuses(
    lock_of([lock_entry(['version' => "11.0.0\n rm -rf /"])]),
    'version must be a non-empty single-line string',
    'a control character in a version is refused'
);
refuses(
    lock_of([lock_entry(['tree_sha256' => str_repeat('A', 64)])]),
    'tree_sha256 must be 64 lowercase hex characters',
    'an uppercase tree digest is refused'
);
refuses(
    lock_of([lock_entry(['tree_sha256' => str_repeat('b', 63)])]),
    'tree_sha256 must be 64 lowercase hex characters',
    'a short tree digest is refused'
);
refuses(
    lock_of([lock_entry(['origin' => ['kind' => 'composer', 'url' => 'https://example.test/x.zip', 'archive_sha256' => str_repeat('a', 64)]])]),
    'origin.kind must be one of imported-archive/wp-org-release',
    'an unknown origin kind is refused by name'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'vendored-archive',
        'path' => 'code/archives/acme-premium.1.4.2.zip',
        'archive_sha256' => str_repeat('c', 64),
    ]])]),
    "origin.kind 'vendored-archive' is no longer a lock origin: Git must not carry third-party code, archives included; "
    . 'import the archive on the host with `wprism code-import <archive.zip>` and re-lock the component with `wprism code-classify`',
    'the issue #3499 vendored-archive origin is refused by name with its remedy, in a v2 lock'
);
refuses(
    legacy_lock_of([lock_entry(['origin' => [
        'kind' => 'vendored-archive',
        'path' => 'code/archives/acme-premium.1.4.2.zip',
        'archive_sha256' => str_repeat('c', 64),
    ]])]),
    "origin.kind 'vendored-archive' is no longer a lock origin",
    'and in a legacy v1 lock: no reader resolves a ZIP committed inside the repository again'
);
refuses(
    lock_of([lock_entry(['origin' => imported_origin(['url' => 'https://vendor.example/acme.zip'])])]),
    "origin of kind 'imported-archive' must contain exactly archive_sha256, kind and may add archive_root",
    'an imported-archive origin carrying a url is refused: a vendor download link must never reach the repository'
);
refuses(
    lock_of([lock_entry(['origin' => imported_origin(['path' => 'code/archives/acme.zip'])])]),
    "origin of kind 'imported-archive' must contain exactly archive_sha256, kind and may add archive_root",
    'an imported-archive origin carrying a path is refused: the repository carries no archive bytes'
);
refuses(
    lock_of([lock_entry(['origin' => imported_origin(['archive_sha256' => 'nope'])])]),
    'origin.archive_sha256 must be 64 lowercase hex characters',
    'an imported-archive origin with a malformed digest is refused'
);
refuses(
    lock_of([lock_entry(['origin' => imported_origin(['archive_root' => '../elsewhere'])])]),
    'origin.archive_root must be a safe relative path inside the archive',
    'a traversing imported-archive archive_root is refused'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'wp-org-release',
        'url' => 'http://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
        'archive_sha256' => str_repeat('a', 64),
    ]])]),
    'origin.url must be an https:// URL with no whitespace',
    'a plaintext http origin URL is refused'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'wp-org-release',
        'url' => "https://downloads.wordpress.org/plugin/woo.zip\n--output=/etc/passwd",
        'archive_sha256' => str_repeat('a', 64),
    ]])]),
    'origin.url must be an https:// URL with no whitespace',
    'a whitespace-bearing origin URL is refused before it can become two fetcher arguments'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'wp-org-release',
        'url' => 'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
        'archive_sha256' => 'not-a-digest',
    ]])]),
    'origin.archive_sha256 must be 64 lowercase hex characters',
    'a malformed archive digest is refused'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'wp-org-release',
        'url' => 'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
        'archive_sha256' => str_repeat('a', 64),
        'archive_root' => '../elsewhere',
    ]])]),
    'origin.archive_root must be a safe relative path inside the archive',
    'a traversing archive_root is refused'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'wp-org-release',
        'url' => 'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
        'archive_sha256' => str_repeat('a', 64),
        'path' => 'code/archives/x.zip',
    ]])]),
    "origin of kind 'wp-org-release' must contain exactly",
    'a wp-org-release origin carrying a path is refused'
);
refuses(
    lock_of([lock_entry(), lock_entry()]),
    "declares component 'plugins/woocommerce' more than once",
    'a duplicate component is refused'
);
refuses(
    lock_of([
        lock_entry(['root' => 'themes', 'component' => 'storefront']),
        lock_entry(['root' => 'plugins', 'component' => 'woocommerce']),
    ]),
    'are not deterministically sorted by root then component',
    'an unsorted components list is refused'
);
refuses(
    lock_of([
        lock_entry(['component' => 'woocommerce']),
        lock_entry(['component' => 'akismet']),
    ]),
    'are not deterministically sorted by root then component',
    'components unsorted within one root are refused'
);

wprism_check_throws(
    static fn() => CodeSourceLock::parse('[1,2,3]'),
    RuntimeException::class,
    'a JSON array is not a lock',
    'code lock is not a JSON object'
);
wprism_check_throws(
    static fn() => CodeSourceLock::parse('{'),
    RuntimeException::class,
    'unparseable JSON is not a lock',
    'code lock is not a JSON object'
);

// ---------------------------------------------------------------------------
// Canonical encoding: a writer cannot emit a lock its own reader refuses.
// ---------------------------------------------------------------------------

$encoded = CodeSourceLock::encode([
    lock_entry(['root' => 'themes', 'component' => 'storefront']),
    lock_entry(['root' => 'plugins', 'component' => 'woocommerce']),
    lock_entry(['root' => 'plugins', 'component' => 'akismet']),
], ['themes/agency-child', 'plugins/wprism-agency', 'plugins/wprism-agency']);
$reparsed = CodeSourceLock::parse($encoded);
wprism_check_same(
    ['plugins/akismet', 'plugins/woocommerce', 'themes/storefront'],
    array_keys(CodeSourceLock::index($reparsed)),
    'encode() sorts entries deterministically, so an unsorted caller list still round-trips'
);
wprism_check_same(
    ['plugins/wprism-agency', 'themes/agency-child'],
    $reparsed['first_party'],
    'encode() sorts and dedupes first_party, so a writer cannot emit a declaration list its reader refuses'
);
wprism_check_same(CodeSourceLock::FORMAT, $reparsed['format'], 'encode() always writes the current format, never the legacy one');
wprism_check_same(
    ['components' => [], 'first_party' => [], 'format' => CodeSourceLock::FORMAT],
    CodeSourceLock::parse(CodeSourceLock::encode([])),
    'a lock with nothing locked and nothing declared still encodes as a complete v2 document'
);
wprism_check_same(
    $encoded,
    Canon::encode($reparsed),
    'lock bytes are canonical: re-encoding the parsed lock reproduces them exactly'
);
wprism_check(str_ends_with($encoded, "}\n"), 'canonical lock bytes end with a newline, like every other canonical artifact');

// ---------------------------------------------------------------------------
// tree_sha256: one algorithm, shared with the descriptor's per-file rows.
// ---------------------------------------------------------------------------

$root = sys_get_temp_dir() . '/wprism_regress_code_source_lock_' . bin2hex(random_bytes(6));
$source = $root . '/code/wp-content';
mkdir($source . '/plugins/woocommerce/includes', 0775, true);
mkdir($source . '/plugins/akismet', 0775, true);
mkdir($source . '/themes/storefront', 0775, true);
file_put_contents($source . '/plugins/woocommerce/woocommerce.php', "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n");
file_put_contents($source . '/plugins/woocommerce/includes/class-wc.php', "<?php\n// includes\n");
file_put_contents($source . '/plugins/akismet/akismet.php', "<?php\n/**\n * Plugin Name: Akismet\n */\n");
file_put_contents($source . '/themes/storefront/style.css', "/*\nTheme Name: Storefront\nVersion: 4.6.0\n*/\n");

$descriptor = CodeDescriptorCompiler::descriptor_from_source($source);
$rows = CodeSourceLock::component_rows($descriptor['files'], 'plugins', 'woocommerce');
wprism_check_same(
    ['includes/class-wc.php', 'woocommerce.php'],
    array_column($rows, 'path'),
    'component_rows() re-roots the descriptor rows at the component, sorted'
);
wprism_check_same(
    hash('sha256', Canon::encode($rows)),
    CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'woocommerce'),
    'tree_sha256 is sha256 over the canonical sorted {path,sha256} rows of the component subtree'
);
wprism_check_same(
    null,
    CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'absent-plugin'),
    'a component the descriptor owns no bytes for has no tree digest — the compile gate reads that as unresolved'
);
wprism_check(
    CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'woocommerce')
        !== CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'akismet'),
    'two components with different bytes hash differently'
);

$before = CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'woocommerce');
file_put_contents($source . '/plugins/woocommerce/includes/class-wc.php', "<?php\n// includes \n");
$after = CodeSourceLock::tree_sha256_from_descriptor(
    CodeDescriptorCompiler::descriptor_from_source($source),
    'plugins',
    'woocommerce'
);
wprism_check($before !== $after, 'one changed byte inside a component moves its tree_sha256');

// A sibling component's bytes never leak into another component's digest.
$akismetBefore = CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'akismet');
$akismetAfter = CodeSourceLock::tree_sha256_from_descriptor(
    CodeDescriptorCompiler::descriptor_from_source($source),
    'plugins',
    'akismet'
);
wprism_check_same($akismetBefore, $akismetAfter, 'editing one component leaves its siblings\' tree digests untouched');

// ---------------------------------------------------------------------------
// .gitignore: the only supported placement, and the two the compiler refuses.
// ---------------------------------------------------------------------------

wprism_check_same(
    '/code/wp-content/plugins/woocommerce/',
    CodeSourceLock::gitignore_line('plugins', 'woocommerce'),
    'a locked component ignore line is root-anchored under code/wp-content'
);
wprism_check_throws(
    static fn() => CodeSourceLock::gitignore_line('mu-plugins', 'wprism'),
    RuntimeException::class,
    'an unlockable root cannot produce an ignore line',
    'unsafe component identity'
);
wprism_check_throws(
    static fn() => CodeSourceLock::gitignore_line('plugins', '../etc'),
    RuntimeException::class,
    'an unsafe component cannot produce an ignore line',
    'unsafe component identity'
);

wprism_check_same(
    ['plugins/woocommerce' => true, 'themes/storefront' => true],
    CodeSourceLock::ignored_components(implode("\n", [
        '# WPrism local publication and environment artifacts',
        '/.wprism/',
        '/code/wp-content/plugins/woocommerce/',
        '/code/wp-content/themes/storefront/',
        '',
    ])),
    'ignored_components() reads exactly the root-anchored component lines'
);
wprism_check_same(
    [],
    CodeSourceLock::ignored_components(implode("\n", [
        'code/wp-content/plugins/woocommerce/',
        '/code/wp-content/plugins/woocommerce',
        '/code/wp-content/plugins/',
        '/code/wp-content/plugins/*/',
        '/code/wp-content/mu-plugins/wprism/',
        '/code/wp-content/plugins/woocommerce/includes/',
    ])),
    'an unanchored, unterminated, glob, deeper or unlockable-root line is not a component exclusion'
);

// ---------------------------------------------------------------------------
// site.wprism.json `code`: format 2 is additive; format 1 keeps its exact bytes.
// ---------------------------------------------------------------------------

$format1 = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
$format2 = ['format' => 2, 'layout' => 'wp-content', 'lock' => CodeSourceLock::PATH, 'source' => 'code/wp-content'];

CodeDescriptorCompiler::assert_config($format1);
wprism_check(true, 'the format-1 declaration is still accepted verbatim');
CodeDescriptorCompiler::assert_config($format2);
wprism_check(true, 'the format-2 split declaration is accepted');

wprism_check_same(null, CodeDescriptorCompiler::lock_path($format1), 'format 1 declares no lock');
wprism_check_same(CodeSourceLock::PATH, CodeDescriptorCompiler::lock_path($format2), 'format 2 exposes the declared lock path');

// Rule 8: the pre-existing refusal bytes must not move for any shape that is
// not the new format. This is asserted as a WHOLE string, not a fragment.
$format1Message = 'wprism: site.wprism.json code must contain exactly {"format":1,"layout":"wp-content","source":"code/wp-content"}';
foreach ([
    'a wrong layout' => ['format' => 1, 'layout' => 'custom', 'source' => 'code/wp-content'],
    'a wrong source' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code'],
    'an unknown format integer' => ['format' => 3, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'a missing key' => ['format' => 1, 'layout' => 'wp-content'],
    'an extra key' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content', 'mode' => 'split'],
] as $label => $config) {
    $actual = null;
    try {
        CodeDescriptorCompiler::assert_config($config);
    } catch (RuntimeException $e) {
        $actual = $e->getMessage();
    }
    wprism_check_same($format1Message, $actual, "$label still refuses with the historical format-1 message, byte for byte");
}

$format2Message = 'wprism: site.wprism.json code format 2 must contain exactly '
    . '{"format":2,"layout":"wp-content","lock":"code/wprism-code.lock.json","source":"code/wp-content"}';
foreach ([
    'a second lock path' => ['format' => 2, 'layout' => 'wp-content', 'lock' => 'code/other.lock.json', 'source' => 'code/wp-content'],
    'a lock key on format 1' => ['format' => 1, 'layout' => 'wp-content', 'lock' => CodeSourceLock::PATH, 'source' => 'code/wp-content'],
    'a format-2 declaration missing its lock' => ['format' => 2, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'a format-2 declaration with an extra key' => ['format' => 2, 'layout' => 'wp-content', 'lock' => CodeSourceLock::PATH, 'source' => 'code/wp-content', 'mode' => 'split'],
] as $label => $config) {
    $actual = null;
    try {
        CodeDescriptorCompiler::assert_config($config);
    } catch (RuntimeException $e) {
        $actual = $e->getMessage();
    }
    wprism_check_same($format2Message, $actual, "$label refuses with the format-2 message");
}

// Teardown: the suite's own scratch tree, never anything it did not create.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($root);

wprism_check_summary('regress_code_source_lock');
