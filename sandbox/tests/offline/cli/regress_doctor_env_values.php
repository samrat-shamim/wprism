<?php
// Regression — DUO-3254: Doctor's tracked env-values branch must be a real
// blocking failure even though the stock sandbox image can only exercise the
// no-git advisory branch. This fake stops at the existing Transport boundary;
// Doctor::run() and its exact shell-probe interpretation remain production code.

declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/Doctor.php';

use Duo\Orchestrator\Doctor;
use Duo\Orchestrator\Transport;

final class DoctorTransport extends Transport {
    public array $rawScripts = [];

    /** @param array{exit:int, stdout:string, stderr:string}|null $gitOverride raw result for the probe, overriding the exit:0/stdout:$gitResult default (DUO-3512: simulates a transport/shell failure the sentinel-only fake below couldn't express) */
    public function __construct(private string $gitResult, private ?array $gitOverride = null) {
        parent::__construct('doctor-regression', ['repo_path' => '/srv/site-repo']);
    }

    public function describe(): string {
        return 'doctor regression transport';
    }

    protected function wpCommand(array $wpArgs): string {
        return 'unused';
    }

    protected function rawCommand(string $script): string {
        return 'unused';
    }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo duo-reachable') {
            return self::result('duo-reachable');
        }
        if (str_contains($script, 'site.duo.json')) {
            return self::result('duo-repo-ok');
        }
        if (str_contains($script, 'git ls-files --error-unmatch .duo-env-values.json')) {
            return $this->gitOverride ?? self::result($this->gitResult);
        }
        return ['exit' => 97, 'stdout' => '', 'stderr' => "unexpected raw command: $script"];
    }

    public function captureWp(array $wpArgs): array {
        if ($wpArgs === ['core', 'is-installed']) {
            return self::result('');
        }
        $snippet = (string) ($wpArgs[1] ?? '');
        if (str_contains($snippet, 'class_exists')) {
            return self::result('duo-ok');
        }
        if (str_contains($snippet, 'DISALLOW_FILE_MODS')) {
            return self::result('duo-set');
        }
        if (str_contains($snippet, 'PHP_VERSION')) {
            return self::result('8.3.33|11.8.8|mariadb|7.0.2');
        }
        return ['exit' => 98, 'stdout' => '', 'stderr' => 'unexpected wp command: ' . json_encode($wpArgs)];
    }

    private static function result(string $stdout): array {
        return ['exit' => 0, 'stdout' => $stdout . "\n", 'stderr' => ''];
    }
}

function fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check(array $result, string $label): array {
    foreach ($result['checks'] as $check) {
        if ($check['label'] === $label) {
            return $check;
        }
    }
    fail("missing Doctor check: $label");
}

function pass(string $message): void {
    echo "ok: $message\n";
}

$label = '.duo-env-values.json not git-tracked';

$trackedTransport = new DoctorTransport('duo-tracked');
$tracked = Doctor::run($trackedTransport);
$trackedCheck = check($tracked, $label);
if ($tracked['ok'] !== false || $trackedCheck['ok'] !== false || !empty($trackedCheck['advisory'])) {
    fail('a tracked secrets scratch file was not a blocking Doctor failure');
}
if (!str_contains($trackedCheck['detail'], 'is committed to this repo')
    || !str_contains($trackedCheck['detail'], 'git rm --cached')) {
    fail('tracked-file failure did not carry actionable remediation');
}
if (count(array_filter(
    $trackedTransport->rawScripts,
    fn(string $script): bool => str_contains($script, 'git ls-files --error-unmatch .duo-env-values.json')
)) !== 1) {
    fail('Doctor did not execute the production git tracked-status probe exactly once');
}
pass('git-tracked .duo-env-values.json is a non-advisory failure with remediation');

$untracked = Doctor::run(new DoctorTransport('duo-untracked'));
$untrackedCheck = check($untracked, $label);
if ($untracked['ok'] !== true || $untrackedCheck['ok'] !== true || !empty($untrackedCheck['advisory'])) {
    fail('untracked/absent env-values file did not remain a clean pass');
}
pass('untracked or absent .duo-env-values.json remains clean');

$noGit = Doctor::run(new DoctorTransport('duo-nogit'));
$noGitCheck = check($noGit, $label);
if ($noGit['ok'] !== true || $noGitCheck['ok'] !== false || empty($noGitCheck['advisory'])
    || !str_contains($noGitCheck['detail'], 'could not verify')) {
    fail('no-git environment did not remain an honest non-blocking advisory');
}
pass('missing git remains an explicit advisory rather than a false pass');

// DUO-3512: a transport/shell failure produces neither sentinel Doctor
// otherwise switches on. Before this fix, $out === 'duo-tracked' read that
// as false and the check rendered a clean, non-advisory [PASS] — a false
// clean bill of health from a probe that never actually ran.
$erroredExit = new DoctorTransport('unused', ['exit' => 1, 'stdout' => '', 'stderr' => 'connection reset by peer']);
$errored = Doctor::run($erroredExit);
$erroredCheck = check($errored, $label);
if ($errored['ok'] !== true || $erroredCheck['ok'] !== false || empty($erroredCheck['advisory'])) {
    fail('a non-zero exit / empty stdout from the tracked-status probe was not rendered as a non-blocking WARN');
}
if (!str_contains($erroredCheck['detail'], 'could not verify')
    || !str_contains($erroredCheck['detail'], 'the tracked-status probe did not run')
    || !str_contains($erroredCheck['detail'], 'connection reset by peer')) {
    fail('probe-failure WARN did not name that the probe did not run, with the underlying reason');
}
pass('non-zero exit / empty stdout from the probe is a WARN naming the reason, never a silent PASS');

$garbledExit = new DoctorTransport('unused', ['exit' => 0, 'stdout' => "garbled\n", 'stderr' => '']);
$garbled = Doctor::run($garbledExit);
$garbledCheck = check($garbled, $label);
if ($garbled['ok'] !== true || $garbledCheck['ok'] !== false || empty($garbledCheck['advisory'])) {
    fail('exit 0 with unexpected stdout from the tracked-status probe was not rendered as a non-blocking WARN');
}
if (!str_contains($garbledCheck['detail'], 'could not verify')
    || !str_contains($garbledCheck['detail'], 'the tracked-status probe did not run')) {
    fail('unexpected-stdout WARN did not name that the probe did not run');
}
pass('exit 0 with output outside the three known sentinels is a WARN, never a silent PASS');

echo "REGRESS_DOCTOR_ENV_VALUES PASSED\n";
