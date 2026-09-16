<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Review/Lint.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once dirname(__DIR__, 2) . '/fixtures/native-schema/roster.php';
wprism_test_define_agent_versions();

use WPrism\{BlockReferenceScanner, BlockValueGrammar, Blocks, Canon, Lint, LintEnvironment, RepositoryCompiler, Tokens};
use WPrismTest\{FakeWpdb, FrozenPolicy};

$capsule = dirname(__DIR__, 2);
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$inventory = Canon::decode(Canon::read_file($capsule . '/fixtures/authoring-inventory.json'));
$schemaBytes = Canon::read_file($capsule . '/fixtures/native-schema/roster.json');
$schema = Canon::decode($schemaBytes);
$native = QiNativeSchemaRoster::expand($schema['native_groups']);
$added = QiNativeSchemaRoster::expand($schema['added_groups']);
$rules = BlockValueGrammar::attribute_maps($manifest);
wprism_check_same($inventory['closed_attribute_roster']['native_schema_roster_sha256'], hash('sha256', $schemaBytes), 'independent native schema roster retains its reviewed artifact-derived bytes');
wprism_check_same($schema['artifact_sha256'], $inventory['artifact_sha256'], 'schema evidence names the independently locked Qi artifact');
wprism_check_same(21456, array_sum(array_map('count', $native)), 'complete shipped native schema includes every scalar and container attribute');
wprism_check_same(14008, array_sum(array_map('count', $added)), 'reviewed scalar additions close the omitted numeric and boolean fields');
wprism_check_same(array_keys($native), $manifest[BlockValueGrammar::CLOSURE_FIELD], 'all 49 shipped Qi block owners opt into exact closure');
$policy = FrozenPolicy::policy([$core, $manifest], FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION));
$source = new Tokens('https://source.example.test', 'https://source.example.test/wp-content/uploads');
$target = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$database = FakeWpdb::install();
$queries = $database->queries();
$block = static fn(string $name, array $attributes): string => '<!-- wp:' . $name . ' ' . serialize_block_attributes($attributes) . ' /-->';
$all = '';
foreach ($native as $name => $attributes) {
    $names = array_keys($attributes); $names[] = 'className'; sort($names, SORT_STRING);
    wprism_check_same($names, array_keys($rules[$name]), "$name roster is exactly native schema plus supported WordPress className");
    $values = [];
    foreach ($added[$name] as $attribute => $type) {
        $values[$attribute] = match (trim($type)) { 'number' => 23, 'boolean' => true, 'string' => 'standard' };
    }
    $body = $block($name, $values);
    wprism_check_same($body, Blocks::capture_rewrite($body, $policy, $source), "$name newly declared scalar fields preserve exact native JSON types");
    wprism_check_same($body, Blocks::apply_rewrite($body, $policy, $target), "$name scalar-only Apply preserves every supplied value without allocating identities");
    // The exhaustive synthetic field input tests codecs. Compilation uses
    // one scalar per native owner; full native bodies have their own fixture.
    $first = array_key_first($values);
    $all .= $block($name, [$first => $values[$first]]) . "\n";
    $unknown = $block($name, ['extension' => ['entity' => 1]]);
    $expected = "wprism: block '$name' contains an undeclared attribute outside its closed roster";
    foreach (['capture', 'apply'] as $verb) {
        try { Blocks::{$verb . '_rewrite'}($unknown, $policy, $verb === 'capture' ? $source : $target); $message = ''; }
        catch (RuntimeException $e) { $message = $e->getMessage(); }
        wprism_check_same($expected, $message, "$name $verb refuses a neutral extension carrying an otherwise invisible source integer");
    }
    $findings = BlockReferenceScanner::scan(parse_blocks($unknown), $policy->block_attr_rules(), 'page.md', 'https://source.example.test');
    wprism_check_same(['undeclared_block_attribute'], array_column($findings, 'class'), "$name Lint reports exact closure failure instead of guessing field semantics");
    wprism_check(!str_contains(Canon::encode($findings), 'extension') && !str_contains(Canon::encode($findings), 'entity'), "$name closure diagnostic excludes unknown key names and values");
}
wprism_check_same($queries, $database->queries(), 'scalar roster and closure probes perform no native database query or mutation');

$scratch = sys_get_temp_dir() . '/wprism-qi-roster-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$uuid = '11111111-1111-4111-8111-111111111111';
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-16 00:00:00', 'date_gmt' => '2026-09-16 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [], 'modified' => '2026-09-16 00:00:00', 'modified_gmt' => '2026-09-16 00:00:00', 'parent' => null, 'ping_status' => 'closed', 'slug' => 'roster', 'status' => 'publish', 'terms' => (object) [], 'title' => 'Roster', 'type' => 'page', 'uuid' => $uuid];
$post = $scratch . '/state/posts/page/' . $uuid . '--roster.md';
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($policy->site));
Canon::write_file($post, Canon::post_file($front, $all));
wprism_check_same(1, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'new scalar fields from all 49 block owners compile together through the target-free product boundary');
Canon::write_file($post, Canon::post_file($front, $block('qi-blocks/single-image', ['extension' => ['entity' => 1]])));
$before = Canon::read_file($post);
$environment = LintEnvironment::recorded(['format' => LintEnvironment::FORMAT, 'home' => 'https://source.example.test',
    'entities' => [], 'column_types' => [], 'probe_hash' => null,
    'scanned' => ['blocks' => true, 'shortcodes' => false], 'state_hash' => LintEnvironment::state_hash($scratch . '/state')]);
$lint = Lint::scan_tree($scratch . '/state', $policy, $environment);
wprism_check_same(['undeclared_block_attribute'], array_column($lint, 'class'), 'public repository Lint exposes the Qi closure refusal through the shared scanner');
try { RepositoryCompiler::compile($scratch, $policy); $diagnostics = []; }
catch (WPrism\RepositoryCompilationException $e) { $diagnostics = $e->diagnostics; }
wprism_check_same(['repository_block_attribute_undeclared'], array_column($diagnostics, 'code'), 'the original Qi source-integer reproduction now refuses immutable compilation');
wprism_check_same($before, Canon::read_file($post), 'compilation refusal preserves authored repository bytes');
wprism_check_same($queries, $database->queries(), 'public Lint and immutable roster admission/refusal require no target contact');
foreach (['<!-- wp:qi-blocks/single-image {"extension":broken} /-->', '<!-- wp:qi-blocks/single-image [4] /-->'] as $malformed) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($malformed, $policy, $source), RuntimeException::class,
        'Qi closed Capture refuses broken native comments instead of losing authored data during parse');
    wprism_check_throws(static fn() => Blocks::apply_rewrite($malformed, $policy, $target), RuntimeException::class,
        'Qi direct Apply validates closed native comment framing before returning content');
    wprism_check_same(['invalid_block_attributes'], array_column(BlockReferenceScanner::scan_closed_document($malformed, $policy->block_attr_rules(), 'roster.md'), 'class'),
        'Qi parser-free review reports corrupt source attributes');
}
wprism_check_summary('Qi native schema roster');
