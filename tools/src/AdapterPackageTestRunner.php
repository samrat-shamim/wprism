<?php

declare(strict_types=1);

namespace Duo\Tooling;

use RuntimeException;

require_once __DIR__ . '/AdapterPackageTestDiscovery.php';

/** Execute every discovered offline suite directly and retain every result. */
final class AdapterPackageTestRunner
{
    public const FORMAT = 'duo-adapter-package-test-run/v1';

    /**
     * @return array{
     *     format:string,
     *     adapter:string,
     *     class:'offline',
     *     status:'passed'|'failed',
     *     exit_code:0|1,
     *     tests:list<array{path:string,runtime:'php'|'bash',exit_code:int,stdout:string,stderr:string}>
     * }
     */
    public static function run(string $repoRoot, string $slug): array
    {
        $discovery = AdapterPackageTestDiscovery::discover($repoRoot, $slug, 'offline');
        $results = [];
        $failed = false;
        foreach ($discovery['tests'] as $test) {
            self::assertStillOrdinary($test['file'], $discovery['package']);
            $command = $test['runtime'] === 'php'
                ? [PHP_BINARY, $test['file']]
                : ['bash', $test['file']];
            $execution = self::execute($command, $discovery['package']);
            $results[] = [
                'path' => $test['path'],
                'runtime' => $test['runtime'],
                'exit_code' => $execution['exit_code'],
                'stdout' => $execution['stdout'],
                'stderr' => $execution['stderr'],
            ];
            if ($execution['exit_code'] !== 0) {
                $failed = true;
            }
        }

        return [
            'format' => self::FORMAT,
            'adapter' => $slug,
            'class' => 'offline',
            'status' => $failed ? 'failed' : 'passed',
            'exit_code' => $failed ? 1 : 0,
            'tests' => $results,
        ];
    }

    private static function assertStillOrdinary(string $file, string $package): void
    {
        $stat = @lstat($file);
        $canonical = realpath($file);
        if ($stat === false
            || ($stat['mode'] & 0170000) !== 0100000
            || is_link($file)
            || $canonical !== $file
            || !str_starts_with($file, rtrim($package, '/') . '/tests/offline/')) {
            throw new RuntimeException("Adapter offline test changed or escaped after discovery: $file");
        }
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private static function execute(array $command, string $cwd): array
    {
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'could not start test process'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $observedExit = null;

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                $selected = @stream_select($read, $write, $except, 0, 200000);
                if ($selected === false) {
                    $stderr .= 'could not read test process output';
                    break;
                }
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk !== false) {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $observedExit = $status['exitcode'];
                if (feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }
            }
        }

        $remainingOut = stream_get_contents($pipes[1]);
        $remainingErr = stream_get_contents($pipes[2]);
        $stdout .= $remainingOut === false ? '' : $remainingOut;
        $stderr .= $remainingErr === false ? '' : $remainingErr;
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExit = proc_close($process);
        $exitCode = $observedExit !== null && $observedExit >= 0 ? $observedExit : $closedExit;
        if ($exitCode < 0) {
            $exitCode = 127;
            $stderr .= ($stderr === '' ? '' : "\n") . 'test process exit status was unavailable';
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
