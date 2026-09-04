<?php
/** Product-path contract regression for the Rank Math commerce/multilingual stack. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';

use WPrism\Canon;
use WPrism\Policy;

$scenarioPath = $root . '/integration-scenarios/rank-math-commerce-multilingual/scenario.json';
$scenarioBytes = (string) file_get_contents($scenarioPath);
$scenario = Canon::decode($scenarioBytes);
wprism_check_same($scenarioBytes, Canon::encode($scenario),
    'the Rank Math commerce/multilingual participant record is canonical');
wprism_check_same(
    ['acf', 'polylang', 'rank-math', 'woocommerce'],
    $scenario['participants'] ?? null,
    'the scenario declares the four exact adapter owners in canonical order'
);

$historicalPath = $root . '/integration-scenarios/rank-math-commerce-multilingual/fixtures/'
    . 'historical-site-outcome.json';
$historicalBytes = (string) file_get_contents($historicalPath);
$historical = Canon::decode($historicalBytes);
wprism_check_same($historicalBytes, Canon::encode($historical),
    'the pre-promotion four-plugin site exercise remains canonical and value-redacted');
wprism_check_same(
    ['advanced-custom-fields:6.8.7', 'polylang:3.8.6', 'seo-by-rank-math:1.0.277', 'woocommerce:11.0.1'],
    array_map(
        static fn(array $plugin): string => $plugin['name'] . ':' . $plugin['version'],
        $historical['combinations'][0]['plugins'] ?? []
    ),
    'the historical scenario pins every exact plugin artifact it exercised'
);
$historicalSignature = (string) ($historical['combinations'][0]['signature'] ?? '');
wprism_check(($historical['combinations'][0]['outcome'] ?? null) === 'green'
    && str_contains($historicalSignature, 'unchanged 43')
    && str_contains($historicalSignature, 'target-only scheduler action remained')
    && str_contains($historicalSignature, 'hreflang'),
    'the historical scenario retains convergence, runtime isolation and rendered multilingual SEO evidence');
wprism_check_same('refused', $historical['refusals'][0]['outcome'] ?? null,
    'the historical hostile ACF overlap is a refusal, never a compatibility claim');
wprism_check(str_contains(
    (string) ($historical['refusals'][0]['signature'] ?? ''),
    "post_meta 'rank_math_title' has multiple classification owners"
), 'the historical scenario retains the exact cross-owner refusal');

$site = sys_get_temp_dir() . '/wprism_rank_math_combo_' . bin2hex(random_bytes(8));
if (!mkdir($site, 0700, true) && !is_dir($site)) {
    throw new RuntimeException("could not create scratch site repository $site");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/site.wprism.json');
    @rmdir($site);
});

$library = \WPrism\AdapterLibrary::fromSourceTree($root);
$orders = [
    ['core', 'acf', 'polylang', 'rank-math', 'woocommerce'],
    ['core', 'woocommerce', 'rank-math', 'polylang', 'acf'],
];
$ordinaryAcfField = [
    'type' => 'post',
    'path' => 'state/posts/acf-field/field_rmcombo_badge.json',
    'data' => ['type' => 'acf-field', 'slug' => 'field_rmcombo_badge'],
    'body' => serialize([
        'key' => 'field_rmcombo_badge',
        'name' => 'rmcombo_badge',
        'type' => 'text',
    ]),
];
$hostileAcfField = [
    'type' => 'post',
    'path' => 'state/posts/acf-field/field_rmcombo_rank_title.json',
    'data' => ['type' => 'acf-field', 'slug' => 'field_rmcombo_rank_title'],
    'body' => serialize([
        'key' => 'field_rmcombo_rank_title',
        'name' => 'rank_math_title',
        'type' => 'post_object',
    ]),
];

foreach ($orders as $pins) {
    Canon::write_file($site . '/site.wprism.json', Canon::encode([
        'manifests' => array_map(
            static fn(string $name): array => ['name' => $name, 'source' => 'shipped'],
            array_slice($pins, 1)
        ),
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    $policy = Policy::load($site, $pins, adapterLibrary: $library);
    $policy->prime_interpreters_from_repository([$ordinaryAcfField, $hostileAcfField]);

    foreach (['actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs'] as $table) {
        wprism_check_same('runtime', $policy->table_rule($table)['class'] ?? null,
            "$table remains one byte-identical runtime declaration under pin order " . implode(',', $pins));
    }
    wprism_check_same('rank-math', $policy->option_namespace('rank_math_modules')['owner'] ?? null,
        'Rank Math keeps its option namespace under either active-plugin order');
    wprism_check_same('woocommerce', $policy->option_namespace('woocommerce_shop_page_id')['owner'] ?? null,
        'WooCommerce keeps its option namespace under either active-plugin order');
    wprism_check_same('polylang', $policy->option_namespace('polylang')['owner'] ?? null,
        'Polylang keeps its option namespace under either active-plugin order');
    wprism_check_same('authored', $policy->meta_rule_for_post('rank_math_title', [])['class'] ?? null,
        'ordinary Rank Math product SEO remains statically authored without an ACF shadow');
    wprism_check_same('authored', $policy->meta_rule_for_post('rmcombo_badge', [
        '_rmcombo_badge' => 'field_rmcombo_badge',
        'rmcombo_badge' => 'Portable badge',
    ])['class'] ?? null,
        'a non-overlapping ACF product field composes with Rank Math metadata');

    wprism_check_throws(
        fn() => $policy->meta_rule_for_post('rank_math_title', [
            '_rank_math_title' => 'field_rmcombo_rank_title',
            'rank_math_title' => '17',
        ]),
        RuntimeException::class,
        'an ACF field cannot reinterpret Rank Math physical metadata under pin order ' . implode(',', $pins),
        "post_meta 'rank_math_title' has multiple classification owners"
    );
}

$rankMath = Canon::decode(Canon::read_file(
    $root . '/adapter-packages/rank-math/package/manifest.json'
));
$actions = $rankMath['actions'] ?? [];
wprism_check(!array_key_exists('triggers', $actions[2] ?? []),
    'Rank Math declares its native repair as site-complete rather than a partial product/CPT trigger list');
foreach (['post:product', 'option:polylang', 'table:wc_product_meta_lookup', 'term:product_cat'] as $surface) {
    $selected = array_values(array_filter(
        $policy->actions_for([$surface]),
        static fn(array $action): bool => ($action['provider'] ?? null) === 'rank-math-state'
    ));
    wprism_check_same(
        ['rebuild_all_link_state'],
        array_column($selected, 'capability'),
        "$surface selects Rank Math's one site-complete repair inside the combined policy"
    );
}
wprism_check_same(
    ['inspect_schema', 'prepare_schema', 'rebuild_all_link_state'],
    $rankMath['providers'][0]['capabilities'] ?? null,
    'the combined scenario consumes the same readiness/schema/site-repair provider contract as standalone Rank Math'
);

$livePath = $root . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/'
    . 'regress_rank_math_commerce_multilingual.sh';
$live = is_file($livePath) ? (string) file_get_contents($livePath) : '';
foreach ([
    'advanced-custom-fields 6.8.7',
    'polylang 3.8.6',
    'seo-by-rank-math 1.0.277.2',
    'woocommerce 11.0.1',
    "post_meta 'rank_math_title' has multiple classification owners",
    'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema',
    "add_filter('rank_math/excluded_post_types'",
    'register_post_type(\'rmcombo_book\'',
    'source-only Action Scheduler state exists natively before capture',
    'exact reciprocal hreflang, canonical and Open Graph state renders on both products',
    'native 302 routing consumes the target-bound URL',
    'provider failure retained combined retry authority',
    'direct deletion remains refusal-only across the combined adapter boundary',
    'deletion_writer_exclusion_required',
    'combined direct deletion refusal changed native or target-runtime state',
    'signed promotion is exercised by the SSH scenario extension',
    'combined target recapture differs',
] as $witness) {
    wprism_check(str_contains($live, $witness), "the candidate-bound live scenario pins: $witness");
}

$configureDefinition = strpos($live, 'configure_rank_math() {');
$nativeModuleDisable = strpos($live, 'RankMath\\Helper::update_modules(array_fill_keys($stored, "off"));');
$nativeModuleEnable = strpos($live, 'RankMath\\Helper::update_modules(array_fill_keys($desired, "on"));');
$readinessDefinition = strpos($live, 'rank_math_readiness() {');
$activeModuleReadback = strpos($live, '$activeModules = array_values(RankMath\\Helper::get_active_modules());');
$sourceConfigure = strpos($live, "\nconfigure_rank_math wp1 source\n");
$targetConfigure = strpos($live, "\nconfigure_rank_math wp2 target\n");
$sourceReadiness = strpos($live, 'SOURCE_RANK_MATH_READY=$(rank_math_readiness wp1 source)');
$targetReadiness = strpos($live, 'TARGET_RANK_MATH_READY=$(rank_math_readiness wp2 target)');
$sourceReadinessObserved = strpos(
    $live,
    "require_observed_nonempty 'source Rank Math native module readiness' \"\$SOURCE_RANK_MATH_READY\""
);
$targetReadinessObserved = strpos(
    $live,
    "require_observed_nonempty 'target Rank Math native module readiness' \"\$TARGET_RANK_MATH_READY\""
);
$readinessOracle = strpos($live, <<<'SH'
jq -en --argjson source "$SOURCE_RANK_MATH_READY" --argjson target "$TARGET_RANK_MATH_READY" '
  $source == {
    active_modules:["link-counter","redirections","rich-snippet"],
    modules:["link-counter","redirections","rich-snippet"],role:"source",
    tables:{rank_math_internal_links:true,rank_math_internal_meta:true,
      rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  } and
  $target == {
    active_modules:["redirections","rich-snippet"],
    modules:["redirections","rich-snippet"],role:"target",
    tables:{rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  }
' >/dev/null || fail "Rank Math native module readiness is incomplete: $SOURCE_RANK_MATH_READY / $TARGET_RANK_MATH_READY"
SH);
$nativeAuthoring = strpos($live,
    "say 'author native multilingual products, ACF values, Rank Math SEO/link state and Woo lookup state'");
wprism_check(
    $configureDefinition !== false
        && $nativeModuleDisable !== false
        && $nativeModuleEnable !== false
        && $readinessDefinition !== false
        && $activeModuleReadback !== false
        && $sourceConfigure !== false
        && $targetConfigure !== false
        && str_contains($live, '["link-counter", "redirections", "rich-snippet"]')
        && str_contains($live, '["redirections", "rich-snippet"]')
        && !str_contains($live, 'update_option("rank_math_modules"')
        && str_contains($live,
            '["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"]')
        && str_contains($live, '["rank_math_redirections", "rank_math_redirections_cache"]')
        && $sourceReadiness !== false
        && $targetReadiness !== false
        && $sourceReadinessObserved !== false
        && $targetReadinessObserved !== false
        && $readinessOracle !== false
        && $nativeAuthoring !== false
        && $configureDefinition < $nativeModuleDisable
        && $nativeModuleDisable < $nativeModuleEnable
        && $nativeModuleEnable < $readinessDefinition
        && $readinessDefinition < $activeModuleReadback
        && $activeModuleReadback < $sourceConfigure
        && $sourceConfigure < $targetConfigure
        && $targetConfigure < $sourceReadiness
        && $sourceReadiness < $targetReadiness
        && $targetReadiness < $sourceReadinessObserved
        && $sourceReadinessObserved < $targetReadinessObserved
        && $targetReadinessObserved < $readinessOracle
        && $readinessOracle < $nativeAuthoring,
    'runtime module setup and fresh registered-active readbacks prove every exact Rank Math role and table before authoring'
);

preg_match_all('/^run_leg (forward reverse|reverse forward)$/m', $live, $legs);
wprism_check_same(
    ['forward reverse', 'reverse forward'],
    $legs[1] ?? [],
    'both pairwise-opposed source/target plugin-load orders run the complete parameterized product path'
);
$sourceOrderReadback = strpos($live, 'SOURCE_ORDER=$(active_plugin_order wp1)');
$targetOrderReadback = strpos($live, 'TARGET_ORDER=$(active_plugin_order wp2)');
$jointOrderGuard = strpos($live, '  $source == $expected_source and $target == $expected_target');
wprism_check(
    $sourceOrderReadback !== false
        && $targetOrderReadback !== false
        && $jointOrderGuard !== false
        && $sourceOrderReadback < $targetOrderReadback
        && $targetOrderReadback < $jointOrderGuard,
    'the live scenario reads back both actual plugin boot orders before comparing both exact expectations'
);
foreach ([
    'persist_active_plugin_order 1 "$source_order"',
    'persist_active_plugin_order 2 "$target_order"',
] as $setter) {
    $position = strpos($live, $setter);
    wprism_check($position !== false && $position < $sourceOrderReadback,
        'each explicit plugin-order premise is persisted before either order is observed');
}
wprism_check(
    str_contains($live,
        '["polylang/polylang.php","advanced-custom-fields/acf.php","seo-by-rank-math/rank-math.php","woocommerce/woocommerce.php"]')
        && str_contains($live,
            '["polylang/polylang.php","woocommerce/woocommerce.php","seo-by-rank-math/rank-math.php","advanced-custom-fields/acf.php"]'),
    'both pairwise-opposed orders retain Polylang native precedence and reverse every other participant'
);
$hostDeploy = strpos($live, 'CLEAN_DEPLOY=$(host_wprism_combo wp2 deploy');
$settledOrderReadback = strpos($live, 'HOST_SETTLED_ORDER=$(active_plugin_order wp2)');
$initialApply = strpos($live, 'INITIAL=$(wp2 wprism apply');
wprism_check(
    $hostDeploy !== false
        && $settledOrderReadback !== false
        && $initialApply !== false
        && $hostDeploy < $settledOrderReadback
        && $settledOrderReadback < $initialApply
        && str_contains($live,
            'jq -en --argjson actual "$HOST_SETTLED_ORDER" --argjson expected "$expected_source"')
        && str_contains($live, '$actual == $expected'),
    'host lifecycle order is independently read back and matched to canonical source before product apply'
);
wprism_check(
    str_contains($live, '([ $target.products.en.links[].type ] | sort) == ["external","internal"]')
        && str_contains($live, '.retired_target_counts.incoming_link_count | tonumber) == 0')
        && str_contains($live, '$target.stale_link_sentinels == 0')
        && !str_contains($live, '.products.en.links >=')
        && !str_contains($live, "grep -Fq 'hreflang='"),
    'the live oracle asserts exact link rows, retired-target counts and head semantics rather than lower bounds or token greps'
);
wprism_check(
    str_contains($live, '($failed | .products.en.content = $baseline.products.en.content) == $baseline')
        && str_contains($live, '| .products.en.rank_counts = $baseline.products.en.rank_counts) == $baseline')
        && str_contains($live, '$final == $retried')
        && str_contains($live, 'provider:rank-math-state/rebuild_all_link_state'),
    'failure, retry and no-op phases retain exact target-only witnesses and bind the selected Rank Math action source'
);
$directDeletion = strpos($live, "say 'direct deletion remains refusal-only across the combined adapter boundary'");
$withheldRefusal = strpos($live, 'DELETE_WITHHELD_RC=0');
$exclusionRefusal = strpos($live, '.reason_code == "deletion_writer_exclusion_required"');
$refusalObservation = strpos($live, 'DELETE_REFUSAL_NATIVE=$(native_state wp2)');
$refusalEquality = strpos(
    $live,
    '--argjson before "$TARGET_FINAL" --argjson after "$DELETE_REFUSAL_NATIVE" \'$after == $before\''
);
wprism_check(
    $directDeletion !== false
        && $withheldRefusal !== false
        && $exclusionRefusal !== false
        && $refusalObservation !== false
        && $refusalEquality !== false
        && $directDeletion < $withheldRefusal
        && $withheldRefusal < $exclusionRefusal
        && $exclusionRefusal < $refusalObservation
        && $refusalObservation < $refusalEquality
        && !str_contains($live, 'promote target --with-deletes')
        && !str_contains($live, 'promote complete: verified committed receipt; traffic exclusion released'),
    'the Docker scenario proves both direct deletion refusals and exact native-state preservation, never a fabricated success'
);

$sshDeletionPath = $root . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/'
    . 'regress_rank_math_commerce_multilingual_ssh_deletion.sh';
$sshDeletion = is_file($sshDeletionPath) ? (string) file_get_contents($sshDeletionPath) : '';
wprism_check($sshDeletion !== '',
    'the participant-owned scenario includes a tracked SSH-adoption deletion extension');
$directProbe = proc_open(
    ['bash', $sshDeletionPath],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $directPipes,
    $root,
    ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')]
);
$directStdout = '';
$directStderr = '';
$directStatus = 127;
if (is_resource($directProbe)) {
    $directStdout = (string) stream_get_contents($directPipes[1]);
    fclose($directPipes[1]);
    $directStderr = (string) stream_get_contents($directPipes[2]);
    fclose($directPipes[2]);
    $directStatus = proc_close($directProbe);
}
wprism_check(
    $directStatus !== 0
        && $directStdout === ''
        && str_contains($directStderr, 'ADOPT_FIXTURE'),
    'the discovered direct live-gate argv refuses missing allocation instead of returning a silent false green'
);
wprism_check(
    !str_contains($sshDeletion, 'declare(strict_types=1);')
        && str_contains($sshDeletion, 'if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then')
        && str_contains($sshDeletion, 'export WPRISM_SSH_ADOPT_EXTENSION=')
        && str_contains($sshDeletion, 'exec bash "$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"'),
    'the extension remains WP-CLI eval-safe when sourced and delegates direct execution to the shared SSH product gate'
);
$sshExtensionHelper = (string) file_get_contents($root . '/sandbox/tests/lib/ssh_adopt_extension.sh');
wprism_check(
    str_contains($sshDeletion, 'wprism_ssh_enroll_full_recovery rm-combo')
        && str_contains($sshDeletion, 'wprism_ssh_install_certified_plugin "$adapter" "$expected_version"')
        && str_contains($sshDeletion, 'wprism_ssh_stage_code_inventory')
        && str_contains($sshDeletion, 'advanced-custom-fields polylang seo-by-rank-math woocommerce')
        && str_contains($sshDeletion, 'wprism_ssh_stage_generation_releases 1')
        && str_contains($sshDeletion, 'wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete')
        && !str_contains($sshDeletion, 'wp plugin install')
        && str_contains($sshExtensionHelper, 'artifact_library_jq -ce')
        && str_contains($sshExtensionHelper, '.role == "certified-boundary"')
        && str_contains($sshExtensionHelper, 'hash_file("sha256", $argv[1])')
        && str_contains($sshExtensionHelper, 'Deletion::capture_tombstones($compiled, [], $policy, [$uuid])')
        && str_contains($sshExtensionHelper,
            '$ROOT/sandbox/tests/fixtures/plan-bound-code-release-provider.php')
        && !str_contains($sshExtensionHelper, 'adapter-packages/woocommerce/fixtures/'),
    'the scenario composes certified artifacts, code/release/tombstone staging, and recovery through shared machinery'
);
$sshArtifactPins = [
    'acf' => ['advanced-custom-fields', '6.8.7', 'f877a94871e55cc2f2931052c693705d376da12cb85c9761b6915c037f91cec2'],
    'polylang' => ['polylang', '3.8.6', 'dd2a213d407c6d565eb5e246e68b434003f1112c059ee53ca070bf97102010aa'],
    'rank-math' => ['seo-by-rank-math', '1.0.277.2', '1c6cae3fda401798dfdc5d1d5814de17c040ffcb457c40e2f0256db84a680b1b'],
    'woocommerce' => ['woocommerce', '11.0.1', 'da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21'],
];
foreach ($sshArtifactPins as $participant => [$slug, $version, $sha256]) {
    $lock = Canon::decode(Canon::read_file(
        "$root/adapter-packages/$participant/evidence/artifacts.lock.json"
    ));
    wprism_check_same(
        ['url' => "https://downloads.wordpress.org/plugin/$slug.$version.zip", 'sha256' => $sha256, 'role' => 'certified-boundary'],
        $lock['plugins'][$slug][$version] ?? null,
        "the SSH combination's $slug $version bytes are fixed by the participant-owned certified artifact lock"
    );
}
foreach ([
    'advanced-custom-fields|6.8.7',
    'polylang|3.8.6',
    'seo-by-rank-math|1.0.277.2',
    'woocommerce|11.0.1',
    'wprism_ssh_enroll_full_recovery rm-combo',
    'WC_Product_Simple',
    "pll_set_post_language(\$postId, 'en')",
    "update_field('field_rmcombo_ssh_note', 'delete this ACF value', \$postId)",
    'RankMath\\Links\\Links::process_post_links($postId, get_post($postId))',
    '.source == "provider:rank-math-state/rebuild_all_link_state"',
    '.source == "provider:woocommerce-product-lookups/cleanup_product_deletions"',
    'promote target --with-deletes',
    'promote complete: verified committed receipt; traffic exclusion released',
    '.receipt.allow_deletes == true',
    'signed promotion deletes ACF/Polylang/Rank Math state, preserves the live Woo product/lookup, and converges',
] as $witness) {
    wprism_check(str_contains($sshDeletion, $witness),
        "the SSH deletion extension pins: $witness");
}
$extensionDefinition = strpos($sshDeletion, 'wprism_ssh_adopt_extension() {');
$recoveryEnrollment = strpos($sshDeletion, 'wprism_ssh_enroll_full_recovery rm-combo');
$codeBinding = strpos($sshDeletion, 'bind the exact combined plugin/theme code and adapter pins');
$baselineCapture = strpos($sshDeletion, 'capture target --target-branch="$TARGET_REPOSITORY_BRANCH"');
$tombstonePublication = strpos($sshDeletion,
    'wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete');
$fullPlan = strpos($sshDeletion, 'plan target --format=json');
$signedPromotion = strpos($sshDeletion, 'promote target --with-deletes');
$postimageObservation = strpos($sshDeletion, 'after_json="$(ssh_fixture');
$fixedPointPlan = strrpos($sshDeletion, 'plan target --format=json');
wprism_check(
    $extensionDefinition !== false
        && $recoveryEnrollment !== false
        && $codeBinding !== false
        && $baselineCapture !== false
        && $tombstonePublication !== false
        && $fullPlan !== false
        && $signedPromotion !== false
        && $postimageObservation !== false
        && $fixedPointPlan !== false
        && $extensionDefinition < $recoveryEnrollment
        && $recoveryEnrollment < $codeBinding
        && $codeBinding < $baselineCapture
        && $baselineCapture < $tombstonePublication
        && $tombstonePublication < $fullPlan
        && $fullPlan < $signedPromotion
        && $signedPromotion < $postimageObservation
        && $postimageObservation < $fixedPointPlan,
    'the SSH extension binds recovery/code before capture and proves cleanup plus convergence only after signed promotion'
);
wprism_check(
    str_contains($sshDeletion, 'declared core post:post boundary')
        && str_contains($sshDeletion, 'wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete')
        && str_contains($sshDeletion, '.deletion_type == "post"')
        && str_contains($sshDeletion,
            '([.effects_inventory[]? | select(.source == "provider:woocommerce-product-lookups/cleanup_product_deletions")] | length) == 0')
        && !str_contains($sshDeletion, 'deletion_owner_agreements')
        && !str_contains($sshDeletion, 'type:"product",uuid:$uuid'),
    'the SSH extension stays on core post deletion and does not manufacture unaudited Woo product authority'
);
wprism_check(
    str_contains($sshDeletion, '.deleted.acf_meta == 2')
        && str_contains($sshDeletion, '.deleted.language == "en"')
        && str_contains($sshDeletion, '.deleted.rank_target_links == 1')
        && str_contains($sshDeletion, 'rank_links:0')
        && str_contains($sshDeletion, 'rank_meta:0')
        && str_contains($sshDeletion, 'relationships:0'),
    'the successful SSH path starts from non-vacuous ACF/Polylang/Rank Math state and proves every owned row is gone'
);
wprism_check(
    str_contains($sshDeletion, '.stock_notifications == true')
        && str_contains($sshDeletion, 'lookup:1')
        && str_contains($sshDeletion, 'rank_incoming:1')
        && str_contains($sshDeletion, '($after.product | del(.rank_incoming)) == ($before.product | del(.rank_incoming))')
        && str_contains($sshDeletion, '$after.product.rank_incoming == 0')
        && str_contains($sshDeletion, '$after.product.post == 1')
        && str_contains($sshDeletion, '$after.product.lookup == 1')
        && str_contains($sshDeletion, '.selected_actions == []'),
    'WooCommerce is live and populated, remains outside the core deletion, and the signed path reaches a no-action fixed point'
);

wprism_check_summary('regress_rank_math_commerce_multilingual_contract');
