<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Build;
use Duo\EngineeringPlatform\CatalogException;
use PHPUnit\Framework\TestCase;

final class BuildTest extends TestCase
{
    private string $root;
    private string $output;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $this->output = sys_get_temp_dir() . '/duo-build-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->output);
    }

    public function testBuildIsDeterministicAndVerifiesOutsideTheCheckout(): void
    {
        $builder = new Build($this->root);
        $first = $builder->build($this->output, false);
        $firstDigest = hash_file('sha256', $this->output . '/archives/agent.tar');
        self::assertIsString($firstDigest);
        $second = $builder->build($this->output, false);

        self::assertSame($first, $second);
        self::assertSame($firstDigest, hash_file('sha256', $this->output . '/archives/agent.tar'));
        self::assertSame('pass', $builder->verify($this->output, false)['state']);
        self::assertFileExists($this->output . '/host/cli/duo');
        self::assertFileDoesNotExist($this->output . '/payload/declarations/contracts/commands/generated/developer-commands.json');
    }

    public function testVerificationRejectsMutatedPayloadBytes(): void
    {
        $builder = new Build($this->root);
        $builder->build($this->output, false);
        file_put_contents($this->output . '/payload/recovery/CanonicalJson.php', "\n", FILE_APPEND);

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('file bytes or mode disagree');
        $builder->verify($this->output, false);
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
