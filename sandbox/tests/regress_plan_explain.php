<?php
declare(strict_types=1);

/**
 * Offline DUO-3345 regression for the value-free explanation model.
 * Product routing and the strict live observation boundary have their own
 * driver/live assertions; this fixture locks the pure report projected from
 * compiler, policy, reference-graph, and exact action-selection evidence.
 */

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/Secrets.php';
require_once __DIR__ . '/../../agent/src/CommandRefusal.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/NativeActions.php';
require_once __DIR__ . '/../../agent/src/AdapterSources.php';
require_once __DIR__ . '/../../agent/src/ReferenceRules.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/PlainData.php';
require_once __DIR__ . '/../../agent/src/SidebarState.php';
require_once __DIR__ . '/../../agent/src/ReferenceGraph.php';
require_once __DIR__ . '/../../agent/src/CodeCompatibility.php';
require_once __DIR__ . '/../../agent/src/RepositoryCompiler.php';
require_once __DIR__ . '/../../agent/src/Deletion.php';
require_once __DIR__ . '/../../agent/src/PlanExplanation.php';

use Duo\CommandRefusalException;
use Duo\CompiledRepository;
use Duo\OptionState;
use Duo\PlanExplanation;
use Duo\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'FAIL') . ": $message\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$post = '11111111-1111-7111-8111-111111111111';
$related = '22222222-2222-7222-8222-222222222222';
$term = '33333333-3333-7333-8333-333333333333';
$secret = 'sk_live_1234567890PLANEXPLAINMUSTNOTLEAK';
$signed = 'https://example.test/callback?X-Amz-Signature=PLANEXPLAINMUSTNOTLEAK';

$policy = new Policy();
$policy->site = [
    'policy' => [
        'post_types' => ['product', 'attachment'],
        'taxonomies' => ['product_cat'],
        'options' => [
            'shop_mode' => ['class' => 'authored', 'autoload' => 'yes'],
        ],
    ],
];
$policy->manifests = [[
    'name' => 'shop-adapter',
    'plugin' => 'shop/shop.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'post_types' => ['product' => ['class' => 'authored']],
    'post_meta' => [
        '_related_product' => ['class' => 'authored', 'ref' => 'post'],
        '_computed_label' => ['class' => 'derived'],
    ],
    'tables' => [
        'shop_rows' => ['class' => 'authored_snapshot', 'id_kind' => 'shop_row', 'columns' => []],
    ],
    'deletions' => [
        'post:product' => [
            'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
            'guards' => [[
                'table' => 'shop_orders', 'column' => 'product_id', 'id_kind' => 'post',
                'reason' => $secret,
            ]],
        ],
    ],
    'providers' => [[
        'id' => 'shop-rebuild', 'version' => '1.0.0', 'source' => 'plugin',
        'plugin' => 'shop/shop.php', 'capabilities' => ['reindex'],
    ]],
    'actions' => [
        [
            'kind' => 'provider', 'provider' => 'shop-rebuild', 'capability' => 'reindex',
            'args' => ['endpoint' => $signed], 'triggers' => ['post:product'],
        ],
        [
            'kind' => 'native', 'action' => 'transient.delete',
            'args' => ['name' => $secret],
        ],
    ],
]];

$postEntity = [
    'type' => 'post',
    'path' => 'posts/product/' . $post . '--' . $secret . '.md',
    'hash' => hash('sha256', 'post authored state'),
    'content' => $secret,
    'body' => '',
    'data' => [
        'uuid' => $post,
        'type' => 'product',
        'title' => $secret,
        'meta' => [
            '_related_product' => '{{post:' . $related . '}}',
            '_computed_label' => $secret,
        ],
        'terms' => ['product_cat' => [$term]],
    ],
];
$relatedEntity = [
    'type' => 'post', 'path' => 'posts/product/related.md',
    'hash' => hash('sha256', 'related'), 'content' => '{}', 'body' => '',
    'data' => ['uuid' => $related, 'type' => 'product', 'meta' => [], 'terms' => []],
];
$termEntity = [
    'type' => 'term', 'path' => 'terms/product_cat/private.json',
    'hash' => hash('sha256', 'term'), 'content' => '{}',
    'data' => ['uuid' => $term, 'taxonomy' => 'product_cat', 'meta' => [], 'relationships' => []],
];
$compiled = CompiledRepository::create([
    'revision_hash' => hash('sha256', 'revision'),
    'manifest_hash' => hash('sha256', 'manifest'),
    'site_hash' => hash('sha256', 'site'),
    'tree' => [$post => $postEntity, $related => $relatedEntity, $term => $termEntity],
    'deletions' => [],
    'resolved_adapters' => [[
        'name' => 'shop-adapter', 'digest' => hash('sha256', 'adapter'),
        'plugin' => 'shop/shop.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ]],
    'effects_inventory' => [],
]);

$actions = $policy->actions_for(['post:product']);
$report = PlanExplanation::build(
    'update:' . $post,
    'update',
    [
        'uuid' => $post, 'type' => 'post', 'path' => $postEntity['path'],
        'title' => $secret, 'env_id' => 987654, 'blocked' => $secret,
    ],
    $compiled,
    $policy,
    ['post:product'],
    $actions,
    ['adopt_by_slug' => [], 'force_unresolved_refs' => false, 'compiled_artifact_provided' => false]
);

$check(($report['format'] ?? null) === 'duo-explain/v1' && ($report['ok'] ?? null) === true,
    'report is an unambiguous versioned success');
$check(
    ($report['selector']['copyable'] ?? null) === PlanExplanation::selectorForOutput('update', $post)
        && !str_contains((string) $report['selector']['copyable'], $post),
    'public selector is copyable and opaque rather than echoing the raw entity key'
);
$check(
    ($report['source']['path'] ?? null) === 'posts/product/<identity>.md'
        && ($report['source']['content_binding'] ?? null) === 'compiled_artifact',
    'source is a structural locator bound by the artifact, never a slug or value'
);
$ruleJson = json_encode($report['rules'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(
    str_contains($ruleJson, 'post_types.product')
        && str_contains($ruleJson, 'post_meta._related_product')
        && str_contains($ruleJson, 'shop-adapter')
        && str_contains($ruleJson, 'shop/shop.php'),
    'effective entity/field rules retain manifest and adapter provenance'
);
$check(
    ($report['references']['status'] ?? null) === 'declared'
        && count($report['references']['edges'] ?? []) === 2
        && count(array_filter($report['references']['edges'], static fn(array $e): bool => $e['direction'] === 'outbound')) === 2,
    'only the selected entity outbound declared reference/relationship edges are projected'
);
$check(
    ($report['execution']['rebuild_surfaces'] ?? null) === ['post:product']
        && array_column($report['execution']['actions'] ?? [], 'source')
            === ['provider:shop-rebuild/reindex', 'native:transient.delete']
        && ($report['execution']['actions'][0]['readiness'] ?? null) === 'not_checked'
        && ($report['execution']['provider_negotiation'] ?? null) === 'not_performed',
    'exact and unscoped actions preserve declaration order and remain declaration-only'
);
$json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$human = implode("\n", PlanExplanation::render($report));
foreach ([$secret, $signed, (string) 987654, $postEntity['path'], $post] as $forbidden) {
    $check(!str_contains($json, $forbidden) && !str_contains($human, $forbidden),
        'JSON and human explanation omit raw value/local-id/path/key evidence: ' . hash('sha256', $forbidden));
}
$check(
    !str_contains($json, hash('sha256', 'post authored state')),
    'per-entity content hashes are omitted; the immutable artifact digest is the public binding'
);
$check(
    json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        === json_encode(PlanExplanation::build(
            'update:' . $post,
            'update',
            [
                'uuid' => $post, 'type' => 'post', 'path' => $postEntity['path'],
                'title' => $secret, 'env_id' => 987654, 'blocked' => $secret,
            ],
            $compiled, $policy, ['post:product'], $actions,
            ['adopt_by_slug' => [], 'force_unresolved_refs' => false, 'compiled_artifact_provided' => false]
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    'same immutable inputs produce byte-stable JSON'
);

echo "\n== opaque selector grammar and safe failures ==\n";
$sidebarKey = "sidebar/গ্রাহক zone:primary";
$selected = PlanExplanation::select(['update' => [[
    'uuid' => $sidebarKey, 'type' => 'sidebar', 'path' => 'sidebars/private.json',
]]], 'update:' . $sidebarKey);
$check(($selected['key'] ?? null) === $sidebarKey, 'valid UTF-8 opaque sidebar keys resolve without path normalization');
$selectedHashed = PlanExplanation::select(['update' => [[
    'uuid' => $sidebarKey, 'type' => 'sidebar', 'path' => 'sidebars/private.json',
]]], PlanExplanation::selectorForOutput('update', $sidebarKey));
$check(($selectedHashed['key'] ?? null) === $sidebarKey, 'hash-safe plan selector resolves the same opaque key');
foreach ([
    ['selector' => "update:bad\n$secret", 'code' => 'invalid_plan_selector'],
    ['selector' => 'code_mismatch:plugin', 'code' => 'unsupported_plan_selector'],
    ['selector' => 'private_customer_token:row', 'code' => 'unsupported_plan_selector'],
    ['selector' => 'update:absent-' . $secret, 'code' => 'plan_action_not_found'],
] as $case) {
    try {
        PlanExplanation::select(['update' => []], $case['selector']);
        $check(false, $case['code'] . ' selector is refused');
    } catch (CommandRefusalException $e) {
        $check($e->reasonCode === $case['code'], $case['code'] . ' has a stable reason code');
        $check(!str_contains($e->getMessage(), $secret), $case['code'] . ' does not echo untrusted selector text');
    }
}

echo "\n== options fan-out and deletion authority stay value-free ==\n";
$options = OptionState::document([
    'shop_mode' => OptionState::present($secret, 'yes'),
]);
$optionsCompiled = CompiledRepository::create([
    'revision_hash' => hash('sha256', 'options revision'),
    'manifest_hash' => hash('sha256', 'manifest'),
    'site_hash' => hash('sha256', 'site'),
    'tree' => ['options/core' => [
        'type' => 'options', 'path' => 'options/core.json',
        'hash' => hash('sha256', 'options content'), 'content' => $secret, 'data' => $options,
    ]],
    'deletions' => [], 'resolved_adapters' => [], 'effects_inventory' => [],
]);
$optionsReport = PlanExplanation::build(
    'update:options/core', 'update',
    ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'rebuild_option_names' => ['shop_mode']],
    $optionsCompiled, $policy, ['option:shop_mode'], [], []
);
$check(
    str_contains((string) json_encode($optionsReport['rules']), 'options.shop_mode')
        && !str_contains((string) json_encode($optionsReport), $secret),
    'options/core explains changed record coordinates and exact surface without option values'
);

$deletionCompiled = CompiledRepository::create([
    'revision_hash' => hash('sha256', 'delete revision'),
    'manifest_hash' => hash('sha256', 'manifest'),
    'site_hash' => hash('sha256', 'site'),
    'tree' => [],
    'deletions' => [$post => [
        'type' => 'deletion', 'path' => 'deletions/' . $post . '.json',
        'hash' => hash('sha256', 'tombstone'), 'content' => $secret,
        'data' => [
            'format' => 'duo-deletion/v1', 'uuid' => $post, 'kind' => 'post', 'type' => 'product',
            'expected_hash' => hash('sha256', 'low entropy'), 'expected_revision' => hash('sha256', 'old'),
            'source_path' => $postEntity['path'],
        ],
    ]],
    'resolved_adapters' => [[
        'name' => 'shop-adapter', 'digest' => hash('sha256', 'adapter'),
        'plugin' => 'shop/shop.php', 'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ]],
    'effects_inventory' => [],
]);
$deleteReport = PlanExplanation::build(
    'delete:' . $post, 'delete',
    [
        'uuid' => $post, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'product',
        'path' => 'deletions/' . $post . '.json', 'blocked' => $secret,
        'guard_refs' => [['rows' => [123456], 'reason' => $secret]],
    ],
    $deletionCompiled, $policy, ['post:product'], $actions, []
);
$deleteJson = json_encode($deleteReport, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(
    ($deleteReport['execution']['mutation']['apply_eligibility'] ?? null) === 'explicit_flags_required'
        && ($deleteReport['execution']['mutation']['required_flags'] ?? null)
            === ['--with-deletes', '--force-delete-referenced']
        && ($deleteReport['rules'][0]['details']['guard_declarations'] ?? null) === 1,
    'deletion explanation keeps explicit authority and safe declared guard count'
);
$check(
    str_contains($deleteJson, 'postmeta') && str_contains($deleteJson, 'term_relationships')
        && !str_contains($deleteJson, $secret) && !str_contains($deleteJson, '123456')
        && !str_contains($deleteJson, hash('sha256', 'low entropy')),
    'deletion cascades remain visible while guard rows/reasons and content hashes remain absent'
);

echo "\n== strict observation owns no repair, provider, or action authority ==\n";
$privateIdentityFailure = 'duo: duplicate _duo_uuid 11111111-1111-7111-8111-111111111111 is attached to post:12, post:99';
$identityRefusal = CommandRefusalException::explainObservationPrecondition(
    new RuntimeException($privateIdentityFailure)
);
$identityPublic = json_encode([
    'reason_code' => $identityRefusal->reasonCode,
    'message' => $identityRefusal->publicMessage,
    'remediation' => $identityRefusal->remediation,
    'diagnostics' => $identityRefusal->diagnostics,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(
    $identityRefusal->reasonCode === 'explain_observation_precondition_failed'
        && ($identityRefusal->diagnostics[0]['code'] ?? '') === 'explain_observation_precondition_failed'
        && !str_contains($identityPublic, $privateIdentityFailure),
    'duplicate/invalid embedded identity state maps to the stable value-free observation precondition refusal'
);
$captureSource = file_get_contents(__DIR__ . '/../../agent/src/Capture.php');
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$captureStart = strpos((string) $captureSource, 'public static function snapshot_read_only(');
$captureEnd = strpos((string) $captureSource, "\n    /**", (int) $captureStart + 1);
$strictCapture = substr((string) $captureSource, (int) $captureStart, (int) $captureEnd - (int) $captureStart);
$buildPostStart = strpos((string) $captureSource, 'private function build_post(');
$buildPostEnd = strpos((string) $captureSource, "\n    /**", (int) $buildPostStart + 1);
$buildPost = substr((string) $captureSource, (int) $buildPostStart, (int) $buildPostEnd - (int) $buildPostStart);
$explainStart = strpos((string) $applySource, 'public static function explain(');
$explainTail = substr((string) $applySource, (int) $explainStart + 1);
preg_match(
    '/\n    (?:public|private|protected) (?:static )?function [A-Za-z_]+\(/',
    $explainTail,
    $nextMethod,
    PREG_OFFSET_CAPTURE
);
$explainEnd = isset($nextMethod[0][1]) ? (int) $explainStart + 1 + (int) $nextMethod[0][1] : false;
$strictExplain = substr((string) $applySource, (int) $explainStart, (int) $explainEnd - (int) $explainStart);
$check(
    $captureStart !== false && $captureEnd !== false
        && str_contains($strictCapture, 'Ledger::assert_read_only_schema()')
        && substr_count($strictCapture, 'assert_read_only_identity_precondition(') === 3
        && str_contains($strictCapture, "true\n            );"),
    'explain capture asserts existing schema and normalizes every strict identity read'
);
foreach (['Ledger::ensure', 'prune_dead_map', 'repair_truncated_entity_types'] as $forbiddenCall) {
    $check(
        !str_contains($strictCapture, $forbiddenCall),
        "strict capture contains no $forbiddenCall maintenance call"
    );
}
$check(
    $buildPostStart !== false && $buildPostEnd !== false
        && str_contains($captureSource, '$forceUnresolvedRefs,' . "\n" . '                $strictReadOnly')
        && str_contains($buildPost, 'if (!$strictReadOnly) {')
        && str_contains($buildPost, "'duo_attachment_capture_source'")
        && str_contains($buildPost, 'external offload hook is deliberately not invoked by explain'),
    'strict attachment observation uses only local bytes and refuses rather than invoking an offload provider hook'
);
$check(
    $explainStart !== false && $explainEnd !== false
        && str_contains($strictExplain, 'build_plan($opts, $compiled, true, false)')
        && !str_contains($strictExplain, 'Providers::')
        && !str_contains($strictExplain, 'NativeActions::'),
    'explain requests strict planning with adapter diagnosis disabled and invokes no action/provider runtime'
);

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " plan explain check(s) failed\n");
    exit(1);
}
echo "PASS: one plan entity traces through value-free source, policy, references, actions, and verification\n";
