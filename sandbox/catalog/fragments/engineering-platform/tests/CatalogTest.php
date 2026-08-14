<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testCheckedInPlatformFragmentIsValidAndNonempty(): void
    {
        $root = dirname(__DIR__, 5);
        $catalog = (new Catalog($root))->validate('thread-1', false);

        self::assertNotEmpty($catalog['suites']);
        self::assertNotEmpty($catalog['inventory']);
        self::assertNotEmpty($catalog['profiles']);
    }

    public function testUnknownOwnerFailsClosed(): void
    {
        $root = dirname(__DIR__, 5);

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('unknown owner');
        (new Catalog($root))->validate('thread-99', false);
    }
}
