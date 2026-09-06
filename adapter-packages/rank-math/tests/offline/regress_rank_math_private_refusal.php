<?php

declare(strict_types=1);

$helper = realpath(__DIR__ . '/../../fixtures/private-refusal-evidence.php');
if (!is_string($helper)) {
    fwrite(STDERR, "FAIL: Rank Math private-refusal helper is missing\n");
    exit(1);
}
require_once $helper;
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PrivateRefusalEvidence.php';

$failures = 0;
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    fwrite(STDERR, "FAIL: $message\n");
    $failures++;
};
$run = static function (array $arguments) use ($helper): array {
    $process = proc_open(
        array_merge([PHP_BINARY, $helper, dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateRefusalReceipt.php'], $arguments),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'process launch failed'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $removeTree($path . '/' . $name);
        }
    }
    @rmdir($path);
};
$writeRecord = static function (string $path, string $profileName, string $message): void {
    $profile = rank_math_private_refusal_profile($profileName);
    $record = [
        'format' => 'wprism-private-refusal-evidence/v2',
        'recorded_at' => '2026-09-03T16:45:19Z',
        'command' => $profile['command'],
        'reason_code' => $profile['reason_code'],
        ...WPrism\PrivateRefusalEvidence::graph(new RuntimeException($message)),
    ];
    file_put_contents(
        $path,
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    chmod($path, 0600);
};

$scratch = sys_get_temp_dir() . '/wprism-rank-math-private-refusal-' . bin2hex(random_bytes(8));
$directory = $scratch . '/.wprism/refusals';
register_shutdown_function(static function () use ($scratch, $removeTree): void {
    $removeTree($scratch);
});

$empty = $run(['snapshot', 'schema-loss', $directory]);
$check(
    $empty['exit'] === 0 && $empty['stdout'] === "[]\n" && $empty['stderr'] === '',
    'a missing refusal directory has one canonical nonempty empty-list baseline'
);

mkdir($directory, 0700, true);
chmod($directory, 0700);
$staleName = '20260903-164449-schema-status-' . str_repeat('a', 24) . '.json';
$newName = '20260903-164519-schema-status-' . str_repeat('b', 24) . '.json';
$extraName = '20260903-164520-schema-status-' . str_repeat('c', 24) . '.json';
$message = "wprism: schema-settle table 'rank_math_redirections' is absent but durable identity history remains; "
    . 'restore the database-matched table instead of preparing an empty replacement';
$writeRecord($directory . '/' . $staleName, 'schema-loss', $message);
$baseline = $run(['snapshot', 'schema-loss', $directory]);
$baselineJson = json_encode([$staleName], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(
    $baseline['exit'] === 0 && trim($baseline['stdout']) === $baselineJson && $baseline['stderr'] === '',
    'snapshot inventories pre-existing command-scoped evidence canonically without reading its contents'
);

$noAppend = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$check(
    $noAppend['exit'] !== 0
        && str_contains($noAppend['stderr'], 'did not append exactly one schema-status refusal record'),
    'a stale matching record cannot prove an invocation that appended nothing'
);

$writeRecord($directory . '/' . $newName, 'schema-loss', $message);
$verified = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$expectedReceipt = json_encode([
    'command' => 'schema-status',
    'format' => 'wprism-rank-math-private-refusal-check/v1',
    'new_records' => 1,
    'root_message_sha256' => hash('sha256', $message),
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$check(
    $verified['exit'] === 0 && $verified['stdout'] === $expectedReceipt && $verified['stderr'] === '',
    'one appended complete v2 graph yields the fixed value-free verification receipt'
);
$check(
    !str_contains($verified['stdout'], $newName) && !str_contains($verified['stdout'], $message),
    'the verification receipt discloses neither the private filename nor its root sentence'
);

$writeRecord($directory . '/' . $newName, 'schema-loss', 'wrong private cause canary');
$wrongCause = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$check(
    $wrongCause['exit'] !== 0
        && str_contains($wrongCause['stderr'], 'does not retain the exact complete cause graph')
        && !str_contains($wrongCause['stderr'], 'wrong private cause canary'),
    'a wrong private cause refuses without echoing private record values'
);
$writeRecord($directory . '/' . $newName, 'schema-loss', $message);
$writeRecord($directory . '/' . $extraName, 'schema-loss', $message);
$twoAppends = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$check(
    $twoAppends['exit'] !== 0
        && str_contains($twoAppends['stderr'], 'did not append exactly one schema-status refusal record'),
    'two appended records cannot be collapsed into one apparent proof by timestamp order'
);

$malformedBaseline = $run(['verify', 'schema-loss', $directory, '["not-a-record.json"]']);
$check(
    $malformedBaseline['exit'] !== 0
        && str_contains($malformedBaseline['stderr'], 'baseline contains a noncanonical record name'),
    'a caller cannot inject an unscoped filename into the baseline'
);

$missingCodeBaseline = $run(['snapshot', 'missing-code', $directory]);
$check(
    $missingCodeBaseline['exit'] === 0
        && $missingCodeBaseline['stdout'] === "[]\n"
        && $missingCodeBaseline['stderr'] === '',
    'command-scoped inventory excludes the existing schema-status records'
);
$missingCodeName = '20260903-164521-lifecycle-status-' . str_repeat('d', 24) . '.json';
$missingCodeMessage = rank_math_private_refusal_profile('missing-code')['message'];
$writeRecord($directory . '/' . $missingCodeName, 'missing-code', $missingCodeMessage);
$missingCodeVerified = $run(['verify', 'missing-code', $directory, '[]']);
$check(
    $missingCodeVerified['exit'] === 0
        && $missingCodeVerified['stdout'] === rank_math_private_refusal_receipt('missing-code') . "\n"
        && !str_contains($missingCodeVerified['stdout'], $missingCodeMessage)
        && hash('sha256', $missingCodeMessage)
            === '9145307bebd452be68d85b17d6bcd43b48921710b4a3f4fd9ffd0010d7690d93',
    'the lifecycle profile proves the exact private missing-code cause without disclosing it'
);
$virginSchemaBaseline = $run(['snapshot', 'virgin-schema', $directory]);
$check(
    $virginSchemaBaseline['exit'] === 0
        && $virginSchemaBaseline['stdout'] === "[]\n"
        && $virginSchemaBaseline['stderr'] === '',
    'plan-scoped inventory excludes schema-status and lifecycle-status records'
);
$virginSchemaName = '20260903-164522-plan-' . str_repeat('e', 24) . '.json';
$virginSchemaMessage = rank_math_private_refusal_profile('virgin-schema')['message'];
$writeRecord($directory . '/' . $virginSchemaName, 'virgin-schema', $virginSchemaMessage);
$virginSchemaVerified = $run(['verify', 'virgin-schema', $directory, '[]']);
$check(
    $virginSchemaVerified['exit'] === 0
        && $virginSchemaVerified['stdout'] === rank_math_private_refusal_receipt('virgin-schema') . "\n"
        && !str_contains($virginSchemaVerified['stdout'], $virginSchemaMessage)
        && hash('sha256', $virginSchemaMessage)
            === '4a8208927399b3863b0973d34410b2fd71bfc406d286d10dc14de0b24763ff76',
    'the plan profile proves the exact private virgin-schema cause without disclosing it'
);
$unknownProfile = $run(['snapshot', 'not-a-profile', $directory]);
$check(
    $unknownProfile['exit'] !== 0
        && str_contains($unknownProfile['stderr'], 'unknown verification profile'),
    'an unknown verifier profile refuses before inspecting private storage'
);

$wrappedProfile = rank_math_private_refusal_profile('schema-mismatch');
$wrappedRecord = [
    'format' => 'wprism-private-refusal-evidence/v2',
    'command' => $wrappedProfile['command'],
    'reason_code' => $wrappedProfile['reason_code'],
    ...WPrism\PrivateRefusalEvidence::graph(new WPrism\PrivateEvidenceException(
        $wrappedProfile['message'],
        new RuntimeException($wrappedProfile['private_cause_message'])
    )),
];
$writeWrapped = static function (string $path, array $record): void {
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
    chmod($path, 0600);
};
$wrappedBaseline = $run(['snapshot', 'schema-mismatch', $directory]);
$check(
    $wrappedBaseline['exit'] === 0 && $wrappedBaseline['stdout'] === "[]\n"
        && $wrappedBaseline['stderr'] === '',
    'schema settlement scopes its baseline away from plan, lifecycle and schema-status evidence'
);
$wrappedStale = $directory . '/20260905-095300-schema-settle-' . str_repeat('1', 24) . '.json';
$wrappedNew = $directory . '/20260905-095334-schema-settle-' . str_repeat('2', 24) . '.json';
$wrappedExtra = $directory . '/20260905-095335-schema-settle-' . str_repeat('3', 24) . '.json';
$writeWrapped($wrappedStale, $wrappedRecord);
$wrappedBaseline = $run(['snapshot', 'schema-mismatch', $directory]);
$wrappedBaselineJson = trim($wrappedBaseline['stdout']);
$wrappedNoAppend = $run(['verify', 'schema-mismatch', $directory, $wrappedBaselineJson]);
$check(
    $wrappedBaseline['exit'] === 0 && $wrappedNoAppend['exit'] !== 0
        && str_contains($wrappedNoAppend['stderr'], 'did not append exactly one schema-settle refusal record'),
    'an old exact wrapped provider graph never certifies a new invocation'
);
$writeWrapped($wrappedNew, $wrappedRecord);
$wrappedVerified = $run(['verify', 'schema-mismatch', $directory, $wrappedBaselineJson]);
$check(
    $wrappedVerified['exit'] === 0
        && $wrappedVerified['stdout'] === rank_math_private_refusal_receipt('schema-mismatch') . "\n"
        && $wrappedVerified['stderr'] === ''
        && !str_contains($wrappedVerified['stdout'], $wrappedProfile['message'])
        && !str_contains($wrappedVerified['stdout'], $wrappedProfile['private_cause_message'])
        && !str_contains($wrappedVerified['stdout'], $wrappedNew),
    'the engine-produced private edge yields only exact public-root and private-cause digest receipts'
);

// Mutate the emitted v2 graph, not a hand-built approximation of its edges.
// A matching message anywhere in a graph must not replace an exact bounded
// root -> private_evidence relationship or conceal omitted evidence.
$wrappedMutations = [
    'wrong command' => ['command', 'schema-status'],
    'wrong reason' => ['reason_code', 'schema_status_failed'],
    'incomplete scan' => ['traversal.scan_complete', false],
    'incomplete record' => ['traversal.record_complete', false],
    'omitted node' => ['traversal.omitted_scanned_nodes', 1],
    'unrecorded node' => ['traversal.scanned_nodes', 3],
    'unexamined edge' => ['traversal.examined_edges', 2],
    'truncated edge' => ['traversal.truncated_edges', 1],
    'graph failure' => ['traversal.graph_errors', 1],
    'unrelated root type' => ['throwable.0.class', 'RuntimeException'],
    'duplicate child index' => ['throwable.1.index', 0],
    'wrong parent' => ['throwable.1.parent_index', 1],
    'unrecorded parent' => ['throwable.1.parent_recorded', false],
    'printable previous edge' => ['throwable.1.relation', 'previous'],
    'unrelated cause type' => ['throwable.1.class', 'LogicException'],
    'wrong private cause' => ['throwable.1.message', 'private cause canary must stay private'],
    'wrong message encoding' => ['throwable.1.message_encoding', 'base64'],
    'wrong message bytes' => ['throwable.1.message_original_bytes', 1],
    'wrong message digest' => ['throwable.1.message_sha256', str_repeat('0', 64)],
    'truncated message' => ['throwable.1.message_truncated', true],
    'missing private edge' => ['throwable', [$wrappedRecord['throwable'][0]]],
    'extra unrelated cause' => ['throwable', [...$wrappedRecord['throwable'], $wrappedRecord['throwable'][1]]],
    'old root-only cause graph' => ['throwable', [
        WPrism\PrivateRefusalEvidence::graph(new RuntimeException($wrappedProfile['private_cause_message']))['throwable'][0],
    ]],
];
foreach ($wrappedMutations as $label => [$path, $value]) {
    $mutated = $wrappedRecord;
    $slot = &$mutated;
    foreach (explode('.', $path) as $component) {
        $slot = &$slot[$component];
    }
    $slot = $value;
    unset($slot);
    $writeWrapped($wrappedNew, $mutated);
    $rejected = $run(['verify', 'schema-mismatch', $directory, $wrappedBaselineJson]);
    $check(
        $rejected['exit'] !== 0 && $rejected['stdout'] === ''
            && str_contains($rejected['stderr'], 'wprism-rank-math-private-refusal:')
            && !str_contains($rejected['stderr'], 'private cause canary')
            && !str_contains($rejected['stderr'], $wrappedProfile['private_cause_message'])
            && !str_contains($rejected['stderr'], $wrappedNew),
        "wrapped schema receipt rejects $label without disclosing private values"
    );
}
$writeWrapped($wrappedNew, $wrappedRecord);
$writeWrapped($wrappedExtra, $wrappedRecord);
$wrappedTwo = $run(['verify', 'schema-mismatch', $directory, $wrappedBaselineJson]);
$check(
    $wrappedTwo['exit'] !== 0 && $wrappedTwo['stdout'] === ''
        && str_contains($wrappedTwo['stderr'], 'did not append exactly one schema-settle refusal record'),
    'two new complete wrapped records cannot be reduced to the newest apparent proof'
);

// Execute every capsule-owned standalone receipt invocation with a source
// root containing spaces. The Compose surrogate admits only the exact readonly
// mount and native UID/entrypoint argv, then runs the actual PHP entrypoint.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
$mountedRoot = $scratch . '/candidate source';
symlink($root, $mountedRoot);
$conformance = (string) file_get_contents(__DIR__ . '/../conformance/check.sh');
$matrix = (string) file_get_contents(__DIR__ . '/../certify/version-matrix.sh');
$matrixStart = strpos($matrix, 'rank_math_private_evidence() {');
$matrixEnd = $matrixStart === false ? false : strpos($matrix, "\n}\n", $matrixStart);
$matrixFunction = $matrixStart === false || $matrixEnd === false ? ''
    : substr($matrix, $matrixStart, $matrixEnd + 3 - $matrixStart);
$mountProbe = <<<'SH'
set -euo pipefail
PAIR_SOURCE_ROOT="$1" fixture_directory="$2" fixture_broken_library="$3" fixture_service="$4"
COMPOSE=fixture_compose
PAIR_COMPOSE=(fixture_compose)
AUTHORED_LOSS_PRIVATE_BASELINE='[]'
MISSING_CODE_PRIVATE_BASELINE='[]'
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$PAIR_SOURCE_ROOT/sandbox/conformance/asserts.sh"
fixture_compose() {
  [ "$#" -ge 13 ] && [ "$1" = run ] && [ "$2" = --rm ] && [ "$3" = -T ] \
    && [ "$4" = --volume ] \
    && [ "$5" = "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" ] \
    && [ "$6" = --entrypoint ] && [ "$7" = php ] && [ "$8" = "$fixture_service" ] \
    && [ "$9" = /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php ] \
    && [ "${10}" = /wprism-test/PrivateRefusalReceipt.php ] \
    && [ "${13}" = /siterepo/.wprism/refusals ] || return 83
  fixture_library="$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php"
  [ "$fixture_broken_library" = no ] || fixture_library="$fixture_directory/missing-library.php"
  php "$PAIR_SOURCE_ROOT/adapter-packages/rank-math/fixtures/private-refusal-evidence.php" \
    "$fixture_library" "${11}" "${12}" "$fixture_directory" "${@:14}"
}
SH;
$mountCases = [
    ['AUTHORED_LOSS_PRIVATE_BASELINE', 'schema-loss', 'snapshot', 'cli2'],
    ['AUTHORED_LOSS_PRIVATE_RECEIPT', 'schema-loss', 'verify', 'cli2'],
    ['MISSING_CODE_PRIVATE_BASELINE', 'missing-code', 'snapshot', 'cli2'],
    ['MISSING_CODE_PRIVATE_RECEIPT', 'missing-code', 'verify', 'cli2'],
    ['MATRIX_SNAPSHOT', 'virgin-schema', 'snapshot', 'cli2'],
    ['MATRIX_VERIFY', 'virgin-schema', 'verify', 'cli2'],
    ['MATRIX_SOURCE_SNAPSHOT', 'below-range', 'snapshot', 'cli1'],
    ['MATRIX_SOURCE_VERIFY', 'below-range', 'verify', 'cli1'],
];
foreach ($mountCases as [$variable, $profileName, $mode, $service]) {
    $mountedDirectory = $scratch . '/mount-' . $variable;
    mkdir($mountedDirectory, 0700);
    if ($mode === 'verify') {
        $profile = rank_math_private_refusal_profile($profileName);
        $name = '20260905-110000-' . $profile['command'] . '-' . str_repeat('4', 24) . '.json';
        $writeRecord($mountedDirectory . '/' . $name, $profileName, $profile['message']);
    }
    $block = str_starts_with($variable, 'MATRIX_')
        ? $matrixFunction . "\n$variable=\$(rank_math_private_evidence $service $mode $profileName /siterepo/.wprism/refusals"
            . ($mode === 'verify' ? " '[]'" : '') . ")\n"
        : WPrismTest\ShellProbe::captureBlock($conformance, $variable, 'require_observed_nonempty');
    foreach (['no', 'yes'] as $missingLibrary) {
        [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
            $mountProbe . "\n" . $block . "\nprintf '%s\\n' \"\$$variable\"\n",
            [$mountedRoot, $mountedDirectory, $missingLibrary, $service],
            $root
        );
        $expected = $mode === 'snapshot' ? '[]' : rank_math_private_refusal_receipt($profileName);
        $check($missingLibrary === 'no'
            ? $status === 0 && $stdout === $expected . "\n" && $stderr === ''
            : $status !== 0 && $stdout === ''
                && str_contains($stderr, 'the explicitly mounted shared test library is unavailable'),
            "the actual $variable standalone call preserves its explicit readonly mount and rejects missing-library=$missingLibrary");
    }
}

echo $failures === 0
    ? "PASS: Rank Math private refusal evidence ($assertions assertions)\n"
    : "FAIL: Rank Math private refusal evidence ($failures failures)\n";
exit($failures === 0 ? 0 : 1);
