<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Providers::class, false)) {
    require_once __DIR__ . '/Providers.php';
}
if (!class_exists(ProviderPhaseExecutor::class, false)) {
    require_once __DIR__ . '/ProviderPhaseExecutor.php';
}
if (!class_exists(RepositoryCompiler::class, false)) {
    require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
}
if (!class_exists(PromotionLock::class, false)) {
    require_once __DIR__ . '/../Promotion/PromotionLock.php';
}
if (!class_exists(RetainedCheckpointCipher::class, false)) {
    require_once __DIR__ . '/../Recovery/RetainedCheckpointCipher.php';
}
if (!class_exists(DatabaseTargetIdentity::class, false)) {
    require_once __DIR__ . '/../Recovery/DatabaseTargetIdentity.php';
}
if (!class_exists(ProviderSettlementIntent::class, false)) {
    require_once __DIR__ . '/../Kernel/ProviderSettlementIntent.php';
}
if (!class_exists(StoragePrerequisites::class, false)) {
    require_once __DIR__ . '/../Kernel/StoragePrerequisites.php';
}
if (!class_exists(StoragePrerequisiteSettlement::class, false)) {
    require_once __DIR__ . '/../Kernel/StoragePrerequisiteSettlement.php';
}

/** Adapter-owned completion gate for asynchronous plugin upgrade and storage work. */
final class LifecycleSettlement {
    public static function assert_ready(Policy $policy): void {
        ProviderPhaseExecutor::assert_ready(
            $policy,
            $policy->lifecycle_settle_actions(),
            'lifecycle settlement'
        );
    }

    /** @return array{format:string,actions:int,receipts:list<array<string,mixed>>} */
    public static function run(
        string $repo,
        string $artifactPath,
        string $artifactHash,
        string $promotionOwner,
        string $checkpointPath,
        bool $releaseOnSuccess = false,
        bool $storagePrerequisitesOnly = false
    ): array {
        $phase = $storagePrerequisitesOnly ? 'storage-prerequisite-settle' : 'lifecycle-settle';
        if ($checkpointPath !== '') {
            return ProviderSettlementIntent::with_phase(
                $repo,
                $artifactPath,
                $checkpointPath,
                $promotionOwner,
                $artifactHash,
                $phase,
                static fn(array $providerIntent): array => self::run_continued(
                    $repo,
                    $artifactPath,
                    $artifactHash,
                    $promotionOwner,
                    $checkpointPath,
                    $releaseOnSuccess,
                    $storagePrerequisitesOnly,
                    (string) ($providerIntent['checkpoint']['cipher_sha256'] ?? '')
                )
            );
        }
        return self::run_continued(
            $repo,
            $artifactPath,
            $artifactHash,
            $promotionOwner,
            '',
            $releaseOnSuccess,
            $storagePrerequisitesOnly,
            ''
        );
    }

    /** @return array{format:string,actions:int,receipts:list<array<string,mixed>>} */
    private static function run_continued(
        string $repo,
        string $artifactPath,
        string $artifactHash,
        string $promotionOwner,
        string $checkpointPath,
        bool $releaseOnSuccess,
        bool $storagePrerequisitesOnly,
        string $expectedCipherSha256
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            throw new \InvalidArgumentException('wprism: lifecycle-settle requires a valid artifact hash');
        }
        if ($promotionOwner === '') {
            throw new \InvalidArgumentException('wprism: lifecycle-settle requires the host promotion owner');
        }
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::read_artifact($artifactPath, $policy);
        if (!hash_equals($artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException('wprism: lifecycle-settle artifact does not match the host-compiled artifact hash');
        }
        $actions = $storagePrerequisitesOnly
            ? StoragePrerequisiteSettlement::actions_for_readiness(
                $policy->manifests,
                StoragePrerequisites::readiness($policy->manifests)
            )
            : $policy->lifecycle_settle_actions();
        if ($actions !== []) {
            if ($checkpointPath === '') {
                throw new \RuntimeException(
                    'wprism: lifecycle-settle requires a host-authenticated provider settlement checkpoint'
                );
            }
            $checkpoint = RetainedCheckpointCipher::verify($repo, $checkpointPath);
            DatabaseTargetIdentity::assertWordPressConfig(
                (string) ($checkpoint['database_target_sha256'] ?? '')
            );
            if (!hash_equals($expectedCipherSha256, (string) ($checkpoint['cipher_sha256'] ?? ''))) {
                throw new \RuntimeException(
                    'wprism: lifecycle-settle checkpoint ciphertext changed after provider settlement authorization'
                );
            }
        }
        PromotionLock::acquire($promotionOwner, $artifactHash, 'lifecycle-settle', null, true);
        try {
            PromotionLock::assert_lifecycle_complete($promotionOwner, $artifactHash);
            $summary = self::run_selected_locked($policy, $promotionOwner, $artifactHash, $actions);
            if ($releaseOnSuccess) {
                PromotionLock::release($promotionOwner, $artifactHash);
            }
            return $summary;
        } catch (\Throwable $failure) {
            try {
                PromotionLock::release($promotionOwner, $artifactHash);
            } catch (\Throwable $_releaseFailure) {
                // Preserve the provider refusal; the bounded lease and host
                // recovery path remain authoritative if release also fails.
            }
            throw $failure;
        }
    }

    /**
     * Direct-deploy execution under its continuously held exact promotion
     * session. The caller has already completed lifecycle reconciliation and
     * verified the locked policy/artifact pair.
     *
     * @return array{format:string,actions:int,receipts:list<array<string,mixed>>}
     */
    public static function run_locked(
        Policy $policy,
        string $promotionOwner,
        string $artifactHash
    ): array {
        return self::run_selected_locked(
            $policy,
            $promotionOwner,
            $artifactHash,
            $policy->lifecycle_settle_actions()
        );
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @return array{format:string,actions:int,receipts:list<array<string,mixed>>}
     */
    private static function run_selected_locked(
        Policy $policy,
        string $promotionOwner,
        string $artifactHash,
        array $actions
    ): array {
        PromotionLock::assert_no_lifecycle_attempt($promotionOwner, $artifactHash, 'lifecycle-settle');
        if ($actions === []) {
            PromotionLock::heartbeat($promotionOwner, $artifactHash, 'lifecycle-settled');
            return ['format' => 'wprism-lifecycle-settlement/v1', 'actions' => 0, 'receipts' => []];
        }

        $receipts = ProviderPhaseExecutor::run(
            $policy,
            $actions,
            'lifecycle settlement',
            static fn(): mixed => PromotionLock::heartbeat(
                $promotionOwner,
                $artifactHash,
                'lifecycle-settle-provider'
            )
        );
        PromotionLock::heartbeat($promotionOwner, $artifactHash, 'lifecycle-settled');

        return [
            'format' => 'wprism-lifecycle-settlement/v1',
            'actions' => count($actions),
            'receipts' => $receipts,
        ];
    }
}
