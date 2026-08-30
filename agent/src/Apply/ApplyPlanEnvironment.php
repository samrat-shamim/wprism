<?php
namespace WPrism;

require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/EnvironmentValues.php';
require_once __DIR__ . '/ProtectedPostIdentity.php';
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
        private readonly RebuildSelection $rebuildSelection,
        private readonly string $repo
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
    public function env_missing_projection(array $tree = []): array {
        global $wpdb;
        $expected = EnvironmentValues::read($this->repo);
        $projection = ApplyPlanner::env_missing_projection(
            $this->policy->env_options(),
            fn(string $name): mixed => $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name
            )),
            fn(string $name): ?string => isset($expected[$name]) ? (string) $expected[$name] : null
        );
        foreach ($tree as $uuid => $entity) {
            if (!is_array($entity) || ($entity['type'] ?? null) !== 'post') {
                continue;
            }
            $front = is_array($entity['data'] ?? null) ? $entity['data'] : [];
            if (!array_key_exists('password_binding', $front)) {
                continue;
            }
            $binding = EnvironmentValues::postPasswordName((string) $uuid);
            if (!is_string($front['password_binding']) || !hash_equals($binding, $front['password_binding'])) {
                throw new \RuntimeException('wprism: canonical protected post carries an invalid password binding');
            }
            $intended = $expected[$binding] ?? null;
            $postType = $front['type'] ?? null;
            if (!is_string($postType) || $postType === '') {
                throw new \RuntimeException('wprism: canonical protected post carries no post type');
            }
            $postWitness = ProtectedPostIdentity::observe((string) $uuid, $postType);
            $postId = $postWitness['post_id'] ?? null;
            $live = $postWitness['post_password'] ?? null;
            $matches = is_string($intended) && $intended !== ''
                && ($postId === null || (is_string($live) && hash_equals($intended, $live)));
            if ($matches) {
                continue;
            }
            $projection['env_missing'][] = ['name' => $binding, 'required' => true];
            $state = $intended === null ? 'not yet provisioned' : 'different from its intended value';
            $projection['warnings'][] = "env_missing: post password binding '$binding' is required and $state on "
                . "this environment — see 'wp wprism env-set --name=$binding --stdin'";
        }

        return $projection;
    }
}
