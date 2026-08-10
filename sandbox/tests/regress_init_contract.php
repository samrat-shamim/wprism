<?php
// Offline regression for DUO-3336's public proposal/confirmation boundary.

declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Transport.php';
require_once __DIR__ . '/../../cli/src/Init.php';

use Duo\Orchestrator\Init;
use Duo\Orchestrator\Transport;

final class InitTransport extends Transport {
    /** @var list<array{exit:int,stdout:string,stderr:string}> */
    private array $responses;
    /** @var list<list<string>> */
    public array $calls = [];

    /** @param list<array{exit:int,stdout:string,stderr:string}> $responses */
    public function __construct(array $responses, string $repo = '/srv/shop-state') {
        parent::__construct('shop', ['repo_path' => $repo]);
        $this->responses = $responses;
    }

    public function describe(): string { return 'init regression transport'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        return array_shift($this->responses)
            ?? ['exit' => 97, 'stdout' => '', 'stderr' => 'unexpected extra init request'];
    }
}

function fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check(bool $ok, string $message): void {
    if (!$ok) fail($message);
    echo "ok: $message\n";
}

function response(array $body): array {
    return ['exit' => 0, 'stdout' => json_encode($body, JSON_UNESCAPED_SLASHES) . "\n", 'stderr' => ''];
}

$digest = str_repeat('a', 64);
$stateRevision = str_repeat('b', 64);
$codeRevision = str_repeat('c', 64);
$lifecycle = [
    'enabled' => true,
    'completed' => true,
    'code_revision' => $codeRevision,
];
$proposal = [
    'format' => 'duo-init-plan/v1',
    'digest' => $digest,
    'ready' => true,
    'advisories' => [[
        'code' => 'active_theme_code_only', 'extension' => 'shop-theme', 'kind' => 'theme',
        'reason' => 'theme bytes are code-only', 'remediation' => 'install a theme adapter if needed',
    ], [
        'code' => 'repository_external_writer_exclusion', 'extension' => '/srv/shop-state', 'kind' => 'repository',
        'reason' => 'Duo locks serialize Duo writers only', 'remediation' => 'quiesce non-Duo repository writers',
    ]],
    'environment' => [
        'wordpress' => '7.0.2', 'php' => '8.3.33',
        'database' => ['access' => 'verified-read', 'server' => '11.8.8-MariaDB'],
        'home' => 'https://shop.example.test',
    ],
    'code' => [
        'management' => 'managed-baseline-proposed',
        'files' => 42,
        'bytes' => 8192,
        'source_revision' => $codeRevision,
        'roots' => [
            'content' => '/var/www/html/wp-content',
            'mu_plugins' => '/var/www/html/wp-content/mu-plugins',
            'plugins' => '/var/www/html/wp-content/plugins',
            'themes' => '/var/www/html/wp-content/themes',
        ],
        'components' => ['plugins' => ['woocommerce'], 'themes' => ['shop-theme']],
        'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
        'active_plugins' => [['basename' => 'woocommerce/woocommerce.php', 'version' => '11.0.0']],
        'active_theme' => ['stylesheet' => 'shop-theme', 'template' => 'shop-theme'],
    ],
    'state' => [
        'baseline' => 'capture-consistent-snapshot',
        'config_identity' => 'absent',
        'existing_config' => 'absent',
        'repository' => '/srv/shop-state',
        'repository_identity' => 'sha256:' . str_repeat('d', 64),
        'adapters' => [['name' => 'core'], ['name' => 'woocommerce']],
        'config' => [
            'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
            'manifests' => [['digest' => str_repeat('d', 64), 'name' => 'core']],
            'policy' => ['post_types' => ['post'], 'taxonomies' => ['category']],
            'spec_version' => 2,
        ],
        'git' => ['mode' => 'initialize-on-confirm', 'version' => 'git version 2.51.0'],
        'gitignore_identity' => 'absent',
        'ledger' => ['rows' => 0, 'tables' => 0],
        'media' => ['strategy' => 'local', 'attachments' => 2, 'unavailable' => 0],
        'risk_surfaces' => [
            'options' => ['stripe key' => 1],
            'user_meta' => ['email address' => 2],
            'oversized' => ['options' => 3, 'user_meta' => 4],
            'scanned' => ['options' => 120, 'user_meta' => 80],
            'limits' => ['rows_per_surface' => 5000, 'bytes_per_surface' => 8388608],
            'truncated' => true,
        ],
    ],
    'unsupported' => [],
];
$result = [
    'format' => 'duo-init-result/v1',
    'proposal_digest' => $digest,
    'baseline' => ['kind' => 'state-capture', 'revision_hash' => $stateRevision],
    'capture' => [
        'revision_hash' => $stateRevision,
        'initial_code_baseline' => $lifecycle,
        'initial_publication_cleanup' => 'clean',
    ],
    'code' => [
        'descriptor' => ['code_revision' => $codeRevision],
        'lifecycle' => $lifecycle,
        'management' => 'managed-baseline',
        'revision_hash' => $codeRevision,
        'source' => 'code/wp-content',
    ],
    'state' => [
        'git' => 'existing-worktree',
        'repository' => '/srv/shop-state',
        'site_config' => '/srv/shop-state/site.duo.json',
    ],
    'unsupported' => [],
];

$transport = new InitTransport([response($proposal), response($result)]);
check(Init::proposal($transport) === $proposal, 'proposal JSON is returned without host-side reinterpretation');
check($transport->calls[0] === ['duo', 'init', '--repo=/srv/shop-state', '--format=json'], 'proposal uses the authenticated target agent and repository path');
check(Init::confirm($transport, $digest) === $result, 'confirmation result is returned');
check($transport->calls[1] === ['duo', 'init', '--repo=/srv/shop-state', '--confirm=' . $digest, '--format=json'], 'confirmation sends only the reviewed digest, never a mutable config payload');
$recoveryResult = array_replace($result, ['recovery' => 'committed-finalized']);
check(
    Init::confirm(new InitTransport([response($recoveryResult)]), $digest) === $recoveryResult,
    'verified committed-init finalization uses the same fail-closed result contract'
);

$slashTransport = new InitTransport([response($proposal), response($result)], '/srv/shop-state/');
check(Init::proposal($slashTransport) === $proposal, 'host normalizes a configured trailing slash when validating the target proposal');
check(Init::confirm($slashTransport, $digest) === $result, 'host normalizes a configured trailing slash when validating the target result');

$rendered = implode("\n", Init::render($proposal));
check(str_contains($rendered, 'code: managed-baseline-proposed'), 'rendering preserves the separate code/state contract');
check(str_contains($rendered, 'active plugin: woocommerce/woocommerce.php 11.0.0'), 'rendering inventories active plugin versions');
check(str_contains($rendered, 'core, woocommerce'), 'rendering names selected adapters');
check(str_contains($rendered, 'ADVISORY THEME shop-theme [active_theme_code_only]'), 'rendering exposes code-only active theme state coverage');
check(str_contains($rendered, 'ADVISORY REPOSITORY /srv/shop-state [repository_external_writer_exclusion]'), 'rendering exposes the first-init external-writer exclusion');
check(str_contains($rendered, '1 secret-shaped option value(s), 2 PII-shaped user-meta value(s)'), 'rendering exposes redacted risk counts');
check(str_contains($rendered, '3 oversized option value(s) and 4 oversized user-meta value(s) were not scanned'), 'rendering exposes redacted oversized omissions');
check(str_contains($rendered, 'after scanning 120 option value(s) and 80 user-meta value(s)'), 'rendering exposes deterministic bounded-scan coverage');
check(str_contains($rendered, 'redacted counts are incomplete'), 'rendering discloses a bounded risk scan instead of implying completeness');
check(!str_contains($rendered, 'sk_live_') && !str_contains($rendered, '@example.'), 'rendering cannot expose secret or PII values from the count-only report');

$next = implode("\n", Init::nextSteps('shop', '/srv/shop-state'));
foreach (['branch', 'duo capture shop', 'duo plan shop', 'duo promote shop', 'rollback'] as $step) {
    check(str_contains($next, $step), "workflow guide includes $step");
}
check(str_contains($next, 'Coverage outside the selected adapters remains advisory'), 'guide does not turn a managed-scope proof into a whole-site guarantee');
check(str_contains($next, "git -C '/srv/shop-state'"), 'guide runs Git in the target-owned worktree');

$badJson = new InitTransport([['exit' => 0, 'stdout' => "not-json\n", 'stderr' => '']]);
try {
    Init::proposal($badJson);
    fail('invalid target JSON was accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'invalid JSON'), 'invalid target JSON fails closed');
}

// DUO-3421: the target's refusal envelope arrives on STDOUT while a docker
// transport's stderr always carries `docker compose run` progress noise. The
// stderr-first rule handed the operator that noise and dropped the reason
// code, the remediation, and the redaction witness — a refusal-transparency
// loss of exactly the DUO-3398 shape, reproduced here without docker by
// planting the noise the live transport really emits.
$refusalEnvelope = [
    'format' => 'duo-command-refusal/v1',
    'ok' => false,
    'command' => 'init',
    'error' => 'init_failed',
    'reason_code' => 'init_failed',
    'message' => 'init refused at an unclassified safety gate',
    'remediation' => 'correct the named init blocker, then retry the command',
    'details_redacted' => true,
    'diagnostics' => [[
        'code' => 'init_failed',
        'message' => 'init refused at an unclassified safety gate',
        'remediation' => 'correct the named init blocker, then retry the command',
    ]],
];
$composeNoise = " Container duo-pair-cli1-run-6a2f Creating \n Container duo-pair-cli1-run-6a2f Created\n";
foreach (['proposal', 'confirmation'] as $noisyPhase) {
    $noisy = new InitTransport([[
        'exit' => 1,
        'stdout' => json_encode($refusalEnvelope, JSON_UNESCAPED_SLASHES) . "\n",
        'stderr' => $composeNoise,
    ]]);
    try {
        $noisyPhase === 'proposal'
            ? Init::proposal($noisy)
            : Init::confirm($noisy, $digest);
        fail("a refused init $noisyPhase was accepted");
    } catch (\Duo\Orchestrator\InitRefusalException $refused) {
        check(
            $refused->refusal === $refusalEnvelope,
            "a refused init $noisyPhase carries the target's complete v1 envelope for rendering, not a flattened string"
        );
        check(
            !str_contains($refused->getMessage(), 'Container duo-')
                && str_contains($refused->getMessage(), "init $noisyPhase failed"),
            "a refused init $noisyPhase names its phase without pasting transport progress noise"
        );
    } catch (RuntimeException $wrong) {
        fail("refused init $noisyPhase surfaced transport noise instead of the envelope: {$wrong->getMessage()}");
    }
}
// Anything that is not a v1 envelope keeps the original stderr-else-stdout
// passthrough, so non-docker transports and non-envelope failures are
// untouched by the change above.
try {
    Init::proposal(new InitTransport([[
        'exit' => 255,
        'stdout' => '',
        'stderr' => "Error: 'duo' is not a registered wp command.\n",
    ]]));
    fail('a non-envelope init failure was accepted');
} catch (\Duo\Orchestrator\InitRefusalException) {
    fail('a non-envelope init failure was misread as a refusal envelope');
} catch (RuntimeException $passthrough) {
    check(
        str_contains($passthrough->getMessage(), 'not a registered wp command'),
        'a non-envelope init failure still passes the target stderr through unchanged'
    );
}
$initCommandSource = (string) file_get_contents(__DIR__ . '/../../cli/duo');
check(
    substr_count($initCommandSource, 'catch (\Duo\Orchestrator\InitRefusalException $e) {') === 2
        && substr_count($initCommandSource, 'render_command_refusal_human($e->refusal);') === 2,
    'both init phases render the refusal envelope through the shared host renderer, as status and pending do'
);

foreach ([
    'empty proposal' => [],
    'wrong proposal format' => array_replace($proposal, ['format' => 'duo-init-plan/v0']),
    'malformed proposal digest' => array_replace($proposal, ['digest' => 'abc']),
    'non-boolean proposal readiness' => array_replace($proposal, ['ready' => 1]),
    'sparse ready proposal' => [
        'format' => 'duo-init-plan/v1', 'digest' => $digest, 'ready' => true,
        'environment' => [], 'code' => [],
        'state' => ['repository' => '/srv/shop-state', 'repository_identity' => 'sha256:' . str_repeat('d', 64)],
        'unsupported' => [], 'advisories' => [],
    ],
    'blocked proposal without blockers' => array_replace($proposal, ['ready' => false]),
    'proposal for another repository' => array_replace_recursive($proposal, ['state' => ['repository' => '/srv/other']]),
] as $label => $invalidProposal) {
    try {
        Init::proposal(new InitTransport([response($invalidProposal)]));
        fail("$label was accepted");
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), 'incompatible or incomplete contract'), "$label fails closed");
    }
}

foreach ([
    'empty result' => [],
    'wrong result format' => array_replace($result, ['format' => 'duo-init-result/v0']),
    'mismatched proposal digest' => array_replace($result, ['proposal_digest' => str_repeat('e', 64)]),
    'malformed state baseline hash' => array_replace_recursive($result, ['baseline' => ['revision_hash' => 'bad']]),
    'capture baseline mismatch' => array_replace_recursive($result, ['capture' => ['revision_hash' => str_repeat('d', 64)]]),
    'malformed code baseline hash' => array_replace_recursive($result, ['code' => ['revision_hash' => 'bad']]),
    'descriptor revision mismatch' => array_replace_recursive($result, ['code' => ['descriptor' => ['code_revision' => str_repeat('d', 64)]]]),
    'incomplete lifecycle receipt' => array_replace_recursive($result, ['code' => ['lifecycle' => ['completed' => false]]]),
    'capture lifecycle mismatch' => array_replace_recursive($result, ['capture' => ['initial_code_baseline' => ['completed' => false]]]),
    'retained initial publication cleanup' => array_replace_recursive($result, ['capture' => ['initial_publication_cleanup' => 'retained']]),
    'result for another repository' => array_replace_recursive($result, ['state' => ['repository' => '/srv/other']]),
    'result retaining unsupported coverage' => array_replace($result, ['unsupported' => [['code' => 'still-blocked']]]),
] as $label => $invalidResult) {
    try {
        Init::confirm(new InitTransport([response($invalidResult)]), $digest);
        fail("$label was accepted");
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), 'incompatible or incomplete result'), "$label fails closed");
    }
}

try {
    Init::confirm(new InitTransport([]), 'not-a-digest');
    fail('malformed caller confirmation digest was accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'exact 64-hex'), 'malformed caller digest refuses before target contact');
}

$failed = new InitTransport([['exit' => 17, 'stdout' => '', 'stderr' => 'adapter unsupported']]);
try {
    Init::proposal($failed);
    fail('target failure was accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'adapter unsupported'), 'target refusal remains visible to the operator');
}

$agentSource = file_get_contents(__DIR__ . '/../../agent/src/Init.php');
check(is_string($agentSource), 'target init source is readable');
check(str_contains($agentSource, "'code' => 'repository_external_writer_exclusion'"), 'target proposal binds the generic repository writer-exclusion advisory');
check(!str_contains(strtolower($agentSource), 'woocommerce'), 'generic target init has no plugin-name branch');
check(str_contains($agentSource, "(\$rule['class'] ?? null) === 'authored'"), 'post-type scope expands only from authored manifest rulings');
check(substr_count($agentSource, "(\$rule['class'] ?? null) === 'authored'") >= 2, 'post-type and taxonomy scope expand only from explicit authored manifest rulings');
$lockedRecheck = strrpos($agentSource, 'self::assert_confirmed_proposal($proposal, $expectedDigest);');
$siteWrite = $lockedRecheck === false ? false : strpos($agentSource, '$sitePublication = self::publish_owned_file(', $lockedRecheck);
check($lockedRecheck !== false && $siteWrite !== false && $lockedRecheck < $siteWrite, 'under-lock digest recheck precedes the site-config write');
check(str_contains($agentSource, "'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE]"), 'site config declares code independently from state policy');
check(str_contains($agentSource, 'Code::descriptor_from_source($stage)'), 'captured code is validated by the existing descriptor contract before publication');
check(
    str_contains($agentSource, 'Capture::run_initial_baseline(')
        && str_contains($agentSource, '(string) $stateIdentity')
        && str_contains($agentSource, '(string) $mediaIdentity'),
    'confirmed baseline uses the init-wide strict publication transaction'
);
check(str_contains($agentSource, 'SELECT GET_LOCK(%s, 0)'), 'concurrent confirmations share a target advisory lease');
check(
    str_contains($agentSource, "'existing_state_payload'")
        && str_contains($agentSource, "'existing_media_payload'")
        && str_contains($agentSource, "'existing_capture_receipt'")
        && str_contains($agentSource, "'existing_duo_ledger'"),
    'stale state, media, capture-receipt, and ledger ownership block initialization'
);
check(str_contains($agentSource, 'Secrets::hard_match($window)'), 'every code byte crosses the high-confidence secret matcher');
check(str_contains($agentSource, 'substr($window, -32768)'), 'streaming secret scan retains one full bounded-pattern chunk');
check(str_contains($agentSource, "['allowed_classes' => false]"), 'risk discovery cannot instantiate serialized user-meta objects');
check(!str_contains($agentSource, 'maybe_unserialize('), 'read-only risk discovery never uses class-enabled WordPress unserialization');
check(
    str_contains($agentSource, 'ORDER BY $idColumn ASC LIMIT $fetchLimit')
        && str_contains($agentSource, "'option_id'") && str_contains($agentSource, "'umeta_id'"),
    'bounded risk discovery uses deterministic primary-key keyset ordering'
);
check(str_contains($agentSource, "git', 'init', '--initial-branch=main"), 'confirmation creates a verified Git worktree when absent');
check(str_contains($agentSource, "\$finalGit['mode'] !== 'existing-worktree'"), 'success re-verifies Git readiness after the baseline transaction');
$gitAttempt = strpos($agentSource, '$gitCreated = true;');
$gitInitialize = strpos($agentSource, 'self::initialize_git($repo);');
check(
    $gitAttempt !== false && $gitInitialize !== false && $gitAttempt < $gitInitialize
        && str_contains($agentSource, "file_exists(\$repo . '/.git') || is_link(\$repo . '/.git')"),
    'partial first Git initialization is marked before invocation and fully compensated'
);
$rootLinkCheck = strpos($agentSource, 'if (is_link($repo))');
$rootAbsentCheck = strpos($agentSource, 'if (!file_exists($repo))');
check(
    $rootLinkCheck !== false && $rootAbsentCheck !== false && $rootLinkCheck < $rootAbsentCheck,
    'dangling repository-root links refuse before the absent-root path'
);
check(
    str_contains($agentSource, 'self::repository_root_blocker($logicalRepo)')
        && str_contains($agentSource, "return self::proposal_bound('.', \$logicalRepo, \$binding['identity'])")
        && str_contains($agentSource, "\$repo = '.';")
        && str_contains($agentSource, 'self::fresh_lstat($repo)'),
    'proposal and confirmation bind a freshly inspected ordinary repository inode before child traversal'
);
$reviewedIdentity = strpos($agentSource, "\$reviewedIdentity = \$proposal['state']['repository_identity'] ?? null;");
$publicationLock = strpos($agentSource, '$publicationLock = Publish::lock_new($stateDir);');
check(
    $reviewedIdentity !== false && $publicationLock !== false && $reviewedIdentity < $publicationLock
        && str_contains($agentSource, "hash_equals(\$reviewedIdentity, \$binding['identity'])"),
    'a replacement ordinary directory refuses before the first publication-lock write'
);
check(
    str_contains($agentSource, "'repository_root_missing'")
        && str_contains($agentSource, 'the repository root and every parent must already exist'),
    'missing repository roots are an explicit bootstrap prerequisite rather than a racy init mutation'
);
check(
    str_contains($agentSource, "'unsafe_site_config'")
        && str_contains($agentSource, 'self::publish_owned_file(')
        && str_contains($agentSource, 'self::regular_file_identity($path, $label)')
        && str_contains($agentSource, 'if (!@rename($tmp, $path))'),
    'site config publication verifies reviewed bytes before its crash-atomic same-parent replacement'
);
check(
    str_contains($agentSource, "'unsafe_code_root'")
        && str_contains($agentSource, "assert_absent_owned_path(\$codeRoot, 'code publication root')")
        && str_contains($agentSource, "mkdir(\$codeRoot, 0700)")
        && str_contains($agentSource, "rename(\$stagedCode, \$codeRoot . '/wp-content')"),
    'code baseline reserves an owned root before publishing its verified child'
);
$publishSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Publish.php');
$captureSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Capture.php');
$liveHarness = (string) file_get_contents(__DIR__ . '/regress_duo_init.sh');
check(
    str_contains($publishSource, 'public static function lock_new(')
        && str_contains($publishSource, 'public static function assert_lock_path(')
        && str_contains($publishSource, 'public static function write_entities_fresh(')
        && str_contains($publishSource, 'public static function swap_initial(')
        && str_contains($publishSource, 'public static function cleanup_committed_initial('),
    'initial publication has fresh lock, strict staging, swap, and exact cleanup primitives'
);
check(
    substr_count($captureSource, "Publish::intent_path(\$stateDir) . '.previous'") >= 2
        && substr_count($captureSource, "Publish::receipt_path(\$stateDir) . '.previous'") >= 2
        && str_contains($publishSource, 'self::assert_record_slot_absent($previousPath, "$label previous-transition")'),
    'fresh and final first-publication gates include every fixed record-transition slot'
);
$repoFormat = (string) file_get_contents(__DIR__ . '/../../spec/repo-format.md');
check(
    str_contains($repoFormat, 'requires non-Duo tools to leave the')
        && str_contains($repoFormat, 'complete `state.capture*` protocol namespace untouched')
        && str_contains($repoFormat, 'adversarial namespace-race sandbox for these siblings'),
    'ordinary capture states its protocol-namespace exclusion without overclaiming portable PHP race safety'
);
check(
    str_contains($agentSource, "private const ATTEMPT_FILE = '.duo-init-attempt';")
        && str_contains($agentSource, "private const ATTEMPT_NEXT_FILE = '.duo-init-attempt.next';")
        && strpos($agentSource, 'self::write_init_attempt($repo, $attemptRecord, \'absent\')')
            < strpos($agentSource, '$publicationLock = Publish::lock_new($stateDir);'),
    'sealed init recovery journal is durable before the first persistent capture lock mutation'
);
check(
    str_contains($agentSource, "'verify-interrupted-precommit-init'")
        && str_contains($agentSource, 'roll back only payloads carrying complete deletion authority')
        && str_contains($agentSource, 'partial or ambiguous artifacts are retained')
        && str_contains($agentSource, "'verify-interrupted-committed-init'")
        && str_contains($agentSource, 'appears to have durable committed-state proof')
        && str_contains($agentSource, 'only exact proof permits clearing the sealed journal'),
    'interrupted-init proposals promise verification, never cleanup before exact recovery authority is proven'
);
check(
    str_contains($agentSource, 'private static function interrupted_attempt_manual_recovery_reason(')
        && str_contains($agentSource, "'manual-interrupted-init-recovery'")
        && str_contains($agentSource, "'interrupted_init_manual_recovery'")
        && str_contains($agentSource, 'state.capture-intent.tmp.')
        && str_contains($agentSource, "str_contains(\$entry, '.duo-claim-')")
        && str_contains($agentSource, "str_contains(\$entry, '.duo-init-')")
        && str_contains($agentSource, 'the interrupted-init repository contains an unjournaled Init temporary or claim artifact')
        && str_contains($agentSource, "'.*.duo-init-*'")
        && str_contains($agentSource, "\$proposal['ready'] = false;")
        && str_contains($liveHarness, 'unmanifested state recovery is non-confirmable')
        && str_contains($liveHarness, 'partial code-stage recovery is non-confirmable')
        && str_contains($liveHarness, 'partial state-stage recovery is non-confirmable'),
    'known partial init payloads produce a non-confirmable manual-recovery plan before mutation'
);
check(
    str_contains($publishSource, "self::fault_checkpoint('record-create-temp');")
        && str_contains($publishSource, 'public static function remove_owned_tree_initial(')
        && str_contains($publishSource, 'public static function remove_owned_file_initial(')
        && str_contains($publishSource, 'remove_owned_tree_initial($backup')
        && str_contains($publishSource, 'remove_owned_file_initial($intent'),
    'strict first-publication cleanup has a temp crash seam and no unjournaled claim rename'
);
check(
    str_contains($agentSource, "self::init_fault_checkpoint('owned-file-temp');")
        && str_contains($agentSource, "self::init_fault_checkpoint('owned-file-claim');")
        && str_contains($agentSource, "self::init_fault_checkpoint('owned-tree-claim');"),
    'Init hidden temp and claim boundaries have explicit crash seams for live evidence'
);
check(
    str_contains($agentSource, "self::init_fault_checkpoint('lock-created')")
        && str_contains($agentSource, "self::init_fault_checkpoint('attempt-transition-pre-rename')")
        && str_contains($agentSource, "'attempt-transition-pre-rename-' . (string) \$attempt['phase']")
        && str_contains($agentSource, "self::init_fault_checkpoint('capture-complete')")
        && str_contains($agentSource, "self::init_fault_checkpoint('attempt-remove-pre-unlink')")
        && str_contains($agentSource, "self::init_fault_checkpoint('attempt-remove-post-unlink')")
        && str_contains($agentSource, "return ['outcome' => 'precommit-rolled-back']")
        && str_contains($agentSource, "return ['outcome' => 'committed-finalized']"),
    'init exposes fresh-process crash seams and distinct precommit/committed recovery outcomes'
);
check(
    str_contains($agentSource, "\$attemptRecord['owned']['code_stage_planned'] = true;")
        && str_contains($agentSource, 'partial code staging tree without a complete descriptor'),
    'code-stage creation is write-ahead journaled before the staging-root mutation'
);
check(
    str_contains($agentSource, 'final class InitAttemptRetentionException')
        && str_contains($agentSource, 'if ($error instanceof InitAttemptRetentionException)')
        && str_contains($agentSource, "DUO_TEST_INIT_FAIL_PHASE') === 'code-copy-after-file'")
        && str_contains($liveHarness, 'changed code source left a staging tree, journal, lock, or canonical payload')
        && str_contains($publishSource, "if (\$stillSame) @unlink(\$name);"),
    'post-create code-copy failures either compensate the exact partial stage or retain sealed recovery authority'
);
check(
    str_contains($agentSource, 'git-initialized-before-identity')
        && str_contains($agentSource, 'incomplete Git metadata without a complete ownership manifest')
        && str_contains($liveHarness, 'Git initialization failure left an unjournaled or unlocked metadata root'),
    'planned-to-mutated Git failures retain their sealed journal when no complete ownership manifest exists'
);
check(
    str_contains($publishSource, 'lock-acquire-after-create')
        && str_contains($liveHarness, 'first-lock acquisition failure stranded a lock, journal, or repository payload'),
    'first-lock acquisition failure cannot erase its journal while leaving an unowned canonical lock'
);
check(
    str_contains($agentSource, 'state-reserved-before-identity')
        && str_contains($agentSource, 'incomplete state reservation without a complete ownership manifest')
        && str_contains($liveHarness, 'state recovery refusal deleted the unmanifested sentinel'),
    'state reservation is not deletion authority until its complete identity is sealed'
);
check(
    str_contains($agentSource, "'capture-payload-ready'")
        && str_contains($agentSource, "\$attemptRecord['owned']['state_staging_manifest'] = \$stagingManifest;")
        && str_contains($agentSource, "\$attemptRecord['owned']['media_manifest'] = \$mediaManifest;")
        && str_contains($agentSource, 'the interrupted-init state root no longer matches any sealed ownership manifest')
        && str_contains($agentSource, 'Publish::tree_ownership_manifest($path)')
        && str_contains($agentSource, 'no longer matches its sealed ownership manifest')
        && str_contains($agentSource, 'partial state staging tree without a complete deletion manifest')
        && str_contains($agentSource, 'partial code staging tree without a complete descriptor')
        && str_contains($liveHarness, 'DUO_TEST_PUBLISH_KILL_PHASE=initial-staging-partial')
        && str_contains($liveHarness, 'record-create-next intent-written after-state-rename')
        && str_contains($liveHarness, 'partial manifest-bound tree is non-confirmable')
        && str_contains($publishSource, 'recover_initial_unpublished_intent_next')
        && str_contains($publishSource, 'remove_matching_record_temps')
        && str_contains($publishSource, 'public static function recover_initial(')
        && str_contains($liveHarness, 'unmanifested-empty-directory'),
    'interrupted first publication deletes only journaled complete manifests and retains partial payloads'
);
check(
    str_contains($captureSource, 'post-swap-unmanifested-empty')
        && substr_count($captureSource, "Publish::assert_owned_tree(\n                        \$stateDir") >= 2
        && str_contains($liveHarness, 'post-swap recovery deleted the unmanifested directory or cleared its journal'),
    'initial success revalidates the exact published candidate before receipt cleanup and final result'
);
check(
    str_contains($publishSource, 'public static function intent_record(')
        && str_contains($publishSource, 'public static function receipt_record(')
        && str_contains($agentSource, 'self::assert_interrupted_committed_attempt(')
        && str_contains($agentSource, "'recovery' => 'committed-finalized'"),
    'committed journal recovery verifies durable intent/receipt state before returning a truthful result'
);
check(
    substr_count($captureSource, 'self::assert_no_interrupted_init($repoPath);') >= 2
        && str_contains($captureSource, 'sealed init recovery journal exists')
        && str_contains($agentSource, "hash_equals((string) (\$receipt['previous_sha256'] ?? ''), hash('sha256', ''))"),
    'ordinary capture cannot replace a retained initial receipt and committed recovery proves a first publication'
);
check(
    str_contains($agentSource, 'private static function remove_exact_owned_file(')
        && str_contains($agentSource, 'if (!@unlink($path))'),
    'completed journal removal uses an identity-checked atomic unlink instead of an unjournalled hidden claim'
);
check(
    str_contains($agentSource, "'unreadable_repository_root'")
        && str_contains($agentSource, '$entries = @scandir($repo);')
        && str_contains($agentSource, 'if ($entries === false)'),
    'repository ownership fails closed when the root cannot be enumerated'
);
check(
    str_contains($agentSource, "'state.capture.lock', 'state.capture-receipt'")
        && str_contains($agentSource, "'state.capture-intent', 'state.capture-receipt'"),
    'final Git readiness allowlists the retained capture receipt and ignores no in-flight publication root'
);
$cliSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Cli.php');
check(
    str_contains($cliSource, "CommandRefusalException::invalidArgument('init', '--repo')")
        && str_contains($cliSource, "self::halt_json_failure(\$t, \$assoc, 'init');"),
    'target init owns the stable JSON invalid-argument and exception-refusal contract'
);
$adapterSource = (string) file_get_contents(__DIR__ . '/../../agent/src/AdapterSources.php');
check(str_contains($adapterSource, 'file_exists($siteDir) || is_link($siteDir)'), 'adapter allowlist refuses every present non-directory boundary');

$codeSource = file_get_contents(__DIR__ . '/../../agent/src/Code.php');
check(is_string($codeSource), 'code lifecycle source is readable');
check(str_contains($codeSource, 'public static function complete_initial_baseline'), 'code lifecycle exposes a narrow initial-baseline primitive');
check(str_contains($codeSource, 'complete_initial_baseline_in_active_transaction'), 'initial lifecycle can join capture transaction without a nested commit');
check(str_contains($codeSource, 'lifecycle metadata') && str_contains($codeSource, 'already exists'), 'initial baseline refuses to overwrite existing lifecycle metadata');
check(str_contains($codeSource, 'self::verify_payload($descriptor)') && str_contains($codeSource, 'self::owned_extra_files($descriptor)'), 'initial baseline verifies live bytes and rejects unrecorded managed files');

$woo = json_decode((string) file_get_contents(__DIR__ . '/../../manifests/woocommerce.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['product_cat', 'product_tag', 'product_shipping_class', 'product_type'] as $taxonomy) {
    check(($woo['taxonomies'][$taxonomy]['class'] ?? null) === 'authored', "Woo adapter owns authored init scope for $taxonomy");
}
check(($woo['taxonomies']['product_visibility']['class'] ?? null) === 'runtime', 'Woo adapter keeps mixed product visibility out of authored state');

$ignoreTemplate = (string) file_get_contents(__DIR__ . '/../site-repo.gitignore.template');
check(
    str_contains($liveHarness, 'attempt-transition-pre-rename-code-staging')
        && str_contains($liveHarness, 'partial code staging tree without a complete descriptor'),
    'live crash recovery retains a code-stage transition without manufacturing deletion authority'
);
check(
    str_contains($agentSource, 'self::assert_init_attempt_transition($record, $next);')
        && str_contains($agentSource, 'next-record exists without its canonical sealed attempt')
        && str_contains($liveHarness, 'orphan init next-record blocks proposal before writes')
        && str_contains($liveHarness, 'malformed next-record blocks recovery before cleanup'),
    'init validates orphan and canonical-plus-next journal shapes before any recovery mutation'
);
check(
    str_contains($liveHarness, 'DUO_TEST_INIT_KILL_PHASE=attempt-remove-pre-unlink')
        && str_contains($liveHarness, 'DUO_TEST_INIT_KILL_PHASE=attempt-remove-post-unlink')
        && str_contains($liveHarness, ".duo-init-compensate-*"),
    'live coverage proves both sides of the completed-journal unlink crash boundary'
);
check(
    str_contains($ignoreTemplate, '.duo-init-attempt')
        && str_contains($ignoreTemplate, '.duo-init-attempt.next')
        && str_contains($ignoreTemplate, '.duo-init-code-*')
        && str_contains($ignoreTemplate, '.*.duo-init-*')
        && str_contains($ignoreTemplate, 'state.capture-intent.previous')
        && str_contains($ignoreTemplate, 'state.capture-intent.next')
        && str_contains($ignoreTemplate, 'state.capture-receipt.previous')
        && str_contains($ignoreTemplate, 'state.capture-receipt.next'),
    'canonical site-repo ignore template protects the init journal and fixed capture transition slots'
);
$sourceBinding = strpos($liveHarness, 'export DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA"');
$pairUp = strpos($liveHarness, 'bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless');
check(
    str_contains($liveHarness, "git rev-parse --verify 'HEAD^{commit}'")
        && str_contains($liveHarness, '[[ ! -d "$REPO_ROOT/.git" ]]')
        && str_contains($liveHarness, 'git status --porcelain --untracked-files=all')
        && $sourceBinding !== false && $pairUp !== false && $sourceBinding < $pairUp,
    'live init evidence binds a clean standalone exact Git HEAD before the first pair mutation'
);
$pairValidation = strpos($liveHarness, '[[ ! "$PAIR" =~ ^[a-z][a-z0-9]*$ ]]');
$pathDerivation = strpos($liveHarness, 'HOST_REPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"');
check(
    $pairValidation !== false && $pathDerivation !== false && $pairValidation < $pathDerivation,
    'live harness validates the pair name before deriving any cleanup path'
);
check(
    str_contains($liveHarness, 'PORT1 % 2 != 0 || PORT2 != PORT1 + 1'),
    'live harness validates the owned even/adjacent port pair before cleanup is armed'
);
$destroyCall = strpos($liveHarness, 'if bash sandbox/bin/pair.sh destroy "$PAIR"');
$deadReadback = strpos($liveHarness, 'label=com.docker.compose.project=duo-${PAIR}');
$rootRemoval = strpos($liveHarness, 'rm -rf "$HOST_REPO"');
check(
    $destroyCall !== false && $deadReadback !== false && $rootRemoval !== false
        && $destroyCall < $deadReadback && $deadReadback < $rootRemoval,
    'live cleanup proves pair destruction and Docker absence before removing bind roots'
);
check(
    !str_contains($liveHarness, 'pair.sh destroy "$PAIR" >/dev/null 2>&1 || true'),
    'live cleanup never suppresses pair-destroy failure before root removal'
);
check(
    substr_count($liveHarness, '-e DUO_TEST_INIT_PUBLICATION_PAUSE_MS=5000') >= 2,
    'both concurrent init contenders pause whichever winner holds the publication lock'
);
check(
    str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-link')
        && str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-directory')
        && substr_count($liveHarness, '-e DUO_TEST_INIT_PAUSE_MS=10000') >= 2,
    'live root replacement races wait for the post-proposal init lease before swapping paths'
);
check(
    str_contains($liveHarness, 'symlinked repository ancestor blocks init')
        && str_contains($liveHarness, 'repository_root_missing')
        && str_contains($liveHarness, 'replacement ordinary directory received a repository write')
        && str_contains($liveHarness, 'post-proposal symlink replacement received a repository write'),
    'live root suite covers missing, ancestor-link, symlink-swap, and ordinary-directory replacement boundaries'
);

// DUO-3421. This leg and the live golden path (bundle legs 13-14) both run on
// bundle-owing branches BY CONSTRUCTION, where the checked-in attestation is
// expired and every certified claim therefore carries evidence_not_current. An
// init proposal with unsupported rows is not ready, refuses confirmation
// instantly, and the live harness's paused root-replacement races then time out
// waiting for an init lease no confirmation ever took — the bundle blocked on
// the evidence it exists to mint. The live harness now mounts a hermetic
// library (the shipped manifests byte for byte, attestation re-sealed against
// the working tree) instead of the live one. Pinned here because the ordering
// is the whole property: sealed and asserted BEFORE the pair exists, and the
// live library never mounted at all.
$fixtureBuild = strpos($liveHarness, 'php sandbox/tests/certification_fixture.php "$HERMETIC_ROOT"');
$fixtureMount = strpos($liveHarness, 'export DUO_MANIFESTS_SRC="$HERMETIC_MANIFESTS"');
check(
    $fixtureBuild !== false && $fixtureMount !== false && $pairUp !== false
        && $fixtureBuild < $fixtureMount && $fixtureMount < $pairUp
        && !str_contains($liveHarness, 'export DUO_MANIFESTS_SRC="$REPO_ROOT/manifests"'),
    'live init evidence seals and mounts a hermetic certified library before pair bring-up, never the live one'
);
check(
    str_contains($liveHarness, 'fixture manufacture failed: the sealed registry does not read current evidence')
        && str_contains($liveHarness, 'fixture manufacture failed: the sealed library is not the shipped library outside capabilities/')
        && str_contains($liveHarness, 'fixture manufacture failed: sealing the fixture modified the shipped manifest library'),
    'live harness asserts its own fixture manufacture — current, byte-identical, and non-destructive — before any behavior'
);
// The fixture builder itself, exercised offline: if it cannot produce a current
// attestation on this tree, legs 13-14 cannot pass and this says so in seconds
// rather than an hour into a live pair.
require_once __DIR__ . '/certification_fixture.php';
$fixtureRoot = sys_get_temp_dir() . '/duo-init-contract-fixture-' . bin2hex(random_bytes(6));
register_shutdown_function(static function () use ($fixtureRoot): void {
    exec('rm -rf ' . escapeshellarg($fixtureRoot));
});
$sealedDir = duo_cert_seal_library(dirname(__DIR__, 2), $fixtureRoot);
$sealedRegistry = \Duo\Canon::decode(\Duo\Canon::read_file("$sealedDir/capabilities/registry.json"));
$sealedStatuses = [];
foreach (['manifests', 'profiles'] as $sealedSection) {
    foreach ($sealedRegistry[$sealedSection] as $sealedClaim) {
        $sealedStatuses[(string) ($sealedClaim['evidence']['status'] ?? '?')] = true;
    }
}
check(
    ($sealedRegistry['evidence']['status'] ?? null) === 'current'
        && array_keys($sealedStatuses) === ['current']
        && duo_cert_library_bytes($sealedDir) === duo_cert_library_bytes(dirname(__DIR__, 2) . '/manifests'),
    'the shared certification fixture seals this tree into a current attestation over byte-identical shipped manifests'
);

// DUO-3421. The confirmation logs are the only place a paused confirmation's
// own answer is written, so a failed run must keep them; a green one still
// cleans up after itself, and the owned pair is destroyed either way.
check(
    str_contains($liveHarness, 'trap cleanup_on_exit EXIT')
        && str_contains($liveHarness, 'cleanup 1')
        && str_contains($liveHarness, 'preserved init evidence for %s (this run failed; nothing below was deleted)')
        && str_contains($liveHarness, 'if [ "$preserve" != 1 ]; then')
        && str_contains($liveHarness, 'rm -f "${INIT_LOGS[@]}"'),
    'live cleanup preserves and names the failed confirmation logs, and deletes them only on success'
);
check(
    str_contains($liveHarness, 'confirmation never acquired the init lease for $repo; its own answer, from $log:')
        && str_contains($liveHarness, '$(cat -- "$log" 2>&1)')
        && str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-link "/tmp/${PAIR}-init-root-symlink.log"')
        && str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-directory "/tmp/${PAIR}-init-root-directory.log"'),
    'a lease-wait timeout pastes the confirmation log that already holds the diagnosis'
);
// DUO-3421 (DUO-3381 family). Every injected-failure case takes a fresh
// proposal and confirms its digest; a compose run starved to empty with exit 0
// yields an empty digest, a confirmation that refuses before its first
// mutation, and a case that blames the ENGINE for losing the journal that was
// never created. The manufacture is asserted before any of them consume it,
// and nothing may reach a confirmation through the old unchecked shape.
check(
    str_contains($liveHarness, 'assert_init_plan() {')
        && str_contains($liveHarness, 'fixture manufacture failed: $label proposal is not ready')
        && str_contains($liveHarness, '[[ "$digest" =~ ^[a-f0-9]{64}$ ]]')
        && substr_count($liveHarness, 'assert_init_plan wp') >= 20
        && !preg_match('/_PLAN=\$\(wp[12] duo init/', $liveHarness)
        && !str_contains($liveHarness, '_DIGEST=$(jq -r .digest <<<'),
    'every confirmed live proposal asserts its own manufacture before the confirmation consumes the digest'
);

$unsafeRoot = __DIR__ . '/../unsafe1';
$unsafeSentinel = $unsafeRoot . '/sentinel';
if (!is_dir($unsafeRoot) && !mkdir($unsafeRoot, 0777, true) && !is_dir($unsafeRoot)) {
    fail('could not create invalid-pair cleanup sentinel');
}
file_put_contents($unsafeSentinel, "preserve\n");
register_shutdown_function(static function () use ($unsafeSentinel, $unsafeRoot): void {
    if (is_file($unsafeSentinel)) unlink($unsafeSentinel);
    if (is_dir($unsafeRoot)) rmdir($unsafeRoot);
});
$invalidOutput = [];
$invalidExit = 0;
exec(
    'DUO_INIT_PAIR=' . escapeshellarg('../unsafe') . ' bash '
        . escapeshellarg(__DIR__ . '/regress_duo_init.sh') . ' 2>&1',
    $invalidOutput,
    $invalidExit
);
check($invalidExit === 2, 'invalid live pair name refuses before Docker or cleanup');
check(str_contains(implode("\n", $invalidOutput), 'invalid DUO_INIT_PAIR'), 'invalid-pair regression reaches the pair guard');
check(is_file($unsafeSentinel), 'invalid live pair name cannot escape siterepo and delete the sentinel');

// Exercise the target-only bounded risk probe without WordPress. Query
// failures must not become clean zero counts, and oversized omissions must be
// explicit even when the bounded row queries themselves return no rows.
require_once __DIR__ . '/../../agent/src/Secrets.php';
require_once __DIR__ . '/../../agent/src/PersonalData.php';
require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/AdapterSources.php';
require_once __DIR__ . '/../../agent/src/Init.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// DUO-3421: the proposal-time manual-recovery gate and the recovery-time
// deletion authority must answer the SAME question about an unmanifested Git
// root. They did not: the gate tested only that `git_empty_identity` was
// PRESENT, while the compensation path additionally requires it to still
// describe the root. initialize_git() runs between the `git-reserved` journal
// that records that key and the `git-ready` journal that records
// `git_identity`, so an attempt interrupted inside that window was proposed as
// a ready, confirmable `verify-interrupted-precommit-init` plan whose
// confirmation then refused mid-protocol with the unclassified envelope.
// Exercised against the real private predicate with real directory identities,
// offline: no docker, no WordPress.
// The predicate resolves the fixed capture-record slots through Publish.
require_once __DIR__ . '/../../agent/src/Publish.php';
$recoveryReason = (new ReflectionClass(\Duo\Init::class))
    ->getMethod('interrupted_attempt_manual_recovery_reason');
$recoveryReason->setAccessible(true);
$directoryIdentity = (new ReflectionClass(\Duo\Init::class))->getMethod('directory_identity');
$directoryIdentity->setAccessible(true);
$gitFixtureRepo = sys_get_temp_dir() . '/duo-init-git-authority-' . bin2hex(random_bytes(6));
if (!mkdir($gitFixtureRepo . '/.git', 0777, true)) fail('could not create the Git authority fixture');
register_shutdown_function(static function () use ($gitFixtureRepo): void {
    exec('rm -rf ' . escapeshellarg($gitFixtureRepo));
});
$emptyRootIdentity = (string) $directoryIdentity->invoke(null, $gitFixtureRepo . '/.git', 'Git metadata root');
$gitAttempt = static fn(array $owned): array => ['owned' => ['git_created' => true] + $owned];
check(
    $recoveryReason->invoke(
        null,
        $gitFixtureRepo,
        $gitAttempt(['git_empty_identity' => $emptyRootIdentity])
    ) === null,
    'a Git root reserved and still untouched keeps complete deletion authority and stays automatically recoverable'
);
$incompleteGitReason = 'the sealed attempt has incomplete Git metadata without a complete ownership manifest';
check(
    $recoveryReason->invoke(null, $gitFixtureRepo, $gitAttempt([])) === $incompleteGitReason,
    'a reserved Git root with no ownership manifest at all is non-confirmable'
);
// The exact `git-initialized-before-identity` window the live harness injects.
file_put_contents($gitFixtureRepo . '/.git/HEAD', "ref: refs/heads/main\n");
check(
    $recoveryReason->invoke(
        null,
        $gitFixtureRepo,
        $gitAttempt(['git_empty_identity' => $emptyRootIdentity])
    ) === $incompleteGitReason,
    'a Git root written into after its empty-root manifest was sealed is non-confirmable — presence is not deletion authority'
);
check(
    $recoveryReason->invoke(
        null,
        $gitFixtureRepo,
        $gitAttempt([
            'git_empty_identity' => $emptyRootIdentity,
            'git_identity' => (string) $directoryIdentity->invoke(
                null,
                $gitFixtureRepo . '/.git',
                'Git metadata root'
            ),
        ])
    ) === null,
    'a completed git-ready manifest remains automatically recoverable'
);
$initAuthoritySource = (string) file_get_contents(__DIR__ . '/../../agent/src/Init.php');
check(
    substr_count($initAuthoritySource, 'self::git_empty_identity_current($repo, $owned)') === 2
        && str_contains($initAuthoritySource, 'private static function git_empty_identity_current('),
    'the proposal gate and the compensation authority resolve the empty-root manifest through one shared predicate'
);

// DUO-3421: the interrupted-init compensation runs over the same artifacts
// twice by design — Init::confirm()'s catch compensates its own publications,
// then re-enters recover_interrupted_attempt() to PROVE the rollback from the
// sealed journal. Every branch of that proof is presence-guarded and therefore
// idempotent except the two owned-file publications, which met a file they had
// just deleted and refused; the caller turned that into a retained journal,
// a retained lock, and an unclassified refusal where the contract promises a
// clean rollback.
$alreadyCompensated = (new ReflectionClass(\Duo\Init::class))
    ->getMethod('owned_file_already_compensated');
$alreadyCompensated->setAccessible(true);
$compensatedFixture = sys_get_temp_dir() . '/duo-init-compensated-' . bin2hex(random_bytes(6));
if (!mkdir($compensatedFixture, 0777, true)) fail('could not create the compensation fixture');
register_shutdown_function(static function () use ($compensatedFixture): void {
    exec('rm -rf ' . escapeshellarg($compensatedFixture));
});
$absentFile = $compensatedFixture . '/site.duo.json';
$presentFile = $compensatedFixture . '/.gitignore';
file_put_contents($presentFile, "published\n");
check(
    $alreadyCompensated->invoke(null, $absentFile, ['previous' => null, 'published' => 'x']) === true,
    'a deleted owned file with no prior version to restore reads as already compensated'
);
check(
    $alreadyCompensated->invoke(null, $absentFile, ['previous' => "prior\n", 'published' => 'x']) === false,
    'a deleted owned file whose record carries a prior version is still a compensation to perform'
);
check(
    $alreadyCompensated->invoke(null, $presentFile, ['previous' => null, 'published' => 'x']) === false
        && $alreadyCompensated->invoke(null, $presentFile, ['previous' => "prior\n", 'published' => 'x']) === false,
    'a present owned file is never skipped, whatever its record says'
);
$initCompensationSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Init.php');
check(
    substr_count($initCompensationSource, 'self::owned_file_already_compensated(') === 2
        && str_contains($initCompensationSource, 'private static function owned_file_already_compensated('),
    'both owned-file publications — site.duo.json and .gitignore — carry the same idempotence guard as their sibling branches'
);

// DUO-3421: both owned-file publications are strictly write-ahead — the plan,
// carrying the previous bytes, is journaled BEFORE the path is touched — so an
// artifact that is present while the journal holds no plan for it predates the
// attempt and is none of recovery's business. Refusing it made every ordinary
// pre-existing .gitignore (i.e. every existing Git worktree, which is what the
// live harness sets up by name) an unprovable ownership situation and demanded
// manual recovery for a file Duo had never opened.
$gitignoreFixture = sys_get_temp_dir() . '/duo-init-unbound-' . bin2hex(random_bytes(6));
if (!mkdir($gitignoreFixture, 0777, true)) fail('could not create the pre-existing-artifact fixture');
register_shutdown_function(static function () use ($gitignoreFixture): void {
    exec('rm -rf ' . escapeshellarg($gitignoreFixture));
});
file_put_contents($gitignoreFixture . '/.gitignore', "state.capture-staging/\n");
check(
    $recoveryReason->invoke(null, $gitignoreFixture, ['owned' => []]) === null,
    'a pre-existing .gitignore with no journaled plan leaves the interrupted attempt automatically recoverable'
);
file_put_contents($gitignoreFixture . '/site.duo.json', "{}\n");
check(
    $recoveryReason->invoke(null, $gitignoreFixture, ['owned' => []]) === null,
    'a pre-existing adoption seed with no journaled plan is likewise not this attempt to prove'
);
unlink($gitignoreFixture . '/.gitignore');
symlink('/nonexistent', $gitignoreFixture . '/.gitignore');
check(
    $recoveryReason->invoke(null, $gitignoreFixture, ['owned' => []])
        === 'the sealed attempt has a non-regular .gitignore boundary',
    'a non-regular owned-file boundary is still non-confirmable'
);
// DUO-3421: the proposal blocker and the recovery-time refusal describe the
// SAME artifact, and the harness (like both pins above) greps the proposal's
// words. The proposal said "partial code staging root" while every sibling
// message, the recovery refusal it precedes, and every pin said "tree", so the
// blocker fired correctly and named itself in words nothing else used.
check(
    substr_count($initCompensationSource, 'partial code staging tree without a complete descriptor') === 2
        && substr_count($initCompensationSource, 'partial state staging tree without a complete deletion manifest') === 2
        && !str_contains($initCompensationSource, 'partial code staging root')
        && !str_contains($initCompensationSource, 'partial state staging root'),
    'the proposal blockers and the recovery refusals name the partial code and state staging trees identically'
);
check(
    !str_contains($initCompensationSource, 'unbound site.duo.json')
        && !str_contains($initCompensationSource, 'unbound .gitignore')
        && substr_count($initCompensationSource, "is_array(\$owned['site_plan'] ?? null)") >= 1,
    'neither owned-file arm refuses an artifact the journal never planned; both require the plan they compensate against'
);

// DUO-3421: the pre-COMMIT rollback is a SUCCESSFUL outcome delivered as a
// non-zero exit — the interrupted attempt was proven and undone, and the
// operator simply reruns. Thrown as a bare RuntimeException on a command that
// is rightly absent from Cli::PUBLIC_REFUSAL_COMMANDS, it reached JSON callers
// as "init refused at an unclassified safety gate" with details_redacted:
// DUO-3398's shape on the recovery path. It has a reviewable shape, so per
// DUO-3399 it carries one.
require_once __DIR__ . '/../../agent/src/CommandRefusal.php';
check(
    str_contains($initCompensationSource, "throw new CommandRefusalException(\n                    'interrupted_init_rolled_back',")
        && !str_contains(
            $initCompensationSource,
            "throw new \\RuntimeException(\n                    'duo: interrupted pre-COMMIT init was safely rolled back"
        ),
    'the proven pre-COMMIT rollback answers with a reviewed reason code, not the unclassified arm'
);
$rolledBack = new \Duo\CommandRefusalException(
    'interrupted_init_rolled_back',
    'duo: interrupted pre-COMMIT init was safely rolled back; rerun duo init and confirm the fresh proposal',
    'rerun duo init and confirm the fresh proposal it prints'
);
check(
    $rolledBack->reasonCode === 'interrupted_init_rolled_back'
        && str_contains($rolledBack->publicMessage, 'safely rolled back')
        && $rolledBack->detailsRedacted === false,
    'the rollback outcome survives the refusal class own sensitivity screen as a public answer'
);

// DUO-3421: init must be able to STAGE the payload it is certified to manage.
// The staging walk applied safe_component()'s identifier charset — the one for
// slugs Duo selects — to directory names the SITE owns, so WooCommerce
// 11.0.0's assets/client/blocks/@woocommerce made `duo init` refuse its own
// golden path after the journal and lock existed. Staging components now use
// the traversal/control-byte predicate the code half applies to these exact
// paths for the rest of their lifecycle (Code::safe_relative()).
require_once __DIR__ . '/../../agent/src/Code.php';
$stageComponent = (new ReflectionClass(\Duo\Init::class))->getMethod('safe_stage_component');
$stageComponent->setAccessible(true);
$codeComponent = (new ReflectionClass(\Duo\Code::class))->getMethod('safe_component');
$codeComponent->setAccessible(true);
$identifierComponent = (new ReflectionClass(\Duo\Init::class))->getMethod('safe_component');
$identifierComponent->setAccessible(true);
$ecosystemNames = [
    '@woocommerce' => true,
    'Inter-VariableFont_slnt,wght.woff2' => true,
    'akismet-refresh-logo@2x.png' => true,
    'woocommerce' => true,
    'twentytwentyone' => true,
    '' => false,
    '.' => false,
    '..' => false,
    'a/b' => false,
    'a\\b' => false,
    "a\0b" => false,
    "a\tb" => false,
];
$stageVerdicts = [];
$codeVerdicts = [];
foreach ($ecosystemNames as $name => $expected) {
    $stageVerdicts[$name] = (bool) $stageComponent->invoke(null, $name);
    $codeVerdicts[$name] = (bool) $codeComponent->invoke(null, $name);
}
check(
    $stageVerdicts === $ecosystemNames,
    'code staging accepts the real ecosystem component names and still refuses traversal, separators, and control bytes'
);
check(
    $stageVerdicts === $codeVerdicts,
    'init stages exactly the components the code half will carry afterwards — one predicate, no init-only refusal'
);
check(
    $identifierComponent->invoke(null, '@woocommerce') === false
        && $identifierComponent->invoke(null, 'woocommerce') === true
        && substr_count($initAuthoritySource, 'self::safe_stage_component($part)') === 1
        && substr_count($initAuthoritySource, 'self::safe_component($component)') === 1
        && substr_count($initAuthoritySource, 'self::safe_component($theme)') === 1,
    'the selected plugin basename and theme slug keep the strict identifier charset; only the staging walk was widened'
);

final class InitRiskWpdb {
    public string $options = 'wp_options';
    public string $usermeta = 'wp_usermeta';
    public string $last_error = '';
    public bool $failOptions = false;
    public int $oversizedOptions = 0;
    public int $oversizedUserMeta = 0;
    /** @var list<array<string,mixed>> */
    public array $optionRows = [];
    /** @var list<array<string,mixed>> */
    public array $userMetaRows = [];

    public function get_results(string $sql, mixed $format): ?array {
        $this->last_error = '';
        if ($this->failOptions && str_contains($sql, $this->options)) {
            $this->last_error = 'sensitive database detail';
            return null;
        }
        $rows = str_contains($sql, $this->usermeta) ? $this->userMetaRows : $this->optionRows;
        preg_match('/> ([0-9]+).*LIMIT ([0-9]+)/s', $sql, $matches);
        $lastId = (int) ($matches[1] ?? 0);
        $limit = (int) ($matches[2] ?? 101);
        $idColumn = str_contains($sql, $this->usermeta) ? 'umeta_id' : 'option_id';
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int) ($row[$idColumn] ?? 0) > $lastId
        ));
        return array_slice($rows, 0, $limit);
    }

    public function get_var(string $sql): int {
        $this->last_error = '';
        return str_contains($sql, $this->usermeta) ? $this->oversizedUserMeta : $this->oversizedOptions;
    }
}

$riskProbe = (new ReflectionClass(\Duo\Init::class))->getMethod('risk_probe');
$codeSecretProbe = (new ReflectionClass(\Duo\Init::class))->getMethod('code_secret_label');
$secretFixture = tempnam(sys_get_temp_dir(), 'duo-init-long-secret-');
if (!is_string($secretFixture)) fail('could not create long-secret scanner fixture');
file_put_contents($secretFixture, "\n" . 'sk_live_' . str_repeat('A', 40000));
check($codeSecretProbe->invoke(null, $secretFixture) === 'stripe key', 'overlong boundary-less token is refused during streaming scan');
unlink($secretFixture);
$jwtFixture = tempnam(sys_get_temp_dir(), 'duo-init-jwt-shape-');
if (!is_string($jwtFixture)) fail('could not create JWT scanner fixture');
file_put_contents($jwtFixture, "\n" . 'eyJ' . str_repeat('A', 9000));
check($codeSecretProbe->invoke(null, $jwtFixture) === null, 'bare bundled base64url payload is not mislabeled as a JWT');
file_put_contents($jwtFixture, "\n" . 'eyJ' . str_repeat('A', 700) . '.eyJ' . str_repeat('B', 24) . '.signature');
check($codeSecretProbe->invoke(null, $jwtFixture) === 'jwt', 'complete long JWT remains blocked');
unlink($jwtFixture);
$originalWpdb = $GLOBALS['wpdb'] ?? null;
$fakeWpdb = new InitRiskWpdb();
$fakeWpdb->oversizedOptions = 2;
$fakeWpdb->oversizedUserMeta = 3;
$GLOBALS['wpdb'] = $fakeWpdb;
$boundedRisk = $riskProbe->invoke(null);
check(($boundedRisk['oversized'] ?? null) === ['options' => 2, 'user_meta' => 3], 'risk probe reports only redacted oversized omission counts');
check(($boundedRisk['truncated'] ?? false) === true, 'oversized values make risk readback explicitly incomplete');

$fakeWpdb->oversizedOptions = 0;
$fakeWpdb->oversizedUserMeta = 0;
for ($i = 1; $i <= 130; $i++) {
    $fakeWpdb->optionRows[] = [
        'option_id' => $i,
        'option_name' => "near_limit_$i",
        'option_value' => str_repeat('O', 65536),
    ];
}
$byteBoundedRisk = $riskProbe->invoke(null);
check(($byteBoundedRisk['scanned']['options'] ?? null) === 128, 'near-limit values stop at the deterministic 8 MiB surface budget');
check(($byteBoundedRisk['truncated'] ?? false) === true, 'byte-budget omission is reported as incomplete');

$fakeWpdb->failOptions = true;
try {
    $riskProbe->invoke(null);
    fail('failed risk query was reported as clean');
} catch (ReflectionException $unexpected) {
    throw $unexpected;
} catch (Throwable $expected) {
    check(str_contains($expected->getMessage(), 'could not read option values safely'), 'risk query error fails closed with a redacted diagnostic');
    check(!str_contains($expected->getMessage(), 'sensitive database detail'), 'risk query error does not disclose database details');
}
$GLOBALS['wpdb'] = $originalWpdb;

$adapterRepo = sys_get_temp_dir() . '/duo-init-adapter-permissions-' . bin2hex(random_bytes(6));
mkdir($adapterRepo . '/adapters', 0777, true);
file_put_contents($adapterRepo . '/adapters/foreign.json', "{}\n");
chmod($adapterRepo . '/adapters', 0000);
try {
    \Duo\AdapterSources::discover(__DIR__ . '/../../manifests', $adapterRepo);
    fail('unreadable adapter source was silently treated as empty');
} catch (RuntimeException $expected) {
    check(
        str_contains($expected->getMessage(), 'not readable')
            || str_contains($expected->getMessage(), 'could not be enumerated'),
        'unreadable adapter source fails closed instead of laundering inert bytes'
    );
} finally {
    chmod($adapterRepo . '/adapters', 0777);
    unlink($adapterRepo . '/adapters/foreign.json');
    rmdir($adapterRepo . '/adapters');
    rmdir($adapterRepo);
}

$nestedAdapterRepo = sys_get_temp_dir() . '/duo-init-nested-adapter-permissions-' . bin2hex(random_bytes(6));
mkdir($nestedAdapterRepo . '/adapters/nested', 0777, true);
file_put_contents($nestedAdapterRepo . '/adapters/nested/hidden.json', "{}\n");
chmod($nestedAdapterRepo . '/adapters/nested', 0000);
try {
    \Duo\AdapterSources::discover(__DIR__ . '/../../manifests', $nestedAdapterRepo);
    fail('unreadable nested adapter content was silently treated as empty');
} catch (RuntimeException $expected) {
    check(
        str_contains($expected->getMessage(), 'nested site adapter source')
            && str_contains($expected->getMessage(), 'could not be enumerated'),
        'unreadable nested adapter content fails closed instead of becoming inert bytes'
    );
} finally {
    chmod($nestedAdapterRepo . '/adapters/nested', 0777);
    unlink($nestedAdapterRepo . '/adapters/nested/hidden.json');
    rmdir($nestedAdapterRepo . '/adapters/nested');
    rmdir($nestedAdapterRepo . '/adapters');
    rmdir($nestedAdapterRepo);
}

echo "REGRESS_INIT_CONTRACT PASSED\n";
