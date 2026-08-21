<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LinuxHostBoundaryDeploymentTest extends TestCase {
    public function testInstalledClientClosureRegistriesMatchTheShippedCanonicalConfigs(): void {
        require_once DUO_REPO_ROOT . '/cloud/runtime/FirewallClientConfig.php';
        $registry = new \ReflectionMethod(
            \Duo\Cloud\FirewallClientConfig::class,
            'installedAuthorityClosure'
        );
        foreach (['firewall', 'storage'] as $authority) {
            $document = json_decode(
                (string) file_get_contents(
                    DUO_REPO_ROOT . "/cloud/deploy/$authority-client.json.example"
                ),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            self::assertSame(
                array_column($document['closure'], 'path'),
                $registry->invoke(null, $document['authority']['path'])
            );
        }
    }

    public function testCrashRecoveryIdentitiesAreDeterministicPerConfiguration(): void {
        $configuration = str_repeat('a', 64);
        $rotation = hash(
            'sha256',
            "duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0"
                . $configuration
        );
        $first = $this->cleanupPlan($configuration, $rotation);
        self::assertSame($first, $this->cleanupPlan($configuration, $rotation));
        self::assertNotSame(
            $first,
            $this->cleanupPlan(str_repeat('b', 64), $rotation)
        );
        self::assertCount(3, $first['containers']);
        self::assertCount(2, $first['firewalls']);
        self::assertCount(2, $first['networks']);
        self::assertCount(2, $first['volumes']);
        self::assertMatchesRegularExpression(
            '#\A/run/duo-cloud-host-proof/work-[a-f0-9]{32}\z#D',
            $first['scratch']
        );

        $verifier = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php'
        );
        self::assertStringNotContainsString('random_bytes(', $verifier);
        self::assertStringContainsString(
            "'duo.cloud.host-boundary-proof=' . \$configuration",
            $verifier
        );
        self::assertStringContainsString('reconcileHostProofScratch($root)', $verifier);
        self::assertStringContainsString("\$recovery,\n        true", $verifier);
    }

    public function testStartupReconcilesAFirstNetworkAndBindingAfterProcessDeath(): void {
        $configuration = str_repeat('c', 64);
        $rotation = hash(
            'sha256',
            "duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0"
                . $configuration
        );
        $verifier = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $program = <<<'PHP'
define('DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY', true);
require __VERIFIER__;
final class HostProofRecoveryRunner implements \Duo\Cloud\ContainerArgvProcessRunner {
    private array $resources;
    public array $calls = [];
    public function __construct(
        private string $configuration,
        string $network,
        private bool $busy = false
    ) {
        $this->resources = ['container' => [], 'network' => [$network => true], 'volume' => []];
    }
    public function run(array $argv, ?string $stdinFile = null, ?int $timeoutSeconds = null): array {
        $this->calls[] = $argv;
        if ($stdinFile !== null) return self::result(64, '', "unexpected stdin\n");
        if (($argv[0] ?? null) === '/firewall' && ($argv[1] ?? null) === 'unbind') {
            if ($this->busy) {
                return self::result(
                    \Duo\Cloud\HostAuthorityBusy::EXIT_STATUS,
                    '',
                    \Duo\Cloud\HostAuthorityBusy::FIREWALL_CLIENT_STDERR
                );
            }
            return self::canonical(['state' => 'absent']);
        }
        if (($argv[0] ?? null) === '/storage' && ($argv[1] ?? null) === 'proof-unbind') {
            return self::canonical(['state' => 'absent']);
        }
        if (($argv[0] ?? null) !== '/engine') return self::result(64, '', "bad executable\n");
        $kind = $argv[1] ?? null;
        $action = $argv[2] ?? null;
        if (!is_string($kind) || !isset($this->resources[$kind]) || !is_string($action)) {
            return self::result(64, '', "bad Docker grammar\n");
        }
        if ($action === 'ls') {
            $filterIndex = array_search('--filter', $argv, true);
            $filter = is_int($filterIndex) ? ($argv[$filterIndex + 1] ?? null) : null;
            foreach (array_keys($this->resources[$kind]) as $name) {
                $expected = 'name=^' . ($kind === 'container' ? '/' : '') . $name . '$';
                if ($filter === $expected) return self::result(0, $name . "\n");
            }
            return self::result(0, '');
        }
        $name = end($argv);
        if (!is_string($name) || !isset($this->resources[$kind][$name])) {
            return self::result(64, '', "unknown Docker resource\n");
        }
        if ($action === 'inspect') {
            $labels = ['duo.cloud.host-boundary-proof' => $this->configuration];
            $document = $kind === 'container'
                ? ['Config' => ['Labels' => $labels], 'Name' => '/' . $name]
                : ['Labels' => $labels, 'Name' => $name];
            return self::result(0, json_encode($document, JSON_THROW_ON_ERROR) . "\n");
        }
        if ($action === 'rm') {
            unset($this->resources[$kind][$name]);
            return self::result(0, $name . "\n");
        }
        return self::result(64, '', "bad Docker action\n");
    }
    public function remaining(): array {
        return array_map(static fn (array $rows): array => array_keys($rows), $this->resources);
    }
    private static function canonical(array $document): array {
        return self::result(0, \Duo\Cloud\CanonicalJson::encode($document) . "\n");
    }
    private static function result(int $exit, string $stdout, string $stderr = ''): array {
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }
}
$configuration = __CONFIGURATION__;
$rotation = __ROTATION__;
$plan = hostProofCleanupPlan($configuration, $rotation);
$runner = new HostProofRecoveryRunner($configuration, $plan['networks'][0]['network_name']);
cleanup($runner, $configuration, '/engine', '/firewall', '/storage', $plan, true);
cleanup($runner, $configuration, '/engine', '/firewall', '/storage', $plan, true);
$scratch = sys_get_temp_dir() . '/duo-host-proof-cleanup-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$busyRunner = new HostProofRecoveryRunner(
    $configuration,
    $plan['networks'][0]['network_name'],
    true
);
$transient = false;
try {
    finalizeHostProofCleanup(
        $busyRunner,
        $configuration,
        '/engine',
        '/firewall',
        '/storage',
        $plan,
        $scratch
    );
} catch (LinuxHostProofTransientBusy) {
    $transient = true;
}
$firewallUnbinds = array_filter($runner->calls, static fn (array $argv): bool =>
    ($argv[0] ?? null) === '/firewall' && ($argv[1] ?? null) === 'unbind');
$networkRemovals = array_filter($runner->calls, static fn (array $argv): bool =>
    ($argv[0] ?? null) === '/engine' && ($argv[1] ?? null) === 'network'
        && ($argv[2] ?? null) === 'rm');
echo json_encode([
    'firewall_unbinds' => count($firewallUnbinds),
    'network_removals' => count($networkRemovals),
    'remaining' => $runner->remaining(),
    'transient_cleanup' => $transient,
    'transient_scratch_absent' => !file_exists($scratch),
], JSON_THROW_ON_ERROR);
PHP;
        $program = str_replace(
            ['__VERIFIER__', '__CONFIGURATION__', '__ROTATION__'],
            [
                var_export($verifier, true),
                var_export($configuration, true),
                var_export($rotation, true),
            ],
            $program
        );
        $result = $this->phpProgram($program);

        self::assertSame(4, $result['firewall_unbinds']);
        self::assertSame(1, $result['network_removals']);
        self::assertTrue($result['transient_cleanup']);
        self::assertTrue($result['transient_scratch_absent']);
        self::assertSame(
            ['container' => [], 'network' => [], 'volume' => []],
            $result['remaining']
        );
    }

    /** @return array<string,mixed> */
    private function cleanupPlan(string $configuration, string $rotation): array {
        $verifier = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $program = 'define("DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY", true); require '
            . var_export($verifier, true) . '; echo json_encode(hostProofCleanupPlan('
            . var_export($configuration, true) . ', ' . var_export($rotation, true)
            . '), JSON_THROW_ON_ERROR);';
        return $this->phpProgram($program);
    }

    /** @return array<string,mixed> */
    private function phpProgram(string $program): array {
        $process = proc_open(
            [PHP_BINARY, '-r', $program],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);
        self::assertIsString($stdout);
        $plan = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        return $plan;
    }

    public function testProofIdentityComesFromOnePinnedProductionConfiguration(): void {
        $path = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $verifier = (string) file_get_contents($path);

        self::assertTrue(is_executable($path));
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php\n", $verifier);
        self::assertStringContainsString(
            'ProductionConfig::inspectForFleet($configurationFile, $configuration)',
            $verifier
        );
        foreach ([
            "\$runtime['container_engine'] ?? null",
            "\$runtime['process_launcher'] ?? null",
            "\$runtime['firewall_authority'] ?? null",
            "\$runtime['storage_authority'] ?? null",
            "\$runtime['seccomp_profile_file'] ?? null",
            "\$runtime['seccomp_profile_sha256'] ?? null",
            "\$runtime['image'] ?? null",
            '$productionConfig->workerRoot()',
        ] as $derivedIdentity) {
            self::assertStringContainsString($derivedIdentity, $verifier);
        }
        foreach ([
            '$engineIdentity = pinnedRuntimeFile(',
            '$processLauncherIdentity = pinnedRuntimeFile(',
            '$firewallClientIdentity = pinnedRuntimeFile(',
            '$storageClientIdentity = pinnedRuntimeFile(',
            '$seccompIdentity = pinnedRuntimeFile(',
            'ProductionDeploymentProofs::linuxHostInstalledConfigurationSnapshot(',
            'ProductionDeploymentProofs::assertLinuxHostInstalledConfigurationSnapshotCurrent(',
            '$activePhpBinary !== $processLauncher',
            '!sameFile($before, $opened)',
            '!sameFile($before, $finished)',
            '!sameFile($before, $after)',
            '!hash_equals($expectedSha256, $actualSha256)',
            'revalidateHostProofFile($identity, $label, $limit, $executable)',
            "'php_closure_sha256' => \$linuxHostPhpClosureSha256",
        ] as $pinnedReadback) {
            self::assertStringContainsString($pinnedReadback, $verifier);
        }
        foreach ([
            '--image',
            '--seccomp-profile',
            '--seccomp-profile-sha256',
            '--storage-root-path',
            '--secondary-configuration-sha256',
        ] as $removedFreeIdentity) {
            self::assertStringNotContainsString("'$removedFreeIdentity'", $verifier);
        }
        self::assertStringContainsString("'image_reference' => \$imageReference", $verifier);
        self::assertStringContainsString("'image_id' => \$imageId", $verifier);
        self::assertStringContainsString("'storage_worker_root' => \$storageRoot", $verifier);
        self::assertStringContainsString(
            '!in_array($imageReference, $repositoryDigests, true)',
            $verifier
        );
        self::assertStringContainsString(
            'duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1',
            $verifier
        );
        self::assertStringContainsString(
            '$productionConfig->authorityConfigRoot() !== \'/var/lib/duo-cloud/config\'',
            $verifier
        );
        self::assertStringContainsString(
            "'synthetic_rotation_scope' => 'firewall-multi-binding-only'",
            $verifier
        );
    }

    public function testEndSnapshotRejectsAnAtomicSameByteExecutableReplacement(): void {
        $verifier = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $program = <<<'PHP'
define('DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY', true);
require __VERIFIER__;
$root = sys_get_temp_dir() . '/duo-host-proof-snapshot-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$root = (string) realpath($root);
$path = $root . '/engine';
$replacement = $root . '/replacement';
file_put_contents($path, "same executable bytes\n");
chmod($path, 0700);
$identity = observedHostProofFile($path, 'test executable', 1024, true);
file_put_contents($replacement, "same executable bytes\n");
chmod($replacement, 0700);
rename($replacement, $path);
$refused = false;
try {
    revalidateHostProofFile($identity, 'test executable', 1024, true);
} catch (RuntimeException) {
    $refused = true;
}
unlink($path);
rmdir($root);
echo json_encode(['refused' => $refused], JSON_THROW_ON_ERROR);
PHP;
        $program = str_replace('__VERIFIER__', var_export($verifier, true), $program);

        self::assertSame(['refused' => true], $this->phpProgram($program));
    }

    public function testCgroupProbeChildUsesThePinnedInterpreterWithoutExecutablePermission(): void {
        $verifier = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $verifierSource = (string) file_get_contents($verifier);
        self::assertStringContainsString(
            '[$phpCli, $success, $successMarker, $successReady]',
            $verifierSource
        );
        self::assertStringContainsString(
            '[$phpCli, $timeout, $timeoutMarker, $timeoutReady]',
            $verifierSource
        );
        self::assertSame(2, substr_count($verifierSource, 'if ($child < 0) exit(71);'));
        self::assertSame(
            2,
            substr_count(
                $verifierSource,
                'if (file_put_contents($argv[2], "ready\n") !== 6) exit(73);'
            )
        );
        $program = <<<'PHP'
define('DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY', true);
require __VERIFIER__;
$root = sys_get_temp_dir() . '/duo-host-proof-child-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$child = $root . '/child';
writePhpSource($child, "<?php fwrite(STDOUT, \"pinned-interpreter\\n\");\n");
$argv = [PHP_BINARY, $child];
$result = (new \Duo\Cloud\NativeContainerArgvProcessRunner(5, PHP_BINARY))->run($argv);
$mode = fileperms($child) & 0777;
$source = file_get_contents($child);
removeTree($root);
echo json_encode([
    'interpreter_first' => $argv === [PHP_BINARY, $child],
    'mode' => $mode,
    'result' => $result,
    'source' => $source,
], JSON_THROW_ON_ERROR);
PHP;
        $program = str_replace('__VERIFIER__', var_export($verifier, true), $program);

        self::assertSame([
            'interpreter_first' => true,
            'mode' => 0600,
            'result' => [
                'exit' => 0,
                'stderr' => '',
                'stdout' => "pinned-interpreter\n",
            ],
            'source' => "<?php fwrite(STDOUT, \"pinned-interpreter\\n\");\n",
        ], $this->phpProgram($program));
    }

    public function testCgroupProbeRejectsAChildThatFailsBeforeEnforcement(): void {
        $verifier = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $program = <<<'PHP'
define('DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY', true);
require __VERIFIER__;
$root = sys_get_temp_dir() . '/duo-host-proof-child-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$false = realpath('/usr/bin/false') ?: realpath('/bin/false');
$message = null;
try {
    proveCgroup(new \Duo\Cloud\NativeContainerArgvProcessRunner(5, $false), $root, $false);
} catch (RuntimeException $error) {
    $message = $error->getMessage();
}
removeTree($root);
echo json_encode(['message' => $message], JSON_THROW_ON_ERROR);
PHP;
        $program = str_replace('__VERIFIER__', var_export($verifier, true), $program);

        self::assertSame([
            'message' => 'detached successful child failed before cgroup enforcement',
        ], $this->phpProgram($program));
    }

    public function testCgroupTimeoutCannotPassWithoutAReadyDescendant(): void {
        $verifier = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $program = <<<'PHP'
define('DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY', true);
require __VERIFIER__;
final class MissingReadyCgroupRunner implements \Duo\Cloud\ContainerArgvProcessRunner {
    private int $calls = 0;
    public function run(array $argv, ?string $stdinFile = null, ?int $timeoutSeconds = null): array {
        $this->calls++;
        if ($this->calls === 1) {
            file_put_contents($argv[3], "ready\n");
            throw new \Duo\Cloud\ControlRefusal(
                'direct argv process left a descendant after completion'
            );
        }
        throw new \Duo\Cloud\ControlRefusal(
            'direct argv process exceeded its wall timeout'
        );
    }
}
$root = sys_get_temp_dir() . '/duo-host-proof-ready-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$message = null;
try {
    proveCgroup(new MissingReadyCgroupRunner(), $root, PHP_BINARY);
} catch (RuntimeException $error) {
    $message = $error->getMessage();
}
removeTree($root);
echo json_encode(['message' => $message], JSON_THROW_ON_ERROR);
PHP;
        $program = str_replace('__VERIFIER__', var_export($verifier, true), $program);

        self::assertSame([
            'message' => 'detached timed child did not establish its descendant',
        ], $this->phpProgram($program));
    }

    public function testProductionAndNonproductionUnitsCannotBeConfused(): void {
        $production = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-linux-host-boundaries@.service'
        );
        $diagnostic = (string) file_get_contents(
            DUO_REPO_ROOT
                . '/cloud/deploy/duo-cloud-diagnose-linux-host-boundaries-nonproduction.service'
        );

        foreach ([$production, $diagnostic] as $unit) {
            self::assertStringContainsString(
                '--configuration-file ${DUO_CLOUD_PROOF_CONFIGURATION_FILE}',
                $unit
            );
            self::assertStringContainsString(
                '--configuration-sha256 ${DUO_CLOUD_PROOF_CONFIGURATION_SHA256}',
                $unit
            );
            self::assertStringContainsString(
                '--synthetic-rotation-configuration-sha256 '
                    . '${DUO_CLOUD_PROOF_SYNTHETIC_ROTATION_CONFIGURATION_SHA256}',
                $unit
            );
            self::assertStringNotContainsString('DUO_CLOUD_PROOF_IMAGE', $unit);
            self::assertStringNotContainsString('DUO_CLOUD_PROOF_SECCOMP', $unit);
        }
        self::assertStringNotContainsString('--allow-nonproduction-missing-apparmor', $production);
        self::assertStringNotContainsString('SuccessExitStatus=78', $production);
        self::assertStringContainsString('Restart=on-failure', $production);
        self::assertStringContainsString('RestartPreventExitStatus=70 78', $production);
        self::assertStringContainsString('NONPRODUCTION diagnostic', $diagnostic);
        self::assertStringContainsString('--allow-nonproduction-missing-apparmor', $diagnostic);
        self::assertStringContainsString('SuccessExitStatus=78', $diagnostic);
        self::assertStringNotContainsString("\n[Install]\n", $diagnostic);
    }

    public function testNonproductionProofRemainsAFalseReadinessDiagnostic(): void {
        $verifier = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php'
        );

        self::assertStringContainsString(
            "'production_ready' => \$hasAppArmor && !\$nonproductionDiagnostic",
            $verifier
        );
        self::assertStringContainsString(
            '$terminalExit = $nonproductionDiagnostic ? 78 : 0;',
            $verifier
        );
        self::assertStringContainsString(
            "'proof_scope' => \$nonproductionDiagnostic "
                . "? 'nonproduction-diagnostic' : 'production'",
            $verifier
        );
        self::assertStringContainsString("'apparmor' => \$expectAppArmor ? 'docker-default-enforced' : 'missing'", $verifier);
        self::assertStringContainsString('HostAuthorityBusy::matchesProcessResult(', $verifier);
        self::assertStringContainsString('catch (LinuxHostProofTransientBusy)', $verifier);
        self::assertStringContainsString('exit(HostAuthorityBusy::EXIT_STATUS)', $verifier);
    }

    public function testHostProofEnvironmentContainsNoDuplicatedRuntimeAuthority(): void {
        $lines = file(
            DUO_REPO_ROOT . '/cloud/deploy/host-proof.env.example',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        self::assertSame([
            'DUO_CLOUD_PROOF_CONFIGURATION_FILE=/var/lib/duo-cloud/config/workers/site-a.json',
            'DUO_CLOUD_PROOF_CONFIGURATION_SHA256=REPLACE_PRIMARY_WORKER_CONFIGURATION_SHA256',
            'DUO_CLOUD_PROOF_HOST_PREFLIGHT_ROOT=/var/lib/duo-cloud/host-preflight',
            'DUO_CLOUD_PROOF_SYNTHETIC_ROTATION_CONFIGURATION_SHA256='
                . 'REPLACE_DOMAIN_SEPARATED_SYNTHETIC_ROTATION_SHA256',
        ], $lines);
    }
}
