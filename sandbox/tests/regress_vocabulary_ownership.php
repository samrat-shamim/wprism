<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3318: ownership rules for the engine's closed manifest vocabularies,
 * the safe extension points around them, and the parent-scoped multi-column
 * natural key those rules had to be written down for.
 *
 * The proof shape is a SECOND ADAPTER. Every negative below is manifest 'b'
 * — a perfectly well-formed adapter of its own — attempting something that
 * would give it authority over manifest 'a''s entities, or attempting to
 * mint a vocabulary value the engine owns. Both are refused at LOAD time,
 * before any target exists to contact, with a message that names the
 * rejected token, prints the legal set, and says who owns extension. That
 * last clause is the point of the issue, not decoration: a refusal that does
 * not say where the extension path IS just sends an adapter author back to
 * guessing, which is how the engine grew plugin-shaped branches before.
 *
 * Runs the REAL, unmodified agent/src/{Canon,Policy,Uuid,Snapshot,
 * IdentityNotes}.php against manifest fixture files this test writes into a
 * scratch DUO_MANIFESTS_DIR — the same idiom as
 * sandbox/tests/regress_adapter_contract.sh (DUO-3222/DUO-3243). No $wpdb
 * stub is needed at all: every path exercised here is either manifest
 * validation or the two pure natural-key derivation helpers, which is itself
 * the DUO-3318 grammar-split claim being demonstrated (a declaration is
 * refusable with no database in the process).
 *
 * What this file does NOT cover, because it genuinely needs a live target:
 * capture/apply of a parent-scoped natural key end to end, cross-environment
 * UUID equality against genuinely different local ids, and rename-as-ordinary-
 * update continuity — sandbox/tests/regress_parent_scoped_natural_key.sh owns
 * those against a real database pair.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

// WordPress supplies this in production. The offline harness exposes a
// switchable equivalent so Policy::load()'s real v1 single-site gate is
// exercised without bootstrapping WordPress or replacing the product path.
$GLOBALS['duo_test_is_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['duo_test_is_multisite'];
}

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Uuid.php';
require __DIR__ . '/../../agent/src/Snapshot.php';
require __DIR__ . '/../../agent/src/IdentityNotes.php';

use Duo\Canon;
use Duo\IdentityNotes;
use Duo\Policy;
use Duo\Snapshot;
use Duo\Uuid;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function expect_throw(callable $fn, string $needle, string $msg): void {
    try {
        $fn();
        check(false, "$msg (expected a RuntimeException containing '$needle', none thrown)");
    } catch (\RuntimeException $e) {
        check(
            str_contains($e->getMessage(), $needle),
            "$msg (message: {$e->getMessage()})"
        );
    }
}

/** Fresh scratch manifests dir for one check; auto-removed at exit. */
function fresh_manifests_dir(array $files): void {
    $root = sys_get_temp_dir() . '/duo_regress_vocab_ownership_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    foreach ($files as $name => $content) {
        Canon::write_file("$root/$name.json", Canon::encode($content));
    }
    register_shutdown_function(function () use ($root) {
        foreach (glob("$root/*") ?: [] as $f) {
            unlink($f);
        }
        @rmdir($root);
    });
    putenv("DUO_MANIFESTS_DIR=$root");
}

/** Site repo carrying only the policy under test; pins are supplied by the caller. */
function fresh_site_repo(array $manifests, array $policy = []): string {
    $root = sys_get_temp_dir() . '/duo_regress_vocab_site_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    Canon::write_file("$root/site.duo.json", Canon::encode([
        'manifests' => $manifests,
        'policy' => $policy === [] ? new \stdClass() : $policy,
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    register_shutdown_function(function () use ($root) {
        @unlink("$root/site.duo.json");
        @rmdir($root);
    });
    return $root;
}

/**
 * The frozen-snapshot entry point, built from the same manifest bytes.
 * Present in almost every check below on purpose: DUO-3318's first latent bug
 * was a validator wired into load() and silently missing here, which made a
 * verification process reach a verdict the process that froze the policy
 * could not have reached.
 */
function load_frozen(array $manifests): Policy {
    return Policy::from_snapshot([
        'capabilities' => null,
        'dispositions' => null,
        'format' => 'duo-policy-snapshot/v3',
        'manifests' => $manifests,
        // A real snapshot has been through Canon::decode(), so every object is
        // already a PHP array by the time from_snapshot() sees it.
        'site' => [
            'manifests' => array_map(static fn(array $m): string => (string) $m['name'], $manifests),
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
            'spec_version' => DUO_SPEC_VERSION,
        ],
    ]);
}

// ---------------------------------------------------------------- fixtures

/**
 * Adapter A: an ordinary, well-formed plugin manifest. It owns a post type
 * (with a body mode, a phase, and a derived-field claim), an option
 * namespace, a table keyspace, and a provider. Everything manifest B tries
 * below is an attempt to reach into one of those.
 */
function manifest_a(array $overrides = []): array {
    return array_merge([
        'name' => 'a',
        'spec_version' => DUO_SPEC_VERSION,
        'plugin' => 'acme-a/acme-a.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'option_namespaces' => [['match' => '^acme_a_']],
        'options' => ['acme_a_setting' => ['class' => 'authored', 'autoload' => 'yes']],
        'post_types' => [
            'acme_thing' => [
                'class' => 'authored',
                'body' => 'verbatim',
                'phase' => 'early',
                'fields' => ['modified' => ['class' => 'derived']],
                'regen_dependency' => [
                    'regenerator' => 'acme-a',
                    'verify' => ['table' => 'acme_a_index', 'column' => 'room_id'],
                ],
            ],
        ],
        'providers' => [[
            'id' => 'acme-a-cache',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'acme-a/acme-a.php',
            'capabilities' => ['flush'],
        ]],
        'tables' => [
            'acme_a_rooms' => [
                'class' => 'authored_snapshot',
                'id_kind' => 'acme_room',
                'pk' => 'room_id',
                'slug_column' => 'room_code',
                'columns' => ['room_code' => ['class' => 'authored']],
                'refs' => [],
                'identity' => ['mode' => 'natural_key', 'column' => 'room_code'],
            ],
        ],
    ], $overrides);
}

/**
 * Adapter B: the second plugin. Its POSITIVE form is the intended extension
 * path in full — it declares its own post type with its own body/phase/field
 * claims, its own provider, and a child table whose authored key is unique
 * only WITHIN its parent room, expressed as a parent-scoped natural key whose
 * first component is a ref into a table adapter A owns.
 */
function manifest_b(array $overrides = []): array {
    return array_merge([
        'name' => 'b',
        'spec_version' => DUO_SPEC_VERSION,
        'plugin' => 'acme-b/acme-b.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'option_namespaces' => [['match' => '^acme_b_']],
        'post_types' => [
            'acme_widget' => [
                'class' => 'authored',
                'body' => 'verbatim',
                'phase' => 'early',
                'fields' => ['title' => ['class' => 'derived']],
            ],
        ],
        'providers' => [[
            'id' => 'acme-b-cache',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'acme-b/acme-b.php',
            'capabilities' => ['flush'],
        ]],
        'tables' => [
            'acme_b_slots' => [
                'class' => 'authored_snapshot',
                'id_kind' => 'acme_slot',
                'pk' => 'slot_id',
                'slug_column' => 'slot_code',
                'columns' => ['slot_code' => ['class' => 'authored']],
                'refs' => [['column' => 'room_id', 'kind' => 'acme_room']],
                'identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']],
                'invalidate' => [['table' => 'acme_b_cache', 'column' => 'slot_id'], ['option_pattern' => 'acme_b_slot_{id}']],
            ],
        ],
    ], $overrides);
}

/** One-off variant of B, loaded beside an unmodified A. */
function load_pair(array $b, ?array $a = null): Policy {
    $a ??= manifest_a();
    fresh_manifests_dir(['a' => $a, 'b' => $b]);
    return Policy::load(null, ['a', 'b']);
}

function refuse_pair(array $b, string $needle, string $msg, ?array $a = null): void {
    expect_throw(fn() => load_pair($b, $a), $needle, $msg);
}

/** One-off variant of B loaded ALONE (no A pinned) — for the extension-path checks. */
function refuse_solo(array $b, string $needle, string $msg): void {
    expect_throw(
        function () use ($b) {
            fresh_manifests_dir(['b' => $b]);
            Policy::load(null, ['b']);
        },
        $needle,
        $msg
    );
}

// ======================================================================
echo "\n== the intended extension path: two independent adapters load clean, side by side ==\n";

$policy = load_pair(manifest_b());
check(true, 'two well-formed adapters — each owning its own post type, provider, option namespace, and table keyspace — load together without complaint');
check(
    $policy->body_mode('acme_thing') === 'verbatim' && $policy->body_mode('acme_widget') === 'verbatim',
    'each adapter\'s own post-type body mode resolves to its own declaration'
);
check(
    $policy->post_type_phase('acme_widget') === 'early' && $policy->post_type_phase('unrelated_type') === 'normal',
    'an undeclared post type keeps the default phase — declaring is opt-in, not a global switch'
);
check(
    $policy->field_class('acme_widget', 'title') === 'derived'
        && $policy->field_class('acme_thing', 'title') === 'authored',
    'a derived-field claim covers exactly the declaring adapter\'s own post type'
);
$slots = $policy->declared_tables()['acme_b_slots'] ?? [];
check(
    Policy::natural_key_columns($slots) === ['room_id', 'slot_code'],
    'the parent-scoped natural key survives load as a declared, ordered component list'
);
load_frozen([manifest_a(), manifest_b()]);
check(true, 'the identical pair re-validates through the frozen-snapshot entry point, not just through load()');

echo "\n== acceptance 4: extension may not grant one adapter authority over another adapter's state ==\n";

refuse_pair(
    manifest_b(['post_types' => ['acme_thing' => ['fields' => ['title' => ['class' => 'derived']]]] + manifest_b()['post_types']]),
    'both declare post_types.acme_thing.fields',
    'B cannot restate A\'s derived-field claim for A\'s post type when the two declarations differ'
);
refuse_pair(
    manifest_b(['post_types' => ['acme_thing' => ['body' => 'blocks']] + manifest_b()['post_types']]),
    'both declare post_types.acme_thing.body',
    'B cannot flip the body mode of a post type A owns'
);
refuse_pair(
    manifest_b(['post_types' => ['acme_thing' => ['phase' => 'normal']] + manifest_b()['post_types']]),
    'both declare post_types.acme_thing.phase',
    'B cannot flip the apply phase of a post type A owns'
);
refuse_pair(
    manifest_b(['post_types' => ['acme_thing' => ['regen_dependency' => [
        'regenerator' => 'acme-b', 'verify' => ['table' => 'acme_b_cache', 'column' => 'slot_id'],
    ]]] + manifest_b()['post_types']]),
    'both declare post_types.acme_thing.regen_dependency',
    'B cannot attach its own regenerator to a post type A owns'
);
$identicalRestatement = manifest_b();
$identicalRestatement['post_types']['acme_thing'] = ['body' => 'verbatim'];
load_pair($identicalRestatement);
check(true, 'an IDENTICAL restatement of the same key is redundant rather than ambiguous and is allowed through (same allowance the adapter-claim guard already makes)');

$namespaceGrab = load_pair(manifest_b(['option_namespaces' => [['match' => '^acme_a_']]]));
expect_throw(
    fn() => $namespaceGrab->option_namespace('acme_a_setting'),
    'claimed by overlapping namespaces',
    'B cannot claim discovery ownership of A\'s option namespace — ownership must not depend on pin order'
);
refuse_pair(
    manifest_b([
        'plugin' => 'acme-a/acme-a.php',
        'version_range' => ['min' => '3.0.0', 'max' => '4.0.0'],
        // Its own provider has to follow the plugin claim, or the provider
        // validator refuses first for a different (also correct) reason.
        'providers' => [[
            'id' => 'acme-b-cache', 'version' => '1.0.0', 'source' => 'manifest',
            'plugin' => 'acme-a/acme-a.php', 'capabilities' => ['flush'],
        ]],
    ]),
    'different version_range values',
    'B cannot re-declare A\'s plugin under its own version window'
);
refuse_pair(
    manifest_b(['providers' => [[
        'id' => 'acme-a-cache', 'version' => '9.0.0', 'source' => 'manifest',
        'plugin' => 'acme-b/acme-b.php', 'capabilities' => ['flush'],
    ]]]),
    "both declare provider id 'acme-a-cache'",
    'B cannot claim A\'s provider id — a provider id names one concrete implementation'
);
refuse_pair(
    manifest_b(['actions' => [['kind' => 'provider', 'provider' => 'acme-a-cache', 'capability' => 'flush', 'args' => []]]]),
    "must name a `providers` entry declared by manifest 'b'",
    'B\'s action cannot reach across and invoke A\'s provider'
);

echo "\n== ref kinds: engine-owned base vocabulary, adapter-owned extension by declaring a table ==\n";

refuse_pair(
    manifest_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'psot']]]),
    'reference kind vocabulary is closed',
    'a typo\'d ref kind is refused instead of silently dropping every value it names'
);
$typoPolicy = null;
expect_throw(
    fn() => load_pair(manifest_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'psot']]])),
    'declare the table, then name its id_kind',
    'the ref-kind refusal states the adapter-owned extension path, not just the rejected token'
);
load_pair(manifest_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'acme_room[]']]]));
check(true, 'a ref naming ANOTHER pinned adapter\'s declared id_kind is legal — the vocabulary is global once the owning table is pinned');
refuse_solo(
    manifest_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'acme_room']]]),
    'reference kind vocabulary is closed',
    'the SAME ref becomes illegal the moment the manifest that declares the owning table is not pinned — extension is by declaration, never by assertion'
);
refuse_pair(
    manifest_b(['block_attrs' => ['acme/b' => [['kind' => 'psot', 'path' => 'id', 'type' => 'int']]]]),
    'token kind vocabulary is closed',
    'a typo\'d block_attrs ref kind is refused (Tokens passes an unknown kind through as a ledger lookup, so this used to drop the ref with an ordinary dangling warning)'
);
refuse_pair(
    manifest_b(['block_attrs' => ['acme/b' => [['kind_from' => ['attr' => 'type', 'map' => ['thing' => 'psot']], 'path' => 'id', 'type' => 'int']]]]),
    'kind_from.map.thing',
    'a kind_from map value is held to the same closed vocabulary as a static kind, and the refusal names the exact map entry'
);
refuse_pair(
    manifest_b(['shortcode_attrs' => ['acme_b' => [['kind' => 'psot', 'path' => 'id']]]]),
    'token kind vocabulary is closed',
    'a typo\'d shortcode_attrs ref kind is refused too — one vocabulary, checked on every surface that names one'
);
refuse_pair(
    manifest_b(['post_meta' => ['_acme_b_data' => ['class' => 'authored', 'json_refs' => [['path' => '$.id', 'kind' => 'psot']]]]]),
    'post_meta._acme_b_data.json_refs[0].kind',
    'a structured-value ref kind is checked with the same vocabulary, and the refusal names its exact path'
);
load_pair(manifest_b(['options' => ['acme_b_user' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'user']]]));
check(true, "the classification vocabulary keeps 'user' (a login-serialized reference), which the token vocabulary deliberately does not — duo_map has no user keyspace");
refuse_pair(
    manifest_b(['block_attrs' => ['acme/b' => [['kind' => 'user', 'path' => 'id', 'type' => 'int']]]]),
    'token kind vocabulary is closed',
    "'user' is refused as a block-attribute kind for that same reason — two vocabularies, because they answer different questions"
);

echo "\n== post-type switches: closed vocabularies with a named owner ==\n";

refuse_pair(
    manifest_b(['post_types' => ['acme_widget' => ['class' => 'authored', 'body' => 'verbatm']]]),
    "post_types.acme_widget.body='verbatm' but the vocabulary is closed (blocks, verbatim)",
    'a misspelled body mode is refused instead of silently meaning "blocks" and corrupting the serialized bodies the declaration exists to protect'
);
refuse_pair(
    manifest_b(['post_types' => ['acme_widget' => ['class' => 'authored', 'phase' => 'earliest']]]),
    "post_types.acme_widget.phase='earliest' but the vocabulary is closed (normal, early)",
    'a misspelled phase is refused instead of silently reverting to glob-alphabetical apply ordering'
);
refuse_pair(
    manifest_b(['post_types' => ['acme_widget' => ['class' => 'authored', 'fields' => ['slug' => ['class' => 'derived']]]]]),
    'The post-field vocabulary is engine-owned',
    'an unsupported derivable field names the engine as the owner and the spec bump as the path'
);
check(
    array_keys(Policy::DERIVABLE_FIELD_COLUMNS) === ['title', 'modified', 'modified_gmt'],
    'the derivable-field allowlist and Apply\'s wp_posts column map are one declaration, so a widened allowlist cannot ship without its column mapping'
);

echo "\n== table declarations: class, identity mode, and invalidation are engine-owned ==\n";

refuse_pair(
    manifest_b(['tables' => ['acme_b_slots' => ['class' => 'authored_snaphot', 'pk' => 'slot_id', 'id_kind' => 'acme_slot']]]),
    'the table class vocabulary is closed',
    'a transposed table class is refused instead of silently dropping the whole table out of capture'
);
refuse_pair(
    manifest_b(['tables' => ['acme_b_slots' => array_merge(
        manifest_b()['tables']['acme_b_slots'],
        ['identity' => ['mode' => 'natrual_key', 'column' => 'slot_code']]
    )]]),
    'the identity vocabulary is closed and engine-owned',
    'an unknown identity mode enumerates all three modes and says which table SHAPE each serves'
);
refuse_pair(
    manifest_b(['tables' => ['acme_b_slots' => array_merge(
        manifest_b()['tables']['acme_b_slots'],
        ['invalidate' => [['table' => 'acme_b_cache', 'colum' => 'slot_id']]]
    )]]),
    'the invalidation vocabulary is closed and engine-owned',
    'a misspelled invalidate key is refused instead of meaning "this cache is never invalidated"'
);
refuse_pair(
    manifest_b(['tables' => ['acme_b_slots' => array_merge(
        manifest_b()['tables']['acme_b_slots'],
        ['invalidate' => [['option_pattern' => 'acme_b_slot_cache']]]
    )]]),
    'must be a string containing the {id} substitution point',
    'an option_pattern with no {id} is refused — without it every row names the same one option row'
);

echo "\n== the parent-scoped natural key: declaration grammar ==\n";

$slotDecl = static fn(array $identity, array $extra = []): array => manifest_b(['tables' => ['acme_b_slots' => array_merge(
    manifest_b()['tables']['acme_b_slots'],
    ['identity' => $identity],
    $extra
)]]);

refuse_pair(
    $slotDecl(['mode' => 'natural_key', 'column' => 'slot_code', 'columns' => ['room_id', 'slot_code']]),
    "BOTH 'column' and 'columns'",
    'the two spellings of one vocabulary may not both appear'
);
refuse_pair(
    $slotDecl(['mode' => 'natural_key', 'columns' => []]),
    'a derived identity needs at least one authored component',
    'an empty component list is refused'
);
refuse_pair(
    $slotDecl(['mode' => 'natural_key', 'columns' => ['slot_id', 'slot_code']]),
    'names its primary key',
    'the surrogate primary key is refused BY NAME as an identity component — deriving from it would mint a different uuid per environment'
);
refuse_pair(
    $slotDecl(['mode' => 'natural_key', 'columns' => ['room_id', 'room_id']]),
    "repeats identity column 'room_id'",
    'a repeated component is refused — it adds no distinguishing power and makes declared order ambiguous'
);
refuse_pair(
    $slotDecl(['mode' => 'natural_key', 'columns' => ['room_id', 'slot_nmae']]),
    'neither a declared refs[] column nor a declared columns{} entry',
    'a component naming an unclassified column is refused — capture would have nothing portable to derive from'
);
$noSlug = $slotDecl(['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']]);
unset($noSlug['tables']['acme_b_slots']['slug_column']);
expect_throw(
    fn() => load_pair($noSlug),
    "without 'slug_column'",
    'a multi-column key must declare slug_column — a tuple has no portable one-line filename spelling'
);
$compositeWithPk = manifest_b(['tables' => ['acme_b_slots' => array_merge(
    manifest_b()['tables']['acme_b_slots'],
    ['identity' => ['mode' => 'composite_ref', 'columns' => ['room_id', 'slot_code']]]
)]]);
expect_throw(
    fn() => load_pair($compositeWithPk),
    "composite_ref AND a 'pk'",
    'composite_ref\'s own invariants are unchanged and now also refuse offline, at load, not only at capture'
);

echo "\n== the parent-scoped natural key: derivation ==\n";

$roomUuid = Uuid::v5(Uuid::NAMESPACE_DUO, 'acme_a_rooms:studio-one');
$otherRoomUuid = Uuid::v5(Uuid::NAMESPACE_DUO, 'acme_a_rooms:studio-two');
$slotTable = manifest_b()['tables']['acme_b_slots'];
$roomTable = manifest_a()['tables']['acme_a_rooms'];

check(
    Snapshot::natural_key_name('acme_a_rooms', $roomTable, ['room_code' => 'studio-one']) === 'acme_a_rooms:studio-one',
    'the SINGLE-component derivation string is frozen at "<table>:<value>" — every natural_key uuid ever minted derives from it'
);
$components = Snapshot::natural_key_components_from_front($slotTable, [
    'room_id' => '{{acme_room:' . $roomUuid . '}}',
    'slot_code' => 'morning',
]);
check(
    $components === ['room_id' => $roomUuid, 'slot_code' => 'morning'],
    'a ref component contributes the REFERENCED ROW\'S OWN uuid, read straight out of the token the file already carries'
);
check(
    Snapshot::natural_key_name('acme_b_slots', $slotTable, $components)
        === "acme_b_slots:room_id=$roomUuid:slot_code=morning",
    'the multi-component derivation string names each component in DECLARED order'
);
$reordered = $slotTable;
$reordered['identity']['columns'] = ['slot_code', 'room_id'];
check(
    Snapshot::natural_key_name('acme_b_slots', $reordered, $components)
        !== Snapshot::natural_key_name('acme_b_slots', $slotTable, $components),
    'reordering identity.columns changes the derived identity — declared order is load-bearing, which is why the grammar preserves it instead of sorting'
);
$sameSlotOtherRoom = Snapshot::natural_key_components_from_front($slotTable, [
    'room_id' => '{{acme_room:' . $otherRoomUuid . '}}',
    'slot_code' => 'morning',
]);
check(
    Uuid::v5(Uuid::NAMESPACE_DUO, Snapshot::natural_key_name('acme_b_slots', $slotTable, $components))
        !== Uuid::v5(Uuid::NAMESPACE_DUO, Snapshot::natural_key_name('acme_b_slots', $slotTable, $sameSlotOtherRoom)),
    'the SAME slot code under a DIFFERENT room is a different identity — which is the entire reason a parent-scoped key cannot be a single column'
);
check(
    Snapshot::natural_key_components_from_front($slotTable, ['slot_code' => 'morning']) === null,
    'a file missing a component derives nothing rather than deriving something wrong'
);

$derivedUuid = Uuid::v5(Uuid::NAMESPACE_DUO, Snapshot::natural_key_name('acme_b_slots', $slotTable, $components));
check(
    IdentityNotes::natural_key_continuity($derivedUuid, 'acme_b_slots', $slotTable, [
        'room_id' => '{{acme_room:' . $roomUuid . '}}',
        'slot_code' => 'morning',
    ]) === null,
    'a freshly bootstrapped tuple identity is not reported as a rename'
);
check(
    IdentityNotes::natural_key_continuity($derivedUuid, 'acme_b_slots', $slotTable, [
        'room_id' => '{{acme_room:' . $roomUuid . '}}',
        'slot_code' => 'evening',
    ]) === "acme_b_slots row room_id=$roomUuid, slot_code=evening: renamed since first capture (uuid retained via ledger)",
    'a renamed component reports the ordinary ledger-continuity note, naming every component so a reader can tell which parent it belongs to'
);

echo "\n== widgets, block/shortcode attribute rules: structural grammar at load time ==\n";

refuse_pair(
    manifest_b(['widgets' => ['acme_b' => ['fields' => ['title' => ['class' => 'authored']]]]]),
    'must declare a non-empty `settings` object',
    'a widget type with no settings allowlist is refused — capture refuses undeclared settings, so an absent map makes every instance uncapturable'
);
refuse_pair(
    manifest_b(['widgets' => ['acme_b' => ['settings' => ['title' => ['class' => 'runtime']]]]]),
    'must declare class=authored',
    'a non-authored widget setting is refused — exclusion is expressed by leaving the field out'
);
refuse_pair(
    manifest_b(['widgets' => ['acme_b' => ['settings' => ['body' => ['class' => 'authored', 'codec' => 'markdown']]]]]),
    'codec vocabulary is closed and engine-owned',
    'an unsupported widget settings codec is refused at load, not at the first sidebar capture'
);
refuse_pair(
    manifest_b(['widgets' => ['acme_b' => ['settings' => ['owner' => ['class' => 'authored', 'ref' => 'post']]]]]),
    'ref vocabulary is closed and engine-owned',
    'a widget settings ref kind outside the engine-implemented one is refused'
);
refuse_pair(
    manifest_b(['block_attrs' => ['acme/b' => [['path' => 'id', 'type' => 'int']]]]),
    'declares none of kind, kind_from, tokenize, or lint_ok',
    'a block attribute rule with no disposition is refused here instead of throwing mid-capture on whichever post carried the block first'
);
refuse_pair(
    manifest_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => 'ids', 'type' => 'array']]]]),
    'attribute-value vocabulary is closed and engine-owned (int, int[])',
    'an unsupported attribute value type is refused instead of quietly truncating a list to one dropped attribute'
);
refuse_pair(
    manifest_b(['block_attrs' => ['acme/b' => [['kind' => 'post', 'path' => '', 'type' => 'int']]]]),
    'must be a non-empty attribute name',
    'an empty attribute path is refused — an unmatched path is silently skipped at rewrite time'
);

echo "\n== effect grammar: one refusal per closed vocabulary, each naming its own token ==\n";

$effectAction = static fn(array $effect): array => manifest_b(['actions' => [[
    'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'acme_b'], 'effects' => [$effect],
]]]);
refuse_pair(
    $effectAction(['id' => 'e', 'kind' => 'telepathy', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'acme_b_setting']]),
    "kind='telepathy' is not one of the engine-owned effect kinds",
    'an unsupported effect kind names the token and prints the legal set'
);
refuse_pair(
    $effectAction(['id' => 'e', 'kind' => 'database', 'mode' => 'telekinesis', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'acme_b_setting']]),
    "mode='telekinesis' is not one of the engine-owned reversibility modes",
    'an unsupported reversibility mode is a SEPARATE refusal from kind, so an author is never left bisecting their own declaration'
);
refuse_pair(
    $effectAction(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'checkpoint', 'type' => 'option', 'value' => 'acme_b_setting']]),
    "selector.scope='checkpoint' is not one of the engine-owned scopes",
    'a selector scope typo names itself instead of hiding inside a four-cause message'
);
refuse_pair(
    $effectAction(['id' => 'e', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'row', 'value' => 'acme_b_setting']]),
    "selector.type='row' is not one of the engine-owned selector types",
    'a selector type typo names itself and points at provider_resource as the adapter-side path'
);
refuse_pair(
    $effectAction(['id' => 'e', 'kind' => 'cache', 'mode' => 'irreversible', 'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'acme_*']]),
    'contains a wildcard or control character',
    'a wildcard selector value names the wildcard as the cause, not "empty, unbounded, secret-shaped, or unsupported"'
);
refuse_pair(
    $effectAction(['id' => 'e', 'kind' => 'cache', 'mode' => 'irreversible', 'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'acme_api_key_store']]),
    'is secret-shaped',
    'a secret-shaped selector value names secrecy as the cause'
);

echo "\n== the frozen-snapshot entry point validates exactly what load() does ==\n";

// DUO-3318 latent bug L1: validate_dynamic_options() ran in load() and not in
// from_snapshot(), so a manifest the live path refused would verify clean in
// the fresh verification process — the one place the two verdicts must agree.
$badResolver = manifest_b(['dynamic_options' => ['theme_mods' => [
    'prefix' => 'theme_mods_',
    'resolver' => 'active_plugin',
    'sub_keys' => ['acme' => ['class' => 'authored']],
    'autoload' => 'yes',
]]]);
expect_throw(
    fn() => load_pair($badResolver),
    'only active_stylesheet is supported in v1',
    'an unsupported dynamic_options resolver is refused by load()'
);
expect_throw(
    fn() => load_frozen([manifest_a(), $badResolver]),
    'only active_stylesheet is supported in v1',
    'the SAME manifest is refused by from_snapshot() — the two entry points reach the same verdict (DUO-3318 L1)'
);
foreach ([
    'table class' => manifest_b(['tables' => ['acme_b_slots' => ['class' => 'authored_snaphot', 'pk' => 'x', 'id_kind' => 'y']]]),
    'post-type body' => manifest_b(['post_types' => ['acme_widget' => ['class' => 'authored', 'body' => 'verbatm']]]),
    'ref kind' => manifest_b(['options' => ['acme_b_ref' => ['class' => 'authored', 'autoload' => 'yes', 'ref' => 'psot']]]),
] as $label => $bad) {
    expect_throw(
        fn() => load_frozen([manifest_a(), $bad]),
        'duo: ',
        "from_snapshot() also refuses a bad $label declaration"
    );
}

echo "\n== site.duo.json's own policy.tables override is held to the same grammar ==\n";

fresh_manifests_dir(['a' => manifest_a()]);
expect_throw(
    fn() => Policy::load(fresh_site_repo(['a'], ['tables' => [
        'acme_a_rooms' => ['class' => 'authored_snaphot', 'pk' => 'room_id', 'id_kind' => 'acme_room'],
    ]])),
    'the table class vocabulary is closed',
    'a site-policy table override is validated too — declared_tables() merges it LAST, so a malformed one is exactly as fatal as a malformed manifest'
);
Policy::load(fresh_site_repo(['a']));
check(true, 'an ordinary site repo pinning the same manifest still loads cleanly');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
