<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';

/** Version-independent filesystem recovery debt, outside managed code and WordPress. */
final class RecoveryFence {
    /** @return array{exit:int,stdout:string,stderr:string} */
    public static function checkpoint(EnvironmentDriver $transport, string $repo): array {
        $program = <<<'PHP'
$repo = realpath($arguments[0] ?? '');
if ($repo === false || !is_dir($repo)) {
    return ['exit' => 76, 'stderr' => 'checkpoint recovery repository is unreadable', 'stdout' => ''];
}
$state = $repo . '/.wprism';
$control = $state . '/control';
foreach ([$state, $control] as $directory) {
    $stat = @lstat($directory);
    if ($stat === false) {
        return ['exit' => 0, 'stderr' => '', 'stdout' => 'clear'];
    }
    if (($stat['mode'] & 0170000) !== 0040000 || is_link($directory) || realpath($directory) !== $directory) {
        return ['exit' => 76, 'stderr' => 'checkpoint recovery control boundary is unsafe', 'stdout' => ''];
    }
}
$intent = $control . '/checkpoint-recovery-intent.json';
if (@lstat($intent) === false) {
    return ['exit' => 0, 'stderr' => '', 'stdout' => 'clear'];
}
return ['exit' => 75, 'stderr' => 'incomplete checkpoint recovery is active', 'stdout' => ''];
PHP;
        $result = self::capture($transport, $program, $repo);
        if ($result['verified']) {
            return [
                'exit' => $result['exit'],
                'stdout' => $result['stdout'],
                'stderr' => $result['stderr'],
            ];
        }
        return [
            'exit' => 76,
            'stdout' => '',
            'stderr' => 'checkpoint recovery framed control response is unsafe',
        ];
    }

    /**
     * `state` comes only from the exact inner tuple. Outer transport
     * diagnostics, truncation, extra target bytes, and unknown exits are
     * observable to the framing driver but can never be reinterpreted here.
     *
     * @return array{exit:int,stdout:string,stderr:string,state:string}
     */
    public static function external(EnvironmentDriver $transport, string $repo): array {
        $program = <<<'PHP'
$repo = realpath($arguments[0] ?? '');
if ($repo === false || !is_dir($repo)) {
    return ['exit' => 76, 'stderr' => 'external recovery repository is unreadable', 'stdout' => ''];
}
$state = $repo . '/.wprism';
$control = $state . '/control';
foreach ([$state, $control] as $directory) {
    $stat = @lstat($directory);
    if ($stat === false) {
        return ['exit' => 0, 'stderr' => '', 'stdout' => 'clear'];
    }
    if (($stat['mode'] & 0170000) !== 0040000 || is_link($directory) || realpath($directory) !== $directory) {
        return ['exit' => 76, 'stderr' => 'external recovery control boundary is unsafe', 'stdout' => ''];
    }
}
foreach ([
    'checkpoint-recovery-intent.json' => 'incomplete checkpoint recovery is active',
    'provider-settlement-intent.json' => 'incomplete provider settlement is active',
] as $file => $message) {
    $path = $control . '/' . $file;
    $stat = @lstat($path);
    if ($stat === false) {
        continue;
    }
    if (($stat['mode'] & 0170000) !== 0100000 || is_link($path)) {
        return ['exit' => 76, 'stderr' => 'external recovery intent boundary is unsafe', 'stdout' => ''];
    }
    return ['exit' => 75, 'stderr' => $message, 'stdout' => ''];
}
return ['exit' => 0, 'stderr' => '', 'stdout' => 'clear'];
PHP;
        $result = self::capture($transport, $program, $repo);
        $exit = (int) ($result['exit'] ?? 1);
        $stdout = (string) ($result['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? '');
        $verified = ($result['verified'] ?? false) === true;
        $state = match (true) {
            $verified && $exit === 0 && $stdout === 'clear' && $stderr === '' => 'clear',
            $verified && $exit === 75 && $stdout === ''
                && $stderr === 'incomplete checkpoint recovery is active' => 'checkpoint_recovery',
            $verified && $exit === 75 && $stdout === ''
                && $stderr === 'incomplete provider settlement is active' => 'provider_settlement',
            default => 'unsafe',
        };
        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'state' => $state,
        ];
    }

    /**
     * @return array{
     *   verified:bool,
     *   exit:int,
     *   stdout:string,
     *   stderr:string,
     *   transport_exit:int,
     *   transport_stderr:string,
     *   failure:?string
     * }
     */
    private static function capture(
        EnvironmentDriver $transport,
        string $program,
        string $repo
    ): array {
        if (!$transport instanceof BoundedControlDriver) {
            return [
                'verified' => false,
                'exit' => 255,
                'stdout' => '',
                'stderr' => '',
                'transport_exit' => 255,
                'transport_stderr' => '',
                'failure' => 'bounded_control_unavailable',
            ];
        }
        return $transport->captureRawFramed(
            trim($program),
            [$repo],
            30000,
            1024,
            1024
        );
    }
}
