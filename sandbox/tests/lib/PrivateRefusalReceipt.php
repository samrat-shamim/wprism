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

    /** @param array<string,mixed> $profile */
    public static function snapshot(string $directory, array $profile): string {
        self::checkProfile($profile);
        return json_encode(self::inventory($directory, $profile['command']), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
        try {
            $record = json_decode(self::read($directory . '/' . $new[0]), true, 32, JSON_THROW_ON_ERROR);
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
            || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $profile['command']) !== 1
            || !is_string($profile['reason_code'])
            || preg_match('/\A[a-z][a-z0-9_]{0,127}\z/', $profile['reason_code']) !== 1
            || !is_array($profile['nodes']) || !array_is_list($profile['nodes'])
            || count($profile['nodes']) < 1 || count($profile['nodes']) > self::NODE_LIMIT) {
            self::fail('verification profile is not one closed bounded declaration');
        }
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
