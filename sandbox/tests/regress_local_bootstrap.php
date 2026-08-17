<?php
declare(strict_types=1);

/**
 * Offline product-path regression for the privileged local adoption boundary.
 *
 * A fake `wp` executable supplies only the bounded WordPress probes while the
 * real LocalTransport, BootstrapEligibilityReport, Adopt transaction, and
 * rollback runtime operate on disposable local directories.
 */

require dirname(__DIR__, 2) . '/cli/src/Transport/EnvironmentDriver.php';
require dirname(__DIR__, 2) . '/cli/src/Transport/Transport.php';
require dirname(__DIR__, 2) . '/cli/src/Transport/LocalTransport.php';
require dirname(__DIR__, 2) . '/recovery/rollback-control.php';
require dirname(__DIR__, 2) . '/cli/src/Onboarding/BootstrapEligibility.php';
require dirname(__DIR__, 2) . '/cli/src/Transport/CodeDeploy.php';
require dirname(__DIR__, 2) . '/cli/src/Onboarding/Adopt.php';

use Duo\Orchestrator\AdoptionTransport;
use Duo\Orchestrator\Adopt;
use Duo\Orchestrator\BootstrapEligibilityReport;
use Duo\Orchestrator\LocalTransport;

function local_bootstrap_ok(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok: $message\n";
}

function local_bootstrap_remove(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                local_bootstrap_remove($path . '/' . $item);
            }
        }
    }
    @rmdir($path);
}

function local_bootstrap_tree_hash(string $path): string {
    if (!file_exists($path) && !is_link($path)) {
        return hash('sha256', 'absent');
    }
    $root = rtrim($path, '/');
    $rows = [];
    $walk = static function (string $candidate, string $relative) use (&$walk, &$rows): void {
        $stat = lstat($candidate);
        if (!is_array($stat)) {
            throw new RuntimeException('could not stat regression tree');
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0040000) {
            $rows[] = ['dir', $relative, $stat['mode'] & 0777];
            $children = scandir($candidate);
            if (!is_array($children)) {
                throw new RuntimeException('could not scan regression tree');
            }
            sort($children, SORT_STRING);
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $walk($candidate . '/' . $child, $relative === '' ? $child : $relative . '/' . $child);
                }
            }
            return;
        }
        if ($type === 0100000) {
            $bytes = file_get_contents($candidate);
            if (!is_string($bytes)) {
                throw new RuntimeException('could not read regression tree file');
            }
            $rows[] = ['file', $relative, $stat['mode'] & 0777, strlen($bytes), hash('sha256', $bytes)];
            return;
        }
        $rows[] = ['special', $relative, $type];
    };
    $walk($root, '');
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

final class LocalBootstrapUploadCollisionTransport implements AdoptionTransport {
    /** @var list<string> */
    public array $raw = [];
    public ?string $foreign = null;

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture', 'remediation' => ''];
    }
    public function repoPath(): string { return '/fixture/repo'; }
    public function wpPath(): string { return '/fixture/wp'; }
    public function captureRaw(string $script): array {
        $this->raw[] = $script;
        if ($script === 'echo duo-reachable') {
            return ['exit' => 0, 'stdout' => "duo-reachable\n", 'stderr' => ''];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        if ($wpArgs === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($wpArgs === ['eval', 'echo WPMU_PLUGIN_DIR;']) {
            return ['exit' => 0, 'stdout' => "/fixture/mu\n", 'stderr' => ''];
        }
        return ['exit' => 90, 'stdout' => '', 'stderr' => 'unexpected wp call'];
    }
    public function uploadFile(string $localPath, string $remotePath): array {
        $this->foreign = $remotePath;
        file_put_contents($remotePath, "foreign-collision\n", LOCK_EX);
        return ['exit' => 67, 'stdout' => '', 'stderr' => 'fixture upload collision'];
    }
}

$physicalTemp = realpath(sys_get_temp_dir());
if (!is_string($physicalTemp) || $physicalTemp === '' || $physicalTemp === DIRECTORY_SEPARATOR) {
    throw new RuntimeException('could not resolve the regression temporary directory');
}
$root = rtrim($physicalTemp, DIRECTORY_SEPARATOR) . '/duo-local-bootstrap-' . bin2hex(random_bytes(8));
$bin = $root . '/bin';
$wpRoot = $root . '/wordpress';
$content = $wpRoot . '/wp-content';
$mu = $content . '/mu-plugins';
$repo = $root . '/site-repo';
$source = dirname(__DIR__, 2);
$oldPath = (string) getenv('PATH');

try {
    mkdir($bin, 0700, true);
    mkdir($content, 0700, true);
    $versionSource = file_get_contents($source . '/agent/duo.php');
    if (!is_string($versionSource)
        || preg_match("/define\\(\\s*'DUO_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $versionSource, $match) !== 1) {
        throw new RuntimeException('could not resolve fixture agent version');
    }
    $wpScript = <<<'SH'
#!/bin/sh
set -eu
: "${DUO_LOCAL_BOOTSTRAP_MU:?}"
: "${DUO_LOCAL_BOOTSTRAP_VERSION:?}"
printf '%s\n' "$*" >> "${DUO_LOCAL_BOOTSTRAP_LOG:?}"
case " $* " in
  *" core is-installed "*) exit 0 ;;
  *DUO_BOOTSTRAP_WPMU_PLUGIN_DIR*) printf '%s' "$DUO_LOCAL_BOOTSTRAP_MU"; exit 0 ;;
  *DUO_AGENT_VERSION*) printf '%s' "$DUO_LOCAL_BOOTSTRAP_VERSION"; exit 0 ;;
  *duo-policy-ok*) printf '%s' 'duo-policy-ok'; exit 0 ;;
esac
printf '%s\n' 'unexpected fake wp invocation' >&2
exit 91
SH;
    file_put_contents($bin . '/wp', $wpScript, LOCK_EX);
    chmod($bin . '/wp', 0700);
    file_put_contents($root . '/wp.log', '', LOCK_EX);
    putenv('PATH=' . $bin . ':' . $oldPath);
    putenv('DUO_LOCAL_BOOTSTRAP_MU=' . $mu);
    putenv('DUO_LOCAL_BOOTSTRAP_VERSION=' . $match[1]);
    putenv('DUO_LOCAL_BOOTSTRAP_LOG=' . $root . '/wp.log');

    $plain = new LocalTransport('plain', [
        'transport' => 'local',
        'wp_path' => $wpRoot,
        'repo_path' => $repo,
        '_machine_local' => true,
    ]);
    $plainReport = $plain->capabilityReport('adopt');
    local_bootstrap_ok(!$plainReport->ready(), 'local adoption is unsupported without an explicit bootstrap opt-in');
    local_bootstrap_ok(
        str_contains((string) ($plainReport->blockers()[0]['remediation'] ?? ''), '.duo-envs.json'),
        'the static refusal gives actionable machine-local remediation'
    );
    local_bootstrap_ok(trim((string) file_get_contents($root . '/wp.log')) === '', 'static capability negotiation never contacts the target');

    $checkedIn = new LocalTransport('checked', [
        'transport' => 'local',
        'wp_path' => $wpRoot,
        'repo_path' => $repo,
        'bootstrap' => ['format' => LocalTransport::BOOTSTRAP_FORMAT],
        '_machine_local' => false,
    ]);
    local_bootstrap_ok(!$checkedIn->capabilityReport('adopt')->ready(), 'checked-in config cannot self-authorize bootstrap');

    foreach ([null, [], ['format' => LocalTransport::BOOTSTRAP_FORMAT, 'extra' => true]] as $badBootstrap) {
        $threw = false;
        try {
            new LocalTransport('bad', [
                'transport' => 'local',
                'wp_path' => $wpRoot,
                'repo_path' => $repo,
                'bootstrap' => $badBootstrap,
                '_machine_local' => true,
            ]);
        } catch (Throwable) {
            $threw = true;
        }
        local_bootstrap_ok($threw, 'a present malformed local bootstrap opt-in refuses at construction');
    }

    $transport = new LocalTransport('fixture', [
        'transport' => 'local',
        'wp_path' => $wpRoot,
        'repo_path' => $repo,
        'bootstrap' => ['format' => LocalTransport::BOOTSTRAP_FORMAT],
        '_machine_local' => true,
    ]);
    local_bootstrap_ok($transport->capabilityReport('adopt')->ready(), 'the exact machine-local opt-in advertises the bootstrap mechanism');
    $uploaderSource = file_get_contents($source . '/cli/src/Transport/LocalTransport.php');
    $openedDestinationIdentity = is_string($uploaderSource)
        ? strpos($uploaderSource, '$openedDestinationStat = fstat($destination);')
        : false;
    $flushBeforePostWrite = is_string($uploaderSource)
        ? strpos($uploaderSource, 'if (!fflush($destination))')
        : false;
    $postWriteIdentity = is_string($uploaderSource)
        ? strpos($uploaderSource, '$postWriteDestinationStat = fstat($destination);')
        : false;
    $closedBeforeFinalPath = is_string($uploaderSource)
        ? strpos($uploaderSource, '$destinationClosed = fclose($destination);')
        : false;
    $finalPathIdentity = is_string($uploaderSource)
        ? strpos($uploaderSource, '$finalDestinationStat = @lstat($remotePath);')
        : false;
    $finalPathReadback = is_string($uploaderSource)
        ? strpos($uploaderSource, '@hash_file(\'sha256\', $remotePath)')
        : false;
    local_bootstrap_ok(
        is_int($openedDestinationIdentity)
            && is_int($flushBeforePostWrite)
            && is_int($postWriteIdentity)
            && is_int($closedBeforeFinalPath)
            && is_int($finalPathIdentity)
            && is_int($finalPathReadback)
            && $openedDestinationIdentity < $flushBeforePostWrite
            && $flushBeforePostWrite < $postWriteIdentity
            && $postWriteIdentity < $closedBeforeFinalPath
            && $closedBeforeFinalPath < $finalPathIdentity
            && $finalPathIdentity < $finalPathReadback,
        'the local uploader keeps opened-handle proof, then binds its token only to a post-close final path readback'
    );

    $temporaryTarget = $root . '/temporary-target';
    $temporaryLink = $root . '/temporary-link';
    $temporaryFile = $root . '/temporary-file';
    mkdir($temporaryTarget, 0700);
    symlink($temporaryTarget, $temporaryLink);
    file_put_contents($temporaryFile, "not-a-directory\n", LOCK_EX);
    $temporaryCheck = new ReflectionMethod(BootstrapEligibilityReport::class, 'temporaryPathCheckScript');
    $linkedTemporary = $transport->captureRaw(
        (string) $temporaryCheck->invoke(null, $temporaryLink) . "echo safe\n"
    );
    local_bootstrap_ok(
        $linkedTemporary['exit'] === 0 && trim($linkedTemporary['stdout']) === 'safe',
        'a linked temporary alias resolving to an ordinary writable directory is eligible'
    );
    unlink($temporaryLink);
    symlink($temporaryFile, $temporaryLink);
    $nonDirectoryTemporary = $transport->captureRaw(
        (string) $temporaryCheck->invoke(null, $temporaryLink) . "echo safe\n"
    );
    local_bootstrap_ok(
        $nonDirectoryTemporary['exit'] === 0
            && trim($nonDirectoryTemporary['stdout']) === 'temporary_path_unsafe',
        'a linked temporary alias resolving to a non-directory remains blocked'
    );
    unlink($temporaryLink);

    $eligibility = BootstrapEligibilityReport::inspect($transport, 'fixture', 'local', $source);
    if (!$eligibility->ready()) {
        fwrite(STDERR, json_encode($eligibility->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    local_bootstrap_ok($eligibility->ready(), 'a fresh ordinary local WordPress layout is read-only eligible');
    local_bootstrap_ok(!file_exists($mu) && !file_exists($repo), 'eligibility creates neither the control-plane nor repository leaf');

    $verifiedInsideTransaction = false;
    $result = Adopt::install(
        $transport,
        $source,
        null,
        null,
        null,
        $eligibility,
        static function () use (&$verifiedInsideTransaction, $mu, $repo): bool {
            $verifiedInsideTransaction = is_file($mu . '/duo/duo.php')
                && is_file($mu . '/duo-loader.php')
                && is_file($mu . '/manifests/core.json')
                && is_file($repo . '/site.duo.json')
                && is_file($repo . '/.duo/control/target.json')
                && is_dir($repo . '/.duo/rollback');
            return $verifiedInsideTransaction;
        }
    );
    if ($result['exit'] !== 0 || !$verifiedInsideTransaction) {
        fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    local_bootstrap_ok($result['exit'] === 0 && $verifiedInsideTransaction, 'local adoption stages, swaps, and verifies before commit');
    local_bootstrap_ok(($result['repo_created'] ?? false) === true, 'fresh local adoption creates the minimal seed repository');
    $wpLog = (string) file_get_contents($root . '/wp.log');
    local_bootstrap_ok(
        str_contains($wpLog, 'DUO_BOOTSTRAP_WPMU_PLUGIN_DIR')
            && str_contains($wpLog, 'DUO_CONTROL_PLANE'),
        'eligibility and post-swap verification both use isolated plugin-free WordPress bootstraps'
    );
    local_bootstrap_ok(
        (\Duo\Recovery\RollbackControl::inspectReadOnly($repo . '/.duo/control')['quiescent'] ?? false) === true,
        'the committed bootstrap has a complete read-only-verifiable rollback authority'
    );
    local_bootstrap_ok(glob($mu . '/.duo-adopt-*') === [], 'successful commit leaves no adoption lock or transaction path');

    $beforeRepeat = [local_bootstrap_tree_hash($mu), local_bootstrap_tree_hash($repo)];
    $secondEligibility = BootstrapEligibilityReport::inspect($transport, 'fixture', 'local', $source);
    local_bootstrap_ok(!$secondEligibility->ready(), 'local bootstrap refuses a pre-existing Duo control plane');
    local_bootstrap_ok(
        str_contains((string) ($secondEligibility->blockers()[0]['remediation'] ?? ''), 'existing environment update path'),
        'the repeated-local-adopt refusal points to the installed-target update path'
    );
    $repeated = Adopt::install($transport, $source, null, null, null, $secondEligibility, static fn(): bool => true);
    local_bootstrap_ok(
        $repeated['exit'] !== 0 && $repeated['phase'] === 'target eligibility',
        'a blocked repeated-local eligibility report cannot authorize staging'
    );
    local_bootstrap_ok(
        [local_bootstrap_tree_hash($mu), local_bootstrap_tree_hash($repo)] === $beforeRepeat,
        'the repeated-local-adopt refusal changes no target bytes'
    );

    $doctorContent = $root . '/doctor-content';
    $doctorMu = $doctorContent . '/mu-plugins';
    $doctorRepo = $root . '/doctor-repo';
    mkdir($doctorContent, 0700, true);
    putenv('DUO_LOCAL_BOOTSTRAP_MU=' . $doctorMu);
    $doctorTransport = new LocalTransport('doctor-red', [
        'transport' => 'local',
        'wp_path' => $wpRoot,
        'repo_path' => $doctorRepo,
        'bootstrap' => ['format' => LocalTransport::BOOTSTRAP_FORMAT],
        '_machine_local' => true,
    ]);
    $doctorEligibility = BootstrapEligibilityReport::inspect($doctorTransport, 'doctor-red', 'local', $source);
    local_bootstrap_ok($doctorEligibility->ready(), 'a second fresh target is eligible for the transactional-doctor refusal tooth');
    $beforeFailure = [local_bootstrap_tree_hash($doctorMu), local_bootstrap_tree_hash($doctorRepo)];
    $failed = Adopt::install(
        $doctorTransport,
        $source,
        null,
        null,
        null,
        $doctorEligibility,
        static fn(): bool => false
    );
    local_bootstrap_ok($failed['exit'] !== 0 && $failed['phase'] === 'doctor verification', 'a red transactional doctor refuses adoption');
    $afterFailure = [local_bootstrap_tree_hash($doctorMu), local_bootstrap_tree_hash($doctorRepo)];
    local_bootstrap_ok($afterFailure === $beforeFailure, 'doctor failure restores the absent control-plane and repository leaves exactly');
    local_bootstrap_ok(glob($doctorContent . '/.duo-adopt-*') === [], 'doctor rollback leaves no lock or transaction residue');
    local_bootstrap_ok(glob($root . '/.duo-old-*') === [], 'doctor rollback leaves no staged repository authority');
    putenv('DUO_LOCAL_BOOTSTRAP_MU=' . $mu);

    $badRepo = $root . '/bad-repo';
    $badMu = $root . '/bad-mu';
    mkdir($badRepo . '/.duo/rollback', 0700, true);
    mkdir($badMu, 0700, true);
    putenv('DUO_LOCAL_BOOTSTRAP_MU=' . $badMu);
    $badTransport = new LocalTransport('bad-authority', [
        'transport' => 'local',
        'wp_path' => $wpRoot,
        'repo_path' => $badRepo,
        'bootstrap' => ['format' => LocalTransport::BOOTSTRAP_FORMAT],
        '_machine_local' => true,
    ]);
    $badBefore = local_bootstrap_tree_hash($badRepo);
    $badEligibility = BootstrapEligibilityReport::inspect($badTransport, 'bad-authority', 'local', $source);
    local_bootstrap_ok(!$badEligibility->ready(), 'an incomplete prior .duo authority refuses re-adoption');
    local_bootstrap_ok(local_bootstrap_tree_hash($badRepo) === $badBefore, 'the incomplete-authority refusal performs no target write');

    $uploadSource = $root . '/upload-source';
    $uploadDestination = '/tmp/duo-adopt-' . str_repeat('a', 24) . '.tar';
    file_put_contents($uploadSource, "source\n", LOCK_EX);
    file_put_contents($uploadDestination, "foreign\n", LOCK_EX);
    $collision = $transport->uploadFile($uploadSource, $uploadDestination);
    local_bootstrap_ok($collision['exit'] !== 0, 'the local uploader refuses a pre-existing archive destination');
    local_bootstrap_ok(file_get_contents($uploadDestination) === "foreign\n", 'the local uploader never changes a colliding archive');
    unlink($uploadDestination);

    $ownedDestination = '/tmp/duo-adopt-' . str_repeat('b', 24) . '.tar';
    $ownedUpload = $transport->uploadFile($uploadSource, $ownedDestination);
    local_bootstrap_ok($ownedUpload['exit'] === 0, 'a fresh local archive is created exclusively');
    $ownedIdentity = $transport->uploadedFileIdentity($ownedUpload);
    $ownedFinalStat = lstat($ownedDestination);
    $ownedFinalIdentity = is_array($ownedFinalStat)
        ? (string) ((int) $ownedFinalStat['dev']) . ':' . (string) ((int) $ownedFinalStat['ino'])
            . ':' . (string) (((int) $ownedFinalStat['mode']) & 0170000)
        : '';
    local_bootstrap_ok(
        $ownedIdentity === $ownedFinalIdentity,
        'the local uploader returns the completed path identity used by later archive cleanup'
    );
    $foreignReplacement = $root . '/foreign-archive-replacement';
    file_put_contents($foreignReplacement, "foreign-replacement\n", LOCK_EX);
    rename($foreignReplacement, $ownedDestination);
    $ambiguousCleanup = $transport->cleanupUploadedFile($ownedDestination, $ownedIdentity);
    local_bootstrap_ok($ambiguousCleanup['exit'] !== 0, 'archive cleanup refuses a replacement identity');
    local_bootstrap_ok(
        file_get_contents($ownedDestination) === "foreign-replacement\n",
        'identity-bound archive cleanup retains a foreign replacement'
    );
    unlink($ownedDestination);

    $cleanDestination = '/tmp/duo-adopt-' . str_repeat('c', 24) . '.tar';
    $cleanUpload = $transport->uploadFile($uploadSource, $cleanDestination);
    $cleanIdentity = $transport->uploadedFileIdentity($cleanUpload);
    local_bootstrap_ok(
        $transport->cleanupUploadedFile($cleanDestination, $cleanIdentity)['exit'] === 0
            && !file_exists($cleanDestination),
        'identity-bound archive cleanup removes only the file the uploader created'
    );

    putenv('DUO_LOCAL_BOOTSTRAP_MU=' . $mu);
    $collisionTransport = new LocalBootstrapUploadCollisionTransport();
    $collisionResult = Adopt::install($collisionTransport, $source);
    local_bootstrap_ok($collisionResult['exit'] !== 0 && $collisionResult['phase'] === 'archive upload', 'an upload collision refuses before remote install');
    local_bootstrap_ok(
        $collisionTransport->foreign !== null
            && file_get_contents($collisionTransport->foreign) === "foreign-collision\n",
        'Adopt does not delete an archive path it did not create'
    );
    local_bootstrap_ok(
        count(array_filter($collisionTransport->raw, static fn(string $script): bool => str_starts_with($script, 'rm -f '))) === 0,
        'the collision path never invokes remote archive cleanup'
    );
    if ($collisionTransport->foreign !== null) {
        @unlink($collisionTransport->foreign);
    }
} finally {
    putenv('PATH=' . $oldPath);
    putenv('DUO_LOCAL_BOOTSTRAP_MU');
    putenv('DUO_LOCAL_BOOTSTRAP_VERSION');
    putenv('DUO_LOCAL_BOOTSTRAP_LOG');
    local_bootstrap_remove($root);
}

echo "REGRESS_LOCAL_BOOTSTRAP PASSED\n";
