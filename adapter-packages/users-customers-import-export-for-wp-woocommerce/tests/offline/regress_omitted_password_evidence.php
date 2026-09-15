<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once "$root/sandbox/tests/lib/check.php";
require_once "$capsule/fixtures/omitted-password-evidence.php";

$scratch = sys_get_temp_dir() . '/wprism-importer-omitted-password-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
register_shutdown_function(static function () use ($scratch): void {
    foreach (glob($scratch . '/*') ?: [] as $path) unlink($path);
    rmdir($scratch);
});
$stem = $scratch . '/command';
$pair = 'omitprobe';
$record = [
    'phase' => 'consume',
    'history_id' => 17,
    'run' => ['response' => true, 'finished' => 1, 'total_success' => 1],
    'display_name' => 'Generated target',
    'existing_user' => true,
    'password_hash_nonempty' => true,
    'password_preserved' => true,
];
$path = '/var/www/html/wp-content/plugins/users-customers-import-export-for-wp-woocommerce/admin/modules/user/import/import.php';
$logged = '[15-Sep-2026 18:09:23 UTC] PHP Warning:  Undefined array key "user_pass" in ' . $path . ' on line 640';
$displayed = 'Warning: Undefined array key "user_pass" in ' . $path . ' on line 640';
$validStderr = " Container wprism-$pair-cli2-run-0123456789ab Creating \n Container wprism-$pair-cli2-run-0123456789ab Created \n"
    . "$logged\n$displayed\n$logged\n$displayed\n";
$write = static function (array $value, string $stderr = '', int $exit = 0, ?string $stdout = null) use ($stem): void {
    $streams = [
        'stdout' => $stdout ?? json_encode($value, JSON_THROW_ON_ERROR) . "\n",
        'stderr' => $stderr,
        'exit' => $exit . "\n",
    ];
    foreach ($streams as $suffix => $bytes) {
        file_put_contents($stem . '.' . $suffix, $bytes);
        chmod($stem . '.' . $suffix, 0600);
    }
};
$write($record, $validStderr);
wprism_check_same($record, ImporterOmittedPasswordEvidence::admittedLimitation($stem, $pair),
    'exact existing-user outcome and two native warnings admit the upstream limitation');
$newUser = $record;
$newUser['existing_user'] = false;
$newUser['password_preserved'] = null;
ImporterOmittedPasswordEvidence::result($newUser, false);
wprism_check(true, 'diagnostic-free new-user result admits generated-password behavior');

$faults = [
    'no-warning' => [$record, '', 0, null],
    'one-warning' => [$record, " Container wprism-$pair-cli2-run-0123456789ab Created \n$logged\n$displayed\n", 0, null],
    'wrong-line' => [$record, str_replace('line 640', 'line 641', $validStderr), 0, null],
    'wrong-key' => [$record, str_replace('"user_pass"', '"user_email"', $validStderr), 0, null],
    'extra-diagnostic' => [$record, $validStderr . "PHP Notice: unrelated\n", 0, null],
    'warning-on-stdout' => [$record, $validStderr, 0, "PHP Warning: moved\n" . json_encode($record, JSON_THROW_ON_ERROR)],
    'nonzero' => [$record, $validStderr, 1, null],
    'new-user' => [array_replace($record, ['existing_user' => false, 'password_preserved' => null]), $validStderr, 0, null],
    'changed-password' => [array_replace($record, ['password_preserved' => false]), $validStderr, 0, null],
    'failed-job' => [array_replace($record, ['run' => ['response' => false, 'finished' => 1, 'total_success' => 0]]), $validStderr, 0, null],
];
foreach ($faults as $name => [$value, $stderr, $exit, $stdout]) {
    $write($value, $stderr, $exit, $stdout);
    wprism_check_throws(static fn() => ImporterOmittedPasswordEvidence::admittedLimitation($stem, $pair), Throwable::class,
        'upstream limitation evidence rejects ' . $name);
}
wprism_check_summary('importer omitted-password evidence');
