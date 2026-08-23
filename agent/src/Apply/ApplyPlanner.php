<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Repository/IdentityNotes.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/ReferenceGraph.php';
require_once __DIR__ . '/../Repository/Snapshot.php';
require_once __DIR__ . '/IncompleteApplyMarker.php';

/**
 * The pure conflict/display-projection half of plan production (DUO-3347
 * slice 2, first cut of the issue's "ApplyPlanner: immutable plan and
 * conflict production" target seam).
 *
 * The pure plan projections here read only explicit inputs plus the planner's
 * injected Policy — no `$wpdb`, no target reads, and no Apply state. The
 * live-DB-backed collision/adoption lookup in
 * `find_collision()`/`collision_parent_id()`/`one_collision()` is now the
 * first stateful planner responsibility here as well. The work/deletion
 * ordering and natural-key continuity annotation projections used by plan,
 * apply, and scoped recovery also live here because they read only explicit
 * immutable inputs and injected collaborators.
 * The remaining stateful `build_plan()` option/user-meta special cases,
 * collision/adoption, and guard-ref orchestration stay in `Apply` for later
 * slices, per the issue's "extract one collaborator at a time" guardrail.
 *
 * `Apply` keeps `conflict_view()`, `forced_override_evidence()`,
 * `incomplete_override_refusal()`, `entity_display_title()`,
 * `lifecycle_comparison_hash()`, `theme_mismatch_warnings()`, and
 * `nested_delete_candidate_counts()` as
 * thin compatibility facades delegating here, so its own internal callers
 * need no behavior change.
 */
final class ApplyPlanner {
    /** @var \Closure(string,string):?int */
    private readonly \Closure $tableLedgerIdFor;

    /**
     * The planner owns the policy needed for typed-table collision lookup and
     * the already-memoized authored-table roster supplied by Apply. Keeping
     * the roster an explicit input avoids making this collaborator reach into
     * Apply for cache state or silently re-read the manifest on every entity.
     *
     * @param array<string,array> $snapshotRowTables
     * @param \Closure(string,string):?int $ledgerIdFor Resolves engine reference tokens used by Snapshot collision checks.
     * @param \Closure(string,string):?int $tableLedgerIdFor Resolves a declared table's raw ledger id_kind.
     */
    public function __construct(
        private readonly Policy $policy,
        private readonly array $snapshotRowTables,
        private readonly \Closure $ledgerIdFor,
        \Closure $tableLedgerIdFor
    ) {
        // A declaration is already in ledger keyspace: its literal `tt` must
        // not be rewritten as the engine token spelling `term_taxonomy`.
        // Snapshot collision references still use $ledgerIdFor and retain
        // that translation.
        $this->tableLedgerIdFor = $tableLedgerIdFor;
    }

    /**
     * Project the informational natural-key continuity notes for one planned
     * typed-table row. The ledger resolver is an explicit input boundary:
     * without an already-retained local identity, a UUID differing from the
     * current key is only a fresh-target/adoption question, never a rename
     * claim. Desired and same-snapshot observed fronts are both inspected so
     * a plan describes the continuity fact on either side without a target
     * reread; duplicate notes are collapsed deterministically.
     *
     * @return list<string>
     */
    public function natural_key_continuity_annotations(
        string $uuid,
        string $table,
        ?array $desired,
        ?array $env
    ): array {
        $decl = $this->snapshotRowTables[$table] ?? null;
        if (!is_array($decl) || ($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return [];
        }
        if (($this->tableLedgerIdFor)($uuid, (string) ($decl['id_kind'] ?? '')) === null) {
            return [];
        }

        $fronts = [];
        if ($desired !== null) {
            $fronts[] = $desired;
        }
        $content = $env['content'] ?? null;
        if (is_string($content)) {
            $fronts[] = Canon::decode($content);
        }

        $annotations = [];
        foreach ($fronts as $front) {
            $columns = is_array($front['columns'] ?? null) ? $front['columns'] : [];
            $note = IdentityNotes::natural_key_continuity($uuid, $table, $decl, $columns);
            if ($note !== null && !in_array($note, $annotations, true)) {
                $annotations[] = $note;
            }
        }
        return $annotations;
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
     * Project target-only sidebar widget deletions and identity evidence.
     *
     * Sidebar capture marks target defaults with `_duo_unmanaged`; when a
     * previously managed sidebar has such a default while a desired widget
     * has lost its durable ledger mapping, the plan must refuse rather than
     * infer which local instance owns the canonical UUID. The ledger lookup is
     * the planner's injected engine-identity boundary; widget materialization
     * and option writes remain in SidebarState/Apply.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $desiredDocument
     * @param array<string,mixed> $environment
     * @param string|null $baseHash
     * @return array<string,mixed>
     */
    public function project_sidebar_deletes(
        array $row,
        array $desiredDocument,
        array $environment,
        ?string $baseHash
    ): array {
        $envFront = Canon::decode((string) ($environment['content'] ?? ''));
        $hasUnmanaged = false;
        foreach ((array) ($envFront['widgets'] ?? []) as $widget) {
            $hasUnmanaged = $hasUnmanaged || !empty($widget['settings']['_duo_unmanaged']);
        }
        $missingDesiredMap = false;
        foreach ((array) ($desiredDocument['widgets'] ?? []) as $widget) {
            if (($this->ledgerIdFor)(
                (string) ($widget['uuid'] ?? ''),
                'widget_' . (string) ($widget['type'] ?? '')
            ) === null) {
                $missingDesiredMap = true;
                break;
            }
        }
        if ($hasUnmanaged && $missingDesiredMap && $baseHash !== null) {
            throw new \RuntimeException(
                "duo: widget identity history is missing for {$row['path']}; refusing to infer which live "
                . 'instance owns a canonical UUID. Restore identity-export before plan/apply.'
            );
        }
        $desiredWidgets = array_fill_keys(array_map(
            static fn(array $widget): string => (string) ($widget['uuid'] ?? ''),
            (array) ($desiredDocument['widgets'] ?? [])
        ), true);
        $widgetDeletes = [];
        foreach ((array) ($envFront['widgets'] ?? []) as $widget) {
            if (!isset($desiredWidgets[(string) ($widget['uuid'] ?? '')])) {
                $widgetDeletes[] = [
                    'uuid' => (string) ($widget['uuid'] ?? ''),
                    'type' => (string) ($widget['type'] ?? ''),
                    'unmanaged' => !empty($widget['settings']['_duo_unmanaged']),
                ];
            }
        }
        if ($widgetDeletes) {
            $row['widget_deletes'] = $widgetDeletes;
        }
        return $row;
    }

    /**
     * Classify one compiled deletion tombstone against the target observation
     * and last-synced base. The caller has already established that this row
     * is explicit deletion authority and has resolved its adapter capability;
     * this pure projection only preserves the three-way comparison and the
     * conflict evidence shape. Guard evaluation and destructive choice policy
     * remain in Apply after every tombstone has been classified.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $env
     * @param array<string,mixed>|null $base
     * @return array{bucket:'delete'|'delete_conflict'|'deleted',row:array<string,mixed>}
     */
    public static function classify_deletion(array $row, ?array $env, ?array $base): array {
        $expected = (string) ($row['expected_hash'] ?? '');
        $receipt = (string) ($row['receipt_hash'] ?? '');
        if ($env === null) {
            return ['bucket' => 'deleted', 'row' => $row];
        }

        $envHash = (string) ($env['hash'] ?? '');
        if ($base === null) {
            return [
                'bucket' => 'delete_conflict',
                'row' => $row + [
                    'reason' => 'target entity exists but has no last-synced base',
                    'conflict_view' => self::conflict_view(
                        'target_without_last_synced_base',
                        'delete',
                        'missing',
                        null,
                        null,
                        $expected,
                        $receipt,
                        $envHash,
                        ['--with-deletes', '--force-theirs']
                    ),
                ],
            ];
        }

        $baseContentHash = is_string($base['content_hash'] ?? null)
            ? $base['content_hash']
            : null;
        if (($base['entity_type'] ?? '') === 'deletion') {
            return [
                'bucket' => 'delete_conflict',
                'row' => $row + [
                    'reason' => 'target entity was recreated after this deletion intent was applied',
                    'conflict_view' => self::conflict_view(
                        'target_recreated_after_delete',
                        'delete',
                        'deleted',
                        $baseContentHash,
                        null,
                        $expected,
                        $receipt,
                        $envHash,
                        ['--with-deletes', '--force-theirs']
                    ),
                ],
            ];
        }
        if (!hash_equals($expected, (string) $baseContentHash)) {
            return [
                'bucket' => 'delete_conflict',
                'row' => $row + [
                    'reason' => 'tombstone expected hash does not match the target last-synced base',
                    'conflict_view' => self::conflict_view(
                        'repository_expected_base_mismatch',
                        'delete',
                        'present',
                        $baseContentHash,
                        null,
                        $expected,
                        $receipt,
                        $envHash,
                        ['--with-deletes', '--force-theirs']
                    ),
                ],
            ];
        }
        if (!hash_equals($expected, $envHash)) {
            return [
                'bucket' => 'delete_conflict',
                'row' => $row + [
                    'reason' => 'target entity changed locally since the tombstone base',
                    'conflict_view' => self::conflict_view(
                        'target_changed_since_delete_base',
                        'delete',
                        'present',
                        $baseContentHash,
                        null,
                        $expected,
                        $receipt,
                        $envHash,
                        ['--with-deletes', '--force-theirs']
                    ),
                ],
            ];
        }
        return ['bucket' => 'delete', 'row' => $row];
    }

    /**
     * Project the options/core deletion intents found alongside an observed
     * target. This is deliberately narrower than option materialization:
     * desired and target records are decoded here, while option writes,
     * managed-option policy, and destructive authority remain in Apply.
     *
     * A deleted desired record only becomes pending deletion when the target
     * still has that option as present. Its expected record hash is compared
     * first; the existing options lifecycle recreation check is preserved
     * second. The returned `continue` bucket lets Apply proceed to its generic
     * observed comparison without inventing a planner bucket for this
     * additive row annotation.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $desiredDocument
     * @param array<string,mixed> $environment
     * @param string|null $baseHash
     * @param string $repositoryHash
     * @param string|null $comparisonEnvironmentHash
     * @return array{bucket:'continue'|'conflict',row:array<string,mixed>}
     */
    public static function classify_option_deletions(
        array $row,
        array $desiredDocument,
        array $environment,
        ?string $baseHash,
        string $repositoryHash,
        ?string $comparisonEnvironmentHash
    ): array {
        $desiredRecords = OptionState::records($desiredDocument);
        $envDocument = Canon::decode((string) ($environment['content'] ?? ''));
        $envRecords = OptionState::records($envDocument);
        $pendingDeletes = [];
        $deleteConflicts = [];
        foreach ($desiredRecords as $name => $record) {
            if ($record['state'] !== 'deleted' || !isset($envRecords[$name])
                || $envRecords[$name]['state'] !== 'present') {
                continue;
            }
            $pendingDeletes[] = (string) $name;
            if (!hash_equals($record['expected_hash'], OptionState::record_hash($envRecords[$name]))) {
                $deleteConflicts[] = "$name changed after the deletion base";
            } elseif ($baseHash !== null && hash_equals($repositoryHash, $baseHash)) {
                $deleteConflicts[] = "$name was recreated after its deletion intent was applied";
            }
        }
        if ($pendingDeletes) {
            $row['option_deletes'] = $pendingDeletes;
        }
        if (!$deleteConflicts) {
            return ['bucket' => 'continue', 'row' => $row];
        }
        return [
            'bucket' => 'conflict',
            'row' => $row + [
                'reason' => implode('; ', $deleteConflicts),
                'conflict_view' => self::conflict_view(
                    'option_delete_and_target_changed_since_base',
                    'update',
                    $baseHash === null ? 'missing' : 'present',
                    $baseHash,
                    $repositoryHash,
                    $baseHash,
                    null,
                    $comparisonEnvironmentHash,
                    ['--with-deletes', '--force-theirs']
                ),
            ],
        ];
    }

    /**
     * Classify one observed entity's immutable repository/base/target hashes.
     * The caller has already handled entity-specific special cases and has an
     * observed target, so this projection only selects the existing plan
     * bucket and preserves first-sync/conflict evidence. Collision/adoption
     * lookup for a fresh target remains in Apply after this returns.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $environment
     * @param string|null $baseHash
     * @param string|null $comparisonEnvironmentHash
     * @return array{bucket:'unchanged'|'update'|'drift'|'conflict',row:array<string,mixed>}
     */
    public static function classify_observed(
        array $row,
        string $repositoryHash,
        array $environment,
        ?string $baseHash,
        ?string $comparisonEnvironmentHash
    ): array {
        $environmentHash = (string) ($environment['hash'] ?? '');
        if ($repositoryHash === $environmentHash) {
            return ['bucket' => 'unchanged', 'row' => $row];
        }
        if ($baseHash === null || $comparisonEnvironmentHash === $baseHash) {
            return [
                'bucket' => 'update',
                'row' => $row + ['first_sync' => $baseHash === null],
            ];
        }
        if ($repositoryHash === $baseHash) {
            return ['bucket' => 'drift', 'row' => $row];
        }
        return [
            'bucket' => 'conflict',
            'row' => $row + [
                'conflict_view' => self::conflict_view(
                    'repository_and_target_changed_since_base',
                    'update',
                    'present',
                    $baseHash,
                    $repositoryHash,
                    $baseHash,
                    null,
                    $comparisonEnvironmentHash,
                    ['--force-theirs']
                ),
            ],
        ];
    }

    /**
     * Add unchanged reverse-reference owners to the authored work set when a
     * referenced identity must be recreated or adopted. Phase 1 can replace
     * the target's local id, so omitting an otherwise byte-equal owner leaves
     * its live shortcode/block/option/table reference on the retired id. The
     * complete-uninstall branch in checks/code-snippets.sh reproduced that
     * exact boundary: the snippet moved from local id 11 to 5 and canonical
     * verification refused the unchanged post that phase 2 had skipped.
     *
     * Environment drift cannot be preserved across that identity change: its
     * embedded local ids would become dangling. Promote it to the ordinary
     * typed conflict contract so reconciliation is the default and an exact
     * --force-theirs choice can authorize rewriting repository state.
     *
     * @param array<string,mixed> $plan
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array<string,mixed>> $environment
     * @param array<string,array<string,mixed>> $base
     * @return array<string,mixed>
     */
    public function project_reference_rebinds(
        array $plan,
        array $tree,
        array $environment,
        array $base
    ): array {
        $recreatedEntries = [];
        foreach (['create', 'adopt'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $recreatedEntries[(string) ($row['uuid'] ?? '')] = true;
            }
        }
        unset($recreatedEntries['']);
        if ($recreatedEntries === []) {
            return $plan;
        }

        $owners = ReferenceGraph::owners($tree);
        $recreatedTargets = [];
        foreach ($owners as $uuid => $owner) {
            if (isset($recreatedEntries[(string) ($owner['entity'] ?? '')])) {
                $recreatedTargets[(string) $uuid] = true;
            }
        }
        if ($recreatedTargets === []) {
            return $plan;
        }

        $targetsByReferrer = [];
        foreach (ReferenceGraph::edges($tree, $this->policy) as $edge) {
            $target = (string) ($edge['target'] ?? '');
            if (!isset($recreatedTargets[$target])) {
                continue;
            }
            $from = (string) ($edge['from'] ?? '');
            $referrer = (string) ($owners[$from]['entity'] ?? $from);
            if ($referrer === '' || isset($recreatedEntries[$referrer])) {
                continue;
            }
            $targetsByReferrer[$referrer][$target] = true;
        }
        foreach ($targetsByReferrer as &$targets) {
            $targets = array_keys($targets);
            sort($targets, SORT_STRING);
        }
        unset($targets);
        if ($targetsByReferrer === []) {
            return $plan;
        }

        foreach (['create', 'adopt', 'update', 'unchanged', 'drift', 'conflict'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $index => $row) {
                $uuid = (string) ($row['uuid'] ?? '');
                if (isset($targetsByReferrer[$uuid])) {
                    $plan[$bucket][$index]['reference_rebind_targets'] = $targetsByReferrer[$uuid];
                }
            }
        }

        foreach ((array) ($plan['unchanged'] ?? []) as $index => $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if (!isset($targetsByReferrer[$uuid])) {
                continue;
            }
            $plan['update'][] = $plan['unchanged'][$index];
            unset($plan['unchanged'][$index]);
        }
        $plan['unchanged'] = array_values((array) ($plan['unchanged'] ?? []));

        foreach ((array) ($plan['drift'] ?? []) as $index => $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if (!isset($targetsByReferrer[$uuid])) {
                continue;
            }
            $repositoryHash = is_string($tree[$uuid]['hash'] ?? null) ? $tree[$uuid]['hash'] : null;
            $baseHash = is_string($base[$uuid]['content_hash'] ?? null) ? $base[$uuid]['content_hash'] : null;
            $targetHash = is_string($environment[$uuid]['hash'] ?? null) ? $environment[$uuid]['hash'] : null;
            $conflict = $plan['drift'][$index];
            $conflict['reason'] = 'target-authored entity references an identity that must be recreated';
            $conflict['conflict_view'] = self::conflict_view(
                'reference_target_identity_recreated',
                'update',
                $baseHash === null ? 'missing' : 'present',
                $baseHash,
                $repositoryHash,
                $baseHash,
                null,
                $targetHash,
                ['--force-theirs']
            );
            $plan['conflict'][] = $conflict;
            unset($plan['drift'][$index]);
        }
        $plan['drift'] = array_values((array) ($plan['drift'] ?? []));

        return $plan;
    }

    /**
     * Phase-2 finalize order: early post types first, then ordinary
     * posts/terms/menus/options, then declared table rows in their own
     * topological order. This is a plan projection: no target or ledger read
     * may influence the order after the plan has been built.
     */
    public function phase2_rank(array $entity): int {
        if (isset($this->snapshotRowTables[$entity['type']])) {
            return 2 + Snapshot::phase2_rank($this->policy, (string) $entity['type']);
        }
        if (($entity['type'] ?? '') === 'post'
            && $this->policy->post_type_phase($entity['post_type'] ?? '') === 'early') {
            return 0;
        }
        return 1;
    }

    /** Delete custom-table children before their declared parents. */
    public function deletion_rank(array $row): int {
        if (($row['deletion_kind'] ?? '') === 'table') {
            return 100 + Snapshot::phase2_rank($this->policy, (string) $row['deletion_type']);
        }
        return match ($row['deletion_kind'] ?? '') {
            'post' => 30,
            'menu' => 20,
            'term' => 10,
            default => 0,
        };
    }

    /**
     * DUO-3206's retry widening, and DUO-3489's / DUO-3491's carve-outs.
     *
     * A prior apply that committed authored rows but failed a required
     * rebuild deliberately left `apply_in_progress`. The live canonical hash
     * can now be unchanged, drift, or conflict: rebuild actions may normalize
     * a just-written row after COMMIT, while duo_state intentionally still
     * names the pre-apply base. In every case the interrupted promotion's
     * repository tree remains the recovery target, so every mapped canonical
     * entity is re-run through phase 2/rebuild until the marker clears;
     * otherwise the ordinary three-way gate can make a truthful failure
     * impossible to retry without an unrelated --force-theirs override.
     *
     * That reasoning covers rows the failed run WROTE. It does not cover
     * environment-only drift, which a normal apply deliberately leaves for
     * capture (rebuild_work() below, :700-706 — "A normal apply leaves
     * environment-only drift for capture"). DUO-3489 measured the consequence
     * on a live pair:
     * run 1 planned `drift:2`, preserved both rows, and failed the whole-tree
     * convergence gate; run 2 planned `drift:0`, silently overwrote both, and
     * reported `applied 14 entities (canary clean)`. So a row the interrupted
     * run recorded as preserved drift, and which still classifies as drift
     * now, stays in `drift`: the plan keeps telling the truth, `duo status`
     * keeps its non-zero `drift` count, and the documented remedy
     * (`duo capture` first) remains the only thing that folds it in.
     *
     * DUO-3491 is the same shape one bucket over. Draining `conflict` into
     * `update` means the retry writes rows that a first apply refuses outright
     * — "duo: conflicts (env and repo both changed since last sync) — capture
     * first or --force-theirs" (ApplyPreparationCoordinator.php:58) — so the
     * marker silently converted an operator decision into an automatic
     * override. That is right only where DUO-3206's premise holds: the failed
     * run wrote the row, its `duo_state` base is stale, and the "repo side
     * changed too" half of the three-way answer is our own write. It does not
     * hold for an identity that run never wrote — a preserved-drift row whose
     * repository side moved when the operator recompiled between the two runs
     * is a genuine env-and-repo divergence, and so is a row that drifted after
     * the marker was written. Those stay in `conflict` and keep demanding the
     * same explicit `--force-theirs` (or capture-first) as a first apply.
     *
     * The evidence is the marker's own record, and each wire is read for the
     * claim it makes and no more: v2's `write_set` decides directly, v1
     * (DUO-3489's wire, still live on any target interrupted under 9b440c3)
     * can only prove the preserved-drift identities were not written and
     * leaves the rest on DUO-3206's widening, and a record-less marker keeps
     * DUO-3206's behaviour byte for byte.
     *
     * @param array<string,mixed> $plan
     * @param string|null $marker the raw `apply_in_progress` ledger value
     * @return array<string,mixed>
     */
    public static function project_incomplete_apply_retry(array $plan, ?string $marker): array {
        if ($marker === null) {
            return $plan;
        }
        $preserved = IncompleteApplyMarker::preserved_drift($marker);
        $writeSet = IncompleteApplyMarker::write_set($marker);
        $retained = [];
        $widened = [];
        foreach ((array) ($plan['drift'] ?? []) as $row) {
            if ($preserved !== null && isset($preserved[(string) ($row['uuid'] ?? '')])) {
                $retained[] = $row;
                continue;
            }
            $widened[] = $row;
        }
        $retainedConflict = [];
        $widenedConflict = [];
        foreach ((array) ($plan['conflict'] ?? []) as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($writeSet !== null) {
                // v2: membership is the whole answer, in both directions.
                if (isset($writeSet[$uuid])) {
                    $widenedConflict[] = $row;
                } else {
                    $retainedConflict[] = $row;
                }
                continue;
            }
            // v1 carries no write set. The one thing it does prove is that a
            // recorded preserved-drift identity was NOT written, so a conflict
            // on one of those is a genuine divergence; everything else has no
            // evidence either way and keeps DUO-3206's widening.
            if ($preserved !== null && isset($preserved[$uuid])) {
                $retainedConflict[] = $row;
                continue;
            }
            $widenedConflict[] = $row;
        }
        $reason = 'previous apply did not complete required rebuilds or convergence metadata';
        $incomplete = ['reason' => $reason];
        if ($retained !== []) {
            $incomplete = [
                'reason' => $reason . '; ' . count($retained)
                    . ' environment-drifted entities it deliberately preserved are still drift and are not being overwritten by this retry — run `duo capture` to fold them into the repository first',
                'preserved_drift' => array_map(
                    static fn(array $row): array => [
                        'path' => (string) ($row['path'] ?? ''),
                        'type' => (string) ($row['type'] ?? ''),
                        'uuid' => (string) ($row['uuid'] ?? ''),
                    ],
                    $retained
                ),
            ];
        }
        if ($retainedConflict !== []) {
            $incomplete['reason'] = (string) $incomplete['reason'] . '; ' . count($retainedConflict)
                . (count($retainedConflict) === 1 ? ' entity conflicts' : ' entities conflict')
                . ' three ways on identities the interrupted apply never wrote, so this retry will not override them — resolve the repository side or run `duo capture` first, or re-run with --force-theirs exactly as a first apply demands';
            $incomplete['retained_conflict'] = array_map(
                static fn(array $row): array => [
                    'path' => (string) ($row['path'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''),
                    'uuid' => (string) ($row['uuid'] ?? ''),
                ],
                $retainedConflict
            );
        }
        $plan['incomplete_apply'][] = $incomplete;
        // The three buckets are consumed in their original DUO-3206 order:
        // rebuild_work() re-sorts `update` only by phase2_rank, and PHP's
        // stable sort therefore carries this sequence into apply's actual
        // write order. `drift` and `conflict` keep only what stays carved out.
        $plan['drift'] = $widened;
        $plan['conflict'] = $widenedConflict;
        foreach (['unchanged', 'drift', 'conflict'] as $retryKind) {
            foreach ($plan[$retryKind] as $row) {
                $plan['update'][] = $row + ['retry' => true];
            }
            $plan[$retryKind] = [];
        }
        $plan['drift'] = $retained;
        $plan['conflict'] = $retainedConflict;
        return $plan;
    }

    /**
     * Project the exact authored work and deletion ordering shared by plan,
     * apply, and scoped recovery. Retry tombstones widen only the rebuild
     * selection; they never become authored delete authority a second time.
     *
     * @param array<string,mixed> $plan
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,mixed> $opts
     * @return array{work:list<array<string,mixed>>,delete_work:list<array<string,mixed>>,rebuild_delete_work:list<array<string,mixed>>}
     */
    public function rebuild_work(
        array $plan,
        array $tree,
        array $opts,
        bool $retryingIncompleteApply,
        bool $includeScopedPromotionDrift = false
    ): array {
        $deleteWork = (array) ($plan['delete'] ?? []);
        if (!empty($opts['force_theirs'])) {
            $deleteWork = array_merge($deleteWork, (array) ($plan['delete_conflict'] ?? []));
        }
        usort($deleteWork, fn(array $a, array $b): int =>
            $this->deletion_rank($b) <=> $this->deletion_rank($a)
            ?: ($a['uuid'] <=> $b['uuid'])
        );

        // A previous apply can have committed authored rows and failed after
        // a tombstone target was already absent. Include those immutable
        // tombstones in the retry surface set so bounded derived-state
        // actions still clear/verify their rows on the next attempt.
        $rebuildDeleteWork = $deleteWork;
        if ($retryingIncompleteApply) {
            $rebuildDeleteWork = array_merge($rebuildDeleteWork, (array) ($plan['deleted'] ?? []));
            usort($rebuildDeleteWork, fn(array $a, array $b): int =>
                $this->deletion_rank($b)
                <=> $this->deletion_rank($a)
                ?: ((string) ($a['uuid'] ?? '') <=> (string) ($b['uuid'] ?? ''))
            );
        }

        // Deterministic, declared ordering for BOTH phases: 'early' post
        // types lead. This is also the exact authored work set whose
        // canonical surfaces may select a provider action.
        $work = array_merge(
            (array) ($plan['create'] ?? []),
            (array) ($plan['adopt'] ?? []),
            (array) ($plan['update'] ?? []),
            array_map(fn(array $row): array => $row, (array) ($plan['conflict'] ?? []))
        );
        if ($includeScopedPromotionDrift) {
            // A normal apply leaves environment-only drift for capture. The
            // externally checkpointed scoped-promotion profile is the one
            // reviewed exception whose authority permits replacing selected
            // drift with frozen repository state.
            $work = array_merge($work, (array) ($plan['drift'] ?? []));
        }
        usort($work, fn(array $x, array $y): int =>
            $this->phase2_rank($tree[(string) $x['uuid']])
            <=> $this->phase2_rank($tree[(string) $y['uuid']])
        );

        return [
            'work' => $work,
            'delete_work' => $deleteWork,
            'rebuild_delete_work' => $rebuildDeleteWork,
        ];
    }

    /**
     * The operator sentence for tombstones an ordinary apply planned but was
     * never authorized to execute.
     *
     * Only the SCOPED path refuses this state
     * (ApplyPreparationCoordinator.php:171-179). A full apply keeps
     * `$executeDeletes` false while `rebuild_work()` above hands back a fully
     * populated `delete_work`, and AuthoredTransactionExecutor.php:222-253
     * then skips its entire delete block — so the target still holds every
     * tombstoned entity, and ApplyLedgerFinalizer.php:94-99 still records
     * `applied_revision` for the run. Before DUO-3502 the only trace of that
     * was numeric: `"delete":1` beside `"deleted":0` in the receipt
     * (ApplyRequestCoordinator.php:1533-1534), which no operator reads as
     * "none of the planned deletions happened".
     *
     * Pure by construction, exactly like `rebuild_work()`: the caller owns the
     * authorization decision and guards `$deleteWork !== []`; this only
     * renders the rows it was handed. `path` is the tombstone's repository
     * path; the `<type> <uuid>` fallback covers rows projected without one.
     *
     * @param list<array<string,mixed>> $deleteWork
     */
    public static function unauthorized_deletes_warning(array $deleteWork): string {
        $rows = array_map(
            static function (array $row): string {
                $path = (string) ($row['path'] ?? '');
                return $path !== ''
                    ? $path
                    : (string) ($row['type'] ?? '?') . ' ' . (string) ($row['uuid'] ?? '?');
            },
            $deleteWork
        );
        return sprintf(
            'planned deletions NOT applied (%d) — --with-deletes was not supplied; the target still holds them '
                . 'and this revision is recorded as applied without them. Rerun with --with-deletes to authorize:'
                . "\n  - %s",
            count($deleteWork),
            implode("\n  - ", $rows)
        );
    }

    /**
     * Project the exact plan facts which authorize a later mutation.
     *
     * Report-only buckets are intentionally excluded. The selected plan
     * rows, deletion guard witnesses, retry receipts, and immutable source
     * inventories remain part of the hash so a scoped apply or a locked
     * recheck cannot reuse a plan after its mutation authority changed.
     */
    public static function plan_precondition_hash(array $plan): string {
        $keys = [
            'create', 'update', 'unchanged', 'drift', 'conflict', 'adopt',
            'collision', 'delete', 'delete_conflict', 'deleted',
            'code_mismatch', 'code_drift', 'incomplete_apply', 'regen_pending', 'regen_context',
            'missing_user', 'skipped_user_meta', 'uploads_inventory', 'effects_inventory',
        ];
        $basis = [];
        foreach ($keys as $key) {
            $basis[$key] = $plan[$key] ?? [];
        }
        return hash('sha256', Canon::encode($basis));
    }

    /**
     * Project the authored UUIDs whose desired state is scheduled to be
     * written in this revision. Deletion guards use this narrow witness to
     * distinguish a parent repair from a child-only delete; conflicted rows
     * and tombstones are deliberately excluded.
     *
     * @return array<string,bool>
     */
    public static function guard_repair_uuids(array $plan, bool $includeDrift = false): array {
        $out = [];
        $buckets = ['create', 'update', 'adopt'];
        if ($includeDrift) {
            $buckets[] = 'drift';
        }
        foreach ($buckets as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $uuid = (string) ($row['uuid'] ?? '');
                if ($uuid !== '') {
                    $out[$uuid] = true;
                }
            }
        }
        return $out;
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
     * Project durable derived-state debt for a read-only plan.
     *
     * Apply owns the engine boundaries that read Ledger and Policy. It passes
     * those observations and claimant/identity predicates here so this
     * projection owns the shared row shape, malformed-marker handling,
     * deterministic ordering, and operator warning text for all three retry
     * keyspaces. The callbacks are deliberately read-only: this method never
     * mutates a marker, resolves a target on its own, or authorizes a retry.
     *
     * A pending marker is visible only when its post type is declared and its
     * UUID still resolves. A context receipt is visible only when it is a
     * well-formed receipt with a claimant. Those are the same orphan rules the
     * old Apply projection used, kept together so the two dispatchers share
     * one plan/status vocabulary.
     *
     * @param array<string,mixed> $pendingMarkers
     * @param array<string,mixed> $deleteContextMarkers
     * @param array<string,mixed> $reparentContextMarkers
     * @param \Closure(mixed):bool $pendingTypeClaimed
     * @param \Closure(string):bool $pendingUuidExists
     * @param \Closure(string):bool $contextClaimed
     * @return array{
     *   regen_pending:list<array{uuid:string,type:string,post_type:mixed}>,
     *   regen_context:list<array{uuid:string,type:string,post_type:string,kind:string}>,
     *   warnings:list<string>
     * }
     */
    public static function regeneration_debt_projection(
        array $pendingMarkers,
        array $deleteContextMarkers,
        array $reparentContextMarkers,
        \Closure $pendingTypeClaimed,
        \Closure $pendingUuidExists,
        \Closure $contextClaimed
    ): array {
        $pending = [];
        $context = [];
        $warnings = [];

        foreach ($pendingMarkers as $key => $postType) {
            $uuid = substr((string) $key, strlen('regen_pending:'));
            if (!$pendingTypeClaimed($postType) || !$pendingUuidExists($uuid)) {
                continue;
            }
            $pending[] = ['uuid' => $uuid, 'type' => 'post', 'post_type' => $postType];
            $warnings[] = "regen_pending: post $uuid (type '$postType') has a regeneration "
                . 'retry pending from a prior failed verify';
        }

        foreach ([
            [$deleteContextMarkers, 'delete'],
            [$reparentContextMarkers, 'reparent'],
        ] as [$markers, $kind]) {
            foreach ($markers as $key => $encoded) {
                $decoded = is_string($encoded) ? json_decode($encoded, true) : null;
                $postType = is_array($decoded) ? (string) ($decoded['post_type'] ?? '') : '';
                $id = is_array($decoded) ? (int) ($decoded['id'] ?? 0) : 0;
                if ($postType === '' || $id <= 0 || !$contextClaimed($postType)) {
                    continue;
                }
                $context[] = [
                    'uuid' => is_array($decoded) && (string) ($decoded['uuid'] ?? '') !== ''
                        ? (string) $decoded['uuid']
                        : substr((string) $key, strlen('regen_' . $kind . '_context:')),
                    'type' => 'post',
                    'post_type' => $postType,
                    'kind' => $kind,
                ];
            }
        }
        usort($context, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind']) ?: strcmp($a['uuid'], $b['uuid']));
        foreach ($context as $row) {
            $warnings[] = "regen_context: post {$row['uuid']} (type '{$row['post_type']}') has an "
                . "outstanding {$row['kind']} receipt awaiting a verified derived-state repair";
        }

        return [
            'regen_pending' => $pending,
            'regen_context' => $context,
            'warnings' => $warnings,
        ];
    }

    /**
     * Project the read-only checklist for manifest-declared environment
     * options. Apply supplies the already-resolved Policy rules and the live
     * option-value reader; the planner owns the missing-value semantics, row
     * shape, declaration order, and required-option warning vocabulary.
     *
     * Missing deliberately means only an absent row or an empty string on
     * this environment. The callback is invoked once for every declaration,
     * including present optional values, and this method never writes through
     * it or mutates the supplied rules.
     *
     * @param array<string,array> $envOptions
     * @return array{env_missing:list<array{name:string,required:bool}>,warnings:list<string>}
     */
    public static function env_missing_projection(
        array $envOptions,
        \Closure $readOption
    ): array {
        $missing = [];
        $warnings = [];

        foreach ($envOptions as $name => $rule) {
            $name = (string) $name;
            $value = $readOption($name);
            if ($value !== null && $value !== '') {
                continue;
            }
            $required = (bool) ($rule['required'] ?? false);
            $missing[] = ['name' => $name, 'required' => $required];
            if ($required) {
                $warnings[] = "env_missing: option '$name' is required and not yet provisioned on "
                    . "this environment — see 'wp duo env-set --name=$name --stdin'";
            }
        }

        return [
            'env_missing' => $missing,
            'warnings' => $warnings,
        ];
    }

    /**
     * Project the warning emitted when an FSE entity is tagged for a captured
     * theme other than the target's active stylesheet. The active stylesheet
     * is supplied by Apply at its WordPress boundary; this projection itself
     * reads only the immutable compiled tree and that already-observed value.
     * It deliberately warns rather than blocks: the authored rows remain
     * byte-correct, but WordPress will not render them until the captured
     * theme is active on the target.
     *
     * @param array<string,array<string,mixed>> $tree
     * @return list<string>
     */
    public static function theme_mismatch_warnings(array $tree, string $activeTheme): array {
        $themeSlugByUuid = [];
        foreach ($tree as $uuid => $e) {
            if (($e['type'] ?? '') !== 'term') {
                continue;
            }
            $front = (array) ($e['data'] ?? []);
            if (($front['taxonomy'] ?? '') === 'wp_theme') {
                // Preserve the old null/absent-slug behavior: a term without
                // a usable identity cannot establish an active-theme
                // mismatch. In particular, do not manufacture the empty
                // string as a captured theme name.
                if (array_key_exists('slug', $front)) {
                    $themeSlugByUuid[$uuid] = $front['slug'];
                }
            }
        }
        if (!$themeSlugByUuid) {
            return [];
        }
        $affected = [];
        foreach ($tree as $e) {
            if (($e['type'] ?? '') !== 'post') {
                continue;
            }
            $front = (array) ($e['data'] ?? []);
            foreach ((array) ($front['terms']['wp_theme'] ?? []) as $themeUuid) {
                $slug = $themeSlugByUuid[$themeUuid] ?? null;
                if ($slug !== null && $slug !== $activeTheme) {
                    $affected[$slug][] = (string) ($e['path'] ?? '');
                }
            }
        }
        $warnings = [];
        foreach ($affected as $capturedTheme => $paths) {
            $verb = count($paths) === 1 ? 'is tagged for' : 'are tagged for';
            $warnings[] = "active-theme mismatch: this environment's active theme is '$activeTheme' but "
                . implode(', ', $paths) . " $verb theme '$capturedTheme'"
                . " — will apply but will NOT render until '$capturedTheme' is active here";
        }
        return $warnings;
    }

    /**
     * Preserve Apply's old short-circuit before it observes the target's
     * active stylesheet. A wp_theme term is enough to make that observation
     * relevant, even if its slug is malformed and therefore cannot produce a
     * warning.
     *
     * @param array<string,array<string,mixed>> $tree
     */
    public static function theme_mismatch_has_theme_terms(array $tree): bool {
        foreach ($tree as $e) {
            if (($e['type'] ?? '') !== 'term') {
                continue;
            }
            $front = (array) ($e['data'] ?? []);
            if (($front['taxonomy'] ?? '') === 'wp_theme') {
                return true;
            }
        }
        return false;
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

    /**
     * The binding a materialized target's restored bytes and ledger belong
     * to, from `--rebind-from-home` / `--rebind-from-uploads` (plan/apply
     * opts `rebind_from_home` / `rebind_from_uploads`). Both or neither: a
     * lone URL cannot describe a binding, and both must be absolute http(s)
     * URLs — the tokenizer matches literal prefixes, so anything else could
     * only ever fail to excuse drift, silently. Null when absent.
     *
     * @return array{home:string,uploads:string}|null
     */
    public static function rebind_binding(array $opts): ?array {
        $home = $opts['rebind_from_home'] ?? null;
        $uploads = $opts['rebind_from_uploads'] ?? null;
        if ($home === null && $uploads === null) {
            return null;
        }
        foreach ([$home, $uploads] as $value) {
            if (!is_string($value) || preg_match('~^https?://[^\s/?#]+(?:/[^\s?#]*)?$~', $value) !== 1) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'the restored environment binding is incomplete or malformed',
                    'pass both --rebind-from-home and --rebind-from-uploads, each an absolute http(s) URL, or neither'
                );
            }
        }
        return ['home' => rtrim((string) $home, '/'), 'uploads' => rtrim((string) $uploads, '/')];
    }

    /**
     * Compare a materialized target's entity as if unchanged since base when
     * its foreign-bound observation IS the repository's or the base's content
     * (ApplyPlanBuilder::build() explains why that observation exists). The
     * returned comparison hash is the base hash — the ordinary "target equals
     * base" input to classify_observed(), which then yields `update`; the
     * caller marks the row `rebind`. Null leaves the ordinary comparison in
     * place: no foreign observation, an entity that already observes as the
     * repository under this environment's binding, or a foreign-bound
     * observation that matches neither (genuine target authorship).
     */
    public static function rebind_comparison_hash(
        string $repositoryHash,
        string $environmentHash,
        ?string $baseHash,
        ?string $comparisonEnvironmentHash,
        ?string $foreignHash
    ): ?string {
        if ($foreignHash === null || hash_equals($repositoryHash, $environmentHash)) {
            return null;
        }
        if ($baseHash === null) {
            // First sync already classifies as update; nothing to excuse.
            return null;
        }
        if ($comparisonEnvironmentHash !== null && hash_equals($baseHash, $comparisonEnvironmentHash)) {
            return null;
        }
        if (hash_equals($repositoryHash, $foreignHash) || hash_equals($baseHash, $foreignHash)) {
            return $baseHash;
        }
        return null;
    }
}
