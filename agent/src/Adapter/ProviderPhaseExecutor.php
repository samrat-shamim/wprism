<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Providers::class, false)) {
    require_once __DIR__ . '/Providers.php';
}

/** Shared negotiation and verified invocation loop for provider-only phases. */
final class ProviderPhaseExecutor {
    /** @param list<array<string,mixed>> $actions */
    public static function assert_ready(Policy $policy, array $actions, string $label): void {
        self::negotiated($policy, $actions, $label);
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @param callable():void $heartbeat
     * @return list<array<string,mixed>>
     */
    public static function run(
        Policy $policy,
        array $actions,
        string $label,
        callable $heartbeat
    ): array {
        $negotiated = self::negotiated($policy, $actions, $label);
        $receipts = [];
        foreach ($actions as $action) {
            $providerId = (string) $action['provider'];
            $capability = (string) $action['capability'];
            $provider = $negotiated['providers'][$providerId] ?? null;
            $declaration = $negotiated['capabilities'][$providerId][$capability] ?? null;
            if (!is_object($provider) || !is_array($declaration)) {
                throw new \RuntimeException(
                    "wprism: $label lost negotiated provider '$providerId' capability '$capability'"
                );
            }
            $heartbeat();
            $receipts[] = [
                'capability' => $capability,
                'manifest' => (string) $action['manifest'],
                'provider' => $providerId,
                'receipt' => Providers::invoke($provider, $action, $declaration, []),
            ];
        }
        return $receipts;
    }

    /** @param list<array<string,mixed>> $actions @return array<string,mixed> */
    private static function negotiated(Policy $policy, array $actions, string $label): array {
        $negotiated = Providers::negotiate($policy, $actions);
        if ($negotiated['problems'] !== []) {
            $codes = [];
            foreach ($negotiated['problems'] as $problem) {
                $codes[] = (string) ($problem['code'] ?? 'provider_unavailable')
                    . ':' . (string) ($problem['provider'] ?? '?');
            }
            throw new \RuntimeException(
                "wprism: $label provider negotiation failed: " . implode(', ', $codes)
            );
        }
        return $negotiated;
    }
}
