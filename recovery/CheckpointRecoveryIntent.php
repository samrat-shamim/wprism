<?php
declare(strict_types=1);

namespace WPrism\Recovery;

require_once __DIR__ . '/ProviderSettlementIntent.php';

/**
 * Database-external authority for one operator-directed checkpoint import.
 *
 * The record lives in the adopted control root, not in managed code or the
 * database it fences. An exact retry can therefore survive both code-first
 * rollback and a reset/import crash, while a different checkpoint cannot use
 * the resulting empty or partially restored database as a fresh start.
 */
final class CheckpointRecoveryIntent {
    public const FORMAT = 'wprism-checkpoint-recovery-intent/v1';
    public const STATUS_FORMAT = 'wprism-checkpoint-recovery-status/v1';
    private const FILE = 'checkpoint-recovery-intent.json';
    private const LOCK = 'checkpoint-recovery-intent.lock';
    private const SCHEMA_FORMAT = 'wprism-schema-settlement-intent/v1';

    /**
     * Resume an already-published exact intent before consulting the database.
     * A retry after reset must not depend on the ledger surviving that reset.
     *
     * @return array{cipher_sha256:string,database_target_sha256:string,format:string,provider_intent:bool,resumed:true,schema_intent:bool}|null
     */
    public static function resume(
        string $root,
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash,
        string $databaseTargetSha256
    ): ?array {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $checkpoint,
            $cipherSha256,
            $owner,
            $artifactHash,
            $databaseTargetSha256
        ): ?array {
            $current = self::read($root);
            if ($current === null) {
                return null;
            }
            self::assertIdentity(
                $current,
                self::identity($root, $repo, $checkpoint, $cipherSha256, $owner, $artifactHash)
            );
            self::assertDatabaseTargetHash($current, $databaseTargetSha256);
            return self::summary($current, true);
        });
    }

    /**
     * Publish only after ciphertext authentication and a checked schema-intent
     * read, and before the first destructive database command.
     *
     * @return array{cipher_sha256:string,database_target_sha256:string,format:string,provider_intent:bool,resumed:bool,schema_intent:bool}
     */
    public static function begin(
        string $root,
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash,
        string $databaseTargetSha256,
        string $topology,
        ?string $schemaIntent
    ): array {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $checkpoint,
            $cipherSha256,
            $owner,
            $artifactHash,
            $databaseTargetSha256,
            $topology,
            $schemaIntent
        ): array {
            $identity = self::identity(
                $root,
                $repo,
                $checkpoint,
                $cipherSha256,
                $owner,
                $artifactHash
            );
            $current = self::read($root);
            if ($current !== null) {
                self::assertIdentity($current, $identity);
                self::assertDatabaseTargetHash($current, $databaseTargetSha256);
                return self::summary($current, true);
            }

            if ($topology !== 'single-site') {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: whole-database restore is certified for single-site topology only'
                );
            }
            $schemaHash = self::schemaIntentHash(
                $schemaIntent,
                $identity['checkpoint'],
                $identity['artifact_hash']
            );
            $providerHash = ProviderSettlementIntent::recoveryHash(
                $root,
                $repo,
                $checkpoint,
                $cipherSha256,
                $owner,
                $artifactHash
            );
            $intent = self::validate($identity + [
                'database_target_sha256' => self::databaseTargetDigest($databaseTargetSha256),
                'format' => self::FORMAT,
                'provider_intent_sha256' => $providerHash,
                'schema_intent_sha256' => $schemaHash,
                'topology' => $topology,
            ]);
            AtomicStore::publishExact(
                self::path($root),
                CanonicalJson::encode($intent) . "\n",
                0600,
                'checkpoint recovery intent',
                'wprism checkpoint recovery',
                false
            );
            $published = self::read($root);
            if ($published === null
                || !hash_equals(
                    CanonicalJson::encode($intent),
                    CanonicalJson::encode($published)
                )) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: durable intent readback failed before database reset'
                );
            }
            return self::summary($published, false);
        });
    }

    /**
     * Clear only after import and the restored lease's final abort both
     * succeeded. Directory fsync is part of success, so terminal output never
     * gets ahead of durable deletion.
     *
     * @return array{active:false,format:string}
     */
    public static function complete(
        string $root,
        string $repo,
        string $checkpoint,
        string $owner,
        string $artifactHash
    ): array {
        return self::locked($root, static function () use (
            $root,
            $repo,
            $checkpoint,
            $owner,
            $artifactHash
        ): array {
            $current = self::read($root);
            if ($current === null) {
                return ['active' => false, 'format' => self::STATUS_FORMAT];
            }
            $expected = self::identity(
                $root,
                $repo,
                $checkpoint,
                (string) $current['checkpoint']['cipher_sha256'],
                $owner,
                $artifactHash
            );
            self::assertIdentity($current, $expected);
            if ($current['provider_intent_sha256'] !== null) {
                ProviderSettlementIntent::completeRecovery(
                    $root,
                    $repo,
                    $checkpoint,
                    $owner,
                    $artifactHash,
                    (string) $current['provider_intent_sha256']
                );
            }
            $path = self::path($root);
            if (!@unlink($path)) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: completed intent could not be removed'
                );
            }
            AtomicStore::syncDirectory($root, 'control root', 'wprism checkpoint recovery');
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: completed intent remained after durable removal'
                );
            }
            return ['active' => false, 'format' => self::STATUS_FORMAT];
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
     * Bind destructive recovery to the effective wp-config.php database
     * target without persisting a hostname, database name, or credential.
     */
    public static function databaseTargetHash(string $host, string $name, string $prefix): string {
        return \WPrism\DatabaseTargetIdentity::hash($host, $name, $prefix);
    }

    /** Refuse before a reset or import process can address a different DB. */
    public static function assertDatabaseTarget(string $root, string $databaseTargetSha256): void {
        self::locked($root, static function () use ($root, $databaseTargetSha256): void {
            $current = self::read($root);
            if ($current === null) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: no durable intent authorizes this database operation'
                );
            }
            self::assertDatabaseTargetHash($current, $databaseTargetSha256);
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
            'checkpoint recovery intent',
            'wprism checkpoint recovery'
        ));
    }

    /** @return array<string,mixed> */
    private static function identity(
        string $root,
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $owner,
        string $artifactHash
    ): array {
        $root = self::root($root);
        $repoInput = rtrim($repo, '/');
        $repoRoot = realpath($repoInput);
        $expectedRoot = $repoRoot === false ? '' : $repoRoot . '/.wprism/control';
        if ($repoRoot === false
            || is_link($repoInput)
            || $expectedRoot !== $root) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: repository and durable control root disagree'
            );
        }
        $checkpointRoot = $repoRoot . '/.wprism/checkpoints';
        $basename = basename($checkpoint);
        if (!is_dir($checkpointRoot)
            || is_link($checkpointRoot)
            || realpath($checkpointRoot) !== $checkpointRoot
            || dirname($checkpoint) !== $checkpointRoot
            || $checkpoint !== $checkpointRoot . '/' . $basename
            || preg_match(
                '/^(?:promote|deploy|materialize)-[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.sql\.enc$/D',
                $basename
            ) !== 1
            || is_link($checkpoint)
            || !is_file($checkpoint)) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: checkpoint escaped the retained-checkpoint boundary'
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $cipherSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1
            || $owner === ''
            || strlen($owner) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $owner) !== 1) {
            throw new \RuntimeException('wprism checkpoint recovery: recovery identity is malformed');
        }
        return [
            'artifact_hash' => $artifactHash,
            'checkpoint' => [
                'cipher_sha256' => $cipherSha256,
                'path' => $checkpoint,
            ],
            'owner' => $owner,
        ];
    }

    /** @param array<string,mixed> $checkpoint */
    private static function schemaIntentHash(
        ?string $raw,
        array $checkpoint,
        string $artifactHash
    ): ?string {
        if ($raw === null) {
            return null;
        }
        try {
            $intent = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: schema settlement intent is malformed',
                0,
                $failure
            );
        }
        if (!is_array($intent)
            || array_is_list($intent)
            || CanonicalJson::encode($intent) !== $raw
            || ($intent['format'] ?? null) !== self::SCHEMA_FORMAT
            || ($intent['checkpoint'] ?? null) !== $checkpoint
            || ($intent['artifact_hash'] ?? null) !== $artifactHash) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: selected checkpoint does not match the incomplete schema settlement'
            );
        }
        $keys = array_keys($intent);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'actions_sha256', 'artifact_hash', 'checkpoint', 'effects_sha256',
            'format', 'presence', 'tables',
        ]) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: schema settlement intent is malformed'
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['actions_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['effects_sha256'] ?? '')) !== 1
            || !is_array($intent['tables'] ?? null)
            || !array_is_list($intent['tables'])) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: schema settlement intent is malformed'
            );
        }
        $tables = [];
        foreach ($intent['tables'] as $table) {
            if (!is_string($table)
                || preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $table) !== 1
                || isset($tables[$table])) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: schema settlement intent is malformed'
                );
            }
            $tables[$table] = true;
        }
        $sorted = array_keys($tables);
        sort($sorted, SORT_STRING);
        if ($intent['tables'] !== $sorted
            || !is_array($intent['presence'] ?? null)
            || !array_is_list($intent['presence'])
            || count($intent['presence']) !== count($sorted)) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: schema settlement intent is malformed'
            );
        }
        $absent = false;
        foreach ($intent['presence'] as $index => $row) {
            $rowKeys = is_array($row) ? array_keys($row) : [];
            sort($rowKeys, SORT_STRING);
            if ($rowKeys !== ['present', 'table']
                || ($row['table'] ?? null) !== $sorted[$index]
                || !is_bool($row['present'] ?? null)) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: schema settlement intent is malformed'
                );
            }
            $absent = $absent || !$row['present'];
        }
        if (!$absent) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: schema settlement intent is malformed'
            );
        }
        return hash('sha256', $raw);
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private static function validate(array $intent): array {
        $keys = array_keys($intent);
        sort($keys, SORT_STRING);
        $checkpoint = $intent['checkpoint'] ?? null;
        $checkpointKeys = is_array($checkpoint) ? array_keys($checkpoint) : [];
        sort($checkpointKeys, SORT_STRING);
        if ($keys !== [
            'artifact_hash', 'checkpoint', 'database_target_sha256', 'format', 'owner',
            'provider_intent_sha256', 'schema_intent_sha256', 'topology',
        ]
            || ($intent['format'] ?? null) !== self::FORMAT
            || !is_string($intent['owner'] ?? null)
            || $intent['owner'] === ''
            || strlen($intent['owner']) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $intent['owner']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['artifact_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['database_target_sha256'] ?? '')) !== 1
            || ($intent['provider_intent_sha256'] !== null
                && preg_match('/^[a-f0-9]{64}$/D', (string) $intent['provider_intent_sha256']) !== 1)
            || ($intent['schema_intent_sha256'] !== null
                && preg_match('/^[a-f0-9]{64}$/D', (string) $intent['schema_intent_sha256']) !== 1)
            || ($intent['topology'] ?? null) !== 'single-site'
            || $checkpointKeys !== ['cipher_sha256', 'path']
            || !is_string($checkpoint['path'] ?? null)
            || $checkpoint['path'] === ''
            || str_contains($checkpoint['path'], "\0")
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($checkpoint['cipher_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('wprism checkpoint recovery: durable intent is malformed');
        }
        return $intent;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $expected */
    private static function assertIdentity(array $current, array $expected): void {
        foreach ($expected as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                throw new \RuntimeException(
                    'wprism checkpoint recovery: an incomplete recovery belongs to a different checkpoint or release'
                );
            }
        }
    }

    /** @param array<string,mixed> $current */
    private static function assertDatabaseTargetHash(array $current, string $databaseTargetSha256): void {
        $databaseTargetSha256 = self::databaseTargetDigest($databaseTargetSha256);
        if (!hash_equals((string) $current['database_target_sha256'], $databaseTargetSha256)) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: configured database target differs from the incomplete recovery'
            );
        }
    }

    private static function databaseTargetDigest(string $databaseTargetSha256): string {
        if (preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1) {
            throw new \RuntimeException(
                'wprism checkpoint recovery: configured database target identity is malformed'
            );
        }
        return $databaseTargetSha256;
    }

    /**
     * @param array<string,mixed> $intent
     * @return array{cipher_sha256:string,database_target_sha256:string,format:string,provider_intent:bool,resumed:bool,schema_intent:bool}
     */
    private static function summary(array $intent, bool $resumed): array {
        return [
            'cipher_sha256' => (string) $intent['checkpoint']['cipher_sha256'],
            'database_target_sha256' => (string) $intent['database_target_sha256'],
            'format' => self::FORMAT,
            'provider_intent' => $intent['provider_intent_sha256'] !== null,
            'resumed' => $resumed,
            'schema_intent' => $intent['schema_intent_sha256'] !== null,
        ];
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
            throw new \RuntimeException('wprism checkpoint recovery: durable control root is unsafe');
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
            'wprism checkpoint recovery: lock path is unsafe',
            'wprism checkpoint recovery: could not open the recovery lock',
            'wprism checkpoint recovery: could not acquire the recovery lock',
            0600
        );
    }
}
