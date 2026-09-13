<?php
/**
 * WP-6.1 primitive 1 — `column_codecs`, the structured typed-table column
 * codec, and the previously-rejected candidate that proves it SUFFICIENT.
 *
 * WHAT THIS SUITE IS FOR. `tools/engine-gaps.json` recorded the demand as the
 * primitive `serialized_column_codec` (candidate Redirection 5.9.0, coordinate
 * `tables.redirection_items.columns.action_data`). Four things have to be true
 * before that row may be marked closed, and this file is where each is
 * measured:
 *
 *   A. the section STAGES through spec/repo-format.md § v3.2's channel — three
 *      distinct refusals and one acceptance, with `WPRISM_SPEC_VERSION` still 3;
 *   B. its grammar refuses every declaration that would load and then do
 *      nothing, through the REAL `Policy::load()`;
 *   C. the codec keeps its IDENTITY ROUND-TRIP PRECONDITION — it re-encodes to
 *      the exact input bytes when nothing was substituted, or refuses before
 *      any substitution — and a substitution that CHANGES BYTE LENGTH comes out
 *      with correct `s:<n>:` prefixes in both directions;
 *   D. the rejected candidate is authored end to end and captures.
 *
 * THE DEFECT THIS CLOSES, stated as it was measured. Before this section, an
 * authored typed-table column reached capture as
 * `$tokens->tokenize_text($value)` over the RAW STORAGE BYTES
 * (TypedTableCapture.php's ordinary and composite column loops). Against
 * `a:2:{s:3:"url";s:25:"https://source.example/go";...}` that produced
 * `a:2:{s:3:"url";s:25:"{{home}}/go";...}` — prefix 25, payload 11 — a value
 * that no longer unserializes, written into canonical state with no diagnostic
 * anywhere. Group C's first case is that exact input, and it now unserializes.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--compile-without-wordpress') {
    require_once __DIR__ . '/../../lib/agent_version.php';
    require_once __DIR__ . '/../../lib/frozen_policy.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $input = json_decode(file_get_contents($argv[2] . '/probe.json'), true, flags: JSON_THROW_ON_ERROR);
    $pinned = WPrismTest\FrozenPolicy::policy([$input['manifest']], $input['site']);
    $compiled = WPrism\RepositoryCompiler::compile($argv[2], $pinned);
    echo json_encode(['entities' => count($compiled->tree()), 'wordpress' => function_exists('get_option'),
        'database' => isset($GLOBALS['wpdb'])], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';

wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Grammar/ColumnCodecGrammar.php';
require_once $root . '/agent/src/Capture/TypedTableCapture.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/TypedTableMaterializer.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use WPrism\Canon;
use WPrism\ColumnCodecGrammar;
use WPrism\Policy;
use WPrism\RepositoryAuthorization;
use WPrism\RepositoryAuthorizationException;
use WPrism\Tokens;
use WPrism\TypedTableCapture;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

// ---------------------------------------------------------------------------
// The fixture adapter: Redirection 5.9.0, authored end to end.
//
// This is the candidate `tools/engine-gaps.json` rejected, written as the
// manifest an author would ship — a two-table typed snapshot whose child rows
// reference their group and whose `action_data` column is the PHP-serialized
// container the primitive exists for. It is a FIXTURE and not a shipped
// adapter: `manifests/` carries no Redirection entry, makes no capability
// claim, and moves no adapter digest (AGENTS.md rule 2). Its purpose is the
// sufficiency question the work package asks — is the primitive enough to
// author the blocked candidate, or merely enough to describe it.
// ---------------------------------------------------------------------------
$redirection = [
    'name' => 'redirection',
    'spec_version' => 3,
    'plugin' => 'redirection/redirection.php',
    'version_range' => ['min' => '5.9.0', 'max' => '5.9.1'],
    'option_autoload' => 'preserve',
    // BOTH names, and the second one is not decoration. `engine_features` is
    // itself a feature-claimed key — it sits in no arm of the signer's
    // partition and is admitted only because `spec-window/v1` claims it
    // (spec/repo-format.md § v3.3's worked example). So an adapter that uses
    // the channel at all declares the channel's own feature; case A5 pins that,
    // because it is the first thing an author of a post-v3 section trips over.
    'engine_features' => ['mixed-column-codecs/v1', 'spec-window/v1', 'typed-column-codecs/v1'],
    'tables' => [
        'redirection_groups' => [
            'class' => 'authored_snapshot',
            'pk' => 'id',
            'id_kind' => 'red_group',
            'slug_column' => 'name',
            'identity' => ['mode' => 'natural_key', 'column' => 'name'],
            'columns' => [
                'name' => ['class' => 'authored'],
                'module_id' => ['class' => 'authored'],
                'status' => ['class' => 'authored'],
                'position' => ['class' => 'authored'],
            ],
            'refs' => [],
        ],
        'redirection_items' => [
            'class' => 'authored_snapshot',
            'pk' => 'id',
            'id_kind' => 'red_item',
            'slug_column' => 'url',
            'columns' => [
                'url' => ['class' => 'authored'],
                'match_type' => ['class' => 'authored'],
                'action_type' => ['class' => 'authored'],
                'action_code' => ['class' => 'authored'],
                'action_data' => ['class' => 'authored'],
                'position' => ['class' => 'authored'],
                'last_count' => ['class' => 'runtime'],
                'last_access' => ['class' => 'runtime'],
            ],
            'refs' => [['column' => 'group_id', 'kind' => 'red_group']],
        ],
    ],
    // The primitive. `action_data` is the only column whose bytes are a
    // container; every sibling stays on the ordinary text path, which is what
    // makes this a codec declaration rather than a table-wide mode.
    'column_codecs' => [
        'redirection_items' => [
            'action_data' => ['container' => 'php_serialized_or_text', 'leaves' => 'text'],
        ],
    ],
];

/**
 * Load one synthetic manifest library through the REAL loader.
 *
 * The closed fixture library's dispositions are synthetic, so these cases
 * exercise grammar only and cannot borrow a review claim from the source
 * library — the same boundary as regress_ecosystem_adapter_batch.php's own
 * mutation loader.
 *
 * @param array<string,array<string,mixed>> $files
 */
$load = static function (array $files) use ($root): Policy {
    $dir = sys_get_temp_dir() . '/wprism_column_codec_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create manifest library $dir");
    }
    foreach ($files as $name => $manifest) {
        Canon::write_file("$dir/$name.json", Canon::encode($manifest));
    }
    register_shutdown_function(static function () use ($dir): void {
        manifest_fixture_remove_tree($dir);
    });
    return Policy::load(
        null,
        array_keys($files),
        adapterLibrary: manifest_fixture_adapter_library($dir)
    );
};

/** @param array<string,mixed> $overlay */
$variant = static function (array $overlay) use ($redirection): array {
    return array_replace($redirection, $overlay);
};

// ===========================================================================
// A. The `engine_features` staging channel (spec/repo-format.md § v3.2, § v3.3)
// ===========================================================================

// The claim WP-6.1 exists to demonstrate: a new grammar section shipped with NO
// version bump. If this ever fails, the section did not ride the channel.
wprism_check_same(3, WPRISM_SPEC_VERSION, 'WPRISM_SPEC_VERSION is still 3 — `column_codecs` shipped through engine_features, not a bump');

$policy = $load(['redirection' => $redirection]);
wprism_check_same(
    ['redirection'],
    array_column($policy->manifests, 'name'),
    'A1: a spec_version 3 manifest declaring the feature AND the section loads through the real loader'
);
wprism_check_same(
    ['action_data' => ['container' => 'php_serialized_or_text', 'leaves' => 'text']],
    $policy->column_codec_rules('redirection_items'),
    'A1: the loaded policy projects the declared codec for the table that declared it'
);
wprism_check_same([], $policy->column_codec_rules('redirection_groups'), 'A1: a table with no codec projects none');

wprism_check_throws(
    static fn(): Policy => $load(['redirection' => $variant(['spec_version' => 2, 'engine_features' => null])]),
    RuntimeException::class,
    'A2: a spec_version 2 manifest declaring the section is refused BY SECTION, naming the version that has it',
    "the section 'column_codecs', which this engine implements only at spec_version 3"
);

$withoutMixedFeature = $redirection;
$withoutMixedFeature['engine_features'] = ['spec-window/v1', 'typed-column-codecs/v1'];
wprism_check_throws(
    static fn(): Policy => $load(['redirection' => $withoutMixedFeature]),
    RuntimeException::class,
    'A6: the heterogeneous framing is a separately gated value-vocabulary change',
    "the engine feature 'mixed-column-codecs/v1' gates"
);

$noFeature = $redirection;
unset($noFeature['engine_features']);
wprism_check_throws(
    static fn(): Policy => $load(['redirection' => $noFeature]),
    RuntimeException::class,
    'A3: at spec_version 3 the key set is CLOSED, so the section without its feature is refused BY KEY',
    "the top-level key 'column_codecs', which this engine does not recognise"
);

wprism_check_throws(
    static fn(): Policy => $load(['redirection' => $variant([
        'engine_features' => ['spec-window/v1', 'typed-column-codecs/v9'],
    ])]),
    RuntimeException::class,
    'A4: a feature name this engine does not implement is refused BY FEATURE NAME, never admitted as forward-looking',
    "declares engine feature 'typed-column-codecs/v9'"
);

wprism_check_throws(
    static fn(): Policy => $load(['redirection' => $variant([
        'engine_features' => ['typed-column-codecs/v1'],
    ])]),
    RuntimeException::class,
    'A5: declaring only the section\'s own feature refuses — `engine_features` is itself claimed by `spec-window/v1`',
    "the top-level key 'engine_features', which this engine does not recognise"
);

// ===========================================================================
// B. The grammar: every declaration that would load and then do nothing
// ===========================================================================

/** @param array<string,mixed> $codecs */
$refuse = static function (array $codecs, string $fragment, string $message) use ($load, $variant): void {
    wprism_check_throws(
        static fn(): Policy => $load(['redirection' => $variant(['column_codecs' => $codecs])]),
        RuntimeException::class,
        $message,
        $fragment
    );
};

$refuse(
    [],
    'column_codecs must be a non-empty object',
    'B1: an empty section declares a capability the adapter does not use'
);
$refuse(
    ['redirection_items' => []],
    'must be a non-empty object keyed by column name',
    'B2: a table entry naming no column is refused'
);
$refuse(
    ['redirection_logs' => ['data' => ['container' => 'php_serialized', 'leaves' => 'text']]],
    'names a table this manifest does not declare as class=authored_snapshot',
    'B3: a codec for a table this manifest does not declare is refused'
);
$refuse(
    ['redirection_items' => ['last_count' => ['container' => 'php_serialized', 'leaves' => 'text']]],
    'names a column that is not a declared authored columns{} entry',
    'B4: a codec over a runtime column is refused — capture never carries it'
);
$refuse(
    ['redirection_items' => ['group_id' => ['container' => 'php_serialized', 'leaves' => 'text']]],
    'names a column that is not a declared authored columns{} entry',
    'B5: a codec over a ref column is refused — a ref is an id, not a container'
);
$refuse(
    ['redirection_items' => ['url' => ['container' => 'php_serialized', 'leaves' => 'text']]],
    "names this table's slug_column",
    'B6: a codec over the slug column is refused — a canonical filename half must stay a plain scalar'
);
// B7 needs an identity column that is NOT also the slug column, so that the
// identity refusal is the one under test rather than B6's.
$identityTables = $redirection['tables'];
$identityTables['redirection_groups']['identity'] = ['mode' => 'natural_key', 'column' => 'module_id'];
wprism_check_throws(
    static fn(): Policy => $load(['redirection' => $variant([
        'tables' => $identityTables,
        'column_codecs' => [
            'redirection_groups' => ['module_id' => ['container' => 'php_serialized', 'leaves' => 'text']],
        ],
    ])]),
    RuntimeException::class,
    'B7: a codec over a natural_key identity column is refused — identity would depend on this engine\'s serializer',
    'names an identity column'
);
$refuse(
    ['redirection_items' => ['action_data' => ['container' => 'php_serialized']]],
    'a column codec is exactly {container, leaves}',
    'B8: an implied leaf treatment is refused — both members are required'
);
$refuse(
    ['redirection_items' => ['action_data' => ['container' => 'yaml', 'leaves' => 'text']]],
    'the column container vocabulary is closed and engine-owned',
    'B9: an unknown container is refused, and the refusal prints the legal set'
);
$refuse(
    ['redirection_items' => ['action_data' => ['container' => 'php_serialized', 'leaves' => 'blocks']]],
    'the leaf codec vocabulary is closed and engine-owned',
    'B10: an unknown leaf codec is refused, and the refusal prints the legal set'
);
$refuse(
    ['redirection_items' => ['action_data' => 'php_serialized']],
    'must be an object declaring exactly {container, leaves}',
    'B11: a bare string codec is refused rather than coerced'
);

wprism_check_same(
    ['php_serialized', 'php_serialized_or_text', 'json'],
    Policy::closed_vocabularies()['column_codec_containers'],
    'B12: the container vocabulary is published from the same const the refusal consults'
);
wprism_check_same(
    ['text'],
    Policy::closed_vocabularies()['column_codec_leaves'],
    'B12: the leaf vocabulary is published from the same const the refusal consults'
);

// ===========================================================================
// C. The codec: the identity round-trip precondition, and length prefixes
// ===========================================================================

$store = WpStore::reset()->seedOptions(['home' => 'https://source.example']);
$sourceTokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
$targetTokens = new Tokens('https://target.example.co.uk', 'https://target.example.co.uk/wp-content/uploads');
$codec = ['container' => 'php_serialized', 'leaves' => 'text'];

// C1 — the exact input that corrupted before this section existed.
$withUrl = serialize(['url' => 'https://source.example/go', 'code' => 301]);
$captured = ColumnCodecGrammar::capture_value($withUrl, $codec, $sourceTokens, 'C1');
wprism_check_same(
    'a:2:{s:3:"url";s:11:"{{home}}/go";s:4:"code";i:301;}',
    $captured,
    'C1: a substitution that SHORTENS the leaf is re-encoded with a corrected s:<n>: prefix'
);
wprism_check_same(
    ['url' => '{{home}}/go', 'code' => 301],
    unserialize($captured, ['allowed_classes' => false]),
    'C1: the captured bytes unserialize — the pre-WP-6.1 capture produced s:25:"{{home}}/go", which did not'
);

// C2 — the same substitution in the other direction, LENGTHENING the leaf.
$applied = ColumnCodecGrammar::apply_value($captured, $codec, $targetTokens, 'C2');
wprism_check_same(
    'a:2:{s:3:"url";s:31:"https://target.example.co.uk/go";s:4:"code";i:301;}',
    $applied,
    'C2: apply to a longer home rebinds the leaf and recomputes the prefix'
);
wprism_check_same(
    ['url' => 'https://target.example.co.uk/go', 'code' => 301],
    unserialize($applied, ['allowed_classes' => false]),
    'C2: the applied bytes unserialize on the target'
);

// C3 — THE IDENTITY ROUND-TRIP PRECONDITION. Nothing to substitute means the
// codec must hand back exactly what it was given, byte for byte, in both
// directions. This is the property that makes a mis-decode a refusal rather
// than a corruption.
$noRefs = serialize(['note' => 'nothing portable here', 'nested' => ['n' => 7, 'deep' => ['x' => 'y']]]);
wprism_check_same(
    $noRefs,
    ColumnCodecGrammar::capture_value($noRefs, $codec, $sourceTokens, 'C3'),
    'C3: capture with nothing substituted re-encodes to the exact input bytes'
);
wprism_check_same(
    $noRefs,
    ColumnCodecGrammar::apply_value($noRefs, $codec, $targetTokens, 'C3'),
    'C3: apply with nothing substituted re-encodes to the exact input bytes'
);
wprism_check_same(
    $captured,
    ColumnCodecGrammar::capture_value(
        ColumnCodecGrammar::apply_value($captured, $codec, $sourceTokens, 'C3'),
        $codec,
        $sourceTokens,
        'C3'
    ),
    'C3: apply-then-capture on the SOURCE environment is a fixed point'
);

// C4 — a value the codec cannot re-encode faithfully is REFUSED, before any
// substitution. Each of these is a way the engine could otherwise have written
// back a container it mis-read.
$reject = static function (mixed $bytes, string $fragment, string $message) use ($codec, $sourceTokens): void {
    wprism_check_throws(
        static fn(): string => ColumnCodecGrammar::capture_value($bytes, $codec, $sourceTokens, 'C4'),
        RuntimeException::class,
        $message,
        $fragment
    );
};
$reject(
    serialize(['a' => 1]) . 'trailing',
    'trailing or noncanonical PHP-serialized data',
    'C4: a valid serialized prefix followed by arbitrary bytes is refused by the plain-data boundary'
);
$reject(
    'a:1:{s:1:"a";i:1;} ',
    'does not reproduce its bytes exactly',
    'C4: a trailing space — which trim() would have swallowed — fails the identity precondition'
);
$reject(
    'https://source.example/go',
    'does not reproduce its bytes exactly',
    'C4: a column that holds a plain string is refused rather than double-encoded'
);
$reject(
    serialize('https://source.example/go'),
    'decodes to string',
    'C4: a serialized SCALAR is refused — the ordinary text path already handles it correctly'
);
$reject(
    301,
    'not a string',
    'C4: a non-string column value is refused rather than coerced into a container'
);
$reject(
    'O:8:"stdClass":1:{s:1:"a";i:1;}',
    'PHP object',
    'C4: a serialized object never reaches the leaf rewrite'
);

// C5 — the secret gate composes, and screens the DECODED value, which is where
// a credential nested inside a container actually lives.
wprism_check_throws(
    static fn(): string => ColumnCodecGrammar::capture_value(
        serialize(['token' => 'AKIAIOSFODNN7EXAMPLE']),
        $codec,
        $sourceTokens,
        'C5'
    ),
    RuntimeException::class,
    'C5: the secret guard screens the decoded container, not just its framing bytes',
    'secret guard tripped'
);
wprism_check_throws(
    static fn(): string => ColumnCodecGrammar::capture_value(
        serialize(['nested' => ['credential' => 'GeneratedValue-2026-Blocked']]),
        $codec,
        $sourceTokens,
        'C5',
        'action_data'
    ),
    RuntimeException::class,
    'C5: decoded typed-column keys receive the heuristic credential gate, not only hard signatures',
    'credential-shaped value'
);
wprism_check_throws(
    static fn(): string => ColumnCodecGrammar::capture_value(
        serialize(['nested' => ['smtp_pass' => ['primary' => 'GeneratedValue-2026-Blocked']]]),
        $codec,
        $sourceTokens,
        'C5',
        'action_data'
    ),
    RuntimeException::class,
    'C5: a decoded typed-column credential container retains its role at a generic scalar leaf',
    'credential-shaped value'
);
wprism_check_throws(
    static fn(): string => ColumnCodecGrammar::capture_value(
        serialize(['nested' => ['sk_live_COLUMNKEY1234567890' => 'enabled']]),
        $codec,
        $sourceTokens,
        'C5',
        'action_data'
    ),
    RuntimeException::class,
    'C5: a hard secret in a decoded serialized-column key cannot evade clearance',
    'stripe key'
);
wprism_check_throws(
    static fn(): string => ColumnCodecGrammar::capture_value(
        serialize(['customerProfile' => ['firstName' => 'Private Customer']]),
        $codec,
        $sourceTokens,
        'C5',
        'action_data'
    ),
    RuntimeException::class,
    'C5: decoded typed-column personal-data keys refuse before re-encoding',
    'personal name'
);
wprism_check_throws(
    static fn(): string => ColumnCodecGrammar::capture_value(
        serialize(['audience' => ['alice@example.test' => 'enabled']]),
        $codec,
        $sourceTokens,
        'C5',
        'action_data'
    ),
    RuntimeException::class,
    'C5: PII in a decoded serialized-column key cannot evade clearance',
    'email address'
);
wprism_check_same(
    serialize(['customerProfile' => ['firstName' => 'Private Customer']]),
    ColumnCodecGrammar::capture_value(
        serialize(['customerProfile' => ['firstName' => 'Private Customer']]),
        $codec,
        $sourceTokens,
        'C5',
        'action_data',
        ['allow_pii' => true]
    ),
    'C5: an exact typed-column allow_pii rule composes with decoded clearance'
);

// C6 — Redirection's actual storage union. One action_data column holds raw
// target text for ordinary URL redirects, a serialized map for conditional
// redirects, and NULL for actions such as HTTP errors. The mixed codec is
// explicit because treating any of those as another is silent corruption.
$mixed = ['container' => 'php_serialized_or_text', 'leaves' => 'text'];
wprism_check_same(
    '{{home}}/go',
    ColumnCodecGrammar::capture_value('https://source.example/go', $mixed, $sourceTokens, 'C6'),
    'C6: a plain action target takes the ordinary text path'
);
wprism_check_same(
    'https://target.example.co.uk/go',
    ColumnCodecGrammar::apply_value('{{home}}/go', $mixed, $targetTokens, 'C6'),
    'C6: a plain action target rebinds on apply without being serialized'
);
wprism_check_same(
    $captured,
    ColumnCodecGrammar::capture_value($withUrl, $mixed, $sourceTokens, 'C6'),
    'C6: a serialized conditional map retains the strict container path'
);
wprism_check_same(null, ColumnCodecGrammar::capture_value(null, $mixed, $sourceTokens, 'C6'),
    'C6: a nullable action keeps SQL NULL distinct from text and serialized data');
wprism_check_same(null, ColumnCodecGrammar::apply_value(null, $mixed, $targetTokens, 'C6'),
    'C6: apply preserves the nullable arm byte-for-byte');
wprism_check_throws(
    static fn(): mixed => ColumnCodecGrammar::capture_value('a:1:{broken', $mixed, $sourceTokens, 'C6'),
    RuntimeException::class,
    'C6: a serialized-looking malformed value refuses instead of falling through as text',
    'malformed or noncanonical PHP-serialized data'
);
wprism_check_throws(
    static fn(): mixed => ColumnCodecGrammar::capture_value(301, $mixed, $sourceTokens, 'C6'),
    RuntimeException::class,
    'C6: the mixed framing admits no undocumented integer arm',
    'not a string'
);

// ===========================================================================
// D. THE SUFFICIENCY PROOF — the rejected candidate, captured end to end
// ===========================================================================

$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_wprism_map', [
    [
        'id' => 1,
        'uuid' => '019200aa-0000-7000-8000-0000000000a1',
        'entity_type' => 'redirection_groups',
        'id_kind' => 'red_group',
        'local_id' => 3,
    ],
]);
$wpdb->seedTable('wp_redirection_groups', [
    ['id' => 3, 'name' => 'Modified posts', 'module_id' => 1, 'status' => 'enabled', 'position' => 0],
]);
$wpdb->seedTable('wp_redirection_items', [
    [
        'id' => 11,
        'url' => '/old-page',
        'match_type' => 'url',
        'action_type' => 'url',
        'action_code' => 301,
        'action_data' => serialize(['url' => 'https://source.example/new-page']),
        'position' => 0,
        'group_id' => 3,
        'last_count' => 42,
        'last_access' => '2026-08-22 00:00:00',
    ],
    [
        'id' => 12,
        'url' => '/keep',
        'match_type' => 'url',
        'action_type' => 'pass',
        'action_code' => 200,
        'action_data' => serialize(['url' => '/relative-target', 'flags' => ['regex' => false]]),
        'position' => 1,
        'group_id' => 3,
        'last_count' => 0,
        'last_access' => '2026-08-22 00:00:00',
    ],
    [
        'id' => 13,
        'url' => '/plain',
        'match_type' => 'url',
        'action_type' => 'url',
        'action_code' => 302,
        'action_data' => 'https://source.example/plain-target',
        'position' => 2,
        'group_id' => 3,
        'last_count' => 0,
        'last_access' => '2026-08-22 00:00:00',
    ],
    [
        'id' => 14,
        'url' => '/gone',
        'match_type' => 'url',
        'action_type' => 'error',
        'action_code' => 410,
        'action_data' => null,
        'position' => 3,
        'group_id' => 3,
        'last_count' => 0,
        'last_access' => '2026-08-22 00:00:00',
    ],
]);

$captureTokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
$identity = new class() {
    /** Deterministic per-row identity; the ledger half is exercised by the ref column below. */
    public function identifyRow(
        string $table,
        array $decl,
        array $row,
        int $localId,
        object $tokens,
        bool $mint,
        bool $strictReadOnly
    ): string {
        return sprintf('019200bb-0000-7000-8000-%012d', $localId);
    }
};
$boundary = new TypedTableCapture(
    $identity,
    static function (): void {},
    static fn(): ?string => null,
    static fn(string $raw): string => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($raw)) ?? '', '-')
);

$entities = $boundary->capture_table(
    'redirection_items',
    $redirection['tables']['redirection_items'],
    [],
    $captureTokens,
    false,
    false,
    $policy->column_codec_rules('redirection_items')
);
wprism_check_same(4, count($entities), 'D1: serialized, plain-text and NULL action rows all capture');

$first = Canon::decode($entities[0]['content']);
wprism_check_same(
    serialize(['url' => '{{home}}/new-page']),
    $first['columns']['action_data'],
    'D2: the serialized container captured with a corrected length prefix'
);
wprism_check_same(
    ['url' => '{{home}}/new-page'],
    unserialize((string) $first['columns']['action_data'], ['allowed_classes' => false]),
    'D2: the captured container unserializes — this is the coordinate the ledger recorded as blocked'
);
$gitEdited = $first;
$gitEdited['columns']['action_data'] = serialize([
    'customerProfile' => ['firstName' => 'Private Customer'],
]);
$gitEditedEntity = [
    'type' => 'redirection_items',
    'path' => $entities[0]['path'],
    'content' => Canon::encode($gitEdited),
    'data' => $gitEdited,
];
$authorizationDiagnostics = [];
try {
    RepositoryAuthorization::assert_tree($policy, [$gitEdited['uuid'] => $gitEditedEntity]);
} catch (RepositoryAuthorizationException $failure) {
    $authorizationDiagnostics = $failure->diagnostics;
}
wprism_check_same(
    1,
    count(array_filter($authorizationDiagnostics, static fn(array $diagnostic): bool =>
        ($diagnostic['code'] ?? null) === 'repository_pii_not_allowed'
        && ($diagnostic['surface'] ?? null) === 'table_column'
        && ($diagnostic['field'] ?? null) === 'action_data'
    )),
    'D2: repository authorization decodes typed-column framing before recursive personal-data clearance'
);
$gitEditedSecret = $first;
$gitEditedSecret['columns']['action_data'] = serialize([
    'password' => ['primary' => 'GeneratedValue-2026-Blocked'],
]);
$gitEditedSecretEntity = [
    'type' => 'redirection_items',
    'path' => $entities[0]['path'],
    'content' => Canon::encode($gitEditedSecret),
    'data' => $gitEditedSecret,
];
$secretAuthorizationDiagnostics = [];
try {
    RepositoryAuthorization::assert_tree($policy, [$gitEditedSecret['uuid'] => $gitEditedSecretEntity]);
} catch (RepositoryAuthorizationException $failure) {
    $secretAuthorizationDiagnostics = $failure->diagnostics;
}
wprism_check_same(
    1,
    count(array_filter($secretAuthorizationDiagnostics, static fn(array $diagnostic): bool =>
        ($diagnostic['code'] ?? null) === 'repository_secret_not_allowed'
        && ($diagnostic['surface'] ?? null) === 'table_column'
        && ($diagnostic['field'] ?? null) === 'action_data'
    )),
    'D2: repository authorization retains a decoded typed-column credential container role'
);
wprism_check_same(
    '{{red_group:019200aa-0000-7000-8000-0000000000a1}}',
    $first['columns']['group_id'],
    'D3: the sibling ref column is unaffected by the codec and still resolves through the ledger'
);
wprism_check_same(
    '/old-page',
    $first['columns']['url'],
    'D3: sibling authored columns stay on the ordinary text path'
);
wprism_check(
    !array_key_exists('last_count', (array) $first['columns']),
    'D3: runtime columns are still excluded'
);

$second = Canon::decode($entities[1]['content']);
wprism_check_same(
    serialize(['url' => '/relative-target', 'flags' => ['regex' => false]]),
    $second['columns']['action_data'],
    'D4: a container with nothing portable in it round-trips to the exact source bytes (the identity precondition, through the product path)'
);
$third = Canon::decode($entities[2]['content']);
wprism_check_same('{{home}}/plain-target', $third['columns']['action_data'] ?? null,
    'D4: Redirection ordinary URL actions use the mixed codec text arm');
$fourth = Canon::decode($entities[3]['content']);
wprism_check(array_key_exists('action_data', $fourth['columns']) && $fourth['columns']['action_data'] === null,
    'D4: Redirection error actions preserve the mixed codec NULL arm');

// D5 — the whole point of the primitive: what the target actually receives.
$targetSide = ColumnCodecGrammar::apply_value(
    (string) $first['columns']['action_data'],
    $codec,
    $targetTokens,
    'D5'
);
wprism_check_same(
    serialize(['url' => 'https://target.example.co.uk/new-page']),
    $targetSide,
    'D5: applied to a target whose home is a different length, the container is re-encoded correctly'
);
wprism_check_same(
    ['url' => 'https://target.example.co.uk/new-page'],
    unserialize($targetSide, ['allowed_classes' => false]),
    'D5: Redirection reads back a valid container on the target'
);

// D6 — determinism: capture twice, same canonical bytes. A codec that emitted
// a serializer-dependent spelling would show up here as a moving post hash.
$again = $boundary->capture_table(
    'redirection_items',
    $redirection['tables']['redirection_items'],
    [],
    new Tokens('https://source.example', 'https://source.example/wp-content/uploads'),
    false,
    false,
    $policy->column_codec_rules('redirection_items')
);
wprism_check_same(
    array_column($entities, 'content'),
    array_column($again, 'content'),
    'D6: capture is deterministic across runs'
);

// D7 — the coordinate that stays OPEN. Redirection's other recorded blocker is
// `verified_provider_postcondition` (group/item raw writes bypass
// Red_Module::flush()), and this fixture claims no provider, no apply and no
// deletion: the primitive closes ONE coordinate, and the ledger says so.
wprism_check(
    !array_key_exists('providers', $redirection) && !array_key_exists('actions', $redirection)
        && !array_key_exists('deletions', $redirection),
    'D7: the fixture adapter claims no provider, action or deletion — the second Redirection coordinate stays open'
);

// E. Stored JSON uses the same checked column boundary. Compilation must
// validate every declared framing, including existing serialized containers.
$framingManifest = ['name' => 'column-framing', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['spec-window/v1', 'typed-column-codecs/v1'],
    'tables' => ['framing_rows' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'framing_row',
        'slug_column' => 'name', 'identity' => ['mode' => 'mapped'],
        'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]],
    'column_codecs' => ['framing_rows' => ['data' => $codec]]];
$framingUuid = '11111111-1111-4111-8111-111111111111';
$framingPath = "tables/framing_rows/$framingUuid--selected.json";
$framingScratch = sys_get_temp_dir() . '/wprism-column-framing-' . bin2hex(random_bytes(8));
mkdir($framingScratch, 0700, true);
register_shutdown_function(static fn() => manifest_fixture_remove_tree($framingScratch));
$compileFraming = static function (array $manifest, mixed $value) use ($framingScratch, $framingUuid, $framingPath): array {
    $site = WPrismTest\FrozenPolicy::site([$manifest], 3);
    $pinned = WPrismTest\FrozenPolicy::policy([$manifest], $site);
    Canon::write_file($framingScratch . '/site.wprism.json', Canon::encode($site));
    Canon::write_file($framingScratch . '/state/' . $framingPath,
        Canon::encode(['uuid' => $framingUuid, 'table' => 'framing_rows', 'meta' => (object) [], 'columns' => ['name' => 'Selected', 'data' => $value]]));
    return WPrism\RepositoryCompiler::compile($framingScratch, $pinned)->tree();
};
wprism_check_same(1, count($compileFraming($framingManifest, serialize(['url' => '{{home}}/path']))),
    'E1: real compiler admits existing faithful serialized column framing');
wprism_check_throws(static fn() => $compileFraming($framingManifest, 'a:1:{broken'), RuntimeException::class,
    'E1: real compiler refuses malformed declared serialized framing', 'schema_content_mismatch');

$jsonCodec = ['container' => 'json', 'leaves' => 'text'];
$jsonManifest = $framingManifest;
$jsonManifest['engine_features'][] = 'json-column-codecs/v1';
sort($jsonManifest['engine_features'], SORT_STRING);
$jsonManifest['column_codecs']['framing_rows']['data'] = $jsonCodec;
$jsonPolicy = $load(['column-framing' => $jsonManifest]);
wprism_check_same(['data' => $jsonCodec], $jsonPolicy->column_codec_rules('framing_rows'),
    'E2: JSON framing loads through the real negotiated policy path');
$missingJsonFeature = $jsonManifest;
$missingJsonFeature['engine_features'] = $framingManifest['engine_features'];
wprism_check_throws(static fn() => $load(['column-framing' => $missingJsonFeature]), RuntimeException::class,
    'E2: JSON framing cannot borrow the base column feature', 'json-column-codecs/v1');
$missingColumnFeature = $jsonManifest;
$missingColumnFeature['engine_features'] = ['json-column-codecs/v1', 'spec-window/v1'];
wprism_check_throws(static fn() => $load(['column-framing' => $missingColumnFeature]), RuntimeException::class,
    'E2: JSON vocabulary does not self-grant the column section', "top-level key 'column_codecs'");

$jsonNative = json_encode(['url' => 'https://source.example/path?q="quoted"', 'nested' => ['note' => 'বাংলা',
    'empty' => [], 'enabled' => true, 'nothing' => null, 'count' => 7, 'fraction' => 1.25]], JSON_THROW_ON_ERROR);
$jsonExpected = str_replace('https:\/\/source.example', '{{home}}', $jsonNative);
$jsonCaptured = ColumnCodecGrammar::capture_value($jsonNative, $jsonCodec, $sourceTokens, 'E3');
wprism_check_same($jsonExpected, $jsonCaptured, 'E3: escaped native JSON URLs are rewritten only after decoding');
wprism_check_same($jsonCaptured, ColumnCodecGrammar::capture_value(
    ColumnCodecGrammar::apply_value($jsonCaptured, $jsonCodec, $targetTokens, 'E3'), $jsonCodec, $targetTokens, 'E3'),
    'E3: target Apply and recapture preserve complete canonical JSON bytes');
foreach (['[]', '{"note":"unchanged","nested":[true,false,null,7]}', '[{"x":"y"},[]]'] as $stableJson) {
    wprism_check_same($stableJson, ColumnCodecGrammar::capture_value($stableJson, $jsonCodec, $sourceTokens, 'E3'),
        'E3: unchanged JSON preserves every byte and container kind');
}
$invalidJson = ['{"x":', '{"x":1} trailing', '{"x":1,"x":2}', '{"0":"value"}', '{}', '{"nested":{}}',
    '{ "x":1}', '{"x":1.0}', '{"x":9223372036854775808}', '{"x":1e400}', '{"x":"\\u0078"}',
    'null', 'true', '17', '"text"', null, 17, str_repeat('[', 520) . '0' . str_repeat(']', 520)];
$rewriteSpy = new class() {
    public int $calls = 0;
    public function plain_data_capture($value) { ++$this->calls; return $value; }
    public function plain_data_apply($value) { ++$this->calls; return $value; }
};
foreach ($invalidJson as $invalid) {
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($invalid, $jsonCodec, $rewriteSpy, 'E4'),
        RuntimeException::class, 'E4: unsupported or unfaithful native JSON refuses before rewriting');
    wprism_check_throws(static fn() => ColumnCodecGrammar::apply_value($invalid, $jsonCodec, $rewriteSpy, 'E4'),
        RuntimeException::class, 'E4: unsupported or unfaithful canonical JSON refuses before rewriting');
    wprism_check_throws(static fn() => $compileFraming($jsonManifest, $invalid), RuntimeException::class,
        'E4: the real compiler refuses the same invalid framing', 'schema_content_mismatch');
}
wprism_check_same(0, $rewriteSpy->calls, 'E4: rejected framing never invokes a leaf transformer');
foreach ([['credential' => 'GeneratedValue-2026-Blocked'], ['profile' => ['firstName' => 'Private Customer']]] as $private) {
    $privateJson = json_encode($private, JSON_THROW_ON_ERROR);
    wprism_check_throws(static fn() => ColumnCodecGrammar::capture_value($privateJson, $jsonCodec, $sourceTokens, 'E5'),
        RuntimeException::class, 'E5: decoded JSON keys participate in recursive capture clearance');
    wprism_check_throws(static fn() => $compileFraming($jsonManifest, $privateJson), RuntimeException::class,
        'E5: edited JSON keys participate in real compiler clearance');
}

// E6 composes the new framing with row ownership and the actual capture,
// compiler and checked materializer. Foreign invalid JSON never enters a codec.
$scopedManifest = $jsonManifest;
$scopedManifest['engine_features'][] = 'table-row-scopes/v1';
sort($scopedManifest['engine_features'], SORT_STRING);
$scopedManifest['tables']['framing_rows']['row_scope'] = ['item_type' => 'user'];
$scopedManifest['tables']['framing_rows']['columns']['item_type'] = ['class' => 'authored'];
$scopedManifest['tables']['framing_rows']['columns']['hits'] = ['class' => 'runtime'];
$scopedPolicy = $load(['column-framing' => $scopedManifest]);
$framingDecl = $scopedPolicy->declared_tables()['framing_rows'];
$wpdb = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey('framing_rows', 'id')->setAutoIncrement('framing_rows', 800, 'id')
    ->setColumns('framing_rows', ['id' => 'int(11)', 'name' => 'varchar(255)', 'data' => 'longtext',
        'item_type' => 'varchar(32)', 'hits' => 'int(11)'])->setTableEngine('framing_rows', 'InnoDB');
$foreignJsonRows = [['id' => 3, 'name' => 'Foreign', 'item_type' => 'product', 'data' => '{broken', 'hits' => 91]];
$wpdb->seedTable('framing_rows', [
    ['id' => 2, 'name' => 'Selected', 'item_type' => 'user', 'data' => $jsonNative, 'hits' => 42], ...$foreignJsonRows]);
$framingIdentity = new class($framingUuid) {
    public function __construct(private string $uuid) {}
    public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuid; }
};
$framingCapture = new TypedTableCapture($framingIdentity, static function (): void {}, static fn() => null,
    static fn(string $name): string => strtolower($name));
$captureJson = static fn(Tokens $tokens): array => $framingCapture->capture_table(
    'framing_rows', $framingDecl, [], $tokens, true, false, $scopedPolicy->column_codec_rules('framing_rows'));
$jsonEntities = $captureJson($sourceTokens);
wprism_check_same(1, count($jsonEntities), 'E6: scoped Capture ignores foreign malformed JSON');
$jsonFront = Canon::decode($jsonEntities[0]['content']);
wprism_check_same($jsonExpected, $jsonFront['columns']['data'], 'E6: real typed-table Capture emits canonical JSON');
wprism_check(!array_key_exists('hits', $jsonFront['columns']), 'E6: sibling runtime counters stay outside authored state');
$scopedSite = WPrismTest\FrozenPolicy::site([$scopedManifest], 3);
$scopedPolicy = WPrismTest\FrozenPolicy::policy([$scopedManifest], $scopedSite);
Canon::write_file($framingScratch . '/site.wprism.json', Canon::encode($scopedSite));
Canon::write_file($framingScratch . '/state/' . $jsonEntities[0]['path'], $jsonEntities[0]['content']);
$jsonTree = WPrism\RepositoryCompiler::compile($framingScratch, $scopedPolicy)->tree();
wprism_check_same(1, count($jsonTree), 'E6: captured JSON and ownership compose through immutable compilation');
Canon::write_file($framingScratch . '/probe.json', Canon::encode(['manifest' => $scopedManifest, 'site' => $scopedSite]));
$child = proc_open([PHP_BINARY, __FILE__, '--compile-without-wordpress', $framingScratch],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('could not start isolated column compiler');
fclose($pipes[0]);
$childOut = stream_get_contents($pipes[1]);
$childErr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
wprism_check_same(0, proc_close($child), 'E6: JSON columns compile in an isolated process');
wprism_check_same('', $childErr, 'E6: isolated compilation emits no diagnostics');
wprism_check_same(['entities' => 1, 'wordpress' => false, 'database' => false], json_decode($childOut, true),
    'E6: JSON framing validation requires neither WordPress nor a database');
$jsonEntity = $jsonTree[$framingUuid];
$wpdb->seedTable('framing_rows', $foreignJsonRows);
$mappedId = null;
$framingWriter = new WPrism\TypedTableMaterializer(static fn() => ['framing_rows' => $framingDecl], static fn() => [],
    static function () use (&$mappedId): ?int { return $mappedId; },
    static function (string $uuid, string $table, string $kind, int $id) use (&$mappedId): void { $mappedId = $id; },
    static fn() => 0, static fn() => [], static fn() => false, static fn($value) => $value,
    static function (): void {}, static fn() => $scopedPolicy->column_codec_rules('framing_rows'));
$writeJson = static function (callable $write) use (&$mappedId): mixed {
    $mapBefore = $mappedId;
    WPrism\Db::start_repeatable_read('JSON framing fixture', new WPrism\NativeDatabaseProfile(['wp_framing_rows'], ['wp_framing_rows']));
    try {
        $result = $write();
        WPrism\Db::commit('JSON framing fixture');
        return $result;
    } catch (Throwable $failure) {
        WPrism\Db::rollback_after_failure($failure, 'JSON framing fixture');
        $mappedId = $mapBefore;
        throw $failure;
    }
};
$writeJson(static function () use ($framingWriter, $jsonEntity, $targetTokens): void {
    wprism_check($framingWriter->ensureRow($jsonEntity), 'E6: checked Apply creates a target row at a new local identity');
    $framingWriter->finalizeRow($targetTokens, $jsonEntity);
});
$expectedTarget = json_encode(['url' => 'https://target.example.co.uk/path?q="quoted"',
    'nested' => json_decode($jsonNative, true)['nested']], JSON_THROW_ON_ERROR);
wprism_check_same($expectedTarget, $wpdb->rows('framing_rows')[1]['data'], 'E6: checked Apply stores faithful rebound JSON');
wprism_check_same($foreignJsonRows, array_slice($wpdb->rows('framing_rows'), 0, 1), 'E6: checked Apply preserves foreign bytes');
wprism_check_same($jsonEntities[0]['content'], $captureJson($targetTokens)[0]['content'], 'E6: complete canonical recapture is stable');
$writeJson(static function () use ($framingWriter, $jsonEntity, $targetTokens): void {
    wprism_check(!$framingWriter->ensureRow($jsonEntity), 'E6: repeated Apply retains the target identity');
    $framingWriter->finalizeRow($targetTokens, $jsonEntity);
});
wprism_check_same(2, count($wpdb->rows('framing_rows')), 'E6: repeated Apply creates no duplicate');
$beforeJsonFailure = $wpdb->rows('framing_rows');
$changedJsonEntity = $jsonEntity;
$changedJsonEntity['data']['columns']['data'] = json_encode(['url' => '{{home}}/changed'], JSON_THROW_ON_ERROR);
$jsonWriteObserved = false;
wprism_check_throws(static function () use ($writeJson, $framingWriter, $changedJsonEntity, $targetTokens, $wpdb, &$jsonWriteObserved): void {
    $writeJson(static function () use ($framingWriter, $changedJsonEntity, $targetTokens, $wpdb, &$jsonWriteObserved): void {
    $framingWriter->finalizeRow($targetTokens, $changedJsonEntity);
    $jsonWriteObserved = $wpdb->rows('framing_rows')[1]['data'] === json_encode(['url' => 'https://target.example.co.uk/changed'], JSON_THROW_ON_ERROR);
    throw new RuntimeException('injected later JSON failure');
    });
}, RuntimeException::class, 'E6: later failure rolls back the actual checked JSON write', 'injected later JSON failure');
wprism_check($jsonWriteObserved, 'E6: rollback probe reached materialization');
wprism_check_same($beforeJsonFailure, $wpdb->rows('framing_rows'), 'E6: rollback restores every native row');
$malformedEntity = $jsonEntity;
$malformedEntity['data']['columns']['data'] = '{broken';
wprism_check_throws(static fn() => $writeJson(static fn() => $framingWriter->finalizeRow($targetTokens, $malformedEntity)),
    RuntimeException::class, 'E6: materialization independently refuses malformed canonical JSON', 'not valid JSON');
wprism_check_same($beforeJsonFailure, $wpdb->rows('framing_rows'), 'E6: malformed materialization preserves every native row');
$writeJson(static fn() => $framingWriter->deleteLocalRow('framing_rows', $mappedId));
wprism_check_same($foreignJsonRows, $wpdb->rows('framing_rows'), 'E6: deletion leaves foreign malformed data untouched');

wprism_check_summary('regress_column_codec_grammar');
