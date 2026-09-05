<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';

use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;
use WPrismTest\WpStore;

$root = dirname(__DIR__, 4);
$sourceRoot = $argv[1] ?? $root;
$source = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/tests/certify/version-matrix.sh');
$start = strpos($source, 'rank_math_native_state_hash() {');
$end = $start === false ? false : strpos($source, 'rank_math_module_state() {', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Rank Math matrix lost its native-state observation boundary');
}
$preamble = <<<'SH'
set -euo pipefail
wp1() {
  [ "$#" -eq 2 ] && [ "$1" = eval ] || return 81
  printf '%s' "$2"
}
SH;
[$status, $program, $diagnostic] = ShellProbe::run(
    $preamble . "\n" . substr($source, $start, $end - $start) . "\nrank_math_native_state_hash wp1\n",
    [],
    $root
);
wprism_check($status === 0 && $diagnostic === '' && str_contains($program, 'global $wpdb;'),
    'the actual matrix shell function supplies its PHP observation without quote damage');

/** Native fixture rows, not pre-transcribed answers to the observation SQL. */
function rank_observation_database(bool $present = true, bool $aliasFirst = false): FakeWpdb {
    WpStore::reset()->seedOptions(['active_plugins' => ['seo-by-rank-math/rank-math.php']]);
    $db = FakeWpdb::install();
    if ($aliasFirst) {
        $db->seedTable('wp_rankXmathXinternalXlinks', [['id' => 42, 'fixture_value' => 'not the declared table']]);
    }
    $db->seedTable('wp_options', [
        ['option_id' => 1, 'option_name' => 'rank_math_modules', 'option_value' => 'fixture', 'autoload' => 'yes'],
    ]);
    $db->seedTable('wp_postmeta', [
        ['meta_id' => 1, 'post_id' => 11, 'meta_key' => 'rank_math_title', 'meta_value' => 'Title 東京'],
    ]);
    $db->seedTable('wp_termmeta', [
        ['meta_id' => 1, 'term_id' => 29, 'meta_key' => 'rank_math_title', 'meta_value' => 'Term title'],
    ]);
    if ($present) {
        foreach (['internal_links', 'internal_meta', 'redirections', 'redirections_cache'] as $suffix) {
            $db->seedTable('wp_rank_math_' . $suffix, [['id' => 7, 'fixture_value' => $suffix]]);
        }
    }
    return $db;
}

/** @return array{output:string,failure:?Throwable,queries:list<string>} */
function rank_observation_run(string $program): array {
    global $wpdb;
    ob_start();
    $failure = null;
    try {
        // WP-CLI eval runs non-strict PHP. Keep that context: otherwise this
        // strict test would turn hash(false) into a TypeError before exposing
        // the actual helper consuming an encoding failure as empty bytes.
        eval("declare(strict_types=0);\n" . $program);
    } catch (Throwable $caught) {
        $failure = $caught;
    } finally {
        $output = (string) ob_get_clean();
    }
    return ['output' => $output, 'failure' => $failure, 'queries' => $wpdb->queries()];
}

$db = rank_observation_database();
$healthy = rank_observation_run($program);
wprism_check($healthy['failure'] === null && preg_match('/^[a-f0-9]{64}$/D', $healthy['output']) === 1,
    'a complete native-state observation publishes one digest: ' . ($healthy['failure']?->getMessage() ?? 'complete'));
wprism_check(count($healthy['queries']) === 11, 'the digest observes all four table presences/row sets and three metadata families');
$expectedTables = [];
foreach (['internal_links', 'internal_meta', 'redirections', 'redirections_cache'] as $suffix) {
    $expectedTables['rank_math_' . $suffix] = [['id' => '7', 'fixture_value' => $suffix]];
}
$expectedDigest = hash('sha256', json_encode([
    'active' => ['seo-by-rank-math/rank-math.php'],
    'options' => [['option_name' => 'rank_math_modules', 'option_value' => 'fixture', 'autoload' => 'yes']],
    'postmeta' => [['post_id' => '11', 'meta_key' => 'rank_math_title', 'meta_value' => 'Title 東京']],
    'tables' => $expectedTables,
    'termmeta' => [['term_id' => '29', 'meta_key' => 'rank_math_title', 'meta_value' => 'Term title']],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
wprism_check_same($expectedDigest, $healthy['output'], 'healthy digest retains the exact pre-hardening payload: ' . $expectedDigest);
$presenceSql = $db->prepare('SHOW TABLES LIKE %s', $db->esc_like('wp_rank_math_internal_links'));
wprism_check(in_array($presenceSql, $healthy['queries'], true),
    'physical-table presence uses an escaped exact LIKE pattern, not wildcard underscores');

$db = rank_observation_database(false);
$virgin = rank_observation_run($program);
wprism_check($virgin['failure'] === null && preg_match('/^[a-f0-9]{64}$/D', $virgin['output']) === 1
    && $virgin['output'] !== $healthy['output'] && count($virgin['queries']) === 7,
    'proved absent tables are legitimate virgin-schema evidence and issue no row reads');
$db = rank_observation_database(aliasFirst: true);
$alias = rank_observation_run($program);
wprism_check($alias['failure'] === null && $alias['output'] === $healthy['output'],
    'a wildcard-lookalike physical table cannot hide an actually present exact table');

// A failed wpdb read returns null/[] and later successful reads clear its error.
// Execute each real statement's failure, not a fake precomputed digest: the
// old helper emits a convincing hash for all eleven incomplete observations.
foreach ($healthy['queries'] as $offset => $query) {
    $db = rank_observation_database();
    $db->failNextQuery('PRIVATE_OBSERVATION_CANARY', $query);
    $failed = rank_observation_run($program);
    wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === '',
        'native observation refuses query failure before publishing a digest: statement ' . ($offset + 1));
    wprism_check(!str_contains($failed['failure']?->getMessage() ?? '', 'PRIVATE_OBSERVATION_CANARY'),
        'native observation diagnostic contains no driver payload: statement ' . ($offset + 1));
    wprism_check(count($failed['queries']) === $offset + 1 && !$db->suppress_errors(),
        'native observation stops before another read can erase failure and restores error-display mode: statement ' . ($offset + 1));
}
foreach ([null, false, ['not-a-row'], ['not-a-list' => []], [[]], [['id' => ['nested']]],
    [['id' => '1'], ['other' => '2']], [['id' => new stdClass()]], [['' => 'empty key']]] as $shape) {
    $db = rank_observation_database();
    $db->returnNextGetResultsAs($shape, 'SELECT *');
    $failed = rank_observation_run($program);
    wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === '',
        'native observation rejects a malformed compatible-driver row set: ' . get_debug_type($shape));
}
foreach (['option_name,option_value,autoload', 'post_id,meta_key,meta_value', 'term_id,meta_key,meta_value'] as $projection) {
    $db = rank_observation_database();
    $db->returnNextGetResultsAs([['unexpected' => 'shape']], 'SELECT ' . $projection);
    $failed = rank_observation_run($program);
    wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === '',
        'native observation rejects an incomplete metadata projection: ' . $projection);
}
foreach ([false, ['not-a-list' => 'plugin'], [new stdClass()]] as $active) {
    $db = rank_observation_database();
    WpStore::instance()->seedOptions(['active_plugins' => $active]);
    $failed = rank_observation_run($program);
    wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === '',
        'native observation refuses malformed active-plugin inventory: ' . get_debug_type($active));
}
$db = rank_observation_database();
$db->suppress_errors(true);
$db->failNextQuery('PRIVATE_OBSERVATION_CANARY', 'SHOW TABLES');
$failed = rank_observation_run($program);
wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === '' && $db->suppress_errors(),
    'native observation restores an already-suppressed caller diagnostic mode after refusal');

$db = rank_observation_database();
$db->seedTable('wp_postmeta', [
    ['meta_id' => 1, 'post_id' => 11, 'meta_key' => 'rank_math_title', 'meta_value' => "\xB1"],
]);
$failedEncoding = rank_observation_run($program);
wprism_check($failedEncoding['failure'] instanceof RuntimeException && $failedEncoding['output'] === '',
    'the shared encoder failure cannot become a digest of an empty string');

$db = rank_observation_database();
$db->seedTable('wp_postmeta', [
    ['meta_id' => 1, 'post_id' => 11, 'meta_key' => 'rank_math_title', 'meta_value' => 'Changed title'],
]);
$changed = rank_observation_run($program);
wprism_check($changed['failure'] === null && $changed['output'] !== $healthy['output'],
    'a real persisted value change moves the observation digest');
wprism_check_summary('regress_rank_math_native_observation');
