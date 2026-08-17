<?php
/**
 * Offline checks for `cli/src/Rehearse/` — the containment disclosure and the
 * "what a release would touch" preview (round-3 MUP §2.2, §4.4, §4.6).
 *
 * Driven by sandbox/tests/regress_rehearse_provider.sh. No docker, no
 * WordPress, no network, no target: both classes are pure.
 *
 * usage: php preview-checks.php <fixture-dir>
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Rehearse/RehearsalPlanPreview.php';

use Duo\CommandRefusalException;
use Duo\Orchestrator\ProjectionVocabulary;
use Duo\Orchestrator\RehearsalDisclosure;
use Duo\Orchestrator\RehearsalPlanPreview;

$fixtures = $argv[1] ?? '';
if ($fixtures === '') {
    fwrite(STDERR, "usage: preview-checks.php <fixture-dir>\n");
    exit(2);
}

/** @return array<mixed> */
function rp_load(string $path): array {
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        fwrite(STDERR, "could not read $path\n");
        exit(1);
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

$plan = rp_load($fixtures . '/plan.json');
$surfaces = rp_load($fixtures . '/surfaces.json');
$context = [
    'env' => 'preview',
    'source_env' => 'production',
    'branch' => 'main',
    'generated_at' => '2026-08-17T09:14:02Z',
];

// ----------------------------------------------------------- the disclosure
duo_check_same(
    'containment: unknown — not enforced in this profile; do not point this environment '
        . 'at live payment or mail credentials.',
    RehearsalDisclosure::BANNER,
    'the containment banner is MUP §2.2\'s literal sentence, byte for byte'
);
duo_check_same(
    'containment: ' . ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN
        . '; do not point this environment at live payment or mail credentials.',
    RehearsalDisclosure::BANNER,
    'the banner is composed from the shared containment basis, not a second copy of it'
);
duo_check(
    str_contains(RehearsalDisclosure::CONSEQUENCE, 'Experimental')
        && str_contains(RehearsalDisclosure::CONSEQUENCE, 'Uncertified')
        && str_contains(RehearsalDisclosure::CONSEQUENCE, 'not a qualification environment'),
    'the consequence names both words it blocks and says a rehearsal is not a qualification environment'
);
duo_check_same(
    [RehearsalDisclosure::BANNER, RehearsalDisclosure::CONSEQUENCE],
    RehearsalDisclosure::lines(),
    'the human disclosure is the banner then its consequence, always both, always in that order'
);
$block = RehearsalDisclosure::block();
duo_check_same('unknown', $block['containment'], 'the JSON disclosure block reports containment unknown');
duo_check_same(false, $block['enforced'], 'the JSON disclosure block reports enforced=false');
duo_check_same(RehearsalDisclosure::BANNER, $block['note'], 'the JSON note carries the banner verbatim');
duo_check(
    !in_array('sandboxed', [$block['containment']], true)
        && in_array('sandboxed', ProjectionVocabulary::NEVER_EMITTED, true),
    'this profile never claims a sandboxed rehearsal'
);
duo_check_same(
    true,
    RehearsalDisclosure::blocksAuthorization(['readiness' => 'Experimental', 'certification_provenance' => 'Platform-certified']),
    'an Experimental capability cannot be authorized from this rehearsal'
);
duo_check_same(
    true,
    RehearsalDisclosure::blocksAuthorization(['readiness' => 'Ready', 'certification_provenance' => 'Uncertified']),
    'an Uncertified capability cannot be authorized from this rehearsal'
);
duo_check_same(
    false,
    RehearsalDisclosure::blocksAuthorization(['readiness' => 'Ready', 'certification_provenance' => 'Platform-certified']),
    'a Ready, Platform-certified capability is not blocked by the disclosure itself'
);

// -------------------------------------------------------------- the preview
$preview = RehearsalPlanPreview::build($plan, $surfaces, $context);
duo_check_same('duo-rehearsal-preview/v1', $preview['format'], 'the preview carries its own format id');
duo_check_same(
    RehearsalDisclosure::block(),
    $preview['disclosure'],
    'the preview embeds the disclosure block rather than restating it'
);
duo_check_same(
    $preview['preview_digest'],
    RehearsalPlanPreview::digest($preview),
    'the preview digest is sha256 over everything except itself'
);
duo_check_same(
    $preview,
    RehearsalPlanPreview::build($plan, $surfaces, $context),
    'identical inputs regenerate an identical preview'
);

// The categories are the agent's own numbers, copied.
$byId = [];
foreach ($preview['categories'] as $category) {
    $byId[$category['id']] = $category;
}
duo_check_same(
    ['code', 'lifecycle', 'authored_state', 'generated_effects', 'media', 'secrets', 'environment_state', 'capabilities', 'deletions'],
    array_column($preview['categories'], 'id'),
    'every PlanCategorySummary category is reported, in the agent\'s order'
);
duo_check_same(
    $plan['category_summary']['categories'][2]['metrics'],
    $byId['authored_state']['metrics'],
    'authored_state metrics are copied verbatim from the plan, never recomputed'
);
duo_check_same(3, $byId['authored_state']['count'], 'the authored state count is the plan\'s own count');
duo_check_same(1, $byId['code']['count'], 'the code count is the plan\'s own count');

$touch = [];
foreach ($preview['touch_classes'] as $entry) {
    $touch[$entry['id']] = $entry;
}
duo_check_same(
    ['code', 'authored_state', 'generated_state', 'effects'],
    array_keys($touch),
    'the four touch classes are the product spec\'s own words: code, authored state, generated state, effects'
);
duo_check_same(
    ['code' => 1, 'lifecycle' => 1],
    $touch['code']['counts'],
    'the code touch class reports its categories separately, because overlapping facets are not summable'
);
duo_check_same(false, $preview['scope']['summable'], 'the document says its category counts are not summable');

// Scope restriction.
$ids = array_column($preview['surfaces'], 'id');
sort($ids, SORT_STRING);
duo_check_same(
    [
        'media:attachment', 'option_group:core:managed', 'option_group:woocommerce:env',
        'post_type:acme_thing', 'post_type:product', 'post_type:shop_order', 'post_type:tribe_events',
        'taxonomy:product_cat',
    ],
    $ids,
    'the surface rows are restricted to the plan\'s actual scope'
);
duo_check_same(2, $preview['scope']['surfaces_out_of_scope'], 'the two untouched surfaces are excluded and counted');
duo_check_same(10, $preview['scope']['surfaces_total'], 'the true total is reported beside the restricted list');
duo_check_same(
    ['attachment', 'plugin', 'post', 'term'],
    $preview['scope']['entity_kinds'],
    'the scope names the entity and code kinds the plan touches, read out of contained_entities'
);
duo_check_same('kind', $preview['scope']['restriction'], 'the document states that its restriction is at the kind level');

$rows = [];
foreach ($preview['surfaces'] as $row) {
    $rows[$row['id']] = $row;
}
duo_check_same(
    ["the plan touches the 'post' entity kind"],
    $rows['post_type:product']['in_scope_because'],
    'an entity-kind row names the kind that put it in scope'
);
duo_check_same(
    [
        "the plan's 'code' category is non-empty and this surface is the code lifecycle window",
        "the plan's 'lifecycle' category is non-empty and this surface is the code lifecycle window",
    ],
    $rows['option_group:core:managed']['in_scope_because'],
    'the code lifecycle window is in scope through the code/lifecycle categories, not through an entity kind'
);
duo_check_same(
    ["the plan's 'environment_state' category is non-empty and this surface is environment-bound"],
    $rows['option_group:woocommerce:env']['in_scope_because'],
    'an environment-bound surface is in scope through environment_state'
);
duo_check_same(true, $rows['post_type:tribe_events']['authorization_blocked'], 'an Experimental row is flagged unauthorizable');
duo_check_same(true, $rows['post_type:acme_thing']['authorization_blocked'], 'an Uncertified row is flagged unauthorizable');
duo_check_same(false, $rows['post_type:product']['authorization_blocked'], 'a Ready, Platform-certified row is not flagged');

// No value ever reaches the document.
$encoded = RehearsalPlanPreview::encode($preview);
duo_check(!str_contains($encoded, 'stripe_secret='), 'the preview carries no environment value');
duo_check(str_ends_with($encoded, "\n"), 'the preview encodes through Canon (pretty, LF-terminated)');

// ------------------------------------------------------------ refusal paths
duo_check_refuses(
    static fn () => RehearsalPlanPreview::build([], $surfaces, $context),
    'rehearsal_plan_incomplete',
    'an incomplete plan envelope refuses; an empty object renders CLEAN in every tolerant renderer'
);
$noSummary = $plan;
unset($noSummary['category_summary']);
duo_check_refuses(
    static fn () => RehearsalPlanPreview::build($noSummary, $surfaces, $context),
    'rehearsal_categories_unavailable',
    'a plan with no category projection refuses rather than letting the host classify detailed rows'
);
$badSummary = $plan;
$badSummary['category_summary']['categories'][0]['metrics']['count'] = 99;
duo_check_refuses(
    static fn () => RehearsalPlanPreview::build($badSummary, $surfaces, $context),
    'rehearsal_categories_unavailable',
    'a category summary whose arithmetic disagrees with itself is not a preview input'
);
duo_check_refuses(
    static fn () => RehearsalPlanPreview::build($plan, $surfaces, ['env' => 'preview']),
    'rehearsal_preview_unbuildable',
    'a preview names the environment, the source, the ref and the time, or it refuses'
);
duo_check_refuses(
    static fn () => RehearsalPlanPreview::build($plan, $surfaces, $context + ['operation' => 'deploy']),
    'rehearsal_preview_unbuildable',
    'an operation outside the projection vocabulary refuses'
);

// ------------------------------------------------------- bounded human lines
$lines = RehearsalPlanPreview::render($preview);
duo_check_same(
    RehearsalDisclosure::BANNER,
    $lines[0],
    'the containment banner is printed once, at the very top of the report'
);
duo_check_same(RehearsalDisclosure::CONSEQUENCE, $lines[1], 'the consequence follows it immediately');
duo_check_same(
    1,
    substr_count(implode("\n", $lines), RehearsalDisclosure::BANNER),
    'the standalone report carries the banner exactly once'
);
// The command prints the banner itself, first, before the provider runs, and
// renders its tail without it (T5): the page an operator reads carries the
// banner exactly once, at the top.
$tail = RehearsalPlanPreview::render($preview, RehearsalPlanPreview::DEFAULT_LIMIT, false);
duo_check(
    !str_contains(implode("\n", $tail), RehearsalDisclosure::BANNER),
    'render() without the disclosure carries no banner, so the command can print it once at the top'
);
duo_check_same(
    array_slice($lines, count(RehearsalDisclosure::lines())),
    $tail,
    'the disclosure-free render is the standalone report minus its opening disclosure, byte for byte'
);
$body = implode("\n", $lines);
duo_check(str_contains($body, 'what a release would touch'), 'the human view says what it is showing');
duo_check(str_contains($body, 'surfaces in scope: 8 of 10'), 'the human view prints the true totals beside the restricted list');
duo_check(
    !str_contains($body, 'more (use --format=json)'),
    'an uncut section prints no tail line'
);

$bounded = RehearsalPlanPreview::render($preview, 2);
$boundedBody = implode("\n", $bounded);
duo_check(
    str_contains($boundedBody, '6 more (use --format=json)'),
    'a cut section ends in an accurate `N more (use --format=json)` tail'
);
duo_check(
    str_contains($boundedBody, 'surfaces in scope: 8 of 10'),
    'the counts beside a truncated list stay the true totals'
);
duo_check_same(
    RehearsalDisclosure::BANNER,
    $bounded[0],
    'the banner survives the bound: it is printed once regardless of --limit'
);

duo_check_same(50, RehearsalPlanPreview::limitFromArgs([]), 'the default bound is 50 rows per section');
duo_check_same(5, RehearsalPlanPreview::limitFromArgs(['--limit=5']), '--limit=5 is accepted');
foreach (['--limit=0', '--limit=201', '--limit=abc', '--limit=007', '--limit='] as $bad) {
    duo_check_refuses(
        static fn () => RehearsalPlanPreview::limitFromArgs([$bad]),
        'invalid_arguments',
        "a malformed bound ($bad) refuses instead of silently defaulting"
    );
}
duo_check_refuses(
    static fn () => RehearsalPlanPreview::limitFromArgs(['--limit=5', '--limit=6']),
    'invalid_arguments',
    'a repeated --limit refuses rather than last-wins'
);
duo_check_throws(
    static fn () => RehearsalPlanPreview::render($preview, 201),
    CommandRefusalException::class,
    'a render past the ceiling refuses'
);

// Publish both projections beside the fixture so the shell suite can assert
// the banner as a LITERAL against the rendered report: MUP §2.2 fixes that
// sentence, so a reworded disclosure must fail a byte comparison somewhere
// outside the class that owns the constant.
file_put_contents($fixtures . '/render.txt', implode("\n", $lines) . "\n");
file_put_contents($fixtures . '/preview.json', RehearsalPlanPreview::encode($preview));

duo_check_summary('rehearsal preview and containment disclosure');
