<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Build;
use Duo\EngineeringPlatform\Loader;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase
{
    private string $root;
    private string $dist;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $this->dist = sys_get_temp_dir() . '/duo-loader-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testSourceAndDistLoaderMatricesAgree(): void
    {
        (new Build($this->root))->build($this->dist, false);
        $receipt = (new Loader($this->root))->check($this->dist);

        self::assertSame('pass', $receipt['state']);
        $layouts = $receipt['layouts'] ?? null;
        self::assertIsArray($layouts);
        $source = $layouts['source'] ?? null;
        $dist = $layouts['dist'] ?? null;
        self::assertIsArray($source);
        self::assertIsArray($dist);
        $sourceNormal = $source['normal'] ?? null;
        $distNormal = $dist['normal'] ?? null;
        $sourceJournal = $source['journal'] ?? null;
        $distJournal = $dist['journal'] ?? null;
        $distCli = $dist['wp_cli'] ?? null;
        $source80 = $source['php_8_0_guard_emulation'] ?? null;
        self::assertIsArray($sourceNormal);
        self::assertIsArray($distNormal);
        self::assertIsArray($sourceJournal);
        self::assertIsArray($distJournal);
        self::assertIsArray($distCli);
        self::assertIsArray($source80);
        self::assertSame(
            $sourceNormal,
            $distNormal,
        );
        self::assertSame(
            $sourceJournal,
            $distJournal,
        );
        self::assertSame('duo', $distCli['command'] ?? null);
        self::assertSame('unsupported_php_8_0', $source80['status'] ?? null);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
