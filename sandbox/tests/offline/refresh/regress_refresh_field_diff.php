<?php
declare(strict_types=1);

/*
 * Offline contract for DUO-3345's redacted field-level Refresh boundary.
 * This never boots WordPress and deliberately exercises raw source spellings
 * (including an escaped Unicode scalar) so a passing strict compile could not
 * hide a decode/re-encode rewrite.
 */

define('DUO_SPEC_VERSION', 2);
$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/cli/src/Refresh/RefreshFieldDiff.php';
require_once $root . '/cli/src/Refresh/RefreshPlan.php';

use Duo\Canon;
use Duo\Orchestrator\RefreshFieldDiff;
use Duo\Orchestrator\RefreshPlan;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) $failures++;
};
$refuses = static function (callable $operation, string $message) use ($check): void {
    try {
        $operation();
        $check(false, "$message (did not refuse)");
    } catch (Throwable) {
        $check(true, $message);
    }
};

$post = static function (string $title, string $excerpt, string $status, string $body): string {
    return "---\n{\n"
        . "  \"author\": 1.0,\n"
        . "  \"comment_status\": \"open\",\n"
        . "  \"date\": \"2024-01-01 00:00:00\",\n"
        . "  \"date_gmt\": \"2024-01-01 00:00:00\",\n"
        . "  \"excerpt\": $excerpt,\n"
        . "  \"menu_order\": 0,\n"
        . "  \"meta\": {\"opaque\": [\"do-not-leak\", 1]},\n"
        . "  \"modified\": \"2024-01-01 00:00:00\",\n"
        . "  \"modified_gmt\": \"2024-01-01 00:00:00\",\n"
        . "  \"parent\": 0,\n"
        . "  \"ping_status\": \"open\",\n"
        . "  \"slug\": \"field-one\",\n"
        . "  \"status\": $status,\n"
        . "  \"terms\": {},\n"
        . "  \"title\": $title,\n"
        . "  \"type\": \"post\",\n"
        . "  \"uuid\": \"11111111-1111-4111-8111-111111111111\"\n"
        . "}\n---\n$body";
};
$withoutModified = static function (string $content, string $modifiedGmt): string {
    $content = str_replace("  \"modified\": \"2024-01-01 00:00:00\",\n", '', $content);
    return str_replace(
        "  \"modified_gmt\": \"2024-01-01 00:00:00\",\n",
        '  "modified_gmt": "' . $modifiedGmt . "\",\n",
        $content
    );
};
$term = static function (string $name, string $description): string {
    return "{\n"
        . "  \"description\": $description,\n"
        . "  \"meta\": {\"opaque\": \"term-secret\"},\n"
        . "  \"name\": $name,\n"
        . "  \"parent\": 0,\n"
        . "  \"relationships\": [],\n"
        . "  \"slug\": \"category-one\",\n"
        . "  \"taxonomy\": \"category\",\n"
        . "  \"uuid\": \"22222222-2222-4222-8222-222222222222\"\n"
        . "}\n";
};
$row = static function (string $identity, string $type, string $path, string $content): array {
    return [
        'identity' => $identity,
        'type' => $type,
        'path' => $path,
        'hash' => hash('sha256', $content),
        'content' => $content,
    ];
};
$policy = static function (string $siteHash = 'a'): array {
    $projection = [
        'derived_post_fields' => ['post' => []],
        'format' => RefreshFieldDiff::POLICY_FORMAT,
        'manifest_hash' => str_repeat('b', 64),
        'resolved_adapters_sha256' => str_repeat('c', 64),
        'state_site_hash' => str_repeat($siteHash, 64),
    ];
    $projection['projection_hash'] = hash('sha256', Canon::encode($projection));
    return $projection;
};

$basePost = $post('"base"', '"base excerpt"', '"draft"', "shared body\n");
$productionPost = $post('"prod\\u0063tion"', '"base excerpt"', '"publish"', "shared body\n");
$branchPost = $post('"base"', '"branch excerpt"', '"private"', "shared body\n");
$baseBodyPost = $post('"body title"', '"body excerpt"', '"draft"', "base body\n");
$productionBodyPost = $post('"body title"', '"body excerpt"', '"draft"', "production body\n");
$branchBodyPost = $post('"body title"', '"body excerpt"', '"draft"', "branch body\n");
$baseTerm = $term('"base term"', '"base description"');
$productionTerm = $term('"production term"', '"base description"');
$branchTerm = $term('"base term"', '"branch description"');
$baseMenu = "{\"items\":[],\"name\":\"base-menu\",\"slug\":\"menu\",\"uuid\":\"33333333-3333-4333-8333-333333333333\"}\n";
$productionMenu = "{\"items\":[],\"name\":\"production-menu\",\"slug\":\"menu\",\"uuid\":\"33333333-3333-4333-8333-333333333333\"}\n";
$branchMenu = "{\"items\":[],\"name\":\"branch-menu\",\"slug\":\"menu\",\"uuid\":\"33333333-3333-4333-8333-333333333333\"}\n";

$plan = [
    'format' => 'duo-refresh-plan/v1',
    'plan_hash' => hash('sha256', 'field-diff-plan'),
    'context' => ['production_snapshot_hash' => hash('sha256', 'field-diff-production')],
    'entries' => [
        [
            'id' => 'post:11111111-1111-4111-8111-111111111111',
            'identity' => '11111111-1111-4111-8111-111111111111',
            'type' => 'post', 'category' => 'conflicting', 'in_scope' => true,
            'versions' => [
                'base' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/field-one.md', $basePost),
                'production' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/field-one.md', $productionPost),
                'branch' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/field-one.md', $branchPost),
            ],
            'selected_source' => null, 'selected' => null,
        ],
        [
            'id' => 'post:44444444-4444-4444-8444-444444444444',
            'identity' => '44444444-4444-4444-8444-444444444444',
            'type' => 'post', 'category' => 'conflicting', 'in_scope' => true,
            'versions' => [
                'base' => $row('44444444-4444-4444-8444-444444444444', 'post', 'posts/post/body.md', $baseBodyPost),
                'production' => $row('44444444-4444-4444-8444-444444444444', 'post', 'posts/post/body.md', $productionBodyPost),
                'branch' => $row('44444444-4444-4444-8444-444444444444', 'post', 'posts/post/body.md', $branchBodyPost),
            ],
            'selected_source' => null, 'selected' => null,
        ],
        [
            'id' => 'term:22222222-2222-4222-8222-222222222222',
            'identity' => '22222222-2222-4222-8222-222222222222',
            'type' => 'term', 'category' => 'conflicting', 'in_scope' => true,
            'versions' => [
                'base' => $row('22222222-2222-4222-8222-222222222222', 'term', 'terms/category/category-one.json', $baseTerm),
                'production' => $row('22222222-2222-4222-8222-222222222222', 'term', 'terms/category/category-one.json', $productionTerm),
                'branch' => $row('22222222-2222-4222-8222-222222222222', 'term', 'terms/category/category-one.json', $branchTerm),
            ],
            'selected_source' => null, 'selected' => null,
        ],
        [
            'id' => 'menu:33333333-3333-4333-8333-333333333333',
            'identity' => '33333333-3333-4333-8333-333333333333',
            'type' => 'menu', 'category' => 'conflicting', 'in_scope' => true,
            'versions' => [
                'base' => $row('33333333-3333-4333-8333-333333333333', 'menu', 'menus/menu.json', $baseMenu),
                'production' => $row('33333333-3333-4333-8333-333333333333', 'menu', 'menus/menu.json', $productionMenu),
                'branch' => $row('33333333-3333-4333-8333-333333333333', 'menu', 'menus/menu.json', $branchMenu),
            ],
            'selected_source' => null, 'selected' => null,
        ],
    ],
];

$projected = RefreshFieldDiff::project($plan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$diff = $projected['diff'];
$bundle = $projected['bundle'];
$check((static function () use ($bundle, $policy): bool {
    RefreshFieldDiff::assertCandidatePolicy($bundle, $policy());
    return true;
})(), 'candidate policy evidence must exactly match the reviewed branch/production projection');
$refuses(static fn() => RefreshFieldDiff::assertCandidatePolicy($bundle, $policy('d')),
    'candidate policy skew refuses before field materialization');
$check(($diff['format'] ?? null) === RefreshFieldDiff::DIFF_FORMAT
    && preg_match('/^[a-f0-9]{64}$/', (string) ($diff['diff_hash'] ?? '')) === 1,
    'projects a versioned, content-addressed redacted field diff');
$check(($diff['summary']['atomic_records'] ?? null) === 2
    && ($diff['summary']['records'] ?? null) === 4,
    'keeps opaque menu and changed raw post body content as atomic records alongside decomposed records');
$encodedDiff = Canon::encode($diff);
$check(!str_contains($encodedDiff, 'do-not-leak') && !str_contains($encodedDiff, 'term-secret')
    && !str_contains($encodedDiff, 'production body') && !str_contains($encodedDiff, 'field-one')
    && !str_contains($encodedDiff, 'base_sha256') && !str_contains($encodedDiff, 'branch_sha256')
    && ($diff['authority'] ?? null) === false && ($diff['redaction'] ?? null) === 'values_omitted',
    'public diff is value-free, withholds per-value hashes, and declares its non-authority/redaction');
$containsOnlyRedactedPublicData = static function (mixed $value) use (&$containsOnlyRedactedPublicData): bool {
    $forbiddenKeys = ['content', 'path', 'identity', 'id', 'value', 'values', 'spans', 'base_sha256', 'branch_sha256', 'production_sha256'];
    $forbiddenLiterals = ['do-not-leak', 'term-secret', 'production body', 'branch excerpt', 'prod\\u0063tion', 'field-one'];
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array($key, $forbiddenKeys, true)) return false;
            if (!$containsOnlyRedactedPublicData($child)) return false;
        }
        return true;
    }
    if (!is_string($value)) return true;
    foreach ($forbiddenLiterals as $literal) if (str_contains($value, $literal)) return false;
    return true;
};
$check($containsOnlyRedactedPublicData($diff),
    'every nested public diff key and leaf stays redacted rather than only the rendered top-level JSON');
$relations = [];
foreach ($diff['records'] as $record) foreach ($record['changes'] as $change) $relations[] = $change['relation'] ?? null;
$check(count(array_filter($relations, static fn(mixed $relation): bool => is_array($relation)
    && ($relation['base'] ?? null) === 'present'
    && ($relation['branch'] ?? null) === 'present'
    && ($relation['production'] ?? null) === 'present'
    && in_array($relation['branch_vs_base'] ?? null, ['same', 'different'], true)
    && in_array($relation['production_vs_base'] ?? null, ['same', 'different'], true)
    && in_array($relation['branch_vs_production'] ?? null, ['same', 'different'], true))) === count($relations),
    'every public change carries only closed B/P/W presence and equality relations, never literals or hashes');
$labels = [];
foreach ($diff['records'] as $record) foreach ($record['changes'] as $change) $labels[] = $change['field'];
$check(in_array('post.title', $labels, true) && in_array('post.publication', $labels, true)
    && in_array('term.name', $labels, true) && in_array('record', $labels, true),
    'diff uses closed understandable WordPress field labels and record scope');
$entities = array_values(array_unique(array_column($diff['records'], 'entity')));
sort($entities, SORT_STRING);
$check($entities === ['menu', 'post', 'term'],
    'each redacted record carries a closed WordPress entity classification');

$attachmentPlan = $plan;
foreach (['base', 'production', 'branch'] as $role) {
    $attachmentPlan['entries'][0]['versions'][$role]['path'] = 'posts/attachment/field-one.md';
    $attachmentPlan['entries'][0]['versions'][$role]['content'] = str_replace(
        '"type": "post"',
        '"type": "attachment"',
        $attachmentPlan['entries'][0]['versions'][$role]['content']
    );
}
$attachmentDiff = RefreshFieldDiff::project($attachmentPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
])['diff'];
$attachmentRows = array_values(array_filter(
    $attachmentDiff['records'],
    static fn(array $record): bool => ($record['entity'] ?? null) === 'attachment'
));
$check(count($attachmentRows) === 1
    && ($attachmentRows[0]['mode'] ?? null) === 'record'
    && ($attachmentRows[0]['reason'] ?? null) === 'attachment_media',
    'media-bound attachment conflicts retain the closed attachment entity and record-atomic authority');

$legacyOptionalGroupPlan = $plan;
$legacyOptionalGroupPlan['plan_hash'] = hash('sha256', 'legacy-optional-modified-group');
$legacyOptionalGroupPlan['entries'] = [$plan['entries'][0]];
foreach (['base', 'production', 'branch'] as $role) {
    $content = $withoutModified(
        $legacyOptionalGroupPlan['entries'][0]['versions'][$role]['content'],
        '2024-01-01 00:00:00'
    );
    $legacyOptionalGroupPlan['entries'][0]['versions'][$role]['content'] = $content;
    $legacyOptionalGroupPlan['entries'][0]['versions'][$role]['hash'] = hash('sha256', $content);
}
$legacyOptionalGroupDiff = RefreshFieldDiff::project($legacyOptionalGroupPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
])['diff'];
$legacyOptionalGroupRecord = $legacyOptionalGroupDiff['records'][0] ?? [];
$check(($legacyOptionalGroupRecord['mode'] ?? null) === 'fields'
    && ($legacyOptionalGroupRecord['reason'] ?? null) === 'eligible_engine_fields',
    'an identically absent optional coupled member is safely omitted when every retained member is unchanged');

$legacyDivergentGroupPlan = $legacyOptionalGroupPlan;
$legacyDivergentGroupPlan['plan_hash'] = hash('sha256', 'legacy-divergent-modified-group');
foreach ([
    'base' => '2024-01-01 00:00:00',
    'production' => '2024-02-01 00:00:00',
    'branch' => '2024-03-01 00:00:00',
] as $role => $modifiedGmt) {
    $content = $withoutModified($plan['entries'][0]['versions'][$role]['content'], $modifiedGmt);
    $legacyDivergentGroupPlan['entries'][0]['versions'][$role]['content'] = $content;
    $legacyDivergentGroupPlan['entries'][0]['versions'][$role]['hash'] = hash('sha256', $content);
}
$legacyDivergentGroupDiff = RefreshFieldDiff::project($legacyDivergentGroupPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
])['diff'];
$legacyDivergentGroupRecord = $legacyDivergentGroupDiff['records'][0] ?? [];
$check(($legacyDivergentGroupRecord['mode'] ?? null) === 'record'
    && ($legacyDivergentGroupRecord['reason'] ?? null) === 'unsupported_document_shape',
    'a divergent retained member of a partially absent coupled group stays record-atomic');

$singlePostPlan = static function (string $label, string $base, string $production, string $branch) use ($plan): array {
    $out = $plan;
    $out['plan_hash'] = hash('sha256', $label);
    $out['entries'] = [$plan['entries'][0]];
    foreach (['base' => $base, 'production' => $production, 'branch' => $branch] as $role => $content) {
        $out['entries'][0]['versions'][$role]['content'] = $content;
        $out['entries'][0]['versions'][$role]['hash'] = hash('sha256', $content);
    }
    return $out;
};
$publicFields = static function (array $diff): array {
    $out = [];
    foreach ($diff['records'] as $record) {
        foreach ($record['changes'] as $change) $out[] = $change['field'];
    }
    return $out;
};

$escapedBase = $post('"base"', '"base excerpt"', '"draft"', "shared body\n");
$escapedProduction = $post('"\\u0062ase"', '"base excerpt"', '"draft"', "shared body\n");
$escapedBranch = $post('"base"', '"branch excerpt"', '"draft"', "shared body\n");
$escapedPlan = $singlePostPlan('scalar-escaped-token-equality', $escapedBase, $escapedProduction, $escapedBranch);
$escapedProjection = RefreshFieldDiff::project($escapedPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$escapedApplied = RefreshFieldDiff::apply(
    $escapedPlan,
    $escapedProjection['bundle'],
    RefreshFieldDiff::resolution($escapedProjection['diff'], [])
);
$check(!in_array('post.title', $publicFields($escapedProjection['diff']), true)
    && in_array('post.excerpt', $publicFields($escapedProjection['diff']), true)
    && ($escapedApplied['entries'][0]['selected']['content'] ?? null) === $escapedBranch,
    'escaped scalar formatting equal to the canonical value is omitted and leaves branch bytes untouched');

$withAuthor = static function (string $content, string $raw): string {
    return str_replace('  "author": 1.0,', '  "author": ' . $raw . ',', $content);
};
$numericBase = $withAuthor($post('"base"', '"base excerpt"', '"draft"', "shared body\n"), '1');
$numericProduction = $withAuthor($post('"base"', '"base excerpt"', '"draft"', "shared body\n"), '1.0');
$numericBranch = $withAuthor($post('"branch"', '"base excerpt"', '"draft"', "shared body\n"), '1');
$numericPlan = $singlePostPlan('scalar-numeric-token-equality', $numericBase, $numericProduction, $numericBranch);
$numericProjection = RefreshFieldDiff::project($numericPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$numericApplied = RefreshFieldDiff::apply(
    $numericPlan,
    $numericProjection['bundle'],
    RefreshFieldDiff::resolution($numericProjection['diff'], [])
);
$check(!in_array('post.author', $publicFields($numericProjection['diff']), true)
    && in_array('post.title', $publicFields($numericProjection['diff']), true)
    && ($numericApplied['entries'][0]['selected']['content'] ?? null) === $numericBranch,
    'numeric scalar spellings such as 1 and 1.0 compare canonically while preserving branch token bytes');

$objectBase = $withAuthor($post('"base"', '"base excerpt"', '"draft"', "shared body\n"), '{"v":[1]}');
$objectProduction = $withAuthor($post('"base"', '"base excerpt"', '"draft"', "shared body\n"), '{"v":[2]}');
$objectBranch = $withAuthor($post('"branch"', '"base excerpt"', '"draft"', "shared body\n"), '{"v":[1]}');
$objectPlan = $singlePostPlan('opaque-author-container', $objectBase, $objectProduction, $objectBranch);
$objectProjection = RefreshFieldDiff::project($objectPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$objectRecord = $objectProjection['diff']['records'][0] ?? [];
$check(($objectRecord['mode'] ?? null) === 'record'
    && ($objectRecord['reason'] ?? null) === 'opaque_container',
    'object or list group members remain raw opaque evidence and force record-atomic resolution when changed');

$choices = [];
foreach ($diff['records'] as $record) {
    foreach ($record['changes'] as $change) {
        if (($change['category'] ?? null) !== 'conflicting') continue;
        $choices[] = [
            'choice' => $change['scope'] === 'record' ? 'theirs' : 'ours',
            'field_selector_sha256' => $change['field_selector_sha256'],
            'record_selector_sha256' => $change['record_selector_sha256'],
            'scope' => $change['scope'],
        ];
    }
}
usort($choices, static fn(array $a, array $b): int =>
    ($a['record_selector_sha256'] . ':' . $a['field_selector_sha256']) <=> ($b['record_selector_sha256'] . ':' . $b['field_selector_sha256']));
$resolution = RefreshFieldDiff::resolution($diff, $choices);
$check(($resolution['format'] ?? null) === RefreshFieldDiff::RESOLUTION_FORMAT
    && preg_match('/^[a-f0-9]{64}$/', (string) ($resolution['resolution_hash'] ?? '')) === 1,
    'resolution binds plan, production snapshot, diff, and opaque selector choices without value evidence');
$malformedDiff = $diff;
$malformedDiff['records'][0]['changes'][0]['operator_literal'] = 'do-not-leak';
unset($malformedDiff['diff_hash']);
$malformedDiff['diff_hash'] = hash('sha256', Canon::encode($malformedDiff));
$refuses(static fn() => RefreshFieldDiff::resolution($malformedDiff, []),
    'strict public diff schema rejects a rehashed nested literal before TTY or resolution use');
$forgedSelectorDiff = $diff;
foreach ($forgedSelectorDiff['records'] as &$forgedRecord) {
    if (($forgedRecord['mode'] ?? null) === 'fields') {
        $forgedRecord['changes'][0]['field_selector_sha256'] = str_repeat('0', 64);
        break;
    }
}
unset($forgedRecord, $forgedSelectorDiff['diff_hash']);
$forgedSelectorDiff['diff_hash'] = hash('sha256', Canon::encode($forgedSelectorDiff));
$refuses(static fn() => RefreshFieldDiff::validateDiff($forgedSelectorDiff),
    'strict public diff validation binds every field selector to its opaque record selector and closed label');
$forgedFieldEntityDiff = $diff;
foreach ($forgedFieldEntityDiff['records'] as &$forgedRecord) {
    if (($forgedRecord['mode'] ?? null) === 'fields' && ($forgedRecord['entity'] ?? null) === 'post') {
        $forgedRecord['entity'] = 'menu';
        break;
    }
}
unset($forgedRecord, $forgedFieldEntityDiff['diff_hash']);
$forgedFieldEntityDiff['diff_hash'] = hash('sha256', Canon::encode($forgedFieldEntityDiff));
$refuses(static fn() => RefreshFieldDiff::validateDiff($forgedFieldEntityDiff),
    'strict public diff validation refuses a rehashed fields record whose entity disagrees with its post labels');
$skewedPublicPolicyDiff = $diff;
$skewedPublicPolicyDiff['policy_projection_hashes']['production'] = str_repeat('f', 64);
unset($skewedPublicPolicyDiff['diff_hash']);
$skewedPublicPolicyDiff['diff_hash'] = hash('sha256', Canon::encode($skewedPublicPolicyDiff));
$refuses(static fn() => RefreshFieldDiff::validateDiff($skewedPublicPolicyDiff),
    'strict public diff validation refuses persisted branch/production policy-evidence skew');
$interactiveIn = fopen('php://temp', 'r+');
$interactiveOut = fopen('php://temp', 'r+');
fwrite($interactiveIn, "invalid\nb\np\np\n");
rewind($interactiveIn);
$interactiveResolution = RefreshFieldDiff::interactiveResolution($diff, $interactiveIn, $interactiveOut);
rewind($interactiveOut);
$interactiveTranscript = (string) stream_get_contents($interactiveOut);
$check(is_array($interactiveResolution)
    && !str_contains($interactiveTranscript, 'do-not-leak')
    && !str_contains($interactiveTranscript, 'production body')
    && !str_contains($interactiveTranscript, 'base-menu')
    && str_contains($interactiveTranscript, 'menu')
    && str_contains($interactiveTranscript, 'opaque_record_type')
    && str_contains($interactiveTranscript, 'post')
    && str_contains($interactiveTranscript, 'body_changed'),
    'injected interactive input shows closed atomic entity/reason context without revealing source literals');
$cancelIn = fopen('php://temp', 'r+');
$cancelOut = fopen('php://temp', 'r+');
$check(RefreshFieldDiff::interactiveResolution($diff, $cancelIn, $cancelOut) === null,
    'interactive EOF cancels all choices before materialization');

// The public projection deliberately contains only conflicting plan rows.
// Local CLI interaction gets a separate in-memory presentation so it can show
// all automatic plan decisions and one bounded authored/path hint without
// ever changing a diff, resolution, journal, or receipt contract.
$localPresentationPlan = $plan;
$localPresentationPlan['plan_hash'] = hash('sha256', 'interactive-local-presentation');
$localConflictBase = $post('"TTY Base"', '"base excerpt"', '"draft"', "shared body\n");
$localConflictProduction = $post('"TTY Production"', '"base excerpt"', '"publish"', "shared body\n");
$localConflictBranch = $post('"TTY Branch\\u001bLabel"', '"branch excerpt"', '"private"', "shared body\n");
$localPresentationPlan['entries'] = [[
    'id' => 'post:11111111-1111-4111-8111-111111111111',
    'identity' => '11111111-1111-4111-8111-111111111111',
    'type' => 'post', 'category' => 'conflicting', 'in_scope' => true,
    'versions' => [
        'base' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/tty-conflict.md', $localConflictBase),
        'production' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/tty-conflict.md', $localConflictProduction),
        'branch' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/tty-conflict.md', $localConflictBranch),
    ],
    'selected_source' => null, 'selected' => null,
], [
    'id' => 'post:55555555-5555-4555-8555-555555555555',
    'identity' => '55555555-5555-4555-8555-555555555555',
    'type' => 'post', 'category' => 'production-only', 'in_scope' => true,
    'versions' => [
        'base' => $row('55555555-5555-4555-8555-555555555555', 'post', 'posts/post/tty-production.md', $post('"auto base"', '"base excerpt"', '"draft"', "shared body\n")),
        'production' => $row('55555555-5555-4555-8555-555555555555', 'post', 'posts/post/tty-production.md', $post('"TTY Production\\u001bLabel"', '"base excerpt"', '"draft"', "shared body\n")),
        'branch' => $row('55555555-5555-4555-8555-555555555555', 'post', 'posts/post/tty-production.md', $post('"auto base"', '"base excerpt"', '"draft"', "shared body\n")),
    ],
    'selected_source' => 'production', 'selected' => null,
], [
    'id' => 'term:66666666-6666-4666-8666-666666666666',
    'identity' => '66666666-6666-4666-8666-666666666666',
    'type' => 'term', 'category' => 'branch-only', 'in_scope' => true,
    'versions' => [
        'base' => $row('66666666-6666-4666-8666-666666666666', 'term', 'terms/category/tty-branch.json', $term('"auto base"', '"base description"')),
        'production' => $row('66666666-6666-4666-8666-666666666666', 'term', 'terms/category/tty-branch.json', $term('"auto base"', '"base description"')),
        'branch' => $row('66666666-6666-4666-8666-666666666666', 'term', 'terms/category/tty-branch.json', $term('"TTY Branch\\u007fLabel"', '"base description"')),
    ],
    'selected_source' => 'branch', 'selected' => null,
], [
    'id' => 'sidebar:tty-fallback',
    'identity' => 'tty-fallback',
    'type' => 'sidebar', 'category' => 'compatible', 'in_scope' => true,
    'versions' => [
        'base' => $row('tty-fallback', 'sidebar', "sidebars/TTY\x1b\x7fFallback.json", "{\"widgets\":\"base\"}\n"),
        'production' => $row('tty-fallback', 'sidebar', "sidebars/TTY\x1b\x7fFallback.json", "{\"widgets\":\"same\"}\n"),
        'branch' => $row('tty-fallback', 'sidebar', "sidebars/TTY\x1b\x7fFallback.json", "{\"widgets\":\"same\"}\n"),
    ],
    'selected_source' => 'branch', 'selected' => null,
]];
foreach ([1 => 'production', 2 => 'branch', 3 => 'branch'] as $entryIndex => $selectedRole) {
    $localPresentationPlan['entries'][$entryIndex]['selected'] =
        $localPresentationPlan['entries'][$entryIndex]['versions'][$selectedRole];
}
unset($localPresentationPlan['plan_hash']);
$localPresentationPlan = RefreshPlan::normalizePlan($localPresentationPlan);
$localPresentationProjection = RefreshFieldDiff::project($localPresentationPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$localPresentation = RefreshFieldDiff::interactivePresentation(
    $localPresentationPlan,
    $localPresentationProjection['diff'],
    $localPresentationProjection['bundle']
);
$localEntriesById = [];
foreach ($localPresentationPlan['entries'] as $entry) $localEntriesById[$entry['id']] = $entry;
$check(
    RefreshFieldDiff::localPlanEntryLabel($localEntriesById['post:11111111-1111-4111-8111-111111111111']) === 'TTY Branch Label'
        && RefreshFieldDiff::localPlanEntryLabel($localEntriesById['sidebar:tty-fallback']) === 'path:sidebars/TTY Fallback.json',
    'ordinary refresh can reuse sanitized local WordPress labels without widening public field-diff data'
);
$check(
    RefreshFieldDiff::localPlanEntryLabel([
        'category' => 'production-only',
        'versions' => [
            'branch' => $row('branch-post', 'post', 'posts/post/branch.md', $post('"Branch title"', '"excerpt"', '"draft"', "body\n")),
            'production' => $row('production-post', 'post', 'posts/post/production.md', $post('"Production title"', '"excerpt"', '"draft"', "body\n")),
            'base' => $row('base-post', 'post', 'posts/post/base.md', $post('"Base title"', '"excerpt"', '"draft"', "body\n")),
        ],
    ]) === 'Production title'
        && RefreshFieldDiff::localPlanEntryLabel([
            'versions' => [
                'branch' => $row('branch-menu', 'menu', 'menus/branch.json', "{\"name\":\"Branch menu\"}\n"),
                'production' => $row('production-menu', 'menu', 'menus/production.json', "{\"name\":\"Production menu\"}\n"),
            ],
        ]) === 'Branch menu'
        && RefreshFieldDiff::localPlanEntryLabel([
            'versions' => [
                'base' => $row('base-term', 'term', 'terms/category/base.json', $term('"Base category"', '"description"')),
            ],
        ]) === 'Base category'
        && RefreshFieldDiff::localPlanEntryLabel(['versions' => 'malformed']) === '',
    'ordinary labels preserve branch-production-base precedence and reject malformed or empty candidates'
);
$localPresentationIn = fopen('php://temp', 'r+');
$localPresentationOut = fopen('php://temp', 'r+');
fwrite($localPresentationIn, "q\n");
rewind($localPresentationIn);
$localPresentationCancelled = RefreshFieldDiff::interactiveResolution(
    $localPresentationProjection['diff'],
    $localPresentationIn,
    $localPresentationOut,
    $localPresentation
);
rewind($localPresentationOut);
$localPresentationTranscript = (string) stream_get_contents($localPresentationOut);
$localPresentationPublic = Canon::encode($localPresentationProjection['diff']);
$check($localPresentationCancelled === null
    && count($localPresentation['auto'] ?? []) === 3
    && str_contains($localPresentationTranscript, 'auto=production')
    && substr_count($localPresentationTranscript, 'auto=branch') >= 2
    && str_contains($localPresentationTranscript, 'post')
    && str_contains($localPresentationTranscript, 'term')
    && str_contains($localPresentationTranscript, 'sidebar')
    && str_contains($localPresentationTranscript, 'label="TTY Branch Label"')
    && str_contains($localPresentationTranscript, 'label="TTY Production Label"')
    && str_contains($localPresentationTranscript, 'label="path:sidebars/TTY Fallback.json"')
    && !str_contains($localPresentationTranscript, "\x1b")
    && !str_contains($localPresentationTranscript, "\x7f")
    && !str_contains($localPresentationPublic, 'TTY Branch')
    && !str_contains($localPresentationPublic, 'TTY Production')
    && !str_contains($localPresentationPublic, 'TTY Fallback')
    && !str_contains(Canon::encode(RefreshFieldDiff::resolution($localPresentationProjection['diff'], array_map(
        static fn(array $change): array => [
            'choice' => 'ours',
            'field_selector_sha256' => $change['field_selector_sha256'],
            'record_selector_sha256' => $change['record_selector_sha256'],
            'scope' => $change['scope'],
        ],
        array_values(array_filter(array_merge(...array_map(
            static fn(array $record): array => $record['changes'],
            $localPresentationProjection['diff']['records']
        )), static fn(array $change): bool => ($change['category'] ?? null) === 'conflicting'))
    ))), 'TTY Branch'),
    'private interactive presentation previews every automatic plan record and sanitized local labels without adding them to public contracts');
$malformedPresentation = $localPresentation;
foreach ($malformedPresentation['labels'] as $selector => $label) {
    $malformedPresentation['labels'][$selector] = "unsafe\x1b";
    break;
}
$refuses(static fn() => RefreshFieldDiff::interactiveResolution(
    $localPresentationProjection['diff'], fopen('php://temp', 'r+'), fopen('php://temp', 'r+'), $malformedPresentation
), 'local presentation rejects arbitrary terminal text before rendering a prompt');
$inconsistentAutomaticPlan = $localPresentationPlan;
$inconsistentAutomaticPlan['entries'][1]['selected_source'] = 'branch';
$refuses(static fn() => RefreshFieldDiff::interactivePresentation(
    $inconsistentAutomaticPlan,
    $localPresentationProjection['diff'],
    $localPresentationProjection['bundle']
), 'local presentation refuses an automatic outcome that disagrees with the prepared plan selection');
$staleAutomaticPlan = $localPresentationPlan;
$staleAutomaticPlan['entries'][1]['versions']['production']['content'] = str_replace(
    'TTY Production\\u001bLabel',
    'FORGED LOCAL LABEL',
    $staleAutomaticPlan['entries'][1]['versions']['production']['content']
);
$staleAutomaticPlan['entries'][1]['selected'] = $staleAutomaticPlan['entries'][1]['versions']['production'];
$refuses(static fn() => RefreshFieldDiff::interactivePresentation(
    $staleAutomaticPlan,
    $localPresentationProjection['diff'],
    $localPresentationProjection['bundle']
), 'local presentation recomputes the complete plan hash before reading an automatic-row label');

$autoPlan = $plan;
$autoPlan['plan_hash'] = hash('sha256', 'interactive-auto-fields');
$autoPlan['entries'] = [$plan['entries'][0]];
$autoBase = $post('"base auto title"', '"base auto excerpt"', '"draft"', "shared body\n");
$autoProduction = $post('"prod\\u002dauto"', '"base auto excerpt"', '"draft"', "shared body\n");
$autoBranch = $post('"base auto title"', '"branch\\u002dauto"', '"draft"', "shared body\n");
$autoPlan['entries'][0]['versions'] = [
    'base' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/field-one.md', $autoBase),
    'production' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/field-one.md', $autoProduction),
    'branch' => $row('11111111-1111-4111-8111-111111111111', 'post', 'posts/post/field-one.md', $autoBranch),
];
$autoProjection = RefreshFieldDiff::project($autoPlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$autoIn = fopen('php://temp', 'r+');
$autoOut = fopen('php://temp', 'r+');
$autoResolution = RefreshFieldDiff::interactiveResolution($autoProjection['diff'], $autoIn, $autoOut);
rewind($autoOut);
$autoTranscript = (string) stream_get_contents($autoOut);
$autoApplied = is_array($autoResolution)
    ? RefreshFieldDiff::apply($autoPlan, $autoProjection['bundle'], $autoResolution)
    : [];
$autoRecord = $autoProjection['diff']['records'][0] ?? [];
$autoContent = (string) ($autoApplied['entries'][0]['selected']['content'] ?? '');
$check(is_array($autoResolution) && ($autoResolution['choices'] ?? null) === []
    && str_contains($autoTranscript, (string) ($autoRecord['record_selector_sha256'] ?? ''))
    && str_contains($autoTranscript, 'post.title production-only eligible_engine_fields')
    && str_contains($autoTranscript, 'post.excerpt branch-only eligible_engine_fields')
    && str_contains($autoTranscript, 'auto=production')
    && str_contains($autoTranscript, 'auto=branch')
    && str_contains($autoTranscript, '0 manual choices; continuing')
    && !str_contains($autoTranscript, 'prod\\u002dauto')
    && !str_contains($autoTranscript, 'branch\\u002dauto')
    && str_contains($autoContent, '"title": "prod\\u002dauto"')
    && str_contains($autoContent, '"excerpt": "branch\\u002dauto"'),
    'interactive preview reports disjoint automatic field decisions without values and materializes their exact source bytes');

$compatibleBase = $post('"base title"', '"base excerpt"', '"draft"', "shared body\n");
$compatibleProduction = $post('"reviewed\\u002dtitle"', '"base excerpt"', '"draft"', "shared body\n");
$compatibleBranch = $post('"reviewed-title"', '"base excerpt"', '"draft"', "shared body\n");
$compatiblePlan = $singlePostPlan(
    'interactive-compatible-branch-scaffold',
    $compatibleBase,
    $compatibleProduction,
    $compatibleBranch
);
$compatibleProjection = RefreshFieldDiff::project($compatiblePlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]);
$compatibleIn = fopen('php://temp', 'r+');
$compatibleOut = fopen('php://temp', 'r+');
$compatibleResolution = RefreshFieldDiff::interactiveResolution(
    $compatibleProjection['diff'],
    $compatibleIn,
    $compatibleOut
);
rewind($compatibleOut);
$compatibleTranscript = (string) stream_get_contents($compatibleOut);
$compatibleApplied = is_array($compatibleResolution)
    ? RefreshFieldDiff::apply($compatiblePlan, $compatibleProjection['bundle'], $compatibleResolution)
    : [];
$check(is_array($compatibleResolution) && ($compatibleResolution['choices'] ?? null) === []
    && str_contains($compatibleTranscript, 'post.title compatible eligible_engine_fields')
    && str_contains($compatibleTranscript, 'auto=branch')
    && !str_contains($compatibleTranscript, 'auto=production')
    && !str_contains($compatibleTranscript, 'reviewed\\u002dtitle')
    && !str_contains($compatibleTranscript, 'reviewed-title')
    && ($compatibleApplied['entries'][0]['selected']['content'] ?? null) === $compatibleBranch,
    'semantically compatible production/branch scalar spellings preview branch and preserve its exact bytes');

$applied = RefreshFieldDiff::apply($plan, $bundle, $resolution);
$mergedPost = (string) $applied['entries'][0]['selected']['content'];
$mergedTerm = (string) $applied['entries'][2]['selected']['content'];
$check(str_contains($mergedPost, '"title": "prod\\u0063tion"')
    && str_contains($mergedPost, '"excerpt": "branch excerpt"')
    && str_contains($mergedPost, '"status": "private"')
    && str_ends_with($mergedPost, "shared body\n")
    && !array_key_exists('hash', $applied['entries'][0]['selected']),
    'raw branch scaffold composes exact selected spans without claiming a false semantic hash');
$check(str_contains($mergedTerm, '"name": "production term"')
    && str_contains($mergedTerm, '"description": "branch description"'),
    'term scalar leaves auto-compose without decoding or re-encoding opaque maps');
$check(($applied['entries'][1]['selected_source'] ?? null) === 'production'
    && ($applied['entries'][1]['selected']['content'] ?? null) === $productionBodyPost
    && ($applied['entries'][3]['selected_source'] ?? null) === 'production'
    && ($applied['entries'][3]['selected']['content'] ?? null) === $productionMenu,
    'one field-resolution list can choose atomic body and menu records alongside field conflicts');

$forgedAtomicDiff = $diff;
$forgedAtomic = false;
foreach ($forgedAtomicDiff['records'] as &$forgedRecord) {
    if (($forgedRecord['mode'] ?? null) !== 'record') {
        continue;
    }
    // These are all individually closed-schema values, and the relation is
    // internally coherent. Only a re-projection from B/P/W can prove that
    // they do not describe this actual atomic record.
    $forgedRecord['entity'] = 'sidebar';
    $forgedRecord['reason'] = 'attachment_media';
    $forgedRecord['changes'][0]['reason'] = 'attachment_media';
    $forgedRecord['changes'][0]['relation'] = [
        'base' => 'present',
        'branch' => 'present',
        'branch_vs_base' => 'same',
        'branch_vs_production' => 'different',
        'production' => 'present',
        'production_vs_base' => 'different',
    ];
    $forgedAtomic = true;
    break;
}
unset($forgedRecord, $forgedAtomicDiff['diff_hash']);
if (!$forgedAtomic) {
    $check(false, 'fixture exposes an atomic public record for closed-description binding');
} else {
    $forgedAtomicDiff['diff_hash'] = hash('sha256', Canon::encode($forgedAtomicDiff));
    $forgedAtomicBundle = $bundle;
    $forgedAtomicBundle['diff'] = $forgedAtomicDiff;
    $forgedAtomicBundle['diff_hash'] = $forgedAtomicDiff['diff_hash'];
    $forgedAtomicResolution = RefreshFieldDiff::resolution($forgedAtomicDiff, $choices);
    $refuses(static fn() => RefreshFieldDiff::apply($plan, $forgedAtomicBundle, $forgedAtomicResolution),
        'a rehashed closed atomic entity/reason/relation cannot misdescribe the verified selected record');
}

$fieldBundleSelectors = [];
foreach ($bundle['records'] as $selector => $privateRecord) {
    if (($privateRecord['mode'] ?? null) === 'fields') {
        $fieldBundleSelectors[] = $selector;
    }
}
if (count($fieldBundleSelectors) < 2) {
    $check(false, 'fixture exposes two independent field bundle records for selector binding');
} else {
    $swappedBundle = $bundle;
    [$firstFieldSelector, $secondFieldSelector] = $fieldBundleSelectors;
    $firstIndex = $swappedBundle['records'][$firstFieldSelector]['entry_index'];
    $swappedBundle['records'][$firstFieldSelector]['entry_index'] = $swappedBundle['records'][$secondFieldSelector]['entry_index'];
    $swappedBundle['records'][$secondFieldSelector]['entry_index'] = $firstIndex;
    $refuses(static fn() => RefreshFieldDiff::apply($plan, $swappedBundle, $resolution),
        'private bundle selectors cannot be retargeted by swapping conflicting plan entry indices');
}

$retaggedBundle = $bundle;
$retagged = false;
foreach ($retaggedBundle['records'] as &$privateRecord) {
    if (($privateRecord['mode'] ?? null) !== 'fields') continue;
    foreach ($privateRecord['fields'] as &$privateField) {
        if (($privateField['label'] ?? null) !== 'post.author' || ($privateField['category'] ?? null) !== 'unchanged') {
            continue;
        }
        $privateField['category'] = 'production-only';
        $privateField['spans']['branch']['author']['start'] = 0;
        $retagged = true;
        break 2;
    }
}
unset($privateRecord, $privateField);
if (!$retagged) {
    $check(false, 'fixture exposes an unchanged private post field for retagging');
} else {
    $refuses(static fn() => RefreshFieldDiff::apply($plan, $retaggedBundle, $resolution),
        'a retagged private unchanged field/span cannot become an unreviewed automatic change');
}

$alteredSpanBundle = $bundle;
$alteredSpan = false;
foreach ($alteredSpanBundle['records'] as &$privateRecord) {
    if (($privateRecord['mode'] ?? null) !== 'fields') continue;
    foreach ($privateRecord['fields'] as &$privateField) {
        if (($privateField['label'] ?? null) !== 'post.title' || ($privateField['category'] ?? null) === 'unchanged') {
            continue;
        }
        $privateField['spans']['branch']['title']['start'] = 0;
        $alteredSpan = true;
        break 2;
    }
}
unset($privateRecord, $privateField);
if (!$alteredSpan) {
    $check(false, 'fixture exposes a changed private post title span');
} else {
    $refuses(static fn() => RefreshFieldDiff::apply($plan, $alteredSpanBundle, $resolution),
        'a public field label cannot use a forged private source span');
}

$missing = $resolution;
array_pop($missing['choices']);
unset($missing['resolution_hash']);
$refuses(static fn() => RefreshFieldDiff::resolution($diff, $missing['choices']),
    'incomplete field/record choice list refuses');
$stale = $resolution;
$stale['choices'][0]['field_selector_sha256'] = str_repeat('0', 64);
$refuses(static fn() => RefreshFieldDiff::normalizeResolution($stale, $diff),
    'stale field selector refuses before any materialization');
$preHashResolution = $resolution;
unset($preHashResolution['resolution_hash']);
$refuses(static fn() => RefreshFieldDiff::normalizeResolution($preHashResolution, $diff),
    'public resolution normalization refuses an unhashed pre-contract');
$refuses(static fn() => RefreshFieldDiff::apply($plan, $bundle, $preHashResolution),
    'materialization refuses an unhashed resolution before reading private spans');
$scoped = $plan;
$scoped['context']['scope_contract'] = ['format' => 'duo-scope-contract/v1'];
$refuses(static fn() => RefreshFieldDiff::project($scoped, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]), 'scoped plans refuse field-level projection entirely');
$malformedScoped = $plan;
$malformedScoped['context']['scope_contract'] = true;
$refuses(static fn() => RefreshFieldDiff::project($malformedScoped, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]), 'any scope-contract marker refuses field-level projection rather than treating it as unscoped');
$refuses(static fn() => RefreshFieldDiff::project($plan, [
    'base' => $policy(), 'production' => $policy('d'), 'branch' => $policy(),
]), 'unequal production/branch policy evidence refuses field mode');
$noncanonicalPolicy = $policy();
$noncanonicalPolicy['derived_post_fields'] = ['z_type' => [], 'post' => []];
unset($noncanonicalPolicy['projection_hash']);
$noncanonicalPolicy['projection_hash'] = hash('sha256', Canon::encode($noncanonicalPolicy));
$refuses(static fn() => RefreshFieldDiff::normalizePolicyProjection($noncanonicalPolicy),
    'policy projections require sorted canonical post-type and derived-field lists');
$absencePlan = $plan;
unset($absencePlan['entries'][3]['versions']['production']);
$refuses(static fn() => RefreshFieldDiff::project($absencePlan, [
    'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
]), 'field mode refuses a live-record absence before publishing a diff or accepting a resolution');
$impossibleAbsentRelation = $diff;
foreach ($impossibleAbsentRelation['records'] as &$absentRecord) {
    if (($absentRecord['mode'] ?? null) === 'record') {
        $absentRecord['changes'][0]['relation'] = [
            'base' => 'absent',
            'branch' => 'present',
            'branch_vs_base' => 'different',
            'branch_vs_production' => 'different',
            'production' => 'absent',
            'production_vs_base' => 'different',
        ];
        break;
    }
}
unset($absentRecord, $impossibleAbsentRelation['diff_hash']);
$impossibleAbsentRelation['diff_hash'] = hash('sha256', Canon::encode($impossibleAbsentRelation));
$refuses(static fn() => RefreshFieldDiff::validateDiff($impossibleAbsentRelation),
    'strict public relation validation requires two absent roles to be equal');
$nonTransitiveAtomicRelation = $diff;
foreach ($nonTransitiveAtomicRelation['records'] as &$atomicRecord) {
    if (($atomicRecord['mode'] ?? null) === 'record') {
        $atomicRecord['changes'][0]['relation'] = [
            'base' => 'present',
            'branch' => 'present',
            'branch_vs_base' => 'same',
            'branch_vs_production' => 'different',
            'production' => 'present',
            'production_vs_base' => 'same',
        ];
        break;
    }
}
unset($atomicRecord, $nonTransitiveAtomicRelation['diff_hash']);
$nonTransitiveAtomicRelation['diff_hash'] = hash('sha256', Canon::encode($nonTransitiveAtomicRelation));
$refuses(static fn() => RefreshFieldDiff::validateDiff($nonTransitiveAtomicRelation),
    'strict public relation validation rejects a rehashed non-transitive atomic equality relation');

$fieldSnapshot = static function (string $content, string $revision) use ($row): array {
    $identity = '11111111-1111-4111-8111-111111111111';
    return [
        'format' => 'duo-refresh-git/v1',
        'records' => [$identity => $row($identity, 'post', 'posts/post/field-one.md', $content)],
        'deletions' => [],
        'media' => [],
        'policy' => ['site_hash' => str_repeat('a', 64), 'manifest_hash' => str_repeat('b', 64), 'resolved_adapters' => []],
        'completed_code' => null,
        'repository' => ['artifact_hash' => str_repeat('c', 64), 'revision_hash' => $revision, 'code_revision' => null],
    ];
};
$materializePlan = RefreshPlan::plan(
    $fieldSnapshot($basePost, str_repeat('1', 64)),
    $fieldSnapshot($productionPost, str_repeat('2', 64)),
    $fieldSnapshot($branchPost, str_repeat('3', 64)),
    [
        'base_commit' => str_repeat('1', 40),
        'branch_commit' => str_repeat('3', 40),
        'production_commit' => str_repeat('2', 40),
        'production_snapshot_hash' => hash('sha256', 'materialize-field-snapshot'),
    ]
);
$materializeProjection = RefreshPlan::fieldDiff($materializePlan,
    ['field_diff_policy' => $policy()],
    ['field_diff_policy' => $policy()],
    ['field_diff_policy' => $policy()]
);
$materializeChoices = [];
foreach ($materializeProjection['diff']['records'] as $record) {
    foreach ($record['changes'] as $change) {
        if (($change['category'] ?? null) !== 'conflicting') continue;
        $materializeChoices[] = [
            'choice' => 'ours',
            'field_selector_sha256' => $change['field_selector_sha256'],
            'record_selector_sha256' => $change['record_selector_sha256'],
            'scope' => $change['scope'],
        ];
    }
}
usort($materializeChoices, static fn(array $a, array $b): int =>
    ($a['record_selector_sha256'] . ':' . $a['field_selector_sha256']) <=> ($b['record_selector_sha256'] . ':' . $b['field_selector_sha256']));
$materializeResolution = RefreshFieldDiff::resolution($materializeProjection['diff'], $materializeChoices);
$materializeTmp = sys_get_temp_dir() . '/duo-refresh-field-materialize-' . bin2hex(random_bytes(5));
mkdir($materializeTmp, 0700, true);
file_put_contents($materializeTmp . '/.git', "gitdir: disposable\n");
mkdir($materializeTmp . '/state', 0700, true);
try {
    $materializeReceipt = RefreshPlan::materializeFieldResolved(
        $materializePlan,
        $materializeTmp,
        $materializeProjection['bundle'],
        $materializeResolution
    );
    $materializedPost = (string) file_get_contents($materializeTmp . '/state/posts/post/field-one.md');
    $check(($materializeReceipt['field_diff_hash'] ?? null) === $materializeProjection['diff']['diff_hash']
        && ($materializeReceipt['field_resolution_hash'] ?? null) === $materializeResolution['resolution_hash']
        && str_contains($materializedPost, '"title": "prod\\u0063tion"')
        && str_contains($materializedPost, '"excerpt": "branch excerpt"')
        && str_contains($materializedPost, '"status": "private"'),
        'RefreshPlan materializes a field-resolved exact-byte record and binds only public diff/resolution hashes in its receipt');
} finally {
    $removeMaterialize = static function (string $path) use (&$removeMaterialize): void {
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $child) {
            if ($child !== '.' && $child !== '..') $removeMaterialize($path . '/' . $child);
        }
        @rmdir($path);
    };
    $removeMaterialize($materializeTmp);
}

$tmp = sys_get_temp_dir() . '/duo-refresh-field-resolution-' . bin2hex(random_bytes(5));
$link = $tmp . '-link';
$fifo = $tmp . '-fifo';
try {
    file_put_contents($tmp, Canon::encode($resolution));
    $loaded = RefreshFieldDiff::readResolutionFile($tmp, $diff);
    $check(($loaded['resolution_hash'] ?? null) === $resolution['resolution_hash'],
        'canonical bounded local resolution files verify before use');
    $withoutResolutionHash = $resolution;
    unset($withoutResolutionHash['resolution_hash']);
    file_put_contents($tmp, Canon::encode($withoutResolutionHash));
    $refuses(static fn() => RefreshFieldDiff::readResolutionFile($tmp, $diff),
        'external resolution files require their immutable resolution_hash binding');
    file_put_contents($tmp, Canon::encode($resolution) . "\n");
    $refuses(static fn() => RefreshFieldDiff::readResolutionFile($tmp, $diff),
        'noncanonical resolution input refuses without echoing raw content');
    file_put_contents($tmp, Canon::encode($resolution));
    if (symlink($tmp, $link)) {
        $refuses(static fn() => RefreshFieldDiff::readResolutionFile($link, $diff),
            'resolution input rejects a symlink before opening its target');
    } else {
        $check(true, 'resolution symlink refusal is unavailable on this platform');
    }
    if (function_exists('posix_mkfifo') && posix_mkfifo($fifo, 0600)) {
        $refuses(static fn() => RefreshFieldDiff::readResolutionFile($fifo, $diff),
            'resolution input rejects a FIFO before it can block on open');
    } else {
        $check(true, 'resolution FIFO refusal is unavailable on this platform');
    }
} finally {
    @unlink($link);
    @unlink($fifo);
    @unlink($tmp);
}

// ---------------------------------------------------------------------------
// DUO-3494: block-structural post-body composition.
//
// Before this, every changed body was one `body_changed` record choice, so the
// canonical WordPress conflict -- two editors on one page -- resolved as an
// ours/theirs coin flip over the whole document no matter how far apart the
// two edits were. Composition here is a whole-top-level-block byte swap and
// nothing else: DESIGN.md:125 keeps ordered structures record-atomic, so the
// three refusals below are as much of the contract as the composition is.

$blockBody = static function (string $one, string $two, string $three): string {
    return "<!-- wp:paragraph -->\n<p>$one</p>\n<!-- /wp:paragraph -->\n\n"
        . "<!-- wp:heading {\"level\":3} -->\n<h3>$two</h3>\n<!-- /wp:heading -->\n\n"
        . "<!-- wp:separator /-->\n\n"
        . "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>$three</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n";
};
$bodyPost = static fn(string $body): string => $post('"body title"', '"body excerpt"', '"draft"', $body);
$recordOf = static function (array $diff): array {
    return $diff['records'][0] ?? [];
};
// Composition failures must REPORT, not abort: every refusal below is part of
// the same contract, and an apply() regression that threw would hide them.
$composeOrNull = static function (array $composePlan, array $composeBundle, array $composeDiff, array $composeChoices = []): ?array {
    try {
        return RefreshFieldDiff::apply($composePlan, $composeBundle, RefreshFieldDiff::resolution($composeDiff, $composeChoices));
    } catch (Throwable) {
        return null;
    }
};
$projectBody = static function (string $label, string $base, string $production, string $branch)
    use ($singlePostPlan, $policy): array {
    $bodyPlan = $singlePostPlan($label, $base, $production, $branch);
    return [
        'plan' => $bodyPlan,
        'projection' => RefreshFieldDiff::project($bodyPlan, [
            'base' => $policy(), 'production' => $policy(), 'branch' => $policy(),
        ]),
    ];
};

// 1. The headline: production edits the first block, branch edits the last,
//    and the composed document is byte-exactly "both edits", not either side.
$disjointBase = $bodyPost($blockBody('base one', 'base two', 'base three'));
$disjointProduction = $bodyPost($blockBody('PRODUCTION one', 'base two', 'base three'));
$disjointBranch = $bodyPost($blockBody('base one', 'base two', 'BRANCH three'));
$disjoint = $projectBody('body-blocks-disjoint', $disjointBase, $disjointProduction, $disjointBranch);
$disjointDiff = $disjoint['projection']['diff'];
$disjointRecord = $recordOf($disjointDiff);
$disjointFields = $publicFields($disjointDiff);
sort($disjointFields, SORT_STRING);
$check(($disjointRecord['mode'] ?? null) === 'fields'
    && ($disjointRecord['reason'] ?? null) === 'eligible_engine_fields'
    && $disjointFields === ['post.body.branch_blocks', 'post.body.production_blocks']
    && ($disjointDiff['summary']['conflicting_choices'] ?? null) === 0,
    'two branches editing disjoint top-level blocks of one post decompose into automatic body partitions');
$disjointCategories = [];
foreach ($disjointRecord['changes'] as $change) $disjointCategories[$change['field']] = $change['category'];
$check(($disjointCategories['post.body.production_blocks'] ?? null) === 'production-only'
    && ($disjointCategories['post.body.branch_blocks'] ?? null) === 'branch-only',
    'each body partition carries the one automatic category its label declares');
$disjointApplied = $composeOrNull($disjoint['plan'], $disjoint['projection']['bundle'], $disjointDiff) ?? [];
$disjointComposed = $bodyPost($blockBody('PRODUCTION one', 'base two', 'BRANCH three'));
$check(($disjointApplied['entries'][0]['selected']['content'] ?? null) === $disjointComposed
    && ($disjointApplied['entries'][0]['selected_source'] ?? null) === 'field-resolution'
    && !array_key_exists('hash', (array) ($disjointApplied['entries'][0]['selected'] ?? ['hash' => null])),
    'composition splices production block bytes into the branch scaffold and keeps every other byte, including the unchanged blocks and separators');

// 2. The refusal that is the feature. Today this same shape resolved as one
//    ours/theirs record choice under `body_changed`; a coin flip inside a body
//    neither the diff nor the resolution may show is exactly the quiet
//    best-effort DESIGN.md:29 forbids.
$overlap = $projectBody(
    'body-blocks-overlap',
    $disjointBase,
    $bodyPost($blockBody('PRODUCTION one', 'base two', 'base three')),
    $bodyPost($blockBody('BRANCH one', 'base two', 'base three'))
);
$overlapRecord = $recordOf($overlap['projection']['diff']);
$check(($overlapRecord['mode'] ?? null) === 'record'
    && ($overlapRecord['reason'] ?? null) === 'body_block_overlap'
    && ($overlapRecord['changes'][0]['category'] ?? null) === 'conflicting',
    'one top-level block edited differently on both sides refuses composition by name and returns the whole record to one choice');
$overlapPartial = $projectBody(
    'body-blocks-overlap-with-composable-siblings',
    $disjointBase,
    $bodyPost($blockBody('PRODUCTION one', 'PRODUCTION two', 'base three')),
    $bodyPost($blockBody('BRANCH one', 'base two', 'BRANCH three'))
);
$check(($recordOf($overlapPartial['projection']['diff'])['reason'] ?? null) === 'body_block_overlap',
    'a single overlapping block sends the whole body back to record authority rather than composing its composable siblings');

// 3. Position is the only alignment this slice has, so every sequence edit is
//    a refusal rather than a guess about which block moved where.
$structureCases = [
    'body-blocks-inserted' => [
        $bodyPost($blockBody('base one', 'base two', 'base three')
            . "\n<!-- wp:paragraph -->\n<p>appended</p>\n<!-- /wp:paragraph -->\n"),
        $bodyPost($blockBody('base one', 'BRANCH two', 'base three')),
        'an inserted top-level block',
    ],
    'body-blocks-retyped' => [
        $bodyPost(str_replace('wp:paragraph', 'wp:verse', $blockBody('base one', 'base two', 'base three'))),
        $bodyPost($blockBody('base one', 'BRANCH two', 'base three')),
        'a retyped top-level block',
    ],
    'body-blocks-regapped' => [
        $bodyPost(str_replace("<!-- /wp:paragraph -->\n\n", "<!-- /wp:paragraph -->\n\n\n", $blockBody('base one', 'base two', 'base three'))),
        $bodyPost($blockBody('base one', 'BRANCH two', 'base three')),
        'reflowed inter-block gap bytes',
    ],
];
foreach ($structureCases as $label => [$structureProduction, $structureBranch, $description]) {
    $structure = $projectBody($label, $disjointBase, $structureProduction, $structureBranch);
    $check(($recordOf($structure['projection']['diff'])['mode'] ?? null) === 'record'
        && ($recordOf($structure['projection']['diff'])['reason'] ?? null) === 'body_structure_changed',
        "$description keeps the record atomic under the closed body_structure_changed reason");
}

// 4. A body this reader cannot name stays exactly where it was: the reason
//    this file already published for every changed body.
$classicCases = [
    'body-blocks-classic' => ["base text\n", "production text\n", "branch text\n", 'a classic non-block body'],
    'body-blocks-freeform' => [
        "<!-- wp:paragraph -->\n<p>base one</p>\n<!-- /wp:paragraph -->\nloose text\n",
        "<!-- wp:paragraph -->\n<p>production one</p>\n<!-- /wp:paragraph -->\nloose text\n",
        "<!-- wp:paragraph -->\n<p>base one</p>\n<!-- /wp:paragraph -->\nbranch loose text\n",
        'freeform content outside a top-level block',
    ],
    'body-blocks-unterminated' => [
        "<!-- wp:paragraph -->\n<p>base one</p>\n",
        "<!-- wp:paragraph -->\n<p>production one</p>\n",
        "<!-- wp:paragraph -->\n<p>branch one</p>\n",
        'an unterminated block delimiter',
    ],
];
foreach ($classicCases as $label => [$classicBase, $classicProduction, $classicBranch, $description]) {
    $classic = $projectBody($label, $bodyPost($classicBase), $bodyPost($classicProduction), $bodyPost($classicBranch));
    $check(($recordOf($classic['projection']['diff'])['mode'] ?? null) === 'record'
        && ($recordOf($classic['projection']['diff'])['reason'] ?? null) === 'body_changed',
        "$description keeps the pre-existing body_changed record-atomic answer");
}

// 5. The attribute object is scanned as JSON, so a `-->` inside an attribute
//    string cannot end a delimiter early and silently move a block boundary.
$attrBody = static fn(string $one): string =>
    "<!-- wp:html {\"note\":\"arrow --> inside\"} -->\n<p>$one</p>\n<!-- /wp:html -->\n\n"
    . "<!-- wp:paragraph -->\n<p>tail</p>\n<!-- /wp:paragraph -->\n";
$attrs = $projectBody(
    'body-blocks-attribute-arrow',
    $bodyPost($attrBody('base one')),
    $bodyPost($attrBody('PRODUCTION one')),
    $bodyPost($attrBody('base one'))
);
$attrsApplied = $composeOrNull($attrs['plan'], $attrs['projection']['bundle'], $attrs['projection']['diff']) ?? [];
$check(($recordOf($attrs['projection']['diff'])['mode'] ?? null) === 'fields'
    && $publicFields($attrs['projection']['diff']) === ['post.body.production_blocks']
    && ($attrsApplied['entries'][0]['selected']['content'] ?? null) === $bodyPost($attrBody('PRODUCTION one')),
    'a block attribute string containing --> is scanned as JSON rather than ending its delimiter early');

// 6. Both sides making the identical block edit is agreement, not conflict,
//    and agreement keeps the branch scaffold byte-for-byte.
$compatibleBody = $bodyPost($blockBody('SAME one', 'base two', 'base three'));
$compatible = $projectBody('body-blocks-compatible', $disjointBase, $compatibleBody, $compatibleBody);
$compatibleApplied = $composeOrNull($compatible['plan'], $compatible['projection']['bundle'], $compatible['projection']['diff']) ?? [];
$check($publicFields($compatible['projection']['diff']) === ['post.body.compatible_blocks']
    && ($compatibleApplied['entries'][0]['selected']['content'] ?? null) === $compatibleBody,
    'an identical block edit on both sides is one compatible partition that preserves exact branch bytes');

// 7. Mixed run: a manual scalar choice and automatic body partitions resolve
//    together, which is what keeps composition from needing its own verb.
$mixed = $projectBody(
    'body-blocks-mixed-with-scalar-conflict',
    $post('"base"', '"body excerpt"', '"draft"', $blockBody('base one', 'base two', 'base three')),
    $post('"production"', '"body excerpt"', '"draft"', $blockBody('PRODUCTION one', 'base two', 'base three')),
    $post('"branch"', '"body excerpt"', '"draft"', $blockBody('base one', 'base two', 'BRANCH three'))
);
$mixedDiff = $mixed['projection']['diff'];
$mixedTitle = null;
foreach ($recordOf($mixedDiff)['changes'] as $change) {
    if ($change['field'] === 'post.title') $mixedTitle = $change;
}
$mixedApplied = $composeOrNull($mixed['plan'], $mixed['projection']['bundle'], $mixedDiff, [[
    'choice' => 'theirs',
    'field_selector_sha256' => (string) ($mixedTitle['field_selector_sha256'] ?? ''),
    'record_selector_sha256' => (string) ($mixedTitle['record_selector_sha256'] ?? ''),
    'scope' => 'field',
]]) ?? [];
$check(is_array($mixedTitle) && ($mixedTitle['category'] ?? null) === 'conflicting'
    && ($mixedDiff['summary']['conflicting_choices'] ?? null) === 1
    && ($mixedApplied['entries'][0]['selected']['content'] ?? null)
        === $post('"production"', '"body excerpt"', '"draft"', $blockBody('PRODUCTION one', 'base two', 'BRANCH three')),
    'one field resolution run carries a manual scalar choice and automatic body partitions into the same spliced record');

// 8. The redaction contract (RefreshFieldDiff.php:7-17) is unchanged by the
//    new surface: body composition happens over verified private bytes while
//    the public diff still publishes no block content, position, or count.
$disjointEncoded = Canon::encode($disjointDiff);
$check(!str_contains($disjointEncoded, 'PRODUCTION one') && !str_contains($disjointEncoded, 'BRANCH three')
    && !str_contains($disjointEncoded, 'wp:paragraph') && !str_contains($disjointEncoded, 'block:')
    && !str_contains($disjointEncoded, 'body title') && $containsOnlyRedactedPublicData($disjointDiff),
    'the public body partitions publish no block bytes, block index, or block count');

// 9. Discovered members mean no const member list to check a bundle against,
//    so the assert path re-derives the whole partition from the entry's own
//    B/P/W bytes. Each tamper below is one thing that re-derivation catches.
$tamperTargets = [];
foreach ($disjoint['projection']['bundle']['records'] as $selector => $tamperRecord) {
    foreach ($tamperRecord['fields'] as $fieldIndex => $tamperField) {
        if (($tamperField['label'] ?? '') === 'post.body.production_blocks') {
            $tamperTargets[] = [$selector, $fieldIndex];
        }
    }
}
$check(count($tamperTargets) === 1, 'the private bundle names exactly one production body partition to tamper with');
[$tamperSelector, $tamperIndex] = $tamperTargets[0] ?? ['', 0];
$tamperApply = static function (callable $mutate) use ($disjoint, $tamperSelector, $tamperIndex, $disjointDiff): void {
    $bundle = $disjoint['projection']['bundle'];
    $bundle['records'][$tamperSelector]['fields'][$tamperIndex] =
        $mutate($bundle['records'][$tamperSelector]['fields'][$tamperIndex]);
    RefreshFieldDiff::apply($disjoint['plan'], $bundle, RefreshFieldDiff::resolution($disjointDiff, []));
};
$refuses(static fn() => $tamperApply(static function (array $field): array {
    $member = array_key_first($field['spans']['branch']);
    $field['spans']['branch'][$member]['end'] += 8;
    return $field;
}), 'a widened branch body span refuses before any splice');
$refuses(static fn() => $tamperApply(static function (array $field): array {
    $member = array_key_first($field['values']['production']);
    $field['values']['production'][$member] = "<!-- wp:paragraph -->\n<p>smuggled</p>\n<!-- /wp:paragraph -->";
    return $field;
}), 'substituted production body bytes refuse rather than reaching the branch scaffold');
$refuses(static fn() => $tamperApply(static function (array $field): array {
    $field['values']['production']['block:99'] = $field['values']['production'][array_key_first($field['values']['production'])];
    $field['spans']['production']['block:99'] = $field['spans']['production'][array_key_first($field['spans']['production'])];
    return $field;
}), 'an invented body block member refuses rather than widening the partition');
$refuses(static fn() => $tamperApply(static function (array $field): array {
    $field['category'] = 'branch-only';
    return $field;
}), 'a relabelled body partition category refuses because the label declares exactly one category');

// 10. Body partitions are automatic, so an operator with nothing but a
//     composable body is told there is nothing to choose rather than prompted.
$disjointIn = fopen('php://temp', 'r+');
$disjointOut = fopen('php://temp', 'r+');
$disjointInteractive = RefreshFieldDiff::interactiveResolution($disjointDiff, $disjointIn, $disjointOut);
rewind($disjointOut);
$disjointTranscript = (string) stream_get_contents($disjointOut);
fclose($disjointIn);
fclose($disjointOut);
$check(is_array($disjointInteractive) && ($disjointInteractive['choices'] ?? null) === []
    && str_contains($disjointTranscript, '0 manual choices; continuing')
    && str_contains($disjointTranscript, 'post.body.production_blocks production-only eligible_engine_fields')
    && str_contains($disjointTranscript, 'post.body.branch_blocks branch-only eligible_engine_fields')
    && str_contains($disjointTranscript, 'auto=production') && str_contains($disjointTranscript, 'auto=branch')
    && !str_contains($disjointTranscript, 'branch or production?')
    && !str_contains($disjointTranscript, 'PRODUCTION one') && !str_contains($disjointTranscript, 'BRANCH three')
    && !str_contains($disjointTranscript, 'wp:paragraph'),
    'the local interactive preview names each body partition and its automatic outcome without prompting or revealing block bytes');

echo $failures === 0 ? "PASS: refresh field diff\n" : "FAIL: $failures refresh field diff assertion(s)\n";
exit($failures === 0 ? 0 : 1);
