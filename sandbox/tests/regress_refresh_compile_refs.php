<?php
declare(strict_types=1);

/**
 * Offline DUO-3343 regression for the Git-ref compiler boundary.
 *
 * Every fixture commit deliberately defines the same manifest interpreter and
 * regenerator class names, but each implementation declares a different
 * reference shape.  The public compileGitWorktree() calls are sequential on
 * purpose: a caller that evaluates refs in one PHP process leaks the first
 * manifest classes (or redeclares them), while the worker contract must give
 * each ref a fresh process.  The wrong-ref call separately proves that the
 * worker checks the worktree HEAD instead of trusting the declared commit.
 */

use Duo\Orchestrator\RefreshPlan;

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "FAIL: repository root is unavailable\n");
    exit(1);
}
require_once $root . '/cli/src/RefreshPlan.php';

$failures = 0;

function check_compile(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function git_fixture(string $repo, array $args, bool $allowFailure = false): string {
    $command = 'git -C ' . escapeshellarg($repo);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    $output = implode("\n", $lines);
    if ($status !== 0 && !$allowFailure) {
        throw new RuntimeException("git command failed ($status): $command\n$output");
    }
    return $output;
}

function fixture_write(string $path, string $bytes): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("could not create fixture directory: $directory");
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException("could not write fixture file: $path");
    }
}

function fixture_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }
        fixture_remove($path . '/' . $child);
    }
    @rmdir($path);
}

function post_source(mixed $value): string {
    $front = [
        'author' => null,
        'comment_status' => 'open',
        'date' => '2026-08-09 00:00:00',
        'date_gmt' => '2026-08-09 00:00:00',
        'excerpt' => '',
        'menu_order' => 0,
        'meta' => ['_probe' => $value],
        'modified' => '2026-08-09 00:00:00',
        'modified_gmt' => '2026-08-09 00:00:00',
        'parent' => null,
        'ping_status' => 'closed',
        'slug' => 'probe',
        'status' => 'publish',
        'terms' => [],
        'title' => 'Probe',
        'type' => 'post',
        'uuid' => '00000000-0000-4000-8000-000000000001',
    ];
    return "---\n"
        . json_encode($front, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . "\n---\nprobe body\n";
}

function order_preserving_post_source(): string {
    // This mirrors a Capture OrderPreserved subtree: a nested authored map
    // whose insertion order changes downstream behavior.  Parsing to PHP
    // arrays drops the wrapper, so a refresh compiler may only carry the
    // exact validated source bytes forward.
    $front = [
        'author' => null,
        'comment_status' => 'open',
        'date' => '2026-08-09 00:00:00',
        'date_gmt' => '2026-08-09 00:00:00',
        'excerpt' => '',
        'menu_order' => 0,
        'meta' => ['_ordered' => [
            'z-before-a' => ['position' => 1],
            'a-after-z' => ['position' => 2],
        ]],
        'modified' => '2026-08-09 00:00:00',
        'modified_gmt' => '2026-08-09 00:00:00',
        'parent' => null,
        'ping_status' => 'closed',
        'slug' => 'order-preserved',
        'status' => 'publish',
        'terms' => [],
        'title' => 'Order Preserved',
        'type' => 'post',
        'uuid' => '00000000-0000-4000-8000-000000000002',
    ];
    return "---\n"
        . json_encode($front, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . "\n---\nordered body\n";
}

function provider_source(string $version, string $ref): string {
    return "<?php\n"
        . "namespace Duo\\Regenerators;\n"
        . "final class Probe {\n"
        . "    public const VERSION = " . var_export($version, true) . ";\n"
        . "    public function __construct(\$policy) {}\n"
        . "    public function regenerate(int \$localId): void {}\n"
        . "    public static function rule(): array {\n"
        . "        return ['class' => 'authored', 'ref' => " . var_export($ref, true) . "];\n"
        . "    }\n"
        . "}\n";
}

function interpreter_source(string $version): string {
    return str_replace('__VERSION__', var_export($version, true), <<<'PHP'
<?php
namespace Duo\Interpreters;
final class Probe {
    public const VERSION = __VERSION__;
    private array $rule;
    public function __construct($policy) {
        // Force the manifest-owned provider through the normal Policy seam.
        // This makes both same-named provider and interpreter classes part of
        // the ref compilation, without putting plugin semantics in the engine.
        $policy->regenerators();
        $this->rule = \Duo\Regenerators\Probe::rule();
    }
    public function post_meta_rule(string $key, array $allMeta): ?array {
        if ($key === '_ordered') return ['class' => 'authored', 'order_preserving' => true];
        return $key === '_probe' ? $this->rule : null;
    }
}
PHP);
}

function manifest_source(): string {
    return json_encode([
        'name' => 'probe',
        'spec_version' => 2,
        'interpreter' => 'probe',
        'post_types' => [
            'post' => [
                'class' => 'authored',
                'regen_dependency' => [
                    'regenerator' => 'probe',
                    'verify' => ['table' => 'probe_lookup', 'column' => 'post_id'],
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function site_source(): string {
    return json_encode([
        'manifests' => ['probe'],
        'policy' => [
            'options' => new stdClass(),
            'post_meta' => new stdClass(),
            'term_meta' => new stdClass(),
            'post_types' => ['post'],
            'taxonomies' => [],
        ],
        'spec_version' => 2,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function options_source(): string {
    // Exact Canon::encode() bytes for an intentionally empty option document.
    // The compile worker must retain these structured state bytes rather than
    // emitting an empty-string placeholder for options/core.
    return "{\n    \"format\": \"duo-options/v1\",\n    \"records\": []\n}\n";
}

$fixture = sys_get_temp_dir() . '/duo_refresh_compile_refs_' . bin2hex(random_bytes(6));
$trees = [$fixture . '/tree-base', $fixture . '/tree-branch', $fixture . '/tree-production-code'];
mkdir($fixture, 0777, true);

register_shutdown_function(static function () use ($fixture, $trees): void {
    foreach ($trees as $tree) {
        if (is_dir($tree)) {
            $repo = dirname($tree);
            git_fixture($repo, ['worktree', 'remove', '--force', $tree], true);
        }
    }
    fixture_remove($fixture);
});

try {
    git_fixture($fixture, ['init', '-q']);
    git_fixture($fixture, ['config', 'user.email', 'duo-refresh-regression@example.test']);
    git_fixture($fixture, ['config', 'user.name', 'Duo refresh regression']);

    fixture_write($fixture . '/site.duo.json', site_source());
    fixture_write($fixture . '/manifests/probe.json', manifest_source());
    fixture_write($fixture . '/manifests/interpreters/probe.php', interpreter_source('A'));
    fixture_write($fixture . '/state/posts/post/00000000-0000-4000-8000-000000000001--probe.md', post_source('{{post:00000000-0000-4000-8000-000000000001}}'));
    $orderedPost = order_preserving_post_source();
    fixture_write($fixture . '/state/posts/post/00000000-0000-4000-8000-000000000002--order-preserved.md', $orderedPost);
    fixture_write($fixture . '/state/options/core.json', options_source());
    $orphanMediaBytes = "refresh-safe-orphan-media\n";
    $orphanMediaName = hash('sha256', $orphanMediaBytes) . '.txt';
    fixture_write($fixture . '/media/' . $orphanMediaName, $orphanMediaBytes);
    fixture_write($fixture . '/manifests/regenerators/probe.php', provider_source('A', 'post'));
    git_fixture($fixture, ['add', '.']);
    git_fixture($fixture, ['commit', '-qm', 'probe implementation A']);
    $baseCommit = trim(git_fixture($fixture, ['rev-parse', 'HEAD']));

    fixture_write($fixture . '/state/posts/post/00000000-0000-4000-8000-000000000001--probe.md', post_source([
        '{{post:00000000-0000-4000-8000-000000000001}}',
    ]));
    fixture_write($fixture . '/manifests/interpreters/probe.php', interpreter_source('B'));
    fixture_write($fixture . '/manifests/regenerators/probe.php', provider_source('B', 'post[]'));
    git_fixture($fixture, ['add', '.']);
    git_fixture($fixture, ['commit', '-qm', 'probe implementation B']);
    $branchCommit = trim(git_fixture($fixture, ['rev-parse', 'HEAD']));

    fixture_write($fixture . '/state/posts/post/00000000-0000-4000-8000-000000000001--probe.md', post_source('{{post:00000000-0000-4000-8000-000000000001}}'));
    fixture_write($fixture . '/manifests/interpreters/probe.php', interpreter_source('C'));
    fixture_write($fixture . '/manifests/regenerators/probe.php', provider_source('C', 'post'));
    git_fixture($fixture, ['add', '.']);
    git_fixture($fixture, ['commit', '-qm', 'probe implementation C']);
    $productionCommit = trim(git_fixture($fixture, ['rev-parse', 'HEAD']));

    foreach ([[$trees[0], $baseCommit], [$trees[1], $branchCommit], [$trees[2], $productionCommit]] as [$tree, $commit]) {
        git_fixture($fixture, ['worktree', 'add', '--detach', $tree, $commit]);
    }

    // These three calls intentionally share this PHP caller.  The public
    // seam must dispatch each one to a fresh worker process; compiling B in
    // the A worker would reuse Duo\Interpreters\Probe and/or
    // Duo\Regenerators\Probe and reject the list-vs-scalar declaration.
    $base = RefreshPlan::compileGitWorktree($trees[0], $baseCommit, 'base');
    $branch = RefreshPlan::compileGitWorktree($trees[1], $branchCommit, 'branch');
    $productionCode = RefreshPlan::compileGitWorktree($trees[2], $productionCommit, 'production-code');
    $candidatePolicy = RefreshPlan::fieldDiffPolicyFromGitWorktree($trees[1], $branchCommit, 'candidate');
    $scopedBranchBaseline = RefreshPlan::compileGitWorktree(
        $trees[1],
        $branchCommit,
        'branch',
        null,
        true
    );

    foreach ([['base', $base, $baseCommit], ['branch', $branch, $branchCommit], ['production-code', $productionCode, $productionCommit]] as [$label, $artifact, $commit]) {
        check_compile(is_array($artifact), "$label worker returned an artifact");
        check_compile(($artifact['commit'] ?? null) === $commit, "$label artifact records the declared checkout commit");
        check_compile(($artifact['label'] ?? null) === $label, "$label artifact records its compiler role");
        check_compile(is_array($artifact['policy'] ?? null) && is_string($artifact['policy']['manifest_hash'] ?? null), "$label compiled the manifest-bound policy");
    }
    $manifestHashes = [
        $base['policy']['manifest_hash'] ?? null,
        $branch['policy']['manifest_hash'] ?? null,
        $productionCode['policy']['manifest_hash'] ?? null,
    ];
    check_compile(count(array_unique($manifestHashes)) === 3, 'same-named interpreter/provider implementations are evaluated per Git ref');
    check_compile(
        count(array_unique([
            $base['repository']['artifact_hash'] ?? null,
            $branch['repository']['artifact_hash'] ?? null,
            $productionCode['repository']['artifact_hash'] ?? null,
        ])) === 3,
        'each ref has an independent compiled artifact rather than leaked PHP class state'
    );
    check_compile(
        ($candidatePolicy['format'] ?? null) === 'duo-refresh-field-policy/v1'
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($candidatePolicy['projection_hash'] ?? '')) === 1
            && ($candidatePolicy['derived_post_fields'] ?? null) === ($branch['field_diff_policy']['derived_post_fields'] ?? null)
            && ($candidatePolicy['manifest_hash'] ?? null) === ($branch['field_diff_policy']['manifest_hash'] ?? null)
            && ($candidatePolicy['state_site_hash'] ?? null) === ($branch['field_diff_policy']['state_site_hash'] ?? null),
        'fresh field-diff-policy worker loads the candidate ref policy only and returns its closed hash-bound projection'
    );

    $orderedContent = $base['records']['00000000-0000-4000-8000-000000000002']['content'] ?? null;
    check_compile(
        is_string($orderedContent)
            && hash_equals($orderedPost, $orderedContent)
            && strpos($orderedContent, 'z-before-a') < strpos($orderedContent, 'a-after-z'),
        'compile worker retains exact order-preserving post state bytes instead of reserializing decoded maps'
    );

    $options = $base['records']['options/core'] ?? null;
    $optionsContent = is_array($options) ? ($options['content'] ?? null) : null;
    $optionsCanonical = is_string($optionsContent) && $optionsContent !== '' && $optionsContent === options_source();
    check_compile(
        $optionsCanonical,
        'compile worker reconstructs options/core canonical content instead of an empty placeholder'
            . ($optionsCanonical ? '' : ' (got ' . var_export($optionsContent, true) . ')')
    );
    $orphanPayload = $scopedBranchBaseline['media'][$orphanMediaName] ?? null;
    check_compile(
        !isset($base['media'][$orphanMediaName])
            && is_array($orphanPayload)
            && ($orphanPayload['sha256'] ?? null) === hash('sha256', $orphanMediaBytes)
            && base64_decode((string) ($orphanPayload['base64'] ?? ''), true) === $orphanMediaBytes,
        'legacy ref snapshots omit safe orphans while a scoped W baseline carries every verified media blob'
    );
    try {
        // Calling plan first loads the shared state serializers in this
        // parent process as well as exercising the exact consumer boundary.
        $roundTripPlan = RefreshPlan::plan($base, $base, $base, []);
        check_compile(
            ($roundTripPlan['format'] ?? null) === 'duo-refresh-plan/v1',
            'semantic planner accepts compiled options/core content without an empty/noncanonical decode'
        );
        $decodedOptions = \Duo\Canon::decode((string) $optionsContent);
        check_compile(
            \Duo\Canon::encode($decodedOptions) === $optionsContent,
            'compiled options/core content is canonical and round-trips through the state serializer'
        );
    } catch (Throwable $e) {
        check_compile(false, 'compiled options/core content round-trips through the semantic planner: ' . $e->getMessage());
    }

    try {
        RefreshPlan::compileGitWorktree($trees[1], $baseCommit, 'branch');
        check_compile(false, 'worker refuses a declared commit that differs from worktree HEAD');
    } catch (Throwable $e) {
        check_compile(
            preg_match('/HEAD|checkout|declared commit|does not match/i', $e->getMessage()) === 1,
            'worker refuses a declared commit that differs from worktree HEAD'
        );
    }
    try {
        RefreshPlan::fieldDiffPolicyFromGitWorktree($trees[1], $baseCommit, 'candidate');
        check_compile(false, 'field-diff-policy worker accepted a declared commit that differs from worktree HEAD');
    } catch (Throwable $e) {
        check_compile(
            preg_match('/HEAD|checkout|declared commit|does not match/i', $e->getMessage()) === 1,
            'field-diff-policy worker refuses a declared commit that differs from worktree HEAD'
        );
    }
} catch (Throwable $e) {
    check_compile(false, 'compile-ref fixture completed: ' . $e->getMessage());
}

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
