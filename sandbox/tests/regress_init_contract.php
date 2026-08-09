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
    'capture' => ['revision_hash' => $stateRevision, 'initial_code_baseline' => $lifecycle],
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

$slashTransport = new InitTransport([response($proposal), response($result)], '/srv/shop-state/');
check(Init::proposal($slashTransport) === $proposal, 'host normalizes a configured trailing slash when validating the target proposal');
check(Init::confirm($slashTransport, $digest) === $result, 'host normalizes a configured trailing slash when validating the target result');

$rendered = implode("\n", Init::render($proposal));
check(str_contains($rendered, 'code: managed-baseline-proposed'), 'rendering preserves the separate code/state contract');
check(str_contains($rendered, 'active plugin: woocommerce/woocommerce.php 11.0.0'), 'rendering inventories active plugin versions');
check(str_contains($rendered, 'core, woocommerce'), 'rendering names selected adapters');
check(str_contains($rendered, 'ADVISORY THEME shop-theme [active_theme_code_only]'), 'rendering exposes code-only active theme state coverage');
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
check(!str_contains(strtolower($agentSource), 'woocommerce'), 'generic target init has no plugin-name branch');
check(str_contains($agentSource, "(\$rule['class'] ?? null) === 'authored'"), 'post-type scope expands only from authored manifest rulings');
check(substr_count($agentSource, "(\$rule['class'] ?? null) === 'authored'") >= 2, 'post-type and taxonomy scope expand only from explicit authored manifest rulings');
$lockedRecheck = strrpos($agentSource, 'self::assert_confirmed_proposal($proposal, $expectedDigest);');
$siteWrite = strpos($agentSource, 'self::write_owned_file($siteFile, Canon::encode($proposal[' . "'state'" . '][' . "'config'" . ']), ' . "'site.duo.json'" . ');');
check($lockedRecheck !== false && $siteWrite !== false && $lockedRecheck < $siteWrite, 'under-lock digest recheck precedes the site-config write');
check(str_contains($agentSource, "'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE]"), 'site config declares code independently from state policy');
check(str_contains($agentSource, 'Code::descriptor_from_source($stage)'), 'captured code is validated by the existing descriptor contract before publication');
check(str_contains($agentSource, 'Capture::run_initial_baseline($repo, $publicationLock)'), 'confirmed baseline uses the init-wide publication transaction');
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
$publicationLock = strpos($agentSource, '$publicationLock = Publish::lock($stateDir);');
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
        && str_contains($agentSource, 'write_owned_file($siteFile')
        && str_contains($agentSource, 'if (!@rename($tmp, $path))'),
    'site config publication replaces an ordinary owned path without following links'
);
check(
    str_contains($agentSource, "'unsafe_code_root'")
        && str_contains($agentSource, "assert_absent_owned_path(\$codeRoot, 'code publication root')")
        && str_contains($agentSource, 'if (!rename($stagedCode, $codeRoot))'),
    'code baseline publishes its whole absent root atomically instead of traversing a link'
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

$liveHarness = (string) file_get_contents(__DIR__ . '/regress_duo_init.sh');
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
