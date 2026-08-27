<?php
/**
 * Offline product-path regression for the classification context an authored
 * meta roster is rechecked against once the owner range lock is held.
 *
 * `VMATRIX_MANIFEST=acf bash sandbox/tests/certify/certify_version_matrix.sh`
 * died at the acf 6.0.0 TARGET apply with "duo: authored post meta
 * '_duo_related' disagrees with the locked target context"
 * (ApplyFieldMaterializer.php's post-lock recheck, added as "is not authored
 * in the locked target context" by main #556 18f32d13 and re-worded by #558
 * d6d3a85c); sandbox/bin/adapter-boundary.sh:44-69 records the bisector's
 * probe hitting the identical refusal, independently of that script.
 *
 * The mechanism is not ACF-specific and is not about repeated rows: an
 * interpreter classifies a meta key from its SIBLINGS
 * (manifests/interpreters/acf.php:400-427 — '_<field>' is authored only while
 * its '<field>' sibling is present, and '<field>' only while the '_<field>'
 * pointer names a field this revision defines), and the recheck asked the
 * PRE-WRITE rows. The post the apply is about to create has none, so every
 * sibling-dependent authored key on a fresh owner was refused — the whole
 * point of an apply. The engine now rechecks against the map this
 * reconciliation ESTABLISHES (witnessed rows with this roster's own first
 * value per key over them), which is the same map #558's terminal repeated-row
 * readback already classifies against ($finalFlat).
 *
 * Everything below drives the REAL manifests/interpreters/acf.php through the
 * real materializers and the shared row-backed $wpdb, so neither half can
 * drift back: cases 3 and 4 are the checks #556 added, still refusing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/LockingFakeWpdb.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/PlainData.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/UserMetaMaterializer.php';
// The shipped interpreter itself, not a hand-copied twin: this suite is only
// evidence if the sibling-dependent rule under test is adapter-identity bytes
// (AGENTS.md rule 2).
require_once __DIR__ . '/../../../../adapter-packages/acf/package/runtime/interpreters/acf.php';

use Duo\ApplyFieldMaterializer;
use Duo\CacheInvalidationTransaction;
use Duo\Interpreters\Acf;
use Duo\Policy;
use Duo\Tokens;
use Duo\UserMetaMaterializer;
use DuoTest\FakeWpdb;
use DuoTest\LockingFakeWpdb;

const RELATED_UUID = '3f1a5c2e-7b04-4d18-9a63-2c8e51d0b4a7';
const RELATED_TOKEN = '{{post:' . RELATED_UUID . '}}';
/** The exact bytes ACF's relationship update_value() stores for one target id. */
const RELATED_WIRE = 'a:1:{i:0;s:2:"41";}';

/**
 * The certify-matrix subject, verbatim from
 * adapter-packages/acf/tests/certify/version-matrix.sh:
 * one relationship field named duo_related, whose value meta carries the ids
 * and whose '_duo_related' shadow meta carries the field-key pointer.
 */
$acfTree = [[
    'type' => 'post',
    'path' => 'state/posts/acf-field/field_duo_related.json',
    'data' => ['type' => 'acf-field', 'slug' => 'field_duo_related'],
    'body' => serialize([
        'key' => 'field_duo_related',
        'name' => 'duo_related',
        'type' => 'relationship',
        'post_type' => ['post'],
        'return_format' => 'id',
    ]),
]];

$policy = new Policy();
// Deliberately minimal static policy: the only non-interpreter rule is the
// runtime key case 4 uses, so nothing here can accidentally classify an ACF
// key and mask the interpreter's own answer.
$policy->site = ['policy' => [
    'post_meta' => ['_runtime_note' => ['class' => 'runtime']],
    'term_meta' => [],
    'user_meta' => [],
]];
$acf = new Acf($policy);
$acf->prime_repository($acfTree);
(new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($policy, ['acf' => $acf]);
$tokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');

/**
 * @param list<array<string,mixed>> $postmeta
 * @param list<array<string,mixed>> $termmeta
 * @param list<array<string,mixed>> $usermeta
 */
$makeDb = static function (array $postmeta = [], array $termmeta = [], array $usermeta = []): LockingFakeWpdb {
    $db = new LockingFakeWpdb(new FakeWpdb());
    $db->setColumns('postmeta', [
        'meta_id' => 'bigint unsigned', 'post_id' => 'bigint unsigned',
        'meta_key' => 'varchar(255)', 'meta_value' => 'longtext',
    ]);
    $db->setColumns('termmeta', [
        'meta_id' => 'bigint unsigned', 'term_id' => 'bigint unsigned',
        'meta_key' => 'varchar(255)', 'meta_value' => 'longtext',
    ]);
    $db->setColumns('usermeta', [
        'umeta_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned',
        'meta_key' => 'varchar(255)', 'meta_value' => 'longtext',
    ]);
    $db->setColumns('users', ['ID' => 'bigint unsigned', 'user_login' => 'varchar(60)']);
    $db->setColumns('duo_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)',
        'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ]);
    $db->seedTable('postmeta', $postmeta)
        ->seedTable('termmeta', $termmeta)
        ->seedTable('usermeta', $usermeta)
        ->seedTable('users', [['ID' => 7, 'user_login' => 'editor']])
        ->seedTable('duo_map', [[
            'uuid' => RELATED_UUID, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 41,
        ]]);
    foreach ([$db->postmeta, $db->termmeta, $db->usermeta, $db->users] as $table) {
        $db->addInnoDbTable($table);
    }
    return $db->addIndex($db->postmeta, 'post_id', 'post_id')
        ->addIndex($db->termmeta, 'term_id', 'term_id')
        ->addIndex($db->usermeta, 'user_id', 'user_id')
        ->addIndex($db->users, 'user_login', 'user_login');
};

/**
 * One reconciliation inside one authored transaction, rolled back either way
 * so nothing leaks between cases — the same boundary
 * AuthoredTransactionExecutor gives a real apply. Rows are read BEFORE that
 * rollback: FakeWpdb snapshots at START TRANSACTION and restores at ROLLBACK
 * (FakeWpdb.php:2397-2416), so reading afterwards would make every
 * "wrote nothing" assertion below vacuously true.
 *
 * @return array{failure:?Throwable, postmeta:list<array<string,mixed>>,
 *               termmeta:list<array<string,mixed>>, usermeta:list<array<string,mixed>>}
 */
$run = static function (LockingFakeWpdb $db, callable $body) use ($policy, $tokens): array {
    $GLOBALS['wpdb'] = $db;
    \DuoTest\WpStore::reset();
    $field = new ApplyFieldMaterializer($policy, $tokens);
    $user = new UserMetaMaterializer($policy, $tokens, $field);
    \Duo\Db::start_repeatable_read('authored meta context fixture transaction');
    $field->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    $failure = null;
    try {
        $body($field, $user);
    } catch (Throwable $thrown) {
        $failure = $thrown;
    } finally {
        $observed = [
            'failure' => $failure,
            'postmeta' => $db->rows('postmeta'),
            'termmeta' => $db->rows('termmeta'),
            'usermeta' => $db->rows('usermeta'),
        ];
        \Duo\Db::rollback('authored meta context fixture rollback');
        $field->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
    return $observed;
};

/** @param list<array<string,mixed>> $rows */
$pairs = static function (array $rows, string $ownerColumn, int $ownerId): array {
    $out = [];
    foreach ($rows as $row) {
        if ((int) $row[$ownerColumn] === $ownerId) {
            $out[] = [(string) $row['meta_key'], $row['meta_value']];
        }
    }
    return $out;
};

// ---------------------------------------------------------------- case 1
// The certify-matrix failure itself: the target post does not exist yet, so
// the locked owner range is empty and BOTH ACF halves are sibling-dependent.
$fresh = $run($makeDb(), static function (ApplyFieldMaterializer $field): void {
    $field->reconcile_authored_meta(101, [
        // Canonical order: '_' (0x5F) sorts before 'd' (0x64), which is why
        // the live refusal named '_duo_related' rather than its sibling.
        '_duo_related' => 'field_duo_related',
        'duo_related' => [RELATED_TOKEN],
    ], 'post 101');
});
if ($fresh['failure'] !== null) {
    duo_check_detail('refusal: ' . $fresh['failure']->getMessage());
}
duo_check_same(
    [['_duo_related', 'field_duo_related'], ['duo_related', RELATED_WIRE]],
    $pairs($fresh['postmeta'], 'post_id', 101),
    'a fresh post owner materializes both halves of a sibling-classified ACF field'
);

// ---------------------------------------------------------------- case 2
// A half-applied owner (apply interrupted between the two rows) must heal.
// Under the pre-write map '_duo_related' is unclassified there too, because
// its own '<field>' sibling is the row that never landed.
$partial = $run(
    $makeDb([['meta_id' => 5, 'post_id' => 102, 'meta_key' => '_duo_related', 'meta_value' => 'field_duo_related']]),
    static function (ApplyFieldMaterializer $field): void {
        $field->reconcile_authored_meta(102, [
            '_duo_related' => 'field_duo_related',
            'duo_related' => [RELATED_TOKEN],
        ], 'post 102');
    }
);
if ($partial['failure'] !== null) {
    duo_check_detail('refusal: ' . $partial['failure']->getMessage());
}
duo_check_same(
    [['_duo_related', 'field_duo_related'], ['duo_related', RELATED_WIRE]],
    $pairs($partial['postmeta'], 'post_id', 102),
    'a half-applied ACF owner converges instead of refusing forever'
);

// ---------------------------------------------------------------- case 3
// #556's actual protection, unchanged: the roster names only the value key,
// and the target's OWN pointer names a field this revision does not define,
// so the established map still leaves that key unclassified. The roster
// cannot argue itself into ownership of a target row it does not classify.
$foreignRows = [
    ['meta_id' => 6, 'post_id' => 103, 'meta_key' => '_duo_related', 'meta_value' => 'field_duo_undefined'],
    ['meta_id' => 7, 'post_id' => 103, 'meta_key' => 'duo_related', 'meta_value' => 'target-owned'],
];
$foreign = $run($makeDb($foreignRows), static function (ApplyFieldMaterializer $field): void {
    $field->reconcile_authored_meta(103, ['duo_related' => 'roster-value'], 'post 103');
});
duo_check(
    $foreign['failure'] instanceof Throwable
        && str_contains($foreign['failure']->getMessage(), "authored post 103 meta 'duo_related' disagrees with the locked target context"),
    'a key the target\'s own pointer leaves unclassified still refuses under the lock'
);
duo_check_same($foreignRows, $foreign['postmeta'], 'that refusal happens before any mutation');

// ---------------------------------------------------------------- case 4
// The other half of #556: a roster key that is non-authored under its own
// context is refused on a fresh owner too, where there is nothing witnessed
// to compare against. Without this the recheck would be vacuous for exactly
// the owners case 1 unblocked.
$runtime = $run($makeDb(), static function (ApplyFieldMaterializer $field): void {
    $field->reconcile_authored_meta(104, ['_runtime_note' => 'not ours'], 'post 104');
});
duo_check(
    $runtime['failure'] instanceof Throwable
        && str_contains($runtime['failure']->getMessage(), "authored post 104 meta '_runtime_note' disagrees with the locked target context"),
    'a runtime-classified roster key still refuses on an empty owner range'
);
duo_check_same([], $runtime['postmeta'], 'the runtime-key refusal writes nothing');

// ---------------------------------------------------------------- case 5
// term_meta_rule() reaches the same shadow-key machinery through the shared
// reconcileMetaTable(), so the fresh-owner proof has to hold there too.
$term = $run($makeDb(), static function (ApplyFieldMaterializer $field): void {
    $field->reconcile_authored_term_meta(31, [
        '_duo_related' => 'field_duo_related',
        'duo_related' => [RELATED_TOKEN],
    ]);
});
if ($term['failure'] !== null) {
    duo_check_detail('refusal: ' . $term['failure']->getMessage());
}
duo_check_same(
    [['_duo_related', 'field_duo_related'], ['duo_related', RELATED_WIRE]],
    $pairs($term['termmeta'], 'term_id', 31),
    'a fresh term owner materializes the same sibling-classified ACF field'
);

// ---------------------------------------------------------------- case 6
// UserMetaMaterializer carries its own copy of the post-lock recheck
// (UserMetaMaterializer.php's "is not authored in the locked target context"),
// against the same pre-write map. A target user with no ACF rows yet is the
// user-side twin of case 1, and ACF fields on users are a shipped claim
// (manifests/interpreters/acf.php's user_meta_rule(), exercised by
// adapter-packages/acf/tests/conformance/seed.sh).
$userFresh = $run($makeDb(), static function (ApplyFieldMaterializer $field, UserMetaMaterializer $user): void {
    $user->finalize_user_meta([
        'login' => 'editor',
        'meta' => [
            '_duo_related' => 'field_duo_related',
            'duo_related' => [RELATED_TOKEN],
        ],
    ]);
});
if ($userFresh['failure'] !== null) {
    duo_check_detail('refusal: ' . $userFresh['failure']->getMessage());
}
duo_check_same(
    [['_duo_related', 'field_duo_related'], ['duo_related', RELATED_WIRE]],
    $pairs($userFresh['usermeta'], 'user_id', 7),
    'a target user with no ACF rows yet takes the same authored user-meta pair'
);

duo_check_summary('authored meta locked-context classification');
