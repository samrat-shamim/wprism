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
 * Runs the REAL, unmodified agent/src/{Canon,Policy,Uuid,Ledger,Tokens,
 * Snapshot,IdentityNotes}.php against manifest fixture files this test writes
 * into a scratch DUO_MANIFESTS_DIR — the same idiom as
 * sandbox/tests/regress_adapter_contract.sh (DUO-3222/DUO-3243).
 *
 * Most of the file needs no database at all, which is itself the DUO-3318
 * grammar-split claim being demonstrated: a declaration is refusable with no
 * database in the process. The last two groups do need one, because the two
 * DIRECTIONS of the parent-scoped key — deriving identity off a live row, and
 * matching an existing unmanaged row for adoption — are the halves that read
 * the environment. They use the minimal fake $wpdb below rather than a live
 * target: same "real engine code, fake database" approach as
 * regress_composite_ref.php / regress_block_refs.php, and a deliberately
 * smaller stand-in than either (this file's paths issue six query shapes and
 * mutate nothing but duo_map).
 *
 * What this file does NOT cover, because it genuinely needs a live target:
 * apply of a parent-scoped natural key end to end, cross-environment UUID
 * equality against two real auto-increment sequences, and rename-as-ordinary-
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

// ---------------------------------------------------------------- WP stubs

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
function get_option($name, $default = false) {
    return ['home' => 'http://example.test'][$name] ?? $default;
}
function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) {
    return ['baseurl' => 'http://example.test/wp-content/uploads', 'basedir' => sys_get_temp_dir() . '/duo-uploads'];
}
function untrailingslashit($string) {
    return rtrim((string) $string, '/\\');
}
function sanitize_title($s) {
    return strtolower(trim((string) $s));
}

/**
 * Stands in for exactly the six query shapes the two groups at the end of
 * this file issue — read by reading agent/src/{Ledger,Snapshot}.php directly,
 * the discipline regress_block_refs.php's own FakeWpdb docblock states, NOT a
 * general SQL engine. Rows are read-only here (nothing in this file inserts or
 * updates an authored row); duo_map is the only thing that changes, because
 * capture mints identity as a side effect of the derivation being tested.
 */
final class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    /** @var array<string, array<int, string>> id_kind => [local_id => uuid] */
    public $identity = [];
    /** @var array<string, array<int, string>> id_kind => [local_id => entity_type] */
    public $identityType = [];
    /** @var array<string, array{columns: array<string,string>, rows: list<array<string,mixed>>}> */
    public $tables = [];

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return ['__prepared' => true, 'sql' => $query, 'args' => $args];
    }

    public function get_var($prepared) {
        [$sql, $args] = $this->unwrap($prepared);
        if (str_contains($sql, 'SHOW TABLES LIKE')) {
            $unprefixed = $this->strip_prefix((string) $args[0]);
            return isset($this->tables[$unprefixed]) ? (string) $args[0] : null;
        }
        if (str_contains($sql, 'SELECT local_id FROM') && str_contains($sql, 'duo_map')) {
            [$uuid, $kind] = $args;
            foreach ($this->identity[$kind] ?? [] as $localId => $u) {
                if ($u === $uuid) {
                    return $localId;
                }
            }
            return null;
        }
        if (str_contains($sql, 'SELECT uuid FROM') && str_contains($sql, 'duo_map')) {
            [$kind, $localId] = $args;
            return $this->identity[$kind][(int) $localId] ?? null;
        }
        // Snapshot::find_collision()'s natural-key lookup: one projected
        // column, one AND-joined equality predicate per identity component,
        // in declared order — matched positionally against prepare()'s args.
        if (preg_match('/^SELECT `([^`]+)` FROM `([^`]+)` WHERE (.+) LIMIT 1$/s', trim($sql), $m)) {
            $rows = $this->tables[$this->strip_prefix($m[2])]['rows'] ?? [];
            preg_match_all('/`([^`]+)` = %[ds]/', $m[3], $cols);
            foreach ($rows as $row) {
                $hit = true;
                foreach ($cols[1] as $i => $col) {
                    if ((string) ($row[$col] ?? '') !== (string) ($args[$i] ?? '')) {
                        $hit = false;
                        break;
                    }
                }
                if ($hit) {
                    return $row[$m[1]] ?? null;
                }
            }
            return null;
        }
        throw new \RuntimeException("FakeWpdb::get_var: unrecognized query shape: $sql");
    }

    public function get_row($prepared, $output = ARRAY_A) {
        [$sql, $args] = $this->unwrap($prepared);
        if (str_contains($sql, 'SELECT entity_type, local_id FROM') && str_contains($sql, 'duo_map')) {
            [$uuid, $kind] = $args;
            foreach ($this->identity[$kind] ?? [] as $localId => $candidate) {
                if ($candidate === $uuid) {
                    return ['entity_type' => $this->identityType[$kind][$localId] ?? '', 'local_id' => $localId];
                }
            }
            return null;
        }
        if (str_contains($sql, 'SELECT uuid, entity_type FROM') && str_contains($sql, 'duo_map')) {
            [$kind, $localId] = $args;
            $localId = (int) $localId;
            return isset($this->identity[$kind][$localId])
                ? [
                    'uuid' => $this->identity[$kind][$localId],
                    'entity_type' => $this->identityType[$kind][$localId] ?? '',
                ]
                : null;
        }
        throw new \RuntimeException("FakeWpdb::get_row: unrecognized query shape: $sql");
    }

    public function get_results($prepared, $output = ARRAY_A) {
        [$sql, ] = $this->unwrap($prepared);
        $sql = trim($sql);
        if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`/', $sql, $m)) {
            $out = [];
            foreach ($this->tables[$this->strip_prefix($m[1])]['columns'] ?? [] as $name => $type) {
                $out[] = ['Field' => $name, 'Type' => $type];
            }
            return $out;
        }
        if (preg_match('/^SELECT \* FROM `([^`]+)` ORDER BY/', $sql, $m)) {
            return $this->tables[$this->strip_prefix($m[1])]['rows'] ?? [];
        }
        throw new \RuntimeException("FakeWpdb::get_results: unrecognized query shape: $sql");
    }

    public function query($prepared) {
        [$sql, $args] = $this->unwrap($prepared);
        if (str_starts_with(trim($sql), 'INSERT INTO') && str_contains($sql, 'duo_map')) {
            [$uuid, $entityType, $kind, $localId] = $args;
            $this->identity[$kind][(int) $localId] = $uuid;
            $this->identityType[$kind][(int) $localId] = $entityType;
            return 1;
        }
        return 1; // no other mutation is reachable from this file's paths
    }

    private function strip_prefix(string $prefixed): string {
        return str_starts_with($prefixed, $this->prefix) ? substr($prefixed, strlen($this->prefix)) : $prefixed;
    }

    private function unwrap($prepared): array {
        return is_array($prepared) && ($prepared['__prepared'] ?? false)
            ? [$prepared['sql'], $prepared['args']]
            : [(string) $prepared, []];
    }
}

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Uuid.php';
require __DIR__ . '/../../agent/src/Secrets.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/Tokens.php';
require __DIR__ . '/../../agent/src/Snapshot.php';
require __DIR__ . '/../../agent/src/SidebarState.php';
require __DIR__ . '/../../agent/src/IdentityNotes.php';

use Duo\Canon;
use Duo\IdentityNotes;
use Duo\Policy;
use Duo\SidebarState;
use Duo\Snapshot;
use Duo\Tokens;
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
    // The fixtures' code half (manifest A's declared regenerator) travels with
    // their JSON half — see manifest_fixtures.php's manifest_fixture_code().
    manifest_fixture_code($root);
    register_shutdown_function(function () use ($root) {
        manifest_fixture_code_cleanup($root);
        foreach (glob("$root/*") ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
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
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'format' => 'duo-policy-snapshot/v4',
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

// manifest_a()/manifest_b() were established here and now live in
// sandbox/tests/manifest_fixtures.php, so DUO-3327's offline authoring aid
// exercises the identical declarations rather than a second copy of them.
// See that file's header for why it is not named regress_*.
require __DIR__ . '/manifest_fixtures.php';

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
echo "\n== B1: one owner per declared NAME, on all three bulk-declaration surfaces ==\n";

// The per-key guard above can only see a contradiction about the SAME key.
// These are the cases it never saw: a partial restatement whose every shared
// key AGREES, and the two surfaces (tables, widgets) that have no per-key
// guard at all because their lookups take the LAST pin rather than the first.
$partialRestatement = manifest_b();
$partialRestatement['post_types']['acme_thing'] = ['body' => 'verbatim']; // identical to A's own body
refuse_pair(
    $partialRestatement,
    'both declare post_types.acme_thing with different declarations',
    'a PARTIAL restatement of another adapter\'s post type is refused even though every key it repeats agrees — the two declarations are not the same declaration, so one of them would silently lose'
);
expect_throw(
    fn() => load_pair($partialRestatement),
    'Pin only one declaring manifest, or make the two declarations byte-identical',
    'the one-owner refusal states the resolution path, and that v1 has no composition grammar for the surface'
);
$wholeRestatement = manifest_b();
$wholeRestatement['post_types']['acme_thing'] = manifest_a()['post_types']['acme_thing'];
load_pair($wholeRestatement);
check(true, 'a BYTE-IDENTICAL whole declaration is redundant rather than ambiguous and is allowed through (same allowance the option-rule and version-range guards already make)');

refuse_pair(
    manifest_b(['tables' => manifest_b()['tables'] + ['acme_a_rooms' => array_merge(
        manifest_a()['tables']['acme_a_rooms'],
        ['slug_column' => 'room_label', 'columns' => ['room_code' => ['class' => 'authored'], 'room_label' => ['class' => 'authored']]]
    )]]),
    'both declare tables.acme_a_rooms',
    'B cannot re-declare a table A owns — declared_tables() takes the LAST pin, so B\'s declaration would silently replace A\'s'
);
load_pair(manifest_b(['tables' => manifest_b()['tables'] + ['acme_a_rooms' => manifest_a()['tables']['acme_a_rooms']]]));
check(true, 'a byte-identical table restatement is allowed through, on the same terms');

$widget = ['settings' => ['title' => ['class' => 'authored']]];
refuse_pair(
    manifest_b(['widgets' => ['acme_shared' => ['settings' => ['title' => ['class' => 'authored'], 'text' => ['class' => 'authored']]]]]),
    'both declare widgets.acme_shared',
    'B cannot re-declare a widget type A owns — widget_types() takes the LAST pin too',
    manifest_a(['widgets' => ['acme_shared' => $widget]])
);
load_pair(
    manifest_b(['widgets' => ['acme_shared' => $widget]]),
    manifest_a(['widgets' => ['acme_shared' => $widget]])
);
check(true, 'a byte-identical widget restatement is allowed through, on the same terms');

// taxonomies is the fourth surface of the same class (independent review's
// remaining finding): description_refs_for_taxonomy(), object_type_from_
// option, and the taxonomy class rule are all first-pin-wins with no
// precedence layer, so a second declarer is the identical silent takeover.
expect_throw(
    function (): void {
        load_pair(
            manifest_a(['taxonomies' => ['acme_tax' => ['class' => 'authored', 'description_refs' => ['kind' => 'post']]]]),
            manifest_b(['taxonomies' => ['acme_tax' => ['class' => 'runtime', 'description_refs' => ['kind' => 'term']]]])
        );
    },
    'both declare taxonomies.acme_tax',
    'B cannot re-declare a taxonomy A owns — every taxonomy lookup is first-pin-wins with no precedence layer'
);
load_pair(
    manifest_a(['taxonomies' => ['acme_tax' => ['class' => 'authored']]]),
    manifest_b(['taxonomies' => ['acme_tax' => ['class' => 'authored']]])
);
check(true, 'a byte-identical taxonomy restatement is allowed through, on the same terms');

// core is deliberately NOT exempt: the DUO-3249 core-yields-to-plugin layer
// is an option/meta RULE mechanism, and no lookup on these three surfaces
// implements it, so exempting core would reintroduce the coin flip.
expect_throw(
    function (): void {
        fresh_manifests_dir([
            'a' => manifest_a(),
            'core' => [
                'name' => 'core',
                'spec_version' => DUO_SPEC_VERSION,
                'tables' => ['acme_a_rooms' => ['class' => 'runtime']],
            ],
        ]);
        Policy::load(null, ['a', 'core']);
    },
    "manifests 'a' and 'core' both declare tables.acme_a_rooms",
    'core is not exempt from the one-owner rule — there is no ratified precedence layer for these surfaces to appeal to'
);
fresh_manifests_dir(['a' => manifest_a()]);
Policy::load(fresh_site_repo(['a'], ['tables' => [
    'acme_a_rooms' => array_merge(manifest_a()['tables']['acme_a_rooms'], [
        'slug_column' => 'room_label',
        'columns' => ['room_code' => ['class' => 'authored'], 'room_label' => ['class' => 'authored']],
    ]),
]]));
check(true, "site.duo.json's own policy.tables override is EXEMPT — the site's wholesale last word over its own state is not a second adapter reaching into the first");

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

echo "\n== S1: a fuzzed declaration produces a duo: refusal, never PHP coercion or a TypeError ==\n";

// Every case below used to be answered by PHP rather than by this engine:
// `??` swallowing an illegal string offset, array_column() returning [] for a
// list of strings, (string)[] evaluating to the non-empty "Array", or a raw
// TypeError out of array_keys(). expect_throw() catches \RuntimeException
// only, so a TypeError here would kill this script outright — which is
// exactly the failure this group is written to detect.
$mangled = static fn(array $overrides): array => manifest_b(['tables' => ['acme_b_slots' => array_merge(
    manifest_b()['tables']['acme_b_slots'],
    $overrides
)]]);

refuse_pair(
    $mangled(['identity' => 'natural_key']),
    'identity must be an OBJECT naming the mode',
    'a bare-string identity is refused instead of silently meaning identity.mode=mapped — the exact opposite of what the author wrote'
);
refuse_pair(
    $mangled(['refs' => ['room_id']]),
    'every refs[] entry must be an object declaring both `column` and `kind`',
    'a list-of-strings refs is refused instead of resolving to zero ref columns, which captures the raw parent id into canonical state'
);
refuse_pair(
    $mangled(['refs' => 'room_id']),
    'refs must be a LIST of',
    'a scalar refs produces this engine\'s own refusal, not the PHP TypeError array_column() used to raise'
);
refuse_pair(
    $mangled(['refs' => [['column' => 'room_id']]]),
    'refs[0].kind',
    'a ref entry missing its kind is refused, naming the entry and the missing half'
);
refuse_pair(
    $mangled(['columns' => 'slot_code']),
    'columns must be an object keyed by column name',
    'a scalar columns produces this engine\'s own refusal, not the PHP TypeError array_keys() used to raise'
);
refuse_pair(
    $mangled(['pk' => ['slot_id']]),
    'pk must be a non-empty string',
    'an array pk is refused instead of casting to the literal string "Array" and naming a column no table has'
);
refuse_pair(
    $mangled(['identity' => ['mode' => 'natural_key', 'columns' => 'slot_code']]),
    'identity.columns is the ordered LIST spelling',
    'a string identity.columns is refused, and the refusal states the exactly-one-spelling rule instead of silently wrapping the string in a 1-element list'
);
refuse_pair(
    $mangled(['identity' => ['mode' => 'natural_key', 'column' => ['slot_code']]]),
    'identity.column is the SINGLE-component spelling',
    'an array identity.column is refused, pointing at identity.columns as the multi-component spelling'
);

echo "\n== N4/S6: the duo_map keyspace — unique per table, and closed where a manifest names one ==\n";

refuse_pair(
    $mangled(['id_kind' => 'acme_room']),
    "id_kind 'acme_room' is declared by both",
    'two tables may not share one id_kind — duo_map is keyed by (id_kind, local_id), so they would resolve each other\'s rows (refused at LOAD, offline, not at the first capture)'
);
$optionNameRef = static fn(string $idKind): array => manifest_b(['option_name_refs' => [[
    'autoload' => 'yes',
    'class' => 'authored',
    'id_kind' => $idKind,
    'match' => '^acme_b_slot_(?<id>[1-9][0-9]*)_settings$',
]]]);
load_pair($optionNameRef('acme_slot'));
check(true, 'an option_name_refs rule naming a DECLARED table id_kind loads — that is the whole contract, since the embedded id names a row of that table');
refuse_pair(
    $optionNameRef('acme_slto'),
    'option_name_refs[0].id_kind',
    'a typo\'d option_name_refs id_kind is refused instead of resolving to a keyspace with no rows (which drops the option from canonical state)'
);
refuse_pair(
    $optionNameRef('post'),
    'ledger kind vocabulary is closed here',
    'even a REAL engine keyspace is refused for an option-name ref: the id embedded in an option name names a declared table row, not a post'
);
$guarded = static fn(array $guard): array => manifest_b(['deletions' => ['table:acme_b_slots' => [
    'cascades' => [],
    'guards' => [array_merge(['table' => 'acme_b_cache', 'column' => 'slot_id'], $guard)],
]]]);
load_pair($guarded(['id_kind' => 'term_taxonomy']));
check(true, "a deletion guard may name the ledger's own long spellings (post/term/term_taxonomy) — Apply looks the value up in duo_map verbatim");
load_pair($guarded(['id_kind' => 'acme_slot', 'source_id_kind' => 'acme_room', 'source_pk' => 'room_id']));
check(true, 'and any id_kind a pinned manifest declared for a table it owns, on both the guard and its source half');
refuse_pair(
    $guarded(['id_kind' => 'tt']),
    'ledger kind vocabulary is closed here',
    "the TOKEN spelling 'tt' is refused in a guard — this surface reaches Ledger::id_for() directly, where the keyspace is spelled term_taxonomy"
);
refuse_pair(
    $guarded(['id_kind' => 'acme_slot', 'source_id_kind' => 'acme_rooom', 'source_pk' => 'room_id']),
    'deletions.table:acme_b_slots.guards[0].source_id_kind',
    'a typo\'d source_id_kind is refused too, and the refusal names its exact path'
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
    'must be a non-empty list of column names',
    'an empty component list is refused'
);
refuse_pair(
    $slotDecl(['mode' => 'natural_key']),
    'a derived identity needs at least one authored component',
    'declaring the mode with NEITHER spelling is refused by the mode\'s own rule, which states what a derivation needs'
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

echo "\n== S4: the widget declaration grammar has exactly one implementation ==\n";

// SidebarState kept a hand-copy of these rules, reachable only through a live
// sidebar capture, and it had drifted permissive in three places — so a
// declaration could pass the sidebar path and be refused by the very next
// Policy::load(). Both now run the same function; these drive the SIDEBAR
// entry point (which needs no database for this half) and assert Policy's
// stricter reading arrives there too.
$widgetPolicy = static function (array $widgets): Policy {
    $p = new Policy();
    $p->manifests = [['widgets' => $widgets]];
    return $p;
};
expect_throw(
    fn() => SidebarState::assert_policy($widgetPolicy(['text' => ['settings' => []]])),
    'must declare a non-empty `settings` object',
    'an EMPTY settings map is refused through the sidebar path too (it used to pass there — an allowlist naming no field can never capture an instance)'
);
expect_throw(
    fn() => SidebarState::assert_policy($widgetPolicy(['text' => ['settings' => [['class' => 'authored']]]])),
    'must declare a non-empty `settings` object',
    'a settings LIST is refused through the sidebar path too (it used to pass there, then index by integer)'
);
expect_throw(
    fn() => SidebarState::assert_policy($widgetPolicy(['text' => ['settings' => [
        'title' => ['class' => 'authored', 'codec' => null],
    ]]])),
    'codec vocabulary is closed and engine-owned',
    'an explicitly-NULL codec is refused through the sidebar path too (isset() used to read a declared null as absent)'
);
expect_throw(
    fn() => SidebarState::assert_policy($widgetPolicy([str_repeat('m', 30) => ['settings' => [
        'title' => ['class' => 'authored'],
    ]]])),
    'exceeds duo_map.id_kind',
    "the derived-kind WIDTH budget stays SidebarState's own — it is Ledger's schema, not the manifest's grammar, which is why Policy's copy cannot make it"
);
SidebarState::assert_policy($widgetPolicy(['text' => ['settings' => [
    'title' => ['class' => 'authored'],
    'text' => ['class' => 'authored', 'codec' => 'blocks'],
]]]));
check(true, 'a well-formed widget declaration passes both halves unchanged');

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
// The two groups that need an environment. Everything above is a pure
// function of manifest bytes; these two are the halves that READ the target —
// deriving identity off a live row, and matching an existing unmanaged row
// for adoption — driven against the fake $wpdb at the top of this file.
// ======================================================================
echo "\n== S3: the CAPTURE direction — identity derived off a live row, with the parent's UUID ==\n";

/** The two fixture tables, at a chosen pair of local ids, with an empty ledger. */
function seed_agency(FakeWpdb $wpdb, int $roomId, int $slotId, string $slotCode = 'morning'): void {
    $wpdb->identity = [];
    $wpdb->identityType = [];
    $wpdb->tables = [
        'acme_a_rooms' => [
            'columns' => ['room_id' => 'bigint(20) unsigned', 'room_code' => 'varchar(64)'],
            'rows' => [['room_id' => $roomId, 'room_code' => 'studio-one']],
        ],
        'acme_b_slots' => [
            'columns' => [
                'slot_id' => 'bigint(20) unsigned',
                'room_id' => 'bigint(20) unsigned',
                'slot_code' => 'varchar(64)',
            ],
            'rows' => [['slot_id' => $slotId, 'room_id' => $roomId, 'slot_code' => $slotCode]],
        ],
    ];
}

/** @return array<string,array> captured entity by table name */
function capture_agency(Policy $policy): array {
    $out = [];
    foreach (Snapshot::capture($policy, new Tokens(), true) as $entity) {
        $out[$entity['type']] = $entity;
    }
    return $out;
}

$policy = load_pair(manifest_b());
$roomUuid = Uuid::v5(Uuid::NAMESPACE_DUO, 'acme_a_rooms:studio-one');
$expectedSlotUuid = Uuid::v5(Uuid::NAMESPACE_DUO, "acme_b_slots:room_id=$roomUuid:slot_code=morning");

seed_agency($wpdb, 7, 3);
$captured = capture_agency($policy);
check(
    ($captured['acme_a_rooms']['uuid'] ?? null) === $roomUuid,
    'the parent row derives the frozen single-component identity off its own live value'
);
check(
    ($captured['acme_b_slots']['uuid'] ?? null) === $expectedSlotUuid,
    'the child row derives its identity from the PARENT ROW\'S UUID — live_natural_key_components() resolved the ref column through the ledger rather than using the raw local id sitting in it'
);
check(
    (Canon::decode($captured['acme_b_slots']['content'])['columns']['room_id'] ?? null)
        === "{{acme_room:$roomUuid}}",
    'and the captured file carries that same parent as a portable token, never the local id'
);

// The property the whole mode exists for, offline: nothing in either derived
// identity moves when this environment's auto-increment values do.
seed_agency($wpdb, 41, 96);
$other = capture_agency($policy);
check(
    ($other['acme_a_rooms']['uuid'] ?? null) === $roomUuid
        && ($other['acme_b_slots']['uuid'] ?? null) === $expectedSlotUuid,
    'a SECOND environment whose local ids are entirely different derives byte-identical identities for the same authored facts'
);

seed_agency($wpdb, 7, 3);
$wpdb->tables['acme_b_slots']['rows'][0]['room_id'] = 99; // a parent outside capture scope
expect_throw(
    fn() => capture_agency($policy),
    "identity.mode=natural_key column 'room_id' holds unmanaged acme_room ref 99",
    'an unresolvable ref component fails CLOSED at capture — a parent-scoped key has no honest partial form, so the scope refusal is the only correct answer (never a locally-unique id smuggled into a uuid)'
);
seed_agency($wpdb, 7, 3, '');
expect_throw(
    fn() => capture_agency($policy),
    "identity.mode=natural_key column 'slot_code' is empty",
    'an empty scalar component fails closed for the same reason, naming the column'
);
check(
    Tokens::ledger_kind('tt') === 'term_taxonomy' && Tokens::ledger_kind('acme_room') === 'acme_room',
    "Tokens::ledger_kind() is the one public spelling of the token-kind rename table ('tt' is stored as term_taxonomy; a declared id_kind passes through)"
);

echo "\n== S2: the ADOPTION direction — matching an unmanaged row by its parent-scoped key ==\n";

$slotTable = manifest_b()['tables']['acme_b_slots'];
$slotUuid = $expectedSlotUuid;
$roomEntity = [
    'type' => 'acme_a_rooms',
    'data' => ['columns' => ['room_code' => 'studio-one'], 'table' => 'acme_a_rooms', 'uuid' => $roomUuid],
];
$slotEntity = [
    'type' => 'acme_b_slots',
    'data' => [
        'columns' => ['room_id' => "{{acme_room:$roomUuid}}", 'slot_code' => 'morning'],
        'table' => 'acme_b_slots',
        'uuid' => $slotUuid,
    ],
];
$tree = [$roomUuid => $roomEntity, $slotUuid => $slotEntity];

// A target where the whole plugin was pre-provisioned by hand: both rows
// exist, at ids that match nothing in the source, and NOTHING is in the
// ledger yet. This is the case the adoption path exists for.
seed_agency($wpdb, 12, 4);
$cache = [];
check(
    Snapshot::find_collision($policy, $slotEntity, $tree, $cache) === 4,
    'a child row whose parent is UNMAPPED but adoptable still resolves — the parent is found by its OWN natural key, then the child is matched within it (before this, the parent miss returned null and every child was duplicated)'
);
check(
    ($cache[$roomUuid] ?? null) === 12,
    'the parent\'s resolution is memoized in the shared collision cache, so a second child of the same parent costs no second lookup'
);
$noTree = [];
check(
    Snapshot::find_collision($policy, $slotEntity, [], $noTree) === null,
    'with no repository tree to consult, the ledger-only behavior that shipped before is unchanged'
);
$wpdb->identity['acme_room'] = [12 => $roomUuid];
$mapped = [];
check(
    Snapshot::find_collision($policy, $slotEntity, [], $mapped) === 4,
    'and an already-MAPPED parent resolves straight out of the ledger, with no tree and no recursion'
);
seed_agency($wpdb, 12, 4);
$wpdb->tables['acme_a_rooms']['rows'] = []; // the parent genuinely does not exist here
$orphan = [];
check(
    Snapshot::find_collision($policy, $slotEntity, $tree, $orphan) === null,
    'a child whose parent exists NOWHERE on the target is not adopted — there is nothing for it to be scoped within, so an ordinary create is the correct outcome'
);

// N2: the same lookup for a `tt` ref. The manifest spelling and the duo_map
// spelling differ for exactly this one kind, so a raw lookup finds a keyspace
// with no rows and silently answers "no collision" for every row in it.
$ttUuid = '01980000-3318-7000-8000-00000000ab12';
$linkPolicy = load_pair(manifest_b(['tables' => manifest_b()['tables'] + ['acme_b_links' => [
    'class' => 'authored_snapshot',
    'id_kind' => 'acme_link',
    'pk' => 'link_id',
    'slug_column' => 'code',
    'columns' => ['code' => ['class' => 'authored']],
    'refs' => [['column' => 'tt_id', 'kind' => 'tt']],
    'identity' => ['mode' => 'natural_key', 'columns' => ['tt_id', 'code']],
]]]));
$wpdb->identity = ['term_taxonomy' => [5 => $ttUuid]];
$wpdb->tables['acme_b_links'] = [
    'columns' => ['link_id' => 'bigint(20) unsigned', 'tt_id' => 'bigint(20) unsigned', 'code' => 'varchar(64)'],
    'rows' => [['link_id' => 1, 'tt_id' => 5, 'code' => 'x']],
];
$ttCache = [];
check(
    Snapshot::find_collision($linkPolicy, [
        'type' => 'acme_b_links',
        'data' => ['columns' => ['tt_id' => "{{tt:$ttUuid}}", 'code' => 'x'], 'uuid' => $ttUuid],
    ], [], $ttCache) === 1,
    "a `tt` ref component is resolved through duo_map's own spelling (term_taxonomy) — passing the manifest's `tt` verbatim looks up a keyspace with no rows and answers \"no collision\" for every term relationship there is"
);

echo "\n== N3: a corrupt ref token — fail-closed where identity is decided, silent where it is only observed ==\n";

$corrupt = ['room_id' => '{{acme_room:not-a-uuid}}', 'slot_code' => 'morning'];
check(
    Snapshot::natural_key_components_from_front($slotTable, $corrupt) === null,
    'the FRONT-matter derivation reports "cannot derive" for an unparseable ref token, exactly as it does for an absent component — its documented contract, which every caller reads as "say nothing"'
);
check(
    IdentityNotes::natural_key_continuity($slotUuid, 'acme_b_slots', $slotTable, $corrupt) === null,
    'so the identity-continuity NOTE stays silent instead of aborting a whole plan from an annotation nobody asked for'
);
expect_throw(
    fn() => Snapshot::find_collision($policy, [
        'type' => 'acme_b_slots',
        'data' => ['columns' => $corrupt, 'uuid' => $slotUuid],
    ], [], $noTree),
    'composite_ref/natural_key identity derivation',
    'while the lookup that DECIDES whether to adopt an existing row still refuses the same token outright — and the refusal no longer attributes it to composite_ref alone'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
