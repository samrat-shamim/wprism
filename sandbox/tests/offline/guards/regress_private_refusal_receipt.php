<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/PrivateRefusalReceipt.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PrivateRefusalEvidence.php';

use WPrism\PrivateEvidenceException;
use WPrism\PrivateRefusalEvidence;
use WPrismTest\PrivateRefusalReceipt;

$profile = [
    'command' => 'apply',
    'reason_code' => 'apply_failed',
    'nodes' => [
        ['parent_index' => null, 'relation' => 'root', 'class' => PrivateEvidenceException::class, 'message' => 'public boundary'],
        ['parent_index' => 0, 'relation' => 'private_evidence', 'class' => RuntimeException::class, 'message' => 'private cause receipt canary'],
    ],
];
$record = [
    'format' => 'wprism-private-refusal-evidence/v2',
    'command' => 'apply',
    'reason_code' => 'apply_failed',
    ...PrivateRefusalEvidence::graph(new PrivateEvidenceException('public boundary', new RuntimeException('private cause receipt canary'))),
];
$scratch = sys_get_temp_dir() . '/wprism-private-receipt-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$directory = $scratch . '/refusals';
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
register_shutdown_function(static fn() => $removeTree($scratch));
$write = static function (string $path, array|string $value): void {
    file_put_contents($path, is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value);
    chmod($path, 0600);
};
$refuses = static function (callable $operation, string $label): void {
    $failure = null;
    try {
        $operation();
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    wprism_check(
        $failure instanceof RuntimeException
            && str_starts_with($failure->getMessage(), 'private refusal receipt:')
            && !str_contains($failure->getMessage(), 'private cause receipt canary')
            && !str_contains($failure->getMessage(), 'wprism-private-receipt-'),
        $label . ' refuses with a value-free category'
    );
};

wprism_check_same('[]', PrivateRefusalReceipt::snapshot($directory, $profile), 'a missing store has one empty filename baseline');
mkdir($directory, 0700);
$old = '20260905-090000-apply-' . str_repeat('a', 24) . '.json';
$new = '20260905-090001-apply-' . str_repeat('b', 24) . '.json';
$extra = '20260905-090002-apply-' . str_repeat('c', 24) . '.json';
$other = '20260905-090002-plan-' . str_repeat('d', 24) . '.json';
$write($directory . '/' . $old, $record);
$write($directory . '/' . $other, $record);
$baseline = PrivateRefusalReceipt::snapshot($directory, $profile);
wprism_check_same(json_encode([$old]), $baseline, 'inventory scopes exact command filenames rather than timestamps or record contents');
wprism_check_same(
    $baseline,
    PrivateRefusalReceipt::diagnosticSnapshot($directory, 'apply'),
    'a diagnostic freshness baseline needs only the exact command and does not invent an expected cause'
);
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'stale matching evidence without an append');
$write($directory . '/' . $new, $record);
$receipt = PrivateRefusalReceipt::verify($directory, $baseline, $profile);
wprism_check_same(json_encode([
    'command' => 'apply',
    'format' => 'wprism-private-refusal-check/v1',
    'new_records' => 1,
    'node_message_sha256' => [hash('sha256', 'public boundary'), hash('sha256', 'private cause receipt canary')],
    'verified' => true,
], JSON_UNESCAPED_SLASHES), $receipt, 'one fresh engine-emitted exact private graph yields only a digest receipt');
$recordBytes = json_encode($record, JSON_THROW_ON_ERROR);
$diagnostic = json_decode(
    PrivateRefusalReceipt::diagnosticNewRecords($directory, $baseline, 'apply'),
    true,
    flags: JSON_THROW_ON_ERROR
);
wprism_check_same([
    'command' => 'apply',
    'format' => 'wprism-private-refusal-diagnostic/v1',
    'new_records' => 1,
    'purpose' => 'diagnostic_only',
    'records' => [[
        'bytes' => strlen($recordBytes),
        'contents_base64' => base64_encode($recordBytes),
        'name' => $new,
        'sha256' => hash('sha256', $recordBytes),
    ]],
    'verified' => false,
], $diagnostic, 'diagnostic retention preserves bounded raw bytes while explicitly making no expected-cause claim');
$collection = json_decode(PrivateRefusalReceipt::collect($directory, $baseline, $profile), true, 32, JSON_THROW_ON_ERROR);
PrivateRefusalReceipt::assertCollection($collection, $profile);
wprism_check_same($diagnostic, $collection['diagnostic'], 'combined collection verifies the exact retained bytes rather than rereading a mutable record');
wprism_check_same(json_decode($receipt, true, 32, JSON_THROW_ON_ERROR), $collection['receipt'], 'combined collection retains the original digest receipt unchanged');
foreach (['extra-envelope', 'wrong-format', 'error', 'missing-receipt', 'wrong-receipt', 'extra-diagnostic', 'wrong-command',
    'wrong-count', 'verified-diagnostic', 'empty-records', 'duplicate-record', 'wrong-name', 'raw-size', 'raw-digest', 'raw-base64', 'forged-cause'] as $fault) {
    $bad = $collection;
    switch ($fault) {
        case 'extra-envelope': $bad['accepted'] = true; break;
        case 'wrong-format': $bad['format'] = 'foreign'; break;
        case 'error': $bad['error'] = ['message' => 'not verified']; break;
        case 'missing-receipt': $bad['receipt'] = null; break;
        case 'wrong-receipt': $bad['receipt']['node_message_sha256'][1] = str_repeat('a', 64); break;
        case 'extra-diagnostic': $bad['diagnostic']['accepted'] = true; break;
        case 'wrong-command': $bad['diagnostic']['command'] = 'plan'; break;
        case 'wrong-count': $bad['diagnostic']['new_records'] = 2; break;
        case 'verified-diagnostic': $bad['diagnostic']['verified'] = true; break;
        case 'empty-records': $bad['diagnostic']['records'] = []; break;
        case 'duplicate-record': $bad['diagnostic']['records'][] = $bad['diagnostic']['records'][0]; break;
        case 'wrong-name': $bad['diagnostic']['records'][0]['name'] = $other; break;
        case 'raw-size': $bad['diagnostic']['records'][0]['bytes']++; break;
        case 'raw-digest': $bad['diagnostic']['records'][0]['sha256'] = str_repeat('a', 64); break;
        case 'raw-base64': $bad['diagnostic']['records'][0]['contents_base64'] .= "\n"; break;
        case 'forged-cause':
            $forged = array_replace($record, PrivateRefusalEvidence::graph(new PrivateEvidenceException('public boundary', new RuntimeException('unrelated cause'))));
            $bytes = json_encode($forged, JSON_THROW_ON_ERROR);
            $bad['diagnostic']['records'][0] = ['name' => $new, 'bytes' => strlen($bytes), 'contents_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
            break;
    }
    $refuses(fn() => PrivateRefusalReceipt::assertCollection($bad, $profile), 'transported collection ' . $fault);
}
$afterDiagnostic = PrivateRefusalReceipt::diagnosticSnapshot($directory, 'apply');
$emptyDiagnostic = json_decode(
    PrivateRefusalReceipt::diagnosticNewRecords($directory, $afterDiagnostic, 'apply'),
    true,
    flags: JSON_THROW_ON_ERROR
);
wprism_check_same(0, $emptyDiagnostic['new_records'] ?? null, 'a successful command can append no matching diagnostic record');
$overflowNames = [];
for ($index = 3; $index < 8; $index++) {
    $overflowName = '20260905-09000' . $index . '-apply-' . str_repeat((string) $index, 24) . '.json';
    $overflowNames[] = $overflowName;
    $write($directory . '/' . $overflowName, $record);
}
$refuses(
    fn() => PrivateRefusalReceipt::diagnosticNewRecords($directory, $afterDiagnostic, 'apply'),
    'more than four new diagnostic records'
);
foreach ($overflowNames as $overflowName) {
    unlink($directory . '/' . $overflowName);
}
foreach (array_slice($overflowNames, 0, 4) as $overflowName) {
    $write($directory . '/' . $overflowName, str_pad($recordBytes, 262144));
}
$maximumDiagnostic = json_decode(PrivateRefusalReceipt::diagnosticNewRecords($directory, $afterDiagnostic, 'apply'),
    true, flags: JSON_THROW_ON_ERROR);
wprism_check_same(4, $maximumDiagnostic['new_records'], 'four exact-bound records fit the diagnostic count frontier');
wprism_check_same(1048576, array_sum(array_column($maximumDiagnostic['records'], 'bytes')),
    'the diagnostic reader preserves the complete one-MiB raw-byte frontier');
$write($directory . '/' . $overflowNames[0], str_pad($recordBytes, 262145));
$refuses(fn() => PrivateRefusalReceipt::diagnosticNewRecords($directory, $afterDiagnostic, 'apply'),
    'a diagnostic record one byte beyond the per-record boundary');
foreach (array_slice($overflowNames, 0, 4) as $overflowName) {
    unlink($directory . '/' . $overflowName);
}
$write($directory . '/' . $extra, $record);
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'two new records');
unlink($directory . '/' . $extra);
unlink($directory . '/' . $old);
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'removed prior evidence');
$write($directory . '/' . $old, $record);

$mutations = [
    'old format' => ['format', 'wprism-private-refusal-evidence/v1'],
    'wrong command' => ['command', 'plan'],
    'wrong reason' => ['reason_code', 'apply_other_failed'],
    'incomplete scan' => ['traversal.scan_complete', false],
    'incomplete record' => ['traversal.record_complete', false],
    'omitted node' => ['traversal.omitted_scanned_nodes', 1],
    'extra scanned node' => ['traversal.scanned_nodes', 3],
    'unrecorded node' => ['traversal.recorded_nodes', 1],
    'wrong private edges' => ['traversal.private_edges', 0],
    'unexamined edge' => ['traversal.examined_edges', 0],
    'unenqueued edge' => ['traversal.enqueued_edges', 0],
    'omitted bytes' => ['traversal.omitted_for_byte_limit', 1],
    'invalid private edge' => ['traversal.invalid_private_edges', 1],
    'truncated edge' => ['traversal.truncated_edges', 1],
    'cycle' => ['traversal.cycle_edges', 1],
    'pending edge' => ['traversal.truncated_pending_edges', 1],
    'graph error' => ['traversal.graph_errors', 1],
    'wrong root index' => ['throwable.0.index', 1],
    'wrong root parent' => ['throwable.0.parent_index', 0],
    'missing parent proof' => ['throwable.1.parent_recorded', false],
    'wrong child relation' => ['throwable.1.relation', 'previous'],
    'wrong child parent' => ['throwable.1.parent_index', 1],
    'unrelated cause' => ['throwable.1.message', 'unrelated private cause receipt canary'],
    'unrelated class' => ['throwable.1.class', LogicException::class],
    'wrong class encoding' => ['throwable.1.class_encoding', 'base64'],
    'wrong class bytes' => ['throwable.1.class_original_bytes', 1],
    'wrong class digest' => ['throwable.1.class_sha256', str_repeat('0', 64)],
    'truncated class' => ['throwable.1.class_truncated', true],
    'wrong message encoding' => ['throwable.1.message_encoding', 'base64'],
    'wrong message bytes' => ['throwable.1.message_original_bytes', 1],
    'wrong message digest' => ['throwable.1.message_sha256', str_repeat('0', 64)],
    'truncated message' => ['throwable.1.message_truncated', true],
    'missing cause' => ['throwable', [$record['throwable'][0]]],
    'duplicate cause' => ['throwable', [...$record['throwable'], $record['throwable'][1]]],
    'reordered graph' => ['throwable', array_reverse($record['throwable'])],
];
foreach ($mutations as $label => [$path, $value]) {
    $changed = $record;
    $slot = &$changed;
    foreach (explode('.', $path) as $component) {
        $slot = &$slot[$component];
    }
    $slot = $value;
    unset($slot);
    $write($directory . '/' . $new, $changed);
    $refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), $label);
    $failedCollection = json_decode(PrivateRefusalReceipt::collect($directory, $baseline, $profile), true, 32, JSON_THROW_ON_ERROR);
    wprism_check($failedCollection['receipt'] === null && is_array($failedCollection['error'])
        && base64_decode($failedCollection['diagnostic']['records'][0]['contents_base64'], true) === json_encode($changed, JSON_THROW_ON_ERROR),
        'failed combined collection retains the complete ' . $label . ' without a success receipt');
    $refuses(fn() => PrivateRefusalReceipt::assertCollection($failedCollection, $profile), 'failed collected ' . $label);
}
foreach (['', '{}{}', str_repeat('[', 33) . '0' . str_repeat(']', 33)] as $invalidBytes) {
    $write($directory . '/' . $new, $invalidBytes);
    $refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'invalid or over-depth record JSON');
}
$malformed = '{private diagnostic payload';
$write($directory . '/' . $new, $malformed);
$malformedDiagnostic = json_decode(
    PrivateRefusalReceipt::diagnosticNewRecords($directory, $baseline, 'apply'),
    true,
    flags: JSON_THROW_ON_ERROR
);
wprism_check_same(
    base64_encode($malformed),
    $malformedDiagnostic['records'][0]['contents_base64'] ?? null,
    'diagnostic retention preserves a malformed record for private diagnosis without accepting it as evidence'
);
$encoded = json_encode($record, JSON_THROW_ON_ERROR);
$write($directory . '/' . $new, str_pad($encoded, 262144));
wprism_check(PrivateRefusalReceipt::verify($directory, $baseline, $profile) === $receipt, 'the exact v2 record byte limit remains readable');
$write($directory . '/' . $new, str_pad($encoded, 262145));
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'one byte beyond the v2 record limit');
$write($directory . '/' . $new, $record);
chmod($directory . '/' . $new, 0644);
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'a nonprivate record mode');
chmod($directory . '/' . $new, 0600);
chmod($directory, 0755);
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'a nonprivate directory mode');
chmod($directory, 0700);
rename($directory . '/' . $new, $scratch . '/record');
symlink($scratch . '/record', $directory . '/' . $new);
$refuses(fn() => PrivateRefusalReceipt::verify($directory, $baseline, $profile), 'a symlinked record');
unlink($directory . '/' . $new);
rename($scratch . '/record', $directory . '/' . $new);
symlink($directory, $scratch . '/alias');
$refuses(fn() => PrivateRefusalReceipt::verify($scratch . '/alias', $baseline, $profile), 'a symlinked refusal directory');
unlink($scratch . '/alias');

foreach ([
    json_encode([$old, $old]), json_encode([$new, $old]), '["../private cause receipt canary"]',
    str_repeat(' ', 1048577), '{}',
] as $invalidBaseline) {
    $refuses(fn() => PrivateRefusalReceipt::verify($directory, $invalidBaseline, $profile), 'an unbounded or noncanonical baseline');
    $refuses(
        fn() => PrivateRefusalReceipt::diagnosticNewRecords($directory, $invalidBaseline, 'apply'),
        'an unbounded or noncanonical diagnostic baseline'
    );
}
$refuses(fn() => PrivateRefusalReceipt::diagnosticSnapshot($directory, '../apply'), 'a noncanonical diagnostic command');
$badProfile = $profile;
$badProfile['infer_cause'] = true;
$refuses(fn() => PrivateRefusalReceipt::snapshot($directory, $badProfile), 'an undeclared profile field');
$badProfile = $profile;
$badProfile['nodes'] = array_fill(0, 65, $profile['nodes'][0]);
$refuses(fn() => PrivateRefusalReceipt::snapshot($directory, $badProfile), 'an over-node-limit profile');
foreach ([str_repeat('x', 4097), "\xff"] as $badMessage) {
    $badProfile = $profile;
    $badProfile['nodes'][0]['message'] = $badMessage;
    $refuses(fn() => PrivateRefusalReceipt::snapshot($directory, $badProfile), 'an oversized or non-UTF-8 profile field');
}
foreach (['مرحباً 🌍', str_repeat('x', 4096)] as $message) {
    $unicodeProfile = $profile;
    $unicodeProfile['nodes'][1]['message'] = $message;
    $unicodeRecord = array_replace($record, PrivateRefusalEvidence::graph(
        new PrivateEvidenceException('public boundary', new RuntimeException($message))
    ));
    $write($directory . '/' . $new, $unicodeRecord);
    $decoded = json_decode(PrivateRefusalReceipt::verify($directory, $baseline, $unicodeProfile), true, flags: JSON_THROW_ON_ERROR);
    wprism_check_same(hash('sha256', $message), $decoded['node_message_sha256'][1],
        'exact UTF-8 and the inclusive field byte boundary preserve their digest');
}
$previousProfile = $profile;
$previousProfile['nodes'][0]['class'] = RuntimeException::class;
$previousProfile['nodes'][1]['relation'] = 'previous';
$write($directory . '/' . $new, array_replace($record, PrivateRefusalEvidence::graph(
    new RuntimeException('public boundary', 0, new RuntimeException('private cause receipt canary'))
)));
wprism_check(str_contains(PrivateRefusalReceipt::verify($directory, $baseline, $previousProfile), '"verified":true'),
    'a caller-declared ordinary previous chain is exact evidence without inferring a private edge');
for ($index = 0; $index < 4094; $index++) {
    $write($directory . '/unrelated-' . $index, '');
}
$refuses(fn() => PrivateRefusalReceipt::snapshot($directory, $profile), 'the bounded all-entry directory inventory');

// Exercise the public verifier's open/read identity checks deterministically:
// the stream reports one ordinary inode at inventory, then substitutes the
// named inode or grows the opened record after read. No timing race or test
// callback is added to the shared production-independent helper.
final class PrivateReceiptChangingStream {
    public mixed $context;
    public static string $bytes = '';
    public static string $mutation = '';
    public static bool $opened = false;
    public static bool $read = false;
    private int $directoryPosition = 0;
    private int $position = 0;

    private static function stat(bool $directory, bool $named): array {
        return [
            'dev' => 1,
            'ino' => $directory ? 90 : (self::$opened && $named && self::$mutation === 'inode' ? 92 : 91),
            'mode' => $directory ? 0040700 : 0100600,
            'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => strlen(self::$bytes) + (self::$read && self::$mutation === 'grow' ? 1 : 0),
            'atime' => 1, 'mtime' => 1, 'ctime' => 1, 'blksize' => 4096, 'blocks' => 1,
        ];
    }
    public function url_stat(string $path, int $flags): array { return self::stat(str_ends_with($path, '/refusals'), true); }
    public function dir_opendir(string $path, int $options): bool { return true; }
    public function dir_readdir(): string|false {
        return $this->directoryPosition++ === 0 ? '20260905-090001-apply-' . str_repeat('b', 24) . '.json' : false;
    }
    public function dir_closedir(): bool { return true; }
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        self::$opened = true;
        return true;
    }
    public function stream_stat(): array { return self::stat(false, false); }
    public function stream_read(int $count): string {
        self::$read = true;
        $bytes = substr(self::$bytes, $this->position, $count);
        $this->position += strlen($bytes);
        return $bytes;
    }
    public function stream_eof(): bool { return $this->position >= strlen(self::$bytes); }
}
stream_wrapper_register('private-receipt-test', PrivateReceiptChangingStream::class);
PrivateReceiptChangingStream::$bytes = $encoded;
foreach (['inode', 'grow'] as $mutation) {
    PrivateReceiptChangingStream::$mutation = $mutation;
    PrivateReceiptChangingStream::$opened = false;
    PrivateReceiptChangingStream::$read = false;
    $refuses(fn() => PrivateRefusalReceipt::verify('private-receipt-test://fixture/refusals', '[]', $profile),
        'the record changed at the matched ' . $mutation . ' boundary');
    PrivateReceiptChangingStream::$opened = false;
    PrivateReceiptChangingStream::$read = false;
    $refuses(
        fn() => PrivateRefusalReceipt::diagnosticNewRecords(
            'private-receipt-test://fixture/refusals',
            '[]',
            'apply'
        ),
        'the diagnostic record changed at the matched ' . $mutation . ' boundary'
    );
}
stream_wrapper_unregister('private-receipt-test');

wprism_check_summary('regress_private_refusal_receipt');
