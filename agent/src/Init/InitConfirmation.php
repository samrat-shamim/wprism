<?php
namespace Duo;

require_once __DIR__ . '/../Capture/Capture.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/../Code/CodeSourceLock.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/InitAttemptJournal.php';
require_once __DIR__ . '/InitCodeBaseline.php';
require_once __DIR__ . '/InitExceptions.php';
require_once __DIR__ . '/InitFaults.php';
require_once __DIR__ . '/InitOwnedArtifacts.php';
require_once __DIR__ . '/InitPlanner.php';
require_once __DIR__ . '/InitProtocol.php';
require_once __DIR__ . '/InitRecovery.php';
require_once __DIR__ . '/InitRepositoryBoundary.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Publication/Publish.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';

/**
 * Runs the reviewed initialization transaction and its compensation paths.
 */
final class InitConfirmation {
    /**
     * $allowUnmanagedPlugins rides all the way to BOTH recomputations rather
     * than being consumed here. The flag changes which rows are `unsupported`,
     * `unsupported` is inside the proposal, and the proposal digest is what
     * the operator confirmed — so a confirmation run with a different flag
     * recomputes a different digest and is refused by
     * assert_confirmed_proposal(), which is the reviewed-decision property the
     * whole digest protocol exists for.
     *
     * $lockPlan is the host's code classification and rides to both
     * recomputations for exactly the same reason (DUO-3499,
     * InitPlanner::CODE_LOCK_ARGUMENT): it is inside `code.split`, `code.split`
     * is inside the digest, so a confirmation carrying a different
     * classification recomputes a different proposal and is refused.
     *
     * @param ?list<array<string,mixed>> $lockPlan
     * @return array<string,mixed>
     */
    public static function run(
        string $repo,
        string $expectedDigest,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null
    ): array {
        $logicalRepo = InitRepositoryBoundary::normalize($repo);
        $proposal = InitPlanner::proposal($logicalRepo, $allowUnmanagedPlugins, $lockPlan);
        InitPlanner::assert_confirmed_proposal($proposal, $expectedDigest);

        // A connection-scoped database advisory lease is non-durable and
        // shared by every local/docker/SSH WP-CLI process using this target.
        // It prevents two confirmations from both taking ownership of an
        // absent config before the filesystem capture lock can exist.
        $lease = self::acquire_init_lease($logicalRepo);
        $publicationLock = null;
        $lockOwnedAndCreated = false;
        $lockPublication = null;
        $retainPublicationLock = false;
        $attemptRecord = null;
        $attemptPublication = null;
        $succeeded = false;
        $previousCwd = null;
        $rootStat = null;
        $repo = null;
        $gitCreated = false;
        $gitIdentity = null;
        $gitRootIdentity = null;
        $gitignorePublication = null;
        $siteFile = null;
        $sitePublication = null;
        $publishedCode = false;
        $codeRootCreated = false;
        $codeRootEmptyIdentity = null;
        $publishedCodeIdentity = null;
        $stagedCode = null;
        $stagedCodeIdentity = null;
        $mediaCreated = false;
        $mediaIdentity = null;
        $mediaRootIdentity = null;
        $stateReserved = false;
        $stateIdentity = null;
        try {
            if (getenv('DUO_TEST_MODE') === '1') {
                $pauseMs = (int) (getenv('DUO_TEST_INIT_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }

            $binding = InitRepositoryBoundary::bind($logicalRepo);
            $previousCwd = $binding['previous_cwd'];
            $rootStat = $binding['stat'];
            $repo = '.';
            $siteFile = './site.duo.json';
            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            $reviewedIdentity = $proposal['state']['repository_identity'] ?? null;
            if (!is_string($reviewedIdentity)
                || !hash_equals($reviewedIdentity, $binding['identity'])) {
                throw new \RuntimeException(
                    'duo: init repository identity changed after confirmation and before publication locking'
                );
            }
            $stateDir = $repo . '/state';
            $lockPath = Publish::lock_path($stateDir);
            $attemptRecord = InitAttemptJournal::read($repo);
            if ($attemptRecord !== null) {
                $publicationLock = (file_exists($lockPath) || is_link($lockPath))
                    ? Publish::lock($stateDir)
                    : Publish::lock_new($stateDir);
                $lockOwnedAndCreated = true;
                Publish::assert_lock_path($publicationLock, $stateDir);
                $lockPublication = [
                    'previous' => null,
                    'published' => InitOwnedArtifacts::regular_file_identity($lockPath, 'state.capture.lock'),
                ];
                $attemptPublication = [
                    'previous' => null,
                    'published' => InitOwnedArtifacts::regular_file_identity(
                        $repo . '/' . InitAttemptJournal::FILE,
                        InitAttemptJournal::FILE
                    ),
                ];
                [$attemptRecord, $attemptPublication] = InitAttemptJournal::resolve(
                    $repo,
                    $attemptRecord,
                    $attemptPublication
                );
                try {
                    $recoveryOutcome = InitRecovery::recover_interrupted_attempt(
                        $repo,
                        $logicalRepo,
                        $rootStat,
                        $publicationLock,
                        $attemptRecord,
                        $attemptPublication
                    );
                    if (($recoveryOutcome['outcome'] ?? null) === 'committed-finalized') {
                        $attemptPublication = null;
                        $descriptor = (array) $recoveryOutcome['descriptor'];
                        $lifecycle = (array) $recoveryOutcome['lifecycle'];
                        $revisionHash = (string) $recoveryOutcome['revision_hash'];
                        $finalGit = InitRepositoryBoundary::git_probe($repo);
                        $succeeded = true;
                        return [
                            'format' => 'duo-init-result/v1',
                            'proposal_digest' => $expectedDigest,
                            'recovery' => 'committed-finalized',
                            'baseline' => [
                                'kind' => 'state-capture',
                                'revision_hash' => $revisionHash,
                                'rollback_note' => 'This is a state baseline, not a code-and-database rollback checkpoint.',
                            ],
                            'capture' => [
                                'revision_hash' => $revisionHash,
                                'initial_code_baseline' => $lifecycle,
                                'initial_publication_cleanup' => 'clean',
                            ],
                            'code' => [
                                'descriptor' => $descriptor,
                                'management' => 'managed-baseline',
                                'revision_hash' => $descriptor['code_revision'],
                                'source' => Code::SOURCE,
                                'lifecycle' => $lifecycle,
                            ],
                            'state' => [
                                'git' => $finalGit['mode'],
                                'repository' => $logicalRepo,
                                'site_config' => $logicalRepo . '/site.duo.json',
                            ],
                            'unsupported' => [],
                        ];
                    }
                    if ($lockOwnedAndCreated && is_array($lockPublication)) {
                        InitOwnedArtifacts::remove_exact_owned_file(
                            $lockPath,
                            (string) $lockPublication['published'],
                            'state.capture.lock'
                        );
                        $lockOwnedAndCreated = false;
                        $lockPublication = null;
                    }
                    InitAttemptJournal::remove_records(
                        $repo,
                        $logicalRepo,
                        $attemptRecord,
                        $attemptPublication
                    );
                    $attemptPublication = null;
                } catch (\Throwable $recoveryFailure) {
                    $retainPublicationLock = true;
                    throw $recoveryFailure;
                }
                // DUO-3421: this is a SUCCESSFUL outcome delivered as a
                // non-zero exit -- the interrupted attempt was proven and
                // rolled back, and the operator's next step is simply to
                // rerun. As a bare RuntimeException on a command that is
                // (rightly) absent from Cli::PUBLIC_REFUSAL_COMMANDS, it
                // reached JSON callers as "init refused at an unclassified
                // safety gate" with details_redacted, sending the operator to
                // private evidence for an answer that IS the public one -- the
                // DUO-3398 shape again, on the recovery path this time. It has
                // an entirely reviewable shape, so it gets one, per DUO-3399's
                // rule that the generic arm is only for failures that genuinely
                // have none.
                throw new CommandRefusalException(
                    'interrupted_init_rolled_back',
                    'duo: interrupted pre-COMMIT init was safely rolled back; rerun duo init and confirm the fresh proposal',
                    'rerun duo init and confirm the fresh proposal it prints'
                );
            }
            $attemptRecord = [
                'format' => InitProtocol::ATTEMPT_FORMAT,
                'phase' => 'preparing',
                'repository' => $logicalRepo,
                'repository_identity' => $binding['identity'],
                'proposal' => $proposal,
                'owned' => ['lock_planned' => true],
            ];
            $attemptPublication = InitAttemptJournal::write($repo, $attemptRecord, 'absent');
            InitOwnedArtifacts::assert_absent_owned_path($lockPath, 'state.capture.lock');
            $publicationLock = Publish::lock_new($stateDir);
            $lockOwnedAndCreated = true;
            InitFaults::checkpoint('lock-created');
            Publish::assert_lock_path($publicationLock, $stateDir);
            $lockPublication = [
                'previous' => null,
                'published' => InitOwnedArtifacts::regular_file_identity($lockPath, 'state.capture.lock'),
            ];
            $attemptRecord['phase'] = 'locked';
            $attemptRecord['owned']['lock_inode'] = InitOwnedArtifacts::regular_file_inode_identity(
                $lockPath,
                'state.capture.lock'
            );
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            Publish::assert_lock_path($publicationLock, $stateDir);

            // Recompute while BOTH the init advisory lease and the shared
            // state publication lock are held. The operator confirms facts,
            // never a mutable config payload; neither a second init nor an
            // ordinary capture can publish between this recheck and commit.
            $proposal = InitPlanner::proposal_bound(
                $repo,
                $logicalRepo,
                $binding['identity'],
                true,
                true,
                $allowUnmanagedPlugins,
                $lockPlan
            );
            InitPlanner::assert_confirmed_proposal($proposal, $expectedDigest);

            if (($proposal['state']['git']['mode'] ?? null) === 'initialize-on-confirm') {
                Publish::assert_lock_path($publicationLock, $stateDir);
                InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
                InitOwnedArtifacts::assert_absent_owned_path($repo . '/.git', 'Git metadata root');
                $attemptRecord['phase'] = 'git-planned';
                $attemptRecord['owned']['git_created'] = true;
                $attemptPublication = InitAttemptJournal::write(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
                if (!mkdir($repo . '/.git', 0775)) {
                    throw new \RuntimeException('duo: could not reserve the target Git metadata root');
                }
                $gitCreated = true;
                $gitRootIdentity = InitOwnedArtifacts::directory_inode_identity($repo . '/.git', 'Git metadata root');
                $attemptRecord['phase'] = 'git-reserved';
                $attemptRecord['owned']['git_created'] = true;
                $attemptRecord['owned']['git_root_inode'] = $gitRootIdentity;
                $attemptRecord['owned']['git_empty_identity'] = InitOwnedArtifacts::directory_identity(
                    $repo . '/.git',
                    'Git metadata root'
                );
                $attemptPublication = InitAttemptJournal::write(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
                InitOwnedArtifacts::assert_directory_inode($repo . '/.git', $gitRootIdentity, 'Git metadata root');
                InitRepositoryBoundary::initialize_git($repo);
                InitOwnedArtifacts::assert_directory_inode($repo . '/.git', $gitRootIdentity, 'Git metadata root');
                if (getenv('DUO_TEST_MODE') === '1'
                    && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'git-initialized-before-identity') {
                    throw new \RuntimeException(
                        'duo: injected failure after Git initialization and before its complete ownership manifest'
                    );
                }
                $gitIdentity = InitOwnedArtifacts::directory_identity($repo . '/.git', 'Git metadata root');
                $attemptRecord['phase'] = 'git-ready';
                $attemptRecord['owned']['git_identity'] = $gitIdentity;
                $attemptPublication = InitAttemptJournal::write(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
                if (getenv('DUO_TEST_MODE') === '1'
                    && getenv('DUO_TEST_INIT_FAIL_AFTER_GIT_CREATE') === '1') {
                    throw new \RuntimeException('duo: injected init failure after Git metadata creation');
                }
            }
            if (!$gitCreated) {
                $attemptRecord['owned']['git_created'] = false;
            }
            $gitignorePath = $repo . '/.gitignore';
            $attemptRecord['phase'] = 'gitignore-planned';
            $attemptRecord['owned']['gitignore_plan'] = [
                'expected_identity' => (string) ($proposal['state']['gitignore_identity'] ?? ''),
                'previous' => is_file($gitignorePath) && !is_link($gitignorePath)
                    ? Canon::read_file($gitignorePath)
                    : null,
            ];
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            $gitignorePublication = InitRepositoryBoundary::ensure_gitignore(
                $repo,
                (string) ($proposal['state']['gitignore_identity'] ?? ''),
                // DUO-3499: the locked components' root-anchored ignore lines
                // are published in the SAME owned-file transaction as Duo's own
                // local artifacts, so a repository never exists in a state where
                // the lock declares a component Git is still tracking.
                InitRepositoryBoundary::locked_component_ignore_lines(
                    (array) ($proposal['code']['lock'] ?? [])
                )
            );
            $attemptRecord['phase'] = 'gitignore-ready';
            $attemptRecord['owned']['gitignore_publication'] = $gitignorePublication;
            $attemptRecord['owned']['gitignore_identity'] = InitOwnedArtifacts::regular_file_identity(
                $repo . '/.gitignore',
                '.gitignore'
            );
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );

            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            Publish::assert_lock_path($publicationLock, $stateDir);
            [$descriptor, $stagedCode, $stagedCodeIdentity] = InitCodeBaseline::capture(
                $repo,
                $proposal['code'],
                function (string $stage, ?string $rootIdentity) use (
                    $repo, &$attemptRecord, &$attemptPublication
                ): void {
                    $attemptRecord['phase'] = $rootIdentity === null ? 'code-stage-planned' : 'code-staging';
                    $attemptRecord['owned']['code_stage'] = $stage;
                    $attemptRecord['owned']['code_stage_planned'] = true;
                    if ($rootIdentity !== null) {
                        $attemptRecord['owned']['code_stage_root_inode'] = $rootIdentity;
                    }
                    $attemptPublication = InitAttemptJournal::write(
                        $repo,
                        $attemptRecord,
                        (string) $attemptPublication['published']
                    );
                }
            );
            $attemptRecord['phase'] = 'code-staged';
            $attemptRecord['owned']['code_stage'] = $stagedCode;
            $attemptRecord['owned']['code_stage_identity'] = $stagedCodeIdentity;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            $codeRoot = $repo . '/code';
            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            InitOwnedArtifacts::assert_absent_owned_path($codeRoot, 'code publication root');
            $attemptRecord['phase'] = 'code-root-planned';
            $attemptRecord['owned']['code_root_planned'] = true;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (!mkdir($codeRoot, 0700)) {
                throw new \RuntimeException('duo: could not reserve the code publication root');
            }
            $codeRootCreated = true;
            $codeRootIdentity = InitOwnedArtifacts::directory_inode_identity($codeRoot, 'code publication root');
            $codeRootEmptyIdentity = InitOwnedArtifacts::directory_identity($codeRoot, 'code publication root');
            $attemptRecord['phase'] = 'code-root-reserved';
            $attemptRecord['owned']['code_root_inode'] = $codeRootIdentity;
            $attemptRecord['owned']['code_root_empty_identity'] = $codeRootEmptyIdentity;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            InitOwnedArtifacts::assert_directory_inode($codeRoot, $codeRootIdentity, 'code publication root');
            InitOwnedArtifacts::assert_absent_owned_path($codeRoot . '/wp-content', 'code baseline child');
            if (!hash_equals(
                (string) $stagedCodeIdentity,
                InitOwnedArtifacts::directory_identity($stagedCode, 'code capture staging directory')
            )) {
                throw new \RuntimeException('duo: verified code staging changed before publication');
            }
            $attemptRecord['phase'] = 'code-publish-planned';
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (!rename($stagedCode, $codeRoot . '/wp-content')) {
                throw new \RuntimeException('duo: could not publish the verified code baseline into its reserved root');
            }
            $stagedCode = null;
            $stagedCodeIdentity = null;
            $publishedCode = true;
            @chmod($codeRoot, 0775);
            InitOwnedArtifacts::assert_directory_inode($codeRoot, $codeRootIdentity, 'code publication root');
            $publishedDescriptor = Code::descriptor_from_source($codeRoot . '/wp-content');
            if (Canon::encode($publishedDescriptor) !== Canon::encode($descriptor)) {
                throw new \RuntimeException('duo: published code baseline differs from its reviewed descriptor');
            }
            // DUO-3499: the lock is published INSIDE the reserved code root and
            // BEFORE code_identity is taken, so it needs no ownership
            // bookkeeping of its own: the identity recorded at `code-ready`
            // already covers it, the interrupted-attempt compensation that
            // removes the code root already removes it
            // (InitRecovery.php:535-548), and the committed-attempt
            // verification that re-identifies the root already proves it
            // unchanged (InitRecovery.php:738-744).
            $lockRows = (array) ($proposal['code']['lock'] ?? []);
            if ($lockRows !== []) {
                $attemptRecord['phase'] = 'code-lock-planned';
                $attemptPublication = InitAttemptJournal::write(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
                InitOwnedArtifacts::assert_directory_inode($codeRoot, $codeRootIdentity, 'code publication root');
                $lockPath = $codeRoot . '/' . basename(CodeSourceLock::PATH);
                InitOwnedArtifacts::assert_absent_owned_path($lockPath, 'code lock');
                Canon::write_file($lockPath, CodeSourceLock::encode($lockRows));
                // Read it back through the grammar the compiler will use, so a
                // publication that produced anything the gate would refuse
                // fails here rather than at the operator's first compile.
                if (Canon::encode(CodeSourceLock::parse(Canon::read_file($lockPath)))
                    !== Canon::encode(['format' => CodeSourceLock::FORMAT, 'components' => CodeSourceLock::sort_components($lockRows)])) {
                    throw new \RuntimeException('duo: published code lock differs from its reviewed classification');
                }
                $attemptRecord['phase'] = 'code-lock-written';
                $attemptPublication = InitAttemptJournal::write(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
            }
            $publishedCodeIdentity = InitOwnedArtifacts::directory_identity($codeRoot, 'code publication root');
            $attemptRecord['phase'] = 'code-ready';
            unset(
                $attemptRecord['owned']['code_stage'],
                $attemptRecord['owned']['code_stage_identity'],
                $attemptRecord['owned']['code_stage_root_inode'],
                $attemptRecord['owned']['code_stage_planned']
            );
            $attemptRecord['owned']['code_identity'] = $publishedCodeIdentity;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            $attemptRecord['phase'] = 'config-planned';
            $attemptRecord['owned']['site_plan'] = [
                'expected_identity' => (string) ($proposal['state']['config_identity'] ?? ''),
                'previous' => is_file($siteFile) && !is_link($siteFile)
                    ? Canon::read_file($siteFile)
                    : null,
            ];
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (getenv('DUO_TEST_MODE') === '1') {
                $pauseMs = (int) (getenv('DUO_TEST_INIT_CONFIG_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }
            $sitePublication = InitOwnedArtifacts::publish_owned_file(
                $siteFile,
                Canon::encode($proposal['state']['config']),
                (string) ($proposal['state']['config_identity'] ?? ''),
                'site.duo.json'
            );
            $attemptRecord['phase'] = 'config-ready';
            $attemptRecord['owned']['site_publication'] = $sitePublication;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            Policy::load($repo);
            Publish::assert_lock_path($publicationLock, $stateDir);
            $mediaDir = $repo . '/media';
            InitOwnedArtifacts::assert_absent_owned_path($mediaDir, 'media publication root');
            $attemptRecord['phase'] = 'media-planned';
            $attemptRecord['owned']['media_planned'] = true;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (!mkdir($mediaDir, 0775)) {
                throw new \RuntimeException("duo: could not create media publication root $mediaDir");
            }
            $mediaCreated = true;
            $mediaIdentity = InitOwnedArtifacts::directory_identity($mediaDir, 'media publication root');
            $mediaRootIdentity = InitOwnedArtifacts::directory_inode_identity($mediaDir, 'media publication root');
            InitOwnedArtifacts::assert_absent_owned_path($stateDir, 'initial state reservation');
            $attemptRecord['phase'] = 'state-planned';
            $attemptRecord['owned']['media_root_inode'] = $mediaRootIdentity;
            $attemptRecord['owned']['media_identity'] = $mediaIdentity;
            $attemptRecord['owned']['state_planned'] = true;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (!mkdir($stateDir, 0775)) {
                throw new \RuntimeException('duo: could not reserve the initial state publication root');
            }
            if (getenv('DUO_TEST_MODE') === '1'
                && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'state-reserved-before-identity') {
                throw new \RuntimeException(
                    'duo: injected failure after state reservation and before its complete ownership manifest'
                );
            }
            $stateReserved = true;
            $stateIdentity = InitOwnedArtifacts::directory_identity($stateDir, 'initial state reservation');
            $attemptRecord['phase'] = 'capture-ready';
            $attemptRecord['owned']['media_root_inode'] = $mediaRootIdentity;
            $attemptRecord['owned']['media_identity'] = $mediaIdentity;
            $attemptRecord['owned']['state_identity'] = $stateIdentity;
            $attemptPublication = InitAttemptJournal::write(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (getenv('DUO_TEST_MODE') === '1') {
                $pauseMs = (int) (getenv('DUO_TEST_INIT_PUBLICATION_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }
            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            Publish::assert_lock_path($publicationLock, $stateDir);
            if (!hash_equals(
                (string) $lockPublication['published'],
                InitOwnedArtifacts::regular_file_identity($lockPath, 'state.capture.lock')
            )) {
                throw new \RuntimeException('duo: init capture lock pathname no longer names the held lock inode');
            }
            if (!hash_equals(
                (string) $sitePublication['published'],
                InitOwnedArtifacts::regular_file_identity($siteFile, 'site.duo.json')
            )) {
                throw new \RuntimeException('duo: init site.duo.json changed before initial capture');
            }
            if (is_array($gitignorePublication) && !hash_equals(
                (string) $gitignorePublication['published'],
                InitOwnedArtifacts::regular_file_identity($repo . '/.gitignore', '.gitignore')
            )) {
                throw new \RuntimeException('duo: init .gitignore changed before initial capture');
            }
            $reviewedGitignore = (string) ($proposal['state']['gitignore_identity'] ?? '');
            if (!is_array($gitignorePublication)
                && !hash_equals(
                    $reviewedGitignore,
                    InitOwnedArtifacts::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore')
                )) {
                throw new \RuntimeException('duo: init .gitignore changed before initial capture');
            }
            if (!hash_equals(
                (string) $publishedCodeIdentity,
                InitOwnedArtifacts::directory_identity($codeRoot, 'code publication root')
            )) {
                throw new \RuntimeException('duo: init code root changed before initial capture');
            }
            if (!hash_equals(
                (string) $mediaIdentity,
                InitOwnedArtifacts::directory_identity($mediaDir, 'media publication root')
            )) {
                throw new \RuntimeException('duo: init media root changed before initial capture');
            }
            if ($gitCreated && !hash_equals(
                (string) $gitIdentity,
                InitOwnedArtifacts::directory_identity($repo . '/.git', 'Git metadata root')
            )) {
                throw new \RuntimeException('duo: init Git metadata changed before initial capture');
            }
            $capture = Capture::run_initial_baseline(
                $repo,
                $publicationLock,
                (string) $stateIdentity,
                (string) $mediaIdentity,
                (string) ($sitePublication['published'] ?? ''),
                function (array $stagingManifest, array $mediaManifest, array $stateManifest) use (
                    $repo, &$attemptRecord, &$attemptPublication
                ): void {
                    $attemptRecord['phase'] = 'capture-payload-ready';
                    $attemptRecord['owned']['state_staging_manifest'] = $stagingManifest;
                    $attemptRecord['owned']['media_manifest'] = $mediaManifest;
                    $attemptRecord['owned']['state_reservation_manifest'] = $stateManifest;
                    $attemptPublication = InitAttemptJournal::write(
                        $repo,
                        $attemptRecord,
                        (string) $attemptPublication['published']
                    );
                }
            );
            $stateReserved = false;
            if (($capture['initial_publication_cleanup'] ?? null) !== 'clean') {
                throw new \RuntimeException(
                    'duo: init baseline committed but publication cleanup was retained; recover the durable receipt before declaring initialization complete'
                );
            }
            $revisionHash = (string) ($capture['revision_hash'] ?? '');
            $codeBaseline = $capture['initial_code_baseline'] ?? null;
            if (!preg_match('/^[0-9a-f]{64}$/', $revisionHash)
                || !is_array($codeBaseline)
                || ($codeBaseline['enabled'] ?? null) !== true
                || ($codeBaseline['completed'] ?? null) !== true
                || !hash_equals(
                    (string) ($descriptor['code_revision'] ?? ''),
                    (string) ($codeBaseline['code_revision'] ?? '')
                )) {
                throw new \RuntimeException('duo: init capture returned no completed transaction-bound baseline receipt');
            }
            $finalGit = InitRepositoryBoundary::git_probe($repo);
            if ($finalGit['mode'] !== 'existing-worktree' || $finalGit['blockers'] !== []) {
                throw new \RuntimeException(
                    'duo: init baseline committed, but the target Git worktree changed before final verification'
                );
            }
            if (!hash_equals(
                (string) $publishedCodeIdentity,
                InitOwnedArtifacts::directory_identity($codeRoot, 'code publication root')
            ) || Canon::encode(Code::descriptor_from_source($codeRoot . '/wp-content')) !== Canon::encode($descriptor)) {
                throw new \RuntimeException(
                    'duo: init baseline committed, but the code tree changed before final verification'
                );
            }
            InitRepositoryBoundary::assert_binding($logicalRepo, $rootStat);
            InitFaults::checkpoint('capture-complete');
            if (is_array($attemptPublication) && is_array($attemptRecord)) {
                InitAttemptJournal::remove_records(
                    $repo,
                    $logicalRepo,
                    $attemptRecord,
                    $attemptPublication,
                    true
                );
                $attemptPublication = null;
            }
            $succeeded = true;
            return [
                'format' => 'duo-init-result/v1',
                'proposal_digest' => $expectedDigest,
                'baseline' => [
                    'kind' => 'state-capture',
                    'revision_hash' => $revisionHash,
                    'rollback_note' => 'This is a state baseline, not a code-and-database rollback checkpoint.',
                ],
                'capture' => $capture,
                'code' => [
                    'descriptor' => $descriptor,
                    'management' => 'managed-baseline',
                    'revision_hash' => $descriptor['code_revision'],
                    'source' => Code::SOURCE,
                    'lifecycle' => $codeBaseline,
                ],
                'state' => [
                    'git' => $finalGit['mode'],
                    'repository' => $logicalRepo,
                    'site_config' => $logicalRepo . '/site.duo.json',
                ],
                'unsupported' => [],
            ];
        } catch (\Throwable $error) {
            if ($previousCwd === null || $repo !== '.') {
                throw $error;
            }
            if ($error instanceof InitAttemptRetentionException) {
                $retainPublicationLock = true;
                throw new \RuntimeException(
                    'duo: init retained its sealed recovery journal and capture lock because an owned staging artifact could not be safely compensated: '
                    . $error->getMessage(),
                    0,
                    $error
                );
            }
            // Once Capture has swapped state or written an intent, its
            // transaction/receipt protocol is the authority. Never delete
            // config/code around a possibly committed state tree; retain the
            // complete set for deterministic recovery instead of creating a
            // ghost baseline. All failures before that boundary are fully
            // compensated below while both leases remain held.
            $intentPath = Publish::intent_path($repo . '/state');
            $receiptPath = Publish::receipt_path($repo . '/state');
            $hasIntent = file_exists($intentPath);
            $hasReceipt = file_exists($receiptPath);
            $hasRecordTransition = false;
            foreach ([
                $intentPath . '.previous', $intentPath . '.next',
                $receiptPath . '.previous', $receiptPath . '.next',
            ] as $transitionSlot) {
                if (file_exists($transitionSlot) || is_link($transitionSlot)) {
                    $hasRecordTransition = true;
                    break;
                }
            }
            $boundaryRefusal = $error instanceof InitialStateBoundaryException
                && !$hasIntent && !$hasReceipt;
            $crossedPublication = !$boundaryRefusal && (
                !$stateReserved && is_dir($repo . '/state')
                || $hasIntent
                || $hasReceipt
                || $hasRecordTransition
            );
            if ($crossedPublication) {
                $retainPublicationLock = true;
                throw new \RuntimeException(
                    'duo: init publication crossed its durable receipt boundary; retained config, code, state, and ledger together for recovery: '
                    . $error->getMessage(),
                    0,
                    $error
                );
            }
            try {
                if (is_string($stagedCode) && is_string($stagedCodeIdentity)
                    && (file_exists($stagedCode) || is_link($stagedCode))) {
                    InitOwnedArtifacts::remove_owned_tree($stagedCode, $stagedCodeIdentity, 'code staging root');
                }
                if ($mediaCreated && is_string($mediaIdentity)
                    && (file_exists($repo . '/media') || is_link($repo . '/media'))) {
                    InitOwnedArtifacts::remove_owned_tree($repo . '/media', $mediaIdentity, 'media publication root');
                }
                if ($stateReserved && is_string($stateIdentity)
                    && is_dir($repo . '/state')
                    && hash_equals($stateIdentity, InitOwnedArtifacts::directory_identity($repo . '/state', 'initial state reservation'))) {
                    InitOwnedArtifacts::remove_owned_tree($repo . '/state', $stateIdentity, 'initial state reservation');
                }
                if ($publishedCode && is_string($publishedCodeIdentity)
                    && (file_exists($repo . '/code') || is_link($repo . '/code'))) {
                    InitOwnedArtifacts::remove_owned_tree($repo . '/code', $publishedCodeIdentity, 'code publication root');
                } elseif ($codeRootCreated && is_string($codeRootEmptyIdentity)
                    && (file_exists($repo . '/code') || is_link($repo . '/code'))) {
                    InitOwnedArtifacts::remove_owned_tree($repo . '/code', $codeRootEmptyIdentity, 'empty code publication root');
                }
                if (is_array($sitePublication)) {
                    try {
                        InitOwnedArtifacts::compensate_owned_file($siteFile, $sitePublication, 'site.duo.json');
                    } catch (\Throwable $restoreError) {
                        throw new \RuntimeException(
                            $error->getMessage() . "\nduo: init could not compensate its site.duo.json publication: " . $restoreError->getMessage(),
                            0,
                            $error
                        );
                    }
                }
                if (is_array($gitignorePublication)) {
                    InitOwnedArtifacts::compensate_owned_file($repo . '/.gitignore', $gitignorePublication, '.gitignore');
                }
                if ($gitCreated && is_string($gitIdentity)
                    && (file_exists($repo . '/.git') || is_link($repo . '/.git'))) {
                    InitOwnedArtifacts::remove_owned_tree($repo . '/.git', $gitIdentity, 'Git metadata root');
                }
            } catch (\Throwable $cleanupFailure) {
                $retainPublicationLock = true;
                throw new InitAttemptRetentionException(
                    $error->getMessage()
                    . "\nduo: init retained its sealed recovery journal and capture lock because pre-COMMIT compensation could not safely complete: "
                    . $cleanupFailure->getMessage(),
                    0,
                    $cleanupFailure
                );
            }
            if (is_array($attemptPublication) && is_resource($publicationLock)) {
                try {
                    $diskAttempt = InitAttemptJournal::read($repo);
                    if (!is_array($diskAttempt)) {
                        throw new \RuntimeException(
                            'duo: init lost its sealed recovery journal before final compensation verification'
                        );
                    }
                    $diskPublication = [
                        'previous' => null,
                        'published' => InitOwnedArtifacts::regular_file_identity(
                            $repo . '/' . InitAttemptJournal::FILE,
                            InitAttemptJournal::FILE
                        ),
                    ];
                    [$diskAttempt, $diskPublication] = InitAttemptJournal::resolve(
                        $repo,
                        $diskAttempt,
                        $diskPublication
                    );
                    $verifiedCleanup = InitRecovery::recover_interrupted_attempt(
                        $repo,
                        $logicalRepo,
                        $rootStat,
                        $publicationLock,
                        $diskAttempt,
                        $diskPublication
                    );
                    if (($verifiedCleanup['outcome'] ?? null) !== 'precommit-rolled-back') {
                        throw new \RuntimeException(
                            'duo: init compensation reached a durable committed publication and cannot discard its journal'
                        );
                    }
                    $attemptRecord = $diskAttempt;
                    $attemptPublication = $diskPublication;
                } catch (\Throwable $verificationFailure) {
                    $retainPublicationLock = true;
                    throw new \RuntimeException(
                        $error->getMessage()
                        . "\nduo: init retained its sealed journal because final compensation could not prove every planned artifact: "
                        . $verificationFailure->getMessage(),
                        0,
                        $error
                    );
                }
            }
            if (!$retainPublicationLock && $lockOwnedAndCreated
                && is_array($lockPublication) && is_resource($publicationLock)) {
                InitOwnedArtifacts::remove_exact_owned_file(
                    Publish::lock_path($repo . '/state'),
                    (string) $lockPublication['published'],
                    'state.capture.lock'
                );
                $lockOwnedAndCreated = false;
                $lockPublication = null;
            }
            if (is_array($attemptPublication) && is_array($attemptRecord)
                && (file_exists($repo . '/' . InitAttemptJournal::FILE)
                    || is_link($repo . '/' . InitAttemptJournal::FILE))) {
                InitAttemptJournal::remove_records(
                    $repo,
                    $logicalRepo,
                    $attemptRecord,
                    $attemptPublication
                );
                $attemptPublication = null;
            }
            throw $error;
        } finally {
            if (!$succeeded && !$retainPublicationLock && $lockOwnedAndCreated
                && is_array($lockPublication) && is_resource($publicationLock)) {
                InitOwnedArtifacts::remove_exact_owned_file(
                    Publish::lock_path($repo . '/state'),
                    (string) $lockPublication['published'],
                    'state.capture.lock'
                );
            }
            if (is_resource($publicationLock)) {
                Publish::unlock($publicationLock);
            }
            if ($previousCwd !== null && !@chdir($previousCwd)) {
                self::release_init_lease($lease);
                throw new \RuntimeException('duo: init could not restore its process working directory after confirmation');
            }
            self::release_init_lease($lease);
        }
    }
    /** Acquire one non-durable, connection-owned lease for this target/repo. */
    private static function acquire_init_lease(string $repo): string {
        global $wpdb;
        $name = 'duo-init:' . substr(hash('sha256', (string) $wpdb->prefix . "\0" . $repo), 0, 48);
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('duo: init could not acquire its database advisory lease');
        }
        if ((string) $result !== '1') {
            throw new \RuntimeException('duo: init refused — another initialization already holds the target lease');
        }
        return $name;
    }

    private static function release_init_lease(string $name): void {
        global $wpdb;
        // Connection shutdown releases this lock even if the explicit call
        // fails. Cleanup must never hide the operation's authoritative error.
        @$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}
