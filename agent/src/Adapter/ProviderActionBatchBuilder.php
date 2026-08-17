<?php
namespace Duo;

require_once __DIR__ . '/../Repository/CanonicalSurfaces.php';
require_once __DIR__ . '/../Rebuild/RegenerationContext.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Providers::class, false)) {
    require_once __DIR__ . '/Providers.php';
}
/**
 * Builds the exact entity batches and opt-in evidence channels delivered to a
 * selected provider action. It owns payload filtering, local-id resolution,
 * deterministic ordering, and retry-marker discovery, but never invokes a
 * provider or decides whether its verified receipt advances recovery state.
 */
final class ProviderActionBatchBuilder {
    public const REGEN_PENDING_PREFIX = 'regen_pending:';

    public function __construct(
        private readonly Policy $policy,
        private readonly array $snapshotRowTables
    ) {}

    public function action_entities(
        array $action,
        array $work,
        array $tree,
        array $deletionRows = [],
        bool $includeGenericPending = true
    ): array {
        $warnings = [];
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $deletedIds = [];
        foreach ($deletionRows as $row) {
            $deletedId = (int) ($row['id'] ?? 0);
            if ($deletedId > 0) {
                $deletedIds[$deletedId] = true;
            }
            foreach ((array) ($row['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $deletedIds[$childId] = true;
                }
            }
        }
        $entities = [];
        $markers = [];
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $entity = $uuid !== '' ? ($tree[$uuid] ?? null) : null;
            if (!is_array($entity)) {
                continue;
            }
            foreach ($this->entity_rebuild_surfaces($entity, $entry) as $surface) {
                if (!isset($triggers[$surface])) {
                    continue;
                }
                $id = $this->entity_local_id($entity, $uuid);
                if ($id === null) {
                    throw new \RuntimeException(
                        "duo: entity-scoped provider capability '{$action['provider']}/{$action['capability']}' "
                        . "selected canonical surface '$surface', but $uuid has no resolvable target-local id; "
                        . 'declare triggers naming only surfaces whose entities carry one'
                    );
                }
                if (isset($deletedIds[$id])) {
                    continue;
                }
                $entities[$surface . "\0" . $id] = ['kind' => $surface, 'id' => $id];
                if (str_starts_with($surface, 'post:')) {
                    $markers[$uuid] = substr($surface, strlen('post:'));
                }
            }
        }
        foreach ($includeGenericPending ? Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) : [] as $key => $postType) {
            $postType = (string) $postType;
            $surface = 'post:' . $postType;
            if ($postType === '' || !isset($triggers[$surface])
                || $this->policy->regen_batch($postType) !== null) {
                continue;
            }
            $uuid = substr((string) $key, strlen(self::REGEN_PENDING_PREFIX));
            if (isset($markers[$uuid])) {
                continue; // already covered by this run's work above
            }
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                Ledger::kv_delete((string) $key);
                $warnings[] = "regen_pending marker for post $uuid (type '$postType') dropped: "
                    . 'uuid no longer resolves to a local post id';
                continue;
            }
            if (isset($deletedIds[$id])) {
                continue;
            }
            $entities[$surface . "\0" . $id] = ['kind' => $surface, 'id' => $id];
            $markers[$uuid] = $postType;
        }
        $out = array_values($entities);
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
        ksort($markers, SORT_STRING);
        return ['entities' => $out, 'markers' => $markers, 'warnings' => $warnings];
    }

    public function action_marker_keys(array $action, array $durableContexts): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $keys = [];
        foreach ($durableContexts as $context) {
            $markerKey = (string) ($context['_marker_key'] ?? '');
            $postType = (string) ($context['post_type'] ?? '');
            if ($markerKey === '' || $postType === '' || !isset($triggers['post:' . $postType])) {
                continue;
            }
            $keys[$markerKey] = $markerKey;
        }
        return array_values($keys);
    }

    public function action_context(
        array $action,
        array $declaration,
        array $appliedDeletions,
        array $regenContext,
        array $durableReparents = [],
        array $durableDeletions = [],
        bool $retryingIncompleteApply = false
    ): array {
        $context = [];
        if (Providers::declares_channel($declaration, 'always_on_write')) {
            // A flag about this invocation, not evidence about the revision,
            // so it is assembled unconditionally-true whenever declared and
            // action_batch_has_work() deliberately does not consult it.
            $context['always_on_write'] = true;
        }
        if (Providers::declares_channel($declaration, 'deletions')) {
            $context['deletions'] = $this->action_deletions($action, $appliedDeletions, $durableDeletions);
        }
        if (Providers::declares_channel($declaration, 'reparents')) {
            $context['reparents'] = $this->action_reparents($action, $regenContext, $durableReparents);
        }
        if (Providers::declares_channel($declaration, 'retry')) {
            $context['retry'] = $retryingIncompleteApply;
        }
        return $context;
    }

    public function action_batch_has_work(array $declaration, array $entities, array $context): bool {
        if ($entities !== []) {
            return true;
        }
        foreach ($context as $channel => $value) {
            if (in_array($channel, Providers::NO_WORK_CHANNELS, true)) {
                continue;
            }
            if ($value === true || (is_array($value) && $value !== [])) {
                return true;
            }
        }
        return false;
    }

    public function skipped_channel_states(array $declared, array $context): string {
        $parts = [];
        foreach ($declared as $channel) {
            $channel = (string) $channel;
            if (in_array($channel, Providers::NO_WORK_CHANNELS, true)) {
                $parts[] = "$channel (a flag; never work of its own)";
                continue;
            }
            if (!array_key_exists($channel, $context)) {
                $parts[] = "$channel absent";
                continue;
            }
            $value = $context[$channel];
            $parts[] = is_bool($value)
                ? $channel . ' ' . ($value ? 'true' : 'false')
                : "$channel empty";
        }
        return implode(', ', $parts);
    }

    public function action_deletions(
        array $action,
        array $appliedDeletions,
        array $durableDeletions = []
    ): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $rows = [];
        foreach ($appliedDeletions as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            foreach ($this->deletion_rebuild_surfaces($entry) as $surface) {
                if (!isset($triggers[$surface])) {
                    continue;
                }
                $rows[$surface . "\0" . $uuid] = [
                    'kind' => $surface,
                    'uuid' => $uuid,
                    'id' => $this->entity_local_id($entry, $uuid) ?? 0,
                    'post_type' => str_starts_with($surface, 'post:')
                        ? substr($surface, strlen('post:'))
                        : '',
                    'parent_id' => 0,
                    'child_ids' => [],
                ];
            }
        }
        foreach ($durableDeletions as $context) {
            if (!is_array($context) || ($context['kind'] ?? 'delete') !== 'delete') {
                continue;
            }
            $postType = (string) ($context['post_type'] ?? '');
            $surface = $postType !== '' ? 'post:' . $postType : 'entity:post';
            if (!isset($triggers[$surface])) {
                continue;
            }
            $uuid = (string) ($context['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $childIds = [];
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $childIds[$childId] = $childId;
                }
            }
            $childIds = array_values($childIds);
            sort($childIds, SORT_NUMERIC);
            $rows[$surface . "\0" . $uuid] = [
                'kind' => $surface,
                'uuid' => $uuid,
                'id' => (int) ($context['id'] ?? 0),
                'post_type' => $postType,
                'parent_id' => (int) ($context['parent_id'] ?? 0),
                'child_ids' => $childIds,
            ];
        }
        $out = array_values($rows);
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind']) ?: strcmp($a['uuid'], $b['uuid']));
        return $out;
    }

    public function action_reparents(
        array $action,
        array $regenContext,
        array $durableReparents = []
    ): array {
        $triggers = array_fill_keys((array) ($action['triggers'] ?? []), true);
        $rows = [];
        foreach (array_merge($durableReparents, $regenContext) as $entry) {
            if (!is_array($entry) || ($entry['kind'] ?? 'delete') !== 'reparent') {
                continue;
            }
            $postType = (string) ($entry['post_type'] ?? '');
            $surface = $postType !== '' ? 'post:' . $postType : 'entity:post';
            if (!isset($triggers[$surface])) {
                continue;
            }
            $uuid = (string) ($entry['uuid'] ?? '');
            // capture_regen_reparent_context() records nothing without a
            // previous parent, so the root set is non-empty in practice; the
            // fallback keeps a malformed receipt VISIBLE as a move with no
            // known root rather than dropping the entity from the channel.
            $roots = $this->regen_context_root_ids($entry) ?: [0];
            foreach ($roots as $rootId) {
                $rows[$surface . "\0" . $uuid . "\0" . (int) $rootId] = [
                    'kind' => $surface,
                    'uuid' => $uuid,
                    'id' => (int) ($entry['id'] ?? 0),
                    'root_id' => (int) $rootId,
                    'old_parent_id' => (int) ($entry['old_parent_id'] ?? $entry['parent_id'] ?? 0),
                    'new_parent_id' => (int) ($entry['new_parent_id'] ?? 0),
                ];
            }
        }
        $out = array_values($rows);
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['kind'], $b['kind'])
            ?: strcmp($a['uuid'], $b['uuid'])
            ?: ($a['root_id'] <=> $b['root_id']));
        return $out;
    }

    public function entity_local_id(array $entity, string $uuid): ?int {
        $type = (string) ($entity['type'] ?? '');
        if ($type === 'post') {
            return Ledger::id_for($uuid, Ledger::KIND_POST);
        }
        if ($type === 'term' || $type === 'menu') {
            return Ledger::id_for($uuid, Ledger::KIND_TERM);
        }
        $table = $this->snapshotRowTables()[$type] ?? null;
        if (is_array($table) && is_string($table['id_kind'] ?? null)) {
            return Ledger::id_for($uuid, $table['id_kind']);
        }
        return null;
    }

    /** @return list<string> */
    private function entity_rebuild_surfaces(array $entity, array $entry = []): array {
        return CanonicalSurfaces::for_entity($entity, $entry, $this->policy);
    }

    /** @return list<string> */
    private function deletion_rebuild_surfaces(array $row): array {
        return CanonicalSurfaces::for_deletion($row);
    }

    /** @return array<int,int> */
    private function regen_context_root_ids(array $context): array {
        return RegenerationContext::root_ids($context);
    }

    /** @return array<string,array> */
    private function snapshotRowTables(): array {
        return $this->snapshotRowTables;
    }
}
