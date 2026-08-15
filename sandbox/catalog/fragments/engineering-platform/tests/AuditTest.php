<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Audit;
use PHPUnit\Framework\TestCase;

final class AuditTest extends TestCase
{
    public function testCleanProviderResponseIsClean(): void
    {
        $result = Audit::classify(0, '{"advisories":{},"abandoned":{}}', '');

        self::assertSame('clean', $result['state']);
        self::assertSame([], $result['findings']);
        self::assertSame([], $result['abandoned_packages']);
    }

    public function testAdvisoriesAndAbandonmentArePolicyFailure(): void
    {
        $result = Audit::classify(3, json_encode([
            'advisories' => [
                'vendor/package' => [
                    ['advisoryId' => 'PKSA-example'],
                    ['cve' => 'CVE-2099-0001'],
                ],
            ],
            'abandoned' => ['old/package' => 'new/package'],
        ], JSON_THROW_ON_ERROR), '');

        self::assertSame('policy_failure', $result['state']);
        self::assertSame([
            ['package' => 'vendor/package', 'advisory_id' => 'CVE-2099-0001'],
            ['package' => 'vendor/package', 'advisory_id' => 'PKSA-example'],
        ], $result['findings']);
        self::assertSame(['old/package'], $result['abandoned_packages']);
    }

    public function testNetworkOrMalformedResponseIsUnavailableNotClean(): void
    {
        $result = Audit::classify(2, '', 'curl error: token=secret-value at /private/path');

        self::assertSame('unavailable', $result['state']);
        self::assertStringContainsString('token=<redacted>', $result['message']);
        self::assertStringNotContainsString('secret-value', $result['message']);
        self::assertStringNotContainsString('/private/path', $result['message']);
    }

    public function testControlledDependencyUpdatesArePolicyFailure(): void
    {
        $result = Audit::classifyUpdates(0, json_encode([
            'locked' => [
                ['name' => 'vendor/tool', 'version' => '1.0.0', 'latest' => '1.1.0'],
            ],
        ], JSON_THROW_ON_ERROR), '');

        self::assertSame('policy_failure', $result['state']);
        self::assertSame([
            ['name' => 'vendor/tool', 'current' => '1.0.0', 'latest' => '1.1.0'],
        ], $result['updates']);
        self::assertSame('unavailable', Audit::combinedState(['clean', 'unavailable', 'policy_failure']));
    }

    public function testCurrentDependencyResponseIsClean(): void
    {
        self::assertSame('clean', Audit::classifyUpdates(0, '{"locked":[]}', '')['state']);
        self::assertSame('unavailable', Audit::classifyUpdates(2, '', 'network error')['state']);
    }
}
