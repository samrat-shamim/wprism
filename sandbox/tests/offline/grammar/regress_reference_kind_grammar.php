<?php
/**
 * Offline regression for ReferenceKindGrammar (DUO-3348 slice 24).
 *
 * Ref, token, and ledger declarations share the same declared-table id_kind
 * extension path but intentionally have different engine-owned bases. This
 * suite exercises the pure collaborator directly, then proves both Policy
 * loader paths call it after the extraction.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ReferenceKindGrammar.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\Canon;
use Duo\Policy;
use Duo\ReferenceKindGrammar;
use DuoTest\FrozenPolicy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

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

$assertAccepted = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(true, "$label: accepted");
    } catch (\Throwable $e) {
        $check(false, "$label: unexpectedly refused ({$e->getMessage()})");
    }
};

$validManifest = [
    'name' => 'kinds',
    'tables' => [
        'acme_rooms' => ['id_kind' => 'acme_room'],
        'acme_slots' => [
            'id_kind' => 'acme_slot',
            'refs' => [
                ['column' => 'room_id', 'kind' => 'acme_room'],
                ['column' => 'post_id', 'kind' => 'post'],
            ],
        ],
    ],
    'options' => [
        'room' => ['ref' => 'acme_room'],
        'rooms' => ['ref' => 'acme_room[]'],
        'user' => ['ref' => 'user'],
        'site' => ['ref' => 'site_kind'],
    ],
    'post_meta' => [
        'payload' => [
            'json_refs' => [
                ['path' => '$.room', 'kind' => 'acme_room'],
                ['path' => '$.post', 'kind' => 'post'],
            ],
            'key_refs' => ['kind' => 'acme_slot'],
        ],
    ],
    'block_attrs' => [
        'acme/card' => [
            ['path' => 'room', 'type' => 'int', 'kind' => 'acme_room'],
            [
                'path' => 'slot',
                'type' => 'int',
                'kind_from' => [
                    'attr' => 'mode',
                    'map' => ['room' => 'acme_room', 'slot' => 'acme_slot'],
                    'default' => 'post',
                ],
            ],
        ],
    ],
    'shortcode_attrs' => [
        'acme-card' => [
            ['path' => 'owner', 'kind' => 'tt'],
        ],
    ],
    'deletions' => [
        'table:acme_slots' => [
            'guards' => [
                ['id_kind' => 'post', 'source_id_kind' => 'acme_room'],
                ['id_kind' => 'term_taxonomy', 'source_id_kind' => 'acme_slot'],
            ],
        ],
    ],
    'option_name_refs' => [
        ['pattern' => '^acme_room_', 'id_kind' => 'acme_room'],
    ],
    // These are free-form or plugin-owned channels. Their ref/kind-looking
    // values must not be mistaken for engine keyspace declarations.
    'notes' => ['ref' => 'psot', 'nested' => ['kind' => 'psot']],
    'actions' => [['args' => ['ref' => 'psot', 'kind' => 'psot']]],
    'providers' => [['args' => ['ref' => 'psot', 'kind' => 'psot']]],
    'lifecycle_effects' => [['kind' => 'psot', 'ref' => 'psot']],
];

$sitePolicy = [
    'tables' => [
        'site_rows' => ['id_kind' => 'site_kind'],
    ],
    'options' => [
        'site_room' => ['ref' => 'site_kind'],
    ],
];

$validManifests = [$validManifest];
$assertAccepted(
    static fn() => ReferenceKindGrammar::validate_ref_kinds($validManifests, $sitePolicy),
    'engine, pinned-table, site-table, and list-suffixed ref kinds are accepted'
);

$invalid = $validManifests;
$invalid[0]['options']['room']['ref'] = 'psot';
$assertThrows(
    static fn() => ReferenceKindGrammar::validate_ref_kinds($invalid, $sitePolicy),
    'reference kind vocabulary is closed',
    'a misspelled scalar ref kind is refused before runtime lookup can drop its value'
);

$invalid = $validManifests;
$invalid[0]['tables']['acme_slots']['refs'][0]['kind'] = 'user';
$assertThrows(
    static fn() => ReferenceKindGrammar::validate_ref_kinds($invalid, $sitePolicy),
    'token kind vocabulary is closed',
    'the token vocabulary excludes the login-only user reference kind'
);

$invalid = $validManifests;
$invalid[0]['deletions']['table:acme_slots']['guards'][0]['id_kind'] = 'tt';
$assertThrows(
    static fn() => ReferenceKindGrammar::validate_ref_kinds($invalid, $sitePolicy),
    'ledger kind vocabulary is closed here',
    'ledger guards use long engine spellings and do not accept the token short spelling tt'
);

$invalid = $validManifests;
$invalid[0]['option_name_refs'][0]['id_kind'] = 'acme_missing';
$assertThrows(
    static fn() => ReferenceKindGrammar::validate_ref_kinds($invalid, $sitePolicy),
    'option_name_refs[0].id_kind',
    'option-name refs must name a declared table id_kind rather than an arbitrary ledger keyspace'
);

$invalid = $validManifests;
$invalid[0]['block_attrs']['acme/card'][1]['kind_from']['map']['room'] = 'psot';
$assertThrows(
    static fn() => ReferenceKindGrammar::validate_ref_kinds($invalid, $sitePolicy),
    'block_attrs.acme/card[1].kind_from.map.room',
    'attribute kind_from map values share the token vocabulary and report their exact path'
);

$check(
    ReferenceKindGrammar::engineRefKinds() === ['post', 'term', 'tt', 'user']
        && ReferenceKindGrammar::engineTokenKinds() === ['post', 'term', 'tt']
        && ReferenceKindGrammar::engineLedgerKinds() === ['post', 'term', 'term_taxonomy'],
    'the extracted grammar publishes the three engine-owned bases without duplication'
);
$vocabularies = Policy::closed_vocabularies();
$check(
    $vocabularies['engine_ref_kinds'] === ReferenceKindGrammar::engineRefKinds()
        && $vocabularies['engine_token_kinds'] === ReferenceKindGrammar::engineTokenKinds()
        && $vocabularies['engine_ledger_kinds'] === ReferenceKindGrammar::engineLedgerKinds(),
    'Policy::closed_vocabularies() returns the exact arrays consulted by ReferenceKindGrammar'
);

$frozenSnapshot = static function (array $manifests): array {
    return FrozenPolicy::envelope($manifests, FrozenPolicy::site($manifests, DUO_SPEC_VERSION));
};

$snapshotManifests = [manifest_a(), manifest_b()];
$assertAccepted(
    static fn() => Policy::from_snapshot($frozenSnapshot($snapshotManifests)),
    'a valid two-manifest snapshot passes the extracted grammar through Policy::from_snapshot()'
);
$badSnapshotManifests = [
    manifest_a(),
    manifest_b([
        'options' => [
            'acme_b_bad_ref' => [
                'class' => 'authored',
                'autoload' => 'yes',
                'ref' => 'psot',
            ],
        ],
    ]),
];
$assertThrows(
    static fn() => Policy::from_snapshot($frozenSnapshot($badSnapshotManifests)),
    'reference kind vocabulary is closed',
    'Policy::from_snapshot() reaches ReferenceKindGrammar for a malformed ref kind'
);

$loadRoot = sys_get_temp_dir() . '/duo_regress_reference_kind_' . bin2hex(random_bytes(4));
$loadManifests = $loadRoot . '/manifests';
mkdir($loadManifests, 0777, true);
Canon::write_file($loadRoot . '/site.duo.json', Canon::encode([
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
manifest_fixture_code($loadManifests);
Canon::write_file($loadManifests . '/a.json', Canon::encode($snapshotManifests[0]));
Canon::write_file($loadManifests . '/b.json', Canon::encode($snapshotManifests[1]));
$previousManifestsDir = getenv('DUO_MANIFESTS_DIR');
putenv("DUO_MANIFESTS_DIR=$loadManifests");
$assertAccepted(
    static fn() => Policy::load($loadRoot),
    'the live Policy::load() path reaches ReferenceKindGrammar too'
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

$policySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$finalizerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/PolicyLoadFinalizer.php');
$policyReflection = new ReflectionClass(Policy::class);
$grammarReflection = new ReflectionClass(ReferenceKindGrammar::class);
$check(
    !$policyReflection->hasMethod('validate_ref_kinds')
        && !$policyReflection->hasMethod('validate_ledger_kind_claims')
        && !$policyReflection->hasMethod('collect_ref_kind_claims')
        && !$policyReflection->hasMethod('validate_attr_kind_claims')
        && $grammarReflection->hasMethod('validate_ref_kinds')
        && $grammarReflection->getMethod('validate_ref_kinds')->isPublic()
        && $grammarReflection->getMethod('engineRefKinds')->isPublic()
        && $grammarReflection->getMethod('engineTokenKinds')->isPublic()
        && $grammarReflection->getMethod('engineLedgerKinds')->isPublic()
        && substr_count($policySource, 'PolicyLoadFinalizer::finalize(') === 2
        && substr_count($policySource, "ReferenceKindGrammar::validate_ref_kinds(\$p->manifests, \$p->site['policy'] ?? [])") === 0
        && substr_count($finalizerSource, "ReferenceKindGrammar::validate_ref_kinds(\$policy->manifests, \$policy->site['policy'] ?? [])") === 1,
    'Policy delegates both loader paths to PolicyLoadFinalizer, which owns the ref-kind closure'
);

if ($failures !== []) {
    fwrite(STDERR, 'regress_reference_kind_grammar: ' . count($failures) . " failure(s)\n");
    exit(1);
}

echo "ALL PASSED\n";
