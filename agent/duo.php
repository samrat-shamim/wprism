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
require_once __DIR__ . '/src/Secrets.php';
require_once __DIR__ . '/src/CommandRefusal.php';
require_once __DIR__ . '/src/PersonalData.php';
require_once __DIR__ . '/src/ManifestDispositions.php';
require_once __DIR__ . '/src/AdapterSources.php';
require_once __DIR__ . '/src/CapabilityRegistry.php';
require_once __DIR__ . '/src/NativeActions.php';
require_once __DIR__ . '/src/ReferenceRules.php';
require_once __DIR__ . '/src/Policy.php';
require_once __DIR__ . '/src/Providers.php';
require_once __DIR__ . '/src/Ledger.php';
require_once __DIR__ . '/src/PromotionLock.php';
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
require_once __DIR__ . '/src/Publish.php';
require_once __DIR__ . '/src/Capture.php';
require_once __DIR__ . '/src/RepositoryAuthorization.php';
require_once __DIR__ . '/src/CodeCompatibility.php';
require_once __DIR__ . '/src/Code.php';
require_once __DIR__ . '/src/ReferenceGraph.php';
require_once __DIR__ . '/src/RepositoryCompiler.php';
require_once __DIR__ . '/src/ScopeClosure.php';
require_once __DIR__ . '/src/CodeStateContract.php';
require_once __DIR__ . '/src/RefreshExport.php';
require_once __DIR__ . '/src/Apply.php';
require_once __DIR__ . '/src/Deploy.php';
require_once __DIR__ . '/src/Journal.php';
require_once __DIR__ . '/src/Pending.php';
require_once __DIR__ . '/src/Coverage.php';
require_once __DIR__ . '/src/Lint.php';

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
