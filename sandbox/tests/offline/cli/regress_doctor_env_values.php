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

    /** @param array{exit:int, stdout:string, stderr:string}|null $gitOverride raw result for the composed repo/git probe, overriding the exit:0/stdout:"duo-repo-ok\n$gitResult" default (DUO-3512: simulates a transport/shell failure the sentinel-only fake below couldn't express; DUO-3511: one script answers both rows, so an override carries BOTH lines) */
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
        // DUO-3511: the repo-path answer and the tracked-status answer travel
        // as line 1 and line 2 of ONE script, so this seam injects the git
        // outcome where Doctor now reads it — as line 2, under a line 1 that
        // says the repo is fine. The tracked-status branches under test are
        // unchanged: they still decide from this exact token. $gitOverride
        // (DUO-3512) therefore overrides the WHOLE composed result, and the
        // suite below builds its two-line payloads accordingly.
        if (str_contains($script, 'site.duo.json')
            && str_contains($script, 'git ls-files --error-unmatch .duo-env-values.json')) {
            return $this->gitOverride ?? self::result("duo-repo-ok\n" . $this->gitResult);
        }
        return ['exit' => 97, 'stdout' => '', 'stderr' => "unexpected raw command: $script"];
    }

    public function captureWp(array $wpArgs): array {
        if ($wpArgs === ['core', 'is-installed']) {
            return self::result('');
        }
        $snippet = (string) ($wpArgs[1] ?? '');
        if (str_contains($snippet, 'class_exists')
            && str_contains($snippet, 'DISALLOW_FILE_MODS')
            && str_contains($snippet, 'db_server_info')) {
            return self::result((string) json_encode([
                'agent' => 'duo-ok',
                'file_mods' => 'duo-set',
                'php' => '8.3.33',
                'db_version' => '11.8.8',
                'db_engine' => 'mariadb',
                'filesystem' => [
                    'directory_separator' => '/',
                    'os_family' => 'Linux',
                    'functions' => [
                        'chmod' => true,
                        'flock' => true,
                        'fsync' => true,
                        'lstat' => true,
                        'rename' => true,
                    ],
                ],
                'wp' => '7.0.3',
                'site_mode' => 'single-site',
            ]));
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
//
// DUO-3511 composed the repo-path probe and this one into a single script, so
// the shape of "the tracked-status half did not answer" changed while the
// defect class did not: the script prints line 1, then dies or is truncated
// before line 2. That is what these two payloads are — a line 1 that says the
// repo is fine, and a line 2 that is missing or garbage. A non-zero exit is a
// THIRD case now, asserted separately below, because under composition it
// sinks the repo row instead of reaching this branch.
$erroredExit = new DoctorTransport('unused', ['exit' => 0, 'stdout' => "duo-repo-ok\n", 'stderr' => 'connection reset by peer']);
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
pass('a missing tracked-status line is a WARN naming the reason, never a silent PASS');

$garbledExit = new DoctorTransport('unused', ['exit' => 0, 'stdout' => "duo-repo-ok\ngarbled\n", 'stderr' => '']);
$garbled = Doctor::run($garbledExit);
$garbledCheck = check($garbled, $label);
if ($garbled['ok'] !== true || $garbledCheck['ok'] !== false || empty($garbledCheck['advisory'])) {
    fail('exit 0 with unexpected stdout from the tracked-status probe was not rendered as a non-blocking WARN');
}
if (!str_contains($garbledCheck['detail'], 'could not verify')
    || !str_contains($garbledCheck['detail'], 'the tracked-status probe did not run')
    || !str_contains($garbledCheck['detail'], 'garbled')) {
    fail('unexpected-stdout WARN did not name that the probe did not run, with what it printed instead');
}
pass('a tracked-status line outside the three known sentinels is a WARN, never a silent PASS');

// DUO-3511: the reason must be the git half's OWN answer, not the whole
// composed payload. This is the case that bites: with stderr empty,
// self::reason() falls through to stdout, and self::reason($r) on the
// composed result would fold `duo-repo-ok` — and the newline between the two
// answers — into this one-line detail, where DUO-3512 rendered `garbled`
// alone.
if (str_contains($garbledCheck['detail'], 'duo-repo-ok') || str_contains($garbledCheck['detail'], "\n")) {
    fail('unexpected-stdout WARN folded the composed script\'s repo-half answer into its one-line reason');
}
pass('the WARN names the tracked-status answer alone, never the composed payload');

// DUO-3511 + DUO-3512 together: under composition a non-zero exit is the ONE
// failure DUO-3512's branch cannot reach, because it sinks $repoOk first. The
// invariant DUO-3512 exists for still holds, and holds harder — the row is a
// BLOCKING failure, not an advisory WARN, and the repo row fails beside it
// naming the transport reason. This is also byte-identical to what a failed
// repo probe rendered before either issue, which is why it is not a
// regression of the WARN: it is the louder answer taking precedence.
$repoLabel = 'repo path has site.duo.json (/srv/site-repo)';
$deadTransport = new DoctorTransport('unused', ['exit' => 7, 'stdout' => '', 'stderr' => 'connection reset by peer']);
$dead = Doctor::run($deadTransport);
$deadGit = check($dead, $repoLabel);
$deadCheck = check($dead, $label);
if ($dead['ok'] !== false || $deadGit['ok'] !== false
    || !str_contains($deadGit['detail'], 'connection reset by peer')) {
    fail('a failed composed probe did not fail the repo-path row with the transport reason');
}
if ($deadCheck['ok'] !== false || !empty($deadCheck['advisory'])
    || $deadCheck['detail'] !== 'skipped: repo path unavailable') {
    fail('a failed composed probe did not leave the tracked-status row a blocking, honest skip');
}
pass('a failed composed probe is a blocking repo failure plus an honest skip, never a false clean bill of health');

echo "REGRESS_DOCTOR_ENV_VALUES PASSED\n";
