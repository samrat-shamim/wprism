<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
require_once __DIR__ . '/RegenerationContext.php';

/**
 * Captures and rehydrates the durable pre-mutation evidence needed by
 * regeneration consumers after post deletes and reparents.
 *
 * The provider-selection predicate is supplied explicitly because ownership
 * is fixed by Apply's pre-mutation negotiation, while persistence and target
 * reads belong here. This keeps the store independent of Apply's mutable
 * orchestration state without weakening the capture gate.
 */
final class RegenerationContextStore {
    public const DELETE_PREFIX = 'regen_delete_context:';
    public const REPARENT_PREFIX = 'regen_reparent_context:';

    /** @var \Closure(string,string):bool */
    private readonly \Closure $selectionDeclaresChannelFor;

    public function __construct(Policy $policy, \Closure $selectionDeclaresChannelFor) {
        $this->policy = $policy;
        $this->selectionDeclaresChannelFor = $selectionDeclaresChannelFor;
    }

    private readonly Policy $policy;

    public function checked_get_var(mixed $sql, string $context): mixed {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var($sql);
        if ($value === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: regeneration bookkeeping read failed: $context");
        }
        return $value;
    }

    public function checked_get_row(mixed $sql, string $context): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: regeneration bookkeeping read failed: $context");
        }
        return $row;
    }

    /** @return array<int,mixed> */
    public function checked_get_col(mixed $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($sql);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: regeneration bookkeeping read failed: $context");
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function capture_reparents(
        array $work,
        array $tree,
        bool $persistGenericDebt = true,
        ?DatabaseWorkAuthority $workAuthority = null
    ): array {
        global $wpdb;
        $out = [];
        foreach ($work as $entry) {
            DatabaseQueryIsolation::work_unit($workAuthority, function () use ($entry, $tree, $persistGenericDebt, $wpdb, &$out): void {
                $uuid = (string) ($entry['uuid'] ?? '');
                $entity = $tree[$uuid] ?? null;
                if ($uuid === '' || ($entity['type'] ?? '') !== 'post') {
                    return;
                }
                $front = (array) ($entity['data'] ?? []);
                $postType = (string) ($front['type'] ?? '');
                if ($postType === '' || !$this->has_consumer('reparents', $postType)) {
                    return;
                }
                $id = Ledger::id_for($uuid, Ledger::KIND_POST);
                if ($id === null && isset($entry['env_id'])) {
                    $adoptId = (int) $entry['env_id'];
                    if ($adoptId > 0) {
                        $mappedUuid = Ledger::uuid_for($adoptId, Ledger::KIND_POST);
                        if ($mappedUuid !== null && $mappedUuid !== $uuid) {
                            throw new \RuntimeException(
                                "wprism: cannot capture reparent context for adopted post $adoptId ($uuid): "
                                . "the local id is already mapped to canonical post $mappedUuid"
                            );
                        }
                        $id = $adoptId;
                    }
                }
                if ($id === null) {
                    return;
                }
                $row = $this->checked_get_row($wpdb->prepare(
                    "SELECT post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d",
                    $id
                ), "reparent source post $id");
                if (!is_array($row) || (string) ($row['post_type'] ?? '') !== $postType) {
                    return;
                }
                $oldParentId = (int) ($row['post_parent'] ?? 0);
                if ($oldParentId <= 0) {
                    return;
                }

                $parentToken = (string) ($front['parent'] ?? '');
                $newParentId = 0;
                $newParentKnown = $parentToken === '';
                if ($parentToken !== ''
                    && preg_match('/^\{\{post:([0-9a-f-]{36})\}\}$/', $parentToken, $match)) {
                    $newParentId = (int) (Ledger::id_for($match[1], Ledger::KIND_POST) ?? 0);
                    $newParentKnown = $newParentId > 0;
                }
                $oldParentUuid = Ledger::uuid_for($oldParentId, Ledger::KIND_POST);
                $sameParent = $newParentId === $oldParentId
                    || (!$newParentKnown && $oldParentUuid !== null
                        && $parentToken === '{{post:' . $oldParentUuid . '}}');
                if ($sameParent) {
                    return;
                }

                $context = [
                    'kind' => 'reparent',
                    'uuid' => $uuid,
                    'id' => (int) $id,
                    'post_type' => $postType,
                    'parent_id' => $oldParentId,
                    'old_parent_id' => $oldParentId,
                    'new_parent_id' => $newParentId,
                    'child_ids' => [],
                ];
                $stored = $persistGenericDebt
                    ? Ledger::kv_get(self::REPARENT_PREFIX . $uuid)
                    : null;
                $previous = is_string($stored) ? json_decode($stored, true) : null;
                if (is_array($previous)
                    && ($previous['kind'] ?? '') === 'reparent'
                    && (string) ($previous['uuid'] ?? $uuid) === $uuid) {
                    $context = RegenerationContext::merge($previous, $context);
                } else {
                    $context['root_ids'] = array_values(RegenerationContext::root_ids($context));
                    sort($context['root_ids'], SORT_NUMERIC);
                }
                if ($persistGenericDebt) {
                    Ledger::kv_set(self::REPARENT_PREFIX . $uuid, json_encode($context));
                }
                $out[] = $context;
            });
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function capture_deletions(
        array $deleteWork,
        bool $persistGenericDebt = true,
        ?DatabaseWorkAuthority $workAuthority = null
    ): array {
        global $wpdb;
        $out = [];
        $explicitIds = [];
        foreach ($deleteWork as $entry) {
            DatabaseQueryIsolation::work_unit($workAuthority, function () use ($entry, &$explicitIds): void {
                if (($entry['type'] ?? '') !== 'post') {
                    return;
                }
                $entryId = Ledger::id_for((string) ($entry['uuid'] ?? ''), Ledger::KIND_POST);
                if ($entryId !== null) {
                    $explicitIds[(int) $entryId] = true;
                }
            });
        }
        foreach ($deleteWork as $entry) {
            DatabaseQueryIsolation::work_unit($workAuthority, function () use ($entry, $persistGenericDebt, $explicitIds, $wpdb, &$out): void {
                if (($entry['type'] ?? '') !== 'post') {
                    return;
                }
                $uuid = (string) ($entry['uuid'] ?? '');
                if ($uuid === '') {
                    return;
                }
                $id = Ledger::id_for($uuid, Ledger::KIND_POST);
                if ($id === null) {
                    if (!$persistGenericDebt) {
                        return;
                    }
                    $stored = Ledger::kv_get(self::DELETE_PREFIX . $uuid);
                    $decoded = is_string($stored) ? json_decode($stored, true) : null;
                    $replayed = $this->replayable_delete($uuid, $decoded);
                    if ($replayed !== null) {
                        $out[] = $replayed;
                    } elseif ($stored !== null) {
                        Ledger::kv_delete(self::DELETE_PREFIX . $uuid);
                    }
                    return;
                }
                $row = $this->checked_get_row($wpdb->prepare(
                    "SELECT post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d",
                    $id
                ), "delete source post $id");
                if ($row === null) {
                    if (!$persistGenericDebt) {
                        return;
                    }
                    $stored = Ledger::kv_get(self::DELETE_PREFIX . $uuid);
                    $decoded = is_string($stored) ? json_decode($stored, true) : null;
                    $replayed = $this->replayable_delete($uuid, $decoded);
                    if ($replayed !== null) {
                        $out[] = $replayed;
                    } elseif ($stored !== null) {
                        Ledger::kv_delete(self::DELETE_PREFIX . $uuid);
                    }
                    return;
                }
                $postType = (string) ($row['post_type'] ?? '');
                if ($postType === '' || !$this->has_consumer('deletions', $postType)) {
                    if ($persistGenericDebt) {
                        Ledger::kv_delete(self::DELETE_PREFIX . $uuid);
                    }
                    return;
                }
                $parentId = (int) ($row['post_parent'] ?? 0);
                $childIds = [];
                foreach ($this->policy->child_post_types($postType) as $childPostType) {
                    foreach ($this->checked_get_col($wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s ORDER BY ID ASC",
                        $id,
                        $childPostType
                    ), "delete child inventory for post $id (type $childPostType)") as $childId) {
                        $childId = (int) $childId;
                        if ($childId > 0 && isset($explicitIds[$childId])) {
                            $childIds[$childId] = $childId;
                        }
                    }
                }
                $childIds = array_values($childIds);
                sort($childIds, SORT_NUMERIC);
                $context = [
                    'kind' => 'delete',
                    'uuid' => $uuid,
                    'id' => (int) $id,
                    'post_type' => $postType,
                    'parent_id' => $parentId,
                    'child_ids' => $childIds,
                ];
                if ($persistGenericDebt) {
                    Ledger::kv_set(self::DELETE_PREFIX . $uuid, json_encode($context));
                }
                $out[] = $context;
            });
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function durable_deletions(): array {
        return $this->durable_contexts(self::DELETE_PREFIX, 'delete');
    }

    /** @return list<array<string,mixed>> */
    public function durable_reparents(): array {
        return $this->durable_contexts(self::REPARENT_PREFIX, 'reparent');
    }

    private function has_consumer(string $channel, string $postType): bool {
        return $this->policy->regen_batch($postType) !== null
            || ($this->selectionDeclaresChannelFor)($channel, 'post:' . $postType);
    }

    private function replayable_delete(string $uuid, mixed $decoded): ?array {
        $postType = is_array($decoded) ? (string) ($decoded['post_type'] ?? '') : '';
        if ($postType === '' || !$this->has_consumer('deletions', $postType)
            || !is_array($decoded) || (int) ($decoded['id'] ?? 0) <= 0) {
            return null;
        }
        return [
            'kind' => 'delete',
            'uuid' => $uuid,
            'id' => (int) $decoded['id'],
            'post_type' => $postType,
            'parent_id' => (int) ($decoded['parent_id'] ?? 0),
            'child_ids' => array_values(array_map('intval', (array) ($decoded['child_ids'] ?? []))),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function durable_contexts(string $prefix, string $kind): array {
        $out = [];
        foreach (Ledger::kv_prefix($prefix) as $key => $encoded) {
            $context = is_string($encoded) ? json_decode($encoded, true) : null;
            if (!is_array($context)) {
                continue;
            }
            $postType = (string) ($context['post_type'] ?? '');
            $id = (int) ($context['id'] ?? 0);
            if ($postType === '' || $id <= 0) {
                continue;
            }
            if (!isset($context['uuid']) || (string) $context['uuid'] === '') {
                $context['uuid'] = substr((string) $key, strlen($prefix));
            }
            if (!isset($context['kind']) || (string) $context['kind'] === '') {
                $context['kind'] = $kind;
            }
            $context['_marker_key'] = (string) $key;
            $out[] = $context;
        }
        return $out;
    }
}
