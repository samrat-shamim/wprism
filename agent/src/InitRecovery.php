<?php
namespace Duo;

require_once __DIR__ . "/Canon.php";
require_once __DIR__ . "/Publish.php";
require_once __DIR__ . "/Capture.php";
require_once __DIR__ . "/Code.php";
require_once __DIR__ . "/Policy.php";
require_once __DIR__ . "/RepositoryCompiler.php";
require_once __DIR__ . "/InitAttemptJournal.php";
require_once __DIR__ . "/InitOwnedArtifacts.php";
require_once __DIR__ . "/InitProtocol.php";
require_once __DIR__ . "/InitRepositoryBoundary.php";

/** Verification and cleanup of sealed interrupted first-init attempts. */
final class InitRecovery {
    public const PLAN_FORMAT = InitProtocol::PLAN_FORMAT;

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    public static function interrupted_attempt_proposal(
        array $attempt,
        string $repo,
        string $logicalRepo,
        string $rootIdentity
    ): array {
        if (!hash_equals((string) $attempt['repository'], $logicalRepo)
            || !hash_equals((string) $attempt['repository_identity'], $rootIdentity)) {
            throw new \RuntimeException('duo: interrupted init record belongs to a different repository identity');
        }
        $proposal = $attempt['proposal'];
        if (($proposal['format'] ?? null) !== self::PLAN_FORMAT
            || ($proposal['ready'] ?? null) !== true
            || !is_array($proposal['unsupported'] ?? null)
            || $proposal['unsupported'] !== []) {
            throw new \RuntimeException('duo: interrupted init record does not contain a confirmable original proposal');
        }
        $manualReason = self::interrupted_attempt_manual_recovery_reason($repo, $attempt);
        $stateDir = rtrim($repo, '/') . '/state';
        $intent = Publish::intent_record($stateDir);
        $receiptRecord = Publish::receipt_record($stateDir);
        $committed = $receiptRecord !== null;
        if (!$committed && is_array($intent) && ($intent['phase'] ?? null) === 'committing') {
            $committed = Capture::publication_commit_status($stateDir, $intent);
        }
        if ($manualReason !== null) {
            $proposal['state']['recovery'] = 'manual-interrupted-init-recovery';
            $proposal['unsupported'][] = [
                'code' => 'interrupted_init_manual_recovery',
                'extension' => $logicalRepo,
                'kind' => 'repository',
                'reason' => $manualReason,
                'remediation' => 'keep the repository quiesced and preserve the sealed journal, lock, and complete partial root; follow the documented archive-and-recreate manual recovery procedure',
            ];
            $proposal['ready'] = false;
        } elseif ($committed) {
            $proposal['state']['recovery'] = 'verify-interrupted-committed-init';
            $proposal['advisories'][] = [
                'code' => 'interrupted_committed_init_finalization',
                'extension' => $logicalRepo,
                'kind' => 'repository',
                'reason' => 'the interrupted attempt appears to have durable committed-state proof but did not clear its sealed init journal',
                'remediation' => 'confirm this verification plan to re-prove the committed state/code/Git tuple; only exact proof permits clearing the sealed journal, otherwise every recovery artifact is retained',
            ];
        } else {
            $proposal['state']['recovery'] = 'verify-interrupted-precommit-init';
            $proposal['advisories'][] = [
                'code' => 'interrupted_init_recovery',
                'extension' => $logicalRepo,
                'kind' => 'repository',
                'reason' => 'a sealed prior init attempt ended before it returned a completed baseline and requires exact ownership verification',
                'remediation' => 'confirm this verification plan to roll back only payloads carrying complete deletion authority; partial or ambiguous artifacts are retained for the documented manual recovery procedure',
            ];
        }
        usort($proposal['advisories'], static function (array $a, array $b): int {
            return [$a['kind'], $a['extension'], $a['code']] <=> [$b['kind'], $b['extension'], $b['code']];
        });
        unset($proposal['digest']);
        $proposal['digest'] = hash('sha256', Canon::encode($proposal));
        return $proposal;
    }

    /**
     * A read-only preflight for shapes that cannot authorize automatic
     * deletion. Confirmation still re-verifies every complete manifest under
     * the publication lock; this classifier only prevents the public plan
     * from promising a recovery that the sealed journal cannot prove.
     *
     * @param array<string,mixed> $attempt
     */
    public static function interrupted_attempt_manual_recovery_reason(string $repo, array $attempt): ?string {
        $owned = (array) ($attempt['owned'] ?? []);
        $stateDir = rtrim($repo, '/') . '/state';
        $present = static fn(string $path): bool => file_exists($path) || is_link($path);
        $manifestMismatch = static function (
            string $path,
            mixed $manifest,
            string $label
        ) use ($present): ?string {
            if (!$present($path)) {
                return null;
            }
            if (!is_array($manifest)) {
                return "the sealed attempt has a present $label without a complete ownership manifest";
            }
            try {
                $actual = Publish::tree_ownership_manifest($path);
            } catch (\Throwable $failure) {
                return "the interrupted-init $label cannot be enumerated for manifest verification";
            }
            if (Canon::encode($actual) !== Canon::encode($manifest)) {
                return "the interrupted-init $label no longer matches its sealed ownership manifest";
            }
            return null;
        };
        $identityMismatch = static function (
            string $path,
            mixed $identity,
            string $label
        ) use ($present): ?string {
            if (!$present($path)) {
                return null;
            }
            if (!is_string($identity)) {
                return "the sealed attempt has a present $label without a complete ownership identity";
            }
            try {
                $actual = InitOwnedArtifacts::directory_identity($path, $label);
            } catch (\Throwable $failure) {
                return "the interrupted-init $label cannot be enumerated for ownership verification";
            }
            if (!hash_equals($identity, $actual)) {
                return "the interrupted-init $label no longer matches its sealed ownership identity";
            }
            return null;
        };
        $entries = @scandir($repo);
        if ($entries === false) {
            return 'the interrupted-init repository root cannot be enumerated for recovery artifacts';
        }
        foreach ($entries as $entry) {
            if (str_contains($entry, '.duo-claim-')) {
                return 'the interrupted-init repository contains an unjournaled cleanup claim artifact';
            }
            // DUO-3427: "unbound" is the recovery authority's word, and it
            // has a precise meaning there — a write_record() temp that is NOT
            // a hard link to its sealed next slot carrying that exact record
            // (Publish::remove_matching_record_temps(), whose docblock refuses
            // to sweep "by name pattern"). This gate swept by name pattern,
            // so the `record-create-next` crash window — temp created, hard
            // linked to `.next`, fault before the canonical link, one unlink
            // from resolved — was sent to manual archive-and-recreate even
            // though the confirmation would have rolled it back completely.
            // Both sites now ask Publish the same question; a genuinely
            // unbound temp (the `record-create-temp` window, where no `.next`
            // exists at all) still refuses here with the same sentence.
            if ((str_starts_with($entry, 'state.capture-intent.tmp.')
                || str_starts_with($entry, 'state.capture-receipt.tmp.'))
                && !Publish::record_temp_is_resolvable($stateDir, $entry)) {
                return 'the interrupted-init repository contains an unbound capture record temporary artifact';
            }
            $knownInitArtifact = $entry === InitAttemptJournal::FILE
                || $entry === InitAttemptJournal::NEXT_FILE
                || str_starts_with($entry, '.duo-init-code-');
            if (str_contains($entry, '.duo-init-') && !$knownInitArtifact) {
                return 'the interrupted-init repository contains an unjournaled Init temporary or claim artifact';
            }
        }
        $recordPaths = [
            Publish::intent_path($stateDir),
            Publish::intent_path($stateDir) . '.previous',
            Publish::intent_path($stateDir) . '.next',
            Publish::receipt_path($stateDir),
            Publish::receipt_path($stateDir) . '.previous',
            Publish::receipt_path($stateDir) . '.next',
        ];
        $hasPublicationRecord = false;
        foreach ($recordPaths as $recordPath) {
            if ($present($recordPath)) {
                $hasPublicationRecord = true;
                break;
            }
        }
        if ($hasPublicationRecord
            && (!is_array($owned['state_staging_manifest'] ?? null)
                || !is_array($owned['state_reservation_manifest'] ?? null))) {
            return 'the sealed attempt has publication records without complete state deletion manifests';
        }
        if ($present(Publish::backup_dir($stateDir)) && !$hasPublicationRecord) {
            return 'the sealed attempt has a state backup without its publication record';
        }
        if ($present(Publish::stage_dir($stateDir))
            && (is_link(Publish::stage_dir($stateDir)) || !is_dir(Publish::stage_dir($stateDir)))) {
            return 'the sealed attempt has a non-directory state staging boundary';
        }
        if ($present(Publish::stage_dir($stateDir))
            && !is_array($owned['state_staging_manifest'] ?? null)) {
            return 'the sealed attempt has a partial state staging tree without a complete deletion manifest';
        }
        if ($present($stateDir) && (is_link($stateDir) || !is_dir($stateDir))) {
            return 'the sealed attempt has a non-directory state reservation boundary';
        }
        if ($present($stateDir) && !$hasPublicationRecord
            && !is_string($owned['state_identity'] ?? null)) {
            return 'the sealed attempt has an incomplete state reservation without a complete ownership manifest';
        }

        // A sealed journal is deletion authority only for the exact tree it
        // described.  A crash during strict cleanup can leave a partial tree
        // while the journal and its manifest remain intact; do this check in
        // the read-only proposal path so the operator never receives a
        // confirmable plan that will fail only after recovery starts.
        if ($present(Publish::stage_dir($stateDir))) {
            $reason = $manifestMismatch(
                Publish::stage_dir($stateDir),
                $owned['state_staging_manifest'] ?? null,
                'state capture staging root'
            );
            if ($reason !== null) {
                return $reason;
            }
        }
        if ($present(Publish::backup_dir($stateDir))) {
            $reason = $manifestMismatch(
                Publish::backup_dir($stateDir),
                $owned['state_reservation_manifest'] ?? null,
                'state capture backup root'
            );
            if ($reason !== null) {
                return $reason;
            }
        }
        if ($present($stateDir)) {
            $stateCandidates = [];
            if (is_array($owned['state_staging_manifest'] ?? null)) {
                $stateCandidates[] = $owned['state_staging_manifest'];
            }
            if (is_array($owned['state_reservation_manifest'] ?? null)) {
                $stateCandidates[] = $owned['state_reservation_manifest'];
            }
            if ($stateCandidates !== []) {
                try {
                    $actualState = Publish::tree_ownership_manifest($stateDir);
                } catch (\Throwable $failure) {
                    return 'the interrupted-init published state root cannot be enumerated for manifest verification';
                }
                $matchesManifest = false;
                foreach ($stateCandidates as $candidateManifest) {
                    if (Canon::encode($actualState) === Canon::encode($candidateManifest)) {
                        $matchesManifest = true;
                        break;
                    }
                }
                if (!$matchesManifest) {
                    return 'the interrupted-init state root no longer matches any sealed ownership manifest';
                }
            } else {
                $reason = $identityMismatch(
                    $stateDir,
                    $owned['state_identity'] ?? null,
                    'state reservation root'
                );
                if ($reason !== null) {
                    return $reason;
                }
            }
        }

        $mediaDir = rtrim($repo, '/') . '/media';
        if ($present($mediaDir) && (is_link($mediaDir) || !is_dir($mediaDir))) {
            return 'the sealed attempt has a non-directory media publication boundary';
        }
        if ($present($mediaDir)
            && !is_array($owned['media_manifest'] ?? null)
            && !is_string($owned['media_identity'] ?? null)) {
            return 'the sealed attempt has a partial media root without a complete deletion manifest';
        }
        if ($present($mediaDir)) {
            $reason = is_array($owned['media_manifest'] ?? null)
                ? $manifestMismatch($mediaDir, $owned['media_manifest'], 'media publication root')
                : $identityMismatch($mediaDir, $owned['media_identity'] ?? null, 'media publication root');
            if ($reason !== null) {
                return $reason;
            }
        }
        $codeDir = rtrim($repo, '/') . '/code';
        if ($present($codeDir) && (is_link($codeDir) || !is_dir($codeDir))) {
            return 'the sealed attempt has a non-directory code publication boundary';
        }
        if ($present($codeDir)
            && !is_string($owned['code_identity'] ?? null)
            && !is_string($owned['code_root_empty_identity'] ?? null)) {
            return 'the sealed attempt has an incomplete code root without a complete descriptor';
        }
        if ($present($codeDir)) {
            $reason = $identityMismatch(
                $codeDir,
                is_string($owned['code_identity'] ?? null)
                    ? $owned['code_identity']
                    : ($owned['code_root_empty_identity'] ?? null),
                'code publication root'
            );
            if ($reason !== null) {
                return $reason;
            }
        }
        $stage = $owned['code_stage'] ?? null;
        if (is_string($stage) && $present($stage) && (is_link($stage) || !is_dir($stage))) {
            return 'the sealed attempt has a non-directory code staging boundary';
        }
        if (is_string($stage) && $present($stage)
            && !is_string($owned['code_stage_identity'] ?? null)) {
            return 'the sealed attempt has a partial code staging tree without a complete descriptor';
        }
        if (is_string($stage) && $present($stage)) {
            $reason = $identityMismatch($stage, $owned['code_stage_identity'] ?? null, 'code staging root');
            if ($reason !== null) {
                return $reason;
            }
        }

        // DUO-3421: an owned-file artifact that is PRESENT while the journal
        // holds no plan for it is not this attempt's artifact -- it is content
        // that predates the attempt, and recovery must leave it exactly where
        // it is. Both publications are strictly write-ahead: confirm() journals
        // `<artifact>_plan` (carrying the previous bytes to restore) BEFORE
        // publish_owned_file() touches the path, so "present and unrecorded"
        // cannot describe anything Duo wrote. Refusing it instead declared an
        // ordinary pre-existing .gitignore -- which every existing Git worktree
        // has, and which the live harness sets up by name -- an unprovable
        // ownership situation, so a crash before the gitignore phase demanded
        // manual recovery for a file Duo had never opened. The recorded shapes
        // below are unchanged and still refuse: a non-regular boundary here, a
        // recorded plan or publication whose bytes no longer match in the
        // compensation path, and every partial tree.
        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if ($present($siteFile) && (is_link($siteFile) || !is_file($siteFile))) {
            return 'the sealed attempt has a non-regular site.duo.json boundary';
        }
        $gitignore = rtrim($repo, '/') . '/.gitignore';
        if ($present($gitignore) && (is_link($gitignore) || !is_file($gitignore))) {
            return 'the sealed attempt has a non-regular .gitignore boundary';
        }
        $gitDir = rtrim($repo, '/') . '/.git';
        if (($owned['git_created'] ?? false) === true && $present($gitDir)
            && (is_link($gitDir) || !is_dir($gitDir))) {
            return 'the sealed attempt has a non-directory Git metadata boundary';
        }
        // The same exact-tree authority recovery demands before it may delete
        // this root. A complete git_identity must still describe the populated
        // root; an earlier git_empty_identity is sufficient only if the root
        // still has that empty shape. A failed recursive cleanup can restore
        // the original root inode after deleting one child, so the presence of
        // a string-valued git_identity alone is not deletion authority. Both
        // sites ask git_deletion_identity_current() to keep preflight and
        // recovery symmetric.
        if (($owned['git_created'] ?? false) === true && $present($gitDir)
            && self::git_deletion_identity_current($repo, $owned) === null) {
            return 'the sealed attempt has incomplete Git metadata without a complete ownership manifest';
        }
        return null;
    }

    /**
     * Return the sealed Git root identity that still describes the current
     * ordinary root exactly. A populated git-ready identity takes precedence;
     * the earlier empty-root identity also authorizes deletion if a failed
     * cleanup has returned the root to that exact original shape.
     *
     * @param array<string,mixed> $owned
     */
    private static function git_deletion_identity_current(string $repo, array $owned): ?string {
        $gitDir = rtrim($repo, '/') . '/.git';
        if (is_link($gitDir) || !is_dir($gitDir)) {
            return null;
        }
        try {
            $actual = InitOwnedArtifacts::directory_identity($gitDir, 'Git metadata root');
        } catch (\Throwable $failure) {
            return null;
        }
        foreach (['git_identity', 'git_empty_identity'] as $key) {
            $identity = $owned[$key] ?? null;
            if (is_string($identity) && hash_equals($identity, $actual)) {
                return $identity;
            }
        }
        return null;
    }


    /**
     * Roll back a sealed pre-COMMIT attempt in a fresh process. This path is
     * deliberately cleanup-only: after it succeeds the host must request and
     * confirm a new discovery digest, so stale target facts are never reused
     * as authority for a second capture transaction.
     *
     * @param array<string,mixed> $attempt
     * @param array{previous:?string,published:string} $attemptPublication
     * @param resource $lock
     * @return array{outcome:string,revision_hash?:string,descriptor?:array<string,mixed>,lifecycle?:array<string,mixed>}
     */
    public static function recover_interrupted_attempt(
        string $repo,
        string $logicalRepo,
        array $rootStat,
        $lock,
        array $attempt,
        array $attemptPublication
    ): array {
        InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
        $currentAttempt = InitAttemptJournal::read($repo);
        if ($currentAttempt === null || Canon::encode($currentAttempt) !== Canon::encode($attempt)) {
            throw new \RuntimeException('duo: interrupted init record changed before recovery locking');
        }
        $stateDir = rtrim($repo, '/') . '/state';
        Publish::assert_lock_path($lock, $stateDir);
        $owned = (array) $attempt['owned'];
        if (is_string($owned['lock_inode'] ?? null)
            && !hash_equals(
                (string) $owned['lock_inode'],
                InitOwnedArtifacts::regular_file_inode_identity(Publish::lock_path($stateDir), 'state.capture.lock')
            )) {
            throw new \RuntimeException('duo: interrupted init lock identity changed; retained recovery evidence');
        }

        $intentPath = Publish::intent_path($stateDir);
        $intentNext = $intentPath . '.next';
        if (!file_exists($intentPath) && !is_link($intentPath)
            && !file_exists($intentPath . '.previous') && !is_link($intentPath . '.previous')
            && (file_exists($intentNext) || is_link($intentNext))) {
            $stagingManifest = $owned['state_staging_manifest'] ?? null;
            if (!is_array($stagingManifest)) {
                throw new \RuntimeException(
                    'duo: interrupted init retained an unpublished intent without a complete staging manifest; manual recovery is required'
                );
            }
            Publish::recover_initial_unpublished_intent_next($stateDir, $stagingManifest);
        }

        $recordPaths = [
            Publish::intent_path($stateDir),
            Publish::intent_path($stateDir) . '.previous',
            Publish::intent_path($stateDir) . '.next',
            Publish::receipt_path($stateDir),
            Publish::receipt_path($stateDir) . '.previous',
            Publish::receipt_path($stateDir) . '.next',
        ];
        $hasPublicationRecord = false;
        foreach ($recordPaths as $recordPath) {
            if (file_exists($recordPath) || is_link($recordPath)) {
                $hasPublicationRecord = true;
                break;
            }
        }
        if ($hasPublicationRecord) {
            $stagingManifest = $owned['state_staging_manifest'] ?? null;
            $stateReservationManifest = $owned['state_reservation_manifest'] ?? null;
            if (!is_array($stagingManifest) || !is_array($stateReservationManifest)) {
                throw new \RuntimeException(
                    'duo: interrupted init retained a publication record without complete state manifests; manual recovery is required'
                );
            }
            Publish::recover_initial(
                $stateDir,
                $stateReservationManifest,
                $stagingManifest,
                static function (array $intent) use ($stateDir): bool {
                    return Capture::publication_commit_status($stateDir, $intent);
                }
            );
        } elseif (file_exists(Publish::backup_dir($stateDir)) || is_link(Publish::backup_dir($stateDir))) {
            throw new \RuntimeException(
                'duo: interrupted init retained a backup without a sealed publication record; manual recovery is required'
            );
        }
        if (!$hasPublicationRecord) {
            Publish::assert_no_record_temps($stateDir);
        }
        $receipt = Publish::receipt_record($stateDir);
        if ($receipt !== null) {
            $verified = self::assert_interrupted_committed_attempt($repo, $attempt, $receipt);
            InitAttemptJournal::remove_records(
                $repo,
                $logicalRepo,
                $attempt,
                $attemptPublication,
                true
            );
            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            return ['outcome' => 'committed-finalized'] + $verified;
        }
        $staging = Publish::stage_dir($stateDir);
        if (file_exists($staging) || is_link($staging)) {
            $stagingManifest = $owned['state_staging_manifest'] ?? null;
            if (!is_array($stagingManifest)) {
                throw new \RuntimeException(
                    'duo: interrupted init retained a partial state staging tree without a complete deletion manifest; manual recovery is required'
                );
            }
            Publish::remove_owned_tree($staging, $stagingManifest, 'initial capture staging');
        }
        foreach ([Publish::intent_path($stateDir), $staging, Publish::backup_dir($stateDir)] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException('duo: interrupted init recovery retained an ambiguous state publication boundary');
            }
        }
        if (is_dir($stateDir) || is_link($stateDir)) {
            $stateIdentity = $owned['state_identity'] ?? null;
            if (!is_string($stateIdentity)
                || !hash_equals($stateIdentity, InitOwnedArtifacts::directory_identity($stateDir, 'initial state reservation'))) {
                throw new \RuntimeException(
                    'duo: interrupted init retained an incomplete state reservation without a complete ownership manifest; manual recovery is required'
                );
            }
            InitOwnedArtifacts::remove_owned_tree($stateDir, $stateIdentity, 'initial state reservation');
        }

        $mediaDir = rtrim($repo, '/') . '/media';
        if (is_dir($mediaDir) || is_link($mediaDir)) {
            $mediaManifest = $owned['media_manifest'] ?? null;
            if (is_array($mediaManifest)) {
                Publish::remove_owned_tree($mediaDir, $mediaManifest, 'initial media root');
            } else {
                $mediaIdentity = $owned['media_identity'] ?? null;
                if (!is_string($mediaIdentity)
                    || !hash_equals($mediaIdentity, InitOwnedArtifacts::directory_identity($mediaDir, 'media publication root'))) {
                    throw new \RuntimeException(
                        'duo: interrupted init retained a partial media root without a complete deletion manifest; manual recovery is required'
                    );
                }
                InitOwnedArtifacts::remove_owned_tree($mediaDir, $mediaIdentity, 'media publication root');
            }
        }

        $codeDir = rtrim($repo, '/') . '/code';
        if (is_dir($codeDir) || is_link($codeDir)) {
            $codeIdentity = $owned['code_identity'] ?? null;
            if (!is_string($codeIdentity)) {
                $codeIdentity = $owned['code_root_empty_identity'] ?? null;
                if (!is_string($codeIdentity)
                    || !hash_equals($codeIdentity, InitOwnedArtifacts::directory_identity($codeDir, 'code publication root'))) {
                    throw new \RuntimeException(
                        'duo: interrupted init retained an incomplete code root without a complete descriptor; manual recovery is required'
                    );
                }
            }
            InitOwnedArtifacts::remove_owned_tree($codeDir, $codeIdentity, 'code publication root');
        }
        $stage = $owned['code_stage'] ?? null;
        $stageIdentity = $owned['code_stage_identity'] ?? null;
        if (is_string($stage) && (file_exists($stage) || is_link($stage))) {
            if (!str_starts_with($stage, rtrim($repo, '/') . '/.duo-init-code-')) {
                throw new \RuntimeException('duo: interrupted init code-stage path escaped the repository boundary');
            }
            if (!is_string($stageIdentity)) {
                throw new \RuntimeException(
                    'duo: interrupted init retained a partial code staging tree without a complete descriptor; manual recovery is required'
                );
            }
            InitOwnedArtifacts::remove_owned_tree($stage, $stageIdentity, 'code staging root');
        }

        // DUO-3421: presence-guarded like every sibling branch above and below
        // (state, media, code, stage, git, and both `plan` arms). This one is
        // ALSO reached in-process: Init::confirm()'s catch compensates its own
        // publications and then re-enters this function through
        // recover_interrupted_attempt() to PROVE the rollback from the sealed
        // journal. That proof pass must tolerate work the catch already did.
        // Without the guard the second pass met an absent file it had itself
        // just deleted and refused with "preserved a replacement ... instead
        // of deleting external bytes", which the caller reports as "init
        // retained its sealed journal because final compensation could not
        // prove every planned artifact" -- a clean rollback turned into a
        // retained journal and lock plus an unclassified refusal. Only the
        // already-compensated shape is skipped: an absent file with nothing
        // to restore. Anything else -- absent with a prior version to put
        // back, or present with unexpected bytes -- still refuses exactly as
        // before.
        $sitePublication = $owned['site_publication'] ?? null;
        if (is_array($sitePublication)
            && !InitOwnedArtifacts::owned_file_already_compensated(
                rtrim($repo, '/') . '/site.duo.json',
                $sitePublication
            )) {
            InitOwnedArtifacts::compensate_owned_file(
                rtrim($repo, '/') . '/site.duo.json',
                $sitePublication,
                'site.duo.json'
            );
        } elseif (!is_array($sitePublication)
            && is_array($owned['site_plan'] ?? null)
            && (file_exists(rtrim($repo, '/') . '/site.duo.json')
            || is_link(rtrim($repo, '/') . '/site.duo.json'))) {
            // Same write-ahead invariant as the proposal gate above, and the
            // same shape the .gitignore arm below already had: with no
            // journaled plan this file predates the attempt and compensation
            // has nothing to undo. (DUO-3421)
            $sitePlan = $owned['site_plan'];
            $expected = (string) ($sitePlan['expected_identity'] ?? '');
            $current = InitOwnedArtifacts::regular_file_identity(rtrim($repo, '/') . '/site.duo.json', 'site.duo.json');
            if (!hash_equals($expected, $current)) {
                InitOwnedArtifacts::compensate_owned_file(
                    rtrim($repo, '/') . '/site.duo.json',
                    ['previous' => $sitePlan['previous'] ?? null, 'published' => $current],
                    'site.duo.json'
                );
            }
        }

        $gitignorePublication = $owned['gitignore_publication'] ?? null;
        if (is_array($gitignorePublication)
            && !InitOwnedArtifacts::owned_file_already_compensated(
                rtrim($repo, '/') . '/.gitignore',
                $gitignorePublication
            )) {
            InitOwnedArtifacts::compensate_owned_file(
                rtrim($repo, '/') . '/.gitignore',
                $gitignorePublication,
                '.gitignore'
            );
        } elseif (!is_array($gitignorePublication)
            && is_array($owned['gitignore_plan'] ?? null)
            && (file_exists(rtrim($repo, '/') . '/.gitignore')
                || is_link(rtrim($repo, '/') . '/.gitignore'))) {
            $gitignorePlan = $owned['gitignore_plan'];
            $expected = (string) ($gitignorePlan['expected_identity'] ?? '');
            $current = InitOwnedArtifacts::regular_file_identity(rtrim($repo, '/') . '/.gitignore', '.gitignore');
            if (!hash_equals($expected, $current)) {
                InitOwnedArtifacts::compensate_owned_file(
                    rtrim($repo, '/') . '/.gitignore',
                    ['previous' => $gitignorePlan['previous'] ?? null, 'published' => $current],
                    '.gitignore'
                );
            }
        }
        if (($owned['git_created'] ?? false) === true) {
            $gitDir = rtrim($repo, '/') . '/.git';
            if (is_dir($gitDir) || is_link($gitDir)) {
                $gitIdentity = self::git_deletion_identity_current($repo, $owned);
                if (!is_string($gitIdentity)) {
                    throw new \RuntimeException(
                        'duo: interrupted init retained incomplete Git metadata without a complete ownership manifest; manual recovery is required'
                    );
                }
                InitOwnedArtifacts::remove_owned_tree($gitDir, $gitIdentity, 'Git metadata root');
            }
        }
        $postCleanupGit = InitRepositoryBoundary::git_probe($repo);
        if (($postCleanupGit['blockers'] ?? []) !== []) {
            throw new \RuntimeException(
                'duo: interrupted init retained an unjournalled repository artifact; inspect it before retrying'
            );
        }
        InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
        return ['outcome' => 'precommit-rolled-back'];
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $receipt
     * @return array{revision_hash:string,descriptor:array<string,mixed>,lifecycle:array<string,mixed>}
     */
    private static function assert_interrupted_committed_attempt(
        string $repo,
        array $attempt,
        array $receipt
    ): array {
        $stateDir = rtrim($repo, '/') . '/state';
        foreach ([Publish::intent_path($stateDir), Publish::stage_dir($stateDir), Publish::backup_dir($stateDir)] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException('duo: committed init finalization retained an ambiguous publication boundary');
            }
        }
        if (is_link($stateDir) || !is_dir($stateDir)
            || !hash_equals((string) ($receipt['candidate_sha256'] ?? ''), Publish::tree_digest($stateDir))
            || !hash_equals((string) ($receipt['previous_sha256'] ?? ''), hash('sha256', ''))) {
            throw new \RuntimeException('duo: committed init receipt does not prove the current state tree');
        }
        // DUO-3427: two questions, each asked of evidence that survives the
        // sealed journal.
        //
        // This compared the committed file's BYTES to a re-encoding of the
        // journal's copy of the confirmed config, and those bytes can never
        // agree. The file is written from the LIVE proposal, where an empty
        // policy map is a JSON object; the journal stores that proposal as
        // JSON and Canon::decode() reads it back with assoc arrays, so `{}`
        // returns as `[]` and re-encodes as `[]`. Every core-only site has at
        // least one empty policy map, so committed-init FINALIZATION — the
        // whole point of a crash after COMMIT — refused unconditionally with
        // the unclassified envelope, telling the operator their config no
        // longer matched a proposal they had never touched.
        //
        // Byte-exactness is still the anti-tamper contract (a single appended
        // newline must refuse), so it moves to the evidence that CAN carry it
        // through the journal: the publication identity Duo recorded when it
        // wrote the file, which folds the content digest with dev/ino. The
        // proposal binding is kept as a structural comparison, both sides
        // normalized through the same decode/encode, so it means what it says
        // without depending on a distinction the journal cannot hold. The
        // identity is tested FIRST and short-circuits, so the decode below
        // only ever runs on bytes Duo itself wrote.
        $proposal = $attempt['proposal'] ?? null;
        $expectedConfig = is_array($proposal) ? ($proposal['state']['config'] ?? null) : null;
        $sitePublication = ((array) ($attempt['owned'] ?? []))['site_publication'] ?? null;
        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if (!is_array($expectedConfig) || is_link($siteFile) || !is_file($siteFile)
            || !is_array($sitePublication) || !is_string($sitePublication['published'] ?? null)
            || !hash_equals(
                (string) $sitePublication['published'],
                InitOwnedArtifacts::regular_file_identity($siteFile, 'site.duo.json')
            )
            || Canon::encode(Canon::decode(Canon::read_file($siteFile))) !== Canon::encode($expectedConfig)) {
            throw new \RuntimeException('duo: committed init site.duo.json no longer matches the confirmed proposal');
        }
        // DUO-3427: two quantities that are never equal were compared as if
        // they were one. `proposal.code.source_revision` digests the LIVE
        // SOURCE inventory (the target's own wp-content) and is verified
        // against that source, correctly, in capture_code(); a compiled
        // `code_revision` digests the REPOSITORY PAYLOAD. They are computed
        // over different roots from different inputs — the payload
        // deliberately excludes Duo's own control-plane loader, for one — so
        // this refused every committed finalization on arithmetic alone, a
        // second unconditional gate behind the site.duo.json one above.
        //
        // The payload is proved the way everything else in this subsystem is:
        // against evidence the sealed journal carries. `code_identity` is the
        // publication identity Duo recorded for the code root at `code-ready`
        // (dev/ino plus content digest for every child), so it proves the
        // payload is byte-for-byte the tree this attempt published; the
        // completed_code_mismatch() check immediately below already proves
        // that same payload is the one the committed transaction recorded in
        // the ledger. Together those are the binding this line was reaching
        // for. Nothing binds the payload to `source_revision`, because the
        // product does not claim they are equal.
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::compile($repo, $policy);
        $descriptor = $compiled->code_descriptor();
        $codeIdentity = ((array) ($attempt['owned'] ?? []))['code_identity'] ?? null;
        $codeRoot = rtrim($repo, '/') . '/code';
        if (!is_array($descriptor) || !is_string($codeIdentity)
            || is_link($codeRoot) || !is_dir($codeRoot)
            || !hash_equals($codeIdentity, InitOwnedArtifacts::directory_identity($codeRoot, 'code publication root'))) {
            throw new \RuntimeException('duo: committed init code payload changed after Duo published it');
        }
        $codeMismatch = Code::completed_code_mismatch($compiled);
        if ($codeMismatch !== null) {
            throw new \RuntimeException('duo: committed init code lifecycle is not complete: ' . $codeMismatch);
        }
        $git = InitRepositoryBoundary::git_probe($repo);
        if (($git['mode'] ?? null) !== 'existing-worktree' || ($git['blockers'] ?? []) !== []) {
            throw new \RuntimeException('duo: committed init Git worktree no longer satisfies the confirmed boundary');
        }
        return [
            'revision_hash' => $compiled->revision_hash(),
            'descriptor' => $descriptor,
            'lifecycle' => [
                'enabled' => true,
                'completed' => true,
                'code_revision' => (string) $descriptor['code_revision'],
                'files' => count((array) ($descriptor['files'] ?? [])),
            ],
        ];
    }


}
