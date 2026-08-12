<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/Pending.php';

/** Host command handler for the pending review queue. */
final class PendingCommand {
    /** @return array{ok:bool, exit:int, items:list<array<string,mixed>>} */
    public static function fetch(EnvironmentDriver $driver, ?callable $renderRefusal = null): array {
        $result = $driver->captureWp(['duo', 'pending', '--repo=' . $driver->repoPath(), '--format=json']);
        if ($result['exit'] !== 0) {
            fwrite(STDERR, "duo: pending: failed to fetch the review queue for '{$driver->name()}' (exit {$result['exit']})\n");
            $refusal = json_decode(trim($result['stdout']), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'duo-command-refusal/v1' && $renderRefusal !== null) {
                $renderRefusal($refusal);
            } else {
                $message = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
                if ($message !== '') {
                    fwrite(STDERR, $message . "\n");
                }
            }
            return ['ok' => false, 'exit' => $result['exit'] !== 0 ? $result['exit'] : 1, 'items' => []];
        }
        $items = json_decode(trim($result['stdout']), true);
        if (!is_array($items)) {
            fwrite(STDERR, "duo: pending: could not parse review-queue JSON for '{$driver->name()}'\n");
            fwrite(STDERR, "raw output:\n{$result['stdout']}\n");
            return ['ok' => false, 'exit' => 1, 'items' => []];
        }
        return ['ok' => true, 'exit' => 0, 'items' => array_values($items)];
    }

    public static function run(EnvironmentDriver $driver, array $extra, ?callable $renderRefusal = null): int {
        if (in_array('--format=json', $extra, true)) {
            return $driver->streamWp(['duo', 'pending', '--repo=' . $driver->repoPath(), '--format=json']);
        }
        if ($extra !== []) {
            fwrite(STDERR, 'duo: pending: unknown flag(s): ' . implode(' ', $extra) . "\n");
            return 1;
        }
        $result = self::fetch($driver, $renderRefusal);
        if (!$result['ok']) {
            return $result['exit'];
        }
        if ($result['items'] === []) {
            echo "review queue is empty\n";
            return 0;
        }
        foreach (Pending::render($result['items'])['lines'] as $line) {
            echo $line . "\n";
        }
        return 0;
    }
}
