<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ContainerArgvProcessRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

if (!defined('DUO_RUNTIME_IMAGE_VERIFIER_LIBRARY_ONLY')) {
    define('DUO_RUNTIME_IMAGE_VERIFIER_LIBRARY_ONLY', true);
}
require_once DUO_REPO_ROOT . '/cloud/deploy/verify-runtime-image.php';

#[CoversNothing]
final class RuntimeImageVerifierCleanupTest extends TestCase {
    public function testSuccessReceiptIsCreatedOnlyAfterCleanupCompletes(): void {
        $cleaned = false;
        $proof = \finalizeRuntimeImageProof(
            ['format' => 'duo-cloud-runtime-image-proof/v1', 'runtime_ready' => true],
            null,
            static function () use (&$cleaned): void {
                $cleaned = true;
            }
        );

        self::assertTrue($cleaned);
        self::assertSame('exact', $proof['cleanup']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $proof['proof_receipt_sha256']
        );
        $receipt = $proof['proof_receipt_sha256'];
        unset($proof['proof_receipt_sha256']);
        self::assertSame(
            hash(
                'sha256',
                "duo-cloud-runtime-image-proof-receipt/v1\0"
                    . \Duo\Cloud\CanonicalJson::encode($proof)
            ),
            $receipt
        );
    }

    public function testCleanupFailureCannotReturnAProofReceipt(): void {
        try {
            \finalizeRuntimeImageProof(
                ['format' => 'duo-cloud-runtime-image-proof/v1', 'runtime_ready' => true],
                null,
                static function (): void {
                    throw new \RuntimeException('simulated cleanup failure');
                }
            );
            self::fail('cleanup failure returned a success receipt');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'runtime image verifier could not prove exact cleanup',
                $error->getMessage()
            );
            self::assertSame(
                'simulated cleanup failure',
                $error->getPrevious()?->getMessage()
            );
        }
    }

    public function testOwnedDockerStateAndPrivateDirectoryAreProvedAbsent(): void {
        $proofId = str_repeat('a', 16);
        $token = str_repeat('b', 64);
        $container = "duo-preview-$token-g0000000001";
        $volume = "$container-database";
        $runner = new RuntimeImageCleanupRunner(
            [$container => $proofId],
            [$volume => $proofId]
        );
        $directory = sys_get_temp_dir() . '/duo-cloud-image-proof-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        self::assertSame(5, file_put_contents($directory . '/owned', 'proof'));

        \removeProofContainer($runner, '/usr/bin/docker', $container, $proofId);
        \removeProofVolume($runner, '/usr/bin/docker', $volume, $proofId);
        \removeProofDirectory($directory);

        self::assertSame([], $runner->containers);
        self::assertSame([], $runner->volumes);
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testDockerRemovalFailureRefusesAndLeavesNoFalseAbsence(): void {
        $proofId = str_repeat('a', 16);
        $token = str_repeat('b', 64);
        $volume = "duo-preview-$token-g0000000001-filesystem";
        $runner = new RuntimeImageCleanupRunner([], [$volume => $proofId]);
        $runner->failVolumeRemoval = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime image proof volume cleanup failed');
        try {
            \removeProofVolume($runner, '/usr/bin/docker', $volume, $proofId);
        } finally {
            self::assertSame([$volume => $proofId], $runner->volumes);
        }
    }

    public function testFirstCleanupFailureDoesNotSkipLaterOwnedResources(): void {
        $attempted = [];
        try {
            \runRuntimeImageCleanupOperations([
                static function () use (&$attempted): void {
                    $attempted[] = 'container';
                    throw new \RuntimeException('first removal refused');
                },
                static function () use (&$attempted): void {
                    $attempted[] = 'volume';
                },
                static function () use (&$attempted): void {
                    $attempted[] = 'directory';
                },
            ]);
            self::fail('aggregate cleanup accepted one failed owned resource');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'runtime image verifier cleanup operations refused',
                $error->getMessage()
            );
            self::assertSame('first removal refused', $error->getPrevious()?->getMessage());
        }
        self::assertSame(['container', 'volume', 'directory'], $attempted);
    }

    public function testMkdirSuccessBindsCleanupAuthorityBeforeChmodCanFail(): void {
        $owned = false;
        try {
            \acquireRuntimeImageProofDirectory(
                '/synthetic-owned-proof-directory',
                $owned,
                static fn (string $path, int $mode): bool => $path !== '' && $mode === 0700,
                static fn (string $path, int $mode): bool => false
            );
            self::fail('simulated chmod failure was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'proof private directories could not be created',
                $error->getMessage()
            );
        }
        self::assertTrue($owned);
    }

    public function testDurableJournalRecoversVolumeContainerAndEphemeralTagResidue(): void {
        $authorityParent = (string) realpath(DUO_REPO_ROOT . '/sandbox/tmp')
            . '/duo-runtime-proof-authority-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($authorityParent, 0700));
        self::assertTrue(chmod($authorityParent, 0700));
        $authorityRoot = $authorityParent . '/runtime-image-verifier';
        $authority = \acquireRuntimeImageProofAuthority($authorityRoot);
        try {
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
            $proofId = substr(
                hash('sha256', "duo-cloud-runtime-image-verifier-authority/v1\0" . $uid),
                0,
                16
            );
            $tenant = 'tenant-' . $proofId;
            $site = 'site-' . $proofId;
            $token = hash(
                'sha256',
                "duo-cloud-preview-physical-slot/v1\0{$tenant}\0{$site}"
            );
            $container = 'duo-preview-' . $token . '-g0000000001';
            $registry = 'duo-cloud-proof-registry-' . $proofId;
            $helper = 'duo-cloud-proof-helper-' . $proofId;
            $database = $container . '-database';
            $filesystem = $container . '-filesystem';
            $imageId = 'sha256:' . str_repeat('d', 64);
            $journal = \runtimeImageProofJournal(
                PHP_BINARY,
                $proofId,
                $imageId,
                $container,
                $registry,
                $helper,
                $database,
                $filesystem,
                sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId,
                sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId . '-secrets'
            );
            $phases = [
                'volume' => [[], [$database => $proofId], []],
                'container' => [[$container => $proofId], [], []],
                'tag' => [
                    [$registry => $proofId],
                    [],
                    ['127.0.0.1:49152/duo-cloud-preview-' . $proofId . ':proof' => $imageId],
                ],
            ];
            foreach ($phases as $phase => [$containers, $volumes, $images]) {
                $candidate = $journal;
                if ($phase === 'tag') {
                    $candidate['proof_tag'] = array_key_first($images);
                }
                \publishRuntimeImageProofJournal($authority['root'], $candidate);
                $runner = new RuntimeImageCleanupRunner(
                    $containers,
                    $volumes,
                    false,
                    PHP_BINARY,
                    $images
                );

                \recoverRuntimeImageProofJournal($runner, $authority['root']);

                self::assertSame([], $runner->containers, "$phase container residue");
                self::assertSame([], $runner->volumes, "$phase volume residue");
                self::assertSame([], $runner->images, "$phase tag residue");
                self::assertFileDoesNotExist($authority['root'] . '/active.json');
                self::assertFileDoesNotExist($authority['root'] . '/active.json.tmp');
            }
        } finally {
            flock($authority['lock'], LOCK_UN);
            fclose($authority['lock']);
            @unlink($authority['root'] . '/active.json.tmp');
            @unlink($authority['root'] . '/active.json');
            @unlink($authority['root'] . '/authority.lock');
            @rmdir($authority['root']);
            @rmdir($authorityParent);
        }
    }

    public function testMissingDurableJournalCannotAdmitDeterministicDockerResidue(): void {
        self::assertFalse(\runtimeImageProofDurableStateRoot('/tmp/runtime-image-verifier'));
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
        $proofId = substr(
            hash('sha256', "duo-cloud-runtime-image-verifier-authority/v1\0" . $uid),
            0,
            16
        );
        $tenant = 'tenant-' . $proofId;
        $site = 'site-' . $proofId;
        $container = 'duo-preview-' . hash(
            'sha256',
            "duo-cloud-preview-physical-slot/v1\0{$tenant}\0{$site}"
        ) . '-g0000000001';
        $runner = new RuntimeImageCleanupRunner(
            [$container => $proofId],
            [],
            false,
            PHP_BINARY
        );

        try {
            \assertNoUnjournaledRuntimeImageProofResources(
                $runner,
                PHP_BINARY,
                $proofId,
                $container,
                'duo-cloud-proof-registry-' . $proofId,
                'duo-cloud-proof-helper-' . $proofId,
                $container . '-database',
                $container . '-filesystem',
                sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId,
                sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId . '-secrets'
            );
            self::fail('missing durable journal admitted deterministic Docker residue');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'runtime image verifier found unjournaled Docker state',
                $error->getMessage()
            );
        }
        self::assertSame([$container => $proofId], $runner->containers);
    }

    public function testCanonicalTemporaryAliasCannotBecomeDurableAuthority(): void {
        $canonicalTemporary = realpath(sys_get_temp_dir());
        self::assertIsString($canonicalTemporary);
        $authorityRoot = $canonicalTemporary . '/runtime-image-verifier';

        self::assertFalse(\runtimeImageProofDurableStateRoot($authorityRoot));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime image proof durable authority path is invalid');
        \acquireRuntimeImageProofAuthority($authorityRoot);
    }

    public function testAuthorityCreationNeverMutatesExistingOrSymlinkedDirectories(): void {
        $base = (string) realpath(DUO_REPO_ROOT . '/sandbox/tmp')
            . '/duo-runtime-proof-root-safety-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        self::assertTrue(chmod($base, 0700));
        $wrongParent = $base . '/wrong';
        $linkParent = $base . '/link';
        $freshParent = $base . '/fresh';
        foreach ([$wrongParent, $linkParent, $freshParent] as $parent) {
            self::assertTrue(mkdir($parent, 0700));
            self::assertTrue(chmod($parent, 0700));
        }
        $wrong = $wrongParent . '/runtime-image-verifier';
        self::assertTrue(mkdir($wrong, 0755));
        self::assertTrue(chmod($wrong, 0755));
        $wrongMode = fileperms($wrong) & 0777;
        try {
            \acquireRuntimeImageProofAuthority($wrong);
            self::fail('wrong-mode existing authority root was accepted');
        } catch (\RuntimeException) {
            self::assertSame($wrongMode, fileperms($wrong) & 0777);
        }

        $target = $base . '/target';
        self::assertTrue(mkdir($target, 0755));
        self::assertTrue(chmod($target, 0755));
        $targetMode = fileperms($target) & 0777;
        $link = $linkParent . '/runtime-image-verifier';
        self::assertTrue(symlink($target, $link));
        try {
            \acquireRuntimeImageProofAuthority($link);
            self::fail('symlinked authority root was accepted');
        } catch (\RuntimeException) {
            self::assertSame($targetMode, fileperms($target) & 0777);
        }

        $fresh = \acquireRuntimeImageProofAuthority(
            $freshParent . '/runtime-image-verifier'
        );
        self::assertSame(0700, fileperms($fresh['root']) & 0777);
        flock($fresh['lock'], LOCK_UN);
        fclose($fresh['lock']);

        @unlink($fresh['root'] . '/authority.lock');
        @rmdir($fresh['root']);
        @unlink($link);
        @rmdir($target);
        @rmdir($wrong);
        @rmdir($wrongParent);
        @rmdir($linkParent);
        @rmdir($freshParent);
        @rmdir($base);
    }
}

/** @internal Exact fake for the cleanup-only Docker command grammar. */
final class RuntimeImageCleanupRunner implements ContainerArgvProcessRunner {
    /** @param array<string,string> $containers @param array<string,string> $volumes */
    public function __construct(
        public array $containers,
        public array $volumes,
        public bool $failVolumeRemoval = false,
        private string $docker = '/usr/bin/docker',
        public array $images = []
    ) {}

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        if ($stdinFile !== null || $timeoutSeconds !== null || ($argv[0] ?? null) !== $this->docker) {
            return self::result(64, '', 'unexpected invocation');
        }
        $kind = $argv[1] ?? null;
        $action = $argv[2] ?? null;
        if ($kind === 'container' && $action === 'ls') {
            $name = self::filteredName($argv, 'name=^/', '$');
            return self::result(0, isset($this->containers[$name]) ? $name . "\n" : '');
        }
        if ($kind === 'container' && $action === 'inspect') {
            $name = (string) end($argv);
            return self::result(0, json_encode([
                'Config' => ['Labels' => ['duo.cloud.local-image-proof' => $this->containers[$name] ?? null]],
                'Name' => '/' . $name,
            ], JSON_THROW_ON_ERROR) . "\n");
        }
        if ($kind === 'container' && $action === 'rm') {
            $name = (string) end($argv);
            unset($this->containers[$name]);
            return self::result(0, $name . "\n");
        }
        if ($kind === 'volume' && $action === 'ls') {
            $name = self::filteredName($argv, 'name=^', '$');
            return self::result(0, isset($this->volumes[$name]) ? $name . "\n" : '');
        }
        if ($kind === 'volume' && $action === 'inspect') {
            $name = (string) end($argv);
            return self::result(0, json_encode([
                'Labels' => ['duo.cloud.local-image-proof' => $this->volumes[$name] ?? null],
                'Name' => $name,
            ], JSON_THROW_ON_ERROR) . "\n");
        }
        if ($kind === 'volume' && $action === 'rm') {
            $name = (string) end($argv);
            if ($this->failVolumeRemoval) {
                return self::result(1, '', 'simulated removal failure');
            }
            unset($this->volumes[$name]);
            return self::result(0, $name . "\n");
        }
        if ($kind === 'image' && $action === 'ls') {
            $filter = $argv[array_search('--filter', $argv, true) + 1] ?? '';
            $tag = is_string($filter) && str_starts_with($filter, 'reference=')
                ? substr($filter, strlen('reference='))
                : '';
            return self::result(
                0,
                isset($this->images[$tag]) ? $tag . ' ' . $this->images[$tag] . "\n" : ''
            );
        }
        if ($kind === 'image' && $action === 'rm') {
            $tag = (string) end($argv);
            unset($this->images[$tag]);
            return self::result(0, $tag . "\n");
        }
        return self::result(64, '', 'unexpected invocation');
    }

    /** @param list<string> $argv */
    private static function filteredName(array $argv, string $prefix, string $suffix): string {
        $filter = $argv[array_search('--filter', $argv, true) + 1] ?? '';
        if (!is_string($filter) || !str_starts_with($filter, $prefix)
            || !str_ends_with($filter, $suffix)) {
            return '';
        }
        return substr($filter, strlen($prefix), -strlen($suffix));
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private static function result(int $exit, string $stdout, string $stderr = ''): array {
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }
}
