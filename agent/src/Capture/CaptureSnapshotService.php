<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canary.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/CaptureCandidateBuilder.php';
require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';
require_once __DIR__ . '/../Repository/CompiledArtifact.php';
require_once __DIR__ . '/../Repository/CanonicalLedgerMapGuard.php';
require_once __DIR__ . '/../Repository/Identity.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../Repository/SidebarState.php';
require_once __DIR__ . '/../Repository/Snapshot.php';

/**
 * Builds canonical in-memory observations without owning publication.
 *
 * This service keeps the ordinary maintenance-aware snapshot, strict
 * zero-write explain/export snapshots, and lifecycle options-only snapshot
 * visibly separate while sharing candidate construction and canonical record
 * projection. Capture remains the stable command-facing facade.
 */
final class CaptureSnapshotService {
    /** @return array<string,array{type:string,hash:string,content:string,path:string}> */
    public static function snapshot(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?CompiledRepository $compiled = null,
        ?Policy $policy = null,
        ?array &$planObservations = null,
        ?array $binding = null
    ): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        Identity::assert_embedded_unique();
        $policy ??= Policy::load($repo);
        CaptureTransaction::assert_engine_support($policy);
        $repository = $compiled ?? RepositoryCompiler::compile_for_diff($repo, Policy::load($repo));
        CanonicalLedgerMapGuard::assert_pre_prune($policy, $repository);
        Ledger::prune_dead_map();
        SidebarState::prune_dead_map($policy);
        $capture = new CaptureCandidateBuilder($repo, $policy, $binding, $repository->tree());
        $repositoryOptions = self::repositoryOptions($repo, $policy, $repository);
        Snapshot::prune_dead_map($policy, $repositoryOptions);
        $repositoryUserLogins = self::repositoryUserLogins($repository);

        $build = CaptureTransaction::run(
            $policy,
            static function (DatabaseWorkAuthority $workAuthority) use (
                $capture,
                $forceUnresolvedRefs,
                $repositoryOptions,
                $repositoryUserLogins
            ): array {
                Identity::assert_embedded_unique();
                $candidate = $capture->build(
                    false,
                    $forceUnresolvedRefs,
                    $repositoryOptions,
                    $repositoryUserLogins,
                    workAuthority: $workAuthority
                );
                Identity::assert_entities_unique($candidate['entities']);
                return $candidate;
            }
        );
        $planObservations = $capture->planObservations();
        return self::records($build['entities']);
    }

    /**
     * Strict observation twin used by explain. Every prerequisite is proven
     * through reads and no schema, identity, provider, or pruning repair is
     * authorized.
     *
     * @return array<string,array{type:string,hash:string,content:string,path:string}>
     */
    public static function snapshotReadOnly(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?CompiledRepository $compiled = null,
        ?Policy $policy = null
    ): array {
        Canary::suppress_cron_spawn();
        Ledger::assert_read_only_schema();
        self::assertReadOnlyIdentityPrecondition(
            static function (): void {
                Identity::assert_embedded_unique();
            }
        );
        $policy ??= Policy::load($repo);
        CaptureTransaction::assert_engine_support($policy);
        $repository = $compiled ?? RepositoryCompiler::compile_for_diff($repo, Policy::load($repo));
        CanonicalLedgerMapGuard::assert_pre_prune($policy, $repository);
        $capture = new CaptureCandidateBuilder($repo, $policy, null, $repository->tree());
        $repositoryOptions = self::repositoryOptions($repo, $policy, $repository);
        $repositoryUserLogins = self::repositoryUserLogins($repository);

        $build = CaptureTransaction::run(
            $policy,
            static function (DatabaseWorkAuthority $workAuthority) use (
                $capture,
                $forceUnresolvedRefs,
                $repositoryOptions,
                $repositoryUserLogins
            ): array {
                self::assertReadOnlyIdentityPrecondition(
                    static function (): void {
                        Identity::assert_embedded_unique();
                    }
                );
                $candidate = $capture->build(
                    false,
                    $forceUnresolvedRefs,
                    $repositoryOptions,
                    $repositoryUserLogins,
                    true,
                    workAuthority: $workAuthority
                );
                self::assertReadOnlyIdentityPrecondition(
                    static function () use ($candidate): void {
                        Identity::assert_entities_unique($candidate['entities']);
                    }
                );
                return $candidate;
            },
            readOnly: true
        );
        return self::records($build['entities']);
    }

    /**
     * Build within RefreshExport's already-open READ ONLY transaction.
     * Transaction and destination ownership deliberately remain with the
     * caller so all exported evidence comes from one locked database view.
     */
    public static function buildReadOnlyExport(
        string $repo,
        Policy $policy,
        ?array $previousOptions,
        array $previousUserLogins,
        bool $forceUnresolvedRefs = false,
        ?DatabaseWorkAuthority $workAuthority = null
    ): array {
        Canary::suppress_cron_spawn();
        return (new CaptureCandidateBuilder($repo, $policy))->build(
            false,
            $forceUnresolvedRefs,
            $previousOptions,
            $previousUserLogins,
            true,
            workAuthority: $workAuthority
        );
    }

    /** Read-only preflight shared by RefreshExport's snapshot boundary. */
    public static function assertReadOnlyExportEngineSupport(Policy $policy): void {
        CaptureTransaction::assert_engine_support($policy);
    }

    /** @return array<string,array{type:string,hash:string,content:string,path:string}> */
    public static function snapshotOptionsCore(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?CompiledRepository $compiled = null,
        ?Policy $policy = null
    ): array {
        Canary::suppress_cron_spawn();
        Ledger::ensure();
        Identity::assert_embedded_unique();
        Ledger::prune_dead_map();
        $policy ??= Policy::load($repo);
        CaptureTransaction::assert_engine_support($policy, true);
        $capture = new CaptureCandidateBuilder($repo, $policy);
        $repository = $compiled ?? RepositoryCompiler::compile_for_diff($repo, Policy::load($repo));
        $repositoryOptions = self::repositoryOptions($repo, $policy, $repository);
        Snapshot::prune_option_name_ref_map($policy, $repositoryOptions);
        $repositoryValues = $repositoryOptions === null ? [] : OptionState::values($repositoryOptions);
        $dynamicResolverValues = [];
        if (isset($repositoryValues['stylesheet'])) {
            $dynamicResolverValues['active_stylesheet'] = (string) $repositoryValues['stylesheet'];
        }

        $document = CaptureTransaction::run(
            $policy,
            static function (DatabaseWorkAuthority $workAuthority) use (
                $capture,
                $forceUnresolvedRefs,
                $repositoryOptions,
                $dynamicResolverValues
            ): array {
                Identity::assert_embedded_unique();
                return $capture->buildOptionsOnly(
                    $forceUnresolvedRefs,
                    $repositoryOptions,
                    $dynamicResolverValues,
                    true,
                    true,
                    $workAuthority,
                    lifecycleHandoffProjection: true
                );
            },
            optionsOnly: true
        );
        $content = Canon::encode($document);
        return [
            'options/core' => [
                'type' => 'options',
                'hash' => hash('sha256', $content),
                'content' => $content,
                'path' => 'options/core.json',
            ],
        ];
    }

    /** Pure artifact lookup kept public for Capture's historical probe seam. */
    public static function repositoryOptions(
        string $repo,
        Policy $policy,
        ?CompiledRepository $compiled
    ): ?array {
        $tree = $compiled !== null
            ? $compiled->tree()
            : RepositoryCompiler::compile_for_diff($repo, Policy::load($repo))->tree();
        $options = $tree['options/core']['data'] ?? null;
        return is_array($options) ? $options : null;
    }

    public static function assertReadOnlyIdentityPrecondition(callable $assertion): void {
        try {
            $assertion();
        } catch (\RuntimeException $failure) {
            throw CommandRefusalException::explainObservationPrecondition($failure);
        }
    }

    /** @return string[] */
    private static function repositoryUserLogins(CompiledRepository $repository): array {
        $logins = [];
        foreach ($repository->tree() as $entity) {
            if (($entity['type'] ?? '') === 'user-meta') {
                $logins[] = (string) ($entity['data']['login'] ?? '');
            }
        }
        return $logins;
    }

    /** @return array<string,array{type:string,hash:string,content:string,path:string}> */
    private static function records(array $entities): array {
        $records = [];
        foreach ($entities as $entity) {
            $records[(string) $entity['uuid']] = [
                'type' => (string) $entity['type'],
                'hash' => hash('sha256', $entity['hash_basis'] ?? $entity['content']),
                'content' => (string) $entity['content'],
                'path' => (string) $entity['path'],
            ];
        }
        return $records;
    }
}
