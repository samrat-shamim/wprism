<?php
declare(strict_types=1);

/**
 * Offline product-path contract for the five exact-artifact adapters added by
 * the 2026-08-22 ecosystem probe, including the independently certified
 * Advanced Editor Tools, Classic Editor, Code Snippets, WPS Hide Login, and
 * Yoast Duplicate Post subjects. The assertions load the shipped manifests and disposition registry
 * through Policy::load(); fixtures would
 * miss the byte set that managed sites actually pin.
 *
 * The mutation cases use the same loader against a temporary manifest
 * library. Each removes or contradicts a boundary that prevents local ids or
 * ambiguous ownership from reaching canonical state. They therefore fail on
 * the engine's load-time grammar, before capture or target mutation.
 */

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Review/ShortcodeReferenceScanner.php';
require_once $root . '/tools/src/ArtifactLibrary.php';

use WPrism\Canon;
use WPrism\Policy;
use WPrism\ShortcodeReferenceScanner;

require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();

$names = [
    'advanced-editor-tools',
    'classic-editor',
    'code-snippets',
    'wps-hide-login',
    'yoast-duplicate-post',
];
$sourceLibrary = \WPrism\AdapterLibrary::fromSourceTree($root);

/** @var array<string,array<string,mixed>> $manifests */
$manifests = [];
foreach ($names as $name) {
    $package = $sourceLibrary->package($name);
    if ($package === null) {
        throw new RuntimeException("missing shipped adapter package $name");
    }
    $manifests[$name] = Canon::decode(Canon::read_file($package->manifestPath()));
}
$conformanceEntry = Canon::decode(Canon::read_file($root . '/sandbox/conformance/entries/ecosystem-adapter-batch.json'));
$standaloneEntries = [
    'advanced-editor-tools' => Canon::decode(Canon::read_file($root . '/adapter-packages/advanced-editor-tools/tests/conformance/entry.json')),
    'classic-editor' => Canon::decode(Canon::read_file($root . '/adapter-packages/classic-editor/tests/conformance/entry.json')),
    'code-snippets' => Canon::decode(Canon::read_file($root . '/adapter-packages/code-snippets/tests/conformance/entry.json')),
    'wps-hide-login' => Canon::decode(Canon::read_file($root . '/adapter-packages/wps-hide-login/tests/conformance/entry.json')),
    'yoast-duplicate-post' => Canon::decode(Canon::read_file($root . '/adapter-packages/yoast-duplicate-post/tests/conformance/entry.json')),
];
$artifactLock = \WPrism\Tooling\ArtifactLibrary::load($root);

$policy = Policy::load(null, $names, adapterLibrary: $sourceLibrary);

wprism_check_same($names, array_column($policy->manifests, 'name'), 'the real policy loader accepts exactly the five shipped adapter manifests');

$artifacts = [
    'advanced-editor-tools' => [
        'plugin' => 'tinymce-advanced/tinymce-advanced.php',
        'range' => ['min' => '5.9.2', 'max' => '5.9.3'],
        'sha256' => 'ec4c6635dc9d0f9c27d0256d7b83b3655b5904c936748604c698d256fbfd69c7',
    ],
    'classic-editor' => [
        'plugin' => 'classic-editor/classic-editor.php',
        'range' => ['min' => '1.7.0', 'max' => '1.7.1'],
        'sha256' => '7cc7799b0b7d820fbb7528dbff8027c736e52513992416cb37279f44d3d9dd77',
    ],
    'code-snippets' => [
        'plugin' => 'code-snippets/code-snippets.php',
        'range' => ['min' => '3.9.5', 'max' => '3.9.7'],
        'sha256' => '19bb57c5f677348fb449bc5e8115fb9852d523bc5b4c7021aeab1767169af6f4',
    ],
    'wps-hide-login' => [
        'plugin' => 'wps-hide-login/wps-hide-login.php',
        'range' => ['min' => '1.9.19', 'max' => '1.9.20'],
        'sha256' => '34e99ee6032f5863b278aef690ebbfdb00290beb4eacbdae3ab931eb719b3a58',
    ],
    'yoast-duplicate-post' => [
        'plugin' => 'duplicate-post/duplicate-post.php',
        'range' => ['min' => '4.7', 'max' => '4.8'],
        'sha256' => '8cbeebf7c2ee982d2ae33833088ba8ac2391cc316a4dd71800a07f5fa128b418',
    ],
];

$effectiveRanges = $policy->version_ranges();
$certified = ['advanced-editor-tools', 'classic-editor', 'code-snippets', 'wps-hide-login', 'yoast-duplicate-post'];
foreach ($artifacts as $name => $artifact) {
    $manifest = $manifests[$name];
    wprism_check_same($artifact['plugin'], $manifest['plugin'] ?? null, "$name pins the observed plugin basename");
    wprism_check_same($artifact['range'], $manifest['version_range'] ?? null, "$name admits only the observed patch boundary");
    wprism_check(
        str_contains(implode("\n", $manifest['notes'] ?? []), $artifact['sha256']),
        "$name records the exact official artifact SHA-256 in shipped identity"
    );
    wprism_check_same(
        $artifact['range'] + ['manifest' => $name],
        $effectiveRanges[$artifact['plugin']] ?? null,
        "$name exposes the same bounded range through Policy::version_ranges()"
    );

    $entry = $policy->manifest_disposition($name);
    $expectedStatus = in_array($name, $certified, true) ? 'certified' : 'experimental';
    wprism_check_same($expectedStatus, $entry['status'] ?? null, "$name carries its reviewed certification status");
    wprism_check_json_equal(
        ['plugin' => $artifact['plugin'], 'range' => $artifact['range']],
        $entry['supported_versions'] ?? null,
        "$name disposition repeats the exact artifact boundary"
    );
    if (in_array($name, $certified, true)) {
        wprism_check_same(
            ['bundle_schema' => 'wprism-subject-certification-bundle/v1', 'tests' => ["conformance-$name", 'exact-artifact-version-matrix']],
            $entry['evidence'] ?? null,
            "$name cites its isolated adversarial round trip and adjacent-version matrix"
        );
        wprism_check(
            !in_array('promote', array_column($entry['unsupported'] ?? [], 'operation'), true),
            "$name has no stale production promotion blocker"
        );
    } else {
        wprism_check_same(
            ['bundle_schema' => 'wprism-subject-certification-bundle/v1', 'tests' => ['conformance-ecosystem-adapter-batch']],
            $entry['evidence'] ?? null,
            "$name cites the exact-artifact live capture-plan suite"
        );
        wprism_check(
            in_array('promote', array_column($entry['unsupported'] ?? [], 'operation'), true),
            "$name keeps its explicit promotion blocker"
        );
    }
}
wprism_check_same(
    'certified-boundary',
    $artifactLock['plugins']['code-snippets']['3.9.6']['role'] ?? null,
    'Code Snippets 3.9.6 is independently locked as the upper certified patch boundary'
);
wprism_check_same(
    'ab5822db426858b43d7c010481a87d0eaffb056cc483e93e057d96f6e6fdd4f4',
    $artifactLock['plugins']['code-snippets']['3.9.6']['sha256'] ?? null,
    'Code Snippets 3.9.6 upper-boundary evidence is digest-pinned'
);

foreach (['wpforms', 'custom-post-type-ui'] as $rejected) {
    wprism_check($sourceLibrary->package($rejected) === null, "$rejected remains rejected instead of gaining an unsafe package");
}
wprism_check(
    $sourceLibrary->package('redirection') !== null
        && is_file($root . '/adapter-packages/redirection/tests/offline/regress_redirection_adapter.php'),
    'Redirection left the rejected-candidate set only with its own exact adapter and regression evidence'
);

wprism_check_same('ecosystem-adapter-batch', $conformanceEntry['manifest'] ?? null, 'the shared live suite has a convention-discoverable fixture entry');
wprism_check_same('capture-plan', $conformanceEntry['entry']['mode'] ?? null, 'the shared live suite stops before every explicitly unsupported apply path');
wprism_check_same(
    ['core', ...$names],
    $conformanceEntry['entry']['pin'] ?? null,
    'the live suite pins core plus exactly the five new adapters'
);
wprism_check(is_file($root . '/sandbox/conformance/seeds/ecosystem-adapter-batch.sh'), 'the live suite authors representative state through plugin APIs');
wprism_check(is_file($root . '/sandbox/conformance/capture-checks/ecosystem-adapter-batch.sh'), 'the live suite checks plugin consumption and canonical reference bytes');
foreach ($artifacts as $name => $artifact) {
    $slug = explode('/', $artifact['plugin'], 2)[0];
    $version = $artifact['range']['min'];
    $locked = $artifactLock['plugins'][$slug][$version] ?? null;
    wprism_check_same($artifact['sha256'], $locked['sha256'] ?? null, "$slug $version live evidence is locked to the researched artifact digest");
    $expectedRole = in_array($name, $certified, true) ? 'certified-boundary' : 'exercise-fixture';
    wprism_check_same($expectedRole, $locked['role'] ?? null, "$slug $version carries the reviewed evidence role");
}

foreach ($standaloneEntries as $name => $fixture) {
    wprism_check_same($name, $fixture['manifest'] ?? null, "$name has an independently runnable live profile");
    wprism_check(!isset($fixture['entry']['mode']), "$name standalone evidence uses the complete roundtrip path");
    wprism_check_same(['core', $name], $fixture['entry']['pin'] ?? null, "$name live profile isolates core plus one adapter");
    foreach (['seeds' => 'seed.sh', 'postdeploy' => 'postdeploy.sh', 'checks' => 'check.sh'] as $phase => $file) {
        wprism_check(
            is_file($root . "/adapter-packages/$name/tests/conformance/$file"),
            "$name live profile has a separately diagnosable $phase hook"
        );
    }
}

$refusalArtifacts = [
    'tinymce-advanced' => [
        'version' => '5.9.0',
        'sha256' => '6940ab196194ad7f1c99a8242ed390f77ddd526f49c205d128e42c3802e0d7d8',
    ],
    'classic-editor' => [
        'version' => '1.6.7',
        'sha256' => '4b2b45b19c61f627ff8730222692a691023dea3435b35b8db95a2418b45ece65',
    ],
    'code-snippets' => [
        'version' => '3.9.4',
        'sha256' => '2dad76bec092682a441823a6636abfa8723a67dc3c9ecc7f5209140a796cfa4a',
    ],
    'wps-hide-login' => [
        'version' => '1.9.18',
        'sha256' => 'c150d7d5892e96d272f7768913ecd79d645c074aabe9092c0ef892224c392a15',
    ],
    'duplicate-post' => [
        'version' => '4.6',
        'sha256' => 'd7a954adc571fd200e68c13d7f1a19d2b65b83cbdf1cc38c39c011d296e46123',
    ],
];
foreach ($refusalArtifacts as $slug => $artifact) {
    $locked = $artifactLock['plugins'][$slug][$artifact['version']] ?? null;
    wprism_check_same($artifact['sha256'], $locked['sha256'] ?? null, "$slug adjacent official refusal artifact is digest-pinned");
    wprism_check_same('refusal-fixture', $locked['role'] ?? null, "$slug adjacent official release can never be credited as admitted evidence");
}

$expectedOptions = [
    'advanced-editor-tools' => [
        'tadv_admin_settings' => 'authored',
        'tadv_allbtns' => 'runtime',
        'tadv_btns1' => 'runtime',
        'tadv_btns2' => 'runtime',
        'tadv_btns3' => 'runtime',
        'tadv_btns4' => 'runtime',
        'tadv_options' => 'runtime',
        'tadv_plugins' => 'runtime',
        'tadv_settings' => 'authored',
        'tadv_toolbars' => 'runtime',
        'tadv_version' => 'runtime',
    ],
    'classic-editor' => [
        'classic-editor-allow-users' => 'authored',
        'classic-editor-replace' => 'authored',
    ],
    'code-snippets' => [
        'code_snippets_settings' => 'env',
        'code_snippets_version' => 'runtime',
    ],
    'wps-hide-login' => [
        'whl_page' => 'authored',
        'whl_redirect' => 'runtime',
        'whl_redirect_admin' => 'authored',
    ],
];

foreach ($expectedOptions as $manifestName => $inventory) {
    wprism_check_same(array_keys($inventory), array_keys($manifests[$manifestName]['options'] ?? []), "$manifestName option inventory is exact and closed");
    foreach ($inventory as $option => $class) {
        $details = $policy->option_rule_details($option);
        wprism_check_same($manifestName, $details['source'] ?? null, "options.$option is owned by $manifestName");
        wprism_check_same($class, $details['rule']['class'] ?? null, "options.$option resolves as $class through the product policy");
    }
}

$duplicateOptions = [
    'duplicate_post_blacklist',
    'duplicate_post_copyattachments',
    'duplicate_post_copyauthor',
    'duplicate_post_copychildren',
    'duplicate_post_copycomments',
    'duplicate_post_copycontent',
    'duplicate_post_copydate',
    'duplicate_post_copyexcerpt',
    'duplicate_post_copyformat',
    'duplicate_post_copymenuorder',
    'duplicate_post_copypassword',
    'duplicate_post_copyslug',
    'duplicate_post_copystatus',
    'duplicate_post_copytemplate',
    'duplicate_post_copythumbnail',
    'duplicate_post_copytitle',
    'duplicate_post_increase_menu_order_by',
    'duplicate_post_roles',
    'duplicate_post_show_link',
    'duplicate_post_show_link_in',
    'duplicate_post_show_notice',
    'duplicate_post_show_original_column',
    'duplicate_post_show_original_in_post_states',
    'duplicate_post_show_original_meta_box',
    'duplicate_post_taxonomies_blacklist',
    'duplicate_post_title_prefix',
    'duplicate_post_title_suffix',
    'duplicate_post_types_enabled',
];
$duplicateInventory = array_keys($manifests['yoast-duplicate-post']['options'] ?? []);
wprism_check_same([...$duplicateOptions, 'duplicate_post_version'], $duplicateInventory, 'Yoast Duplicate Post declares the closed 28-setting registry plus its runtime version gate');
foreach ($duplicateOptions as $option) {
    wprism_check_same('authored', $policy->option_rule($option)['class'] ?? null, "options.$option is portable authored policy");
}
wprism_check_same('runtime', $policy->option_rule('duplicate_post_version')['class'] ?? null, 'options.duplicate_post_version remains an environment-local upgrade gate');
$duplicateRoleActions = array_values(array_filter(
    $policy->actions_for(['option:duplicate_post_roles']),
    static fn(array $action): bool => ($action['provider'] ?? null) === 'yoast-duplicate-post-role-capabilities'
));
wprism_check_same(1, count($duplicateRoleActions), 'Yoast Duplicate Post role policy schedules exactly one bounded provider');
wprism_check_same(
    'reconcile_role_capabilities',
    $duplicateRoleActions[0]['capability'] ?? null,
    'Yoast Duplicate Post invokes the exact role-capability projection capability'
);
wprism_check_same(
    [['id' => 'yoast-duplicate-post-role-capability-map', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'options']]],
    $duplicateRoleActions[0]['effects'] ?? null,
    'Yoast Duplicate Post checkpoints the prefix-dependent merged role map before mutation'
);
wprism_check(
    is_file($root . '/adapter-packages/yoast-duplicate-post/package/runtime/providers/yoast-duplicate-post-role-capabilities.php'),
    'the shipped Yoast Duplicate Post role provider source exists beside its manifest identity'
);

foreach ([
    'tadv_future_setting',
    'classic-editor-debug',
    'code_snippets_license',
    'whl_secret',
    'duplicate_post_api_key',
] as $neighbor) {
    wprism_check_same(null, $policy->option_rule($neighbor), "undeclared neighbor options.$neighbor remains loud pending work");
}

$snippetTable = $policy->table_rule('snippets');
wprism_check_same('authored_snapshot', $snippetTable['class'] ?? null, 'Code Snippets rows use the implemented authored snapshot table class');
wprism_check_same('code_snippet', $snippetTable['id_kind'] ?? null, 'Code Snippets owns one explicit mapped identity keyspace');
wprism_check_same('id', $snippetTable['pk'] ?? null, 'Code Snippets declares the real snippets primary key');
wprism_check_same([], $snippetTable['refs'] ?? null, 'Code Snippets declares that its observed single-site row has no foreign-id columns');
wprism_check_same(
    [
        'active' => 'authored',
        'cloud_id' => 'env',
        'code' => 'authored',
        'condition_id' => 'runtime',
        'description' => 'authored',
        'modified' => 'runtime',
        'name' => 'authored',
        'priority' => 'authored',
        'revision' => 'runtime',
        'scope' => 'authored',
        'tags' => 'authored',
    ],
    array_map(static fn(array $rule): mixed => $rule['class'] ?? null, $snippetTable['columns'] ?? []),
    'Code Snippets classifies every observed non-primary column without a default bucket'
);
$snippetActions = array_values(array_filter(
    $policy->actions_for(['table:snippets']),
    static fn(array $action): bool => ($action['provider'] ?? null) === 'code-snippets-state'
));
wprism_check_same(1, count($snippetActions), 'Code Snippets table mutation schedules exactly one state-rebuild provider');
wprism_check_same('rebuild_snippet_state', $snippetActions[0]['capability'] ?? null, 'Code Snippets invokes the bounded cache and flat-file rebuild capability');
wprism_check_same(
    ['cache', 'filesystem'],
    array_column($snippetActions[0]['effects'] ?? [], 'kind'),
    'Code Snippets declares both irreversible external projections before mutation'
);
wprism_check(is_file($root . '/adapter-packages/code-snippets/package/runtime/providers/code-snippets-state.php'), 'the shipped provider source exists inside its package identity');

$shortcodes = $policy->shortcode_attr_rules();
$snippetRefRules = [
    ['kind' => 'code_snippet', 'path' => 'id'],
    ['kind' => 'code_snippet', 'path' => 'snippet_id'],
];
wprism_check_same($snippetRefRules, $shortcodes['code_snippet'] ?? null, 'code_snippet rewrites both registered id aliases');
wprism_check_same($snippetRefRules, $shortcodes['code_snippet_source'] ?? null, 'code_snippet_source rewrites both registered id aliases');
wprism_check(!isset($shortcodes['code-snippets/source']), 'an unobserved Code Snippets block schema is not guessed');

$missingAliasRules = $shortcodes;
$missingAliasRules['code_snippet'] = array_values(array_filter(
    $missingAliasRules['code_snippet'],
    static fn(array $rule): bool => ($rule['path'] ?? null) !== 'snippet_id'
));
$missingAliasFindings = ShortcodeReferenceScanner::scan(
    '[code_snippet snippet_id="67"]',
    $missingAliasRules,
    'posts/page/hostile-missing-alias.md',
    '',
    static fn(int $id): ?array => $id === 67 ? ['id' => 67, 'kind' => 'code_snippet'] : null
);
wprism_check_same(
    ['unregistered_shortcode_attr'],
    array_column($missingAliasFindings, 'class'),
    'removing one valid shortcode alias is caught by the production lint scanner before the local id can be trusted'
);
wprism_check_same(
    'shortcode.code_snippet.attrs.snippet_id',
    $missingAliasFindings[0]['locator'] ?? null,
    'the removed-reference refusal identifies the exact missing Code Snippets attribute'
);

wprism_check_same(['class' => 'authored', 'ref' => 'post'], $policy->post_meta_rule('_dp_original'), '_dp_original is a typed durable post reference');
foreach (['_dp_creation_date_gmt', '_dp_has_been_republished', '_dp_has_rewrite_republish_copy', '_dp_is_rewrite_republish_copy'] as $workflowKey) {
    wprism_check_same('runtime', $policy->post_meta_rule($workflowKey)['class'] ?? null, "post_meta.$workflowKey remains in-progress workflow state");
}

foreach ($names as $name) {
    $operations = $policy->manifest_disposition($name)['capabilities']['operations'] ?? [];
    if (in_array($name, $certified, true)) {
        wprism_check(in_array('apply', $operations, true), "$name claims the isolated apply path its target profile proves");
    } else {
        wprism_check(!in_array('apply', $operations, true), "$name does not claim hook-free apply while its postcondition is open");
    }
}

$blockerNames = array_values(array_unique(array_column($policy->certification_readiness_blockers(), 'name')));
sort($blockerNames, SORT_STRING);
$sortedNames = array_values(array_diff($names, $certified));
sort($sortedNames, SORT_STRING);
wprism_check_same($sortedNames, $blockerNames, 'the independently certified adapters add no promotion blocker to the capability registry');

$limitations = (string) file_get_contents($root . '/docs/guides/adapter-authoring-limitations.md');
foreach ([
    'WPForms Lite 2.0.0.4 / 2.0.0.5',
    'Redirection 5.9.0',
    'Custom Post Type UI 1.19.3',
    'PHP serialization length prefixes',
    'type-preserving',
    'string-id attribute codec',
    'verified post-apply type-registration/process boundary',
] as $requiredBoundary) {
    wprism_check(str_contains($limitations, $requiredBoundary), "the limitation ledger records: $requiredBoundary");
}
$guide = (string) file_get_contents($root . '/docs/guides/adapter-authoring.md');
wprism_check(str_contains($guide, 'Adversarial preflight'), 'the primary authoring loop now requires adversarial preflight');
wprism_check(str_contains($guide, 'custom-table ids deliberately differ'), 'the authoring preflight requires divergent source/target identity probes');
wprism_check(str_contains($guide, 'PHP-serialized'), 'the authoring preflight requires serialized-container inspection');
$walk = (string) file_get_contents($root . '/docs/grind/adapter-walk.md');
wprism_check(str_contains($walk, 'does not prove cross-environment form fidelity'), 'the historical WPForms walk no longer reads as a product capability claim');

/**
 * Exercise Policy::load() against one isolated manifest set. No dispositions
 * file is written, so mutations test grammar only and cannot borrow a review
 * claim from the shipped library.
 *
 * @param array<string,array<string,mixed>> $files
 */
$loadMutation = static function (array $files): Policy {
    $dir = sys_get_temp_dir() . '/wprism_ecosystem_adapter_batch_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create adapter mutation directory $dir");
    }
    foreach ($files as $name => $manifest) {
        Canon::write_file("$dir/$name.json", Canon::encode($manifest));
    }
    register_shutdown_function(static function () use ($dir): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    });
    return Policy::load(
        null,
        array_keys($files),
        adapterLibrary: \WPrismTest\FrozenPolicy::adapterLibrary($dir)
    );
};

$badRefs = $manifests['code-snippets'];
$badRefs['tables']['snippets']['refs'] = 'none';
wprism_check_throws(
    static fn(): Policy => $loadMutation(['code-snippets' => $badRefs]),
    RuntimeException::class,
    'the real loader refuses a table whose empty ref inventory is weakened to an untyped scalar',
    'refs must be a LIST'
);

$badKind = $manifests['code-snippets'];
$badKind['shortcode_attrs']['code_snippet'][0]['kind'] = 'code_snipet';
wprism_check_throws(
    static fn(): Policy => $loadMutation(['code-snippets' => $badKind]),
    RuntimeException::class,
    'the real loader refuses a shortcode reference typo instead of dropping the local id later',
    'token kind vocabulary is closed'
);

$badType = $manifests['code-snippets'];
$badType['shortcode_attrs']['code_snippet'][0]['type'] = 'string';
wprism_check_throws(
    static fn(): Policy => $loadMutation(['code-snippets' => $badType]),
    RuntimeException::class,
    'the real loader refuses a type-changing string-id claim unsupported by the current codec',
    'attribute-value vocabulary is closed'
);

$conflict = [
    'spec_version' => WPRISM_SPEC_VERSION,
    'name' => 'conflicting-owner',
    'option_autoload' => 'preserve',
    'plugin' => 'conflicting-owner/conflicting-owner.php',
    'version_range' => ['min' => '1.0.0', 'max' => '1.0.1'],
    'options' => ['code_snippets_settings' => ['class' => 'authored']],
];
wprism_check_throws(
    static fn(): Policy => $loadMutation([
        'code-snippets' => $manifests['code-snippets'],
        'conflicting-owner' => $conflict,
    ]),
    RuntimeException::class,
    'the real loader refuses contradictory ownership regardless of pin order',
    'declare contradictory rules for options.code_snippets_settings'
);

wprism_check_summary('ecosystem adapter batch');
