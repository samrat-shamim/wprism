<?php
declare(strict_types=1);

namespace WPrismTest;

/**
 * Test-only reader for a caller-declared exact private refusal graph.
 *
 * spec/repo-format.md bounds v2 at 64 recorded nodes, 4096 bytes per field
 * and 262144 bytes per record. A fixture cannot turn a public refusal or an
 * old matching record into evidence for this invocation: snapshot immediately
 * before the command, then verify one new command-scoped file as the target
 * CLI identity, without loading WordPress. This class never selects a cause.
 */
final class PrivateRefusalReceipt {
    private const RECORD_LIMIT = 262144;
    private const NODE_LIMIT = 64;
    private const FIELD_LIMIT = 4096;
    // Diagnostic inventory is finite too. Exceeding these fixture bounds is a
    // loud evidence failure, never permission to prune operator records.
    private const DIRECTORY_ENTRY_LIMIT = 4096;
    private const BASELINE_LIMIT = 1048576;
    private const DIAGNOSTIC_RECORD_LIMIT = 4;
    private const DIAGNOSTIC_BYTES_LIMIT = 1048576;

    /** @param array<string,mixed> $profile */
    public static function snapshot(string $directory, array $profile): string {
        self::checkProfile($profile);
        return self::diagnosticSnapshot($directory, $profile['command']);
    }

    /**
     * Snapshot command-scoped filenames without declaring an expected cause.
     *
     * This is only a freshness baseline for a later diagnostic capture. It is
     * deliberately separate from verify(), which is the sole exact-cause
     * evidence path.
     */
    public static function diagnosticSnapshot(string $directory, string $command): string {
        self::checkCommand($command);
        return json_encode(self::inventory($directory, $command), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Validate a transported baseline before its protected command may run. */
    public static function validateDiagnosticBaseline(string $baselineJson, string $command): void {
        self::checkCommand($command);
        self::baseline($baselineJson, $command);
    }

    /**
     * Retain bounded new records for private diagnosis without verifying them.
     *
     * The returned bytes can contain operator material and belong only in a
     * mode-0600 diagnostic sink. A distinct format plus explicit false marker
     * prevents this capture from masquerading as verify()'s expected-cause
     * receipt.
     */
    public static function diagnosticNewRecords(string $directory, string $baselineJson, string $command): string {
        self::checkCommand($command);
        $baseline = self::baseline($baselineJson, $command);
        $current = self::inventory($directory, $command);
        if (array_diff($baseline, $current) !== []) {
            self::fail('the invocation removed prior refusal evidence');
        }
        $new = array_values(array_diff($current, $baseline));
        if (count($new) > self::DIAGNOSTIC_RECORD_LIMIT) {
            self::fail('the invocation exceeded the private diagnostic record boundary');
        }
        $records = [];
        $totalBytes = 0;
        foreach ($new as $name) {
            $bytes = self::read($directory . '/' . $name);
            $totalBytes += strlen($bytes);
            if ($totalBytes > self::DIAGNOSTIC_BYTES_LIMIT) {
                self::fail('the invocation exceeded the private diagnostic byte boundary');
            }
            $records[] = [
                'bytes' => strlen($bytes),
                'contents_base64' => base64_encode($bytes),
                'name' => $name,
                'sha256' => hash('sha256', $bytes),
            ];
        }
        return json_encode([
            'command' => $command,
            'format' => 'wprism-private-refusal-diagnostic/v1',
            'new_records' => count($records),
            'purpose' => 'diagnostic_only',
            'records' => $records,
            'verified' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $profile */
    public static function verify(string $directory, string $baselineJson, array $profile): string {
        self::checkProfile($profile);
        $baseline = self::baseline($baselineJson, $profile['command']);
        $current = self::inventory($directory, $profile['command']);
        if (array_diff($baseline, $current) !== []) {
            self::fail('the invocation removed prior refusal evidence');
        }
        $new = array_values(array_diff($current, $baseline));
        if (count($current) !== count($baseline) + 1 || count($new) !== 1) {
            self::fail('the invocation did not append exactly one ' . $profile['command'] . ' refusal record');
        }
        return self::verifyRecord(self::read($directory . '/' . $new[0]), $profile);
    }

    /** Retain the exact bytes being verified, including when the cause is wrong. */
    public static function collect(string $directory, string $baselineJson, array $profile): string {
        self::checkProfile($profile);
        $diagnostic = json_decode(self::diagnosticNewRecords($directory, $baselineJson, $profile['command']), true, 32, JSON_THROW_ON_ERROR);
        $receipt = $error = null;
        try {
            $receipt = json_decode(self::verifyDiagnostic($diagnostic, $profile), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            $error = ['class' => get_class($failure), 'message_sha256' => hash('sha256', $failure->getMessage())];
        }
        return json_encode(['format' => 'wprism-private-refusal-collection/v1', 'diagnostic' => $diagnostic,
            'receipt' => $receipt, 'error' => $error], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Re-verify transported raw bytes; a copied digest receipt is insufficient. */
    public static function assertCollection(array $collection, array $profile): void {
        if (!self::exactKeys($collection, ['diagnostic', 'error', 'format', 'receipt'])
            || $collection['format'] !== 'wprism-private-refusal-collection/v1' || $collection['error'] !== null
            || !is_array($collection['diagnostic']) || !is_array($collection['receipt'])) {
            self::fail('the retained collection does not prove its exact cause');
        }
        $receipt = json_decode(self::verifyDiagnostic($collection['diagnostic'], $profile), true, 32, JSON_THROW_ON_ERROR);
        if ($collection['receipt'] !== $receipt) self::fail('the retained receipt does not match its raw cause graph');
    }

    private static function verifyDiagnostic(array $diagnostic, array $profile): string {
        self::checkProfile($profile);
        if (!self::exactKeys($diagnostic, ['command', 'format', 'new_records', 'purpose', 'records', 'verified'])
            || $diagnostic['command'] !== $profile['command'] || $diagnostic['format'] !== 'wprism-private-refusal-diagnostic/v1'
            || $diagnostic['new_records'] !== 1 || $diagnostic['purpose'] !== 'diagnostic_only' || $diagnostic['verified'] !== false
            || !is_array($diagnostic['records']) || !array_is_list($diagnostic['records']) || count($diagnostic['records']) !== 1) {
            self::fail('the retained diagnostic does not contain exactly one fresh command record');
        }
        $raw = $diagnostic['records'][0];
        if (!is_array($raw) || !self::exactKeys($raw, ['bytes', 'contents_base64', 'name', 'sha256'])
            || !is_string($raw['name']) || !self::validName($raw['name'], $profile['command'])
            || !is_int($raw['bytes']) || $raw['bytes'] < 1 || $raw['bytes'] > self::RECORD_LIMIT
            || !is_string($raw['contents_base64']) || strlen($raw['contents_base64']) > 4 * (int) ceil(self::RECORD_LIMIT / 3)
            || !is_string($raw['sha256'])) {
            self::fail('the retained diagnostic record is malformed or unbounded');
        }
        $bytes = base64_decode($raw['contents_base64'], true);
        if (!is_string($bytes) || base64_encode($bytes) !== $raw['contents_base64'] || strlen($bytes) !== $raw['bytes']
            || hash('sha256', $bytes) !== $raw['sha256']) self::fail('the retained diagnostic bytes do not match their identity');
        return self::verifyRecord($bytes, $profile);
    }

    private static function verifyRecord(string $bytes, array $profile): string {
        try {
            $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('the appended refusal record is not bounded JSON');
        }
        if (!is_array($record)
            || ($record['format'] ?? null) !== 'wprism-private-refusal-evidence/v2'
            || ($record['command'] ?? null) !== $profile['command']
            || ($record['reason_code'] ?? null) !== $profile['reason_code']
            || !is_array($record['traversal'] ?? null)
            || ($record['traversal']['scan_complete'] ?? null) !== true
            || ($record['traversal']['record_complete'] ?? null) !== true) {
            self::fail('the appended refusal record has an incomplete envelope');
        }
        $nodes = $record['throwable'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) !== count($profile['nodes'])) {
            self::fail('the appended refusal record does not retain the exact complete cause graph');
        }
        $privateEdges = 0;
        foreach ($profile['nodes'] as $index => $expected) {
            $node = $nodes[$index];
            if (!is_array($node)
                || ($node['index'] ?? null) !== $index
                || !array_key_exists('parent_index', $node)
                || $node['parent_index'] !== $expected['parent_index']
                || ($node['parent_recorded'] ?? null) !== true
                || ($node['relation'] ?? null) !== $expected['relation']
                || !self::completeField($node, 'class', $expected['class'])
                || !self::completeField($node, 'message', $expected['message'])) {
                self::fail('the appended refusal record does not retain the exact complete cause graph');
            }
            if ($expected['relation'] === 'private_evidence') {
                $privateEdges++;
            }
        }
        $count = count($nodes);
        foreach ([
            'scanned_nodes' => $count,
            'recorded_nodes' => $count,
            'private_edges' => $privateEdges,
            'examined_edges' => $count - 1,
            'enqueued_edges' => $count - 1,
            'omitted_scanned_nodes' => 0,
            'omitted_for_byte_limit' => 0,
            'invalid_private_edges' => 0,
            'truncated_edges' => 0,
            'cycle_edges' => 0,
            'truncated_pending_edges' => 0,
            'graph_errors' => 0,
        ] as $field => $expected) {
            if (($record['traversal'][$field] ?? null) !== $expected) {
                self::fail('the appended cause graph has an incomplete traversal witness');
            }
        }
        return json_encode([
            'command' => $profile['command'],
            'format' => 'wprism-private-refusal-check/v1',
            'new_records' => 1,
            'node_message_sha256' => array_map(
                static fn(array $node): string => hash('sha256', $node['message']),
                $profile['nodes']
            ),
            'verified' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $profile */
    private static function checkProfile(array $profile): void {
        if (!self::exactKeys($profile, ['command', 'nodes', 'reason_code'])
            || !is_string($profile['command'])
            || !is_string($profile['reason_code'])
            || preg_match('/\A[a-z][a-z0-9_]{0,127}\z/', $profile['reason_code']) !== 1
            || !is_array($profile['nodes']) || !array_is_list($profile['nodes'])
            || count($profile['nodes']) < 1 || count($profile['nodes']) > self::NODE_LIMIT) {
            self::fail('verification profile is not one closed bounded declaration');
        }
        self::checkCommand($profile['command']);
        foreach ($profile['nodes'] as $index => $node) {
            if (!is_array($node)
                || !self::exactKeys($node, ['class', 'message', 'parent_index', 'relation'])
                || !is_string($node['class']) || strlen($node['class']) < 1
                || strlen($node['class']) > self::FIELD_LIMIT || preg_match('//u', $node['class']) !== 1
                || !is_string($node['message']) || strlen($node['message']) > self::FIELD_LIMIT
                || preg_match('//u', $node['message']) !== 1
                || ($index === 0
                    ? $node['parent_index'] !== null || $node['relation'] !== 'root'
                    : !is_int($node['parent_index']) || $node['parent_index'] < 0 || $node['parent_index'] >= $index
                        || !in_array($node['relation'], ['previous', 'private_evidence'], true))) {
                self::fail('verification profile does not declare an exact bounded cause tree');
            }
        }
    }

    private static function checkCommand(string $command): void {
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $command) !== 1) {
            self::fail('command is not one bounded canonical name');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): bool {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys === $expected;
    }

    private static function validName(string $name, string $command): bool {
        return preg_match('/\A[0-9]{8}-[0-9]{6}-' . preg_quote($command, '/') . '-[a-f0-9]{24}\.json\z/', $name) === 1;
    }

    /** @return list<string> */
    private static function inventory(string $directory, string $command): array {
        clearstatcache(true, $directory);
        $before = @lstat($directory);
        if ($before === false) {
            return [];
        }
        if (!self::ordinary($before, 0040000, 0700)) {
            self::fail('refusal directory is not one ordinary 0700 directory');
        }
        $handle = @opendir($directory);
        if ($handle === false) {
            self::fail('refusal directory cannot be read by the target CLI identity');
        }
        $names = [];
        $entries = 0;
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (++$entries > self::DIRECTORY_ENTRY_LIMIT) {
                    self::fail('refusal directory exceeds its fixed entry boundary');
                }
                if (!str_contains($name, '-' . $command . '-')) {
                    continue;
                }
                if (!self::validName($name, $command)) {
                    self::fail($command . ' refusal record has a noncanonical name');
                }
                clearstatcache(true, $directory . '/' . $name);
                $stat = @lstat($directory . '/' . $name);
                if (!is_array($stat) || !self::ordinary($stat, 0100000, 0600)) {
                    self::fail($command . ' refusal record is not one ordinary 0600 file');
                }
                $names[] = $name;
            }
        } finally {
            closedir($handle);
        }
        clearstatcache(true, $directory);
        $after = @lstat($directory);
        if (!is_array($after) || !self::ordinary($after, 0040000, 0700) || !self::sameInode($before, $after)) {
            self::fail('refusal directory changed during inventory');
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return list<string> */
    private static function baseline(string $encoded, string $command): array {
        if (strlen($encoded) > self::BASELINE_LIMIT) {
            self::fail('baseline exceeds its fixed byte boundary');
        }
        try {
            $baseline = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('baseline is not canonical JSON');
        }
        if (!is_array($baseline) || !array_is_list($baseline) || count($baseline) > self::DIRECTORY_ENTRY_LIMIT) {
            self::fail('baseline is not one bounded JSON list');
        }
        if (json_encode($baseline, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !== $encoded) {
            self::fail('baseline is not canonical JSON');
        }
        foreach ($baseline as $name) {
            if (!is_string($name) || !self::validName($name, $command)) {
                self::fail('baseline contains a noncanonical record name');
            }
        }
        $canonical = $baseline;
        sort($canonical, SORT_STRING);
        if ($canonical !== $baseline || count(array_unique($baseline)) !== count($baseline)) {
            self::fail('baseline record names are not sorted and unique');
        }
        return $baseline;
    }

    private static function read(string $path): string {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            self::fail('the appended refusal record could not be opened');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $named = @lstat($path);
            if (!is_array($opened) || !is_array($named)
                || !self::ordinary($opened, 0100000, 0600) || !self::ordinary($named, 0100000, 0600)
                || !self::sameInode($opened, $named)) {
                self::fail('the appended refusal path does not name its opened ordinary 0600 inode');
            }
            $size = $opened['size'] ?? -1;
            if (!is_int($size) || $size < 1 || $size > self::RECORD_LIMIT) {
                self::fail('the appended refusal record is outside its fixed byte boundary');
            }
            $bytes = stream_get_contents($handle, $size + 1);
            $finished = fstat($handle);
            clearstatcache(true, $path);
            $final = @lstat($path);
            if (!is_string($bytes) || strlen($bytes) !== $size || !is_array($finished) || !is_array($final)
                || !self::ordinary($final, 0100000, 0600) || !self::ordinary($finished, 0100000, 0600)
                || !self::sameInode($opened, $final) || ($finished['size'] ?? null) !== $size
                || ($final['size'] ?? null) !== $size
                || ($finished['mtime'] ?? null) !== ($opened['mtime'] ?? null)
                || ($finished['ctime'] ?? null) !== ($opened['ctime'] ?? null)) {
                self::fail('the appended refusal record could not be read exactly without changing');
            }
            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $stat */
    private static function ordinary(array $stat, int $type, int $mode): bool {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === $type
            && (((int) ($stat['mode'] ?? 0)) & 0777) === $mode;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameInode(array $left, array $right): bool {
        return (string) ($left['dev'] ?? '') === (string) ($right['dev'] ?? '')
            && (string) ($left['ino'] ?? '') === (string) ($right['ino'] ?? '');
    }

    /** @param array<string,mixed> $node */
    private static function completeField(array $node, string $field, string $expected): bool {
        return ($node[$field] ?? null) === $expected
            && ($node[$field . '_encoding'] ?? null) === 'utf-8'
            && ($node[$field . '_original_bytes'] ?? null) === strlen($expected)
            && ($node[$field . '_sha256'] ?? null) === hash('sha256', $expected)
            && ($node[$field . '_truncated'] ?? null) === false;
    }

    private static function fail(string $message): never {
        throw new \RuntimeException('private refusal receipt: ' . $message);
    }
}
