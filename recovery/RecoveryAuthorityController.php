<?php
declare(strict_types=1);

namespace Duo\Recovery;

require_once __DIR__ . '/RecoveryTransitionPolicy.php';

/**
 * Public recovery authority facade.
 *
 * The standalone runtime exposes this controller as its only authority/state
 * contract. Resource bundles use their own exported strategy classes and do
 * not need to know how the durable target, receipt, or event journal is
 * stored. The compatibility RollbackControl name remains available for
 * archived v1 callers.
 */
final class RecoveryAuthorityController {
    public static function initialize(string $root, ?string $targetId = null): array {
        return RollbackControl::initialize($root, $targetId);
    }
    public static function installPublicKey(string $root, string $keyId, string $publicKeyBase64): void {
        RollbackControl::installPublicKey($root, $keyId, $publicKeyBase64);
    }
    public static function handleRequest(string $root, string $requestPath): array {
        return RollbackControl::handleRequest($root, $requestPath);
    }
    public static function status(string $root): array { return RollbackControl::status($root); }
    public static function inspectReadOnly(string $root): array { return RollbackControl::inspectReadOnly($root); }
    public static function activeEvidence(string $root): array { return RollbackControl::activeEvidence($root); }
    public static function auditEvidence(string $root): array { return RollbackControl::auditEvidence($root); }
    public static function canonical(array $value): string { return RollbackControl::canonical($value); }
    public static function sign(array $payload, string $keyId, string $secretKey): array {
        return RollbackControl::sign($payload, $keyId, $secretKey);
    }
    public static function transitionAllowed(string $from, string $to, string $profile = 'ordinary'): bool {
        return RecoveryTransitionPolicy::allows($from, $to, $profile);
    }
}

if (!class_exists(RollbackControl::class, false)) {
    require_once __DIR__ . '/rollback-control.php';
}
