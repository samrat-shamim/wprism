<?php
namespace Duo\Providers;

use Duo\Policy;

/**
 * Ninja Forms 3.x form-cache rebuild provider.
 *
 * Duo writes nf3_forms/nf3_fields/nf3_actions rows with SQL, so Ninja Forms'
 * own save path — which is what normally refreshes the per-form cache row in
 * nf3_upgrades — never runs. This provider is the DUO-3338 port of the
 * `wp eval` payload manifests/ninja-forms.json previously carried: the same
 * loop over Ninja_Forms()->form()->get_forms() calling
 * WPN_Helper::build_nf_cache() per form id, with the plugin's own public
 * helpers still owning what "build the cache" means.
 *
 * What is new is the readback. The retired channel could only report that a
 * wp-cli process exited 0; DUO-3267's grind round had to infer whether the
 * rebuild had actually happened by querying nf3_upgrades from outside, after
 * the fact. That query is now the capability's own verification.
 */
final class NinjaFormsFormCache {
    private Policy $policy;

    private const CACHE_TABLE = 'nf3_upgrades';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'ninja-forms-form-cache',
            'plugin' => 'ninja-forms/ninja-forms.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * Site-scoped rather than entity-scoped because the declaration it
     * replaces was unscoped: it rebuilt every form's cache on any non-empty
     * apply. Narrowing that to the exact forms this run touched is a real
     * improvement, but it is a behavior change that needs its own evidence,
     * not something to fold into a channel migration.
     *
     * 120 seconds: the loop is one public build call per form on a fixture
     * with a handful of forms, but form count is a site property rather than
     * a bounded engine input, so the budget leaves room for a real install.
     */
    public function capabilities(): array {
        return [
            'rebuild_form_caches' => [
                'args' => [],
                'reads' => ['table:nf3_forms', 'table:nf3_fields', 'table:nf3_actions'],
                'writes' => ['table:nf3_upgrades', 'entity:ninja-forms-legacy-form-option'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 120,
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        return match ($capability) {
            'rebuild_form_caches' => $this->rebuild_form_caches(),
            default => throw new \RuntimeException(
                "duo: Ninja Forms form-cache provider does not implement capability '$capability'"
            ),
        };
    }

    /**
     * Rebuild every form's cache and prove each one landed.
     *
     * The verification target is nf3_upgrades keyed by form id. That is not a
     * guess: manifests/ninja-forms.json's own note records Ninja Forms'
     * source (includes/Helper.php) doing exactly `DELETE FROM nf3_upgrades
     * WHERE id=$id` plus `delete_option('nf_form_'.$id)` as the INVERSE
     * operation, and sandbox/tests/grind_r1a_forms.sh's DUO-3267 probe uses a
     * non-zero nf3_upgrades row count as live evidence the rebuild ran. A
     * form whose row is still absent after its build call means the cache was
     * not rebuilt, whatever the call returned.
     *
     * @return array{before:array, after:array, verified:true}
     */
    private function rebuild_form_caches(): array {
        if (!function_exists('Ninja_Forms') || !class_exists('WPN_Helper')
            || !is_callable(['WPN_Helper', 'build_nf_cache'])) {
            throw new \RuntimeException(
                'duo: Ninja Forms 3.x form-cache API is unavailable (Ninja_Forms()/WPN_Helper::build_nf_cache)'
            );
        }
        $forms = (array) (\Ninja_Forms()->form()->get_forms() ?: []);
        $ids = [];
        foreach ($forms as $form) {
            if (!is_object($form) || !is_callable([$form, 'get_id'])) {
                throw new \RuntimeException(
                    'duo: Ninja Forms returned a form object without a public get_id(); '
                    . 'the installed version is outside this provider contract'
                );
            }
            $id = (int) $form->get_id();
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        $ids = array_values($ids);

        $before = ['form_ids' => $ids, 'cached_form_ids' => $this->cached_form_ids($ids)];
        foreach ($ids as $id) {
            \WPN_Helper::build_nf_cache($id);
        }
        $cachedAfter = $this->cached_form_ids($ids);
        $missing = array_values(array_diff($ids, $cachedAfter));
        if ($missing !== []) {
            throw new \RuntimeException(
                'duo: Ninja Forms cache rebuild left form(s) without a ' . self::CACHE_TABLE
                . ' row: ' . implode(', ', $missing)
            );
        }

        return [
            'before' => $before,
            'after' => ['form_ids' => $ids, 'cached_form_ids' => $cachedAfter],
            'verified' => true,
        ];
    }

    /**
     * Checked read of which of the supplied form ids currently hold a cache
     * row. WordPress database reads return empty-looking values on SQL
     * failure, so this distinguishes a real absence from a failed query
     * before a receipt can claim anything.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function cached_form_ids(array $ids): array {
        global $wpdb;
        if ($ids === []) {
            return [];
        }
        $table = $wpdb->prefix . self::CACHE_TABLE;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM `$table` WHERE id IN ($placeholders)",
            ...$ids
        ));
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                'duo: Ninja Forms form-cache verification query failed against ' . $table
            );
        }
        $out = array_values(array_unique(array_map('intval', $rows)));
        sort($out, SORT_NUMERIC);
        return $out;
    }
}
