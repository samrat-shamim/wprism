<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\OperatorDiagnostics;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/OperatorDiagnostics.php';

#[CoversNothing]
final class OperatorDiagnosticsTest extends TestCase {
    public function testPrivateDiagnosticScreensValuesAndKeepsStableReason(): void {
        $lines = [];
        $diagnostics = new OperatorDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $secret = 'sentinel-private-token-' . bin2hex(random_bytes(8));
        $first = $diagnostics->record('cloud-http-startup', new \RuntimeException($secret));
        $second = $diagnostics->record('cloud-http-startup', new \RuntimeException($secret));

        self::assertCount(2, $lines);
        self::assertStringNotContainsString($secret, $lines[0]);
        $one = CanonicalJson::decodeObject($lines[0]);
        $two = CanonicalJson::decodeObject($lines[1]);
        self::assertSame(OperatorDiagnostics::FORMAT, $one['format']);
        self::assertSame('cloud-http-startup', $one['boundary']);
        self::assertSame($first, $one['correlation_id']);
        self::assertSame($second, $two['correlation_id']);
        self::assertNotSame($first, $second);
        self::assertSame($one['error_class_sha256'], $two['error_class_sha256']);
        self::assertSame($one['reason_sha256'], $two['reason_sha256']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $first);
    }
}
