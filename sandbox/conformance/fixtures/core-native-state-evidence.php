<?php
declare(strict_types=1);

/** Private diagnostic rows are transport evidence, never a native-state acceptance policy. */
function core_native_state_evidence_projection(array $record): array {
    $tables = ['posts', 'postmeta', 'comments', 'commentmeta', 'term_relationships',
        'terms', 'termmeta', 'term_taxonomy', 'options', 'wprism_map', 'wprism_state', 'wprism_kv', 'wprism_journal'];
    if (array_keys($record) !== ['format', 'purpose', 'verified', 'tables', 'witness']
        || $record['format'] !== 'wprism-core-native-state-diagnostic/v1'
        || $record['purpose'] !== 'diagnostic_only' || $record['verified'] !== false
        || !is_array($record['tables']) || array_keys($record['tables']) !== $tables
        || !is_array($record['witness'])) {
        throw new RuntimeException('core native diagnostic has an invalid envelope');
    }
    $witness = [];
    foreach ($record['tables'] as $table => $rows) {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 4096) {
            throw new RuntimeException('core native diagnostic exceeds its row boundary');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || $row === [] || array_is_list($row)) {
                throw new RuntimeException('core native diagnostic has an invalid row');
            }
            foreach ($row as $column => $value) {
                if (!is_string($column) || (!is_scalar($value) && $value !== null)) {
                    throw new RuntimeException('core native diagnostic has an invalid column');
                }
            }
        }
        $bytes = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 1048576) {
            throw new RuntimeException('core native diagnostic exceeds its table byte boundary');
        }
        $witness[$table] = ['count' => count($rows), 'sha256' => hash('sha256', $bytes)];
    }
    $restorable = [];
    foreach ($record['tables']['wprism_map'] as $row) {
        if (!is_string($row['id_kind'] ?? null)) {
            throw new RuntimeException('core native diagnostic lacks the map kind');
        }
        if (in_array($row['id_kind'], ['post', 'term', 'term_taxonomy'], true)) {
            $restorable[] = $row;
        }
    }
    $witness['restorable_map'] = ['count' => count($restorable), 'sha256' => hash('sha256',
        json_encode($restorable, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    if ($record['witness'] !== $witness) {
        throw new RuntimeException('core native diagnostic rows do not match their complete witness');
    }
    return $witness;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}
try {
    if (count($argv) !== 2 || !str_starts_with($argv[1], '/')) {
        throw new RuntimeException('core native diagnostic needs one absolute private capture stem');
    }
    $stem = $argv[1];
    $directory = @lstat(dirname($stem));
    if (!is_array($directory) || ($directory['mode'] & 0177777) !== 0040700) {
        throw new RuntimeException('core native diagnostic directory is not private');
    }
    $streams = [];
    // Thirteen complete <=1 MiB table encodings plus 8 KiB for their closed
    // envelope and count/hash witnesses; never read an unbounded dump file.
    foreach (['stdout' => 13639680, 'stderr' => 1048576, 'exit' => 8] as $suffix => $limit) {
        $path = $stem . '.' . $suffix;
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1
            || $stat['uid'] !== $directory['uid'] || $stat['size'] > $limit) {
            throw new RuntimeException('core native diagnostic stream is missing, unsafe or oversized');
        }
        $bytes = @file_get_contents($path, false, null, 0, $limit + 1);
        if (!is_string($bytes) || strlen($bytes) !== $stat['size']) {
            throw new RuntimeException('core native diagnostic stream changed while reading');
        }
        $streams[$suffix] = $bytes;
    }
    if ($streams['exit'] !== "0\n") {
        throw new RuntimeException('core native diagnostic command did not succeed');
    }
    $record = json_decode($streams['stdout'], true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($record)) {
        throw new RuntimeException('core native diagnostic is not one JSON object');
    }
    echo json_encode(core_native_state_evidence_projection($record), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    fwrite(STDERR, "core native diagnostic validation failed; inspect the private capture\n");
    exit(1);
}
