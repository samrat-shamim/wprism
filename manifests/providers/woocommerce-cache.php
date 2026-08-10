<?php
namespace Duo\Providers;

use Duo\Policy;

/**
 * WooCommerce 11.x blanket cache-invalidation provider.
 *
 * Duo writes Woo's shipping, tax, and attribute rows with SQL, deliberately
 * bypassing the save hooks Woo normally uses to invalidate the caches those
 * rows feed. This provider is the manifest-owned boundary for that repair.
 * It is the DUO-3338 port of the `wp eval` payload manifests/woocommerce.json
 * previously carried in the retired `rebuilders` channel: the same public
 * WooCommerce API calls, the same guards, and the same three-condition
 * shipping-version persistence proof, now behind an identity/receipt contract
 * with the group list arriving as structured data instead of being spliced
 * into a command string.
 *
 * Everything here is WooCommerce's own public API. The provider adds no cache
 * semantics of its own: it does not decide which groups exist (the manifest
 * action's `args.groups` does), and it does not reimplement invalidation
 * (WC_Cache_Helper does).
 */
final class WoocommerceCache {
    private Policy $policy;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-cache',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * `reads`/`writes` are the capability-level summary in the canonical
     * surface vocabulary; the exact restorable/irreversible boundary of what
     * one invocation touches is carried more precisely by the declaring
     * action's own `effects` list (the transient option rows, the three cache
     * namespaces, and every transient hook Woo fires), which is what recovery
     * reconciles against.
     *
     * 60 seconds is generous for what this is: a bounded number of cache-group
     * version bumps plus one transient read, all of them single-row option
     * writes. An invocation approaching that budget means something other than
     * cache invalidation is happening.
     */
    public function capabilities(): array {
        return [
            'invalidate_cache_groups' => [
                'args' => [
                    'groups' => ['type' => 'list<string>', 'required' => true],
                ],
                'reads' => ['entity:woocommerce-shipping-transient-version'],
                'writes' => [
                    'entity:woocommerce-cache-groups',
                    'entity:woocommerce-shipping-transient-version',
                ],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 60,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        return match ($capability) {
            'invalidate_cache_groups' => $this->invalidate_cache_groups(
                array_map('strval', (array) ($args['groups'] ?? []))
            ),
            default => throw new \RuntimeException(
                "duo: WooCommerce cache provider does not implement capability '$capability'"
            ),
        };
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $this->scoped_postcondition(
                array_map('strval', (array) ($args['groups'] ?? []))
            ),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== 'invalidate_cache_groups') {
            throw new \RuntimeException(
                "duo: WooCommerce cache provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => $this->scoped_postcondition(
                array_map('strval', (array) ($args['groups'] ?? []))
            ),
            'verified' => true,
        ];
    }

    /** @param list<string> $groups @return array{groups:list<string>,shipping_transient_version:?string} */
    private function scoped_postcondition(array $groups): array {
        if ($groups === []) {
            throw new \RuntimeException(
                'duo: WooCommerce cache reconciliation was asked for an empty group list; recovery_required'
            );
        }
        return [
            'groups' => $groups,
            'shipping_transient_version' => $this->observe_shipping_version(),
        ];
    }

    /**
     * Bump each named cache group's version, then prove the shipping transient
     * version survived a genuinely fresh read.
     *
     * The fresh-read proof is the load-bearing part and is ported unchanged
     * from the eval payload: WC_Cache_Helper::get_transient_version('shipping',
     * true) refreshes and returns the new version, and a second call with
     * $refresh = false must return that same non-empty string. A differing or
     * empty second read means the version did not persist — the object cache
     * or a filter swallowed the write — which is exactly the silent failure a
     * bare "the call returned" check would pass.
     *
     * @param list<string> $groups
     * @return array{before:array, after:array, verified:true}
     */
    private function invalidate_cache_groups(array $groups): array {
        if (!class_exists('WC_Cache_Helper')
            || !is_callable(['WC_Cache_Helper', 'invalidate_cache_group'])
            || !is_callable(['WC_Cache_Helper', 'get_transient_version'])) {
            throw new \RuntimeException('duo: WooCommerce 11.x cache invalidation API is unavailable');
        }
        if ($groups === []) {
            throw new \RuntimeException(
                'duo: WooCommerce cache invalidation was asked for an empty group list; '
                . "declare the exact groups in the manifest action's args.groups"
            );
        }

        $before = [
            'groups' => $groups,
            'shipping_transient_version' => $this->observe_shipping_version(),
        ];
        foreach ($groups as $group) {
            if (!\WC_Cache_Helper::invalidate_cache_group($group)) {
                throw new \RuntimeException("duo: WooCommerce cache invalidation failed for $group");
            }
        }

        $shippingVersion = \WC_Cache_Helper::get_transient_version('shipping', true);
        $freshShippingVersion = \WC_Cache_Helper::get_transient_version('shipping', false);
        if (!is_string($shippingVersion) || $shippingVersion === ''
            || $freshShippingVersion !== $shippingVersion) {
            throw new \RuntimeException(
                'duo: WooCommerce shipping transient version did not persist across a fresh read'
            );
        }

        return [
            'before' => $before,
            'after' => [
                'groups' => $groups,
                'shipping_transient_version' => $shippingVersion,
                'shipping_transient_version_fresh_read' => $freshShippingVersion,
            ],
            'verified' => true,
        ];
    }

    /**
     * The before-image of the shipping transient version, read through the
     * WordPress transient API rather than WC_Cache_Helper::get_transient_
     * version(). That is deliberate and load-bearing: Woo's accessor MINTS and
     * stores a version when none exists, even with $refresh = false, so using
     * it to observe the prior state would make the before-image a write. The
     * transient name is the same one manifests/woocommerce.json already
     * declares as a restorable effect. A null means Woo had no version yet.
     */
    private function observe_shipping_version(): ?string {
        if (!function_exists('get_transient')) {
            throw new \RuntimeException(
                'duo: WooCommerce cache invalidation requires the WordPress transient API'
            );
        }
        $version = get_transient('shipping-transient-version');
        return is_string($version) && $version !== '' ? $version : null;
    }
}
