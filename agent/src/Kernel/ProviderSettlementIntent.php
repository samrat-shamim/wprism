<?php
declare(strict_types=1);

namespace WPrism;

/** Read-only fence over the recovery runtime's cross-process provider debt. */
final class ProviderSettlementIntent {
    public const FORMAT = 'wprism-provider-settlement-intent/v1';
    private const RELATIVE_PATH = '/.wprism/control/provider-settlement-intent.json';

    /** @var array{root:string,sha256:string}|null */
    private static ?array $continuation = null;

    /** Every ordinary policy load refuses while an unfinished provider pass exists. */
    public static function assert_clear(string $repo): void {
        $root = self::repoRoot($repo);
        $path = $root . self::RELATIVE_PATH;
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (self::$continuation !== null && self::$continuation['root'] === $root) {
            $actual = self::read($root);
            if (!hash_equals(self::$continuation['sha256'], $actual['sha256'])) {
                throw new \RuntimeException(
                    'wprism: provider settlement authority changed inside its exact continuation'
                );
            }
            return;
        }
        throw new \RuntimeException(
            'wprism: incomplete provider settlement blocks repository observation and mutation; '
            . 'restore its exact retained checkpoint through wprism recover'
        );
    }

    /**
     * Admit only the named phase of the exact host-authenticated transaction.
     * Policy loads inside the callback see a process-local continuation; the
     * control record is re-read before return so no phase can outlive its debt.
     *
     * @template T
     * @param callable(array<string,mixed>):T $callback
     * @return T
     */
    public static function with_phase(
        string $repo,
        string $artifactPath,
        string $checkpointPath,
        string $owner,
        string $artifactHash,
        string $phase,
        callable $callback
    ): mixed {
        if (self::$continuation !== null) {
            throw new \RuntimeException('wprism: nested provider settlement continuation is invalid');
        }
        $root = self::repoRoot($repo);
        $read = self::read($root);
        $intent = $read['intent'];
        $nextPhase = $intent['phases'][count($intent['completed_phases'])] ?? null;
        if (!is_string($nextPhase) || !hash_equals($nextPhase, $phase)) {
            throw new \RuntimeException(
                "wprism: provider settlement intent does not authorize '$phase' as its next phase"
            );
        }
        if (($intent['artifact_path'] ?? null) !== $artifactPath
            || ($intent['checkpoint']['path'] ?? null) !== $checkpointPath
            || ($intent['owner'] ?? null) !== $owner
            || ($intent['artifact_hash'] ?? null) !== $artifactHash) {
            throw new \RuntimeException(
                'wprism: provider settlement continuation belongs to a different checkpoint or release'
            );
        }
        self::$continuation = ['root' => $root, 'sha256' => $read['sha256']];
        try {
            $result = $callback($intent);
            $after = self::read($root);
            if (!hash_equals($read['sha256'], $after['sha256'])) {
                throw new \RuntimeException(
                    'wprism: provider settlement authority changed before phase completion'
                );
            }
            return $result;
        } finally {
            self::$continuation = null;
        }
    }

    /** @return array{intent:array<string,mixed>,sha256:string} */
    private static function read(string $root): array {
        $path = $root . self::RELATIVE_PATH;
        if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
            throw new \RuntimeException('wprism: provider settlement control record is unsafe');
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException('wprism: provider settlement control record is unreadable');
        }
        try {
            $intent = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: malformed provider settlement control record', 0, $failure);
        }
        if (!is_array($intent)
            || array_is_list($intent)
            || self::encode($intent) . "\n" !== $raw) {
            throw new \RuntimeException('wprism: malformed provider settlement control record');
        }
        self::validate($intent);
        return ['intent' => $intent, 'sha256' => hash('sha256', $raw)];
    }

    /** @param array<string,mixed> $intent */
    private static function validate(array $intent): void {
        $keys = array_keys($intent);
        sort($keys, SORT_STRING);
        $checkpoint = $intent['checkpoint'] ?? null;
        $checkpointKeys = is_array($checkpoint) ? array_keys($checkpoint) : [];
        sort($checkpointKeys, SORT_STRING);
        $phases = $intent['phases'] ?? null;
        $completed = $intent['completed_phases'] ?? null;
        if ($keys !== [
            'artifact_hash', 'artifact_path', 'checkpoint', 'completed_phases',
            'format', 'owner', 'phases',
        ]
            || ($intent['format'] ?? null) !== self::FORMAT
            || !is_string($intent['artifact_path'] ?? null)
            || $intent['artifact_path'] === ''
            || str_contains($intent['artifact_path'], "\0")
            || !is_string($intent['owner'] ?? null)
            || $intent['owner'] === ''
            || strlen($intent['owner']) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $intent['owner']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['artifact_hash'] ?? '')) !== 1
            || $checkpointKeys !== ['cipher_sha256', 'path']
            || !is_string($checkpoint['path'] ?? null)
            || $checkpoint['path'] === ''
            || str_contains($checkpoint['path'], "\0")
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($checkpoint['cipher_sha256'] ?? '')) !== 1
            || !is_array($phases)
            || !array_is_list($phases)
            || !is_array($completed)
            || !array_is_list($completed)
            || !in_array($phases, [
                ['schema-settle'],
                ['lifecycle-settle'],
                ['schema-settle', 'lifecycle-settle'],
            ], true)
            || $completed !== array_slice($phases, 0, count($completed))) {
            throw new \RuntimeException('wprism: malformed provider settlement control record');
        }
    }

    private static function repoRoot(string $repo): string {
        $input = rtrim($repo, '/');
        $root = realpath($input);
        if ($root === false || is_link($input) || !is_dir($root)) {
            throw new \RuntimeException('wprism: provider settlement repository boundary is invalid');
        }
        $state = $root . '/.wprism';
        $control = $state . '/control';
        foreach ([$state, $control] as $directory) {
            if (!file_exists($directory) && !is_link($directory)) {
                continue;
            }
            if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
                throw new \RuntimeException('wprism: provider settlement control boundary is invalid');
            }
        }
        return $root;
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string {
        $normalized = self::normalize($value);
        try {
            return json_encode(
                $normalized,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $failure) {
            throw new \RuntimeException('wprism: malformed provider settlement control record', 0, $failure);
        }
    }

    private static function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            if (is_float($value) || is_object($value) || is_resource($value)) {
                throw new \RuntimeException('wprism: malformed provider settlement control record');
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('wprism: malformed provider settlement control record');
            }
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
