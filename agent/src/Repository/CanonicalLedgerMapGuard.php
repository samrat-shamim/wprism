<?php
namespace Duo;

if (!class_exists(CommandRefusalException::class, false)) {
    require_once __DIR__ . '/../Kernel/CommandRefusal.php';
}
if (!class_exists(Uuid::class, false)) {
    require_once __DIR__ . '/../Kernel/Uuid.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(CanonicalMapWitness::class, false)) {
    require_once __DIR__ . '/CanonicalMapWitness.php';
}
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/CompiledArtifact.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/Ledger.php';
}
if (!class_exists(SidebarState::class, false)) {
    require_once __DIR__ . '/SidebarState.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/Snapshot.php';
}

/** Refuse retained canonical mappings which no longer prove physical identity. */
final class CanonicalLedgerMapGuard {
    /**
     * Run before any dead-map pruning. A canonical identity with no map is a
     * legitimate fresh-target case; once any map exists, its complete exact
     * physical tuple is recovery authority and may not be silently discarded.
     */
    public static function assert_pre_prune(Policy $policy, CompiledRepository $compiled): void {
        $expected = self::expected_rows($policy, $compiled->tree());
        $byUuid = [];
        $byIdentityKind = [];
        foreach (Ledger::all_map() as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            $kind = (string) ($row['id_kind'] ?? '');
            $byUuid[$uuid][] = $row;
            $byIdentityKind[$uuid][$kind] = $row;
        }

        try {
            foreach ($expected as $uuid => $kinds) {
                $mappedRows = (array) ($byUuid[$uuid] ?? []);
                if ($mappedRows === []) {
                    continue;
                }
                if (count($mappedRows) !== count($kinds)) {
                    throw new \RuntimeException('canonical mapped identity has an incomplete or unexpected tuple');
                }
                foreach ($mappedRows as $row) {
                    $kind = (string) ($row['id_kind'] ?? '');
                    $evidence = $kinds[$kind] ?? null;
                    if (!is_array($evidence)
                        || (string) ($row['entity_type'] ?? '') !== (string) ($evidence['entity_type'] ?? '')) {
                        throw new \RuntimeException('canonical mapped identity has contradictory ledger coordinates');
                    }
                }
                foreach ($kinds as $kind => $evidence) {
                    $row = $byIdentityKind[$uuid][$kind] ?? null;
                    if (!is_array($row)) {
                        throw new \RuntimeException('canonical mapped identity is missing a required tuple');
                    }
                    CanonicalMapWitness::assert_exact(
                        $policy,
                        $uuid,
                        $kind,
                        $row,
                        $evidence,
                        $byIdentityKind
                    );
                }
            }
        } catch (\Throwable $failure) {
            if ($failure instanceof CommandRefusalException
                && $failure->reasonCode === 'canonical_identity_recovery_required') {
                throw $failure;
            }
            throw CommandRefusalException::canonicalIdentityRecoveryRequired($failure);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $tree
     * @return array<string,array<string,array<string,mixed>>>
     */
    private static function expected_rows(Policy $policy, array $tree): array {
        $expected = [];
        $add = static function (
            string $uuid,
            string $kind,
            string $entityType,
            array $details = []
        ) use (&$expected): void {
            if (!Uuid::is($uuid) || $kind === '' || $entityType === '') {
                return;
            }
            $evidence = ['entity_type' => $entityType] + $details;
            if (isset($expected[$uuid][$kind]) && $expected[$uuid][$kind] !== $evidence) {
                throw new \RuntimeException('duo: compiled canonical identity has contradictory map requirements');
            }
            $expected[$uuid][$kind] = $evidence;
        };
        $rowTables = Snapshot::row_tables($policy);
        foreach ($tree as $identity => $entity) {
            $identity = (string) $identity;
            $type = (string) ($entity['type'] ?? '');
            $data = (array) ($entity['data'] ?? []);
            if ($type === 'post') {
                $add($identity, Ledger::KIND_POST, 'post', ['post_type' => (string) ($data['type'] ?? '')]);
            } elseif ($type === 'term' || $type === 'menu') {
                $taxonomy = $type === 'menu' ? 'nav_menu' : (string) ($data['taxonomy'] ?? '');
                $add($identity, Ledger::KIND_TERM, $type, ['taxonomy' => $taxonomy]);
                $add($identity, Ledger::KIND_TT, $type, ['taxonomy' => $taxonomy]);
            } elseif (isset($rowTables[$type])) {
                $add($identity, (string) ($rowTables[$type]['id_kind'] ?? ''), $type, ['table' => $type]);
            }
            if ($type === 'menu') {
                foreach ((array) ($data['items'] ?? []) as $item) {
                    $add(
                        (string) ($item['uuid'] ?? ''),
                        Ledger::KIND_POST,
                        'menu_item',
                        ['owner' => $identity, 'post_type' => 'nav_menu_item']
                    );
                }
            } elseif ($type === SidebarState::ENTITY_TYPE) {
                $sidebar = str_starts_with($identity, 'sidebar/')
                    ? substr($identity, strlen('sidebar/'))
                    : '';
                foreach ((array) ($data['widgets'] ?? []) as $widget) {
                    $widgetType = (string) ($widget['type'] ?? '');
                    $add(
                        (string) ($widget['uuid'] ?? ''),
                        SidebarState::kind($widgetType),
                        'widget',
                        ['owner' => $sidebar, 'widget_type' => $widgetType]
                    );
                }
            }
        }
        return $expected;
    }
}
