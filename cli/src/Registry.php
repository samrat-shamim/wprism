<?php
namespace Duo\Orchestrator;

/**
 * The environment registry: `envs` from the site repo's site.duo.json
 * (committable, no secrets) overlaid by a machine-local .duo-envs.json
 * (gitignored — compose file paths, ssh aliases, anything host-specific).
 * The overlay wins whole-entry per environment name; there is no
 * per-key deep merge.
 *
 * Both files are found by walking upward from the starting directory,
 * the same way git locates .git — so `duo` works from any subdirectory
 * of a site repo (or of wherever the overlay lives).
 */
final class Registry {
    /**
     * @return array<string, array<string, mixed>> env name => config, each
     *         tagged with a '_dir' key (the directory of the file that
     *         defined it, for resolving relative paths like compose_file).
     */
    public static function load(?string $overlayOverride, string $startDir): array {
        $envs = [];

        $siteFile = self::findUpwards($startDir, 'site.duo.json');
        if ($siteFile !== null) {
            $envs = self::mergeIn($envs, self::readEnvsFile($siteFile), dirname($siteFile), false);
        }

        if ($overlayOverride !== null) {
            $overlayFile = realpath($overlayOverride) ?: null;
            if ($overlayFile === null || !is_file($overlayFile)) {
                throw new \RuntimeException("--envs-file={$overlayOverride}: file not found");
            }
        } else {
            $overlayFile = self::findUpwards($startDir, '.duo-envs.json');
        }
        if ($overlayFile !== null) {
            $envs = self::mergeIn($envs, self::readEnvsFile($overlayFile), dirname($overlayFile), true);
        }

        return $envs;
    }

    /** @return array<string, array<string, mixed>> */
    private static function readEnvsFile(string $path): array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("could not read $path");
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("$path: invalid JSON: " . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new \RuntimeException("$path: expected a JSON object at the top level");
        }
        $envs = $data['envs'] ?? [];
        if (!is_array($envs)) {
            throw new \RuntimeException("$path: 'envs' must be an object");
        }
        foreach ($envs as $name => $cfg) {
            if (!is_array($cfg)) {
                throw new \RuntimeException("$path: envs.$name must be an object");
            }
        }
        return $envs;
    }

    /**
     * @param array<string, array<string, mixed>> $base
     * @param array<string, array<string, mixed>> $overlay
     * @return array<string, array<string, mixed>>
     */
    private static function mergeIn(array $base, array $overlay, string $dir, bool $machineLocal): array {
        foreach ($overlay as $name => $cfg) {
            // These provenance fields are loader-owned. A checked-in file
            // must not self-label privileged provider configuration as local.
            $cfg['_dir'] = $dir;
            $cfg['_machine_local'] = $machineLocal;
            $base[$name] = $cfg;
        }
        return $base;
    }

    /** Walk upward from $startDir looking for $filename, git-style. */
    private static function findUpwards(string $startDir, string $filename): ?string {
        $dir = realpath($startDir) ?: $startDir;
        while (true) {
            $candidate = $dir . '/' . $filename;
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null; // reached filesystem root
            }
            $dir = $parent;
        }
    }

    /** @param array<string, array<string, mixed>> $envs */
    public static function get(array $envs, string $name): array {
        if (!isset($envs[$name])) {
            $known = $envs ? implode(', ', array_keys($envs)) : '(none defined)';
            throw new \RuntimeException("unknown environment '$name'. Known environments: $known");
        }
        return $envs[$name];
    }
}
