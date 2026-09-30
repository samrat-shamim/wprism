<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** A moved reference must fail at the gate even when its entry guide still links correctly. */
final class DocumentationCheckTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/wprism-doc-check-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->scratch, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->scratch);
    }

    public function testMovedReferenceAnchorsAndQuotedPathsRemainNavigable(): void
    {
        $this->fixture('README.md', <<<'MD'
# Welcome
[Second heading](docs/reference/commands.md#repeat-1)
[Code heading](docs/reference/commands.md#wp-wprism-status)
[Unicode](docs/reference/commands.md#caf%C3%A9)
[Explicit](docs/reference/commands.md#stable-id)
[Space](<docs/reference/with space.md>)
[Source](tools/example.php#L12-L14)
[Directory](docs/reference#reference)
MD);
        $this->fixture('docs/reference/commands.md', <<<'MD'
# Repeat
## Repeat
## `wp wprism status`
## Café
<a id="stable-id"></a>
MD);
        $this->fixture('docs/reference/with space.md', '# Space');
        $this->fixture('docs/reference/README.md', '# Reference');
        $this->fixture('tools/example.php', '<?php');

        $result = $this->runChecker();
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertStringContainsString('7 local links', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testBrokenLinksInReferencesNameTheFileAndLine(): void
    {
        $this->fixture('README.md', '[Reference](docs/reference/commands.md)');
        $this->fixture('docs/reference/commands.md', "# Commands\n[Missing](gone.md)\n[Section](#removed)\n");

        $result = $this->runChecker();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('docs/reference/commands.md:2: missing path: gone.md', $result['stderr']);
        self::assertStringContainsString('docs/reference/commands.md:3: missing anchor: #removed', $result['stderr']);
        self::assertStringContainsString('2 failures', $result['stdout']);
    }

    public function testExamplesAndHistoricalSourceDoNotMasqueradeAsCurrentLinks(): void
    {
        $this->fixture('README.md', <<<'MD'
# Current
`[Inline example](absent.md)`
```md
[Fenced example](absent.md)
# Imaginary heading
```
~~~md
[Other fence](absent.md)
~~~
[History](docs/history/README.md)
[External](https://example.invalid/no-network-request)
MD);
        $this->fixture('docs/history/README.md', '[Old review](old-review.md)');
        $this->fixture('docs/history/old-review.md', '[Obsolete path](removed-in-later-version.md)');

        $result = $this->runChecker();
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertStringContainsString('2 local links across 2 current documents', $result['stdout']);
    }

    public function testReferenceDefinitionsAndRepositoryEscapesAreChecked(): void
    {
        $this->fixture('README.md', "# Current\n[link]: docs/missing.md\n[Escape](../outside.md)\n[Fake heading](#example)\n```md\n# Example\n```\n");

        $result = $this->runChecker();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('README.md:2: missing path: docs/missing.md', $result['stderr']);
        self::assertStringContainsString('README.md:3: link escapes repository: ../outside.md', $result['stderr']);
        self::assertStringContainsString('README.md:4: missing anchor: #example', $result['stderr']);
        self::assertStringContainsString('3 failures', $result['stdout']);
    }

    private function fixture(string $relative, string $bytes): void
    {
        $path = $this->scratch . '/' . $relative;
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0700, true));
        }
        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    /** @return array{status: int, stdout: string, stderr: string} */
    private function runChecker(): array
    {
        $process = proc_open(
            ['python3', dirname(__DIR__, 2) . '/tools/check-doc-links.py', '--root', $this->scratch],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->scratch
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
