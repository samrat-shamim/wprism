<?php
declare(strict_types=1);

namespace Duo\Recovery;

/**
 * Closed file-name contract for the standalone recovery runtime.
 *
 * Adoption copies the recovery directory as a unit, while host/bootstrap
 * checks may otherwise inspect only a historical subset. This manifest makes
 * the complete runtime closure explicit and lets the target refuse a partial
 * or substituted package before authority work begins.
 */
final class RecoveryRuntimeManifest {
    public const FORMAT = 'duo-recovery-runtime-manifest/v1';
    private const VERSION = 1;

    /** @return list<string> */
    public static function files(string $runtimeRoot): array {
        $path = rtrim($runtimeRoot, '/') . '/runtime-manifest.json';
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('duo rollback: recovery runtime manifest is missing or unsafe');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('duo rollback: recovery runtime manifest is invalid JSON');
        }
        $keys = array_keys($decoded);
        sort($keys, SORT_STRING);
        if ($keys !== ['files', 'format', 'version']
            || ($decoded['format'] ?? null) !== self::FORMAT
            || ($decoded['version'] ?? null) !== self::VERSION
            || !is_array($decoded['files'] ?? null)
            || !array_is_list($decoded['files'])) {
            throw new \RuntimeException('duo rollback: recovery runtime manifest has an open schema');
        }
        $files = [];
        foreach ($decoded['files'] as $file) {
            if (!is_string($file) || $file === '' || basename($file) !== $file
                || preg_match('/^[A-Za-z0-9._-]+\.(?:php|json)$/D', $file) !== 1
                || in_array($file, $files, true)) {
                throw new \RuntimeException('duo rollback: recovery runtime manifest has an invalid file list');
            }
            $files[] = $file;
        }
        $expected = [
            'AtomicStore.php',
            'CanonicalJson.php',
            'CheckpointBundle.php',
            'CodeRelease.php',
            'EffectBundle.php',
            'ProviderClient.php',
            'ProtocolLock.php',
            'RecoveryAuthorityController.php',
            'RecoveryAuthorityKernel.php',
            'RecoveryCliDispatcher.php',
            'RecoveryExecutor.php',
            'RecoveryRuntimeManifest.php',
            'RecoveryTransitionPolicy.php',
            'TransitionFaultMatrix.php',
            'UploadBundle.php',
            'promotion-recovery-decision.schema.json',
            'rollback-control.php',
            'transition-fault-matrix.json',
        ];
        sort($files, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($files !== $expected) {
            throw new \RuntimeException('duo rollback: recovery runtime manifest closure is not the reviewed set');
        }
        $entries = scandir($runtimeRoot);
        if (!is_array($entries)) {
            throw new \RuntimeException('duo rollback: recovery runtime directory cannot be inspected');
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        $expectedEntries = $files;
        $expectedEntries[] = 'runtime-manifest.json';
        sort($expectedEntries, SORT_STRING);
        if ($entries !== $expectedEntries) {
            throw new \RuntimeException('duo rollback: recovery runtime contains an unreviewed file');
        }
        foreach ($files as $file) {
            $candidate = rtrim($runtimeRoot, '/') . '/' . $file;
            if (is_link($candidate) || !is_file($candidate)) {
                throw new \RuntimeException("duo rollback: recovery runtime file '$file' is missing or unsafe");
            }
        }
        return $files;
    }
}
