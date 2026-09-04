<?php
/**
 * Rank Math's shipped policy contract, retained from the original site-level
 * exercise and promoted only after its lifecycle and derived-state gaps closed.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/cli/src/Adapter/AdapterBoundary.php';

use WPrism\Canon;
use WPrism\Orchestrator\AdapterBoundary;
use WPrism\Policy;

$fixtureDir = $root . '/adapter-packages/rank-math/fixtures';
$adapterPath = $root . '/adapter-packages/rank-math/package/manifest.json';
$releasePath = $fixtureDir . '/historical-release-boundary.json';
$outcomePath = $fixtureDir . '/historical-site-outcome.json';
$seedPath = $root . '/adapter-packages/rank-math/tests/conformance/seed.sh';
$postdeployPath = $root . '/adapter-packages/rank-math/tests/conformance/postdeploy.sh';
$checkPath = $root . '/adapter-packages/rank-math/tests/conformance/check.sh';
$versionMatrixPath = $root . '/adapter-packages/rank-math/tests/certify/version-matrix.sh';
$privateRefusalHelperPath = $fixtureDir . '/private-refusal-evidence.php';
$adapterBytes = (string) file_get_contents($adapterPath);
$adapter = Canon::decode($adapterBytes);

require_once $privateRefusalHelperPath;

wprism_check_same($adapterBytes, Canon::encode($adapter),
    'A1: the shipped Rank Math manifest is canonical before its digest binds the exact bytes');
wprism_check_same(
    ['rank-math', 3, 'seo-by-rank-math/rank-math.php'],
    [$adapter['name'] ?? null, $adapter['spec_version'] ?? null, $adapter['plugin'] ?? null],
    'A1: the package names Rank Math, spec v3 and the exact WordPress plugin basename'
);
wprism_check_same(
    ['max' => '1.0.277.3', 'min' => '1.0.277'],
    $adapter['version_range'] ?? null,
    'A2: the compatibility window admits only the exact reviewed patch family'
);
wprism_check_same(
    [
        'manifest-provider-runtime/v1',
        'plugin-incompatibility/v1',
        'schema-settlement/v1',
        'spec-window/v1',
        'structured-evidence/v1',
    ],
    $adapter['engine_features'] ?? null,
    'A2: every v3 section is admitted through an explicit engine feature'
);
wprism_check(isset($adapter['declaration_evidence']['tables.rank_math_redirections']),
    'A3: the natural-key table decision carries machine-shaped declaration evidence');
wprism_check(count((array) ($adapter['notes'] ?? [])) >= 10,
    'A3: the package retains the live boundary, refusals and authoring friction in its own bytes');
wprism_check_same(
    ['inspect_schema', 'prepare_schema', 'rebuild_all_link_state'],
    $adapter['providers'][0]['capabilities'] ?? null,
    'A4: one digest-bound provider owns schema settlement and native link-state convergence'
);
wprism_check_same(
    ['schema_settle', 'lifecycle_settle', null],
    array_map(static fn(array $action): ?string => $action['phase'] ?? null, $adapter['actions'] ?? []),
    'A4: schema preparation has a pre-observation phase while value-triggered repairs remain post-apply actions'
);
wprism_check_same(
    ['rank_math_internal_links', 'rank_math_internal_meta', 'rank_math_redirections', 'rank_math_redirections_cache'],
    $adapter['actions'][0]['prepares'] ?? null,
    'A4: schema settlement names every exact table it may establish before capture'
);
wprism_check(!array_key_exists('triggers', $adapter['actions'][2] ?? []),
    'A4: every nonempty authored apply selects native repair because route filters span arbitrary state kinds');
wprism_check_same(
    [
        'operation_envelope' => 'wprism-scoped-effect-operation/v1',
        'receipt_projection' => 'handler',
        'reconcile' => true,
    ],
    $adapter['providers'][0]['contracts']['rebuild_all_link_state']['scoped'] ?? null,
    'A4: the globally selected provider is operation-bound and readback-reconcilable during scoped apply'
);
$rebuildContract = $adapter['providers'][0]['contracts']['rebuild_all_link_state'] ?? [];
wprism_check_same(
    [
        'option:category_base', 'option:close_comments_days_old', 'option:close_comments_for_old_posts',
        'option:comments_per_page', 'option:default_category', 'option:home',
        'option:page_for_posts', 'option:page_on_front', 'option:permalink_structure', 'option:polylang',
        'option:posts_per_page', 'option:posts_per_rss', 'option:rank-math-options-general',
        'option:rank_math_modules', 'option:rewrite_rules', 'option:show_on_front', 'option:siteurl',
        'option:sticky_posts', 'option:tag_base', 'option:woocommerce_permalinks',
        'option:wp_page_for_privacy_policy', 'table:comments', 'table:postmeta', 'table:posts',
        'table:rank_math_internal_links', 'table:rank_math_internal_meta', 'table:term_relationships',
        'table:term_taxonomy', 'table:terms', 'table:users', 'table:usermeta',
    ],
    $rebuildContract['reads'] ?? null,
    'A4: native permalink and URL resolution declares its complete direct read boundary'
);
wprism_check_same(
    [
        'RankMath\\Defaults', 'RankMath\\Helper', 'RankMath\\Installer',
        'RankMath\\Links\\ContentProcessor', 'RankMath\\Links\\Links',
        'WP_CLI', 'WP_Hook', 'WP_Post', 'WP_Rewrite',
    ],
    $adapter['providers'][0]['requires']['classes'] ?? null,
    'A4: the provider declares every runtime class it invokes or type-checks'
);
wprism_check_same(
    [
        'clean_post_cache', 'esc_sql', 'get_option', 'get_permalink', 'get_post', 'get_post_types',
        'has_filter', 'home_url', 'is_multisite', 'is_post_type_viewable', 'url_to_postid',
        'wp_json_encode',
    ],
    $adapter['providers'][0]['requires']['functions'] ?? null,
    'A4: the provider declares every runtime function used by parent and fresh child'
);

$site = sys_get_temp_dir() . '/wprism_rank_math_site_' . bin2hex(random_bytes(8));
if (!mkdir($site, 0700, true) && !is_dir($site)) {
    throw new RuntimeException("could not create scratch site repository $site");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/site.wprism.json');
    @rmdir($site);
});
Canon::write_file($site . '/site.wprism.json', Canon::encode([
    'manifests' => [['name' => 'rank-math', 'source' => 'shipped']],
    'policy' => new stdClass(),
    'spec_version' => WPRISM_SPEC_VERSION,
]));
$library = \WPrism\AdapterLibrary::fromSourceTree($root);
$policy = Policy::load(
    $site,
    ['rank-math'],
    adapterLibrary: $library
);

$range = $policy->version_ranges()['seo-by-rank-math/rank-math.php'] ?? null;
wprism_check(is_array($range)
    && ($range['min'] ?? null) === '1.0.277'
    && ($range['max'] ?? null) === '1.0.277.3'
    && ($range['manifest'] ?? null) === 'rank-math',
    'B1: the shipped package load path publishes the exact plugin version boundary');
wprism_check_same(
    ['class' => 'authored', 'plain_data' => true, 'autoload' => 'preserve'],
    $policy->option_rule('rank-math-options-general'),
    'B2: the general settings object is portable plain data with preserved autoload state'
);
$titles = $policy->option_rule('rank-math-options-titles');
wprism_check(($titles['class'] ?? null) === 'authored'
    && array_column((array) ($titles['json_refs'] ?? []), 'path')
        === [
            '$.homepage_facebook_image_id',
            '$.knowledgegraph_logo_id',
            '$.local_seo_about_page',
            '$.local_seo_contact_page',
            '$.open_graph_image_id',
        ],
    'B2: the titles object remaps all three measured attachment ids and both local-SEO page ids');
wprism_check_same('authored', $policy->option_rule('rank_math_registration_skip')['class'] ?? null,
    'B3: disconnected registration skip is authored executable setup state');
wprism_check_same('authored', $policy->option_rule('rank_math_modules')['class'] ?? null,
    'B3: the exercised module selection is portable rather than silently target-owned');
wprism_check_same('runtime', $policy->option_rule('rank_math_flush_rewrite')['class'] ?? null,
    'B3: the plugin one-shot rewrite marker never enters canonical state');
wprism_check_same('runtime', $policy->option_rule('rank_math_indexnow_log')['class'] ?? null,
    'B3: IndexNow response URLs, status codes and timestamps remain target runtime history');
wprism_check_same('runtime', $policy->option_rule('rank_math_notifications')['class'] ?? null,
    'B3: the native notification queue remains target-local runtime rather than portable state');
wprism_check_same('env', $policy->option_rule('rank-math-options-sitemap')['class'] ?? null,
    'B4: sitemap local-id lists keep the whole unsupported option target-owned');
wprism_check_same('env', $policy->option_rule('rank-math-options-instant-indexing')['class'] ?? null,
    'B4: the generated IndexNow credential keeps its option target-owned');
wprism_check_same(
    ['owner' => 'rank-math', 'match' => '^(?:rank-math-options-|rank_math_)'],
    $policy->option_namespace('rank_math_connect_data'),
    'B4: Rank Math owns discovery of a connected-account option without classifying its credentials'
);
wprism_check_same(null, $policy->owned_option_rule('rank_math_connect_data'),
    'B4: namespace ownership is not a blanket authored/runtime classification');
$seedHarness = (string) file_get_contents($seedPath);
$postdeployHarness = (string) file_get_contents($postdeployPath);
$checkHarness = (string) file_get_contents($checkPath);
$versionMatrixHarness = (string) file_get_contents($versionMatrixPath);
$moduleDesired = strpos($seedHarness, '$desired = ["link-counter", "redirections", "rich-snippet"];');
$moduleDisable = strpos($seedHarness, 'RankMath\\Helper::update_modules(array_fill_keys($stored, "off"));');
$moduleEnable = strpos($seedHarness, 'RankMath\\Helper::update_modules(array_fill_keys($desired, "on"));');
$moduleReadback = strpos($seedHarness, 'RANK_MATH_MODULE_READY=$(wp_conf1 eval');
$activeModuleReadback = strpos(
    $seedHarness,
    '$active = array_values(RankMath\\Helper::get_active_modules());'
);
$moduleObserved = strpos(
    $seedHarness,
    "require_observed_nonempty 'Rank Math native module readiness' \"\$RANK_MATH_MODULE_READY\""
);
$moduleOracle = strpos($seedHarness, <<<'SH'
jq -e --arg version "$RANK_MATH_EXPECTED_VERSION" '
  . == {
    active_modules:["link-counter","redirections","rich-snippet"],
    modules:["link-counter","redirections","rich-snippet"],
    tables:{rank_math_internal_links:true,rank_math_internal_meta:true,
      rank_math_redirections:true,rank_math_redirections_cache:true},
    version:$version
  }
' <<<"$RANK_MATH_MODULE_READY" >/dev/null \
  || fail "Rank Math native module readiness is incomplete: $RANK_MATH_MODULE_READY"
SH);
$seedAuthoring = strpos($seedHarness, 'cat > "$SOURCE_REPO/.tmp-rank-math-seed.php"');
wprism_check(
    $moduleDesired !== false
        && $moduleDisable !== false
        && $moduleEnable !== false
        && $moduleReadback !== false
        && $activeModuleReadback !== false
        && $moduleObserved !== false
        && $moduleOracle !== false
        && $seedAuthoring !== false
        && $moduleDesired < $moduleDisable
        && $moduleDisable < $moduleEnable
        && $moduleEnable < $moduleReadback
        && $moduleReadback < $activeModuleReadback
        && $activeModuleReadback < $moduleObserved
        && $moduleObserved < $moduleOracle
        && $moduleOracle < $seedAuthoring
        && !str_contains($seedHarness, 'update_option("rank_math_modules"')
        && !str_contains($seedHarness, '"schema" => "on"'),
    'B4: source setup uses native module lifecycle and a fresh exact registered-active/schema oracle before authoring'
);
$matrixModuleState = strpos($versionMatrixHarness, 'rank_math_module_state() {');
$matrixSeedVersion = strpos(
    $versionMatrixHarness,
    'local RANK_MATH_EXPECTED_VERSION="$RANK_MATH_VERSION"'
);
$matrixSeedSource = strpos(
    $versionMatrixHarness,
    '. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"'
);
$matrixActiveReadback = strpos(
    $versionMatrixHarness,
    '"active_modules" => array_values(RankMath\\Helper::get_active_modules())'
);
$matrixNativeEnable = strpos(
    $versionMatrixHarness,
    'RankMath\\Helper::update_modules(["image-seo" => "on"]);'
);
$matrixSourceReadback = strpos($versionMatrixHarness, 'UPGRADE_SOURCE_MODULES=$(rank_math_module_state wp1)');
$matrixSourceObserved = strpos(
    $versionMatrixHarness,
    "require_observed_nonempty 'Rank Math upgrade source native module readiness' \"\$UPGRADE_SOURCE_MODULES\""
);
$matrixSourceOracle = strpos(
    $versionMatrixHarness,
    'fail "Rank Math upgrade source modules are not registered and active: $UPGRADE_SOURCE_MODULES"'
);
$matrixSourceCapture = strpos($versionMatrixHarness, 'wp1 wprism capture --repo=/siterepo', $matrixSourceOracle ?: 0);
$matrixTargetApply = strpos(
    $versionMatrixHarness,
    "capture_wprism_json_success RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix upgrade apply'"
);
$matrixTargetReadback = strpos($versionMatrixHarness, 'UPGRADE_TARGET_MODULES=$(rank_math_module_state wp2)');
$matrixTargetObserved = strpos(
    $versionMatrixHarness,
    "require_observed_nonempty 'Rank Math upgrade target native module readiness' \"\$UPGRADE_TARGET_MODULES\""
);
$matrixTargetOracle = strpos(
    $versionMatrixHarness,
    'fail "Rank Math upgrade target modules are not registered and active: $UPGRADE_TARGET_MODULES"'
);
$matrixBoundaryCheck = strpos($versionMatrixHarness, 'RANK_MATH_VERSION=1.0.277.2', $matrixTargetOracle ?: 0);
$matrixExactSet = <<<'SH'
active_modules:["link-counter","redirections","rich-snippet","image-seo"],
        modules:["link-counter","redirections","rich-snippet","image-seo"],
        version:"1.0.277.2"
SH;
wprism_check(
    $matrixModuleState !== false
        && $matrixSeedVersion !== false
        && $matrixSeedSource !== false
        && $matrixActiveReadback !== false
        && $matrixNativeEnable !== false
        && $matrixSourceReadback !== false
        && $matrixSourceObserved !== false
        && $matrixSourceOracle !== false
        && $matrixSourceCapture !== false
        && $matrixTargetApply !== false
        && $matrixTargetReadback !== false
        && $matrixTargetObserved !== false
        && $matrixTargetOracle !== false
        && $matrixBoundaryCheck !== false
        && $matrixSeedVersion < $matrixSeedSource
        && $matrixSeedSource < $matrixModuleState
        && $matrixModuleState < $matrixActiveReadback
        && $matrixActiveReadback < $matrixNativeEnable
        && $matrixNativeEnable < $matrixSourceReadback
        && $matrixSourceReadback < $matrixSourceObserved
        && $matrixSourceObserved < $matrixSourceOracle
        && $matrixSourceOracle < $matrixSourceCapture
        && $matrixSourceCapture < $matrixTargetApply
        && $matrixTargetApply < $matrixTargetReadback
        && $matrixTargetReadback < $matrixTargetObserved
        && $matrixTargetObserved < $matrixTargetOracle
        && $matrixTargetOracle < $matrixBoundaryCheck
        && substr_count($versionMatrixHarness, $matrixExactSet) === 2
        && !str_contains($versionMatrixHarness, 'RankMath\\Helper::update_modules($modules);')
        && !str_contains($versionMatrixHarness, 'update_option("rank_math_modules"'),
    'B4: the upgrade boundary enables a real native module and proves its exact active set on source and target'
);
$matrixPrivateFunction = strpos($versionMatrixHarness, <<<'SH'
rank_math_private_evidence() { # <snapshot|verify> <profile> <directory> [baseline]
  "${PAIR_COMPOSE[@]}" run --rm -T --entrypoint php cli2 \
    /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
    "$@"
}
SH);
$matrixVirginRefusalSnippet = str_replace(
    '__RANK_MATH_PRIVATE_REFUSAL_RECEIPT__',
    rank_math_private_refusal_receipt('virgin-schema'),
    <<<'SH'
  PREDEPLOY_STATE=$(rank_math_native_state_hash wp2)
  require_observed_nonempty 'Rank Math virgin-target native baseline' "$PREDEPLOY_STATE"
  PREDEPLOY_PRIVATE_BASELINE=$(rank_math_private_evidence \
    snapshot virgin-schema /siterepo/.wprism/refusals) \
    || fail 'Rank Math virgin-target plan could not snapshot private evidence as the target CLI identity'
  require_observed_nonempty 'Rank Math virgin-target private refusal baseline' "$PREDEPLOY_PRIVATE_BASELINE"
  PREDEPLOY_RC=0
  PREDEPLOY_PLAN=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || PREDEPLOY_RC=$?
  require_wprism_answered 'Rank Math virgin-target strict plan' json "$PREDEPLOY_PLAN"
  PREDEPLOY_PLAN_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$PREDEPLOY_PLAN")
  [ "$PREDEPLOY_RC" -ne 0 ] \
    && jq -e '
      . == {
        format:"wprism-command-refusal/v1",ok:false,command:"plan",
        error:"plan_failed",reason_code:"plan_failed",
        message:"plan refused at an unclassified safety gate",
        remediation:"inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker",
        details_redacted:true,
        diagnostics:[{
          code:"plan_failed",message:"plan refused at an unclassified safety gate",
          remediation:"inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker"
        }]
      }
    ' <<<"$PREDEPLOY_PLAN_JSON" >/dev/null \
    && ! grep -Fq "declared table 'rank_math_" <<<"$PREDEPLOY_PLAN" \
    || fail "Rank Math virgin-target plan did not return its exact redacted refusal: $PREDEPLOY_PLAN"
  PREDEPLOY_PRIVATE_RECEIPT=$(rank_math_private_evidence \
    verify virgin-schema /siterepo/.wprism/refusals "$PREDEPLOY_PRIVATE_BASELINE") \
    || fail 'Rank Math virgin-target plan could not verify private evidence as the target CLI identity'
  require_observed_nonempty 'Rank Math virgin-target private refusal receipt' "$PREDEPLOY_PRIVATE_RECEIPT"
  [ "$PREDEPLOY_PRIVATE_RECEIPT" = \
    '__RANK_MATH_PRIVATE_REFUSAL_RECEIPT__' ] \
    || fail "Rank Math virgin-target private evidence is malformed: $PREDEPLOY_PRIVATE_RECEIPT"
  [ "$(rank_math_native_state_hash wp2)" = "$PREDEPLOY_STATE" ] \
    || fail 'Rank Math virgin-target strict-plan refusal mutated plugin state'
SH
);
$matrixVirginRefusal = strpos($versionMatrixHarness, $matrixVirginRefusalSnippet);
wprism_check(
    $matrixPrivateFunction !== false
        && $matrixVirginRefusal !== false
        && substr_count($versionMatrixHarness, $matrixVirginRefusalSnippet) === 1
        && $matrixPrivateFunction < $matrixVirginRefusal
        && hash('sha256', rank_math_private_refusal_profile('virgin-schema')['message'])
            === '4a8208927399b3863b0973d34410b2fd71bfc406d286d10dc14de0b24763ff76',
    'B4: each virgin boundary proves the exact private schema cause behind one fully redacted public plan refusal'
);
$skipSlug = '--skip-plugins=seo-by-rank-math';
$badSkipBasename = '--skip-plugins=seo-by-rank-math/rank-math.php';
$seedNotification = strrpos($seedHarness, $skipSlug);
$seedNativeWrite = strpos($seedHarness, '\\RankMath\\Helper::add_notification');
$seedFrontend = strpos($seedHarness, 'SOURCE_FRONT=');
$targetNotification = strrpos($postdeployHarness, $skipSlug);
$targetNativeWrite = strpos($postdeployHarness, '\\RankMath\\Helper::add_notification');
$targetSetup = strpos($postdeployHarness, 'TARGET_OUT=');
wprism_check(
    !str_contains($seedHarness . $postdeployHarness, $badSkipBasename)
        && is_int($seedNotification) && is_int($seedNativeWrite) && is_int($seedFrontend)
        && $seedNotification > $seedNativeWrite && $seedNativeWrite > $seedFrontend
        && is_int($targetNotification) && is_int($targetNativeWrite) && is_int($targetSetup)
        && $targetNotification > $targetNativeWrite && $targetNativeWrite > $targetSetup,
    'B4: native notification seeds are proved after shutdown by independent processes using the exact WP-CLI slug'
);
wprism_check(
    str_contains($postdeployHarness, 'reader_priority')
        && str_contains($postdeployHarness, 'writer_priority')
        && str_contains($postdeployHarness, 'center_count')
        && str_contains($checkHarness, 'Target runtime notification must survive')
        && str_contains($checkHarness, 'Source runtime notification must not transfer')
        && str_contains($checkHarness, '.before.link_count == 1 and .after.link_count == 2')
        && str_contains($checkHarness, '.before.meta_count == 1 and .after.meta_count == 2')
        && str_contains($checkHarness, '.before.marker_count == 1 and .after.marker_count == 2')
        && str_contains($checkHarness, '.before.link_hash != .after.link_hash')
        && str_contains($checkHarness, '.before.meta_hash != .after.meta_hash')
        && str_contains($checkHarness, '.before.marker_hash != .after.marker_hash')
        && str_contains($checkHarness, '.before.dependency_state_hash == .after.dependency_state_hash')
        && !str_contains($checkHarness, '.before.dependency_hash != .after.dependency_hash'),
    'B4: live repair proves exact derived replacement and stable dependencies without requiring incidental request topology drift'
);
wprism_check(
    str_contains($postdeployHarness, 'AUTO_INCREMENT = 9400001')
        && str_contains($postdeployHarness, '.redirection >= 9400001'),
    'B4: a fresh hostile target forces the plugin-table identity range to diverge without prior database history'
);
wprism_check(
    str_contains($checkHarness, '.potential_actions[]?')
        && str_contains($checkHarness, '\\WPrism\\Canon::encode($matches[0])')
        && str_contains($checkHarness, '$PAIR_SOURCE_ROOT/agent/src/Kernel/Canon.php')
        && !str_contains($checkHarness, '$WPRISM_SOURCE_ROOT/agent/src/Kernel/Canon.php')
        && str_contains($checkHarness, '.selected_actions == [{declaration_hash:$hash,index:$index,manifest:"rank-math"}]'),
    'B4: scoped evidence binds the full provider in its contract and the plan through its exact hash-only identity from the runner-exported source root'
);
wprism_check(
    str_contains($checkHarness, "grep -Fq 'identity contradiction:'")
        && str_contains($checkHarness, 'is already bound to local id $TARGET_REDIR; refusing to rebind it to $DUPLICATE_ID'),
    'B4: duplicate natural identity evidence pins the generic typed-ledger contradiction and both competing local ids'
);
wprism_check(
    str_contains($checkHarness, "capture_wprism_json_refusal SCHEMA_OUT 'Rank Math unsupported custom schema capture'")
        && str_contains($checkHarness, "capture_wprism_json_refusal DELETE_OUT 'Rank Math unsupported redirection deletion capture'")
        && !str_contains($checkHarness, 'DELETE_OUT=$(wp_conf1 wprism capture --repo=/siterepo --format=json 2>&1)'),
    'B4: expected JSON refusals publish one clean envelope before their downstream refusal assertions'
);
$schemaSourceDisable = strpos($checkHarness, <<<'SH'
wp_conf1 eval '
$modules=array_values((array)get_option("rank_math_modules",[]));
if (!in_array("redirections",$modules,true)) {
    throw new RuntimeException("Rank Math source fixture lacks its authored redirections module");
}
$modules=array_values(array_filter($modules,static fn($m)=>$m!=="redirections"));
update_option("rank_math_modules",$modules);
' >/dev/null
SH);
$schemaBaselineCommit = strpos(
    $checkHarness,
    "commit_rank_math_source 'conformance: schema recovery module baseline'"
);
$schemaBaselineApply = strpos($checkHarness, <<<'SH'
capture_wprism_json_success SCHEMA_BASELINE_APPLY 'Rank Math schema recovery baseline apply' \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
SH);
$schemaDeactivate = strpos($checkHarness, 'wp_conf2 plugin deactivate seo-by-rank-math');
$schemaRepairedDeploy = strpos($checkHarness, 'REDEPLOY=$(host_wprism conf2 deploy 2>&1)');
$schemaSourceRestore = strpos($checkHarness, <<<'SH'
wp_conf1 eval '
$modules=array_values((array)get_option("rank_math_modules",[]));
if (in_array("redirections",$modules,true)) {
    throw new RuntimeException("Rank Math recovery baseline unexpectedly retained redirections");
}
$offset=array_search("rich-snippet",$modules,true);
if (!is_int($offset)) {
    throw new RuntimeException("Rank Math recovery baseline lacks the rich-snippet insertion anchor");
}
array_splice($modules,$offset,0,["redirections"]);
update_option("rank_math_modules",$modules);
' >/dev/null
SH);
$schemaRestoreCommit = strpos(
    $checkHarness,
    "commit_rank_math_source 'conformance: restore Rank Math module intent after recovery'"
);
$schemaRestoreApply = strpos($checkHarness, <<<'SH'
capture_wprism_json_success RESTORE 'Rank Math canonical verification after lifecycle recovery' \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
SH);
wprism_check(
    is_int($schemaSourceDisable)
        && is_int($schemaBaselineCommit)
        && is_int($schemaBaselineApply)
        && is_int($schemaDeactivate)
        && is_int($schemaRepairedDeploy)
        && is_int($schemaSourceRestore)
        && is_int($schemaRestoreCommit)
        && is_int($schemaRestoreApply)
        && $schemaSourceDisable < $schemaBaselineCommit
        && $schemaBaselineCommit < $schemaBaselineApply
        && $schemaBaselineApply < $schemaDeactivate
        && $schemaDeactivate < $schemaRepairedDeploy
        && $schemaRepairedDeploy < $schemaSourceRestore
        && $schemaSourceRestore < $schemaRestoreCommit
        && $schemaRestoreCommit < $schemaRestoreApply,
    'B4: recovery evidence removes and restores its authored module on source through ordered commits and answered target applies'
);
wprism_check(
    !str_contains(
        $checkHarness,
        <<<'SH'
wp_conf2 eval '
$modules=array_values(array_filter((array)get_option("rank_math_modules",[]),static fn($m)=>$m!=="redirections"));
update_option("rank_math_modules",$modules);
' >/dev/null
SH
    ),
    'B4: recovery evidence cannot recreate its authored module premise through the former target-only mutation'
);
$recoveredNotification = strpos(
    $checkHarness,
    "require_observed_nonempty 'Rank Math recovered notification before repaired activation'"
);
$nativeActivationNotification = strpos(
    $checkHarness,
    "require_observed_nonempty 'Rank Math native activation notification outcome'"
);
$concurrentFinal = strpos($checkHarness, 'TARGET_FINAL=$(observe_rank_math conf2)');
$concurrentEmptyNotification = is_int($concurrentFinal)
    ? strpos($checkHarness, '.target_owned.notifications == []', $concurrentFinal)
    : false;
$preUninstallNotification = strpos(
    $checkHarness,
    "require_observed_nonempty 'Rank Math pre-uninstall runtime notification premise'"
);
$nativeUninstallDeactivate = is_int($preUninstallNotification)
    ? strpos($checkHarness, 'wp_conf2 plugin deactivate seo-by-rank-math', $preUninstallNotification)
    : false;
$retiredNotification = strpos(
    $checkHarness,
    "require_observed_nonempty 'Rank Math retired runtime notification'"
);
$reinstalledInactiveNotification = strpos(
    $checkHarness,
    "require_observed_nonempty 'Rank Math reinstalled-inactive runtime notification'"
);
$reinstallDeploy = strpos($checkHarness, 'REINSTALL_DEPLOY=$(host_wprism conf2 deploy 2>&1)');
$reinstallActivationNotification = strpos(
    $checkHarness,
    "require_observed_nonempty 'Rank Math reinstall activation notification outcome'"
);
$reinstallApply = strpos($checkHarness, 'REINSTALL_APPLY=$(wp_conf2 wprism apply');
wprism_check(
    is_int($recoveredNotification)
        && is_int($nativeActivationNotification)
        && is_int($concurrentFinal)
        && is_int($concurrentEmptyNotification)
        && is_int($preUninstallNotification)
        && is_int($nativeUninstallDeactivate)
        && is_int($retiredNotification)
        && is_int($reinstalledInactiveNotification)
        && is_int($reinstallDeploy)
        && is_int($reinstallActivationNotification)
        && is_int($reinstallApply)
        && $recoveredNotification < $schemaRepairedDeploy
        && $schemaRepairedDeploy < $nativeActivationNotification
        && $nativeActivationNotification < $concurrentFinal
        && $concurrentFinal < $concurrentEmptyNotification
        && $concurrentEmptyNotification < $preUninstallNotification
        && $preUninstallNotification < $nativeUninstallDeactivate
        && $nativeUninstallDeactivate < $retiredNotification
        && $retiredNotification < $reinstalledInactiveNotification
        && $reinstalledInactiveNotification < $reinstallDeploy
        && $reinstallDeploy < $reinstallActivationNotification
        && $reinstallActivationNotification < $reinstallApply
        && str_contains(
            $checkHarness,
            'jq -e \'type == "array" and length == 0\' <<<"$ACTIVATED_NOTIFICATION"'
        )
        && str_contains($checkHarness, '<<<"$RECOVERED_NOTIFICATION" >/dev/null \\')
        && substr_count(
            $checkHarness,
            'jq -e --argjson expected "$LIFECYCLE_NOTIFICATION" \'. == $expected\''
        ) === 2
        && str_contains($checkHarness, '<<<"$RETIRED_NOTIFICATION" >/dev/null \\')
        && str_contains($checkHarness, '<<<"$REINSTALL_PENDING_NOTIFICATION" >/dev/null \\')
        && str_contains(
            $checkHarness,
            'jq -e \'type == "array" and length == 0\' <<<"$REINSTALL_ACTIVATED_NOTIFICATION"'
        ),
    'B4: recovery, apply and two native activations distinguish preserved target runtime from plugin-owned queue advancement'
);
wprism_check(
    str_contains($checkHarness, '"details_redacted":true')
        && str_contains($checkHarness, "the target's schema-status refusal was redacted")
        && str_contains($checkHarness, "grep -Fq '.wprism/refusals/'")
        && str_contains(
            $checkHarness,
            'AUTHORED_LOSS_PRIVATE_BASELINE=$($COMPOSE run --rm -T --entrypoint php cli2'
        )
        && str_contains(
            $checkHarness,
            'AUTHORED_LOSS_PRIVATE_RECEIPT=$($COMPOSE run --rm -T --entrypoint php cli2'
        )
        && str_contains($checkHarness, 'wprism-rank-math-private-refusal-check/v1'),
    'B4: authored schema loss keeps its cause private while uid-matched evidence proves the exact new refusal graph'
);
wprism_check(
    str_contains($checkHarness, "'" . rank_math_private_refusal_receipt() . "'")
        && hash('sha256', rank_math_private_refusal_profile('schema-loss')['message'])
            === '818de0fac4852aff4fb77b52978db22331055a6a041ebb963a97e4759e223389',
    'B4: live conformance pins the helper-produced value-free receipt for the exact reviewed private cause'
);
wprism_check(
    str_contains($checkHarness, "'" . rank_math_private_refusal_receipt('missing-code') . "'")
        && str_contains($checkHarness, 'snapshot missing-code /siterepo/.wprism/refusals')
        && str_contains($checkHarness, 'verify missing-code /siterepo/.wprism/refusals')
        && str_contains($checkHarness,
            "! grep -Eq 'code_mismatch|missing_in_code|is not installed|active_plugins'")
        && hash('sha256', rank_math_private_refusal_profile('missing-code')['message'])
            === '9145307bebd452be68d85b17d6bcd43b48921710b4a3f4fd9ffd0010d7690d93',
    'B4: missing code stays public-value-free while command-scoped evidence proves its exact private cause'
);
$reinstallDigestLookup = strpos($checkHarness,
    'RANK_MATH_REINSTALL_SHA=$(artifact_library_jq -er --arg version "$RANK_MATH_EXPECTED_VERSION"');
$reinstallCachePath = strpos($checkHarness,
    'RANK_MATH_REINSTALL="/artifacts-cache/plugin-seo-by-rank-math-${RANK_MATH_EXPECTED_VERSION}-${RANK_MATH_REINSTALL_SHA}.zip"');
$reinstallDigestCheck = strpos($checkHarness,
    '"$(wp_conf2 eval "echo hash_file(\'sha256\', \'$RANK_MATH_REINSTALL\');")"');
$reinstallInstall = strpos($checkHarness, 'wp_conf2 plugin install "$RANK_MATH_REINSTALL" --force');
wprism_check(
    $reinstallDigestLookup !== false
        && $reinstallCachePath !== false
        && $reinstallDigestCheck !== false
        && $reinstallInstall !== false
        && $reinstallDigestLookup < $reinstallCachePath
        && $reinstallCachePath < $reinstallDigestCheck
        && $reinstallDigestCheck < $reinstallInstall
        && str_contains($checkHarness, '.plugins["seo-by-rank-math"][$version].sha256')
        && str_contains($checkHarness, '"$RANK_MATH_REINSTALL_SHA" ]')
        && !str_contains($checkHarness, 'fetch_artifact'),
    'B4: reinstall consumes the setup-established content-addressed artifact through the child-safe read-only library ABI'
);

wprism_check_same(
    ['cast' => 'string', 'class' => 'authored', 'ref' => 'post'],
    $policy->meta_rule_for_post('rank_math_facebook_image_id', []),
    'C1: a post social-image id is a string-stored attachment reference'
);
wprism_check_same(
    ['cast' => 'string', 'class' => 'authored', 'ref' => 'term'],
    $policy->meta_rule_for_post('rank_math_primary_category', []),
    'C1: a dynamic primary-taxonomy value is a string-stored term reference'
);
wprism_check_same('derived', $policy->meta_rule_for_post('rank_math_seo_score', [])['class'] ?? null,
    'C2: the SEO score remains a plugin-derived projection');
wprism_check_same(null, $policy->meta_rule_for_post('rank_math_schema_Article', []),
    'C2: custom schema payloads stay undeclared and therefore block discovery');
wprism_check_same(null, $policy->meta_rule_for_post('rank_math_shortcode_schema_portable-article', []),
    'C2: source-local postmeta-row shortcuts stay undeclared with their schema sibling');
wprism_check_same(
    ['cast' => 'string', 'class' => 'authored', 'ref' => 'post'],
    $policy->meta_rule_for_term('rank_math_twitter_image_id', []),
    'C3: a term social-image id is remapped through attachment identity too'
);

$redirections = $policy->table_rule('rank_math_redirections');
wprism_check(is_array($redirections)
    && ($redirections['class'] ?? null) === 'authored_snapshot'
    && ($redirections['pk'] ?? null) === 'id'
    && ($redirections['id_kind'] ?? null) === 'rank_math_redirection',
    'D1: redirections are a typed authored snapshot with an explicit local primary key and id kind');
wprism_check_same(['column' => 'sources', 'mode' => 'natural_key'], $redirections['identity'] ?? null,
    'D1: native domain-free serialized sources are the portable redirection identity');
wprism_check_same(
    ['header_code' => 'authored', 'sources' => 'authored', 'status' => 'authored', 'url_to' => 'authored'],
    array_intersect_key(
        array_map(static fn(array $rule): string => (string) ($rule['class'] ?? ''),
            (array) ($redirections['columns'] ?? [])),
        array_flip(['header_code', 'sources', 'status', 'url_to'])
    ),
    'D2: only the rule definition columns are portable authored state'
);
wprism_check_same(
    ['created' => 'runtime', 'hits' => 'runtime', 'last_accessed' => 'runtime', 'updated' => 'runtime'],
    array_intersect_key(
        array_map(static fn(array $rule): string => (string) ($rule['class'] ?? ''),
            (array) ($redirections['columns'] ?? [])),
        array_flip(['created', 'hits', 'last_accessed', 'updated'])
    ),
    'D2: traffic counters and clocks remain target runtime state'
);
wprism_check_same(
    [['column' => 'redirection_id', 'table' => 'rank_math_redirections_cache']],
    $redirections['invalidate'] ?? null,
    'D3: apply invalidates only the derived native redirection cache rows it can attribute'
);
wprism_check_same('derived', $policy->table_rule('rank_math_redirections_cache')['class'] ?? null,
    'D3: redirection cache rows are never transferred');
wprism_check_same('runtime', $policy->table_rule('rank_math_404_logs')['class'] ?? null,
    'D3: 404 traffic logs stay on the target');
wprism_check_same('derived', $policy->table_rule('rank_math_internal_links')['class'] ?? null,
    'D3: internal-link edges remain a rebuildable plugin projection');
wprism_check_same('runtime', $policy->table_rule('actionscheduler_actions')['class'] ?? null,
    'D3: Rank Math bundled Action Scheduler jobs remain local execution state');
wprism_check(!isset($adapter['column_codecs']['rank_math_redirections']['sources']),
    'D4: the natural identity column carries no codec that could change its lookup bytes');
$customPostActions = $policy->actions_for(['post:book']);
wprism_check_same(
    ['rebuild_all_link_state'],
    array_values(array_map(
        static fn(array $action): string => (string) ($action['capability'] ?? ''),
        array_filter($customPostActions, static fn(array $action): bool => ($action['kind'] ?? null) === 'provider')
    )),
    'D5: a scoped custom public CPT selects the site repair needed to converge deleted-source state'
);
foreach (['option:unrelated_plugin_state', 'table:unrelated_runtime', 'term:category'] as $trigger) {
    $actions = $policy->actions_for([$trigger]);
    wprism_check_same(
        ['rebuild_all_link_state'],
        array_values(array_map(
            static fn(array $action): string => (string) ($action['capability'] ?? ''),
            array_filter($actions, static fn(array $action): bool => ($action['kind'] ?? null) === 'provider')
        )),
        "D5: $trigger selects the one globally dependent native repair"
    );
}

$releases = AdapterBoundary::readReleaseList($releasePath);
$outcomes = AdapterBoundary::readOutcomeTable($outcomePath, 'seo-by-rank-math');
wprism_check_same(['1.0.276', '1.0.277'], array_column($releases['releases'], 'version'),
    'E1: the recorded release list contains the adjacent below-range control and exercised anchor in order');
wprism_check_same(
    ['5252b6e233fe6fe73e69b57fbb039b50140bb5f12438da0ada1c2a973a99f83d',
        '7fb596fad0b82ef4c2a5fede32aa91f0b645658027c20ac2ffa5cd5979eea616'],
    array_column($releases['releases'], 'sha256'),
    'E1: both official artifacts retain their locally measured SHA-256 identities'
);
wprism_check_same(['1.0.277'], array_keys($outcomes),
    'E2: only the release run end to end has an outcome; the below-range install is not promoted to green');
wprism_check_same(AdapterBoundary::OUTCOME_GREEN, $outcomes['1.0.277']['outcome'] ?? null,
    'E2: the exercised exact release records a green full-chain outcome');
$signature = (string) ($outcomes['1.0.277']['signature'] ?? '');
wprism_check(str_contains($signature, 'adopt 4')
    && str_contains($signature, 'attachment 17')
    && str_contains($signature, 'canonical-recapture/v1 passed')
    && str_contains($signature, 'unchanged 11'),
    'E2: the outcome signature retains hostile-target adoption, reference rebinding, recapture and idempotence evidence');

wprism_check(is_file($root . '/adapter-packages/rank-math/package/disposition.json')
    && is_file($root . '/adapter-packages/rank-math/package/runtime/providers/rank-math-state.php'),
    'F1: the promoted contract is owned by one self-contained shipped capsule');

wprism_check_summary('regress_rank_math_package');
