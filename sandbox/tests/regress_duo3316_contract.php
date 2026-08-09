<?php
/**
 * Offline DUO-3316 contract regression.
 *
 * This deliberately uses a pair of independently-authored, plugin-neutral
 * fixture declarations rather than Polylang/Ninja Forms names.  It exercises
 * the manifest boundary (taxonomy object_keyspace, full description_refs
 * json_refs/key_refs, one attached structured-meta sidecar plus an independent
 * second-sidecar refusal/JSON variant) and then feeds the same declarations to
 * the real offline repository compiler.
 *
 * There is no WordPress bootstrap or target access in this file.  A valid
 * tree contains canonical tokens; companion raw-id trees must be rejected by
 * RepositoryCompiler before a target query could be constructed.
 */

declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

$engineRoot = getenv('DUO3316_ENGINE_ROOT');
if (!is_string($engineRoot) || !is_dir($engineRoot)) {
    $engineRoot = __DIR__ . '/../../agent/src';
}
foreach ([
    'Uuid.php',
    'Canon.php',
    'Code.php',
    'CodeStateContract.php',
    'OptionState.php',
    'UserMetaState.php',
    'Secrets.php',
    'PersonalData.php',
    'Db.php',
    'Policy.php',
    'Ledger.php',
    'Snapshot.php',
    'Deletion.php',
    'SidebarState.php',
    'RepositoryAuthorization.php',
    'RepositoryCompiler.php',
    // These are the DUO-3316 shared boundary helpers.  The conditional
    // include keeps this origin/main regression runnable as a characterized
    // red test before the product implementation lands.
    'ReferencePath.php',
    'ReferenceRules.php',
    'StructuredValue.php',
    'JsonRefs.php',
] as $engineFile) {
    $path = rtrim($engineRoot, '/') . '/' . $engineFile;
    if (is_file($path)) {
        require_once $path;
    }
}

use Duo\Canon;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryCompiler;

$failures = 0;

function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function expect_throw(callable $fn, string $message): void {
    try {
        $fn();
        check(false, "$message (no exception)");
    } catch (Throwable $e) {
        check(true, "$message ({$e->getMessage()})");
    }
}

function put_bytes(string $path, string $bytes): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create $dir");
    }
    file_put_contents($path, $bytes);
}

function put_json(string $path, array $value): void {
    put_bytes($path, Canon::encode($value));
}

function fixture_uuid(int $n): string {
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

function remove_tree(string $root): void {
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    @rmdir($root);
}

/** One independent manifest: no shipped plugin name or observed payload is used. */
function fixture_manifest(string $sidecar = 'a'): array {
    if (!in_array($sidecar, ['a', 'b', 'both'], true)) {
        throw new InvalidArgumentException("unknown fixture sidecar '$sidecar'");
    }
    $manifest = [
        'name' => 'duo3316-fixture',
        'spec_version' => DUO_SPEC_VERSION,
        'taxonomies' => [
            // The relationship rows for this taxonomy are term-owned even
            // though the fixture plugin can register an arbitrary object type
            // sentinel.  The declaration, not that runtime spelling, is the
            // portable authority.
            'dks_term_relation' => [
                'object_keyspace' => 'term',
                'description_refs' => [
                    'json_refs' => [
                        ['path' => '$.*.linked_post', 'kind' => 'post'],
                        ['path' => '$.*.nested.linked_term', 'kind' => 'term'],
                    ],
                    'key_refs' => ['path' => '$.*.term_map', 'kind' => 'term'],
                ],
            ],
            'dks_post_relation' => [
                'object_keyspace' => 'post',
            ],
        ],
        'taxonomy_patterns' => [
            [
                'match' => '^dks_pattern_relation$',
                'object_type' => ['post'],
                'update_count_callback' => '_update_post_term_count',
                'object_keyspace' => 'term',
            ],
        ],
        'options' => [
            'dks_structured_option' => [
                'class' => 'env',
                'autoload' => 'yes',
                'required' => false,
                'sub_keys' => [
                    'payload' => [
                        'class' => 'authored',
                        'key_refs' => ['path' => '$.term_map', 'kind' => 'term'],
                    ],
                ],
            ],
        ],
        'tables' => [
            'dks_entries' => [
                'class' => 'authored_snapshot',
                'pk' => 'id',
                'id_kind' => 'dks_entry',
                'identity' => ['mode' => 'natural_key', 'column' => 'name'],
                'slug_column' => 'name',
                'columns' => ['name' => ['class' => 'authored']],
                'refs' => [],
            ],
            // Sidecar A is the valid owner fixture.  Sidecar B is selected by
            // fixture_manifest('b') for an independent JSON-encoded variant;
            // fixture_manifest('both') is the load-time refusal case because
            // both would fold into one canonical `meta` object.
            'dks_entry_meta_a' => [
                'class' => 'authored_snapshot_meta',
                'attached_to' => ['table' => 'dks_entries', 'column' => 'entry_id'],
                'key_column' => 'meta_key',
                'value_column' => 'meta_value',
                'default_class' => 'authored',
                'keyspace' => [
                    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
                    'keys' => ['scalar_post', 'payload_a'],
                    'patterns' => [],
                ],
                'keys' => [
                    'scalar_post' => ['class' => 'authored', 'ref' => 'post'],
                    'payload_a' => [
                        'class' => 'authored',
                        'json_refs' => [
                            ['path' => '$.linked_post', 'kind' => 'post'],
                        ],
                        'key_refs' => ['path' => '$.term_map', 'kind' => 'term'],
                    ],
                ],
            ],
            'dks_entry_meta_b' => [
                'class' => 'authored_snapshot_meta',
                'attached_to' => ['table' => 'dks_entries', 'column' => 'entry_id'],
                'key_column' => 'meta_key',
                'value_column' => 'meta_value',
                'default_class' => 'authored',
                'keyspace' => [
                    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
                    'keys' => ['payload_b'],
                    'patterns' => [],
                ],
                'keys' => [
                    'payload_b' => [
                        'class' => 'authored',
                        'json_encoded' => true,
                        'json_refs' => [
                            ['path' => '$..linked_term', 'kind' => 'term'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    if ($sidecar === 'a') {
        unset($manifest['tables']['dks_entry_meta_b']);
    } elseif ($sidecar === 'b') {
        unset($manifest['tables']['dks_entry_meta_a']);
    }
    return $manifest;
}

function fixture_site(string $manifestName = 'duo3316-fixture'): array {
    return [
        'manifests' => [$manifestName],
        'policy' => [
            'options' => (object) [],
            'post_meta' => (object) [],
            'term_meta' => (object) [],
            'post_types' => ['post'],
            'taxonomies' => ['dks_term_relation', 'dks_post_relation'],
        ],
        'spec_version' => DUO_SPEC_VERSION,
    ];
}

function write_fixture_inputs(string $root, ?array $manifest = null): void {
    $manifest ??= fixture_manifest();
    put_json("$root/manifests/{$manifest['name']}.json", $manifest);
    put_json("$root/site.duo.json", fixture_site((string) $manifest['name']));
    put_json("$root/state/placeholder.json", []);
}

function load_fixture_policy(string $root, ?array $manifest = null): Policy {
    write_fixture_inputs($root, $manifest);
    putenv("DUO_MANIFESTS_DIR=$root/manifests");
    return Policy::load($root);
}

function post_front(string $uuid): array {
    return [
        'author' => 'user:admin',
        'comment_status' => 'open',
        'date' => '2026-08-09 00:00:00',
        'date_gmt' => '2026-08-09 00:00:00',
        'excerpt' => '',
        'menu_order' => 0,
        'meta' => (object) [],
        'modified_gmt' => '2026-08-09 00:00:00',
        'parent' => null,
        'ping_status' => 'closed',
        'slug' => 'dks-source-post',
        'status' => 'publish',
        'terms' => (object) [],
        'title' => 'Duo Keyspace Source Post',
        'type' => 'post',
        'uuid' => $uuid,
    ];
}

/**
 * Build a minimal compile-able state tree. `$rawSurface` deliberately leaves
 * one declared post/term path or key-ref key numeric; the valid variant uses
 * the exact same shape with canonical tokens.  Keeping each surface isolated
 * proves a structured-path gate is not vacuously passing because a different
 * scalar ref happened to fail first.
 */
function write_compile_tree(string $root, string $rawSurface = 'none', string $sidecar = 'a'): array {
    if (!in_array($sidecar, ['a', 'b'], true)) {
        throw new InvalidArgumentException("unknown compile sidecar '$sidecar'");
    }
    $termUuid = fixture_uuid(1);
    $postUuid = fixture_uuid(2);
    $entryUuid = fixture_uuid(3);
    $termToken = "{{term:$termUuid}}";
    $postToken = "{{post:$postUuid}}";
    $mapToken = "{{term:$termUuid}}";
    $rawDescription = $rawSurface === 'description' || $rawSurface === 'all';
    $rawSidecarA = $sidecar === 'a'
        && ($rawSurface === 'sidecar_a' || $rawSurface === 'all');
    $rawSidecarB = $sidecar === 'b'
        && ($rawSurface === 'sidecar_b' || $rawSurface === 'all');
    $rawScalar = $sidecar === 'a'
        && ($rawSurface === 'scalar_ref' || $rawSurface === 'all');
    $term = $rawDescription ? 701 : $termToken;
    $post = $rawDescription ? 702 : $postToken;
    $mapKey = $rawDescription ? 703 : $mapToken;

    $description = [
        'en' => [
            'linked_post' => $post,
            'nested' => ['linked_term' => $term],
            'term_map' => [$mapKey => 'english'],
        ],
        'fr' => [
            'linked_post' => $postToken,
            'nested' => ['linked_term' => $termToken],
            'term_map' => [],
        ],
    ];
    put_json("$root/state/terms/dks_term_relation/$termUuid--dks-term.json", [
        'description' => $description,
        'meta' => (object) [],
        'name' => 'Duo Keyspace Term',
        'parent' => null,
        'relationships' => (object) [],
        'slug' => 'dks-term',
        'taxonomy' => 'dks_term_relation',
        'uuid' => $termUuid,
    ]);
    put_bytes(
        "$root/state/posts/post/$postUuid--dks-source-post.md",
        Canon::post_file(post_front($postUuid), '')
    );

    $payloadA = [
        'linked_post' => $rawSidecarA ? 705 : $postToken,
        'term_map' => [($rawSidecarA ? 706 : $mapToken) => 'attached'],
    ];
    $payloadB = [
        ['linked_term' => $rawSidecarB ? 707 : $termToken],
    ];
    $meta = $sidecar === 'a'
        ? [
            'scalar_post' => $rawScalar ? 704 : $postToken,
            'payload_a' => $payloadA,
        ]
        : ['payload_b' => $payloadB];
    put_json("$root/state/tables/dks_entries/$entryUuid--dks-entry.json", [
        'columns' => ['name' => 'dks-entry'],
        'meta' => $meta,
        'table' => 'dks_entries',
        'uuid' => $entryUuid,
    ]);
    $optionMapKey = $rawSurface === 'option_subkey' ? 708 : $termToken;
    put_json("$root/state/options/core.json", OptionState::document([
        'dks_structured_option' => OptionState::present([
            'payload' => ['term_map' => [$optionMapKey => 'option-attached']],
        ], 'yes'),
    ]));
    return [$termUuid, $postUuid, $entryUuid];
}

function compile_fixture(string $root, Policy $policy): void {
    RepositoryCompiler::compile($root, $policy);
}

function expect_compile_success(string $root, Policy $policy, string $message): void {
    try {
        compile_fixture($root, $policy);
        check(true, $message);
    } catch (Throwable $e) {
        check(false, "$message ({$e->getMessage()})");
    }
}

function expect_compile_refusal(
    string $root,
    Policy $policy,
    string $message,
    ?string $diagnostic = null
): void {
    try {
        compile_fixture($root, $policy);
        check(false, "$message (no exception)");
    } catch (Throwable $e) {
        $detail = $e->getMessage();
        check(
            $diagnostic === null || str_contains($detail, $diagnostic),
            $diagnostic === null
                ? "$message ($detail)"
                : "$message ($detail; expected diagnostic $diagnostic)"
        );
    }
}

$tmp = sys_get_temp_dir() . '/duo3316-contract-' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    remove_tree($tmp);
    putenv('DUO_MANIFESTS_DIR');
});

echo "\n== manifest load: valid full form + attached structured sidecar ==\n";
$policy = load_fixture_policy($tmp);
check($policy instanceof Policy, 'normal Policy::load accepts object_keyspace, full description_refs, and attached structured meta');
$description = $policy->description_refs_for_taxonomy('dks_term_relation');
check(
    is_array($description)
        && isset($description['json_refs'], $description['key_refs'])
        && count($description['json_refs']) === 2,
    'description_refs exposes both declared json_refs and key_refs in the loaded policy'
);
check(
    ($policy->declared_table_details('dks_entry_meta_a')['rule']['keys']['payload_a']['key_refs']['kind'] ?? null) === 'term'
        && $policy->declared_table_details('dks_entry_meta_b')['rule'] === null,
    'the independent attached structured-meta fixture retains its json_refs/key_refs rule'
);
check(
    method_exists($policy, 'taxonomy_object_keyspace')
        && $policy->taxonomy_object_keyspace('dks_term_relation') === 'term'
        && $policy->taxonomy_object_keyspace('dks_post_relation') === 'post'
        && $policy->taxonomy_object_keyspace('dks_pattern_relation') === 'term',
    'normal Policy::load resolves exact and taxonomy_patterns object_keyspaces'
);

echo "\n== normal/frozen policy loads ==\n";
$frozen = Policy::from_snapshot($policy->export_snapshot());
check($frozen instanceof Policy, 'frozen Policy::from_snapshot accepts the same validated declaration');
$frozenDescription = $frozen->description_refs_for_taxonomy('dks_term_relation');
check(
    is_array($frozenDescription)
        && isset($frozenDescription['json_refs'], $frozenDescription['key_refs']),
    'frozen policy preserves the full description structured-reference declaration'
);
check(
    method_exists($frozen, 'taxonomy_object_keyspace')
        && $frozen->taxonomy_object_keyspace('dks_term_relation') === 'term'
        && $frozen->taxonomy_object_keyspace('dks_post_relation') === 'post'
        && $frozen->taxonomy_object_keyspace('dks_pattern_relation') === 'term',
    'frozen Policy::from_snapshot preserves exact and taxonomy_patterns object_keyspaces'
);
$legacy = fixture_manifest();
$legacy['taxonomies']['dks_term_relation']['description_refs'] = ['kind' => 'post'];
$legacyPolicy = load_fixture_policy($tmp, $legacy);
$legacyRule = $legacyPolicy->description_refs_for_taxonomy('dks_term_relation');
check(
    ($legacyRule['json_refs'][0]['path'] ?? null) === '$.*'
        && ($legacyRule['json_refs'][0]['kind'] ?? null) === 'post'
        && ($legacyRule['legacy_flat_map'] ?? false) === true,
    'normal Policy::load keeps legacy flat description refs semantically and byte-compatibly normalized'
);
$legacySnapshot = $legacyPolicy->export_snapshot();
check(
    ($legacySnapshot['manifests'][0]['taxonomies']['dks_term_relation']['description_refs'] ?? null)
        === ['kind' => 'post'],
    'normal policy snapshot preserves the legacy description declaration bytes'
);
$legacyFrozen = Policy::from_snapshot($legacyPolicy->export_snapshot());
$legacyFrozenRule = $legacyFrozen->description_refs_for_taxonomy('dks_term_relation');
check(
    ($legacyFrozenRule['json_refs'][0]['path'] ?? null) === '$.*'
        && ($legacyFrozenRule['json_refs'][0]['kind'] ?? null) === 'post'
        && ($legacyFrozenRule['legacy_flat_map'] ?? false) === true,
    'frozen Policy::from_snapshot keeps legacy flat description refs semantically compatible'
);

echo "\n== manifest-load refusal matrix ==\n";
$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['description_refs']['json_refs'][0]['path'] = '$.';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'invalid description json_refs path is refused at manifest load');

$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['description_refs']['key_refs']['path'] = '$';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'bare description key_refs path is refused at manifest load');

$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['description_refs']['json_refs'][] = [
    'path' => '$..linked_post', 'kind' => 'term',
];
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'overlapping wildcard/recursive description refs with different kinds are refused at manifest load');

$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['description_refs'] = [
    'json_refs' => [['path' => '$.links', 'kind' => 'post']],
    'key_refs' => ['path' => '$.links', 'kind' => 'term'],
];
expect_throw(
    fn() => load_fixture_policy($tmp, $invalid),
    'a json_refs scalar path equal to a key_refs map path is refused at manifest load'
);

$validNested = fixture_manifest();
$validNested['taxonomies']['dks_term_relation']['description_refs'] = [
    'json_refs' => [['path' => '$.links.*.post_id', 'kind' => 'post']],
    'key_refs' => ['path' => '$.links', 'kind' => 'term'],
];
try {
    load_fixture_policy($tmp, $validNested);
    check(true, 'json_refs below a key_refs map remain a valid combined declaration');
} catch (Throwable $e) {
    check(false, 'json_refs below a key_refs map remain a valid combined declaration (' . $e->getMessage() . ')');
}

$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['object_keyspace'] = 'comment';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'unsupported taxonomy object_keyspace is refused at manifest load');

$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['object_keyspace'] = ['post', 'term'];
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'ambiguous taxonomy object_keyspace list is refused at manifest load');

$invalid = fixture_manifest();
$invalid['taxonomy_patterns'][0]['object_keyspace'] = 'comment';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'unsupported taxonomy_patterns object_keyspace is refused at manifest load');

$invalid = fixture_manifest();
$invalid['taxonomy_patterns'][] = [
    'match' => '^dks_pattern_relation$',
    'object_type' => ['post'],
    'update_count_callback' => '_update_post_term_count',
    'object_keyspace' => 'post',
];
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'conflicting taxonomy_patterns object_keyspaces are refused at manifest load');

$first = fixture_manifest();
$second = fixture_manifest();
$second['name'] = 'duo3316-conflicting-description';
$second['taxonomies']['dks_term_relation']['description_refs']['json_refs'][0]['path'] = '$.*.other_post';
put_json("$tmp/manifests/{$first['name']}.json", $first);
put_json("$tmp/manifests/{$second['name']}.json", $second);
$twoManifestSite = fixture_site();
$twoManifestSite['manifests'] = [$first['name'], $second['name']];
put_json("$tmp/site.duo.json", $twoManifestSite);
putenv("DUO_MANIFESTS_DIR=$tmp/manifests");
expect_throw(
    fn() => Policy::load($tmp),
    'conflicting duplicate taxonomy description reference grammars are refused at manifest load'
);

$invalid = fixture_manifest();
$invalid['taxonomies']['dks_term_relation']['description_refs']['json_refs'][0]['kind'] = 'comment';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'unknown description reference keyspace is refused at manifest load');

$invalid = fixture_manifest();
$invalid['tables']['dks_entry_meta_a']['keys']['payload_a']['key_refs']['kind'] = 'comment';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'unknown attached reference keyspace is refused at manifest load');

$invalid = fixture_manifest();
$invalid['tables']['dks_entry_meta_a']['keys']['payload_a']['ref'] = 'post';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'attached scalar ref mixed with json_refs/key_refs is refused at manifest load');

$invalid = fixture_manifest();
$invalid['tables']['dks_entry_meta_a']['keys']['payload_a']['key_refs']['path'] = '$';
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'bare attached key_refs path is refused at manifest load');

foreach ([
    'ref' => 'post',
    'json_refs' => [['path' => '$.term_id', 'kind' => 'term']],
    'key_refs' => ['path' => '$.term_map', 'kind' => 'term'],
    'json_encoded' => true,
    'cast' => 'string',
    'order_preserving' => true,
    'allow_secret' => true,
    'lint_ok' => true,
] as $field => $value) {
    $invalid = fixture_manifest();
    $invalid['options']['dks_structured_option'][$field] = $value;
    expect_throw(
        fn() => load_fixture_policy($tmp, $invalid),
        "sub-keyed option parent whole-value field '$field' is refused at manifest load"
    );
}

$invalid = fixture_manifest();
$invalid['options']['dks_structured_option']['sub_keys']['payload']['sub_keys'] = [
    'nested' => ['class' => 'authored'],
];
expect_throw(
    fn() => load_fixture_policy($tmp, $invalid),
    'nested option sub_keys are refused instead of silently ignored'
);

$invalid = fixture_manifest();
$invalid['dynamic_options']['dks_dynamic'] = [
    'prefix' => 'dks_dynamic_',
    'resolver' => 'active_stylesheet',
    'autoload' => 'preserve',
    'sub_keys' => ['payload' => ['class' => 'authored']],
    'json_refs' => [['path' => '$.term_id', 'kind' => 'term']],
];
expect_throw(
    fn() => load_fixture_policy($tmp, $invalid),
    'dynamic sub-keyed option parent whole-value fields are refused at manifest load'
);

$invalid = fixture_manifest('both');
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'two attached sidecars for one owner are refused at manifest load');

$invalid = fixture_manifest();
$invalid['tables']['dks_entry_meta_a']['keyspace']['version_range'] = [
    'min' => '2.0.0', 'max' => '2.0.0',
];
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'malformed attached keyspace range is refused at manifest load');

$invalid = fixture_manifest('b');
$invalid['tables']['dks_entry_meta_b']['keyspace']['patterns'] = [
    ['match' => '[unterminated'],
];
expect_throw(fn() => load_fixture_policy($tmp, $invalid), 'malformed attached keyspace regex is refused at manifest load');

echo "\n== frozen-policy refusal mirrors normal load ==\n";
$snapshot = $policy->export_snapshot();
$snapshot['manifests'][0]['taxonomies']['dks_term_relation']['object_keyspace'] = 'comment';
expect_throw(fn() => Policy::from_snapshot($snapshot), 'frozen policy refuses an invalid object_keyspace before consumers run');

$snapshot = $policy->export_snapshot();
$snapshot['manifests'][0]['taxonomies']['dks_term_relation']['description_refs'] = [
    'json_refs' => [['path' => '$.links', 'kind' => 'post']],
    'key_refs' => ['path' => '$.links.by_term', 'kind' => 'term'],
];
expect_throw(
    fn() => Policy::from_snapshot($snapshot),
    'frozen policy refuses a json_refs scalar path that is an ancestor of a key_refs map path'
);

$snapshot = $policy->export_snapshot();
$conflictingFrozen = fixture_manifest();
$conflictingFrozen['name'] = 'duo3316-conflicting-description';
$conflictingFrozen['taxonomies']['dks_term_relation']['description_refs']['key_refs']['kind'] = 'post';
$snapshot['site']['manifests'] = ['duo3316-fixture', 'duo3316-conflicting-description'];
$snapshot['manifests'][] = $conflictingFrozen;
expect_throw(
    fn() => Policy::from_snapshot($snapshot),
    'frozen policy refuses conflicting duplicate taxonomy description reference grammars'
);

$snapshot = $policy->export_snapshot();
$snapshot['manifests'][0]['options']['dks_structured_option']['json_refs'] = [
    ['path' => '$.term_id', 'kind' => 'term'],
];
expect_throw(
    fn() => Policy::from_snapshot($snapshot),
    'frozen policy refuses a whole-value reference declaration on a sub-keyed option parent'
);

$snapshot = $policy->export_snapshot();
$snapshot['manifests'][0]['options']['dks_structured_option']['sub_keys']['payload']['sub_keys'] = [
    'nested' => ['class' => 'authored'],
];
expect_throw(
    fn() => Policy::from_snapshot($snapshot),
    'frozen policy refuses nested sub_keys instead of accepting a dead declaration'
);

$snapshot = $policy->export_snapshot();
$snapshot['manifests'][0]['dynamic_options']['dks_dynamic'] = [
    'prefix' => 'dks_dynamic_',
    'resolver' => 'active_stylesheet',
    'autoload' => 'preserve',
    'sub_keys' => ['payload' => ['class' => 'authored']],
    'key_refs' => ['path' => '$.term_map', 'kind' => 'term'],
];
expect_throw(
    fn() => Policy::from_snapshot($snapshot),
    'frozen policy refuses a whole-value reference declaration on a dynamic sub-keyed option parent'
);

$snapshot = $policy->export_snapshot();
$legacyPattern = [
    'name' => 'duo3316-legacy-pattern',
    'spec_version' => DUO_SPEC_VERSION,
    'taxonomy_patterns' => [[
        'match' => '^dks_pattern_relation$',
        'object_type' => ['post'],
        'update_count_callback' => '_update_post_term_count',
    ]],
];
$snapshot['site']['manifests'] = ['duo3316-fixture', 'duo3316-legacy-pattern'];
$snapshot['manifests'][] = $legacyPattern;
expect_throw(
    fn() => Policy::from_snapshot($snapshot),
    'frozen policy refuses omitted legacy-post versus explicit-term pattern ownership'
);

echo "\n== repository compiler: canonical tokens accepted, raw structured ids refused ==\n";
$validRepo = "$tmp/valid-repo";
mkdir($validRepo, 0777, true);
put_json("$validRepo/site.duo.json", fixture_site());
write_compile_tree($validRepo);
$validPolicy = load_fixture_policy($tmp);
// Policy::load() above rewrites the same manifest directory/site shape; the
// repository compiler reads the separate valid-repo tree below.
expect_compile_success(
    $validRepo,
    $validPolicy,
    'compiler accepts canonical description and attached sidecar json_refs + key_refs tokens'
);

$validBRepo = "$tmp/valid-repo-b";
mkdir($validBRepo, 0777, true);
put_json("$validBRepo/site.duo.json", fixture_site());
write_compile_tree($validBRepo, 'none', 'b');
$validBPolicy = load_fixture_policy($tmp, fixture_manifest('b'));
expect_compile_success(
    $validBRepo,
    $validBPolicy,
    'compiler accepts canonical tokens in the independent json_encoded attached sidecar variant'
);

foreach ([
    'description' => [
        'message' => 'compiler refuses raw numeric ids at declared description json_refs/key_refs paths',
        'diagnostic' => 'nonportable_reference',
    ],
    'sidecar_a' => [
        'message' => 'compiler refuses raw numeric ids at the first sidecar json_refs/key_refs paths',
        'diagnostic' => 'nonportable_reference',
    ],
    'sidecar_b' => [
        'message' => 'compiler refuses raw numeric ids at the second sidecar json_refs path',
        'diagnostic' => 'nonportable_reference',
    ],
    'scalar_ref' => [
        'message' => 'compiler refuses raw numeric ids at the existing scalar attached ref path',
        'diagnostic' => 'nonportable_reference',
    ],
    'option_subkey' => [
        'message' => 'compiler refuses raw numeric map keys at ordinary option sub-key key_refs paths',
        'diagnostic' => 'nonportable_reference',
    ],
] as $surface => $expectation) {
    $surfaceRepo = "$tmp/raw-$surface";
    mkdir($surfaceRepo, 0777, true);
    put_json("$surfaceRepo/site.duo.json", fixture_site());
    $surfacePolicy = $surface === 'sidecar_b' ? $validBPolicy : $validPolicy;
    $surfaceSidecar = $surface === 'sidecar_b' ? 'b' : 'a';
    write_compile_tree($surfaceRepo, $surface, $surfaceSidecar);
    expect_compile_refusal(
        $surfaceRepo,
        $surfacePolicy,
        $expectation['message'],
        $expectation['diagnostic']
    );
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) FAILED\n");
    exit(1);
}
echo "\nALL PASSED\n";
