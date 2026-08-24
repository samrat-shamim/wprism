<?php
namespace Duo;

require_once __DIR__ . '/ApplyServiceCallbacks.php';
require_once __DIR__ . '/ApplyPlanner.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/MenuMaterializer.php';
require_once __DIR__ . '/UserMetaMaterializer.php';
require_once __DIR__ . '/TermMaterializer.php';
require_once __DIR__ . '/OptionsMaterializer.php';
require_once __DIR__ . '/RelationshipMaterializer.php';
require_once __DIR__ . '/AttachmentMaterializer.php';
require_once __DIR__ . '/PostMaterializer.php';
require_once __DIR__ . '/../Delete/DeleteExecutor.php';
require_once __DIR__ . '/../Delete/DeleteGuardReferenceScanner.php';
require_once __DIR__ . '/../Adapter/ProviderActionBatchBuilder.php';
require_once __DIR__ . '/../Rebuild/RegenerationContextStore.php';
require_once __DIR__ . '/../Rebuild/DependencyRegenerator.php';
require_once __DIR__ . '/../Rebuild/NativeRebuildExecutor.php';
require_once __DIR__ . '/../Rebuild/RebuildActionDispatcher.php';
require_once __DIR__ . '/ApplyLedgerFinalizer.php';
require_once __DIR__ . '/EntityAdopter.php';
require_once __DIR__ . '/AuthoredTransactionExecutor.php';
require_once __DIR__ . '/../Rebuild/RebuildActionNegotiator.php';
require_once __DIR__ . '/../Grammar/ShortcodeAlternateRegistrar.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/../Repository/Snapshot.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}

/** Composition root and lazy collaborator registry for one apply invocation. */
final class ApplyServices {
    private Tokens $tokens;
    private ApplyFieldMaterializer $fieldMaterializer;
    private ?MenuMaterializer $menuMaterializer = null;
    private ?UserMetaMaterializer $userMetaMaterializer = null;
    private ?TermMaterializer $termMaterializer = null;
    private ?OptionsMaterializer $optionsMaterializer = null;
    private ?RelationshipMaterializer $relationshipMaterializer = null;
    private ?AttachmentMaterializer $attachmentMaterializer = null;
    private ?PostMaterializer $postMaterializer = null;
    private ?DeleteExecutor $deleteExecutor = null;
    private ?DeleteGuardReferenceScanner $deleteGuardReferenceScanner = null;
    private ?ProviderActionBatchBuilder $providerActionBatchBuilder = null;
    private ?RegenerationContextStore $regenerationContextStore = null;
    private ?DependencyRegenerator $dependencyRegenerator = null;
    private ?NativeRebuildExecutor $nativeRebuildExecutor = null;
    private ?RebuildActionDispatcher $rebuildActionDispatcher = null;
    private ?ApplyLedgerFinalizer $applyLedgerFinalizer = null;
    private ?EntityAdopter $entityAdopter = null;
    private ?AuthoredTransactionExecutor $authoredTransactionExecutor = null;
    private ?RebuildActionNegotiator $rebuildActionNegotiator = null;
    private ?ShortcodeAlternateRegistrar $shortcodeAlternateRegistrar = null;
    private ?ApplyPlanner $applyPlanner = null;
    /** @var array<string,array>|null */
    private ?array $snapshotRowTables = null;

    public function __construct(
        private readonly Policy $policy,
        private readonly CompiledRepository $compiled,
        private readonly ApplyServiceCallbacks $callbacks,
        private readonly string $repositoryRoot
    ) {
        $this->tokens = new Tokens();
        $this->tokens->policy = $policy;
        $this->fieldMaterializer = new ApplyFieldMaterializer($policy, $this->tokens);
    }

    public function tokens(): Tokens {
        return $this->tokens;
    }

    /** @return array<string,array> */
    public function snapshot_row_tables(): array {
        return $this->snapshotRowTables ??= Snapshot::row_tables($this->policy);
    }

    public function field_materializer(): ApplyFieldMaterializer {
        return $this->fieldMaterializer;
    }

    public function menu_materializer(): MenuMaterializer {
        return $this->menuMaterializer ??= new MenuMaterializer(
            $this->policy,
            $this->tokens,
            $this->field_materializer()
        );
    }

    public function user_meta_materializer(): UserMetaMaterializer {
        return $this->userMetaMaterializer ??= new UserMetaMaterializer(
            $this->policy,
            $this->tokens,
            $this->field_materializer()
        );
    }

    public function term_materializer(): TermMaterializer {
        return $this->termMaterializer ??= new TermMaterializer(
            $this->policy,
            $this->tokens,
            $this->field_materializer()
        );
    }

    public function options_materializer(): OptionsMaterializer {
        return $this->optionsMaterializer ??= new OptionsMaterializer(
            $this->policy,
            $this->tokens,
            $this->field_materializer()
        );
    }

    public function relationship_materializer(): RelationshipMaterializer {
        return $this->relationshipMaterializer ??= new RelationshipMaterializer(
            $this->policy,
            $this->field_materializer()
        );
    }

    public function attachment_materializer(): AttachmentMaterializer {
        return $this->attachmentMaterializer ??= new AttachmentMaterializer(
            $this->field_materializer(),
            $this->compiled,
            $this->repositoryRoot
        );
    }

    public function post_materializer(): PostMaterializer {
        return $this->postMaterializer ??= new PostMaterializer(
            $this->policy,
            $this->tokens,
            $this->field_materializer(),
            $this->relationship_materializer(),
            $this->attachment_materializer()
        );
    }

    public function delete_executor(): DeleteExecutor {
        return $this->deleteExecutor ??= new DeleteExecutor(
            $this->policy,
            $this->relationship_materializer(),
            $this->menu_materializer(),
            $this->field_materializer()
        );
    }

    public function delete_guard_reference_scanner(): DeleteGuardReferenceScanner {
        return $this->deleteGuardReferenceScanner ??= new DeleteGuardReferenceScanner($this->policy);
    }

    public function provider_action_batch_builder(): ProviderActionBatchBuilder {
        return $this->providerActionBatchBuilder ??= new ProviderActionBatchBuilder(
            $this->policy,
            $this->snapshot_row_tables()
        );
    }

    public function regeneration_context_store(): RegenerationContextStore {
        return $this->regenerationContextStore ??= new RegenerationContextStore(
            $this->policy,
            $this->callbacks->selectionDeclaresChannelFor
        );
    }

    public function dependency_regenerator(): DependencyRegenerator {
        return $this->dependencyRegenerator ??= new DependencyRegenerator(
            $this->policy,
            $this->regeneration_context_store(),
            $this->callbacks->selectionDeclaresEntityBatchFor,
            $this->callbacks->selectionDeclaresChannelFor,
            $this->callbacks->selectionTriggersProviderActionFor,
            $this->callbacks->pinnedProviderActionOwns,
            $this->callbacks->renewRegenerationLease
        );
    }

    public function native_rebuild_executor(): NativeRebuildExecutor {
        return $this->nativeRebuildExecutor ??= new NativeRebuildExecutor(
            $this->policy,
            $this->callbacks->upsertMeta,
            $this->attachment_materializer()
        );
    }

    public function rebuild_action_dispatcher(): RebuildActionDispatcher {
        return $this->rebuildActionDispatcher ??= new RebuildActionDispatcher(
            $this->policy,
            $this->provider_action_batch_builder(),
            $this->callbacks->renewProviderLease
        );
    }

    public function apply_ledger_finalizer(): ApplyLedgerFinalizer {
        return $this->applyLedgerFinalizer ??= new ApplyLedgerFinalizer(
            fn(): mixed => ($this->callbacks->renewPromotionLock)('apply-ledger')
        );
    }

    public function entity_adopter(): EntityAdopter {
        return $this->entityAdopter ??= new EntityAdopter(
            $this->policy,
            $this->snapshot_row_tables(),
            $this->field_materializer()
        );
    }

    public function authored_transaction_executor(): AuthoredTransactionExecutor {
        return $this->authoredTransactionExecutor ??= new AuthoredTransactionExecutor(
            $this->policy,
            $this->tokens,
            $this->apply_planner(),
            $this->snapshot_row_tables(),
            $this->field_materializer(),
            $this->entity_adopter(),
            $this->term_materializer(),
            $this->post_materializer(),
            $this->attachment_materializer(),
            $this->menu_materializer(),
            $this->options_materializer(),
            $this->user_meta_materializer(),
            $this->delete_executor(),
            $this->regeneration_context_store(),
            $this->callbacks->taxonomyOwnership,
            $this->callbacks->renewPromotionLock,
            $this->callbacks->lockDeleteGuards,
            $this->callbacks->recheckDeleteGuard
        );
    }

    public function rebuild_action_negotiator(): RebuildActionNegotiator {
        return $this->rebuildActionNegotiator ??= new RebuildActionNegotiator($this->policy);
    }

    public function shortcode_alternate_registrar(): ShortcodeAlternateRegistrar {
        return $this->shortcodeAlternateRegistrar ??= new ShortcodeAlternateRegistrar(
            $this->policy,
            $this->tokens
        );
    }

    public function apply_planner(): ApplyPlanner {
        return $this->applyPlanner ??= new ApplyPlanner(
            $this->policy,
            $this->snapshot_row_tables(),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for(
                $uuid,
                $kind === 'tt' ? Ledger::KIND_TT : $kind
            ),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for($uuid, $kind)
        );
    }
}
