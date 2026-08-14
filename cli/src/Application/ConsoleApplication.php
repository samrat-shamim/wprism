<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandRegistry.php';
require_once __DIR__ . '/LegacyHost.php';

/**
 * Composition-only host entrypoint.
 *
 * The executable owns no command grammar, transport, filesystem, or target
 * workflow.  It loads the application composition, hands argv to the
 * explicit registry, and returns the selected handler's exit code.  The
 * legacy module is a compatibility adapter and is intentionally the only
 * implementation supplied to the registry until each command family has a
 * typed use-case replacement.
 */
final class ConsoleApplication
{
    private function __construct(private readonly CommandRegistry $registry) {}

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        $application = new self(CommandRegistry::default());
        try {
            return $application->registry->dispatch(
                $argv,
                static fn(array $legacyArgv): int => legacy_main($legacyArgv)
            );
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'duo: ' . $exception->getMessage() . "\n");
            return 1;
        }
    }

    public function registry(): CommandRegistry
    {
        return $this->registry;
    }
}
