<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/** Fail-closed checks for committed development artifacts, credentials, and action pins. */
final class Hygiene
{
    public function __construct(private readonly string $root) {}

    public function run(): void
    {
        foreach ($this->trackedPaths() as $path) {
            $pathViolation = self::trackedPathViolation($path);
            if ($pathViolation !== null) {
                throw new CatalogException("repository hygiene violation in $path: $pathViolation");
            }
            if (!$this->scannedSource($path)) {
                continue;
            }
            $bytes = file_get_contents($this->root . '/' . $path);
            if (!is_string($bytes)) {
                throw new CatalogException("cannot read hygiene input $path");
            }
            $materialViolation = self::sensitiveMaterialViolation($bytes);
            if ($materialViolation !== null) {
                throw new CatalogException("repository hygiene violation in $path: $materialViolation");
            }
            if (str_starts_with($path, '.github/workflows/')) {
                $actionViolation = self::workflowActionViolation($bytes);
                if ($actionViolation !== null) {
                    throw new CatalogException("repository hygiene violation in $path: $actionViolation");
                }
            }
        }
    }

    public static function trackedPathViolation(string $path): ?string
    {
        if (preg_match('~^(?:artifacts|vendor|\.deptrac\.cache|\.phpunit\.cache|sandbox/tmp)(?:/|$)~D', $path) === 1) {
            return 'generated or dependency artifacts must not be tracked';
        }
        if (preg_match('~(?:^|/)(?:\.env(?:\..+)?|auth\.json|id_(?:rsa|ed25519)|[^/]+\.(?:key|p12|pem|pfx))$~Di', $path) === 1
            && !str_ends_with($path, '.env.example')) {
            return 'credential-bearing file names must not be tracked';
        }
        return null;
    }

    public static function sensitiveMaterialViolation(string $bytes): ?string
    {
        $patterns = [
            'private key material' => '~-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE' . ' KEY-----~',
            'AWS access key' => '~A' . 'KIA[0-9A-Z]{16}~',
            'GitHub access token' => '~gh' . '[pousr]_[A-Za-z0-9]{30,}~',
            'Google API key' => '~AI' . 'za[0-9A-Za-z_-]{35}~',
            'Slack access token' => '~xo' . 'x[baprs]-[0-9A-Za-z-]{20,}~',
        ];
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $bytes) === 1) {
                return $label . ' is committed in a governed source';
            }
        }
        return null;
    }

    public static function workflowActionViolation(string $bytes): ?string
    {
        preg_match_all('/^\s*(?:-\s*)?uses:\s*([^\s#]+)(?:\s+#.*)?$/m', $bytes, $matches);
        foreach ($matches[1] as $reference) {
            if (str_starts_with($reference, './')) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[a-f0-9]{40}$/D', $reference) !== 1) {
                return 'third-party action is not pinned to a full commit SHA';
            }
        }
        return null;
    }

    /** @return list<string> */
    private function trackedPaths(): array
    {
        $process = proc_open(
            ['git', 'ls-files', '-z'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['LC_ALL' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot enumerate tracked paths for hygiene checks');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new CatalogException('cannot enumerate tracked paths for hygiene checks: ' . trim((string) $stderr));
        }
        $paths = array_values(array_filter(explode("\0", $stdout), static fn(string $path): bool => $path !== ''));
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function scannedSource(string $path): bool
    {
        return str_starts_with($path, '.github/workflows/')
            || str_starts_with($path, 'sandbox/catalog/fragments/engineering-platform/');
    }
}
