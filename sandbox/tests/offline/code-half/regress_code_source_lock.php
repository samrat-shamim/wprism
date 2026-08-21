<?php
/**
 * Offline grammar characterization for `duo-code-lock/v1` (DUO-3499).
 *
 * The lock is the only wire the code-half split adds, and everything
 * downstream — the compile gate, `duo init --code=split`, `duo code-classify`,
 * and DUO-3500's resolver — trusts it to have already refused a malformed
 * declaration by name. This suite pins each refusal to the fact it names, and
 * pins the two identities that would otherwise drift silently: the payload
 * source prefix CodeSourceLock spells as a literal, and the tree digest
 * algorithm it shares with CodeDescriptorCompiler's per-file rows.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/PathSafety.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeDescriptorCompiler.php';

use Duo\Canon;
use Duo\CodeDescriptorCompiler;
use Duo\CodeSourceLock;

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

/** @param list<array<string,mixed>> $components @return array<string,mixed> */
function lock_of(array $components): array {
    return ['format' => CodeSourceLock::FORMAT, 'components' => $components];
}

function refuses(array $lock, string $needle, string $message): void {
    duo_check_throws(
        static fn() => CodeSourceLock::assert_lock($lock),
        RuntimeException::class,
        $message,
        $needle
    );
}

// ---------------------------------------------------------------------------
// The two identities that must not drift.
// ---------------------------------------------------------------------------

duo_check_same(
    CodeDescriptorCompiler::SOURCE,
    CodeSourceLock::SOURCE,
    'CodeSourceLock::SOURCE is the descriptor compiler payload prefix, spelled out to keep the grammar loadable alone'
);
duo_check_same('duo-code-lock/v1', CodeSourceLock::FORMAT, 'the lock wire format is duo-code-lock/v1');
duo_check_same('code/duo-code.lock.json', CodeSourceLock::PATH, 'v1 accepts exactly one lock path');
duo_check_same(['plugins', 'themes'], CodeSourceLock::ROOTS, 'only plugins/ and themes/ components are lockable');

// ---------------------------------------------------------------------------
// Accepted shapes.
// ---------------------------------------------------------------------------

CodeSourceLock::assert_lock(lock_of([lock_entry()]));
duo_check(true, 'a minimal wp-org-release entry is accepted');

CodeSourceLock::assert_lock(lock_of([
    lock_entry([
        'origin' => [
            'kind' => 'vendored-archive',
            'path' => 'code/archives/acme-premium.1.4.2.zip',
            'archive_sha256' => str_repeat('c', 64),
            'archive_root' => 'acme-premium',
        ],
        'component' => 'acme-premium',
    ]),
]));
duo_check(true, 'a vendored-archive entry with an archive_root is accepted');

CodeSourceLock::assert_lock(lock_of([
    lock_entry(['root' => 'plugins', 'component' => 'akismet']),
    lock_entry(['root' => 'plugins', 'component' => 'woocommerce']),
    lock_entry(['root' => 'themes', 'component' => 'storefront']),
]));
duo_check(true, 'entries sorted by root then component are accepted');

// ---------------------------------------------------------------------------
// Every malformed shape, refused by the fact it names.
// ---------------------------------------------------------------------------

refuses(
    ['format' => CodeSourceLock::FORMAT, 'components' => [], 'extra' => 1],
    'must contain exactly format and components',
    'an extra top-level key is refused'
);
refuses(
    ['format' => 'duo-code-lock/v2', 'components' => []],
    'format must be "duo-code-lock/v1"',
    'an unknown lock format is refused by name'
);
refuses(
    ['format' => CodeSourceLock::FORMAT, 'components' => ['plugins/woocommerce' => lock_entry()]],
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
    'origin.kind must be one of vendored-archive/wp-org-release',
    'an unknown origin kind is refused by name'
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
        'kind' => 'vendored-archive',
        'path' => '../../outside-the-repo.zip',
        'archive_sha256' => str_repeat('c', 64),
    ]])]),
    'cannot escape the repository',
    'a vendored-archive path that escapes the repository is refused'
);
refuses(
    lock_of([lock_entry(['origin' => [
        'kind' => 'vendored-archive',
        'path' => '/etc/passwd.zip',
        'archive_sha256' => str_repeat('c', 64),
    ]])]),
    'cannot escape the repository',
    'an absolute vendored-archive path is refused'
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
    'a wp-org-release origin carrying a vendored path is refused'
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

duo_check_throws(
    static fn() => CodeSourceLock::parse('[1,2,3]'),
    RuntimeException::class,
    'a JSON array is not a lock',
    'code lock is not a JSON object'
);
duo_check_throws(
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
]);
$reparsed = CodeSourceLock::parse($encoded);
duo_check_same(
    ['plugins/akismet', 'plugins/woocommerce', 'themes/storefront'],
    array_keys(CodeSourceLock::index($reparsed)),
    'encode() sorts entries deterministically, so an unsorted caller list still round-trips'
);
duo_check_same(
    $encoded,
    Canon::encode($reparsed),
    'lock bytes are canonical: re-encoding the parsed lock reproduces them exactly'
);
duo_check(str_ends_with($encoded, "}\n"), 'canonical lock bytes end with a newline, like every other canonical artifact');

// ---------------------------------------------------------------------------
// tree_sha256: one algorithm, shared with the descriptor's per-file rows.
// ---------------------------------------------------------------------------

$root = sys_get_temp_dir() . '/duo_regress_code_source_lock_' . bin2hex(random_bytes(6));
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
duo_check_same(
    ['includes/class-wc.php', 'woocommerce.php'],
    array_column($rows, 'path'),
    'component_rows() re-roots the descriptor rows at the component, sorted'
);
duo_check_same(
    hash('sha256', Canon::encode($rows)),
    CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'woocommerce'),
    'tree_sha256 is sha256 over the canonical sorted {path,sha256} rows of the component subtree'
);
duo_check_same(
    null,
    CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'absent-plugin'),
    'a component the descriptor owns no bytes for has no tree digest — the compile gate reads that as unresolved'
);
duo_check(
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
duo_check($before !== $after, 'one changed byte inside a component moves its tree_sha256');

// A sibling component's bytes never leak into another component's digest.
$akismetBefore = CodeSourceLock::tree_sha256_from_descriptor($descriptor, 'plugins', 'akismet');
$akismetAfter = CodeSourceLock::tree_sha256_from_descriptor(
    CodeDescriptorCompiler::descriptor_from_source($source),
    'plugins',
    'akismet'
);
duo_check_same($akismetBefore, $akismetAfter, 'editing one component leaves its siblings\' tree digests untouched');

// ---------------------------------------------------------------------------
// .gitignore: the only supported placement, and the two the compiler refuses.
// ---------------------------------------------------------------------------

duo_check_same(
    '/code/wp-content/plugins/woocommerce/',
    CodeSourceLock::gitignore_line('plugins', 'woocommerce'),
    'a locked component ignore line is root-anchored under code/wp-content'
);
duo_check_throws(
    static fn() => CodeSourceLock::gitignore_line('mu-plugins', 'duo'),
    RuntimeException::class,
    'an unlockable root cannot produce an ignore line',
    'unsafe component identity'
);
duo_check_throws(
    static fn() => CodeSourceLock::gitignore_line('plugins', '../etc'),
    RuntimeException::class,
    'an unsafe component cannot produce an ignore line',
    'unsafe component identity'
);

duo_check_same(
    ['plugins/woocommerce' => true, 'themes/storefront' => true],
    CodeSourceLock::ignored_components(implode("\n", [
        '# Duo local publication and environment artifacts',
        '/.duo/',
        '/code/wp-content/plugins/woocommerce/',
        '/code/wp-content/themes/storefront/',
        '',
    ])),
    'ignored_components() reads exactly the root-anchored component lines'
);
duo_check_same(
    [],
    CodeSourceLock::ignored_components(implode("\n", [
        'code/wp-content/plugins/woocommerce/',
        '/code/wp-content/plugins/woocommerce',
        '/code/wp-content/plugins/',
        '/code/wp-content/plugins/*/',
        '/code/wp-content/mu-plugins/duo/',
        '/code/wp-content/plugins/woocommerce/includes/',
    ])),
    'an unanchored, unterminated, glob, deeper or unlockable-root line is not a component exclusion'
);

// ---------------------------------------------------------------------------
// site.duo.json `code`: format 2 is additive; format 1 keeps its exact bytes.
// ---------------------------------------------------------------------------

$format1 = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
$format2 = ['format' => 2, 'layout' => 'wp-content', 'lock' => CodeSourceLock::PATH, 'source' => 'code/wp-content'];

CodeDescriptorCompiler::assert_config($format1);
duo_check(true, 'the format-1 declaration is still accepted verbatim');
CodeDescriptorCompiler::assert_config($format2);
duo_check(true, 'the format-2 split declaration is accepted');

duo_check_same(null, CodeDescriptorCompiler::lock_path($format1), 'format 1 declares no lock');
duo_check_same(CodeSourceLock::PATH, CodeDescriptorCompiler::lock_path($format2), 'format 2 exposes the declared lock path');

// Rule 8: the pre-existing refusal bytes must not move for any shape that is
// not the new format. This is asserted as a WHOLE string, not a fragment.
$format1Message = 'duo: site.duo.json code must contain exactly {"format":1,"layout":"wp-content","source":"code/wp-content"}';
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
    duo_check_same($format1Message, $actual, "$label still refuses with the historical format-1 message, byte for byte");
}

$format2Message = 'duo: site.duo.json code format 2 must contain exactly '
    . '{"format":2,"layout":"wp-content","lock":"code/duo-code.lock.json","source":"code/wp-content"}';
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
    duo_check_same($format2Message, $actual, "$label refuses with the format-2 message");
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

duo_check_summary('regress_code_source_lock');
