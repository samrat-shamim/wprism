<?php
/**
 * Duo agent bootstrap. Loaded as an mu-plugin (via duo-loader.php next to the
 * duo/ directory) and by wp-cli. Dependency-free by design: a drop-in agent
 * must not vendor libraries.
 */

if (!defined('ABSPATH') && !(defined('WP_CLI') && WP_CLI)) {
    return;
}

define('DUO_AGENT_VERSION', '0.6.0');
define('DUO_SPEC_VERSION', 3);

require_once __DIR__ . '/src/Kernel/Uuid.php';
require_once __DIR__ . '/src/Kernel/OrderPreserved.php';
require_once __DIR__ . '/src/Kernel/Canon.php';
require_once __DIR__ . '/src/Kernel/OptionState.php';
require_once __DIR__ . '/src/Kernel/UserMetaState.php';
require_once __DIR__ . '/src/Kernel/Db.php';
require_once __DIR__ . '/src/Apply/CacheInvalidationTransaction.php';
require_once __DIR__ . '/src/Adapter/ProviderSdk.php';
require_once __DIR__ . '/src/Adapter/ManifestProviderRuntime.php';
require_once __DIR__ . '/src/Kernel/Secrets.php';
require_once __DIR__ . '/src/Kernel/CommandRefusal.php';
// Beside CommandRefusal because that is its one dependency, and ahead of every
// verb: the topology gate has to be loaded for the Policy-free doors
// (journal-reset, the promotion-lease verbs, classify) that never reach
// src/Policy/Policy.php.
require_once __DIR__ . '/src/Kernel/SiteTopology.php';
require_once __DIR__ . '/src/Kernel/PersonalData.php';
require_once __DIR__ . '/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/src/Policy/PlatformCompatibility.php';
require_once __DIR__ . '/src/Policy/AdapterPackage.php';
require_once __DIR__ . '/src/Policy/AdapterLibrary.php';
require_once __DIR__ . '/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/src/Adapter/TargetProbe.php';
require_once __DIR__ . '/src/Rebuild/NativeActions.php';
require_once __DIR__ . '/src/Kernel/ReferenceRules.php';
require_once __DIR__ . '/src/Policy/ManifestGrammar.php';
require_once __DIR__ . '/src/Adapter/AdapterRegistry.php';
require_once __DIR__ . '/src/Policy/Policy.php';
// After Policy, which it loads pins through: the survey's scan handle holds
// one resolved library for a whole read-only survey (WP-1.3).
require_once __DIR__ . '/src/Adapter/AdapterScan.php';
require_once __DIR__ . '/src/Adapter/Providers.php';
require_once __DIR__ . '/src/Repository/Ledger.php';
require_once __DIR__ . '/src/Promotion/PromotionLease.php';
require_once __DIR__ . '/src/Promotion/ScopedPromotionAuthority.php';
require_once __DIR__ . '/src/Promotion/PromotionLock.php';
require_once __DIR__ . '/src/Promotion/PromotionSessionJournal.php';
require_once __DIR__ . '/src/Promotion/LifecycleJournal.php';
require_once __DIR__ . '/src/Promotion/StateTransitionJournal.php';
require_once __DIR__ . '/src/Kernel/ProcessFence.php';
require_once __DIR__ . '/src/Repository/Identity.php';
require_once __DIR__ . '/src/Repository/IdentityBackup.php';
require_once __DIR__ . '/src/Delete/Deletion.php';
require_once __DIR__ . '/src/Kernel/JsonRefs.php';
require_once __DIR__ . '/src/Grammar/Tokens.php';
require_once __DIR__ . '/src/Grammar/Blocks.php';
require_once __DIR__ . '/src/Kernel/PlainData.php';
require_once __DIR__ . '/src/Kernel/StructuredValue.php';
require_once __DIR__ . '/src/Repository/SidebarState.php';
require_once __DIR__ . '/src/Grammar/Shortcodes.php';
require_once __DIR__ . '/src/Kernel/Canary.php';
require_once __DIR__ . '/src/Repository/IdentityNotes.php';
require_once __DIR__ . '/src/Repository/Snapshot.php';
require_once __DIR__ . '/src/Delete/Orphans.php';
require_once __DIR__ . '/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/src/Kernel/DurableFilesystem.php';
require_once __DIR__ . '/src/Kernel/MediaPayloadAuthority.php';
require_once __DIR__ . '/src/Publication/PublicationJournal.php';
require_once __DIR__ . '/src/Publication/AtomicTreePublisher.php';
require_once __DIR__ . '/src/Publication/Publish.php';
require_once __DIR__ . '/src/Capture/Capture.php';
require_once __DIR__ . '/src/Repository/RepositoryAuthorization.php';
require_once __DIR__ . '/src/Code/CodeCompatibility.php';
require_once __DIR__ . '/src/Kernel/PathSafety.php';
require_once __DIR__ . '/src/Code/CodeStageTransaction.php';
require_once __DIR__ . '/src/Code/Code.php';
require_once __DIR__ . '/src/Repository/ReferenceGraph.php';
require_once __DIR__ . '/src/Repository/CompiledArtifact.php';
require_once __DIR__ . '/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/src/Policy/ScopeClosure.php';
require_once __DIR__ . '/src/Init/InitSiteProbe.php';
require_once __DIR__ . '/src/Init/InitCodeInventory.php';
require_once __DIR__ . '/src/Init/InitFaults.php';
require_once __DIR__ . '/src/Init/InitOwnedArtifacts.php';
require_once __DIR__ . '/src/Init/InitRepositoryBoundary.php';
require_once __DIR__ . '/src/Init/InitProtocol.php';
require_once __DIR__ . '/src/Init/InitAttemptJournal.php';
require_once __DIR__ . '/src/Init/InitRecovery.php';
require_once __DIR__ . '/src/Init/InitExceptions.php';
require_once __DIR__ . '/src/Init/InitCodeBaseline.php';
require_once __DIR__ . '/src/Init/InitPlanner.php';
require_once __DIR__ . '/src/Init/InitConfirmation.php';
require_once __DIR__ . '/src/Init/Init.php';
require_once __DIR__ . '/src/Repository/CanonicalSurfaces.php';
require_once __DIR__ . '/src/Policy/ScopeContract.php';
require_once __DIR__ . '/src/Scope/ScopedStateOverlay.php';
require_once __DIR__ . '/src/Scope/ScopedApplySession.php';
require_once __DIR__ . '/src/Scope/ScopedApply.php';
require_once __DIR__ . '/src/Apply/ApplyPlanner.php';
require_once __DIR__ . '/src/Review/PlanExplanation.php';
require_once __DIR__ . '/src/Review/PlanCategorySummary.php';
require_once __DIR__ . '/src/Review/PlanView.php';
require_once __DIR__ . '/src/Code/CodeStateContract.php';
require_once __DIR__ . '/src/Review/RefreshExport.php';
require_once __DIR__ . '/src/Apply/MenuMaterializer.php';
require_once __DIR__ . '/src/Apply/UserMetaMaterializer.php';
require_once __DIR__ . '/src/Apply/ConvergenceVerifier.php';
require_once __DIR__ . '/src/Apply/Apply.php';
require_once __DIR__ . '/src/Promotion/Deploy.php';
require_once __DIR__ . '/src/Repository/Journal.php';
require_once __DIR__ . '/src/Review/EffectDeclarationCoverage.php';
require_once __DIR__ . '/src/Review/Pending.php';
require_once __DIR__ . '/src/Adapter/AdapterObservation.php';
require_once __DIR__ . '/src/Adapter/AdapterProbe.php';
require_once __DIR__ . '/src/Adapter/DeletionFeasibility.php';
require_once __DIR__ . '/src/Review/Coverage.php';
require_once __DIR__ . '/src/Review/Lint.php';
require_once __DIR__ . '/src/Assess/AssessInventory.php';

/**
 * Additive classmap fallback (DUO-3481, owner rulings D3/D4).
 *
 * Every require_once above is retained and still does all the loading: after
 * this bootstrap runs, 247 of the 251 names in duo-classmap.php are already
 * declared, and the four exceptions (Duo\AdapterCertification and the three
 * withdrawal/supersession signals declared in the same file) are
 * require_once'd at each of that file's three use sites in AdapterSources.php
 * before any of them is ever named. An spl_autoload_register() callback is only consulted for a
 * class that is *still undeclared* at the moment it is referenced, so on the
 * production path this registration resolves nothing and changes nothing. It
 * exists for the partially-loaded contexts the drop-in also runs in — an
 * offline suite that includes three agent/src files by hand, a new file whose
 * hand-written require chain missed a dependency — where the alternative is a
 * fatal "Class not found" rather than a working load.
 *
 * Why this is not an autoloader in the sense AGENTS.md forbids: duo-classmap.php
 * is a generated first-party source file that lives in agent/ and ships with
 * it (Adopt assembles the adapter library into the staged agent, then tars
 * exactly `agent recovery`), regenerated from the tree itself by tools/classmap-generate.php
 * and checked by its own --check mode. Nothing is vendored, nothing is
 * fetched, and no composer artifact is involved.
 *
 * Three properties keep it behaviour-neutral, and each is load-bearing:
 *  - It is appended, never prepended, and it throws nothing. A name it does
 *    not know is passed straight on to any other registered autoloader.
 *  - `class_exists(X::class, false)` sites are untouched by construction —
 *    the `false` argument suppresses autoloading — which is what keeps the
 *    ~40 guarded top-level require blocks in agent/src and every sandbox
 *    shadow-block test (which pre-declares a stub so the guard skips the real
 *    file) behaving exactly as before.
 *  - The is_file() test keeps a *missing* file a missing class rather than a
 *    fatal. Without it a stale map entry would turn today's graceful
 *    "support is not loaded" diagnostics into an uncatchable require failure.
 *
 * Duo\Cli is deliberately absent from the map: agent/src/Command/Cli.php's last line
 * is WP_CLI::add_command('duo', Cli::class), which must keep running only
 * under the WP_CLI require at the bottom of this file.
 */
if (!defined('DUO_CLASSMAP_REGISTERED')) {
    define('DUO_CLASSMAP_REGISTERED', true);
    /** @var array<string,string> $duoClassmap */
    $duoClassmap = require __DIR__ . '/duo-classmap.php';
    spl_autoload_register(static function (string $class) use ($duoClassmap): void {
        if (!isset($duoClassmap[$class])) {
            return;
        }
        $file = __DIR__ . '/' . $duoClassmap[$class];
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Provenance journal is opt-in: define('DUO_JOURNAL', true) in wp-config.php
// (or export DUO_JOURNAL=1 in the environment).
// The control-plane loader runs at WP-CLI's after_wp_config_load boundary,
// before WordPress has defined get_option()/add_filter()/add_action(). It is
// deliberately isolated from normal MU/plugin bootstrap, so provenance
// observation cannot be installed there. Fail closed until the ordinary WP
// runtime has all journal APIs available; the normal opt-in path is unchanged.
$duoJournalEnabled = (defined('DUO_JOURNAL') && DUO_JOURNAL) || getenv('DUO_JOURNAL') === '1';
$duoControlPlane = defined('DUO_CONTROL_PLANE') && DUO_CONTROL_PLANE === true;
if ($duoJournalEnabled
    && !$duoControlPlane
    && function_exists('get_option')
    && function_exists('add_filter')
    && function_exists('add_action')) {
    \Duo\Journal::boot();
}

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/src/Command/Cli.php';
}
