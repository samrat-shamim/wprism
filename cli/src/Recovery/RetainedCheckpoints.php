<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/RecoveryClaim.php';

use Duo\CommandRefusalException;

/**
 * The database checkpoints a target's releases RETAINED — the second source
 * of `duo recover <env> --list` rows (round-3 MUP §2.5), and the only one a
 * target without a rollback authority runtime has.
 *
 * ## Why this exists
 *
 * Every operator-directed promotion (`cli/duo`'s `cmd_promote_internal()`,
 * which is what `duo release` composes on a local, docker or plain SSH
 * target) exports the pre-release database to
 * `<repo>/.duo/checkpoints/promote-<owner>.sql` immediately after taking the
 * promotion lease and prints `database checkpoint retained: <path>` on
 * success. The frozen authorization plan for that release claims exactly one
 * restorable resource — `RecoveryClaim::RESTORES[operator-directed]` is the
 * database checkpoint — so a `duo recover` that could not list or restore
 * that checkpoint on that transport would print a claim at authorization
 * that no verb honours afterwards. `grind_mup.sh` step 11 is that gap made
 * executable; this class closes it without a second recovery mechanism: a
 * retained checkpoint is restored through the SAME four ordered steps the
 * operator-directed path already drives (abort → begin → isolated import →
 * mandatory final abort), with the same lease identity the release used.
 *
 * `duo deploy <env>` is the second writer. It takes its checkpoint at the
 * position promote takes its own — under the `promotion-begin` lease and
 * before code-stage (`DeployCommand::run()`) — and retains it as
 * `deploy-<owner>.sql` beside the `deploy-<owner>.json` artifact the same
 * deploy compiled. The identity derivation below is unchanged precisely
 * because those two file names share a stem: nothing here had to learn a
 * second way to find an artifact, only a second prefix to glob.
 *
 * That prefix set is CLOSED on purpose. `materialize-<operation_id>.sql`
 * (cli/duo:2126-2127, :2576-2577) is the environment materializer's working
 * dump, not a release's before-image, and a bare `*.sql` glob would list it
 * here as a restorable release checkpoint. Two named prefixes; never a
 * wildcard.
 *
 * ## Where the identity comes from
 *
 * The lease `promotion-begin` binds is `(owner, artifact_hash)`. The owner
 * is the checkpoint's own file name (`promote-<owner>.sql`, or deploy's
 * `deploy-<owner>.sql`); the artifact hash is read from the sibling
 * `<repo>/.duo/artifacts/<same-stem>.json`
 * that the same release compiled — a content-addressed artifact whose
 * top-level `artifact_hash` is the identity the lease row in the checkpoint
 * itself carries. Nothing here invents an identity: a checkpoint whose
 * artifact is gone is listed with an empty `artifact_hash` and refuses to
 * restore, because a lease it cannot name is a lease it must not take.
 *
 * ## Read-only, and one script
 *
 * `list()` runs one POSIX-sh script on the target through
 * `EnvironmentDriver::captureRaw()` — the same primitive every transport
 * (local, docker, ssh) already implements — and parses tab-separated lines.
 * It writes nothing. `script()` and `parse()` are pure so an offline suite
 * can pin both the bytes sent and the rows built without a target.
 */
final class RetainedCheckpoints {
    /** The catalog `kind` for a retained release checkpoint. */
    public const KIND = 'retained-release-checkpoint';

    /**
     * The one `state` a retained checkpoint can be in. It is outside
     * `CheckpointCatalog::STATES` on purpose: those are the rollback
     * authority's generation states, and a plain checkpoint file has no
     * generation to be in.
     */
    public const STATE = 'retained';

    /**
     * `--restore` ids and file names share this prefix, as promote wrote them.
     *
     * It stays the DEFAULT rather than becoming one member of a set, because
     * `RecoverCommand::checkpointPath()` calls `checkpointPath()` for the
     * signed-receipt source too, and a receipt row's `id` is a receipt id, not
     * a file name — only promote's prefix can be derived from it.
     */
    public const ID_PREFIX = 'promote-';

    /** The same, for the checkpoint `duo deploy` retains under its own lease. */
    public const DEPLOY_ID_PREFIX = 'deploy-';

    /**
     * Every prefix this catalog claims, and nothing else. `script()` derives
     * its globs from this constant and `parse()` accepts exactly these, so the
     * bytes sent to the target and the names read back can never disagree.
     *
     * @var list<string>
     */
    public const ID_PREFIXES = [self::ID_PREFIX, self::DEPLOY_ID_PREFIX];

    /** The owner grammar `RecoverCommand` and `PromotionLease` both accept. */
    public const OWNER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    public const DISCLOSURE_RETAINED =
        'retained release checkpoints are the plain database checkpoints promote and deploy kept under '
        . '.duo/checkpoints; restoring one drives the operator-directed path (abort, begin, isolated import, '
        . 'final abort)';

    /**
     * Supersession is a fact only the TARGET holds. The durable
     * `promotion_session` row lives in the target's `duo_kv`
     * (`agent/src/Promotion/PromotionLease.php:1005`) and no host verb reads
     * it, so this listing cannot mark a row "not restorable" without
     * inventing an answer — and an older checkpoint IS still restorable when
     * no later session was begun. Step 1 stays the authority (DUO-3506).
     * What the listing can honestly do is name the refusal in advance, with
     * the reason code the failed step now carries and the same remedy, so an
     * operator choosing between two retained checkpoints knows the older one
     * can be refused and why.
     */
    public const DISCLOSURE_SUPERSEDED =
        'a retained checkpoint older than the target\'s latest begun promotion session is refused at step 1 '
        . 'with promotion_abort_session_superseded; an obsolete checkpoint is not a safe recovery source, so '
        . 'recover that release through the provider that owns the target\'s backups instead';

    public const DISCLOSURE_NO_IDENTITY =
        'a retained checkpoint whose compiled artifact is gone has no lease identity and cannot be restored by '
        . 'this command; recover it through the provider that owns the target\'s backups';

    /**
     * Read the retained checkpoints from a target.
     *
     * @return list<array<string,mixed>> catalog rows, newest first
     */
    public static function list(EnvironmentDriver $driver, string $now): array {
        $result = $driver->captureRaw(self::script($driver->repoPath()));
        if (($result['exit'] ?? 1) !== 0) {
            throw new CommandRefusalException(
                'checkpoint_listing_unavailable',
                'the target could not enumerate its retained release checkpoints',
                'run duo doctor ' . $driver->name() . ' and repair the transport it reports, then re-run duo recover',
                [['detail' => trim((string) (($result['stderr'] ?? '') !== '' ? $result['stderr'] : ($result['stdout'] ?? '')))]]
            );
        }

        return self::parse((string) ($result['stdout'] ?? ''), $now);
    }

    /**
     * The one script `list()` sends. Pure.
     *
     * POSIX sh only (docker runs it under `bash -c`, ssh under the remote
     * login shell, local under the system shell): for every non-empty
     * `promote-*.sql` and `deploy-*.sql`, one line of
     * `<basename>\t<artifact_hash>\t<mtime>`. The glob list is built from
     * `ID_PREFIXES` rather than written out, so a prefix this class accepts in
     * `parse()` but never asked the target for is not expressible.
     * The artifact hash is `grep`ped out of the sibling artifact rather than
     * parsed by a language runtime, because `grep`, `stat`, `sed` and
     * `basename` are the only tools every target already proved it has by
     * running `wp` at all. The artifact is `Canon::encode()`d — pretty-printed,
     * `"artifact_hash": "<hex>"` with one space — so the pattern admits any
     * run of spaces after the colon. Both `stat` dialects are tried (GNU/busybox `-c`,
     * BSD `-f`). A missing artifact yields an empty hash field, never an
     * absent line — the absence is printed, not implied.
     */
    public static function script(string $repoPath): string {
        $duo = rtrim($repoPath, '/') . '/.duo';
        $q = escapeshellarg($duo);
        $globs = [];
        foreach (self::ID_PREFIXES as $prefix) {
            $globs[] = '"$d"/checkpoints/' . $prefix . '*.sql';
        }

        return 'd=' . $q . '; '
            . 'for f in ' . implode(' ', $globs) . '; do '
            . '[ -s "$f" ] || continue; '
            . 'b=$(basename "$f" .sql); '
            . 'h=$(grep -o \'"artifact_hash": *"[a-f0-9]\{64\}"\' "$d/artifacts/$b.json" 2>/dev/null | head -n 1 | sed \'s/.*"\([a-f0-9]\{64\}\)"$/\1/\'); '
            . 'm=$(stat -c %Y "$f" 2>/dev/null || stat -f %m "$f" 2>/dev/null || echo 0); '
            . 'printf \'%s\t%s\t%s\n\' "$b" "$h" "$m"; '
            . 'done; exit 0';
    }

    /**
     * Turn the script's stdout into catalog rows. Pure.
     *
     * Malformed lines are refused rather than skipped: a target that prints
     * something this build cannot read is a target this build must not
     * pretend to have inventoried.
     *
     * @return list<array<string,mixed>> newest first, then by id
     */
    public static function parse(string $stdout, string $now): array {
        $rows = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) !== 3) {
                throw self::malformed('a retained checkpoint line did not carry exactly three fields');
            }
            [$basename, $hash, $mtime] = $parts;
            $prefix = null;
            foreach (self::ID_PREFIXES as $candidate) {
                if (str_starts_with($basename, $candidate)) {
                    $prefix = $candidate;
                    break;
                }
            }
            if ($prefix === null) {
                throw self::malformed('a retained checkpoint name carries neither the promote- nor the deploy- prefix');
            }
            $owner = substr($basename, strlen($prefix));
            if (preg_match(self::OWNER_PATTERN, $owner) !== 1) {
                throw self::malformed('a retained checkpoint names an owner this build will not turn into a lease');
            }
            if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw self::malformed('a retained checkpoint artifact hash is not 64 hex characters');
            }
            if (preg_match('/^[0-9]{1,12}$/D', $mtime) !== 1) {
                throw self::malformed('a retained checkpoint modification time is not a positive integer');
            }
            $rows[] = self::row($owner, $hash, (int) $mtime, $now, $prefix);
        }
        usort($rows, static function (array $a, array $b): int {
            $byTime = strcmp((string) $b['created_at'], (string) $a['created_at']);

            return $byTime !== 0 ? $byTime : strcmp((string) $a['id'], (string) $b['id']);
        });

        return $rows;
    }

    /**
     * One catalog row, in `CheckpointCatalog::row()`'s exact key set. Pure.
     *
     * `generation` is 0 and `event_chain_sha256`, `claim_expires_at` and
     * `retention_until` are null: a plain checkpoint has no signed
     * generation, no event chain, no claim and no retention policy, and
     * printing a fabricated one would teach the operator a guarantee the
     * file does not carry. `terminal` is true because nothing about a
     * retained file is in progress. `covers` is the one resource the
     * operator-directed claim names.
     *
     * `$prefix` selects which verb's file name the `id` reproduces and is the
     * ONLY place the two verbs differ. It is deliberately not a row key:
     * `regress_recover_claim.php` pins that a retained row carries exactly
     * the key set `CheckpointCatalog::row()` produces, and an extra `verb` or
     * `prefix` key would break that honest-inventory contract. `id` already
     * carries the fact, and `prefixForRow()` reads it back.
     *
     * @return array<string,mixed>
     */
    public static function row(
        string $owner,
        string $artifactHash,
        int $mtime,
        string $now,
        string $prefix = self::ID_PREFIX
    ): array {
        $createdAt = $mtime > 0 ? gmdate('Y-m-d\TH:i:s\Z', $mtime) : null;
        $age = null;
        if ($createdAt !== null) {
            $nowTime = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $now, new \DateTimeZone('UTC'));
            if (!$nowTime || $nowTime->format('Y-m-d\TH:i:s\Z') !== $now) {
                throw new CommandRefusalException(
                    'checkpoint_timestamp_invalid',
                    'the checkpoint catalog now is not canonical UTC seconds',
                    'inspect private operator evidence on the target before recovering'
                );
            }
            $age = max(0, $nowTime->getTimestamp() - $mtime);
        }

        return [
            'age_seconds' => $age,
            'artifact_hash' => $artifactHash,
            'claim_expires_at' => null,
            'covers' => [RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT],
            'created_at' => $createdAt,
            'event_chain_sha256' => null,
            'generation' => 0,
            'id' => $prefix . $owner,
            'kind' => self::KIND,
            'owner' => $owner,
            'retention_until' => null,
            'state' => self::STATE,
            'terminal' => true,
        ];
    }

    /** Whether a catalog row is one of ours. @param array<string,mixed> $row */
    public static function isRetained(array $row): bool {
        return (string) ($row['kind'] ?? '') === self::KIND;
    }

    /**
     * Which file-name prefix a catalog row's checkpoint was written under.
     * Pure.
     *
     * BOTH halves of the condition are load-bearing. `isRetained()` is what
     * keeps a signed receipt out: a receipt row's `id` is a receipt id chosen
     * by the rollback authority, not a file name, so a receipt that ever began
     * with `deploy-` would otherwise resolve to a path promote never wrote.
     * And the `id` test is what distinguishes the two retained writers, since
     * the row carries no `verb` key (see `row()`).
     *
     * @param array<string,mixed> $row
     */
    public static function prefixForRow(array $row): string {
        if (self::isRetained($row) && str_starts_with((string) ($row['id'] ?? ''), self::DEPLOY_ID_PREFIX)) {
            return self::DEPLOY_ID_PREFIX;
        }

        return self::ID_PREFIX;
    }

    /**
     * The target path of a retained row's checkpoint. Pure; the caller
     * probes existence.
     *
     * `$prefix` defaults to promote's, so every call site that predates
     * deploy's checkpoint keeps its exact behaviour; `prefixForRow()` is what
     * a caller holding a catalog row passes.
     *
     * @param array<string,mixed> $row
     */
    public static function checkpointPath(string $repoPath, array $row, string $prefix = self::ID_PREFIX): string {
        $owner = (string) ($row['owner'] ?? '');
        if (preg_match(self::OWNER_PATTERN, $owner) !== 1) {
            throw new CommandRefusalException(
                'checkpoint_owner_invalid',
                'the retained checkpoint names an owner this build will not turn into a filesystem path',
                'inspect private operator evidence for this target before recovering'
            );
        }

        return rtrim($repoPath, '/') . '/.duo/checkpoints/' . $prefix . $owner . '.sql';
    }

    private static function malformed(string $detail): CommandRefusalException {
        return new CommandRefusalException(
            'checkpoint_listing_malformed',
            'the target answered the retained checkpoint listing with a line this build cannot read',
            'upgrade the host orchestrator to the build that matches this target, or inspect '
                . '.duo/checkpoints on the target by hand before recovering',
            [['detail' => $detail]]
        );
    }
}
