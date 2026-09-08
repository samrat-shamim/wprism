<?php
declare(strict_types=1);

// These are deliberately synthetic transport/admission controls. They prove
// the actual host verdict is falsifiable; only the separate native run proves
// WordPress/MariaDB execution of Capture -> env-set.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../fixtures/protected-identity-native.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PrivateRefusalEvidence.php';

use WPrism\PrivateRefusalEvidence;

$repo = dirname(__DIR__, 4);
$pair = 'pwprobe01';
$sourceSha = str_repeat('c', 40);
$sink = sys_get_temp_dir() . '/wprism-protected-native-contract-' . bin2hex(random_bytes(8));
$diagnostics = $repo . '/sandbox/tmp/wprism-conformance-env-set.' . $pair . '.' . bin2hex(random_bytes(3));
foreach ([$sink, $diagnostics] as $directory) {
    if (!mkdir($directory, 0700)) throw new RuntimeException('could not allocate owned contract evidence');
}
register_shutdown_function(static function () use ($sink, $diagnostics): void {
    foreach ([$sink, $diagnostics] as $directory) {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') unlink($directory . '/' . $name);
        }
        rmdir($directory);
    }
});
$uuid = '11111111-1111-4111-8111-111111111111';
$post = array_replace(array_fill_keys(ProtectedIdentityNativeEvidence::columns()['posts'], ''), [
    'ID' => '4', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => ProtectedIdentityNativeEvidence::SLUG,
    'post_password' => ProtectedIdentityNativeEvidence::INITIAL, 'post_content' => 'Owned native identity fixture.',
]);
$before = ['format' => 'wprism-native-protected-identity/v1', 'home' => 'http://' . $pair . '1.invalid',
    'wordpress' => '7.1', 'php' => '8.3.33', 'database' => '11.8.3-MariaDB', 'active_plugins' => [],
    'runtime' => ['theme' => ['stylesheet' => 'twentytwentyone', 'version' => '2.8'], 'mu_plugins' => ['wprism-loader.php'], 'dropins' => []],
    'post_id' => 4, 'uuid' => $uuid,
    'rows' => ['posts' => [$post], 'postmeta' => [['meta_id' => '1', 'post_id' => '4', 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid]],
        'terms' => [['term_id' => '1', 'name' => 'Uncategorized', 'slug' => 'uncategorized', 'term_group' => '0']], 'termmeta' => [],
        'map' => [['uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => '4']],
        'state' => [['uuid' => $uuid, 'entity_type' => 'post', 'content_hash' => str_repeat('a', 64)]]],
    'canonical' => "---\n" . json_encode(['uuid' => $uuid, 'type' => 'post', 'password_binding' => 'post_password:' . $uuid], JSON_THROW_ON_ERROR)
        . "\n---\nOwned native identity fixture.\n", 'intended' => null];
$change = static function (array $record, string $password) use ($uuid): array {
    $record['rows']['posts'][0]['post_password'] = $password;
    $record['intended'] = ['mode' => 0600, 'bytes' => json_encode(['post_password:' . $uuid => $password], JSON_THROW_ON_ERROR) . "\n"];
    return $record;
};
$positive = $change($before, ProtectedIdentityNativeEvidence::POSITIVE);
$orphans = $positive;
$orphans['rows']['postmeta'][] = ['meta_id' => '2', 'post_id' => '1000004', 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid];
$orphans['rows']['termmeta'][] = ['meta_id' => '1', 'term_id' => '1000001', 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid];
$afterOrphans = $change($orphans, ProtectedIdentityNativeEvidence::ORPHAN);
$duplicate = $afterOrphans;
$duplicate['rows']['termmeta'][] = ['meta_id' => '2', 'term_id' => '1', 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid];
$recordName = '20260909-120001-env-set-' . str_repeat('b', 24) . '.json';
$rawRecord = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'env-set', 'reason_code' => 'env_set_failed',
    ...PrivateRefusalEvidence::graph(new RuntimeException('wprism: protected post binding identity does not match its unique exact live backing row'))];
$diagnostic = static function (array $record) use ($recordName): array {
    $bytes = json_encode($record, JSON_THROW_ON_ERROR);
    return ['command' => 'env-set', 'format' => 'wprism-private-refusal-diagnostic/v1', 'new_records' => 1,
        'purpose' => 'diagnostic_only', 'records' => [['bytes' => strlen($bytes), 'contents_base64' => base64_encode($bytes),
            'name' => $recordName, 'sha256' => hash('sha256', $bytes)]], 'verified' => false];
};
$streams = [];
$stage = static function (string $stem, array|string $stdout, string $stderr = '', int $exit = 0) use (&$streams): void {
    $streams[$stem . '.stdout'] = is_array($stdout) ? json_encode($stdout, JSON_THROW_ON_ERROR) . "\n" : $stdout;
    $streams[$stem . '.stderr'] = $stderr;
    $streams[$stem . '.exit'] = "$exit\n";
};
foreach (['before' => $before, 'after-positive' => $positive, 'orphans' => $orphans, 'after-orphans' => $afterOrphans,
    'duplicate' => $duplicate, 'after-refusal' => $duplicate] as $name => $observation) $stage($sink . '/' . $name, $observation);
$stage($sink . '/seed', ['seeded_post' => 4]);
$stage($sink . '/source', ProtectedIdentityNativeEvidence::source($sourceSha, $pair));
$stage($sink . '/capture', ['counts' => ['post' => 1], 'warnings' => []]);
$stage($sink . '/positive', ['name' => 'post_password:' . $uuid, 'previously_set' => false]);
$stage($sink . '/orphan-update', ['name' => 'post_password:' . $uuid, 'previously_set' => true]);
$stage($sink . '/refusal', ProtectedIdentityNativeEvidence::refusal(), "private command diagnostics (unverified): $diagnostics\n", 1);
$stage($diagnostics . '/baseline', ['command' => 'env-set', 'baseline' => '[]']);
$stage($diagnostics . '/baseline-check', '');
$stage($diagnostics . '/command', ProtectedIdentityNativeEvidence::refusal(), '', 1);
$stage($diagnostics . '/private', $diagnostic($rawRecord));
$stage($diagnostics . '/private-check', '');
$write = static function (array $contents): void {
    foreach ($contents as $path => $bytes) {
        file_put_contents($path, $bytes);
        chmod($path, 0600);
    }
};
$write($streams);
ProtectedIdentityNativeEvidence::admit($sink, $pair, $sourceSha);
wprism_check(true, 'actual host admission accepts complete synthetic positive/orphan/refusal transport');
$reject = static function (array $changes, string $label, string $exception = RuntimeException::class) use ($write, $streams, $sink, $pair, $sourceSha): void {
    $write(array_replace($streams, $changes));
    wprism_check_throws(static fn() => ProtectedIdentityNativeEvidence::admit($sink, $pair, $sourceSha), $exception, $label);
};
$mutate = static function (string $stage, callable $operation, string $label) use ($streams, $sink, $reject): void {
    $path = $sink . '/' . $stage . '.stdout';
    $record = json_decode($streams[$path], true, 32, JSON_THROW_ON_ERROR);
    $operation($record);
    $reject([$path => json_encode($record, JSON_THROW_ON_ERROR) . "\n"], $label);
};
foreach (['post_id', 'uuid', 'rows', 'canonical', 'intended'] as $key) {
    $mutate('before', static function (array &$record) use ($key): void { unset($record[$key]); }, 'missing observation field ' . $key);
}
foreach (['posts', 'postmeta', 'terms', 'termmeta', 'map', 'state'] as $table) {
    $mutate('after-positive', static function (array &$record) use ($table): void {
        if ($record['rows'][$table] === []) $record['rows'][$table][] = ['unexpected' => 'row'];
        else $record['rows'][$table][0][array_key_last($record['rows'][$table][0])] = 'changed';
    }, 'unrelated positive table mutation ' . $table);
    if ($before['rows'][$table] !== []) {
        $mutate('before', static function (array &$record) use ($table): void { array_pop($record['rows'][$table][0]); }, 'truncated full column roster ' . $table);
    }
}
foreach (['after-positive', 'after-orphans', 'after-refusal'] as $name) {
    $mutate($name, static function (array &$record): void { $record['canonical'] .= 'drift'; }, 'canonical bytes preserved at ' . $name);
    $mutate($name, static function (array &$record): void { $record['rows']['posts'][0]['post_password'] = 'wrong'; }, 'exact live password at ' . $name);
    $mutate($name, static function (array &$record): void { $record['intended']['bytes'] = '{"wrong":"binding"}'; }, 'exact intended bytes at ' . $name);
    $mutate($name, static function (array &$record): void { $record['intended']['mode'] = 0644; }, 'private intended mode at ' . $name);
}
$mutate('before', static function (array &$record): void { $record['post_id'] = 5; }, 'selected post substitution');
$mutate('before', static function (array &$record): void { $record['active_plugins'] = ['foreign/plugin.php']; }, 'core-only active plugin premise');
$mutate('before', static function (array &$record): void { $record['runtime']['mu_plugins'][] = 'foreign.php'; }, 'unrecognized MU participant');
$mutate('before', static function (array &$record): void { $record['runtime']['dropins'][] = 'db.php'; }, 'unrecognized drop-in participant');
$mutate('before', static function (array &$record): void { $record['runtime']['theme']['stylesheet'] = 'foreign'; }, 'named native theme premise');
$mutate('before', static function (array &$record): void { $record['rows']['posts'] = array_fill(0, 1025, $record['rows']['posts'][0]); }, 'complete row sentinel overflow');
$mutate('before', static function (array &$record): void { $record['rows']['state'] = []; }, 'nonempty Capture state premise');
$mutate('before', static function (array &$record): void { $record['canonical'] .= ProtectedIdentityNativeEvidence::INITIAL; }, 'password leak in canonical');
foreach (['duplicate', 'missing', 'unordered', 'malformed'] as $fault) {
    $mutate('before', static function (array &$record) use ($fault): void {
        if ($fault === 'missing') $record['rows']['posts'] = [];
        elseif ($fault === 'malformed') $record['rows']['postmeta'][0]['post_id'] = '04';
        else {
            $second = $record['rows']['posts'][0];
            if ($fault === 'unordered') $second['ID'] = '3';
            $record['rows']['posts'][] = $second;
        }
    }, 'physical inventory ' . $fault);
}
foreach (['map', 'state'] as $table) {
    $mutate('after-positive', static function (array &$record) use ($table): void { $record['rows'][$table][] = $record['rows'][$table][0]; },
        'extra duplicate ' . $table . ' record');
    $mutate('after-positive', static function (array &$record) use ($table): void {
        $row = $record['rows'][$table][0];
        $row['uuid'] = '22222222-2222-4222-8222-222222222222';
        if ($table === 'map') $row['local_id'] = '5';
        $record['rows'][$table][] = $row;
    }, 'extra unrelated ' . $table . ' record');
}
foreach (['source_sha', 'fixture_sha256', 'driver_sha256', 'pair'] as $field) {
    $mutate('source', static function (array &$record) use ($field): void { $record[$field] = 'different'; }, 'source/producer binding ' . $field);
}
foreach (['postmeta' => 'post_id', 'termmeta' => 'term_id'] as $table => $owner) {
    foreach (['live', 'key', 'uuid', 'extra'] as $fault) {
        $mutate('orphans', static function (array &$record) use ($table, $owner, $fault): void {
            $index = array_key_last($record['rows'][$table]);
            if ($fault === 'live') $record['rows'][$table][$index][$owner] = $table === 'postmeta' ? '4' : '1';
            elseif ($fault === 'key') $record['rows'][$table][$index]['meta_key'] = '_WPRISM_UUID';
            elseif ($fault === 'uuid') $record['rows'][$table][$index]['meta_value'] = str_repeat('0', 36);
            else $record['rows']['posts'][0]['post_content'] = 'changed';
        }, 'orphan manufacture ' . $table . ' ' . $fault);
    }
}
$mutate('duplicate', static function (array &$record): void { $record['rows']['termmeta'][1]['term_id'] = '12345'; }, 'duplicate requires live term');
$mutate('duplicate', static function (array &$record): void { $record['rows']['termmeta'][1]['meta_value'] = 'wrong'; }, 'duplicate requires exact UUID');
foreach (['warning', 'empty', 'wrong-answer'] as $fault) {
    $mutate('capture', static function (array &$record) use ($fault): void {
        if ($fault === 'warning') $record['warnings'][] = 'warning';
        elseif ($fault === 'empty') $record['counts']['post'] = 0;
        else $record['error'] = 'capture_failed';
    }, 'Capture response ' . $fault);
}
foreach (['0', '2', '255'] as $exit) $reject([$sink . '/refusal.exit' => "$exit\n"], 'refusal requires exact exit 1, not ' . $exit);
$reject([$sink . '/refusal.stderr' => "private command diagnostics (unverified): /foreign/evidence\n"], 'refusal diagnostic root is caller-bound');
$reject([$sink . '/after-refusal.stderr' => "Warning: leaked diagnostic\n"], 'successful observation rejects warnings');
$reject([$sink . '/positive.stdout' => ''], 'empty success is not a command answer', JsonException::class);
$reject([$diagnostics . '/private-check.stdout' => "PASS\n"], 'diagnostic validator must be silent');
$reject([$diagnostics . '/command.stdout' => json_encode(ProtectedIdentityNativeEvidence::refusal())], 'inner/outer command bytes are exact');
$mutate('refusal', static function (array &$record): void { $record['extra'] = ProtectedIdentityNativeEvidence::REFUSED; }, 'public refusal has no extra/private field');
$badRecord = $rawRecord;
$badRecord['throwable'][0]['message'] = 'unrelated database refusal';
$reject([$diagnostics . '/private.stdout' => json_encode($diagnostic($badRecord), JSON_THROW_ON_ERROR)], 'coherently rehashed unrelated private cause');
$reject([$diagnostics . '/baseline.stdout' => json_encode(['command' => 'env-set', 'baseline' => json_encode([$recordName])])], 'old matching private record cannot prove new refusal');
$write($streams);
ProtectedIdentityNativeEvidence::admit($sink, $pair, $sourceSha);
wprism_check(true, 'restoring exact transport restores admission');

$driver = file_get_contents(__DIR__ . '/../../live/regress_protected_identity_native.sh');
wprism_check(is_string($driver) && str_contains($driver, 'pair_live_ownership_acquire mariadb')
    && str_contains($driver, 'pair_live_ownership_up --headless') && str_contains($driver, 'pair_live_ownership_complete')
    && str_contains($driver, 'conformance_private_command cli1 env-set') && !str_contains($driver, 'pair.sh reset'),
    'native driver uses owned lifecycle and shared private refusal transport');
wprism_check_summary('protected identity native admission contract');
