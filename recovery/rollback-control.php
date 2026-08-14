#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Standalone recovery bootstrap.
 *
 * The authority implementation lives in RecoveryAuthorityKernel; this file
 * intentionally performs only dependency composition and CLI dispatch so the
 * packaged recovery entry point cannot grow another state machine.
 */
require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/AtomicStore.php';
require_once __DIR__ . '/ProtocolLock.php';
require_once __DIR__ . '/ProviderClient.php';
require_once __DIR__ . '/RecoveryExecutor.php';
require_once __DIR__ . '/CheckpointBundle.php';
require_once __DIR__ . '/CodeRelease.php';
require_once __DIR__ . '/UploadBundle.php';
require_once __DIR__ . '/EffectBundle.php';
require_once __DIR__ . '/RecoveryTransitionPolicy.php';
require_once __DIR__ . '/RecoveryRuntimeManifest.php';
require_once __DIR__ . '/RecoveryAuthorityKernel.php';
require_once __DIR__ . '/RecoveryAuthorityController.php';
require_once __DIR__ . '/RecoveryCliDispatcher.php';

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(RecoveryCliDispatcher::run($argv));
}
