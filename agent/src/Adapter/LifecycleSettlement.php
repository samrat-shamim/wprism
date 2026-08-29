<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Providers::class, false)) {
    require_once __DIR__ . '/Providers.php';
}
if (!class_exists(RepositoryCompiler::class, false)) {
    require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
}
if (!class_exists(PromotionLock::class, false)) {
    require_once __DIR__ . '/../Promotion/PromotionLock.php';
}

/** Adapter-owned completion gate for asynchronous plugin upgrade work. */
final class LifecycleSettlement {
    /** @return array{format:string,actions:int,receipts:list<array<string,mixed>>} */
    public static function run(
        string $repo,
        string $artifactPath,
        string $artifactHash,
        string $promotionOwner
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
        PromotionLock::acquire($promotionOwner, $artifactHash, 'lifecycle-settle', null, true);
        try {
            PromotionLock::assert_lifecycle_complete($promotionOwner, $artifactHash);
            $actions = $policy->lifecycle_settle_actions();
            if ($actions === []) {
                PromotionLock::heartbeat($promotionOwner, $artifactHash, 'lifecycle-settled');
                return ['format' => 'wprism-lifecycle-settlement/v1', 'actions' => 0, 'receipts' => []];
            }

            $negotiated = Providers::negotiate($policy, $actions);
            if ($negotiated['problems'] !== []) {
                $codes = [];
                foreach ($negotiated['problems'] as $problem) {
                    $codes[] = (string) ($problem['code'] ?? 'provider_unavailable')
                        . ':' . (string) ($problem['provider'] ?? '?');
                }
                throw new \RuntimeException(
                    'wprism: lifecycle settlement provider negotiation failed: ' . implode(', ', $codes)
                );
            }

            $receipts = [];
            foreach ($actions as $action) {
                $providerId = (string) $action['provider'];
                $capability = (string) $action['capability'];
                $provider = $negotiated['providers'][$providerId] ?? null;
                $declaration = $negotiated['capabilities'][$providerId][$capability] ?? null;
                if (!is_object($provider) || !is_array($declaration)) {
                    throw new \RuntimeException(
                        "wprism: lifecycle settlement lost negotiated provider '$providerId' capability '$capability'"
                    );
                }
                PromotionLock::heartbeat($promotionOwner, $artifactHash, 'lifecycle-settle-provider');
                $receipts[] = [
                    'capability' => $capability,
                    'manifest' => (string) $action['manifest'],
                    'provider' => $providerId,
                    'receipt' => Providers::invoke($provider, $action, $declaration, []),
                ];
            }
            PromotionLock::heartbeat($promotionOwner, $artifactHash, 'lifecycle-settled');

            return [
                'format' => 'wprism-lifecycle-settlement/v1',
                'actions' => count($actions),
                'receipts' => $receipts,
            ];
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
}
