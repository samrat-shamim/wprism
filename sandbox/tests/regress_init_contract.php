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
    public function __construct(array $responses) {
        parent::__construct('shop', ['repo_path' => '/srv/shop-state']);
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
$proposal = [
    'format' => 'duo-init-plan/v1',
    'digest' => $digest,
    'ready' => true,
    'environment' => [
        'wordpress' => '7.0.2', 'php' => '8.3.33',
        'database' => ['server' => '11.8.8-MariaDB'], 'home' => 'https://shop.example.test',
    ],
    'code' => [
        'management' => 'managed-baseline-proposed',
        'files' => 42,
        'bytes' => 8192,
        'source_revision' => str_repeat('c', 64),
        'roots' => ['plugins' => '/var/www/html/wp-content/plugins'],
        'active_plugins' => [['basename' => 'woocommerce/woocommerce.php', 'version' => '11.0.0']],
        'active_theme' => ['stylesheet' => 'shop-theme', 'template' => 'shop-theme'],
    ],
    'state' => [
        'repository' => '/srv/shop-state',
        'adapters' => [['name' => 'core'], ['name' => 'woocommerce']],
        'media' => ['strategy' => 'local', 'attachments' => 2, 'unavailable' => 0],
        'risk_surfaces' => [
            'options' => ['stripe key' => 1],
            'user_meta' => ['email address' => 2],
            'truncated' => true,
        ],
    ],
    'unsupported' => [],
];
$result = [
    'format' => 'duo-init-result/v1',
    'baseline' => ['kind' => 'state-capture', 'revision_hash' => str_repeat('b', 64)],
];

$transport = new InitTransport([response($proposal), response($result)]);
check(Init::proposal($transport) === $proposal, 'proposal JSON is returned without host-side reinterpretation');
check($transport->calls[0] === ['duo', 'init', '--repo=/srv/shop-state', '--format=json'], 'proposal uses the authenticated target agent and repository path');
check(Init::confirm($transport, $digest) === $result, 'confirmation result is returned');
check($transport->calls[1] === ['duo', 'init', '--repo=/srv/shop-state', '--confirm=' . $digest, '--format=json'], 'confirmation sends only the reviewed digest, never a mutable config payload');

$rendered = implode("\n", Init::render($proposal));
check(str_contains($rendered, 'code: managed-baseline-proposed'), 'rendering preserves the separate code/state contract');
check(str_contains($rendered, 'active plugin: woocommerce/woocommerce.php 11.0.0'), 'rendering inventories active plugin versions');
check(str_contains($rendered, 'core, woocommerce'), 'rendering names selected adapters');
check(str_contains($rendered, '1 secret-shaped option value(s), 2 PII-shaped user-meta value(s)'), 'rendering exposes redacted risk counts');
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
$siteWrite = strpos($agentSource, 'Canon::write_file($siteFile, Canon::encode($proposal[' . "'state'" . '][' . "'config'" . ']));');
check($lockedRecheck !== false && $siteWrite !== false && $lockedRecheck < $siteWrite, 'under-lock digest recheck precedes the site-config write');
check(str_contains($agentSource, "'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE]"), 'site config declares code independently from state policy');
check(str_contains($agentSource, 'Code::descriptor_from_source($stage)'), 'captured code is validated by the existing descriptor contract before publication');
check(str_contains($agentSource, 'Capture::run_initial_baseline($repo, $publicationLock)'), 'confirmed baseline uses the init-wide publication transaction');
check(str_contains($agentSource, 'SELECT GET_LOCK(%s, 0)'), 'concurrent confirmations share a target advisory lease');
check(str_contains($agentSource, "'existing_state_payload'") && str_contains($agentSource, "'existing_media_payload'") && str_contains($agentSource, "'existing_duo_ledger'"), 'stale state, media, and ledger ownership block initialization');
check(str_contains($agentSource, 'Secrets::hard_match($window)'), 'every code byte crosses the high-confidence secret matcher');
check(str_contains($agentSource, "['allowed_classes' => false]"), 'risk discovery cannot instantiate serialized user-meta objects');
check(!str_contains($agentSource, 'maybe_unserialize('), 'read-only risk discovery never uses class-enabled WordPress unserialization');
check(str_contains($agentSource, 'ORDER BY option_id ASC') && str_contains($agentSource, 'ORDER BY umeta_id ASC'), 'bounded risk discovery uses deterministic primary-key ordering');
check(str_contains($agentSource, "git', 'init', '--initial-branch=main"), 'confirmation creates a verified Git worktree when absent');
check(str_contains($agentSource, "\$finalGit['mode'] !== 'existing-worktree'"), 'success re-verifies Git readiness after the baseline transaction');

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

echo "REGRESS_INIT_CONTRACT PASSED\n";
