<?php
/**
 * Offline regression for ManifestGrammar (DUO-3348's first extraction slice
 * out of Policy.php: the pure table/widget declaration grammar, DUO-3318).
 *
 * This is new characterization coverage — before the extraction, the table
 * and widget grammar asserters were reached only indirectly (through
 * Policy::load() with a whole manifest, or Snapshot's live re-checks), never
 * unit-tested directly by exact refusal message. Belongs in regress-offline-all
 * (no WordPress, DB, providers, or docker: every assertion here is a pure
 * function of an in-memory declaration array).
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/ManifestGrammar.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/manifest_fixtures.php';

use Duo\Canon;
use Duo\ManifestGrammar;
use Duo\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

/** Runs $fn, asserts it threw, and that the message contains $needle. */
$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (\RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})"
        );
    }
};

/** Runs $fn and asserts it does NOT throw. */
$assertPasses = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(true, "$label: accepted");
    } catch (\RuntimeException $e) {
        $check(false, "$label: accepted (unexpected refusal: {$e->getMessage()})");
    }
};

// --------------------------------------------------------- natural_key_columns

$check(ManifestGrammar::natural_key_columns(['identity' => ['mode' => 'mapped']]) === [],
    'natural_key_columns: mapped mode returns []');
$check(ManifestGrammar::natural_key_columns([]) === [],
    'natural_key_columns: no identity section returns []');
$check(ManifestGrammar::natural_key_columns(['identity' => ['mode' => 'natural_key', 'column' => 'slot_code']]) === ['slot_code'],
    'natural_key_columns: single-column spelling');
$check(ManifestGrammar::natural_key_columns(['identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']]]) === ['room_id', 'slot_code'],
    'natural_key_columns: multi-column spelling preserves declared order');
$check(ManifestGrammar::natural_key_columns(['identity' => ['mode' => 'composite_ref', 'columns' => ['a', 'b']]]) === [],
    'natural_key_columns: composite_ref mode returns [] (only natural_key carries components)');

// --------------------------------------------------------- assert_table_grammar: valid paths

$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_widgets', ['class' => 'runtime']),
    'table: inert class runtime needs nothing further');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_widgets', ['class' => 'derived']),
    'table: inert class derived needs nothing further');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_widgets', ['class' => 'env']),
    'table: inert class env needs nothing further');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_widgets', ['class' => 'authored_typed_snapshot_post_v1']),
    'table: honest-intent marker needs nothing further');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_meta', [
    'class' => 'authored_snapshot_meta',
    'attached_to' => ['table' => 'acme_records', 'column' => 'record_id'],
]), 'table: authored_snapshot_meta with attached_to');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_records', [
    'class' => 'authored_snapshot',
    'pk' => 'id',
    'columns' => ['name' => ['class' => 'authored']],
    'refs' => [],
    'identity' => ['mode' => 'mapped'],
]), 'table: mapped mode with pk');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_slots', [
    'class' => 'authored_snapshot',
    'pk' => 'id',
    'columns' => ['slot_code' => ['class' => 'authored']],
    'refs' => [],
    'identity' => ['mode' => 'natural_key', 'column' => 'slot_code'],
]), 'table: single-column natural_key needs no slug_column');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_room_slots', [
    'class' => 'authored_snapshot',
    'pk' => 'id',
    'columns' => ['slot_code' => ['class' => 'authored']],
    'refs' => [['column' => 'room_id', 'kind' => 'post']],
    'identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']],
    'slug_column' => 'slot_code',
]), 'table: multi-column natural_key with slug_column');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_term_meta', [
    'class' => 'authored_snapshot',
    'refs' => [['column' => 'term_id', 'kind' => 'term'], ['column' => 'meta_id', 'kind' => 'post']],
    'columns' => [],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['term_id', 'meta_id']],
]), 'table: composite_ref with identity.columns == refs columns');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_records', [
    'class' => 'authored_snapshot',
    'pk' => 'id',
    'columns' => ['record_id' => ['class' => 'authored']],
    'refs' => [],
    'identity' => ['mode' => 'mapped'],
    'invalidate' => [['table' => 'acme_cache', 'column' => 'record_id']],
]), 'table: valid invalidate row-delete entry');
$assertPasses(fn() => ManifestGrammar::assert_table_grammar('acme_records', [
    'class' => 'authored_snapshot',
    'pk' => 'id',
    'columns' => [],
    'refs' => [],
    'identity' => ['mode' => 'mapped'],
    'invalidate' => [['option_pattern' => 'acme_cache_{id}']],
]), 'table: valid invalidate option_pattern entry with {id}');

// --------------------------------------------------------- assert_table_grammar: refusals

$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', 'not-an-object'),
    'must be declared as an object of table rules', 'table: scalar declaration');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', ['x']),
    'must be declared as an object of table rules', 'table: non-empty list declaration');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', ['class' => 'nonsense']),
    'table class vocabulary is closed', 'table: unknown class');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', ['class' => 'authored_snapshot_meta']),
    'no attached_to.{table,column}', 'table: authored_snapshot_meta missing attached_to');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [], 'identity' => ['mode' => 'bogus'],
]), 'declares unknown identity.mode', 'table: unknown identity.mode');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id',
    'columns' => ['x' => ['class' => 'authored']],
    'refs' => [['column' => 'x', 'kind' => 'post']],
    'identity' => ['mode' => 'mapped'],
]), 'BOTH columns and refs', 'table: column declared in both columns and refs');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id',
    'columns' => [], 'refs' => [['column' => 'a', 'kind' => 'post'], ['column' => 'b', 'kind' => 'post']],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['a', 'b']],
]), "identity.mode=composite_ref AND a 'pk'", 'table: composite_ref with pk present');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot',
    'columns' => [], 'refs' => [['column' => 'a', 'kind' => 'post'], ['column' => 'b', 'kind' => 'post']],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['a', 'b']],
    'invalidate' => [['table' => 'x', 'column' => 'y']],
]), "composite_ref with 'invalidate'", 'table: composite_ref with invalidate');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot',
    'columns' => [], 'refs' => [['column' => 'a', 'kind' => 'post']],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['a']],
]), 'identity.columns != exactly 2 entries', 'table: composite_ref with 1 identity column');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot',
    'columns' => [], 'refs' => [
        ['column' => 'a', 'kind' => 'post'], ['column' => 'b', 'kind' => 'post'], ['column' => 'c', 'kind' => 'post'],
    ],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['a', 'b', 'c']],
]), 'identity.columns != exactly 2 entries', 'table: composite_ref with 3 identity columns');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot',
    'columns' => [], 'refs' => [['column' => 'a', 'kind' => 'post'], ['column' => 'b', 'kind' => 'post']],
    'identity' => ['mode' => 'composite_ref', 'columns' => ['a', 'c']],
]), 'must be EXACTLY its refs[] columns', 'table: composite_ref identity.columns mismatches refs columns');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'columns' => [], 'refs' => [], 'identity' => ['mode' => 'mapped'],
]), "with no 'pk'", 'table: authored_snapshot missing pk');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => ['x' => ['class' => 'authored']], 'refs' => [],
    'identity' => ['mode' => 'natural_key', 'column' => 'x', 'columns' => ['x']],
]), "BOTH 'column' and 'columns'", 'table: natural_key with both column and columns');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'natural_key'],
]), "no 'column' and no non-empty 'columns'", 'table: natural_key with no components');
// NOTE (found by this characterization pass, pre-existing, out of scope for
// this pure extraction): assert_natural_key_grammar()'s own
// "declares an empty identity column name" throw is unreachable through this
// entry point. assert_table_section_shapes() runs first and already rejects
// identity.column="" and any empty entry in identity.columns[] unconditionally
// (both spellings, every mode) — so by the time assert_natural_key_grammar()
// iterates natural_key_columns(), no component can be "". Reported on the
// Linear issue rather than changed here (no behavior change in an extraction
// slice).
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => ['x' => ['class' => 'authored']], 'refs' => [],
    'identity' => ['mode' => 'natural_key', 'columns' => ['x', 'x']],
]), 'repeats identity column', 'table: natural_key repeated column');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'natural_key', 'column' => 'id'],
]), 'names its primary key', 'table: natural_key column equals pk');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'natural_key', 'column' => 'ghost'],
]), 'neither a declared refs[] column nor a declared columns{} entry', 'table: natural_key column not classified anywhere');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id',
    'columns' => ['a' => ['class' => 'authored'], 'b' => ['class' => 'authored']], 'refs' => [],
    'identity' => ['mode' => 'natural_key', 'columns' => ['a', 'b']],
]), "without 'slug_column'", 'table: multi-column natural_key missing slug_column');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => ['x' => ['class' => 'authored']], 'refs' => [],
    'identity' => ['mode' => 'mapped'], 'slug_column' => 'missing_col',
]), 'must name a non-empty authored columns entry', 'table: slug_column not a declared authored column');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'mapped'], 'invalidate' => 'not-a-list',
]), 'invalidate must be a non-empty list', 'table: invalidate not a list');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'mapped'], 'invalidate' => ['not-an-object'],
]), 'invalidate[0] must be an object', 'table: invalidate entry not an object');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'mapped'], 'invalidate' => [['table' => 'x']],
]), 'declares [table] but the invalidation vocabulary is closed', 'table: invalidate entry with unclosed key shape');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [],
    'identity' => ['mode' => 'mapped'], 'invalidate' => [['option_pattern' => 'acme_cache_no_placeholder']],
]), 'must be a string containing the {id} substitution point', 'table: invalidate option_pattern missing {id}');

// -------------------------------------------------- assert_table_section_shapes (via assert_table_grammar)

$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => ['id'], 'columns' => [], 'refs' => [], 'identity' => ['mode' => 'mapped'],
]), 'pk must be a non-empty string', 'table: pk not a string');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => '', 'columns' => [], 'refs' => [], 'identity' => ['mode' => 'mapped'],
]), 'pk must be a non-empty string', 'table: pk explicitly empty string');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => 'not-a-list', 'identity' => ['mode' => 'mapped'],
]), 'refs must be a LIST', 'table: refs not a list');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => ['not-an-object'], 'identity' => ['mode' => 'mapped'],
]), 'every refs[] entry must be an object declaring both', 'table: refs[i] not an object');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [['column' => 'x']], 'identity' => ['mode' => 'mapped'],
]), 'needs a non-empty string `column`', 'table: refs[i] missing kind');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => 'not-an-object', 'refs' => [], 'identity' => ['mode' => 'mapped'],
]), 'columns must be an object keyed by column name', 'table: columns not an object');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [], 'identity' => 'not-an-object',
]), 'identity must be an OBJECT naming the mode', 'table: identity not an object');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [], 'identity' => ['column' => ''],
]), 'identity.column is the SINGLE-component spelling', 'table: identity.column empty');
$assertThrows(fn() => ManifestGrammar::assert_table_grammar('t', [
    'class' => 'authored_snapshot', 'pk' => 'id', 'columns' => [], 'refs' => [], 'identity' => ['columns' => []],
]), 'identity.columns is the ordered LIST spelling', 'table: identity.columns empty list');

// --------------------------------------------------------- assert_widget_grammar: valid + refusals

$assertPasses(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', [
    'settings' => ['title' => ['class' => 'authored']],
]), 'widget: minimal valid declaration');
$assertPasses(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', [
    'settings' => ['body' => ['class' => 'authored', 'codec' => 'blocks']],
]), 'widget: valid codec=blocks setting');
$assertPasses(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', [
    'settings' => ['cat' => ['class' => 'authored', 'ref' => 'term']],
]), 'widget: valid ref=term setting');

$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('Bad Type!', ['settings' => ['x' => ['class' => 'authored']]]),
    'names an invalid widget type', 'widget: type fails id_base pattern');
$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', ['settings' => []]),
    'must declare a non-empty `settings` object', 'widget: empty settings map');
$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', ['settings' => ['a', 'b']]),
    'must declare a non-empty `settings` object', 'widget: settings as a list');
$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', ['settings' => ['x' => ['class' => 'runtime']]]),
    'must declare class=authored', 'widget: setting not class=authored');
$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', [
    'settings' => ['x' => ['class' => 'authored', 'codec' => 'xml']],
]), 'codec vocabulary is closed', 'widget: unknown codec');
$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', [
    'settings' => ['x' => ['class' => 'authored', 'ref' => 'post']],
]), 'ref vocabulary is closed', 'widget: unknown ref');
$assertThrows(fn() => ManifestGrammar::assert_widget_grammar('acme_widget', [
    'settings' => ['x' => ['class' => 'authored', 'codec' => 'blocks', 'ref' => 'term']],
]), 'cannot declare codec and ref', 'widget: both codec and ref declared');

// --------------------------------------------------------- closed-vocabulary accessors

$check(ManifestGrammar::tableClasses() === ['authored_snapshot', 'authored_snapshot_meta', 'authored_typed_snapshot_post_v1', 'runtime', 'derived', 'env'],
    'tableClasses(): exact closed vocabulary');
$check(ManifestGrammar::identityModes() === ['mapped', 'natural_key', 'composite_ref'],
    'identityModes(): exact closed vocabulary');
$check(ManifestGrammar::widgetSettingCodecs() === ['blocks'], 'widgetSettingCodecs(): exact closed vocabulary');
$check(ManifestGrammar::widgetSettingRefs() === ['term'], 'widgetSettingRefs(): exact closed vocabulary');

// --------------------------------------------------------- aggregate loaders

$assertPasses(
    fn() => ManifestGrammar::validate_tables([
        'tables' => ['acme_runtime' => ['class' => 'runtime']],
    ], 'site.duo.json'),
    'aggregate table validation accepts a valid keyed declaration'
);
$assertThrows(
    fn() => ManifestGrammar::validate_tables(['tables' => ['acme_bad' => 'not-an-object']], 'site.duo.json'),
    'must be declared as an object of table rules',
    'aggregate table validation keeps the per-declaration refusal'
);
$assertThrows(
    fn() => ManifestGrammar::validate_tables(['tables' => ['not-an-object']], 'site.duo.json'),
    'tables must be an object keyed by unprefixed table name',
    'aggregate table validation refuses a list-shaped table map'
);
$assertPasses(
    fn() => ManifestGrammar::validate_widgets([
        'name' => 'acme',
        'widgets' => ['acme_card' => ['settings' => ['title' => ['class' => 'authored']]]],
    ]),
    'aggregate widget validation accepts a valid keyed declaration'
);
$assertThrows(
    fn() => ManifestGrammar::validate_widgets(['name' => 'acme', 'widgets' => ['not-a-widget!' => []]]),
    'names an invalid widget type',
    'aggregate widget validation keeps the per-declaration refusal'
);
$assertThrows(
    fn() => ManifestGrammar::validate_widgets(['name' => 'acme', 'widgets' => ['card']]),
    'widgets must be an object keyed by widget type',
    'aggregate widget validation refuses a list-shaped widget map'
);

$validManifestA = manifest_a([
    'widgets' => ['acme_card' => ['settings' => ['title' => ['class' => 'authored']]]],
]);
$validManifestB = manifest_b();
$frozenSnapshot = static function (array $manifests, array $sitePolicy = []): array {
    return [
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'format' => 'duo-policy-snapshot/v4',
        'manifests' => $manifests,
        'site' => [
            'manifests' => array_map(static fn(array $manifest): string => (string) $manifest['name'], $manifests),
            'policy' => array_merge([
                'options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => [],
            ], $sitePolicy),
            'spec_version' => DUO_SPEC_VERSION,
        ],
    ];
};
$assertPasses(
    fn() => Policy::from_snapshot($frozenSnapshot([$validManifestA, $validManifestB], [
        'tables' => ['site_runtime' => ['class' => 'runtime']],
    ])),
    'Policy::from_snapshot() reaches both extracted aggregate grammars'
);
$assertThrows(
    fn() => Policy::from_snapshot($frozenSnapshot([
        $validManifestA,
        manifest_b(['widgets' => ['bad widget!' => ['settings' => ['title' => ['class' => 'authored']]]]]),
    ])),
    'names an invalid widget type',
    'Policy::from_snapshot() preserves aggregate widget refusals'
);
$assertThrows(
    fn() => Policy::from_snapshot($frozenSnapshot([
        manifest_a(['tables' => ['acme_bad' => 'not-an-object']]),
        $validManifestB,
    ])),
    'must be declared as an object of table rules',
    'Policy::from_snapshot() preserves aggregate table refusals'
);

$loadRoot = sys_get_temp_dir() . '/duo_regress_manifest_grammar_' . bin2hex(random_bytes(4));
$loadManifests = $loadRoot . '/manifests';
mkdir($loadManifests, 0777, true);
manifest_fixture_code($loadManifests);
Canon::write_file($loadRoot . '/site.duo.json', Canon::encode([
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
Canon::write_file($loadManifests . '/a.json', Canon::encode($validManifestA));
Canon::write_file($loadManifests . '/b.json', Canon::encode($validManifestB));
$previousManifestsDir = getenv('DUO_MANIFESTS_DIR');
putenv("DUO_MANIFESTS_DIR=$loadManifests");
$assertPasses(
    fn() => Policy::load($loadRoot),
    'Policy::load() reaches both extracted aggregate grammars'
);
Canon::write_file($loadManifests . '/b.json', Canon::encode(
    manifest_b(['widgets' => ['bad widget!' => ['settings' => ['title' => ['class' => 'authored']]]]])
));
$assertThrows(
    fn() => Policy::load($loadRoot),
    'names an invalid widget type',
    'Policy::load() preserves aggregate widget refusals'
);
if ($previousManifestsDir === false) {
    putenv('DUO_MANIFESTS_DIR');
} else {
    putenv("DUO_MANIFESTS_DIR=$previousManifestsDir");
}
foreach (glob($loadManifests . '/*') ?: [] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}
manifest_fixture_code_cleanup($loadManifests);
@rmdir($loadManifests);
@unlink($loadRoot . '/site.duo.json');
@rmdir($loadRoot);

$policySource = (string) file_get_contents(__DIR__ . '/../../agent/src/Policy.php');
$manifestValidatorSource = (string) file_get_contents(__DIR__ . '/../../agent/src/ManifestValidator.php');
$policyReflection = new ReflectionClass(Policy::class);
$grammarReflection = new ReflectionClass(ManifestGrammar::class);
$check(
    !$policyReflection->hasMethod('validate_tables')
        && !$policyReflection->hasMethod('validate_widgets')
        && $grammarReflection->getMethod('validate_tables')->isPublic()
        && $grammarReflection->getMethod('validate_widgets')->isPublic()
        && substr_count($policySource, 'ManifestGrammar::validate_tables(') === 2
        && substr_count($policySource, 'ManifestGrammar::validate_widgets(') === 0
        && substr_count($manifestValidatorSource, 'ManifestGrammar::validate_tables(') === 1
        && substr_count($manifestValidatorSource, 'ManifestGrammar::validate_widgets(') === 1,
    'Policy keeps site aggregate checks while ManifestValidator owns per-manifest aggregate validators'
);

// ---------------------------------------------------------------------- summary

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall ManifestGrammar checks passed\n";
exit(0);
