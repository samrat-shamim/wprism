<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';

/**
 * Canonical deletion intent. Absence from state is never authority: capture
 * converts a previously-compiled live entity that disappeared from the
 * source environment into one durable tombstone, tied to that entity's hash
 * and the exact prior repository revision. Subsequent captures preserve the
 * tombstone byte-for-byte until the entity reappears.
 */
final class Deletion {
    public const FORMAT = 'wprism-deletion/v1';

    /** @return array{kind:string,type:string} */
    public static function descriptor(array $entity): array {
        $data = (array) ($entity['data'] ?? []);
        if (($entity['type'] ?? '') === 'post') {
            return ['kind' => 'post', 'type' => (string) ($data['type'] ?? '')];
        }
        if (($entity['type'] ?? '') === 'term') {
            return ['kind' => 'term', 'type' => (string) ($data['taxonomy'] ?? '')];
        }
        if (($entity['type'] ?? '') === 'menu') {
            return ['kind' => 'menu', 'type' => 'nav_menu'];
        }
        return ['kind' => 'table', 'type' => (string) ($entity['type'] ?? '')];
    }

    public static function selector(string $kind, string $type): string {
        return "$kind:$type";
    }

    /**
     * Assert that an adapter explicitly owns the destructive effects this
     * entity kind needs. A missing capability is a correct refusal, not a
     * request to infer WordPress/plugin cascade behavior.
     *
     * @return array<string,mixed> normalized capability
     */
    public static function capability(Policy $policy, string $kind, string $type): array {
        $selector = self::selector($kind, $type);
        $cap = $policy->deletion_capability($selector);
        if ($cap === null) {
            throw self::unsupported_capability_refusal($selector);
        }
        $required = match ($kind) {
            'post' => ['postmeta', 'post_revisions', 'term_relationships'],
            'term' => ['termmeta', 'term_taxonomy', 'term_relationships'],
            'menu' => ['termmeta', 'term_taxonomy', 'term_relationships', 'menu_items'],
            'table' => self::table_requires_attached_meta($policy, $type) ? ['attached_meta'] : [],
            default => throw new \RuntimeException("wprism: invalid deletion kind '$kind'"),
        };
        $declared = array_values(array_unique(array_map('strval', (array) ($cap['cascades'] ?? []))));
        $missing = array_values(array_diff($required, $declared));
        if ($missing) {
            throw new \RuntimeException(
                "wprism: deletion capability $selector omits required cascade effect(s): " . implode(', ', $missing)
            );
        }
        if (($cap['active_plugin_boundary'] ?? null) === 'declarers_only') {
            if (!function_exists('get_option')) {
                throw new \RuntimeException(
                    "wprism: deletion capability $selector requires a live active-plugin boundary"
                );
            }
            $active = array_values(array_unique(array_filter(
                array_map('strval', (array) get_option('active_plugins', [])),
                static fn(string $plugin): bool => $plugin !== ''
            )));
            $declaring = array_fill_keys((array) ($cap['declaring_plugins'] ?? []), true);
            $unowned = array_values(array_filter(
                $active,
                static fn(string $plugin): bool => !isset($declaring[$plugin])
            ));
            sort($unowned, SORT_STRING);
            if ($unowned) {
                throw new CommandRefusalException(
                    'deletion_plugin_boundary',
                    "deletion intent for $selector is blocked by active plugins outside its closed reverse-reference contract",
                    'deactivate the named plugins or extend each owning adapter with an agreeing deletion declaration and guards',
                    array_map(static fn(string $plugin): array => [
                        'code' => 'deletion_plugin_boundary',
                        'surface' => $selector,
                        'plugin' => $plugin,
                        'message' => 'active plugin does not participate in this deletion contract',
                        'remediation' => 'deactivate it or add a reviewed agreeing deletion declaration to its adapter',
                    ], $unowned),
                    "wprism: deletion intent for $selector is blocked while these active plugins have no agreeing reverse-reference contract: "
                        . implode(', ', $unowned)
                );
            }
        }
        return $cap;
    }

    /** One reviewed machine contract for a generic deletion selector no adapter owns. */
    private static function unsupported_capability_refusal(string $selector): CommandRefusalException {
        $operatorMessage = "wprism: deletion intent for $selector is unsupported — no pinned adapter declares its reverse-reference checks and cascade effects";
        return new CommandRefusalException(
            'unsupported_deletion',
            "deletion intent for $selector is unsupported because no pinned adapter owns its destructive semantics",
            'restore the missing source entity, or pin a compatible adapter that declares the required reverse-reference guards and cascade effects before trying again',
            [[
                'code' => 'unsupported_deletion',
                'surface' => $selector,
                'message' => 'no pinned adapter declares this deletion selector',
                'remediation' => 'restore the missing source entity or pin a compatible adapter with complete deletion guards and cascade effects',
            ]],
            $operatorMessage
        );
    }

    /**
     * @param array<int,array<string,mixed>> $liveEntities Capture::build()
     * @return array<int,array{uuid:string,type:string,path:string,content:string}>
     */
    public static function capture_tombstones(
        ?CompiledRepository $previous,
        array $liveEntities,
        Policy $policy,
        ?array $authorizedUuids = null
    ): array {
        if ($previous === null) {
            return [];
        }
        $live = [];
        foreach ($liveEntities as $entity) {
            $live[(string) $entity['uuid']] = true;
        }
        $authorized = $authorizedUuids === null
            ? null
            : array_fill_keys(array_map('strval', $authorizedUuids), true);

        $out = [];
        foreach ($previous->deletions() as $uuid => $deletion) {
            if ($authorized !== null && !isset($authorized[$uuid])) {
                continue;
            }
            if (isset($live[$uuid])) {
                continue;
            }
            $data = (array) $deletion['data'];
            self::capability($policy, (string) $data['kind'], (string) $data['type']);
            $out[] = [
                'uuid' => $uuid,
                'type' => 'deletion',
                'path' => (string) $deletion['path'],
                'content' => (string) $deletion['content'],
            ];
        }

        foreach ($previous->tree() as $uuid => $entity) {
            if ($authorized !== null && !isset($authorized[$uuid])) {
                continue;
            }
            // User-meta sidecar absence is deliberately not deletion
            // authority. Users are target-local and have no portable
            // lifecycle; an empty retained sidecar expresses owned-key
            // removal while removing the file leaves target metadata alone.
            if ($uuid === 'options/core'
                || in_array(($entity['type'] ?? ''), ['user-meta', SidebarState::ENTITY_TYPE], true)
                || isset($live[$uuid])) {
                continue;
            }
            $desc = self::descriptor($entity);
            self::capability($policy, $desc['kind'], $desc['type']);
            $data = [
                'format' => self::FORMAT,
                'uuid' => $uuid,
                'kind' => $desc['kind'],
                'type' => $desc['type'],
                'expected_hash' => (string) $entity['hash'],
                'expected_revision' => $previous->revision_hash(),
                'source_path' => (string) $entity['path'],
            ];
            $out[] = [
                'uuid' => $uuid,
                'type' => 'deletion',
                'path' => "deletions/$uuid.json",
                'content' => Canon::encode($data),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return $out;
    }

    private static function table_requires_attached_meta(Policy $policy, string $table): bool {
        foreach (Snapshot::meta_tables($policy) as $decl) {
            if (($decl['attached_to']['table'] ?? null) === $table) {
                return true;
            }
        }
        return false;
    }
}
