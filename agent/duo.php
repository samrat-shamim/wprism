<?php
/**
 * Duo agent bootstrap. Loaded as an mu-plugin (via duo-loader.php next to the
 * duo/ directory) and by wp-cli. Dependency-free by design: a drop-in agent
 * must not vendor libraries.
 */

if (!defined('ABSPATH') && !(defined('WP_CLI') && WP_CLI)) {
    return;
}

if (PHP_VERSION_ID < 80200) {
    if (!defined('DUO_AGENT_RUNTIME_STATUS')) {
        define('DUO_AGENT_RUNTIME_STATUS', 'unsupported_php_' . PHP_MAJOR_VERSION . '_' . PHP_MINOR_VERSION);
    }
    return;
}

define('DUO_AGENT_VERSION', '0.5.0');
define('DUO_SPEC_VERSION', 2);

$duoLegacyClassmap = [
    // BEGIN GENERATED LEGACY CLASSMAP
    'Duo\\BoundHelper' => 'PublicationJournal.php',
    'Duo\\CodeCompilationException' => 'CodeDescriptorCompiler.php',
    'Duo\\CommandRefusalException' => 'CommandRefusal.php',
    'Duo\\CompiledRepository' => 'CompiledArtifact.php',
    'Duo\\DatabaseMutationException' => 'Db.php',
    'Duo\\InitAttemptRecord' => 'InitAttemptJournal.php',
    'Duo\\InitAttemptRetentionException' => 'InitExceptions.php',
    'Duo\\InitialStateBoundaryException' => 'DurableFilesystem.php',
    'Duo\\LedgerScopedApplySessionStorage' => 'ScopedApply.php',
    'Duo\\LifecycleAttemptRecord' => 'LifecycleJournal.php',
    'Duo\\PromotionLeaseRecord' => 'PromotionLease.php',
    'Duo\\PromotionSessionRecord' => 'PromotionSessionJournal.php',
    'Duo\\ProviderPackagingException' => 'Providers.php',
    'Duo\\PublicationRecord' => 'PublicationJournal.php',
    'Duo\\RepositoryAuthorizationException' => 'RepositoryAuthorization.php',
    'Duo\\RepositoryCompilationException' => 'CompiledArtifact.php',
    'Duo\\ScopedApplySessionStorage' => 'ScopedApplySession.php',
    'Duo\\ScopedOptionMutationUnsupported' => 'ScopeContract.php',
    'Duo\\StateTransitionRecord' => 'StateTransitionJournal.php',
    'Duo\\TargetRuntimeInspectionPort' => 'TargetRuntimeInspector.php',
    // END GENERATED LEGACY CLASSMAP
];

spl_autoload_register(static function (string $class) use ($duoLegacyClassmap): void {
    if (isset($duoLegacyClassmap[$class])) {
        require_once __DIR__ . '/src/' . $duoLegacyClassmap[$class];
        return;
    }
    $prefix = 'Duo\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $relative) !== 1) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

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
    class_exists(\Duo\Cli::class);
}
