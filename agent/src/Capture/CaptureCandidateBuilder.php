<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';
require_once __DIR__ . '/../Kernel/BlockAttributeReader.php';
require_once __DIR__ . '/../Kernel/Uuid.php';
require_once __DIR__ . '/CaptureIdentity.php';
require_once __DIR__ . '/CaptureSafetyGates.php';
require_once __DIR__ . '/CaptureTransaction.php';
require_once __DIR__ . '/EntityMetaCapture.php';
require_once __DIR__ . '/MediaCapture.php';
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';
require_once __DIR__ . '/MenuCapture.php';
require_once __DIR__ . '/OptionsCapture.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/PostCapture.php';
require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';
require_once __DIR__ . '/../Policy/ScopeDiscovery.php';
require_once __DIR__ . '/../Repository/SidebarState.php';
require_once __DIR__ . '/../Repository/Snapshot.php';
require_once __DIR__ . '/../Repository/RepositoryValueValidation.php';
require_once __DIR__ . '/TermCapture.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/../Grammar/Blocks.php';
require_once __DIR__ . '/../Grammar/ShortcodeAlternateRegistrar.php';
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
    /** @var array<int,array<string,object|null>> Same-snapshot untracked rows; null means an ambiguous native key. */
    private array $unmappedTerms = [];
    /** @var array{menus_by_term_id:array} */
    private array $planObservations = ['menus_by_term_id' => []];

    /**
     * @param array{home:string,uploads:string}|null $binding observe AS this
     *        environment binding instead of the live one (Tokens' docblock);
     *        plan's foreign-bound comparison observation is the only caller.
     * @param null|\Closure(object):?string $unmappedTermObserver Only a full non-minting plan may compare an unmanaged native row with desired natural identity.
     * @param null|\Closure(int,string):?string $captureIdentityLookup Observation-local reference projection; it never changes apply-direction bindings or the durable ledger.
     * @param null|\Closure(array,?DatabaseWorkAuthority):void $prepareCaptureReferences Bind that lookup from the bounded options namespace inside the lifecycle transaction.
     */
    public function __construct(
        string $repo,
        private Policy $policy,
        ?array $binding = null,
        private readonly ?array $canonicalShortcodeTree = null,
        private readonly ?\Closure $unmappedTermObserver = null,
        ?\Closure $captureIdentityLookup = null,
        ?\Closure $prepareCaptureReferences = null
    ) {
        $this->repo = rtrim($repo, '/');
        $this->tokens = $binding === null
            ? new Tokens(captureIdentityLookup: $captureIdentityLookup)
            : new Tokens((string) $binding['home'], (string) $binding['uploads'], $captureIdentityLookup);
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
                $this->safetyGates->guardPersonalData($section, $key, $value, $rule, $context);
            },
            static function (string $where): void {
                CaptureTransaction::check_transient_db_error($where);
            },
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
                $this->safetyGates->guardPersonalData(
                    'user_meta',
                    $key,
                    $value,
                    $rule,
                    " on exact login '$login'"
                );
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
                $this->safetyGates->guardPersonalData($section, $key, $value, $rule);
            },
            static function (int $id, string $kind, bool $force) use ($policy): ?string {
                return ReferenceScopeClassifier::classify($id, $kind, $force, $policy);
            },
            static function (Policy $policy, string $kind, int $id): bool {
                return Snapshot::row_exists_for_kind($policy, $kind, $id);
            },
            $this->unmappedTermObserver === null ? null : function (int $id, string $taxonomy): ?string {
                $native = $this->unmappedTerms[$id][$taxonomy] ?? null;
                return $native === null ? null : ($this->unmappedTermObserver)($native);
            },
            $prepareCaptureReferences
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

    /**
     * Borrowed input observation is deliberately not an export: a stock
     * category awaiting adoption cannot block an unrelated image recipe.
     * Comparison-only unmanaged consumer ids prevent silent omission while
     * granting no durable identity or publication authority.
     */
    public function buildBlockInputs(array $blockNames, DatabaseWorkAuthority $workAuthority): array {
        if (!array_is_list($blockNames) || $blockNames === [] || count($blockNames) > 4096) {
            throw new \RuntimeException('wprism: block input observation requires an exact bounded block roster');
        }
        foreach ($blockNames as $name) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                throw new \RuntimeException('wprism: block input observation has a malformed block selector');
            }
        }
        if (count(array_unique($blockNames)) !== count($blockNames)) {
            throw new \RuntimeException('wprism: block input observation requires an exact bounded block roster');
        }
        $this->reset(false);
        $posts = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array => $this->scopeDiscovery->posts());
        $identities = [];
        $managed = [];
        foreach ($posts as $post) {
            $id = (int) $post->ID;
            $uuid = DatabaseQueryIsolation::work_unit($workAuthority,
                fn(): ?string => $this->captureIdentity->observePost($id, 'post'));
            if ($uuid !== null) $managed[$id] = $uuid;
            $identities[$id] = $uuid ?? Uuid::v5(Uuid::NAMESPACE_WPRISM, 'unmanaged-block-input:post:' . $id);
        }
        $entities = [];
        $mediaSizes = [];
        $mediaBytes = 0;
        foreach ($posts as $post) {
            $id = (int) $post->ID;
            $isAttachment = $post->post_type === 'attachment';
            if ($isAttachment && !isset($managed[$id])) continue;
            if (!$isAttachment && ($this->policy->body_mode((string) $post->post_type) !== 'blocks'
                || BlockAttributeReader::read((string) $post->post_content, $blockNames) === [])) continue;
            $build = DatabaseQueryIsolation::work_unit($workAuthority,
                fn(): array => $this->postCapture->captureBlockInput($post, $identities[$id]));
            if ($build['media_ref'] !== null) {
                [$name, $source] = $build['media_ref'];
                $size = $source['witness']['size'] ?? null;
                if (!is_int($size) || (isset($mediaSizes[$name]) && $mediaSizes[$name] !== $size)) {
                    throw new \RuntimeException('wprism: block input media lacks a consistent bounded source witness');
                }
                if (!isset($mediaSizes[$name])) $mediaBytes = MediaPayloadAuthority::addToAggregate($mediaBytes, $size);
                $mediaSizes[$name] = $size;
            }
            $entities[] = $build['entity'];
        }
        $references = $this->portableWidgetReferenceScan($posts, $identities, null, $workAuthority)['references'];
        // SidebarState's non-minting comparison path verifies existing maps
        // and retains unmanaged widget markers. Strict export would reject
        // unrelated default widgets; duplicating its assignment parser would
        // lose portable references to selected inactive instances.
        $sidebars = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array => SidebarState::capture(
            $this->policy, $this->tokens, false, false, false, $references === [] ? null : $references,
            $this->canonicalShortcodeTree
        ));
        $entities = [...$entities, ...$sidebars['entities']];
        $this->safetyGates->assertContentReferences($this->tokens);
        return $entities;
    }

    /** Options-only candidate used by lifecycle handoff snapshots. */
    public function buildOptionsOnly(
        bool $forceUnresolvedRefs,
        ?array $previousOptions = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false,
        bool $strictReadOnly = false,
        ?DatabaseWorkAuthority $workAuthority = null,
        bool $lifecycleHandoffProjection = false
    ): array {
        $this->reset($forceUnresolvedRefs);
        if ($this->unmappedTermObserver !== null) {
            throw new \LogicException('wprism: planned references require the full target identity observation');
        }
        $options = $this->buildOptions(
            false,
            $forceUnresolvedRefs,
            $previousOptions,
            $dynamicResolverValues,
            $bindMissingDynamicDesired,
            $strictReadOnly,
            $workAuthority,
            $lifecycleHandoffProjection
        );
        $this->assertOptionGates();
        return $options;
    }

    /**
     * @return array{
     *   entities:array,
     *   media:array<string,array{path?:string,bytes?:string,witness:array{extension:string,sha256:string,size:int}}>,
     *   portable_widget_scan:?array{selected_post_uuids:list<string>,reference_count:int},
     *   notes:string[],
     *   warnings:string[]
     * }
     */
    public function build(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousOptions = null,
        array $carriedUserLogins = [],
        bool $strictReadOnly = false,
        ?array $selectedIdentities = null,
        ?DatabaseWorkAuthority $workAuthority = null
    ): array {
        if ($this->unmappedTermObserver !== null && ($mint || $strictReadOnly)) {
            throw new \LogicException('wprism: planned references cannot mint identity or replace strict export observation');
        }
        $this->reset($forceUnresolvedRefs);
        $entities = [];
        $media = [];
        $mediaBytes = 0;

        $scope = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array => $this->scopeDiscovery->discover(
            $strictReadOnly,
            function (array $gaps): void {
                $this->safetyGates->assertScopeGaps($gaps);
            }
        ));
        $posts = $scope['posts'];
        $terms = $scope['terms'];
        $this->taxonomiesByPostType = $scope['by_post_type'];
        $this->termObjectTaxonomies = $scope['term_object'];

        $postUuids = [];
        foreach ($posts as $post) {
            $uuid = DatabaseQueryIsolation::work_unit($workAuthority, fn(): ?string =>
                $this->captureIdentity->ensurePost((int) $post->ID, 'post', $mint, $strictReadOnly));
            if ($uuid !== null) {
                $postUuids[(int) $post->ID] = $uuid;
            }
        }
        $termUuids = [];
        $this->unmappedTerms = [];
        foreach ($terms as $term) {
            $uuid = DatabaseQueryIsolation::work_unit($workAuthority, fn(): ?string =>
                $this->captureIdentity->ensureTerm($term, 'term', $mint, $strictReadOnly));
            if ($uuid !== null) {
                $termUuids[(int) $term->term_id] = $uuid;
            } elseif ($this->unmappedTermObserver !== null) {
                $id = (int) $term->term_id;
                $taxonomy = (string) $term->taxonomy;
                $this->unmappedTerms[$id][$taxonomy] = array_key_exists($taxonomy, $this->unmappedTerms[$id] ?? [])
                    ? null : $term;
            }
        }

        $menuBuild = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array =>
            $this->menuCapture->capture($mint, $strictReadOnly));
        $menus = $menuBuild['menus'];
        $this->planObservations['menus_by_term_id'] = $menuBuild['observations'];

        foreach ($terms as $term) {
            DatabaseQueryIsolation::work_unit($workAuthority, function () use ($term): void {
                $flatMeta = $this->entityMetaCapture->termMetaMap((int) $term->term_id);
                foreach ($flatMeta as $key => $_) {
                    if ($this->policy->meta_rule_for_term($key, $flatMeta) === null) {
                        $this->unclassified[] = "term_meta:$key (unclassified on taxonomy {$term->taxonomy})";
                    }
                }
            });
        }

        // Table identities must exist before post/sidebar tokenization.
        $tableEntities = Snapshot::capture($this->policy, $this->tokens, $mint, $strictReadOnly, $workAuthority);
        CaptureTransaction::check_transient_db_error('Snapshot::capture()');
        $portableWidgetScan = $this->portableWidgetReferenceScan($posts, $postUuids, $selectedIdentities, $workAuthority);
        $portableWidgetReferences = $portableWidgetScan['references'];
        $sidebarBuild = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array => SidebarState::capture(
            $this->policy,
            $this->tokens,
            $mint,
            $forceUnresolvedRefs,
            $strictReadOnly,
            $portableWidgetReferences === [] ? null : $portableWidgetReferences,
            $this->canonicalShortcodeTree
        ));

        foreach ($terms as $term) {
            $uuid = $termUuids[(int) $term->term_id] ?? null;
            if ($uuid !== null) {
                $entities[] = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array =>
                    $this->termCapture->capture($term, $uuid, $this->termObjectTaxonomies));
            }
        }
        foreach ($posts as $post) {
            $uuid = $postUuids[(int) $post->ID] ?? null;
            if ($uuid === null) {
                continue;
            }
            $postBuild = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array => $this->postCapture->capture(
                $post,
                $uuid,
                $this->taxonomiesByPostType,
                $forceUnresolvedRefs,
                $strictReadOnly
            ));
            if ($postBuild['media_ref'] !== null) {
                $mediaName = $postBuild['media_ref'][0];
                $mediaSource = $postBuild['media_ref'][1];
                if (!isset($media[$mediaName])) {
                    $witness = $mediaSource['witness'] ?? null;
                    if (!is_array($witness) || !is_int($witness['size'] ?? null)) {
                        throw new \RuntimeException('wprism: captured media source lacks its bounded byte witness');
                    }
                    $mediaBytes = MediaPayloadAuthority::addToAggregate($mediaBytes, $witness['size']);
                    $media[$mediaName] = $mediaSource;
                } elseif ($media[$mediaName]['witness'] !== $mediaSource['witness']) {
                    throw new \RuntimeException('wprism: one media content address has inconsistent capture witnesses');
                }
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
            $strictReadOnly,
            $workAuthority
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
        foreach ($this->userMetaCapture->capture($carriedUserLogins, $workAuthority) as $entity) {
            $entities[] = $entity;
        }

        $this->assertOptionGates();
        $this->safetyGates->assertContentReferences($this->tokens);
        $this->safetyGates->assertCanonicalContent($entities, $this->policy);
        RepositoryValueValidation::assert_native_tree($entities, $this->policy);
        return [
            'entities' => $entities,
            'media' => $media,
            'portable_widget_scan' => $selectedIdentities === null ? null : [
                'selected_post_uuids' => $portableWidgetScan['selected_post_uuids'],
                'reference_count' => count($portableWidgetReferences),
            ],
            'notes' => $this->tokens->notes,
            'warnings' => array_merge($sidebarBuild['warnings'], $this->tokens->warnings),
        ];
    }

    /**
     * Discover portable widget owners only in the immutable scoped closure.
     * Null is the ordinary whole-snapshot path; a list comes only from the
     * associated ScopeContract in scoped publication.
     *
     * @param list<object> $posts
     * @param array<int,string> $postUuids
     * @param ?list<string> $selectedIdentities
     * @return array{
     *   references:list<array{type:string,local_id:int}>,
     *   selected_post_uuids:list<string>
     * }
     */
    private function portableWidgetReferenceScan(
        array $posts,
        array $postUuids,
        ?array $selectedIdentities,
        ?DatabaseWorkAuthority $workAuthority
    ): array {
        $selected = null;
        if ($selectedIdentities !== null) {
            if (!array_is_list($selectedIdentities)) {
                throw new \RuntimeException('wprism: scoped widget discovery received a malformed selected identity roster');
            }
            $selected = [];
            foreach ($selectedIdentities as $position => $identity) {
                if (!is_string($identity) || $identity === '' || isset($selected[$identity])) {
                    throw new \RuntimeException(
                        "wprism: scoped widget discovery received a malformed selected identity at position $position"
                    );
                }
                $selected[$identity] = true;
            }
        }

        $referencesByKey = [];
        $scannedPostUuids = [];
        foreach ($posts as $post) {
            $postId = (int) ($post->ID ?? 0);
            $postUuid = $postUuids[$postId] ?? null;
            if (!is_string($postUuid)
                || ($selected !== null && !isset($selected[$postUuid]))
                || $this->policy->body_mode((string) $post->post_type) !== 'blocks') {
                continue;
            }
            $scannedPostUuids[$postUuid] = true;
            $references = DatabaseQueryIsolation::work_unit($workAuthority, fn(): array => Blocks::capture_widget_instance_references(
                (string) ($post->post_content ?? ''),
                $this->policy
            ));
            foreach ($references as $reference) {
                $referencesByKey[$reference['type'] . '-' . $reference['local_id']] = $reference;
            }
        }
        ksort($referencesByKey, SORT_STRING);
        ksort($scannedPostUuids, SORT_STRING);
        return [
            'references' => array_values($referencesByKey),
            'selected_post_uuids' => array_keys($scannedPostUuids),
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
        if ($this->canonicalShortcodeTree !== null) {
            (new ShortcodeAlternateRegistrar($this->policy, $this->tokens))->register(
                $this->canonicalShortcodeTree
            );
        }
    }

    private function buildOptions(
        bool $mint,
        bool $forceUnresolvedRefs = false,
        ?array $previousDocument = null,
        array $dynamicResolverValues = [],
        bool $bindMissingDynamicDesired = false,
        bool $strictReadOnly = false,
        ?DatabaseWorkAuthority $workAuthority = null,
        bool $lifecycleHandoffProjection = false
    ): array {
        $result = $this->optionsCapture->capture(
            $mint,
            $forceUnresolvedRefs,
            $previousDocument,
            $dynamicResolverValues,
            $bindMissingDynamicDesired,
            $strictReadOnly,
            $workAuthority,
            $lifecycleHandoffProjection
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
