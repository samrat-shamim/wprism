<?php
/**
 * issue #3513: DockerTransport's `mode: "exec"` opt-in.
 *
 * `run --rm` (today's default, unconditionally) pays container create
 * (~0.40s) + dependency resolution (~0.51s on the legacy
 * sandbox/docker-compose.yml estate; N/A on sandbox/pair.yml, which
 * deliberately has no depends_on) + wp-cli startup (~0.33s) on EVERY call.
 * `exec -T` against an already-resident service measured ~0.14s flat.
 * `mode` is a strict, closed opt-in (absent/"run" byte-identical to before;
 * "exec" switches the verb; anything else refuses loudly) because a resident
 * container means no fresh-container-per-call: env/mount edits go stale and
 * wp-cli's own cache/tmp state persists (cli/README.md, docs/sandbox.md).
 *
 * This suite is entirely offline: it never shells out to a real `docker`.
 * The one thing that legitimately would (the "is the service running?"
 * precondition DockerTransport must check before trusting `exec`) is
 * injected through DockerTransport's constructor seam
 * ($serviceRunningProbe), so the not-running branch is exercised
 * deterministically without a compose stack.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/DockerTransport.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/Doctor.php';
require_once __DIR__ . '/../../../../cli/src/Command/DoctorCommand.php';

use WPrism\Orchestrator\DockerTransport;
use WPrism\Orchestrator\Doctor;
use WPrism\Orchestrator\DoctorCommand;

$cfg = [
    'transport' => 'docker',
    'compose_file' => '/tmp/wprism-exec-mode-compose.yml',
    'service' => 'cli',
    'repo_path' => '/repo',
];

$wpCommand = new ReflectionMethod(DockerTransport::class, 'wpCommand');
$rawCommand = new ReflectionMethod(DockerTransport::class, 'rawCommand');

// ---------------------------------------------------------------------
// Rule 8: mode absent, and mode explicitly "run", stay byte-identical to
// the pre-issue #3513 command strings and describe() line -- this whole
// feature is opt-in, so nobody who never sets `mode` should see one
// different byte on the wire.
// ---------------------------------------------------------------------
$default = new DockerTransport('docker-default', $cfg);
wprism_check_same(
    "'docker' 'compose' '-f' '/tmp/wprism-exec-mode-compose.yml' 'run' '--rm' '-T' 'cli' 'wp' 'wprism' 'status'",
    $wpCommand->invoke($default, ['wprism', 'status']),
    'mode absent renders the pre-issue #3513 `run --rm` wp command byte-for-byte'
);
wprism_check_same(
    "'docker' 'compose' '-f' '/tmp/wprism-exec-mode-compose.yml' 'run' '--rm' '-T' 'cli' 'bash' '-c' 'echo hi'",
    $rawCommand->invoke($default, 'echo hi'),
    'mode absent renders the pre-issue #3513 `run --rm` raw command byte-for-byte'
);
wprism_check_same(
    'docker compose_file=/tmp/wprism-exec-mode-compose.yml service=cli repo_path=/repo',
    $default->describe(),
    'mode absent leaves describe() byte-identical -- no mode= suffix'
);

$explicitRun = new DockerTransport('docker-explicit-run', $cfg + ['mode' => 'run']);
wprism_check_same(
    $wpCommand->invoke($default, ['wprism', 'status']),
    $wpCommand->invoke($explicitRun, ['wprism', 'status']),
    'explicit mode "run" is byte-identical to mode absent for wp commands'
);
wprism_check_same(
    $rawCommand->invoke($default, 'echo hi'),
    $rawCommand->invoke($explicitRun, 'echo hi'),
    'explicit mode "run" is byte-identical to mode absent for raw commands'
);
wprism_check_same(
    $default->describe(),
    $explicitRun->describe(),
    'explicit mode "run" is byte-identical to mode absent in describe()'
);

// ---------------------------------------------------------------------
// mode "exec" against a running service: `exec -T`, never `run`/`--rm`.
// ---------------------------------------------------------------------
$execCfg = $cfg + ['mode' => 'exec'];
$exec = new DockerTransport('docker-exec', $execCfg, static fn(): bool => true);
wprism_check_same(
    "'docker' 'compose' '-f' '/tmp/wprism-exec-mode-compose.yml' 'exec' '-T' 'cli' 'wp' 'wprism' 'status'",
    $wpCommand->invoke($exec, ['wprism', 'status']),
    'mode "exec" renders `exec -T` for wp commands with no `run`/`--rm`'
);
wprism_check_same(
    "'docker' 'compose' '-f' '/tmp/wprism-exec-mode-compose.yml' 'exec' '-T' 'cli' 'bash' '-c' 'echo hi'",
    $rawCommand->invoke($exec, 'echo hi'),
    'mode "exec" renders `exec -T` for raw commands with no `run`/`--rm`'
);
wprism_check_same(
    'docker compose_file=/tmp/wprism-exec-mode-compose.yml mode=exec service=cli repo_path=/repo',
    $exec->describe(),
    'mode "exec" is visible in describe() (wprism envs)'
);
wprism_check_same(
    "'docker' 'compose' '-f' '/tmp/wprism-exec-mode-compose.yml' 'exec' '-T' 'cli' 'wp' 'wprism' 'status'",
    $exec->wpInstruction(['wprism', 'status']),
    'the public wpInstruction() (env-set stdin handoff) matches the exec wp command'
);

$execProfile = new DockerTransport('docker-exec-profile', $execCfg + ['profile' => 'grind'], static fn(): bool => true);
wprism_check_same(
    "'docker' 'compose' '-f' '/tmp/wprism-exec-mode-compose.yml' '--profile' 'grind' 'exec' '-T' 'cli' 'wp' 'wprism' 'status'",
    $wpCommand->invoke($execProfile, ['wprism', 'status']),
    'mode "exec" composes with --profile exactly like mode "run" does'
);

// ---------------------------------------------------------------------
// Invalid mode: loud refusal naming the env, the key, and the offending
// value -- never a silent coercion to "run" (rule 9).
// ---------------------------------------------------------------------
$badMode = null;
try {
    new DockerTransport('docker-bad-mode', $cfg + ['mode' => 'walk']);
} catch (\RuntimeException $e) {
    $badMode = $e;
}
wprism_check($badMode !== null, 'an unrecognized string mode refuses construction');
if ($badMode !== null) {
    wprism_check(str_contains($badMode->getMessage(), "env 'docker-bad-mode'"), 'invalid mode error names the environment');
    wprism_check(str_contains($badMode->getMessage(), "'mode'"), 'invalid mode error names the key');
    wprism_check(str_contains($badMode->getMessage(), "'walk'"), 'invalid mode error names the offending value');
}

$badModeBool = null;
try {
    new DockerTransport('docker-bad-mode-bool', $cfg + ['mode' => true]);
} catch (\RuntimeException $e) {
    $badModeBool = $e;
}
wprism_check($badModeBool !== null, 'a non-string (bool) mode refuses construction rather than being silently truthy-coerced');
if ($badModeBool !== null) {
    wprism_check(
        str_contains($badModeBool->getMessage(), "env 'docker-bad-mode-bool'") && str_contains($badModeBool->getMessage(), 'true'),
        'invalid boolean mode error names the environment and the offending value'
    );
}

// ---------------------------------------------------------------------
// Not-running precondition: a FAILED capture result carrying the exact
// remedy, never a docker command line at all -- the probe result is
// injected via the constructor seam, so this needs no compose stack.
// ---------------------------------------------------------------------
$probeCalls = 0;
$down = new DockerTransport(
    'docker-exec-down',
    $execCfg + ['profile' => 'grind'],
    static function () use (&$probeCalls): bool {
        $probeCalls++;
        return false;
    }
);
$expectedRemedy = "env 'docker-exec-down': transport mode \"exec\" requires service 'cli' to be running. "
    . "remedy: docker compose -f /tmp/wprism-exec-mode-compose.yml --profile grind up -d cli";

$rawResult = $down->captureRaw('echo wprism-reachable');
wprism_check_same(1, $rawResult['exit'], 'not-running captureRaw() returns a non-zero exit, not a hung/real docker call');
wprism_check_same('', $rawResult['stdout'], 'not-running captureRaw() carries no stdout -- nothing was actually run against docker');
wprism_check_same($expectedRemedy . "\n", $rawResult['stderr'], 'not-running captureRaw() stderr is exactly the named remedy, never a `run` command string');

$wpResult = $down->captureWp(['wprism', 'status']);
wprism_check_same(1, $wpResult['exit'], 'not-running captureWp() also returns the failed result, not a `run` fallback');
wprism_check_same($expectedRemedy . "\n", $wpResult['stderr'], 'not-running captureWp() stderr matches the same named remedy');

// The precondition is cached PER PROCESS (one DockerTransport instance
// lives for one `wprism` invocation, per cli/wprism's single Transport::make()
// call) rather than re-probed on every command -- a probe that ran
// per-command would pay a real docker round trip on every single wp-cli
// call this mode exists to make cheap. Two capture calls above, still one
// probe invocation.
wprism_check_same(1, $probeCalls, 'two capture calls so far share one cached probe invocation');

$down->captureRaw('echo again');
wprism_check_same(1, $probeCalls, 'a third capture call reuses the cached probe result instead of probing again');

// ---------------------------------------------------------------------
// End-to-end: `wprism doctor`'s reachability check is the first thing that
// calls captureRaw(), so this is the exact path the issue names --
// [FAIL] transport reachable, with the remedy, never a hang or a docker
// error dump.
// ---------------------------------------------------------------------
$doctorDown = new DockerTransport('docker-doctor-down', $execCfg, static fn(): bool => false);
$doctorResult = Doctor::run($doctorDown);
wprism_check($doctorResult['ok'] === false, 'doctor overall result is not ok when the exec precondition fails');
wprism_check(
    $doctorResult['checks'][0]['label'] === 'transport reachable' && $doctorResult['checks'][0]['ok'] === false,
    'doctor\'s first check is the failed reachability probe, gating every later check'
);
wprism_check(
    str_contains($doctorResult['checks'][0]['detail'], 'remedy: docker compose')
        && str_contains($doctorResult['checks'][0]['detail'], 'up -d'),
    'doctor surfaces the exact up -d remedy in its reachability detail'
);

ob_start();
DoctorCommand::render($doctorResult);
$rendered = (string) ob_get_clean();
wprism_check(
    str_contains($rendered, '[FAIL] transport reachable — ') && str_contains($rendered, 'remedy: docker compose'),
    'DoctorCommand renders "[FAIL] transport reachable — ... remedy: ..." exactly as the issue requires'
);

wprism_check_summary('docker-exec-mode');
