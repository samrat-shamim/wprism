<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Target-free option grammar for the nested `duo env` command. */
final class EnvironmentCommandOptions {
    /** @return array{source:string,branch:string,create:bool,ttl_seconds:int,json:bool} */
    public static function materialize(array $args): array {
        $source = null;
        $branch = null;
        $create = false;
        $ttl = 0;
        $ttlSeen = false;
        $json = false;
        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];
            if (!is_string($arg)) {
                throw new \RuntimeException('unsupported non-string env flag');
            }
            if ($arg === '--create') {
                if ($create) throw new \RuntimeException('duplicate --create');
                $create = true;
                continue;
            }
            if ($arg === '--format=json') {
                if ($json) throw new \RuntimeException('duplicate --format=json');
                $json = true;
                continue;
            }
            if ($arg === '--from' || str_starts_with($arg, '--from=')) {
                if ($source !== null) throw new \RuntimeException('duplicate --from');
                $source = $arg === '--from' ? ($args[++$index] ?? null) : substr($arg, strlen('--from='));
                if (!is_string($source) || $source === '' || str_starts_with($source, '-')) {
                    throw new \RuntimeException('--from requires an environment name');
                }
                continue;
            }
            if ($arg === '--branch' || str_starts_with($arg, '--branch=')) {
                if ($branch !== null) throw new \RuntimeException('duplicate --branch');
                $branch = $arg === '--branch' ? ($args[++$index] ?? null) : substr($arg, strlen('--branch='));
                if (!is_string($branch)) throw new \RuntimeException('--branch requires a Git ref');
                if ($branch === '' || str_starts_with($branch, '-') || str_contains($branch, "\0")) {
                    throw new \RuntimeException('--branch must be a safe Git ref');
                }
                continue;
            }
            if ($arg === '--ttl' || str_starts_with($arg, '--ttl=')) {
                if ($ttlSeen) throw new \RuntimeException('duplicate --ttl');
                $ttlSeen = true;
                $raw = $arg === '--ttl' ? ($args[++$index] ?? null) : substr($arg, strlen('--ttl='));
                if (!is_string($raw)) throw new \RuntimeException('--ttl requires whole seconds');
                if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
                    throw new \RuntimeException('--ttl must be whole seconds');
                }
                $ttl = (int) $raw;
                if ($ttl < 60 || $ttl > 2592000) {
                    throw new \RuntimeException('--ttl must be between 60 and 2592000 seconds');
                }
                continue;
            }
            throw new \RuntimeException("unsupported flag '$arg'");
        }
        if ($source === null || $branch === null) {
            throw new \RuntimeException('materialize requires exactly --from <production-env> and --branch <ref>');
        }
        return [
            'source' => $source,
            'branch' => $branch,
            'create' => $create,
            'ttl_seconds' => $ttl,
            'json' => $json,
        ];
    }

    public static function reap(array $args): bool {
        if ($args === []) return false;
        if ($args === ['--format=json']) return true;
        throw new \RuntimeException('reap accepts only optional --format=json');
    }

    /**
     * `duo env provider-check <env>` — the branch-environment provider
     * conformance harness.
     *
     * `--confirm-disposable` is mandatory for `--cycle` and cannot be inferred:
     * the cycle's second source action is `snapshot-prepare`, which FREEZES the
     * named source environment (EnvironmentLifecycle.php:1013-1021). The same
     * posture as `duo recover --prune-retained --confirm-prune` — a destructive
     * scope is named by the operator, never deduced from a name or a TTL.
     *
     * @return array{from:?string,cycle:bool,confirm:bool,create:bool,role:string,branch:?string,json:bool}
     */
    public static function providerCheck(array $args): array {
        $source = null;
        $branch = null;
        $role = null;
        $cycle = false;
        $confirm = false;
        $create = false;
        $json = false;
        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];
            if (!is_string($arg)) {
                throw new \RuntimeException('unsupported non-string env flag');
            }
            if ($arg === '--cycle') {
                if ($cycle) throw new \RuntimeException('duplicate --cycle');
                $cycle = true;
                continue;
            }
            if ($arg === '--confirm-disposable') {
                if ($confirm) throw new \RuntimeException('duplicate --confirm-disposable');
                $confirm = true;
                continue;
            }
            if ($arg === '--create') {
                if ($create) throw new \RuntimeException('duplicate --create');
                $create = true;
                continue;
            }
            if ($arg === '--format=json') {
                if ($json) throw new \RuntimeException('duplicate --format=json');
                $json = true;
                continue;
            }
            if ($arg === '--from' || str_starts_with($arg, '--from=')) {
                if ($source !== null) throw new \RuntimeException('duplicate --from');
                $source = $arg === '--from' ? ($args[++$index] ?? null) : substr($arg, strlen('--from='));
                if (!is_string($source) || $source === '' || str_starts_with($source, '-')) {
                    throw new \RuntimeException('--from requires an environment name');
                }
                continue;
            }
            if ($arg === '--role' || str_starts_with($arg, '--role=')) {
                if ($role !== null) throw new \RuntimeException('duplicate --role');
                $role = $arg === '--role' ? ($args[++$index] ?? null) : substr($arg, strlen('--role='));
                if (!is_string($role) || !in_array($role, ['source', 'target'], true)) {
                    throw new \RuntimeException('--role must be source or target');
                }
                continue;
            }
            if ($arg === '--branch' || str_starts_with($arg, '--branch=')) {
                if ($branch !== null) throw new \RuntimeException('duplicate --branch');
                $branch = $arg === '--branch' ? ($args[++$index] ?? null) : substr($arg, strlen('--branch='));
                if (!is_string($branch)) throw new \RuntimeException('--branch requires a Git ref');
                if ($branch === '' || str_starts_with($branch, '-') || str_contains($branch, "\0")) {
                    throw new \RuntimeException('--branch must be a safe Git ref');
                }
                continue;
            }
            throw new \RuntimeException("unsupported flag '$arg'");
        }
        if ($cycle && ($source === null || !$confirm)) {
            throw new \RuntimeException(
                'provider-check --cycle requires --from <disposable-source-env> and --confirm-disposable'
            );
        }
        if (!$cycle && ($source !== null || $confirm || $create || $branch !== null)) {
            throw new \RuntimeException(
                'provider-check --from/--confirm-disposable/--create/--branch are only used with --cycle'
            );
        }
        if ($cycle && $role !== null) {
            throw new \RuntimeException('provider-check --role is decided by --cycle, which drives both sides');
        }
        return [
            'from' => $source,
            'cycle' => $cycle,
            'confirm' => $confirm,
            'create' => $create,
            'role' => $role ?? 'target',
            'branch' => $branch,
            'json' => $json,
        ];
    }
}
