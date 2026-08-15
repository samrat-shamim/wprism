<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/** @phpstan-import-type HarnessContext from HarnessApproval */
final class Qualification
{
    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function run(
        string $mode,
        string $authorityDirectory,
        ?string $expectedReleaseFamilyDigest = null,
        ?string $releaseFamilyPath = null,
    ): array {
        if (!in_array($mode, ['candidate_adoption', 'release_validation'], true)) {
            throw new CatalogException('qualification mode is invalid');
        }
        $authority = $this->externalDirectory($authorityDirectory);
        $harness = (new HarnessApproval($this->root))->verify(
            $authority . '/approval.json',
            $authority . '/keyring.json',
            $authority . '/provisioning.json',
            $authority . '/probe.json',
        );
        $commandBytes = $this->externalBytes($authority . '/command.json', 'qualification command');
        $command = $this->canonicalObject($commandBytes, 'qualification command');
        $this->assertKeys($command, ['argv', 'artifact_path', 'artifact_sha256', 'format', 'mode', 'timeout_seconds'], 'qualification command');
        if (($command['format'] ?? null) !== 'duo-qualification-command/v1'
            || ($command['mode'] ?? null) !== $mode) {
            throw new CatalogException('qualification command format or mode is invalid');
        }
        $argv = $this->stringList($command, 'argv', 'qualification command');
        $executable = $argv[0] ?? null;
        if (!is_string($executable) || $executable === '' || $executable[0] !== '/'
            || !is_file($executable) || is_link($executable) || !is_executable($executable)) {
            throw new CatalogException('qualification command requires an absolute external executable');
        }
        $executableReal = realpath($executable);
        $rootReal = realpath($this->root);
        $executableMode = fileperms($executable);
        if (!is_string($executableReal) || !is_string($rootReal)
            || !is_int($executableMode) || ($executableMode & 0022) !== 0
            || $executableReal === $rootReal || str_starts_with($executableReal, $rootReal . '/')) {
            throw new CatalogException('qualification command executable must be controlled outside the repository');
        }
        $artifactPath = $this->requiredString($command, 'artifact_path', 'qualification command');
        if ($artifactPath === '' || $artifactPath[0] !== '/' || !is_file($artifactPath) || is_link($artifactPath)) {
            throw new CatalogException('qualification command artifact is absent or unsafe');
        }
        $artifactMode = fileperms($artifactPath);
        if (!is_int($artifactMode) || ($artifactMode & 0022) !== 0) {
            throw new CatalogException('qualification command artifact must not be group/world writable');
        }
        $artifactBytes = file_get_contents($artifactPath);
        if (!is_string($artifactBytes)
            || ($command['artifact_sha256'] ?? null) !== $this->digest($artifactBytes)) {
            throw new CatalogException('qualification command does not bind the exact retained artifact bytes');
        }
        DeterministicArchive::decode($artifactBytes);
        $timeout = $command['timeout_seconds'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 3600) {
            throw new CatalogException('qualification command timeout is invalid');
        }
        if ($mode === 'release_validation'
            && (!is_string($expectedReleaseFamilyDigest)
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedReleaseFamilyDigest) !== 1)) {
            throw new CatalogException('release validation requires the verified release-family digest');
        }
        $releaseFamilyManifestDigest = null;
        if ($mode === 'release_validation') {
            if (!is_string($releaseFamilyPath) || $releaseFamilyPath === '') {
                throw new CatalogException('release validation requires the retained release-family directory');
            }
            $releaseFamily = $this->externalDirectory($releaseFamilyPath);
            $releaseFamilyMode = fileperms($releaseFamily);
            if (!is_int($releaseFamilyMode) || ($releaseFamilyMode & 0022) !== 0) {
                throw new CatalogException('retained release-family directory must not be group/world writable');
            }
            $familyBytes = $this->externalBytes($releaseFamily . '/release-family.json', 'retained release-family manifest');
            $releaseFamilyManifestDigest = $this->digest($familyBytes);
            if (!hash_equals($expectedReleaseFamilyDigest, $releaseFamilyManifestDigest)) {
                throw new CatalogException('retained release-family manifest disagrees with the independently verified digest');
            }
            $expectedArtifact = realpath($releaseFamily . '/target-install.tar');
            $actualArtifact = realpath($artifactPath);
            if (!is_string($expectedArtifact) || !is_string($actualArtifact)
                || !hash_equals($expectedArtifact, $actualArtifact)) {
                throw new CatalogException('release validation command does not consume the retained family target-install archive');
            }
        } elseif ($releaseFamilyPath !== null || $expectedReleaseFamilyDigest !== null) {
            throw new CatalogException('candidate qualification must not accept release-family authority inputs');
        }

        $temporary = sys_get_temp_dir() . '/duo-qualification-' . bin2hex(random_bytes(8));
        if (!mkdir($temporary, 0700)) {
            throw new CatalogException('cannot create qualification control directory');
        }
        $observationPath = $temporary . '/observation.json';
        $timeoutTool = $this->findTool('timeout');
        if ($timeoutTool === null) {
            $this->removeTree($temporary);
            throw new CatalogException('util-linux timeout is required for qualification cleanup');
        }
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $temporary,
            'TMPDIR' => $temporary,
            'LC_ALL' => 'C',
            'LANG' => 'C',
            'TZ' => 'UTC',
            'DUO_QUALIFICATION_ARTIFACT' => $artifactPath,
            'DUO_QUALIFICATION_OBSERVATION' => $observationPath,
            'DUO_QUALIFICATION_MODE' => $mode,
            'DUO_QUALIFICATION_RELEASE_FAMILY_SHA256' => $expectedReleaseFamilyDigest ?? '',
        ];
        foreach ($harness['environment'] as $name => $value) {
            $environment[$name] = $value;
        }
        $started = hrtime(true);
        try {
            $executionArgv = [$timeoutTool, '--signal=TERM', '--kill-after=5s', $timeout . 's'];
            foreach ($argv as $argument) {
                $executionArgv[] = $argument;
            }
            $process = proc_open(
                $executionArgv,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $temporary,
                $environment,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new CatalogException('cannot start qualification command');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $output = (string) $stdout . (string) $stderr;
            foreach ($harness['environment'] as $secret) {
                $output = str_replace($secret, '<redacted>', $output);
            }
            if ($exit !== 0) {
                throw new CatalogException($exit === 124
                    ? 'qualification command timed out'
                    : 'qualification command failed: ' . trim($output));
            }
            $observationBytes = is_file($observationPath) && !is_link($observationPath)
                ? file_get_contents($observationPath)
                : false;
            if (!is_string($observationBytes)) {
                throw new CatalogException('qualification command did not publish an observation');
            }
            $observation = $this->canonicalObject($observationBytes, 'qualification observation');
            $this->assertObservation($observation, $mode, $this->digest($artifactBytes), $expectedReleaseFamilyDigest);
            $summary = $harness;
            unset($summary['environment']);
            return [
                'format' => $mode === 'candidate_adoption'
                    ? 'duo-candidate-adoption-qualification/v1'
                    : 'duo-release-validation/v1',
                'state' => 'pass',
                'candidate_sha' => $this->candidateSha(),
                'authority' => $mode === 'candidate_adoption' ? 'non_authorizing' : 'independent_qualification',
                'adoptability' => $mode === 'candidate_adoption' ? 'forbidden' : 'retained_exact_release_only',
                'artifact_sha256' => $this->digest($artifactBytes),
                'release_family_sha256' => $releaseFamilyManifestDigest,
                'command_sha256' => $this->digest($commandBytes),
                'executable_sha256' => $this->fileDigest($executableReal),
                'timeout_tool_sha256' => $this->fileDigest($timeoutTool),
                'harness' => $summary,
                'observation_sha256' => $this->digest($observationBytes),
                'operations' => $observation['operations'],
                'source_checkout_fallback' => false,
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ];
        } finally {
            $this->removeTree($temporary);
        }
    }

    /** @param array<string,mixed> $observation */
    private function assertObservation(array $observation, string $mode, string $artifactDigest, ?string $familyDigest): void
    {
        $this->assertKeys($observation, [
            'artifact_sha256', 'format', 'mode', 'non_adoptable_enforced', 'operations',
            'release_family_sha256', 'rollback_restored', 'source_checkout_fallback', 'target_mutated',
        ], 'qualification observation');
        $operations = $observation['operations'] ?? null;
        if (($observation['format'] ?? null) !== 'duo-qualification-observation/v1'
            || ($observation['mode'] ?? null) !== $mode
            || ($observation['artifact_sha256'] ?? null) !== $artifactDigest
            || ($observation['release_family_sha256'] ?? null) !== $familyDigest
            || ($observation['source_checkout_fallback'] ?? null) !== false
            || ($observation['target_mutated'] ?? null) !== true
            || ($observation['rollback_restored'] ?? null) !== true
            || ($observation['non_adoptable_enforced'] ?? null) !== ($mode === 'candidate_adoption')
            || !is_array($operations) || array_is_list($operations)) {
            throw new CatalogException('qualification observation is malformed or does not bind the requested run');
        }
        $operations = $this->stringKeyed($operations, 'qualification observation operations');
        $this->assertKeys($operations, ['adoption', 'recovery', 'rollback', 'swap', 'transfer'], 'qualification observation operations');
        foreach ($operations as $state) {
            if ($state !== 'pass') {
                throw new CatalogException('qualification observation does not prove every required operation');
            }
        }
    }

    private function externalDirectory(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new CatalogException('qualification authority must be an absolute external directory');
        }
        $real = realpath($path);
        $root = realpath($this->root);
        if (!is_string($real) || !is_dir($real) || !is_string($root)
            || $real === $root || str_starts_with($real, $root . '/')) {
            throw new CatalogException('qualification authority must be controlled outside the repository');
        }
        return $real;
    }

    private function externalBytes(string $path, string $label): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new CatalogException("$label is absent or unsafe");
        }
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0022) !== 0) {
            throw new CatalogException("$label must not be group/world writable");
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException("$label is unreadable");
        }
        return $bytes;
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $bytes, string $label): array
    {
        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException("$label is invalid JSON: {$exception->getMessage()}");
        }
        if (!is_array($decoded) || array_is_list($decoded) || $this->canonical($decoded) . "\n" !== $bytes) {
            throw new CatalogException("$label is not a canonical JSON object");
        }
        return $this->stringKeyed($decoded, $label);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $expected
     */
    private function assertKeys(array $value, array $expected, string $label): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new CatalogException("$label has missing or unrecognized fields");
        }
    }

    /** @param array<string,mixed> $value */
    private function requiredString(array $value, string $key, string $label): string
    {
        $found = $value[$key] ?? null;
        if (!is_string($found)) {
            throw new CatalogException("$label $key must be a string");
        }
        return $found;
    }

    /**
     * @param array<string,mixed> $value
     * @return list<string>
     */
    private function stringList(array $value, string $key, string $label): array
    {
        $found = $value[$key] ?? null;
        if (!is_array($found) || !array_is_list($found) || $found === []) {
            throw new CatalogException("$label $key must be a nonempty list");
        }
        $result = [];
        foreach ($found as $item) {
            if (!is_string($item) || str_contains($item, "\0")) {
                throw new CatalogException("$label $key contains an invalid argument");
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param array<mixed,mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyed(array $value, string $label): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new CatalogException("$label must be an object");
            }
            $result[$key] = $item;
        }
        return $result;
    }

    private function findTool(string $tool): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $path = $directory . '/' . $tool;
            if ($directory !== '' && is_file($path) && is_executable($path)) {
                $real = realpath($path);
                return is_string($real) ? $real : $path;
            }
        }
        return null;
    }

    private function candidateSha(): string
    {
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot resolve qualification candidate');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)
            || preg_match('/^[a-f0-9]{40}\s*$/D', $stdout) !== 1) {
            throw new CatalogException('qualification candidate is not a full SHA');
        }
        return trim($stdout);
    }

    private function fileDigest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new CatalogException('cannot digest qualification execution input');
        }
        return 'sha256:' . $digest;
    }

    private function digest(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(fn(mixed $item): string => $this->canonical($item), $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $this->canonical($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new CatalogException('cannot remove qualification control path');
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        if (!rmdir($path)) {
            throw new CatalogException('cannot remove qualification control directory');
        }
    }
}
