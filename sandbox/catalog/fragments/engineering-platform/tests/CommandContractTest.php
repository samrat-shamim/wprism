<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\CommandContract;
use PHPUnit\Framework\TestCase;

final class CommandContractTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removeTree($root);
        }
    }

    public function testRepositoryContractIsCompleteAndCanonical(): void
    {
        $contract = new CommandContract($this->root);
        $document = $contract->load();

        self::assertSame('duo-developer-commands/v1', $document['format']);
        self::assertCount(33, $document['commands']);
        $names = array_column($document['commands'], 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
        self::assertStringEndsWith("\n", $contract->canonical());
    }

    public function testUnknownFieldFailsClosed(): void
    {
        $root = $this->fixtureRoot();
        $path = $root . '/sandbox/catalog/fragments/engineering-platform/developer-commands.json';
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $commands = is_array($document) ? ($document['commands'] ?? null) : null;
        $first = is_array($commands) ? ($commands[0] ?? null) : null;
        if (!is_array($document) || !is_array($commands) || !is_array($first)) {
            throw new \LogicException('repository command fixture has an unexpected shape');
        }
        $first['surprise'] = true;
        $commands[0] = $first;
        $document['commands'] = $commands;
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('unknown or missing fields');
        (new CommandContract($root))->load();
    }

    public function testMissingMakeTargetFailsClosed(): void
    {
        $root = $this->fixtureRoot();
        $makefile = (string) file_get_contents($root . '/Makefile');
        $makefile = preg_replace('/^audit:\n\t.*\n/m', '', $makefile);
        self::assertIsString($makefile);
        file_put_contents($root . '/Makefile', $makefile);

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('developer command has no Make target: audit');
        (new CommandContract($root))->load();
    }

    private function fixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/duo-command-contract-' . bin2hex(random_bytes(6));
        $this->temporaryRoots[] = $root;
        $directory = $root . '/sandbox/catalog/fragments/engineering-platform';
        self::assertTrue(mkdir($directory, 0700, true));
        self::assertTrue(copy(
            $this->root . '/sandbox/catalog/fragments/engineering-platform/developer-commands.json',
            $directory . '/developer-commands.json',
        ));
        self::assertTrue(copy($this->root . '/Makefile', $root . '/Makefile'));
        return $root;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        }
        rmdir($path);
    }
}
