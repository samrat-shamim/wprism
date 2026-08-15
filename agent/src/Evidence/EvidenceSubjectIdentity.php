<?php
declare(strict_types=1);

namespace Duo;

require_once dirname(__DIR__) . '/CapabilityRegistry.php';

/** Pure subject identity calculator shared by evidence and claim projection. */
final class EvidenceSubjectIdentity {
    public static function digest(
        string $kind,
        string $name,
        array $manifest,
        array $claim,
        string $manifestDir
    ): string {
        return CapabilityRegistry::subject_digest($kind, $name, $manifest, $claim, $manifestDir);
    }
}
