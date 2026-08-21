<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ImmutableOciReference;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/ImmutableOciReference.php';

#[CoversNothing]
final class ImmutableOciReferenceTest extends TestCase {
    private const DIGEST = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testAcceptsCanonicalRepositoryDigestsIncludingBoundedRegistryPorts(): void {
        foreach ([
            'registry@' . self::DIGEST,
            'library/wordpress@' . self::DIGEST,
            'registry.example.test/duo/wordpress@' . self::DIGEST,
            '127.0.0.1:1/duo-cloud-preview-proof@' . self::DIGEST,
            'localhost:65535/namespace/image:proof-1@' . self::DIGEST,
        ] as $reference) {
            self::assertTrue(ImmutableOciReference::valid($reference), $reference);
            self::assertSame(self::DIGEST, ImmutableOciReference::digest($reference));
        }
    }

    public function testRejectsNoncanonicalOrUnboundedAuthoritiesNamesTagsAndDigests(): void {
        foreach ([
            '127.0.0.1:0/repository@' . self::DIGEST,
            '127.0.0.1:01/repository@' . self::DIGEST,
            '127.0.0.1:65536/repository@' . self::DIGEST,
            '127.0.0.1:123456/repository@' . self::DIGEST,
            '127.0.0.1:5000/@' . self::DIGEST,
            'user@registry.example.test/repository@' . self::DIGEST,
            'Registry.example.test/repository@' . self::DIGEST,
            'registry.example.test//repository@' . self::DIGEST,
            'registry.example.test/../repository@' . self::DIGEST,
            'registry.example.test/repository:@' . self::DIGEST,
            'registry.example.test/repository:UPPER@' . self::DIGEST,
            'registry.example.test/repository@sha256:' . str_repeat('B', 64),
            'registry.example.test/repository@sha512:' . str_repeat('b', 64),
            'registry.example.test/repository@' . self::DIGEST . '?query=1',
        ] as $reference) {
            self::assertFalse(ImmutableOciReference::valid($reference), $reference);
        }
    }
}
