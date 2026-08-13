<?php
/**
 * Offline regression for DUO-3344's immutable scope-contract evidence.
 *
 * This deliberately drives the real compiler, ScopeClosure, Policy action
 * grammar, and ScopeContract over a scratch repository. No WordPress target
 * is bootstrapped: the two tempting target APIs are booby-trapped below, so a
 * regression that reaches out for a guard witness, upload directory, or live
 * option fails immediately rather than looking green by accident.
 */
declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__, 2);
define('DUO_SPEC_VERSION', 2);
foreach ([
    'Uuid', 'OrderPreserved', 'Canon', 'OptionState', 'UserMetaState', 'Db', 'Secrets',
    'PersonalData', 'ManifestDispositions', 'AdapterSources', 'CapabilityRegistry',
    'NativeActions', 'ReferenceRules', 'Policy', 'Providers', 'Ledger', 'Deletion',
    'JsonRefs', 'PlainData', 'StructuredValue', 'SidebarState', 'Snapshot',
    'RepositoryAuthorization', 'CodeCompatibility', 'Code', 'CodeStateContract',
    'ReferenceGraph', 'RepositoryCompiler', 'ScopeClosure', 'CanonicalSurfaces', 'ScopeContract',
    'ScopedStateOverlay', 'ScopedApplySession', 'ScopedApply', 'Capture',
] as $file) {
    require_once "$root/agent/src/$file.php";
}
require_once "$root/cli/src/RefreshPlan.php";

function get_option($name): never { throw new RuntimeException("TARGET CONTACT: get_option($name)"); }
function wp_upload_dir(...$args): never { throw new RuntimeException('TARGET CONTACT: wp_upload_dir'); }
function apply_filters(...$args): never { throw new RuntimeException('TARGET CONTACT: apply_filters/provider negotiation'); }
function is_multisite(): bool { return false; }

use Duo\Canon;
use Duo\Deletion;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\ScopeClosure;
use Duo\ScopeContract;
use Duo\ScopedStateOverlay;
use Duo\Orchestrator\RefreshPlan;

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}

function expect_throw(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        check(false, $message . ' (did not refuse)');
    } catch (Throwable $failure) {
        check(str_contains($failure->getMessage(), $needle), $message . ' (' . $failure->getMessage() . ')');
    }
}

$tmp = sys_get_temp_dir() . '/duo-scope-contract-' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tmp);
});

function put(string $path, string $content): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}
function uuid(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
/** @return array<string,mixed> */
function front(string $id, string $type, string $slug): array {
    return [
        'author' => 'user:admin', 'comment_status' => 'open',
        'date' => '2026-08-09 00:00:00', 'date_gmt' => '2026-08-09 00:00:00',
        'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified_gmt' => '2026-08-09 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => $slug, 'status' => 'publish',
        'terms' => (object) [], 'title' => ucfirst($slug), 'type' => $type, 'uuid' => $id,
    ];
}
/** @return array<string,mixed> */
function effect(string $id): array {
    return [
        'id' => $id, 'kind' => 'database', 'mode' => 'restorable',
        'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'scope_contract_probe'],
    ];
}
function options(array $overrides): string {
    $rows = [];
    foreach ([
        'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
        'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
        'template', 'wp_page_for_privacy_policy',
    ] as $name) {
        $rows[$name] = OptionState::absent();
    }
    return Canon::encode(OptionState::document(array_replace($rows, $overrides)));
}

$manifestDir = "$tmp/manifests";
mkdir($manifestDir, 0777, true);
copy("$root/manifests/core.json", "$manifestDir/core.json");
$fixtureManifest = [
    'name' => 'scope-contract-fixture',
    'spec_version' => 2,
    'post_types' => [
        'duo_contract' => [
            'class' => 'authored',
            'children' => ['duo_child'],
            'regen_dependency' => [
                'regenerator' => 'scope-contract-probe',
                'verify' => ['table' => 'duo_contract_index', 'column' => 'post_id'],
                'effects' => [effect('scope-contract-regenerator')],
            ],
        ],
        'duo_child' => ['class' => 'authored'],
    ],
    'providers' => [[
        'id' => 'scope-contract-provider', 'version' => '1.0.0', 'source' => 'plugin',
        'plugin' => 'scope-contract/scope-contract.php', 'capabilities' => ['rebuild_scope'],
    ]],
    'actions' => [
        [
            'kind' => 'native', 'action' => 'transient.delete',
            'args' => ['name' => 'scope_contract_page'], 'triggers' => ['post:page'],
            'effects' => [effect('scope-contract-page-action')],
        ],
        [
            'kind' => 'native', 'action' => 'transient.delete',
            'args' => ['name' => 'scope_contract_product'], 'triggers' => ['post:product'],
            'effects' => [effect('scope-contract-product-action')],
        ],
        [
            'kind' => 'provider', 'provider' => 'scope-contract-provider', 'capability' => 'rebuild_scope',
            'args' => [], 'triggers' => ['post:page'], 'effects' => [effect('scope-contract-provider-action')],
        ],
    ],
];
put("$manifestDir/scope-contract-fixture.json", Canon::encode($fixtureManifest));
putenv("DUO_MANIFESTS_DIR=$manifestDir");

$ids = [
    'page' => uuid(1), 'term' => uuid(2), 'attachment' => uuid(3), 'otherAttachment' => uuid(4),
    'custom' => uuid(5), 'tombstone' => uuid(6), 'otherTombstone' => uuid(7),
    'menu' => uuid(9), 'menuItem' => uuid(10),
];
$repo = "$tmp/repo";
put("$repo/site.duo.json", Canon::encode([
    'manifests' => ['core', 'scope-contract-fixture'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => ['post', 'page', 'attachment', 'duo_contract', 'duo_child'],
        'taxonomies' => ['category', 'post_tag'],
    ],
    'spec_version' => 2,
]));

put("$repo/state/terms/category/{$ids['term']}--scope.json", Canon::encode([
    'description' => '', 'meta' => (object) [], 'name' => 'Scope', 'parent' => null,
    'relationships' => (object) [], 'slug' => 'scope', 'taxonomy' => 'category', 'uuid' => $ids['term'],
]));
$bytes = "scope-contract-selected-media\n";
$hash = hash('sha256', $bytes);
put("$repo/media/$hash.txt", $bytes);
$attachment = front($ids['attachment'], 'attachment', 'selected-media');
$attachment += ['alt' => '', 'file' => 'scope/selected.txt', 'media' => "$hash.txt", 'mime' => 'text/plain'];
put("$repo/state/posts/attachment/{$ids['attachment']}--selected-media.md", Canon::post_file($attachment, ''));
$otherBytes = "scope-contract-unrelated-media\n";
$otherHash = hash('sha256', $otherBytes);
put("$repo/media/$otherHash.txt", $otherBytes);
$otherAttachment = front($ids['otherAttachment'], 'attachment', 'other-media');
$otherAttachment += ['alt' => '', 'file' => 'scope/other.txt', 'media' => "$otherHash.txt", 'mime' => 'text/plain'];
put("$repo/state/posts/attachment/{$ids['otherAttachment']}--other-media.md", Canon::post_file($otherAttachment, ''));
$orphanBytes = "scope-contract-safe-orphan-media\n";
$orphanHash = hash('sha256', $orphanBytes);
put("$repo/media/$orphanHash.txt", $orphanBytes);
$page = front($ids['page'], 'page', 'scope-page');
$page['terms'] = (object) ['category' => [$ids['term']]];
put("$repo/state/posts/page/{$ids['page']}--scope-page.md", Canon::post_file(
    $page,
    '<!-- wp:image {"id":"{{post:' . $ids['attachment'] . '}}"} --><figure></figure><!-- /wp:image -->'
));
put("$repo/state/posts/duo_contract/{$ids['custom']}--contract.md", Canon::post_file(
    front($ids['custom'], 'duo_contract', 'contract'), ''
));
put("$repo/state/menus/main.json", Canon::encode([
    'items' => [[
        'attr_title' => '', 'classes' => [], 'object' => 'duo_contract', 'parent' => null,
        'position' => 1, 'ref' => '{{post:' . $ids['custom'] . '}}', 'target' => '',
        'title' => 'Contract', 'type' => 'post_type', 'uuid' => $ids['menuItem'], 'xfn' => '',
    ]],
    'locations' => [], 'name' => 'Main', 'slug' => 'main', 'uuid' => $ids['menu'],
]));
put("$repo/state/options/core.json", options([
    'blogname' => OptionState::present('Scope Contract', 'yes'),
    'page_on_front' => OptionState::present('{{post:' . $ids['page'] . '}}', 'yes'),
    'show_on_front' => OptionState::present('page', 'yes'),
]));
put("$repo/state/deletions/{$ids['tombstone']}.json", Canon::encode([
    'format' => Deletion::FORMAT, 'uuid' => $ids['tombstone'], 'kind' => 'post', 'type' => 'page',
    'expected_hash' => str_repeat('a', 64), 'expected_revision' => str_repeat('b', 64),
    'source_path' => "posts/page/{$ids['tombstone']}--removed.md",
]));
put("$repo/state/deletions/{$ids['otherTombstone']}.json", Canon::encode([
    'format' => Deletion::FORMAT, 'uuid' => $ids['otherTombstone'], 'kind' => 'post', 'type' => 'page',
    'expected_hash' => str_repeat('c', 64), 'expected_revision' => str_repeat('d', 64),
    'source_path' => "posts/page/{$ids['otherTombstone']}--other-removed.md",
]));

$policy = Policy::load($repo);
$compiled = RepositoryCompiler::compile($repo, $policy);

// Menu locations are one shared authored namespace. A selected source menu
// and a protected target menu cannot both own the same slot: the complete
// mixed candidate compiler must refuse before scoped authority or mutation.
$menuSourceRows = [];
foreach ($compiled->tree() as $identity => $row) {
    $data = $row['data'];
    $content = $row['content'];
    if ((string) $identity === $ids['menu']) {
        $data['locations'] = ['primary'];
        $content = Canon::encode($data);
    }
    $menuSourceRows[] = [
        'uuid' => (string) $identity,
        'type' => (string) $row['type'],
        'path' => (string) $row['path'],
        'content' => (string) $content,
    ];
}
$singleMenuView = ScopedStateOverlay::stage_state_view($menuSourceRows);
try {
    $singleMenuCompiled = RepositoryCompiler::compile_staged($singleMenuView, $repo, $policy);
    check(
        ($singleMenuCompiled->tree()[$ids['menu']]['data']['locations'] ?? null) === ['primary'],
        'compiler accepts one authored holder for a menu location'
    );
} finally {
    ScopedStateOverlay::discard_state_view($singleMenuView);
}
$protectedMenu = uuid(12);
$menuTakeoverRows = $menuSourceRows;
$menuTakeoverRows[] = [
    'uuid' => $protectedMenu,
    'type' => 'menu',
    'path' => 'menus/protected.json',
    'content' => Canon::encode([
        'items' => [], 'locations' => ['primary'], 'name' => 'Protected',
        'slug' => 'protected', 'uuid' => $protectedMenu,
    ]),
];
$menuTakeoverView = ScopedStateOverlay::stage_state_view($menuTakeoverRows);
try {
    expect_throw(
        static fn() => RepositoryCompiler::compile_staged($menuTakeoverView, $repo, $policy),
        'duplicate_menu_location',
        'mixed scoped candidate refuses selected menu takeover of a protected target location pre-authority'
    );
} finally {
    ScopedStateOverlay::discard_state_view($menuTakeoverView);
}

// Baseline v1 is still ScopeClosure's exact legacy report. Contract creation
// must neither change its version nor mutate its output.
$legacyBefore = ScopeClosure::resolve($compiled, $policy, ['post:' . $ids['page']]);
$legacyBytes = Canon::encode($legacyBefore);
$contract = ScopeContract::resolve($compiled, $policy, [
    'tombstone:' . $ids['tombstone'], 'post:' . $ids['page'], 'post:' . $ids['page'],
]);
$legacyAfter = ScopeClosure::resolve($compiled, $policy, ['post:' . $ids['page']]);
check($legacyBefore['format'] === 'duo-scope/v1' && Canon::encode($legacyAfter) === $legacyBytes,
    'legacy duo-scope/v1 output remains byte-compatible when --contract is absent');
check($contract['format'] === ScopeContract::FORMAT && $contract['read_only_evidence'] === true
    && $contract['mutation_authority'] === false,
    'contract is separately versioned, explicitly read-only evidence, and never mutation authority');
check($contract['selectors'] === ['post:' . $ids['page'], 'tombstone:' . $ids['tombstone']],
    'contract normalizes selector order and duplicates');
check($contract['resolution']['live_root_entities'] === [$ids['page']]
    && $contract['resolution']['tombstone_uuids'] === [$ids['tombstone']],
    'contract binds explicit normalized root resolution for live and tombstone identities');

$reordered = ScopeContract::resolve($compiled, $policy, ['post:' . $ids['page'], 'tombstone:' . $ids['tombstone']]);
check(Canon::encode($contract) === Canon::encode($reordered),
    'reordered or duplicate selectors produce byte-identical contract evidence');
check(ScopeContract::from_array($contract) === $contract, 'strict schema and intrinsic scope_hash verify the emitted contract');
ScopeContract::assert_associated($contract, $compiled, $policy);
check(true, 'association verifier recomputes the complete contract for the exact compiled artifact/policy');

$optionContract = ScopeContract::resolve($compiled, $policy, ['option:blogname']);
$optionRoot = $optionContract['live']['roots'][0] ?? null;
$compiledOptionRecords = OptionState::records((array) $compiled->tree()['options/core']['data']);
check(
    $optionContract['selectors'] === ['option:blogname']
        && $optionContract['resolution']['live_root_entities'] === ['options/core#blogname']
        && is_array($optionRoot)
        && ($optionRoot['entity'] ?? null) === 'options/core#blogname'
        && ($optionRoot['type'] ?? null) === 'option'
        && ($optionRoot['option'] ?? null) === 'blogname'
        && ($optionRoot['path'] ?? null) === 'options/core.json'
        && ($optionRoot['entity_hash'] ?? null) === OptionState::record_hash($compiledOptionRecords['blogname'])
        && ($optionRoot['source_hash'] ?? null) === $compiled->tree()['options/core']['source_hash']
        && $optionContract['eligible_surfaces'] === ['option:blogname']
        && !in_array('options/core', array_column($optionContract['live']['excluded'], 'entity'), true),
    'option-root contract binds the exact record plus owning source file without publishing whole-options authority'
);
ScopeContract::assert_associated($optionContract, $compiled, $policy);
check(ScopeContract::option_root_names($optionContract) === ['blogname'],
    'option-root discovery exposes only the exact selected option name');
$mixedOptionsContract = ScopeContract::resolve($compiled, $policy, ['options', 'option:blogname']);
ScopeContract::assert_mutation_supported($mixedOptionsContract, 'scoped promote');
check(
    !\Duo\ScopedApply::has_record_scoped_options($mixedOptionsContract)
        && isset(\Duo\ScopedApply::selected_set($mixedOptionsContract)['options/core'])
        && !isset(\Duo\ScopedApply::selected_set($mixedOptionsContract)['options/core#blogname']),
    'a redundant option selector under whole options retains whole-carrier semantics for every consumer'
);
$captureScopeContract = new ReflectionMethod(\Duo\Capture::class, 'scope_contract_for_request');
$captureDirectContract = $captureScopeContract->invoke(null, $optionContract, $compiled, $policy);
$captureCompactContract = $captureScopeContract->invoke(null, [
    'format' => 'duo-scope-request/v1',
    'scope_hash' => $optionContract['scope_hash'],
    'selectors' => $optionContract['selectors'],
], $compiled, $policy);
check(
    ($captureDirectContract['scope_hash'] ?? null) === $optionContract['scope_hash']
        && ($captureCompactContract['scope_hash'] ?? null) === $optionContract['scope_hash'],
    'capture target accepts direct and host-compact option-root evidence before its record-aware overlay'
);
expect_throw(
    static fn() => ScopeContract::assert_mutation_supported($optionContract, 'scoped promote'),
    'does not support per-option scoped mutation',
    'valid option-root evidence remains refused for a whole-document promotion consumer'
);
$resolvedOptionApplyContract = \Duo\ScopedApply::resolve_contract($optionContract, $compiled, $policy);
check(
    ($resolvedOptionApplyContract['scope_hash'] ?? null) === $optionContract['scope_hash']
        && \Duo\ScopedApply::has_record_scoped_options($resolvedOptionApplyContract),
    'agent scoped plan/apply associates valid option-root evidence for its record-aware carrier protocol'
);
$optionTargetRows = [];
foreach ($compiled->tree() as $identity => $row) {
    $content = (string) $row['content'];
    if ($identity === 'options/core') {
        $records = OptionState::records(Canon::decode($content));
        $records['blogname'] = OptionState::present('Production title', 'yes');
        $content = Canon::encode(OptionState::document($records));
    }
    $optionTargetRows[] = [
        'uuid' => (string) $identity,
        'type' => (string) $row['type'],
        'path' => (string) $row['path'],
        'content' => $content,
    ];
}
$optionTargetView = ScopedStateOverlay::stage_state_view($optionTargetRows);
try {
    $optionTarget = RepositoryCompiler::compile_staged($optionTargetView, $repo, $policy);
    ScopeContract::assert_candidate_bounded($optionContract, $optionTarget, $policy);
    check(true, 'option-root candidate validation permits a changed selected record without granting its carrier document wholesale authority');
} finally {
    ScopedStateOverlay::discard_state_view($optionTargetView);
}
$optionTargetActual = [];
foreach ($compiled->tree() as $identity => $row) {
    $content = (string) $row['content'];
    if ($identity === 'options/core') {
        $records = OptionState::records(Canon::decode($content));
        $records['blogname'] = OptionState::present('Production title', 'yes');
        $records['blogdescription'] = OptionState::present('protected sibling drift', 'yes');
        $content = Canon::encode(OptionState::document($records));
    }
    $optionTargetActual[(string) $identity] = [
        'type' => (string) $row['type'],
        'hash' => hash('sha256', $content),
        'path' => (string) $row['path'],
        'content' => $content,
    ];
}
$optionDecision = \Duo\ScopedApply::option_plan_decision(
    (array) $compiled->tree()['options/core']['data'],
    $optionTargetActual['options/core'],
    [],
    $optionContract,
    ['blogname']
);
$optionCandidateRow = \Duo\ScopedApply::target_option_candidate_row(
    $compiled->tree()['options/core'],
    $optionTargetActual['options/core'],
    $optionContract
);
$optionCandidateRecords = OptionState::records(Canon::decode((string) $optionCandidateRow['content']));
$optionStateHashes = \Duo\ScopedApply::option_state_hashes(
    (array) $compiled->tree()['options/core']['data'],
    $optionContract
);
$optionRecoveryRow = \Duo\ScopedApply::recovery_option_row([
    'uuid' => 'options/core',
    'type' => 'options',
    'path' => 'options/core.json',
    'retry' => true,
    'rebuild_option_names' => ['blogdescription'],
], ['blogname'], $compiled->tree()['options/core']);
$optionProjected = \Duo\ScopedApply::project_plan([
    'update' => [[
        'uuid' => 'options/core',
        'type' => 'options',
        'rebuild_option_names' => ['blogname'],
    ]],
    'unchanged' => [[
        'uuid' => 'post:' . $ids['page'],
        'type' => 'post',
    ]],
], $optionContract);
$optionBeforeRoot = \Duo\ScopedApply::selected_observation_root($optionTargetActual, $optionContract);
$siblingOnlyDrift = $optionTargetActual;
$siblingRecords = OptionState::records(Canon::decode((string) $siblingOnlyDrift['options/core']['content']));
$siblingRecords['blogdescription'] = OptionState::present('another protected sibling drift', 'yes');
$siblingOnlyDrift['options/core']['content'] = Canon::encode(OptionState::document($siblingRecords));
$siblingOnlyDrift['options/core']['hash'] = hash('sha256', $siblingOnlyDrift['options/core']['content']);
$desiredWithSiblingDrift = $optionTargetActual;
$desiredRecords = OptionState::records(Canon::decode((string) $desiredWithSiblingDrift['options/core']['content']));
$desiredRecords['blogname'] = $compiledOptionRecords['blogname'];
$desiredWithSiblingDrift['options/core']['content'] = Canon::encode(OptionState::document($desiredRecords));
$desiredWithSiblingDrift['options/core']['hash'] = hash('sha256', $desiredWithSiblingDrift['options/core']['content']);
check(
    ($optionDecision['bucket'] ?? null) === 'update'
        && (($optionDecision['row']['rebuild_option_names'] ?? null) === ['blogname'])
        && Canon::encode($optionCandidateRecords['blogname'] ?? null) === Canon::encode($compiledOptionRecords['blogname'])
        && Canon::encode($optionCandidateRecords['blogdescription'] ?? null)
            === Canon::encode(OptionState::present('protected sibling drift', 'yes'))
        && count($optionStateHashes) === 1
        && array_key_first($optionStateHashes) === \Duo\ScopedApply::option_state_identity('blogname')
        && strlen((string) array_key_first($optionStateHashes)) === 64
        && !isset($optionRecoveryRow['retry'])
        && $optionRecoveryRow['rebuild_option_names'] === ['blogname']
        && (($optionProjected['update'][0]['uuid'] ?? null) === 'options/core')
        && ($optionProjected['unchanged'] ?? null) === []
        && $optionBeforeRoot === \Duo\ScopedApply::selected_observation_root($siblingOnlyDrift, $optionContract)
        && \Duo\ScopedApply::authored_state(
            $desiredWithSiblingDrift, $compiled, $policy, $optionContract, $optionBeforeRoot
        ) === 'desired',
    'scoped plan/apply selects only the named option, overlays only that record onto the target carrier, and keeps recovery inside the selected virtual record'
);
$pageOptionContract = ScopeContract::resolve($compiled, $policy, ['option:page_on_front']);
$escapedOptionRows = [];
foreach ($compiled->tree() as $identity => $row) {
    $content = (string) $row['content'];
    if ($identity === 'options/core') {
        $records = OptionState::records(Canon::decode($content));
        $records['page_on_front'] = OptionState::present('{{post:' . $ids['otherAttachment'] . '}}', 'yes');
        $content = Canon::encode(OptionState::document($records));
    }
    $escapedOptionRows[] = [
        'uuid' => (string) $identity,
        'type' => (string) $row['type'],
        'path' => (string) $row['path'],
        'content' => $content,
    ];
}
$escapedOptionView = ScopedStateOverlay::stage_state_view($escapedOptionRows);
try {
    $escapedOption = RepositoryCompiler::compile_staged($escapedOptionView, $repo, $policy);
    expect_throw(
        static fn() => ScopeContract::assert_candidate_bounded($pageOptionContract, $escapedOption, $policy),
        'dependency closure escaped',
        'option-root candidate validation re-walks selected references and refuses an excluded dependency'
    );
} finally {
    ScopedStateOverlay::discard_state_view($escapedOptionView);
}
$malformedOptionRoot = $optionContract;
$malformedOptionRoot['live']['roots'][0]['option'] = 'blogdescription';
$malformedOptionRootWithoutHash = $malformedOptionRoot;
unset($malformedOptionRootWithoutHash['scope_hash']);
$malformedOptionRoot['scope_hash'] = hash('sha256', Canon::encode($malformedOptionRootWithoutHash));
expect_throw(
    static fn() => ScopeContract::from_array($malformedOptionRoot),
    'option root is malformed',
    'strict contract parser rejects a synthetic option identity whose named record does not agree'
);
$optionInClosure = $optionContract;
$optionInClosure['live']['closure'] = $optionInClosure['live']['roots'];
$optionInClosure['live']['closure'][0]['provenance'] = [
    'kind' => 'closure', 'from' => 'options/core#blogname', 'from_path' => 'options/core.json',
    'locator' => 'records.blogname', 'reason' => 'ref',
];
$optionInClosure['live']['roots'] = [];
$optionInClosureWithoutHash = $optionInClosure;
unset($optionInClosureWithoutHash['scope_hash']);
$optionInClosure['scope_hash'] = hash('sha256', Canon::encode($optionInClosureWithoutHash));
expect_throw(
    static fn() => ScopeContract::from_array($optionInClosure),
    'option evidence must be a root',
    'strict contract parser refuses a synthetic option proof placed outside the root boundary'
);

$contractPath = "$tmp/selected.scope.json";
put($contractPath, Canon::encode($contract));
$fakeBin = "$tmp/fake-bin";
mkdir($fakeBin, 0700, true);
$forwardedPath = "$tmp/capture-forwarded.json";
put("$fakeBin/wp", "#!/usr/bin/env php\n<?php file_put_contents(getenv('DUO_CAPTURE_FORWARDED'), json_encode(array_slice(\$argv, 1)));\n");
chmod("$fakeBin/wp", 0700);
put("$tmp/envs.json", json_encode(['envs' => ['fixture' => [
    'transport' => 'local', 'wp_path' => "$tmp/wordpress", 'repo_path' => '/target/repo',
]]], JSON_UNESCAPED_SLASHES));
$oldPath = getenv('PATH') ?: '';
putenv("PATH=$fakeBin:$oldPath");
putenv("DUO_CAPTURE_FORWARDED=$forwardedPath");
$command = [PHP_BINARY, "$root/cli/duo", "--envs-file=$tmp/envs.json", 'capture', 'fixture', "--scope-contract=$contractPath"];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$captureExit = -1;
$captureStderr = '';
if (is_resource($process)) {
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $captureStderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $captureExit = proc_close($process);
}
putenv("PATH=$oldPath");
putenv('DUO_CAPTURE_FORWARDED');
$forwardedArgs = is_file($forwardedPath) ? json_decode((string) file_get_contents($forwardedPath), true) : null;
$wire = null;
foreach ((array) $forwardedArgs as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--scope-request-b64=')) {
        $wire = json_decode((string) base64_decode(substr($arg, strlen('--scope-request-b64=')), true), true);
    }
}
check($captureExit === 0 && is_array($forwardedArgs)
    && !in_array("--scope-contract=$contractPath", $forwardedArgs, true)
    && is_array($wire) && ($wire['scope_hash'] ?? null) === $contract['scope_hash']
    && ($wire['selectors'] ?? null) === $contract['selectors'],
    'host validates the local contract and forwards only selectors+scope_hash, never its machine-local path'
        . ' (exit=' . $captureExit . ' args=' . json_encode($forwardedArgs) . ' stderr=' . trim($captureStderr) . ')');

$badContractPath = "$tmp/tampered.scope.json";
$badContract = $contract;
$badContract['scope_hash'] = str_repeat('0', 64);
put($badContractPath, Canon::encode($badContract));
@unlink($forwardedPath);
putenv("PATH=$fakeBin:$oldPath");
putenv("DUO_CAPTURE_FORWARDED=$forwardedPath");
$badCommand = [
    PHP_BINARY, "$root/cli/duo", "--envs-file=$tmp/envs.json", 'capture', 'fixture',
    "--scope-contract=$badContractPath", '--format=json',
];
$badProcess = proc_open($badCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $badPipes);
$badExit = -1;
$badStdout = '';
if (is_resource($badProcess)) {
    fclose($badPipes[0]);
    $badStdout = stream_get_contents($badPipes[1]) ?: '';
    stream_get_contents($badPipes[2]);
    fclose($badPipes[1]);
    fclose($badPipes[2]);
    $badExit = proc_close($badProcess);
}
putenv("PATH=$oldPath");
putenv('DUO_CAPTURE_FORWARDED');
$badRefusal = json_decode(trim($badStdout), true);
check($badExit !== 0 && is_array($badRefusal)
    && ($badRefusal['format'] ?? null) === 'duo-command-refusal/v1'
    && ($badRefusal['reason_code'] ?? null) === 'scope_contract_invalid'
    && !is_file($forwardedPath)
    && !str_contains($badStdout, $badContractPath),
    'tampered local capture contract returns one redacted JSON refusal before target invocation');

$unknownKey = $contract;
$unknownKey['unexpected'] = true;
expect_throw(static fn() => ScopeContract::from_array($unknownKey), 'unexpected schema',
    'strict contract parser refuses unknown top-level keys');
$malformedHash = $contract;
$malformedHash['source']['artifact_hash'] = 'not-a-sha256';
$withoutMalformedHash = $malformedHash;
unset($withoutMalformedHash['scope_hash']);
$malformedHash['scope_hash'] = hash('sha256', Canon::encode($withoutMalformedHash));
expect_throw(static fn() => ScopeContract::from_array($malformedHash), 'artifact_hash must be a lowercase SHA-256 hash',
    'strict contract parser refuses malformed bound source fields even with a recomputed scope_hash');

$closureIds = array_column($contract['live']['closure'], 'entity');
check(in_array($ids['attachment'], $closureIds, true) && in_array($ids['term'], $closureIds, true),
    'live closure binds dependency identities with hashes and provenance');
check(count($contract['live']['inbound']) === 1
    && $contract['live']['inbound'][0]['entity'] === 'options/core'
    && $contract['live']['inbound'][0]['target'] === $ids['page'],
    'inbound referrer evidence is bound with source/target hashes rather than discarded');
check(in_array($ids['otherAttachment'], array_column($contract['live']['excluded'], 'entity'), true),
    'excluded live rows and their exclusion reason are bound into the contract');
check($contract['uploads'] !== [] && $contract['uploads'][0]['attachment_uuid'] === $ids['attachment']
    && $contract['media'] === [['name' => "$hash.txt", 'sha256' => $hash]],
    'uploads and media are filtered to the resolved closure rather than every artifact blob');

$potentialEffects = array_column($contract['potential_effects'], 'effect');
$effectIds = array_column($potentialEffects, 'id');
check(in_array('scope-contract-page-action', $effectIds, true)
    && in_array('scope-contract-provider-action', $effectIds, true)
    && !in_array('scope-contract-product-action', $effectIds, true),
    'potential effects include exact eligible action declarations and exclude unrelated trigger rows');
check(count($contract['potential_providers']) === 1
    && $contract['potential_providers'][0]['id'] === 'scope-contract-provider',
    'provider declaration is potential evidence only when an eligible trigger reaches it');
check(in_array('post:page', $contract['eligible_surfaces'], true)
    && !in_array('post:product', $contract['eligible_surfaces'], true),
    'eligible canonical surfaces share exact trigger vocabulary and do not broaden to unrelated types');
check($contract['tombstones'][0]['tombstone_hash'] !== ''
    && $contract['tombstones'][0]['policy_deletion_obligations']['static_only'] === true
    && $contract['tombstones'][0]['policy_deletion_obligations']['guards'] !== []
    && !array_key_exists('target_guard_witnesses', $contract['tombstones'][0]['policy_deletion_obligations']),
    'tombstone evidence binds exact static deletion capability/cascades/guards without target guard witnesses');

$liveOnly = ScopeContract::resolve($compiled, $policy, ['post:' . $ids['page']]);
check($liveOnly['tombstones'] === [] && $liveOnly['resolution']['tombstone_uuids'] === [],
    'live-only contract contains no tombstone inferred from a matching kind, source path, cascade, or graph edge');

$all = ScopeContract::resolve($compiled, $policy, ['tombstone:' . $ids['tombstone'], 'all', 'post:' . $ids['page']]);
check($all['selectors'] === ['all']
    && $all['resolution']['tombstone_uuids'] === [$ids['tombstone'], $ids['otherTombstone']]
    && in_array($ids['otherAttachment'], $all['resolution']['live_root_entities'], true),
    '`all` subsumes narrower selectors and includes every compiled tombstone/live root');
check(in_array('scope-contract-regenerator', array_column(array_column($all['potential_effects'], 'effect'), 'id'), true),
    'all contracts include the matching declared regenerator potential effect but no lifecycle rows');
check(!in_array('lifecycle', array_column($all['potential_effects'], 'phase'), true),
    'code/lifecycle effects are excluded from static scope effects');

expect_throw(
    static fn() => ScopeContract::resolve($compiled, $policy, ['tombstone:' . uuid(99)]),
    'names no compiled tombstone', 'missing exact tombstone selector is refused'
);

$cliSource = file_get_contents($root . '/agent/src/Cli.php');
$captureSlice = is_string($cliSource)
    ? strstr(strstr($cliSource, 'public function capture') ?: '', 'public function refresh_export', true)
    : false;
check(is_string($captureSlice) && is_string($cliSource) && str_contains($cliSource, '--scope-contract=<path>')
    && substr_count($captureSlice, "assoc['scope-contract']") === 3,
    'capture consumes the new scope-contract argument while later mutation verbs remain out of slice');
expect_throw(
    static fn() => ScopeContract::resolve($compiled, $policy, ['tombstone:not-a-uuid']),
    'must be exactly tombstone', 'malformed tombstone selector is refused'
);
expect_throw(
    static fn() => ScopeContract::resolve($compiled, $policy, ['delete:' . $ids['tombstone']]),
    'not mutation authority', 'legacy mutation-sounding delete selector is refused'
);

// A self-recomputed hash alone is not authority: full association re-resolves
// the closure and catches a deleted row/effect even when the attacker updates
// scope_hash to make intrinsic validation pass.
$tampered = $contract;
array_pop($tampered['live']['closure']);
$without = $tampered;
unset($without['scope_hash']);
$tampered['scope_hash'] = hash('sha256', Canon::encode($without));
check(ScopeContract::from_array($tampered) === $tampered,
    'intrinsic scope_hash can verify a structurally valid tampered object (control)');
expect_throw(
    static fn() => ScopeContract::assert_associated($tampered, $compiled, $policy),
    'complete resolved evidence', 'association verifier refuses recomputed-hash closure tampering'
);

// The target-bound overlay changes only selected identities. It starts from
// complete prior bytes, so an atomic full-tree swap cannot erase an omitted
// live row or tombstone.
$observed = [];
foreach ($compiled->tree() as $identity => $row) {
    $content = (string) $row['content'];
    if ($identity === $ids['page']) {
        $content = str_replace('<figure></figure>', '<figure class="captured"></figure>', $content);
    }
    $observed[] = [
        'uuid' => (string) $identity,
        'type' => (string) $row['type'],
        'path' => (string) $row['path'],
        'content' => $content,
    ];
}
$selectedDeletions = [];
foreach ($compiled->deletions() as $identity => $row) {
    $selectedDeletions[] = [
        'uuid' => (string) $identity, 'type' => 'deletion',
        'path' => (string) $row['path'], 'content' => (string) $row['content'],
    ];
}
$overlay = ScopedStateOverlay::project_capture_associated(
    $compiled, $contract, $observed, $selectedDeletions
);
$overlayDir = "$tmp/overlay-state";
foreach (array_merge($overlay['entities'], $overlay['deletions']) as $row) {
    put($overlayDir . '/' . $row['path'], $row['content']);
}
$overlayCompiled = RepositoryCompiler::compile_staged($overlayDir, $repo, $policy);
ScopedStateOverlay::assert_excluded_preserved($compiled, $overlayCompiled, $contract);
ScopeContract::assert_candidate_bounded($contract, $overlayCompiled, $policy);
check((string) $overlayCompiled->tree()[$ids['page']]['content'] !== (string) $compiled->tree()[$ids['page']]['content']
    && (string) $overlayCompiled->tree()[$ids['otherAttachment']]['content']
        === (string) $compiled->tree()[$ids['otherAttachment']]['content']
    && (string) $overlayCompiled->tree()['options/core']['content']
        === (string) $compiled->tree()['options/core']['content']
    && (string) $overlayCompiled->deletions()[$ids['otherTombstone']]['content']
        === (string) $compiled->deletions()[$ids['otherTombstone']]['content'],
    'scoped overlay updates selected bytes while preserving excluded attachment/options/tombstone bytes exactly');

// Exact option roots are virtual identities in a shared physical carrier.
// Capture must retain every source sibling while it accepts the observed
// selected record; copying the carrier as an excluded whole silently drops
// the selected change, while copying it whole would grant sibling authority.
$optionObserved = [];
foreach ($compiled->tree() as $identity => $row) {
    $content = (string) $row['content'];
    if ($identity === 'options/core') {
        $records = OptionState::records(Canon::decode($content));
        $records['blogname'] = OptionState::present('Captured title', 'yes');
        $records['blogdescription'] = OptionState::present('unselected target drift', 'yes');
        $content = Canon::encode(OptionState::document($records));
    }
    $optionObserved[] = [
        'uuid' => (string) $identity,
        'type' => (string) $row['type'],
        'path' => (string) $row['path'],
        'content' => $content,
    ];
}
$optionOverlay = ScopedStateOverlay::project_capture_associated(
    $compiled, $optionContract, $optionObserved, []
);
$optionOverlayState = "$tmp/option-overlay-state";
foreach (array_merge($optionOverlay['entities'], $optionOverlay['deletions']) as $row) {
    put($optionOverlayState . '/' . $row['path'], $row['content']);
}
$optionOverlayCompiled = RepositoryCompiler::compile_staged($optionOverlayState, $repo, $policy);
ScopedStateOverlay::assert_excluded_preserved($compiled, $optionOverlayCompiled, $optionContract);
ScopeContract::assert_candidate_bounded($optionContract, $optionOverlayCompiled, $policy);
$optionOverlayRecords = OptionState::records((array) $optionOverlayCompiled->tree()['options/core']['data']);
check(
    Canon::encode($optionOverlayRecords['blogname'] ?? null)
        === Canon::encode(OptionState::present('Captured title', 'yes'))
        && Canon::encode($optionOverlayRecords['blogdescription'] ?? null)
            === Canon::encode($compiledOptionRecords['blogdescription']),
    'record-aware scoped capture writes only the selected option while preserving an observed sibling drift byte-for-byte from source'
);

$badOptionOverlayRows = [];
foreach ($optionOverlayCompiled->tree() as $identity => $row) {
    $content = (string) $row['content'];
    if ($identity === 'options/core') {
        $records = OptionState::records(Canon::decode($content));
        $records['blogdescription'] = OptionState::present('forged sibling change', 'yes');
        $content = Canon::encode(OptionState::document($records));
    }
    $badOptionOverlayRows[] = [
        'uuid' => (string) $identity,
        'type' => (string) $row['type'],
        'path' => (string) $row['path'],
        'content' => $content,
    ];
}
$badOptionOverlayState = ScopedStateOverlay::stage_state_view($badOptionOverlayRows);
try {
    $badOptionOverlay = RepositoryCompiler::compile_staged($badOptionOverlayState, $repo, $policy);
    expect_throw(
        static fn() => ScopedStateOverlay::assert_excluded_preserved($compiled, $badOptionOverlay, $optionContract),
        "excluded option 'blogdescription'",
        'record-aware scoped capture rejects a changed excluded option sibling'
    );
} finally {
    ScopedStateOverlay::discard_state_view($badOptionOverlayState);
}

$captureSource = Canon::read_file("$root/agent/src/Capture.php");
$finalAssociation = strpos($captureSource, 'ScopeContract::assert_associated($scopeContract, $currentSource, $currentPolicy);');
$candidateCompile = strpos($captureSource, '$compiledCandidate = RepositoryCompiler::compile_staged(');
$firstMediaWrite = strpos($captureSource, "foreach (\$candidate['media'] as \$file => \$source)");
$sourceMediaView = strpos($captureSource, 'stage_associated_source_media_view(');
$beginIntent = strpos($captureSource, 'Publish::begin_intent($stateDir, $staging);');
check(is_int($candidateCompile) && is_int($firstMediaWrite) && is_int($sourceMediaView)
    && is_int($finalAssociation) && is_int($beginIntent)
    && $candidateCompile < $firstMediaWrite
    && $firstMediaWrite < $sourceMediaView
    && $sourceMediaView < $finalAssociation
    && $finalAssociation < $beginIntent,
    'scoped capture validates candidate media off-source, then re-associates after its own blob writes immediately before publication');
check(str_contains($captureSource, 'if ($scopeContract === null && $intoRepo)')
    && str_contains($captureSource, 'scoped capture publishes a bounded overlay into its associated repository; --out is unsupported'),
    'legacy output-only capture skips repo-media compilation while scoped --out refuses explicitly');
$refreshExportSource = Canon::read_file("$root/agent/src/RefreshExport.php");
check(str_contains($captureSource, "array_is_list(\$request['selectors'])")
    && str_contains($refreshExportSource, "array_is_list(\$request['selectors'])"),
    'compact capture and refresh requests require selector lists rather than accepting associative objects');

$newMediaBytes = "scope-contract-new-selected-media\n";
$newMediaName = hash('sha256', $newMediaBytes) . '.txt';
$newMediaObserved = $observed;
foreach ($newMediaObserved as &$row) {
    if (($row['uuid'] ?? null) === $ids['attachment']) {
        [$newMediaFront, $newMediaBody] = Canon::parse_post_file((string) $row['content']);
        $newMediaFront['media'] = $newMediaName;
        $row['content'] = Canon::post_file($newMediaFront, $newMediaBody);
    }
}
unset($row);
$newMediaOverlay = ScopedStateOverlay::project_capture_associated(
    $compiled, $contract, $newMediaObserved, $selectedDeletions
);
$newMediaState = "$tmp/new-media-overlay-state";
foreach (array_merge($newMediaOverlay['entities'], $newMediaOverlay['deletions']) as $row) {
    put($newMediaState . '/' . $row['path'], $row['content']);
}
$newMediaAddition = [$newMediaName => ['bytes' => $newMediaBytes]];
$candidateMediaView = ScopedStateOverlay::stage_candidate_media_view($repo, $newMediaAddition);
try {
    $newMediaCompiled = RepositoryCompiler::compile_staged(
        $newMediaState,
        $repo,
        $policy,
        $candidateMediaView
    );
} finally {
    ScopedStateOverlay::discard_media_view($candidateMediaView);
}
check(($newMediaCompiled->tree()[$ids['attachment']]['data']['media'] ?? null) === $newMediaName
    && !is_file("$repo/media/$newMediaName"),
    'selected new media validates against an immutable candidate view without changing the associated source artifact');

$literalMediaRow = [[
    'uuid' => $ids['custom'], 'type' => 'post',
    'path' => "posts/duo_contract/{$ids['custom']}--contract.md",
    'content' => Canon::post_file(
        front($ids['custom'], 'duo_contract', 'contract'),
        '<p>documentation literal ' . $otherHash . '.txt is not an attachment reference</p>'
    ),
]];
check(ScopedStateOverlay::selected_media(
    $literalMediaRow,
    ["$otherHash.txt" => ['bytes' => $otherBytes]]
) === [],
    'literal content-addressed filenames do not grant media authority without a selected attachment record');
$selectedAttachmentRow = array_values(array_filter(
    $observed,
    static fn(array $row): bool => ($row['uuid'] ?? null) === $ids['attachment']
));
check(array_keys(ScopedStateOverlay::selected_media(
    $selectedAttachmentRow,
    ["$hash.txt" => ['bytes' => $bytes], "$otherHash.txt" => ['bytes' => $otherBytes]]
)) === ["$hash.txt"],
    'selected media authority comes only from the selected attachment front matter');

put("$repo/media/$newMediaName", $newMediaBytes);
$sourceMediaView = ScopedStateOverlay::stage_associated_source_media_view(
    $repo,
    (array) ($compiled->export()['media_catalog'] ?? []),
    $newMediaAddition
);
try {
    $reassociatedSource = RepositoryCompiler::compile_for_diff($repo, $policy, $sourceMediaView);
    ScopeContract::assert_associated($contract, $reassociatedSource, $policy);
} finally {
    ScopedStateOverlay::discard_media_view($sourceMediaView);
}
check($reassociatedSource->artifact_hash() === $compiled->artifact_hash()
    && RepositoryCompiler::compile_for_diff($repo, $policy)->artifact_hash() !== $compiled->artifact_hash(),
    'post-write source fence ignores only the verified candidate addition and reconstructs the exact contract artifact');
$intruderBytes = "scope-contract-concurrent-media-drift\n";
$intruderName = hash('sha256', $intruderBytes) . '.txt';
put("$repo/media/$intruderName", $intruderBytes);
expect_throw(
    static fn() => ScopedStateOverlay::stage_associated_source_media_view(
        $repo,
        (array) ($compiled->export()['media_catalog'] ?? []),
        $newMediaAddition
    ),
    'inventory changed',
    'post-write source fence refuses a concurrent media addition outside the verified candidate set'
);
unlink("$repo/media/$intruderName");
unlink("$repo/media/$newMediaName");

$resurrected = $observed;
$resurrected[] = [
    'uuid' => $ids['tombstone'], 'type' => 'post',
    'path' => "posts/page/{$ids['tombstone']}--resurrected.md",
    'content' => Canon::post_file(front($ids['tombstone'], 'page', 'resurrected'), ''),
];
expect_throw(
    static fn() => ScopedStateOverlay::project_capture_associated(
        $compiled, $contract, $resurrected, $selectedDeletions
    ),
    'grants no resurrection authority',
    'selected immutable tombstone cannot become live scoped state'
);

$pageMissing = array_values(array_filter(
    $observed,
    static fn(array $row): bool => ($row['uuid'] ?? null) !== $ids['page']
));
$pageDeletion = [[
    'uuid' => $ids['page'], 'type' => 'deletion',
    'path' => "deletions/{$ids['page']}.json",
    'content' => Canon::encode([
        'format' => Deletion::FORMAT, 'uuid' => $ids['page'], 'kind' => 'post', 'type' => 'page',
        'expected_hash' => (string) $compiled->tree()[$ids['page']]['hash'],
        'expected_revision' => $compiled->revision_hash(),
        'source_path' => (string) $compiled->tree()[$ids['page']]['path'],
    ]),
]];
expect_throw(
    static fn() => ScopedStateOverlay::project_capture_associated(
        $compiled, $contract, $pageMissing, array_merge($selectedDeletions, $pageDeletion)
    ),
    'out-of-scope inbound reference',
    'selected deletion refuses when an excluded canonical row still refers inbound'
);

$deleteContract = ScopeContract::resolve($compiled, $policy, ['post:' . $ids['otherAttachment']]);
$attachmentMissing = array_values(array_filter(
    $observed,
    static fn(array $row): bool => ($row['uuid'] ?? null) !== $ids['otherAttachment']
));
$attachmentDeletion = [[
    'uuid' => $ids['otherAttachment'], 'type' => 'deletion',
    'path' => "deletions/{$ids['otherAttachment']}.json",
    'content' => Canon::encode([
        'format' => Deletion::FORMAT, 'uuid' => $ids['otherAttachment'], 'kind' => 'post', 'type' => 'attachment',
        'expected_hash' => (string) $compiled->tree()[$ids['otherAttachment']]['hash'],
        'expected_revision' => $compiled->revision_hash(),
        'source_path' => (string) $compiled->tree()[$ids['otherAttachment']]['path'],
    ]),
]];
$deletedOverlay = ScopedStateOverlay::project_capture_associated(
    $compiled, $deleteContract, $attachmentMissing, $attachmentDeletion
);
$deletedDir = "$tmp/selected-deletion-overlay-state";
foreach (array_merge($deletedOverlay['entities'], $deletedOverlay['deletions']) as $row) {
    put($deletedDir . '/' . $row['path'], $row['content']);
}
$deletedCompiled = RepositoryCompiler::compile_staged($deletedDir, $repo, $policy);
ScopedStateOverlay::assert_excluded_preserved($compiled, $deletedCompiled, $deleteContract);
ScopeContract::assert_candidate_bounded(
    $deleteContract, $deletedCompiled, $policy, [$ids['otherAttachment']]
);
check(isset($deletedCompiled->deletions()[$ids['otherAttachment']])
    && !isset($deletedCompiled->tree()[$ids['otherAttachment']]),
    'selected live deletion succeeds only as an explicitly authorized bounded tombstone');

$escapedRows = $overlay;
foreach ($escapedRows['entities'] as &$row) {
    if (($row['uuid'] ?? null) === $ids['page']) {
        $row['content'] = str_replace(
            '<figure class="captured"></figure>',
            '<!-- wp:image {"id":"{{post:' . $ids['otherAttachment'] . '}}"} --><figure class="captured"></figure><!-- /wp:image -->',
            (string) $row['content']
        );
    }
}
unset($row);
$escapedDir = "$tmp/escaped-overlay-state";
foreach (array_merge($escapedRows['entities'], $escapedRows['deletions']) as $row) {
    put($escapedDir . '/' . $row['path'], $row['content']);
}
$escapedCompiled = RepositoryCompiler::compile_staged($escapedDir, $repo, $policy);
expect_throw(
    static fn() => ScopeContract::assert_candidate_bounded($contract, $escapedCompiled, $policy),
    'closure escaped', 'selected target bytes cannot pull a new excluded dependency into the scope'
);

// A frozen closure row stays selected even if its old root edge disappears.
// It must still be re-walked: otherwise that detached selected row could gain
// a new excluded dependency behind the roots-only traversal.
$detachedRows = $overlay;
foreach ($detachedRows['entities'] as &$row) {
    if (($row['uuid'] ?? null) === $ids['page']) {
        [$frontMatter] = Canon::parse_post_file((string) $row['content']);
        $row['content'] = Canon::post_file($frontMatter, '<!-- wp:paragraph --><p>detached</p><!-- /wp:paragraph -->');
    }
    if (($row['uuid'] ?? null) === $ids['attachment']) {
        [$frontMatter] = Canon::parse_post_file((string) $row['content']);
        $row['content'] = Canon::post_file(
            $frontMatter,
            '<!-- wp:image {"id":"{{post:' . $ids['otherAttachment'] . '}}"} --><figure></figure><!-- /wp:image -->'
        );
    }
}
unset($row);
$detachedDir = "$tmp/detached-closure-overlay-state";
foreach (array_merge($detachedRows['entities'], $detachedRows['deletions']) as $row) {
    put($detachedDir . '/' . $row['path'], $row['content']);
}
$detachedCompiled = RepositoryCompiler::compile_staged($detachedDir, $repo, $policy);
expect_throw(
    static fn() => ScopeContract::assert_candidate_bounded($contract, $detachedCompiled, $policy),
    'closure escaped',
    'every retained selected closure row is re-walked after its original root edge disappears'
);

// A target can gain a mapped, otherwise valid declared child after the
// source contract was minted. The overlay would omit that target-only row,
// so the complete target probe must reject it before projection.
$customContract = ScopeContract::resolve($compiled, $policy, ['post:' . $ids['custom']]);
$targetOnlyChild = uuid(8);
$childFront = front($targetOnlyChild, 'duo_child', 'target-only-child');
$childFront['parent'] = '{{post:' . $ids['custom'] . '}}';
$targetObserved = $observed;
$targetObserved[] = [
    'uuid' => $targetOnlyChild, 'type' => 'post',
    'path' => "posts/duo_child/$targetOnlyChild--target-only-child.md",
    'content' => Canon::post_file($childFront, ''),
];
$targetProbeState = ScopedStateOverlay::stage_state_view(
    ScopedStateOverlay::target_probe_rows($compiled, $targetObserved, $selectedDeletions)
);
try {
    $targetProbeCompiled = RepositoryCompiler::compile_staged($targetProbeState, $repo, $policy);
    expect_throw(
        static fn() => ScopeContract::assert_candidate_bounded($customContract, $targetProbeCompiled, $policy),
        'closure escaped',
        'complete target observation refuses a newly declared child outside the immutable source closure'
    );
    $allContract = ScopeContract::resolve($compiled, $policy, ['all']);
    expect_throw(
        static fn() => ScopeContract::assert_candidate_bounded($allContract, $targetProbeCompiled, $policy),
        'outside the immutable source contract',
        '`all` remains a strict state-only contract and refuses a target identity minted after source association'
    );
} finally {
    ScopedStateOverlay::discard_state_view($targetProbeState);
}

$menuContract = ScopeContract::resolve($compiled, $policy, ['menu:main']);
$menuInboundPost = uuid(11);
$menuInboundObserved = $observed;
$menuInboundObserved[] = [
    'uuid' => $menuInboundPost, 'type' => 'post',
    'path' => "posts/page/$menuInboundPost--menu-item-referrer.md",
    'content' => Canon::post_file(
        front($menuInboundPost, 'page', 'menu-item-referrer'),
        '<p>{{post:' . $ids['menuItem'] . '}}</p>'
    ),
];
$menuProbeState = ScopedStateOverlay::stage_state_view(
    ScopedStateOverlay::target_probe_rows($compiled, $menuInboundObserved, $selectedDeletions)
);
try {
    $menuProbeCompiled = RepositoryCompiler::compile_staged($menuProbeState, $repo, $policy);
    expect_throw(
        static fn() => ScopeContract::assert_candidate_bounded(
            $menuContract,
            $menuProbeCompiled,
            $policy,
            [$ids['menu']]
        ),
        'out-of-scope inbound reference',
        'target inbound deletion guard maps nested menu-item UUIDs to their selected owner'
    );
} finally {
    ScopedStateOverlay::discard_state_view($menuProbeState);
}

// Scoped refresh exports omit unrelated P rows explicitly, then materialize
// by overlaying selected P bytes onto the complete exact W baseline.
$snapshotOf = static function (Duo\CompiledRepository $artifact, string $format = 'duo-refresh-git/v1') use ($contract, $repo): array {
    $records = [];
    foreach ($artifact->tree() as $identity => $row) {
        $records[(string) $identity] = [
            'identity' => (string) $identity, 'type' => (string) $row['type'],
            'path' => (string) $row['path'], 'hash' => (string) $row['hash'],
            'content' => (string) $row['content'],
        ];
    }
    $deletions = [];
    foreach ($artifact->deletions() as $identity => $row) {
        $deletions[(string) $identity] = [
            'identity' => (string) $identity, 'type' => 'deletion',
            'path' => (string) $row['path'], 'hash' => (string) $row['hash'],
            'content' => (string) $row['content'],
        ];
    }
    $export = $artifact->export();
    $media = [];
    foreach ((array) ($export['media_catalog'] ?? []) as $name => $expected) {
        $payload = Canon::read_file("$repo/media/$name");
        if (!hash_equals((string) $expected, hash('sha256', $payload))) {
            throw new RuntimeException("fixture media '$name' does not verify");
        }
        $media[(string) $name] = ['sha256' => (string) $expected, 'base64' => base64_encode($payload)];
    }
    return [
        'format' => $format,
        'records' => $records,
        'deletions' => $deletions,
        'media' => $media,
        'policy' => [
            'site_hash' => $artifact->site_hash(), 'manifest_hash' => $artifact->manifest_hash(),
            'resolved_adapters' => $artifact->resolved_adapters(),
        ],
        'completed_code' => null,
        'repository' => [
            'artifact_hash' => $artifact->artifact_hash(),
            'revision_hash' => $artifact->revision_hash(), 'code_revision' => null,
        ],
    ];
};
$baseSnapshot = $snapshotOf($compiled);
$branchSnapshot = $snapshotOf($compiled);
$productionSnapshot = $snapshotOf($overlayCompiled, 'duo-refresh-production/v1');
$selectedSet = array_fill_keys(ScopedStateOverlay::selected_identities($contract), true);
$productionSnapshot['records'] = array_filter(
    $productionSnapshot['records'],
    static fn(string $identity): bool => isset($selectedSet[$identity]),
    ARRAY_FILTER_USE_KEY
);
$productionSnapshot['deletions'] = array_filter(
    $productionSnapshot['deletions'],
    static fn(string $identity): bool => isset($selectedSet[$identity]),
    ARRAY_FILTER_USE_KEY
);
$refreshLiteralBytes = "scope-refresh-literal-only-media\n";
$refreshLiteralName = hash('sha256', $refreshLiteralBytes) . '.txt';
$productionPage = $productionSnapshot['records'][$ids['page']];
[$productionPageFront, $productionPageBody] = Canon::parse_post_file((string) $productionPage['content']);
$productionPage['content'] = Canon::post_file(
    $productionPageFront,
    $productionPageBody . "\n<p>literal $refreshLiteralName is not attachment authority</p>"
);
$productionPage['hash'] = hash('sha256', $productionPage['content']);
$productionSnapshot['records'][$ids['page']] = $productionPage;
$productionSnapshot['media'][$refreshLiteralName] = [
    'sha256' => hash('sha256', $refreshLiteralBytes),
    'base64' => base64_encode($refreshLiteralBytes),
];
$productionSnapshot['scope'] = [
    'format' => 'duo-refresh-scope/v1', 'scope_hash' => $contract['scope_hash'],
    'source' => $contract['source'], 'selectors' => $contract['selectors'],
    'selected_identities' => array_keys($selectedSet), 'out_of_scope' => 'omitted_not_absent',
];
$productionBasis = $productionSnapshot;
$productionSnapshot['snapshot_hash'] = hash('sha256', Canon::encode($productionBasis));
$scopedPlan = RefreshPlan::plan($baseSnapshot, $productionSnapshot, $branchSnapshot, [
    'base_commit' => str_repeat('1', 40), 'branch_commit' => str_repeat('2', 40),
    'production_commit' => str_repeat('3', 40), 'production_snapshot_hash' => $productionSnapshot['snapshot_hash'],
    'scope_contract' => $contract,
]);
$planByIdentity = [];
foreach ($scopedPlan['entries'] as $entry) {
    $planByIdentity[(string) $entry['identity']] = $entry;
}
check(($planByIdentity[$ids['otherAttachment']]['in_scope'] ?? null) === false
    && ($planByIdentity[$ids['otherAttachment']]['production_omitted'] ?? null) === true
    && ($planByIdentity[$ids['otherAttachment']]['selected_source'] ?? null) === 'branch'
    && ($planByIdentity[$ids['page']]['in_scope'] ?? null) === true,
    'scoped refresh treats omitted production rows as branch-preserved, never target absence');

$refreshTree = "$tmp/scoped-refresh-worktree";
mkdir($refreshTree, 0700, true);
put("$refreshTree/.git", "gitdir: disposable\n");
put("$refreshTree/site.duo.json", Canon::read_file("$repo/site.duo.json"));
$refreshReceipt = RefreshPlan::materialize($scopedPlan, $refreshTree);
check(($refreshReceipt['scope_hash'] ?? null) === $contract['scope_hash']
    && Canon::read_file("$refreshTree/state/" . $compiled->tree()[$ids['page']]['path'])
        === (string) $productionPage['content']
    && Canon::read_file("$refreshTree/state/" . $compiled->tree()[$ids['otherAttachment']]['path'])
        === (string) $compiled->tree()[$ids['otherAttachment']]['content']
    && Canon::read_file("$refreshTree/state/options/core.json")
        === (string) $compiled->tree()['options/core']['content']
    && Canon::read_file("$refreshTree/media/$otherHash.txt") === $otherBytes,
    'scoped refresh overlays selected P and preserves excluded W state/options/media bytes exactly');
check(Canon::read_file("$refreshTree/media/$orphanHash.txt") === $orphanBytes,
    'scoped refresh preserves an unreferenced content-addressed W media blob byte-for-byte');
check(!is_file("$refreshTree/media/$refreshLiteralName"),
    'scoped refresh does not materialize a media blob named only as literal selected-post text');

// A selected option is still carried as one record inside options/core.json,
// but scoped refresh must not silently widen that source fact to every sibling
// record. Production exports only the selected record; the plan marks every
// sibling omitted, and materialization overlays that one record onto W's exact
// whole document.
$optionsContract = $optionContract;
$optionsBase = $snapshotOf($compiled);
$optionsBranch = $optionsBase;
$optionsProduction = $optionsBase;
$branchOptionsContent = options([
    'blogdescription' => OptionState::present('branch-only description', 'yes'),
    'blogname' => OptionState::present('Scope Contract', 'yes'),
    'page_on_front' => OptionState::present('{{post:' . $ids['page'] . '}}', 'yes'),
    'show_on_front' => OptionState::present('branch-genuine-conflict', 'yes'),
]);
$productionOptionsContent = Canon::encode(OptionState::document([
    'blogname' => OptionState::present('production-only title', 'yes'),
]));
foreach ([
    [&$optionsBranch, $branchOptionsContent],
    [&$optionsProduction, $productionOptionsContent],
] as [&$snapshot, $content]) {
    $snapshot['records']['options/core']['content'] = $content;
    $snapshot['records']['options/core']['hash'] = hash('sha256', $content);
}
unset($snapshot);
$optionsProduction['format'] = 'duo-refresh-production/v1';
$optionsSelectedSet = array_fill_keys(ScopedStateOverlay::selected_identities($optionsContract), true);
$optionsProduction['records'] = [
    'options/core' => $optionsProduction['records']['options/core'],
];
$optionsProduction['deletions'] = array_filter(
    $optionsProduction['deletions'],
    static fn(string $identity): bool => isset($optionsSelectedSet[$identity]),
    ARRAY_FILTER_USE_KEY
);
$optionsProduction['scope'] = [
    'format' => 'duo-refresh-scope/v1', 'scope_hash' => $optionsContract['scope_hash'],
    'source' => $optionsContract['source'], 'selectors' => $optionsContract['selectors'],
    'selected_identities' => array_keys($optionsSelectedSet), 'out_of_scope' => 'omitted_not_absent',
];
$optionsProductionBasis = $optionsProduction;
unset($optionsProductionBasis['snapshot_hash']);
$optionsProduction['snapshot_hash'] = hash('sha256', Canon::encode($optionsProductionBasis));
$optionsContext = [
    'base_commit' => str_repeat('4', 40), 'branch_commit' => str_repeat('5', 40),
    'production_commit' => str_repeat('6', 40),
    'production_snapshot_hash' => $optionsProduction['snapshot_hash'],
    'scope_contract' => $optionsContract,
];
$optionsPlan = RefreshPlan::plan($optionsBase, $optionsProduction, $optionsBranch, $optionsContext);
$optionsById = [];
foreach ($optionsPlan['entries'] as $entry) {
    $optionsById[(string) ($entry['identity'] ?? '')] = $entry;
}
check(!isset($optionsById['options/core'])
    && isset($optionsById['option:blogname'], $optionsById['option:blogdescription'], $optionsById['option:show_on_front']),
    'scoped options plan decomposes into per-option identities, never a bare whole-file options/core entry');
check(($optionsById['option:blogname']['category'] ?? null) === 'production-only'
    && ($optionsById['option:blogdescription']['category'] ?? null) === 'branch-only'
    && ($optionsById['option:page_on_front']['in_scope'] ?? null) === false
    && ($optionsById['option:active_plugins']['in_scope'] ?? null) === false
    && ($optionsById['option:show_on_front']['in_scope'] ?? null) === false,
    'one option-root makes only that record mutable while sibling option identities remain omitted production state');
check($optionsPlan['unresolved'] === [],
    'an excluded sibling conflict cannot force a resolution decision for the selected option root');

$optionsResolved = $optionsPlan;
$optionsTree = "$tmp/scoped-options-worktree";
mkdir($optionsTree, 0700, true);
put("$optionsTree/.git", "gitdir: disposable\n");
put("$optionsTree/site.duo.json", Canon::read_file("$repo/site.duo.json"));
RefreshPlan::materialize($optionsResolved, $optionsTree);
$mergedOptions = OptionState::records(Canon::decode(Canon::read_file("$optionsTree/state/options/core.json")));
check(($mergedOptions['blogname']['value'] ?? null) === 'production-only title'
    && ($mergedOptions['blogdescription']['value'] ?? null) === 'branch-only description'
    && ($mergedOptions['show_on_front']['value'] ?? null) === 'branch-genuine-conflict'
    && ($mergedOptions['page_on_front']['value'] ?? null) === '{{post:' . $ids['page'] . '}}'
    && ($mergedOptions['active_plugins']['state'] ?? null) === 'absent',
    'option-root refresh overlays only its production record and preserves branch bytes for every excluded sibling option');

// validateMaterialization() recompiles an actual linked Git worktree and
// then compares its bytes to the exact branch baseline. This must allow the
// selected option to alter the physical options/core carrier while still
// retaining every excluded sibling record. A fake planner cannot cover this
// strict candidate-validation boundary.
$strictGitSource = "$tmp/scoped-options-strict-source";
$strictGitWorktree = "$tmp/scoped-options-strict-worktree";
mkdir($strictGitSource, 0700, true);
put("$strictGitSource/.keep", "scope contract strict validation fixture\n");
$runGit = static function (array $command): void {
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start Git fixture command');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Git fixture command failed: ' . implode(' ', $command) . "\n$stdout$stderr");
    }
};
$runGit(['git', 'init', $strictGitSource]);
$runGit(['git', '-C', $strictGitSource, 'config', 'user.email', 'scope-contract@example.test']);
$runGit(['git', '-C', $strictGitSource, 'config', 'user.name', 'Scope Contract']);
$runGit(['git', '-C', $strictGitSource, 'add', '.keep']);
$runGit(['git', '-C', $strictGitSource, 'commit', '-m', 'fixture source']);
$runGit(['git', '-C', $strictGitSource, 'worktree', 'add', '--detach', $strictGitWorktree, 'HEAD']);
put("$strictGitWorktree/site.duo.json", Canon::read_file("$repo/site.duo.json"));
if (!is_dir("$strictGitWorktree/manifests")) mkdir("$strictGitWorktree/manifests", 0700, true);
copy("$manifestDir/core.json", "$strictGitWorktree/manifests/core.json");
copy("$manifestDir/scope-contract-fixture.json", "$strictGitWorktree/manifests/scope-contract-fixture.json");
$strictReceipt = RefreshPlan::materialize($optionsPlan, $strictGitWorktree);
$runGit(['git', '-C', $strictGitWorktree, 'add', '--all']);
$runGit(['git', '-C', $strictGitWorktree, 'commit', '-m', 'materialized option root']);
try {
    RefreshPlan::validateMaterialization($strictReceipt, $optionsPlan, $strictGitWorktree);
    check(true, 'strict candidate validation permits a selected option-root materialization and preserves excluded siblings');
} catch (Throwable $failure) {
    check(false, 'strict candidate validation accepts the valid option-root carrier change (' . $failure->getMessage() . ')');
}

$removedOptionProduction = $optionsProduction;
$removedOptionContent = Canon::encode(OptionState::document([
    'blogname' => OptionState::absent(),
]));
$removedOptionProduction['records']['options/core']['content'] = $removedOptionContent;
$removedOptionProduction['records']['options/core']['hash'] = hash('sha256', $removedOptionContent);
$removedBasis = $removedOptionProduction;
unset($removedBasis['snapshot_hash']);
$removedOptionProduction['snapshot_hash'] = hash('sha256', Canon::encode($removedBasis));
$removedPlan = RefreshPlan::plan(
    $optionsBase,
    $removedOptionProduction,
    $optionsBase,
    array_replace($optionsContext, ['production_snapshot_hash' => $removedOptionProduction['snapshot_hash']])
);
$removedById = [];
foreach ($removedPlan['entries'] as $entry) {
    $removedById[(string) ($entry['identity'] ?? '')] = $entry;
}
check(($removedById['option:blogname']['category'] ?? null) === 'production-only'
    && ($removedById['option:blogname']['selected_source'] ?? null) === 'production'
    && ($removedById['option:blogname']['selected']['content'] ?? null) === Canon::encode(OptionState::absent()),
    'a scoped option explicitly turned absent() on production is a valid production-only resolution, not absence without tombstone authority');
$removedTree = "$tmp/scoped-options-removed-worktree";
mkdir($removedTree, 0700, true);
put("$removedTree/.git", "gitdir: disposable\n");
put("$removedTree/site.duo.json", Canon::read_file("$repo/site.duo.json"));
RefreshPlan::materialize($removedPlan, $removedTree);
$removedOptions = OptionState::records(Canon::decode(Canon::read_file("$removedTree/state/options/core.json")));
check(($removedOptions['blogname']['state'] ?? null) === 'absent'
    && ($removedOptions['show_on_front']['value'] ?? null) === 'page'
    && ($removedOptions['active_plugins']['state'] ?? null) === 'absent',
    'materializing the resolved plan writes blogname as absent() while leaving every other option byte-for-byte untouched');

// A scope contract can select options/core even when a PARTICULAR
// checkout's baseline never captured one at all (e.g. a fresh site with no
// authored option ever captured, or a branch created before this repo
// authored any). materializeScoped() must synthesize a fresh document
// instead of assuming a baseline row exists to seed from.
$noOptionsBase = $optionsBase;
unset($noOptionsBase['records']['options/core']);
$noOptionsBranch = $noOptionsBase;
$newOptionContent = options(['blogname' => OptionState::present('brand-new-title', 'yes')]);
$noOptionsProduction = $noOptionsBase;
$noOptionsProduction['records']['options/core'] = [
    'identity' => 'options/core', 'type' => 'options', 'path' => 'options/core.json',
    'hash' => hash('sha256', $newOptionContent), 'content' => $newOptionContent,
];
$noOptionsProduction['format'] = 'duo-refresh-production/v1';
$noOptionsProduction['records'] = [
    'options/core' => $noOptionsProduction['records']['options/core'],
];
$noOptionsProduction['deletions'] = array_filter(
    $noOptionsProduction['deletions'],
    static fn(string $identity): bool => isset($optionsSelectedSet[$identity]),
    ARRAY_FILTER_USE_KEY
);
$noOptionsProduction['scope'] = $optionsProduction['scope'];
$noOptionsProductionBasis = $noOptionsProduction;
unset($noOptionsProductionBasis['snapshot_hash']);
$noOptionsProduction['snapshot_hash'] = hash('sha256', Canon::encode($noOptionsProductionBasis));
$noOptionsPlan = RefreshPlan::plan(
    $noOptionsBase,
    $noOptionsProduction,
    $noOptionsBranch,
    array_replace($optionsContext, ['production_snapshot_hash' => $noOptionsProduction['snapshot_hash']])
);
$noOptionsTree = "$tmp/scoped-options-no-baseline-worktree";
mkdir($noOptionsTree, 0700, true);
put("$noOptionsTree/.git", "gitdir: disposable\n");
put("$noOptionsTree/site.duo.json", Canon::read_file("$repo/site.duo.json"));
RefreshPlan::materialize($noOptionsPlan, $noOptionsTree);
$noBaselineOptions = OptionState::records(Canon::decode(Canon::read_file("$noOptionsTree/state/options/core.json")));
check(($noBaselineOptions['blogname']['value'] ?? null) === 'brand-new-title'
    && !array_key_exists('show_on_front', $noBaselineOptions),
    'option-root refresh synthesizes a fresh options/core document containing only its selected record when no baseline exists');

// Selected source bytes and immutable policy/action/effect bytes each flow
// into a new compiled binding and contract scope hash.
$firstHash = $contract['scope_hash'];
$pageBytes = file_get_contents("$repo/state/posts/page/{$ids['page']}--scope-page.md");
put("$repo/state/posts/page/{$ids['page']}--scope-page.md", str_replace('Scope-page', 'Scope-page-revised', (string) $pageBytes));
$changedCompiled = RepositoryCompiler::compile($repo, $policy);
$changedBytes = ScopeContract::resolve($changedCompiled, $policy, ['post:' . $ids['page'], 'tombstone:' . $ids['tombstone']]);
check($changedBytes['source']['artifact_hash'] !== $contract['source']['artifact_hash']
    && $changedBytes['scope_hash'] !== $firstHash,
    'selected canonical bytes change artifact binding and scope_hash');
expect_throw(
    static fn() => ScopeContract::assert_associated($contract, $changedCompiled, $policy),
    'not associated', 'old contract refuses a different compiled artifact association'
);

$otherPath = "$repo/state/posts/attachment/{$ids['otherAttachment']}--other-media.md";
$otherBytes = file_get_contents($otherPath);
put($otherPath, str_replace('Other-media', 'Other-media-revised', (string) $otherBytes));
$excludedChangedCompiled = RepositoryCompiler::compile($repo, $policy);
$excludedChanged = ScopeContract::resolve(
    $excludedChangedCompiled,
    $policy,
    ['post:' . $ids['page'], 'tombstone:' . $ids['tombstone']]
);
check($excludedChanged['source']['artifact_hash'] !== $changedBytes['source']['artifact_hash']
    && $excludedChanged['scope_hash'] !== $changedBytes['scope_hash']
    && $excludedChanged['live']['excluded'] !== $changedBytes['live']['excluded'],
    'an unrelated excluded live-byte change still changes bound artifact and scope evidence');
expect_throw(
    static fn() => ScopeContract::assert_associated($changedBytes, $excludedChangedCompiled, $policy),
    'not associated', 'old association refuses after an excluded live row changes'
);

$fixtureManifest['actions'][0]['effects'][0]['id'] = 'scope-contract-page-action-revised';
put("$manifestDir/scope-contract-fixture.json", Canon::encode($fixtureManifest));
$changedPolicy = Policy::load($repo);
$policyCompiled = RepositoryCompiler::compile($repo, $changedPolicy);
$changedPolicyContract = ScopeContract::resolve($policyCompiled, $changedPolicy, ['post:' . $ids['page'], 'tombstone:' . $ids['tombstone']]);
check($changedPolicyContract['source']['manifest_hash'] !== $changedBytes['source']['manifest_hash']
    && $changedPolicyContract['scope_hash'] !== $changedBytes['scope_hash']
    && in_array('scope-contract-page-action-revised', array_column(array_column($changedPolicyContract['potential_effects'], 'effect'), 'id'), true),
    'policy/action/effect bytes change manifest binding, potential evidence, and scope_hash');
expect_throw(
    static fn() => ScopeContract::assert_associated($changedBytes, $policyCompiled, $changedPolicy),
    'not associated', 'old policy/action evidence refuses a different compiled policy association'
);

if ($failures > 0) {
    fwrite(STDERR, "FAIL: $failures scope-contract regression assertion(s) failed\n");
    exit(1);
}
echo "ALL SCOPE CONTRACT REGRESSIONS PASSED\n";
