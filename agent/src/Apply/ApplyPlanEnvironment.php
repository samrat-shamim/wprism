<?php
namespace Duo;

require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/../Adapter/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/../Rebuild/RegenerationContextStore.php';
require_once __DIR__ . '/../Rebuild/RebuildSelection.php';
require_once __DIR__ . '/../Policy/Policy.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}

/** WordPress/Ledger observations injected into the otherwise pure plan builder. */
final class ApplyPlanEnvironment {
    public function __construct(
        private readonly Policy $policy,
        private readonly RebuildSelection $rebuildSelection
    ) {}

    /** @return array{regen_pending:list<array>,regen_context:list<array>,warnings:list<string>} */
    public function regeneration_debt_projection(): array {
        return ApplyPlanner::regeneration_debt_projection(
            Ledger::kv_prefix(ProviderActionBatchBuilder::REGEN_PENDING_PREFIX),
            Ledger::kv_prefix(RegenerationContextStore::DELETE_PREFIX),
            Ledger::kv_prefix(RegenerationContextStore::REPARENT_PREFIX),
            fn(mixed $postType): bool => $this->policy->regen_dependency($postType) !== null,
            fn(string $uuid): bool => Ledger::id_for($uuid, Ledger::KIND_POST) !== null,
            fn(string $postType): bool => $this->policy->regen_batch($postType) !== null
                || $this->rebuildSelection->pinned_action_triggers('post:' . $postType),
        );
    }

    /** @return array{env_missing:list<array{name:string,required:bool}>,warnings:list<string>} */
    public function env_missing_projection(): array {
        global $wpdb;
        return ApplyPlanner::env_missing_projection(
            $this->policy->env_options(),
            fn(string $name): mixed => $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name
            ))
        );
    }
}
