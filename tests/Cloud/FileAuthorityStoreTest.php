<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/FileAuthorityStore.php';

#[CoversNothing]
final class FileAuthorityStoreTest extends TestCase {
    private string $scratch;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-file-authority-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testHeldAuthorityLockRefusesImmediatelyWithoutEnteringCallbackAndRetrySucceeds(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the authority-lock contention regression');
        }
        $store = new FileAuthorityStore($this->scratch . '/authority.json');
        $started = $this->scratch . '/started';
        $release = $this->scratch . '/release';
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                $store->locked(static function () use ($started, $release): void {
                    file_put_contents($started, "held\n");
                    $deadline = microtime(true) + 5.0;
                    while (!is_file($release) && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (!is_file($release)) {
                        throw new \RuntimeException('authority-lock fixture timed out');
                    }
                });
                exit(0);
            } catch (\Throwable) {
                exit(70);
            }
        }
        $this->waitForFile($started);

        $entered = false;
        $before = microtime(true);
        try {
            $store->locked(static function () use (&$entered): void {
                $entered = true;
            });
            self::fail('held authority lock was acquired by a contender');
        } catch (ControlRefusal $error) {
            self::assertSame('authority store lock is busy', $error->getMessage());
        }
        self::assertLessThan(0.5, microtime(true) - $before);
        self::assertFalse($entered);

        self::assertNotFalse(file_put_contents($release, "continue\n"));
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        $store->locked(static function ($session): void {
            $session->save(['recovered' => true]);
        });
        self::assertFileExists($this->scratch . '/authority.json');
    }

    public function testKilledPublicationLeavesOneRecoverableDestinationBoundTemporary(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')
            || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and POSIX signals are required for the authority crash regression');
        }
        $path = $this->scratch . '/authority.json';
        $store = new FileAuthorityStore($path);
        $store->locked(static function ($session): void {
            $session->save(['generation' => 1]);
        });

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_FILE_AUTHORITY_KILL_PHASE=temporary-synchronized');
            $store->locked(static function ($session): void {
                $session->save(['generation' => 2]);
            });
            exit(71);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        self::assertFileExists($path . '.tmp');
        self::assertCount(1, glob($path . '.tmp*') ?: []);

        $store->locked(static function ($session): void {
            self::assertSame(['generation' => 1], $session->state());
            $session->save(['generation' => 2]);
        });

        self::assertSame([], glob($path . '.tmp*') ?: []);
        self::assertSame(['generation' => 2], $store->stableRead());
    }

    public function testUnsafeDestinationBoundTemporaryRefusesWithoutFollowingIt(): void {
        $path = $this->scratch . '/authority.json';
        $outside = $this->scratch . '/outside';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $path . '.tmp'));
        $store = new FileAuthorityStore($path);

        try {
            $store->locked(static function (): void {
                self::fail('unsafe temporary must refuse before entering the authority callback');
            });
            self::fail('unsafe temporary was accepted');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('temporary file must be', $error->getMessage());
        }
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(is_link($path . '.tmp'));
    }

    private function waitForFile(string $path): void {
        $deadline = microtime(true) + 5.0;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
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
