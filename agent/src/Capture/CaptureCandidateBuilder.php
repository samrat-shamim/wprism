<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/CaptureIdentity.php';
require_once __DIR__ . '/CaptureSafetyGates.php';
require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/EntityMetaCapture.php';
require_once __DIR__ . '/MediaCapture.php';
require_once __DIR__ . '/MenuCapture.php';
require_once __DIR__ . '/OptionsCapture.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/PostCapture.php';
require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';
require_once __DIR__ . '/../Policy/ScopeDiscovery.php';
require_once __DIR__ . '/../Repository/SidebarState.php';
require_once __DIR__ . '/../Repository/Snapshot.php';
require_once __DIR__ . '/TermCapture.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/UserMetaCapture.php';

/**
 * Builds one canonical candidate from a coherent live database view.
 *
 * Transaction, repository history, deletion inference, staging, and
 * publication remain outside this class. This boundary owns the ordering of
 * live scope discovery, identity observation/minting, entity capture, and the
 * safety findings accumulated across those surfaces.
 */
final class CaptureCandidateBuilder {
    private string $repo;
    private Tokens $tokens;
    private CaptureSafetyGates $safetyGates;
    private CaptureIdentity $captureIdentity;
    private ScopeDiscovery $scopeDiscovery;
    private EntityMetaCapture $entityMetaCapture;
    private UserMetaCapture $userMetaCapture;
    private MediaCapture $mediaCapture;
    private PostCapture $postCapture;
    private TermCapture $termCapture;
    private MenuCapture $menuCapture;
    private OptionsCapture $optionsCapture;

    /** @var string[] */
    private array $unclassified = [];
    /** @var array<int,array{option:string,kind:string,id:int,target_type:string}> */
    private array $unscopedRefs = [];
    /** @var array<int,array{option:string,id_kind:string,id:int}> */
    private array $unscopedOptionNameRefs = [];
    /** @var array<string,string[]> */
    private array $taxonomiesByPostType = [];
    /** @var string[] */
    private array $termObjectTaxonomies = [];
    /** @var array{menus_by_term_id:array} */
    private array $planObservations = ['menus_by_term_id' => []];

    /**
     * @param array{home:string,uploads:string}|null $binding observe AS this
     *        environment binding instead of the live one (Tokens' docblock);
     *        plan's foreign-bound comparison observation is the only caller.
     */
    public function __construct(string $repo, private Policy $policy, ?array $binding = null) {
        $this->repo = rtrim($repo, '/');
        $this->tokens = $binding === null
            ? new Tokens()
            : new Tokens((string) $binding['home'], (string) $binding['uploads']);
        $this->tokens->policy = $policy;
        $this->safetyGates = new CaptureSafetyGates($this->repo);
        $this->captureIdentity = new CaptureIdentity();
        $this->scopeDiscovery = new ScopeDiscovery(
            $policy,
            static function (): void {},
            function (string $warning): void {
                $this->tokens->warnings[] = $warning;
            }
        );
        $this->entityMetaCapture = new EntityMetaCapture(
            $policy,
            $this->tokens,
            function (string $section, string $key, $value, array $rule, string $context): void {
                $this->safetyGates->guardSecret($section, $key, $value, $rule, $context);
            },
            static function (): void {},
            function (string $finding): void {
                $this->unclassified[] = $finding;
            }
        );
        $this->userMetaCapture = new UserMetaCapture(
            $policy,
            $this->tokens,
            function (string $section, string $key, $value, array $rule, string $context): void {
                $this->safetyGates->guardSecret($section, $key, $value, $rule, $context);
            },
            function (string $key, $value, array $rule, string $login): void {
                $this->safetyGates->guardPersonalData($key, $value, $rule, $login);
            },
            static function (string $where): void {
                CaptureTransaction::check_transient_db_error($where);
            }
        );
        $this->mediaCapture = new MediaCapture();
        $this->postCapture = new PostCapture(
            $policy,
            $this->tokens,
            $this->entityMetaCapture,
            $this->mediaCapture
        );
        $this->termCapture = new TermCapture($policy, $this->tokens, $this->entityMetaCapture);
        $this->menuCapture = new MenuCapture(
            $policy,
            $this->tokens,
            fn(object $term, string $type, bool $mint, bool $strictReadOnly): ?string =>
                $this->captureIdentity->ensureTerm($term, $type, $mint, $strictReadOnly),
            fn(int $id, string $type, bool $mint, bool $strictReadOnly): ?string =>
                $this->captureIdentity->ensurePost($id, $type, $mint, $strictReadOnly),
            fn(int $id): array => $this->entityMetaCapture->postMetaMap($id),
            fn(int $id): array => $this->entityMetaCapture->postMetaByKey($id),
            fn(string $key, array $values, array $flatMeta, string $ownerLabel, string $prefix): array =>
                $this->entityMetaCapture->classifyValue(
                    $key,
                    $values,
                    $flatMeta,
                    $ownerLabel,
                    $prefix
                )
        );
        $this->optionsCapture = new OptionsCapture(
            $policy,
            $this->tokens,
            function (string $section, string $key, $value, array $rule): void {
                $this->safetyGates->guardSecret($section, $key, $value, $rule);
            },
            static function (int $id, string $kind, bool $force) use ($policy): ?string {
                return ReferenceScopeClassifier::classify($id, $kind, $force, $policy);
            },
            static function (Policy $policy, string $kind, int $id): bool {
                return Snapshot::row_exists_for_kind($policy, $kind, $id);
            }
        );
    }

    public function repo(): string {
        return $this->repo;
    }

    public function policy(): Policy {
        return $this->policy;
    }

    /** @return array{menus_by_term_id:array} */
    public function planObservations(): array {
        return $this->planObservations;
    }

    /** Options-only candidate used by lifecycle handoff snapshots. */
    public function buildOptionsOnly(
        bool $forceUnresolvedRefs,
        ?array $previousOptions = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false
    ): array {
        $this->reset($forceUnresolvedRefs);
        $options = $this->buildOptions(
            false,
            $forceUnresolvedRefs,
            $previousOptions,
            $dynamicResolverValues,
            $bindMissingDynamicDesired
        );
        $this->assertOptionGates();
        return $options;
    }

    /**
     * @return array{
     *   entities:array,
     *   media:array<string,array{path?:string,bytes?:string}>,
     *   notes:string[],
     *   warnings:string[]
     * }
     */
    public function build(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousOptions = null,
        array $carriedUserLogins = [],
        bool $strictReadOnly = false
    ): array {
        $this->reset($forceUnresolvedRefs);
        $entities = [];
        $media = [];

        $scope = $this->scopeDiscovery->discover(
            $strictReadOnly,
            function (array $gaps): void {
                $this->safetyGates->assertScopeGaps($gaps);
            }
        );
        $posts = $scope['posts'];
        $terms = $scope['terms'];
        $this->taxonomiesByPostType = $scope['by_post_type'];
        $this->termObjectTaxonomies = $scope['term_object'];

        $postUuids = [];
        foreach ($posts as $post) {
            $uuid = $this->captureIdentity->ensurePost((int) $post->ID, 'post', $mint, $strictReadOnly);
            if ($uuid !== null) {
                $postUuids[(int) $post->ID] = $uuid;
            }
        }
        $termUuids = [];
        foreach ($terms as $term) {
            $uuid = $this->captureIdentity->ensureTerm($term, 'term', $mint, $strictReadOnly);
            if ($uuid !== null) {
                $termUuids[(int) $term->term_id] = $uuid;
            }
        }

        $menuBuild = $this->menuCapture->capture($mint, $strictReadOnly);
        $menus = $menuBuild['menus'];
        $this->planObservations['menus_by_term_id'] = $menuBuild['observations'];

        foreach ($terms as $term) {
            $flatMeta = $this->entityMetaCapture->termMetaMap((int) $term->term_id);
            foreach ($flatMeta as $key => $_) {
                if ($this->policy->meta_rule_for_term($key, $flatMeta) === null) {
                    $this->unclassified[] = "term_meta:$key (unclassified on taxonomy {$term->taxonomy})";
                }
            }
        }

        // Table identities must exist before post/sidebar tokenization.
        $tableEntities = Snapshot::capture($this->policy, $this->tokens, $mint, $strictReadOnly);
        CaptureTransaction::check_transient_db_error('Snapshot::capture()');
        $sidebarBuild = SidebarState::capture(
            $this->policy,
            $this->tokens,
            $mint,
            $forceUnresolvedRefs,
            $strictReadOnly
        );

        foreach ($terms as $term) {
            $uuid = $termUuids[(int) $term->term_id] ?? null;
            if ($uuid !== null) {
                $entities[] = $this->termCapture->capture($term, $uuid, $this->termObjectTaxonomies);
            }
        }
        foreach ($posts as $post) {
            $uuid = $postUuids[(int) $post->ID] ?? null;
            if ($uuid === null) {
                continue;
            }
            $postBuild = $this->postCapture->capture(
                $post,
                $uuid,
                $this->taxonomiesByPostType,
                $forceUnresolvedRefs,
                $strictReadOnly
            );
            if ($postBuild['media_ref'] !== null) {
                $media[$postBuild['media_ref'][0]] = $postBuild['media_ref'][1];
            }
            $entities[] = $postBuild['entity'];
        }
        foreach ($menus as $menu) {
            $entities[] = [
                'uuid' => $menu['uuid'],
                'type' => 'menu',
                'path' => "menus/{$menu['slug']}.json",
                'content' => Canon::encode($menu['front']),
            ];
        }
        foreach ($sidebarBuild['entities'] as $entity) {
            $entities[] = $entity;
        }

        $options = $this->buildOptions(
            $mint,
            $forceUnresolvedRefs,
            $previousOptions,
            [],
            false,
            $strictReadOnly
        );
        $entities[] = [
            'uuid' => 'options/core',
            'type' => 'options',
            'path' => 'options/core.json',
            'content' => Canon::encode($options),
        ];
        foreach ($tableEntities as $entity) {
            $entities[] = $entity;
        }
        foreach ($this->userMetaCapture->capture($carriedUserLogins) as $entity) {
            $entities[] = $entity;
        }

        $this->assertOptionGates();
        $this->safetyGates->assertContentReferences($this->tokens);
        return [
            'entities' => $entities,
            'media' => $media,
            'notes' => $this->tokens->notes,
            'warnings' => array_merge($sidebarBuild['warnings'], $this->tokens->warnings),
        ];
    }

    /** Direct seam retained for narrow tests of menu observation semantics. */
    public function captureMenus(bool $mint, bool $strictReadOnly = false): array {
        $result = $this->menuCapture->capture($mint, $strictReadOnly);
        $this->planObservations['menus_by_term_id'] = $result['observations'];
        return $result['menus'];
    }

    private function reset(bool $forceUnresolvedRefs): void {
        $this->unclassified = [];
        $this->unscopedRefs = [];
        $this->unscopedOptionNameRefs = [];
        $this->planObservations = ['menus_by_term_id' => []];
        $this->tokens->unscopedBlockRefs = [];
        $this->tokens->unscopedShortcodeRefs = [];
        $this->tokens->unscopedUrlQueryRefs = [];
        $this->tokens->forceUnresolvedRefs = $forceUnresolvedRefs;
    }

    private function buildOptions(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousDocument = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false,
        bool $strictReadOnly = false
    ): array {
        $result = $this->optionsCapture->capture(
            $mint,
            $forceUnresolvedRefs,
            $previousDocument,
            $dynamicResolverValues,
            $bindMissingDynamicDesired,
            $strictReadOnly
        );
        $this->unclassified = array_merge($this->unclassified, $result['unclassified']);
        $this->unscopedRefs = array_merge($this->unscopedRefs, $result['unscoped_refs']);
        $this->unscopedOptionNameRefs = array_merge(
            $this->unscopedOptionNameRefs,
            $result['unscoped_option_name_refs']
        );
        return $result['document'];
    }

    private function assertOptionGates(): void {
        $this->safetyGates->assertOptions(
            $this->unclassified,
            $this->unscopedRefs,
            $this->unscopedOptionNameRefs,
            $this->tokens
        );
    }
}
