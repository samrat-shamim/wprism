<?php
declare(strict_types=1);

/**
 * Thread 3 command-golden characterization.
 *
 * This invokes the real Cli refusal formatter with only reviewed exception
 * inputs. It pins output fields and insertion order without booting WordPress,
 * touching a repository, or granting a command any mutation authority.
 */

namespace {
    final class CommandGoldenHalt extends \RuntimeException {
        public function __construct(public int $status) {
            parent::__construct('halt:' . $status);
        }
    }

    final class WP_CLI {
        /** @var list<string> */
        public static array $lines = [];
        /** @var list<string> */
        public static array $errors = [];

        public static function add_command($name, $class): void {}

        public static function line($line): void {
            self::$lines[] = (string) $line;
        }

        public static function halt($status): void {
            throw new CommandGoldenHalt((int) $status);
        }

        public static function error($message): void {
            self::$errors[] = (string) $message;
            throw new \RuntimeException((string) $message);
        }
    }
}

namespace {
    require_once __DIR__ . '/../../../../agent/src/Cli.php';

    $root = dirname(__DIR__, 4);
    $fixturePath = __DIR__ . '/command-goldens.json';
    $fixture = json_decode((string) file_get_contents($fixturePath), true);
    $failures = [];
    $check = static function (bool $condition, string $message) use (&$failures): void {
        if ($condition) {
            fwrite(STDOUT, "ok: $message\n");
            return;
        }
        $failures[] = $message;
        fwrite(STDERR, "FAIL: $message\n");
    };

    $check(is_array($fixture) && ($fixture['format'] ?? null) === 'duo-command-golden-set/v1',
        'command golden fixture has the versioned format');
    $check(is_array($fixture) && ($fixture['owner'] ?? null) === 'thread-3',
        'command golden fixture is owned by thread-3');
    $cases = is_array($fixture) ? ($fixture['cases'] ?? []) : [];
    $check(is_array($cases) && count($cases) === 6, 'command golden fixture covers six refusal boundaries');

    $invoke = static function (string $exception, string $command): array {
        \WP_CLI::$lines = [];
        \WP_CLI::$errors = [];
        $throwable = match ($exception) {
            'portable_capture_unqualified' => \Duo\CommandRefusalException::portableCaptureUnqualified(),
            'qualification_harness_required' => \Duo\CommandRefusalException::qualificationHarnessRequired(),
            'missing_scope_roots' => \Duo\CommandRefusalException::invalidArgument('scope', '--roots'),
            'malformed_repository' => new \RuntimeException(
                'malformed repository payload at /private/customer/repository'
            ),
            'unsupported_topology' => new \Duo\CommandRefusalException(
                'unsupported_deletion',
                'the selected topology has no reviewed deletion capability',
                'select a supported topology or preserve it as authored state',
                [[
                    'code' => 'unsupported_deletion',
                    'surface' => 'table:nf3_forms',
                    'message' => 'no reviewed deletion capability is declared for this topology',
                    'remediation' => 'review the adapter capability before retrying',
                ]]
            ),
            'capture_recovery_ambiguous' => \Duo\CommandRefusalException::ambiguousCaptureRecovery(
                'duo: operator-only interrupted publication detail at /private/repository/state.capture-intent'
            ),
            default => throw new \LogicException('unknown golden exception: ' . $exception),
        };

        $formatter = new \ReflectionMethod(\Duo\Cli::class, 'halt_json_failure');
        try {
            $formatter->invoke(null, $throwable, ['format' => 'json'], $command);
            throw new \LogicException('formatter returned without halting');
        } catch (CommandGoldenHalt $halt) {
            if ($halt->status !== 1) {
                throw new \RuntimeException('formatter halted with status ' . $halt->status);
            }
        }
        if (\WP_CLI::$errors !== [] || count(\WP_CLI::$lines) !== 1) {
            throw new \RuntimeException('formatter did not produce one machine-only line');
        }
        $bytes = \WP_CLI::$lines[0];
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('formatter output is not JSON');
        }
        return ['bytes' => $bytes, 'decoded' => $decoded];
    };

    foreach ($cases as $case) {
        if (!is_array($case)) {
            $check(false, 'golden case is an object');
            continue;
        }
        $id = (string) ($case['id'] ?? 'unnamed');
        try {
            $actual = $invoke((string) ($case['exception'] ?? ''), (string) ($case['command'] ?? ''));
            $expected = $case['expected'] ?? null;
            $check(is_array($expected) && $actual['decoded'] === $expected, "$id preserves exact refusal fields");
            $check(array_keys($actual['decoded']) === ($case['expected_key_order'] ?? []),
                "$id preserves refusal field order");
            $canonical = json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $check(is_string($canonical) && $actual['bytes'] === $canonical, "$id preserves canonical JSON bytes");
            foreach ((array) ($case['forbidden'] ?? []) as $needle) {
                $check(!str_contains($actual['bytes'], (string) $needle), "$id redacts " . (string) $needle);
            }
        } catch (\Throwable $e) {
            $check(false, "$id executes through the real formatter: " . $e->getMessage());
        }
    }

    if ($failures !== []) {
        fwrite(STDERR, sprintf("REGRESS_COMMAND_GOLDENS FAILED (%d failures)\n", count($failures)));
        exit(1);
    }
    fwrite(STDOUT, "REGRESS_COMMAND_GOLDENS PASSED\n");
}
