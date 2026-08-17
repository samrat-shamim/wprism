<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';
require_once __DIR__ . '/../Recovery/CheckpointCatalog.php';
require_once __DIR__ . '/../Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../Recovery/RollbackAuthority.php';
require_once __DIR__ . '/../Recovery/ScopedRollbackProfile.php';
require_once __DIR__ . '/../Recovery/VerifiedRollbackProfile.php';
require_once __DIR__ . '/../Release/AuthorizationPlan.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';

use Duo\CommandRefusalException;

/**
 * `duo recover <env>` — the operator verb over the recovery runtime
 * (round-3 MUP §2.5).
 *
 * This replaces raw invocation of `recovery/rollback-control.php` (§5.3).
 * The runtime is unchanged; only who types it changes. It is deliberately a
 * THIN, LITERAL front end: `RollbackAuthority`, `VerifiedRollbackProfile` and
 * `ScopedRollbackProfile` own every transition, and the operator-directed
 * path drives exactly the four commands `cli/duo`'s
 * `print_promotion_recovery()` currently asks a human to type, in the same
 * order, built from the same `CodeDeploy` argument builders. Nothing here
 * invents a fifth step, and nothing here reorders the four.
 *
 * ## The three rules §2.5 makes non-negotiable
 *
 *  1. **External writer exclusion is asserted before anything starts.** The
 *     checkpoint contains the promotion lease row (`cli/duo`'s
 *     `print_promotion_recovery()` says so), so a lock inside the database
 *     being imported cannot protect the window. `--writers-excluded` is the
 *     operator's assertion that a real maintenance window exists; without it
 *     this command refuses before the first transition.
 *  2. **Code first.** If the failure happened after a code phase, a database
 *     import is refused until code is reconciled to the pre-release revision,
 *     and the refusal names that exact revision. Restoring a database that
 *     describes one code revision underneath a different one is the state
 *     that cannot be reasoned about afterwards.
 *  3. **The final abort is mandatory, including when the import fails.**
 *     Step 4 releases the temporary lease row the imported dump reinstated.
 *     Skipping it on a failed import leaves the target holding a lease no
 *     process owns, which is the one outcome worse than a failed recovery.
 *     It runs in a `finally`, so no branch of this code can skip it.
 *
 * ## The claim is printed twice
 *
 * `RecoveryClaim`'s `restores` / `does_not_restore` lists are printed
 * verbatim BEFORE acting and again in the outcome. When the checkpoint's own
 * artifact hash matches a frozen authorization plan in this site repository,
 * the claim printed is that plan's claim, byte for byte — which is what makes
 * "the claim the operator saw at authorization is the claim they see at
 * recovery" a checkable property rather than a coincidence.
 *
 * The checkpoint instant is printed on the line after the claim, at both
 * printings, and never inside it: the claim is embedded in the DIGESTED part
 * of the frozen plan, so a clock value in it would give one unchanged
 * authorization a new `plan_digest` every second (`RecoveryClaim`'s docblock,
 * "Why no field here holds a clock value"). `checkpointLine()` names where
 * the instant came from, because the receipt's own creation time and the
 * plan's release-start instant answer slightly different questions.
 *
 * Exit status: `0` a completed listing or recovery, `1` any refusal or a
 * recovery that did not reach its terminal state.
 */
final class RecoverCommand {
    /** The four ordered steps of the operator-directed path (§2.5). */
    public const ORDERED_STEPS = ['abort', 'begin', 'import', 'final-abort'];

    /** The flag that asserts a real external maintenance window. */
    public const WRITERS_EXCLUDED_FLAG = '--writers-excluded';

    /** Where the checkpoint instant printed beside the claim came from. */
    public const SOURCE_RECEIPT = 'receipt';
    public const SOURCE_PLAN = 'authorization-plan';
    public const SOURCE_NONE = 'unpublished';

    /**
     * Checkpoint coverage that means code moved during the failed release.
     *
     * A receipt covering a code release is a receipt taken around a code
     * phase, so a database import under it is a code-first decision.
     */
    public const CODE_COVERAGE = RecoveryClaim::RESOURCE_CODE_RELEASE;

    /**
     * @param list<string> $extra everything after `<env>`
     * @param ?callable():string $clock null reads the wall clock
     */
    public static function run(EnvironmentDriver $driver, array $extra, ?callable $clock = null): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $flags = self::flags($extra);
            $transport = self::authorityTransport($driver);
            $catalog = CheckpointCatalog::list(
                $transport,
                ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))()
            );
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'recover');
        }

        if ($flags['restore'] === null) {
            if ($json) {
                echo self::encode($catalog);

                return 0;
            }
            foreach (CheckpointCatalog::humanLines($catalog, $flags['limit']) as $line) {
                echo $line . "\n";
            }

            return 0;
        }

        try {
            $outcome = self::restore($transport, $catalog, $flags, $json);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'recover');
        }

        if ($json) {
            echo self::encode($outcome);

            return $outcome['recovered'] === true ? 0 : 1;
        }
        foreach (self::outcomeLines($outcome, $flags['limit']) as $line) {
            echo $line . "\n";
        }

        return $outcome['recovered'] === true ? 0 : 1;
    }

    /**
     * Drive one restore.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $flags
     * @return array<string,mixed>
     */
    private static function restore(
        SshTransport $transport,
        array $catalog,
        array $flags,
        bool $json
    ): array {
        $row = CheckpointCatalog::find($catalog, (string) $flags['restore']);
        if ($row === null) {
            throw new CommandRefusalException(
                'checkpoint_unknown',
                'no checkpoint with that receipt id is active on this target',
                'run duo recover <env> --list and restore one of the receipt ids it prints'
            );
        }
        if ($flags['writers_excluded'] !== true) {
            // §2.5, and `cli/duo`'s own recovery guidance: the checkpoint
            // contains its temporary promotion lease row, so the exclusion
            // has to be external to the database being imported.
            throw new CommandRefusalException(
                'writer_exclusion_required',
                'recovery imports a database that contains its own promotion lease row, so it cannot start '
                    . 'until every external writer is excluded for the whole recovery window',
                'establish external maintenance/exclusion that prevents every Duo writer for the full recovery '
                    . 'window, then re-run duo recover <env> --restore=<id> ' . self::WRITERS_EXCLUDED_FLAG
            );
        }

        $resolved = self::claim($row);
        $claim = $resolved['claim'];
        // Printed BEFORE acting, always, in both channels: the operator has
        // to read what recovery does not restore while they can still stop.
        // The checkpoint instant follows the claim rather than sitting inside
        // it — the claim is the byte-identical document §2.5 requires, and a
        // clock value in it would change the frozen plan's identity every
        // second (RecoveryClaim's docblock).
        if (!$json) {
            foreach (RecoveryClaim::humanLines($claim, $flags['limit']) as $line) {
                echo $line . "\n";
            }
            echo '  ' . self::checkpointLine($resolved) . "\n";
        }

        // Which path is available is a fact about this controller, not a
        // preference. The signed profile needs the Ed25519 secret that stays
        // on the controller (`RollbackAuthority::__construct()` refuses
        // without it), so a receipt this machine cannot sign against is
        // recovered the operator-directed way — the same conclusion
        // `cli/duo`'s promotion path reaches when `rollbackConfigured()` is
        // false. `--operator-directed` forces that path explicitly.
        $signed = $row['kind'] === CheckpointCatalog::KIND_VERIFIED
            && $flags['operator_directed'] !== true
            && $transport->rollbackConfigured();

        self::assertCodeFirst($transport, $row, $signed);

        $steps = $signed
            ? self::signedRollback($transport)
            : self::operatorDirected($transport, $row);

        return [
            'checkpoint' => $row,
            'checkpoint_at' => $resolved['checkpoint_at'],
            'checkpoint_source' => $resolved['checkpoint_source'],
            'claim' => $claim,
            'environment' => $transport->name(),
            'format' => 'duo-recovery-outcome/v1',
            'recovered' => $steps['recovered'],
            'steps' => $steps['steps'],
        ];
    }

    /**
     * The signed, provider-backed path: the profile's own rollback.
     *
     * Every ordering decision — effects, uploads, code, database, prior
     * verification, exclusion release — belongs to
     * `VerifiedRollbackProfile::rollback()`, which is the same call
     * `cli/duo`'s `promote_verified_failed()` makes. Recomputing which
     * boundaries were crossed here would be a second, divergent answer to a
     * question the signed receipt already answers.
     *
     * @return array{recovered:bool,steps:list<array<string,mixed>>}
     */
    private static function signedRollback(SshTransport $transport): array {
        $profile = new VerifiedRollbackProfile($transport);
        try {
            $status = $profile->rollback(true, true, true);
        } catch (\Throwable $failure) {
            unset($failure);

            return ['recovered' => false, 'steps' => [[
                'detail' => 'the signed generation remains nonterminal with traffic excluded; resume it, '
                    . 'do not start operator-directed recovery',
                'ok' => false,
                'step' => 'signed-rollback',
            ]]];
        }

        return ['recovered' => ($status['state'] ?? '') === 'rolled_back', 'steps' => [[
            'detail' => 'prior world verified; exclusion released',
            'ok' => true,
            'step' => 'signed-rollback',
        ]]];
    }

    /**
     * The operator-directed path: abort → begin → isolated import → final
     * abort, driven rather than printed.
     *
     * @param array<string,mixed> $row a catalog row
     * @return array{recovered:bool,steps:list<array<string,mixed>>}
     */
    private static function operatorDirected(SshTransport $transport, array $row): array {
        $owner = (string) $row['owner'];
        $artifactHash = (string) $row['artifact_hash'];
        // Proved BEFORE step 1. An absent or truncated checkpoint means there
        // is nothing to import, and finding that out after the lease has been
        // aborted and re-begun would leave the target opened up for a
        // recovery that was never possible.
        $checkpoint = self::checkpointPath($transport, $owner);
        $steps = [];
        $recovered = false;

        // Step 1 stands outside the mandatory-final-abort guarantee on
        // purpose. `cli/duo`: "do not begin checkpoint recovery until the
        // exact lease cleanup command above succeeds" — expiry lets a
        // DIFFERENT promotion owner recover the target, it does not authorize
        // this restore. Nothing has been reinstated yet either, so there is no
        // lease row for a fourth step to release.
        $steps[] = self::step($transport, 'abort', CodeDeploy::abortArgs($owner, $artifactHash));
        if (!$steps[0]['ok']) {
            return ['recovered' => false, 'steps' => $steps];
        }

        try {
            $begin = self::step($transport, 'begin', CodeDeploy::beginArgs($owner, $artifactHash));
            $steps[] = $begin;
            if (!$begin['ok']) {
                return ['recovered' => false, 'steps' => $steps];
            }
            $import = self::step($transport, 'import', CodeDeploy::recoveryDbImportArgs($checkpoint));
            $steps[] = $import;
            $recovered = $import['ok'];
        } finally {
            // Step 4 runs even when the import failed, and even when begin
            // failed after opening the window. That is the whole point of the
            // rule, so it lives in a `finally` where no future early return
            // can route around it.
            $steps[] = self::step($transport, 'final-abort', CodeDeploy::abortArgs($owner, $artifactHash));
        }
        $final = $steps[count($steps) - 1];

        return ['recovered' => $recovered && $final['ok'], 'steps' => $steps];
    }

    /**
     * Code-first ordering, enforced rather than advised (§2.5).
     *
     * @param array<string,mixed> $row
     */
    private static function assertCodeFirst(SshTransport $transport, array $row, bool $signed): void {
        $covers = array_values(array_map('strval', (array) ($row['covers'] ?? [])));
        if (!in_array(self::CODE_COVERAGE, $covers, true)) {
            return;
        }
        if ($signed) {
            // The signed profile restores code itself, in its own order,
            // before the database restore (`VerifiedRollbackProfile::rollback()`).
            return;
        }
        $revision = self::priorCodeRevision($transport, $row);
        if ($revision !== null && self::targetHead($transport) === $revision) {
            return;
        }
        throw new CommandRefusalException(
            'recover_code_not_reconciled',
            'this checkpoint was taken around a code phase, so importing its database before code is reconciled '
                . 'would leave a database describing one code revision underneath another',
            'reconcile or restore the target code to '
                . ($revision === null
                    ? 'the pre-release revision recorded in the frozen authorization plan for this release'
                    : substr($revision, 0, 12))
                . ' first, confirm with duo status, then re-run duo recover with the same --restore id',
            [['code_revision_expected' => $revision === null ? 'unknown' : substr($revision, 0, 12)]]
        );
    }

    /**
     * The pre-release code revision this checkpoint belongs to.
     *
     * Read out of the frozen authorization plan whose `artifact_hash` matches
     * the receipt's — the plan is the durable record of what the release was
     * authorized to move FROM, and `.duo/releases/` is committed for exactly
     * this reason (MUP §3.1).
     *
     * @param array<string,mixed> $row
     */
    private static function priorCodeRevision(SshTransport $transport, array $row): ?string {
        unset($transport);
        foreach (self::frozenPlans() as $plan) {
            if ((string) ($plan['artifact_hash'] ?? '') !== (string) ($row['artifact_hash'] ?? '')) {
                continue;
            }
            $revision = (string) ($plan['code_revision_from'] ?? '');
            if (preg_match('/^[a-f0-9]{40,64}$/D', $revision) === 1) {
                return $revision;
            }
        }

        return null;
    }

    /**
     * The claim to print and the instant that goes beside it.
     *
     * The claim is the frozen plan's own when one matches this checkpoint, so
     * the two printings are byte-identical (§2.5), and a freshly built one
     * otherwise. It carries no clock value at all — the boundary sentence
     * names the checkpoint, `RecoveryClaim`'s docblock says why the instant
     * cannot live inside a document the plan digests — so the instant is
     * resolved separately here.
     *
     * Both sources are real and they are ordered by which one is evidence
     * about THIS checkpoint: the receipt's own `created_at` first, because it
     * is when the checkpoint was actually pinned; the frozen plan's
     * `recovery_profile.checkpoint_at` second, because it is the release-start
     * instant the operator was shown at authorization. A full promotion
     * receipt publishes no creation time at all (`CheckpointCatalog`'s
     * `DISCLOSURE_NO_CREATION_TIME`), which is exactly the case the plan
     * covers; when neither exists the caller prints the absence rather than a
     * derived time.
     *
     * Split into an impure reader and a pure resolver for the same reason
     * `RecoveryProfileSelection` splits prove/decide: the fallback ORDER is
     * the part that has to be provable offline, and the case that exercises
     * it — a full promotion receipt, which publishes no creation time at all
     * — cannot be reached through a fixture that has one.
     *
     * @param array<string,mixed> $row
     * @return array{claim:array<string,mixed>,checkpoint_at:?string,checkpoint_source:string}
     */
    private static function claim(array $row): array {
        return self::resolveClaim($row, self::frozenPlans());
    }

    /**
     * The pure half: a catalog row and the frozen plans this site repository
     * holds go in; one claim and one dated instant come out.
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $frozenPlans
     * @return array{claim:array<string,mixed>,checkpoint_at:?string,checkpoint_source:string}
     */
    public static function resolveClaim(array $row, array $frozenPlans): array {
        $receiptAt = is_string($row['created_at'] ?? null) && $row['created_at'] !== ''
            ? (string) $row['created_at']
            : null;
        foreach ($frozenPlans as $plan) {
            if ((string) ($plan['artifact_hash'] ?? '') !== (string) ($row['artifact_hash'] ?? '')) {
                continue;
            }
            $claim = $plan['recovery_profile']['claim'] ?? null;
            if (is_array($claim)) {
                RecoveryClaim::validate($claim);
                $planAt = $plan['recovery_profile']['checkpoint_at'] ?? null;

                return self::withCheckpoint(
                    $claim,
                    $receiptAt,
                    is_string($planAt) && $planAt !== '' ? $planAt : null
                );
            }
        }

        return self::withCheckpoint(RecoveryClaim::build([
            'covered_resources' => array_values(array_map('strval', (array) ($row['covers'] ?? []))),
            'profile' => $row['kind'] === CheckpointCatalog::KIND_VERIFIED
                ? RecoveryClaim::VERIFIED_AUTOMATIC
                : RecoveryClaim::OPERATOR_DIRECTED,
        ]), $receiptAt, null);
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{claim:array<string,mixed>,checkpoint_at:?string,checkpoint_source:string}
     */
    private static function withCheckpoint(array $claim, ?string $receiptAt, ?string $planAt): array {
        if ($receiptAt !== null) {
            return ['checkpoint_at' => $receiptAt, 'checkpoint_source' => self::SOURCE_RECEIPT, 'claim' => $claim];
        }
        if ($planAt !== null) {
            return ['checkpoint_at' => $planAt, 'checkpoint_source' => self::SOURCE_PLAN, 'claim' => $claim];
        }

        return ['checkpoint_at' => null, 'checkpoint_source' => self::SOURCE_NONE, 'claim' => $claim];
    }

    /**
     * The one line printed beside the claim, at both printings.
     *
     * It names its source as well as its value. The two sources answer
     * slightly different questions — when the checkpoint was pinned, versus
     * when the release that took it started — and an operator deciding what
     * they are about to lose is owed the difference rather than a bare
     * timestamp that looks equally authoritative either way.
     *
     * @param array<string,mixed> $outcome a restore() result
     */
    public static function checkpointLine(array $outcome): string {
        $at = $outcome['checkpoint_at'] ?? null;
        if (!is_string($at) || $at === '') {
            return 'checkpoint at: not published by this receipt, and no frozen authorization plan in this '
                . 'site repository records it';
        }

        return 'checkpoint at: ' . $at . (($outcome['checkpoint_source'] ?? '') === self::SOURCE_PLAN
            ? ' (release start, from the frozen authorization plan)'
            : ' (from the checkpoint receipt)');
    }

    /**
     * Every frozen authorization plan in this site repository.
     *
     * @return list<array<string,mixed>>
     */
    private static function frozenPlans(): array {
        try {
            $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
        } catch (CommandRefusalException) {
            // Recovery must work from anywhere: an operator recovering a
            // production target may not be standing in the site repository.
            // Without it the claim is rebuilt from the receipt instead.
            return [];
        }
        $paths = glob(rtrim($siteRepo, '/') . '/' . AuthorizationPlan::DIRECTORY . '/*.json');
        $out = [];
        foreach ($paths === false ? [] : $paths as $path) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                continue;
            }
            try {
                AuthorizationPlan::validate($decoded);
            } catch (\Throwable) {
                continue;
            }
            $out[] = $decoded;
        }

        return $out;
    }

    /**
     * Where the operator-directed checkpoint lives on the target.
     *
     * `cli/duo`'s `cmd_promote_internal()` writes it at
     * `<repo>/.duo/checkpoints/promote-<owner>.sql`, and the receipt names the
     * owner, so the path is derived rather than guessed. Its presence and
     * non-emptiness are proved before the import: an empty checkpoint is
     * evidence of an interrupted export, not a checkpoint.
     */
    private static function checkpointPath(SshTransport $transport, string $owner): string {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $owner) !== 1) {
            throw new CommandRefusalException(
                'checkpoint_owner_invalid',
                'the active receipt names an owner this build will not turn into a filesystem path',
                'inspect private operator evidence for this target before recovering'
            );
        }
        $path = rtrim($transport->repoPath(), '/') . '/.duo/checkpoints/promote-' . $owner . '.sql';
        $probe = $transport->captureRaw('test -s ' . escapeshellarg($path));
        if (($probe['exit'] ?? 1) !== 0) {
            throw new CommandRefusalException(
                'checkpoint_unavailable',
                'the database checkpoint this receipt refers to is absent or empty on the target, so there is '
                    . 'nothing to import',
                'do not retry: escalate. An absent or truncated checkpoint means the export was interrupted, '
                    . 'and the prior database has to be recovered from provider backups instead'
            );
        }

        return $path;
    }

    /** The target repository's current revision, for the code-first compare. */
    private static function targetHead(SshTransport $transport): ?string {
        $result = $transport->captureRaw(
            'git -C ' . escapeshellarg($transport->repoPath()) . ' rev-parse HEAD'
        );
        $revision = trim((string) ($result['stdout'] ?? ''));

        return ($result['exit'] ?? 1) === 0 && preg_match('/^[a-f0-9]{40,64}$/D', $revision) === 1
            ? $revision
            : null;
    }

    /**
     * One ordered step, run through the agent's own argument builders.
     *
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private static function step(SshTransport $transport, string $name, array $args): array {
        $result = $transport->captureWp($args);

        return [
            'detail' => ($result['exit'] ?? 1) === 0
                ? 'completed'
                : 'the target refused or failed this step; inspect private operator evidence',
            'ok' => ($result['exit'] ?? 1) === 0,
            'step' => $name,
        ];
    }

    /**
     * Only an SSH target carries the rollback authority runtime, which is
     * what every action in `recovery/rollback-control.php` is reached
     * through. Saying so is more useful than a missing catalog.
     */
    private static function authorityTransport(EnvironmentDriver $driver): SshTransport {
        if ($driver instanceof SshTransport) {
            return $driver;
        }
        throw new CommandRefusalException(
            'recovery_authority_unavailable',
            'this transport carries no rollback authority runtime, so there is no signed checkpoint catalog to '
                . 'list or restore',
            'recover this environment through the provider that owns its backups; duo recover drives the '
                . 'adopted rollback authority, which only an SSH-adopted target has'
        );
    }

    /**
     * @param array<string,mixed> $outcome
     * @return list<string>
     */
    private static function outcomeLines(array $outcome, int $limit): array {
        $lines = ['recover ' . (string) $outcome['environment'] . ': '
            . ($outcome['recovered'] === true ? 'recovered' : 'not recovered')];
        foreach (array_slice((array) $outcome['steps'], 0, $limit) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $lines[] = '  ' . (string) $step['step'] . ': '
                . ($step['ok'] === true ? 'ok' : 'FAILED') . ' — ' . (string) $step['detail'];
        }
        $lines[] = 'the claim this recovery was performed under, unchanged:';
        foreach (RecoveryClaim::humanLines($outcome['claim'], $limit) as $line) {
            $lines[] = '  ' . $line;
        }
        $lines[] = '  ' . self::checkpointLine($outcome);

        return $lines;
    }

    /** @param array<string,mixed> $document */
    private static function encode(array $document): string {
        return \Duo\Canon::encode($document);
    }

    /**
     * The closed flag grammar.
     *
     * @param list<string> $extra
     * @return array<string,mixed>
     */
    private static function flags(array $extra): array {
        $out = [
            'limit' => 50,
            'list' => false,
            'operator_directed' => false,
            'restore' => null,
            'writers_excluded' => false,
        ];
        $limitSeen = false;
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidArguments('recover received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            switch ($name) {
                case '--list':
                    $out['list'] = true;
                    break;
                case '--restore':
                    if ($out['restore'] !== null || $value === null || $value === '') {
                        throw self::invalidArguments('--restore takes exactly one --restore=<checkpoint> value');
                    }
                    $out['restore'] = $value;
                    break;
                case self::WRITERS_EXCLUDED_FLAG:
                    $out['writers_excluded'] = true;
                    break;
                case '--operator-directed':
                    $out['operator_directed'] = true;
                    break;
                case '--limit':
                    if ($limitSeen
                        || $value === null
                        || preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', $value) !== 1) {
                        throw self::invalidArguments('--limit must be a single value between 1 and 200');
                    }
                    $limitSeen = true;
                    $out['limit'] = (int) $value;
                    break;
                case '--format':
                case '--json':
                    break;
                default:
                    throw self::invalidArguments("recover received an option it does not define: '$name'");
            }
        }
        if ($out['list'] && $out['restore'] !== null) {
            throw self::invalidArguments('--list and --restore are separate requests');
        }

        return $out;
    }

    private static function invalidArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'duo recover <env> accepts --list, --restore=<checkpoint>, ' . self::WRITERS_EXCLUDED_FLAG
                . ', --operator-directed, --limit=<1..200> and --format=json'
        );
    }
}
