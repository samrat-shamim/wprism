<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * issue #3235's composite_ref identity mode (agent/src/Repository/Snapshot.php, task
 * #125): the typed-snapshot grammar's representation for a PURE JOIN table
 * with no surrogate primary key, where every PK column is itself a ref into
 * another keyspace — proving fixture PMPro's pmpro_memberships_pages
 * (membership_id -> a declared pmpro_level row, page_id -> a post).
 *
 * Runs the REAL, unmodified agent/src/{Canon,Policy,Uuid,Secrets,Db,Ledger,
 * Tokens,Snapshot}.php against rows seeded into the shared FakeWpdb. Its SQL
 * interpreter and transaction/query-filter model keep this suite on the same
 * product mutation path as every other offline engine suite: a changed SQL
 * shape is refused by name instead of being hidden by a local answer map.
 *
 * What THIS file proves: schema-assertion invariants, uuid derivation off
 * the REFERENCED entities' own uuids (not raw local ids — the cross-
 * environment portability property the whole design turns on), the
 * structural-ref throw, ensure_row()'s phase-1 no-op, finalize_composite_
 * row()'s apply-direction resolve+upsert on a DIFFERENT "environment" than
 * capture used, delete_row()'s unpack, and pack_composite_id()'s asserted
 * budget. What it deliberately does NOT prove: Apply::build_plan()'s own
 * create/update/unchanged bucketing (Apply.php is out of this file's
 * dependency boundary by design — see Snapshot.php's own docblock,
 * "Engine boundary") — that is sandbox/tests/live/regress_pmpro_composite_ref.sh's
 * job, against a real database.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

// --------------------------------------------------------- shared harness

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

WpStore::reset()->seedOptions(['home' => 'http://example.test']);
$wpdb = FakeWpdb::install()->enableInformationSchema();

// ----------------------------------------------------------- engine + fixtures

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Repository/IdentityNotes.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';

use WPrism\Canon;
use WPrism\Ledger;
use WPrism\Policy;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrism\Uuid;

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
function check_throws(callable $fn, string $needle, string $msg): void {
    global $failures;
    try {
        $fn();
        echo "FAIL: $msg (did not throw)\n";
        $failures++;
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            echo "ok: $msg (threw: {$e->getMessage()})\n";
        } else {
            echo "FAIL: $msg (threw, but message missing '$needle': {$e->getMessage()})\n";
            $failures++;
        }
    }
}

/**
 * The proving fixture's manifest declaration — mirrors manifests/paid-
 * memberships-pro.json's own pmpro_memberships_pages entry exactly.
 * Deliberately a SHALLOW top-level merge (array_merge, not
 * array_replace_recursive): 'identity'/'refs'/'columns' are each replaced
 * WHOLESALE when overridden, never index-merged — array_replace_recursive
 * on a plain numeric-indexed list like `identity.columns` merges BY INDEX,
 * so a 1-element override would silently leave the base array's 2nd
 * element in place instead of shrinking the list (caught the hard way
 * authoring this file's own A2 check, which is exactly the kind of
 * "the test lied" bug this project's own evidence discipline exists to
 * catch before it hides a real defect). Callers overriding 'identity' must
 * therefore always supply the COMPLETE sub-structure, including 'mode'.
 */
function pmpro_pages_decl(array $overrides = []): array {
    return array_merge([
        'class' => 'authored_snapshot',
        'id_kind' => 'pmpro_restrict',
        'identity' => ['mode' => 'composite_ref', 'columns' => ['membership_id', 'page_id']],
        'refs' => [
            ['column' => 'membership_id', 'kind' => 'pmpro_level'],
            ['column' => 'page_id', 'kind' => 'post'],
        ],
        'columns' => [
            'modified' => ['class' => 'runtime'],
        ],
    ], $overrides);
}

/** DESCRIBE'd live against this round's own sandbox pair (asnap3235):
 *  PRIMARY KEY (page_id, membership_id), plus an auto ON UPDATE timestamp. */
function seed_pmpro_pages_table(FakeWpdb $wpdb, array $rows): void {
    if (!$wpdb->hasTable('wp_pmpro_memberships_pages')) {
        $wpdb->setColumns('wp_pmpro_memberships_pages', [
            'membership_id' => 'int(11) unsigned',
            'page_id' => 'bigint(20) unsigned',
            'modified' => 'timestamp',
        ])
            ->setUniqueKey('wp_pmpro_memberships_pages', ['page_id', 'membership_id'])
            ->setTableEngine('wp_pmpro_memberships_pages', 'InnoDB');
    }
    $wpdb->seedTable('wp_pmpro_memberships_pages', $rows);
}

function fresh_policy(array $tablesDecl): Policy {
    $policy = new Policy();
    $policy->manifests = [['tables' => ['pmpro_memberships_pages' => $tablesDecl]]];
    return $policy;
}

function shipping_method_decl(array $overrides = []): array {
    return array_merge([
        'class' => 'authored_snapshot',
        'id_kind' => 'wc_zone_method',
        'pk' => 'instance_id',
        'refs' => [['column' => 'zone_id', 'kind' => 'wc_zone']],
        'columns' => [
            'method_id' => ['class' => 'authored'],
            'method_order' => ['class' => 'authored'],
            'is_enabled' => ['class' => 'authored'],
        ],
    ], $overrides);
}

function shipping_method_policy(array $overrides = []): Policy {
    $policy = new Policy();
    $policy->manifests = [['tables' => [
        'woocommerce_shipping_zone_methods' => shipping_method_decl($overrides),
    ]]];
    return $policy;
}

function seed_shipping_methods_table(FakeWpdb $wpdb, array $rows): void {
    if (!$wpdb->hasTable('wp_woocommerce_shipping_zone_methods')) {
        $wpdb->setColumns('wp_woocommerce_shipping_zone_methods', [
            'instance_id' => 'bigint(20) unsigned',
            'zone_id' => 'bigint(20) unsigned',
            'method_id' => 'varchar(255)',
            'method_order' => 'bigint(20) unsigned',
            'is_enabled' => 'tinyint(1)',
        ])
            ->setPrimaryKey('wp_woocommerce_shipping_zone_methods', 'instance_id')
            ->setTableEngine('wp_woocommerce_shipping_zone_methods', 'InnoDB');
    }
    $wpdb->seedTable('wp_woocommerce_shipping_zone_methods', $rows);
}

/**
 * Replace one environment's durable identities with real wprism_map rows.
 * Snapshot.php:433-442's long-entity repair writes both ledger tables, so
 * their InnoDB facts deliberately drive Db's standalone authority profile.
 *
 * @param array<string,array<int,string>> $identity
 */
function seed_ledger(FakeWpdb $wpdb, array $identity): void {
    $entityTypes = [
        'pmpro_level' => 'pmpro_membership_levels',
        'post' => 'post',
        'wc_zone' => 'woocommerce_shipping_zones',
        'wc_zone_method' => 'woocommerce_shipping_zone_methods',
    ];
    $rows = [];
    foreach ($identity as $kind => $byLocalId) {
        if (!isset($entityTypes[$kind])) {
            throw new \InvalidArgumentException("missing fixture entity type for ledger kind '$kind'");
        }
        foreach ($byLocalId as $localId => $uuid) {
            $rows[] = [
                'uuid' => $uuid,
                'entity_type' => $entityTypes[$kind],
                'id_kind' => $kind,
                'local_id' => (int) $localId,
            ];
        }
    }
    if (!$wpdb->hasTable('wp_wprism_map')) {
        $wpdb->setColumns('wp_wprism_map', [
            'uuid' => 'char(36)',
            'entity_type' => 'varchar(64)',
            'id_kind' => 'varchar(32)',
            'local_id' => 'bigint unsigned',
        ])
            ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
            ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
            ->setTableEngine('wp_wprism_map', 'InnoDB')
            ->setColumns('wp_wprism_state', [
                'uuid' => 'varchar(64)',
                'entity_type' => 'varchar(64)',
                'content_hash' => 'char(64)',
            ])
            ->setUniqueKey('wp_wprism_state', ['uuid'])
            ->setTableEngine('wp_wprism_state', 'InnoDB');
    }
    $wpdb
        ->seedTable('wp_wprism_map', $rows)
        ->seedTable('wp_wprism_state', []);
}

/** @return array<string,array{path:string,content:string}> */
function entities_by_uuid(array $entities): array {
    $out = [];
    foreach ($entities as $entity) {
        $out[$entity['uuid']] = [
            'path' => $entity['path'],
            'content' => $entity['content'],
        ];
    }
    ksort($out, SORT_STRING);
    return $out;
}

// ======================================================================
// GROUP A — assert_composite_row_schema() invariants (driven through the
// public capture() entry point, exactly as manifest authoring mistakes
// would surface in real use — see this file's own docblock for why
// assert_*_schema() is always called before any row is read).
// ======================================================================
echo "\n== Group A: schema assertion ==\n";

seed_ledger($wpdb, []);
seed_pmpro_pages_table($wpdb, []);

check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl(['pk' => 'id'])), new Tokens(), true),
    "composite_ref AND a 'pk'",
    'A1: declaring pk alongside composite_ref refuses'
);

check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl([
        'identity' => ['mode' => 'composite_ref', 'columns' => ['membership_id']],
    ])), new Tokens(), true),
    'identity.columns != exactly 2',
    'A2: identity.columns with only 1 entry refuses'
);

check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl([
        'identity' => ['mode' => 'composite_ref', 'columns' => ['membership_id', 'page_id', 'modified']],
    ])), new Tokens(), true),
    'identity.columns != exactly 2',
    'A2b: identity.columns with 3 entries refuses (only the proven 2-column shape is accepted)'
);

check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl([
        'identity' => ['mode' => 'composite_ref', 'columns' => ['membership_id', 'modified']],
    ])), new Tokens(), true),
    'must be EXACTLY its refs[] columns',
    'A3: identity.columns not matching refs[] columns refuses'
);

check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl([
        'invalidate' => [['table' => 'whatever', 'column' => 'id']],
    ])), new Tokens(), true),
    "with 'invalidate'",
    "A4: declaring 'invalidate' on a composite_ref table refuses"
);

check_throws(
    fn() => Snapshot::capture(fresh_policy([
        'class' => 'authored_snapshot',
        'id_kind' => 'pmpro_restrict',
        'identity' => ['mode' => 'composite_ref', 'columns' => ['membership_id', 'page_id']],
        'refs' => [
            ['column' => 'membership_id', 'kind' => 'pmpro_level'],
            ['column' => 'page_id', 'kind' => 'post'],
        ],
        // 'modified' deliberately NOT classified — the finding-#8 rule
        // extended to this mode: every live column still must be accounted for.
        'columns' => [],
    ]), new Tokens(), true),
    'undeclared column(s): modified',
    'A5: an unclassified live column (modified) still refuses capture loudly, same as a row table'
);

// A6: a fully valid declaration passes schema assertion cleanly (empty table -> empty result, no throw).
seed_ledger($wpdb, []);
$emptyResult = Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true);
check($emptyResult === [], 'A6: a valid composite_ref declaration against an empty table passes schema assertion and returns no entities');

// ======================================================================
// GROUP B — identify_composite_row() / capture_composite_table(): uuid
// derivation, the cross-environment portability property, structural
// throws, determinism.
// ======================================================================
echo "\n== Group B: capture-side identity ==\n";

const LEVEL_UUID = '01980000-1001-7000-8000-000000000001';
const PAGE_UUID  = '01980000-1002-7000-8000-000000000002';

// "Environment A": level local_id=5, page local_id=14 (matching this
// round's own live fixture on asnap3235 — Studio Access is level 2 there,
// but the SPECIFIC numbers are arbitrary; only their DIFFERENCE from
// "environment B" below matters for the portability proof in B3).
seed_ledger($wpdb, ['pmpro_level' => [5 => LEVEL_UUID], 'post' => [14 => PAGE_UUID]]);
seed_pmpro_pages_table($wpdb, [
    ['membership_id' => 5, 'page_id' => 14, 'modified' => '2026-08-06 12:00:00'],
]);
$policyB = fresh_policy(pmpro_pages_decl());
$entitiesB1 = Snapshot::capture($policyB, new Tokens(), true);
check(count($entitiesB1) === 1, 'B1: one row captured');
$eB1 = $entitiesB1[0] ?? null;
if ($eB1 !== null) {
    check($eB1['type'] === 'pmpro_memberships_pages', 'B1: entity type is the table name itself');
    check(str_starts_with($eB1['path'], 'tables/pmpro_memberships_pages/' . $eB1['uuid'] . '--'), 'B1: path is tables/<table>/<uuid>--<slug>.json');
    $frontB1 = Canon::decode($eB1['content']);
    check($frontB1['columns']['membership_id'] === '{{pmpro_level:' . LEVEL_UUID . '}}', 'B1: membership_id column holds the resolved pmpro_level token');
    check($frontB1['columns']['page_id'] === '{{post:' . PAGE_UUID . '}}', 'B1: page_id column holds the resolved post token');
    check(!isset($frontB1['columns']['modified']), 'B1: the runtime-classified "modified" column is absent from canonical state entirely');
    check($frontB1['meta'] === [], 'B1: meta is present but empty (composite_ref rows can never own an attached-meta sidecar)');
    check(Uuid::is($eB1['uuid']), 'B1: the derived uuid is a well-formed uuid');
}

// B2 — THE core correctness property: capturing the SAME two referenced
// entities' uuids on a SECOND, independent "environment" whose OWN local
// ids for those same two entities are completely different numbers must
// derive the IDENTICAL uuid. This is what makes the fact portable across
// environments at all — see Snapshot.php's own docblock for why deriving
// from raw local ids instead would have silently broken this.
seed_ledger($wpdb, ['pmpro_level' => [999 => LEVEL_UUID], 'post' => [4242 => PAGE_UUID]]); // same two REFERENCED uuids, wildly different LOCAL ids
seed_pmpro_pages_table($wpdb, [
    ['membership_id' => 999, 'page_id' => 4242, 'modified' => '2026-08-01 00:00:00'],
]);
$entitiesB2 = Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true);
check(count($entitiesB2) === 1, 'B2: one row captured on the second "environment"');
$eB2 = $entitiesB2[0] ?? null;
if ($eB2 !== null && $eB1 !== null) {
    check($eB2['uuid'] === $eB1['uuid'], 'B2 (CORE PROPERTY): the SAME two referenced entities produce the SAME derived uuid regardless of their wildly different local ids on each environment (5/14 vs 999/4242) — got ' . $eB1['uuid'] . ' vs ' . $eB2['uuid']);
    $frontB2 = Canon::decode($eB2['content']);
    check($frontB2['columns'] == Canon::decode($eB1['content'])['columns'], 'B2: the two environments\' captured "columns" content is identical too (same tokens, since tokens encode the portable uuids, not the local ids)');
}

// B3 — structural throw: an unmapped ref (page 999 was never captured/minted).
seed_ledger($wpdb, ['pmpro_level' => [5 => LEVEL_UUID]]); // 'post' kind has NOTHING mapped
seed_pmpro_pages_table($wpdb, [
    ['membership_id' => 5, 'page_id' => 999, 'modified' => '2026-08-06 12:00:00'],
]);
check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true),
    "unmanaged post ref 999 in identity column 'page_id'",
    'B3: an unmapped structural ref throws (capture scope must include the referenced row), unconditionally (mint=true here; see B3b for mint=false)'
);

// B3b — the SAME unresolved-ref throw fires identically on a NON-minting
// call (Capture::snapshot()'s mint=false) — deliberately NOT softened to a
// silent skip, mirroring capture_table()'s own regular-row structural-ref
// posture exactly (see Snapshot.php's docblock).
check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), false),
    "unmanaged post ref 999 in identity column 'page_id'",
    'B3b: the same throw fires on a non-minting (snapshot) call too — composite_ref never gates visibility behind $mint'
);

// B4 — determinism: capturing the SAME live state twice is byte-identical.
seed_ledger($wpdb, ['pmpro_level' => [5 => LEVEL_UUID], 'post' => [14 => PAGE_UUID]]);
seed_pmpro_pages_table($wpdb, [
    ['membership_id' => 5, 'page_id' => 14, 'modified' => '2026-08-06 12:00:00'],
]);
$b4a = Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true);
$b4b = Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true);
check($b4a[0]['content'] === $b4b[0]['content'] && $b4a[0]['uuid'] === $b4b[0]['uuid'], 'B4: capture-twice on unchanged live state is byte-identical (spec\'s "capturing the same site twice" invariant)');

// ======================================================================
// GROUP C — apply-side: ensure_row() no-op, finalize_composite_row() on a
// DIFFERENT "environment" than capture used, delete_row().
// ======================================================================
echo "\n== Group C: apply-side reconciliation ==\n";

// Reuse $eB1's captured file content as "the repo" being applied to a
// FRESH third environment whose local ids for the level/post differ yet
// again from BOTH capture-side environments above.
$capturedEntity = $eB1; // uuid = the portable one; columns = {{pmpro_level:...}}, {{post:...}} tokens

seed_ledger($wpdb, ['pmpro_level' => [77 => LEVEL_UUID], 'post' => [88 => PAGE_UUID]]); // environment C's OWN local ids
seed_pmpro_pages_table($wpdb, []); // fresh target: table exists, zero rows
$policyC = fresh_policy(pmpro_pages_decl());

$ensured = Snapshot::ensure_row($policyC, $capturedEntity);
check($ensured === false, 'C1: ensure_row() (phase 1) is a documented no-op for composite_ref — returns false, inserts nothing yet');
check(count($wpdb->rows('wp_pmpro_memberships_pages')) === 0, 'C1: phase 1 truly inserted zero rows (the row is created ENTIRELY in phase 2, unlike every other row table)');

$tokensC = new Tokens();
Snapshot::finalize_row($policyC, $tokensC, $capturedEntity);
$rowsAfterFinalize = $wpdb->rows('wp_pmpro_memberships_pages');
check(count($rowsAfterFinalize) === 1, 'C2: finalize_row() (phase 2) inserted exactly one row');
if (count($rowsAfterFinalize) === 1) {
    $r = $rowsAfterFinalize[0];
    check((int) $r['membership_id'] === 77, 'C2: membership_id resolved to environment C\'s OWN local id (77), not the source\'s (5 or 999)');
    check((int) $r['page_id'] === 88, 'C2: page_id resolved to environment C\'s OWN local id (88), not the source\'s (14 or 4242)');
}
$packedAfterFinalize = Ledger::id_for($capturedEntity['uuid'], 'pmpro_restrict');
check($packedAfterFinalize !== null, 'C2: finalize_composite_row() recorded a wprism_map entry for this environment (bookkeeping only, never consulted for identity — see docblock)');

// C3 — idempotency: finalize the SAME entity again (as a real re-apply of
// an unchanged file would eventually call it) must not duplicate the row.
Snapshot::finalize_row($policyC, new Tokens(), $capturedEntity);
check(count($wpdb->rows('wp_pmpro_memberships_pages')) === 1, 'C3: re-finalizing the identical entity is idempotent — still exactly one row, no duplicate-key style corruption');

// C4 — delete_row(): unpack the packed local_id back into the correct
// tuple and delete exactly that row on THIS environment.
Snapshot::delete_row($policyC, $capturedEntity['uuid'], 'pmpro_memberships_pages');
check(count($wpdb->rows('wp_pmpro_memberships_pages')) === 0, 'C4: delete_row() removed the row (resolved via the packed local_id, unpacked back to membership_id=77/page_id=88)');
check(
    Ledger::id_for($capturedEntity['uuid'], 'pmpro_restrict') === $packedAfterFinalize,
    'C4: delete_row() retains the ledger entry until Apply post-rebuild bookkeeping commits convergence metadata'
);

// ======================================================================
// GROUP D — pack_composite_id() budget (exercised end-to-end: this file's
// pack/unpack helpers are private, so the budget is proven the same way a
// manifest author would ever actually hit it — a real oversized id flowing
// through capture).
// ======================================================================
echo "\n== Group D: composite local-id packing budget ==\n";

$OVER_BUDGET = (1 << 31); // 2^31 — one past the 31-bit-per-component budget documented in pack_composite_id()'s docblock
seed_ledger($wpdb, ['pmpro_level' => [$OVER_BUDGET => LEVEL_UUID], 'post' => [14 => PAGE_UUID]]);
seed_pmpro_pages_table($wpdb, [
    ['membership_id' => $OVER_BUDGET, 'page_id' => 14, 'modified' => '2026-08-06 12:00:00'],
]);
check_throws(
    fn() => Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true),
    "table 'pmpro_memberships_pages' composite identity (membership_id=$OVER_BUDGET, page_id=14) has out-of-budget component(s) (membership_id=$OVER_BUDGET)",
    'D1: a component exceeding the 31-bit packed-id budget throws loudly, naming the table and BOTH column=value pairs (not just the bare overflowing scalar), plus which specific one(s) are out of budget'
);

// A component comfortably within budget (the realistic case, matching
// this round\'s own live fixture\'s small ids) must NOT throw.
seed_ledger($wpdb, ['pmpro_level' => [2147483647 >> 1 => LEVEL_UUID], 'post' => [14 => PAGE_UUID]]); // (2^31-1)/2, safely inside budget
seed_pmpro_pages_table($wpdb, [
    ['membership_id' => 2147483647 >> 1, 'page_id' => 14, 'modified' => '2026-08-06 12:00:00'],
]);
$dOk = Snapshot::capture(fresh_policy(pmpro_pages_decl()), new Tokens(), true);
check(count($dOk) === 1, 'D2: a component well within the packed-id budget captures cleanly (no false-positive refusal)');

// ======================================================================
// GROUP E — ordinary mapped-row filenames must be portable too. The live
// ecommerce grind found this after two shipping methods received opposite
// auto-increment instance_ids on source and target: content and UUIDs were
// identical, but the old numeric fallback suffix renamed both files.
// ======================================================================
echo "\n== Group E: mapped-row path portability ==\n";

const ZONE_UUID = '01980000-2000-7000-8000-000000000000';
const FLAT_RATE_UUID = '01980000-2001-7000-8000-000000000001';
const FREE_SHIPPING_UUID = '01980000-2002-7000-8000-000000000002';

seed_ledger($wpdb, [
    'wc_zone' => [7 => ZONE_UUID],
    'wc_zone_method' => [1 => FLAT_RATE_UUID, 2 => FREE_SHIPPING_UUID],
]);
seed_shipping_methods_table($wpdb, [
    ['instance_id' => 1, 'zone_id' => 7, 'method_id' => 'flat_rate', 'method_order' => 1, 'is_enabled' => 1],
    ['instance_id' => 2, 'zone_id' => 7, 'method_id' => 'free_shipping', 'method_order' => 2, 'is_enabled' => 1],
]);
$methodsA = entities_by_uuid(Snapshot::capture(shipping_method_policy(), new Tokens(), true));

seed_ledger($wpdb, [
    'wc_zone' => [77 => ZONE_UUID],
    // Same portable rows, opposite target-local instance ids.
    'wc_zone_method' => [1 => FREE_SHIPPING_UUID, 2 => FLAT_RATE_UUID],
]);
seed_shipping_methods_table($wpdb, [
    ['instance_id' => 1, 'zone_id' => 77, 'method_id' => 'free_shipping', 'method_order' => 2, 'is_enabled' => 1],
    ['instance_id' => 2, 'zone_id' => 77, 'method_id' => 'flat_rate', 'method_order' => 1, 'is_enabled' => 1],
]);
$methodsB = entities_by_uuid(Snapshot::capture(shipping_method_policy(), new Tokens(), true));

check($methodsA === $methodsB, 'E1: mapped rows recapture to identical paths and bytes when target-local primary keys are swapped');
check(
    ($methodsA[FLAT_RATE_UUID]['path'] ?? '') === 'tables/woocommerce_shipping_zone_methods/' . FLAT_RATE_UUID . '--record.json'
        && ($methodsA[FREE_SHIPPING_UUID]['path'] ?? '') === 'tables/woocommerce_shipping_zone_methods/' . FREE_SHIPPING_UUID . '--record.json',
    'E2: a table without slug_column uses the portable --record suffix, never an environment-local primary key'
);
check_throws(
    fn() => Snapshot::assert_row_schema(
        'woocommerce_shipping_zone_methods',
        shipping_method_decl(['slug_column' => 'instance_id'])
    ),
    'slug_column must name a non-empty authored columns entry',
    'E3: a primary-key slug_column is rejected before capture can leak environment-local ids into paths'
);
$runtimeSlugDecl = shipping_method_decl(['slug_column' => 'method_id']);
$runtimeSlugDecl['columns']['method_id']['class'] = 'runtime';
check_throws(
    fn() => Snapshot::assert_row_schema('woocommerce_shipping_zone_methods', $runtimeSlugDecl),
    'slug_column must name a non-empty authored columns entry',
    'E4: a runtime slug_column is rejected; only portable authored data may name canonical files'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
