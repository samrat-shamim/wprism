<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\AdapterLibraryAssembler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterLibraryAssembler.php';

final class AdapterLibraryAssemblerTest extends TestCase
{
    private string $scratch;
    private string $repo;

    protected function setUp(): void
    {
        $scratch = sys_get_temp_dir() . '/duo-adapter-assembler-' . bin2hex(random_bytes(8));
        self::makeDirectory($scratch);
        $resolved = realpath($scratch);
        self::assertNotFalse($resolved);
        $this->scratch = $resolved;
        $this->repo = $this->scratch . '/source';
        self::makeDirectory($this->repo . '/adapter-packages');
        $this->addPlatform();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->scratch);
    }

    public function testAssemblyReplacesStaleStageWithExactBytePreservingAllowlist(): void
    {
        $this->addPackage('zeta');
        $this->addPackage(
            'alpha',
            [
                'interpreter' => 'alpha',
                'providers' => [
                    ['id' => 'plugin-code', 'source' => 'plugin'],
                    ['id' => 'cache', 'source' => 'manifest'],
                ],
                'post_types' => [
                    'article' => ['regen_dependency' => ['regenerator' => 'article-index']],
                ],
            ],
            [
                'interpreters/alpha.php' => "<?php\n// alpha interpreter\n",
                'providers/cache.php' => "<?php\n// cache provider\n",
                'regenerators/article-index.php' => "<?php\n// article regenerator\n",
            ]
        );
        self::makeDirectory($this->repo . '/adapter-packages/alpha/tests/offline');
        self::write($this->repo . '/adapter-packages/alpha/tests/offline/regress.php', '<?php');
        self::makeDirectory($this->repo . '/adapter-packages/alpha/fixtures');
        self::write($this->repo . '/adapter-packages/alpha/fixtures/site.json', '{}');
        self::makeDirectory($this->repo . '/adapter-packages/alpha/evidence');
        self::write($this->repo . '/adapter-packages/alpha/evidence/proof.txt', 'not shipped');
        self::write($this->repo . '/adapter-packages/alpha/README.md', '# Alpha');

        $agent = $this->stageAgent('first');
        self::makeDirectory($agent . '/adapter-library/old');
        self::write($agent . '/adapter-library/old/stale.txt', 'stale');
        $result = AdapterLibraryAssembler::assemble($this->repo, $agent);

        self::assertSame(AdapterLibraryAssembler::FORMAT, $result['format']);
        self::assertSame($agent . '/adapter-library', $result['target']);
        self::assertSame(
            "duo-embedded-adapter-library-assembly/v1\n",
            file_get_contents($agent . '/' . AdapterLibraryAssembler::DEPLOYMENT_MARKER)
        );
        self::assertSame([
            'adapter-library/adapters/alpha/manifest.json',
            'adapter-library/adapters/alpha/disposition.json',
            'adapter-library/adapters/alpha/runtime/interpreters/alpha.php',
            'adapter-library/adapters/alpha/runtime/providers/cache.php',
            'adapter-library/adapters/alpha/runtime/regenerators/article-index.php',
            'adapter-library/adapters/zeta/manifest.json',
            'adapter-library/adapters/zeta/disposition.json',
            'adapter-library/platform/core/manifest.json',
            'adapter-library/platform/core/disposition.json',
            'adapter-library/platform/profiles.json',
            'adapter-library/platform/capabilities/platform.json',
            'adapter-library/platform/capabilities/adapter-authorities.json',
        ], array_column($result['files'], 'path'));
        self::assertSame(self::digest($result['files']), $result['library_sha256']);
        self::assertFileDoesNotExist($agent . '/adapter-library/old/stale.txt');
        self::assertFileDoesNotExist($agent . '/adapter-library/adapters/alpha/tests/offline/regress.php');
        self::assertFileDoesNotExist($agent . '/adapter-library/adapters/alpha/fixtures/site.json');
        self::assertFileDoesNotExist($agent . '/adapter-library/adapters/alpha/evidence/proof.txt');
        self::assertFileDoesNotExist($agent . '/adapter-library/adapters/alpha/README.md');

        foreach ($result['files'] as $row) {
            $destination = $agent . '/' . $row['path'];
            self::assertFileExists($destination);
            self::assertSame($row['sha256'], hash_file('sha256', $destination));
            self::assertSame($row['size'], filesize($destination));
            self::assertSame(0644, fileperms($destination) & 0777);
            self::assertSame(946684800, filemtime($destination));
        }
        self::assertSame(
            file_get_contents($this->repo . '/adapter-packages/alpha/package/runtime/providers/cache.php'),
            file_get_contents($agent . '/adapter-library/adapters/alpha/runtime/providers/cache.php')
        );
        self::assertSame([], $this->assemblyScratchEntries('first'));
    }

    public function testRepeatedAssembliesHaveIdenticalMembersBytesModesAndTimes(): void
    {
        $this->addPackage('alpha', [
            'providers' => [['id' => 'cache', 'source' => 'manifest']],
        ], ['providers/cache.php' => "<?php\nreturn 'cache';\n"]);
        $firstAgent = $this->stageAgent('first');
        $secondAgent = $this->stageAgent('second');

        $first = AdapterLibraryAssembler::assemble($this->repo, $firstAgent);
        $second = AdapterLibraryAssembler::assemble($this->repo, $secondAgent);
        self::assertSame($first['files'], $second['files']);
        self::assertSame($first['library_sha256'], $second['library_sha256']);
        self::assertSame(
            self::snapshot($firstAgent . '/adapter-library'),
            self::snapshot($secondAgent . '/adapter-library')
        );

        self::write($firstAgent . '/adapter-library/stale.txt', 'remove me');
        $again = AdapterLibraryAssembler::assemble($this->repo, $firstAgent);
        self::assertSame($first['files'], $again['files']);
        self::assertSame($first['library_sha256'], $again['library_sha256']);
        self::assertSame(
            self::snapshot($firstAgent . '/adapter-library'),
            self::snapshot($secondAgent . '/adapter-library')
        );
        self::assertSame([], $this->assemblyScratchEntries('first'));
        self::assertSame([], $this->assemblyScratchEntries('second'));
    }

    public function testAssemblyRefusesAWholeSourceGenerationChangeBetweenCaptureAndPublish(): void
    {
        $this->addPackage('alpha', [
            'providers' => [['id' => 'cache', 'source' => 'manifest']],
        ], ['providers/cache.php' => "<?php\nreturn 'generation-a';\n"]);
        $agent = $this->stageAgent('generation-race');
        self::makeDirectory($agent . '/adapter-library/current');
        self::write($agent . '/adapter-library/current/sentinel.txt', 'keep exact');
        $marker = $agent . '/' . AdapterLibraryAssembler::DEPLOYMENT_MARKER;
        self::assertFileDoesNotExist($marker);
        $before = self::snapshot($agent);

        try {
            AdapterLibraryAssembler::assemble(
                $this->repo,
                $agent,
                function (): void {
                    self::writeJson(
                        $this->repo . '/adapter-packages/alpha/package/manifest.json',
                        [
                            'name' => 'alpha',
                            'providers' => [['id' => 'cache', 'source' => 'manifest']],
                            'notes' => ['generation' => 'b'],
                        ]
                    );
                    self::write(
                        $this->repo . '/adapter-packages/alpha/package/runtime/providers/cache.php',
                        "<?php\nreturn 'generation-b';\n"
                    );
                }
            );
            self::fail('A source generation change must refuse assembly');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Adapter package source generation changed during assembly',
                $exception->getMessage()
            );
        }

        self::assertSame($before, self::snapshot($agent));
        self::assertFileDoesNotExist($marker);
        self::assertSame([], $this->assemblyScratchEntries('generation-race'));
    }

    public function testSourceRefusalHappensBeforeExistingStageMutation(): void
    {
        $this->addPackage('alpha', [], ['providers/undeclared.php' => '<?php']);
        $agent = $this->stageAgent('refusal');
        self::makeDirectory($agent . '/adapter-library/current');
        self::write($agent . '/adapter-library/current/sentinel.txt', 'keep exact');
        $marker = $agent . '/' . AdapterLibraryAssembler::DEPLOYMENT_MARKER;
        self::assertFileDoesNotExist($marker);
        $before = self::snapshot($agent);

        try {
            AdapterLibraryAssembler::assemble($this->repo, $agent);
            self::fail('Undeclared runtime PHP should refuse assembly');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Undeclared runtime PHP', $exception->getMessage());
        }

        self::assertSame($before, self::snapshot($agent));
        self::assertFileDoesNotExist($marker);
        self::assertSame('keep exact', file_get_contents($agent . '/adapter-library/current/sentinel.txt'));
        self::assertSame([], $this->assemblyScratchEntries('refusal'));
    }

    public function testCompleteEmbeddedValidationRefusesDuplicateRuntimeIdentityBeforeStageMutation(): void
    {
        foreach (['alpha', 'beta'] as $slug) {
            $this->addPackage(
                $slug,
                ['providers' => [['id' => 'shared-cache', 'source' => 'manifest']]],
                ['providers/shared-cache.php' => "<?php\n// $slug\n"]
            );
        }
        $agent = $this->stageAgent('duplicate-runtime');
        self::makeDirectory($agent . '/adapter-library/current');
        self::write($agent . '/adapter-library/current/sentinel.txt', 'keep exact');
        $before = self::snapshot($agent);

        try {
            AdapterLibraryAssembler::assemble($this->repo, $agent);
            self::fail('Duplicate global runtime identities must refuse assembly');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'adapter runtime provider shared-cache is declared by both alpha and beta',
                $exception->getMessage()
            );
        }

        self::assertSame($before, self::snapshot($agent));
        self::assertFileDoesNotExist($agent . '/' . AdapterLibraryAssembler::DEPLOYMENT_MARKER);
        self::assertSame([], $this->assemblyScratchEntries('duplicate-runtime'));
    }

    public function testEveryPreCommitPublicationFaultRestoresLibraryAndNewMarkerState(): void
    {
        $this->addPackage('alpha');
        foreach (['marker-written', 'target-published', 'pre-backup-cleanup'] as $faultPhase) {
            $stage = 'publication-' . $faultPhase;
            $agent = $this->stageAgent($stage);
            self::makeDirectory($agent . '/adapter-library/current');
            self::write($agent . '/adapter-library/current/sentinel.txt', 'keep exact');
            $before = self::snapshot($agent);

            try {
                AdapterLibraryAssembler::assemble(
                    $this->repo,
                    $agent,
                    null,
                    static function (string $phase) use ($faultPhase): void {
                        if ($phase === $faultPhase) {
                            throw new RuntimeException("publication fault at $phase");
                        }
                    }
                );
                self::fail("The $faultPhase publication fault must refuse assembly");
            } catch (RuntimeException $exception) {
                self::assertSame("publication fault at $faultPhase", $exception->getMessage());
            }

            self::assertSame($before, self::snapshot($agent), "$faultPhase restores the exact staged agent");
            self::assertFileDoesNotExist($agent . '/' . AdapterLibraryAssembler::DEPLOYMENT_MARKER);
            self::assertSame([], $this->assemblyScratchEntries($stage));
        }
    }

    public function testPostCommitBackupCleanupFailureKeepsTheCompletePublicationSuccessful(): void
    {
        $this->addPackage('alpha');
        $agent = $this->stageAgent('cleanup-failure');
        self::makeDirectory($agent . '/adapter-library/current');
        self::write($agent . '/adapter-library/current/sentinel.txt', 'old');
        $cleanupReached = false;

        $result = AdapterLibraryAssembler::assemble(
            $this->repo,
            $agent,
            null,
            static function (string $phase) use (&$cleanupReached): void {
                if ($phase === 'backup-cleanup') {
                    $cleanupReached = true;
                    throw new RuntimeException('simulated backup cleanup failure');
                }
            }
        );

        self::assertTrue($cleanupReached);
        self::assertSame($agent . '/adapter-library', $result['target']);
        self::assertSame(
            "duo-embedded-adapter-library-assembly/v1\n",
            file_get_contents($agent . '/' . AdapterLibraryAssembler::DEPLOYMENT_MARKER)
        );
        self::assertFileExists($agent . '/adapter-library/adapters/alpha/manifest.json');
        self::assertFileDoesNotExist($agent . '/adapter-library/current/sentinel.txt');
        $scratch = $this->assemblyScratchEntries('cleanup-failure');
        self::assertCount(1, $scratch);
        self::assertStringContainsString('backup-', $scratch[0]);
    }

    public function testSymlinkedExistingTargetRefusesWithoutFollowingIt(): void
    {
        $this->addPackage('alpha');
        $agent = $this->stageAgent('symlink');
        $outside = $this->scratch . '/outside-library';
        self::makeDirectory($outside);
        self::write($outside . '/sentinel.txt', 'outside');
        if (!@symlink($outside, $agent . '/adapter-library')) {
            self::markTestSkipped('symlinks are unavailable');
        }

        try {
            AdapterLibraryAssembler::assemble($this->repo, $agent);
            self::fail('Symlinked staging targets must refuse');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not an ordinary directory', $exception->getMessage());
        }

        self::assertTrue(is_link($agent . '/adapter-library'));
        self::assertSame('outside', file_get_contents($outside . '/sentinel.txt'));
    }

    public function testSourceCheckoutAgentCannotBeUsedAsTheStage(): void
    {
        $this->addPackage('alpha');
        self::makeDirectory($this->repo . '/agent');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the source repository');
        AdapterLibraryAssembler::assemble($this->repo, $this->repo . '/agent');
    }

    /**
     * @param array<string,mixed> $manifestFields
     * @param array<string,string> $runtimeFiles
     */
    private function addPackage(string $slug, array $manifestFields = [], array $runtimeFiles = []): void
    {
        $package = $this->repo . '/adapter-packages/' . $slug . '/package';
        self::makeDirectory($package);
        self::writeJson($package . '/manifest.json', ['name' => $slug, ...$manifestFields]);
        self::writeJson($package . '/disposition.json', ['status' => 'supported']);
        foreach ($runtimeFiles as $relative => $bytes) {
            self::makeDirectory(dirname($package . '/runtime/' . $relative));
            self::write($package . '/runtime/' . $relative, $bytes);
        }
    }

    private function addPlatform(): void
    {
        $platform = $this->repo . '/platform/adapter-library';
        self::makeDirectory($platform . '/core');
        self::makeDirectory($platform . '/capabilities');
        self::writeJson($platform . '/core/manifest.json', ['name' => 'core']);
        self::writeJson($platform . '/core/disposition.json', ['status' => 'supported']);
        self::writeJson($platform . '/profiles.json', ['profiles' => []]);
        self::writeJson($platform . '/capabilities/platform.json', ['capabilities' => []]);
        self::writeJson($platform . '/capabilities/adapter-authorities.json', ['authorities' => []]);
    }

    private function stageAgent(string $name): string
    {
        $agent = $this->scratch . '/stage-' . $name . '/agent';
        self::makeDirectory($agent);
        return $agent;
    }

    /** @return list<string> */
    private function assemblyScratchEntries(string $stageName): array
    {
        $parent = $this->scratch . '/stage-' . $stageName;
        $entries = scandir($parent);
        self::assertNotFalse($entries);
        $entries = array_values(array_filter(
            array_diff($entries, ['.', '..']),
            static fn(string $entry): bool => str_contains($entry, '.adapter-library-')
        ));
        sort($entries, SORT_STRING);
        return $entries;
    }

    /**
     * @param list<array{path:string,sha256:string,size:int}> $rows
     */
    private static function digest(array $rows): string
    {
        $context = hash_init('sha256');
        foreach ($rows as $row) {
            hash_update($context, $row['path'] . "\0" . $row['size'] . "\0" . $row['sha256'] . "\n");
        }
        return hash_final($context);
    }

    /** @return array<string,array{type:string,mode:int,mtime:int,sha256:?string,size:int}> */
    private static function snapshot(string $root): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = substr($path, strlen($root) + 1);
            $snapshot[$relative] = [
                'type' => $item->isDir() ? 'directory' : 'file',
                'mode' => $item->getPerms() & 0777,
                'mtime' => $item->getMTime(),
                'sha256' => $item->isFile() ? hash_file('sha256', $path) : null,
                'size' => $item->isFile() ? $item->getSize() : 0,
            ];
        }
        ksort($snapshot, SORT_STRING);
        return $snapshot;
    }

    /** @param array<mixed> $value */
    private static function writeJson(string $path, array $value): void
    {
        self::write($path, json_encode($value, JSON_THROW_ON_ERROR));
    }

    private static function write(string $path, string $bytes): void
    {
        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            self::assertTrue(mkdir($path, 0777, true));
        }
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
