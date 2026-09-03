<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/PrivateEvidenceException.php';

/**
 * Bounded Throwable graph and private, inode-bound refusal record store.
 *
 * Provider failures can contain credentials, target data, and local paths, so
 * they cannot use Throwable::$previous without making ordinary log rendering a
 * disclosure channel. PrivateEvidenceException keeps that public/operator
 * boundary closed; this dependency-free kernel service is the one deliberate
 * crossing point, and only into a 0600 file below a bound 0700 directory chain.
 *
 * The caller owns repository eligibility. It passes the root identity it
 * reviewed, and this service refuses every write if that inode or either inner
 * directory changes. Recording is diagnostic only and therefore best-effort:
 * no warning, custom error handler, race, or filesystem failure may replace the
 * command refusal being diagnosed.
 */
final class PrivateRefusalEvidence {
    public const FORMAT = 'wprism-private-refusal-evidence/v2';
    private const SCAN_NODE_LIMIT = 256;
    private const RECORD_NODE_LIMIT = 64;

    /**
     * Scan a bounded Throwable graph, then retain root plus private-cause paths
     * ahead of ordinary wrapper detail. The separate scan and record limits
     * keep a carrier behind a long printable chain while making every omission,
     * cycle, and truncated edge explicit.
     *
     * @return array{
     *   throwable:list<array{index:int,parent_index:?int,parent_recorded:bool,relation:string,class:string,message:string,file:string,line:int}>,
     *   traversal:array{scan_complete:bool,record_complete:bool,scan_node_limit:int,record_node_limit:int,scanned_nodes:int,recorded_nodes:int,omitted_scanned_nodes:int,private_edges:int,cycle_edges:int,truncated_pending_edges:int}
     * }
     */
    public static function graph(\Throwable $root): array {
        /** @var list<array{throwable:\Throwable,parent_index:?int,relation:string,private_path:bool}> $pending */
        $pending = [[
            'throwable' => $root,
            'parent_index' => null,
            'relation' => 'root',
            'private_path' => false,
        ]];
        /** @var list<array{throwable:\Throwable,parent_index:?int,relation:string,private_path:bool}> $scanned */
        $scanned = [];
        /** @var array<int,int> $visited */
        $visited = [];
        $privateEdges = 0;
        $cycleEdges = 0;

        while ($pending !== [] && count($scanned) < self::SCAN_NODE_LIMIT) {
            $entry = array_shift($pending);
            $cause = $entry['throwable'];
            $objectId = spl_object_id($cause);
            if (isset($visited[$objectId])) {
                $cycleEdges++;
                continue;
            }
            $index = count($scanned);
            $visited[$objectId] = $index;
            $scanned[] = $entry;

            if ($cause instanceof PrivateEvidenceException) {
                foreach ($cause->private_evidence_causes() as $privateCause) {
                    $privateEdges++;
                    $pending[] = [
                        'throwable' => $privateCause,
                        'parent_index' => $index,
                        'relation' => 'private_evidence',
                        'private_path' => true,
                    ];
                }
            }
            $previous = $cause->getPrevious();
            if ($previous !== null) {
                $pending[] = [
                    'throwable' => $previous,
                    'parent_index' => $index,
                    'relation' => 'previous',
                    'private_path' => $entry['private_path'],
                ];
            }
        }

        /** @var array<int,true> $selected */
        $selected = $scanned === [] ? [] : [0 => true];
        foreach ($scanned as $index => $entry) {
            if (count($selected) >= self::RECORD_NODE_LIMIT) {
                break;
            }
            if ($entry['private_path'] || $entry['throwable'] instanceof PrivateEvidenceException) {
                $selected[$index] = true;
            }
        }
        foreach ($scanned as $index => $_entry) {
            if (count($selected) >= self::RECORD_NODE_LIMIT) {
                break;
            }
            $selected[$index] = true;
        }
        ksort($selected, SORT_NUMERIC);

        $nodes = [];
        foreach (array_keys($selected) as $index) {
            $entry = $scanned[$index];
            $cause = $entry['throwable'];
            $parent = $entry['parent_index'];
            $nodes[] = [
                'index' => $index,
                'parent_index' => $parent,
                'parent_recorded' => $parent === null || isset($selected[$parent]),
                'relation' => $entry['relation'],
                'class' => get_class($cause),
                'message' => $cause->getMessage(),
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ];
        }
        $scanComplete = $pending === [];
        return [
            'throwable' => $nodes,
            'traversal' => [
                'scan_complete' => $scanComplete,
                'record_complete' => $scanComplete && count($nodes) === count($scanned),
                'scan_node_limit' => self::SCAN_NODE_LIMIT,
                'record_node_limit' => self::RECORD_NODE_LIMIT,
                'scanned_nodes' => count($scanned),
                'recorded_nodes' => count($nodes),
                'omitted_scanned_nodes' => count($scanned) - count($nodes),
                'private_edges' => $privateEdges,
                'cycle_edges' => $cycleEdges,
                'truncated_pending_edges' => count($pending),
            ],
        ];
    }

    /**
     * @param array{throwable:list<array<string,mixed>>,traversal:array<string,mixed>} $evidence
     */
    public static function record(
        string $repo,
        string $expectedRootIdentity,
        array $evidence,
        string $command,
        string $reasonCode
    ): void {
        try {
            self::record_checked($repo, $expectedRootIdentity, $evidence, $command, $reasonCode);
        } catch (\Throwable) {
            // Diagnostics must never become a second command failure. This also
            // catches host error handlers that promote filesystem warnings.
        }
    }

    /**
     * @param array{throwable:list<array<string,mixed>>,traversal:array<string,mixed>} $evidence
     */
    private static function record_checked(
        string $repo,
        string $expectedRootIdentity,
        array $evidence,
        string $command,
        string $reasonCode
    ): void {
        $target = self::private_refusal_target($repo, $expectedRootIdentity);
        if ($target === null) {
            return;
        }
        $record = json_encode([
            'format' => self::FORMAT,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'command' => $command,
            'reason_code' => $reasonCode,
            'throwable' => $evidence['throwable'],
            'traversal' => $evidence['traversal'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($record === false) {
            return;
        }
        $nonce = bin2hex(random_bytes(12));
        $label = preg_replace('/[^a-z0-9_-]+/', '-', $command) ?: 'command';
        $path = $target['directory'] . '/' . gmdate('Ymd-His') . '-' . $label . '-' . $nonce . '.json';
        self::write_private_refusal_record($target, $path, $record . "\n");
    }

    /** Return a dev:ino identity only for one ordinary directory path. */
    private static function directory_identity(string $path, ?int $requiredMode = null): ?string {
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }
        clearstatcache(true, $path);
        $stat = self::quietly(static fn() => lstat($path));
        if (!is_array($stat)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0040000
            || ($requiredMode !== null && (((int) ($stat['mode'] ?? 0)) & 0777) !== $requiredMode)) {
            return null;
        }
        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }

    /** Create or bind one exact 0700 directory without following a link. */
    private static function private_refusal_directory(string $path): ?string {
        $identity = self::directory_identity($path, 0700);
        if ($identity !== null) {
            return $identity;
        }
        clearstatcache(true, $path);
        if (self::quietly(static fn() => lstat($path)) !== false) {
            // Do not chmod an existing path: a swap between a link check and a
            // path-based chmod could mutate an object outside the repository.
            return null;
        }
        $priorUmask = umask(0077);
        try {
            $created = self::quietly(static fn() => mkdir($path, 0700));
        } finally {
            umask($priorUmask);
        }
        return $created ? self::directory_identity($path, 0700) : null;
    }

    /**
     * @return ?array{root:string,root_identity:string,control:string,control_identity:string,directory:string,directory_identity:string}
     */
    private static function private_refusal_target(string $repo, string $expectedRootIdentity): ?array {
        $root = rtrim($repo, '/');
        if ($root === '' || self::directory_identity($root) !== $expectedRootIdentity) {
            return null;
        }
        $control = $root . '/.wprism';
        $controlIdentity = self::private_refusal_directory($control);
        if ($controlIdentity === null || self::directory_identity($root) !== $expectedRootIdentity) {
            return null;
        }
        $directory = $control . '/refusals';
        $directoryIdentity = self::private_refusal_directory($directory);
        if ($directoryIdentity === null
            || self::directory_identity($root) !== $expectedRootIdentity
            || self::directory_identity($control, 0700) !== $controlIdentity) {
            return null;
        }
        return [
            'root' => $root,
            'root_identity' => $expectedRootIdentity,
            'control' => $control,
            'control_identity' => $controlIdentity,
            'directory' => $directory,
            'directory_identity' => $directoryIdentity,
        ];
    }

    /** @param array<string,string> $target */
    private static function private_refusal_target_is_bound(array $target): bool {
        return self::directory_identity($target['root']) === $target['root_identity']
            && self::directory_identity($target['control'], 0700) === $target['control_identity']
            && self::directory_identity($target['directory'], 0700) === $target['directory_identity'];
    }

    /** Remove only the exact regular inode this writer created. */
    private static function discard_private_refusal_file(mixed $handle, string $path): void {
        try {
            $opened = is_resource($handle) ? self::quietly(static fn() => fstat($handle)) : false;
            if (is_resource($handle)) {
                self::quietly(static fn() => fclose($handle));
            }
            if (is_array($opened)) {
                self::discard_named_file($path, $opened);
            }
        } catch (\Throwable) {
            // The evidence writer is best-effort even during cleanup.
        }
    }

    /** @param array<string,mixed> $opened */
    private static function discard_named_file(string $path, array $opened): void {
        clearstatcache(true, $path);
        $named = self::quietly(static fn() => lstat($path));
        if (is_array($named)
            && (((int) ($named['mode'] ?? 0)) & 0170000) === 0100000
            && (string) ($opened['dev'] ?? '') === (string) ($named['dev'] ?? '')
            && (string) ($opened['ino'] ?? '') === (string) ($named['ino'] ?? '')) {
            self::quietly(static fn() => unlink($path));
        }
    }

    /** @param array<string,string> $target */
    private static function write_private_refusal_record(array $target, string $path, string $bytes): void {
        $handle = null;
        try {
            if (!self::private_refusal_target_is_bound($target)) {
                return;
            }
            $priorUmask = umask(0077);
            try {
                $handle = self::quietly(static fn() => fopen($path, 'x+b'));
            } finally {
                umask($priorUmask);
            }
            if (!is_resource($handle)) {
                return;
            }
            $opened = self::quietly(static fn() => fstat($handle));
            if (!is_array($opened)
                || (((int) ($opened['mode'] ?? 0)) & 0170000) !== 0100000
                // PHP exposes no descriptor-scoped chmod. Exclusive creation
                // under umask 0077 must yield final mode before any content.
                || (((int) ($opened['mode'] ?? 0)) & 0777) !== 0600) {
                self::discard_private_refusal_file($handle, $path);
                $handle = null;
                return;
            }
            clearstatcache(true, $path);
            $named = self::quietly(static fn() => lstat($path));
            if (!is_array($named)
                || (((int) ($named['mode'] ?? 0)) & 0170000) !== 0100000
                || (((int) ($named['mode'] ?? 0)) & 0777) !== 0600
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || !self::private_refusal_target_is_bound($target)) {
                self::discard_private_refusal_file($handle, $path);
                $handle = null;
                return;
            }

            $length = strlen($bytes);
            $offset = 0;
            while ($offset < $length) {
                $written = self::quietly(static fn() => fwrite($handle, substr($bytes, $offset)));
                if (!is_int($written) || $written <= 0) {
                    self::discard_private_refusal_file($handle, $path);
                    $handle = null;
                    return;
                }
                $offset += $written;
            }
            if (!self::quietly(static fn() => fflush($handle))
                || (function_exists('fsync') && !self::quietly(static fn() => fsync($handle)))) {
                self::discard_private_refusal_file($handle, $path);
                $handle = null;
                return;
            }
            $opened = self::quietly(static fn() => fstat($handle));
            if (!is_array($opened)
                || (((int) ($opened['mode'] ?? 0)) & 0777) !== 0600
                || !self::private_refusal_target_is_bound($target)) {
                self::discard_private_refusal_file($handle, $path);
                $handle = null;
                return;
            }
            self::quietly(static fn() => fclose($handle));
            $handle = null;
            clearstatcache(true, $path);
            $named = self::quietly(static fn() => lstat($path));
            if (!is_array($named)
                || (((int) ($named['mode'] ?? 0)) & 0170000) !== 0100000
                || (((int) ($named['mode'] ?? 0)) & 0777) !== 0600
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || !self::private_refusal_target_is_bound($target)) {
                self::discard_named_file($path, $opened);
            }
        } catch (\Throwable) {
            self::discard_private_refusal_file($handle, $path);
        }
    }

    /** Run one warning-prone primitive without invoking the host error handler. */
    private static function quietly(callable $operation): mixed {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
