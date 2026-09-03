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
    'post deletion selects the site-complete Rank Math repair',
    'deleted source posts leave no Rank Math rows/counts/markers',
    'combined target recapture differs',
] as $witness) {
    wprism_check(str_contains($live, $witness), "the candidate-bound live scenario pins: $witness");
}

preg_match_all('/^run_leg (forward reverse|reverse forward)$/m', $live, $legs);
wprism_check_same(
    ['forward reverse', 'reverse forward'],
    $legs[1] ?? [],
    'both opposite source/target plugin-load orders run the complete parameterized product path'
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
wprism_check(
    str_contains($live, '(.plan.delete + .plan.deleted) > 0')
        && str_contains($live, '. == {links:0,markers:0,meta:0,post:0}')
        && str_contains($live, '$after.neighbor == $before.neighbor')
        && str_contains($live, '$after.scheduler == $before.scheduler'),
    'the live deletion oracle proves exact physical/derived cleanup while unrelated target state survives'
);

wprism_check_summary('regress_rank_math_commerce_multilingual_contract');
