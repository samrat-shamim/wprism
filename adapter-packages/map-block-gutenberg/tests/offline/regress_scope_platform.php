<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Apply;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\Capture;
use WPrism\CommandRefusalException;
use WPrism\PlatformCompatibility;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;

$library = AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg');
$policy = Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: $library);
$native = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/saved-default-key.html');
// The pure codec never consults Tokens; constructing it would itself require
// WordPress path helpers and invalidate the absent-runtime premise below.
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$db = FakeWpdb::install();
$db->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'gmw-map-block-key', 'option_value' => 'map-fixture-target-key', 'autoload' => 'off']]);
$rows = $db->rows('wp_options');

// Maps is a browser iframe service, not an adapter/provider API. The actual
// shipped codec runs before any WordPress, HTTP client or native plugin loads;
// successful transport here says nothing about Google credential validity.
wprism_check(!function_exists('parse_blocks') && !function_exists('wp_remote_get') && !class_exists('wf_map_block'), 'codec qualification begins without native plugin or WordPress APIs');
$codec = $policy->interpreters()['map-block-gutenberg'];
preg_match('/<!-- wp:webfactory\/map (\{.*?\}) -->\n(.*)\n<!-- \/wp:webfactory\/map -->/s', trim($native), $parts);
if (count($parts) !== 3) throw new RuntimeException('native saved fixture framing changed');
$portable = $codec->capture_block_content(['attrs' => json_decode($parts[1], true, 32, JSON_THROW_ON_ERROR), 'innerHTML' => $parts[2]], $tokens);
$canonical = '<!-- wp:webfactory/map ' . json_encode($portable['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ' -->' . $portable['html'] . '<!-- /wp:webfactory/map -->';
wprism_check_same([], $policy->repository_constraint_diagnostics([['type' => 'post', 'body' => $canonical]]), 'WordPress-free immutable validation admits the credential-free exact saver');
$materialized = $codec->apply_block_content(['attrs' => $portable['attrs'], 'innerHTML' => $portable['html']], $tokens, ['gmw-map-block-key' => 'map-fixture-target-key']);
wprism_check_same(1, substr_count($materialized['html'], 'map-fixture-target-key'), 'pure materialization binds the iframe without contacting its service');
wprism_check_same('map-fixture-target-key', $materialized['attrs']['api_key'], 'pure materialization binds the matching attribute');
wprism_check(!str_contains($canonical, 'map-fixture-source-key'), 'service-independent capture still excludes the source credential');

// The shared body grammar, unlike immutable compilation and the capsule
// codec, requires WordPress's parser. It must throw rather than return raw
// credential-bearing content when that mandatory API is genuinely absent.
foreach (['capture', 'apply'] as $operation) {
    $output = null;
    try {
        $output = $operation === 'capture'
            ? Blocks::capture_rewrite($native, $policy, $tokens)
            : Blocks::apply_rewrite($canonical, $policy, $tokens);
        wprism_check(false, "$operation must refuse the absent parser");
    } catch (Error $failure) {
        wprism_check_same('Call to undefined function WPrism\\parse_blocks()', $failure->getMessage(), "$operation reaches the real missing-parser boundary");
    }
    wprism_check_same(null, $output, "$operation cannot publish a raw-body fallback");
    wprism_check_same($rows, $db->rows('wp_options'), "$operation missing-parser refusal preserves the target credential");
    wprism_check_same([], $db->queryLog(), "$operation missing-parser refusal performs no database work");
}
try {
    PlatformCompatibility::current_facts();
    wprism_check(false, 'an unbootstrapped runtime cannot provide platform facts');
} catch (CommandRefusalException $failure) {
    wprism_check_same('platform_probe_unavailable', $failure->reasonCode, 'missing WordPress fact API is a typed probe refusal');
    wprism_check_same('wordpress', $failure->diagnostics[0]['axis'] ?? null, 'missing WordPress API names only its unavailable axis');
}

// Conditional declarations keep the preceding absence test real. These are
// explicit target facts, not claims of running other WordPress installations.
$GLOBALS['map_scope_wordpress'] = '7.1';
$GLOBALS['map_scope_multisite'] = false;
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show): string { return $show === 'version' ? $GLOBALS['map_scope_wordpress'] : ''; }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool { return $GLOBALS['map_scope_multisite']; }
}
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Capture/Capture.php';
require_once $root . '/agent/src/Apply/Apply.php';
if (!defined('WPINC')) define('WPINC', 'wp-includes');
$db->setServerVersion('11.8.8-MariaDB');
$tokens = new Tokens('https://source.example.test', 'https://source.example.test/wp-content/uploads');
$tokens->bind_block_environment_options(['gmw-map-block-key' => 'map-fixture-target-key']);
$parsed = Blocks::capture_rewrite(trim($native), $policy, $tokens);
wprism_check_same($canonical, $parsed, 'the real parser produces the same independently validated canonical saver');
wprism_check_same($parsed, Blocks::capture_rewrite(Blocks::apply_rewrite($parsed, $policy, $tokens), $policy, $tokens), 'restoring the mandatory parser restores the credential-isolated fixed point');
$platform = $policy->adapter_platform_boundary();
$facts = PlatformCompatibility::current_facts();
PlatformCompatibility::assert_supported($platform, $facts);
wprism_check_same('env', Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: $library)->option_rule('gmw-map-block-key')['class'], 'supported target facts preserve the Map credential ownership boundary');

$scratch = sys_get_temp_dir() . '/map-scope-boundary-' . bin2hex(random_bytes(12));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode(['spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core', 'map-block-gutenberg']]));
Canon::write_file($scratch . '/state/retained-map.txt', $canonical);
$inventory = static function () use ($scratch): array {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS)) as $entry) {
        $out[substr($entry->getPathname(), strlen($scratch) + 1)] = hash_file('sha256', $entry->getPathname());
    }
    ksort($out, SORT_STRING);
    return $out;
};
$before = $inventory();
foreach ([
    'multisite' => ['7.1', true, '11.8.8-MariaDB', 'multisite_unsupported', null],
    'WordPress below minimum' => ['6.8.99', false, '11.8.8-MariaDB', 'platform_unsupported', 'platform_wordpress_version_unsupported'],
    'WordPress exclusive maximum' => ['7.2', false, '11.8.8-MariaDB', 'platform_unsupported', 'platform_wordpress_version_unsupported'],
    'MariaDB below minimum' => ['7.1', false, '10.11.0-MariaDB', 'platform_unsupported', 'platform_database_version_unsupported'],
    'MariaDB exclusive maximum' => ['7.1', false, '12.0.0-MariaDB', 'platform_unsupported', 'platform_database_version_unsupported'],
    'MySQL below minimum' => ['7.1', false, '8.3.99', 'platform_unsupported', 'platform_database_version_unsupported'],
    'MySQL exclusive maximum' => ['7.1', false, '8.5.0', 'platform_unsupported', 'platform_database_version_unsupported'],
    'unavailable WordPress fact' => ['', false, '11.8.8-MariaDB', 'platform_probe_unavailable', 'platform_probe_unavailable'],
    'unavailable database fact' => ['7.1', false, '11.8.8-MariaDB', 'platform_probe_unavailable', 'platform_probe_unavailable'],
] as $label => [$wordpress, $multisite, $database, $reason, $diagnostic]) {
    $GLOBALS['map_scope_wordpress'] = $wordpress;
    $GLOBALS['map_scope_multisite'] = $multisite;
    $db->setServerVersion($database);
    $db->onQuery($label === 'unavailable database fact' ? static fn(string $sql): ?string => $sql === 'SELECT VERSION()' ? 'private-map-platform-probe' : null : null);
    foreach (['capture', 'apply'] as $operation) {
        $db->resetLog();
        try {
            if ($operation === 'capture') Capture::run($scratch, adapterLibrary: $library);
            else Apply::apply($scratch, ['adapter_library' => $library]);
            wprism_check(false, "$label $operation must refuse");
        } catch (CommandRefusalException $failure) {
            wprism_check_same($reason, $failure->reasonCode, "$label $operation reaches the platform/topology gate");
            wprism_check_same($diagnostic === null ? [] : [$diagnostic], array_column($failure->diagnostics, 'code'), "$label $operation reports only the expected axis");
            wprism_check(!$failure->detailsRedacted && !str_contains(Canon::encode($failure->payload()), 'private-map-platform-probe'), "$label $operation public refusal contains no private driver detail");
        }
        wprism_check_same($multisite ? [] : ['SELECT VERSION()'], $db->queries(), "$label $operation precedes every content/ledger query");
        wprism_check_same($rows, $db->rows('wp_options'), "$label $operation preserves the target-local key");
        wprism_check_same($before, $inventory(), "$label $operation preserves every repository file and creates no publication sidecar");
    }
}
$db->onQuery(null);
$GLOBALS['map_scope_wordpress'] = '7.1';
$GLOBALS['map_scope_multisite'] = false;
$db->setServerVersion('11.8.8-MariaDB');

// PHP/OS/function availability cannot be reassigned in-process. Inject facts
// only at the product's explicit platform-fact boundary, without claiming a
// live platform matrix or substituting a fake gate for Capture/Apply above.
foreach ([
    ['php', '8.2.99', 'platform_php_version_unsupported'],
    ['php', '8.5.0', 'platform_php_version_unsupported'],
    ['filesystem.os_family', 'Windows', 'platform_filesystem_os_unsupported'],
    ['filesystem.functions.fsync', false, 'platform_filesystem_function_unsupported'],
    ['process.functions.proc_open', false, 'platform_process_function_unsupported'],
    ['process.shell.executable', false, 'platform_process_shell_unavailable'],
    ['process.wp_cli_opcache_enabled', true, 'platform_process_cli_opcache_unsupported'],
] as [$axis, $value, $reason]) {
    $candidate = $facts;
    $leaf = &$candidate;
    foreach (explode('.', $axis) as $key) $leaf = &$leaf[$key];
    $leaf = $value;
    unset($leaf);
    try {
        PlatformCompatibility::assert_supported($platform, $candidate);
        wprism_check(false, "$axis must refuse unsupported facts");
    } catch (CommandRefusalException $failure) {
        wprism_check_same('platform_unsupported', $failure->reasonCode, "$axis uses the product platform refusal");
        wprism_check_same([$reason], array_column($failure->diagnostics, 'code'), "$axis identifies the exact missing prerequisite");
    }
}
wprism_check_summary('map-block-gutenberg scope/platform boundary');
