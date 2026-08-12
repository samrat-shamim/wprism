<?php
namespace Duo;

require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/OptionState.php';
require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/Snapshot.php';

/**
 * The pure conflict/display-projection half of plan production (DUO-3347
 * slice 2, first cut of the issue's "ApplyPlanner: immutable plan and
 * conflict production" target seam).
 *
 * The pure plan projections here read only explicit inputs plus the planner's
 * injected Policy — no `$wpdb`, no target reads, and no Apply state. The
 * live-DB-backed collision/adoption lookup in
 * `find_collision()`/`collision_parent_id()`/`one_collision()` is now the
 * first stateful planner responsibility here as well. Everything else
 * `build_plan()` does (the 600+ line comparison/guard-ref orchestration)
 * stays in `Apply` for a later slice, per the issue's "extract one
 * collaborator at a time" guardrail.
 *
 * `Apply` keeps `conflict_view()`, `forced_override_evidence()`,
 * `incomplete_override_refusal()`, `entity_display_title()`,
 * `lifecycle_comparison_hash()`, and `nested_delete_candidate_counts()` as
 * thin compatibility facades delegating here, so its own internal callers
 * need no behavior change.
 */
final class ApplyPlanner {
    /**
     * The planner owns the policy needed for typed-table collision lookup and
     * the already-memoized authored-table roster supplied by Apply. Keeping
     * the roster an explicit input avoids making this collaborator reach into
     * Apply for cache state or silently re-read the manifest on every entity.
     *
     * @param array<string,array> $snapshotRowTables
     * @param \Closure(string,string):?int $ledgerIdFor
     */
    public function __construct(
        private readonly Policy $policy,
        private readonly array $snapshotRowTables,
        private readonly \Closure $ledgerIdFor
    ) {
    }

    /** Same-slug target entity: managed with a different UUID or adoptable. */
    public function find_collision(array $e, array $tree, array &$cache): ?int {
        global $wpdb;
        $uuid = (string) ($e['data']['uuid'] ?? '');
        if ($uuid !== '' && array_key_exists($uuid, $cache)) {
            return $cache[$uuid];
        }
        if (isset($this->snapshotRowTables[$e['type']])) {
            // Natural-key identity tables only (for example, WooCommerce
            // attribute taxonomies pre-provisioned on the target) have a
            // collision concept. Mapped-identity tables return null here.
            // $tree and $cache remain explicit because a parent-scoped key
            // may name a parent row that is itself only adoptable.
            $id = Snapshot::find_collision($this->policy, $e, $tree, $cache, [], $this->ledgerIdFor);
            if ($uuid !== '') {
                $cache[$uuid] = $id;
            }
            return $id;
        }
        if ($e['type'] === 'post') {
            $front = $e['data'];
            $parentId = $this->collision_parent_id($front['parent'] ?? null, 'post', $tree, $cache);
            if (!empty($front['parent']) && $parentId === null) {
                return $cache[$uuid] = null;
            }
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_name = %s AND p.post_type = %s "
                . 'AND p.post_parent = %d ORDER BY p.ID ASC',
                $front['slug'], $front['type'], $parentId ?? 0
            )) ?: [];
            return $cache[$uuid] = $this->one_collision(
                $ids,
                "post {$front['type']}/{$front['slug']} under parent " . ($parentId ?? 0)
            );
        }
        if ($e['type'] === 'term' || $e['type'] === 'menu') {
            $front = $e['data'];
            $tax = $e['type'] === 'menu' ? 'nav_menu' : $front['taxonomy'];
            $slug = $front['slug'];
            $parentId = $e['type'] === 'menu'
                ? 0
                : $this->collision_parent_id($front['parent'] ?? null, 'term', $tree, $cache);
            if ($e['type'] !== 'menu' && !empty($front['parent']) && $parentId === null) {
                return $cache[$uuid] = null;
            }
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE t.slug = %s AND tt.taxonomy = %s AND tt.parent = %d ORDER BY t.term_id ASC",
                $slug, $tax, $parentId ?? 0
            )) ?: [];
            return $cache[$uuid] = $this->one_collision(
                $ids,
                "term $tax/$slug under parent " . ($parentId ?? 0)
            );
        }
        return null;
    }

    private function collision_parent_id($parentUuid, string $kind, array $tree, array &$cache): ?int {
        if ($parentUuid === null || $parentUuid === '') {
            return 0;
        }
        // Post parents are serialized through the ordinary typed-token
        // grammar; term parents are bare UUID fields. Normalize both to the
        // canonical parent UUID before consulting either ledger or tree.
        if (is_string($parentUuid)
            && preg_match('/^\{\{' . preg_quote($kind, '/') . ':([^}]+)\}\}$/', $parentUuid, $m)) {
            $parentUuid = $m[1];
        }
        // Keep the planner independent of Ledger's class-load boundary. These
        // are the stable id_kind values the injected resolver accepts; Apply's
        // compatibility facade translates them to Ledger constants at the
        // engine bootstrap boundary.
        $idKind = $kind === 'post' ? 'post' : 'term';
        $mapped = ($this->ledgerIdFor)((string) $parentUuid, $idKind);
        if ($mapped !== null) {
            return $mapped;
        }
        $parent = $tree[(string) $parentUuid] ?? null;
        if ($parent === null || $parent['type'] !== $kind) {
            return null;
        }
        return $this->find_collision($parent, $tree, $cache);
    }

    private function one_collision(array $ids, string $identity): ?int {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > 1) {
            throw new \RuntimeException(
                "duo: conflicting adoption key for $identity matches local ids " . implode(', ', $ids)
                . '; full natural identity must be unique before adoption'
            );
        }
        return $ids ? $ids[0] : null;
    }

    /**
     * Select the desired option records whose phase-2 rebuild must run.
     *
     * This is a pure projection over canonical option state and an optional
     * same-snapshot target observation. Target-only records are deliberately
     * ignored: omission is not deletion authority, and managed lifecycle
     * options are handled by Deploy rather than authored option materialization.
     * Keeping the policy lookup here prevents build_plan() from owning a
     * second copy of the planner's option-selection contract.
     *
     * @return list<string>
     */
    public function option_rebuild_names(array $desiredDocument, ?array $env): array {
        $desired = OptionState::records($desiredDocument);
        if ($env === null) {
            $names = [];
            foreach ($desired as $name => $record) {
                if (($record['state'] ?? null) === 'absent') {
                    continue;
                }
                if (($record['state'] ?? null) === 'present'
                    && (($this->policy->option_rule((string) $name)['class'] ?? null) === 'managed')) {
                    continue;
                }
                $names[] = (string) $name;
            }
            sort($names, SORT_STRING);
            return $names;
        }
        $envDocument = Canon::decode((string) ($env['content'] ?? ''));
        $observed = OptionState::records($envDocument);
        $names = [];
        foreach ($desired as $name => $record) {
            if (($record['state'] ?? null) === 'absent') {
                continue;
            }
            if (($record['state'] ?? null) === 'present'
                && (($this->policy->option_rule((string) $name)['class'] ?? null) === 'managed')) {
                continue;
            }
            if (!array_key_exists($name, $observed)
                || !hash_equals(
                    OptionState::record_hash($record),
                    OptionState::record_hash($observed[$name])
                )) {
                $names[] = (string) $name;
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Project nested deletion candidates from the exact snapshot/build-plan
     * evidence already in memory. No target, ledger, policy, provider, or
     * filesystem call is permitted here: a later read could describe a
     * different target moment from the plan it annotates.
     *
     * Menu observations are Capture's internal side channel from the same
     * MVCC build. Widget rows are narrowed against the GLOBAL desired UUID
     * set, matching SidebarState::finalize_sidebar() so a sidebar move is not
     * reported as deletion. Option names are deduplicated across the exact
     * three buckets Apply::run() turns into pending option deletes.
     *
     * @param array<string,array<string,mixed>> $env
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,mixed> $plan
     * @param array{menus_by_term_id:array<string,array{uuid:?string,managed_menu_item_uuids:list<string>,all_menu_item_count:int}>}|null $observations
     * @return array{menu:int,widget:int,option:int}|null
     */
    public static function nested_delete_candidate_counts(
        array $env,
        array $tree,
        array $plan,
        ?array $observations
    ): ?array {
        if (!is_array($observations) || !is_array($observations['menus_by_term_id'] ?? null)) {
            return null;
        }
        $menuObservationsByTerm = [];
        $menuObservationsByUuid = [];
        foreach ($observations['menus_by_term_id'] as $termId => $observation) {
            $termKey = (string) $termId;
            if ($termKey === '' || !ctype_digit($termKey) || (int) $termKey < 1
                || !is_array($observation)
                || !array_key_exists('uuid', $observation)
                || !($observation['uuid'] === null
                    || (is_string($observation['uuid']) && $observation['uuid'] !== ''))
                || !is_array($observation['managed_menu_item_uuids'] ?? null)
                || !array_is_list($observation['managed_menu_item_uuids'])
                || !is_int($observation['all_menu_item_count'] ?? null)
                || $observation['all_menu_item_count'] < 0) {
                return null;
            }
            $managedMenuItems = [];
            foreach ($observation['managed_menu_item_uuids'] as $itemUuid) {
                if (!is_string($itemUuid) || $itemUuid === ''
                    || isset($managedMenuItems[$itemUuid])) {
                    return null;
                }
                $managedMenuItems[$itemUuid] = true;
            }
            if (count($managedMenuItems) > $observation['all_menu_item_count']) {
                return null;
            }
            $menuObservationsByTerm[$termKey] = $observation;
            $menuUuid = $observation['uuid'];
            if (is_string($menuUuid)) {
                if (isset($menuObservationsByUuid[$menuUuid])) {
                    return null;
                }
                $menuObservationsByUuid[$menuUuid] = $observation;
            }
        }
        $menuCandidates = 0;
        $widgetCandidates = [];
        $optionCandidates = [];
        $globallyDesiredWidgets = [];

        foreach ($tree as $entry) {
            if (!is_array($entry)) {
                return null;
            }
            if (($entry['type'] ?? null) !== 'sidebar') {
                continue;
            }
            if (!is_array($entry['data'] ?? null)) {
                return null;
            }
            if (!array_key_exists('widgets', $entry['data'])) {
                return null;
            }
            $widgets = $entry['data']['widgets'];
            if (!is_array($widgets) || !array_is_list($widgets)) {
                return null;
            }
            foreach ($widgets as $widget) {
                if (!is_array($widget)) {
                    return null;
                }
                $type = $widget['type'] ?? null;
                $uuid = $widget['uuid'] ?? null;
                if (!is_string($type) || $type === '' || !is_string($uuid) || $uuid === '') {
                    return null;
                }
                $globallyDesiredWidgets[$type . "\0" . $uuid] = true;
            }
        }

        foreach (['create', 'adopt', 'update', 'conflict'] as $bucket) {
            if (!array_key_exists($bucket, $plan)
                || !is_array($plan[$bucket])
                || !array_is_list($plan[$bucket])) {
                return null;
            }
            $rows = $plan[$bucket];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    return null;
                }
                $uuid = $row['uuid'] ?? null;
                if (!is_string($uuid) || $uuid === '' || !is_array($tree[$uuid] ?? null)) {
                    return null;
                }
                $entry = $tree[$uuid];
                if (($entry['type'] ?? null) === 'menu') {
                    if ($bucket === 'adopt') {
                        $termId = $row['env_id'] ?? null;
                        if (!is_int($termId) || $termId < 1) {
                            return null;
                        }
                        $observation = $menuObservationsByTerm[(string) $termId] ?? null;
                    } elseif (!isset($env[$uuid])) {
                        // A genuinely new menu has no target items to remove.
                        continue;
                    } else {
                        $observation = $menuObservationsByUuid[$uuid] ?? null;
                    }
                    if (!is_array($observation)) {
                        return null;
                    }
                    $desired = [];
                    if (!is_array($entry['data'] ?? null)) {
                        return null;
                    }
                    if (!array_key_exists('items', $entry['data'])) {
                        return null;
                    }
                    $items = $entry['data']['items'];
                    if (!is_array($items) || !array_is_list($items)) {
                        return null;
                    }
                    foreach ($items as $item) {
                        $itemUuid = is_array($item) ? ($item['uuid'] ?? null) : null;
                        if (!is_string($itemUuid) || $itemUuid === '' || isset($desired[$itemUuid])) {
                            return null;
                        }
                        $desired[$itemUuid] = true;
                    }
                    foreach ($observation['managed_menu_item_uuids'] as $itemUuid) {
                        if (!is_string($itemUuid) || $itemUuid === '') {
                            return null;
                        }
                        if (!isset($desired[$itemUuid])) {
                            $menuCandidates++;
                        }
                    }
                    continue;
                }
                if (!isset($env[$uuid])) {
                    continue;
                }
                if (($entry['type'] ?? null) === 'sidebar') {
                    $widgetDeletes = $row['widget_deletes'] ?? [];
                    if (!is_array($widgetDeletes) || !array_is_list($widgetDeletes)) {
                        return null;
                    }
                    foreach ($widgetDeletes as $widget) {
                        if (!is_array($widget)) {
                            return null;
                        }
                        $type = $widget['type'] ?? null;
                        $widgetUuid = $widget['uuid'] ?? null;
                        if (!is_string($type) || $type === ''
                            || !is_string($widgetUuid) || $widgetUuid === '') {
                            return null;
                        }
                        $key = $type . "\0" . $widgetUuid;
                        if (!isset($globallyDesiredWidgets[$key])) {
                            $widgetCandidates[$key] = true;
                        }
                    }
                }
            }
        }

        foreach (['delete', 'delete_conflict'] as $bucket) {
            if (!array_key_exists($bucket, $plan)
                || !is_array($plan[$bucket])
                || !array_is_list($plan[$bucket])) {
                return null;
            }
            $rows = $plan[$bucket];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    return null;
                }
                if (($row['deletion_kind'] ?? null) !== 'menu') {
                    continue;
                }
                $uuid = $row['uuid'] ?? null;
                if (!is_string($uuid) || $uuid === '') {
                    return null;
                }
                $observation = $menuObservationsByUuid[$uuid] ?? null;
                if (!isset($env[$uuid]) || !is_array($observation)) {
                    return null;
                }
                $menuCandidates += $observation['all_menu_item_count'];
            }
        }

        foreach (['create', 'update', 'conflict'] as $bucket) {
            if (!array_key_exists($bucket, $plan)
                || !is_array($plan[$bucket])
                || !array_is_list($plan[$bucket])) {
                return null;
            }
            $rows = $plan[$bucket];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    return null;
                }
                $optionDeletes = $row['option_deletes'] ?? [];
                if (!is_array($optionDeletes) || !array_is_list($optionDeletes)) {
                    return null;
                }
                foreach ($optionDeletes as $name) {
                    if (!is_string($name) || $name === '') {
                        return null;
                    }
                    $optionCandidates[$name] = true;
                }
            }
        }

        return [
            'menu' => $menuCandidates,
            'widget' => count($widgetCandidates),
            'option' => count($optionCandidates),
        ];
    }

    /**
     * Stable, additive conflict evidence for plan JSON. The three roles are
     * deliberately semantic rather than value-bearing: repository entities
     * can contain secrets, PII, or plugin-owned opaque structures, so a plan
     * may expose their already-canonical hashes and intent but never copy raw
     * entity values into a diagnostic surface. `reconcile_in_repository` is
     * the non-destructive default; `apply_repository` maps the existing named
     * report-not-hide escape hatch and states every required flag.
     *
     * @param list<string> $applyRequirements
     * @return array<string,mixed>
     */
    public static function conflict_view(
        string $reasonCode,
        string $repositoryIntent,
        string $baseState,
        ?string $baseHash,
        ?string $repositoryHash,
        ?string $expectedBaseHash,
        ?string $intentReceiptHash,
        ?string $targetHash,
        array $applyRequirements
    ): array {
        $destructiveEffect = $repositoryIntent === 'delete'
            ? 'delete_target_authored_state'
            : 'replace_target_authored_state';

        return [
            'format' => 'duo-plan-conflict/v1',
            'kind' => $repositoryIntent === 'delete' ? 'tombstone_conflict' : 'concurrent_change',
            'reason_code' => $reasonCode,
            'base' => [
                'role' => 'last_synced',
                'source' => 'duo_state',
                'state' => $baseState,
                'content_hash' => $baseHash,
            ],
            'repository' => [
                'role' => 'repository_intent',
                'source' => 'compiled_repository',
                'intent' => $repositoryIntent,
                'content_hash' => $repositoryHash,
                'expected_base_hash' => $expectedBaseHash,
                'intent_receipt_hash' => $intentReceiptHash,
            ],
            'target' => [
                'role' => 'target_observation',
                'source' => 'live_target_snapshot',
                'intent' => 'preserve_target_change',
                'state' => 'present',
                'content_hash' => $targetHash,
            ],
            'recommended_choice' => 'reconcile_in_repository',
            'choices' => [
                [
                    'id' => 'reconcile_in_repository',
                    'effect' => 'preserve_and_reconcile_both_intents',
                    'requires' => [],
                    'destructive' => false,
                ],
                [
                    'id' => 'apply_repository',
                    'effect' => $destructiveEffect,
                    'requires' => array_values($applyRequirements),
                    'destructive' => true,
                ],
            ],
        ];
    }

    /**
     * Public failure evidence for a conflict override is intentionally less
     * identifying than the successful plan row: hash the canonical entity
     * identity so option/user-meta identities and WordPress names cannot leak
     * through a later JSON refusal. All remaining fields are engine-owned
     * enums already present in the reviewed conflict view.
     *
     * A referentially blocked tombstone deliberately does not advertise the
     * destructive apply_repository choice. Report required and actually
     * supplied flags separately; only their exact equality is authorization.
     * This keeps a partial attempt truthful and never fabricates the
     * suppressed plan choice.
     *
     * @return array<string,mixed>
     */
    public static function forced_override_evidence(array $row, string $bucket, array $opts): array {
        $view = (array) ($row['conflict_view'] ?? []);
        $choice = [];
        foreach ((array) ($view['choices'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === 'apply_repository') {
                $choice = $candidate;
                break;
            }
        }
        $kind = in_array($view['kind'] ?? null, ['concurrent_change', 'tombstone_conflict'], true)
            ? (string) $view['kind']
            : 'concurrent_change';
        $reasonCode = preg_match('/^[a-z][a-z0-9_]{2,63}$/', (string) ($view['reason_code'] ?? '')) === 1
            ? (string) $view['reason_code']
            : 'plan_conflict';
        $isDeletion = $bucket === 'delete_conflict' || $kind === 'tombstone_conflict';
        $effect = in_array(
            $choice['effect'] ?? null,
            ['replace_target_authored_state', 'delete_target_authored_state'],
            true
        ) ? (string) $choice['effect'] : ($isDeletion
            ? 'delete_target_authored_state'
            : 'replace_target_authored_state');
        $choiceId = $choice === [] ? 'explicit_force_flags' : 'apply_repository';
        $requiredFlags = $choice === []
            ? ($isDeletion ? ['--with-deletes', '--force-theirs'] : ['--force-theirs'])
            : array_values(array_filter(
                (array) ($choice['requires'] ?? []),
                static fn($flag): bool => is_string($flag)
                    && in_array($flag, ['--force-theirs', '--with-deletes'], true)
        ));
        $guardOverride = $isDeletion && array_key_exists('blocked', $row);
        if ($guardOverride) {
            $requiredFlags[] = '--force-delete-referenced';
        }
        $requiredFlags = array_values(array_unique($requiredFlags));
        $flagOptions = [
            '--with-deletes' => 'with_deletes',
            '--force-theirs' => 'force_theirs',
            '--force-delete-referenced' => 'force_delete_referenced',
        ];
        $suppliedFlags = [];
        foreach ($requiredFlags as $flag) {
            $option = $flagOptions[$flag] ?? null;
            if ($option !== null && !empty($opts[$option])) {
                $suppliedFlags[] = $flag;
            }
        }
        $status = $suppliedFlags === $requiredFlags ? 'authorized' : 'incomplete';

        $evidence = [
            'format' => 'duo-forced-plan-override/v1',
            'plan_bucket' => $bucket === 'delete_conflict' ? 'delete_conflict' : 'conflict',
            'entity_identity_sha256' => hash('sha256', (string) ($row['uuid'] ?? '')),
            'conflict_kind' => $kind,
            'reason_code' => $reasonCode,
            'choice' => $choiceId,
            'effect' => $effect,
            'required_flags' => $requiredFlags,
            'supplied_flags' => $suppliedFlags,
            'status' => $status,
        ];
        if ($guardOverride && in_array('--force-delete-referenced', $suppliedFlags, true)) {
            $evidence['guard_override'] = 'force_delete_referenced';
        }
        return $evidence;
    }

    /**
     * A requested destructive conflict override is not authorization until
     * every advertised gate is present. Keep the refusal machine-readable
     * without promoting a missing flag into a false FORCED/authorized claim.
     *
     * @param list<array<string,mixed>> $evidence
     */
    public static function incomplete_override_refusal(array $evidence, string $operatorMessage): CommandRefusalException {
        return new CommandRefusalException(
            'apply_conflict_override_incomplete',
            'apply refused an incomplete plan conflict override authorization',
            'supply every flag listed in required_flags or reconcile the target and repository intents before applying again',
            [],
            $operatorMessage,
            null,
            $evidence
        );
    }

    /**
     * WordPress-facing display name for a plan row. Posts carry authored
     * `title` front matter and terms/menus carry `name`; options, sidebars,
     * and typed tables are already named by their repository path. Only the
     * authored value itself is projected — never a guessed or derived label
     * (DUO-3345: plans must speak WordPress names, not identifier-bearing
     * paths alone). The raw value lands in plan JSON; human renderers own
     * any display sanitization.
     */
    public static function entity_display_title(mixed $data): ?string {
        if (!is_array($data)) {
            return null;
        }
        foreach (['title', 'name'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Deploy already changed lifecycle-managed records through WordPress
     * APIs. Compare three-way history against the exact pre-hook snapshot
     * only while the current canonical entity still equals deploy's recorded
     * post-hook snapshot; any later or unrelated target edit falls back to
     * the ordinary conflict path.
     *
     * @param array{entity:string,before_hash:string,after_hash:string}|null $transition
     */
    public static function lifecycle_comparison_hash(
        string $uuid,
        ?string $environmentHash,
        ?array $transition
    ): ?string {
        if ($uuid === 'options/core' && $environmentHash !== null && $transition !== null
            && hash_equals((string) $transition['after_hash'], $environmentHash)) {
            return (string) $transition['before_hash'];
        }
        return $environmentHash;
    }
}
