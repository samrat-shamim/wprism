<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/PersonalData.php';
require_once __DIR__ . '/../Kernel/Secrets.php';
require_once __DIR__ . '/../Kernel/Uuid.php';

// Pure sidebar validation also runs from compiler/scanner partial loads. The
// kind width belongs to the shared grammar, not to loading a database writer.
require_once __DIR__ . '/../Kernel/ReferenceKindGrammar.php';

/** Canonical sidebar ownership and ledger-only widget instance identity. */
final class SidebarState {
    public const ENTITY_TYPE = 'sidebar';
    public const LONGEST_CORE_ID_KIND = 'widget_media_gallery';
    private const MAX_OPTION_NAME_BYTES = 764;
    private const MAX_OPTION_NAME_CHARACTERS = 191;
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_WIDGET_FAMILIES = 2048;
    private const MAX_WIDGET_FAMILY_BYTES = 67108864;
    private const MAX_WIDGET_INSTANCES_PER_FAMILY = 100000;
    private const MAX_WIDGET_SETTINGS_PER_INSTANCE = 256;
    private const MAX_SIDEBARS = 4096;
    private const MAX_SIDEBAR_ASSIGNMENTS = 100000;
    private const MAX_SIDEBAR_NAME_BYTES = 764;
    private const MAX_SIDEBAR_NAME_CHARACTERS = 191;
    /** @var ?\Closure(string,string):?array{option_name:string,option_value:string,autoload:string} */
    private static ?\Closure $lockOptionRow = null;
    /** @var ?\Closure(string,string):void */
    private static ?\Closure $queueOption = null;
    /** @var ?\Closure(string,string,string,string):void */
    private static ?\Closure $assertOptionRow = null;

    /** Bind repository state writes to the one authored transaction owner. */
    public static function begin_authored_transaction(
        \Closure $lockOptionRow,
        \Closure $queueOption,
        \Closure $assertOptionRow
    ): void {
        if (self::$lockOptionRow !== null || self::$queueOption !== null || self::$assertOptionRow !== null) {
            throw new \RuntimeException('wprism: sidebar state authored transaction was already active');
        }
        self::$lockOptionRow = $lockOptionRow;
        self::$queueOption = $queueOption;
        self::$assertOptionRow = $assertOptionRow;
    }

    public static function end_authored_transaction(): void {
        self::$lockOptionRow = null;
        self::$queueOption = null;
        self::$assertOptionRow = null;
    }

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

    /**
     * The one top-level option name this class owns outright. Named as a
     * constant so `load_sidebars_option()` (:279) and the apply write (:520)
     * — the two queries that MAKE the ownership claim below true — cannot
     * drift from it.
     */
    public const SIDEBARS_OPTION = 'sidebars_widgets';

    /**
     * Whether this class, rather than a manifest classification, owns an
     * option name end to end: `sidebars_widgets` plus kind()'s own
     * `widget_` prefix (:20-22), which is exactly the set
     * load_widget_options() enumerates (`option_name LIKE 'widget\_%'`,
     * :213) and gates (an undeclared type with real instances refuses
     * capture at :262-269).
     *
     * issue #3508: `Pending::mechanism_owner()` asks this so a journal-observed
     * write to one of these names is not queued for a classification that
     * does not exist — there is no class to give.
     * platform/adapter-library/core/manifest.json:86 records the alternative
     * (declaring the family in `options{}`) being tried and reverted, with this
     * guard named as "the actual, sufficient, already-shipped blocking net".
     */
    public static function owns_option(string $name): bool {
        return $name === self::SIDEBARS_OPTION || str_starts_with($name, 'widget_');
    }

    public static function assert_width_budget(): void {
        if (strlen(self::LONGEST_CORE_ID_KIND) > ReferenceKindGrammar::LEDGER_KIND_WIDTH) {
            throw new \RuntimeException(
                'wprism: widget id_kind width budget is smaller than ' . self::LONGEST_CORE_ID_KIND
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
                    "wprism: identity kind '$kind' is declared by widget '$type' and table '{$tableKinds[$kind]}'"
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
        bool $strictReadOnly = false,
        ?array $portableWidgetReferences = null,
        ?array $canonicalTree = null
    ): array {
        self::assert_policy($policy);
        $declared = $policy->widget_types();
        $sidebars = self::load_sidebars_option();
        // Active assignments and exact portable-block references below are
        // the ownership boundary. The loader retains the preexisting loud
        // undeclared-family gate, but unassigned or unrelated inactive
        // instances never enter canonical state.
        $options = self::load_widget_options($policy, $declared, true);
        $entities = [];
        $warnings = [];
        $seen = [];
        $inactive = (array) ($sidebars['wp_inactive_widgets'] ?? []);
        $selectedInactive = [];
        $requested = [];
        if ($portableWidgetReferences !== null) {
            foreach ($portableWidgetReferences as $position => $reference) {
                if (!is_array($reference)
                    || array_keys($reference) !== ['type', 'local_id']
                    || !is_string($reference['type'] ?? null)
                    || !isset($declared[$reference['type']])
                    || !is_int($reference['local_id'] ?? null)
                    || $reference['local_id'] <= 0) {
                    throw new \RuntimeException(
                        "wprism: portable block widget reference at position $position is malformed"
                    );
                }
                $instanceKey = $reference['type'] . '-' . $reference['local_id'];
                $requested[$instanceKey] = true;
            }
            foreach ($inactive as $instanceKey) {
                if (is_string($instanceKey) && isset($requested[$instanceKey])) {
                    $selectedInactive[] = $instanceKey;
                }
            }
            $unselected = count($inactive) - count($selectedInactive);
            if ($unselected > 0) {
                $warnings[] = self::inactive_exclusion_warning();
            }
            if ($selectedInactive === []) {
                unset($sidebars['wp_inactive_widgets']);
            } else {
                $sidebars['wp_inactive_widgets'] = $selectedInactive;
            }
        } elseif ($inactive) {
            $warnings[] = self::inactive_exclusion_warning();
        }
        unset($sidebars['array_version']);
        if ($portableWidgetReferences === null) {
            unset($sidebars['wp_inactive_widgets']);
        }
        ksort($sidebars, SORT_STRING);
        foreach ($sidebars as $sidebar => $instanceKeys) {
            if (!is_string($sidebar) || $sidebar === '' || str_contains($sidebar, '/')
                || !is_array($instanceKeys) || !array_is_list($instanceKeys)) {
                throw new \RuntimeException("wprism: sidebars_widgets has an invalid sidebar '$sidebar' shape");
            }
            $widgets = [];
            foreach ($instanceKeys as $position => $instanceKey) {
                $parsed = is_string($instanceKey) ? self::parse_widget_instance_key($instanceKey) : null;
                if ($parsed === null) {
                    throw new \RuntimeException("wprism: sidebar '$sidebar' has malformed widget instance id at position $position");
                }
                [$type, $local] = $parsed;
                if (!isset($declared[$type])) {
                    throw new \RuntimeException(
                        "wprism: sidebar '$sidebar' contains undeclared widget type '$type' ($instanceKey); "
                        . 'classify it in a pinned manifest before capture'
                    );
                }
                if (isset($seen[$instanceKey])) {
                    throw new \RuntimeException("wprism: widget instance '$instanceKey' is assigned to more than one sidebar");
                }
                $seen[$instanceKey] = true;
                $settings = $options[$type][$local] ?? null;
                if (!is_array($settings)) {
                    // Polylang's PLL_REMOVE_ALL_DATA branch deletes its
                    // widget family but leaves the core sidebar assignment.
                    // A compiled file-owned sidebar is complete replacement
                    // authority, so preserve a deterministic target-only
                    // marker for ApplyPlanner to delete. Omitting the
                    // assignment would make an empty desired sidebar compare
                    // equal and leave native residue behind. Capture and
                    // unowned sidebars still refuse.
                    if (self::canonical_owns_sidebar($canonicalTree, $sidebar)) {
                        $uuid = Uuid::v5(Uuid::NAMESPACE_WPRISM, "unmanaged-widget:$type:$local");
                        $widgets[] = [
                            'uuid' => $uuid,
                            'type' => $type,
                            'settings' => (object) ['_wprism_unmanaged' => true],
                        ];
                        $warnings[] = "sidebar '$sidebar' has contentless declared widget residue '$instanceKey'; "
                            . 'retained as deterministic target-only deletion evidence because the compiled repository owns the complete sidebar';
                        continue;
                    }
                    throw new \RuntimeException("wprism: $instanceKey is absent from option widget_$type or is not a settings object");
                }
                $kind = self::kind($type);
                $uuid = Ledger::uuid_for($local, $kind);
                if ($uuid !== null) {
                    // uuid_for() proves only the local half. A stale/manual
                    // tuple with the wrong entity type or reverse binding is
                    // not authority for this selected physical widget.
                    Ledger::require_read_only_mapping(
                        $uuid,
                        'widget',
                        $kind,
                        $local,
                        "widget '$instanceKey'"
                    );
                }
                if ($strictReadOnly && $uuid === null) {
                    throw new \RuntimeException(
                        "wprism: refresh export refused — widget '$instanceKey' has no durable ledger identity; "
                        . 'run the existing capture/identity recovery gate before exporting production'
                    );
                }
                if ($uuid === null && $mint) {
                    $uuid = Uuid::v7();
                    Ledger::set($uuid, 'widget', $kind, $local);
                }
                $portable = self::capture_settings(
                    $type, $settings, $declared[$type], $policy, $tokens, $sidebar, $forceUnresolvedRefs
                );
                if ($uuid === null) {
                    // Snapshot-only marker: makes fresh target defaults visible
                    // in plan without claiming durable identity for them.
                    $uuid = Uuid::v5(Uuid::NAMESPACE_WPRISM, "unmanaged-widget:$type:$local");
                    $portable = ['_wprism_unmanaged' => true] + $portable;
                }
                $widgets[] = ['uuid' => $uuid, 'type' => $type, 'settings' => (object) $portable];
            }
            $front = ['widgets' => $widgets];
            $entities[] = [
                'uuid' => self::key($sidebar), 'type' => self::ENTITY_TYPE,
                'path' => self::path($sidebar), 'content' => Canon::encode($front),
            ];
        }
        foreach (array_keys($requested) as $instanceKey) {
            if (!isset($seen[$instanceKey])) {
                throw new \RuntimeException(
                    'wprism: portable block widget reference is stale or absent from sidebars_widgets ('
                    . self::identity_fingerprint($instanceKey) . ')'
                );
            }
        }
        return ['entities' => $entities, 'warnings' => $warnings];
    }

    private static function canonical_owns_sidebar(?array $tree, string $sidebar): bool {
        $entity = $tree[self::key($sidebar)] ?? null;
        return is_array($entity)
            && ($entity['type'] ?? null) === self::ENTITY_TYPE
            && ($entity['path'] ?? null) === self::path($sidebar)
            && is_array($entity['data']['widgets'] ?? null);
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

    /**
     * Count undeclared families and active layout references without returning
     * settings. Init must use the same bounded reader and native array grammar
     * as Capture: an option-only exclusion cannot authorize an incomplete
     * sidebar file (capture() refuses every undeclared active assignment).
     *
     * @return array{families:array<string,array{type:string,instances:int,class:?string}>,active:array<string,list<string>>}
     */
    public static function unmanaged_inventory(Policy $policy): array {
        $declared = $policy->widget_types();
        $families = [];
        self::load_widget_options(
            $policy,
            $declared,
            false,
            static function (string $name, string $type, array $instances) use ($policy, $declared, &$families): void {
                if ($instances === [] || isset($declared[$type])) return;
                $classification = $policy->option_rule($name);
                $families[$name] = [
                    'type' => $type,
                    'instances' => count($instances),
                    'class' => $classification['class'] ?? null,
                ];
            }
        );
        $sidebars = self::load_sidebars_option();
        unset($sidebars['array_version'], $sidebars['wp_inactive_widgets']);
        ksort($sidebars, SORT_STRING);
        $active = [];
        foreach ($sidebars as $sidebar => $instanceKeys) {
            if (!is_string($sidebar) || $sidebar === '' || str_contains($sidebar, '/')
                || !is_array($instanceKeys) || !array_is_list($instanceKeys)) {
                throw new \RuntimeException("wprism: sidebars_widgets has an invalid sidebar '$sidebar' shape");
            }
            foreach ($instanceKeys as $position => $instanceKey) {
                $parsed = is_string($instanceKey) ? self::parse_widget_instance_key($instanceKey) : null;
                if ($parsed === null) {
                    throw new \RuntimeException("wprism: sidebar '$sidebar' has malformed widget instance id at position $position");
                }
                [$type] = $parsed;
                if (!isset($declared[$type]) && !in_array($sidebar, $active[$type] ?? [], true)) {
                    $active[$type][] = $sidebar;
                }
            }
        }
        ksort($active, SORT_STRING);
        return ['families' => $families, 'active' => $active];
    }

    /**
     * Validate the multi-instance family before any row is used.
     * @param ?\Closure(string,string,array<int,array<string,mixed>>):void $observeFamily
     */
    private static function load_widget_options(
        Policy $policy,
        array $declared,
        bool $scanUndeclared,
        ?\Closure $observeFamily = null
    ): array {
        global $wpdb;
        $wpdb->last_error = '';
        $preflight = $wpdb->get_results(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
            . 'SHA2(option_value, 256) AS option_value_sha256 '
            . "FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' "
            . 'ORDER BY option_name ASC, option_id ASC LIMIT ' . (self::MAX_WIDGET_FAMILIES + 1),
            ARRAY_A
        );
        if (!is_array($preflight)
            || !array_is_list($preflight)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: bounded widget option size preflight failed');
        }
        if (count($preflight) > self::MAX_WIDGET_FAMILIES) {
            throw new \RuntimeException('wprism: widget option family exceeds the bounded row limit');
        }
        $expected = [];
        $aggregateBytes = 0;
        foreach ($preflight as $position => $row) {
            if (!is_array($row)
                || array_keys($row) !== ['option_name', 'option_value_bytes', 'option_value_sha256']
                || !is_string($row['option_name'] ?? null)) {
                throw new \RuntimeException(
                    "wprism: widget option size preflight returned a malformed row at bounded position $position"
                );
            }
            self::assert_option_name($row['option_name'], 'widget option family');
            if (!str_starts_with($row['option_name'], 'widget_')) {
                throw new \RuntimeException('wprism: widget option family contains a collation alias');
            }
            $valueBytes = self::canonical_size($row['option_value_bytes'] ?? null);
            $valueHash = self::canonical_sha256($row['option_value_sha256'] ?? null);
            if ($valueBytes === null || $valueHash === null || $valueBytes > self::MAX_OPTION_VALUE_BYTES) {
                throw new \RuntimeException('wprism: widget option family exceeds the bounded value frontier');
            }
            $folded = strtolower($row['option_name']);
            if (isset($expected[$folded])) {
                throw new \RuntimeException('wprism: widget option family contains duplicate/collation-alias rows');
            }
            $expected[$folded] = [
                'name' => $row['option_name'],
                'bytes' => $valueBytes,
                'sha256' => $valueHash,
            ];
            $aggregateBytes += strlen($row['option_name']) + $valueBytes;
            if ($aggregateBytes > self::MAX_WIDGET_FAMILY_BYTES) {
                throw new \RuntimeException('wprism: widget option family exceeds the bounded aggregate frontier');
            }
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' "
            . 'ORDER BY option_name ASC, option_id ASC LIMIT ' . (self::MAX_WIDGET_FAMILIES + 1),
            ARRAY_A
        );
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== ''
            || count($rows) !== count($preflight)) {
            throw new \RuntimeException('wprism: bounded widget option read failed or changed after size preflight');
        }
        $out = [];
        foreach ($rows as $position => $row) {
            if (!is_array($row)
                || array_keys($row) !== ['option_name', 'option_value']
                || !is_string($row['option_name'] ?? null)
                || !is_string($row['option_value'] ?? null)) {
                throw new \RuntimeException(
                    "wprism: bounded widget option read returned a malformed row at position $position"
                );
            }
            $name = $row['option_name'];
            $descriptor = $expected[strtolower($name)] ?? null;
            if (!is_array($descriptor)
                || !hash_equals($descriptor['name'], $name)
                || $descriptor['bytes'] !== strlen($row['option_value'])
                || !hash_equals($descriptor['sha256'], hash('sha256', $row['option_value']))) {
                throw new \RuntimeException('wprism: widget option row identity/length changed after size preflight');
            }
            $type = substr($name, 7);
            $instances = self::decode_widget_family($name, $row['option_value']);
            if ($observeFamily !== null) $observeFamily($name, $type, $instances);
            if ($instances && !isset($declared[$type]) && $scanUndeclared) {
                // issue #3264: the deliberate-exclusion escape hatch every
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
                    "wprism: widget option '$name' contains instances but type '$type' is undeclared -- either add "
                    . "\"$type\" to a pinned manifest's widgets{} grammar (see "
                    . "platform/adapter-library/core/manifest.json's "
                    . 'widgets.block/nav_menu/text for the shape) if its settings should be portable, or declare it '
                    . "a deliberate exclusion (wp wprism classify --set='options:$name=runtime') if not"
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
        $row = self::read_exact_option(self::SIDEBARS_OPTION, 'sidebars option');
        if ($row === null) {
            return [];
        }
        return self::decode_sidebars_option($row['option_value']);
    }

    private static function decode_sidebars_option(string $raw): array {
        $value = PlainData::decode($raw, 'option sidebars_widgets');
        if (!is_array($value)) {
            throw new \RuntimeException('wprism: option sidebars_widgets is not an array');
        }
        if (count($value) > self::MAX_SIDEBARS + 2) {
            throw new \RuntimeException('wprism: option sidebars_widgets exceeds the bounded sidebar limit');
        }
        if (array_key_exists('array_version', $value)
            && (!is_int($value['array_version']) || $value['array_version'] !== 3)) {
            throw new \RuntimeException('wprism: option sidebars_widgets has an invalid array_version; expected integer 3');
        }
        $assignments = 0;
        $seen = [];
        foreach ($value as $sidebar => $keys) {
            if ($sidebar === 'array_version') {
                continue;
            }
            if (!is_string($sidebar)) {
                throw new \RuntimeException('wprism: option sidebars_widgets contains a non-string sidebar identity');
            }
            self::assert_sidebar_name($sidebar);
            if (!is_array($keys) || !array_is_list($keys)) {
                throw new \RuntimeException('wprism: option sidebars_widgets contains a malformed assignment list');
            }
            $assignments += count($keys);
            if ($assignments > self::MAX_SIDEBAR_ASSIGNMENTS) {
                throw new \RuntimeException('wprism: option sidebars_widgets exceeds the bounded assignment limit');
            }
            foreach ($keys as $position => $instanceKey) {
                if (!is_string($instanceKey) || self::parse_widget_instance_key($instanceKey) === null) {
                    throw new \RuntimeException(
                        'wprism: option sidebars_widgets sidebar identity fingerprint '
                        . self::identity_fingerprint($sidebar)
                        . " has a malformed widget assignment at position $position"
                    );
                }
                if (isset($seen[$instanceKey])) {
                    throw new \RuntimeException(
                        'wprism: option sidebars_widgets assigns one widget instance more than once ('
                        . self::identity_fingerprint($instanceKey) . ')'
                    );
                }
                $seen[$instanceKey] = true;
            }
        }
        return $value;
    }

    /** @return array<int,array<string,mixed>> */
    private static function decode_widget_family(string $name, string $raw): array {
        return self::decode_widget_family_state($name, $raw)['instances'];
    }

    /**
     * @return array{
     *   instances:array<int,array<string,mixed>>,
     *   marker:int|string|null,
     *   order:list<int|string>
     * }
     */
    private static function decode_widget_family_state(string $name, string $raw): array {
        $value = PlainData::decode($raw, "option '$name'");
        if (!is_array($value)) {
            throw new \RuntimeException("wprism: widget option '$name' is not a multi-instance array");
        }
        if (count($value) > self::MAX_WIDGET_INSTANCES_PER_FAMILY + 1) {
            throw new \RuntimeException("wprism: widget option '$name' exceeds the bounded instance limit");
        }
        $instances = [];
        $marker = null;
        $order = [];
        foreach ($value as $key => $settings) {
            if ((string) $key === '_multiwidget') {
                if (!in_array($settings, [1, '1'], true)) {
                    throw new \RuntimeException("wprism: widget option '$name' has an invalid _multiwidget marker");
                }
                $marker = $settings;
                $order[] = '_multiwidget';
                continue;
            }
            $local = self::canonical_positive_decimal($key);
            if ($local === null || !is_array($settings)) {
                throw new \RuntimeException("wprism: widget option '$name' is not a valid _multiwidget family shape");
            }
            if (count($settings) > self::MAX_WIDGET_SETTINGS_PER_INSTANCE) {
                throw new \RuntimeException("wprism: widget option '$name' exceeds the bounded settings limit");
            }
            if (array_key_exists($local, $instances)) {
                throw new \RuntimeException("wprism: widget option '$name' contains duplicate canonical instance identities");
            }
            $instances[$local] = $settings;
            $order[] = $local;
        }
        return ['instances' => $instances, 'marker' => $marker, 'order' => $order];
    }

    /**
     * @return array{
     *   widgets:array<string,array<int,array<string,mixed>>>,
     *   markers:array<string,int|string|null>,
     *   orders:array<string,list<int|string>>,
     *   present:array<string,bool>,
     *   sidebars:array<string,mixed>
     * }
     */
    private static function load_locked_sidebar_state(array $declared): array {
        $types = array_map('strval', array_keys($declared));
        sort($types, SORT_STRING);
        $names = [self::SIDEBARS_OPTION];
        foreach ($types as $type) {
            $names[] = 'widget_' . $type;
        }
        sort($names, SORT_STRING);
        $rows = [];
        foreach ($names as $name) {
            $rows[$name] = self::lock_authored_option_row(
                $name,
                "sidebar option '$name' locking"
            );
        }
        $widgets = [];
        $markers = [];
        $orders = [];
        $present = [];
        foreach ($types as $type) {
            $name = 'widget_' . $type;
            $row = $rows[$name];
            $state = $row === null
                ? ['instances' => [], 'marker' => null, 'order' => []]
                : self::decode_widget_family_state($name, $row['option_value']);
            $widgets[$type] = $state['instances'];
            $markers[$type] = $state['marker'];
            $orders[$type] = $state['order'];
            $present[$type] = $row !== null;
        }
        $sidebarsRow = $rows[self::SIDEBARS_OPTION];
        return [
            'widgets' => $widgets,
            'markers' => $markers,
            'orders' => $orders,
            'present' => $present,
            'sidebars' => $sidebarsRow === null
                ? []
                : self::decode_sidebars_option($sidebarsRow['option_value']),
        ];
    }

    /**
     * issue #3318 review (S4): the DECLARATION grammar is Policy's, for every
     * reader — one implementation, exactly as Snapshot's schema assertions
     * delegate their pure half to Policy::assert_table_grammar(). This file
     * had its own hand-copy of the same five rules, reachable only once a
     * sidebar was actually captured or applied (so it needed a live
     * WordPress), and it had drifted three ways in the permissive direction:
     * an empty `settings` map, a settings LIST, and an explicitly-null
     * `codec`/`ref` all passed here and were refused by the load-time copy.
     *
     * What stays is the one check that is genuinely this file's: the derived
     * `widget_<type>` ledger kind has to FIT wprism_map.id_kind, which is
     * the stored identity contract rather than only the widget declaration
     * grammar. Its pure width is shared with Ledger's schema so compiler and
     * scanner partial loads do not construct a database-writer dependency.
     */
    private static function assert_declared_types(array $declared): void {
        foreach ($declared as $type => $rule) {
            Policy::assert_widget_grammar((string) $type, $rule);
            if (strlen(self::kind((string) $type)) > ReferenceKindGrammar::LEDGER_KIND_WIDTH) {
                throw new \RuntimeException(
                    "wprism: over-budget manifest widget type '$type' — its derived identity kind '"
                    . self::kind((string) $type) . "' exceeds wprism_map.id_kind (VARCHAR("
                    . ReferenceKindGrammar::LEDGER_KIND_WIDTH . '))'
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
                "wprism: widget_$type in sidebar '$sidebar' has undeclared setting(s): " . implode(', ', $unknown)
            );
        }
        $out = [];
        foreach ($settings as $key => $value) {
            $rule = (array) $rules[$key];
            $secret = empty($rule['allow_secret'])
                ? Secrets::clearance_match_deep((string) $key, $value)
                : null;
            if ($secret !== null) {
                throw new \RuntimeException(
                    "wprism: widget_$type setting '$key' in sidebar '$sidebar' contains a secret ($secret); "
                    . 'refusing capture without allow_secret=true'
                );
            }
            $pii = empty($rule['allow_pii']) ? PersonalData::match_deep((string) $key, $value) : null;
            if ($pii !== null) {
                throw new \RuntimeException(
                    "wprism: widget_$type setting '$key' in sidebar '$sidebar' contains personal data ($pii); "
                    . 'refusing capture without allow_pii=true'
                );
            }
            if (($rule['codec'] ?? '') === 'blocks') {
                if (!is_string($value)) {
                    throw new \RuntimeException("wprism: widget_$type setting '$key' must be block-content text");
                }
                $out[$key] = Blocks::capture_rewrite(
                    $value, $policy, $tokens, $forceUnresolvedRefs, "sidebar '$sidebar'"
                );
            } elseif (in_array(($rule['ref'] ?? ''), ['term', 'post'], true)) {
                $kind = (string) $rule['ref'];
                $id = self::captured_reference_id($value, $type, (string) $key, $sidebar, $kind);
                $out[$key] = $id === null ? null : ($tokens->id_to_token($id, $kind)
                    ?? throw new \RuntimeException(
                        "wprism: widget_$type setting '$key' references an unmanaged $kind row"
                    ));
            } else {
                $out[$key] = self::rewrite_strings($value, fn(string $s): string => $tokens->tokenize_text($s));
            }
        }
        return $out;
    }

    private static function apply_settings(string $type, array $settings, array $decl, Policy $policy, Tokens $tokens): array {
        unset($settings['_wprism_unmanaged']);
        $out = [];
        foreach ($settings as $key => $value) {
            $rule = (array) (($decl['settings'] ?? [])[$key] ?? []);
            if (($rule['codec'] ?? '') === 'blocks') {
                $out[$key] = Blocks::apply_rewrite((string) $value, $policy, $tokens);
            } elseif (in_array(($rule['ref'] ?? ''), ['term', 'post'], true)) {
                $kind = (string) $rule['ref'];
                if ($value === null) {
                    $out[$key] = 0;
                    continue;
                }
                if (!is_string($value)
                    || preg_match('/^\{\{' . preg_quote($kind, '/') . ':[0-9a-f-]{36}\}\}$/D', $value) !== 1) {
                    throw new \RuntimeException(
                        "wprism: widget_$type setting '$key' must be null or one canonical {{"
                        . $kind . ':uuid}} token'
                    );
                }
                $out[$key] = $tokens->token_to_id($value);
            } else {
                $out[$key] = self::rewrite_strings($value, fn(string $s): string => $tokens->detokenize_text($s));
            }
        }
        return $out;
    }

    /**
     * WordPress widget options come from legacy form input and may preserve a
     * canonical decimal string, while the normal update path stores an int.
     * Admit only those two exact positive forms plus WordPress's four unset
     * spellings; a loose `(int)` cast would turn booleans, floats, garbage,
     * leading zeroes, and overflow into a different entity reference.
     */
    private static function captured_reference_id(
        mixed $value,
        string $type,
        string $key,
        string $sidebar,
        string $kind
    ): ?int {
        if (in_array($value, [null, '', 0, '0'], true)) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (string) (int) $value === $value
            && (int) $value > 0) {
            return (int) $value;
        }
        throw new \RuntimeException(
            "wprism: widget_$type setting '$key' in sidebar '$sidebar' must be an exact positive $kind id "
            . 'or the native unset value'
        );
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
    public static function ensure_widgets(Policy $policy, array $tree, ?DatabaseWorkAuthority $workAuthority = null): void {
        $declared = $policy->widget_types();
        $options = self::load_locked_sidebar_state($declared)['widgets'];
        $used = [];
        foreach ($options as $type => $instances) {
            $used[$type] = array_fill_keys(array_keys($instances), true);
        }
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== self::ENTITY_TYPE) {
                continue;
            }
            foreach ((array) ($entity['data']['widgets'] ?? []) as $widget) {
                DatabaseQueryIsolation::work_unit($workAuthority, function () use ($widget, &$used): void {
                    $type = (string) ($widget['type'] ?? '');
                    $uuid = (string) ($widget['uuid'] ?? '');
                    $kind = self::kind($type);
                    $local = Ledger::id_for($uuid, $kind);
                    if ($local !== null) {
                        $used[$type][$local] = true;
                        return;
                    }
                    $local = 1;
                    while (isset($used[$type][$local])) {
                        $local++;
                    }
                    $used[$type][$local] = true;
                    Ledger::set($uuid, 'widget', $kind, $local);
                });
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
        $state = self::load_locked_sidebar_state($declared);
        $options = $state['widgets'];
        $markers = $state['markers'];
        $orders = $state['orders'];
        $present = $state['present'];
        $sidebars = $state['sidebars'];
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
        $inactiveOverlay = $sidebar === 'wp_inactive_widgets';
        $inactiveAssignments = array_fill_keys(
            array_values(array_filter(
                (array) ($sidebars['wp_inactive_widgets'] ?? []),
                static fn($key): bool => is_string($key)
            )),
            true
        );
        $preserveFamilyOrder = [];
        foreach (array_keys($inactiveAssignments) as $inactiveKey) {
            $parsedInactive = self::parse_widget_instance_key($inactiveKey);
            if ($parsedInactive !== null) {
                $preserveFamilyOrder[$parsedInactive[0]] = true;
            }
        }
        foreach ($inactiveOverlay ? [] : (array) ($sidebars[$sidebar] ?? []) as $oldKey) {
            $parsed = is_string($oldKey) ? self::parse_widget_instance_key($oldKey) : null;
            if ($parsed === null || isset($globallyDesired[$oldKey])) continue;
            [$oldType, $oldLocal] = $parsed;
            if (isset($declared[$oldType])) {
                $oldUuid = Ledger::uuid_for($oldLocal, self::kind($oldType));
                if (array_key_exists($oldLocal, $options[$oldType])) {
                    unset($options[$oldType][$oldLocal]);
                    $touchedTypes[$oldType] = true;
                }
                if ($oldUuid !== null) Ledger::forget($oldUuid);
            }
        }
        $keys = [];
        foreach ((array) ($front['widgets'] ?? []) as $widget) {
            $type = (string) $widget['type'];
            $uuid = (string) $widget['uuid'];
            $local = Ledger::id_for($uuid, self::kind($type));
            if ($local === null) throw new \RuntimeException("wprism: widget $uuid has no allocated $type identity");
            $options[$type][$local] = self::apply_settings(
                $type, (array) $widget['settings'], $declared[$type], $policy, $tokens
            );
            if ($inactiveOverlay || isset($inactiveAssignments["$type-$local"])) {
                $preserveFamilyOrder[$type] = true;
            }
            $touchedTypes[$type] = true;
            $keys[] = "$type-$local";
        }
        if ($writeTouchedOnly) {
            ksort($touchedTypes, SORT_STRING);
        }
        $optionWriteTypes = ($writeTouchedOnly || $inactiveOverlay)
            ? array_keys($touchedTypes)
            : array_keys($declared);
        foreach ($optionWriteTypes as $type) {
            if (isset($preserveFamilyOrder[$type])) {
                $stored = [];
                $seenStoredKeys = [];
                foreach ($orders[$type] as $orderedKey) {
                    if ($orderedKey === '_multiwidget') {
                        $stored['_multiwidget'] = $markers[$type] ?? 1;
                        $seenStoredKeys['_multiwidget'] = true;
                        continue;
                    }
                    if (is_int($orderedKey) && array_key_exists($orderedKey, $options[$type])) {
                        $stored[$orderedKey] = $options[$type][$orderedKey];
                        $seenStoredKeys[(string) $orderedKey] = true;
                    }
                }
                foreach ($options[$type] as $local => $settings) {
                    if (!isset($seenStoredKeys[(string) $local])) {
                        $stored[$local] = $settings;
                    }
                }
                if (!isset($seenStoredKeys['_multiwidget']) && !$present[$type]) {
                    $stored['_multiwidget'] = $markers[$type] ?? 1;
                }
            } else {
                ksort($options[$type], SORT_NUMERIC);
                $stored = $options[$type];
                $stored['_multiwidget'] = $markers[$type] ?? 1;
            }
            $name = 'widget_' . $type;
            $wire = maybe_serialize($stored);
            $locked = self::lock_authored_option_row($name, "apply widget_$type option locking");
            $autoload = $locked['autoload'] ?? 'yes';
            if ($locked === null) {
                Db::insert(
                    $GLOBALS['wpdb']->options,
                    ['option_name' => $name, 'option_value' => $wire, 'autoload' => $autoload],
                    null,
                    "apply widget_$type option"
                );
            } else {
                Db::update(
                    $GLOBALS['wpdb']->options,
                    ['option_value' => $wire],
                    ['option_name' => $name],
                    null,
                    null,
                    "apply widget_$type option"
                );
            }
            self::queue_authored_option(
                $name,
                "apply widget_$type option"
            );
            self::assert_authored_option_row(
                $name,
                $wire,
                $autoload,
                "apply widget_$type option readback"
            );
        }
        // A WordPress widget instance may be assigned to only one active
        // sidebar. If an owned UUID was moved locally, force-theirs must
        // restore its declared assignment without duplicating the instance;
        // unrelated keys in the other sidebar remain untouched.
        $desiredKeys = array_fill_keys($keys, true);
        foreach ($sidebars as $otherSidebar => $otherKeys) {
            if ($otherSidebar === $sidebar || $otherSidebar === 'array_version' || !is_array($otherKeys)) {
                continue;
            }
            $sidebars[$otherSidebar] = array_values(array_filter(
                $otherKeys,
                static fn($key): bool => !is_string($key) || !isset($desiredKeys[$key])
            ));
        }
        if ($inactiveOverlay) {
            $merged = [];
            foreach ((array) ($sidebars[$sidebar] ?? []) as $oldKey) {
                if (is_string($oldKey)) {
                    $merged[$oldKey] = true;
                }
            }
            foreach ($keys as $key) {
                $merged[$key] = true;
            }
            $sidebars[$sidebar] = array_keys($merged);
        } else {
            $sidebars[$sidebar] = $keys;
        }
        $sidebars['array_version'] = max(3, (int) ($sidebars['array_version'] ?? 3));
        $wire = maybe_serialize($sidebars);
        $locked = self::lock_authored_option_row(
            self::SIDEBARS_OPTION,
            "apply sidebar '$sidebar' option locking"
        );
        $autoload = $locked['autoload'] ?? 'yes';
        if ($locked === null) {
            Db::insert(
                $GLOBALS['wpdb']->options,
                ['option_name' => self::SIDEBARS_OPTION, 'option_value' => $wire, 'autoload' => $autoload],
                null,
                "apply sidebar '$sidebar'"
            );
        } else {
            Db::update(
                $GLOBALS['wpdb']->options,
                ['option_value' => $wire],
                ['option_name' => self::SIDEBARS_OPTION],
                null,
                null,
                "apply sidebar '$sidebar'"
            );
        }
        self::queue_authored_option(self::SIDEBARS_OPTION, "apply sidebar '$sidebar'");
        self::assert_authored_option_row(
            self::SIDEBARS_OPTION,
            $wire,
            $autoload,
            "apply sidebar '$sidebar' readback"
        );
    }

    public static function sidebar_from_path(string $path): ?string {
        return preg_match('#^sidebars/([^/]+)\.json$#', $path, $m) ? $m[1] : null;
    }

    public static function inactive_warning(): ?string {
        $sidebars = self::load_sidebars_option();
        return !empty($sidebars['wp_inactive_widgets'])
            ? self::inactive_exclusion_warning()
            : null;
    }

    private static function inactive_exclusion_warning(): string {
        return 'unreferenced wp_inactive_widgets entries are target-owned; parked widget content will not propagate';
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
                Db::mutation(
                    "DELETE FROM {$wpdb->prefix}wprism_map",
                    $wpdb->prepare(
                        'uuid = %s AND id_kind = %s',
                        $row['uuid'],
                        $row['id_kind']
                    ),
                    '',
                    'ledger prune dead widget identity'
                );
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
                        "wprism: mapped widget identity $uuid ($kind) from " . basename($file)
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
                $parsed = is_string($instanceKey) ? self::parse_widget_instance_key($instanceKey) : null;
                if ($parsed === null) {
                    continue;
                }
                [$type, $local] = $parsed;
                if (isset($declared[$type]) && Ledger::uuid_for($local, self::kind($type)) === null) {
                    throw new \RuntimeException(
                        "wprism: cannot export identity sidecar: owned widget '$instanceKey' in sidebar '$sidebar' "
                        . 'has no ledger identity; capture it first or restore the missing sidecar'
                    );
                }
            }
        }
    }

    public static function witness(string $type, int $local): string {
        $name = 'widget_' . $type;
        $row = self::read_exact_option($name, 'widget identity witness');
        $value = $row === null ? null : PlainData::decode($row['option_value'], "option widget_$type");
        if (!is_array($value) || !isset($value[$local]) || !is_array($value[$local])) {
            throw new \RuntimeException("wprism: widget identity row widget_$type:$local is missing");
        }
        return hash('sha256', Canon::encode(['kind' => self::kind($type), 'local_id' => $local, 'settings' => $value[$local]]));
    }

    /** @return ?array{option_value:string} */
    private static function read_exact_option(string $name, string $where): ?array {
        global $wpdb;
        self::assert_option_name($name, $where);
        $wpdb->last_error = '';
        $preflight = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
            . 'SHA2(option_value, 256) AS option_value_sha256 '
            . "FROM {$wpdb->options} WHERE option_name = %s ORDER BY option_id ASC LIMIT 2",
            $name
        ), ARRAY_A);
        if (!is_array($preflight)
            || !array_is_list($preflight)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $where size preflight failed");
        }
        if (count($preflight) > 1) {
            throw new \RuntimeException("wprism: $where found duplicate/collation-alias option rows");
        }
        if ($preflight === []) {
            return null;
        }
        $size = $preflight[0];
        $bytes = is_array($size) ? self::canonical_size($size['option_value_bytes'] ?? null) : null;
        if (!is_array($size)
            || array_keys($size) !== ['option_name', 'option_value_bytes', 'option_value_sha256']
            || !is_string($size['option_name'] ?? null)
            || !hash_equals($name, $size['option_name'])
            || $bytes === null
            || self::canonical_sha256($size['option_value_sha256'] ?? null) === null
            || $bytes > self::MAX_OPTION_VALUE_BYTES) {
            throw new \RuntimeException("wprism: $where size/identity preflight is malformed or over the bounded frontier");
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} "
            . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
            $name
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || count($rows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $where exact bounded read failed or changed after preflight");
        }
        $row = $rows[0];
        $expectedHash = self::canonical_sha256($size['option_value_sha256'] ?? null);
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value']
            || !is_string($row['option_name'] ?? null)
            || !is_string($row['option_value'] ?? null)
            || !hash_equals($name, $row['option_name'])
            || strlen($row['option_value']) !== $bytes
            || !hash_equals((string) $expectedHash, hash('sha256', $row['option_value']))) {
            throw new \RuntimeException("wprism: $where exact bounded row changed after preflight");
        }
        return ['option_value' => $row['option_value']];
    }

    private static function assert_option_name(string $name, string $where): void {
        $characters = preg_match('//u', $name) === 1 ? preg_match_all('/./us', $name) : false;
        if ($name === ''
            || strlen($name) > self::MAX_OPTION_NAME_BYTES
            || !is_int($characters)
            || $characters > self::MAX_OPTION_NAME_CHARACTERS
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \RuntimeException("wprism: $where option identity is invalid or over the schema frontier");
        }
    }

    private static function canonical_size(mixed $value): ?int {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $size = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($size) ? $size : null;
    }

    private static function canonical_sha256(mixed $value): ?string {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1
            ? $value
            : null;
    }

    /** @return ?array{0:string,1:int} */
    private static function parse_widget_instance_key(string $key): ?array {
        if (strlen($key) > self::MAX_OPTION_NAME_BYTES
            || preg_match('/^([a-z0-9_-]+)-([1-9][0-9]*)$/D', $key, $match) !== 1
            || strlen(self::kind($match[1])) > ReferenceKindGrammar::LEDGER_KIND_WIDTH) {
            return null;
        }
        $local = self::canonical_positive_decimal($match[2]);
        return $local === null ? null : [$match[1], $local];
    }

    private static function canonical_positive_decimal(mixed $value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($parsed) && (string) $parsed === $value ? $parsed : null;
    }

    private static function assert_sidebar_name(string $name): void {
        $characters = preg_match('//u', $name) === 1 ? preg_match_all('/./us', $name) : false;
        if ($name === ''
            || str_contains($name, '/')
            || strlen($name) > self::MAX_SIDEBAR_NAME_BYTES
            || !is_int($characters)
            || $characters > self::MAX_SIDEBAR_NAME_CHARACTERS
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \RuntimeException(
                'wprism: option sidebars_widgets contains an invalid sidebar identity ('
                . self::identity_fingerprint($name) . ')'
            );
        }
    }

    private static function identity_fingerprint(string $identity): string {
        return 'bytes=' . strlen($identity) . ',sha256=' . substr(hash('sha256', $identity), 0, 16);
    }

    /** @return ?array{option_name:string,option_value:string,autoload:string} */
    private static function lock_authored_option_row(string $name, string $purpose): ?array {
        if (self::$lockOptionRow === null) {
            throw new \RuntimeException("wprism: $purpose requires the authored sidebar transaction");
        }
        $row = (self::$lockOptionRow)($name, $purpose);
        if ($row !== null && (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || !is_string($row['option_name'])
            || !is_string($row['option_value'])
            || !is_string($row['autoload']))) {
            throw new \RuntimeException("wprism: $purpose returned a malformed locked option row");
        }
        return $row;
    }

    private static function queue_authored_option(string $name, string $purpose): void {
        if (self::$queueOption === null) {
            throw new \RuntimeException("wprism: $purpose requires the authored sidebar transaction");
        }
        (self::$queueOption)($name, $purpose);
    }

    private static function assert_authored_option_row(
        string $name,
        string $value,
        string $autoload,
        string $purpose
    ): void {
        if (self::$assertOptionRow === null) {
            throw new \RuntimeException("wprism: $purpose requires the authored sidebar transaction");
        }
        (self::$assertOptionRow)($name, $value, $autoload, $purpose);
    }
}
