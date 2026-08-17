<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';

/** Canonical sidebar ownership and ledger-only widget instance identity. */
final class SidebarState {
    public const ENTITY_TYPE = 'sidebar';
    public const LONGEST_CORE_ID_KIND = 'widget_media_gallery';

    public static function key(string $sidebar): string {
        return 'sidebar/' . $sidebar;
    }

    public static function path(string $sidebar): string {
        return 'sidebars/' . $sidebar . '.json';
    }

    public static function kind(string $type): string {
        return 'widget_' . $type;
    }

    public static function type_from_kind(string $kind): ?string {
        return str_starts_with($kind, 'widget_') && strlen($kind) > 7 ? substr($kind, 7) : null;
    }

    public static function assert_width_budget(): void {
        if (strlen(self::LONGEST_CORE_ID_KIND) > Ledger::ID_KIND_WIDTH) {
            throw new \RuntimeException(
                'duo: widget id_kind width budget is smaller than ' . self::LONGEST_CORE_ID_KIND
            );
        }
    }

    public static function assert_policy(Policy $policy): void {
        self::assert_width_budget();
        $declared = $policy->widget_types();
        self::assert_declared_types($declared);
        $tableKinds = [];
        foreach (Snapshot::row_tables($policy) as $table => $decl) {
            $tableKinds[(string) $decl['id_kind']] = $table;
        }
        foreach (array_keys($declared) as $type) {
            $kind = self::kind((string) $type);
            if (isset($tableKinds[$kind])) {
                throw new \RuntimeException(
                    "duo: identity kind '$kind' is declared by widget '$type' and table '{$tableKinds[$kind]}'"
                );
            }
        }
    }

    /** @return array{entities:list<array>,warnings:list<string>} */
    public static function capture(
        Policy $policy,
        Tokens $tokens,
        bool $mint,
        bool $forceUnresolvedRefs = false,
        bool $strictReadOnly = false
    ): array {
        self::assert_policy($policy);
        $declared = $policy->widget_types();
        $sidebars = self::load_sidebars_option();
        $options = self::load_widget_options($policy, $declared, true);
        $entities = [];
        $warnings = [];
        $seen = [];
        $inactive = (array) ($sidebars['wp_inactive_widgets'] ?? []);
        if ($inactive) {
            $warnings[] = 'wp_inactive_widgets is excluded from sidebar portability v1; parked widget content will not propagate';
        }
        unset($sidebars['array_version'], $sidebars['wp_inactive_widgets']);
        ksort($sidebars, SORT_STRING);
        foreach ($sidebars as $sidebar => $instanceKeys) {
            if (!is_string($sidebar) || $sidebar === '' || str_contains($sidebar, '/')
                || !is_array($instanceKeys) || !array_is_list($instanceKeys)) {
                throw new \RuntimeException("duo: sidebars_widgets has an invalid sidebar '$sidebar' shape");
            }
            $widgets = [];
            foreach ($instanceKeys as $position => $instanceKey) {
                if (!is_string($instanceKey) || !preg_match('/^(.+)-([1-9][0-9]*)$/', $instanceKey, $m)) {
                    throw new \RuntimeException("duo: sidebar '$sidebar' has malformed widget instance id at position $position");
                }
                $type = $m[1];
                $local = (int) $m[2];
                if (!isset($declared[$type])) {
                    throw new \RuntimeException(
                        "duo: sidebar '$sidebar' contains undeclared widget type '$type' ($instanceKey); "
                        . 'classify it in a pinned manifest before capture'
                    );
                }
                if (isset($seen[$instanceKey])) {
                    throw new \RuntimeException("duo: widget instance '$instanceKey' is assigned to more than one sidebar");
                }
                $seen[$instanceKey] = true;
                $settings = $options[$type][$local] ?? null;
                if (!is_array($settings)) {
                    throw new \RuntimeException("duo: $instanceKey is absent from option widget_$type or is not a settings object");
                }
                $kind = self::kind($type);
                $uuid = Ledger::uuid_for($local, $kind);
                if ($strictReadOnly && $uuid === null) {
                    throw new \RuntimeException(
                        "duo: refresh export refused — widget '$instanceKey' has no durable ledger identity; "
                        . 'run the existing capture/identity recovery gate before exporting production'
                    );
                }
                if ($uuid === null && $mint) {
                    $uuid = Uuid::v7();
                    Ledger::set($uuid, 'widget', $kind, $local);
                }
                if ($strictReadOnly) {
                    Ledger::require_read_only_mapping($uuid, 'widget', $kind, $local, "widget '$instanceKey'");
                }
                $portable = self::capture_settings(
                    $type, $settings, $declared[$type], $policy, $tokens, $sidebar, $forceUnresolvedRefs
                );
                if ($uuid === null) {
                    // Snapshot-only marker: makes fresh target defaults visible
                    // in plan without claiming durable identity for them.
                    $uuid = Uuid::v5(Uuid::NAMESPACE_DUO, "unmanaged-widget:$type:$local");
                    $portable = ['_duo_unmanaged' => true] + $portable;
                }
                $widgets[] = ['uuid' => $uuid, 'type' => $type, 'settings' => (object) $portable];
            }
            $front = ['widgets' => $widgets];
            $entities[] = [
                'uuid' => self::key($sidebar), 'type' => self::ENTITY_TYPE,
                'path' => self::path($sidebar), 'content' => Canon::encode($front),
            ];
        }
        return ['entities' => $entities, 'warnings' => $warnings];
    }

    /**
     * Bind one selected widget ledger tuple to the exact live option instance
     * and selected sidebar assignment. This is read-only and deliberately
     * separate from global dead-map pruning: unselected widget rows are not
     * this scoped authority's evidence to repair or reinterpret.
     */
    public static function assert_read_only_selected_mapping(
        Policy $policy,
        string $uuid,
        string $type,
        int $localId,
        string $sidebar
    ): void {
        $declared = $policy->widget_types();
        if (!isset($declared[$type]) || $localId <= 0 || $sidebar === '') {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        try {
            $options = self::load_widget_options($policy, $declared, false);
            $sidebars = self::load_sidebars_option();
        } catch (\Throwable $failure) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
        }
        $key = "$type-$localId";
        if (!isset($options[$type][$localId])
            || !in_array($key, (array) ($sidebars[$sidebar] ?? []), true)) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        foreach ($sidebars as $owner => $keys) {
            if ((string) $owner !== $sidebar && in_array($key, (array) $keys, true)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
        }
        try {
            Ledger::require_read_only_mapping(
                $uuid,
                'widget',
                self::kind($type),
                $localId,
                "selected widget '$key'"
            );
        } catch (\Throwable $failure) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
        }
    }

    /** Validate the multi-instance family before any row is used. */
    private static function load_widget_options(Policy $policy, array $declared, bool $scanUndeclared): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' ORDER BY option_name",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $name = (string) $row['option_name'];
            $type = substr($name, 7);
            $value = PlainData::decode($row['option_value'], "option '$name'");
            if (!is_array($value)) {
                throw new \RuntimeException("duo: widget option '$name' is not a multi-instance array");
            }
            $instances = [];
            foreach ($value as $key => $settings) {
                if ((string) $key === '_multiwidget') {
                    if (!in_array($settings, [1, '1'], true)) {
                        throw new \RuntimeException("duo: widget option '$name' has an invalid _multiwidget marker");
                    }
                    continue;
                }
                if (!preg_match('/^[1-9][0-9]*$/', (string) $key) || !is_array($settings)) {
                    throw new \RuntimeException("duo: widget option '$name' is not a valid _multiwidget family shape");
                }
                $instances[(int) $key] = $settings;
            }
            if ($instances && !isset($declared[$type]) && $scanUndeclared) {
                // DUO-3264: the deliberate-exclusion escape hatch every
                // other loud gate in this engine already has (options.
                // <name>=runtime/env is the standing pattern this codebase
                // uses to record "acknowledged, not portable" — see
                // authorize_post()'s own per-key re-derivation for the
                // established precedent of a second, deliberately
                // redundant guard consulting the same classification).
                // Live-verified before this existed: setting options.
                // widget_<type>=runtime via site policy did NOT satisfy
                // this guard (it only ever consulted $declared, this
                // method's own widgets{}-sourced parameter) -- capture
                // refused again with the identical message even after an
                // operator followed the exact remedy the message itself
                // named. That's the operator-path-dishonesty class this
                // project refuses to ship (a stated remedy that doesn't
                // function), so the guard now ALSO treats an explicit
                // runtime/env classification as first-class acknowledgment,
                // the same tier as a widgets{} declaration -- "acknowledged
                // and excluded" alongside "acknowledged and portable",
                // not a hierarchy between them.
                $classification = $policy->option_rule($name);
                if (in_array($classification['class'] ?? null, ['runtime', 'env'], true)) {
                    continue;
                }
                throw new \RuntimeException(
                    "duo: widget option '$name' contains instances but type '$type' is undeclared -- either add "
                    . "\"$type\" to a pinned manifest's widgets{} grammar (see manifests/core.json's "
                    . 'widgets.block/nav_menu/text for the shape) if its settings should be portable, or declare it '
                    . "a deliberate exclusion (wp duo classify --set 'options:$name=runtime') if not"
                );
            }
            if (isset($declared[$type])) {
                $out[$type] = $instances;
            }
        }
        foreach ($declared as $type => $_) {
            $out[$type] ??= [];
        }
        return $out;
    }

    private static function load_sidebars_option(): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'sidebars_widgets'
        ));
        if ($raw === null) {
            return [];
        }
        $value = PlainData::decode($raw, 'option sidebars_widgets');
        if (!is_array($value)) {
            throw new \RuntimeException('duo: option sidebars_widgets is not an array');
        }
        return $value;
    }

    /**
     * DUO-3318 review (S4): the DECLARATION grammar is Policy's, for every
     * reader — one implementation, exactly as Snapshot's schema assertions
     * delegate their pure half to Policy::assert_table_grammar(). This file
     * had its own hand-copy of the same five rules, reachable only once a
     * sidebar was actually captured or applied (so it needed a live
     * WordPress), and it had drifted three ways in the permissive direction:
     * an empty `settings` map, a settings LIST, and an explicitly-null
     * `codec`/`ref` all passed here and were refused by the load-time copy.
     *
     * What stays is the one check that is genuinely this file's: the derived
     * `widget_<type>` ledger kind has to FIT duo_map.id_kind, which is
     * Ledger's schema rather than the manifest's grammar (and the reason
     * Policy's copy cannot make it — naming Ledger there would drag a second
     * engine class into a file whose whole point is that it loads alone).
     */
    private static function assert_declared_types(array $declared): void {
        foreach ($declared as $type => $rule) {
            Policy::assert_widget_grammar((string) $type, $rule);
            if (strlen(self::kind((string) $type)) > Ledger::ID_KIND_WIDTH) {
                throw new \RuntimeException(
                    "duo: over-budget manifest widget type '$type' — its derived identity kind '"
                    . self::kind((string) $type) . "' exceeds duo_map.id_kind (VARCHAR("
                    . Ledger::ID_KIND_WIDTH . '))'
                );
            }
        }
    }

    private static function capture_settings(
        string $type,
        array $settings,
        array $decl,
        Policy $policy,
        Tokens $tokens,
        string $sidebar,
        bool $forceUnresolvedRefs
    ): array {
        $rules = (array) $decl['settings'];
        $unknown = array_diff(array_keys($settings), array_keys($rules));
        if ($unknown) {
            sort($unknown, SORT_STRING);
            throw new \RuntimeException(
                "duo: widget_$type in sidebar '$sidebar' has undeclared setting(s): " . implode(', ', $unknown)
            );
        }
        $out = [];
        foreach ($settings as $key => $value) {
            $rule = (array) $rules[$key];
            $secret = empty($rule['allow_secret']) ? Secrets::hard_match_deep($value) : null;
            if ($secret !== null) {
                throw new \RuntimeException(
                    "duo: widget_$type setting '$key' in sidebar '$sidebar' contains a hard secret ($secret); "
                    . 'refusing capture without allow_secret=true'
                );
            }
            if (($rule['codec'] ?? '') === 'blocks') {
                if (!is_string($value)) {
                    throw new \RuntimeException("duo: widget_$type setting '$key' must be block-content text");
                }
                $out[$key] = Blocks::capture_rewrite(
                    $value, $policy, $tokens, $forceUnresolvedRefs, "sidebar '$sidebar'"
                );
            } elseif (($rule['ref'] ?? '') === 'term') {
                $id = (int) $value;
                $out[$key] = $id > 0 ? ($tokens->id_to_token($id, 'term')
                    ?? throw new \RuntimeException("duo: widget_$type setting '$key' references unmanaged term $id")) : null;
            } else {
                $out[$key] = self::rewrite_strings($value, fn(string $s): string => $tokens->tokenize_text($s));
            }
        }
        return $out;
    }

    private static function apply_settings(string $type, array $settings, array $decl, Policy $policy, Tokens $tokens): array {
        unset($settings['_duo_unmanaged']);
        $out = [];
        foreach ($settings as $key => $value) {
            $rule = (array) (($decl['settings'] ?? [])[$key] ?? []);
            if (($rule['codec'] ?? '') === 'blocks') {
                $out[$key] = Blocks::apply_rewrite((string) $value, $policy, $tokens);
            } elseif (($rule['ref'] ?? '') === 'term') {
                $out[$key] = $value === null ? 0 : $tokens->token_to_id((string) $value);
            } else {
                $out[$key] = self::rewrite_strings($value, fn(string $s): string => $tokens->detokenize_text($s));
            }
        }
        return $out;
    }

    private static function rewrite_strings($value, callable $rewrite) {
        if (is_string($value)) {
            return $rewrite($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::rewrite_strings($child, $rewrite);
            }
        }
        return $value;
    }

    /** Phase 1: allocate collision-free target-local counters for every desired widget. */
    public static function ensure_widgets(Policy $policy, array $tree): void {
        $declared = $policy->widget_types();
        $options = self::load_widget_options($policy, $declared, false);
        $used = [];
        foreach ($options as $type => $instances) {
            $used[$type] = array_fill_keys(array_keys($instances), true);
        }
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== self::ENTITY_TYPE) {
                continue;
            }
            foreach ((array) ($entity['data']['widgets'] ?? []) as $widget) {
                $type = (string) ($widget['type'] ?? '');
                $uuid = (string) ($widget['uuid'] ?? '');
                $kind = self::kind($type);
                $local = Ledger::id_for($uuid, $kind);
                if ($local !== null) {
                    $used[$type][$local] = true;
                    continue;
                }
                $local = 1;
                while (isset($used[$type][$local])) {
                    $local++;
                }
                $used[$type][$local] = true;
                Ledger::set($uuid, 'widget', $kind, $local);
            }
        }
    }

    /**
     * Phase 2: reconcile one file-owned sidebar and delete displaced defaults.
     *
     * $writeTouchedOnly is the scoped write bound. The complete compiled tree
     * remains necessary for the read-only global desired-key scan: a widget
     * owned by a different sidebar must prevent this selected sidebar from
     * deleting the instance during a move. In bounded mode, however, only
     * widget families actually changed by this sidebar may be sorted/upserted
     * or have their option caches invalidated. The default preserves the
     * historical full-apply write behavior exactly.
     */
    public static function finalize_sidebar(
        Policy $policy,
        Tokens $tokens,
        array $front,
        string $sidebar,
        array $tree,
        bool $writeTouchedOnly = false
    ): void {
        $declared = $policy->widget_types();
        $options = self::load_widget_options($policy, $declared, false);
        $sidebars = self::load_sidebars_option();
        $globallyDesired = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== self::ENTITY_TYPE) continue;
            foreach ((array) ($entity['data']['widgets'] ?? []) as $widget) {
                $type = (string) $widget['type'];
                $local = Ledger::id_for((string) $widget['uuid'], self::kind($type));
                if ($local !== null) $globallyDesired["$type-$local"] = true;
            }
        }
        $touchedTypes = [];
        foreach ((array) ($sidebars[$sidebar] ?? []) as $oldKey) {
            if (!is_string($oldKey) || isset($globallyDesired[$oldKey])
                || !preg_match('/^(.+)-([1-9][0-9]*)$/', $oldKey, $m)) continue;
            $oldType = $m[1];
            $oldLocal = (int) $m[2];
            if (isset($declared[$oldType])) {
                if (array_key_exists($oldLocal, $options[$oldType])) {
                    unset($options[$oldType][$oldLocal]);
                    $touchedTypes[$oldType] = true;
                }
                $oldUuid = Ledger::uuid_for($oldLocal, self::kind($oldType));
                if ($oldUuid !== null) Ledger::forget($oldUuid);
            }
        }
        $keys = [];
        foreach ((array) ($front['widgets'] ?? []) as $widget) {
            $type = (string) $widget['type'];
            $uuid = (string) $widget['uuid'];
            $local = Ledger::id_for($uuid, self::kind($type));
            if ($local === null) throw new \RuntimeException("duo: widget $uuid has no allocated $type identity");
            $options[$type][$local] = self::apply_settings(
                $type, (array) $widget['settings'], $declared[$type], $policy, $tokens
            );
            $touchedTypes[$type] = true;
            $keys[] = "$type-$local";
        }
        if ($writeTouchedOnly) {
            ksort($touchedTypes, SORT_STRING);
        }
        $optionWriteTypes = $writeTouchedOnly ? array_keys($touchedTypes) : array_keys($declared);
        foreach ($optionWriteTypes as $type) {
            ksort($options[$type], SORT_NUMERIC);
            $stored = $options[$type];
            $stored['_multiwidget'] = 1;
            Db::query($GLOBALS['wpdb']->prepare(
                "INSERT INTO {$GLOBALS['wpdb']->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes') "
                . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
                'widget_' . $type, maybe_serialize($stored)
            ), "apply widget_$type option");
            wp_cache_delete('widget_' . $type, 'options');
            wp_cache_delete('alloptions', 'options');
        }
        // A WordPress widget instance may be assigned to only one active
        // sidebar. If an owned UUID was moved locally, force-theirs must
        // restore its declared assignment without duplicating the instance;
        // unrelated keys in the other sidebar remain untouched.
        $desiredKeys = array_fill_keys($keys, true);
        foreach ($sidebars as $otherSidebar => $otherKeys) {
            if ($otherSidebar === $sidebar || $otherSidebar === 'wp_inactive_widgets'
                || $otherSidebar === 'array_version' || !is_array($otherKeys)) {
                continue;
            }
            $sidebars[$otherSidebar] = array_values(array_filter(
                $otherKeys,
                static fn($key): bool => !is_string($key) || !isset($desiredKeys[$key])
            ));
        }
        $sidebars[$sidebar] = $keys;
        $sidebars['array_version'] = max(3, (int) ($sidebars['array_version'] ?? 3));
        Db::query($GLOBALS['wpdb']->prepare(
            "INSERT INTO {$GLOBALS['wpdb']->options} (option_name, option_value, autoload) VALUES ('sidebars_widgets', %s, 'yes') "
            . 'ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', maybe_serialize($sidebars)
        ), "apply sidebar '$sidebar'");
        wp_cache_delete('sidebars_widgets', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    public static function sidebar_from_path(string $path): ?string {
        return preg_match('#^sidebars/([^/]+)\.json$#', $path, $m) ? $m[1] : null;
    }

    public static function inactive_warning(): ?string {
        $sidebars = self::load_sidebars_option();
        return !empty($sidebars['wp_inactive_widgets'])
            ? 'wp_inactive_widgets is excluded from sidebar portability v1; parked widget content will not propagate'
            : null;
    }

    /** Mappings whose backing option instance disappeared, before pruning. */
    public static function observed_deleted_mapped_uuids(Policy $policy): array {
        $options = self::load_widget_options($policy, $policy->widget_types(), false);
        $deleted = [];
        foreach (Ledger::all_map() as $row) {
            $type = self::type_from_kind($row['id_kind']);
            if ($type !== null && isset($policy->widget_types()[$type])
                && !isset($options[$type][$row['local_id']])) {
                $deleted[$row['uuid']] = true;
            }
        }
        return $deleted;
    }

    public static function prune_dead_map(Policy $policy): void {
        global $wpdb;
        $options = self::load_widget_options($policy, $policy->widget_types(), false);
        foreach (Ledger::all_map() as $row) {
            $type = self::type_from_kind($row['id_kind']);
            if ($type !== null && isset($policy->widget_types()[$type])
                && !isset($options[$type][$row['local_id']])) {
                Db::query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}duo_map WHERE uuid = %s AND id_kind = %s",
                    $row['uuid'], $row['id_kind']
                ), 'ledger prune dead widget identity');
            }
        }
    }

    public static function assert_mapped_history_present(string $repo, array $observedDeleted = []): void {
        if (!is_dir(rtrim($repo, '/') . '/state/sidebars')) return;
        foreach (glob(rtrim($repo, '/') . '/state/sidebars/*.json') ?: [] as $file) {
            $front = Canon::decode(Canon::read_file($file));
            foreach ((array) ($front['widgets'] ?? []) as $widget) {
                $uuid = (string) ($widget['uuid'] ?? '');
                $kind = self::kind((string) ($widget['type'] ?? ''));
                if (!isset($observedDeleted[$uuid]) && Ledger::id_for($uuid, $kind) === null) {
                    throw new \RuntimeException(
                        "duo: mapped widget identity $uuid ($kind) from " . basename($file)
                        . ' is missing from the ledger; restore identity-export before capture'
                    );
                }
            }
        }
    }

    /** Identity export must cover every declared widget in an owned sidebar. */
    public static function assert_all_owned_widgets_mapped(Policy $policy, string $repo): void {
        $declared = $policy->widget_types();
        $sidebars = self::load_sidebars_option();
        foreach (glob(rtrim($repo, '/') . '/state/sidebars/*.json') ?: [] as $file) {
            $sidebar = self::sidebar_from_path('sidebars/' . basename($file));
            if ($sidebar === null) continue;
            $keys = (array) ($sidebars[$sidebar] ?? []);
            foreach ((array) $keys as $instanceKey) {
                if (!is_string($instanceKey) || !preg_match('/^(.+)-([1-9][0-9]*)$/', $instanceKey, $m)) {
                    continue;
                }
                $type = $m[1];
                $local = (int) $m[2];
                if (isset($declared[$type]) && Ledger::uuid_for($local, self::kind($type)) === null) {
                    throw new \RuntimeException(
                        "duo: cannot export identity sidecar: owned widget '$instanceKey' in sidebar '$sidebar' "
                        . 'has no ledger identity; capture it first or restore the missing sidecar'
                    );
                }
            }
        }
    }

    public static function witness(string $type, int $local): string {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'widget_' . $type
        ));
        $value = $raw === null ? null : PlainData::decode($raw, "option widget_$type");
        if (!is_array($value) || !isset($value[$local]) || !is_array($value[$local])) {
            throw new \RuntimeException("duo: widget identity row widget_$type:$local is missing");
        }
        return hash('sha256', Canon::encode(['kind' => self::kind($type), 'local_id' => $local, 'settings' => $value[$local]]));
    }
}
