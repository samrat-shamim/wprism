<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Hygiene;
use PHPUnit\Framework\TestCase;

final class HygieneTest extends TestCase
{
    public function testCredentialMaterialAndGeneratedArtifactsAreRejected(): void
    {
        $token = 'gh' . 'p_' . str_repeat('a', 36);
        self::assertStringContainsString('GitHub', Hygiene::sensitiveMaterialViolation($token) ?? '');
        self::assertStringContainsString('artifacts', Hygiene::trackedPathViolation('artifacts/dist/release.tar') ?? '');
        self::assertStringContainsString('credential', Hygiene::trackedPathViolation('config/production.pem') ?? '');
    }

    public function testSecretReferencesAndPinnedActionsRemainPermitted(): void
    {
        self::assertNull(Hygiene::sensitiveMaterialViolation('${{ secrets.RELEASE_TOKEN }}'));
        self::assertNull(Hygiene::trackedPathViolation('.env.example'));
        self::assertNull(Hygiene::workflowActionViolation(
            'uses: actions/checkout@' . str_repeat('a', 40) . " # v4\n",
        ));
        self::assertStringContainsString(
            'full commit SHA',
            Hygiene::workflowActionViolation("uses: actions/checkout@v4\n") ?? '',
        );
    }
}
