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
] as $file) {
    require_once "$root/agent/src/$file.php";
}

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
            'regen_dependency' => [
                'regenerator' => 'scope-contract-probe',
                'verify' => ['table' => 'duo_contract_index', 'column' => 'post_id'],
                'effects' => [effect('scope-contract-regenerator')],
            ],
        ],
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
    'custom' => uuid(5), 'tombstone' => uuid(6),
];
$repo = "$tmp/repo";
put("$repo/site.duo.json", Canon::encode([
    'manifests' => ['core', 'scope-contract-fixture'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => ['post', 'page', 'attachment', 'duo_contract'],
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
$page = front($ids['page'], 'page', 'scope-page');
$page['terms'] = (object) ['category' => [$ids['term']]];
put("$repo/state/posts/page/{$ids['page']}--scope-page.md", Canon::post_file(
    $page,
    '<!-- wp:image {"id":"{{post:' . $ids['attachment'] . '}}"} --><figure></figure><!-- /wp:image -->'
));
put("$repo/state/posts/duo_contract/{$ids['custom']}--contract.md", Canon::post_file(
    front($ids['custom'], 'duo_contract', 'contract'), ''
));
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

$policy = Policy::load($repo);
$compiled = RepositoryCompiler::compile($repo, $policy);

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
check($all['selectors'] === ['all'] && $all['resolution']['tombstone_uuids'] === [$ids['tombstone']]
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
check(is_string($cliSource) && !str_contains($cliSource, '--scope-contract'),
    'no mutation command accepts a scope-contract flag; contract remains scope evidence only');
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
