<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Deadlock-free host subprocess boundary shared by composed CLI commands. */
final class HostProcess {
    /**
     * @param list<string> $argv
     * @param array<string,string> $extraEnv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function run(
        array $argv,
        ?string $cwd = null,
        array $extraEnv = [],
        bool $passthrough = false
    ): array {
        $descriptors = $passthrough
            ? [0 => STDIN, 1 => STDOUT, 2 => STDERR]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $hostEnvironment = getenv();
        $environment = $extraEnv === []
            ? null
            : array_replace(is_array($hostEnvironment) ? $hostEnvironment : [], $extraEnv);
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        if ($passthrough) {
            return ['exit' => proc_close($process), 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        // A child can fill stderr while holding stdout open (or the reverse).
        // The 200 KiB adversarial child in regress_ideal_onboarding.php pins
        // the concurrent drain; sequential stream_get_contents() deadlocks.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, null) === false) {
                break;
            }
            foreach ($read as $stream) {
                $fd = $stream === $pipes[1] ? 1 : 2;
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$fd]);
                    continue;
                }
                $buffers[$fd] .= $chunk;
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        return ['exit' => proc_close($process), 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
    }
}
