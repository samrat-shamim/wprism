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
$adapterBytes = (string) file_get_contents($adapterPath);
$adapter = Canon::decode($adapterBytes);

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
        'WP_CLI', 'WP_Hook', 'WP_Post',
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
