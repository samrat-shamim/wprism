<?php
/**
 * DUO-3501 -- code-stage must stop re-writing a target that already holds the
 * descriptor's exact bytes, and must keep every refusal it had before.
 *
 * The defect this pins: CodeMaterializer::write_payload() looped the whole
 * descriptor and temp+renamed every row unconditionally, so a repeated
 * `duo deploy` of one artifact re-staged all 8,918 files of a real payload
 * with nothing to show for it. The skip is only safe if it is decided AFTER
 * the per-row refusals, which is what most of this suite asserts: each
 * refusal case below is set up so that the target already matches the
 * descriptor, i.e. exactly where a skip-first implementation would return
 * early and never look at the source at all.
 *
 * The proof that no write happened is the target's inode, not the returned
 * count: temp+rename always publishes a NEW inode, so an unchanged inode is
 * filesystem evidence that the rename did not run. The counts are asserted
 * too, because they are what the stage receipt reports.
 *
 * Ledger/Db/PromotionLock are faked in-process exactly as
 * regress_code_stage_transaction_unit.sh fakes them -- Code::stage() needs a
 * kv store and a lease, not a database -- while the filesystem half is real,
 * which is the half this issue is about.
 */
declare(strict_types=1);

namespace Duo;

$root = \dirname(__DIR__, 4);
$target = \sys_get_temp_dir() . '/duo-code-stage-skip-target-' . \bin2hex(\random_bytes(6));
$repo = \sys_get_temp_dir() . '/duo-code-stage-skip-repo-' . \bin2hex(\random_bytes(6));
\define('WP_CONTENT_DIR', $target);
\define('WP_PLUGIN_DIR', $target . '/plugins');
\define('WPMU_PLUGIN_DIR', $target . '/mu-plugins');

final class CompiledRepository {
    public function __construct(private array $descriptor, private string $artifact) {}
    public function code_descriptor(): ?array { return $this->descriptor; }
    public function artifact_hash(): string { return $this->artifact; }
}
final class CodeStateContract {
    public static function validate(CompiledRepository $compiled, array $descriptor): void {}
}
final class Ledger {
    /** @var array<string,string> */
    public static array $rows = [];
    public static function ensure(): void {}
    public static function kv_get(string $key): ?string { return self::$rows[$key] ?? null; }
    public static function kv_set(string $key, string $value): void { self::$rows[$key] = $value; }
}
final class Db {
    /** @var ?array<string,string> */
    private static ?array $snapshot = null;
    public static function start(string $context): void { self::$snapshot = Ledger::$rows; }
    public static function commit(string $context): void { self::$snapshot = null; }
    public static function rollback(string $context): void {
        Ledger::$rows = self::$snapshot ?? [];
        self::$snapshot = null;
    }
}
final class PromotionLock {
    public static function acquire(string $owner, string $artifact, string $phase, ?int $ttl, bool $continuation): array {
        return ['owner' => $owner, 'artifact_hash' => $artifact, 'phase' => $phase];
    }
    public static function assert_no_lifecycle_attempt(string $owner, string $artifact, string $context): void {}
    public static function heartbeat(string $owner, string $artifact, string $phase): void {}
    public static function release(string $owner, string $artifact): void {}
}

require_once __DIR__ . '/../../lib/check.php';
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Code/Code.php';

function skip_put(string $path, string $bytes): void {
    $dir = \dirname($path);
    if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
        throw new \RuntimeException("fixture cannot create $dir");
    }
    if (\file_put_contents($path, $bytes) === false) {
        throw new \RuntimeException("fixture cannot write $path");
    }
    \clearstatcache(true, $path);
}

function skip_rm(string $path): void {
    if (!\file_exists($path) && !\is_link($path)) {
        return;
    }
    if (\is_link($path) || \is_file($path)) {
        @\unlink($path);
        \clearstatcache(true, $path);
        return;
    }
    foreach (\scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            skip_rm($path . '/' . $child);
        }
    }
    @\rmdir($path);
    \clearstatcache(true, $path);
}

/**
 * Per-row inode of every materialized target. temp+rename publishes a fresh
 * inode for every file it writes, so this is the direct filesystem answer to
 * "did the loop write this row".
 *
 * @return array<string,int>
 */
function skip_inodes(string $target, array $descriptor): array {
    \clearstatcache(true);
    $inodes = [];
    foreach ($descriptor['files'] as $row) {
        $path = $target . '/' . $row['path'];
        $stat = \is_file($path) ? \stat($path) : false;
        $inodes[(string) $row['path']] = $stat === false ? -1 : (int) $stat['ino'];
    }
    return $inodes;
}

\mkdir($target, 0777, true);
\register_shutdown_function(static function () use ($repo, $target): void {
    skip_rm($repo);
    skip_rm($target);
});

$source = $repo . '/code/wp-content';
skip_put($source . '/plugins/fixture/fixture.php', "<?php\n/*\nPlugin Name: Skip Fixture\n*/\n");
skip_put($source . '/plugins/fixture/inc/helper.php', "<?php\n// helper\n");
skip_put($source . '/plugins/fixture/readme.txt', "skip fixture readme\n");

$descriptor = Code::descriptor_from_source($source);
$artifact = \str_repeat('e', 64);
$compiled = new CompiledRepository($descriptor, $artifact);
$paths = \array_map(static fn(array $row): string => (string) $row['path'], $descriptor['files']);
\duo_check_same(
    ['plugins/fixture/fixture.php', 'plugins/fixture/inc/helper.php', 'plugins/fixture/readme.txt'],
    $paths,
    'the fixture payload is the three rows this suite reasons about'
);

$stage = static function (string $owner) use ($repo, $compiled, $artifact): array {
    return Code::stage($repo, $compiled, [
        'artifact_hash' => $artifact,
        'promotion_owner' => $owner,
    ]);
};
// The ledger state a finalized environment is left in: the completed marker
// and descriptor, no temporary stage receipt. This is the state a SECOND
// `duo deploy` of the same artifact actually starts from.
$finalized = static function () use ($descriptor): void {
    Ledger::$rows = [
        Code::CODE_REVISION_KEY => (string) $descriptor['code_revision'],
        Code::CODE_DESCRIPTOR_KEY => Canon::encode($descriptor),
    ];
};

// --- first stage: an empty target, every row written -----------------------
Ledger::$rows = [];
$first = $stage('skip-first-stage');
\duo_check_same(true, $first['staged'], 'the first stage publishes a staged receipt');
\duo_check_same(3, $first['files'], "'files' still reports the whole descriptor inventory");
\duo_check_same(3, $first['written'], 'an empty target writes every descriptor row');
\duo_check_same(0, $first['unchanged'], 'an empty target has nothing to leave alone');
Code::assert_verified_staged($compiled);
$firstInodes = skip_inodes($target, $descriptor);
\duo_check_same(
    [],
    \array_keys(\array_filter($firstInodes, static fn(int $ino): bool => $ino === -1)),
    'every descriptor row exists on the target after the first stage'
);
\duo_check_same(
    ['plugins/fixture/fixture.php', 'plugins/fixture/inc/helper.php', 'plugins/fixture/readme.txt'],
    Canon::decode(Ledger::$rows[Code::CODE_STAGE_CREATED_PATHS_KEY]),
    'the first stage records created-path provenance for all three rows'
);

// --- re-stage under the retained staged receipt (a lifecycle retry) --------
$retry = $stage('skip-restage-retry');
\duo_check_same(0, $retry['written'], 'a re-stage of the identical payload writes nothing');
\duo_check_same(3, $retry['unchanged'], 'a re-stage of the identical payload counts every row unchanged');
\duo_check_same(3, $retry['files'], "'files' is unaffected by the write/unchanged split");
\duo_check_same($firstInodes, skip_inodes($target, $descriptor), 'no target inode moved during the re-stage');
Code::assert_verified_staged($compiled);
// Skipping the write must not disturb the created-path receipt either way:
// the retry re-publishes exactly the provenance the first stage earned (the
// three paths it proved absent), because nothing finalized in between.
\duo_check_same(
    ['plugins/fixture/fixture.php', 'plugins/fixture/inc/helper.php', 'plugins/fixture/readme.txt'],
    Canon::decode(Ledger::$rows[Code::CODE_STAGE_CREATED_PATHS_KEY]),
    'a re-stage carries forward exactly the created-path provenance it earned'
);

// --- the real second deploy: stage again from a FINALIZED environment ------
$finalized();
$second = $stage('skip-second-deploy');
\duo_check_same(0, $second['written'], 'the second deploy of one artifact writes zero files');
\duo_check_same(3, $second['unchanged'], 'the second deploy reports every row unchanged');
\duo_check_same($firstInodes, skip_inodes($target, $descriptor), 'the second deploy moved no inode');
Code::assert_verified_staged($compiled);
// ...and against a finalized environment it claims none, because every row
// was already completed code rather than a path this stage proved absent.
\duo_check_same(
    [],
    Canon::decode(Ledger::$rows[Code::CODE_STAGE_CREATED_PATHS_KEY]),
    'the second deploy claims no created-path provenance over completed code'
);

// --- one byte drifts on the target: that row, and only that row, is rewritten
$finalized();
$drifted = $target . '/plugins/fixture/inc/helper.php';
$intended = (string) \file_get_contents($source . '/plugins/fixture/inc/helper.php');
skip_put($drifted, $intended . 'X');
$repaired = $stage('skip-target-byte-drift');
\duo_check_same(1, $repaired['written'], 'a one-byte target change rewrites exactly one row');
\duo_check_same(2, $repaired['unchanged'], 'the untouched rows stay unchanged');
\duo_check_same($intended, (string) \file_get_contents($drifted), 'the drifted row is restored to the descriptor bytes');
$repairedInodes = skip_inodes($target, $descriptor);
\duo_check_same(
    ['plugins/fixture/inc/helper.php'],
    \array_keys(\array_filter(
        $repairedInodes,
        static fn(int $ino, string $path): bool => $ino !== $firstInodes[$path],
        \ARRAY_FILTER_USE_BOTH
    )),
    'only the drifted row was republished'
);
$firstInodes = $repairedInodes;
Code::assert_verified_staged($compiled);

// --- mode drift is republished too ----------------------------------------
// The write branch ends with @chmod($tmp, fileperms($src) & 0777) before its
// rename (agent/src/Code/CodeMaterializer.php), so a content-only skip would
// silently stop converging a bit the write converges. The skip therefore
// requires both, which keeps it observationally identical to the write.
$finalized();
$moded = $target . '/plugins/fixture/readme.txt';
$sourceMode = \fileperms($source . '/plugins/fixture/readme.txt') & 0777;
\chmod($moded, 0600);
\clearstatcache(true, $moded);
\duo_check_same(true, (\fileperms($moded) & 0777) !== $sourceMode, 'the fixture really diverged the target mode');
$remoded = $stage('skip-target-mode-drift');
\clearstatcache(true, $moded);
\duo_check_same(1, $remoded['written'], 'a mode-divergent target row is rewritten');
\duo_check_same(2, $remoded['unchanged'], 'mode drift on one row leaves the others alone');
\duo_check_same($sourceMode, \fileperms($moded) & 0777, 'the rewritten row converges back on the source mode');
$firstInodes = skip_inodes($target, $descriptor);

// --- every prior refusal survives, decided BEFORE the skip -----------------
// Each case leaves the target byte-identical to the descriptor, so a skip
// decided before these checks would swallow the refusal entirely.
$row = 'plugins/fixture/fixture.php';
$sourceFile = $source . '/' . $row;
$sourceBytes = (string) \file_get_contents($sourceFile);
$targetFile = $target . '/' . $row;
\duo_check_same(
    \hash_file('sha256', $sourceFile),
    \hash_file('sha256', $targetFile),
    'the refusal cases start from a target that already matches the descriptor'
);

\duo_check_throws(
    static function () use ($repo, $descriptor, $sourceFile, $sourceBytes): void {
        skip_put($sourceFile, $sourceBytes . '// drifted after compile');
        try {
            CodeMaterializer::write_payload($repo, $descriptor);
        } finally {
            skip_put($sourceFile, $sourceBytes);
        }
    },
    \RuntimeException::class,
    'a source that changed after compile still refuses even when the target matches',
    "duo: code-stage source hash changed for '$row'"
);

\duo_check_throws(
    static function () use ($repo, $descriptor, $sourceFile, $sourceBytes): void {
        skip_rm($sourceFile);
        try {
            CodeMaterializer::write_payload($repo, $descriptor);
        } finally {
            skip_put($sourceFile, $sourceBytes);
        }
    },
    \RuntimeException::class,
    'a source that disappeared still refuses even when the target matches',
    "duo: code-stage source file disappeared or became a symlink '$row'"
);

\duo_check_throws(
    static function () use ($repo, $descriptor, $sourceFile, $sourceBytes, $target): void {
        $decoy = $target . '/.skip-source-decoy';
        skip_put($decoy, $sourceBytes);
        skip_rm($sourceFile);
        \symlink($decoy, $sourceFile);
        try {
            CodeMaterializer::write_payload($repo, $descriptor);
        } finally {
            skip_rm($sourceFile);
            skip_rm($decoy);
            skip_put($sourceFile, $sourceBytes);
        }
    },
    \RuntimeException::class,
    'a source replaced by a symlink to identical bytes still refuses',
    "duo: code-stage source file disappeared or became a symlink '$row'"
);

\duo_check_throws(
    static function () use ($repo, $descriptor, $targetFile, $sourceBytes, $target): void {
        $decoy = $target . '/.skip-target-decoy';
        skip_put($decoy, $sourceBytes);
        skip_rm($targetFile);
        \symlink($decoy, $targetFile);
        try {
            CodeMaterializer::write_payload($repo, $descriptor);
        } finally {
            skip_rm($targetFile);
            skip_rm($decoy);
            skip_put($targetFile, $sourceBytes);
        }
    },
    \RuntimeException::class,
    'a symlinked target holding identical bytes still refuses',
    "duo: code-stage target path is not a regular file '$row'"
);

\duo_check_throws(
    static function () use ($repo, $descriptor, $targetFile, $sourceBytes): void {
        skip_rm($targetFile);
        \mkdir($targetFile, 0777, true);
        try {
            CodeMaterializer::write_payload($repo, $descriptor);
        } finally {
            skip_rm($targetFile);
            skip_put($targetFile, $sourceBytes);
        }
    },
    \RuntimeException::class,
    'a non-regular target still refuses',
    "duo: code-stage target path is not a regular file '$row'"
);

// The same two target refusals also fire through the product stage path,
// where assert_payload_targets() reaches them first.
$finalized();
$decoy = $target . '/.skip-stage-decoy';
skip_put($decoy, $sourceBytes);
skip_rm($targetFile);
\symlink($decoy, $targetFile);
\duo_check_throws(
    static fn() => $stage('skip-symlinked-target'),
    \RuntimeException::class,
    'Code::stage refuses a symlinked target before it materializes anything',
    'code-stage preflight'
);
skip_rm($targetFile);
skip_rm($decoy);
skip_put($targetFile, $sourceBytes);

// --- the target is whole again, and a clean re-stage still writes nothing --
$finalized();
$closing = $stage('skip-closing-restage');
\duo_check_same(0, $closing['written'], 'the repaired target re-stages with zero writes');
\duo_check_same(3, $closing['unchanged'], 'the repaired target re-stages with every row unchanged');
Code::assert_verified_staged($compiled);

// --- the receipt an operator actually reads --------------------------------
// AGENTS.md rule 8 pins the existing WP-CLI success line's bytes, so the new
// counts had to arrive as an additional line rather than as an edit. Both
// facts are asserted against the source because Cli.php's receipt cannot be
// executed without a full WordPress/WP-CLI process.
$cli = (string) \file_get_contents($root . '/agent/src/Command/Cli.php');
\duo_check_same(
    true,
    \str_contains($cli, "'staged code revision %s (%d file(s)%s); promotion lease retained for finalize'"),
    'the pre-existing code-stage success line is byte-identical'
);
\duo_check_same(
    true,
    \str_contains($cli, "'code payload: %d written, %d unchanged of %d file(s)'"),
    'the stage receipt reports the write/unchanged split on its own line'
);

\duo_check_summary('code-stage unchanged-payload skip (DUO-3501)');
