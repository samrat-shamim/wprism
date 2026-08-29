<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

/**
 * Target-free host handler for the driver-capabilities command.
 *
 * Capability negotiation is deliberately kept separate from command
 * registration and transport resolution. The handler owns only the stable
 * option grammar, report rendering, and exit contract.
 */
final class DriverCapabilitiesCommand {
    public static function run(EnvironmentDriver $driver, array $extra): int {
        $operation = 'attach';
        $operationSeen = false;
        $json = false;
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                fwrite(STDERR, "wprism: driver-capabilities: unsupported non-string flag\n");
                return 1;
            }
            if ($arg === '--format=json') {
                if ($json) {
                    fwrite(STDERR, "wprism: driver-capabilities: duplicate --format=json\n");
                    return 1;
                }
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--operation=')) {
                if ($operationSeen) {
                    fwrite(STDERR, "wprism: driver-capabilities: duplicate --operation\n");
                    return 1;
                }
                $operationSeen = true;
                $operation = substr($arg, strlen('--operation='));
                if ($operation === '') {
                    fwrite(STDERR, "wprism: driver-capabilities: --operation cannot be empty\n");
                    return 1;
                }
                continue;
            }
            fwrite(STDERR, "wprism: driver-capabilities: unsupported flag '$arg'\n");
            return 1;
        }

        try {
            $report = $driver->capabilityReport($operation);
        } catch (\Throwable $e) {
            fwrite(STDERR, "wprism: driver-capabilities: {$e->getMessage()}\n");
            return 1;
        }

        $body = $report->toArray();
        if ($json) {
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        } else {
            $state = $report->ready() ? 'READY' : 'BLOCKED';
            echo "driver {$body['driver']['id']} protocol {$body['driver']['protocol']} "
                . "environment {$body['driver']['environment']} operation {$body['operation']}: $state\n";
            foreach ($body['requirements'] as $check) {
                $mark = $check['state'] === 'supported' ? 'PASS' : 'BLOCKED';
                echo "[$mark] {$check['capability']} — {$check['reason']}\n";
                if ($check['state'] !== 'supported') {
                    echo "  remediation: {$check['remediation']}\n";
                }
            }
            echo "report {$body['digest']}\n";
        }
        return $report->ready() ? 0 : 1;
    }
}
