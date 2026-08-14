<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * Network-aware Composer advisory wrapper with stable three-way outcomes.
 *
 * @phpstan-type Finding array{package:string,advisory_id:string}
 * @phpstan-type AuditResult array{state:string,message:string,findings:list<Finding>,abandoned_packages:list<string>}
 * @phpstan-type Update array{name:string,current:string,latest:string}
 * @phpstan-type UpdateResult array{state:string,message:string,updates:list<Update>}
 * @phpstan-type ImageCheck array{name:string,reference:string,pinned_digest:string,observed_digest:?string,state:string}
 * @phpstan-type ImageResult array{state:string,message:string,checks:list<ImageCheck>,docker:?string}
 */
final class Audit
{
    public function __construct(private readonly string $root) {}

    public function run(string $resultPath): int
    {
        $composer = $this->findExecutable('composer');
        if ($composer === null) {
            throw new CatalogException('Composer is unavailable for audit');
        }
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $startedNs = hrtime(true);
        $auditProcess = $this->process([$composer, 'audit', '--locked', '--no-interaction', '--format=json']);
        $result = self::classify($auditProcess['exit'], $auditProcess['stdout'], $auditProcess['stderr']);
        $updateProcess = $this->process([$composer, 'outdated', '--direct', '--locked', '--no-interaction', '--format=json']);
        $updates = self::classifyUpdates($updateProcess['exit'], $updateProcess['stdout'], $updateProcess['stderr']);
        $images = $this->checkImages();
        $state = self::combinedState([$result['state'], $updates['state'], $images['state']]);
        $messages = [$result['message'], $updates['message'], $images['message']];
        $receipt = [
            'format' => 'duo-network-audit-receipt/v1',
            'state' => $state,
            'message' => implode('; ', $messages),
            'candidate_sha' => $this->candidateSha(),
            'provider' => 'composer-audit+outdated/packagist;docker-buildx/registry',
            'providers' => [
                'composer-audit/packagist',
                'composer-outdated/packagist',
                'docker-buildx/registry',
            ],
            'retrieved_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'started_at' => $started,
            'duration_ms' => (int) round((hrtime(true) - $startedNs) / 1_000_000),
            'composer_sha256' => $this->digest($composer),
            'dependency_lock_sha256' => $this->digest($this->root . '/composer.lock'),
            'performance_profile_sha256' => $this->digest($this->root . '/sandbox/catalog/fragments/engineering-platform/performance-profile.json'),
            'findings' => $result['findings'],
            'abandoned_packages' => $result['abandoned_packages'],
            'dependency_updates' => $updates['updates'],
            'docker_sha256' => $images['docker'] === null ? null : $this->digest($images['docker']),
            'image_checks' => $images['checks'],
        ];
        $this->publish($resultPath, json_encode(
            $receipt,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
        return match ($state) {
            'clean' => 0,
            'policy_failure' => 1,
            default => 2,
        };
    }

    /** @return UpdateResult */
    public static function classifyUpdates(int $exit, string $stdout, string $stderr): array
    {
        try {
            $decoded = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['state' => 'unavailable', 'message' => self::unavailableMessage($exit, $stderr), 'updates' => []];
        }
        $locked = is_array($decoded) ? ($decoded['locked'] ?? null) : null;
        if (!is_array($locked) || !array_is_list($locked)) {
            return ['state' => 'unavailable', 'message' => 'dependency update provider response is malformed', 'updates' => []];
        }
        $updates = [];
        foreach ($locked as $row) {
            if (!is_array($row)
                || !is_string($row['name'] ?? null)
                || !is_string($row['version'] ?? null)
                || !is_string($row['latest'] ?? null)
                || $row['name'] === ''
                || $row['version'] === ''
                || $row['latest'] === '') {
                return ['state' => 'unavailable', 'message' => 'dependency update provider row is malformed', 'updates' => []];
            }
            $updates[] = ['name' => $row['name'], 'current' => $row['version'], 'latest' => $row['latest']];
        }
        usort($updates, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);
        if ($updates !== []) {
            return ['state' => 'policy_failure', 'message' => 'direct locked Composer dependencies have controlled updates available', 'updates' => $updates];
        }
        if ($exit !== 0) {
            return ['state' => 'unavailable', 'message' => self::unavailableMessage($exit, $stderr), 'updates' => []];
        }
        return ['state' => 'clean', 'message' => 'direct locked Composer dependencies are current', 'updates' => []];
    }

    /** @param list<string> $states */
    public static function combinedState(array $states): string
    {
        if (in_array('unavailable', $states, true)) {
            return 'unavailable';
        }
        if (in_array('policy_failure', $states, true)) {
            return 'policy_failure';
        }
        return 'clean';
    }

    /** @return AuditResult */
    public static function classify(int $exit, string $stdout, string $stderr): array
    {
        try {
            $decoded = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'state' => 'unavailable',
                'message' => self::unavailableMessage($exit, $stderr),
                'findings' => [],
                'abandoned_packages' => [],
            ];
        }
        if (!is_array($decoded)) {
            return [
                'state' => 'unavailable',
                'message' => 'audit provider returned a non-object response',
                'findings' => [],
                'abandoned_packages' => [],
            ];
        }
        $advisories = $decoded['advisories'] ?? [];
        $abandoned = $decoded['abandoned'] ?? [];
        if (!is_array($advisories) || !is_array($abandoned)) {
            return [
                'state' => 'unavailable',
                'message' => 'audit provider response is missing advisory collections',
                'findings' => [],
                'abandoned_packages' => [],
            ];
        }
        $findings = [];
        foreach ($advisories as $package => $rows) {
            if (!is_string($package) || !is_array($rows)) {
                return [
                    'state' => 'unavailable',
                    'message' => 'audit provider advisory collection is malformed',
                    'findings' => [],
                    'abandoned_packages' => [],
                ];
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = $row['advisoryId'] ?? $row['cve'] ?? null;
                if (is_string($id) && $id !== '') {
                    $findings[] = ['package' => $package, 'advisory_id' => $id];
                }
            }
        }
        usort($findings, static fn(array $left, array $right): int => [$left['package'], $left['advisory_id']] <=> [$right['package'], $right['advisory_id']]);
        $abandonedPackages = [];
        foreach ($abandoned as $package => $replacement) {
            if (is_string($package) && (is_string($replacement) || $replacement === null)) {
                $abandonedPackages[] = $package;
            }
        }
        sort($abandonedPackages, SORT_STRING);
        if ($findings !== [] || $abandonedPackages !== []) {
            return [
                'state' => 'policy_failure',
                'message' => 'Composer reported security advisories or abandoned packages',
                'findings' => $findings,
                'abandoned_packages' => $abandonedPackages,
            ];
        }
        if ($exit !== 0) {
            return [
                'state' => 'unavailable',
                'message' => self::unavailableMessage($exit, $stderr),
                'findings' => [],
                'abandoned_packages' => [],
            ];
        }
        return [
            'state' => 'clean',
            'message' => 'Composer reported no security advisories or abandoned packages',
            'findings' => [],
            'abandoned_packages' => [],
        ];
    }

    /** @return ImageResult */
    private function checkImages(): array
    {
        $profilePath = $this->root . '/sandbox/catalog/fragments/engineering-platform/performance-profile.json';
        $bytes = file_get_contents($profilePath);
        if (!is_string($bytes)) {
            return ['state' => 'unavailable', 'message' => 'performance image profile is absent', 'checks' => [], 'docker' => null];
        }
        try {
            $profile = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['state' => 'unavailable', 'message' => 'performance image profile is invalid JSON', 'checks' => [], 'docker' => null];
        }
        $references = is_array($profile) ? ($profile['images'] ?? null) : null;
        if (!is_array($references) || $references === []) {
            return ['state' => 'unavailable', 'message' => 'performance image profile has no images', 'checks' => [], 'docker' => null];
        }
        ksort($references, SORT_STRING);
        $docker = $this->findExecutable('docker');
        if ($docker === null) {
            return ['state' => 'unavailable', 'message' => 'Docker is unavailable for pinned image update checks', 'checks' => [], 'docker' => null];
        }
        $checks = [];
        $policyFailure = false;
        foreach ($references as $name => $reference) {
            if (!is_string($name)
                || !is_string($reference)
                || preg_match('~^([a-z0-9][a-z0-9._/-]*:[A-Za-z0-9._-]+)@(sha256:[a-f0-9]{64})$~D', $reference, $matches) !== 1) {
                return ['state' => 'unavailable', 'message' => 'performance image profile contains a malformed digest pin', 'checks' => $checks, 'docker' => $docker];
            }
            $process = $this->process([$docker, 'buildx', 'imagetools', 'inspect', $matches[1], '--format', '{{json .Manifest.Digest}}']);
            try {
                $observed = json_decode($process['stdout'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $observed = null;
            }
            if ($process['exit'] !== 0 || !is_string($observed) || preg_match('/^sha256:[a-f0-9]{64}$/D', $observed) !== 1) {
                return [
                    'state' => 'unavailable',
                    'message' => self::unavailableMessage($process['exit'], $process['stderr']),
                    'checks' => $checks,
                    'docker' => $docker,
                ];
            }
            $checkState = hash_equals($matches[2], $observed) ? 'current' : 'update_required';
            $policyFailure = $policyFailure || $checkState !== 'current';
            $checks[] = [
                'name' => $name,
                'reference' => $matches[1],
                'pinned_digest' => $matches[2],
                'observed_digest' => $observed,
                'state' => $checkState,
            ];
        }
        if ($policyFailure) {
            return ['state' => 'policy_failure', 'message' => 'a controlled performance image tag no longer resolves to its reviewed digest', 'checks' => $checks, 'docker' => $docker];
        }
        return ['state' => 'clean', 'message' => 'controlled performance image tags match their reviewed digests', 'checks' => $checks, 'docker' => $docker];
    }

    private function findExecutable(string $name): ?string
    {
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            $candidate = rtrim($directory, '/') . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param list<string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function process(array $argv): array
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            [
                'PATH' => (string) getenv('PATH'),
                'HOME' => (string) getenv('HOME'),
                'COMPOSER_HOME' => (string) getenv('COMPOSER_HOME'),
                'COMPOSER_NO_INTERACTION' => '1',
                'LC_ALL' => 'C',
                'LANG' => 'C',
                'TZ' => 'UTC',
            ],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return ['exit' => 2, 'stdout' => '', 'stderr' => 'cannot start audit provider'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
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
            throw new CatalogException('cannot resolve audit candidate');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout) || preg_match('/^[a-f0-9]{40}$/D', trim($stdout)) !== 1) {
            throw new CatalogException('audit candidate is not a full SHA');
        }
        return trim($stdout);
    }

    private function digest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new CatalogException('cannot digest audit input');
        }
        return 'sha256:' . $digest;
    }

    private function publish(string $relative, string $bytes): void
    {
        if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative) !== 1 || str_contains($relative, '..')) {
            throw new CatalogException('audit result path must be artifacts-relative');
        }
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new CatalogException('cannot create audit result directory');
        }
        $temporary = tempnam(dirname($path), '.audit.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create audit result temporary file');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new CatalogException('cannot publish audit result');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function unavailableMessage(int $exit, string $stderr): string
    {
        $reason = trim((string) preg_replace('/\s+/', ' ', $stderr));
        if ($reason === '') {
            return "audit provider was unavailable (exit $exit)";
        }
        $reason = (string) preg_replace(
            '/(?i)(password|token|secret|credential|authorization)(\s*[:=]\s*)\S+/',
            '$1$2<redacted>',
            $reason,
        );
        $reason = (string) preg_replace('#(?<![A-Za-z0-9:])/(?:[A-Za-z0-9._@%+=,~\-]+/?)+#', '<redacted-path>', $reason);
        return 'audit provider was unavailable: ' . substr($reason, 0, 240);
    }
}
