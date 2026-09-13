<?php
declare(strict_types=1);

require_once __DIR__ . '/recovery-evidence.php';
use WPrism\Canon;
use WPrism\CapturePublicationRecovery;
use WPrism\Ledger;
use WPrism\Publish;

$mode = $args[0] ?? '';
$checkpoint = $args[1] ?? '';
$state = '/siterepo/state';
$read = static function (string $path): string {
    if (!is_file($path) || is_link($path) || filesize($path) < 1 || filesize($path) > 8388608) throw new RuntimeException('recovery fixture input is missing or unbounded');
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || $bytes === '') throw new RuntimeException('recovery fixture read failed');
    return $bytes;
};
$write = static function (string $path, string $bytes): void {
    if (is_link($path) || file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)
        || file_get_contents($path) !== $bytes) throw new RuntimeException('recovery fixture write or readback failed');
};
$paths = glob($state . '/posts/page/*--map-boundary-fixture.md');
if (!is_array($paths) || count($paths) !== 1) throw new RuntimeException('recovery fixture post identity is not unique');
$path = $paths[0];
$setupPath = __DIR__ . '/setup.json';
if ($mode === 'prepare') {
    foreach (['setup.json', 'post.prior', 'post.candidate'] as $name) {
        if (file_exists(__DIR__ . '/' . $name) || is_link(__DIR__ . '/' . $name)) throw new RuntimeException('recovery fixture already prepared');
    }
    $prior = $read($path);
    $candidate = str_replace(['"height":420', 'height="420px"'], ['"height":430', 'height="430px"'], $prior, $count);
    if ($count !== 2) throw new RuntimeException('recovery candidate must change both saver representations');
    $priorHash = Publish::tree_digest($state);
    $write(__DIR__ . '/post.prior', $prior);
    $write(__DIR__ . '/post.candidate', $candidate);
    $write($path, $candidate);
    $write($setupPath, Canon::encode(['path' => $path, 'prior_sha256' => hash('sha256', $prior), 'candidate_sha256' => hash('sha256', $candidate),
        'prior_tree_sha256' => $priorHash, 'candidate_tree_sha256' => Publish::tree_digest($state)]));
}
$setup = Canon::decode($read($setupPath));
if (!is_array($setup) || ($setup['path'] ?? null) !== $path
    || hash('sha256', $read(__DIR__ . '/post.prior')) !== ($setup['prior_sha256'] ?? null)
    || hash('sha256', $read(__DIR__ . '/post.candidate')) !== ($setup['candidate_sha256'] ?? null)
    || $setup['prior_tree_sha256'] === $setup['candidate_tree_sha256']) throw new RuntimeException('recovery fixture ownership record differs');

if (in_array($mode, ['prior', 'candidate'], true)) {
    if (!in_array(hash('sha256', $read($path)), [$setup['prior_sha256'], $setup['candidate_sha256']], true)) throw new RuntimeException('refusing to replace unexpected canonical Map bytes');
    foreach ([Publish::stage_dir($state), Publish::backup_dir($state), Publish::intent_path($state)] as $artifact) {
        if (file_exists($artifact) || is_link($artifact)) throw new RuntimeException('canonical fixture cannot overwrite pending recovery');
    }
    $write($path, $read(__DIR__ . '/post.' . $mode));
    if (Publish::tree_digest($state) !== $setup[$mode . '_tree_sha256']) throw new RuntimeException('canonical fixture generation differs');
}
$markerKey = CapturePublicationRecovery::markerKey($state);
if (str_starts_with($mode, 'tamper-') || str_starts_with($mode, 'restore-')) {
    if (!in_array($checkpoint, MapRecoveryEvidence::CHECKPOINTS, true)) throw new RuntimeException('recovery fault checkpoint differs');
    $kind = substr($mode, strpos($mode, '-') + 1);
    $recordPath = match ($kind) { 'intent' => Publish::intent_path($state), 'receipt' => Publish::receipt_path($state), 'marker' => null, default => throw new RuntimeException('recovery fault kind differs') };
    $actual = $recordPath === null ? Ledger::kv_get($markerKey) : $read($recordPath);
    if (!is_string($actual) || $actual === '') throw new RuntimeException('recovery fault record missing');
    $backup = __DIR__ . '/' . $checkpoint . '-' . $kind . '.original';
    $mutantPath = __DIR__ . '/' . $checkpoint . '-' . $kind . '.mutant';
    if (str_starts_with($mode, 'tamper-')) {
        if (file_exists($backup) || file_exists($mutantPath)) throw new RuntimeException('recovery fault already manufactured');
        $record = Canon::decode($actual);
        MapRecoveryEvidence::record($record, $kind === 'marker' ? 'commit-marker' : $kind);
        if ($kind === 'marker') {
            $record['candidate_sha256'] = str_repeat('f', 64);
            unset($record['record_sha256']);
            $record['record_sha256'] = hash('sha256', Canon::encode($record));
        } else $record['record_sha256'] = str_repeat('0', 64);
        $next = Canon::encode($record);
        if ($next === $actual) throw new RuntimeException('recovery fault did not change its record');
        $write($backup, $actual);
        $write($mutantPath, $next);
    } else {
        if ($actual !== $read($mutantPath)) throw new RuntimeException('refusing to restore an unexpected recovery record');
        $next = $read($backup);
    }
    if ($recordPath === null) {
        Ledger::kv_set($markerKey, $next);
        if (Ledger::kv_get($markerKey) !== $next) throw new RuntimeException('recovery marker fault readback differs');
    } else $write($recordPath, $next);
} elseif (!in_array($mode, ['prepare', 'prior', 'candidate', 'observe', 'inventory', 'clean'], true)) {
    throw new RuntimeException('unknown native recovery operation');
}

$transitions = [];
foreach ([Publish::intent_path($state), Publish::receipt_path($state)] as $recordPath) {
    foreach (['.next', '.previous'] as $suffix) {
        if (file_exists($recordPath . $suffix) || is_link($recordPath . $suffix)) $transitions[] = basename($recordPath . $suffix);
    }
}
$markerRaw = Ledger::kv_get($markerKey);
if ($mode === 'observe') {
    $record = static fn(string $file): ?array => is_file($file) ? Canon::decode($read($file)) : null;
    $answer = ['format' => 'wprism-map-recovery-observation/v1', 'checkpoint' => $checkpoint,
        'prior_sha256' => $setup['prior_tree_sha256'], 'candidate_sha256' => $setup['candidate_tree_sha256'],
        'live_sha256' => Publish::tree_digest($state),
        'staging_sha256' => is_dir(Publish::stage_dir($state)) ? Publish::tree_digest(Publish::stage_dir($state)) : null,
        'backup_sha256' => is_dir(Publish::backup_dir($state)) ? Publish::tree_digest(Publish::backup_dir($state)) : null,
        'intent' => $record(Publish::intent_path($state)), 'receipt' => $record(Publish::receipt_path($state)),
        'marker' => $markerRaw === null ? null : Canon::decode($markerRaw),
        'destination_sha256' => CapturePublicationRecovery::destinationSha256($state), 'transitions' => $transitions];
    MapRecoveryEvidence::assertCrash($answer, $checkpoint);
} elseif ($mode === 'inventory') {
    $files = [];
    foreach ([$state, ...glob($state . '.capture-*')] as $artifact) {
        if (is_link($artifact)) throw new RuntimeException('recovery inventory contains a symlink');
        if (is_dir($artifact)) $files[basename($artifact)] = ['type' => 'directory', 'sha256' => Publish::tree_digest($artifact)];
        elseif (is_file($artifact)) $files[basename($artifact)] = ['type' => 'file', 'sha256' => hash_file('sha256', $artifact)];
        else throw new RuntimeException('recovery inventory contains an unsupported path');
    }
    ksort($files, SORT_STRING);
    $answer = ['format' => 'wprism-map-recovery-inventory/v1', 'files' => $files, 'marker_sha256' => $markerRaw === null ? null : hash('sha256', $markerRaw)];
} elseif ($mode === 'clean') {
    if ($markerRaw !== null || $transitions !== [] || Publish::tree_digest($state) !== $setup['candidate_tree_sha256']) throw new RuntimeException('native recovery is not the clean candidate generation');
    foreach ([Publish::stage_dir($state), Publish::backup_dir($state), Publish::intent_path($state)] as $artifact) {
        if (file_exists($artifact) || is_link($artifact)) throw new RuntimeException('native recovery left a pending publication artifact');
    }
    $receipt = Canon::decode($read(Publish::receipt_path($state)));
    MapRecoveryEvidence::record($receipt, 'receipt');
    if ($receipt['candidate_sha256'] !== $setup['candidate_tree_sha256']) throw new RuntimeException('native recovery receipt belongs to another generation');
    $answer = ['format' => 'wprism-map-recovery-clean/v1', 'candidate_sha256' => $setup['candidate_tree_sha256'], 'marker_absent' => true, 'pending_artifacts' => []];
} else {
    $answer = ['format' => 'wprism-map-recovery-fixture/v1', 'mode' => $mode, 'checkpoint' => $checkpoint, 'post_sha256' => hash('sha256', $read($path))];
}
echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
