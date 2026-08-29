<?php
/**
 * Offline product contract for `duo deploy`'s database checkpoint and its
 * recoverability.
 *
 * Three things can drift independently, and only the round trip catches the
 * third:
 *
 *   1. WHERE the export sits. It has to be under the promotion lease, after
 *      `promotion-begin` and before `code-stage` — the position promote uses
 *      (cli/duo:2385-2388). Taken earlier the dump carries no lease row or a
 *      stale one, and `duo recover`'s abort -> begin -> import -> final abort
 *      sequence (RecoverCommand::ORDERED_STEPS) would restore a database
 *      inconsistent with the lease those four steps re-take
 *      (cli/duo:3289-3297). Taken later it already describes mutated code.
 *   2. WHAT it is named. `deploy-<runId>.sql` is the sibling of the
 *      `deploy-<runId>.json` artifact, which is the only reason
 *      `RetainedCheckpoints::script()`'s `artifacts/$b.json` identity grep
 *      (RetainedCheckpoints.php) finds this deploy's artifact_hash at all.
 *   3. Whether the catalog can READ that name back and rebuild the same path.
 *      Against the build before this suite, `parse()` throws
 *      `checkpoint_listing_malformed` on a `deploy-` basename, so the round
 *      trip below fails through the product path rather than by inspection.
 *
 * Pure fakes: no target, no docker, no WordPress, no filesystem writes.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Recovery/RetainedCheckpointCipher.php';
require_once __DIR__ . '/../../../../cli/src/Command/DeployCommand.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RetainedCheckpoints.php';

use Duo\Orchestrator\DeployCommand;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\RetainedCheckpoints;
use Duo\RetainedCheckpointCipher;

const DEPLOY_CHECKPOINT_REPO = '/fixture/repo';
const DEPLOY_CHECKPOINT_RUN_ID = 'checkpoint-test-owner';
const DEPLOY_CHECKPOINT_HASH = '4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b';

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $saltName) {
    if (!defined($saltName)) {
        define($saltName, hash('sha256', 'deploy-checkpoint-fixture-' . $saltName));
    }
}

// A full authentication pass precedes the output pass, so corruption near
// EOF cannot feed an importer a valid SQL prefix before failing.
$cipherRoot = sys_get_temp_dir() . '/duo-retained-cipher-' . bin2hex(random_bytes(6));
mkdir($cipherRoot . '/.duo/checkpoints', 0700, true);
$cipherPath = $cipherRoot . '/.duo/checkpoints/deploy-cipher-test.sql.enc';
$plain = "CREATE TABLE prior_state (id bigint);\n" . str_repeat('checkpoint-payload-', 140000);
$plainInput = fopen('php://temp', 'w+b');
fwrite($plainInput, $plain);
rewind($plainInput);
RetainedCheckpointCipher::seal($cipherRoot, $cipherPath, $plainInput);
fclose($plainInput);
$cipherBytes = (string) file_get_contents($cipherPath);
duo_check(!str_contains($cipherBytes, 'CREATE TABLE prior_state'), 'the retained file contains no plaintext SQL');
duo_check_same(0600, fileperms($cipherPath) & 0777, 'retained ciphertext is owner-readable only');
$plainOutput = fopen('php://temp', 'w+b');
RetainedCheckpointCipher::open($cipherRoot, $cipherPath, $plainOutput);
rewind($plainOutput);
duo_check_same($plain, stream_get_contents($plainOutput), 'authenticated ciphertext streams back byte-identically');
fclose($plainOutput);

$tampered = $cipherBytes;
$tampered[strlen($tampered) - 8] = chr(ord($tampered[strlen($tampered) - 8]) ^ 1);
file_put_contents($cipherPath, $tampered);
$refusedOutput = fopen('php://temp', 'w+b');
duo_check_throws(
    static fn () => RetainedCheckpointCipher::open($cipherRoot, $cipherPath, $refusedOutput),
    \RuntimeException::class,
    'tampered retained ciphertext refuses before restore',
    'failed authentication'
);
rewind($refusedOutput);
duo_check_same('', stream_get_contents($refusedOutput), 'late ciphertext tampering emits no SQL prefix');
fclose($refusedOutput);
@unlink($cipherPath);
@rmdir($cipherRoot . '/.duo/checkpoints');
@rmdir($cipherRoot . '/.duo');
@rmdir($cipherRoot);

/**
 * The same fake-driver shape `offline/cli/regress_deploy_command.php` proves
 * the phase graph with, extended with the one primitive the checkpoint uses:
 * `captureWp(['db','export',...])`. No `$wpdb` fake is needed — the export is
 * a wp-cli call, not SQL.
 */
final class DeployCheckpointDriver implements EnvironmentDriver {
    /** @var list<string> every target interaction, in order */
    public array $events = [];
    /** @var list<array<int,string>> */
    public array $calls = [];
    /** @var list<string> every raw script this driver was handed */
    public array $rawScripts = [];
    public int $exportExit = 0;
    public int $stageExit = 0;
    public bool $codeEnabled = true;

    public function name(): string { return 'checkpoint-fixture'; }
    public function driverId(): string { return 'checkpoint-fixture'; }
    public function repoPath(): string { return DEPLOY_CHECKPOINT_REPO; }
    public function describe(): string { return 'deploy checkpoint fixture'; }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        $this->events[] = 'raw:mkdir';
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        if (($wpArgs[0] ?? null) === 'db' && ($wpArgs[1] ?? null) === 'export') {
            $this->events[] = 'capture:db-export';
            return ['exit' => $this->exportExit, 'stdout' => (string) ($wpArgs[2] ?? ''), 'stderr' => ''];
        }
        $command = $this->command($wpArgs);
        $this->events[] = 'capture:' . $command;
        $revision = str_repeat('b', 64);
        if ($command === 'compile') {
            $summary = ['artifact_hash' => DEPLOY_CHECKPOINT_HASH];
            if ($this->codeEnabled) {
                $summary['code'] = ['code_revision' => $revision, 'format' => 1];
            }
            return ['exit' => 0, 'stdout' => json_encode($summary, JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'code-preflight') {
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'duo-code-runtime/v1', 'enabled' => true, 'change_required' => true, 'compatible' => true,
                'code_revision' => $revision,
                'target' => ['php' => '8.3', 'wordpress' => '6.8', 'source' => 'target-control-plane'],
                'requirements' => [], 'diagnostics' => [],
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'promotion-begin') {
            return ['exit' => 0, 'stdout' => 'begun', 'stderr' => ''];
        }
        throw new \RuntimeException("unexpected capture command $command");
    }

    public function captureWpPipeline(array $producer, array $consumer): array {
        $this->calls[] = $producer;
        $this->calls[] = $consumer;
        $this->events[] = 'capture:db-export';

        return ['exit' => $this->exportExit, 'stdout' => '', 'stderr' => ''];
    }

    public function streamWp(array $wpArgs): int {
        $this->calls[] = $wpArgs;
        $command = $this->command($wpArgs);
        $phase = null;
        foreach ($wpArgs as $arg) {
            if (str_starts_with($arg, '--lifecycle-phase=')) {
                $phase = substr($arg, strlen('--lifecycle-phase='));
            }
        }
        $this->events[] = 'stream:' . $command . ($phase === null ? '' : ':' . $phase);
        return $command === 'code-stage' ? $this->stageExit : 0;
    }

    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }

    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('checkpoint-fixture', 'checkpoint-fixture', $operation, []);
    }

    /** @param array<int,string> $args */
    private function command(array $args): string {
        $index = array_search('duo', $args, true);
        if (!is_int($index) || !isset($args[$index + 1])) {
            throw new \RuntimeException('driver did not receive a duo command');
        }
        return $args[$index + 1];
    }
}

/**
 * Drive `DeployCommand::run()` with recording collaborators.
 *
 * Only STDOUT is captured. `DeployCommand` writes its refusals to the STDERR
 * constant, which an in-process suite cannot rebind without leaving a closed
 * stream behind — so the STDERR sentences are pinned end to end, through a real
 * `cli/duo` subprocess, by `offline/code-half/regress_code_deploy_unit.sh`.
 * What matters here is what the collaborators were HANDED, which is the part a
 * shell suite cannot see.
 *
 * @param list<string> $extra
 * @return array{exit:int,callbacks:list<string>,stdout:string}
 */
function run_deploy_checkpoint(DeployCheckpointDriver $driver, array $extra): array {
    $callbacks = [];
    ob_start();
    try {
        $exit = DeployCommand::run(
            $driver,
            $extra,
            static function (array $args) use (&$callbacks): ?int {
                $callbacks[] = 'scope';
                return null;
            },
            static function (EnvironmentDriver $transport) use (&$callbacks): bool {
                $callbacks[] = 'fence:' . $transport->name();
                return true;
            },
            static function () use (&$callbacks): string {
                $callbacks[] = 'run-id';
                return DEPLOY_CHECKPOINT_RUN_ID;
            },
            static function (EnvironmentDriver $t, array $begin, string $owner, string $hash) use (&$callbacks): void {
                $callbacks[] = "compensate:$owner:$hash";
            },
            static function (EnvironmentDriver $t, string $owner, string $hash) use (&$callbacks): bool {
                $callbacks[] = "abort:$owner:$hash";
                return true;
            },
            // DUO-3525 narrowed this callback to (driver, checkpoint,
            // codeMayHaveChanged). The recovery guidance names one verb —
            // `duo recover <env> --restore=<id> …`, whose `<id>` is the
            // checkpoint's own basename — so the lease identity is no longer
            // an input to what is PRINTED. `abort` above still receives it,
            // and this suite still asserts that pair on that call.
            static function (
                EnvironmentDriver $t,
                string $checkpoint,
                bool $codeMayHaveChanged
            ) use (&$callbacks): void {
                $callbacks[] = 'recovery:' . $checkpoint . ':' . ($codeMayHaveChanged ? 'code' : 'nocode');
            }
        );
    } finally {
        $stdout = (string) ob_get_clean();
    }

    return ['exit' => $exit, 'callbacks' => $callbacks, 'stdout' => $stdout];
}

$checkpointPath = DEPLOY_CHECKPOINT_REPO . '/.duo/checkpoints/deploy-' . DEPLOY_CHECKPOINT_RUN_ID . '.sql.enc';
$artifactPath = DEPLOY_CHECKPOINT_REPO . '/.duo/artifacts/deploy-' . DEPLOY_CHECKPOINT_RUN_ID . '.json';

// ------------------------------------------------------------------ (1) where
// The whole event list is asserted, not just the presence of an export: an
// export moved before promotion-begin or after code-stage fails here.
$happy = new DeployCheckpointDriver();
$happyResult = run_deploy_checkpoint($happy, ['--force-code-mismatch']);
duo_check_same(0, $happyResult['exit'], 'a default code-enabled deploy succeeds with its checkpoint');
duo_check_same(
    [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'capture:db-export',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate',
        'stream:lifecycle-settle', 'stream:code-finalize',
    ],
    $happy->events,
    'the checkpoint sits under the lease: after promotion-begin, before code-stage'
);
$export = null;
foreach ($happy->calls as $call) {
    if (($call[0] ?? null) === 'db') {
        $export = $call;
    }
}
duo_check_same(['db', 'export', '-'], $export, 'the database export has no durable plaintext output path');
$seal = null;
foreach ($happy->calls as $call) {
    if (in_array('checkpoint-seal', $call, true)) {
        $seal = $call;
    }
}
duo_check(
    is_array($seal) && in_array('--output=' . $checkpointPath, $seal, true),
    'the export stream terminates at the authenticated checkpoint sealer'
);

// ------------------------------------------------------------------- (2) what
duo_check_same(
    'deploy-' . DEPLOY_CHECKPOINT_RUN_ID,
    basename($checkpointPath, '.sql.enc'),
    'the checkpoint basename is deploy-<runId>'
);
duo_check_same(
    basename($artifactPath, '.json'),
    basename($checkpointPath, '.sql.enc'),
    'checkpoint and artifact share a stem, which is what the identity grep needs'
);
duo_check(
    str_contains($happy->rawScripts[0], escapeshellarg(dirname($checkpointPath)))
        && str_contains($happy->rawScripts[0], escapeshellarg(dirname($artifactPath))),
    'one mkdir creates both directories, so the export cannot fail for a reason that is not the database'
);
duo_check_same(1, count($happy->rawScripts), 'still exactly one raw mkdir event');
duo_check(
    str_contains($happyResult['stdout'], "deploy phase: checkpoint\n"),
    'the checkpoint phase announces itself in promote\'s words'
);
duo_check(
    str_contains($happyResult['stdout'], "database checkpoint: $checkpointPath\n"),
    'the checkpoint path is printed when it is taken'
);
duo_check(
    str_contains(
        $happyResult['stdout'],
        "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize\n"
            . "database checkpoint retained: $checkpointPath\n"
    ),
    'the completion line is unchanged and the retained line follows it, as promote does (cli/duo:2465)'
);

// A code-only deploy with no descriptor has nothing to mutate. In particular,
// it must not manufacture lifecycle side effects (agency audit #77), so it
// exits before a lease/checkpoint as a disclosed hook-free no-op.
$legacy = new DeployCheckpointDriver();
$legacy->codeEnabled = false;
$legacyResult = run_deploy_checkpoint($legacy, []);
duo_check_same(0, $legacyResult['exit'], 'a descriptor-free deploy succeeds as a no-op');
duo_check_same(
    ['raw:mkdir', 'capture:compile'],
    $legacy->events,
    'a deploy with no code descriptor takes no lease/checkpoint and invokes no lifecycle phase'
);
duo_check(
    str_contains(
        $legacyResult['stdout'],
        "deploy complete: no code descriptor; lifecycle hooks not run\n"
    ),
    'the no-op completion line explicitly discloses that lifecycle hooks did not run'
);

// ----------------------------------------------------- a failed export aborts
$failedExport = new DeployCheckpointDriver();
$failedExport->exportExit = 23;
$failedExportResult = run_deploy_checkpoint($failedExport, []);
duo_check_same(23, $failedExportResult['exit'], 'a non-zero export exit propagates unchanged');
duo_check_same(
    ['raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin', 'capture:db-export'],
    $failedExport->events,
    'a failed export starts no code or lifecycle phase'
);
duo_check_same(
    ['scope', 'fence:checkpoint-fixture', 'run-id',
        'abort:' . DEPLOY_CHECKPOINT_RUN_ID . ':' . DEPLOY_CHECKPOINT_HASH],
    $failedExportResult['callbacks'],
    'a failed export aborts the exact lease it took, and prints no recovery guidance for a dump that does not exist'
);

// ------------------------------------ a post-checkpoint failure guides recovery
$stageFail = new DeployCheckpointDriver();
$stageFail->stageExit = 8;
$stageFailResult = run_deploy_checkpoint($stageFail, []);
duo_check_same(8, $stageFailResult['exit'], 'a code-stage exit propagates unchanged');
duo_check_same(
    ['scope', 'fence:checkpoint-fixture', 'run-id',
        'abort:' . DEPLOY_CHECKPOINT_RUN_ID . ':' . DEPLOY_CHECKPOINT_HASH,
        'recovery:' . $checkpointPath . ':code'],
    $stageFailResult['callbacks'],
    'a post-checkpoint stage failure guides recovery of THIS checkpoint, after aborting THIS lease pair'
);

// ------------------------------------------------------------- --no-checkpoint
$optOut = new DeployCheckpointDriver();
$optOutResult = run_deploy_checkpoint($optOut, ['--no-checkpoint']);
duo_check_same(0, $optOutResult['exit'], '--no-checkpoint deploys successfully');
duo_check_same(
    [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate',
        'stream:lifecycle-settle', 'stream:code-finalize',
    ],
    $optOut->events,
    '--no-checkpoint reproduces the pre-change wp-call sequence exactly'
);
duo_check_same(
    "deploy phase: compile\ndeploy phase: code-preflight\ndeploy phase: promotion-begin\n"
        . "deploy phase: code-stage\ndeploy phase: lifecycle-retire\ndeploy phase: lifecycle-activate\n"
        . "deploy phase: lifecycle-settle\ndeploy phase: code-finalize\n"
        . "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize\n",
    $optOutResult['stdout'],
    '--no-checkpoint reproduces the pre-change stdout byte for byte'
);
duo_check(
    !str_contains($optOut->rawScripts[0], '/checkpoints'),
    '--no-checkpoint does not even create the checkpoint directory'
);
$optOutStage = new DeployCheckpointDriver();
$optOutStage->stageExit = 8;
$optOutStageResult = run_deploy_checkpoint($optOutStage, ['--no-checkpoint']);
duo_check_same(
    ['scope', 'fence:checkpoint-fixture', 'run-id',
        'abort:' . DEPLOY_CHECKPOINT_RUN_ID . ':' . DEPLOY_CHECKPOINT_HASH],
    $optOutStageResult['callbacks'],
    'under --no-checkpoint a phase failure prints no recovery guidance for a checkpoint that was never taken'
);
duo_check_same(
    "deploy phase: compile\ndeploy phase: code-preflight\ndeploy phase: promotion-begin\n"
        . "deploy phase: code-stage\n",
    $optOutStageResult['stdout'],
    '--no-checkpoint reproduces the pre-change failure stdout byte for byte'
);

// The flag gate itself. forceFlags() has one caller, so it is the single gate.
duo_check_same(true, DeployCommand::checkpointRequested([]), 'the checkpoint is the default');
duo_check_same(
    false,
    DeployCommand::checkpointRequested(['--force-code-drift', '--no-checkpoint']),
    'an exact --no-checkpoint opts out'
);
duo_check_same(
    [],
    DeployCommand::forceFlags(['--no-checkpoint'], 'deploy'),
    '--no-checkpoint is consumed by the host and never forwarded to a lifecycle phase'
);
duo_check_throws(
    static fn () => DeployCommand::forceFlags(['--no-checkpoint=false'], 'deploy'),
    \RuntimeException::class,
    'a --no-checkpoint=value spelling is refused rather than reinterpreted',
    'only --force-code-mismatch, --force-code-drift and --no-checkpoint are accepted'
);

// ---------------------------------------------------------- (3) the round trip
// The one assertion that fails against the prior defect through the product
// path: feed the basename DeployCommand just wrote back through the catalog and
// require the rebuilt path to be the written one. On the prior build parse()
// throws checkpoint_listing_malformed here.
$listing = basename($checkpointPath, '.sql.enc') . "\t" . DEPLOY_CHECKPOINT_HASH . "\t1786961410\n";
$rows = RetainedCheckpoints::parse($listing, '2026-08-17T10:20:10Z');
duo_check_same(1, count($rows), 'a deploy checkpoint line becomes one catalog row');
duo_check_same('deploy-' . DEPLOY_CHECKPOINT_RUN_ID, $rows[0]['id'], 'the row id is the deploy file name');
duo_check_same(DEPLOY_CHECKPOINT_RUN_ID, $rows[0]['owner'], 'the owner is the lease owner deploy used');
duo_check_same(
    DEPLOY_CHECKPOINT_HASH,
    $rows[0]['artifact_hash'],
    'the artifact hash comes from the sibling deploy-<runId>.json, so the restore can name the lease'
);
duo_check_same(
    RetainedCheckpoints::DEPLOY_ID_PREFIX,
    RetainedCheckpoints::prefixForRow($rows[0]),
    'prefixForRow reads the deploy prefix back off the row id'
);
duo_check_same(
    $checkpointPath,
    RetainedCheckpoints::checkpointPath(
        DEPLOY_CHECKPOINT_REPO,
        $rows[0],
        RetainedCheckpoints::prefixForRow($rows[0])
    ),
    'the catalog rebuilds exactly the path DeployCommand wrote'
);

// Both halves of prefixForRow()'s condition are load-bearing: a signed receipt
// row's id is a receipt id, not a file name, so only the retained kind may let
// a `deploy-` id choose deploy's prefix.
duo_check_same(
    RetainedCheckpoints::ID_PREFIX,
    RetainedCheckpoints::prefixForRow(['kind' => 'rollback-receipt', 'id' => 'deploy-looking-receipt']),
    'a signed receipt row keeps promote\'s prefix even when its id starts with deploy-'
);
duo_check_same(
    RetainedCheckpoints::ID_PREFIX,
    RetainedCheckpoints::prefixForRow(['kind' => RetainedCheckpoints::KIND, 'id' => 'promote-owner']),
    'a retained promote row keeps promote\'s prefix'
);

// ------------------------------------------------- the glob stays a closed set
$script = RetainedCheckpoints::script(DEPLOY_CHECKPOINT_REPO);
duo_check(
    str_contains($script, 'checkpoints/promote-*.sql.enc')
        && str_contains($script, 'checkpoints/deploy-*.sql.enc'),
    'the listing script asks the target for both prefixes'
);
duo_check(
    !preg_match('~checkpoints/\*\.sql\.enc~', $script),
    'no bare *.sql.enc glob: materialize-<operation_id>.sql.enc stays outside this catalog'
);
duo_check_refuses(
    static fn () => RetainedCheckpoints::parse("materialize-abc\t\t1\n", '2026-08-17T10:20:10Z'),
    'checkpoint_listing_malformed',
    'a name carrying neither prefix refuses loudly rather than being dropped from the inventory'
);

duo_check_summary('deploy checkpoint');
