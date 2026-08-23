<?php
/**
 * Offline characterization for `duo code-classify` (DUO-3499).
 *
 * The migration's whole claim is that it is FREE: because the descriptor
 * hashes the bytes on disk and the lock only declares provenance, splitting an
 * already-initialized repository is a pure Git operation — the bytes never
 * leave the working tree, only Git stops tracking them, and the next compile
 * produces the identical `code_revision` and therefore the identical
 * `artifact_hash`. No re-pin, no deploy, nothing fleet-visible.
 *
 * That claim is asserted here rather than described: the suite compiles the
 * repository before and after the migration and compares the revisions, and it
 * pins the refusals that keep the claim true — a dirty `code/` tree, a target
 * that describes different components, a `site.duo.json` that is not
 * canonical — plus the one the no-third-party-bytes invariant adds: a
 * component that neither locks nor is declared first-party refuses the WHOLE
 * run, because there is no third state for Git to hold it in. A format-2
 * repository is re-classified rather than refused, which is how it gains a
 * first-party declaration or a newly imported archive's lock entry.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeDescriptorCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Command/CodeClassifyCommand.php';

use Duo\Canon;
use Duo\CodeDescriptorCompiler;
use Duo\CodeSourceLock;
use Duo\Orchestrator\CodeClassifyCommand;
use Duo\Orchestrator\Transport;

const FORMAT_1 = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
const FORMAT_2 = ['format' => 2, 'layout' => 'wp-content', 'lock' => 'code/duo-code.lock.json', 'source' => 'code/wp-content'];

/** A transport that answers exactly `wp duo code-inventory` from a fixed body. */
final class ClassifyFixtureTransport extends Transport {
    /** @param array<string,mixed>|null $inventory null answers with a failure */
    public function __construct(private ?array $inventory, private int $exit = 0) {
        parent::__construct('classify-fixture', ['repo_path' => '/srv/site']);
    }

    public function describe(): string { return 'code classify fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    /** @var list<list<string>> */
    public array $requests = [];

    public function captureWp(array $wpArgs): array {
        $this->requests[] = array_values(array_map('strval', $wpArgs));
        if ($this->inventory === null) {
            return ['exit' => $this->exit, 'stdout' => '', 'stderr' => 'the fixture target refuses'];
        }
        return [
            'exit' => $this->exit,
            'stdout' => json_encode($this->inventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'stderr' => '',
        ];
    }
}

$scratch = sys_get_temp_dir() . '/duo_regress_code_classify_' . bin2hex(random_bytes(6));
$registry = $scratch . '/registry';
mkdir($registry . '/plugin', 0775, true);
mkdir($registry . '/theme', 0775, true);
putenv('DUO_CODE_ARTIFACT_BASE=file://' . $registry);

$gitAvailable = trim((string) shell_exec('command -v git 2>/dev/null')) !== '';
$zipAvailable = class_exists(ZipArchive::class);

$wooFiles = [
    'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n",
    'includes/class-wc.php' => "<?php\n// wc\n",
];
$agencyFiles = ['duo-agency.php' => "<?php\n/**\n * Plugin Name: Duo Agency\n * Version: 1.0.0\n */\n"];

if ($zipAvailable) {
    $zip = new ZipArchive();
    $zip->open($registry . '/plugin/woocommerce.11.0.0.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addEmptyDir('woocommerce');
    foreach ($wooFiles as $relative => $body) {
        $zip->addFromString('woocommerce/' . $relative, $body);
    }
    $zip->close();
}

$seq = 0;

/** A committed site repository with two components under code/wp-content. */
function make_site_repo(string $scratch, int &$seq, array $wooFiles, array $agencyFiles, bool $git, array $codeConfig = FORMAT_1): string {
    $repo = $scratch . '/site' . (++$seq);
    $source = $repo . '/code/wp-content';
    foreach (['plugins/woocommerce' => $wooFiles, 'plugins/duo-agency' => $agencyFiles] as $component => $files) {
        foreach ($files as $relative => $body) {
            $path = $source . '/' . $component . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $body);
        }
    }
    mkdir($repo . '/state', 0775, true);
    file_put_contents($repo . '/state/.keep', '');
    file_put_contents($repo . '/site.duo.json', Canon::encode([
        'code' => $codeConfig,
        'manifests' => [['digest' => str_repeat('d', 64), 'name' => 'core']],
        'spec_version' => 2,
    ]));
    if ($git) {
        foreach ([
            ['init', '--initial-branch=main'],
            ['config', 'user.email', 'suite@duo.test'],
            ['config', 'user.name', 'Duo Suite'],
            ['add', '-A'],
            ['commit', '-m', 'baseline', '--no-gpg-sign'],
        ] as $args) {
            exec('git -C ' . escapeshellarg($repo) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $status);
            if ($status !== 0) {
                return $repo;
            }
        }
    }
    return $repo;
}

function tracked(string $repo): array {
    exec('git -C ' . escapeshellarg($repo) . ' ls-files 2>/dev/null', $out);
    sort($out, SORT_STRING);
    return $out;
}

/** @return array{0:int,1:string} exit code and the message of a refusal */
function classify_repo(Transport $transport, string $repo, bool $dryRun = false, bool $offline = false, ?string $cacheDir = null, array $firstParty = []): array {
    $method = new ReflectionMethod(CodeClassifyCommand::class, 'classify');
    ob_start();
    try {
        $exit = (int) $method->invoke(null, $transport, $repo, $dryRun, $offline, $cacheDir, $firstParty);
        ob_end_clean();
        return [$exit, ''];
    } catch (\Throwable $error) {
        ob_end_clean();
        return [1, $error->getMessage()];
    }
}

function inventory_body(array $components): array {
    return ['format' => 'duo-code-inventory/v1', 'components' => $components, 'source' => 'code/wp-content'];
}

function remove_tree(string $path): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

$cache = $scratch . '/cache';
mkdir($cache, 0775, true);

// ---------------------------------------------------------------------------
// Refusals that do not need Git or a release archive.
// ---------------------------------------------------------------------------

$stateOnly = make_site_repo($scratch, $seq, $wooFiles, $agencyFiles, false);
file_put_contents($stateOnly . '/site.duo.json', Canon::encode(['manifests' => [], 'spec_version' => 2]));
[$exit, $message] = classify_repo(new ClassifyFixtureTransport(null), $stateOnly);
duo_check_same(1, $exit, 'a state-only repository is refused');
duo_check(str_contains($message, 'no code half to classify'), 'and told it has no code half, rather than being half-migrated');

$already = make_site_repo($scratch, $seq, $wooFiles, $agencyFiles, false, FORMAT_2);
[$exit, $message] = classify_repo(new ClassifyFixtureTransport(null), $already);
duo_check(
    str_contains($message, 'code/duo-code.lock.json is not a regular file'),
    'a format-2 repository is re-classified, not refused as "already split" — so one whose declared lock is missing refuses on the lock'
);
file_put_contents($already . '/code/duo-code.lock.json', CodeSourceLock::encode([[
    'root' => 'plugins',
    'component' => 'absent-premium',
    'version' => '2.0.0',
    'origin' => ['kind' => 'imported-archive', 'archive_sha256' => str_repeat('c', 64)],
    'tree_sha256' => str_repeat('d', 64),
]]));
[$exit, $message] = classify_repo(new ClassifyFixtureTransport(null), $already);
duo_check(
    str_contains($message, 'the lock declares plugins/absent-premium but the component is not on disk')
        && str_contains($message, 'duo code-resolve'),
    'a locked component that is not on disk refuses the re-classification and names code-resolve: nothing is dropped from the lock by omission'
);

$noncanonical = make_site_repo($scratch, $seq, $wooFiles, $agencyFiles, false);
file_put_contents($noncanonical . '/site.duo.json', "{\"spec_version\":2,\"code\":{\"format\":1,\"layout\":\"wp-content\",\"source\":\"code/wp-content\"}}\n");
[$exit, $message] = classify_repo(new ClassifyFixtureTransport(null), $noncanonical);
duo_check(
    str_contains($message, 'is not canonical'),
    'a non-canonical site.duo.json is refused: rewriting one key would move bytes nobody reviewed'
);

if (!$gitAvailable) {
    duo_check(true, 'git is unavailable on this host; the Git-dependent half of this suite is reported, not skipped silently');
    remove_tree($scratch);
    duo_check_summary('regress_code_classify');
}

// ---------------------------------------------------------------------------
// The dirty-tree refusal: `git rm --cached` must never orphan an edit.
// ---------------------------------------------------------------------------

$dirty = make_site_repo($scratch, $seq, $wooFiles, $agencyFiles, true);
file_put_contents($dirty . '/code/wp-content/plugins/woocommerce/woocommerce.php', "<?php\n// edited, uncommitted\n");
[$exit, $message] = classify_repo(new ClassifyFixtureTransport(null), $dirty);
duo_check_same(1, $exit, 'a repository with uncommitted changes under code/ is refused');
duo_check(
    str_contains($message, 'has uncommitted changes') && str_contains($message, 'would become the only copy'),
    'and told exactly why: untracking a tree with an edit inside it orphans that edit'
);
duo_check(
    is_file($dirty . '/code/duo-code.lock.json') === false,
    'and nothing was written before the refusal'
);

// ---------------------------------------------------------------------------
// The target cross-check.
// ---------------------------------------------------------------------------

$repo = make_site_repo($scratch, $seq, $wooFiles, $agencyFiles, true);
$source = $repo . '/code/wp-content';
$localInventory = CodeDescriptorCompiler::component_inventory($source);
$beforeRevision = CodeDescriptorCompiler::compile($repo, FORMAT_1)['code_revision'];

$disagreeing = array_map(
    static fn(array $row): array => array_replace($row, ['tree_sha256' => str_repeat('f', 64)]),
    $localInventory
);
[$exit, $message] = classify_repo(new ClassifyFixtureTransport(inventory_body($disagreeing)), $repo);
duo_check_same(1, $exit, 'a target describing different component digests is refused');
duo_check(
    str_contains($message, 'describe different code components'),
    'and told that the checkout and the target are at different revisions'
);

[$exit, $message] = classify_repo(new ClassifyFixtureTransport(null, 1), $repo);
duo_check(
    str_contains($message, 'could not report its code inventory'),
    'a target that cannot answer at all is refused, not assumed to agree'
);

$agreeing = new ClassifyFixtureTransport(inventory_body($localInventory));
duo_check_same(
    ['duo', 'code-inventory', '--repo=/srv/site', '--format=json'],
    (classify_repo($agreeing, $repo, true, false, $cache) !== null ? $agreeing->requests[0] : []),
    'the cross-check is exactly the read-only agent subcommand, scoped to the target repo'
);

// ---------------------------------------------------------------------------
// --dry-run writes nothing.
// ---------------------------------------------------------------------------

if ($zipAvailable) {
    // The in-house plugin has no release and no declaration yet, so the run
    // is refused WHOLE — dry or not — and nothing is written. This is the
    // invariant at the migration door: there is no "vendor it anyway".
    $unsourcedTransport = new ClassifyFixtureTransport(inventory_body($localInventory));
    [$exit, $message] = classify_repo($unsourcedTransport, $repo, false, false, $cache);
    duo_check_same(1, $exit, 'a component that neither locks nor is declared first-party refuses the whole run');
    duo_check(!is_file($repo . '/code/duo-code.lock.json'), 'and no lock is written');
    duo_check_same(
        FORMAT_1,
        (array) json_decode((string) file_get_contents($repo . '/site.duo.json'), true)['code'],
        'and site.duo.json still declares format 1'
    );
    [$exit, $message] = classify_repo(new ClassifyFixtureTransport(inventory_body($localInventory)), $repo, true, false, $cache);
    duo_check_same(1, $exit, 'and --dry-run reports the same refusal, so a script can see it');
    [$exit, $message] = classify_repo(new ClassifyFixtureTransport(inventory_body($localInventory)), $repo, false, false, $cache, ['plugins/ghost']);
    duo_check(str_contains($message, 'which is not a component this site has'), 'a first-party declaration naming no component is refused before anything is written');

    $dryTransport = new ClassifyFixtureTransport(inventory_body($localInventory));
    [$exit, $message] = classify_repo($dryTransport, $repo, true, false, $cache, ['plugins/duo-agency']);
    duo_check_same(0, $exit, '--dry-run with the in-house plugin declared first-party succeeds');
    duo_check(!is_file($repo . '/code/duo-code.lock.json'), 'and writes no lock');
    duo_check(!is_file($repo . '/.gitignore'), 'and writes no .gitignore');
    duo_check_same(
        FORMAT_1,
        (array) json_decode((string) file_get_contents($repo . '/site.duo.json'), true)['code'],
        'and leaves site.duo.json declaring format 1'
    );

    // -----------------------------------------------------------------------
    // The migration itself.
    // -----------------------------------------------------------------------

    $transport = new ClassifyFixtureTransport(inventory_body($localInventory));
    [$exit, $message] = classify_repo($transport, $repo, false, false, $cache, ['plugins/duo-agency']);
    duo_check_same(0, $exit, 'the migration succeeds against a matching release archive plus the first-party declaration');

    $lock = CodeSourceLock::parse((string) file_get_contents($repo . '/code/duo-code.lock.json'));
    duo_check_same(
        ['plugins/woocommerce'],
        array_keys(CodeSourceLock::index($lock)),
        'only the component whose release hash-matched is locked'
    );
    duo_check_same(['plugins/duo-agency'], $lock['first_party'], 'and the in-house plugin is declared first-party in the same lock');
    duo_check_same(CodeSourceLock::FORMAT, $lock['format'], 'the lock is written in the current format');
    duo_check_same(
        '11.0.0',
        $lock['components'][0]['version'],
        'and the lock records the version the component itself declares'
    );

    duo_check(
        str_contains((string) file_get_contents($repo . '/.gitignore'), "\n/code/wp-content/plugins/woocommerce/\n"),
        'the locked component gets its root-anchored ignore line in the repository-root .gitignore'
    );
    duo_check_same(
        FORMAT_2,
        (array) json_decode((string) file_get_contents($repo . '/site.duo.json'), true)['code'],
        'site.duo.json now declares format 2 naming the lock'
    );

    $trackedNow = tracked($repo);
    duo_check(
        !in_array('code/wp-content/plugins/woocommerce/woocommerce.php', $trackedNow, true),
        'Git no longer tracks the locked component'
    );
    duo_check(
        in_array('code/wp-content/plugins/duo-agency/duo-agency.php', $trackedNow, true),
        'and still tracks the first-party one'
    );
    duo_check(
        is_file($repo . '/code/wp-content/plugins/woocommerce/woocommerce.php'),
        'the bytes are still on disk: only Git stopped tracking them'
    );

    // THE claim: the next compile produces the identical code_revision, so no
    // artifact, pin or deployed site sees anything at all.
    $afterRevision = CodeDescriptorCompiler::compile($repo, FORMAT_2)['code_revision'];
    duo_check_same($beforeRevision, $afterRevision, 'the migrated repository compiles to the IDENTICAL code_revision');

    // Re-classifying the format-2 repository is idempotent: the declaration
    // carries forward, nothing new is untracked, the lock bytes do not move.
    $lockBytesBefore = (string) file_get_contents($repo . '/code/duo-code.lock.json');
    exec('git -C ' . escapeshellarg($repo) . ' add -A && git -C ' . escapeshellarg($repo) . ' commit -q -m split --no-gpg-sign 2>&1');
    $trackedCommitted = tracked($repo);
    duo_check(
        !in_array('code/wp-content/plugins/woocommerce/woocommerce.php', $trackedCommitted, true),
        'committing the declaration does not re-add the locked tree: its ignore line holds'
    );
    [$exit, $message] = classify_repo(new ClassifyFixtureTransport(inventory_body($localInventory)), $repo, false, false, $cache);
    duo_check_same(0, $exit, 'a format-2 repository re-classifies without restating --first-party: the declaration carries forward');
    duo_check_same($lockBytesBefore, (string) file_get_contents($repo . '/code/duo-code.lock.json'), 'and the lock bytes are unchanged');
    duo_check_same($trackedCommitted, tracked($repo), 'and Git tracks exactly what it tracked before');
    [$exit, $message] = classify_repo(new ClassifyFixtureTransport(inventory_body($localInventory)), $repo, false, false, $cache, ['plugins/woocommerce']);
    duo_check(
        str_contains($message, 'plugins/woocommerce is a locked component in the current lock'),
        'a locked component cannot be flipped to first-party in the same run: Git does not carry it, and declaring it would be a lie'
    );

    // And the compile gate is now live on this repository.
    rename($repo . '/code/wp-content/plugins/woocommerce', $repo . '/moved-away');
    $diagnostics = [];
    try {
        CodeDescriptorCompiler::compile($repo, FORMAT_2);
    } catch (\Duo\CodeCompilationException $e) {
        $diagnostics = $e->diagnostics;
    }
    duo_check_same(
        ['code_component_unresolved'],
        array_values(array_unique(array_column($diagnostics, 'code'))),
        'and removing the locked bytes now refuses with code_component_unresolved instead of compiling a shrunken payload'
    );
    rename($repo . '/moved-away', $repo . '/code/wp-content/plugins/woocommerce');
} else {
    duo_check(true, 'ZipArchive is unavailable on this host; the release-verification half of this suite is reported, not skipped silently');
}

remove_tree($scratch);

duo_check_summary('regress_code_classify');
