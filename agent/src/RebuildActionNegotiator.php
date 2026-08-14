<?php
namespace Duo;

require_once __DIR__ . '/CanonicalSurfaces.php';
require_once __DIR__ . '/CommandRefusal.php';
require_once __DIR__ . '/Providers.php';
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
}

/** Selects and negotiates every rebuild action before target mutation. */
final class RebuildActionNegotiator {
    public function __construct(private readonly Policy $policy) {
    }

    /** @return array{selected_actions:list<array<string,mixed>>,negotiation:array,providers:array} */
    public function negotiate(
        array $work,
        array $tree,
        array $rebuildDeleteWork,
        array $deleteWork,
        bool $scoped,
        bool $scopedPromotion
    ): array {
        $selectedActions = $this->policy->actions_for(
            CanonicalSurfaces::for_apply($work, $tree, $rebuildDeleteWork, $this->policy)
        );
        if ($scopedPromotion) {
            self::assert_scoped_promotion_selection($selectedActions, $work, $deleteWork, $tree);
        }
        if ($scoped) {
            foreach ($selectedActions as $action) {
                if (!array_key_exists('triggers', $action)) {
                    throw new \RuntimeException(
                        'duo: scoped apply refused before target mutation — an untriggered global action has no bounded scope authority'
                    );
                }
            }
            foreach ($work as $entry) {
                $entity = $tree[(string) ($entry['uuid'] ?? '')] ?? null;
                $postType = is_array($entity) && ($entity['type'] ?? '') === 'post'
                    ? (string) ($entity['data']['type'] ?? '')
                    : '';
                if ($postType === 'attachment') {
                    throw new \RuntimeException(
                        'duo: scoped apply refused before target mutation — attachment metadata generation has no operation-bound reconciliation contract in this slice'
                    );
                }
                if ($postType !== '' && $this->policy->regen_dependency($postType) !== null) {
                    throw new \RuntimeException(
                        "duo: scoped apply refused before target mutation — post type '$postType' selects a legacy regenerator without operation-bound reconciliation"
                    );
                }
            }
            foreach ($deleteWork as $entry) {
                $postType = ($entry['type'] ?? '') === 'post'
                    ? (string) ($entry['deletion_type'] ?? '')
                    : '';
                if ($postType !== '' && $this->policy->regen_dependency($postType) !== null) {
                    throw new \RuntimeException(
                        "duo: scoped apply refused before target mutation — deleted post type '$postType' "
                        . 'selects a legacy regenerator without operation-bound reconciliation'
                    );
                }
            }
        }

        $negotiation = $scoped && method_exists(Providers::class, 'negotiate_scoped')
            ? Providers::negotiate_scoped($this->policy, $selectedActions)
            : Providers::negotiate($this->policy, $selectedActions);
        if ($negotiation['problems'] !== []) {
            throw new \RuntimeException(
                'duo: apply refused before target mutation — declared provider capabilities are unavailable '
                . "or incompatible in this environment:\n  - "
                . implode("\n  - ", array_column($negotiation['problems'], 'message'))
            );
        }
        if ($scoped) {
            foreach ($selectedActions as $action) {
                if (($action['kind'] ?? '') !== 'provider') {
                    continue;
                }
                $providerId = (string) ($action['provider'] ?? '');
                $capability = (string) ($action['capability'] ?? '');
                $declaration = $negotiation['capabilities'][$providerId][$capability] ?? null;
                if (!is_array($declaration)) {
                    continue;
                }
                foreach (['deletions', 'reparents'] as $channel) {
                    if (Providers::declares_channel($declaration, $channel)) {
                        throw new \RuntimeException(
                            "duo: scoped apply refused before target mutation — provider channel '$channel' "
                            . 'requires durable environment-local recovery input not carried by this scoped protocol version'
                        );
                    }
                }
            }
        }
        return [
            'selected_actions' => $selectedActions,
            'negotiation' => $negotiation,
            'providers' => [
                'providers' => $negotiation['providers'],
                'capabilities' => $negotiation['capabilities'],
                'scoped_capabilities' => (array) ($negotiation['scoped_capabilities'] ?? []),
            ],
        ];
    }

    public static function assert_scoped_promotion_selection(
        array $selectedActions,
        array $work,
        array $deleteWork,
        array $tree
    ): void {
        if ($selectedActions !== []) {
            throw CommandRefusalException::applyRefused(
                'scoped promotion selected a manifest/provider/native effect without a scoped inverse contract',
                'use ordinary scoped apply or narrow the contract to DB-contained state without declared actions',
                'duo: scoped promotion external effect refused'
            );
        }
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $type = (string) ($tree[$uuid]['type'] ?? '');
            if ($type === 'post' || $type === 'term' || $type === 'menu' || $type === '') {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion selected a post/term/menu surface whose derived effects are not checkpoint-only',
                    'narrow the contract to options, sidebars, user meta, or declared authored snapshot tables',
                    'duo: scoped promotion selected unsupported derived effects'
                );
            }
        }
        foreach ($deleteWork as $entry) {
            $kind = (string) ($entry['deletion_kind'] ?? $entry['kind'] ?? $entry['type'] ?? '');
            if (!in_array($kind, ['option', 'options', 'table'], true)) {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion selected a deletion outside its checkpoint-only state profile',
                    'narrow the contract to option or declared authored snapshot-table tombstones',
                    'duo: scoped promotion selected unsupported deletion effects'
                );
            }
        }
    }
}
