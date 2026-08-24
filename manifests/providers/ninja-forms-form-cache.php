<?php
namespace Duo\Providers;

use Duo\PlainData;
use Duo\Policy;
use Duo\WpCliChildProcess;

if (!class_exists(WpCliChildProcess::class, false)) {
    require_once __DIR__ . '/../../agent/src/Kernel/WpCliChildProcess.php';
}

/**
 * Ninja Forms 3.x form-cache rebuild provider.
 *
 * Ninja Forms reads nf3_upgrades before its authored tables and memoizes each
 * form factory for the lifetime of the PHP process. Duo materializes the
 * authored graph with SQL, so rebuilding in that same process can feed stale
 * cached fields back into WPN_Helper::build_nf_cache(). The provider therefore
 * runs the plugin's native builder in a fresh wp-cli child after deleting every
 * old cache row. A checked parent-process projection then proves source tables,
 * cache membership, and native field/action identities agree.
 */
final class NinjaFormsFormCache {
    private Policy $policy;

    private const CACHE_TABLE = 'nf3_upgrades';

    private const CHILD_FORMAT = 'duo-ninja-forms-cache-rebuild/v1';

    private const MAX_CACHE_BYTES = 16777216;

    private const REQUIRED_COLUMNS = [
        'nf3_forms' => [
            'id', 'title', 'key', 'created_at', 'updated_at', 'views', 'subs',
            'form_title', 'default_label_pos', 'show_title', 'clear_complete',
            'hide_complete', 'logged_in', 'seq_num',
        ],
        'nf3_form_meta' => ['id', 'parent_id', 'key', 'value', 'meta_key', 'meta_value'],
        'nf3_fields' => [
            'id', 'label', 'key', 'type', 'parent_id', 'created_at', 'updated_at',
            'field_label', 'field_key', 'order', 'required', 'default_value',
            'label_pos', 'personally_identifiable',
        ],
        'nf3_field_meta' => ['id', 'parent_id', 'key', 'value', 'meta_key', 'meta_value'],
        'nf3_actions' => [
            'id', 'title', 'key', 'type', 'active', 'parent_id', 'created_at',
            'updated_at', 'label',
        ],
        'nf3_action_meta' => ['id', 'parent_id', 'key', 'value', 'meta_key', 'meta_value'],
        self::CACHE_TABLE => ['id', 'cache', 'stage', 'maintenance'],
        'options' => ['option_id', 'option_name', 'option_value', 'autoload'],
    ];

    private const SOURCE_TABLES = [
        'nf3_forms',
        'nf3_form_meta',
        'nf3_fields',
        'nf3_field_meta',
        'nf3_actions',
        'nf3_action_meta',
    ];

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'ninja-forms-form-cache',
            'plugin' => 'ninja-forms/ninja-forms.php',
            'version' => '2.2.0',
        ];
    }

    public function capabilities(): array {
        return [
            'rebuild_form_caches' => [
                'args' => [],
                'reads' => [
                    'table:nf3_forms',
                    'table:nf3_form_meta',
                    'table:nf3_fields',
                    'table:nf3_field_meta',
                    'table:nf3_actions',
                    'table:nf3_action_meta',
                    'table:options',
                ],
                'writes' => ['table:nf3_upgrades', 'entity:ninja-forms-legacy-form-option'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 300,
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
            'rebuild_form_caches' => $this->rebuild_form_caches(),
            default => throw new \RuntimeException(
                "duo: Ninja Forms form-cache provider does not implement capability '$capability'"
            ),
        };
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== 'rebuild_form_caches') {
            throw new \RuntimeException(
                "duo: Ninja Forms form-cache provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => $this->projection_summary(true),
            'verified' => true,
        ];
    }

    /** @return array{before:array<string,int|string>,after:array<string,int|string>,verified:true} */
    private function rebuild_form_caches(): array {
        if (function_exists('is_multisite') && is_multisite()) {
            throw new \RuntimeException(
                'duo: Ninja Forms form-cache provider is certified for single-site tables only'
            );
        }
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                'duo: Ninja Forms cache regeneration requires a fresh wp-cli process'
            );
        }

        $before = $this->projection_summary(false);
        if ($before['maintenance_form_caches'] !== 0) {
            throw new \RuntimeException(
                'duo: Ninja Forms has a form cache in maintenance mode; recovery_required'
            );
        }

        try {
            $result = WpCliChildProcess::capture(
                'eval ' . escapeshellarg(self::child_payload()),
                300,
                262144,
                131072
            );
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                'duo: Ninja Forms fresh cache-rebuild process could not start',
                0,
                $t
            );
        }
        if ($result['return_code'] !== 0) {
            throw new \RuntimeException(
                "duo: Ninja Forms fresh cache-rebuild process exited {$result['return_code']}; recovery_required"
            );
        }
        if (trim($result['stderr']) !== '') {
            throw new \RuntimeException(
                'duo: Ninja Forms fresh cache-rebuild process emitted stderr despite exit 0; recovery_required'
            );
        }
        try {
            $child = json_decode(
                trim($result['stdout']),
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                'duo: Ninja Forms fresh cache-rebuild process returned a malformed receipt; recovery_required'
            );
        }
        if (!is_array($child)
            || array_keys($child) !== [
                'format',
                'form_count',
                'rebuilt_form_count',
                'cache_fingerprint',
                'verified',
            ]
            || ($child['format'] ?? null) !== self::CHILD_FORMAT
            || !is_int($child['form_count'] ?? null)
            || !is_int($child['rebuilt_form_count'] ?? null)
            || !is_string($child['cache_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $child['cache_fingerprint']) !== 1
            || ($child['verified'] ?? null) !== true
            || $child['form_count'] < 0
            || $child['rebuilt_form_count'] !== $child['form_count']) {
            throw new \RuntimeException(
                'duo: Ninja Forms fresh cache-rebuild process returned an invalid receipt; recovery_required'
            );
        }

        $after = $this->projection_summary(true);
        if (!hash_equals((string) $before['source_fingerprint'], (string) $after['source_fingerprint'])
            || $before['forms'] !== $after['forms']) {
            throw new \RuntimeException(
                'duo: Ninja Forms authored table graph changed during cache regeneration; recovery_required'
            );
        }
        if ($after['forms'] !== $child['form_count']) {
            throw new \RuntimeException(
                'duo: Ninja Forms child receipt disagrees with the checked form population; recovery_required'
            );
        }
        if (!hash_equals((string) $child['cache_fingerprint'], (string) $after['cache_fingerprint'])) {
            throw new \RuntimeException(
                'duo: Ninja Forms child cache projection disagrees with parent readback; recovery_required'
            );
        }

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /**
     * A launched wp-cli process is the correctness boundary: Ninja_Forms()->
     * form($id) caches factories in a static local, and get_fields() consults
     * nf3_upgrades even when asked for a fresh read. Deleting the cache before
     * the first per-id factory is created makes the plugin's own builder read
     * the committed tables. Exact serialized readback catches silent writes.
     */
    private static function child_payload(): string {
        return <<<'PHP'
if (is_multisite()) {
    throw new RuntimeException('Ninja Forms cache rebuild refuses multisite');
}
if (!function_exists('Ninja_Forms') || !class_exists('WPN_Helper')
    || !is_callable(['WPN_Helper', 'build_nf_cache'])) {
    throw new RuntimeException('Ninja Forms 3.x form-cache API is unavailable');
}
global $wpdb;
$forms = $wpdb->prefix . 'nf3_forms';
$cache = $wpdb->prefix . 'nf3_upgrades';
$options = $wpdb->options;
$wpdb->last_error = '';
$ids = $wpdb->get_col("SELECT id FROM `$forms` ORDER BY id");
if (!is_array($ids) || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms form inventory query failed');
}
$ids = array_values(array_map('intval', $ids));
foreach ($ids as $id) {
    if ($id <= 0) {
        throw new RuntimeException('Ninja Forms form inventory contains a non-positive id');
    }
}
$wpdb->last_error = '';
$maintenance = $wpdb->get_var("SELECT COUNT(*) FROM `$cache` WHERE maintenance <> 0");
if ($maintenance === null || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms cache maintenance query failed');
}
if ((int) $maintenance !== 0) {
    throw new RuntimeException('Ninja Forms has a form cache in maintenance mode');
}
$wpdb->last_error = '';
$deleted = $wpdb->query("DELETE FROM `$cache`");
if ($deleted === false || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms stale cache purge failed');
}
$wpdb->last_error = '';
$legacy = $wpdb->get_col("SELECT option_name FROM `$options` WHERE option_name LIKE 'nf_form_%' ORDER BY option_name");
if (!is_array($legacy) || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms legacy cache inventory query failed');
}
foreach ($legacy as $name) {
    if (is_string($name) && preg_match('/^nf_form_[1-9][0-9]*$/D', $name) === 1) {
        delete_option($name);
    }
}
$rebuilt = 0;
foreach ($ids as $id) {
    $expected = WPN_Helper::build_nf_cache($id);
    if (!is_array($expected) || (int) ($expected['id'] ?? 0) !== $id) {
        throw new RuntimeException('Ninja Forms native cache builder returned an invalid form cache');
    }
    $wpdb->last_error = '';
    $actual = $wpdb->get_var($wpdb->prepare("SELECT cache FROM `$cache` WHERE id = %d", $id));
    if (!is_string($actual) || (string) $wpdb->last_error !== ''
        || !hash_equals(hash('sha256', serialize($expected)), hash('sha256', $actual))) {
        throw new RuntimeException('Ninja Forms native cache bytes did not persist exactly');
    }
    $rebuilt++;
}
$wpdb->last_error = '';
$cached = $wpdb->get_col("SELECT id FROM `$cache` ORDER BY id");
if (!is_array($cached) || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms rebuilt cache inventory query failed');
}
$cached = array_values(array_map('intval', $cached));
if ($cached !== $ids) {
    throw new RuntimeException('Ninja Forms rebuilt cache inventory does not equal the form inventory');
}
$wpdb->last_error = '';
$cache_rows = $wpdb->get_results("SELECT id, cache, stage, maintenance FROM `$cache` ORDER BY id", ARRAY_A);
if (!is_array($cache_rows) || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms rebuilt cache projection query failed');
}
$cache_projection = [];
foreach ($cache_rows as $row) {
    $cache_id = (int) ($row['id'] ?? 0);
    $raw_cache = $row['cache'] ?? null;
    $stage = (int) ($row['stage'] ?? -1);
    $maintenance_value = $row['maintenance'] ?? null;
    if ($maintenance_value === 0 || $maintenance_value === '0' || $maintenance_value === "\0") {
        $maintenance_mode = 0;
    } elseif ($maintenance_value === 1 || $maintenance_value === '1' || $maintenance_value === "\1") {
        $maintenance_mode = 1;
    } else {
        throw new RuntimeException('Ninja Forms rebuilt cache has an invalid maintenance flag');
    }
    if ($cache_id <= 0 || isset($cache_projection[$cache_id]) || !is_string($raw_cache) || $stage < 0) {
        throw new RuntimeException('Ninja Forms rebuilt cache projection is invalid');
    }
    $cache_projection[$cache_id] = [
        'cache_sha256' => hash('sha256', $raw_cache),
        'stage' => $stage,
        'maintenance' => $maintenance_mode,
    ];
}
ksort($cache_projection, SORT_NUMERIC);
$wpdb->last_error = '';
$legacy = $wpdb->get_col("SELECT option_name FROM `$options` WHERE option_name LIKE 'nf_form_%' ORDER BY option_name");
if (!is_array($legacy) || (string) $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms legacy cache verification query failed');
}
foreach ($legacy as $name) {
    if (is_string($name) && preg_match('/^nf_form_[1-9][0-9]*$/D', $name) === 1) {
        throw new RuntimeException('Ninja Forms legacy form cache survived regeneration');
    }
}
echo wp_json_encode([
    'format' => 'duo-ninja-forms-cache-rebuild/v1',
    'form_count' => count($ids),
    'rebuilt_form_count' => $rebuilt,
    'cache_fingerprint' => hash('sha256', serialize($cache_projection)),
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP;
    }

    /** @return array<string,int|string> */
    private function projection_summary(bool $verify): array {
        $this->assert_schema();
        $source = [];
        foreach (self::SOURCE_TABLES as $table) {
            $source[$table] = $this->checked_rows(
                "SELECT * FROM `{$this->table_name($table)}` ORDER BY `id`",
                "$table source inventory"
            );
        }

        $formIds = $this->positive_ids($source['nf3_forms'], 'id');
        $fieldEntityIds = $this->positive_ids($source['nf3_fields'], 'id');
        $actionEntityIds = $this->positive_ids($source['nf3_actions'], 'id');
        $fieldIds = $this->ids_by_parent($source['nf3_fields'], $formIds, 'field');
        $actionIds = $this->ids_by_parent($source['nf3_actions'], $formIds, 'action');
        $this->assert_meta_rows($source['nf3_form_meta'], $formIds, 'form');
        $this->assert_meta_rows($source['nf3_field_meta'], $fieldEntityIds, 'field');
        $this->assert_meta_rows($source['nf3_action_meta'], $actionEntityIds, 'action');
        $cacheRows = $this->checked_rows(
            "SELECT id, cache, stage, maintenance FROM `{$this->table_name(self::CACHE_TABLE)}` ORDER BY `id`",
            'Ninja Forms cache inventory'
        );
        $legacyRows = $this->checked_rows(
            "SELECT option_name, option_value FROM `{$this->table_name('options')}` "
            . "WHERE option_name LIKE 'nf_form_%' ORDER BY option_name",
            'Ninja Forms legacy cache inventory'
        );
        $legacyFingerprintRows = [];
        foreach ($legacyRows as $row) {
            $name = $row['option_name'] ?? null;
            if (!is_string($name) || preg_match('/^nf_form_[1-9][0-9]*$/D', $name) !== 1) {
                continue;
            }
            $legacyFingerprintRows[$name] = hash('sha256', (string) ($row['option_value'] ?? ''));
        }
        ksort($legacyFingerprintRows, SORT_STRING);

        $cacheIds = [];
        $cacheFingerprintRows = [];
        $invalid = 0;
        $maintenance = 0;
        foreach ($cacheRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($cacheIds[$id])) {
                $invalid++;
                continue;
            }
            $cacheIds[$id] = true;
            $raw = $row['cache'] ?? null;
            $stage = (int) ($row['stage'] ?? -1);
            $maintenanceMode = $this->bit_flag(
                $row['maintenance'] ?? null,
                'cache maintenance'
            );
            if ($maintenanceMode !== 0) {
                $maintenance++;
            }
            $cacheFingerprintRows[$id] = [
                'cache_sha256' => is_string($raw) ? hash('sha256', $raw) : '',
                'stage' => $stage,
                'maintenance' => $maintenanceMode,
            ];
            if (!isset($formIds[$id])
                || !is_string($raw)
                || strlen($raw) > self::MAX_CACHE_BYTES
                || $stage < 0
                || !$this->valid_cache($raw, $id, $fieldIds[$id] ?? [], $actionIds[$id] ?? [])) {
                $invalid++;
            }
        }
        ksort($cacheFingerprintRows, SORT_NUMERIC);

        $missing = array_diff_key($formIds, $cacheIds);
        $orphan = array_diff_key($cacheIds, $formIds);
        $summary = [
            'forms' => count($formIds),
            'form_meta_rows' => count($source['nf3_form_meta']),
            'fields' => count($source['nf3_fields']),
            'field_meta_rows' => count($source['nf3_field_meta']),
            'actions' => count($source['nf3_actions']),
            'action_meta_rows' => count($source['nf3_action_meta']),
            'cache_rows' => count($cacheRows),
            'missing_form_caches' => count($missing),
            'orphan_form_caches' => count($orphan),
            'invalid_form_caches' => $invalid,
            'maintenance_form_caches' => $maintenance,
            'legacy_form_caches' => count($legacyFingerprintRows),
            'source_fingerprint' => hash('sha256', serialize($source)),
            'cache_fingerprint' => hash('sha256', serialize($cacheFingerprintRows)),
            'legacy_cache_fingerprint' => hash('sha256', serialize($legacyFingerprintRows)),
        ];
        if ($verify) {
            foreach ([
                'missing_form_caches',
                'orphan_form_caches',
                'invalid_form_caches',
                'maintenance_form_caches',
                'legacy_form_caches',
            ] as $field) {
                if ($summary[$field] !== 0) {
                    throw new \RuntimeException(
                        "duo: Ninja Forms cache readback found {$summary[$field]} $field; recovery_required"
                    );
                }
            }
        }
        return $summary;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,true> */
    private function positive_ids(array $rows, string $column): array {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$column] ?? 0);
            if ($id <= 0 || isset($ids[$id])) {
                throw new \RuntimeException(
                    "duo: Ninja Forms source inventory has an invalid or duplicate $column; recovery_required"
                );
            }
            $ids[$id] = true;
        }
        ksort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,true> $parents
     * @return array<int,list<int>>
     */
    private function ids_by_parent(array $rows, array $parents, string $label): array {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parent = (int) ($row['parent_id'] ?? 0);
            if ($id <= 0 || $parent <= 0 || !isset($parents[$parent])) {
                throw new \RuntimeException(
                    "duo: Ninja Forms $label inventory has an invalid id or parent_id; recovery_required"
                );
            }
            $out[$parent][] = $id;
        }
        foreach ($out as &$ids) {
            sort($ids, SORT_NUMERIC);
        }
        unset($ids);
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @param array<int,true> $owners */
    private function assert_meta_rows(array $rows, array $owners, string $label): void {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parent = (int) ($row['parent_id'] ?? 0);
            if ($id <= 0 || isset($ids[$id]) || $parent <= 0 || !isset($owners[$parent])) {
                throw new \RuntimeException(
                    "duo: Ninja Forms $label meta inventory has an invalid identity or owner; recovery_required"
                );
            }
            $ids[$id] = true;
            $legacyValue = $row['value'] ?? null;
            $currentValue = $row['meta_value'] ?? null;
            $valuesMatch = ($legacyValue === null && $currentValue === null)
                || (is_string($legacyValue)
                    && is_string($currentValue)
                    && hash_equals($legacyValue, $currentValue));
            if (!is_string($row['key'] ?? null)
                || $row['key'] === ''
                || !is_string($row['meta_key'] ?? null)
                || !hash_equals($row['key'], $row['meta_key'])
                || !$valuesMatch) {
                throw new \RuntimeException(
                    "duo: Ninja Forms $label meta legacy/current columns diverge; recovery_required"
                );
            }
        }
    }

    /** @param list<int> $fieldIds @param list<int> $actionIds */
    private function valid_cache(string $raw, int $formId, array $fieldIds, array $actionIds): bool {
        try {
            $cache = PlainData::decode_serialized($raw, "Ninja Forms cache for form $formId");
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($cache)
            || array_keys($cache) !== ['id', 'fields', 'actions', 'settings']
            || (int) ($cache['id'] ?? 0) !== $formId
            || !is_array($cache['settings'] ?? null)) {
            return false;
        }
        $cachedFields = $this->cache_member_ids($cache['fields'] ?? null);
        $cachedActions = $this->cache_member_ids($cache['actions'] ?? null);
        return $cachedFields !== null
            && $cachedActions !== null
            && $cachedFields === $fieldIds
            && $cachedActions === $actionIds;
    }

    /** @return ?list<int> */
    private function cache_member_ids(mixed $members): ?array {
        if (!is_array($members) || !array_is_list($members)) {
            return null;
        }
        $ids = [];
        foreach ($members as $member) {
            if (!is_array($member)
                || array_keys($member) !== ['settings', 'id']
                || !is_array($member['settings'] ?? null)
                || !is_int($member['id'] ?? null)
                || $member['id'] <= 0) {
                return null;
            }
            $ids[] = $member['id'];
        }
        sort($ids, SORT_NUMERIC);
        return count($ids) === count(array_unique($ids)) ? $ids : null;
    }

    private function assert_schema(): void {
        global $wpdb;
        foreach (self::REQUIRED_COLUMNS as $table => $required) {
            $prefixed = $this->table_name($table);
            $wpdb->last_error = '';
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `$prefixed`");
            if (!is_array($columns) || (string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    "duo: Ninja Forms $table schema probe failed; recovery_required"
                );
            }
            $missing = array_values(array_diff($required, array_map('strval', $columns)));
            if ($missing !== []) {
                throw new \RuntimeException(
                    "duo: Ninja Forms $table is missing required column(s): "
                    . implode(', ', $missing) . '; recovery_required'
                );
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function checked_rows(string $sql, string $label): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: $label query failed; recovery_required");
        }
        return array_values($rows);
    }

    private function table_name(string $suffix): string {
        global $wpdb;
        $prefix = (string) ($wpdb->prefix ?? '');
        if ($prefix === '' || preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
            throw new \RuntimeException('duo: Ninja Forms database prefix is unavailable or unsafe');
        }
        return $prefix . $suffix;
    }

    /** MariaDB returns BIT(1) as a one-byte binary string through wpdb. */
    private function bit_flag(mixed $value, string $label): int {
        if ($value === 0 || $value === '0' || $value === "\0") {
            return 0;
        }
        if ($value === 1 || $value === '1' || $value === "\1") {
            return 1;
        }
        throw new \RuntimeException(
            "duo: Ninja Forms $label flag is not an exact BIT(1) value; recovery_required"
        );
    }
}
