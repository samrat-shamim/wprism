<?php
/**
 * WPrism mu-plugin loader. WordPress only auto-loads top-level files in
 * wp-content/mu-plugins/, so this file sits there and pulls in the agent
 * from the wprism/ directory beside it.
 *
 * The directory flock binds one process to one published agent generation.
 * Adoption replaces both paths below, so neither can be the stable lock
 * object. One shared, transaction-owned writer-intent link gives adoption and
 * unadoption mutual exclusion and priority over new readers; the post-lock
 * check closes the check/acquire race.
 * WPRISM_AGENT_GENERATION_FENCE_PROTOCOL=1
 */
$wprismCliProcess = defined('WP_CLI') && WP_CLI;
$wprismCliOpcache = ini_get('opcache.enable_cli');
if ($wprismCliProcess && is_string($wprismCliOpcache)
    && in_array(strtolower(trim($wprismCliOpcache)), ['1', 'on', 'true', 'yes'], true)) {
    throw new RuntimeException(
        'wprism: WP-CLI opcache.enable_cli=1 is unsupported; refusing before replaceable agent bytes load'
    );
}

$wprismWriterPending = static fn(): bool =>
    @lstat(__DIR__ . '/.wprism-generation-writer-pending') !== false;
$wprismDirectoryIdentity = static function (array $stat): string {
    return (string) $stat['dev'] . ':' . (string) $stat['ino'] . ':' . (string) ($stat['mode'] & 0170000);
};
$wprismReleaseGeneration = static function (&$handle): void {
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    @fclose($handle);
    $handle = null;
};

if ($wprismWriterPending()) {
    throw new RuntimeException('wprism: an agent generation writer is active or requires recovery');
}
$wprismDirectoryBefore = @lstat(__DIR__);
$wprismGenerationHandle = @fopen(__DIR__, 'rb');
$wprismDirectoryOpened = is_resource($wprismGenerationHandle) ? @fstat($wprismGenerationHandle) : false;
if (!is_array($wprismDirectoryBefore) || !is_array($wprismDirectoryOpened)
    || ($wprismDirectoryBefore['mode'] & 0170000) !== 0040000
    || !hash_equals(
        $wprismDirectoryIdentity($wprismDirectoryBefore),
        $wprismDirectoryIdentity($wprismDirectoryOpened)
    )
    || !@flock($wprismGenerationHandle, LOCK_SH)) {
    $wprismReleaseGeneration($wprismGenerationHandle);
    throw new RuntimeException('wprism: could not acquire the stable MU-plugin generation fence');
}
$wprismDirectoryAfter = @lstat(__DIR__);
if (!is_array($wprismDirectoryAfter)
    || !hash_equals(
        $wprismDirectoryIdentity($wprismDirectoryOpened),
        $wprismDirectoryIdentity($wprismDirectoryAfter)
    )
    || $wprismWriterPending()) {
    $wprismReleaseGeneration($wprismGenerationHandle);
    throw new RuntimeException('wprism: the agent generation changed while its loader was acquiring a read fence');
}

try {
    require_once __DIR__ . '/wprism/wprism.php';
} catch (Throwable $failure) {
    $wprismReleaseGeneration($wprismGenerationHandle);
    throw $failure;
}

if ($wprismCliProcess) {
    // WP-CLI can load manifest executables long after bootstrap. Keep the
    // descriptor in the private shutdown closure, rather than a mutable global,
    // so another plugin cannot release the generation fence early.
    register_shutdown_function(static function () use ($wprismGenerationHandle, $wprismReleaseGeneration): void {
        $wprismReleaseGeneration($wprismGenerationHandle);
    });
} else {
    // Normal WordPress requests eagerly load the fixed agent source graph and
    // never execute a WPrism command. Releasing here avoids holding adoption
    // behind the rest of the application request.
    $wprismReleaseGeneration($wprismGenerationHandle);
}

unset(
    $wprismCliOpcache,
    $wprismCliProcess,
    $wprismDirectoryAfter,
    $wprismDirectoryBefore,
    $wprismDirectoryIdentity,
    $wprismDirectoryOpened,
    $wprismGenerationHandle,
    $wprismReleaseGeneration,
    $wprismWriterPending
);
