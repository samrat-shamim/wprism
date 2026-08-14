<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * Network-aware Composer advisory wrapper with stable three-way outcomes.
 *
 * @phpstan-type Finding array{package:string,advisory_id:string}
 * @phpstan-type AuditResult array{state:string,message:string,findings:list<Finding>,abandoned_packages:list<string>}
 */
final class Audit
{
    public function __construct(private readonly string $root) {}

    public function run(string $resultPath): int
    {
        $composer = $this->findComposer();
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $startedNs = hrtime(true);
        $process = proc_open(
            [$composer, 'audit', '--locked', '--no-interaction', '--format=json'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            [
                'PATH' => (string) getenv('PATH'),
                'HOME' => (string) getenv('HOME'),
                'COMPOSER_HOME' => (string) getenv('COMPOSER_HOME'),
                'LC_ALL' => 'C',
                'LANG' => 'C',
                'TZ' => 'UTC',
            ],
            ['bypass_shell' => true],
        );
        $stdout = '';
        $stderr = '';
        $exit = 2;
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdoutValue = stream_get_contents($pipes[1]);
            $stderrValue = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $stdout = is_string($stdoutValue) ? $stdoutValue : '';
            $stderr = is_string($stderrValue) ? $stderrValue : '';
        }
        $result = self::classify($exit, $stdout, $stderr);
        $receipt = [
            'format' => 'duo-network-audit-receipt/v1',
            'state' => $result['state'],
            'message' => $result['message'],
            'candidate_sha' => $this->candidateSha(),
            'provider' => 'composer-audit/packagist',
            'retrieved_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'started_at' => $started,
            'duration_ms' => (int) round((hrtime(true) - $startedNs) / 1_000_000),
            'composer_sha256' => $this->digest($composer),
            'dependency_lock_sha256' => $this->digest($this->root . '/composer.lock'),
            'findings' => $result['findings'],
            'abandoned_packages' => $result['abandoned_packages'],
        ];
        $this->publish($resultPath, json_encode(
            $receipt,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
        return match ($result['state']) {
            'clean' => 0,
            'policy_failure' => 1,
            default => 2,
        };
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

    private function findComposer(): string
    {
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            $candidate = rtrim($directory, '/') . '/composer';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new CatalogException('Composer is unavailable for audit');
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
