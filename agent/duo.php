<?php
/**
 * Duo agent bootstrap. Loaded as an mu-plugin (via duo-loader.php next to the
 * duo/ directory) and by wp-cli. Dependency-free by design: a drop-in agent
 * must not vendor libraries.
 */

if (!defined('ABSPATH') && !(defined('WP_CLI') && WP_CLI)) {
    return;
}

define('DUO_AGENT_VERSION', '0.5.0');
define('DUO_SPEC_VERSION', 2);

require_once __DIR__ . '/src/Uuid.php';
require_once __DIR__ . '/src/OrderPreserved.php';
require_once __DIR__ . '/src/Canon.php';
require_once __DIR__ . '/src/OptionState.php';
require_once __DIR__ . '/src/UserMetaState.php';
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/ProviderSdk.php';
require_once __DIR__ . '/src/Secrets.php';
require_once __DIR__ . '/src/CommandRefusal.php';
require_once __DIR__ . '/src/PersonalData.php';
require_once __DIR__ . '/src/ManifestDispositions.php';
require_once __DIR__ . '/src/AdapterSources.php';
require_once __DIR__ . '/src/CapabilityRegistry.php';
require_once __DIR__ . '/src/ScopedCertificationBundle.php';
require_once __DIR__ . '/src/NativeActions.php';
require_once __DIR__ . '/src/ReferenceRules.php';
require_once __DIR__ . '/src/ManifestGrammar.php';
require_once __DIR__ . '/src/AdapterRegistry.php';
require_once __DIR__ . '/src/Policy.php';
require_once __DIR__ . '/src/Providers.php';
require_once __DIR__ . '/src/Ledger.php';
require_once __DIR__ . '/src/PromotionLease.php';
require_once __DIR__ . '/src/ScopedPromotionAuthority.php';
require_once __DIR__ . '/src/PromotionLock.php';
require_once __DIR__ . '/src/PromotionSessionJournal.php';
require_once __DIR__ . '/src/LifecycleJournal.php';
require_once __DIR__ . '/src/StateTransitionJournal.php';
require_once __DIR__ . '/src/ProcessFence.php';
require_once __DIR__ . '/src/Identity.php';
require_once __DIR__ . '/src/IdentityBackup.php';
require_once __DIR__ . '/src/Deletion.php';
require_once __DIR__ . '/src/JsonRefs.php';
require_once __DIR__ . '/src/Tokens.php';
require_once __DIR__ . '/src/Blocks.php';
require_once __DIR__ . '/src/PlainData.php';
require_once __DIR__ . '/src/StructuredValue.php';
require_once __DIR__ . '/src/SidebarState.php';
require_once __DIR__ . '/src/Shortcodes.php';
require_once __DIR__ . '/src/Canary.php';
require_once __DIR__ . '/src/IdentityNotes.php';
require_once __DIR__ . '/src/Snapshot.php';
require_once __DIR__ . '/src/Orphans.php';
require_once __DIR__ . '/src/TransientDbException.php';
require_once __DIR__ . '/src/DurableFilesystem.php';
require_once __DIR__ . '/src/PublicationJournal.php';
require_once __DIR__ . '/src/AtomicTreePublisher.php';
require_once __DIR__ . '/src/Publish.php';
require_once __DIR__ . '/src/Capture.php';
require_once __DIR__ . '/src/RepositoryAuthorization.php';
require_once __DIR__ . '/src/CodeCompatibility.php';
require_once __DIR__ . '/src/PathSafety.php';
require_once __DIR__ . '/src/CodeStageTransaction.php';
require_once __DIR__ . '/src/Code.php';
require_once __DIR__ . '/src/ReferenceGraph.php';
require_once __DIR__ . '/src/CompiledArtifact.php';
require_once __DIR__ . '/src/RepositoryCompiler.php';
require_once __DIR__ . '/src/ScopeClosure.php';
require_once __DIR__ . '/src/InitSiteProbe.php';
require_once __DIR__ . '/src/InitCodeInventory.php';
require_once __DIR__ . '/src/InitFaults.php';
require_once __DIR__ . '/src/InitOwnedArtifacts.php';
require_once __DIR__ . '/src/InitRepositoryBoundary.php';
require_once __DIR__ . '/src/InitProtocol.php';
require_once __DIR__ . '/src/InitAttemptJournal.php';
require_once __DIR__ . '/src/InitRecovery.php';
require_once __DIR__ . '/src/InitExceptions.php';
require_once __DIR__ . '/src/InitCodeBaseline.php';
require_once __DIR__ . '/src/InitPlanner.php';
require_once __DIR__ . '/src/InitConfirmation.php';
require_once __DIR__ . '/src/Init.php';
require_once __DIR__ . '/src/CanonicalSurfaces.php';
require_once __DIR__ . '/src/ScopeContract.php';
require_once __DIR__ . '/src/ScopedStateOverlay.php';
require_once __DIR__ . '/src/ScopedApplySession.php';
require_once __DIR__ . '/src/ScopedApply.php';
require_once __DIR__ . '/src/ApplyPlanner.php';
require_once __DIR__ . '/src/PlanExplanation.php';
require_once __DIR__ . '/src/PlanCategorySummary.php';
require_once __DIR__ . '/src/PlanView.php';
require_once __DIR__ . '/src/CodeStateContract.php';
require_once __DIR__ . '/src/RefreshExport.php';
require_once __DIR__ . '/src/MenuMaterializer.php';
require_once __DIR__ . '/src/UserMetaMaterializer.php';
require_once __DIR__ . '/src/ConvergenceVerifier.php';
require_once __DIR__ . '/src/Apply.php';
require_once __DIR__ . '/src/Deploy.php';
require_once __DIR__ . '/src/Journal.php';
require_once __DIR__ . '/src/Pending.php';
require_once __DIR__ . '/src/AdapterObservation.php';
require_once __DIR__ . '/src/Coverage.php';
require_once __DIR__ . '/src/Lint.php';

/**
 * Additive classmap fallback (DUO-3481, owner rulings D3/D4).
 *
 * Every require_once above is retained and still does all the loading: after
 * this bootstrap runs, 237 of the 238 names in duo-classmap.php are already
 * declared, and the single exception (Duo\AdapterCertification) is
 * require_once'd at both of its use sites in AdapterSources.php before it is
 * ever named. An spl_autoload_register() callback is only consulted for a
 * class that is *still undeclared* at the moment it is referenced, so on the
 * production path this registration resolves nothing and changes nothing. It
 * exists for the partially-loaded contexts the drop-in also runs in — an
 * offline suite that includes three agent/src files by hand, a new file whose
 * hand-written require chain missed a dependency — where the alternative is a
 * fatal "Class not found" rather than a working load.
 *
 * Why this is not an autoloader in the sense AGENTS.md forbids: duo-classmap.php
 * is a generated first-party source file that lives in agent/ and ships with
 * it (cli/src/Adopt.php tars `agent manifests recovery` and cp -R's the whole
 * agent tree, and the map is inside the certification closure, so
 * ScopedCertificationBundle::assertRuntimeInputsCurrent() re-verifies its bytes
 * on every Policy::load()). Nothing is vendored, nothing is fetched, and no
 * composer artifact is involved.
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
 * Duo\Cli is deliberately absent from the map: agent/src/Cli.php's last line
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
    require_once __DIR__ . '/src/Cli.php';
}
