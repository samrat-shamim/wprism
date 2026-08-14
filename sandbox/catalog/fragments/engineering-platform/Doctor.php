<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

final class Doctor
{
    /** @return list<array{name:string,state:string,detail:string}> */
    public function inspect(string $root): array
    {
        $checks = [];
        $checks[] = $this->versionCheck('php', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP >=8.2 is required for development tooling');
        foreach (['dom', 'filter', 'hash', 'iconv', 'json', 'libxml', 'phar', 'sodium', 'tokenizer', 'xmlwriter'] as $extension) {
            $checks[] = [
                'name' => "php-extension:$extension",
                'state' => extension_loaded($extension) ? 'pass' : 'fail',
                'detail' => extension_loaded($extension) ? 'loaded' : 'required extension is not loaded',
            ];
        }
        foreach (['git', 'make', 'composer', 'jq', 'setsid'] as $tool) {
            $path = $this->findTool($tool);
            $checks[] = [
                'name' => "tool:$tool",
                'state' => $path === null ? 'fail' : 'pass',
                'detail' => $path === null ? 'not found on PATH' : 'available on PATH',
            ];
        }
        foreach (['proc_open', 'posix_kill', 'posix_getpgid', 'pcntl_signal', 'pcntl_async_signals'] as $function) {
            $checks[] = [
                'name' => "php-function:$function",
                'state' => function_exists($function) ? 'pass' : 'fail',
                'detail' => function_exists($function) ? 'available' : 'required function is unavailable',
            ];
        }
        foreach (['docker', 'shellcheck', 'shfmt', 'actionlint'] as $tool) {
            $path = $this->findTool($tool);
            $checks[] = [
                'name' => "optional-tool:$tool",
                'state' => $path === null ? 'advisory' : 'pass',
                'detail' => $path === null ? 'needed only by its declared quality/live profile' : 'available on PATH',
            ];
        }
        foreach (['composer.json', 'composer.lock', 'phpunit.xml', 'phpstan.neon', '.php-cs-fixer.dist.php'] as $path) {
            $checks[] = [
                'name' => "configuration:$path",
                'state' => is_file($root . '/' . $path) ? 'pass' : 'fail',
                'detail' => is_file($root . '/' . $path) ? 'present' : 'missing',
            ];
        }
        $vendorState = is_file($root . '/vendor/bin/phpunit')
            && is_file($root . '/vendor/bin/phpstan')
            && is_file($root . '/vendor/bin/php-cs-fixer');
        $checks[] = [
            'name' => 'development-dependencies',
            'state' => $vendorState ? 'pass' : 'advisory',
            'detail' => $vendorState ? 'lock-pinned tools are installed' : 'run make bootstrap-dev before quality/unit targets',
        ];
        $composerPath = $this->findTool('composer');
        if ($composerPath !== null && function_exists('proc_open')) {
            [$composerExit, $composerOutput] = $this->command([$composerPath, '--version', '--no-ansi'], $root);
            $composerVersion = preg_match('/Composer version ([0-9]+(?:\.[0-9]+){1,2})/', $composerOutput, $match) === 1
                ? $match[1]
                : null;
            $checks[] = $this->versionCheck(
                'composer',
                $composerVersion ?? 'unknown',
                $composerExit === 0 && $composerVersion !== null && version_compare($composerVersion, '2.3.0', '>='),
                'Composer >=2.3 is required by the lock plugin API',
            );
            [$platformExit] = $this->command([$composerPath, 'check-platform-reqs', '--lock', '--no-ansi'], $root);
            $checks[] = [
                'name' => 'composer-lock-platform',
                'state' => $platformExit === 0 ? 'pass' : 'fail',
                'detail' => $platformExit === 0
                    ? 'current PHP runtime satisfies lock-file platform requirements'
                    : 'current PHP runtime does not satisfy lock-file platform requirements',
            ];
        } else {
            $checks[] = $this->versionCheck('composer', 'unavailable', false, 'Composer >=2.3 is required by the lock plugin API');
            $checks[] = [
                'name' => 'composer-lock-platform',
                'state' => 'fail',
                'detail' => 'cannot verify lock-file platform requirements without Composer and proc_open',
            ];
        }
        return $checks;
    }

    /** @param list<array{name:string,state:string,detail:string}> $checks */
    public function render(array $checks): int
    {
        $exit = 0;
        foreach ($checks as $check) {
            printf("%-9s %-34s %s\n", $check['state'], $check['name'], $check['detail']);
            if ($check['state'] === 'fail') {
                $exit = 1;
            }
        }
        return $exit;
    }

    /** @return array{name:string,state:string,detail:string} */
    private function versionCheck(string $name, string $version, bool $valid, string $requirement): array
    {
        return [
            'name' => "version:$name",
            'state' => $valid ? 'pass' : 'fail',
            'detail' => "$version; $requirement",
        ];
    }

    private function findTool(string $tool): ?string
    {
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            $candidate = $directory . '/' . $tool;
            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param list<string> $argv
     * @return array{int,string}
     */
    private function command(array $argv, string $root): array
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return [127, ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), trim((string) $stdout . "\n" . (string) $stderr)];
    }
}
