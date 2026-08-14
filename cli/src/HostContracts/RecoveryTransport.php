<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/TargetInvocation.php';

/** SSH-independent transport surface used by recovery orchestration. */
interface RecoveryTransport extends TargetInvocation {
    public function rollbackConfigured(): bool;
    public function rollbackKeyId(): ?string;
    public function rollbackSigningKeyPath(): ?string;
    public function recoveryConfigured(): bool;
    public function checkpointConfigured(): bool;
    public function codeReleaseConfigured(): bool;
    public function uploadProviderConfigured(): bool;
    public function effectProviderConfigured(): bool;
    public function verifiedRollbackConfigured(): bool;

    /** @return ?array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} */
    public function verifiedRollbackConfig(): ?array;

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function uploadFile(string $localPath, string $remotePath): array;
}
