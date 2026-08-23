<?php
/**
 * Offline product contract for host→target code push over ssh (DUO-3514).
 *
 * Before this, `duo code-resolve` on an ssh environment refused
 * `code_resolve_transport_unsupported` and the automatic deploy phase refused
 * with it unless the target ALREADY hashed correctly — so a fresh clone of a
 * split repository could not be deployed over ssh at all. docs/code-half.md
 * §2.2 (:241-242) fixes the shape: resolve OFF the target (production has no
 * registry egress, which `code_release_provider`'s probe attests) and ship
 * only the resolved trees.
 *
 * What separates that from "scp and hope" is the ORDER, and this suite pins
 * it end to end against a REAL filesystem rather than a mock: the fixture
 * transport executes the target-side scripts through `sh`, copies the archive
 * with `copy()`, and answers `wp duo code-inventory` by running the agent's
 * own `CodeDescriptorCompiler::component_inventory()`. Nothing is stubbed
 * except the network hop itself, so the tar members, the staging layout, the
 * `mv` guards and the digests are the real ones.
 *
 *   A. the full push: the exact ordered call sequence, and afterwards the
 *      target holds every locked component at the locked digest with no
 *      archive and no staging directory left behind;
 *   B. THE gate: a tree corrupted in flight is caught TARGET-SIDE, in the
 *      staging directory, before a single byte reaches code/wp-content —
 *      zero publish calls, the target still empty, and the archive and
 *      staging directory still removed;
 *   C. a component the target holds at a DIFFERENT digest refuses
 *      `code_resolve_component_drifted` before anything is fetched or
 *      transferred, and the planted bytes are byte-identical afterwards;
 *   D. a re-run transfers nothing at all — no allocation, no archive;
 *   E. `--dry-run` transfers nothing either, and says what it would do;
 *   F. a driver that is NOT a `CodePushTransport` keeps the byte-identical
 *      `code_resolve_transport_unsupported` refusal, DUO-3514 sentence
 *      included, because for it that sentence is still true.
 *
 * The local and docker arms are NOT re-pinned here: `regress_code_resolve.php`
 * K1/K2/K4b already own them, and this issue changed neither.
 *
 * The registry is a local `file://` fixture and every archive is built in this
 * process: the offline corpus contacts no network.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../cli/src/Code/CodeResolver.php';
require_once __DIR__ . '/../../../../cli/src/Command/CodeResolveCommand.php';

use Duo\CodeSourceLock;
use Duo\Orchestrator\CodeResolveCommand;
use Duo\Orchestrator\WpOrgReleases;

const PUSH_FORMAT_2 = [
    'format' => 2,
    'layout' => 'wp-content',
    'lock' => 'code/duo-code.lock.json',
    'source' => 'code/wp-content',
];

$scratch = sys_get_temp_dir() . '/duo_regress_code_push_' . bin2hex(random_bytes(6));
$registry = $scratch . '/registry';
mkdir($registry . '/plugin', 0775, true);
mkdir($registry . '/theme', 0775, true);
$targetSeq = 0;

/** @param array<string,string> $files */
function push_write_tree(string $root, array $files): void {
    foreach ($files as $relative => $body) {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $body);
    }
}

/** @param array<string,string> $files */
function push_write_archive(string $archivePath, string $componentRoot, array $files): void {
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("could not create the fixture archive $archivePath");
    }
    $zip->addEmptyDir($componentRoot);
    foreach ($files as $relative => $body) {
        $zip->addFromString($componentRoot . '/' . $relative, $body);
    }
    $zip->close();
}

function push_remove_tree(string $path): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

/** The digest one file map hashes to once it is a component directory on disk. */
function push_tree_digest(string $scratch, array $files): string {
    $probe = $scratch . '/probe-' . bin2hex(random_bytes(6));
    push_write_tree($probe, $files);
    $digest = WpOrgReleases::treeDigest($probe);
    push_remove_tree($probe);
    return $digest;
}

/**
 * A TARGET repository: `site.duo.json` at code format 2, the declared lock,
 * and only the components named in `$present` materialized.
 *
 * @param list<array<string,mixed>> $lockRows
 * @param array<string,array<string,string>> $present `{root}/{component}` => file map
 */
function push_make_target(string $scratch, int &$seq, array $lockRows, array $present = []): string {
    $repo = $scratch . '/target' . (++$seq);
    mkdir($repo . '/code/wp-content/plugins', 0775, true);
    mkdir($repo . '/code/wp-content/themes', 0775, true);
    file_put_contents(
        $repo . '/site.duo.json',
        \Duo\Canon::encode(['code' => PUSH_FORMAT_2, 'format' => 1, 'site' => 'fixture'])
    );
    file_put_contents($repo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($lockRows));
    foreach ($present as $key => $files) {
        push_write_tree($repo . '/' . CodeSourceLock::SOURCE . '/' . $key, $files);
    }
    return $repo;
}

if (!class_exists(ZipArchive::class)) {
    // Not a skip, and not a silent one: without ZipArchive this host cannot
    // verify a release at all, so the push has nothing verified to ship. That
    // is a refusal to state, not a suite to pass.
    duo_check(false, 'the php-zip extension is required to exercise the code push; install php-zip and rerun');
    push_remove_tree($scratch);
    duo_check_summary('regress_code_resolve_push');
}

// ---------------------------------------------------------------------------
// The library the target's lock declares.
// ---------------------------------------------------------------------------

$wooFiles = [
    'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n",
    'includes/class-wc.php' => "<?php\n// wc\n",
];
$themeFiles = ['style.css' => "/*\nTheme Name: Storefront\nVersion: 4.6.0\n*/\n"];
$otherFiles = [
    'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n// tampered\n",
];

push_write_archive($registry . '/plugin/woocommerce.11.0.0.zip', 'woocommerce', $wooFiles);
push_write_archive($registry . '/theme/storefront.4.6.0.zip', 'storefront', $themeFiles);

$wooTreeDigest = push_tree_digest($scratch, $wooFiles);
$themeTreeDigest = push_tree_digest($scratch, $themeFiles);
$lockRows = [
    [
        'root' => 'plugins',
        'component' => 'woocommerce',
        'version' => '11.0.0',
        'origin' => [
            'kind' => 'wp-org-release',
            'url' => WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '11.0.0'),
            'archive_sha256' => (string) hash_file('sha256', $registry . '/plugin/woocommerce.11.0.0.zip'),
        ],
        'tree_sha256' => $wooTreeDigest,
    ],
    [
        'root' => 'themes',
        'component' => 'storefront',
        'version' => '4.6.0',
        'origin' => [
            'kind' => 'wp-org-release',
            'url' => WpOrgReleases::canonicalUrl('themes', 'storefront', '4.6.0'),
            'archive_sha256' => (string) hash_file('sha256', $registry . '/theme/storefront.4.6.0.zip'),
        ],
        'tree_sha256' => $themeTreeDigest,
    ],
];

// The "tampered" tree an in-flight corruption substitutes: same component
// name, same layout, different bytes, so only a DIGEST catches it.
$tamperRoot = $scratch . '/tamper/plugins';
push_write_tree($tamperRoot . '/woocommerce', $otherFiles);
push_write_tree($scratch . '/tamper/themes/storefront', $themeFiles);

// ---------------------------------------------------------------------------
// The fixture transport and the child-process runner.
//
// A child process because the refusal renderer writes to STDERR, which
// ob_start() cannot capture: the STDERR constant is bound at startup and there
// is no in-process way to redirect fd 2. regress_code_resolve.php takes the
// identical proc_open([PHP_BINARY, ...]) seam, for the same reason.
// ---------------------------------------------------------------------------

$root = dirname(__DIR__, 4);
$fixtureFile = $scratch . '/push_fixture.php';
$runnerFile = $scratch . '/push_runner.php';

file_put_contents($fixtureFile, str_replace(
    '__DUO_ROOT__',
    var_export($root, true),
    <<<'PHP_FIXTURE'
<?php
declare(strict_types=1);
require_once __DUO_ROOT__ . '/cli/src/Transport/Transport.php';
require_once __DUO_ROOT__ . '/cli/src/Transport/CodePushTransport.php';
require_once __DUO_ROOT__ . '/cli/src/Command/CodeResolveCommand.php';
require_once __DUO_ROOT__ . '/agent/src/Code/CodeDescriptorCompiler.php';

/**
 * An ssh-shaped transport whose "target" is a real directory on this host.
 *
 * Everything the real SshTransport does over the wire is done locally and
 * recorded: `captureRaw()` runs the script through `sh` (so the mkdir, the
 * tar extract, the mv guards and the rm are the REAL ones), `putCodePushInput`
 * copies the archive, and `captureWp(['duo','code-inventory',…])` answers with
 * the agent's own component_inventory() over the named repository. Only the
 * network hop is absent.
 */
class PushFixtureTransport extends \Duo\Orchestrator\Transport {
    /** @var list<string> the ordered call log the suite asserts on */
    public array $events = [];
    /** @var list<string> every target path this fixture was asked to allocate */
    public array $allocated = [];

    public function __construct(
        string $repoPath,
        private string $lockPath,
        private ?string $corruptFrom = null
    ) {
        parent::__construct('push-fixture', ['repo_path' => $repoPath, 'transport' => 'ssh']);
    }

    /** ssh has no host-side repository, which is what selects the target arm. */
    public function hostRepoPath(): ?string { return null; }

    public function describe(): string { return 'code push fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureRaw(string $script): array {
        $this->events[] = $this->classify($script);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['sh', '-c', $script], $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'could not start sh'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function captureWp(array $wpArgs): array {
        if (($wpArgs[0] ?? '') !== 'duo' || ($wpArgs[1] ?? '') !== 'code-inventory') {
            $this->events[] = 'wp:other';
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'unsupported'];
        }
        $repo = '';
        foreach ($wpArgs as $arg) {
            if (str_starts_with($arg, '--repo=')) {
                $repo = substr($arg, strlen('--repo='));
            }
        }
        $staged = str_contains($repo, '/' . \Duo\Orchestrator\CodeResolveCommand::PUSH_STAGING . '/');
        $this->events[] = $staged ? 'wp:inventory-staged' : 'wp:inventory';
        $source = rtrim($repo, '/') . '/' . \Duo\CodeDescriptorCompiler::SOURCE;
        $components = is_dir($source) ? \Duo\CodeDescriptorCompiler::component_inventory($source) : [];
        return ['exit' => 0, 'stdout' => json_encode([
            'format' => 'duo-code-inventory/v1',
            'components' => $components,
            'source' => \Duo\CodeDescriptorCompiler::SOURCE,
        ], JSON_UNESCAPED_SLASHES), 'stderr' => ''];
    }

    public function allocateCodePushInput(string $label): string {
        $this->events[] = 'allocate';
        $path = sys_get_temp_dir() . '/duo-code-push-' . $label . '-' . bin2hex(random_bytes(16)) . '.tar';
        $this->allocated[] = $path;
        return $path;
    }

    public function putCodePushInput(string $localPath, string $targetPath): array {
        $this->events[] = 'put';
        if ($this->corruptFrom !== null) {
            // The in-flight corruption: an archive of the SAME component paths
            // built from different bytes. Only a digest read on the far side
            // can tell the two apart, which is exactly what is under test.
            $members = [];
            foreach (['plugins/woocommerce', 'themes/storefront'] as $member) {
                if (is_dir($this->corruptFrom . '/' . $member)) {
                    $members[] = escapeshellarg($member);
                }
            }
            $command = 'tar -C ' . escapeshellarg($this->corruptFrom) . ' -cf '
                . escapeshellarg($targetPath) . ' ' . implode(' ', $members);
            exec($command, $ignored, $exit);
            return ['exit' => $exit, 'stdout' => '', 'stderr' => ''];
        }
        return ['exit' => copy($localPath, $targetPath) ? 0 : 1, 'stdout' => '', 'stderr' => ''];
    }

    public function removeCodePushInput(string $targetPath): array {
        $this->events[] = 'remove';
        @unlink($targetPath);
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    private function classify(string $script): string {
        if (str_contains($script, '/site.duo.json')) {
            return 'raw:site';
        }
        if (str_contains($script, $this->lockPath)) {
            return 'raw:lock';
        }
        if (str_contains($script, 'tar --no-same-owner -xf')) {
            return 'raw:extract';
        }
        if (str_contains($script, 'mv "$s" "$d"')) {
            return 'raw:publish';
        }
        if (str_starts_with($script, 'rm -rf ')) {
            return 'raw:rm-staging';
        }
        return 'raw:other';
    }
}

/** The same fixture WITHOUT the push capability: every refusal must be unchanged. */
final class PushlessFixtureTransport extends PushFixtureTransport {
}

/** With it, which is the only difference between the two arms. */
final class PushCapableFixtureTransport extends PushFixtureTransport implements
    \Duo\Orchestrator\CodePushTransport {
}
PHP_FIXTURE
));

file_put_contents($runnerFile, str_replace(
    '__DUO_FIXTURE__',
    var_export($fixtureFile, true),
    <<<'PHP_RUNNER'
<?php
declare(strict_types=1);
require_once __DUO_FIXTURE__;

$spec = json_decode((string) $argv[1], true);
$class = ($spec['pushable'] ?? true) ? 'PushCapableFixtureTransport' : 'PushlessFixtureTransport';
$driver = new $class(
    (string) $spec['repo_path'],
    (string) $spec['lock_path'],
    isset($spec['corrupt_from']) ? (string) $spec['corrupt_from'] : null
);
$phase = null;
if (($spec['mode'] ?? 'verb') === 'verb') {
    $exit = \Duo\Orchestrator\CodeResolveCommand::run($driver, (array) ($spec['extra'] ?? []));
} else {
    $phase = \Duo\Orchestrator\CodeResolveCommand::deployPhase($driver, (string) ($spec['verb'] ?? 'deploy'));
    $exit = $phase ?? 0;
}
// A side channel, never stdout: one assertion below is that a repository with
// nothing to do prints an exact, short report and nothing else.
file_put_contents((string) $argv[2], json_encode([
    'phase' => $phase === null ? 'continue' : $phase,
    'events' => $driver->events,
    'allocated' => $driver->allocated,
]));
exit((int) $exit);
PHP_RUNNER
));

/**
 * Run one command boundary in a child process.
 *
 * `DUO_CODE_ARTIFACT_BASE` is pinned at the local `file://` registry: the
 * command boundary constructs its own WpOrgReleases and would otherwise take
 * the canonical downloads.wordpress.org base, which the offline corpus must
 * never reach.
 *
 * @param array<string,mixed> $spec
 * @return array{exit:int,stdout:string,stderr:string,phase:string|int,events:list<string>,allocated:list<string>}
 */
function push_run(string $runnerFile, string $scratch, array $spec, array $env = []): array {
    global $registry;
    $markers = $scratch . '/markers-' . bin2hex(random_bytes(6)) . '.json';
    $spec['lock_path'] ??= CodeSourceLock::PATH;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $runnerFile, json_encode($spec, JSON_UNESCAPED_SLASHES), $markers],
        $descriptors,
        $pipes,
        null,
        array_merge((array) getenv(), [
            'DUO_CODE_ARTIFACT_BASE' => 'file://' . $registry,
            'XDG_CACHE_HOME' => $scratch . '/xdg',
        ], $env)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start the code-push runner');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $side = is_file($markers) ? (array) json_decode((string) file_get_contents($markers), true) : [];
    @unlink($markers);
    return [
        'exit' => $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'phase' => $side['phase'] ?? 'absent',
        'events' => (array) ($side['events'] ?? []),
        'allocated' => (array) ($side['allocated'] ?? []),
    ];
}

/** Whatever `/tmp/duo-code-push-*.tar` a run left behind. @return list<string> */
function push_stray_archives(array $allocated): array {
    return array_values(array_filter($allocated, static fn(string $path): bool => is_file($path)));
}

/** The `.duo/code-push/` staging entries a run left behind. @return list<string> */
function push_stray_staging(string $repo): array {
    $found = glob($repo . '/' . CodeResolveCommand::PUSH_STAGING . '/*');
    return $found === false ? [] : array_values($found);
}

// ---------------------------------------------------------------------------
// A. The full push: the ordered sequence, then the target actually holds it.
// ---------------------------------------------------------------------------

$target = push_make_target($scratch, $targetSeq, $lockRows);
$result = push_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'repo_path' => $target,
]);
duo_check_same('continue', $result['phase'], 'the deploy phase resolves and pushes on ssh instead of refusing');
duo_check_same(
    [
        'raw:site', 'raw:lock', 'wp:inventory',
        'allocate', 'put', 'raw:extract', 'wp:inventory-staged',
        'raw:publish', 'wp:inventory',
        'remove', 'raw:rm-staging',
    ],
    $result['events'],
    'THE ordering contract: read the target, allocate, place, extract into staging, VERIFY the staged trees, '
    . 'publish, re-verify the repository, then clean up — the staged verification sits before the publish'
);
duo_check_same(
    $wooTreeDigest,
    WpOrgReleases::treeDigest($target . '/code/wp-content/plugins/woocommerce'),
    'and afterwards the target holds the plugin at exactly the digest its lock declares'
);
duo_check_same(
    $themeTreeDigest,
    WpOrgReleases::treeDigest($target . '/code/wp-content/themes/storefront'),
    'and the theme too'
);
duo_check_same([], push_stray_archives($result['allocated']), 'the pushed archive is removed from the target');
duo_check_same([], push_stray_staging($target), 'and so is the target-side staging directory');
duo_check(
    str_contains($result['stdout'], "deploy phase: code-resolve\n")
        && str_contains($result['stdout'], 'RESOLVED plugins/woocommerce 11.0.0')
        && str_contains($result['stdout'], 'deploy: 2 materialized, 0 unchanged.'),
    'reported in the vocabulary duo code-resolve already prints, with no new row state invented for ssh'
);
duo_check(
    !str_contains($result['stdout'] . $result['stderr'], 'DUO-3514'),
    'and nothing names DUO-3514 as unimplemented work any more on this arm'
);

// The host leaves no staging worktree of its own behind either.
duo_check_same(
    [],
    array_values(array_filter(
        (array) glob(sys_get_temp_dir() . '/duo-code-push-*'),
        static fn(string $path): bool => is_dir($path)
    )),
    'and the throwaway HOST staging worktree is removed too'
);

// The verb, not the phase: same mechanism, the verb's own rendering, exit 0.
$verbTarget = push_make_target($scratch, $targetSeq, $lockRows);
$result = push_run($runnerFile, $scratch, ['mode' => 'verb', 'repo_path' => $verbTarget]);
duo_check_same(0, $result['exit'], 'duo code-resolve now SUCCEEDS on an ssh environment');
duo_check(
    str_contains($result['stdout'], 'duo: code-resolve: 2 component(s) declared in ' . CodeSourceLock::PATH),
    'rendering under the verb prefix rather than a phase prefix'
);
duo_check_same(
    $wooTreeDigest,
    WpOrgReleases::treeDigest($verbTarget . '/code/wp-content/plugins/woocommerce'),
    'and the bytes are present where the target reads them'
);

// ---------------------------------------------------------------------------
// B. THE gate: a tree corrupted in flight never reaches code/wp-content.
// ---------------------------------------------------------------------------

$corrupt = push_make_target($scratch, $targetSeq, $lockRows);
$result = push_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'repo_path' => $corrupt,
    'corrupt_from' => $scratch . '/tamper',
]);
duo_check_same(1, $result['phase'], 'a tree that arrives corrupted refuses the phase');
duo_check(
    str_contains($result['stderr'], '[' . \Duo\Orchestrator\CodeResolver::REASON_TREE_DIGEST_MISMATCH . ']'),
    'with the tree-digest reason code the host-side resolver already uses for the same class of failure'
);
duo_check(
    str_contains($result['stderr'], 'plugins/woocommerce'),
    'naming the component whose digest disagreed'
);
duo_check_same(
    0,
    count(array_filter($result['events'], static fn(string $e): bool => $e === 'raw:publish')),
    'THE property: ZERO publish calls — the verification is target-side and it happens BEFORE any rename'
);
duo_check(
    !is_dir($corrupt . '/code/wp-content/plugins/woocommerce')
        && !is_dir($corrupt . '/code/wp-content/themes/storefront'),
    'so the target code/wp-content is untouched, not half-written'
);
duo_check_same(
    ['remove', 'raw:rm-staging'],
    array_values(array_slice($result['events'], -2)),
    'and the finally still removes the archive and the staging directory on the refusal path'
);
duo_check_same([], push_stray_archives($result['allocated']), 'leaving no /tmp/duo-code-push-*.tar behind');
duo_check_same([], push_stray_staging($corrupt), 'and no .duo/code-push/ entry behind');
duo_check(
    str_contains($result['stderr'], 'refusing before compile'),
    'stating that it stopped before compile, so no lease and no checkpoint exist to compensate'
);

// ---------------------------------------------------------------------------
// C. Drift refuses before anything is fetched or transferred.
// ---------------------------------------------------------------------------

$drifted = push_make_target($scratch, $targetSeq, $lockRows, ['plugins/woocommerce' => $otherFiles]);
$plantedDigest = WpOrgReleases::treeDigest($drifted . '/code/wp-content/plugins/woocommerce');
$result = push_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'repo_path' => $drifted,
]);
duo_check_same(1, $result['phase'], 'a component present at a different digest refuses');
duo_check(
    str_contains($result['stderr'], '[' . \Duo\Orchestrator\CodeResolver::REASON_DRIFTED . ']'),
    'with CodeResolver\'s own drift reason code: the doctrine crosses the transport unchanged'
);
duo_check(
    str_contains($result['stderr'], 'nothing overwrites a tree Git does not carry'),
    'and the same remedy sentence, because it is the same rule'
);
duo_check_same(
    ['raw:site', 'raw:lock', 'wp:inventory'],
    $result['events'],
    'refusing after the READ and before the first allocation: nothing is fetched, nothing is transferred'
);
duo_check_same(
    $plantedDigest,
    WpOrgReleases::treeDigest($drifted . '/code/wp-content/plugins/woocommerce'),
    'and the operator\'s own bytes are byte-identical afterwards'
);
duo_check(
    !is_dir($drifted . '/code/wp-content/themes/storefront'),
    'the ABSENT sibling is not pushed either: a repository half at the lock is the state nobody can reason about'
);

// ---------------------------------------------------------------------------
// D. A re-run transfers nothing.
// ---------------------------------------------------------------------------

$result = push_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'repo_path' => $target,
]);
duo_check_same('continue', $result['phase'], 'a target that already holds every locked component proceeds');
duo_check_same(
    ['raw:site', 'raw:lock', 'wp:inventory'],
    $result['events'],
    'and transfers nothing at all: no allocation, no archive, no extract'
);
duo_check(
    str_contains($result['stdout'], 'UNCHANGED plugins/woocommerce')
        && str_contains($result['stdout'], 'deploy: 0 materialized, 2 unchanged.'),
    'reporting every component unchanged, exactly as the read-only arm did before the push existed'
);

// ---------------------------------------------------------------------------
// E. --dry-run transfers nothing either.
// ---------------------------------------------------------------------------

$dry = push_make_target($scratch, $targetSeq, $lockRows);
$result = push_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'repo_path' => $dry,
    'extra' => ['--dry-run'],
]);
duo_check_same(0, $result['exit'], 'a dry run over ssh exits 0');
duo_check_same(
    ['raw:site', 'raw:lock', 'wp:inventory'],
    $result['events'],
    'and contacts the target only to READ: no allocation and no archive'
);
duo_check(
    str_contains($result['stdout'], 'WOULD-RESOLVE plugins/woocommerce 11.0.0')
        && str_contains($result['stdout'], 'duo: --dry-run: nothing was fetched, written, or cached.'),
    'saying what it would do in the vocabulary the host-side dry run already prints'
);
duo_check(
    !is_dir($dry . '/code/wp-content/plugins/woocommerce'),
    'and nothing lands on the target'
);

// ---------------------------------------------------------------------------
// F. A driver with no push capability keeps the refusal it always had.
// ---------------------------------------------------------------------------

$unpushable = push_make_target($scratch, $targetSeq, $lockRows);
$result = push_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'repo_path' => $unpushable,
    'pushable' => false,
]);
duo_check_same(1, $result['phase'], 'a driver that does not implement CodePushTransport still refuses');
duo_check(
    str_contains($result['stderr'], '[' . CodeResolveCommand::REASON_TRANSPORT_UNSUPPORTED . ']'),
    'with the byte-identical reason code'
);
duo_check(
    str_contains($result['stderr'], 'host-to-target push over ssh is DUO-3514 and is not implemented'),
    'and the byte-identical DUO-3514 sentence, which for a transport with no push mechanism is still true'
);
duo_check(
    str_contains($result['stderr'], 'plugins/woocommerce 11.0.0 (absent)')
        && str_contains($result['stderr'], 'themes/storefront 4.6.0 (absent)'),
    'naming each pending component in lock order, exactly as before'
);
duo_check_same(
    ['raw:site', 'raw:lock', 'wp:inventory'],
    $result['events'],
    'and the read sequence it issues is unchanged'
);

// The capability is the ONLY difference: the same target, the same lock, the
// same reads — one arm refuses and the other pushes.
$result = push_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'repo_path' => $unpushable,
]);
duo_check_same('continue', $result['phase'], 'and the identical run through a CodePushTransport succeeds');

push_remove_tree($scratch);

duo_check_summary('regress_code_resolve_push');
