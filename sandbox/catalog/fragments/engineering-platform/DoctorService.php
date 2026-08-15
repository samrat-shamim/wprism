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
        foreach (['json', 'sodium'] as $extension) {
            $checks[] = [
                'name' => "php-extension:$extension",
                'state' => extension_loaded($extension) ? 'pass' : 'fail',
                'detail' => extension_loaded($extension) ? 'loaded' : 'required extension is not loaded',
            ];
        }
        foreach (['git', 'make', 'composer', 'jq'] as $tool) {
            $path = $this->findTool($tool);
            $checks[] = [
                'name' => "tool:$tool",
                'state' => $path === null ? 'fail' : 'pass',
                'detail' => $path ?? 'not found on PATH',
            ];
        }
        foreach (['docker', 'shellcheck', 'shfmt', 'actionlint'] as $tool) {
            $path = $this->findTool($tool);
            $checks[] = [
                'name' => "optional-tool:$tool",
                'state' => $path === null ? 'advisory' : 'pass',
                'detail' => $path ?? 'needed only by its declared quality/live profile',
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
        $referencedCommit = 'c30c1976342e7bf9e5aea0b7711986beb0108410';
        $checks[] = [
            'name' => 'offline-evidence-history',
            'state' => $this->gitObjectExists($root, $referencedCommit) ? 'pass' : 'advisory',
            'detail' => $this->gitObjectExists($root, $referencedCommit)
                ? 'referenced evidence commit is present'
                : "referenced commit $referencedCommit is absent; evidence-backed legacy suites will fail",
        ];
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

    private function gitObjectExists(string $root, string $object): bool
    {
        $process = proc_open(
            ['git', 'cat-file', '-e', $object . '^{commit}'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0;
    }
}
