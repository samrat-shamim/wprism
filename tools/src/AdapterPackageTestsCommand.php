<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use Throwable;

require_once __DIR__ . '/AdapterPackageTestDiscovery.php';
require_once __DIR__ . '/AdapterPackageTestRunner.php';

/** Stable CLI contract for listing or running one adapter package's tests. */
final class AdapterPackageTestsCommand
{
    public const PLAN_FORMAT = 'wprism-adapter-package-test-plan/v1';
    public const USAGE = 'usage: php tools/adapter-package-tests.php --adapter=SLUG [--repo=PATH] '
        . '[--class=offline|live|certify|conformance|spike] [--list|--json]';

    /**
     * @param list<string> $arguments
     * @return array{status:int,stdout:string,stderr:string}
     */
    public static function execute(array $arguments, string $defaultRepo): array
    {
        try {
            $options = self::parse($arguments, $defaultRepo);
        } catch (\InvalidArgumentException $invalid) {
            return [
                'status' => 2,
                'stdout' => '',
                'stderr' => 'adapter-package-tests: ' . $invalid->getMessage() . "\n" . self::USAGE . "\n",
            ];
        }

        if ($options['help']) {
            return ['status' => 0, 'stdout' => self::USAGE . "\n", 'stderr' => ''];
        }

        try {
            if ($options['mode'] !== 'run') {
                $discovery = AdapterPackageTestDiscovery::discover(
                    $options['repo'],
                    $options['adapter'],
                    $options['class']
                );
                return [
                    'status' => 0,
                    'stdout' => $options['mode'] === 'json'
                        ? self::jsonPlan($discovery)
                        : self::listPlan($discovery),
                    'stderr' => '',
                ];
            }
            if ($options['class'] !== 'offline') {
                throw new \InvalidArgumentException(
                    "execution is available only for --class=offline; use --list or --json for {$options['class']}"
                );
            }
            $run = AdapterPackageTestRunner::run($options['repo'], $options['adapter']);
            return self::renderRun($run);
        } catch (\InvalidArgumentException $invalid) {
            return [
                'status' => 2,
                'stdout' => '',
                'stderr' => 'adapter-package-tests: ' . $invalid->getMessage() . "\n" . self::USAGE . "\n",
            ];
        } catch (Throwable $failure) {
            return [
                'status' => 1,
                'stdout' => '',
                'stderr' => 'adapter-package-tests: ' . $failure->getMessage() . "\n",
            ];
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *     adapter:string,
     *     repo:string,
     *     class:string,
     *     mode:'run'|'list'|'json',
     *     help:bool
     * }
     */
    private static function parse(array $arguments, string $defaultRepo): array
    {
        $adapter = null;
        $repo = $defaultRepo;
        $class = 'offline';
        $mode = 'run';
        $modeSeen = false;
        $repoSeen = false;
        $classSeen = false;
        $help = false;

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $help = true;
                continue;
            }
            if ($argument === '--list' || $argument === '--json') {
                if ($modeSeen) {
                    throw new \InvalidArgumentException('--list and --json are mutually exclusive and may appear once');
                }
                $mode = $argument === '--list' ? 'list' : 'json';
                $modeSeen = true;
                continue;
            }
            if (str_starts_with($argument, '--adapter=')) {
                if ($adapter !== null) {
                    throw new \InvalidArgumentException('--adapter may be specified only once');
                }
                $adapter = substr($argument, strlen('--adapter='));
                continue;
            }
            if (str_starts_with($argument, '--repo=')) {
                if ($repoSeen) {
                    throw new \InvalidArgumentException('--repo may be specified only once');
                }
                $repo = substr($argument, strlen('--repo='));
                $repoSeen = true;
                continue;
            }
            if (str_starts_with($argument, '--class=')) {
                if ($classSeen) {
                    throw new \InvalidArgumentException('--class may be specified only once');
                }
                $class = substr($argument, strlen('--class='));
                $classSeen = true;
                continue;
            }
            throw new \InvalidArgumentException('unknown argument ' . var_export($argument, true));
        }

        if ($help) {
            if (count($arguments) !== 1) {
                throw new \InvalidArgumentException('--help cannot be combined with other arguments');
            }
            return [
                'adapter' => '',
                'repo' => $repo,
                'class' => $class,
                'mode' => $mode,
                'help' => true,
            ];
        }
        if ($adapter === null || $adapter === '') {
            throw new \InvalidArgumentException('--adapter=SLUG is required');
        }

        return [
            'adapter' => $adapter,
            'repo' => $repo,
            'class' => $class,
            'mode' => $mode,
            'help' => false,
        ];
    }

    /**
     * @param array{adapter:string,class:string,tests:list<array{path:string,runtime:string}>} $discovery
     */
    private static function listPlan(array $discovery): string
    {
        $lines = [
            'adapter-package-tests: ' . $discovery['adapter'] . ' ' . $discovery['class']
                . ' (' . count($discovery['tests']) . ')',
        ];
        foreach ($discovery['tests'] as $test) {
            $lines[] = $test['runtime'] . ' ' . $test['path'];
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{adapter:string,class:string,tests:list<array{path:string,runtime:string}>} $discovery
     */
    private static function jsonPlan(array $discovery): string
    {
        $tests = [];
        foreach ($discovery['tests'] as $test) {
            $tests[] = [
                'path' => $test['path'],
                'runtime' => $test['runtime'],
                'command' => [$test['runtime'], $test['path']],
            ];
        }
        $encoded = json_encode([
            'format' => self::PLAN_FORMAT,
            'adapter' => $discovery['adapter'],
            'class' => $discovery['class'],
            'tests' => $tests,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Could not encode adapter package test plan');
        }
        return $encoded . "\n";
    }

    /**
     * @param array{
     *     adapter:string,
     *     exit_code:int,
     *     tests:list<array{path:string,exit_code:int,stdout:string,stderr:string}>
     * } $run
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function renderRun(array $run): array
    {
        $stdout = 'adapter-package-tests: running ' . count($run['tests']) . ' offline tests for '
            . $run['adapter'] . "\n";
        $stderr = '';
        $failed = 0;
        foreach ($run['tests'] as $test) {
            $stdout .= '== ' . $test['path'] . " ==\n" . $test['stdout'];
            if ($test['stdout'] !== '' && !str_ends_with($test['stdout'], "\n")) {
                $stdout .= "\n";
            }
            if ($test['stderr'] !== '') {
                $stderr .= '== ' . $test['path'] . " (stderr) ==\n" . $test['stderr'];
                if (!str_ends_with($test['stderr'], "\n")) {
                    $stderr .= "\n";
                }
            }
            if ($test['exit_code'] !== 0) {
                $failed++;
                $stderr .= 'adapter-package-tests: ' . $test['path'] . ' exited ' . $test['exit_code'] . "\n";
            }
        }
        $stdout .= 'adapter-package-tests: ' . (count($run['tests']) - $failed) . ' passed, '
            . $failed . " failed\n";
        return ['status' => $run['exit_code'], 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
