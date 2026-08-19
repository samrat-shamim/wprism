<?php
/**
 * Offline regression for ReferenceKeyspaceGrammar (DUO-3348 slice 23).
 *
 * ReferenceShapeGrammar owns one declaration's local ref shape. This suite
 * characterizes the later cross-source keyspace/sidecar pass directly, then
 * drives both Policy loader entry points so the extracted grammar cannot be
 * silently skipped by either live or frozen validation.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../agent/src/Kernel/ReferenceKeyspaceGrammar.php';
require_once __DIR__ . '/manifest_fixtures.php';

use Duo\Canon;
use Duo\Policy;
use Duo\ReferenceKeyspaceGrammar;

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

$declaredTables = [
    'acme_rooms' => [
        'class' => 'authored_snapshot',
        'id_kind' => 'acme_room',
    ],
    'acme_room_meta' => [
        'class' => 'authored_snapshot_meta',
        'attached_to' => ['table' => 'acme_rooms', 'column' => 'room_id'],
    ],
];

$validSource = [
    'options' => [
        'acme_room_option' => ['ref' => 'acme_room'],
    ],
    'post_meta' => [
        'post_owner' => ['json_refs' => [['path' => '$.owner', 'kind' => 'post']]],
    ],
    'term_meta' => [
        'term_parent' => ['ref' => 'term'],
    ],
    'user_meta' => [
        'user_owner' => ['ref' => 'user'],
    ],
    'option_patterns' => [
        ['ref' => 'tt'],
    ],
    'meta_patterns' => [
        ['ref' => 'acme_room'],
    ],
    'option_name_refs' => [
        ['ref' => 'acme_room'],
    ],
    'dynamic_options' => [
        'acme_dynamic_' => [
            'sub_keys' => [
                'room' => ['ref' => 'acme_room'],
            ],
        ],
    ],
    'taxonomies' => [
        'acme_topics' => [
            'description_refs' => ['kind' => 'term'],
        ],
    ],
    'tables' => [
        'acme_room_meta' => [
            'class' => 'authored_snapshot_meta',
            'keys' => [
                'owner' => ['ref' => 'user'],
            ],
        ],
    ],
];

$assertAccepted(
    static fn() => ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars(
        ['options' => ['site_post' => ['ref' => 'post']]],
        [$validSource],
        $declaredTables
    ),
    'engine, declared-table, and user reference keyspaces remain accepted across every declaration surface'
);
$assertAccepted(
    static fn() => ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [], []),
    'omitted reference declarations and sidecars remain accepted'
);
$assertThrows(
    static function () use ($validSource, $declaredTables): void {
        $source = $validSource;
        $source['options']['acme_room_option']['ref'] = 'acme_missing';
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$source], $declaredTables);
    },
    "unknown reference keyspace 'acme_missing'",
    'a scalar reference naming no engine or declared table keyspace is refused'
);
$assertThrows(
    static function () use ($validSource, $declaredTables): void {
        $source = $validSource;
        $source['dynamic_options']['acme_dynamic_']['sub_keys']['room']['ref'] = 'acme_missing';
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$source], $declaredTables);
    },
    "dynamic_options.acme_dynamic_.sub_keys.room declares unknown reference keyspace 'acme_missing'",
    'nested dynamic-option sub-key references are checked recursively with their full path'
);
$assertThrows(
    static function () use ($validSource, $declaredTables): void {
        $source = $validSource;
        $source['post_meta']['post_owner']['json_refs'][0]['kind'] = 'user';
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$source], $declaredTables);
    },
    "post_meta.post_owner declares unknown reference keyspace 'user'",
    'structured references refuse the login keyspace even though scalar login refs are intentionally accepted'
);
$assertThrows(
    static function () use ($validSource, $declaredTables): void {
        $source = $validSource;
        $source['tables']['acme_room_meta']['keys']['owner']['ref'] = 'acme_missing';
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$source], $declaredTables);
    },
    "tables.acme_room_meta.keys.owner declares unknown reference keyspace 'acme_missing'",
    'attached-meta key declarations use the same closed keyspace grammar'
);
$assertThrows(
    static function () use ($validSource, $declaredTables): void {
        $tables = $declaredTables;
        $tables['acme_other_meta'] = [
            'class' => 'authored_snapshot_meta',
            'attached_to' => ['table' => 'acme_rooms', 'column' => 'other_id'],
        ];
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$validSource], $tables);
    },
    "attached-meta tables 'acme_room_meta' and 'acme_other_meta' both attach to 'acme_rooms'",
    'two sidecars attached to one canonical row table are refused as ambiguous'
);
$assertThrows(
    static function () use ($validSource, $declaredTables): void {
        $tables = $declaredTables;
        $tables['acme_room_meta']['attached_to'] = ['table' => 'acme_rooms'];
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars([], [$validSource], $tables);
    },
    "attached-meta table 'acme_room_meta' must declare attached_to {table, column}",
    'a sidecar missing its attached column is refused before any runtime query'
);

/** Build the smallest frozen envelope accepted by the real loader. */
$frozenSnapshot = static function (array $manifests): array {
    return [
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'dispositions' => null,
        'format' => 'duo-policy-snapshot/v4',
        'manifests' => $manifests,
        'site' => [
            'manifests' => array_map(static fn(array $manifest): string => (string) $manifest['name'], $manifests),
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
            'spec_version' => DUO_SPEC_VERSION,
        ],
    ];
};

$manifestA = manifest_a([
    'post_meta' => [
        'room_note' => ['ref' => 'acme_room'],
    ],
]);
$manifestB = manifest_b();
$manifestB['tables']['acme_b_room_meta'] = [
    'class' => 'authored_snapshot_meta',
    'attached_to' => ['table' => 'acme_a_rooms', 'column' => 'room_id'],
    'keys' => ['owner' => ['ref' => 'user']],
];
$validManifests = [$manifestA, $manifestB];
$assertAccepted(
    static fn() => Policy::from_snapshot($frozenSnapshot($validManifests)),
    'a valid reference keyspace and one attached sidecar load through Policy::from_snapshot()'
);
$invalidSnapshot = $validManifests;
$invalidSnapshot[0]['taxonomies'] = [
    'bad_description' => [
        'description_refs' => ['kind' => 'user'],
    ],
];
$assertThrows(
    static fn() => Policy::from_snapshot($frozenSnapshot($invalidSnapshot)),
    "manifest 'a'.taxonomies.bad_description.description_refs declares unknown reference keyspace 'user'",
    'Policy::from_snapshot() invokes the extracted cross-source keyspace grammar'
);

$loadRoot = sys_get_temp_dir() . '/duo_regress_reference_keyspace_' . bin2hex(random_bytes(4));
$loadManifests = $loadRoot . '/manifests';
mkdir($loadManifests, 0777, true);
Canon::write_file($loadRoot . '/site.duo.json', Canon::encode([
    'manifests' => ['a', 'b'],
    'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    'spec_version' => DUO_SPEC_VERSION,
]));
manifest_fixture_code($loadManifests);
Canon::write_file($loadManifests . '/a.json', Canon::encode($manifestA));
Canon::write_file($loadManifests . '/b.json', Canon::encode($manifestB));
$previousManifestsDir = getenv('DUO_MANIFESTS_DIR');
putenv("DUO_MANIFESTS_DIR=$loadManifests");
$assertAccepted(
    static fn() => Policy::load($loadRoot),
    'the live Policy::load() path invokes the extracted cross-source keyspace grammar'
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

$policySource = (string) file_get_contents(__DIR__ . '/../../agent/src/Policy/Policy.php');
$finalizerSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Policy/PolicyLoadFinalizer.php');
$policyReflection = new ReflectionClass(Policy::class);
$grammarReflection = new ReflectionClass(ReferenceKeyspaceGrammar::class);
$check(
    !$policyReflection->hasMethod('validate_reference_keyspaces_and_sidecars')
        && !$policyReflection->hasMethod('assert_reference_rule_keyspaces')
        && $grammarReflection->hasMethod('validate_reference_keyspaces_and_sidecars')
        && $grammarReflection->getMethod('validate_reference_keyspaces_and_sidecars')->isPublic()
        && substr_count($policySource, 'PolicyLoadFinalizer::finalize(') === 2
        && substr_count($policySource, 'ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars(') === 0
        && substr_count($finalizerSource, 'ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars(') === 1,
    'Policy delegates both loader paths to PolicyLoadFinalizer, which owns the cross-source keyspace call'
);

if ($failures !== []) {
    fwrite(STDERR, 'regress_reference_keyspace_grammar: ' . count($failures) . " failure(s)\n");
    exit(1);
}

echo "ALL PASSED\n";
