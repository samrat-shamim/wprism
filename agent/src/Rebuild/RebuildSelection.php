<?php
namespace WPrism;

require_once __DIR__ . '/../Policy/Policy.php';

require_once __DIR__ . '/../Adapter/Providers.php';

/** Negotiated provider-action ownership for one apply/rebuild workflow. */
final class RebuildSelection {
    /** @var list<array<string,mixed>> */
    private array $selectedActions = [];
    /** @var array<string,mixed>|null */
    private ?array $negotiatedProviders = null;

    public function __construct(private readonly Policy $policy) {}

    /** @param list<array<string,mixed>> $actions */
    public function set_selected_actions(array $actions): void {
        $this->selectedActions = $actions;
    }

    /** @return list<array<string,mixed>> */
    public function selected_actions(): array {
        return $this->selectedActions;
    }

    /** @param array<string,mixed>|null $providers */
    public function set_negotiated_providers(?array $providers): void {
        $this->negotiatedProviders = $providers;
    }

    /** @return array<string,mixed>|null */
    public function negotiated_providers(): ?array {
        return $this->negotiatedProviders;
    }

    public function declares_channel_for(string $channel, string $surface): bool {
        foreach ($this->selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider'
                || !in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                continue;
            }
            $declaration = $this->negotiatedProviders['capabilities']
                [(string) ($action['provider'] ?? '')]
                [(string) ($action['capability'] ?? '')] ?? null;
            if (is_array($declaration) && Providers::declares_channel($declaration, $channel)) {
                return true;
            }
        }
        return false;
    }

    public function declares_entity_batch_for(string $surface): bool {
        foreach ($this->selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider'
                || !in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                continue;
            }
            $declaration = $this->negotiatedProviders['capabilities']
                [(string) ($action['provider'] ?? '')]
                [(string) ($action['capability'] ?? '')] ?? null;
            if (is_array($declaration) && ($declaration['scope'] ?? '') === 'entity') {
                return true;
            }
        }
        return $this->pinned_action_owns($surface);
    }

    public function pinned_action_triggers(string $surface): bool {
        foreach ($this->policy->actions() as $action) {
            if (($action['kind'] ?? '') === 'provider'
                && in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    public function pinned_action_owns(string $surface): bool {
        foreach ($this->policy->actions() as $action) {
            if (($action['kind'] ?? '') !== 'provider'
                || !in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                continue;
            }
            $declaration = $this->negotiatedProviders['capabilities']
                [(string) ($action['provider'] ?? '')]
                [(string) ($action['capability'] ?? '')] ?? null;
            if (!is_array($declaration) || ($declaration['scope'] ?? '') === 'entity') {
                return true;
            }
        }
        return false;
    }

    public function triggers_provider_action_for(string $surface): bool {
        foreach ($this->selectedActions as $action) {
            if (($action['kind'] ?? '') === 'provider'
                && in_array($surface, (array) ($action['triggers'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }
}
