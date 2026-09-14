<?php
declare(strict_types=1);

/**
 * Offline product-path regression: applying one CF7 property storage generation
 * over a target row that holds the other.
 *
 * The CF7 conformance apply refused at c9d3a9a8 with "wprism: Contact Form 7
 * form has both 'additional_settings' and '_additional_settings'; current CF7
 * prefers the underscored property, so the legacy duplicate must be removed
 * before capture" (runtime/interpreters/contact-form-7.php, post_meta_rule()).
 * The premise is the conformance fixture's own: seed.sh converts the source's
 * "Conformance Legacy Storage" form to CF7's still-readable pre-3.3 names, and
 * postdeploy.sh saves a same-slug stale form on the target through the native
 * API, which writes current underscored names. Apply adopts that row.
 *
 * Neither row ever holds both spellings. The union exists only inside apply:
 * ApplyFieldMaterializer::reconcileMetaTable() rechecks the desired roster
 * against the locked target rows with the roster overlaid, and keeps the rows
 * it is about to DELETE in that map on purpose (#562; see the comment above
 * $lockedContext). So the interpreter was asked to classify a transition, and
 * refused it. That refusal was a second copy of a repository-shape rule
 * repository_diagnostics() already owns ("has both current '_<p>' and legacy
 * '<p>' properties"), which capture runs through compile_staged() before it
 * publishes — so removing the copy removes no protection.
 *
 * Drives the SHIPPED manifest and interpreter through the real materializer and
 * the shared row-backed $wpdb (sandbox/tests/lib), modelled on
 * sandbox/tests/offline/apply/regress_authored_meta_context.php.
 */

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $repoRoot . '/sandbox/tests/lib/check.php';
require_once $repoRoot . '/sandbox/tests/lib/wp_stubs.php';
require_once $repoRoot . '/sandbox/tests/lib/FakeWpdb.php';
require_once $repoRoot . '/sandbox/tests/lib/LockingFakeWpdb.php';
require_once $repoRoot . '/agent/src/Kernel/TransientDbException.php';
require_once $repoRoot . '/agent/src/Kernel/Db.php';
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Kernel/PlainData.php';
require_once $repoRoot . '/agent/src/Policy/Policy.php';
require_once $repoRoot . '/agent/src/Repository/Ledger.php';
require_once $repoRoot . '/agent/src/Grammar/Tokens.php';
require_once $repoRoot . '/agent/src/Apply/ApplyFieldMaterializer.php';

use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\LockingFakeWpdb;

const CF7_FORM_ID = 18;
const CF7_PROPERTIES = ['additional_settings', 'form', 'mail', 'mail_2', 'messages'];

/** @return array<string,mixed> CF7's five properties, as one generation spells them. */
function cf7_generation_properties(bool $legacy): array {
    $mail = [
        'subject' => 'Storage generation', 'sender' => 'WPrism <ops@example.test>', 'body' => 'Body',
        'recipient' => 'ops@example.test', 'additional_headers' => '', 'attachments' => '',
        'use_html' => false, 'exclude_blank' => false,
    ];
    $properties = [
        'additional_settings' => 'demo_mode: on',
        'form' => '[submit "Send"]',
        'mail' => $mail,
        'mail_2' => ['active' => true] + $mail,
        'messages' => ['mail_sent_ok' => 'Sent'],
    ];
    $out = [];
    foreach ($properties as $name => $value) {
        $out[($legacy ? '' : '_') . $name] = $value;
    }
    return $out;
}

/** @return array<string,mixed> the canonical front-matter meta a repository form carries. */
function cf7_generation_front_meta(bool $legacy): array {
    $meta = cf7_generation_properties($legacy) + ['_hash' => str_repeat('a', 64), '_locale' => 'en_US'];
    ksort($meta, SORT_STRING);
    return $meta;
}

/** @return list<array<string,mixed>> the same form as WordPress stores it on the target. */
function cf7_generation_target_rows(bool $legacy): array {
    $rows = [];
    $metaId = 900;
    foreach (cf7_generation_front_meta($legacy) as $key => $value) {
        $rows[] = [
            'meta_id' => ++$metaId,
            'post_id' => CF7_FORM_ID,
            'meta_key' => $key,
            'meta_value' => is_array($value) ? serialize($value) : (string) $value,
        ];
    }
    return $rows;
}

$policy = Policy::load(
    null,
    ['contact-form-7'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'contact-form-7')
);
$tokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');

/** @param list<array<string,mixed>> $postmeta */
$makeDb = static function (array $postmeta): LockingFakeWpdb {
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
    $db->setColumns('wprism_map', [
        'uuid' => 'char(36)', 'entity_type' => 'varchar(64)',
        'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
    ]);
    $db->seedTable('postmeta', $postmeta)->seedTable('termmeta', [])->seedTable('usermeta', [])
        ->seedTable('users', [])->seedTable('wprism_map', []);
    foreach ([$db->postmeta, $db->termmeta, $db->usermeta, $db->users, $db->prefix . 'wprism_map'] as $table) {
        $db->addInnoDbTable($table);
    }
    return $db->addIndex($db->postmeta, 'post_id', 'post_id')
        ->addIndex($db->termmeta, 'term_id', 'term_id')
        ->addIndex($db->usermeta, 'user_id', 'user_id')
        ->addIndex($db->users, 'user_login', 'user_login');
};

/**
 * One reconciliation inside one authored transaction. Rows are read BEFORE the
 * rollback: FakeWpdb restores its START TRANSACTION snapshot at ROLLBACK, so
 * reading afterwards would make every convergence assertion vacuous.
 *
 * @return array{failure:?Throwable, keys:list<string>}
 */
$apply = static function (array $targetRows, array $frontMeta) use ($makeDb, $policy, $tokens): array {
    $db = $makeDb($targetRows);
    $GLOBALS['wpdb'] = $db;
    \WPrismTest\WpStore::reset();
    $field = new ApplyFieldMaterializer($policy, $tokens);
    \WPrism\Db::start_repeatable_read(
        'CF7 storage generation fixture transaction',
        new \WPrism\NativeDatabaseProfile(
            [$db->users, $db->prefix . 'wprism_map'],
            [$db->postmeta, $db->termmeta, $db->usermeta]
        )
    );
    $field->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    $failure = null;
    try {
        $field->reconcile_authored_meta(CF7_FORM_ID, $frontMeta, 'post ' . CF7_FORM_ID);
    } catch (Throwable $thrown) {
        $failure = $thrown;
    } finally {
        $keys = [];
        foreach ($db->rows('postmeta') as $row) {
            if ((int) $row['post_id'] === CF7_FORM_ID) {
                $keys[] = (string) $row['meta_key'];
            }
        }
        sort($keys, SORT_STRING);
        \WPrism\Db::rollback('CF7 storage generation fixture rollback');
        $field->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
    return ['failure' => $failure, 'keys' => $keys];
};

/** @param list<string> $keys */
$bothSpellings = static function (array $keys): array {
    return array_values(array_filter(
        CF7_PROPERTIES,
        static fn(string $name): bool => in_array($name, $keys, true) && in_array('_' . $name, $keys, true)
    ));
};

$legacyKeys = array_keys(cf7_generation_front_meta(true));
$currentKeys = array_keys(cf7_generation_front_meta(false));
sort($legacyKeys, SORT_STRING);
sort($currentKeys, SORT_STRING);

// ---------------------------------------------------------------- case 1
// The conformance apply itself: a legacy repository form adopted over a
// target row CF7's own save() wrote in current storage.
$adopt = $apply(cf7_generation_target_rows(false), cf7_generation_front_meta(true));
if ($adopt['failure'] !== null) {
    wprism_check_detail('refusal: ' . $adopt['failure']->getMessage());
}
wprism_check(
    $adopt['failure'] === null,
    'a legacy-storage repository form applies over a same-slug current-storage target row'
);
wprism_check_same(
    $legacyKeys,
    $adopt['keys'],
    'the target converges to exactly the repository generation: every underscored property row is removed'
);
wprism_check_same([], $bothSpellings($adopt['keys']), 'no property is left in both spellings, which CF7 would resolve to the stale one');

// ---------------------------------------------------------------- case 2
// The mirror transition converges the same way.
$upgrade = $apply(cf7_generation_target_rows(true), cf7_generation_front_meta(false));
if ($upgrade['failure'] !== null) {
    wprism_check_detail('refusal: ' . $upgrade['failure']->getMessage());
}
wprism_check_same(
    [null, $currentKeys],
    [$upgrade['failure'], $upgrade['keys']],
    'a current-storage repository form applied over a legacy target row leaves only current storage'
);

// ---------------------------------------------------------------- case 3
// Same generation on both sides is a no-op, not a refusal.
$same = $apply(cf7_generation_target_rows(true), cf7_generation_front_meta(true));
wprism_check_same(
    [null, $legacyKeys],
    [$same['failure'], $same['keys']],
    'a legacy repository form over a legacy target row reconciles in place'
);

// ---------------------------------------------------------------- case 4
// The protection the removed copy duplicated still stands where it belongs:
// a REPOSITORY form carrying both spellings refuses compilation, which capture
// runs over its staged candidate before publishing.
$dual = cf7_generation_front_meta(false);
$dual['additional_settings'] = $dual['_additional_settings'];
$diagnostics = $policy->repository_constraint_diagnostics([[
    'type' => 'post',
    'path' => 'state/posts/wpcf7_contact_form/11111111-1111-4111-8111-111111111111--form.md',
    'data' => ['type' => 'wpcf7_contact_form', 'meta' => $dual],
]]);
wprism_check_same(
    [['adapter_schema_content_mismatch', 'meta._additional_settings',
        "Contact Form 7 form has both current '_additional_settings' and legacy 'additional_settings' properties"]],
    array_map(static fn(array $d): array => [$d['code'], $d['locator'], $d['message']], $diagnostics),
    'a repository form in both generations is refused by the compiler, naming the exact property'
);

wprism_check_summary('Contact Form 7 storage generation apply');
