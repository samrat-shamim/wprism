<?php
/**
 * The Rank Math 1.0.277 site-adapter exercise as a committed, executable
 * fixture. Live evidence is carried in rank-math.outcomes.json; this suite
 * proves the exact adapter bytes retain the narrow boundary that exercise
 * measured and load through the same out-of-tree Policy path a customer uses.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/agent_version.php';

$root = dirname(__DIR__, 4);
duo_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/cli/src/Adapter/AdapterBoundary.php';

use Duo\Canon;
use Duo\Orchestrator\AdapterBoundary;
use Duo\Policy;

$fixtureDir = $root . '/sandbox/fixtures/rank-math';
$adapterPath = $fixtureDir . '/adapters/rank-math.json';
$releasePath = $fixtureDir . '/rank-math.releases.json';
$outcomePath = $fixtureDir . '/rank-math.outcomes.json';
$adapterBytes = (string) file_get_contents($adapterPath);
$adapter = Canon::decode($adapterBytes);

duo_check_same($adapterBytes, Canon::encode($adapter),
    'A1: the committed Rank Math adapter is canonical before certification signs its exact bytes');
duo_check_same(
    ['rank-math', 3, 'seo-by-rank-math/rank-math.php'],
    [$adapter['name'] ?? null, $adapter['spec_version'] ?? null, $adapter['plugin'] ?? null],
    'A1: the site fixture names Rank Math, spec v3 and the exact WordPress plugin basename'
);
duo_check_same(
    ['max' => '1.0.278', 'min' => '1.0.277'],
    $adapter['version_range'] ?? null,
    'A2: the compatibility window admits only the one release exercised end to end'
);
duo_check_same(
    ['spec-window/v1', 'structured-evidence/v1'],
    $adapter['engine_features'] ?? null,
    'A2: every v3 section is admitted through an explicit engine feature'
);
duo_check(isset($adapter['declaration_evidence']['tables.rank_math_redirections']),
    'A3: the natural-key table decision carries machine-shaped declaration evidence');
duo_check(count((array) ($adapter['notes'] ?? [])) >= 10,
    'A3: the fixture retains the live boundary, refusals and authoring friction in its own bytes');

$site = sys_get_temp_dir() . '/duo_rank_math_site_' . bin2hex(random_bytes(8));
if (!mkdir($site . '/adapters', 0700, true) && !is_dir($site . '/adapters')) {
    throw new RuntimeException("could not create scratch site repository $site");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/adapters/rank-math.json');
    @unlink($site . '/site.duo.json');
    @rmdir($site . '/adapters');
    @rmdir($site);
});
Canon::write_file($site . '/adapters/rank-math.json', $adapterBytes);
Canon::write_file($site . '/site.duo.json', Canon::encode([
    'manifests' => [['name' => 'rank-math', 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]));
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');
$policy = Policy::load($site, ['rank-math']);

$range = $policy->version_ranges()['seo-by-rank-math/rank-math.php'] ?? null;
duo_check(is_array($range)
    && ($range['min'] ?? null) === '1.0.277'
    && ($range['max'] ?? null) === '1.0.278'
    && ($range['manifest'] ?? null) === 'rank-math',
    'B1: the customer site-adapter load path publishes the exact plugin version boundary');
duo_check_same(
    ['class' => 'authored', 'plain_data' => true, 'autoload' => 'preserve'],
    $policy->option_rule('rank-math-options-general'),
    'B2: the general settings object is portable plain data with preserved autoload state'
);
$titles = $policy->option_rule('rank-math-options-titles');
duo_check(($titles['class'] ?? null) === 'authored'
    && array_column((array) ($titles['json_refs'] ?? []), 'path')
        === ['$.homepage_facebook_image_id', '$.knowledgegraph_logo_id'],
    'B2: the titles object remaps both measured attachment-id positions');
duo_check_same('authored', $policy->option_rule('rank_math_registration_skip')['class'] ?? null,
    'B3: disconnected registration skip is authored executable setup state');
duo_check_same('authored', $policy->option_rule('rank_math_modules')['class'] ?? null,
    'B3: the exercised module selection is portable rather than silently target-owned');
duo_check_same('runtime', $policy->option_rule('rank_math_flush_rewrite')['class'] ?? null,
    'B3: the plugin one-shot rewrite marker never enters canonical state');
duo_check_same('env', $policy->option_rule('rank-math-options-sitemap')['class'] ?? null,
    'B4: sitemap local-id lists keep the whole unsupported option target-owned');
duo_check_same('env', $policy->option_rule('rank-math-options-instant-indexing')['class'] ?? null,
    'B4: the generated IndexNow credential keeps its option target-owned');
duo_check_same(
    ['owner' => 'rank-math', 'match' => '^(?:rank-math-options-|rank_math_)'],
    $policy->option_namespace('rank_math_connect_data'),
    'B4: Rank Math owns discovery of a connected-account option without classifying its credentials'
);
duo_check_same(null, $policy->owned_option_rule('rank_math_connect_data'),
    'B4: namespace ownership is not a blanket authored/runtime classification');

duo_check_same(
    ['cast' => 'string', 'class' => 'authored', 'ref' => 'post'],
    $policy->meta_rule_for_post('rank_math_facebook_image_id', []),
    'C1: a post social-image id is a string-stored attachment reference'
);
duo_check_same(
    ['cast' => 'string', 'class' => 'authored', 'ref' => 'term'],
    $policy->meta_rule_for_post('rank_math_primary_category', []),
    'C1: a dynamic primary-taxonomy value is a string-stored term reference'
);
duo_check_same('derived', $policy->meta_rule_for_post('rank_math_seo_score', [])['class'] ?? null,
    'C2: the SEO score remains a plugin-derived projection');
duo_check_same(null, $policy->meta_rule_for_post('rank_math_schema_Article', []),
    'C2: custom schema payloads stay undeclared and therefore block discovery');
duo_check_same(null, $policy->meta_rule_for_post('rank_math_shortcode_schema_portable-article', []),
    'C2: source-local postmeta-row shortcuts stay undeclared with their schema sibling');
duo_check_same(
    ['cast' => 'string', 'class' => 'authored', 'ref' => 'post'],
    $policy->meta_rule_for_term('rank_math_twitter_image_id', []),
    'C3: a term social-image id is remapped through attachment identity too'
);

$redirections = $policy->table_rule('rank_math_redirections');
duo_check(is_array($redirections)
    && ($redirections['class'] ?? null) === 'authored_snapshot'
    && ($redirections['pk'] ?? null) === 'id'
    && ($redirections['id_kind'] ?? null) === 'rank_math_redirection',
    'D1: redirections are a typed authored snapshot with an explicit local primary key and id kind');
duo_check_same(['column' => 'sources', 'mode' => 'natural_key'], $redirections['identity'] ?? null,
    'D1: native domain-free serialized sources are the portable redirection identity');
duo_check_same(
    ['header_code' => 'authored', 'sources' => 'authored', 'status' => 'authored', 'url_to' => 'authored'],
    array_intersect_key(
        array_map(static fn(array $rule): string => (string) ($rule['class'] ?? ''),
            (array) ($redirections['columns'] ?? [])),
        array_flip(['header_code', 'sources', 'status', 'url_to'])
    ),
    'D2: only the rule definition columns are portable authored state'
);
duo_check_same(
    ['created' => 'runtime', 'hits' => 'runtime', 'last_accessed' => 'runtime', 'updated' => 'runtime'],
    array_intersect_key(
        array_map(static fn(array $rule): string => (string) ($rule['class'] ?? ''),
            (array) ($redirections['columns'] ?? [])),
        array_flip(['created', 'hits', 'last_accessed', 'updated'])
    ),
    'D2: traffic counters and clocks remain target runtime state'
);
duo_check_same(
    [['column' => 'redirection_id', 'table' => 'rank_math_redirections_cache']],
    $redirections['invalidate'] ?? null,
    'D3: apply invalidates only the derived native redirection cache rows it can attribute'
);
duo_check_same('derived', $policy->table_rule('rank_math_redirections_cache')['class'] ?? null,
    'D3: redirection cache rows are never transferred');
duo_check_same('runtime', $policy->table_rule('rank_math_404_logs')['class'] ?? null,
    'D3: 404 traffic logs stay on the target');
duo_check_same('derived', $policy->table_rule('rank_math_internal_links')['class'] ?? null,
    'D3: internal-link edges remain a rebuildable plugin projection');
duo_check_same('runtime', $policy->table_rule('actionscheduler_actions')['class'] ?? null,
    'D3: Rank Math bundled Action Scheduler jobs remain local execution state');
duo_check(!isset($adapter['column_codecs']['rank_math_redirections']['sources']),
    'D4: the natural identity column carries no codec that could change its lookup bytes');

$releases = AdapterBoundary::readReleaseList($releasePath);
$outcomes = AdapterBoundary::readOutcomeTable($outcomePath, 'seo-by-rank-math');
duo_check_same(['1.0.276', '1.0.277'], array_column($releases['releases'], 'version'),
    'E1: the recorded release list contains the adjacent below-range control and exercised anchor in order');
duo_check_same(
    ['5252b6e233fe6fe73e69b57fbb039b50140bb5f12438da0ada1c2a973a99f83d',
        '7fb596fad0b82ef4c2a5fede32aa91f0b645658027c20ac2ffa5cd5979eea616'],
    array_column($releases['releases'], 'sha256'),
    'E1: both official artifacts retain their locally measured SHA-256 identities'
);
duo_check_same(['1.0.277'], array_keys($outcomes),
    'E2: only the release run end to end has an outcome; the below-range install is not promoted to green');
duo_check_same(AdapterBoundary::OUTCOME_GREEN, $outcomes['1.0.277']['outcome'] ?? null,
    'E2: the exercised exact release records a green full-chain outcome');
$signature = (string) ($outcomes['1.0.277']['signature'] ?? '');
duo_check(str_contains($signature, 'adopt 4')
    && str_contains($signature, 'attachment 17')
    && str_contains($signature, 'canonical-recapture/v1 passed')
    && str_contains($signature, 'unchanged 11'),
    'E2: the outcome signature retains hostile-target adoption, reference rebinding, recapture and idempotence evidence');

$exercise = (string) file_get_contents($root . '/docs/agents/rank-math-adapter-authoring-exercise.md');
foreach (['now lets test and harden', 'use terra/luna appropriately', 'can we relax the digest requirement?'] as $prompt) {
    duo_check(str_contains($exercise, $prompt), "F1: the interaction record retains the actual user prompt '$prompt'");
}
duo_check(str_contains($exercise, 'natural identity matches local ids 2 and 6')
    && str_contains($exercise, '--adopt-by-slug=posts,terms')
    && str_contains($exercise, 'rank_math_registration_skip'),
    'F2: the record carries the live duplicate, adoption and executable-setup findings that changed machinery');
duo_check(!is_file($root . '/manifests/rank-math.json')
    && !is_file($root . '/manifests/dispositions/rank-math.json'),
    'F3: the exercise remains a site fixture and makes no shipped Rank Math capability claim');

duo_check_summary('regress_rank_math_adapter');
