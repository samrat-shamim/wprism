<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ControlRefusal;
use Duo\Cloud\NativeContainerArgvProcessRunner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/cloud/src/ContainerWorkloadRuntime.php';

final class NativeContainerArgvProcessRunnerTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/duo-native-runner-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void {
        self::remove($this->root);
    }

    public function testOutputOverflowKillsDelayedDescendantBeforeItsSideEffect(): void {
        $marker = $this->root . '/overflow-orphan';
        $script = $this->executable('overflow.php', <<<'PHP'
<?php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
$child = pcntl_fork();
if ($child === 0) {
    usleep(2000000);
    file_put_contents($argv[1], "escaped\n");
    exit(0);
}
while (true) {
    fwrite(STDERR, str_repeat('x', 65536));
}
PHP);
        $runner = new NativeContainerArgvProcessRunner(5, PHP_BINARY);
        $started = microtime(true);
        try {
            $runner->run([$script, $marker]);
            self::fail('expected the direct argv output cap to refuse');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'direct argv process output exceeded its byte limit',
                $error->getMessage()
            );
        }
        self::assertLessThan(2.0, microtime(true) - $started);
        usleep(2200000);
        self::assertFileDoesNotExist($marker);
    }

    public function testOuterDeadlineKillsNestedAuthorityRunnerInTheSameTransaction(): void {
        $marker = $this->root . '/nested-orphan';
        $inner = $this->executable('nested-child.php', <<<'PHP'
<?php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
$child = pcntl_fork();
if ($child === 0) {
    usleep(3000000);
    file_put_contents($argv[1], "escaped\n");
    exit(0);
}
sleep(30);
PHP);
        $source = var_export(dirname(__DIR__, 2) . '/cloud/src/ContainerWorkloadRuntime.php', true);
        $authority = $this->executable('nested-authority.php', "<?php\n"
            . "require_once $source;\n"
            . '$runner = new \\Duo\\Cloud\\NativeContainerArgvProcessRunner(10, PHP_BINARY, false);' . "\n"
            . '$runner->run([$argv[1], $argv[2]]);' . "\n");
        $runner = new NativeContainerArgvProcessRunner(1, PHP_BINARY);
        try {
            $runner->run([$authority, $inner, $marker]);
            self::fail('expected the outer transaction deadline to refuse');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'direct argv process exceeded its wall timeout',
                $error->getMessage()
            );
        }
        usleep(3200000);
        self::assertFileDoesNotExist($marker);
    }

    private function executable(string $name, string $body): string {
        $path = $this->root . '/' . $name;
        $bytes = '#!' . PHP_BINARY . "\n" . $body;
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0700));
        return $path;
    }

    private static function remove(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
