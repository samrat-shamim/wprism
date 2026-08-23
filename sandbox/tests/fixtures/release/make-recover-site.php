<?php
declare(strict_types=1);

/**
 * Build the offline fixture `regress_recover_ordering.sh` drives
 * `php cli/duo recover` against.
 *
 * The signed catalog and the signed rollback exist only on an SSH-adopted
 * target, because the adopted rollback authority runtime is what every
 * action in `recovery/rollback-control.php` is reached through. This fixture
 * therefore builds a real `ssh` environment entry and puts a fake `ssh` on
 * PATH that runs the remote command locally — the same shape
 * `sandbox/tests/fixtures/duo3344-scoped-promote-unit.php` uses, kept small
 * here because the subject under test is the HOST'S ordering, not the signed
 * runtime (which `regress_scoped_promote_unit.sh` already covers end to end).
 * A second, `local` environment (`plain`) points at the same target
 * repository with no authority runtime in play, so the retained release
 * checkpoints — the rows every transport has — are exercised on a transport
 * that has nothing else.
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
 *                     .duo/artifacts/promote-<owner>.json
 *                       — the compiled artifact the same promotion retained,
 *                         whose top-level artifact_hash is the lease identity
 *                         a retained checkpoint is restored under; a suite
 *                         deletes it to exercise the no-identity refusal
 *                     .duo/checkpoints/promote-<code-owner>.sql (+ artifact)
 *                       — a second retained checkpoint whose frozen plan
 *                         entered a code lifecycle phase, for code-first
 *                     .duo/checkpoints/deploy-<deploy-owner>.sql (+ artifact)
 *                       — the checkpoint a standalone `duo deploy` retains
 *                         under its own lease. Same kind, same four ordered
 *                         steps; only the file-name prefix differs, which is
 *                         exactly what RetainedCheckpoints::prefixForRow()
 *                         reads back off the row id
 *   envs.json       one `ssh` environment named `fixture`, one `local`
 *                   environment named `plain`, and one `local` environment
 *                   named `configured` that HAS a rollback authority, all on
 *                   the same target repository
 *   signing.key     an Ed25519 controller secret for the `configured` env
 *   bin/ssh         runs the remote command locally
 *   bin/wp          records every call and honours the injected exit codes
 *   envs.json       one `ssh` environment named `fixture`
 *
 * Environment variables the generated executables honour:
 *
 *   DUO_WP_CALLS=<file>      every fake-wp invocation, one per line, in order
 *   DUO_RECOVER_STATUS=<f>   the authority status document to serve
 *   DUO_ABORT_EXIT=<n>       `duo promotion-abort` exit code (default 0)
 *   DUO_ABORT_ENVELOPE=<f>   a file whose bytes `duo promotion-abort` prints
 *                            on STDOUT, for the refusal the agent answers a
 *                            `--format=json` abort with
 *                            (`duo-command-refusal/v1`). Set it together with
 *                            DUO_ABORT_EXIT=<non-zero>: an envelope is what a
 *                            REFUSED abort returns, and the two knobs stay
 *                            separate so a suite can also prove that a
 *                            failure carrying no envelope keeps the constant
 *                            detail (RecoverCommand::STEP_FAILED_DETAIL)
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
/** The second retained checkpoint: a release that entered a code lifecycle phase. */
const RECOVER_CODE_OWNER = 'recover-fixture-code';
const RECOVER_CODE_ARTIFACT = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
/**
 * The third: a standalone `duo deploy`'s own checkpoint. It reuses
 * RECOVER_ARTIFACT so the SAME frozen plan (database-only, no lifecycle phase)
 * governs it — the subject here is the file-name prefix reaching restore, not
 * a second code-first scenario, which RECOVER_CODE_OWNER already covers.
 */
const RECOVER_DEPLOY_OWNER = 'deploy-recover-fixture-owner';

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

// A second frozen plan, for a release that entered the code lifecycle
// window: its retained checkpoint is one taken around a code phase, so
// restoring it is subject to code-first even though the checkpoint file
// itself carries no code evidence.
$codePlan = json_decode((string) file_get_contents(__DIR__ . '/plan-clean.json'), true, 512, JSON_THROW_ON_ERROR);
$codeDocument = AuthorizationPlan::build([
    'authority' => [],
    'capabilities' => [],
    'contract' => [
        'contract_digest' => 'sha256:' . str_repeat('cd', 32),
        'declarations' => ['external_effects' => [[
            'containment' => 'live',
            'effect_recovery_semantics' => 'compensatable',
            'reason' => 'the plugin lifecycle window fires WordPress hooks',
            'surfaces' => [AuthorizationPlan::LIFECYCLE_WINDOW_SURFACE],
        ]]],
    ],
    'deletion_semantics' => [],
    'environment' => 'fixture',
    'flags' => ['plan_only' => false, 'with_deletes' => false],
    'frozen_at' => '2026-08-17T10:00:00Z',
    'plan' => $codePlan,
    'projection' => [],
    'recovery' => [
        'checkpoint_at' => '2026-08-17T10:00:00Z',
        'claim' => $claim,
        'selected' => RecoveryClaim::OPERATOR_DIRECTED,
        'selected_because' => 'the fixture target proves a database checkpoint and nothing more',
    ],
    'scope' => ['code' => ['lifecycle_phases' => ['retire', 'activate'], 'plugins_changed' => 1, 'themes_changed' => 0], 'surfaces' => []],
    'target' => [
        'artifact_hash' => RECOVER_CODE_ARTIFACT,
        'code_revision_from' => str_repeat('e1', 20),
    ],
]);
AuthorizationPlan::freeze($codeDocument, "$dir/site");

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

foreach (['.duo/control/recovery-runtime', '.duo/checkpoints', '.duo/artifacts'] as $child) {
    if (!is_dir("$dir/target/$child") && !mkdir("$dir/target/$child", 0700, true)) {
        fwrite(STDERR, "make-recover-site: could not create $dir/target/$child\n");
        exit(2);
    }
}
file_put_contents(
    "$dir/target/.duo/checkpoints/promote-" . RECOVER_OWNER . '.sql',
    "-- fixture checkpoint\n"
);
// The compiled artifact promote retained beside the checkpoint, in the
// agent's own encoding (CompiledArtifact::write() -> Canon::encode(), the
// pretty-printed canonical form): its top-level artifact_hash is what a
// retained checkpoint's lease identity is read from.
file_put_contents(
    "$dir/target/.duo/artifacts/promote-" . RECOVER_OWNER . '.json',
    \Duo\Canon::encode(['artifact_hash' => RECOVER_ARTIFACT, 'format' => 'duo-compiled/fixture'])
);
// The deploy checkpoint and its sibling artifact, stem for stem: that shared
// stem is the whole reason RetainedCheckpoints reads the lease identity out of
// `artifacts/<basename>.json` with no second mechanism. Dated between the two
// promote checkpoints so the merged listing order is deterministic.
file_put_contents(
    "$dir/target/.duo/checkpoints/" . RECOVER_DEPLOY_OWNER . '.sql',
    "-- fixture checkpoint (deploy)\n"
);
touch("$dir/target/.duo/checkpoints/" . RECOVER_DEPLOY_OWNER . '.sql', 1_750_000_000);
file_put_contents(
    "$dir/target/.duo/artifacts/" . RECOVER_DEPLOY_OWNER . '.json',
    \Duo\Canon::encode(['artifact_hash' => RECOVER_ARTIFACT, 'format' => 'duo-compiled/fixture'])
);
// The code-phase release's pair, dated earlier so the listing order is fixed.
file_put_contents(
    "$dir/target/.duo/checkpoints/promote-" . RECOVER_CODE_OWNER . '.sql',
    "-- fixture checkpoint (code phase)\n"
);
touch("$dir/target/.duo/checkpoints/promote-" . RECOVER_CODE_OWNER . '.sql', 1_700_000_000);
file_put_contents(
    "$dir/target/.duo/artifacts/promote-" . RECOVER_CODE_OWNER . '.json',
    \Duo\Canon::encode(['artifact_hash' => RECOVER_CODE_ARTIFACT, 'format' => 'duo-compiled/fixture'])
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
  *" duo promotion-abort "*)
    # The agent answers a --format=json refusal with one
    # `duo-command-refusal/v1` object on STDOUT and exits non-zero
    # (agent/src/Command/Cli.php:145-146). Reproduce exactly that: bytes on
    # stdout, the injected exit code, nothing on stderr.
    if [ -n "${DUO_ABORT_ENVELOPE:-}" ] && [ -f "${DUO_ABORT_ENVELOPE}" ]; then
      cat "${DUO_ABORT_ENVELOPE}"
    fi
    exit "${DUO_ABORT_EXIT:-0}"
    ;;
  *" duo promotion-begin "*) exit "${DUO_BEGIN_EXIT:-0}" ;;
  *" db import "*) exit "${DUO_IMPORT_EXIT:-0}" ;;
  *" core is-installed "*) exit 0 ;;
  *is_multisite*)
    # RecoverCommand's host-side topology probe, asked before step 1.
    # DUO_TOPOLOGY_EXIT drives the fail-closed "cannot answer" case; the
    # answer itself is printed exactly as `wp eval` would.
    if [ "${DUO_TOPOLOGY_EXIT:-0}" != 0 ]; then
      exit "${DUO_TOPOLOGY_EXIT}"
    fi
    printf '%s' "${DUO_TOPOLOGY:-single-site}"
    exit 0
    ;;
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
        // The same target through a transport that carries no rollback
        // authority runtime: only the retained checkpoints exist here.
        'plain' => [
            'transport' => 'local',
            'wp_path' => realpath("$dir/wordpress") ?: "$dir/wordpress",
            'repo_path' => realpath("$dir/target") ?: "$dir/target",
        ],
        // The same target, the same transport class, the same runtime — the
        // only difference is that this environment configured a rollback
        // authority. It is what makes `recovery_authority_unavailable` a
        // statement about configuration rather than about SSH.
        'configured' => [
            'transport' => 'local',
            'wp_path' => realpath("$dir/wordpress") ?: "$dir/wordpress",
            'repo_path' => realpath("$dir/target") ?: "$dir/target",
            'rollback_key_id' => 'recover-ordering-fixture',
            'rollback_signing_key' => "$dir/signing.key",
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$keypair = sodium_crypto_sign_keypair();
file_put_contents("$dir/signing.key", base64_encode(sodium_crypto_sign_secretkey($keypair)) . "\n");
chmod("$dir/signing.key", 0600);

echo "recover fixture ready: $dir\n";
