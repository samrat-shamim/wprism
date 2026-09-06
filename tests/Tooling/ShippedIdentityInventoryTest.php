<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/** Byte-currency and architectural-boundary tests for the shipped-name projection. */
final class ShippedIdentityInventoryTest extends TestCase
{
    private static function repoRoot(): string
    {
        $configured = getenv('WPRISM_REPO_ROOT');
        return is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        require_once self::repoRoot() . '/tools/shipped-identity-inventory.php';
        require_once self::repoRoot() . '/agent/src/Adapter/ShippedIdentityInventory.php';
    }

    public function testCommittedInventoryIsByteIdenticalToTheSourceTree(): void
    {
        $root = self::repoRoot();
        $expected = \sii_render(\sii_adapter_names($root));
        self::assertSame(
            $expected,
            (string) file_get_contents($root . '/' . \SII_OUTPUT),
            'regenerate with: php tools/shipped-identity-inventory.php'
        );
    }

    public function testRuntimeConstantIsTheSortedLibraryMembership(): void
    {
        $names = \sii_adapter_names(self::repoRoot());
        self::assertSame($names, \WPrism\ShippedIdentityInventory::ADAPTER_NAMES);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
        self::assertSame($names, array_values(array_unique($names)));
    }

    public function testGeneratorDoesNotProjectThePermanentIdKindFloor(): void
    {
        $source = (string) file_get_contents(self::repoRoot() . '/tools/shipped-identity-inventory.php');
        self::assertStringContainsString('`id_kind` grandfather floor deliberately remains hand-reviewed', $source);
        self::assertSame(
            ['ADAPTER_NAMES'],
            array_keys((new \ReflectionClass(\WPrism\ShippedIdentityInventory::class))->getConstants())
        );
    }

    public function testCheckModeAndReleaseGateUseTheSameGenerator(): void
    {
        $root = self::repoRoot();
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($root . '/tools/shipped-identity-inventory.php') . ' --check';
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertStringContainsString('ok    agent/src/Adapter/ShippedIdentityInventory.php', $stdout);

        $makefile = (string) file_get_contents($root . '/Makefile');
        self::assertStringContainsString("\tphp tools/shipped-identity-inventory.php --check\n", $makefile);
    }
}
