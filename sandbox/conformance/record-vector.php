<?php
/**
 * Host-side assembler for `record-vector.sh`: fold one passing sweep's four
 * artifacts into a self-hashed `duo-conformance-vector/v1` document.
 *
 * It runs on the HOST, not on the target, because two of the four inputs are
 * host-side directories (`$R1/state` and conf2's recapture, which run.sh has
 * just proven byte-identical) and because the canonical encoder and the vector
 * grammar both already live here — `DuoTest\ConformanceVector` is the same
 * class the offline replay consumes, so a recorder cannot emit a shape the
 * replay would not accept.
 *
 * The `verdict` it stamps is `ConformanceVector::LIVE_VERDICT`. That is a
 * claim about the RUN, not about this script: `record-vector.sh` is invoked by
 * run.sh only after the round-trip acceptance passed, and
 * `ConformanceVector::assert_document()` refuses any vector carrying a weaker
 * word — so a replay can never father a vector.
 *
 * Usage: php conformance/record-vector.php <out.json> <manifest> <state-dir> <recapture-dir> <probe.json> <rows.json>
 * `DUO_MANIFESTS_DIR` overrides where `<manifest>.json` is read from, the same
 * way it does for the agent.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../tests/lib/FakeWpdb.php';
require_once __DIR__ . '/../tests/lib/frozen_policy.php';
require_once __DIR__ . '/../tests/lib/ConformanceVector.php';

use DuoTest\ConformanceVector;

$argvList = $_SERVER['argv'] ?? [];
if (count($argvList) !== 7) {
    fwrite(STDERR, "usage: record-vector.php <out.json> <manifest> <state-dir> <recapture-dir> <probe.json> <rows.json>\n");
    exit(1);
}
[, $out, $manifestName, $stateDir, $recaptureDir, $probePath, $rowsPath] = $argvList;

/** Read one JSON artifact or die naming it; a half-recorded vector is worse than none. */
$readJson = static function (string $path, string $what): array {
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "FAIL: cannot read $what at $path\n");
        exit(1);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "FAIL: $what at $path is not a JSON object\n");
        exit(1);
    }
    return $decoded;
};

/**
 * A canonical state tree as `relative path => bytes`, restricted to `tables/`.
 * A vector replays typed rows through `Snapshot::capture()`; recording
 * `options/` or `posts/` would promise a replay this driver does not perform,
 * and an over-broad recording is the way a weak verdict quietly widens.
 *
 * @return array<string,string>
 */
$readTree = static function (string $dir, string $what): array {
    $tree = [];
    foreach (glob(rtrim($dir, '/') . '/tables/*/*.json') ?: [] as $file) {
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            fwrite(STDERR, "FAIL: cannot read $what entry $file\n");
            exit(1);
        }
        $tree[substr($file, strlen(rtrim($dir, '/')) + 1)] = $bytes;
    }
    ksort($tree, SORT_STRING);
    if ($tree === []) {
        fwrite(STDERR, "FAIL: $what at $dir holds no tables/ entries; there is no typed round trip to replay\n");
        exit(1);
    }
    return $tree;
};

// The shipped library by default; `DUO_MANIFESTS_DIR` is the same override the
// agent itself honours (Policy::load()), which is what lets the offline suite
// drive this recorder against a synthetic adapter and prove the recorder emits
// exactly what the replay accepts — without pinning a shipped manifest's bytes
// into a test fixture (AGENTS.md rule 2: those bytes ARE adapter identity).
$manifestsDir = getenv('DUO_MANIFESTS_DIR') ?: (__DIR__ . '/../../manifests');
$manifestPath = rtrim($manifestsDir, '/') . '/' . $manifestName . '.json';
$manifest = $readJson($manifestPath, "shipped manifest '$manifestName'");
$probe = $readJson($probePath, 'the adapter probe');
$dump = $readJson($rowsPath, 'the row dump');

$sourceSha = trim((string) shell_exec('git -C ' . escapeshellarg(__DIR__ . '/../..') . ' rev-parse HEAD 2>/dev/null'));

$document = ConformanceVector::document([
    'ledger' => ['duo_map' => array_values((array) ($dump['ledger']['duo_map'] ?? []))],
    'manifest' => $manifest,
    'probe' => $probe,
    'recapture' => $readTree($recaptureDir, "conf2's recapture"),
    'recorded' => [
        'agent_version' => (string) ($dump['agent_version'] ?? 'unknown'),
        'manifest_name' => $manifestName,
        'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        // The commit the sweep's own DUO_EXPECTED_SOURCE_SHA gate bound it to,
        // when run.sh was given one; otherwise the tree it was recorded from.
        'source_sha' => getenv('CONF_EXPECTED_SOURCE_SHA') ?: ($sourceSha !== '' ? $sourceSha : 'unknown'),
        'spec_version' => (int) ($dump['spec_version'] ?? 0),
        'verdict' => ConformanceVector::LIVE_VERDICT,
    ],
    'rows' => (array) ($dump['rows'] ?? []),
    'state' => $readTree($stateDir, "conf1's captured state"),
]);

// Refuse to write a vector this repository's own replay would reject. The
// recorder holds the pair; discovering the defect here costs one message,
// discovering it at replay time costs another pair.
try {
    ConformanceVector::assert_document($document);
} catch (\Throwable $failure) {
    fwrite(STDERR, 'FAIL: the recorded vector is not replayable: ' . $failure->getMessage() . "\n");
    exit(1);
}

if (@file_put_contents($out, \Duo\Canon::encode($document)) === false) {
    fwrite(STDERR, "FAIL: cannot write the vector to $out\n");
    exit(1);
}
echo "recorded " . ConformanceVector::FORMAT . " for '$manifestName' -> $out ("
    . count($document['state']) . " canonical entr" . (count($document['state']) === 1 ? 'y' : 'ies') . ', '
    . count($document['ledger']['duo_map']) . " duo_map row(s), hash {$document['vector_hash']})\n";
