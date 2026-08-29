<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Transport/EnvironmentDriver.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * Target-local identity and one-time operation authorization consumption.
 *
 * State lives under the target checkout's private Git directory, never in the
 * worktree or database. A source fast-forward therefore cannot erase it and a
 * database recovery cannot resurrect a consumed authorization. `mkdir(2)` of
 * one authorization-digest directory is the atomic winner election: exactly
 * one controller may cross the first-mutation boundary, an exact lost-response
 * replay reads the same record, and a directory without its record is reported
 * as ambiguous rather than retried.
 */
final class TargetOperationStore {
    public const TARGET_FORMAT = 'wprism-target-identity/v1';
    public const CONSUMPTION_FORMAT = 'wprism-authorization-consumption/v1';
    public const COMPLETION_FORMAT = 'wprism-authorized-operation-completion/v1';

    private const DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/D';
    private const TARGET_PATTERN = '/^wprism-target:[a-f0-9]{64}$/D';

    /**
     * Establish the stable target identity. This is a staging/adoption write,
     * never a prepare write. Exact retries return the original identity.
     */
    public static function ensureIdentity(EnvironmentDriver $driver): string {
        $root = self::controlRoot($driver);
        $script = <<<'PHP'
$root = $argv[1] ?? '';
if ($root === '' || $root[0] !== '/') { fwrite(STDERR, "root\n"); exit(20); }
if ((file_exists($root) || is_link($root)) && (!is_dir($root) || is_link($root))) {
    fwrite(STDERR, "root-type\n"); exit(21);
}
if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
    fwrite(STDERR, "root-create\n"); exit(22);
}
@chmod($root, 0700);
$lock = @fopen($root . '/identity.lock', 'c');
if (!is_resource($lock) || !@flock($lock, LOCK_EX)) { fwrite(STDERR, "lock\n"); exit(23); }
$path = $root . '/target-id';
if (is_link($path) || (file_exists($path) && !is_file($path))) {
    fwrite(STDERR, "identity-type\n"); exit(24);
}
$bytes = is_file($path) ? @file_get_contents($path) : false;
if ($bytes === false) {
    $id = 'wprism-target:' . bin2hex(random_bytes(32));
    $temporary = @tempnam($root, '.target-id-');
    $handle = is_string($temporary) ? @fopen($temporary, 'r+b') : false;
    $identityBytes = $id . "\n";
    if (!is_resource($handle)
        || @fwrite($handle, $identityBytes) !== strlen($identityBytes)
        || !@fflush($handle)
        || !function_exists('fsync')
        || !@fsync($handle)
        || !@fclose($handle)
        || !@chmod($temporary, 0600)
        || !@rename($temporary, $path)) {
        if (is_resource($handle)) @fclose($handle);
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        fwrite(STDERR, "identity-write\n"); exit(25);
    }
    foreach ([$root, dirname($root)] as $syncDirectory) {
        $sync = @fopen($syncDirectory, 'rb');
        if (!is_resource($sync) || !@fsync($sync) || !@fclose($sync)) {
            if (is_resource($sync)) @fclose($sync);
            fwrite(STDERR, "identity-sync\n"); exit(25);
        }
    }
    $bytes = $id . "\n";
}
$id = substr($bytes, -1) === "\n" ? substr($bytes, 0, -1) : '';
if (preg_match('/^wprism-target:[a-f0-9]{64}$/D', $id) !== 1) {
    fwrite(STDERR, "identity-invalid\n"); exit(26);
}
echo $id . "\n";
PHP;
        $result = $driver->captureRaw(self::php($script, [$root]));

        return self::identityResult($result, 'establish');
    }

    /** Read the stable identity without creating any target byte. */
    public static function readIdentity(EnvironmentDriver $driver): string {
        $root = self::controlRoot($driver);
        $script = <<<'PHP'
$path = ($argv[1] ?? '') . '/target-id';
if (is_link($path) || !is_file($path)) { fwrite(STDERR, "identity-missing\n"); exit(20); }
$bytes = @file_get_contents($path);
if (!is_string($bytes)) { fwrite(STDERR, "identity-read\n"); exit(21); }
$id = substr($bytes, -1) === "\n" ? substr($bytes, 0, -1) : '';
if (preg_match('/^wprism-target:[a-f0-9]{64}$/D', $id) !== 1) {
    fwrite(STDERR, "identity-invalid\n"); exit(22);
}
echo $id . "\n";
PHP;
        $result = $driver->captureRaw(self::php($script, [$root]));

        return self::identityResult($result, 'read');
    }

    /**
     * Atomically consume one already-verified signed authorization.
     *
     * @param array{actor:string,authorization_digest:string,expires_at:string,key_id:string,nonce:string,operation:string,operation_id:string,presentation_digest:string,subject_digest:string,target_id:string} $authorization
     * @return array{consumption:array<string,mixed>,replayed:bool}
     */
    public static function consume(
        EnvironmentDriver $driver,
        array $authorization
    ): array {
        self::validateVerifiedAuthorization($authorization);
        $targetId = self::readIdentity($driver);
        if (!hash_equals($targetId, $authorization['target_id'])) {
            throw self::refuse(
                'authorization_target_mismatch',
                'the signed authorization names a different stable target identity',
                'prepare and authorize the operation against the target being executed'
            );
        }
        $consumption = [
            'actor' => $authorization['actor'],
            'authorization_digest' => $authorization['authorization_digest'],
            'expires_at' => $authorization['expires_at'],
            'format' => self::CONSUMPTION_FORMAT,
            'key_id' => $authorization['key_id'],
            'nonce' => $authorization['nonce'],
            'operation' => $authorization['operation'],
            'operation_id' => $authorization['operation_id'],
            'presentation_digest' => $authorization['presentation_digest'],
            'subject_digest' => $authorization['subject_digest'],
            'target_id' => $authorization['target_id'],
        ];
        $consumption['consumption_digest'] = self::documentDigest($consumption, 'consumption_digest');
        $consumption = Canon::normalize($consumption);
        $bytes = Canon::encode($consumption);
        $root = self::controlRoot($driver);
        $hex = substr($authorization['authorization_digest'], 7);

        $script = <<<'PHP'
$root = $argv[1] ?? '';
$hex = $argv[2] ?? '';
$expected = base64_decode($argv[3] ?? '', true);
$expires = $argv[4] ?? '';
$expectedTarget = $argv[5] ?? '';
if ($root === '' || $root[0] !== '/' || preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1
    || !is_string($expected) || $expected === ''
    || preg_match('/^wprism-target:[a-f0-9]{64}$/D', $expectedTarget) !== 1) {
    fwrite(STDERR, "input\n"); exit(20);
}
$expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $expires, new DateTimeZone('UTC'));
if (!$expiry instanceof DateTimeImmutable || $expiry->format('Y-m-d\TH:i:s\Z') !== $expires) {
    fwrite(STDERR, "expiry-shape\n"); exit(21);
}
$identityLock = @fopen($root . '/identity.lock', 'c');
if (!is_resource($identityLock) || !@flock($identityLock, LOCK_SH)) {
    fwrite(STDERR, "identity-lock\n"); exit(30);
}
$identityPath = $root . '/target-id';
if (is_link($identityPath) || !is_file($identityPath)) {
    fwrite(STDERR, "target-mismatch\n"); exit(31);
}
$identityBytes = @file_get_contents($identityPath);
if (!is_string($identityBytes) || !hash_equals($expectedTarget . "\n", $identityBytes)) {
    fwrite(STDERR, "target-mismatch\n"); exit(31);
}
$authorizations = $root . '/authorizations';
if ((file_exists($authorizations) || is_link($authorizations))
    && (!is_dir($authorizations) || is_link($authorizations))) {
    fwrite(STDERR, "store-type\n"); exit(23);
}
$directory = $authorizations . '/' . $hex;
$replay = static function (string $directory, string $expected): never {
    if (!is_dir($directory) || is_link($directory)) { fwrite(STDERR, "record-type\n"); exit(26); }
    $path = $directory . '/consumption.json';
    if (is_link($path) || !is_file($path)) { fwrite(STDERR, "record-uncertain\n"); exit(27); }
    $actual = @file_get_contents($path);
    if (!is_string($actual)) { fwrite(STDERR, "record-unreadable\n"); exit(28); }
    if (!hash_equals($expected, $actual)) { fwrite(STDERR, "record-conflict\n"); exit(29); }
    echo "replay\n"; exit(0);
};
if (file_exists($directory) || is_link($directory)) {
    $replay($directory, $expected);
}
if ($expiry->getTimestamp() <= time()) { fwrite(STDERR, "expired\n"); exit(22); }
if (!is_dir($authorizations) && !@mkdir($authorizations, 0700, true) && !is_dir($authorizations)) {
    fwrite(STDERR, "store-create\n"); exit(24);
}
@chmod($authorizations, 0700);
// The expiry check immediately before winner election is the target clock's
// mutation boundary. An existing exact record remains readable after expiry.
if ($expiry->getTimestamp() <= time()) { fwrite(STDERR, "expired\n"); exit(22); }
if (@mkdir($directory, 0700)) {
    @chmod($directory, 0700);
    $temporary = @tempnam($directory, '.consumption-');
    $handle = is_string($temporary) ? @fopen($temporary, 'r+b') : false;
    if (!is_resource($handle)
        || @fwrite($handle, $expected) !== strlen($expected)
        || !@fflush($handle)
        || !function_exists('fsync')
        || !@fsync($handle)
        || !@fclose($handle)
        || !@chmod($temporary, 0600)
        || !@rename($temporary, $directory . '/consumption.json')) {
        if (is_resource($handle)) @fclose($handle);
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        fwrite(STDERR, "publish-uncertain\n"); exit(25);
    }
    foreach ([$directory, $authorizations, $root] as $syncDirectory) {
        $sync = @fopen($syncDirectory, 'rb');
        if (!is_resource($sync) || !@fsync($sync) || !@fclose($sync)) {
            if (is_resource($sync)) @fclose($sync);
            fwrite(STDERR, "publish-uncertain\n"); exit(25);
        }
    }
    $readback = @file_get_contents($directory . '/consumption.json');
    if (!is_string($readback) || !hash_equals($expected, $readback)) {
        fwrite(STDERR, "publish-uncertain\n"); exit(25);
    }
    echo "first\n"; exit(0);
}
$replay($directory, $expected);
PHP;
        $result = $driver->captureRaw(self::php($script, [
            $root,
            $hex,
            base64_encode($bytes),
            $authorization['expires_at'],
            $authorization['target_id'],
        ]));
        $status = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) !== 0 || !in_array($status, ['first', 'replay'], true)) {
            $detail = trim((string) ($result['stderr'] ?? ''));
            $code = match (true) {
                str_contains($detail, 'target-mismatch') => 'authorization_target_mismatch',
                str_contains($detail, 'expired') => 'authorization_expired',
                str_contains($detail, 'conflict') => 'authorization_consumption_conflict',
                default => 'authorization_consumption_uncertain',
            };
            throw self::refuse(
                $code,
                match ($code) {
                    'authorization_target_mismatch' =>
                        'the stable target identity changed before authorization consumption',
                    'authorization_expired' =>
                        'the signed authorization expired before durable target-side consumption',
                    'authorization_consumption_conflict' =>
                        'the target already holds different bytes under this authorization identity',
                    default => 'the target cannot prove whether this authorization was durably consumed',
                },
                match ($code) {
                    'authorization_target_mismatch' =>
                        'do not mutate; prepare and authorize again against the stable target identity in force now',
                    'authorization_expired' => 'obtain a fresh authorization for the unchanged subject',
                    default => 'do not retry mutation; reconcile this exact operation and inspect target control evidence',
                }
            );
        }

        return ['consumption' => $consumption, 'replayed' => $status === 'replay'];
    }

    /**
     * Publish one terminal outcome beside its consumption. Exact retry is a
     * no-op; a different outcome under the same authority is a conflict.
     *
     * @param array<string,mixed> $consumption
     * @param array<string,mixed> $outcome
     * @return array{completion:array<string,mixed>,replayed:bool}
     */
    public static function complete(
        EnvironmentDriver $driver,
        array $consumption,
        array $outcome
    ): array {
        self::validateConsumption($consumption);
        $completion = [
            'authorization_digest' => $consumption['authorization_digest'],
            'format' => self::COMPLETION_FORMAT,
            'operation' => $consumption['operation'],
            'operation_id' => $consumption['operation_id'],
            'outcome' => $outcome,
            'outcome_digest' => self::digest(Canon::encode($outcome)),
            'subject_digest' => $consumption['subject_digest'],
            'target_id' => $consumption['target_id'],
        ];
        $completion['completion_digest'] = self::documentDigest($completion, 'completion_digest');
        $completion = Canon::normalize($completion);
        $bytes = Canon::encode($completion);
        $consumptionBytes = Canon::encode($consumption);
        $root = self::controlRoot($driver);
        $hex = substr((string) $consumption['authorization_digest'], 7);
        $script = <<<'PHP'
$directory = ($argv[1] ?? '') . '/authorizations/' . ($argv[2] ?? '');
$expected = base64_decode($argv[3] ?? '', true);
$expectedConsumption = base64_decode($argv[4] ?? '', true);
if (!is_dir($directory) || is_link($directory) || !is_string($expected) || $expected === ''
    || !is_string($expectedConsumption) || $expectedConsumption === '') {
    fwrite(STDERR, "consumption-missing\n"); exit(20);
}
$consumption = $directory . '/consumption.json';
if (is_link($consumption) || !is_file($consumption)) { fwrite(STDERR, "consumption-uncertain\n"); exit(21); }
$actualConsumption = @file_get_contents($consumption);
if (!is_string($actualConsumption)) { fwrite(STDERR, "consumption-read\n"); exit(22); }
if (!hash_equals($expectedConsumption, $actualConsumption)) {
    fwrite(STDERR, "consumption-conflict\n"); exit(23);
}
$publication = $directory . '/completion';
$path = $publication . '/outcome.json';
if (@mkdir($publication, 0700)) {
    @chmod($publication, 0700);
    $temporary = @tempnam($publication, '.outcome-');
    $handle = is_string($temporary) ? @fopen($temporary, 'r+b') : false;
    if (!is_resource($handle)
        || @fwrite($handle, $expected) !== strlen($expected)
        || !@fflush($handle)
        || !function_exists('fsync')
        || !@fsync($handle)
        || !@fclose($handle)
        || !@chmod($temporary, 0600)
        || !@rename($temporary, $path)) {
        if (is_resource($handle)) @fclose($handle);
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        fwrite(STDERR, "outcome-uncertain\n"); exit(27);
    }
    foreach ([$publication, $directory] as $syncDirectory) {
        $sync = @fopen($syncDirectory, 'rb');
        if (!is_resource($sync) || !@fsync($sync) || !@fclose($sync)) {
            if (is_resource($sync)) @fclose($sync);
            fwrite(STDERR, "outcome-uncertain\n"); exit(27);
        }
    }
    $readback = @file_get_contents($path);
    if (!is_string($readback) || !hash_equals($expected, $readback)) {
        fwrite(STDERR, "outcome-uncertain\n"); exit(27);
    }
    echo "first\n"; exit(0);
}
if (!is_dir($publication) || is_link($publication)) { fwrite(STDERR, "outcome-type\n"); exit(24); }
if (file_exists($path) || is_link($path)) {
    if (is_link($path) || !is_file($path)) { fwrite(STDERR, "outcome-type\n"); exit(24); }
    $actual = @file_get_contents($path);
    if (!is_string($actual)) { fwrite(STDERR, "outcome-read\n"); exit(25); }
    if (!hash_equals($expected, $actual)) { fwrite(STDERR, "outcome-conflict\n"); exit(26); }
    echo "replay\n"; exit(0);
}
fwrite(STDERR, "outcome-uncertain\n"); exit(27);
PHP;
        $result = $driver->captureRaw(self::php(
            $script,
            [$root, $hex, base64_encode($bytes), base64_encode($consumptionBytes)]
        ));
        $status = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) !== 0 || !in_array($status, ['first', 'replay'], true)) {
            $detail = (string) ($result['stderr'] ?? '');
            $consumptionConflict = str_contains($detail, 'consumption-conflict');
            $outcomeConflict = str_contains($detail, 'outcome-conflict');
            throw self::refuse(
                $consumptionConflict
                    ? 'authorization_consumption_conflict'
                    : ($outcomeConflict ? 'authorized_operation_outcome_conflict' : 'authorized_operation_outcome_uncertain'),
                $consumptionConflict
                    ? 'the supplied consumption does not match the target\'s durable authorization record'
                    : ($outcomeConflict
                        ? 'the target already holds a different terminal outcome for this authorized operation'
                        : 'the target cannot prove whether the terminal authorized-operation outcome was published'),
                'do not start another mutation; reconcile the exact operation from target control evidence'
            );
        }

        return ['completion' => $completion, 'replayed' => $status === 'replay'];
    }

    /**
     * Read a consumed operation and optional terminal outcome without writing.
     *
     * @return ?array{consumption:array<string,mixed>,completion:?array<string,mixed>}
     */
    public static function status(EnvironmentDriver $driver, string $authorizationDigest): ?array {
        self::assertDigest($authorizationDigest, 'authorization digest');
        $root = self::controlRoot($driver);
        $hex = substr($authorizationDigest, 7);
        $script = <<<'PHP'
$directory = ($argv[1] ?? '') . '/authorizations/' . ($argv[2] ?? '');
if (!file_exists($directory) && !is_link($directory)) { echo "absent\n"; exit(0); }
if (!is_dir($directory) || is_link($directory)) { fwrite(STDERR, "record-type\n"); exit(20); }
$consumption = $directory . '/consumption.json';
if (is_link($consumption) || !is_file($consumption)) { fwrite(STDERR, "record-uncertain\n"); exit(21); }
$consumptionBytes = @file_get_contents($consumption);
if (!is_string($consumptionBytes)) { fwrite(STDERR, "record-read\n"); exit(22); }
$publication = $directory . '/completion';
$outcomeBytes = null;
if (file_exists($publication) || is_link($publication)) {
    if (!is_dir($publication) || is_link($publication)) { fwrite(STDERR, "outcome-type\n"); exit(23); }
    $outcome = $publication . '/outcome.json';
    if (is_link($outcome) || !is_file($outcome)) { fwrite(STDERR, "outcome-uncertain\n"); exit(24); }
    $outcomeBytes = @file_get_contents($outcome);
    if (!is_string($outcomeBytes)) { fwrite(STDERR, "outcome-read\n"); exit(25); }
}
echo base64_encode($consumptionBytes) . "\n";
echo $outcomeBytes === null ? "-\n" : base64_encode($outcomeBytes) . "\n";
PHP;
        $result = $driver->captureRaw(self::php($script, [$root, $hex]));
        if (($result['exit'] ?? 1) !== 0) {
            throw self::refuse(
                'authorization_consumption_uncertain',
                'the target cannot read a complete authorization consumption record',
                'do not retry mutation; reconcile the exact operation from target control evidence'
            );
        }
        $stdout = (string) ($result['stdout'] ?? '');
        if ($stdout === "absent\n") {
            return null;
        }
        $lines = explode("\n", rtrim($stdout, "\n"));
        if (count($lines) !== 2) {
            throw self::statusMalformed();
        }
        $consumption = self::decodeStored($lines[0], self::CONSUMPTION_FORMAT);
        self::validateConsumption($consumption);
        $completion = $lines[1] === '-' ? null : self::decodeStored($lines[1], self::COMPLETION_FORMAT);
        if (is_array($completion)) {
            self::validateCompletion($completion, $consumption);
        }

        return ['completion' => $completion, 'consumption' => $consumption];
    }

    /** @param array<string,mixed> $authorization */
    private static function validateVerifiedAuthorization(array $authorization): void {
        $expected = [
            'actor', 'authorization_digest', 'expires_at', 'key_id', 'nonce', 'operation', 'operation_id',
            'presentation_digest', 'subject_digest', 'target_id',
        ];
        self::closedKeys($authorization, $expected, 'verified authorization');
        foreach (['authorization_digest', 'presentation_digest', 'subject_digest'] as $field) {
            self::assertDigest((string) ($authorization[$field] ?? ''), $field);
        }
        if (!is_string($authorization['target_id'] ?? null)
            || preg_match(self::TARGET_PATTERN, $authorization['target_id']) !== 1) {
            throw self::shape('the verified authorization has no stable target identity');
        }
        foreach (['actor', 'expires_at', 'key_id', 'nonce', 'operation', 'operation_id'] as $field) {
            if (!is_string($authorization[$field] ?? null) || $authorization[$field] === '') {
                throw self::shape("the verified authorization has no $field");
            }
        }
        self::timestamp($authorization['expires_at'], 'authorization expires_at');
    }

    /** @param array<string,mixed> $consumption */
    private static function validateConsumption(array $consumption): void {
        self::closedKeys($consumption, [
            'actor', 'authorization_digest', 'consumption_digest', 'expires_at', 'format', 'key_id',
            'nonce', 'operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id',
        ], 'authorization consumption');
        if (($consumption['format'] ?? null) !== self::CONSUMPTION_FORMAT
            || !is_string($consumption['consumption_digest'] ?? null)
            || !hash_equals(self::documentDigest($consumption, 'consumption_digest'), $consumption['consumption_digest'])) {
            throw self::shape('the authorization consumption record is malformed or has a mismatched digest');
        }
        self::validateVerifiedAuthorization(array_diff_key($consumption, array_flip([
            'consumption_digest', 'format',
        ])));
    }

    /** @param array<string,mixed> $completion @param array<string,mixed> $consumption */
    private static function validateCompletion(array $completion, array $consumption): void {
        self::closedKeys($completion, [
            'authorization_digest', 'completion_digest', 'format', 'operation', 'operation_id', 'outcome',
            'outcome_digest', 'subject_digest', 'target_id',
        ], 'authorized operation completion');
        if (($completion['format'] ?? null) !== self::COMPLETION_FORMAT
            || !is_array($completion['outcome'] ?? null)
            || !is_string($completion['completion_digest'] ?? null)
            || !hash_equals(self::documentDigest($completion, 'completion_digest'), $completion['completion_digest'])
            || !is_string($completion['outcome_digest'] ?? null)
            || !hash_equals(self::digest(Canon::encode($completion['outcome'])), $completion['outcome_digest'])) {
            throw self::shape('the authorized operation completion is malformed or has a mismatched digest');
        }
        foreach (['authorization_digest', 'operation', 'operation_id', 'subject_digest', 'target_id'] as $field) {
            if (!is_string($completion[$field] ?? null)
                || !hash_equals((string) $consumption[$field], (string) $completion[$field])) {
                throw self::shape("the authorized operation completion changed $field");
            }
        }
    }

    /** @return array<string,mixed> */
    private static function decodeStored(string $encoded, string $format): array {
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes)) {
            throw self::statusMalformed();
        }
        $document = json_decode($bytes, true);
        if (!is_array($document) || array_is_list($document) || ($document['format'] ?? null) !== $format
            || !hash_equals(Canon::encode($document), $bytes)) {
            throw self::statusMalformed();
        }

        return $document;
    }

    /** Locate the worktree-private Git directory through the target itself. */
    private static function controlRoot(EnvironmentDriver $driver): string {
        $result = $driver->captureRaw(
            'git -C ' . escapeshellarg(rtrim($driver->repoPath(), '/')) . ' rev-parse --absolute-git-dir'
        );
        $stdout = (string) ($result['stdout'] ?? '');
        $gitDir = substr($stdout, -1) === "\n" ? substr($stdout, 0, -1) : $stdout;
        if (($result['exit'] ?? 1) !== 0
            || $gitDir === '' || $gitDir[0] !== '/' || $gitDir === '/'
            || str_contains($gitDir, "\0") || str_contains($gitDir, "\n") || str_contains($gitDir, "\r")) {
            throw self::refuse(
                'target_control_store_unavailable',
                'the target repository has no unambiguous private Git control directory',
                'repair the target Git checkout before staging or authorizing an operation'
            );
        }

        return rtrim($gitDir, '/') . '/wprism-control';
    }

    /** @param list<string> $arguments */
    private static function php(string $script, array $arguments): string {
        $tokens = ['php', '-r', $script, '--'];
        foreach ($arguments as $argument) {
            $tokens[] = $argument;
        }

        return implode(' ', array_map('escapeshellarg', $tokens));
    }

    /** @param array<string,mixed> $result */
    private static function identityResult(array $result, string $verb): string {
        $stdout = (string) ($result['stdout'] ?? '');
        $identity = substr($stdout, -1) === "\n" ? substr($stdout, 0, -1) : $stdout;
        if (($result['exit'] ?? 1) !== 0 || preg_match(self::TARGET_PATTERN, $identity) !== 1) {
            throw self::refuse(
                'target_identity_unavailable',
                "WPrism could not $verb the target's stable operation identity",
                'repair target Git control-directory ownership and retry before preparing any operation'
            );
        }

        return $identity;
    }

    /** @param array<string,mixed> $document @param list<string> $expected */
    private static function closedKeys(array $document, array $expected, string $label): void {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::shape("the $label does not have its closed key set");
        }
    }

    private static function documentDigest(array $document, string $field): string {
        unset($document[$field]);

        return self::digest(Canon::encode($document));
    }

    private static function digest(string $bytes): string {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private static function assertDigest(string $digest, string $label): void {
        if (preg_match(self::DIGEST_PATTERN, $digest) !== 1) {
            throw self::shape("the $label is not a sha256 digest");
        }
    }

    private static function timestamp(string $value, string $label): int {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw self::shape("the $label is not a canonical UTC-seconds timestamp");
        }

        return $date->getTimestamp();
    }

    private static function statusMalformed(): CommandRefusalException {
        return self::refuse(
            'authorized_operation_status_invalid',
            'the target returned malformed authorized-operation control evidence',
            'do not retry mutation; inspect and reconcile the target control store'
        );
    }

    private static function shape(string $message): CommandRefusalException {
        return self::refuse(
            'authorized_operation_record_invalid',
            $message,
            'do not act on this record; prepare and authorize a fresh operation after reconciling target state'
        );
    }

    private static function refuse(string $code, string $message, string $remediation): CommandRefusalException {
        return new CommandRefusalException($code, $message, $remediation);
    }
}
