<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/OperationAuthorization.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * Target-local identity and one-time operation authorization consumption.
 *
 * State lives under the target checkout's private Git directory, never in the
 * worktree or database. A source fast-forward therefore cannot erase it and a
 * database recovery cannot resurrect a consumed authorization. One target-wide
 * lock elects both an operation tuple and its exact authorization: a fresh
 * envelope can never repair or repeat an older nonterminal operation, while an
 * exact lost-response replay reads the same record. Any partially published
 * election is ambiguous rather than retried.
 */
final class TargetOperationStore {
    public const TARGET_FORMAT = 'wprism-target-identity/v1';
    public const AUTHORITY_POLICY_SYNC_FORMAT = 'wprism-target-authority-policy-sync/v1';
    public const CONSUMPTION_FORMAT = 'wprism-authorization-consumption/v1';
    public const COMPLETION_FORMAT = 'wprism-authorized-operation-completion/v1';
    public const ELECTION_FORMAT = 'wprism-authorized-operation-election/v1';
    public const PRECONDITION_FORMAT = 'wprism-target-operation-precondition/v1';

    private const DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/D';
    private const TARGET_PATTERN = '/^wprism-target:[a-f0-9]{64}$/D';

    /** Read target-enrolled authority policy without creating control bytes. */
    public static function readAuthorityPolicy(EnvironmentDriver $driver): array {
        $root = self::controlRoot($driver);
        $script = <<<'PHP'
$root = $argv[1] ?? '';
$regular = static function (string $path, bool $directory = false): mixed {
    $before = @lstat($path);
    if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== ($directory ? 0040000 : 0100000)) return false;
    $handle = @fopen($path, 'rb');
    $after = is_resource($handle) ? @fstat($handle) : false;
    if (!is_resource($handle) || !is_array($after) || ($before['dev'] ?? null) !== ($after['dev'] ?? null)
        || ($before['ino'] ?? null) !== ($after['ino'] ?? null)) { if (is_resource($handle)) @fclose($handle); return false; }
    return $handle;
};
if ($root === '' || $root[0] !== '/' || !is_array(@lstat($root)) || is_link($root)) { fwrite(STDERR, "root\n"); exit(20); }
$lock = $regular($root . '/authority.lock');
if (!is_resource($lock) || !@flock($lock, LOCK_SH)) { fwrite(STDERR, "lock\n"); exit(21); }
$lockStat = @fstat($lock); $lockPathStat = @lstat($root . '/authority.lock');
if (!is_array($lockStat) || !is_array($lockPathStat)
    || (($lockPathStat['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockStat['dev'] ?? null) !== ($lockPathStat['dev'] ?? null)
    || ($lockStat['ino'] ?? null) !== ($lockPathStat['ino'] ?? null)) { fwrite(STDERR, "lock\n"); exit(21); }
$policy = $regular($root . '/authority-policy.json');
if (!is_resource($policy)) { fwrite(STDERR, "missing\n"); exit(22); }
$bytes = @stream_get_contents($policy);
if (!is_string($bytes) || $bytes === '') { fwrite(STDERR, "read\n"); exit(23); }
$lockStat = @fstat($lock); $lockPathStat = @lstat($root . '/authority.lock');
if (!is_array($lockStat) || !is_array($lockPathStat)
    || (($lockPathStat['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockStat['dev'] ?? null) !== ($lockPathStat['dev'] ?? null)
    || ($lockStat['ino'] ?? null) !== ($lockPathStat['ino'] ?? null)) { fwrite(STDERR, "lock\n"); exit(21); }
echo base64_encode($bytes) . "\n";
PHP;
        $result = $driver->captureRaw(self::php($script, [$root]));
        if (($result['exit'] ?? 1) !== 0) {
            throw self::refuse('target_authority_policy_unavailable', 'the target has no readable enrolled operation-authority policy', 'explicitly sync the intended authority policy to this target before preparing or executing an operation');
        }
        $bytes = base64_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (!is_string($bytes)) throw self::refuse('target_authority_policy_invalid', 'the target returned malformed operation-authority policy bytes', 'inspect and reconcile the target authority control store before authorizing an operation');
        return self::policyFromBytes($bytes);
    }

    /** Explicit CAS policy enrollment; operation execution never creates policy. */
    public static function syncAuthorityPolicy(EnvironmentDriver $driver, array $policy, string $expectedCurrent): array {
        OperationAuthorization::validateTrust($policy);
        if ($expectedCurrent !== 'absent' && preg_match(self::DIGEST_PATTERN, $expectedCurrent) !== 1) {
            throw self::refuse('target_authority_policy_expected_invalid', 'the expected target authority-policy identity is neither absent nor a sha256 digest', 'read target authority-policy status and repeat the explicit sync with its exact current identity');
        }
        $targetId = self::ensureIdentity($driver);
        $bytes = Canon::encode($policy);
        $digest = OperationAuthorization::trustDigest($policy);
        $root = self::controlRoot($driver);
        $script = <<<'PHP'
$root=$argv[1]??''; $expected=$argv[2]??''; $nextDigest=$argv[3]??''; $next=base64_decode($argv[4]??'',true); $target=$argv[5]??'';
if ($root==='' || $root[0]!=='/' || ($expected!=='absent' && preg_match('/^sha256:[a-f0-9]{64}$/D',$expected)!==1) || preg_match('/^sha256:[a-f0-9]{64}$/D',$nextDigest)!==1 || !is_string($next) || $next==='' || !hash_equals($nextDigest,'sha256:'.hash('sha256',$next)) || preg_match('/^wprism-target:[a-f0-9]{64}$/D',$target)!==1) { fwrite(STDERR,"input\n"); exit(20); }
$open = static function(string $path, string $mode): mixed { $before=@lstat($path); $handle=@fopen($path,$mode); $after=is_resource($handle)?@fstat($handle):false; if (!is_array($before)||!is_resource($handle)||!is_array($after)||(($before['mode']??0)&0170000)!==0100000||($before['dev']??null)!==($after['dev']??null)||($before['ino']??null)!==($after['ino']??null)) { if(is_resource($handle))@fclose($handle); return false; } return $handle; };
if (!is_dir($root) || is_link($root)) { fwrite(STDERR,"root-type\n"); exit(21); }
$lockPath=$root.'/authority.lock';
if (!file_exists($lockPath)) { $created=@fopen($lockPath,'x+b'); if (!is_resource($created) || !@fflush($created) || !function_exists('fsync') || !@fsync($created) || !@fclose($created) || !@chmod($lockPath,0600)) { if(is_resource($created))@fclose($created); fwrite(STDERR,"lock-create\n"); exit(21); } }
$bound=static function($handle,string $path):bool{$held=@fstat($handle);$named=@lstat($path);return is_array($held)&&is_array($named)&&(($named['mode']??0)&0170000)===0100000&&($held['dev']??null)===($named['dev']??null)&&($held['ino']??null)===($named['ino']??null);};
$lock=$open($lockPath,'r+b'); if(!is_resource($lock)||!@flock($lock,LOCK_EX)||!$bound($lock,$lockPath)){fwrite(STDERR,"lock\n");exit(21);}
$identityPath=$root.'/identity.lock';$identity=$open($identityPath,'rb'); if(!is_resource($identity)||!@flock($identity,LOCK_SH)||!$bound($identity,$identityPath)){fwrite(STDERR,"identity-lock\n");exit(22);}
$targetPath=$root.'/target-id';$targetHandle=$open($targetPath,'rb');$identityBytes=is_resource($targetHandle)?@stream_get_contents($targetHandle):false;if(is_resource($targetHandle))@fclose($targetHandle);if(!is_string($identityBytes)||!$bound($lock,$lockPath)||!$bound($identity,$identityPath)||!hash_equals($target."\n",$identityBytes)){fwrite(STDERR,"target-mismatch\n");exit(23);}
$path=$root.'/authority-policy.json'; if(is_link($path)||(file_exists($path)&&!is_file($path))){fwrite(STDERR,"policy-type\n");exit(24);} $currentBytes=is_file($path)?@file_get_contents($path):false; if($currentBytes!==false&&!is_string($currentBytes)){fwrite(STDERR,"policy-read\n");exit(25);} if(!$bound($lock,$lockPath)||!$bound($identity,$identityPath)){fwrite(STDERR,"lock\n");exit(21);} $current=is_string($currentBytes)?'sha256:'.hash('sha256',$currentBytes):'absent';
if(is_string($currentBytes)&&hash_equals($next,$currentBytes)){echo "replay $current\n";exit(0);} if(!hash_equals($expected,$current)){fwrite(STDERR,"policy-conflict\n");exit(26);} if(!$bound($lock,$lockPath)||!$bound($identity,$identityPath)){fwrite(STDERR,"lock\n");exit(21);} $temporary=@tempnam($root,'.authority-policy-');$handle=is_string($temporary)?@fopen($temporary,'r+b'):false; if(!is_resource($handle)||@fwrite($handle,$next)!==strlen($next)||!@fflush($handle)||!function_exists('fsync')||!@fsync($handle)||!@fclose($handle)||!@chmod($temporary,0600)||!@rename($temporary,$path)){if(is_resource($handle))@fclose($handle);if(is_string($temporary)&&is_file($temporary))@unlink($temporary);fwrite(STDERR,"policy-write\n");exit(27);} $sync=@fopen($root,'rb');if(!is_resource($sync)||!@fsync($sync)||!@fclose($sync)){fwrite(STDERR,"policy-sync\n");exit(27);} $readback=@file_get_contents($path);if(!is_string($readback)||!hash_equals($next,$readback)){fwrite(STDERR,"policy-readback\n");exit(27);} echo "first $current\n";
PHP;
        $result = $driver->captureRaw(self::php($script, [$root, $expectedCurrent, $digest, base64_encode($bytes), $targetId]));
        $stdout = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) !== 0 || preg_match('/^(first|replay) (absent|sha256:[a-f0-9]{64})$/D', $stdout, $match) !== 1) {
            $detail = trim((string) ($result['stderr'] ?? ''));
            $code = str_contains($detail, 'policy-conflict') ? 'target_authority_policy_conflict' : (str_contains($detail, 'target-mismatch') ? 'authorization_target_mismatch' : 'target_authority_policy_sync_uncertain');
            throw self::refuse($code, $code === 'target_authority_policy_conflict' ? 'the target authority policy changed from the explicitly expected identity' : ($code === 'authorization_target_mismatch' ? 'the target identity changed during authority-policy sync' : 'the target cannot prove whether the authority policy was durably synchronized'), $code === 'target_authority_policy_conflict' ? 'read target authority-policy status, review the current policy and retry with its exact digest' : 'do not authorize mutation; inspect and reconcile target authority-control evidence');
        }
        return Canon::normalize(['format' => self::AUTHORITY_POLICY_SYNC_FORMAT,'policy_digest' => $digest,'previous_policy_digest' => $match[2] === 'absent' ? null : $match[2],'replayed' => $match[1] === 'replay','target_id' => $targetId]);
    }

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
$rootStat = @lstat($root);
if (!is_array($rootStat) || (($rootStat['mode'] ?? 0) & 0170000) !== 0040000) {
    fwrite(STDERR, "root-type\n"); exit(21);
}
$lockPath = $root . '/identity.lock';
$lockBefore = @lstat($lockPath);
if (is_array($lockBefore) && (($lockBefore['mode'] ?? 0) & 0170000) !== 0100000) {
    fwrite(STDERR, "lock\n"); exit(23);
}
$lock = @fopen($lockPath, 'c');
$lockAfter = is_resource($lock) ? @fstat($lock) : false;
if (!is_array($lockAfter) || (($lockAfter['mode'] ?? 0) & 0170000) !== 0100000
    || (is_array($lockBefore) && (($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
        || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)))) {
    if (is_resource($lock)) @fclose($lock);
    fwrite(STDERR, "lock\n"); exit(23);
}
if (!is_resource($lock) || !@flock($lock, LOCK_EX)) { fwrite(STDERR, "lock\n"); exit(23); }
$lockNamed = @lstat($lockPath); $lockHeld = @fstat($lock);
if (!is_array($lockNamed) || !is_array($lockHeld)
    || (($lockNamed['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockNamed['dev'] ?? null) !== ($lockHeld['dev'] ?? null)
    || ($lockNamed['ino'] ?? null) !== ($lockHeld['ino'] ?? null)) { fwrite(STDERR, "lock\n"); exit(23); }
$path = $root . '/target-id';
if (is_link($path) || (file_exists($path) && !is_file($path))) {
    fwrite(STDERR, "identity-type\n"); exit(24);
}
$bytes = is_file($path) ? @file_get_contents($path) : false;
if ($bytes === false) {
    $lockNamed = @lstat($lockPath); $lockHeld = @fstat($lock);
    if (!is_array($lockNamed) || !is_array($lockHeld)
        || (($lockNamed['mode'] ?? 0) & 0170000) !== 0100000
        || ($lockNamed['dev'] ?? null) !== ($lockHeld['dev'] ?? null)
        || ($lockNamed['ino'] ?? null) !== ($lockHeld['ino'] ?? null)) { fwrite(STDERR, "lock\n"); exit(23); }
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
$root = $argv[1] ?? '';
$rootStat = $root !== '' ? @lstat($root) : false;
if (!is_array($rootStat) || (($rootStat['mode'] ?? 0) & 0170000) !== 0040000) {
    fwrite(STDERR, "root-type\n"); exit(20);
}
$lockPath = $root . '/identity.lock';
$lockBefore = @lstat($lockPath); $lock = @fopen($lockPath, 'rb'); $lockAfter = is_resource($lock) ? @fstat($lock) : false;
if (!is_resource($lock) || !is_array($lockBefore) || !is_array($lockAfter)
    || (($lockBefore['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
    || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)
    || !@flock($lock, LOCK_SH)) { if (is_resource($lock)) @fclose($lock); fwrite(STDERR, "identity-lock\n"); exit(20); }
$lockNamed = @lstat($lockPath); $lockHeld = @fstat($lock);
if (!is_array($lockNamed) || !is_array($lockHeld)
    || (($lockNamed['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockNamed['dev'] ?? null) !== ($lockHeld['dev'] ?? null)
    || ($lockNamed['ino'] ?? null) !== ($lockHeld['ino'] ?? null)) { fwrite(STDERR, "identity-lock\n"); exit(20); }
$path = $root . '/target-id';
$before = @lstat($path); $handle = @fopen($path, 'rb'); $after = is_resource($handle) ? @fstat($handle) : false;
$bytes = is_resource($handle) ? @stream_get_contents($handle) : false; if (is_resource($handle)) @fclose($handle); $named = @lstat($path);
if (!is_array($before) || !is_array($after) || !is_array($named)
    || (($before['mode'] ?? 0) & 0170000) !== 0100000
    || ($before['dev'] ?? null) !== ($after['dev'] ?? null) || ($before['ino'] ?? null) !== ($after['ino'] ?? null)
    || ($before['dev'] ?? null) !== ($named['dev'] ?? null) || ($before['ino'] ?? null) !== ($named['ino'] ?? null)) { fwrite(STDERR, "identity-missing\n"); exit(20); }
if (!is_string($bytes)) { fwrite(STDERR, "identity-read\n"); exit(21); }
$lockNamed = @lstat($lockPath); $lockHeld = @fstat($lock);
if (!is_array($lockNamed) || !is_array($lockHeld)
    || (($lockNamed['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockNamed['dev'] ?? null) !== ($lockHeld['dev'] ?? null)
    || ($lockNamed['ino'] ?? null) !== ($lockHeld['ino'] ?? null)) { fwrite(STDERR, "identity-lock\n"); exit(20); }
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
     * @param array<string,mixed> $envelope exact signed envelope
     * @param array<string,mixed> $subject immutable authorization subject projection
     * @param ?array{files:list<array{bytes:?int,path:string,sha256:string}>,format:string,locks:list<string>,not_after:string,ordered_file_hashes:list<array{path:string,sha256:string}>,repository_head:string} $precondition
     * @return array{consumption:array<string,mixed>,replayed:bool}
     */
    public static function consume(
        EnvironmentDriver $driver,
        array $authorization,
        array $envelope,
        array $subject,
        ?array $precondition = null
    ): array {
        self::validateVerifiedAuthorization($authorization);
        OperationAuthorization::validateEnvelopeShape($envelope);
        if (!hash_equals($authorization['authorization_digest'], OperationAuthorization::envelopeDigest($envelope))) {
            throw self::shape('the verified authorization does not match the complete signed envelope');
        }
        self::closedKeys($subject, ['authority_policy_digest', 'operation', 'operation_id', 'presentation_digest', 'required_grants', 'subject_digest', 'target_id'], 'authorization subject');
        foreach (['authority_policy_digest', 'presentation_digest', 'subject_digest'] as $field) self::assertDigest((string) ($subject[$field] ?? ''), $field);
        foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
            if (!is_string($subject[$field] ?? null) || !hash_equals((string) $authorization[$field], $subject[$field])) throw self::shape("the verified authorization does not match subject $field");
        }
        if (!is_array($subject['required_grants'] ?? null) || !array_is_list($subject['required_grants'])) throw self::shape('the authorization subject has no required-grants list');
        if ($precondition !== null) self::validatePrecondition($precondition);
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
        $tuple = self::operationTuple($authorization);
        $tupleDigest = self::digest(Canon::encode($tuple));
        $preconditionDigest = $precondition === null ? null : self::digest(Canon::encode($precondition));
        $election = $tuple + [
            'authorization_digest' => $authorization['authorization_digest'],
            'format' => self::ELECTION_FORMAT,
            'precondition_digest' => $preconditionDigest,
            'tuple_digest' => $tupleDigest,
        ];
        $election['election_digest'] = self::documentDigest($election, 'election_digest');
        $election = Canon::normalize($election);
        $electionBytes = Canon::encode($election);
        $root = self::controlRoot($driver);
        $hex = substr($authorization['authorization_digest'], 7);
        $tupleHex = substr($tupleDigest, 7);
        $preconditionBytes = $precondition === null ? '' : Canon::encode($precondition);
        $envelopeBytes = Canon::encode($envelope);
        $subjectBytes = Canon::encode($subject);

        $script = <<<'PHP'
$root = $argv[1] ?? '';
$repo = $argv[2] ?? '';
$hex = $argv[3] ?? '';
$tupleHex = $argv[4] ?? '';
$expected = base64_decode($argv[5] ?? '', true);
$expectedElection = base64_decode($argv[6] ?? '', true);
$expires = $argv[7] ?? '';
$targetId = $argv[8] ?? '';
$preconditionRaw = base64_decode($argv[9] ?? '', true);
$preconditionFormat = $argv[10] ?? '';
$electionFormat = $argv[11] ?? '';
$envelopeBytes = base64_decode($argv[12] ?? '', true);
$subjectBytes = base64_decode($argv[13] ?? '', true);
if ($root === '' || $root[0] !== '/' || $repo === '' || $repo[0] !== '/'
    || preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1
    || preg_match('/^[a-f0-9]{64}$/D', $tupleHex) !== 1
    || preg_match('/^wprism-target:[a-f0-9]{64}$/D', $targetId) !== 1
    || !is_string($expected) || $expected === ''
    || !is_string($expectedElection) || $expectedElection === ''
    || !is_string($preconditionRaw) || !is_string($envelopeBytes) || $envelopeBytes === ''
    || !is_string($subjectBytes) || $subjectBytes === '') { fwrite(STDERR, "input\n"); exit(20); }
$expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $expires, new DateTimeZone('UTC'));
if (!$expiry instanceof DateTimeImmutable || $expiry->format('Y-m-d\TH:i:s\Z') !== $expires) {
    fwrite(STDERR, "expiry-shape\n"); exit(21);
}
$expectedDocument = json_decode($expected, true);
$expectedElectionDocument = json_decode($expectedElection, true);
if (!is_array($expectedDocument) || !is_array($expectedElectionDocument)
    || ($expectedDocument['authorization_digest'] ?? '') !== 'sha256:' . $hex
    || ($expectedElectionDocument['authorization_digest'] ?? '') !== 'sha256:' . $hex
    || ($expectedElectionDocument['tuple_digest'] ?? '') !== 'sha256:' . $tupleHex) {
    fwrite(STDERR, "input-document\n"); exit(20);
}
$tupleMatches = static function (array $document) use ($expectedElectionDocument): bool {
    foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
        if (!is_string($document[$field] ?? null)
            || !hash_equals((string) $expectedElectionDocument[$field], (string) $document[$field])) return false;
    }
    return true;
};
$precondition = null;
$lockPaths = [];
if ($preconditionRaw !== '') {
    $precondition = json_decode($preconditionRaw, true);
    if (!is_array($precondition)
        || ($precondition['format'] ?? '') !== $preconditionFormat
        || !is_array($precondition['locks'] ?? null)
        || !is_array($precondition['files'] ?? null)
        || !is_array($precondition['ordered_file_hashes'] ?? null)) {
        fwrite(STDERR, "precondition-shape\n"); exit(30);
    }
    $lockPaths = $precondition['locks'];
}
$safeRelative = static function (mixed $path): bool {
    if (!is_string($path) || $path === '' || $path[0] === '/' || str_contains($path, "\0")) return false;
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..'
            || preg_match('/^[A-Za-z0-9._-]+$/D', $part) !== 1) return false;
    }
    return true;
};
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map($canonicalize, $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalEncode = static function (array $value) use ($canonicalize): string {
    $encoded = json_encode($canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded . "\n" : '';
};
$regularPath = static function (string $path, bool $directory = false, ?string $base = null): bool {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) return false;
    if ($base === null) {
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === ($directory ? 0040000 : 0100000);
    }
    $base = rtrim($base, '/');
    $baseStat = @lstat($base);
    if (!is_array($baseStat) || (($baseStat['mode'] ?? 0) & 0170000) !== 0040000
        || !str_starts_with($path, $base . '/')) return false;
    $current = $base;
    $parts = explode('/', substr($path, strlen($base) + 1));
    foreach ($parts as $index => $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
        $current .= '/' . $part;
        $stat = @lstat($current);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) === 0120000) return false;
        $last = $index === count($parts) - 1;
        $expectedType = $last && $directory ? 0040000 : ($last ? 0100000 : 0040000);
        if (($stat['mode'] & 0170000) !== $expectedType) return false;
    }
    return true;
};
$openRegular = static function (string $path, string $mode, ?string $base = null) use ($regularPath): mixed {
    if (!$regularPath($path, false, $base)) return false;
    $before = @lstat($path);
    $handle = @fopen($path, $mode);
    $after = is_resource($handle) ? @fstat($handle) : false;
    if (!is_array($before) || !is_array($after)
        || ($before['dev'] ?? null) !== ($after['dev'] ?? null)
        || ($before['ino'] ?? null) !== ($after['ino'] ?? null)
        || (($after['mode'] ?? 0) & 0170000) !== 0100000) {
        if (is_resource($handle)) @fclose($handle);
        return false;
    }
    return [$handle, $before];
};
$lockBound = static function (mixed $handle, string $path): bool {
    $held = is_resource($handle) ? @fstat($handle) : false;
    $named = @lstat($path);
    return is_array($held) && is_array($named)
        && (($named['mode'] ?? 0) & 0170000) === 0100000
        && ($held['dev'] ?? null) === ($named['dev'] ?? null)
        && ($held['ino'] ?? null) === ($named['ino'] ?? null);
};
$hashRegular = static function (string $path, ?string $base = null) use ($openRegular): mixed {
    $opened = $openRegular($path, 'rb', $base);
    if (!is_array($opened) || !is_resource($opened[0]) || !is_array($opened[1])) return false;
    $handle = $opened[0];
    $before = $opened[1];
    $context = hash_init('sha256');
    $updated = @hash_update_stream($context, $handle);
    $after = @fstat($handle);
    $onPath = @lstat($path);
    @fclose($handle);
    if (!is_int($updated) || !is_array($after) || !is_array($onPath)
        || ($before['dev'] ?? null) !== ($after['dev'] ?? null)
        || ($before['ino'] ?? null) !== ($after['ino'] ?? null)
        || ($before['dev'] ?? null) !== ($onPath['dev'] ?? null)
        || ($before['ino'] ?? null) !== ($onPath['ino'] ?? null)
        || (($after['mode'] ?? 0) & 0170000) !== 0100000
        || (($onPath['mode'] ?? 0) & 0170000) !== 0100000) return false;
    return ['hash' => hash_final($context), 'bytes' => $after['size'] ?? null];
};
if (!$regularPath($root, true)) { fwrite(STDERR, "root-type\n"); exit(32); }
$authorityPath = $root . '/authority.lock';
$authority = $openRegular($authorityPath, 'rb', $root);
if (!is_array($authority) || !is_resource($authority[0]) || !@flock($authority[0], LOCK_SH)
    || !$lockBound($authority[0], $authorityPath)) {
    fwrite(STDERR, "authority-lock\n"); exit(32);
}
$policyOpened = $openRegular($root . '/authority-policy.json', 'rb', $root);
$policyHandle = is_array($policyOpened) ? $policyOpened[0] : false;
$policyBytes = is_resource($policyHandle) ? @stream_get_contents($policyHandle) : false;
if (is_resource($policyHandle)) @fclose($policyHandle);
$policy = is_string($policyBytes) ? json_decode($policyBytes, true) : null;
$envelope = json_decode($envelopeBytes, true);
$subject = json_decode($subjectBytes, true);
$keys = static function (array $value, array $expected): bool { $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING); return $actual === $expected; };
$stringSet = static function (mixed $value, ?array $allowed = null): bool {
    if (!is_array($value) || !array_is_list($value)) return false;
    $seen = [];
    foreach ($value as $item) { if (!is_string($item) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+\\/-]{0,255}$/D', $item) !== 1 || ($allowed !== null && !in_array($item, $allowed, true))) return false; $seen[] = $item; }
    $sorted = array_values(array_unique($seen)); sort($sorted, SORT_STRING); return $seen === $sorted;
};
if (!is_array($policy) || !is_array($envelope) || !is_array($subject) || array_is_list($policy) || array_is_list($envelope) || array_is_list($subject)
    || !$keys($policy, ['format', 'keys', 'max_clock_skew_seconds', 'max_ttl_seconds']) || ($policy['format'] ?? null) !== 'wprism-operation-authorities/v1'
    || !is_array($policy['keys'] ?? null) || array_is_list($policy['keys']) || !is_int($policy['max_clock_skew_seconds'] ?? null) || !is_int($policy['max_ttl_seconds'] ?? null)
    || $policy['max_clock_skew_seconds'] < 0 || $policy['max_clock_skew_seconds'] > 300 || $policy['max_ttl_seconds'] < 30 || $policy['max_ttl_seconds'] > 86400
    || !hash_equals($canonicalEncode($policy), (string) $policyBytes)
    || !$keys($envelope, ['format', 'signature', 'statement']) || ($envelope['format'] ?? null) !== 'wprism-operation-authorization/v1' || !is_array($envelope['statement'] ?? null)
    || !$keys($subject, ['authority_policy_digest', 'operation', 'operation_id', 'presentation_digest', 'required_grants', 'subject_digest', 'target_id'])
    || !$stringSet($subject['required_grants'] ?? null) || !hash_equals($canonicalEncode($envelope), $envelopeBytes) || !hash_equals($canonicalEncode($subject), $subjectBytes)
    || !hash_equals((string) ($subject['authority_policy_digest'] ?? ''), 'sha256:' . hash('sha256', (string) $policyBytes))
    || !hash_equals('sha256:' . $hex, 'sha256:' . hash('sha256', $envelopeBytes))) { fwrite(STDERR, "authority-policy\n"); exit(33); }
foreach ($policy['keys'] as $policyKeyId => $record) {
    $public = is_array($record) ? base64_decode((string) ($record['public_key'] ?? ''), true) : false;
    if (!is_string($policyKeyId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $policyKeyId) !== 1 || !is_array($record) || array_is_list($record)
        || !$keys($record, ['actor', 'algorithm', 'grants', 'operations', 'public_key', 'status']) || ($record['algorithm'] ?? null) !== 'ed25519'
        || !is_string($record['actor'] ?? null) || !in_array($record['status'] ?? null, ['trusted', 'revoked'], true) || !$stringSet($record['grants'] ?? null) || !$stringSet($record['operations'] ?? null, ['release', 'recovery'])
        || !is_string($public) || strlen($public) !== 32 || !hash_equals(base64_encode($public), (string) $record['public_key'])) { fwrite(STDERR, "authority-policy\n"); exit(33); }
}
$statement = $envelope['statement'];
foreach (['actor', 'expires_at', 'issued_at', 'key_id', 'nonce', 'operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) { if (!is_string($statement[$field] ?? null)) { fwrite(STDERR, "authority-shape\n"); exit(34); } }
foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) { if (!is_string($subject[$field] ?? null) || !hash_equals($subject[$field], $statement[$field])) { fwrite(STDERR, "authority-subject\n"); exit(35); } }
$record = $policy['keys'][$statement['key_id']] ?? null; $public = is_array($record) ? base64_decode((string) ($record['public_key'] ?? ''), true) : false; $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
if (!is_array($record) || ($record['status'] ?? null) !== 'trusted' || !hash_equals((string) ($record['actor'] ?? ''), $statement['actor']) || !in_array($statement['operation'], $record['operations'], true) || array_diff($subject['required_grants'], $record['grants']) !== [] || !is_string($public) || !is_string($signature) || strlen($signature) !== 64 || !function_exists('sodium_crypto_sign_verify_detached') || !sodium_crypto_sign_verify_detached($signature, "wprism-operation-authorization-signature/v1\0" . $canonicalEncode($statement), $public)) { fwrite(STDERR, "authority-signature\n"); exit(36); }
$issued = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $statement['issued_at'], new DateTimeZone('UTC')); $signedExpiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $statement['expires_at'], new DateTimeZone('UTC'));
if (!$issued instanceof DateTimeImmutable || !$signedExpiry instanceof DateTimeImmutable || !hash_equals($expires, $statement['expires_at']) || $issued->getTimestamp() > time() + $policy['max_clock_skew_seconds'] || $signedExpiry->getTimestamp() <= time() || $signedExpiry->getTimestamp() <= $issued->getTimestamp() || ($signedExpiry->getTimestamp() - $issued->getTimestamp()) > $policy['max_ttl_seconds']) { fwrite(STDERR, "expired\n"); exit(22); }
$locks = [];
$repositoryLock = null;
$repositoryLockPath = '';
if (is_array($precondition)) {
    // Recovery's Git fact is serialized by the same target-private lock as
    // release materialization/promotion. Creating the empty lock is permitted
    // only here, at the mutation boundary; prepare and status remain read-only.
    $repositoryLockPath = $root . '/repository.lock';
    $repositoryBefore = @lstat($repositoryLockPath);
    if (is_array($repositoryBefore)
        && (($repositoryBefore['mode'] ?? 0) & 0170000) !== 0100000) {
        fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
    }
    $repositoryLock = false;
    if (!is_array($repositoryBefore)) {
        $previousUmask = umask(0077);
        $repositoryLock = @fopen($repositoryLockPath, 'x+b');
        umask($previousUmask);
        if (is_resource($repositoryLock)) {
            $repositoryCreated = @fstat($repositoryLock);
            $repositoryOnPath = @lstat($repositoryLockPath);
            if (!is_array($repositoryCreated) || !is_array($repositoryOnPath)
                || (($repositoryCreated['mode'] ?? 0) & 0170000) !== 0100000
                || ($repositoryCreated['dev'] ?? null) !== ($repositoryOnPath['dev'] ?? null)
                || ($repositoryCreated['ino'] ?? null) !== ($repositoryOnPath['ino'] ?? null)
                || !@fflush($repositoryLock)
                || !function_exists('fsync')
                || !@fsync($repositoryLock)) {
                if (is_resource($repositoryLock)) @fclose($repositoryLock);
                fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
            }
            $rootSync = @fopen($root, 'rb');
            if (!is_resource($rootSync) || !@fsync($rootSync) || !@fclose($rootSync)) {
                if (is_resource($rootSync)) @fclose($rootSync);
                @fclose($repositoryLock);
                fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
            }
        }
    }
    if (!is_resource($repositoryLock)) {
        $repositoryOpened = $openRegular($repositoryLockPath, 'r+b', $root);
        $repositoryLock = is_array($repositoryOpened) ? $repositoryOpened[0] : false;
    }
    if (!is_resource($repositoryLock) || !@flock($repositoryLock, LOCK_EX)) {
        if (is_resource($repositoryLock)) @fclose($repositoryLock);
        fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
    }
    $repositoryLocked = @fstat($repositoryLock);
    $repositoryOnPath = @lstat($repositoryLockPath);
    if (!is_array($repositoryLocked) || !is_array($repositoryOnPath)
        || (($repositoryOnPath['mode'] ?? 0) & 0170000) !== 0100000
        || ($repositoryLocked['dev'] ?? null) !== ($repositoryOnPath['dev'] ?? null)
        || ($repositoryLocked['ino'] ?? null) !== ($repositoryOnPath['ino'] ?? null)) {
        @fclose($repositoryLock);
        fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
    }
    $locks[] = $repositoryLock;
}
foreach ($lockPaths as $relative) {
    if (!$safeRelative($relative)) { fwrite(STDERR, "precondition-lock\n"); exit(30); }
    $path = rtrim($repo, '/') . '/' . $relative;
    $opened = $openRegular($path, 'c', $repo);
    $lock = is_array($opened) ? $opened[0] : false;
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) @fclose($lock);
        fwrite(STDERR, "precondition-lock\n"); exit(31);
    }
    $locks[] = $lock;
}
if (is_array($precondition) && !$lockBound($repositoryLock, $repositoryLockPath)) {
    fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
}
$operationLockPath = $root . '/identity.lock';
$operationOpened = $openRegular($operationLockPath, 'c', $root);
$operationLock = is_array($operationOpened) ? $operationOpened[0] : false;
if (!is_resource($operationLock) || !@flock($operationLock, LOCK_EX)) {
    if (is_resource($operationLock)) @fclose($operationLock);
    fwrite(STDERR, "operation-lock\n"); exit(32);
}
if (!$lockBound($operationLock, $operationLockPath) || !$lockBound($authority[0], $authorityPath)) {
    fwrite(STDERR, "operation-lock\n"); exit(32);
}
$identityPath = $root . '/target-id';
$identityOpened = $openRegular($identityPath, 'rb', $root);
$identityHandle = is_array($identityOpened) ? $identityOpened[0] : false;
$identityRaw = is_resource($identityHandle) ? @stream_get_contents($identityHandle) : false;
if (is_resource($identityHandle)) @fclose($identityHandle);
$identity = is_string($identityRaw) && str_ends_with($identityRaw, "\n")
    ? substr($identityRaw, 0, -1) : '';
if (!$lockBound($operationLock, $operationLockPath) || !$lockBound($authority[0], $authorityPath)) {
    fwrite(STDERR, "operation-lock\n"); exit(32);
}
if (!hash_equals($targetId, $identity)) { fwrite(STDERR, "target-mismatch\n"); exit(34); }
$authorizations = $root . '/authorizations';
if ((file_exists($authorizations) || is_link($authorizations))
    && !$regularPath($authorizations, true, $root)) {
    fwrite(STDERR, "store-type\n"); exit(23);
}
$directory = $authorizations . '/' . $hex;
$operations = $authorizations . '/operations';
$operationDirectory = $operations . '/' . $tupleHex;
$electionPath = $operationDirectory . '/election.json';
$completionState = static function (string $directory): string {
    $publication = $directory . '/completion';
    if (!file_exists($publication) && !is_link($publication)) return 'nonterminal';
    if (!is_dir($publication) || is_link($publication)) return 'uncertain';
    $outcome = $publication . '/outcome.json';
    return !is_link($outcome) && is_file($outcome) ? 'complete' : 'uncertain';
};
$replay = static function (string $directory, string $expected): never {
    if (!is_dir($directory) || is_link($directory)) { fwrite(STDERR, "record-type\n"); exit(26); }
    $path = $directory . '/consumption.json';
    if (is_link($path) || !is_file($path)) { fwrite(STDERR, "record-uncertain\n"); exit(27); }
    $actual = @file_get_contents($path);
    if (!is_string($actual)) { fwrite(STDERR, "record-unreadable\n"); exit(28); }
    if (!hash_equals($expected, $actual)) { fwrite(STDERR, "record-conflict\n"); exit(29); }
    echo "replay\n"; exit(0);
};
$validElection = static function (array $document, string $raw, string $electionFormat)
    use ($canonicalEncode): bool {
    $expectedKeys = [
        'authorization_digest', 'election_digest', 'format', 'operation', 'operation_id',
        'precondition_digest', 'presentation_digest', 'subject_digest', 'target_id', 'tuple_digest',
    ];
    $actualKeys = array_keys($document);
    sort($actualKeys, SORT_STRING);
    sort($expectedKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys || ($document['format'] ?? null) !== $electionFormat
        || !is_string($document['authorization_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['authorization_digest']) !== 1
        || !is_string($document['tuple_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['tuple_digest']) !== 1
        || !(($document['precondition_digest'] ?? null) === null
            || (is_string($document['precondition_digest'])
                && preg_match('/^sha256:[a-f0-9]{64}$/D', $document['precondition_digest']) === 1))) {
        return false;
    }
    foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
        if (!is_string($document[$field] ?? null)) return false;
    }
    if (!is_string($document['election_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['election_digest']) !== 1) return false;
    $withoutDigest = $document;
    unset($withoutDigest['election_digest']);
    $encoded = $canonicalEncode($withoutDigest);
    return $encoded !== '' && $raw === $canonicalEncode($document)
        && hash_equals($document['election_digest'], 'sha256:' . hash('sha256', $encoded));
};
$tupleWinner = static function (
    string $winnerHex,
    string $currentHex,
    string $authorizations,
    callable $completionState
): never {
    if (!preg_match('/^[a-f0-9]{64}$/D', $winnerHex)) { fwrite(STDERR, "election-invalid\n"); exit(35); }
    if (hash_equals($winnerHex, $currentHex)) { fwrite(STDERR, "record-uncertain\n"); exit(27); }
    $winnerDirectory = $authorizations . '/' . $winnerHex;
    if (!is_dir($winnerDirectory) || is_link($winnerDirectory)
        || is_link($winnerDirectory . '/consumption.json')
        || !is_file($winnerDirectory . '/consumption.json')) {
        fwrite(STDERR, "tuple-uncertain\n"); exit(36);
    }
    $state = $completionState($winnerDirectory);
    if ($state === 'complete') { echo 'tuple-complete:' . $winnerHex . "\n"; exit(0); }
    fwrite(STDERR, $state === 'nonterminal' ? "tuple-nonterminal\n" : "tuple-uncertain\n");
    exit($state === 'nonterminal' ? 37 : 36);
};
if ((file_exists($operations) || is_link($operations))
    && !$regularPath($operations, true, $root)) {
    fwrite(STDERR, "store-type\n"); exit(23);
}
if (file_exists($operationDirectory) || is_link($operationDirectory)) {
    if (!$regularPath($operationDirectory, true, $root)) { fwrite(STDERR, "election-invalid\n"); exit(35); }
}
if (file_exists($electionPath) || is_link($electionPath)) {
    if (is_link($electionPath) || !is_file($electionPath)) { fwrite(STDERR, "election-invalid\n"); exit(35); }
    $actualElection = @file_get_contents($electionPath);
    $elected = is_string($actualElection) ? json_decode($actualElection, true) : null;
    if (!is_array($elected) || !is_string($actualElection)
        || !$validElection($elected, $actualElection, $electionFormat)
        || ($elected['tuple_digest'] ?? '') !== 'sha256:' . $tupleHex
        || !$tupleMatches($elected)) { fwrite(STDERR, "election-invalid\n"); exit(35); }
    $winner = (string) ($elected['authorization_digest'] ?? '');
    $winnerHex = str_starts_with($winner, 'sha256:') ? substr($winner, 7) : '';
    if (hash_equals($winnerHex, $hex)) $replay($directory, $expected);
    $tupleWinner($winnerHex, $hex, $authorizations, $completionState);
}
// Upgrade-safe scan: pre-index consumptions still elect their operation tuple.
// A different fresh authorization can therefore never bypass an older record.
$legacyWinner = null;
if (is_dir($authorizations)) {
    foreach (glob($authorizations . '/[a-f0-9]*') ?: [] as $candidate) {
        $candidateHex = basename($candidate);
        if (preg_match('/^[a-f0-9]{64}$/D', $candidateHex) !== 1
            || !is_dir($candidate) || is_link($candidate)) continue;
        $path = $candidate . '/consumption.json';
        if (is_link($path) || !is_file($path)) continue;
        $candidateRaw = @file_get_contents($path);
        $candidateDocument = is_string($candidateRaw) ? json_decode($candidateRaw, true) : null;
        if (is_array($candidateDocument) && $tupleMatches($candidateDocument)) {
            if ($legacyWinner !== null && !hash_equals($legacyWinner, $candidateHex)) {
                fwrite(STDERR, "tuple-uncertain\n"); exit(36);
            }
            $legacyWinner = $candidateHex;
        }
    }
}
if ($legacyWinner !== null) {
    if (hash_equals($legacyWinner, $hex)) $replay($directory, $expected);
    $tupleWinner($legacyWinner, $hex, $authorizations, $completionState);
}
if ($expiry->getTimestamp() <= time()) { fwrite(STDERR, "expired\n"); exit(22); }
if (is_array($precondition)) {
    $notAfter = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        (string) ($precondition['not_after'] ?? ''),
        new DateTimeZone('UTC')
    );
    if (!$notAfter instanceof DateTimeImmutable
        || $notAfter->format('Y-m-d\TH:i:s\Z') !== ($precondition['not_after'] ?? '')
        || $notAfter->getTimestamp() <= time()) {
        fwrite(STDERR, "precondition-expired\n"); exit(38);
    }
    $head = (string) ($precondition['repository_head'] ?? '');
    $readHead = static function () use ($repo): ?string {
        $pipes = [];
        $process = @proc_open(
            ['git', '-C', $repo, 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) return null;
        @fclose($pipes[0]);
        $actual = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        @fclose($pipes[1]); @fclose($pipes[2]);
        return proc_close($process) === 0 ? $actual : null;
    };
    if (!$lockBound($repositoryLock, $repositoryLockPath)) {
        fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
    }
    $actualHead = $readHead();
    if (!is_string($actualHead) || !hash_equals($head, $actualHead)) {
        fwrite(STDERR, "precondition-head\n"); exit(39);
    }
    foreach ($precondition['files'] as $file) {
        if (!is_array($file) || !$safeRelative($file['path'] ?? null)
            || !is_string($file['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1
            || !(is_int($file['bytes'] ?? null) || ($file['bytes'] ?? null) === null)) {
            fwrite(STDERR, "precondition-file-shape\n"); exit(30);
        }
        $path = rtrim($repo, '/') . '/' . $file['path'];
        $actual = $hashRegular($path, $repo);
        $actualHash = is_array($actual) ? ($actual['hash'] ?? null) : null;
        $actualBytes = is_array($actual) ? ($actual['bytes'] ?? null) : null;
        if (!is_string($actualHash) || !hash_equals($file['sha256'], $actualHash)
            || ($file['bytes'] !== null && (!is_int($actualBytes) || $actualBytes !== $file['bytes']))) {
            fwrite(STDERR, "precondition-file\n"); exit(40);
        }
    }
    foreach ($precondition['ordered_file_hashes'] as $set) {
        if (!is_array($set) || !$safeRelative($set['path'] ?? null)
            || !is_string($set['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $set['sha256']) !== 1) {
            fwrite(STDERR, "precondition-file-set-shape\n"); exit(30);
        }
        $setDirectory = rtrim($repo, '/') . '/' . $set['path'];
        if (is_link($setDirectory) || !is_dir($setDirectory)) {
            fwrite(STDERR, "precondition-file-set\n"); exit(41);
        }
        $paths = glob($setDirectory . '/*.json') ?: [];
        sort($paths, SORT_STRING);
        $hashes = [];
        foreach ($paths as $index => $path) {
            $actual = $hashRegular($path, $repo);
            if (!is_array($actual) || !is_string($actual['hash'] ?? null)) {
                fwrite(STDERR, "precondition-file-set\n"); exit(41);
            }
            $hashes[] = ['sequence' => $index + 1, 'sha256' => $actual['hash']];
        }
        $encoded = json_encode(
            $hashes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        $actual = is_string($encoded) ? hash('sha256', $encoded . "\n") : '';
        if (!hash_equals($set['sha256'], $actual)) {
            fwrite(STDERR, "precondition-file-set\n"); exit(41);
        }
    }
    // A non-cooperating Git writer injected after the first read is still
    // caught before election. Supported WPrism writers cannot reach this gap:
    // they serialize on repository.lock, which remains held through publish.
    if (!$lockBound($repositoryLock, $repositoryLockPath)) {
        fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
    }
    $finalHead = $readHead();
    if (!is_string($finalHead) || !hash_equals($head, $finalHead)) {
        fwrite(STDERR, "precondition-head\n"); exit(39);
    }
}
if (is_array($precondition) && !$lockBound($repositoryLock, $repositoryLockPath)) {
    fwrite(STDERR, "precondition-repository-lock\n"); exit(31);
}
if (!is_dir($authorizations) && !@mkdir($authorizations, 0700, true) && !is_dir($authorizations)) {
    fwrite(STDERR, "store-create\n"); exit(24);
}
if (!$lockBound($operationLock, $operationLockPath) || !$lockBound($authority[0], $authorityPath)) {
    fwrite(STDERR, "operation-lock\n"); exit(32);
}
@chmod($authorizations, 0700);
// The expiry check immediately before winner election is the target clock's
// mutation boundary. An existing exact record remains readable after expiry.
if ($expiry->getTimestamp() <= time()) { fwrite(STDERR, "expired\n"); exit(22); }
$publish = static function (string $directory, string $path, string $expected, string $prefix): void {
    $temporary = @tempnam($directory, '.' . $prefix . '-');
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
        fwrite(STDERR, "publish-uncertain\n"); exit(25);
    }
};
if (!is_dir($operations) && !@mkdir($operations, 0700, true) && !is_dir($operations)) {
    fwrite(STDERR, "store-create\n"); exit(24);
}
if (!$regularPath($operations, true, $root)) { fwrite(STDERR, "store-type\n"); exit(23); }
@chmod($operations, 0700);
if (!@mkdir($operationDirectory, 0700)) { fwrite(STDERR, "election-uncertain\n"); exit(35); }
@chmod($operationDirectory, 0700);
$publish($operationDirectory, $electionPath, $expectedElection, 'election');
foreach ([$operationDirectory, $operations, $authorizations, $root] as $syncDirectory) {
    $sync = @fopen($syncDirectory, 'rb');
    if (!is_resource($sync) || !@fsync($sync) || !@fclose($sync)) {
        if (is_resource($sync)) @fclose($sync);
        fwrite(STDERR, "publish-uncertain\n"); exit(25);
    }
}
if (!@mkdir($directory, 0700)) { fwrite(STDERR, "publish-uncertain\n"); exit(25); }
@chmod($directory, 0700);
$publish($directory, $directory . '/consumption.json', $expected, 'consumption');
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
PHP;
        $result = $driver->captureRaw(self::php($script, [
            $root,
            rtrim($driver->repoPath(), '/'),
            $hex,
            $tupleHex,
            base64_encode($bytes),
            base64_encode($electionBytes),
            $authorization['expires_at'],
            $authorization['target_id'],
            base64_encode($preconditionBytes),
            self::PRECONDITION_FORMAT,
            self::ELECTION_FORMAT,
            base64_encode($envelopeBytes),
            base64_encode($subjectBytes),
        ]));
        $status = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) === 0 && str_starts_with($status, 'tuple-complete:')) {
            $winner = 'sha256:' . substr($status, strlen('tuple-complete:'));
            self::assertDigest($winner, 'elected authorization digest');
            $stored = self::status($driver, $winner);
            if ($stored === null || !is_array($stored['completion'] ?? null)) throw self::statusMalformed();
            throw self::refuse(
                'authorized_operation_already_completed',
                'a different authorization already completed this exact operation subject',
                'query or replay the elected authorization; this authorization did not execute and remains unconsumed'
            );
        }
        if (($result['exit'] ?? 1) !== 0 || !in_array($status, ['first', 'replay'], true)) {
            $detail = trim((string) ($result['stderr'] ?? ''));
            $code = match (true) {
                str_contains($detail, 'tuple-nonterminal') =>
                    'authorized_operation_reconciliation_required',
                str_contains($detail, 'precondition') =>
                    'authorized_operation_precondition_changed',
                str_contains($detail, 'authority-policy') =>
                    'authorization_authority_policy_changed',
                str_contains($detail, 'authority-signature') =>
                    'authorization_signature_invalid',
                str_contains($detail, 'authority-') =>
                    'authorization_subject_mismatch',
                str_contains($detail, 'target-mismatch') =>
                    'authorization_target_mismatch',
                str_contains($detail, 'expired') => 'authorization_expired',
                str_contains($detail, 'conflict') => 'authorization_consumption_conflict',
                default => 'authorization_consumption_uncertain',
            };
            throw self::refuse(
                $code,
                match ($code) {
                    'authorized_operation_reconciliation_required' =>
                        'another authorization already won this exact operation subject without a terminal outcome',
                    'authorized_operation_precondition_changed' =>
                        'the target changed after final verification but before operation election',
                    'authorization_authority_policy_changed' =>
                        'the target authority policy changed after this immutable subject was prepared',
                    'authorization_signature_invalid' =>
                        'the target could not verify the operation signature under its current authority policy',
                    'authorization_subject_mismatch' =>
                        'the target-side authorization facts do not match the exact immutable subject',
                    'authorization_target_mismatch' =>
                        'the signed authorization names a different stable target identity',
                    'authorization_expired' =>
                        'the signed authorization expired before durable target-side consumption',
                    'authorization_consumption_conflict' =>
                        'the target already holds different bytes under this authorization identity',
                    default => 'the target cannot prove whether this authorization was durably consumed',
                },
                match ($code) {
                    'authorized_operation_precondition_changed' =>
                        'the authorization remains unconsumed; reconcile the changed target and prepare a fresh subject',
                    'authorization_authority_policy_changed', 'authorization_signature_invalid',
                    'authorization_subject_mismatch' =>
                        'sync and review the intended target authority policy, then prepare and authorize a fresh subject',
                    'authorization_target_mismatch' =>
                        'prepare and authorize the operation against the target being executed',
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
        $tupleDigest = self::digest(Canon::encode(self::operationTuple($consumption)));
        $tupleHex = substr($tupleDigest, 7);
        $script = <<<'PHP'
$root = $argv[1] ?? '';
$hex = $argv[2] ?? '';
$expected = base64_decode($argv[3] ?? '', true);
$expectedConsumption = base64_decode($argv[4] ?? '', true);
$tupleHex = $argv[5] ?? '';
$electionFormat = $argv[6] ?? '';
if ($root === '' || $root[0] !== '/' || preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1
    || preg_match('/^[a-f0-9]{64}$/D', $tupleHex) !== 1
    || !is_string($expected) || $expected === ''
    || !is_string($expectedConsumption) || $expectedConsumption === '') {
    fwrite(STDERR, "consumption-missing\n"); exit(20);
}
$regularPath = static function (string $path, bool $directory = false, ?string $base = null): bool {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) return false;
    if ($base === null) {
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === ($directory ? 0040000 : 0100000);
    }
    $base = rtrim($base, '/');
    $baseStat = @lstat($base);
    if (!is_array($baseStat) || (($baseStat['mode'] ?? 0) & 0170000) !== 0040000
        || !str_starts_with($path, $base . '/')) return false;
    $current = $base;
    $parts = explode('/', substr($path, strlen($base) + 1));
    foreach ($parts as $index => $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
        $current .= '/' . $part;
        $stat = @lstat($current);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) === 0120000) return false;
        $last = $index === count($parts) - 1;
        $expectedType = $last && $directory ? 0040000 : ($last ? 0100000 : 0040000);
        if (($stat['mode'] & 0170000) !== $expectedType) return false;
    }
    return true;
};
$lockBound = static function (mixed $handle, string $path): bool {
    $held = is_resource($handle) ? @fstat($handle) : false;
    $named = @lstat($path);
    return is_array($held) && is_array($named)
        && (($named['mode'] ?? 0) & 0170000) === 0100000
        && ($held['dev'] ?? null) === ($named['dev'] ?? null)
        && ($held['ino'] ?? null) === ($named['ino'] ?? null);
};
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map($canonicalize, $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalEncode = static function (array $value) use ($canonicalize): string {
    $encoded = json_encode($canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded . "\n" : '';
};
$validElection = static function (array $document, string $raw, string $electionFormat)
    use ($canonicalEncode): bool {
    $expectedKeys = [
        'authorization_digest', 'election_digest', 'format', 'operation', 'operation_id',
        'precondition_digest', 'presentation_digest', 'subject_digest', 'target_id', 'tuple_digest',
    ];
    $actualKeys = array_keys($document);
    sort($actualKeys, SORT_STRING);
    sort($expectedKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys || ($document['format'] ?? null) !== $electionFormat
        || !is_string($document['authorization_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['authorization_digest']) !== 1
        || !is_string($document['tuple_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['tuple_digest']) !== 1
        || !(($document['precondition_digest'] ?? null) === null
            || (is_string($document['precondition_digest'])
                && preg_match('/^sha256:[a-f0-9]{64}$/D', $document['precondition_digest']) === 1))) {
        return false;
    }
    foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
        if (!is_string($document[$field] ?? null)) return false;
    }
    if (!is_string($document['election_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['election_digest']) !== 1) return false;
    $withoutDigest = $document;
    unset($withoutDigest['election_digest']);
    $encoded = $canonicalEncode($withoutDigest);
    return $encoded !== '' && $raw === $canonicalEncode($document)
        && hash_equals($document['election_digest'], 'sha256:' . hash('sha256', $encoded));
};
if (!$regularPath($root, true)) { fwrite(STDERR, "operation-lock\n"); exit(20); }
$operationLockPath = $root . '/identity.lock';
$lockBefore = @lstat($operationLockPath);
$operationLock = @fopen($operationLockPath, 'c');
$lockAfter = is_resource($operationLock) ? @fstat($operationLock) : false;
if (!is_array($lockBefore) || !is_array($lockAfter)
    || (($lockBefore['mode'] ?? 0) & 0170000) !== 0100000
    || (($lockAfter['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
    || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)) {
    if (is_resource($operationLock)) @fclose($operationLock);
    fwrite(STDERR, "operation-lock\n"); exit(20);
}
if (!is_resource($operationLock) || !@flock($operationLock, LOCK_EX)) {
    if (is_resource($operationLock)) @fclose($operationLock);
    fwrite(STDERR, "operation-lock\n"); exit(20);
}
$identityPath = $root . '/target-id';
$identityBefore = @lstat($identityPath); $identityHandle = @fopen($identityPath, 'rb');
$identityAfter = is_resource($identityHandle) ? @fstat($identityHandle) : false;
$identityBytes = is_resource($identityHandle) ? @stream_get_contents($identityHandle) : false;
if (is_resource($identityHandle)) @fclose($identityHandle); $identityNamed = @lstat($identityPath);
$identity = is_string($identityBytes) && str_ends_with($identityBytes, "\n")
    ? substr($identityBytes, 0, -1) : '';
if (!$lockBound($operationLock, $operationLockPath) || !is_array($identityBefore)
    || !is_array($identityAfter) || !is_array($identityNamed)
    || (($identityBefore['mode'] ?? 0) & 0170000) !== 0100000
    || ($identityBefore['dev'] ?? null) !== ($identityAfter['dev'] ?? null)
    || ($identityBefore['ino'] ?? null) !== ($identityAfter['ino'] ?? null)
    || ($identityBefore['dev'] ?? null) !== ($identityNamed['dev'] ?? null)
    || ($identityBefore['ino'] ?? null) !== ($identityNamed['ino'] ?? null)) {
    fwrite(STDERR, "target-mismatch\n"); exit(20);
}
$directory = $root . '/authorizations/' . $hex;
if (!$regularPath($directory, true, $root)) { fwrite(STDERR, "consumption-missing\n"); exit(20); }
$consumption = $directory . '/consumption.json';
if (is_link($consumption) || !is_file($consumption)) { fwrite(STDERR, "consumption-uncertain\n"); exit(21); }
$actualConsumption = @file_get_contents($consumption);
if (!is_string($actualConsumption)) { fwrite(STDERR, "consumption-read\n"); exit(22); }
if (!hash_equals($expectedConsumption, $actualConsumption)) {
    fwrite(STDERR, "consumption-conflict\n"); exit(23);
}
$consumptionDocument = json_decode($actualConsumption, true);
if (!is_array($consumptionDocument) || !hash_equals((string) ($consumptionDocument['target_id'] ?? ''), $identity)
    || !$lockBound($operationLock, $operationLockPath)) { fwrite(STDERR, "target-mismatch\n"); exit(20); }
$electionPath = $root . '/authorizations/operations/' . $tupleHex . '/election.json';
if (!$regularPath($root . '/authorizations', true, $root)
    || ((file_exists(dirname($electionPath)) || is_link(dirname($electionPath)))
        && !$regularPath(dirname($electionPath), true, $root))) {
    fwrite(STDERR, "election-invalid\n"); exit(28);
}
if (file_exists($electionPath) || is_link($electionPath)) {
    if (is_link($electionPath) || !is_file($electionPath)) { fwrite(STDERR, "election-invalid\n"); exit(28); }
    $electionRaw = @file_get_contents($electionPath);
    $election = is_string($electionRaw) ? json_decode($electionRaw, true) : null;
    if (!is_array($consumptionDocument) || !is_array($election) || !is_string($electionRaw)
        || !$validElection($election, $electionRaw, $electionFormat)
        || ($election['tuple_digest'] ?? '') !== 'sha256:' . $tupleHex
        || ($election['authorization_digest'] ?? '') !== 'sha256:' . $hex) {
        fwrite(STDERR, "election-invalid\n"); exit(28);
    }
    foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
        if (!is_string($election[$field] ?? null) || !is_string($consumptionDocument[$field] ?? null)
            || !hash_equals($consumptionDocument[$field], $election[$field])) {
            fwrite(STDERR, "election-invalid\n"); exit(28);
        }
    }
}
$publication = $directory . '/completion';
$path = $publication . '/outcome.json';
if (!$lockBound($operationLock, $operationLockPath)) { fwrite(STDERR, "operation-lock\n"); exit(20); }
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
            [
                $root,
                $hex,
                base64_encode($bytes),
                base64_encode($consumptionBytes),
                $tupleHex,
                self::ELECTION_FORMAT,
            ]
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
$root = $argv[1] ?? '';
$lockPath = $root . '/identity.lock';
$regularPath = static function (string $path, bool $directory = false, ?string $base = null): bool {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) return false;
    if ($base === null) {
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === ($directory ? 0040000 : 0100000);
    }
    $base = rtrim($base, '/');
    $baseStat = @lstat($base);
    if (!is_array($baseStat) || (($baseStat['mode'] ?? 0) & 0170000) !== 0040000
        || !str_starts_with($path, $base . '/')) return false;
    $current = $base;
    $parts = explode('/', substr($path, strlen($base) + 1));
    foreach ($parts as $index => $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
        $current .= '/' . $part;
        $stat = @lstat($current);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) === 0120000) return false;
        $last = $index === count($parts) - 1;
        $expectedType = $last && $directory ? 0040000 : ($last ? 0100000 : 0040000);
        if (($stat['mode'] & 0170000) !== $expectedType) return false;
    }
    return true;
};
$lockBound = static function (mixed $handle, string $path): bool {
    $held = is_resource($handle) ? @fstat($handle) : false;
    $named = @lstat($path);
    return is_array($held) && is_array($named)
        && (($named['mode'] ?? 0) & 0170000) === 0100000
        && ($held['dev'] ?? null) === ($named['dev'] ?? null)
        && ($held['ino'] ?? null) === ($named['ino'] ?? null);
};
if (!$regularPath($root, true)) { fwrite(STDERR, "lock\n"); exit(20); }
$lockBefore = @lstat($lockPath);
$lock = @fopen($lockPath, 'c');
$lockAfter = is_resource($lock) ? @fstat($lock) : false;
if (!is_array($lockBefore) || !is_array($lockAfter)
    || (($lockBefore['mode'] ?? 0) & 0170000) !== 0100000
    || (($lockAfter['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
    || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)) {
    if (is_resource($lock)) @fclose($lock);
    fwrite(STDERR, "lock\n"); exit(20);
}
if (!is_resource($lock) || !@flock($lock, LOCK_SH)) {
    if (is_resource($lock)) @fclose($lock);
    fwrite(STDERR, "lock\n"); exit(20);
}
$identityPath = $root . '/target-id';
$identityBefore = @lstat($identityPath); $identityHandle = @fopen($identityPath, 'rb');
$identityAfter = is_resource($identityHandle) ? @fstat($identityHandle) : false;
$identityBytes = is_resource($identityHandle) ? @stream_get_contents($identityHandle) : false;
if (is_resource($identityHandle)) @fclose($identityHandle); $identityNamed = @lstat($identityPath);
if (!$lockBound($lock, $lockPath) || !is_array($identityBefore) || !is_array($identityAfter)
    || !is_array($identityNamed) || (($identityBefore['mode'] ?? 0) & 0170000) !== 0100000
    || ($identityBefore['dev'] ?? null) !== ($identityAfter['dev'] ?? null)
    || ($identityBefore['ino'] ?? null) !== ($identityAfter['ino'] ?? null)
    || ($identityBefore['dev'] ?? null) !== ($identityNamed['dev'] ?? null)
    || ($identityBefore['ino'] ?? null) !== ($identityNamed['ino'] ?? null)
    || !is_string($identityBytes)) { fwrite(STDERR, "identity\n"); exit(20); }
$directory = $root . '/authorizations/' . ($argv[2] ?? '');
$authorizations = $root . '/authorizations';
if (file_exists($authorizations) || is_link($authorizations)) {
    if (!$regularPath($authorizations, true, $root)) { fwrite(STDERR, "record-type\n"); exit(20); }
}
if (!file_exists($directory) && !is_link($directory)) {
    if (!$lockBound($lock, $lockPath)) { fwrite(STDERR, "lock\n"); exit(20); }
    echo "absent\n"; exit(0);
}
if (!$regularPath($directory, true, $root)) { fwrite(STDERR, "record-type\n"); exit(20); }
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
if (!$lockBound($lock, $lockPath)) { fwrite(STDERR, "lock\n"); exit(20); }
echo base64_encode($identityBytes) . "\n";
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
        if (count($lines) !== 3) {
            throw self::statusMalformed();
        }
        $consumption = self::decodeStored($lines[0], self::CONSUMPTION_FORMAT);
        self::validateConsumption($consumption);
        $completion = $lines[1] === '-' ? null : self::decodeStored($lines[1], self::COMPLETION_FORMAT);
        if (is_array($completion)) {
            self::validateCompletion($completion, $consumption);
        }
        $targetBytes = base64_decode($lines[2], true);
        $targetId = is_string($targetBytes) && str_ends_with($targetBytes, "\n")
            ? substr($targetBytes, 0, -1) : '';
        if (preg_match(self::TARGET_PATTERN, $targetId) !== 1
            || !hash_equals($targetId, (string) $consumption['target_id'])
            || (is_array($completion) && !hash_equals($targetId, (string) $completion['target_id']))) {
            throw self::statusMalformed();
        }

        return ['completion' => $completion, 'consumption' => $consumption];
    }

    /**
     * Read the one durable winner for an operation tuple, independent of the
     * particular authorization envelope that elected it. Callers use this to
     * refuse a fresh envelope before it can alias, repair or repeat an older
     * operation; only status by the elected authorization may replay outcome.
     *
     * @param array<string,mixed> $subject OperationAuthorization subject projection
     * @return ?array{authorization_digest:string,completion:?array<string,mixed>,consumption:array<string,mixed>}
     */
    public static function statusForSubject(EnvironmentDriver $driver, array $subject): ?array {
        $tuple = self::operationTuple($subject);
        $tupleDigest = self::digest(Canon::encode($tuple));
        $root = self::controlRoot($driver);
        $tupleHex = substr($tupleDigest, 7);
        $tupleBytes = Canon::encode($tuple);
        $script = <<<'PHP'
$root = $argv[1] ?? '';
$tupleHex = $argv[2] ?? '';
$expectedRaw = base64_decode($argv[3] ?? '', true);
$electionFormat = $argv[4] ?? '';
$expected = is_string($expectedRaw) ? json_decode($expectedRaw, true) : null;
if ($root === '' || $root[0] !== '/' || preg_match('/^[a-f0-9]{64}$/D', $tupleHex) !== 1
    || !is_array($expected)) { fwrite(STDERR, "input\n"); exit(20); }
$matches = static function (array $document) use ($expected): bool {
    foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
        if (!is_string($document[$field] ?? null)
            || !hash_equals((string) $expected[$field], (string) $document[$field])) return false;
    }
    return true;
};
$regularPath = static function (string $path, bool $directory = false, ?string $base = null): bool {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) return false;
    if ($base === null) {
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === ($directory ? 0040000 : 0100000);
    }
    $base = rtrim($base, '/');
    $baseStat = @lstat($base);
    if (!is_array($baseStat) || (($baseStat['mode'] ?? 0) & 0170000) !== 0040000
        || !str_starts_with($path, $base . '/')) return false;
    $current = $base;
    $parts = explode('/', substr($path, strlen($base) + 1));
    foreach ($parts as $index => $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
        $current .= '/' . $part;
        $stat = @lstat($current);
        if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) === 0120000) return false;
        $last = $index === count($parts) - 1;
        $expectedType = $last && $directory ? 0040000 : ($last ? 0100000 : 0040000);
        if (($stat['mode'] & 0170000) !== $expectedType) return false;
    }
    return true;
};
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map($canonicalize, $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalEncode = static function (array $value) use ($canonicalize): string {
    $encoded = json_encode($canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded . "\n" : '';
};
$validElection = static function (array $document, string $raw, string $electionFormat)
    use ($canonicalEncode): bool {
    $expectedKeys = [
        'authorization_digest', 'election_digest', 'format', 'operation', 'operation_id',
        'precondition_digest', 'presentation_digest', 'subject_digest', 'target_id', 'tuple_digest',
    ];
    $actualKeys = array_keys($document);
    sort($actualKeys, SORT_STRING);
    sort($expectedKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys || ($document['format'] ?? null) !== $electionFormat
        || !is_string($document['authorization_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['authorization_digest']) !== 1
        || !is_string($document['tuple_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['tuple_digest']) !== 1
        || !(($document['precondition_digest'] ?? null) === null
            || (is_string($document['precondition_digest'])
                && preg_match('/^sha256:[a-f0-9]{64}$/D', $document['precondition_digest']) === 1))) {
        return false;
    }
    foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
        if (!is_string($document[$field] ?? null)) return false;
    }
    if (!is_string($document['election_digest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['election_digest']) !== 1) return false;
    $withoutDigest = $document;
    unset($withoutDigest['election_digest']);
    $encoded = $canonicalEncode($withoutDigest);
    return $encoded !== '' && $raw === $canonicalEncode($document)
        && hash_equals($document['election_digest'], 'sha256:' . hash('sha256', $encoded));
};
$lockPath = $root . '/identity.lock';
if (!$regularPath($root, true)) { fwrite(STDERR, "lock\n"); exit(21); }
$lockBefore = @lstat($lockPath);
$lock = @fopen($lockPath, 'c');
$lockAfter = is_resource($lock) ? @fstat($lock) : false;
if (!is_array($lockBefore) || !is_array($lockAfter)
    || (($lockBefore['mode'] ?? 0) & 0170000) !== 0100000
    || (($lockAfter['mode'] ?? 0) & 0170000) !== 0100000
    || ($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
    || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)) {
    if (is_resource($lock)) @fclose($lock);
    fwrite(STDERR, "lock\n"); exit(21);
}
if (!is_resource($lock) || !@flock($lock, LOCK_SH)) {
    if (is_resource($lock)) @fclose($lock);
    fwrite(STDERR, "lock\n"); exit(21);
}
$lockBound = static function (mixed $handle, string $path): bool {
    $held = is_resource($handle) ? @fstat($handle) : false;
    $named = @lstat($path);
    return is_array($held) && is_array($named)
        && (($named['mode'] ?? 0) & 0170000) === 0100000
        && ($held['dev'] ?? null) === ($named['dev'] ?? null)
        && ($held['ino'] ?? null) === ($named['ino'] ?? null);
};
$identityPath = $root . '/target-id'; $identityBefore = @lstat($identityPath);
$identityHandle = @fopen($identityPath, 'rb'); $identityAfter = is_resource($identityHandle) ? @fstat($identityHandle) : false;
$identityBytes = is_resource($identityHandle) ? @stream_get_contents($identityHandle) : false;
if (is_resource($identityHandle)) @fclose($identityHandle); $identityNamed = @lstat($identityPath);
$identity = is_string($identityBytes) && str_ends_with($identityBytes, "\n") ? substr($identityBytes, 0, -1) : '';
if (!$lockBound($lock, $lockPath) || !is_array($identityBefore) || !is_array($identityAfter)
    || !is_array($identityNamed) || (($identityBefore['mode'] ?? 0) & 0170000) !== 0100000
    || ($identityBefore['dev'] ?? null) !== ($identityAfter['dev'] ?? null)
    || ($identityBefore['ino'] ?? null) !== ($identityAfter['ino'] ?? null)
    || ($identityBefore['dev'] ?? null) !== ($identityNamed['dev'] ?? null)
    || ($identityBefore['ino'] ?? null) !== ($identityNamed['ino'] ?? null)
    || !hash_equals((string) $expected['target_id'], $identity)) { fwrite(STDERR, "target-mismatch\n"); exit(21); }
$authorizations = $root . '/authorizations';
$election = $authorizations . '/operations/' . $tupleHex . '/election.json';
$winner = null;
if ((file_exists($authorizations) || is_link($authorizations))
    && !$regularPath($authorizations, true, $root)) { fwrite(STDERR, "election\n"); exit(22); }
if ((file_exists(dirname($election)) || is_link(dirname($election)))
    && !$regularPath(dirname($election), true, $root)) { fwrite(STDERR, "election\n"); exit(22); }
if (file_exists($election) || is_link($election)) {
    if (is_link($election) || !is_file($election)) { fwrite(STDERR, "election\n"); exit(22); }
    $raw = @file_get_contents($election);
    $document = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($document) || !is_string($raw)
        || !$validElection($document, $raw, $electionFormat)
        || ($document['tuple_digest'] ?? '') !== 'sha256:' . $tupleHex
        || !$matches($document)) { fwrite(STDERR, "election\n"); exit(22); }
    $winner = $document['authorization_digest'] ?? null;
} elseif (is_dir($authorizations)) {
    foreach (glob($authorizations . '/[a-f0-9]*') ?: [] as $candidate) {
        $candidateHex = basename($candidate);
        if (preg_match('/^[a-f0-9]{64}$/D', $candidateHex) !== 1
            || !is_dir($candidate) || is_link($candidate)) continue;
        $path = $candidate . '/consumption.json';
        if (is_link($path) || !is_file($path)) continue;
        $raw = @file_get_contents($path);
        $document = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($document) && $matches($document)) {
            $candidateDigest = 'sha256:' . $candidateHex;
            if ($winner !== null && !hash_equals((string) $winner, $candidateDigest)) {
                fwrite(STDERR, "tuple-conflict\n"); exit(23);
            }
            $winner = $candidateDigest;
        }
    }
}
if ($winner === null) {
    if (!$lockBound($lock, $lockPath)) { fwrite(STDERR, "lock\n"); exit(21); }
    echo "absent\n"; exit(0);
}
if (!is_string($winner) || preg_match('/^sha256:[a-f0-9]{64}$/D', $winner) !== 1) {
    fwrite(STDERR, "winner\n"); exit(24);
}
if (!$lockBound($lock, $lockPath)) { fwrite(STDERR, "lock\n"); exit(21); }
echo $winner . "\n";
PHP;
        $result = $driver->captureRaw(self::php(
            $script,
            [$root, $tupleHex, base64_encode($tupleBytes), self::ELECTION_FORMAT]
        ));
        if (($result['exit'] ?? 1) !== 0) throw self::statusMalformed();
        $winner = rtrim((string) ($result['stdout'] ?? ''), "\n");
        if ($winner === 'absent') return null;
        self::assertDigest($winner, 'elected authorization digest');
        $stored = self::status($driver, $winner);
        if ($stored === null) throw self::statusMalformed();

        return ['authorization_digest' => $winner] + $stored;
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

    /** @param array<string,mixed> $document @return array<string,string> */
    private static function operationTuple(array $document): array {
        $tuple = [];
        foreach (['operation', 'operation_id', 'presentation_digest', 'subject_digest', 'target_id'] as $field) {
            if (!is_string($document[$field] ?? null) || $document[$field] === '') {
                throw self::shape("the operation tuple has no $field");
            }
            $tuple[$field] = $document[$field];
        }
        foreach (['presentation_digest', 'subject_digest'] as $field) {
            self::assertDigest($tuple[$field], "operation tuple $field");
        }
        if (preg_match(self::TARGET_PATTERN, $tuple['target_id']) !== 1) {
            throw self::shape('the operation tuple has no stable target identity');
        }

        return $tuple;
    }

    /** @param array<string,mixed> $precondition */
    private static function validatePrecondition(array $precondition): void {
        self::closedKeys(
            $precondition,
            ['files', 'format', 'locks', 'not_after', 'ordered_file_hashes', 'repository_head'],
            'target operation precondition'
        );
        if (($precondition['format'] ?? null) !== self::PRECONDITION_FORMAT
            || !is_string($precondition['repository_head'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $precondition['repository_head']) !== 1
            || !is_string($precondition['not_after'] ?? null)) {
            throw self::shape('the target operation precondition identity is malformed');
        }
        self::timestamp($precondition['not_after'], 'target operation precondition not_after');
        $locks = $precondition['locks'] ?? null;
        if (!is_array($locks) || !array_is_list($locks) || $locks === []) {
            throw self::shape('the target operation precondition has no lock set');
        }
        $normalizedLocks = [];
        foreach ($locks as $path) {
            if (!is_string($path) || !self::safeRelativePath($path)) {
                throw self::shape('the target operation precondition lock path is unsafe');
            }
            $normalizedLocks[] = $path;
        }
        $sortedLocks = array_values(array_unique($normalizedLocks));
        sort($sortedLocks, SORT_STRING);
        if ($normalizedLocks !== $sortedLocks) {
            throw self::shape('the target operation precondition lock set is not sorted and unique');
        }
        $files = $precondition['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            throw self::shape('the target operation precondition has no file assertions');
        }
        $paths = [];
        foreach ($files as $file) {
            if (!is_array($file) || array_is_list($file)) {
                throw self::shape('the target operation precondition file assertion is malformed');
            }
            self::closedKeys($file, ['bytes', 'path', 'sha256'], 'target operation file assertion');
            if (!is_string($file['path'] ?? null) || !self::safeRelativePath($file['path'])
                || !is_string($file['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1
                || !(($file['bytes'] ?? null) === null
                    || (is_int($file['bytes']) && $file['bytes'] >= 0))) {
                throw self::shape('the target operation precondition file assertion is malformed');
            }
            $paths[] = $file['path'];
        }
        $sortedPaths = array_values(array_unique($paths));
        sort($sortedPaths, SORT_STRING);
        if ($paths !== $sortedPaths) {
            throw self::shape('the target operation precondition files are not sorted and unique');
        }
        $sets = $precondition['ordered_file_hashes'] ?? null;
        if (!is_array($sets) || !array_is_list($sets)) {
            throw self::shape('the target operation precondition ordered file hashes are malformed');
        }
        $setPaths = [];
        foreach ($sets as $set) {
            if (!is_array($set) || array_is_list($set)) {
                throw self::shape('the target operation precondition ordered file hash is malformed');
            }
            self::closedKeys($set, ['path', 'sha256'], 'target operation ordered file hash');
            if (!is_string($set['path'] ?? null) || !self::safeRelativePath($set['path'])
                || !is_string($set['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $set['sha256']) !== 1) {
                throw self::shape('the target operation precondition ordered file hash is malformed');
            }
            $setPaths[] = $set['path'];
        }
        $sortedSetPaths = array_values(array_unique($setPaths));
        sort($sortedSetPaths, SORT_STRING);
        if ($setPaths !== $sortedSetPaths) {
            throw self::shape('the target operation precondition ordered file hashes are not sorted and unique');
        }
    }

    private static function safeRelativePath(string $path): bool {
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0")) return false;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/D', $part) !== 1) return false;
        }

        return true;
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

    /** @return array<string,mixed> */
    private static function policyFromBytes(string $bytes): array {
        try {
            $policy = Canon::decode($bytes);
        } catch (\Throwable) {
            throw self::refuse('target_authority_policy_invalid', 'the target operation-authority policy is malformed JSON', 'explicitly sync one canonical validated authority policy before authorizing an operation');
        }
        if (!is_array($policy) || array_is_list($policy)) {
            throw self::refuse('target_authority_policy_invalid', 'the target operation-authority policy is not a JSON object', 'explicitly sync one canonical validated authority policy before authorizing an operation');
        }
        OperationAuthorization::validateTrust($policy);
        if (!hash_equals(Canon::encode($policy), $bytes)) {
            throw self::refuse('target_authority_policy_invalid', 'the target operation-authority policy is not canonical JSON', 'explicitly sync the canonical authority policy before authorizing an operation');
        }
        return $policy;
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
