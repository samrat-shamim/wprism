<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The environment registry: `envs` from the site repo's site.duo.json
 * (committable, no secrets) overlaid by a machine-local .duo-envs.json
 * (gitignored — compose file paths, ssh aliases, anything host-specific).
 * The overlay wins whole-entry per environment name; there is no
 * per-key deep merge.
 *
 * site.duo.json is found by walking upward from the starting directory and,
 * inside Git, accepted only at that worktree's root. The automatically trusted
 * overlay is pinned beside it, or to the current Git root when no site file
 * exists. Nested registries are refused rather than allowed to shadow target
 * authority.
 */
final class Registry {
    /**
     * @return array<string, array<string, mixed>> env name => config, each
     *         tagged with a '_dir' key (the directory of the file that
     *         defined it, for resolving relative paths like compose_file).
     */
    public static function load(?string $overlayOverride, string $startDir): array {
        $envs = [];

        $gitRoot = self::findGitRoot($startDir);
        $siteFile = self::findUpwards($startDir, 'site.duo.json');
        if ($siteFile !== null && $gitRoot !== null && dirname($siteFile) !== $gitRoot) {
            throw new \RuntimeException(
                "$siteFile: refusing a nested site.duo.json outside Git worktree root $gitRoot; "
                . 'the site registry must be rooted in the repository whose environments it controls'
            );
        }
        if ($siteFile !== null) {
            $envs = self::mergeIn($envs, self::readEnvsFile($siteFile), dirname($siteFile), false);
        }

        if ($overlayOverride !== null) {
            $overlayFile = realpath($overlayOverride) ?: null;
            if ($overlayFile === null || !is_file($overlayFile)) {
                throw new \RuntimeException("--envs-file={$overlayOverride}: file not found");
            }
        } else {
            $registryDir = $siteFile !== null
                ? dirname($siteFile)
                : ($gitRoot ?? (realpath($startDir) ?: $startDir));
            $nearestOverlay = self::findUpwards($startDir, '.duo-envs.json');
            if ($nearestOverlay !== null && dirname($nearestOverlay) !== $registryDir) {
                throw new \RuntimeException(
                    "$nearestOverlay: refusing an auto-discovered .duo-envs.json outside registry root "
                    . "$registryDir; keep the machine-local overlay beside site.duo.json (or at the Git root "
                    . 'when no site file exists), or select another trusted file explicitly with --envs-file'
                );
            }
            $candidate = $registryDir . '/.duo-envs.json';
            $overlayFile = is_file($candidate) ? $candidate : null;
            if ($overlayFile !== null && self::isGitTracked($overlayFile)) {
                throw new \RuntimeException(
                    "$overlayFile: refusing a Git-tracked .duo-envs.json; "
                    . 'privileged environment providers must be machine-local and untracked'
                );
            }
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
        // Keep an object-aware view alongside the associative runtime view.
        // PHP arrays cannot distinguish a JSON object with numeric keys from
        // a JSON list after decoding, but the registry contract must.
        $shape = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("$path: invalid JSON: " . json_last_error_msg());
        }
        if (!is_object($shape)) {
            throw new \RuntimeException("$path: expected a JSON object at the top level");
        }
        if (property_exists($shape, 'envs')
            && !is_object($shape->envs)
            && !(is_array($shape->envs) && $shape->envs === [])) {
            throw new \RuntimeException("$path: 'envs' must be an object (or an empty list)");
        }
        $data = json_decode($raw, true);
        $envs = $data['envs'] ?? [];
        foreach ($envs as $name => $cfg) {
            // PHP coerces a JSON object key such as "123" to an integer array
            // key. Numeric-only names are part of the public grammar, so
            // normalize the decoded key for validation and diagnostics.
            $environment = (string) $name;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $environment) !== 1) {
                throw new \RuntimeException(
                    "$path: environment names must match [A-Za-z0-9][A-Za-z0-9._-]{0,63}"
                );
            }
            if (!is_array($cfg)) {
                throw new \RuntimeException("$path: envs.$environment must be an object");
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

    /** Find the containing Git worktree without executing repository hooks. */
    private static function findGitRoot(string $startDir): ?string {
        $dir = realpath($startDir) ?: $startDir;
        while (true) {
            if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * Auto-discovery is a convenience, not an authority grant to repository
     * content. Check the path exactly as discovered (rather than its realpath)
     * so a committed symlink is also recognized as tracked. An explicit
     * --envs-file is a separate operator-selected trust boundary.
     */
    private static function isGitTracked(string $path): bool {
        $process = @proc_open([
            'git', '-C', dirname($path), 'ls-files', '--error-unmatch', '--', basename($path),
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0;
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
