<?php
namespace WPrism;

require_once __DIR__ . '/../Repository/CanonicalSurfaces.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Adapter/Providers.php';
require_once __DIR__ . '/NativeRewriteEffects.php';
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
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
            CanonicalSurfaces::for_apply($work, $tree, $rebuildDeleteWork, $this->policy),
            CanonicalSurfaces::mutation_channels_for_apply($work, $rebuildDeleteWork)
        );
        if ($scopedPromotion) {
            self::assert_scoped_promotion_selection($selectedActions, $work, $deleteWork, $tree);
        }
        if ($scoped) {
            foreach ($work as $entry) {
                $entity = $tree[(string) ($entry['uuid'] ?? '')] ?? null;
                $postType = is_array($entity) && ($entity['type'] ?? '') === 'post'
                    ? (string) ($entity['data']['type'] ?? '')
                    : '';
                if ($postType === 'attachment') {
                    throw new \RuntimeException(
                        'wprism: scoped apply refused before target mutation — attachment metadata generation has no operation-bound reconciliation contract in this slice'
                    );
                }
                if ($postType !== '' && $this->policy->regen_dependency($postType) !== null) {
                    throw new \RuntimeException(
                        "wprism: scoped apply refused before target mutation — post type '$postType' selects a legacy regenerator without operation-bound reconciliation"
                    );
                }
            }
            foreach ($deleteWork as $entry) {
                $postType = ($entry['type'] ?? '') === 'post'
                    ? (string) ($entry['deletion_type'] ?? '')
                    : '';
                if ($postType !== '' && $this->policy->regen_dependency($postType) !== null) {
                    throw new \RuntimeException(
                        "wprism: scoped apply refused before target mutation — deleted post type '$postType' "
                        . 'selects a legacy regenerator without operation-bound reconciliation'
                    );
                }
            }
        }

        // Provider and native actions execute after the authored commit. A
        // rewrite-topology refusal cannot undo that commit, so preflight every
        // selected action whose reviewed effects say it reaches Core's
        // generate_rewrite_rules hook. This includes a provider that delegates
        // to NativeActions::rewrite.flush, not only a manifest `kind:native`
        // action. The fresh child repeats the proof immediately before
        // generation to close topology drift between preflight and effect.
        self::preflight_rewrite_actions($selectedActions);

        $negotiation = $scoped && method_exists(Providers::class, 'negotiate_scoped')
            ? Providers::negotiate_scoped($this->policy, $selectedActions)
            : Providers::negotiate($this->policy, $selectedActions);
        if ($negotiation['problems'] !== []) {
            throw new \RuntimeException(
                'wprism: apply refused before target mutation — declared provider capabilities are unavailable '
                . "or incompatible in this environment:\n  - "
                . implode("\n  - ", array_column($negotiation['problems'], 'message'))
            );
        }
        if ($scoped) {
            self::assert_scoped_action_authority($selectedActions, $negotiation, 'apply');
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
                            "wprism: scoped apply refused before target mutation — provider channel '$channel' "
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

    /**
     * An untriggered action is globally selected, but that does not make its
     * effects unbounded once the scope contract has hashed its declaration,
     * effects and exact provider capability. Admit only the provider form
     * whose operation receipt and reconciliation contract negotiated here;
     * native actions and legacy providers retain the historical refusal.
     *
     * @param list<array<string,mixed>> $selectedActions
     * @param array<string,mixed> $negotiation
     */
    public static function assert_scoped_action_authority(
        array $selectedActions,
        array $negotiation,
        string $operation
    ): void {
        foreach ($selectedActions as $action) {
            if (array_key_exists('triggers', $action)) {
                continue;
            }
            $provider = (string) ($action['provider'] ?? '');
            $capability = (string) ($action['capability'] ?? '');
            if (($action['kind'] ?? '') === 'provider'
                && isset($negotiation['scoped_capabilities'][$provider][$capability])) {
                continue;
            }
            throw new \RuntimeException(
                "wprism: scoped $operation refused before target mutation — an untriggered global action "
                . 'requires a successfully negotiated operation-bound provider reconciliation contract'
            );
        }
    }

    /** @param list<array<string,mixed>> $selectedActions */
    private static function preflight_rewrite_actions(array $selectedActions): void {
        foreach ($selectedActions as $action) {
            if (!self::action_generates_rewrite_rules($action)) {
                continue;
            }
            try {
                NativeRewriteEffects::prepare();
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    "wprism: apply refused before target mutation — native action 'rewrite.flush' runtime is unsupported: "
                    . $failure->getMessage(),
                    0,
                    $failure
                );
            }
            // One request has one loaded callback/service topology. Every
            // selected rewrite-generating action is covered by the same proof;
            // its fresh child still re-proves immediately before each effect.
            return;
        }
    }

    /** @param array<string,mixed> $action */
    private static function action_generates_rewrite_rules(array $action): bool {
        if (($action['kind'] ?? '') === 'native' && ($action['action'] ?? '') === 'rewrite.flush') {
            return true;
        }
        foreach ((array) ($action['effects'] ?? []) as $effect) {
            if (!is_array($effect) || ($effect['kind'] ?? '') !== 'external') {
                continue;
            }
            $selector = $effect['selector'] ?? null;
            if (is_array($selector)
                && ($selector['scope'] ?? '') === 'external'
                && ($selector['type'] ?? '') === 'hook'
                && ($selector['value'] ?? '') === 'generate_rewrite_rules') {
                return true;
            }
        }
        return false;
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
                'wprism: scoped promotion external effect refused'
            );
        }
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $type = (string) ($tree[$uuid]['type'] ?? '');
            if ($type === 'post' || $type === 'term' || $type === 'menu' || $type === '') {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion selected a post/term/menu surface whose derived effects are not checkpoint-only',
                    'narrow the contract to options, sidebars, user meta, or declared authored snapshot tables',
                    'wprism: scoped promotion selected unsupported derived effects'
                );
            }
        }
        foreach ($deleteWork as $entry) {
            $kind = (string) ($entry['deletion_kind'] ?? $entry['kind'] ?? $entry['type'] ?? '');
            if (!in_array($kind, ['option', 'options', 'table'], true)) {
                throw CommandRefusalException::applyRefused(
                    'scoped promotion selected a deletion outside its checkpoint-only state profile',
                    'narrow the contract to option or declared authored snapshot-table tombstones',
                    'wprism: scoped promotion selected unsupported deletion effects'
                );
            }
        }
    }
}
