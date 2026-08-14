<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DelegateCheckTest extends TestCase
{
    private string $root;

    private string $fixture;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $directory = $this->root . '/artifacts/test-tmp';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $this->fixture = $directory . '/delegate-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixture)) {
            unlink($this->fixture);
        }
    }

    public function testMissingOwnerExportIsUnavailable(): void
    {
        [$exit, $stdout, $stderr] = $this->runDelegate([
            '--label=canonical-contract-check',
            '--delegate=artifacts/test-tmp/absent-export',
            '--',
        ]);

        self::assertSame(69, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('owner export is unavailable or non-executable', $stderr);
    }

    public function testArgumentsAndExitStatusArePreserved(): void
    {
        file_put_contents(
            $this->fixture,
            "#!/usr/bin/env php\n<?php fwrite(STDOUT, json_encode(array_slice(\$argv, 1))); exit(7);\n",
        );
        chmod($this->fixture, 0700);
        $relative = substr($this->fixture, strlen($this->root) + 1);

        [$exit, $stdout, $stderr] = $this->runDelegate([
            '--label=evidence-impact',
            '--delegate=' . $relative,
            '--required-sha=BASE_SHA=' . str_repeat('a', 40),
            '--',
            '--base-sha=' . str_repeat('a', 40),
            'two words',
        ]);

        self::assertSame(7, $exit);
        self::assertSame(
            json_encode(['--base-sha=' . str_repeat('a', 40), 'two words']),
            $stdout,
        );
        self::assertSame('', $stderr);
    }

    public function testPublicArgumentsFailBeforeDelegation(): void
    {
        [$exit, , $stderr] = $this->runDelegate([
            '--label=evidence-impact',
            '--delegate=artifacts/test-tmp/absent-export',
            '--required-sha=BASE_SHA=short',
            '--',
        ]);

        self::assertSame(64, $exit);
        self::assertStringContainsString('full lowercase 40-hex BASE_SHA', $stderr);
    }

    /**
     * @param list<string> $arguments
     * @return array{int,string,string}
     */
    private function runDelegate(array $arguments): array
    {
        $command = array_merge([
            PHP_BINARY,
            $this->root . '/sandbox/catalog/fragments/engineering-platform/delegate-check.php',
        ], $arguments);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        return [$exit, $stdout, $stderr];
    }
}
