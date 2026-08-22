<?php
declare(strict_types=1);

/**
 * Offline product-path contract for the five exact-artifact adapter drafts
 * added by the 2026-08-22 ecosystem probe. The assertions load the shipped
 * manifests and disposition registry through Policy::load(); fixtures would
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
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Review/ShortcodeReferenceScanner.php';

use Duo\Canon;
use Duo\Policy;
use Duo\ShortcodeReferenceScanner;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

$names = [
    'advanced-editor-tools',
    'classic-editor',
    'code-snippets',
    'wps-hide-login',
    'yoast-duplicate-post',
];

/** @var array<string,array<string,mixed>> $manifests */
$manifests = [];
foreach ($names as $name) {
    $manifests[$name] = Canon::decode(Canon::read_file($root . "/manifests/$name.json"));
}
$dispositions = Canon::decode(Canon::read_file($root . '/manifests/dispositions.json'));
$conformanceEntry = Canon::decode(Canon::read_file($root . '/sandbox/conformance/entries/ecosystem-adapter-batch.json'));
$standaloneEntries = [
    'advanced-editor-tools' => Canon::decode(Canon::read_file($root . '/sandbox/conformance/entries/advanced-editor-tools.json')),
    'classic-editor' => Canon::decode(Canon::read_file($root . '/sandbox/conformance/entries/classic-editor.json')),
];
$artifactLock = Canon::decode(Canon::read_file($root . '/sandbox/conformance/artifacts.lock.json'));

$priorManifestDir = getenv('DUO_MANIFESTS_DIR');
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');
$policy = Policy::load(null, $names);

duo_check_same($names, array_column($policy->manifests, 'name'), 'the real policy loader accepts exactly the five shipped adapter manifests');

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
        'range' => ['min' => '3.9.6', 'max' => '3.9.7'],
        'sha256' => 'ab5822db426858b43d7c010481a87d0eaffb056cc483e93e057d96f6e6fdd4f4',
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
foreach ($artifacts as $name => $artifact) {
    $manifest = $manifests[$name];
    duo_check_same($artifact['plugin'], $manifest['plugin'] ?? null, "$name pins the observed plugin basename");
    duo_check_same($artifact['range'], $manifest['version_range'] ?? null, "$name admits only the observed patch boundary");
    duo_check(
        str_contains(implode("\n", $manifest['notes'] ?? []), $artifact['sha256']),
        "$name records the exact official artifact SHA-256 in shipped identity"
    );
    duo_check_same(
        $artifact['range'] + ['manifest' => $name],
        $effectiveRanges[$artifact['plugin']] ?? null,
        "$name exposes the same bounded range through Policy::version_ranges()"
    );

    $entry = $policy->manifest_disposition($name);
    duo_check_same('experimental', $entry['status'] ?? null, "$name is explicitly experimental, never implicitly certified");
    duo_check_json_equal(
        ['plugin' => $artifact['plugin'], 'range' => $artifact['range']],
        $entry['supported_versions'] ?? null,
        "$name disposition repeats the exact artifact boundary"
    );
    $unsupportedOperations = array_column($entry['unsupported'] ?? [], 'operation');
    duo_check(in_array('promote', $unsupportedOperations, true), "$name carries an explicit promotion blocker");
    duo_check_same(
        ['bundle_schema' => 'duo-subject-certification-bundle/v1', 'tests' => ['conformance-ecosystem-adapter-batch']],
        $entry['evidence'] ?? null,
        "$name cites the exact-artifact live capture-plan suite"
    );
}

foreach (['wpforms', 'redirection', 'custom-post-type-ui'] as $rejected) {
    duo_check(!is_file($root . "/manifests/$rejected.json"), "$rejected remains rejected instead of gaining an unsafe manifest");
}

duo_check_same('ecosystem-adapter-batch', $conformanceEntry['manifest'] ?? null, 'the shared live suite has a convention-discoverable fixture entry');
duo_check_same('capture-plan', $conformanceEntry['entry']['mode'] ?? null, 'the shared live suite stops before every explicitly unsupported apply path');
duo_check_same(
    ['core', ...$names],
    $conformanceEntry['entry']['pin'] ?? null,
    'the live suite pins core plus exactly the five new adapters'
);
duo_check(is_file($root . '/sandbox/conformance/seeds/ecosystem-adapter-batch.sh'), 'the live suite authors representative state through plugin APIs');
duo_check(is_file($root . '/sandbox/conformance/capture-checks/ecosystem-adapter-batch.sh'), 'the live suite checks plugin consumption and canonical reference bytes');
foreach ($artifacts as $artifact) {
    $slug = explode('/', $artifact['plugin'], 2)[0];
    $version = $artifact['range']['min'];
    $locked = $artifactLock['plugins'][$slug][$version] ?? null;
    duo_check_same($artifact['sha256'], $locked['sha256'] ?? null, "$slug $version live evidence is locked to the researched artifact digest");
    duo_check_same('exercise-fixture', $locked['role'] ?? null, "$slug $version is labeled evidence, not a certified boundary");
}

foreach ($standaloneEntries as $name => $fixture) {
    duo_check_same($name, $fixture['manifest'] ?? null, "$name has an independently runnable live profile");
    duo_check(!isset($fixture['entry']['mode']), "$name standalone evidence uses the complete roundtrip path");
    duo_check_same(['core', $name], $fixture['entry']['pin'] ?? null, "$name live profile isolates core plus one adapter");
    foreach (['seeds', 'postdeploy', 'checks'] as $phase) {
        duo_check(
            is_file($root . "/sandbox/conformance/$phase/$name.sh"),
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
];
foreach ($refusalArtifacts as $slug => $artifact) {
    $locked = $artifactLock['plugins'][$slug][$artifact['version']] ?? null;
    duo_check_same($artifact['sha256'], $locked['sha256'] ?? null, "$slug adjacent official refusal artifact is digest-pinned");
    duo_check_same('refusal-fixture', $locked['role'] ?? null, "$slug adjacent official release can never be credited as admitted evidence");
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
    duo_check_same(array_keys($inventory), array_keys($manifests[$manifestName]['options'] ?? []), "$manifestName option inventory is exact and closed");
    foreach ($inventory as $option => $class) {
        $details = $policy->option_rule_details($option);
        duo_check_same($manifestName, $details['source'] ?? null, "options.$option is owned by $manifestName");
        duo_check_same($class, $details['rule']['class'] ?? null, "options.$option resolves as $class through the product policy");
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
duo_check_same([...$duplicateOptions, 'duplicate_post_version'], $duplicateInventory, 'Yoast Duplicate Post declares the closed 28-setting registry plus its runtime version gate');
foreach ($duplicateOptions as $option) {
    duo_check_same('authored', $policy->option_rule($option)['class'] ?? null, "options.$option is portable authored policy");
}
duo_check_same('runtime', $policy->option_rule('duplicate_post_version')['class'] ?? null, 'options.duplicate_post_version remains an environment-local upgrade gate');

foreach ([
    'tadv_future_setting',
    'classic-editor-debug',
    'code_snippets_license',
    'whl_secret',
    'duplicate_post_api_key',
] as $neighbor) {
    duo_check_same(null, $policy->option_rule($neighbor), "undeclared neighbor options.$neighbor remains loud pending work");
}

$snippetTable = $policy->table_rule('snippets');
duo_check_same('authored_snapshot', $snippetTable['class'] ?? null, 'Code Snippets rows use the implemented authored snapshot table class');
duo_check_same('code_snippet', $snippetTable['id_kind'] ?? null, 'Code Snippets owns one explicit mapped identity keyspace');
duo_check_same('id', $snippetTable['pk'] ?? null, 'Code Snippets declares the real snippets primary key');
duo_check_same([], $snippetTable['refs'] ?? null, 'Code Snippets declares that its observed single-site row has no foreign-id columns');
duo_check_same(
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

$shortcodes = $policy->shortcode_attr_rules();
$snippetRefRules = [
    ['kind' => 'code_snippet', 'path' => 'id'],
    ['kind' => 'code_snippet', 'path' => 'snippet_id'],
];
duo_check_same($snippetRefRules, $shortcodes['code_snippet'] ?? null, 'code_snippet rewrites both registered id aliases');
duo_check_same($snippetRefRules, $shortcodes['code_snippet_source'] ?? null, 'code_snippet_source rewrites both registered id aliases');
duo_check(!isset($shortcodes['code-snippets/source']), 'an unobserved Code Snippets block schema is not guessed');

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
duo_check_same(
    ['unregistered_shortcode_attr'],
    array_column($missingAliasFindings, 'class'),
    'removing one valid shortcode alias is caught by the production lint scanner before the local id can be trusted'
);
duo_check_same(
    'shortcode.code_snippet.attrs.snippet_id',
    $missingAliasFindings[0]['locator'] ?? null,
    'the removed-reference refusal identifies the exact missing Code Snippets attribute'
);

duo_check_same(['class' => 'authored', 'ref' => 'post'], $policy->post_meta_rule('_dp_original'), '_dp_original is a typed durable post reference');
foreach (['_dp_creation_date_gmt', '_dp_has_been_republished', '_dp_has_rewrite_republish_copy', '_dp_is_rewrite_republish_copy'] as $workflowKey) {
    duo_check_same('runtime', $policy->post_meta_rule($workflowKey)['class'] ?? null, "post_meta.$workflowKey remains in-progress workflow state");
}

foreach ($names as $name) {
    $operations = $policy->manifest_disposition($name)['capabilities']['operations'] ?? [];
    duo_check(!in_array('apply', $operations, true), "$name does not claim hook-free apply while its postcondition is open");
}

$blockerNames = array_values(array_unique(array_column($policy->certification_readiness_blockers(), 'name')));
sort($blockerNames, SORT_STRING);
$sortedNames = $names;
sort($sortedNames, SORT_STRING);
duo_check_same($sortedNames, $blockerNames, 'every new experimental adapter blocks promotion through the capability registry');

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
    duo_check(str_contains($limitations, $requiredBoundary), "the limitation ledger records: $requiredBoundary");
}
$guide = (string) file_get_contents($root . '/docs/guides/adapter-authoring.md');
duo_check(str_contains($guide, 'Adversarial preflight'), 'the primary authoring loop now requires adversarial preflight');
duo_check(str_contains($guide, 'custom-table ids deliberately differ'), 'the authoring preflight requires divergent source/target identity probes');
duo_check(str_contains($guide, 'PHP-serialized'), 'the authoring preflight requires serialized-container inspection');
$walk = (string) file_get_contents($root . '/docs/grind/adapter-walk.md');
duo_check(str_contains($walk, 'does not prove cross-environment form fidelity'), 'the historical WPForms walk no longer reads as a product capability claim');

/**
 * Exercise Policy::load() against one isolated manifest set. No dispositions
 * file is written, so mutations test grammar only and cannot borrow a review
 * claim from the shipped library.
 *
 * @param array<string,array<string,mixed>> $files
 */
$loadMutation = static function (array $files): Policy {
    $dir = sys_get_temp_dir() . '/duo_ecosystem_adapter_batch_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create adapter mutation directory $dir");
    }
    foreach ($files as $name => $manifest) {
        Canon::write_file("$dir/$name.json", Canon::encode($manifest));
    }
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    });
    putenv('DUO_MANIFESTS_DIR=' . $dir);
    return Policy::load(null, array_keys($files));
};

$badRefs = $manifests['code-snippets'];
$badRefs['tables']['snippets']['refs'] = 'none';
duo_check_throws(
    static fn(): Policy => $loadMutation(['code-snippets' => $badRefs]),
    RuntimeException::class,
    'the real loader refuses a table whose empty ref inventory is weakened to an untyped scalar',
    'refs must be a LIST'
);

$badKind = $manifests['code-snippets'];
$badKind['shortcode_attrs']['code_snippet'][0]['kind'] = 'code_snipet';
duo_check_throws(
    static fn(): Policy => $loadMutation(['code-snippets' => $badKind]),
    RuntimeException::class,
    'the real loader refuses a shortcode reference typo instead of dropping the local id later',
    'token kind vocabulary is closed'
);

$badType = $manifests['code-snippets'];
$badType['shortcode_attrs']['code_snippet'][0]['type'] = 'string';
duo_check_throws(
    static fn(): Policy => $loadMutation(['code-snippets' => $badType]),
    RuntimeException::class,
    'the real loader refuses a type-changing string-id claim unsupported by the current codec',
    'attribute-value vocabulary is closed'
);

$conflict = [
    'spec_version' => DUO_SPEC_VERSION,
    'name' => 'conflicting-owner',
    'option_autoload' => 'preserve',
    'plugin' => 'conflicting-owner/conflicting-owner.php',
    'version_range' => ['min' => '1.0.0', 'max' => '1.0.1'],
    'options' => ['code_snippets_settings' => ['class' => 'authored']],
];
duo_check_throws(
    static fn(): Policy => $loadMutation([
        'code-snippets' => $manifests['code-snippets'],
        'conflicting-owner' => $conflict,
    ]),
    RuntimeException::class,
    'the real loader refuses contradictory ownership regardless of pin order',
    'declare contradictory rules for options.code_snippets_settings'
);

if ($priorManifestDir === false) {
    putenv('DUO_MANIFESTS_DIR');
} else {
    putenv('DUO_MANIFESTS_DIR=' . $priorManifestDir);
}

duo_check_summary('ecosystem adapter batch');
