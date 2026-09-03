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
 * crossing point, and only into a 0600 file below a bound 0700 refusal
 * directory. Its existing `.wprism` parent may use the product's historical
 * non-writable 0755 mode or the sticky shared-harness mode, but an ordinary
 * group/world-writable parent is never trusted.
 *
 * The caller owns repository eligibility. It passes the root and qualifying
 * marker identities it reviewed, and this service refuses every write if
 * either inode or either inner directory changes. Recording is diagnostic only
 * and therefore best-effort: no warning, custom error handler, race, or
 * filesystem failure may replace the command refusal being diagnosed.
 */
final class PrivateRefusalEvidence {
    public const FORMAT = 'wprism-private-refusal-evidence/v2';
    private const SCAN_NODE_LIMIT = 256;
    private const SCAN_EDGE_LIMIT = 512;
    private const RECORD_NODE_LIMIT = 64;
    private const FIELD_BYTE_LIMIT = 4096;
    private const GRAPH_BYTE_LIMIT = 131072;
    private const RECORD_BYTE_LIMIT = 262144;

    /**
     * Scan a bounded Throwable graph, then retain root plus private-cause paths
     * ahead of ordinary wrapper detail. The separate scan and record limits
     * keep a carrier behind a long printable chain while making every omission,
     * cycle, and truncated edge explicit.
     *
     * @return array{
     *   throwable:list<array<string,mixed>>,
     *   traversal:array<string,int|bool>
     * }
     */
    public static function graph(\Throwable $root): array {
        try {
            return self::graph_checked($root);
        } catch (\Throwable $graphFailure) {
            // Refusal rendering is the primary path. Even a corrupted private
            // carrier must leave a bounded, explicitly incomplete witness
            // instead of replacing the refusal with a diagnostic failure.
            return self::fallback_graph($root, $graphFailure);
        }
    }

    /**
     * @return array{
     *   throwable:list<array<string,mixed>>,
     *   traversal:array<string,int|bool>
     * }
     */
    private static function graph_checked(\Throwable $root): array {
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
        $invalidPrivateEdges = 0;
        $cycleEdges = 0;
        $examinedEdges = 0;
        $enqueuedEdges = 0;
        $truncatedEdges = 0;

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
                $privateCauses = $cause->private_evidence_causes();
                $privateEdges += count($privateCauses);
                $privatePosition = 0;
                foreach ($privateCauses as $privateCause) {
                    if ($examinedEdges >= self::SCAN_EDGE_LIMIT) {
                        $truncatedEdges += count($privateCauses) - $privatePosition;
                        break;
                    }
                    $privatePosition++;
                    $examinedEdges++;
                    if (!$privateCause instanceof \Throwable) {
                        $invalidPrivateEdges++;
                        continue;
                    }
                    $pending[] = [
                        'throwable' => $privateCause,
                        'parent_index' => $index,
                        'relation' => 'private_evidence',
                        'private_path' => true,
                    ];
                    $enqueuedEdges++;
                }
            }
            $previous = $cause->getPrevious();
            if ($previous !== null) {
                if ($examinedEdges >= self::SCAN_EDGE_LIMIT) {
                    $truncatedEdges++;
                } else {
                    $examinedEdges++;
                    $pending[] = [
                        'throwable' => $previous,
                        'parent_index' => $index,
                        'relation' => 'previous',
                        'private_path' => $entry['private_path'],
                    ];
                    $enqueuedEdges++;
                }
            }
        }

        $priority = [];
        if ($scanned !== []) {
            $priority[] = 0;
        }
        foreach ($scanned as $index => $entry) {
            if ($index !== 0
                && ($entry['private_path'] || $entry['throwable'] instanceof PrivateEvidenceException)) {
                $priority[] = $index;
            }
        }
        foreach (array_keys($scanned) as $index) {
            if (!in_array($index, $priority, true)) {
                $priority[] = $index;
            }
        }

        /** @var array<int,array<string,mixed>> $selectedNodes */
        $selectedNodes = [];
        $graphBytes = 0;
        $omittedForBytes = 0;
        foreach ($priority as $index) {
            if (count($selectedNodes) >= self::RECORD_NODE_LIMIT) {
                break;
            }
            $entry = $scanned[$index];
            $node = self::evidence_node($entry, $index);
            $encodedNode = json_encode(
                $node,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $nodeBytes = is_string($encodedNode) ? strlen($encodedNode) : self::GRAPH_BYTE_LIMIT + 1;
            if ($graphBytes + $nodeBytes > self::GRAPH_BYTE_LIMIT) {
                $omittedForBytes++;
                continue;
            }
            $selectedNodes[$index] = $node;
            $graphBytes += $nodeBytes;
        }
        ksort($selectedNodes, SORT_NUMERIC);
        $nodes = [];
        foreach ($selectedNodes as $index => $node) {
            $parent = $node['parent_index'];
            $node['parent_recorded'] = $parent === null || isset($selectedNodes[$parent]);
            $nodes[] = $node;
        }
        $scanComplete = $pending === [] && $invalidPrivateEdges === 0 && $truncatedEdges === 0;
        return [
            'throwable' => $nodes,
            'traversal' => [
                'scan_complete' => $scanComplete,
                'record_complete' => $scanComplete && count($nodes) === count($scanned),
                'scan_node_limit' => self::SCAN_NODE_LIMIT,
                'scan_edge_limit' => self::SCAN_EDGE_LIMIT,
                'record_node_limit' => self::RECORD_NODE_LIMIT,
                'field_byte_limit' => self::FIELD_BYTE_LIMIT,
                'graph_byte_limit' => self::GRAPH_BYTE_LIMIT,
                'record_byte_limit' => self::RECORD_BYTE_LIMIT,
                'scanned_nodes' => count($scanned),
                'recorded_nodes' => count($nodes),
                'omitted_scanned_nodes' => count($scanned) - count($nodes),
                'omitted_for_byte_limit' => $omittedForBytes,
                'graph_bytes' => $graphBytes,
                'private_edges' => $privateEdges,
                'invalid_private_edges' => $invalidPrivateEdges,
                'examined_edges' => $examinedEdges,
                'enqueued_edges' => $enqueuedEdges,
                'truncated_edges' => $truncatedEdges,
                'cycle_edges' => $cycleEdges,
                'truncated_pending_edges' => count($pending) + $truncatedEdges,
                'graph_errors' => 0,
            ],
        ];
    }

    /**
     * @param array{throwable:\Throwable,parent_index:?int,relation:string,private_path:bool} $entry
     * @return array<string,mixed>
     */
    private static function evidence_node(array $entry, int $index): array {
        $cause = $entry['throwable'];
        return [
            'index' => $index,
            'parent_index' => $entry['parent_index'],
            'parent_recorded' => false,
            'relation' => $entry['relation'],
            ...self::bounded_field('class', get_class($cause)),
            ...self::bounded_field('message', $cause->getMessage()),
            ...self::bounded_field('file', $cause->getFile()),
            'line' => $cause->getLine(),
        ];
    }

    /** @return array<string,int|string|bool> */
    private static function bounded_field(string $name, string $value): array {
        $originalBytes = strlen($value);
        return [
            $name => $originalBytes > self::FIELD_BYTE_LIMIT
                ? substr($value, 0, self::FIELD_BYTE_LIMIT)
                : $value,
            $name . '_original_bytes' => $originalBytes,
            $name . '_sha256' => hash('sha256', $value),
            $name . '_truncated' => $originalBytes > self::FIELD_BYTE_LIMIT,
        ];
    }

    /**
     * @return array{
     *   throwable:list<array<string,mixed>>,
     *   traversal:array<string,int|bool>
     * }
     */
    private static function fallback_graph(\Throwable $root, \Throwable $graphFailure): array {
        $rootNode = self::evidence_node([
            'throwable' => $root,
            'parent_index' => null,
            'relation' => 'root',
            'private_path' => false,
        ], 0);
        $rootNode['parent_recorded'] = true;
        $failureNode = self::evidence_node([
            'throwable' => $graphFailure,
            'parent_index' => 0,
            'relation' => 'graph_failure',
            'private_path' => true,
        ], 1);
        $failureNode['parent_recorded'] = true;
        $nodes = [$rootNode, $failureNode];
        $graphBytes = strlen((string) json_encode(
            $nodes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        return [
            'throwable' => $nodes,
            'traversal' => [
                'scan_complete' => false,
                'record_complete' => false,
                'scan_node_limit' => self::SCAN_NODE_LIMIT,
                'scan_edge_limit' => self::SCAN_EDGE_LIMIT,
                'record_node_limit' => self::RECORD_NODE_LIMIT,
                'field_byte_limit' => self::FIELD_BYTE_LIMIT,
                'graph_byte_limit' => self::GRAPH_BYTE_LIMIT,
                'record_byte_limit' => self::RECORD_BYTE_LIMIT,
                'scanned_nodes' => 2,
                'recorded_nodes' => 2,
                'omitted_scanned_nodes' => 0,
                'omitted_for_byte_limit' => 0,
                'graph_bytes' => $graphBytes,
                'private_edges' => 0,
                'invalid_private_edges' => 0,
                'examined_edges' => 0,
                'enqueued_edges' => 0,
                'truncated_edges' => 1,
                'cycle_edges' => 0,
                'truncated_pending_edges' => 1,
                'graph_errors' => 1,
            ],
        ];
    }

    /**
     * @param array{root_identity:string,qualifier_path:string,qualifier_identity:string,qualifier_type:string} $repositoryWitness
     */
    public static function record(
        string $repo,
        array $repositoryWitness,
        \Throwable $root,
        string $command,
        string $reasonCode
    ): void {
        try {
            self::record_checked($repo, $repositoryWitness, $root, $command, $reasonCode);
        } catch (\Throwable) {
            // Diagnostics must never become a second command failure. This also
            // catches host error handlers that promote filesystem warnings.
        }
    }

    /**
     * @param array{root_identity:string,qualifier_path:string,qualifier_identity:string,qualifier_type:string} $repositoryWitness
     */
    private static function record_checked(
        string $repo,
        array $repositoryWitness,
        \Throwable $root,
        string $command,
        string $reasonCode
    ): void {
        $target = self::private_refusal_target($repo, $repositoryWitness);
        if ($target === null) {
            return;
        }
        $evidence = self::graph($root);
        $record = json_encode([
            'format' => self::FORMAT,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'command' => $command,
            'reason_code' => $reasonCode,
            'throwable' => $evidence['throwable'],
            'traversal' => $evidence['traversal'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($record === false || strlen($record) + 1 > self::RECORD_BYTE_LIMIT) {
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
     * Bind the authority root without mutating its path-based mode.
     *
     * Existing adoptions may have published `.wprism` as 0755, while a dev
     * pair needs a sticky shared parent for the host runner and container uid.
     * Both prevent an unrelated user from replacing the 0700 refusal child;
     * plain group/world write authority does not. A missing root is 0700.
     */
    private static function private_refusal_control_directory(string $path): ?string {
        $identity = self::trusted_parent_identity($path);
        if ($identity !== null) {
            return $identity;
        }
        clearstatcache(true, $path);
        if (self::quietly(static fn() => lstat($path)) !== false) {
            return null;
        }
        $priorUmask = umask(0077);
        try {
            $created = self::quietly(static fn() => mkdir($path, 0700));
        } finally {
            umask($priorUmask);
        }
        return $created ? self::trusted_parent_identity($path) : null;
    }

    /** A regular directory whose write bits are absent or sticky-bit bounded. */
    private static function trusted_parent_identity(string $path): ?string {
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }
        clearstatcache(true, $path);
        $stat = self::quietly(static fn() => lstat($path));
        if (!is_array($stat) || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0040000) {
            return null;
        }
        $mode = ((int) $stat['mode']) & 07777;
        if (($mode & 0022) !== 0 && ($mode & 01000) === 0) {
            return null;
        }
        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }

    /**
     * @param array{root_identity:string,qualifier_path:string,qualifier_identity:string,qualifier_type:string} $repositoryWitness
     * @return ?array{root:string,root_identity:string,qualifier_path:string,qualifier_identity:string,qualifier_type:string,control:string,control_identity:string,directory:string,directory_identity:string}
     */
    private static function private_refusal_target(string $repo, array $repositoryWitness): ?array {
        $root = rtrim($repo, '/');
        $expectedRootIdentity = $repositoryWitness['root_identity'];
        if ($root === ''
            || !self::repository_qualifier_is_bound($root, $repositoryWitness)
            || self::directory_identity($root) !== $expectedRootIdentity) {
            return null;
        }
        $control = $root . '/.wprism';
        $controlIdentity = self::private_refusal_control_directory($control);
        if ($controlIdentity === null
            || self::directory_identity($root) !== $expectedRootIdentity
            || !self::repository_qualifier_is_bound($root, $repositoryWitness)) {
            return null;
        }
        $directory = $control . '/refusals';
        $directoryIdentity = self::private_refusal_directory($directory);
        if ($directoryIdentity === null
            || self::directory_identity($root) !== $expectedRootIdentity
            || self::trusted_parent_identity($control) !== $controlIdentity
            || !self::repository_qualifier_is_bound($root, $repositoryWitness)) {
            return null;
        }
        return [
            'root' => $root,
            'root_identity' => $expectedRootIdentity,
            'qualifier_path' => $repositoryWitness['qualifier_path'],
            'qualifier_identity' => $repositoryWitness['qualifier_identity'],
            'qualifier_type' => $repositoryWitness['qualifier_type'],
            'control' => $control,
            'control_identity' => $controlIdentity,
            'directory' => $directory,
            'directory_identity' => $directoryIdentity,
        ];
    }

    /** @param array<string,string> $target */
    private static function private_refusal_target_is_bound(array $target): bool {
        return self::directory_identity($target['root']) === $target['root_identity']
            && self::repository_qualifier_is_bound($target['root'], $target)
            && self::trusted_parent_identity($target['control']) === $target['control_identity']
            && self::directory_identity($target['directory'], 0700) === $target['directory_identity'];
    }

    /** @param array<string,string> $witness */
    private static function repository_qualifier_is_bound(string $root, array $witness): bool {
        $type = $witness['qualifier_type'] ?? '';
        $expectedPath = match ($type) {
            'site' => $root . '/site.wprism.json',
            'control' => $root . '/.wprism',
            default => '',
        };
        if ($expectedPath === '' || ($witness['qualifier_path'] ?? '') !== $expectedPath) {
            return false;
        }
        $identity = $type === 'site'
            ? self::regular_file_identity($expectedPath)
            : self::directory_identity($expectedPath);
        return $identity !== null && $identity === ($witness['qualifier_identity'] ?? '');
    }

    /** Return a dev:ino identity only for one ordinary regular-file path. */
    private static function regular_file_identity(string $path): ?string {
        clearstatcache(true, $path);
        $stat = self::quietly(static fn() => lstat($path));
        if (!is_array($stat) || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000) {
            return null;
        }
        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }

    /** Remove only the exact regular inode this writer created. */
    private static function discard_private_refusal_file(mixed $handle, string $path): void {
        try {
            $opened = is_resource($handle) ? self::quietly(static fn() => fstat($handle)) : false;
            if (is_resource($handle)) {
                // A parent renamed after the pre-write checks can move the open
                // inode beyond the bound tree. Erase through the descriptor
                // before close; pathname-only unlink cannot reach that inode
                // once an attacker has replaced the old name.
                self::quietly(static fn() => ftruncate($handle, 0));
                self::quietly(static fn() => fflush($handle));
                if (function_exists('fsync')) {
                    self::quietly(static fn() => fsync($handle));
                }
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

    /**
     * @param array<string,string> $target
     * @param ?\Closure():void $afterFirstWrite Boundary seam for deterministic race regression only.
     */
    private static function write_private_refusal_record(
        array $target,
        string $path,
        string $bytes,
        ?\Closure $afterFirstWrite = null
    ): void {
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
            $firstWrite = true;
            while ($offset < $length) {
                $written = self::quietly(static fn() => fwrite($handle, substr($bytes, $offset)));
                if (!is_int($written) || $written <= 0) {
                    self::discard_private_refusal_file($handle, $path);
                    $handle = null;
                    return;
                }
                $offset += $written;
                if ($firstWrite && $afterFirstWrite !== null) {
                    $firstWrite = false;
                    $afterFirstWrite();
                }
            }
            if (!self::quietly(static fn() => fflush($handle))
                || (function_exists('fsync') && !self::quietly(static fn() => fsync($handle)))) {
                self::discard_private_refusal_file($handle, $path);
                $handle = null;
                return;
            }
            $opened = self::quietly(static fn() => fstat($handle));
            clearstatcache(true, $path);
            $named = self::quietly(static fn() => lstat($path));
            if (!is_array($opened)
                || (((int) ($opened['mode'] ?? 0)) & 0777) !== 0600
                || !is_array($named)
                || (((int) ($named['mode'] ?? 0)) & 0170000) !== 0100000
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || !self::private_refusal_target_is_bound($target)) {
                self::discard_private_refusal_file($handle, $path);
                $handle = null;
                return;
            }
            self::quietly(static fn() => fclose($handle));
            $handle = null;
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
