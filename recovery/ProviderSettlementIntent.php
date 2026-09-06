<?php
declare(strict_types=1);

namespace WPrism\Recovery;

/**
 * Database-external debt for one checkpoint-backed adapter-provider pass.
 *
 * The host publishes this after checkpoint/code staging and before the first
 * lifecycle or settlement callback. It spans lifecycle hooks, schema
 * establishment, and derived-state settlement across their separate WordPress
 * processes, so a crash between phases cannot make an exact-looking target
 * skip unfinished work.
 */
final class ProviderSettlementIntent {
    public const FORMAT = 'wprism-provider-settlement-intent/v1';
    public const STATUS_FORMAT = 'wprism-provider-settlement-status/v1';
    public const RECOVERY_STATUS_FORMAT = 'wprism-provider-settlement-recovery/v1';
    private const FILE = 'provider-settlement-intent.json';
    private const LOCK = 'provider-settlement-intent.lock';

    /**
     * @param list<string> $phases
     * @return array{cipher_sha256:string,format:string,phases:list<string>,resumed:bool}
     */
    public static function begin(
        string $root,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash,
        array $phases
    ): array {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $artifact,
            $checkpoint,
            $cipherSha256,
            $owner,
            $artifactHash,
            $phases
        ): array {
            $intent = self::validate(self::identity(
                $root,
                $repo,
                $artifact,
                $checkpoint,
                $cipherSha256,
                $owner,
                $artifactHash,
                $phases
            ) + ['completed_phases' => [], 'format' => self::FORMAT]);
            $current = self::read($root);
            if ($current !== null) {
                self::assertSame($current, $intent);
                return self::summary($current, true);
            }
            AtomicStore::publishExact(
                self::path($root),
                CanonicalJson::encode($intent) . "\n",
                0600,
                'provider settlement intent',
                'wprism provider settlement',
                false
            );
            $published = self::read($root);
            if ($published === null
                || !hash_equals(CanonicalJson::encode($intent), CanonicalJson::encode($published))) {
                throw new \RuntimeException(
                    'wprism provider settlement: durable intent readback failed before provider mutation'
                );
            }
            return self::summary($published, false);
        });
    }

    /**
     * Durably consume only the next declared phase. A response loss leaves the
     * record at either the prior or advanced canonical state; neither permits
     * a later phase to run out of order or terminal completion to guess.
     *
     * @param list<string> $phases
     * @return array{completed_phases:list<string>,format:string,next_phase:?string}
     */
    public static function advance(
        string $root,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        array $phases,
        string $phase
    ): array {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $artifact,
            $checkpoint,
            $owner,
            $artifactHash,
            $phases,
            $phase
        ): array {
            $current = self::read($root);
            if ($current === null) {
                throw new \RuntimeException('wprism provider settlement: phase advance lost its durable intent');
            }
            $expected = self::identity(
                $root,
                $repo,
                $artifact,
                $checkpoint,
                (string) $current['checkpoint']['cipher_sha256'],
                $owner,
                $artifactHash,
                $phases
            ) + ['format' => self::FORMAT];
            self::assertIdentity($current, $expected);
            $completed = $current['completed_phases'];
            $next = $current['phases'][count($completed)] ?? null;
            if (!is_string($next) || !hash_equals($next, $phase)) {
                throw new \RuntimeException(
                    'wprism provider settlement: phase advance is out of order or already complete'
                );
            }
            $current['completed_phases'][] = $phase;
            self::validate($current);
            AtomicStore::atomicWrite(
                self::path($root),
                CanonicalJson::encode($current) . "\n",
                0600,
                'provider settlement intent',
                'wprism provider settlement'
            );
            $advanced = self::read($root);
            if ($advanced === null
                || !hash_equals(CanonicalJson::encode($current), CanonicalJson::encode($advanced))) {
                throw new \RuntimeException(
                    'wprism provider settlement: phase advance readback failed'
                );
            }
            return [
                'completed_phases' => $advanced['completed_phases'],
                'format' => self::STATUS_FORMAT,
                'next_phase' => $advanced['phases'][count($advanced['completed_phases'])] ?? null,
            ];
        });
    }

    /**
     * Clear only after the terminal provider phase returned verified success.
     * A missing record is an idempotent response retry, not authority to remove
     * a different record.
     *
     * @param list<string> $phases
     * @return array{active:false,format:string}
     */
    public static function complete(
        string $root,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        array $phases
    ): array {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $artifact,
            $checkpoint,
            $owner,
            $artifactHash,
            $phases
        ): array {
            $current = self::read($root);
            if ($current === null) {
                return ['active' => false, 'format' => self::STATUS_FORMAT];
            }
            $expected = self::identity(
                $root,
                $repo,
                $artifact,
                $checkpoint,
                (string) $current['checkpoint']['cipher_sha256'],
                $owner,
                $artifactHash,
                $phases
            ) + ['format' => self::FORMAT];
            self::assertIdentity($current, $expected);
            if ($current['completed_phases'] !== $current['phases']) {
                throw new \RuntimeException(
                    'wprism provider settlement: completion refused before every declared phase advanced'
                );
            }
            self::remove($root);
            return ['active' => false, 'format' => self::STATUS_FORMAT];
        });
    }

    /**
     * Bind checkpoint recovery to the exact still-active provider transaction.
     * Null is the ordinary retained-checkpoint path with no provider debt.
     */
    public static function recoveryHash(
        string $root,
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash
    ): ?string {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $checkpoint,
            $cipherSha256,
            $owner,
            $artifactHash
        ): ?string {
            $current = self::read($root);
            if ($current === null) {
                return null;
            }
            self::assertRecoveryIdentity(
                $current,
                $root,
                $repo,
                $checkpoint,
                $cipherSha256,
                $owner,
                $artifactHash
            );
            return hash('sha256', CanonicalJson::encode($current));
        });
    }

    /** Remove the exact provider fence after its checkpoint restore completed. */
    public static function completeRecovery(
        string $root,
        string $repo,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        string $expectedHash
    ): void {
        self::locked($root, static function () use (
            $root,
            $repo,
            $checkpoint,
            $owner,
            $artifactHash,
            $expectedHash
        ): void {
            if (preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) {
                throw new \RuntimeException('wprism provider settlement: recovery identity is malformed');
            }
            $current = self::read($root);
            if ($current === null) {
                return;
            }
            $actual = hash('sha256', CanonicalJson::encode($current));
            if (!hash_equals($expectedHash, $actual)) {
                throw new \RuntimeException(
                    'wprism provider settlement: recovery cannot clear a different provider transaction'
                );
            }
            self::assertRecoveryIdentity(
                $current,
                $root,
                $repo,
                $checkpoint,
                (string) $current['checkpoint']['cipher_sha256'],
                $owner,
                $artifactHash
            );
            self::remove($root);
        });
    }

    /** @return array{active:bool,format:string} */
    public static function status(string $root): array {
        return self::locked($root, static fn(): array => [
            'active' => self::read($root) !== null,
            'format' => self::STATUS_FORMAT,
        ]);
    }

    /**
     * Expose only the exact retained release identity recovery may select.
     * This read is database-independent and revalidates every filesystem
     * boundary before the host is allowed to choose a recovery profile.
     *
     * @return array{active:bool,artifact_hash:?string,checkpoint:?array{cipher_sha256:string,path:string},format:string,owner:?string}
     */
    public static function recoveryStatus(string $root): array {
        return self::locked($root, static function () use ($root): array {
            $current = self::read($root);
            if ($current === null) {
                return [
                    'active' => false,
                    'artifact_hash' => null,
                    'checkpoint' => null,
                    'format' => self::RECOVERY_STATUS_FORMAT,
                    'owner' => null,
                ];
            }
            $repo = dirname(dirname(self::root($root)));
            self::assertRecoveryIdentity(
                $current,
                $root,
                $repo,
                (string) $current['checkpoint']['path'],
                (string) $current['checkpoint']['cipher_sha256'],
                (string) $current['owner'],
                (string) $current['artifact_hash']
            );
            return [
                'active' => true,
                'artifact_hash' => (string) $current['artifact_hash'],
                'checkpoint' => [
                    'cipher_sha256' => (string) $current['checkpoint']['cipher_sha256'],
                    'path' => (string) $current['checkpoint']['path'],
                ],
                'format' => self::RECOVERY_STATUS_FORMAT,
                'owner' => (string) $current['owner'],
            ];
        });
    }

    /** @return array<string,mixed>|null */
    private static function read(string $root): ?array {
        $path = self::path($root);
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        return self::validate(AtomicStore::readCanonical(
            $path,
            'provider settlement intent',
            'wprism provider settlement'
        ));
    }

    /** @param list<string> $phases @return array<string,mixed> */
    private static function identity(
        string $root,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash,
        array $phases
    ): array {
        $root = self::root($root);
        $repoInput = rtrim($repo, '/');
        $repoRoot = realpath($repoInput);
        if ($repoRoot === false
            || is_link($repoInput)
            || $repoRoot . '/.wprism/control' !== $root) {
            throw new \RuntimeException(
                'wprism provider settlement: repository and durable control root disagree'
            );
        }
        $artifactDirectory = $repoRoot . '/.wprism/artifacts';
        $checkpointDirectory = $repoRoot . '/.wprism/checkpoints';
        $artifactName = basename($artifact);
        $checkpointName = basename($checkpoint);
        if (!is_dir($artifactDirectory)
            || is_link($artifactDirectory)
            || realpath($artifactDirectory) !== $artifactDirectory
            || !is_dir($checkpointDirectory)
            || is_link($checkpointDirectory)
            || realpath($checkpointDirectory) !== $checkpointDirectory
            || $artifact !== $artifactDirectory . '/' . $artifactName
            || is_link($artifact)
            || !is_file($artifact)
            || realpath($artifact) !== $artifact
            || $checkpoint !== $checkpointDirectory . '/' . $checkpointName
            || is_link($checkpoint)
            || !is_file($checkpoint)
            || realpath($checkpoint) !== $checkpoint) {
            throw new \RuntimeException(
                'wprism provider settlement: artifact/checkpoint boundary is invalid'
            );
        }
        if (preg_match(
            '/^(deploy|promote|materialize)-([A-Za-z0-9][A-Za-z0-9._-]{0,127})\.json$/D',
            $artifactName,
            $artifactMatch
        ) !== 1
            || preg_match(
                '/^(deploy|promote|materialize)-([A-Za-z0-9][A-Za-z0-9._-]{0,127})\.sql\.enc$/D',
                $checkpointName,
                $checkpointMatch
            ) !== 1
            || $artifactMatch[1] !== $checkpointMatch[1]
            || $artifactMatch[2] !== $checkpointMatch[2]) {
            throw new \RuntimeException(
                'wprism provider settlement: artifact and checkpoint do not name the same release'
            );
        }
        $expectedOwner = $artifactMatch[1] === 'materialize'
            ? 'wprism-env-promotion-' . $artifactMatch[2]
            : $artifactMatch[2];
        if (!hash_equals($expectedOwner, $owner)
            || preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $cipherSha256) !== 1) {
            throw new \RuntimeException('wprism provider settlement: release identity is malformed');
        }
        self::assertPhases($phases);
        return [
            'artifact_hash' => $artifactHash,
            'artifact_path' => $artifact,
            'checkpoint' => [
                'cipher_sha256' => $cipherSha256,
                'path' => $checkpoint,
            ],
            'owner' => $owner,
            'phases' => $phases,
        ];
    }

    /** @param array<string,mixed> $current */
    private static function assertRecoveryIdentity(
        array $current,
        string $root,
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash
    ): void {
        $expected = self::identity(
            $root,
            $repo,
            (string) $current['artifact_path'],
            $checkpoint,
            $cipherSha256,
            $owner,
            $artifactHash,
            (array) $current['phases']
        ) + ['format' => self::FORMAT];
        self::assertIdentity($current, $expected);
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private static function validate(array $intent): array {
        $keys = array_keys($intent);
        sort($keys, SORT_STRING);
        $checkpoint = $intent['checkpoint'] ?? null;
        $checkpointKeys = is_array($checkpoint) ? array_keys($checkpoint) : [];
        sort($checkpointKeys, SORT_STRING);
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
            || !is_array($intent['phases'] ?? null)
            || !array_is_list($intent['phases'])
            || !is_array($intent['completed_phases'] ?? null)
            || !array_is_list($intent['completed_phases'])) {
            throw new \RuntimeException('wprism provider settlement: durable intent is malformed');
        }
        self::assertPhases($intent['phases']);
        $completed = $intent['completed_phases'];
        if ($completed !== array_slice($intent['phases'], 0, count($completed))) {
            throw new \RuntimeException('wprism provider settlement: durable phase progress is malformed');
        }
        return $intent;
    }

    /** @param list<string> $phases */
    private static function assertPhases(array $phases): void {
        if (!in_array($phases, [
            ['schema-settle'],
            ['lifecycle-settle'],
            ['schema-settle', 'lifecycle-settle'],
            ['lifecycle-retire', 'lifecycle-activate', 'schema-settle'],
            ['lifecycle-retire', 'lifecycle-activate', 'lifecycle-settle'],
            ['lifecycle-retire', 'lifecycle-activate', 'schema-settle', 'lifecycle-settle'],
        ], true)) {
            throw new \RuntimeException('wprism provider settlement: phase set is malformed');
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $expected */
    private static function assertSame(array $current, array $expected): void {
        if (!hash_equals(CanonicalJson::encode($current), CanonicalJson::encode($expected))) {
            throw new \RuntimeException(
                'wprism provider settlement: an incomplete transaction belongs to a different checkpoint or release'
            );
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $expected */
    private static function assertIdentity(array $current, array $expected): void {
        foreach ($expected as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                throw new \RuntimeException(
                    'wprism provider settlement: an incomplete transaction belongs to a different checkpoint or release'
                );
            }
        }
    }

    /** @param array<string,mixed> $intent @return array{cipher_sha256:string,format:string,phases:list<string>,resumed:bool} */
    private static function summary(array $intent, bool $resumed): array {
        return [
            'cipher_sha256' => (string) $intent['checkpoint']['cipher_sha256'],
            'format' => self::FORMAT,
            'phases' => $intent['phases'],
            'resumed' => $resumed,
        ];
    }

    private static function remove(string $root): void {
        $path = self::path($root);
        if (!@unlink($path)) {
            throw new \RuntimeException('wprism provider settlement: completed intent could not be removed');
        }
        AtomicStore::syncDirectory($root, 'control root', 'wprism provider settlement');
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException(
                'wprism provider settlement: completed intent remained after durable removal'
            );
        }
    }

    private static function path(string $root): string {
        return self::root($root) . '/' . self::FILE;
    }

    private static function root(string $root): string {
        $input = rtrim($root, '/');
        $resolved = realpath($input);
        if ($resolved === false
            || $resolved !== $input
            || is_link($input)
            || !is_dir($resolved)) {
            throw new \RuntimeException('wprism provider settlement: durable control root is unsafe');
        }
        return $resolved;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private static function locked(string $root, callable $callback): mixed {
        $root = self::root($root);
        return ProtocolLock::withExclusive(
            $root . '/' . self::LOCK,
            $callback,
            'wprism provider settlement: lock path is unsafe',
            'wprism provider settlement: could not open the settlement lock',
            'wprism provider settlement: could not acquire the settlement lock',
            0600
        );
    }
}
