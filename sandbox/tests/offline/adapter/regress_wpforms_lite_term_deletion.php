<?php
/**
 * THE TAXONOMY/DELETE-SCOPE MATRIX for the wpforms-lite fixture adapter's one
 * advertised deletion selector, `term:wpforms_form_tag`.
 *
 * tools/engine-gaps.json's `taxonomy_delete_scope_exercise` primitive defines
 * what has to run before a post-type adapter carrying an authored taxonomy may
 * claim a deletion selector: "term-attached state, cascade scope, and residue
 * on a dirty target." This suite is that matrix, plus the negative the fixture
 * makes load-bearing by omission. Four cases, each driven through the shipped
 * code path with the COMMITTED fixture bytes
 * (sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json) as the
 * declaration, loaded as a SITE adapter the way a third-party adapter really
 * arrives:
 *
 *   1  TERM-ATTACHED STATE. The declared guard (term_relationships
 *      .term_taxonomy_id) locks the deletion. The block string, the surviving-
 *      row labels and the operator refusal are all pinned, and §1d then runs
 *      the executor on the same attached term to show what the lock is
 *      standing in front of.
 *   2  CASCADE SCOPE. An unattached term is deleted through the real
 *      DeleteExecutor and the exact row delta is pinned across eight tables:
 *      the declared cascade set and nothing else moves.
 *   3  RESIDUE ON A DIRTY TARGET. A second, locally-created wpforms_form_tag
 *      term and an orphaned term_relationships row survive byte-for-byte, and
 *      the forced path enumerates by name the rows it did not touch.
 *   4  THE NEGATIVE. `post:wpforms` is deliberately not advertised, so the
 *      engine refuses that selector with the whole unsupported_deletion
 *      envelope rather than inferring a post cascade.
 *
 * EACH CASE CARRIES ITS OWN MUTATION PROOF (§1c, §2b, §3c, §4b): one edit to a
 * COPY of the manifest, or to the target state, that makes the case's pinned
 * outcome disappear. Without them a green case proves only that the fixture
 * exists. §1c is the load-bearing one -- drop the single guard from a manifest
 * copy and the lock is gone -- because it is what separates "the guard locks"
 * from "this fixture happens to have no attached forms".
 *
 * WHY THIS IS OFFLINE AT ALL, AND WHAT IT COST. No offline suite had ever run
 * a real term-deletion cascade: DeleteExecutor's term branch calls
 * RelationshipMaterializer::lock_owner_relationships()
 * (agent/src/Apply/RelationshipMaterializer.php:287-295) three times, and that
 * is a LEFT JOIN, which FakeWpdb refused by name. The fake's own diagnostic
 * asks for the interpreter to be extended rather than for the read to return
 * null, so it was: FakeWpdb now accepts exactly ONE join form, a single LEFT
 * JOIN whose ON is one equality between one qualified column on each side, and
 * refuses every other join by name. That form is a per-row lookup, not an
 * optimizer decision, and its LEFT half is product semantics -- a
 * term_relationships row whose term_taxonomy row is gone comes back with a
 * NULL taxonomy and is REFUSED (RelationshipMaterializer.php:310-318) instead
 * of silently dropped the way an INNER JOIN would drop it. NOTHING BELOW
 * EXERCISES THAT: every read here is `WHERE object_id = <the term id>`, and no
 * fixture attaches a row to the term itself, so the join returns nothing at
 * all. The LEFT half is pinned in tests/Tooling/HarnessLibTest.php instead,
 * beside the refusal case for every other join shape -- said here because a
 * reader who assumes §3's surviving orphan proves it would be wrong.
 * LockingFakeWpdb gained the matching schema fact:
 * `SHOW KEYS ... WHERE Key_name = 'PRIMARY'`, the probe
 * DeleteGuardReferenceScanner::count() uses to label guarded rows when a guard
 * declares no source_pk (DeleteGuardReferenceScanner.php:122-131).
 *
 * WHAT THIS SUITE DOES NOT PROVE. It does not prove InnoDB actually took the
 * locks: FakeWpdb parses FOR UPDATE and does not model it, and the engine
 * facts come from recorded fixtures, not a server. Row-level locking, gap
 * locks and the concurrent-writer race remain live-certification property
 * (sandbox/tests/certify/certify_deletion_matrix.sh). What is proven here is
 * the DECISION layer end to end -- which rows the declaration selects, which
 * it refuses, which it destroys, and which it leaves alone.
 */
declare(strict_types=1);

// From offline/adapter/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/LockingFakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';

wprism_test_define_agent_versions();

$root = dirname(__DIR__, 4);

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Kernel/Uuid.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Kernel/CommandRefusal.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Repository/CompiledArtifact.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/RelationshipMaterializer.php';
require_once $root . '/agent/src/Apply/MenuMaterializer.php';
require_once $root . '/agent/src/Apply/ApplyServices.php';
require_once $root . '/agent/src/Apply/ApplyPreparationCoordinator.php';
require_once $root . '/agent/src/Apply/ApplyPreparationRequest.php';
require_once $root . '/agent/src/Rebuild/RebuildSelection.php';
require_once $root . '/agent/src/Scope/ScopedApplyWorkflow.php';
require_once $root . '/agent/src/Delete/Deletion.php';
require_once $root . '/agent/src/Delete/DeleteExecutor.php';
require_once $root . '/agent/src/Delete/DeleteGuardEvaluator.php';
require_once $root . '/agent/src/Delete/DeleteGuardLockCoordinator.php';
require_once $root . '/agent/src/Delete/DeleteGuardReferenceScanner.php';

use WPrism\ApplyFieldMaterializer;
use WPrism\ApplyPreparationCoordinator;
use WPrism\ApplyPreparationRequest;
use WPrism\ApplyServiceCallbacks;
use WPrism\ApplyServices;
use WPrism\Canon;
use WPrism\CommandRefusalException;
use WPrism\CompiledRepository;
use WPrism\DeleteExecutor;
use WPrism\DeleteGuardEvaluator;
use WPrism\DeleteGuardLockCoordinator;
use WPrism\DeleteGuardReferenceScanner;
use WPrism\Deletion;
use WPrism\MenuMaterializer;
use WPrism\Policy;
use WPrism\RebuildSelection;
use WPrism\RelationshipMaterializer;
use WPrism\ScopedApplyWorkflow;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\LockingFakeWpdb;
use WPrismTest\WpStore;

// ---------------------------------------------------------------------------
// The runtime taxonomy registry. RelationshipMaterializer reads it through
// WordPress rather than through the manifest (RelationshipMaterializer.php:367
// and :356), and neither function is in wp_stubs.php. wpforms_form_tag is
// registered on the `wpforms` post type, exactly as
// includes/class-form.php:136 registers it on a real site -- which is what
// makes its object_keyspace `post` and therefore makes term_deletion_taxonomies()
// (:352-363, term-OBJECT taxonomies only) correctly exclude it.
// ---------------------------------------------------------------------------

if (!function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array {
        return ['category', 'post_tag', 'wpforms_form_tag'];
    }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object {
        $objectTypes = [
            'category' => ['post'],
            'post_tag' => ['post'],
            'wpforms_form_tag' => ['wpforms'],
        ];
        return (object) ['name' => $taxonomy, 'object_type' => $objectTypes[$taxonomy] ?? ['post']];
    }
}

// ---------------------------------------------------------------------------
// The declaration under test: the committed fixture bytes, and the loader.
// ---------------------------------------------------------------------------

$adapterFile = $root . '/sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json';
$adapter = Canon::decode((string) file_get_contents($adapterFile));

/**
 * Install a manifest as a SITE adapter and load it the way a target does.
 *
 * The same loader regress_wpforms_lite_adapter.php:139-164 uses, and for the
 * same reason: `adapters/<name>.json` is the source a third-party adapter
 * actually arrives through, so this path runs the out-of-tree contract and the
 * vendor-namespace rule that a scratch manifest LIBRARY would skip. It is also
 * what makes every mutation proof below honest -- a mutated manifest is loaded
 * through the identical path, so the only difference between a green case and
 * its refused mutation is the declaration.
 *
 * @param array<string,mixed> $manifest
 */
$loadSite = static function (array $manifest) use ($root): Policy {
    $dir = sys_get_temp_dir() . '/wprism_wpforms_del_' . bin2hex(random_bytes(8));
    if (!mkdir($dir . '/adapters', 0700, true) && !is_dir($dir . '/adapters')) {
        throw new RuntimeException("could not create scratch site repository $dir");
    }
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/adapters/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($dir . '/site.wprism.json');
        @rmdir($dir . '/adapters');
        @rmdir($dir);
    });
    Canon::write_file($dir . '/adapters/wpforms-lite.json', Canon::encode($manifest));
    Canon::write_file($dir . '/site.wprism.json', Canon::encode([
        'manifests' => [['name' => 'wpforms-lite', 'source' => 'site']],
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    return Policy::load(
        $dir,
        ['wpforms-lite'],
        adapterLibrary: \WPrism\AdapterLibrary::fromSourceTree($root)
    );
};

/**
 * The manifest with its `deletions` section replaced. Every mutation proof
 * goes through here, so none of them can accidentally edit the committed file.
 *
 * @param array<string,mixed> $deletions
 * @return array<string,mixed>
 */
$withDeletions = static function (array $deletions) use ($adapter): array {
    return array_replace($adapter, ['deletions' => $deletions]);
};

$policy = $loadSite($adapter);

// ---------------------------------------------------------------------------
// The target. One managed tag, three forms tagged with it, one locally-created
// tag of the same taxonomy that wprism never mapped, and one orphaned
// relationship row pointing at a term_taxonomy row that no longer exists.
// ---------------------------------------------------------------------------

/** The managed `wpforms_form_tag` term: term 10 / term_taxonomy 20. */
$tagUuid = '3f7a1c92-8b45-4d2e-9a17-6c0e5b4a2d81';
/** The tombstone's own record hashes; opaque to every assertion below. */
$expectedHash = str_repeat('a', 64);
$receiptHash = str_repeat('b', 64);

/** term_relationships rows attaching the three live forms to the managed tag. */
$attached = [
    ['object_id' => 101, 'term_taxonomy_id' => 20, 'term_order' => 0],
    ['object_id' => 102, 'term_taxonomy_id' => 20, 'term_order' => 0],
    ['object_id' => 103, 'term_taxonomy_id' => 20, 'term_order' => 0],
];

/**
 * The dirty rows §3 is about. Neither is wprism's: term 11 was created in
 * wp-admin and never captured, and the term_relationships row points at
 * term_taxonomy 99, which no term_taxonomy row backs -- the residue a
 * half-finished plugin uninstall leaves behind.
 */
$dirtyTerm = ['term_id' => 11, 'name' => 'Untracked', 'slug' => 'untracked', 'term_group' => 0];
$dirtyTaxonomy = [
    'term_taxonomy_id' => 21, 'term_id' => 11, 'taxonomy' => 'wpforms_form_tag',
    'description' => '', 'parent' => 0, 'count' => 0,
];
$orphanRelationship = ['object_id' => 900, 'term_taxonomy_id' => 99, 'term_order' => 0];

/**
 * Build a target.
 *
 * @param list<array<string,mixed>> $relationships term_relationships seed
 * @param bool                      $dirty         include the two dirty rows
 * @param int                       $mappedTt      the term_taxonomy id wprism_map binds to $tagUuid
 */
$target = static function (
    array $relationships,
    bool $dirty = false,
    int $mappedTt = 20
) use ($tagUuid, $dirtyTerm, $dirtyTaxonomy, $orphanRelationship): LockingFakeWpdb {
    $db = new LockingFakeWpdb(new FakeWpdb());
    $db->enableInformationSchema();
    $db->setColumns('terms', [
        'term_id' => 'bigint unsigned', 'name' => 'varchar(200)',
        'slug' => 'varchar(200)', 'term_group' => 'bigint',
    ]);
    $db->setColumns('term_taxonomy', [
        'term_taxonomy_id' => 'bigint unsigned', 'term_id' => 'bigint unsigned',
        'taxonomy' => 'varchar(32)', 'description' => 'longtext',
        'parent' => 'bigint unsigned', 'count' => 'bigint',
    ]);
    $db->setColumns('term_relationships', [
        'object_id' => 'bigint unsigned', 'term_taxonomy_id' => 'bigint unsigned', 'term_order' => 'int',
    ]);
    $db->setColumns('termmeta', [
        'meta_id' => 'bigint unsigned', 'term_id' => 'bigint unsigned',
        'meta_key' => 'varchar(255)', 'meta_value' => 'longtext',
    ]);
    $db->setColumns('posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)']);
    $db->setColumns('postmeta', [
        'meta_id' => 'bigint unsigned', 'post_id' => 'bigint unsigned',
        'meta_key' => 'varchar(255)', 'meta_value' => 'longtext',
    ]);
    $db->setColumns('options', [
        'option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
        'option_value' => 'longtext', 'autoload' => 'varchar(20)',
    ]);
    $db->setColumns('wprism_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)',
        'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ]);

    $terms = [['term_id' => 10, 'name' => 'Sales', 'slug' => 'sales', 'term_group' => 0]];
    $taxonomies = [[
        'term_taxonomy_id' => 20, 'term_id' => 10, 'taxonomy' => 'wpforms_form_tag',
        'description' => '', 'parent' => 0, 'count' => count($relationships),
    ]];
    if ($dirty) {
        $terms[] = $dirtyTerm;
        $taxonomies[] = $dirtyTaxonomy;
        $relationships[] = $orphanRelationship;
    }
    $db->seedTable('terms', $terms);
    $db->seedTable('term_taxonomy', $taxonomies);
    $db->seedTable('term_relationships', $relationships);
    $db->seedTable('termmeta', [
        ['meta_id' => 1, 'term_id' => 10, 'meta_key' => 'wpforms_tag_color', 'meta_value' => '#2a7ae2'],
        ['meta_id' => 2, 'term_id' => 11, 'meta_key' => 'wpforms_tag_color', 'meta_value' => '#c0392b'],
    ]);
    // The three tagged forms and one unrelated page. Nothing in a term
    // deletion may read or write either table; they are seeded so the census
    // can SAY so rather than infer it from a table nobody declared.
    $db->seedTable('posts', [
        ['ID' => 101, 'post_type' => 'wpforms'],
        ['ID' => 102, 'post_type' => 'wpforms'],
        ['ID' => 103, 'post_type' => 'wpforms'],
        ['ID' => 400, 'post_type' => 'page'],
    ]);
    $db->seedTable('postmeta', [
        ['meta_id' => 1, 'post_id' => 101, 'meta_key' => 'wpforms_form_locations', 'meta_value' => 'a:0:{}'],
    ]);
    $db->seedTable('options', [
        ['option_id' => 1, 'option_name' => 'wpforms_settings', 'option_value' => 'a:0:{}', 'autoload' => 'yes'],
    ]);
    $db->seedTable('wprism_map', [
        ['uuid' => $tagUuid, 'entity_type' => 'term', 'id_kind' => 'term', 'local_id' => 10],
        ['uuid' => $tagUuid, 'entity_type' => 'term', 'id_kind' => 'term_taxonomy', 'local_id' => $mappedTt],
    ]);
    $db->setUniqueKey('wprism_map', ['uuid', 'id_kind']);

    foreach ([
        $db->terms, $db->term_taxonomy, $db->term_relationships,
        $db->termmeta, $db->posts, $db->postmeta, $db->options,
        $db->prefix . 'wprism_map',
    ] as $table) {
        $db->addInnoDbTable($table);
    }
    // WordPress' real key layout, because the labels depend on it:
    // wp_term_relationships' PRIMARY is the composite (object_id,
    // term_taxonomy_id), which is what every surviving-row label in §1 and §3
    // prints once the guard declares no source_pk.
    $db->addIndex($db->terms, 'PRIMARY', 'term_id', true)
       ->addIndex($db->term_taxonomy, 'PRIMARY', 'term_taxonomy_id', true)
       ->addIndex($db->term_taxonomy, 'term_id_taxonomy', 'term_id')
       ->addIndex($db->term_relationships, 'PRIMARY', 'object_id')
       ->addIndex($db->term_relationships, 'PRIMARY', 'term_taxonomy_id', false, 2)
       ->addIndex($db->term_relationships, 'term_taxonomy_id', 'term_taxonomy_id')
       ->addIndex($db->termmeta, 'term_id', 'term_id')
       ->addIndex($db->posts, 'PRIMARY', 'ID', true)
       ->addIndex($db->postmeta, 'post_id', 'post_id');

    return $db;
};

/** The eight tables a term deletion could conceivably reach, in one shot. */
$census = static function (LockingFakeWpdb $db): array {
    $out = [];
    foreach ([
        'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
        'posts', 'postmeta', 'options', 'wprism_map',
    ] as $table) {
        $out[$table] = $db->rows($table);
    }
    return $out;
};

/**
 * Which rows a run removed and added, per table, as a plain report.
 *
 * Rows are COMPARED by their canonical encoding (key order and PHP type are
 * irrelevant to "is this the same row") but REPORTED as they are stored, so a
 * failure prints the row an assertion below was written against instead of a
 * re-sorted twin of it. A table that did not move is dropped entirely, which
 * is what makes `array_keys($delta)` the readable statement of scope.
 *
 * @return array<string,array{removed:list<array<string,mixed>>,added:list<array<string,mixed>>}>
 */
$delta = static function (array $before, array $after): array {
    $out = [];
    foreach ($before as $table => $rows) {
        $index = static function (array $set): array {
            $keyed = [];
            foreach ($set as $row) {
                $keyed[Canon::encode($row)] = $row;
            }
            return $keyed;
        };
        $was = $index($rows);
        $is = $index($after[$table] ?? []);
        $removed = array_values(array_diff_key($was, $is));
        $added = array_values(array_diff_key($is, $was));
        if ($removed === [] && $added === []) {
            continue;
        }
        $out[$table] = ['removed' => $removed, 'added' => $added];
    }
    return $out;
};

$tokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');

/** @return array<string,mixed> */
function wpforms_delete_writer_witness(): array {
    return [
        'active' => true, 'allow_deletes' => true, 'artifact_hash' => str_repeat('a', 64),
        'exclusion_state' => 'held', 'format' => 'wprism-scoped-promotion-witness/v1',
        'generation' => 1, 'ok' => true, 'owner' => 'wpforms-offline',
        'receipt_format' => 'wprism-scoped-promotion-receipt/v1',
        'receipt_id' => str_repeat('r', 32), 'receipt_payload_sha256' => str_repeat('b', 64),
        'recovery_ready' => true, 'scope_hash' => str_repeat('c', 64),
        'signing_key_id' => 'wpforms-test', 'state' => 'promoting',
        'target_id' => str_repeat('t', 32), 'terminal' => false,
    ];
}

/**
 * Run the real DeleteExecutor over a target, inside the transaction shape
 * AuthoredTransactionExecutor establishes around it, and report the census on
 * both sides. The rollback in `finally` is the harness' own hygiene -- the
 * census is taken BEFORE it, so what is compared is the mutation the executor
 * performed, not what survived the rollback.
 *
 * @return array{failure:?Throwable,warnings:list<string>,before:array,after:array}
 */
$runDelete = static function (
    LockingFakeWpdb $db,
    Policy $runPolicy,
    string $uuid
) use ($tokens, $census): array {
    $GLOBALS['wpdb'] = $db;
    WpStore::reset();
    $field = new ApplyFieldMaterializer($runPolicy, $tokens);
    $deleteAuthorized = false;
    $executor = new DeleteExecutor(
        $runPolicy,
        new RelationshipMaterializer($runPolicy, $field),
        new MenuMaterializer($runPolicy, $tokens, $field),
        $field,
        static function () use (&$deleteAuthorized): void {
            if (!$deleteAuthorized) {
                throw new CommandRefusalException(
                    'deletion_writer_exclusion_not_authorized',
                    'test deletion unit was not authorized',
                    'authorize the exact test deletion unit',
                    [],
                    'test deletion unit was not authorized'
                );
            }
            $deleteAuthorized = false;
        }
    );
    $before = $census($db);
    $warnings = [];
    $failure = null;
    \WPrism\Db::start_repeatable_read(
        'wpforms tag deletion fixture',
        new \WPrism\NativeDatabaseProfile(
            [$db->prefix . 'wprism_map'],
            [$db->terms, $db->term_taxonomy, $db->term_relationships, $db->termmeta]
        )
    );
    $field->begin_authored_transaction();
    \WPrism\CacheInvalidationTransaction::begin();
    \WPrism\CacheInvalidationTransaction::prepare_term_hierarchy_options(['wpforms_form_tag' => false]);
    try {
        $deleteAuthorized = true;
        $executor->delete_entity($uuid, 'term', [], $warnings);
    } catch (Throwable $thrown) {
        $failure = $thrown;
    }
    $after = $census($db);
    \WPrism\Db::rollback('wpforms tag deletion fixture rollback');
    $field->end_authored_transaction();
    \WPrism\CacheInvalidationTransaction::end();

    return ['failure' => $failure, 'warnings' => $warnings, 'before' => $before, 'after' => $after];
};

/**
 * Annotate a one-tombstone plan with the guard findings, through the exact
 * wiring ApplyPlanBuilder uses (ApplyPlanBuilder.php:337-357): the real
 * DeleteGuardLockCoordinator over the real DeleteGuardReferenceScanner, the
 * capability resolved from the loaded manifest, and the same empty-witness
 * constant.
 *
 * @return array<string,mixed> the annotated plan row
 */
$guardFindings = static function (
    LockingFakeWpdb $db,
    Policy $runPolicy,
    string $uuid
) use ($expectedHash, $receiptHash): array {
    $GLOBALS['wpdb'] = $db;
    $row = [
        'uuid' => $uuid,
        'type' => 'term',
        'deletion_kind' => 'term',
        'deletion_type' => 'wpforms_form_tag',
        'path' => "state/deletions/$uuid.json",
        'expected_hash' => $expectedHash,
        'receipt_hash' => $receiptHash,
    ];
    $capability = Deletion::capability($runPolicy, 'term', 'wpforms_form_tag');
    $coordinator = new DeleteGuardLockCoordinator(
        $runPolicy,
        new DeleteGuardReferenceScanner($runPolicy),
        [],
        static fn(array $binding): array => $binding
    );
    $deleteUuids = [$uuid => true];
    $deletions = [$uuid => ['data' => ['kind' => 'term', 'type' => 'wpforms_form_tag']]];
    $annotated = DeleteGuardEvaluator::annotate_plan_guard_findings(
        ['delete' => [$row], 'delete_conflict' => []],
        [$uuid => $capability],
        static function (array $guard, string $target, bool $lock) use (
            $coordinator,
            $deleteUuids,
            $deletions
        ): array {
            return $coordinator->count($guard, $target, $deleteUuids, $deletions, [], [], $lock);
        },
        // term_relationships is not a declared authored-snapshot row table, so
        // the warning tail must say "resolve it through its owning content
        // workflow" rather than offer `wp wprism orphans`.
        static fn(string $table): bool => false,
        hash('sha256', Canon::encode([]))
    );

    return $annotated['delete'][0];
};

/**
 * Drive the shipped ApplyPreparationCoordinator::prepare() over a plan whose
 * delete row carries the guard findings above. Same scaffold
 * regress_delete_authorization_receipt.php:144-235 established: every runtime
 * boundary reachable on the non-scoped path is a no-op, because the branch
 * under test (ApplyPreparationCoordinator.php:209-236) sits before the
 * promotion lock, before the plan recheck, and before any target mutation.
 *
 * @param array<string,mixed> $deleteRow
 * @param array<string,mixed> $opts
 * @return array{refusal:?Throwable,warnings:list<string>}
 */
$prepare = static function (array $deleteRow, array $opts, Policy $runPolicy): array {
    $compiled = CompiledRepository::create(['tree' => []]);
    $noop = static function (): void {};
    $services = new ApplyServices(
        $runPolicy,
        $compiled,
        new ApplyServiceCallbacks(
            taxonomyOwnership: static fn(): array => [],
            renewPromotionLock: static function (string $phase): void {},
            renewRegenerationLease: $noop,
            renewProviderLease: $noop,
            lockDeleteGuards: static function (array $a, array $b, array $c, array $d, array $e): void {},
            deletionDatabaseProfile: static fn(array $work): array => [
                'read_tables' => [], 'table_presence_reads' => [],
            ],
            recheckDeleteGuard: static function (
                array $row,
                array $uuids,
                array $deletions,
                bool $forced,
                array $tree,
                array $repairs,
                bool $mutating
            ): void {},
            selectionDeclaresChannelFor: static fn(string $channel, string $surface): bool => false,
            selectionDeclaresEntityBatchFor: static fn(string $surface): bool => false,
            selectionTriggersProviderActionFor: static fn(string $surface): bool => false,
            pinnedProviderActionOwns: static fn(string $surface): bool => false,
            upsertMeta: static function (
                string $table,
                string $column,
                int $id,
                string $key,
                ?string $value,
                ?string $previous,
                string $context
            ): void {},
        ),
        '/fixture/repo'
    );
    $plan = [
        'create' => [], 'update' => [], 'adopt' => [], 'unchanged' => [], 'drift' => [],
        'conflict' => [], 'collision' => [], 'delete' => [$deleteRow], 'delete_conflict' => [],
        'deleted' => [], 'code_mismatch' => [], 'code_drift' => [], 'missing_user' => [],
        'incomplete_apply' => [], 'regen_pending' => [], 'regen_context' => [],
        'skipped_user_meta' => [], 'uploads_inventory' => [], 'effects_inventory' => [],
    ];
    $warnings = [];
    $evidence = [];
    $coordinator = new ApplyPreparationCoordinator(
        '/fixture/repo',
        $runPolicy,
        $services,
        new RebuildSelection($runPolicy),
        new ScopedApplyWorkflow(),
        static fn(array $o, CompiledRepository $c, bool $s, bool $full): array => $plan,
        static function (string $phase): void {}
    );
    $request = new ApplyPreparationRequest(
        options: $opts,
        compiled: $compiled,
        plan: $plan,
        tree: [],
        scoped: false,
        scopedPromotion: false,
        recoveringScoped: false,
        retryingIncompleteApply: false,
        promotionOwner: 'wprism-test-owner',
        promotionArtifact: str_repeat('e', 64)
    );
    try {
        $coordinator->prepare($request, $warnings, $evidence);
        return ['refusal' => null, 'warnings' => $warnings];
    } catch (Throwable $refusal) {
        return ['refusal' => $refusal, 'warnings' => $warnings];
    }
};

// ===========================================================================
// 0. THE DECLARATION, resolved from the committed bytes through the product.
// ===========================================================================

// regress_wpforms_lite_adapter.php §E2 already reads the same two facts off
// Policy::deletion_capability(). This is the layer above it -- the entry point
// every consumer in this file actually calls, which additionally enforces the
// required-cascade set for the `term` kind (Deletion.php:48-61) -- and it is
// here because every case below depends on it resolving to exactly this, so a
// reader should not have to take another suite's word for what is under test.
$capability = Deletion::capability($policy, 'term', 'wpforms_form_tag');
wprism_check_same(
    ['term_relationships', 'term_taxonomy', 'termmeta'],
    $capability['cascades'],
    'the committed fixture declares exactly the three cascade effects a term deletion requires'
);
wprism_check_same(
    [[
        'column' => 'term_taxonomy_id',
        'id_kind' => 'term_taxonomy',
        'reason' => 'forms are still tagged with this form tag',
        'table' => 'term_relationships',
    ]],
    $capability['guards'],
    'one guard, on term_relationships.term_taxonomy_id -- the whole matrix hangs off this row'
);
wprism_check_same(
    ['wpforms-lite'],
    $capability['declared_by'],
    'the capability is owned by the site adapter, not inherited from a shipped manifest'
);

// ===========================================================================
// 1. TERM-ATTACHED STATE — the guard locks.
// ===========================================================================

// 1a — the guard read. Three forms carry the tag, so the guard finds three
// rows and names each of them by its full PRIMARY key.
$attachedRow = $guardFindings($target($attached), $policy, $tagUuid);
wprism_check_same(
    'forms are still tagged with this form tag — 3 row(s)',
    $attachedRow['blocked'] ?? null,
    'the block string is the manifest\'s own `reason` plus the measured row count'
);
wprism_check_same(
    [[
        'table' => 'term_relationships',
        'rows' => [
            'term_relationships.object_id=101,term_taxonomy_id=20',
            'term_relationships.object_id=102,term_taxonomy_id=20',
            'term_relationships.object_id=103,term_taxonomy_id=20',
        ],
        'repairable' => false,
        'option_name_ref' => false,
    ]],
    $attachedRow['guard_refs'] ?? null,
    'every attached row is enumerated by its composite PRIMARY key, in ascending key order'
);
// The witness hashes the rows AS THE SERVER RETURNS THEM: wpdb runs mysqli
// over the text protocol, so every column arrives as a string. Recomputing it
// here rather than pasting a hex literal is what keeps this assertion about
// the witness contract instead of about one fixture's digest.
wprism_check_same(
    ['0' => hash('sha256', Canon::encode(array_map(
        static fn(array $row): array => array_map('strval', $row),
        $attached
    )))],
    $attachedRow['guard_witnesses'] ?? null,
    'the witness is the hash of the three attached rows, which the locked recheck re-compares'
);

// 1b — THE REFUSAL. --with-deletes authorizes deletion; it does not authorize
// deleting THROUGH a declared guard. Pinned as the operator sees it.
$blockedApply = $prepare($attachedRow, ['with_deletes' => true], $policy);
wprism_check(
    $blockedApply['refusal'] instanceof RuntimeException,
    'an authorized deletion of an attached tag is refused before any target mutation'
);
wprism_check_same(
    "wprism: deletes blocked by referential guards (this environment's runtime data references them; "
        . "--force-delete-referenced to override):\n"
        . "  - term $tagUuid: forms are still tagged with this form tag — 3 row(s)",
    $blockedApply['refusal']?->getMessage(),
    'the refusal names the entity, the declared reason, the count, and the one flag that overrides it'
);
wprism_check_same(
    [],
    $blockedApply['warnings'],
    'a refused delete emits no warning: nothing happened, so there is nothing to report'
);

// 1c — THE MUTATION PROOF, and the load-bearing one. Drop the single guard
// from a COPY of the manifest and the lock disappears against a byte-identical
// target: what locks is the declaration, not the fixture's row layout.
$unguarded = $loadSite($withDeletions([
    'term:wpforms_form_tag' => [
        'cascades' => ['termmeta', 'term_taxonomy', 'term_relationships'],
        'guards' => [],
    ],
]));
$unguardedRow = $guardFindings($target($attached), $unguarded, $tagUuid);
wprism_check(
    !isset($unguardedRow['blocked']) && !isset($unguardedRow['guard_refs']),
    'MUTATION: with the guard dropped, the same three attached rows produce no block at all'
);
wprism_check_same(
    [],
    $unguardedRow['guard_witnesses'],
    'MUTATION: and no witness, so the locked recheck has nothing to compare either'
);
wprism_check_same(
    null,
    $prepare($unguardedRow, ['with_deletes' => true], $unguarded)['refusal'],
    'MUTATION: the apply that §1b refused now prepares — the guard was the whole refusal'
);

// 1d — WHAT THE LOCK IS STANDING IN FRONT OF. The executor is downstream of
// authority: hand it the same attached term and it destroys all three
// attachments. This is the cascade §1b prevented, measured rather than
// asserted, and it is why the guard is not decoration.
$unlocked = $runDelete($target($attached), $policy, $tagUuid);
wprism_check_same(null, $unlocked['failure'], 'the executor itself performs an attached-term deletion without complaint');
wprism_check_same(
    $attached,
    $delta($unlocked['before'], $unlocked['after'])['term_relationships']['removed'] ?? null,
    'past the guard, all three form attachments are destroyed — the guard is the only thing that stops it'
);

// ===========================================================================
// 2. CASCADE SCOPE — an unattached term, and the exact row delta.
// ===========================================================================

// 2a — the run, and the whole eight-table delta it produced.
$clean = $runDelete($target([]), $policy, $tagUuid);
wprism_check_same(null, $clean['failure'], 'an unattached managed tag deletes through the shipped executor');
wprism_check_same(
    ["deleted term $tagUuid"],
    $clean['warnings'],
    'a clean deletion reports exactly one thing: what it deleted'
);

$cleanDelta = $delta($clean['before'], $clean['after']);
wprism_check_same(
    ['terms', 'term_taxonomy', 'termmeta'],
    array_keys($cleanDelta),
    'exactly three tables move. posts, postmeta, options, wprism_map and term_relationships are untouched'
);
wprism_check_same(
    [
        'terms' => [
            'removed' => [['term_id' => 10, 'name' => 'Sales', 'slug' => 'sales', 'term_group' => 0]],
            'added' => [],
        ],
        'term_taxonomy' => [
            'removed' => [[
                'term_taxonomy_id' => 20, 'term_id' => 10, 'taxonomy' => 'wpforms_form_tag',
                'description' => '', 'parent' => 0, 'count' => 0,
            ]],
            'added' => [],
        ],
        'termmeta' => [
            'removed' => [[
                'meta_id' => 1, 'term_id' => 10,
                'meta_key' => 'wpforms_tag_color', 'meta_value' => '#2a7ae2',
            ]],
            'added' => [],
        ],
    ],
    $cleanDelta,
    'the delta is the selected term, its one taxonomy row, and its one meta row — nothing else, nothing added'
);
wprism_check_same(
    [],
    $clean['after']['term_relationships'],
    'term_relationships is empty on both sides: the declared cascade ran and had nothing to remove'
);
// The identity map is deliberately not part of the cascade: ApplyLedgerFinalizer
// .php:129-133 forgets the uuid AFTER the authored transaction, alongside the
// deletion receipt hash. A cascade that pruned it here would strand that
// finalizer with nothing to record.
wprism_check_same(
    $clean['before']['wprism_map'],
    $clean['after']['wprism_map'],
    'the identity map is NOT part of a term cascade; forgetting it is the finalizer\'s separate step'
);

// 2b — MUTATION. Bind the tag's uuid to term_taxonomy 21, which belongs to the
// OTHER wpforms_form_tag term, and the cascade refuses rather than widening:
// scope is bounded by the exact mapped identity, never by the taxonomy name.
$misMapped = $runDelete($target([], true, 21), $policy, $tagUuid);
wprism_check(
    $misMapped['failure'] instanceof RuntimeException
        && $misMapped['failure']->getMessage() === 'wprism: term deletion requires one exact unshared taxonomy row',
    'MUTATION: a term_taxonomy mapping that does not belong to the term refuses instead of cascading'
);
wprism_check_same(
    [],
    $delta($misMapped['before'], $misMapped['after']),
    'MUTATION: and refuses with zero rows moved, on any of the eight tables'
);

// ===========================================================================
// 3. RESIDUE ON A DIRTY TARGET.
// ===========================================================================

// 3a — the dirty rows survive the cascade byte-for-byte.
$dirty = $runDelete($target([], true), $policy, $tagUuid);
wprism_check_same(null, $dirty['failure'], 'the dirty target does not stop the selected term\'s deletion');
$dirtyDelta = $delta($dirty['before'], $dirty['after']);
wprism_check_same(
    ['terms', 'term_taxonomy', 'termmeta'],
    array_keys($dirtyDelta),
    'the dirty target moves the same three tables as the clean one — residue changes nothing about scope'
);
wprism_check_same(
    [$dirtyTerm],
    $dirty['after']['terms'],
    'the locally-created wpforms_form_tag term survives byte-for-byte; same taxonomy is not same entity'
);
wprism_check_same(
    [$dirtyTaxonomy],
    $dirty['after']['term_taxonomy'],
    'so does its term_taxonomy row'
);
wprism_check_same(
    [$orphanRelationship],
    $dirty['after']['term_relationships'],
    'and so does the orphaned relationship row — the cascade deletes by term_taxonomy_id, not by table'
);
wprism_check_same(
    [['meta_id' => 2, 'term_id' => 11, 'meta_key' => 'wpforms_tag_color', 'meta_value' => '#c0392b']],
    $dirty['after']['termmeta'],
    'the untracked term keeps its own meta: termmeta is cascaded by term_id, not truncated'
);

// 3b — REPORTING WHAT IT DID NOT TOUCH. On the clean path there is nothing to
// report, which is why the loud channel is the FORCED one: --force-delete-
// referenced converts the §1 refusal into an enumeration of every row the
// deletion is about to strand, by name, with the repair that owns it.
$forced = $prepare($attachedRow, ['with_deletes' => true, 'force_delete_referenced' => true], $policy);
wprism_check_same(null, $forced['refusal'], 'the override is honoured — report, do not hide');
wprism_check_same(
    [
        "FORCED delete of guarded term $tagUuid: 3 rows in term_relationships will be orphaned; "
            . 'term_relationships is not a declared authored-snapshot table and must be resolved through its '
            . 'owning content workflow. Surviving rows: term_relationships.object_id=101,term_taxonomy_id=20, '
            . 'term_relationships.object_id=102,term_taxonomy_id=20, '
            . 'term_relationships.object_id=103,term_taxonomy_id=20',
    ],
    $forced['warnings'],
    'the forced warning names the count, the table, the owning workflow, and every surviving row individually'
);

// 3c — MUTATION. Residue is residue only while it points somewhere else. Move
// the orphan onto the selected term's own term_taxonomy id and it stops being
// residue: the guard finds it and the deletion is blocked.
$adoptedOrphan = $guardFindings(
    $target([['object_id' => 900, 'term_taxonomy_id' => 20, 'term_order' => 0]]),
    $policy,
    $tagUuid
);
wprism_check_same(
    'forms are still tagged with this form tag — 1 row(s)',
    $adoptedOrphan['blocked'] ?? null,
    'MUTATION: repoint the orphan at the selected term and the same row that survived §3 now blocks the delete'
);

// ===========================================================================
// 4. THE NEGATIVE — a selector the manifest does not advertise.
// ===========================================================================

// 4a — the refusal envelope, whole.
$refusal = null;
try {
    Deletion::capability($policy, 'post', 'wpforms');
} catch (CommandRefusalException $thrown) {
    $refusal = $thrown;
}
wprism_check(
    $refusal instanceof CommandRefusalException,
    'post:wpforms is deliberately absent from the manifest, so the engine refuses the selector'
);
wprism_check_same(
    [
        'error' => 'unsupported_deletion',
        'message' => 'deletion intent for post:wpforms is unsupported because no pinned adapter owns its '
            . 'destructive semantics',
        'remediation' => 'restore the missing source entity, or pin a compatible adapter that declares the '
            . 'required reverse-reference guards and cascade effects before trying again',
        'diagnostics' => [[
            'code' => 'unsupported_deletion',
            'surface' => 'post:wpforms',
            'message' => 'no pinned adapter declares this deletion selector',
            'remediation' => 'restore the missing source entity or pin a compatible adapter with complete '
                . 'deletion guards and cascade effects',
        ]],
    ],
    $refusal?->payload(),
    'the whole refusal envelope is pinned: an undeclared cascade is never inferred from WordPress behaviour'
);

// 4b — MUTATION. The refusal tracks the DECLARATION, not the post type: a
// manifest copy that advertises post:wpforms with its three required cascades
// resolves instead of refusing.
$advertised = $loadSite($withDeletions([
    'term:wpforms_form_tag' => $adapter['deletions']['term:wpforms_form_tag'],
    'post:wpforms' => [
        'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
        'guards' => [],
    ],
]));
wprism_check_same(
    // Sorted, because DeletionCapabilityResolver.php:41-42 normalizes the
    // declared list before any consumer sees it.
    ['post_revisions', 'postmeta', 'term_relationships'],
    Deletion::capability($advertised, 'post', 'wpforms')['cascades'],
    'MUTATION: advertise the selector and the identical call resolves — the omission is what refuses'
);
wprism_check_same(
    ['column' => 'term_taxonomy_id', 'id_kind' => 'term_taxonomy'],
    array_intersect_key(
        Deletion::capability($advertised, 'term', 'wpforms_form_tag')['guards'][0],
        ['column' => true, 'id_kind' => true]
    ),
    'MUTATION: and the tag selector is unaffected — the two selectors are independent declarations'
);

wprism_check_summary('regress_wpforms_lite_term_deletion');
