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
 *      distinct refusals and one acceptance, with `DUO_SPEC_VERSION` still 3;
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

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';

$root = dirname(__DIR__, 4);
duo_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Grammar/ColumnCodecGrammar.php';
require_once $root . '/agent/src/Capture/TypedTableCapture.php';

use Duo\Canon;
use Duo\ColumnCodecGrammar;
use Duo\Policy;
use Duo\Tokens;
use Duo\TypedTableCapture;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

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
    'engine_features' => ['spec-window/v1', 'typed-column-codecs/v1'],
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
            'action_data' => ['container' => 'php_serialized', 'leaves' => 'text'],
        ],
    ],
];

/**
 * Load one synthetic manifest library through the REAL loader.
 *
 * No dispositions file is written, so these cases exercise grammar only and
 * cannot borrow a review claim from the shipped library — the same technique,
 * and the same reason, as regress_ecosystem_adapter_batch.php's own mutation
 * loader.
 *
 * @param array<string,array<string,mixed>> $files
 */
$load = static function (array $files) use ($root): Policy {
    $dir = sys_get_temp_dir() . '/duo_column_codec_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create manifest library $dir");
    }
    foreach ($files as $name => $manifest) {
        Canon::write_file("$dir/$name.json", Canon::encode($manifest));
    }
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    });
    putenv('DUO_MANIFESTS_DIR=' . $dir);
    return Policy::load(null, array_keys($files));
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
duo_check_same(3, DUO_SPEC_VERSION, 'DUO_SPEC_VERSION is still 3 — `column_codecs` shipped through engine_features, not a bump');

$policy = $load(['redirection' => $redirection]);
duo_check_same(
    ['redirection'],
    array_column($policy->manifests, 'name'),
    'A1: a spec_version 3 manifest declaring the feature AND the section loads through the real loader'
);
duo_check_same(
    ['action_data' => ['container' => 'php_serialized', 'leaves' => 'text']],
    $policy->column_codec_rules('redirection_items'),
    'A1: the loaded policy projects the declared codec for the table that declared it'
);
duo_check_same([], $policy->column_codec_rules('redirection_groups'), 'A1: a table with no codec projects none');

duo_check_throws(
    static fn(): Policy => $load(['redirection' => $variant(['spec_version' => 2, 'engine_features' => null])]),
    RuntimeException::class,
    'A2: a spec_version 2 manifest declaring the section is refused BY SECTION, naming the version that has it',
    "the section 'column_codecs', which this engine implements only at spec_version 3"
);

$noFeature = $redirection;
unset($noFeature['engine_features']);
duo_check_throws(
    static fn(): Policy => $load(['redirection' => $noFeature]),
    RuntimeException::class,
    'A3: at spec_version 3 the key set is CLOSED, so the section without its feature is refused BY KEY',
    "the top-level key 'column_codecs', which this engine does not recognise"
);

duo_check_throws(
    static fn(): Policy => $load(['redirection' => $variant([
        'engine_features' => ['spec-window/v1', 'typed-column-codecs/v9'],
    ])]),
    RuntimeException::class,
    'A4: a feature name this engine does not implement is refused BY FEATURE NAME, never admitted as forward-looking',
    "declares engine feature 'typed-column-codecs/v9'"
);

duo_check_throws(
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
    duo_check_throws(
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
duo_check_throws(
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
    ['redirection_items' => ['action_data' => ['container' => 'json', 'leaves' => 'text']]],
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

duo_check_same(
    ['php_serialized'],
    Policy::closed_vocabularies()['column_codec_containers'],
    'B12: the container vocabulary is published from the same const the refusal consults'
);
duo_check_same(
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
duo_check_same(
    'a:2:{s:3:"url";s:11:"{{home}}/go";s:4:"code";i:301;}',
    $captured,
    'C1: a substitution that SHORTENS the leaf is re-encoded with a corrected s:<n>: prefix'
);
duo_check_same(
    ['url' => '{{home}}/go', 'code' => 301],
    unserialize($captured, ['allowed_classes' => false]),
    'C1: the captured bytes unserialize — the pre-WP-6.1 capture produced s:25:"{{home}}/go", which did not'
);

// C2 — the same substitution in the other direction, LENGTHENING the leaf.
$applied = ColumnCodecGrammar::apply_value($captured, $codec, $targetTokens, 'C2');
duo_check_same(
    'a:2:{s:3:"url";s:31:"https://target.example.co.uk/go";s:4:"code";i:301;}',
    $applied,
    'C2: apply to a longer home rebinds the leaf and recomputes the prefix'
);
duo_check_same(
    ['url' => 'https://target.example.co.uk/go', 'code' => 301],
    unserialize($applied, ['allowed_classes' => false]),
    'C2: the applied bytes unserialize on the target'
);

// C3 — THE IDENTITY ROUND-TRIP PRECONDITION. Nothing to substitute means the
// codec must hand back exactly what it was given, byte for byte, in both
// directions. This is the property that makes a mis-decode a refusal rather
// than a corruption.
$noRefs = serialize(['note' => 'nothing portable here', 'nested' => ['n' => 7, 'deep' => ['x' => 'y']]]);
duo_check_same(
    $noRefs,
    ColumnCodecGrammar::capture_value($noRefs, $codec, $sourceTokens, 'C3'),
    'C3: capture with nothing substituted re-encodes to the exact input bytes'
);
duo_check_same(
    $noRefs,
    ColumnCodecGrammar::apply_value($noRefs, $codec, $targetTokens, 'C3'),
    'C3: apply with nothing substituted re-encodes to the exact input bytes'
);
duo_check_same(
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
    duo_check_throws(
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
duo_check_throws(
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

// ===========================================================================
// D. THE SUFFICIENCY PROOF — the rejected candidate, captured end to end
// ===========================================================================

$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_duo_map', [
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
]);

$captureTokens = new Tokens('https://source.example', 'https://source.example/wp-content/uploads');
$identity = new class {
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
duo_check_same(2, count($entities), 'D1: both authored rows captured');

$first = Canon::decode($entities[0]['content']);
duo_check_same(
    serialize(['url' => '{{home}}/new-page']),
    $first['columns']['action_data'],
    'D2: the serialized container captured with a corrected length prefix'
);
duo_check_same(
    ['url' => '{{home}}/new-page'],
    unserialize((string) $first['columns']['action_data'], ['allowed_classes' => false]),
    'D2: the captured container unserializes — this is the coordinate the ledger recorded as blocked'
);
duo_check_same(
    '{{red_group:019200aa-0000-7000-8000-0000000000a1}}',
    $first['columns']['group_id'],
    'D3: the sibling ref column is unaffected by the codec and still resolves through the ledger'
);
duo_check_same(
    '/old-page',
    $first['columns']['url'],
    'D3: sibling authored columns stay on the ordinary text path'
);
duo_check(
    !array_key_exists('last_count', (array) $first['columns']),
    'D3: runtime columns are still excluded'
);

$second = Canon::decode($entities[1]['content']);
duo_check_same(
    serialize(['url' => '/relative-target', 'flags' => ['regex' => false]]),
    $second['columns']['action_data'],
    'D4: a container with nothing portable in it round-trips to the exact source bytes (the identity precondition, through the product path)'
);

// D5 — the whole point of the primitive: what the target actually receives.
$targetSide = ColumnCodecGrammar::apply_value(
    (string) $first['columns']['action_data'],
    $codec,
    $targetTokens,
    'D5'
);
duo_check_same(
    serialize(['url' => 'https://target.example.co.uk/new-page']),
    $targetSide,
    'D5: applied to a target whose home is a different length, the container is re-encoded correctly'
);
duo_check_same(
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
duo_check_same(
    array_column($entities, 'content'),
    array_column($again, 'content'),
    'D6: capture is deterministic across runs'
);

// D7 — the coordinate that stays OPEN. Redirection's other recorded blocker is
// `verified_provider_postcondition` (group/item raw writes bypass
// Red_Module::flush()), and this fixture claims no provider, no apply and no
// deletion: the primitive closes ONE coordinate, and the ledger says so.
duo_check(
    !array_key_exists('providers', $redirection) && !array_key_exists('actions', $redirection)
        && !array_key_exists('deletions', $redirection),
    'D7: the fixture adapter claims no provider, action or deletion — the second Redirection coordinate stays open'
);

duo_check_summary('regress_column_codec_grammar');
