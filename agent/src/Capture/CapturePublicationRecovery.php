<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Publication/Publish.php';

/** Destination-scoped database proof for atomic filesystem publication. */
final class CapturePublicationRecovery {
    /** Resolve lexical/symlink variants to one stable destination identity. */
    public static function destinationSha256(string $stateDir): string {
        $parent = realpath(dirname($stateDir));
        if ($parent === false) {
            throw new \RuntimeException(
                'duo: cannot resolve capture publication destination parent: ' . dirname($stateDir)
            );
        }
        return hash('sha256', rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($stateDir));
    }

    /** Destination-scoped marker key stays below duo_kv.k's 191-byte limit. */
    public static function markerKey(string $stateDir): string {
        return 'capture_publication:' . self::destinationSha256($stateDir);
    }

    /** Exact/self-hashed proof written as the transaction's final DML. */
    public static function marker(string $stateDir, array $intent): string {
        $record = [
            'format' => 'duo-capture-commit-marker/v1',
            'state_sha256' => self::destinationSha256($stateDir),
            'intent_id' => (string) ($intent['id'] ?? ''),
            'candidate_sha256' => (string) ($intent['candidate_sha256'] ?? ''),
            'previous_sha256' => (string) ($intent['previous_sha256'] ?? ''),
        ];
        $record['record_sha256'] = hash('sha256', Canon::encode($record));
        return Canon::encode($record);
    }

    /**
     * True only for a valid marker belonging to this exact intent. Missing
     * and prior-run markers are rollback signals; malformed/current mismatch
     * evidence refuses because commit outcome cannot be inferred safely.
     */
    public static function commitStatus(string $stateDir, array $intent): bool {
        $raw = Ledger::kv_get(self::markerKey($stateDir));
        if ($raw === null || $raw === '') {
            return false;
        }
        try {
            $record = Canon::decode($raw);
        } catch (\Throwable $e) {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found a malformed database commit marker; refusing to retry or discard retained evidence',
                $e
            );
        }
        if (!is_array($record)) {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found a non-object database commit marker; refusing to retry or discard retained evidence'
            );
        }
        $expectedKeys = [
            'format',
            'state_sha256',
            'intent_id',
            'candidate_sha256',
            'previous_sha256',
            'record_sha256',
        ];
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected, SORT_STRING);
        if ($keys !== $sortedExpected || ($record['format'] ?? null) !== 'duo-capture-commit-marker/v1') {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found an unsupported database commit marker shape; refusing to retry or discard retained evidence'
            );
        }
        $seal = (string) $record['record_sha256'];
        unset($record['record_sha256']);
        if (!preg_match('/^[a-f0-9]{64}$/', $seal)
            || !hash_equals($seal, hash('sha256', Canon::encode($record)))) {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found a tampered database commit marker; refusing to retry or discard retained evidence'
            );
        }
        if (!is_string($record['state_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $record['state_sha256']) !== 1
            || !is_string($record['intent_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $record['intent_id']) !== 1
            || !is_string($record['candidate_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $record['candidate_sha256']) !== 1
            || !is_string($record['previous_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $record['previous_sha256']) !== 1) {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found malformed database commit marker fields; refusing to retry or discard retained evidence'
            );
        }
        if (($record['state_sha256'] ?? null) !== self::destinationSha256($stateDir)) {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found a commit marker for a different destination; refusing to retry or discard retained evidence'
            );
        }
        if (($record['intent_id'] ?? null) !== ($intent['id'] ?? null)) {
            return false;
        }
        if (($record['candidate_sha256'] ?? null) !== ($intent['candidate_sha256'] ?? null)
            || ($record['previous_sha256'] ?? null) !== ($intent['previous_sha256'] ?? null)) {
            throw CommandRefusalException::ambiguousCaptureRecovery(
                'duo: capture recovery found a current-intent commit marker with mismatched digests; refusing to retry or discard retained evidence'
            );
        }
        return true;
    }

    /** Commit proof is needed only while filesystem recovery artifacts exist. */
    public static function clearIfClean(string $stateDir): void {
        if (is_file(Publish::intent_path($stateDir))
            || is_dir(Publish::stage_dir($stateDir))
            || is_dir(Publish::backup_dir($stateDir))) {
            return;
        }
        $key = self::markerKey($stateDir);
        if (Ledger::kv_get($key) !== null) {
            Ledger::kv_delete($key);
        }
    }
}
