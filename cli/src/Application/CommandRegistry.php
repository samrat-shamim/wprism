<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The host command vocabulary is explicit even while individual handlers are
 * being migrated out of the legacy compatibility module.  Keeping the
 * vocabulary here gives the composition root one stable seam for command
 * registration and lets the extracted handlers be introduced one family at a
 * time without changing the public executable.
 */
final class CommandRegistry
{
    /** @var array<string,string> */
    private array $commands;

    /** @param array<string,string> $commands */
    public function __construct(array $commands)
    {
        if ($commands === []) {
            throw new \InvalidArgumentException('the host command registry cannot be empty');
        }
        foreach ($commands as $verb => $handler) {
            if (preg_match('/^[a-z][a-z0-9-]*$/D', $verb) !== 1
                || preg_match('/^[A-Za-z][A-Za-z0-9_\\-]*$/D', $handler) !== 1) {
                throw new \InvalidArgumentException('host command registry entries must be closed identifiers');
            }
        }
        ksort($commands, SORT_STRING);
        $this->commands = $commands;
    }

    public static function default(): self
    {
        $verbs = [
            'adapter', 'adapter-draft', 'adapter-observe', 'adopt', 'apply', 'capabilities',
            'capture', 'classify', 'coverage', 'deploy', 'doctor', 'driver-capabilities',
            'env', 'env-set', 'envs', 'explain', 'init', 'lint', 'manifest-validate',
            'pending', 'plan', 'promote', 'rebase', 'refresh', 'scope', 'status',
        ];
        $commands = [];
        foreach ($verbs as $verb) {
            // The legacy handler is deliberately a temporary adapter.  The
            // registry remains independent of its implementation so each
            // handler can be replaced by a typed use case without changing
            // command discovery or the executable contract.
            $commands[$verb] = 'legacy_main';
        }
        return new self($commands);
    }

    public function has(string $verb): bool
    {
        return isset($this->commands[$verb]);
    }

    public function handler(string $verb): ?string
    {
        return $this->commands[$verb] ?? null;
    }

    /** @return list<string> */
    public function verbs(): array
    {
        return array_keys($this->commands);
    }

    /**
     * Dispatch through a registry entry while retaining the old parser as a
     * compatibility adapter.  Unknown verbs intentionally fall through so
     * the established closed diagnostic and usage bytes remain unchanged.
     *
     * @param list<string> $argv
     * @param callable(list<string>):int $legacy
     */
    public function dispatch(array $argv, callable $legacy): int
    {
        $verb = self::verb($argv);
        if ($verb === null || !$this->has($verb)) {
            return $legacy($argv);
        }
        $handler = $this->handler($verb);
        if ($handler !== null && is_callable($handler)) {
            return (int) call_user_func($handler, $argv);
        }
        return $legacy($argv);
    }

    /** @param list<string> $argv */
    private static function verb(array $argv): ?string
    {
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '-h' || $argument === '--help' || str_starts_with($argument, '--envs-file=')) {
                continue;
            }
            return str_starts_with($argument, '--') ? null : $argument;
        }
        return null;
    }
}
