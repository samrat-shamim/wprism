<?php
declare(strict_types=1);

/**
 * Build the offline fixture `regress_recover_ordering.sh` drives
 * `php cli/duo recover` against.
 *
 * `duo recover` only exists on an SSH-adopted target, because the adopted
 * rollback authority runtime is what every action in
 * `recovery/rollback-control.php` is reached through. This fixture therefore
 * builds a real `ssh` environment entry and puts a fake `ssh` on PATH that
 * runs the remote command locally — the same shape
 * `sandbox/tests/fixtures/duo3344-scoped-promote-unit.php` uses, kept small
 * here because the subject under test is the HOST'S ordering, not the signed
 * runtime (which `regress_scoped_promote_unit.sh` already covers end to end).
 *
 * What it produces under `<dir>`:
 *
 *   site/           the LOCAL site repository (`site.duo.json`, a Git
 *                   worktree, and optionally one frozen authorization plan)
 *   target/         the TARGET repository as the driver sees it, carrying
 *                     .duo/control/recovery-runtime/rollback-control.php
 *                       — a stub that prints whatever canonical status
 *                         document `$DUO_RECOVER_STATUS` names
 *                     .duo/checkpoints/promote-<owner>.sql
 *                       — the operator-directed checkpoint; a suite deletes
 *                         it to exercise the absent-checkpoint refusal
 *   bin/ssh         runs the remote command locally
 *   bin/wp          records every call and honours the injected exit codes
 *   envs.json       one `ssh` environment named `fixture`
 *
 * Environment variables the generated executables honour:
 *
 *   DUO_WP_CALLS=<file>      every fake-wp invocation, one per line, in order
 *   DUO_RECOVER_STATUS=<f>   the authority status document to serve
 *   DUO_ABORT_EXIT=<n>       `duo promotion-abort` exit code (default 0)
 *   DUO_BEGIN_EXIT=<n>       `duo promotion-begin` exit code (default 0)
 *   DUO_IMPORT_EXIT=<n>      `wp db import` exit code (default 0) — the
 *                            failed-import case the mandatory final abort is
 *                            about
 *
 * Usage: make-recover-site.php <dir>
 */

$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/cli/src/Release/AuthorizationPlan.php';
require_once $root . '/cli/src/Recovery/RecoveryClaim.php';

use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\RecoveryClaim;

$dir = $argv[1] ?? null;
if (!is_string($dir) || $dir === '') {
    fwrite(STDERR, "usage: make-recover-site.php <dir>\n");
    exit(2);
}

/** The receipt identity every part of this fixture agrees on. */
const RECOVER_OWNER = 'recover-fixture-owner';
const RECOVER_RECEIPT = 'receipt-recover-fixture';
const RECOVER_ARTIFACT = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

foreach (['site', 'target', 'bin', 'wordpress', 'status'] as $child) {
    if (!is_dir("$dir/$child") && !mkdir("$dir/$child", 0700, true) && !is_dir("$dir/$child")) {
        fwrite(STDERR, "make-recover-site: could not create $dir/$child\n");
        exit(2);
    }
}

// ------------------------------------------------------------- the site repo
file_put_contents("$dir/site/site.duo.json", json_encode([
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exec('git -C ' . escapeshellarg("$dir/site") . ' init -q 2>/dev/null');
exec('git -C ' . escapeshellarg("$dir/site") . ' config user.email fixture@example.invalid 2>/dev/null');
exec('git -C ' . escapeshellarg("$dir/site") . ' config user.name fixture 2>/dev/null');

// One frozen authorization plan whose artifact hash matches the receipt, so
// the claim `duo recover` prints is provably the claim the operator was
// shown at authorization rather than a freshly-built lookalike.
$claim = RecoveryClaim::build([
    'covered_resources' => [RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT, RecoveryClaim::RESOURCE_CODE_RELEASE],
    'profile' => RecoveryClaim::OPERATOR_DIRECTED,
]);
$document = AuthorizationPlan::build([
    'authority' => [],
    'capabilities' => [],
    'contract' => null,
    'deletion_semantics' => [],
    'environment' => 'fixture',
    'flags' => ['plan_only' => false, 'with_deletes' => false],
    'frozen_at' => '2026-08-17T09:14:02Z',
    'plan' => json_decode(
        (string) file_get_contents(__DIR__ . '/plan-clean.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    ),
    'projection' => [],
    'recovery' => [
        // Beside the claim, not in it: the claim is digested into
        // `plan_digest`, so a clock value there would re-identify one
        // authorization every second (AuthorizationPlan::digest()).
        'checkpoint_at' => '2026-08-17T09:14:02Z',
        'claim' => $claim,
        'selected' => RecoveryClaim::OPERATOR_DIRECTED,
        'selected_because' => 'the fixture target proves a database checkpoint and nothing more',
    ],
    'scope' => ['code' => ['lifecycle_phases' => [], 'plugins_changed' => 0, 'themes_changed' => 0], 'surfaces' => []],
    'target' => [
        'artifact_hash' => RECOVER_ARTIFACT,
        'code_revision_from' => str_repeat('f0', 20),
    ],
]);
AuthorizationPlan::freeze($document, "$dir/site");
file_put_contents("$dir/plan-digest", (string) $document['plan_digest']);
file_put_contents("$dir/claim.json", RecoveryClaim::encode($claim));

// ----------------------------------------------------------- the target repo
file_put_contents("$dir/target/site.duo.json", json_encode([
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exec('git -C ' . escapeshellarg("$dir/target") . ' init -q 2>/dev/null');
exec('git -C ' . escapeshellarg("$dir/target")
    . ' -c user.email=f@example.invalid -c user.name=f commit -q --allow-empty -m target 2>/dev/null');

foreach (['.duo/control/recovery-runtime', '.duo/checkpoints'] as $child) {
    if (!is_dir("$dir/target/$child") && !mkdir("$dir/target/$child", 0700, true)) {
        fwrite(STDERR, "make-recover-site: could not create $dir/target/$child\n");
        exit(2);
    }
}
file_put_contents(
    "$dir/target/.duo/checkpoints/promote-" . RECOVER_OWNER . '.sql',
    "-- fixture checkpoint\n"
);

// The stub runtime. It answers only the two read-only actions the checkpoint
// catalog issues, from a document the suite chooses, in the runtime's own
// canonical encoding — anything else is left to the real runtime and its own
// suites.
$runtime = <<<'PHP'
<?php
declare(strict_types=1);

require_once getenv('DUO_RECOVERY_RUNTIME_SOURCE');

$action = $argv[1] ?? '';
$path = (string) getenv('DUO_RECOVER_STATUS');
if ($action === 'audit') {
    $document = ['event_chain_sha256' => str_repeat('cc', 32), 'ok' => true];
} else {
    $raw = $path === '' ? false : @file_get_contents($path);
    if (!is_string($raw)) {
        fwrite(STDERR, "fixture runtime: no status document\n");
        exit(1);
    }
    $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
echo \Duo\Recovery\CanonicalJson::encode($document) . "\n";
PHP;
file_put_contents("$dir/target/.duo/control/recovery-runtime/rollback-control.php", $runtime . "\n");

/**
 * One authority status document.
 *
 * @param array<string,mixed> $overrides
 */
$status = static function (array $overrides = []): array {
    return array_replace([
        'active' => true,
        'artifact_hash' => RECOVER_ARTIFACT,
        'checkpoint_sha256' => str_repeat('11', 32),
        'claim_expires_at' => '2026-08-18T09:14:02Z',
        'created_at' => '2026-08-17T09:14:02Z',
        'generation' => 3,
        'ok' => true,
        'owner' => RECOVER_OWNER,
        'receipt_id' => RECOVER_RECEIPT,
        'retention_until' => '2026-08-24T09:14:02Z',
        'state' => 'promoting',
        'target_id' => str_repeat('22', 32),
        'terminal' => false,
    ], $overrides);
};

// Operator-directed: a database checkpoint and a code release, which is the
// state that makes code-first ordering enforceable.
file_put_contents(
    "$dir/status/code.json",
    json_encode($status(['code_release_metadata_sha256' => str_repeat('33', 32)]), JSON_UNESCAPED_SLASHES) . "\n"
);
// The same receipt with no code evidence at all: a database-only checkpoint,
// where code-first has nothing to hold up.
file_put_contents("$dir/status/database-only.json", json_encode($status(), JSON_UNESCAPED_SLASHES) . "\n");
// No active receipt: `--list` must say so rather than printing an empty table.
file_put_contents(
    "$dir/status/none.json",
    json_encode(['active' => false, 'ok' => true], JSON_UNESCAPED_SLASHES) . "\n"
);

// ------------------------------------------------------------------ the PATH
$sshShim = <<<'SH'
#!/usr/bin/env bash
# `SshTransport::sshPrefix()` emits `ssh -T [-F cfg] <host> <command>`. The
# remote command is a single argument, so running it locally through bash is
# exactly the same execution the real ssh would have performed remotely.
set -u
args=()
while [ "$#" -gt 0 ]; do
  case "$1" in
    -T) shift ;;
    -F) shift 2 ;;
    *) args+=("$1"); shift ;;
  esac
done
# args[0] is the host, args[1] the command.
exec bash -c "${args[1]}"
SH;
file_put_contents("$dir/bin/ssh", $sshShim . "\n");
chmod("$dir/bin/ssh", 0700);

$wpShim = <<<'SH'
#!/usr/bin/env bash
set -u
printf '%s\n' "$*" >> "$DUO_WP_CALLS"
case " $* " in
  *" duo promotion-abort "*) exit "${DUO_ABORT_EXIT:-0}" ;;
  *" duo promotion-begin "*) exit "${DUO_BEGIN_EXIT:-0}" ;;
  *" db import "*) exit "${DUO_IMPORT_EXIT:-0}" ;;
  *" core is-installed "*) exit 0 ;;
esac
echo "fake wp: unhandled invocation: $*" >&2
exit 90
SH;
file_put_contents("$dir/bin/wp", $wpShim . "\n");
chmod("$dir/bin/wp", 0700);

file_put_contents("$dir/envs.json", json_encode([
    'envs' => [
        'fixture' => [
            'transport' => 'ssh',
            'host' => 'fixture.invalid',
            'wp_path' => realpath("$dir/wordpress") ?: "$dir/wordpress",
            'repo_path' => realpath("$dir/target") ?: "$dir/target",
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "recover fixture ready: $dir\n";
