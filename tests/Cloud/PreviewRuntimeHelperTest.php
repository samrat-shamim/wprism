<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\Image\PreviewRuntimeHelper;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/CanonicalJson.php';
require_once DUO_REPO_ROOT . '/cloud/runtime/image/PreviewRuntimeHelper.php';

#[CoversNothing]
final class PreviewRuntimeHelperTest extends TestCase {
    private string $scratch;
    private string $configuration;
    private string $reviewedBase;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-preview-helper-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        foreach (['database', 'run', 'secrets', 'wordpress'] as $directory) {
            self::assertTrue(mkdir($this->scratch . '/' . $directory, 0700));
        }
        $this->configuration = hash('sha256', 'runtime configuration');
        $this->reviewedBase = hash('sha256', 'reviewed base');
        $this->writeState(true);
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testStatusReturnsExactAuthorityAndRefusesForeignReview(): void {
        $helper = $this->helper();
        ob_start();
        self::assertSame(0, $helper->dispatch('runtime-status', $this->statusArguments()));
        $output = ob_get_clean();
        self::assertSame(CanonicalJson::encode([
            'clean_base' => true,
            'configuration_sha256' => $this->configuration,
            'format' => 'duo-cloud-preview-runtime-status/v1',
            'lease_generation' => 7,
            'ready' => true,
            'reviewed_base_sha256' => $this->reviewedBase,
        ]) . "\n", $output);

        $foreign = $this->statusArguments();
        $foreign[array_search($this->reviewedBase, $foreign, true)] = hash('sha256', 'foreign');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stale or foreign');
        $helper->dispatch('runtime-status', $foreign);
    }

    public function testStatusDoesNotTrustPersistentReadyStateWithoutCurrentProcessMarker(): void {
        self::assertTrue(unlink($this->scratch . '/run/runtime-ready.json'));
        ob_start();
        self::assertSame(0, $this->helper()->dispatch('runtime-status', $this->statusArguments()));
        $output = ob_get_clean();

        $status = json_decode((string) $output, true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($status['ready']);
    }

    public function testStatusReconcilesOneDestinationBoundStateCrashTemporary(): void {
        $temporary = $this->scratch . '/database/runtime-state.json.tmp';
        self::assertSame(8, file_put_contents($temporary, "partial\n"));
        self::assertTrue(chmod($temporary, 0600));

        ob_start();
        self::assertSame(0, $this->helper()->dispatch('runtime-status', $this->statusArguments()));
        ob_end_clean();

        self::assertFileDoesNotExist($temporary);
        self::assertSame([], glob($temporary . '*') ?: []);
    }

    public function testUnsafeStateTemporaryRefusesWithoutFollowingIt(): void {
        $temporary = $this->scratch . '/database/runtime-state.json.tmp';
        $outside = $this->scratch . '/outside-state';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $temporary));

        try {
            $this->helper()->dispatch('runtime-status', $this->statusArguments());
            self::fail('unsafe runtime-state temporary was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('stale temporary file must be', $error->getMessage());
        }
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(is_link($temporary));
    }

    public function testMaterializesSafeTarAndMarksBaseChanged(): void {
        $tarPath = $this->scratch . '/candidate.tar';
        $tar = new \PharData($tarPath);
        $tar->addFromString('site.duo.json', "{}\n");
        $tar->addFromString('state/example.json', "{\"ok\":true}\n");
        unset($tar);
        $repository = $this->scratch . '/repository';
        $helper = $this->helper(null, $tarPath, $repository);
        ob_start();
        self::assertSame(0, $helper->dispatch('materialize-repository', array_merge(
            $this->stageArguments(),
            [
                '--branch-commit', str_repeat('a', 40),
                '--branch-ref', 'refs/heads/duo-preview/operation-a',
                '--destination', $repository,
            ]
        )));
        $output = ob_get_clean();

        self::assertFileExists($repository . '/site.duo.json');
        self::assertFileExists($repository . '/state/example.json');
        self::assertStringContainsString('"stage":"materialize-repository"', (string) $output);
        $state = json_decode((string) file_get_contents($this->scratch . '/database/runtime-state.json'), true);
        self::assertFalse($state['clean_base']);
    }

    public function testMaterializationReusesAndRemovesOneLabelBoundCaptureResidue(): void {
        $residue = $this->scratch . '/run/duo-repository.tar';
        self::assertSame(8, file_put_contents($residue, "partial\n"));
        self::assertTrue(chmod($residue, 0600));
        $tarPath = $this->scratch . '/candidate.tar';
        $tar = new \PharData($tarPath);
        $tar->addFromString('site.duo.json', "{}\n");
        unset($tar);
        $repository = $this->scratch . '/repository';

        ob_start();
        self::assertSame(0, $this->helper(null, $tarPath, $repository)->dispatch(
            'materialize-repository',
            $this->materializeArguments($repository)
        ));
        ob_end_clean();

        self::assertFileExists($repository . '/site.duo.json');
        self::assertFileDoesNotExist($residue);
        self::assertSame([], glob($this->scratch . '/run/duo-repository*') ?: []);
    }

    public function testUnsafeLabelBoundCaptureResidueRefusesWithoutFollowingIt(): void {
        $residue = $this->scratch . '/run/duo-repository.tar';
        $outside = $this->scratch . '/outside-capture';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $residue));
        $tarPath = $this->scratch . '/candidate.tar';
        $tar = new \PharData($tarPath);
        $tar->addFromString('site.duo.json', "{}\n");
        unset($tar);
        $repository = $this->scratch . '/repository';

        try {
            $this->helper(null, $tarPath, $repository)->dispatch(
                'materialize-repository',
                $this->materializeArguments($repository)
            );
            self::fail('unsafe capture residue was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('stale ephemeral file must be', $error->getMessage());
        }
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(is_link($residue));
        self::assertFileDoesNotExist($repository);
    }

    public function testInterruptedReplacementRestoresBackupBeforeReadingAnotherArchive(): void {
        $repository = $this->scratch . '/repository';
        self::assertTrue(mkdir($repository, 0700));
        self::assertSame(4, file_put_contents($repository . '/old', "old\n"));
        [$stage, $backup] = self::replacementPaths($repository);
        self::assertTrue(rename($repository, $backup));
        self::assertTrue(mkdir($stage, 0700));
        self::assertSame(8, file_put_contents($stage . '/partial', "partial\n"));
        $invalid = $this->scratch . '/invalid.tar';
        self::assertSame(8, file_put_contents($invalid, "not tar\n"));

        try {
            $this->helper(null, $invalid, $repository)->dispatch(
                'materialize-repository',
                $this->materializeArguments($repository)
            );
            self::fail('invalid archive was accepted after replacement recovery');
        } catch (\Throwable) {
            self::assertFileExists($repository . '/old');
        }
        self::assertFileDoesNotExist($stage);
        self::assertFileDoesNotExist($backup);
        self::assertSame("old\n", file_get_contents($repository . '/old'));
    }

    public function testPublishedReplacementWinsAndRetiredBackupIsRemovedBeforeNextArchive(): void {
        $repository = $this->scratch . '/repository';
        self::assertTrue(mkdir($repository, 0700));
        self::assertSame(4, file_put_contents($repository . '/new', "new\n"));
        [$stage, $backup] = self::replacementPaths($repository);
        self::assertTrue(mkdir($backup, 0700));
        self::assertSame(4, file_put_contents($backup . '/old', "old\n"));
        $invalid = $this->scratch . '/invalid.tar';
        self::assertSame(8, file_put_contents($invalid, "not tar\n"));

        try {
            $this->helper(null, $invalid, $repository)->dispatch(
                'materialize-repository',
                $this->materializeArguments($repository)
            );
            self::fail('invalid archive was accepted after replacement cleanup');
        } catch (\Throwable) {
            self::assertFileExists($repository . '/new');
        }
        self::assertFileDoesNotExist($stage);
        self::assertFileDoesNotExist($backup);
        self::assertSame("new\n", file_get_contents($repository . '/new'));
    }

    public function testUnsafeReplacementResidueRefusesWithoutFollowingIt(): void {
        $repository = $this->scratch . '/repository';
        [$stage, $backup] = self::replacementPaths($repository);
        $outside = $this->scratch . '/outside';
        self::assertTrue(mkdir($outside, 0700));
        self::assertSame(8, file_put_contents($outside . '/marker', "outside\n"));
        self::assertTrue(symlink($outside, $backup));
        $invalid = $this->scratch . '/invalid.tar';
        self::assertSame(8, file_put_contents($invalid, "not tar\n"));

        try {
            $this->helper(null, $invalid, $repository)->dispatch(
                'materialize-repository',
                $this->materializeArguments($repository)
            );
            self::fail('unsafe replacement residue was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('residue is not a directory', $error->getMessage());
        }
        self::assertSame("outside\n", file_get_contents($outside . '/marker'));
        self::assertTrue(is_link($backup));
        self::assertFileDoesNotExist($stage);
    }

    public function testUrlRebindUsesDirectWpArgvAndNeverACommandString(): void {
        $calls = [];
        $run = static function (array $argv, ?string $stdin) use (&$calls): array {
            $calls[] = [$argv, $stdin];
            return ['exit' => 0, 'stderr' => '', 'stdout' => ''];
        };
        $helper = $this->helper($run);
        ob_start();
        self::assertSame(0, $helper->dispatch('url-rebind', array_merge(
            $this->stageArguments(),
            ['--url', 'https://preview.example.test']
        )));
        ob_end_clean();

        self::assertCount(2, $calls);
        self::assertSame('/usr/local/bin/wp', $calls[0][0][0]);
        self::assertSame(['option', 'update', 'home', 'https://preview.example.test', '--quiet'], array_slice($calls[0][0], 2));
        self::assertNull($calls[0][1]);
    }

    public function testWpCommandPreservesArgumentBoundariesAndOutput(): void {
        $seen = null;
        $run = static function (array $argv, ?string $stdin) use (&$seen): array {
            $seen = [$argv, $stdin];
            return ['exit' => 3, 'stderr' => '', 'stdout' => "out\n"];
        };
        $helper = $this->helper($run);
        ob_start();
        self::assertSame(3, $helper->dispatch('duo-preview-command', [
            'wp', '--', 'option', 'get', 'name with spaces',
        ]));
        $stdout = ob_get_clean();
        self::assertSame("out\n", $stdout);
        self::assertSame([
            '/usr/local/bin/wp', '--path=' . $this->scratch . '/wordpress',
            'option', 'get', 'name with spaces',
        ], $seen[0]);
        self::assertNull($seen[1]);
    }

    public function testCleanBaseConfigurationDisablesCronAndFileMutationAndNamesStaging(): void {
        $method = new \ReflectionMethod(PreviewRuntimeHelper::class, 'wordpressConfiguration');
        $configuration = $method->invoke(null, 'database-password-value-00000000', 'auth-key', 'auth-salt');

        self::assertIsString($configuration);
        self::assertStringContainsString("define('DISABLE_WP_CRON', true);\n", $configuration);
        self::assertStringContainsString("define('DISALLOW_FILE_MODS', true);\n", $configuration);
        self::assertStringContainsString("define('WP_ENVIRONMENT_TYPE', 'staging');\n", $configuration);
        self::assertSame(1, substr_count($configuration, "require_once ABSPATH . 'wp-settings.php';"));
    }

    public function testNativeRunnerKillsAStalledCommandAtItsDeadline(): void {
        $input = $this->scratch . '/stall.sh';
        file_put_contents($input, "sleep 5\n");
        $helper = new PreviewRuntimeHelper(
            $this->scratch . '/database',
            $this->scratch . '/wordpress',
            $this->scratch . '/repository',
            $this->scratch . '/secrets',
            null,
            $input,
            1
        );
        $started = microtime(true);
        try {
            $helper->dispatch('duo-preview-command', ['raw']);
            self::fail('stalled command unexpectedly completed');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('deadline', $error->getMessage());
            self::assertLessThan(3.0, microtime(true) - $started);
        }
    }

    public function testNativeRunnerBoundsLargeStderrWithoutPipeDeadlock(): void {
        $input = $this->scratch . '/large-stderr.sh';
        $php = escapeshellarg(PHP_BINARY);
        file_put_contents($input, $php . " -r 'fwrite(STDERR, str_repeat(\"x\", 17825792));'\n");
        $helper = new PreviewRuntimeHelper(
            $this->scratch . '/database',
            $this->scratch . '/wordpress',
            $this->scratch . '/repository',
            $this->scratch . '/secrets',
            null,
            $input,
            5
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output exceeded');
        $helper->dispatch('duo-preview-command', ['raw']);
    }

    /** @return list<string> */
    private function statusArguments(): array {
        return [
            '--format', 'duo-cloud-preview-runtime-status/v1',
            '--config-sha256', $this->configuration,
            '--lease-generation', '7',
            '--reviewed-base-sha256', $this->reviewedBase,
        ];
    }

    /** @return list<string> */
    private function stageArguments(): array {
        return [
            '--format', 'duo-cloud-preview-runtime-stage/v1',
            '--config-sha256', $this->configuration,
            '--lease-generation', '7',
            '--reviewed-base-sha256', $this->reviewedBase,
        ];
    }

    /** @return list<string> */
    private function materializeArguments(string $repository): array {
        return array_merge($this->stageArguments(), [
            '--branch-commit', str_repeat('a', 40),
            '--branch-ref', 'refs/heads/duo-preview/operation-a',
            '--destination', $repository,
        ]);
    }

    /** @return array{string,string} */
    private static function replacementPaths(string $destination): array {
        $parent = dirname($destination);
        $token = hash('sha256', "duo-preview-runtime-replacement/v1\0" . $destination);
        return [
            $parent . '/.duo-stage-' . $token,
            $parent . '/.duo-backup-' . $token,
        ];
    }

    private function helper(
        ?callable $run = null,
        string $input = 'php://stdin',
        ?string $repository = null
    ): PreviewRuntimeHelper {
        return new PreviewRuntimeHelper(
            $this->scratch . '/database',
            $this->scratch . '/wordpress',
            $repository ?? $this->scratch . '/repository',
            $this->scratch . '/secrets',
            $run,
            $input,
            300,
            $this->scratch . '/run'
        );
    }

    private function writeState(bool $clean): void {
        file_put_contents($this->scratch . '/database/runtime-state.json', CanonicalJson::encode([
            'clean_base' => $clean,
            'configuration_sha256' => $this->configuration,
            'format' => 'duo-cloud-preview-image-state/v1',
            'lease_generation' => 7,
            'ready' => true,
            'reviewed_base_sha256' => $this->reviewedBase,
            'url' => 'http://127.0.0.1:8080',
        ]) . "\n");
        chmod($this->scratch . '/database/runtime-state.json', 0600);
        file_put_contents($this->scratch . '/run/runtime-ready.json', CanonicalJson::encode([
            'configuration_sha256' => $this->configuration,
            'format' => 'duo-cloud-preview-process-readiness/v1',
            'lease_generation' => 7,
            'reviewed_base_sha256' => $this->reviewedBase,
        ]) . "\n");
        chmod($this->scratch . '/run/runtime-ready.json', 0600);
    }

    private function remove(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
