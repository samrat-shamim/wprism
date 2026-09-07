<?php
namespace WPrism;

require_once __DIR__ . '/../Adapter/AdapterSources.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/../Code/CodeSourceLock.php';
require_once __DIR__ . '/InitAttemptJournal.php';
require_once __DIR__ . '/InitCodeInventory.php';
require_once __DIR__ . '/InitOwnedArtifacts.php';
require_once __DIR__ . '/InitProtocol.php';
require_once __DIR__ . '/InitRecovery.php';
require_once __DIR__ . '/InitRepositoryBoundary.php';
require_once __DIR__ . '/InitSiteProbe.php';
require_once __DIR__ . '/../Policy/ManifestDispositions.php';
require_once __DIR__ . '/../Policy/AdapterLibrary.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Policy/ScopeAdoption.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../Repository/SidebarState.php';

/**
 * Builds the read-only, content-addressed initialization proposal.
 */
final class InitPlanner {
    public const FORMAT = InitProtocol::PLAN_FORMAT;

    /**
     * The operator's explicit decision to initialize a site whose active
     * plugins WPrism does not manage (round-3 T6 §3.4).
     *
     * It is a REVIEWED decision rather than a bypass, and the shape enforces
     * that: an unmanaged plugin moves from `unsupported` to `advisories`, it
     * is still named with its own reason code on every proposal, none of its
     * state is selected or written, and the flag rides inside the digest —
     * so a confirmation that omits it recomputes a different proposal and is
     * refused by assert_confirmed_proposal() rather than silently applying a
     * decision the operator did not review.
     *
     * It relaxes exactly `active_plugin_without_adapter`. `ambiguous_plugin_
     * adapter` (two manifests answering for one plugin) is not the same fact:
     * there IS an adapter, the engine cannot say which, and leaving it
     * unmanaged is not the remedy.
     */
    public const ALLOW_UNMANAGED_PLUGINS = 'allow-unmanaged-plugins';

    /**
     * The host's per-component code classification, carried as one base64
     * canonical-JSON argument (issue #3499).
     *
     * It rides inside the proposal exactly as ALLOW_UNMANAGED_PLUGINS does and
     * for the same reason: the classification changes what the repository will
     * declare and what Git will carry, so it is a REVIEWED decision. It is
     * folded into `code.split`, `code.split` is inside the digest, and a
     * confirmation that supplies a different classification therefore
     * recomputes a different digest and is refused by
     * assert_confirmed_proposal() rather than silently applying a split the
     * operator never saw.
     *
     * The agent never produces this itself. Classification requires comparing
     * an installed tree against a release archive, and a target that fetched
     * one would falsify the `code_release_provider` probe attestation
     * "off-target build and dependency resolution … no target Git history or
     * registry credentials" (docs/code-release-runtime.md:24-28). What the
     * agent does is verify: every classified component must be one this probe
     * actually found, at the same version and the same tree digest.
     */
    public const CODE_LOCK_ARGUMENT = 'code-lock-b64';

    /** @param ?list<array<string,mixed>> $lockPlan @return array<string,mixed> */
    public static function proposal(
        string $repo,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        $logicalRepo = InitRepositoryBoundary::normalize($repo);
        $rootBlocker = InitRepositoryBoundary::root_blocker($logicalRepo);
        if ($rootBlocker !== null) {
            return self::blocked_repository_proposal($logicalRepo, $rootBlocker);
        }
        $binding = InitRepositoryBoundary::bind($logicalRepo);
        try {
            return self::proposal_bound(
                '.',
                $logicalRepo,
                $binding['identity'],
                false,
                false,
                $allowUnmanagedPlugins,
                $lockPlan,
                $adapterLibrary
            );
        } finally {
            if (!@chdir($binding['previous_cwd'])) {
                throw new \RuntimeException('wprism: init could not restore its process working directory after proposal');
            }
        }
    }

    /** @param ?list<array<string,mixed>> $lockPlan @return array<string,mixed> */
    public static function proposal_bound(
        string $repo,
        string $logicalRepo,
        string $rootIdentity,
        bool $ownsCaptureLock = false,
        bool $ownsInitAttempt = false,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('wprism: init requires a loaded WordPress database connection');
        }

        $attempt = InitAttemptJournal::read($repo);
        if (!$ownsInitAttempt && $attempt !== null) {
            return InitRecovery::interrupted_attempt_proposal($attempt, $repo, $logicalRepo, $rootIdentity);
        }

        $git = InitRepositoryBoundary::git_probe($repo);
        $ledger = InitSiteProbe::ledger();
        $existing = self::existing_config($repo, $adapterLibrary);
        $gitignoreIdentity = InitOwnedArtifacts::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore');
        $gitattributesIdentity = InitOwnedArtifacts::owned_file_boundary_identity(
            $repo . '/.gitattributes',
            '.gitattributes'
        );
        $manifests = self::installed_manifests($repo, $adapterLibrary);
        $activePlugins = array_values(array_filter(
            (array) get_option('active_plugins', []),
            static fn($value): bool => is_string($value) && $value !== ''
        ));

        $selected = ['core'];
        $unsupported = [];
        $advisories = [[
            'code' => 'repository_external_writer_exclusion',
            'extension' => $logicalRepo,
            'kind' => 'repository',
            'reason' => 'the init lease and capture lock serialize WPrism writers only; first publication requires every non-WPrism writer to remain quiescent across the repository namespace',
            'remediation' => 'pause package managers, self-updaters, Git or shell automation, and any process that can write .git, code, media, state, or state.capture* until init or retained recovery finishes',
        ]];
        $byPlugin = [];
        $byTheme = [];
        foreach ($manifests as $name => $manifest) {
            if (is_string($manifest['plugin'] ?? null) && $manifest['plugin'] !== '') {
                $byPlugin[(string) $manifest['plugin']][] = $name;
            }
            if (is_string($manifest['theme'] ?? null) && $manifest['theme'] !== '') {
                $byTheme[(string) $manifest['theme']][] = $name;
            }
        }
        $plugins = self::plugin_selection($activePlugins, $byPlugin, $allowUnmanagedPlugins);
        $selected = array_merge($selected, $plugins['selected']);
        $unsupported = array_merge($unsupported, $plugins['unsupported']);
        $advisories = array_merge($advisories, $plugins['advisories']);

        $template = (string) get_option('template', '');
        $stylesheet = (string) get_option('stylesheet', '');
        foreach (array_values(array_unique(array_filter([$template, $stylesheet]))) as $theme) {
            $owners = $byTheme[$theme] ?? [];
            if (count($owners) > 1) {
                $unsupported[] = [
                    'code' => 'ambiguous_theme_adapter',
                    'extension' => $theme,
                    'kind' => 'theme',
                    'reason' => 'multiple installed manifests declare this active theme identity',
                    'remediation' => 'retain one versioned theme adapter, then rerun wprism init',
                ];
            } elseif (count($owners) === 1) {
                $selected[] = $owners[0];
            } else {
                $advisories[] = [
                    'code' => 'active_theme_code_only',
                    'extension' => $theme,
                    'kind' => 'theme',
                    'reason' => 'theme bytes will be inventoried as code, but no theme-owned authored-state adapter is selected',
                    'remediation' => 'install a certified theme adapter if theme-specific authored state must be branchable',
                ];
            }
        }

        if (!function_exists('get_mu_plugins') || !function_exists('get_dropins')) {
            $pluginApi = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';
            if ($pluginApi !== '' && is_file($pluginApi)) {
                require_once $pluginApi;
            }
        }
        $muPlugins = function_exists('get_mu_plugins') ? array_keys(get_mu_plugins()) : [];
        $userMuPlugins = array_values(array_filter($muPlugins, static function ($name): bool {
            return is_string($name) && $name !== 'wprism-loader.php';
        }));
        foreach ($userMuPlugins as $plugin) {
            $unsupported[] = [
                'code' => 'mu_plugin_requires_review',
                'extension' => $plugin,
                'kind' => 'mu-plugin',
                'reason' => 'must-use plugin execution is active but no portable authored-state contract can be inferred',
                'remediation' => 'review its state surfaces and install a manifest/provider before initialization',
            ];
        }
        $dropins = function_exists('get_dropins') ? array_keys(get_dropins()) : [];
        foreach ($dropins as $dropin) {
            $unsupported[] = [
                'code' => 'dropin_requires_review',
                'extension' => (string) $dropin,
                'kind' => 'drop-in',
                'reason' => 'WordPress drop-in behavior is active and cannot be classified from its filename',
                'remediation' => 'review the drop-in boundary before initialization',
            ];
        }
        $code = InitCodeInventory::probe($activePlugins, $template, $stylesheet);
        $unsupported = array_merge($unsupported, $code['blockers']);
        $advisories = array_merge($advisories, $code['advisories']);
        unset($code['blockers'], $code['advisories']);
        [$code, $codeBlockers] = self::code_split($code, $lockPlan);
        $unsupported = array_merge($unsupported, $codeBlockers);

        $selected = array_values(array_unique($selected));
        sort($selected, SORT_STRING);
        if ($selected[0] !== 'core') {
            $selected = array_values(array_unique(array_merge(['core'], $selected)));
        }
        // The proposal has no site.wprism.json yet, but its installed adapter
        // source is already repository-owned. Resolve the selected manifests
        // once to obtain their canonical digests/provenance, then reload the
        // exact pins that init will publish. This second verification is what
        // elevates a signed site adapter: name-only discovery must never grant
        // authority, while a reviewed proposal must not remain permanently
        // "signed_unpinned" merely because the config does not exist yet.
        [$policy, $pins] = self::load_selected_policy($selected, $repo, $adapterLibrary);
        $capabilities = $policy->capability_report(['operation' => 'capture']);
        foreach ($capabilities['blockers'] ?? [] as $blocker) {
            $unsupported[] = self::capability_blocker_row(is_array($blocker) ? $blocker : []);
        }

        $adapterScope = self::adapter_scope($selected, $manifests);
        $postTypes = array_merge(['attachment', 'page', 'post'], $adapterScope['post_types']);
        $taxonomies = array_merge(['category', 'post_tag'], $adapterScope['taxonomies']);
        // A block theme keeps its site-editor customisations in core's FSE
        // post types (wp_template, wp_template_part, wp_navigation, wp_block)
        // and taxonomies (wp_theme, wp_template_part_area, wp_pattern_category).
        // They are core-registered, non-public and _builtin, so the scope
        // gate never names them — a proposal that left them out would let a
        // customised footer stay behind SILENTLY, which T7 grind A2 exists to
        // catch. The certified core FSE profile (the `fse` row in
        // manifests/dispositions/profiles.json) declares exactly that scope:
        // propose it whenever the
        // active theme is a block theme, and say so; when the profile is not
        // certified or the registry is unreadable, say that instead and leave
        // the types to `wprism classify`.
        //
        // $manifests is passed because the profile's `manifest` is resolved
        // against the registry's own declared names and never against the
        // directory: a reviewed entry that outlived its manifest keeps the
        // profile valid, and nothing on the load path notices since coverage
        // stopped being a whole-directory check. These are the adapters this
        // site actually installed, which is the set a proposal may be built
        // from.
        $fse = self::fse_profile_scope(null, null, array_keys($manifests), $policy);
        if ($fse !== null) {
            if ($fse['scope'] !== null) {
                $postTypes = array_merge($postTypes, $fse['scope']['post_types']);
                $taxonomies = array_merge($taxonomies, $fse['scope']['taxonomies']);
            }
            $advisories[] = $fse['advisory'];
        }
        $postTypes = array_values(array_unique($postTypes));
        $taxonomies = array_values(array_unique($taxonomies));
        sort($postTypes, SORT_STRING);
        sort($taxonomies, SORT_STRING);

        // The same decision, carried through to the types those plugins
        // register. Init's own confirmation runs the baseline capture, and
        // capture's scope gate refuses any plugin-registered type with rows
        // that no rule names — so "leave the plugin unmanaged" has to mean
        // "its types stay local" or init cannot finish (grind_adapter_walk.sh
        // S1: `[incomplete_policy_scope] … scope:post_type:wpforms` inside
        // `wprism init --allow-unmanaged-plugins --yes`). Each such type gets a
        // reviewed-looking scope rule of class runtime — the exact rule
        // `wprism classify` would write — and is printed as an advisory so the
        // operator sees what was left local; `wprism classify` re-decides it in
        // one line when an adapter arrives.
        // T7 grind A6 widened this from "when unmanaged plugins are present"
        // to always: an ADAPTER-owned plugin can register a rowful type its
        // adapter deliberately leaves to the site (Elementor's
        // elementor_library — manifests/elementor.json says the site opts
        // it in), and init refusing incomplete_policy_scope at confirmation
        // gave the operator no way to adopt at all. Left local and printed,
        // the decision is one `wprism classify` line, exactly as for a type an
        // unmanaged plugin registers.
        $scope = ['post_type' => [], 'taxonomy' => []];
        {
            $declaredPostTypes = [];
            $declaredTaxonomies = [];
            foreach ($selected as $name) {
                $manifest = $manifests[$name] ?? null;
                if (!is_array($manifest)) {
                    continue;
                }
                $declaredPostTypes = array_merge($declaredPostTypes, array_keys((array) ($manifest['post_types'] ?? [])));
                $declaredTaxonomies = array_merge($declaredTaxonomies, array_keys((array) ($manifest['taxonomies'] ?? [])));
            }
            $postCounts = [];
            foreach ($wpdb->get_results(
                "SELECT post_type, COUNT(*) AS entities FROM {$wpdb->posts}
                 WHERE (post_status IN ('publish','draft','pending','private','future')
                        OR (post_type = 'attachment' AND post_status = 'inherit'))
                 GROUP BY post_type",
                ARRAY_A
            ) ?: [] as $row) {
                $postCounts[(string) $row['post_type']] = (int) $row['entities'];
            }
            $taxCounts = [];
            foreach ($wpdb->get_results(
                "SELECT taxonomy, COUNT(*) AS entities FROM {$wpdb->term_taxonomy} GROUP BY taxonomy",
                ARRAY_A
            ) ?: [] as $row) {
                $taxCounts[(string) $row['taxonomy']] = (int) $row['entities'];
            }
            $unmanagedScope = self::unmanaged_scope(
                array_values(array_unique(array_merge(
                    array_values(get_post_types(['public' => true], 'names')),
                    array_values(get_post_types(['_builtin' => false], 'names'))
                ))),
                array_values(array_unique(array_merge(
                    array_values(get_taxonomies(['public' => true], 'names')),
                    array_values(get_taxonomies(['_builtin' => false], 'names'))
                ))),
                $postTypes,
                $taxonomies,
                array_map('strval', $declaredPostTypes),
                array_map('strval', $declaredTaxonomies),
                $postCounts,
                $taxCounts
            );
            $scope = $unmanagedScope['scope'];
            $advisories = array_merge($advisories, $unmanagedScope['advisories']);
        }

        $widgets = self::unmanaged_widgets($policy);
        $advisories = array_merge($advisories, $widgets['advisories']);
        $unsupported = array_merge($unsupported, $widgets['blockers']);

        $resolved = RepositoryCompiler::resolved_adapters($policy);
        $adapterRows = [];
        foreach ($resolved as $row) {
            $adapterRows[] = [
                'digest' => (string) $row['digest'],
                'name' => (string) $row['name'],
                'plugin' => $row['plugin'] ?? null,
                'theme' => $row['theme'] ?? null,
                'status' => (string) (($row['capability']['status'] ?? null) ?: 'unsupported'),
            ];
        }

        $config = [
            // The declaration the classification produced: format 2 naming
            // the lock (components Git does not carry + first_party it does)
            // for every classified site; format 1 only for a site with no
            // lockable component at all, where an empty payload has nothing
            // to declare (issue #3499, then the no-third-party-bytes invariant).
            'code' => $code['declaration'],
            'manifests' => $pins,
            'policy' => [
                'options' => $widgets['options'] === [] ? new \stdClass() : $widgets['options'],
                'post_meta' => new \stdClass(),
                'post_types' => $postTypes,
                'taxonomies' => $taxonomies,
                'term_meta' => new \stdClass(),
            ],
            'spec_version' => WPRISM_SPEC_VERSION,
        ];
        if ($scope['post_type'] !== [] || $scope['taxonomy'] !== []) {
            $config['policy']['scope'] = array_filter([
                'post_type' => $scope['post_type'] !== [] ? $scope['post_type'] : null,
                'taxonomy' => $scope['taxonomy'] !== [] ? $scope['taxonomy'] : null,
            ]);
        }

        $dbVersion = (string) $wpdb->get_var('SELECT VERSION()');
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('wprism: init database probe failed: ' . $wpdb->last_error);
        }
        $media = InitSiteProbe::media();
        $gitLfs = InitRepositoryBoundary::git_lfs_probe(
            $repo,
            (int) ($media['attachments'] ?? 0) > 0,
            (string) $git['mode']
        );
        $unsupported = array_merge($unsupported, $gitLfs['blockers']);
        unset($gitLfs['blockers']);
        $risks = InitSiteProbe::risk();
        if (!empty($media['unavailable'])) {
            $unsupported[] = [
                'code' => 'attachment_bytes_unavailable',
                'extension' => 'media',
                'kind' => 'media',
                'reason' => $media['unavailable'] . ' attachment(s) have neither readable local bytes nor a valid provider source',
                'remediation' => 'install an offload provider for wprism_attachment_capture_source or restore the originals',
            ];
        }
        if (function_exists('is_multisite') && is_multisite()) {
            $unsupported[] = [
                'code' => 'multisite_unsupported',
                'extension' => 'wordpress',
                'kind' => 'platform',
                'reason' => 'the current repository format is single-site only',
                'remediation' => 'initialize a supported single-site target',
            ];
        }
        if ($existing['mode'] === 'unsafe') {
            $unsupported[] = [
                'code' => 'unsafe_site_config',
                'extension' => 'site.wprism.json',
                'kind' => 'repository',
                'reason' => 'site.wprism.json is present but is not an ordinary repository-owned regular file',
                'remediation' => 'replace the link or special file with an ordinary adoption seed, or remove it',
            ];
        } elseif ($existing['mode'] === 'owned') {
            $unsupported[] = [
                'code' => 'existing_configuration',
                'extension' => 'site.wprism.json',
                'kind' => 'repository',
                'reason' => 'the repository already has a non-seed WPrism configuration',
                'remediation' => 'use ordinary capture/plan workflows or move the existing repository before initialization',
            ];
        }
        $codeRoot = $repo . '/code';
        if (is_link($codeRoot) || (file_exists($codeRoot) && !is_dir($codeRoot))) {
            $unsupported[] = [
                'code' => 'unsafe_code_root',
                'extension' => 'code',
                'kind' => 'repository',
                'reason' => 'the code publication root is present but is not an ordinary repository-owned directory',
                'remediation' => 'remove the link or special file before initialization',
            ];
        } elseif (is_dir($codeRoot)) {
            $unsupported[] = [
                'code' => 'existing_code_payload',
                'extension' => 'code',
                'kind' => 'repository',
                'reason' => 'the repository already contains a code root that init does not own',
                'remediation' => 'review or move the existing code payload before initialization',
            ];
        }
        foreach (['state' => 'existing_state_payload', 'media' => 'existing_media_payload'] as $path => $codeName) {
            if (file_exists($repo . '/' . $path) || is_link($repo . '/' . $path)) {
                $unsupported[] = [
                    'code' => $codeName,
                    'extension' => $path,
                    'kind' => 'repository',
                    'reason' => "the repository already contains a $path payload that init does not own",
                    'remediation' => "review or move the existing $path payload before initialization",
                ];
            }
        }
        $captureReceipt = $repo . '/state.capture-receipt';
        if (file_exists($captureReceipt) || is_link($captureReceipt)) {
            $unsupported[] = [
                'code' => 'existing_capture_receipt',
                'extension' => 'state.capture-receipt',
                'kind' => 'repository',
                'reason' => 'the repository already contains durable capture audit evidence that init does not own',
                'remediation' => 'preserve and review the receipt with its original state/ledger history; initialize a genuinely empty repository instead',
            ];
        }
        $captureLock = $repo . '/state.capture.lock';
        if (!$ownsCaptureLock && (file_exists($captureLock) || is_link($captureLock))) {
            $unsupported[] = [
                'code' => 'existing_capture_lock',
                'extension' => 'state.capture.lock',
                'kind' => 'repository',
                'reason' => 'the repository already contains a capture-lock boundary that first init does not own',
                'remediation' => 'verify no publisher uses the repository, preserve any forensic bytes, and initialize an empty repository',
            ];
        }
        if ($ledger['rows'] > 0) {
            $unsupported[] = [
                'code' => 'existing_wprism_ledger',
                'extension' => 'wordpress-database',
                'kind' => 'repository',
                'reason' => 'WPrism ledger rows already exist, so this is not an uninitialized environment',
                'remediation' => 'use ordinary recovery/capture workflows or explicitly remove the abandoned baseline after review',
            ];
        }
        // issue #3497: a site booted with WPRISM_JOURNAL on arrives here with journal
        // rows and no identity at all, which InitSiteProbe::ledger() now counts
        // apart from `rows` above. Init proceeds and preserves them — nothing
        // on the init or baseline-capture path writes wprism_journal (the only
        // statement in the shipped runtime that removes journal rows is
        // Cli::journal_reset's TRUNCATE, agent/src/Command/Cli.php:1989, which
        // regress_init_contract.php pins as a tree-wide invariant) — so the
        // operator is told the evidence is there rather than left to discover
        // an empty `wprism pending` after destroying it.
        // Deliberately count-free: `advisories` is inside the digest that binds
        // proposal to confirmation, and a number that moves with ordinary
        // traffic would refuse every confirmation on a journaling site.
        if ($ledger['observations'] > 0) {
            $advisories[] = [
                'code' => 'retained_journal_observations',
                'extension' => 'wordpress-database',
                'kind' => 'repository',
                'reason' => 'the provenance journal already holds observations recorded before initialization; they are runtime evidence, not baseline identity, and init preserves them',
                'remediation' => 'read them with wp wprism journal-report and expect them in the post-init wprism pending review queue; wp wprism journal-reset would destroy the only record of writes no adapter declares',
            ];
        }
        foreach ($git['blockers'] as $blocker) {
            $unsupported[] = $blocker;
        }

        usort($unsupported, static function (array $a, array $b): int {
            return [$a['kind'], $a['extension'], $a['code']] <=> [$b['kind'], $b['extension'], $b['code']];
        });
        usort($advisories, static function (array $a, array $b): int {
            return [$a['kind'], $a['extension'], $a['code']] <=> [$b['kind'], $b['extension'], $b['code']];
        });
        $proposal = [
            'format' => self::FORMAT,
            'advisories' => $advisories,
            'environment' => [
                'database' => ['access' => 'verified-read', 'server' => $dbVersion],
                'home' => (string) get_option('home', ''),
                'php' => PHP_VERSION,
                'wordpress' => (string) get_bloginfo('version'),
            ],
            'code' => $code,
            'state' => [
                'adapters' => $adapterRows,
                'baseline' => 'capture-consistent-snapshot',
                'config' => $config,
                'config_identity' => $existing['identity'],
                'existing_config' => $existing['mode'],
                'media' => $media,
                'repository' => $logicalRepo,
                'repository_identity' => $rootIdentity,
                'git' => ['mode' => $git['mode'], 'version' => $git['version']],
                'git_lfs' => $gitLfs,
                'gitattributes_identity' => $gitattributesIdentity,
                'gitignore_identity' => $gitignoreIdentity,
                'ledger' => ['rows' => $ledger['rows'], 'tables' => $ledger['tables']],
                'risk_surfaces' => $risks,
            ],
            'unsupported' => $unsupported,
            'ready' => $unsupported === [],
        ];
        $proposal['digest'] = hash('sha256', Canon::encode($proposal));
        return $proposal;
    }

    /**
     * Fold the host's classification into the code proposal (issue #3499).
     *
     * The agent's job here is verification, not decision. Every classified
     * component must be one this run actually inventoried, at the same version
     * and the same `tree_sha256`; the plan must be TOTAL over the inventory, so
     * a component cannot be quietly left unclassified and land in Git by
     * omission; and every locked entry must satisfy the lock grammar before it
     * reaches the proposal, so a confirmation can never be asked to write a
     * lock its own reader would refuse.
     *
     * Three classifications, and only three: `locked` (Git does not carry it;
     * the origin says where its bytes come from), `first-party` (Git carries
     * it because the operator declared the code the site's own), and
     * `unsourced` (neither — no verified release, no imported archive, no
     * first-party declaration). The last is a BLOCKER, not a fallback: it
     * becomes an `unsupported` row (`code_component_unsourced`) naming the
     * component and both remedies, so the proposal reads "blocked" and the
     * operator chooses rather than Git quietly receiving third-party bytes.
     * That is the whole of "Git never carries third-party code" at init time;
     * CodeDescriptorCompiler::lock_diagnostics() holds the same line at every
     * compile afterwards.
     *
     * A protocol violation (an unknown classification, a component this site
     * does not have, a stale digest) throws rather than becoming an
     * `unsupported` row: those are host errors, not conditions of the site
     * being adopted.
     *
     * @param array<string,mixed> $code
     * @param ?list<array<string,mixed>> $lockPlan
     * @return array{0:array<string,mixed>,1:list<array<string,string>>} the code proposal and its blockers
     */
    private static function code_split(array $code, ?array $lockPlan): array {
        // Always present, so the proposal shape does not depend on the mode.
        // An empty split means the host classified nothing, which the host
        // does only for a site with no lockable component at all (the
        // inventory is empty, so there is nothing Git could carry by
        // omission); the declaration then stays format 1 over an empty payload.
        $code['split'] = [];
        if ($lockPlan === null) {
            return [$code, []];
        }
        $inventory = [];
        foreach ((array) ($code['component_inventory'] ?? []) as $row) {
            $inventory[$row['root'] . '/' . $row['component']] = $row;
        }
        $rows = [];
        $lock = [];
        $firstParty = [];
        $blockers = [];
        $seen = [];
        foreach ($lockPlan as $i => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \RuntimeException("wprism: init code classification [$i] must be an object");
            }
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            $classification = $entry['classification'] ?? null;
            $expectedKeys = $classification === 'locked'
                ? ['classification', 'component', 'origin', 'reason', 'root', 'tree_sha256', 'version']
                : ['classification', 'component', 'reason', 'root', 'tree_sha256', 'version'];
            if (!in_array($classification, ['locked', 'first-party', 'unsourced'], true)) {
                throw new \RuntimeException(
                    "wprism: init code classification [$i] must be locked, first-party or unsourced"
                );
            }
            if ($keys !== $expectedKeys) {
                throw new \RuntimeException(
                    "wprism: init code classification [$i] must contain exactly " . implode(', ', $expectedKeys)
                );
            }
            if (!is_string($entry['reason']) || trim($entry['reason']) === ''
                || preg_match('/[\x00-\x1f\x7f]/', $entry['reason']) === 1) {
                throw new \RuntimeException(
                    "wprism: init code classification [$i] must state a single-line reason; a classification nobody can read is not reviewed"
                );
            }
            $key = (string) $entry['root'] . '/' . (string) $entry['component'];
            $probed = $inventory[$key] ?? null;
            if ($probed === null) {
                throw new \RuntimeException(
                    "wprism: init code classification names '$key', which is not an active component of this site"
                );
            }
            if (isset($seen[$key])) {
                throw new \RuntimeException("wprism: init code classification names '$key' more than once");
            }
            $seen[$key] = true;
            // The host classified a specific set of bytes. If the site moved
            // between the probe and the classification, the decision under
            // review is about a tree that no longer exists.
            if ((string) $entry['version'] !== (string) $probed['version']
                || (string) $entry['tree_sha256'] !== (string) $probed['tree_sha256']) {
                throw new \RuntimeException(
                    "wprism: init code classification for '$key' describes a different version or tree digest than this site has; "
                    . 'rerun wprism init so the classification is made against the current bytes'
                );
            }
            $rows[] = $entry;
            if ($classification === 'locked') {
                $lock[] = [
                    'root' => $entry['root'],
                    'component' => $entry['component'],
                    'version' => $entry['version'],
                    'origin' => $entry['origin'],
                    'tree_sha256' => $entry['tree_sha256'],
                ];
            } elseif ($classification === 'first-party') {
                $firstParty[] = $key;
            } else {
                $blockers[] = [
                    'code' => 'code_component_unsourced',
                    'extension' => $key,
                    'kind' => 'code',
                    'reason' => 'the host could not source this component: ' . (string) $entry['reason']
                        . '. Git must not carry third-party code, so it cannot be vendored by default',
                    'remediation' => 'import its release archive on the host with `wprism code-import <archive.zip>` '
                        . 'and rerun wprism init, or declare it the site\'s own code with `wprism init --first-party='
                        . $key . '`',
                ];
            }
        }
        if (count($seen) !== count($inventory)) {
            $missing = array_values(array_diff(array_keys($inventory), array_keys($seen)));
            throw new \RuntimeException(
                'wprism: init code classification is incomplete; it says nothing about ' . implode(', ', $missing)
                . '. Every active component must be classified, or one would be carried by omission'
            );
        }
        usort($rows, static fn(array $a, array $b): int =>
            [(string) $a['root'], (string) $a['component']] <=> [(string) $b['root'], (string) $b['component']]);
        $code['split'] = $rows;
        // A classified site is ALWAYS the split declaration, even when nothing
        // locked: the lock's `first_party` list is what lets the compile gate
        // tell "Git carries this by declaration" from "Git carries this by
        // omission", and format 1 has nowhere to record that.
        $code['lock'] = CodeSourceLock::sort_components($lock);
        $code['first_party'] = CodeSourceLock::sort_first_party($firstParty);
        // Refuse here, where the operator can still read the proposal,
        // rather than mid-transaction when the lock is being published.
        CodeSourceLock::assert_lock([
            'format' => CodeSourceLock::FORMAT,
            'components' => $code['lock'],
            'first_party' => $code['first_party'],
        ]);
        $code['declaration'] = [
            'format' => 2,
            'layout' => 'wp-content',
            'lock' => CodeSourceLock::PATH,
            'source' => Code::SOURCE,
        ];
        return [$code, $blockers];
    }

    /**
     * Resolve discovery names into the exact source/digest pins the confirmed
     * config will own, then re-verify readiness against those same pins.
     *
     * @param list<string> $selected
     * @return array{0:Policy,1:list<array<string,string>>}
     */
    private static function load_selected_policy(
        array $selected,
        string $repo,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        $discovered = Policy::load(null, $selected, false, $repo, $adapterLibrary);
        $pins = [];
        foreach (RepositoryCompiler::resolved_adapters($discovered) as $row) {
            $pin = [
                'digest' => (string) $row['digest'],
                'name' => (string) $row['name'],
            ];
            if (($row['source'] ?? AdapterSources::SHIPPED) === AdapterSources::SITE) {
                $pin['source'] = AdapterSources::SITE;
            }
            $pins[] = $pin;
        }
        return [Policy::load(null, $pins, false, $repo, $adapterLibrary), $pins];
    }
    /**
     * One capability blocker as an `unsupported` row.
     *
     * AdapterSources::diagnostics() writes one remediation for every consumer
     * and `wp wprism capabilities` keeps it byte-identical, so the init-specific
     * instruction is applied HERE rather than by widening that string. At init
     * the honest instruction is narrower and has an order to it: the adapter
     * is already installed, so the operator signs it or removes it, and only
     * then does init have anything new to recompute.
     *
     * @param array<string,mixed> $blocker
     * @return array<string,string>
     */
    private static function capability_blocker_row(array $blocker): array {
        $code = (string) ($blocker['code'] ?? 'capability_not_ready');
        $name = (string) ($blocker['name'] ?? 'registry');
        $remediation = trim((string) ($blocker['remediation'] ?? '')) !== ''
            ? (string) $blocker['remediation']
            : 'install a certified compatible adapter/runtime or leave the site unmanaged';
        if ($code === 'adapter_source_uncertified') {
            // A PLUGIN-bundled adapter cannot be certified in place —
            // certification binds source "site" and the exact adapters/<name>.json
            // path inside the signed statement — so for that source the
            // registry's own remediation (the promotion path: install it as
            // adapters/<name>.json, certify, pin) is the honest instruction and
            // is kept verbatim; only the site source gets the one-command form.
            // (grind_adapter_walk.sh S3: this row told the operator to certify a
            // bundled copy the verb would then refuse.)
            $remediation = ($blocker['source'] ?? AdapterSources::SHIPPED) === AdapterSources::PLUGIN
                ? ((string) $remediation . ' — then rerun wprism init (wprism adapter certify <site-repo> --name=' . $name
                    . ' --pin signs and pins the promoted copy)')
                : 'certify it with wprism adapter certify <site-repo> --name=' . $name
                    . ', or remove it, then rerun wprism init';
        }
        return [
            'code' => $code,
            'extension' => $name,
            'kind' => 'adapter',
            'reason' => (string) ($blocker['reason'] ?? 'adapter capability is not ready'),
            'remediation' => $remediation,
            'source' => (string) ($blocker['source'] ?? AdapterSources::SHIPPED),
            'trust_tier' => (string) ($blocker['trust_tier'] ?? ''),
        ];
    }

    /**
     * Which active plugins this proposal manages, and how the rest are
     * reported. Its own function because it is the ONE decision
     * `--allow-unmanaged-plugins` changes, and proposal_bound() needs a live
     * WordPress for everything else it does — so without a seam the flag's
     * behaviour could only be asserted on a pair.
     *
     * A plugin is never "allowed" in the sense of being waved through: an
     * unmanaged one is reported by name and reason code on every proposal, and
     * it is absent from `selected`, which is what actually decides that
     * nothing about its state is published.
     *
     * @param list<string> $activePlugins
     * @param array<string,list<string>> $byPlugin plugin basename => declaring adapter names
     * @return array{selected:list<string>, unsupported:list<array<string,string>>, advisories:list<array<string,string>>}
     */
    private static function plugin_selection(
        array $activePlugins,
        array $byPlugin,
        bool $allowUnmanagedPlugins
    ): array {
        $selected = [];
        $unsupported = [];
        $advisories = [];
        foreach ($activePlugins as $plugin) {
            $plugin = (string) $plugin;
            $owners = $byPlugin[$plugin] ?? [];
            if (count($owners) === 1) {
                $selected[] = (string) $owners[0];
                continue;
            }
            $row = [
                'code' => $owners === [] ? 'active_plugin_without_adapter' : 'ambiguous_plugin_adapter',
                'extension' => $plugin,
                'kind' => 'plugin',
                'reason' => $owners === []
                    ? 'no installed manifest declares this active plugin identity'
                    : 'multiple installed manifests declare this active plugin identity',
                // The remediation names both exits an operator actually has.
                // Before T6 it named only "install or review one versioned
                // adapter", which was the one thing no operator could finish:
                // certification signs under a key in the agent-owned
                // authorities file, and that file ships empty.
                'remediation' => $owners === []
                    ? 'rerun wprism init --' . self::ALLOW_UNMANAGED_PLUGINS
                        . ' to leave it unmanaged, or install/certify an adapter (wprism adapter certify)'
                    : 'install or review one versioned adapter, then rerun wprism init',
            ];
            // Only the no-adapter fact is relaxable. `ambiguous_plugin_adapter`
            // is a different fact — there IS an adapter and the engine cannot
            // say which — and leaving it unmanaged is not its remedy.
            if ($owners === [] && $allowUnmanagedPlugins) {
                $row['remediation'] = 'init selects no adapter and no authored scope for this plugin; its registered '
                    . 'types that hold rows are left local (see UNMANAGED SCOPE); decide its state class in '
                    . 'the contract (assess reports it as plugin:' . self::plugin_slug($plugin) . ')';
                $advisories[] = $row;
                continue;
            }
            $unsupported[] = $row;
        }
        return ['advisories' => $advisories, 'selected' => $selected, 'unsupported' => $unsupported];
    }

    /**
     * A ready proposal must acknowledge the exact families its baseline would
     * otherwise refuse. Native WPForms reconnaissance reached confirmation
     * with a stored unassigned widget and failed at SidebarState's guard.
     * Inactive exclusions are policy, not new widget grammar. Active layout
     * ownership and existing non-local classifications remain blocking.
     *
     * @return array{options:array<string,array{class:string}>,advisories:list<array<string,string>>,blockers:list<array<string,string>>}
     */
    public static function unmanaged_widgets(Policy $policy): array {
        $inventory = SidebarState::unmanaged_inventory($policy);
        $options = $advisories = $blockers = [];
        foreach ($inventory['active'] as $type => $sidebars) {
            foreach ($sidebars as $sidebar) {
                $blockers[] = [
                    'code' => 'undeclared_active_widget',
                    'extension' => "$sidebar:$type",
                    'kind' => 'widget',
                    'reason' => "active sidebar '$sidebar' contains undeclared widget type '$type'; a sidebar is one complete authored layout, so excluding its option cannot make that layout portable",
                    'remediation' => 'install a reviewed adapter declaring this widget type, or remove its active sidebar assignments through WordPress before requesting a new init proposal',
                ];
            }
        }
        foreach ($inventory['families'] as $name => $family) {
            if (isset($inventory['active'][$family['type']])
                || in_array($family['class'], ['runtime', 'env'], true)) continue;
            if ($family['class'] !== null) {
                $blockers[] = [
                    'code' => 'widget_classification_without_grammar',
                    'extension' => $name,
                    'kind' => 'widget',
                    'reason' => 'this populated widget family has a non-local classification but no selected widget grammar; init cannot replace that existing classification with a local exclusion',
                    'remediation' => 'review the owning declaration and install its widget grammar, or explicitly classify the family as runtime or env before requesting a new init proposal',
                ];
                continue;
            }
            $options[$name] = ['class' => 'runtime'];
            $advisories[] = [
                'code' => 'unmanaged_widget_left_local',
                'extension' => $name,
                'kind' => 'widget',
                'reason' => $family['instances'] . ' stored instance(s) have no selected widget grammar and no active sidebar assignment; left local as runtime, with no settings or widget identity captured',
                'remediation' => 'install a reviewed adapter declaring this widget type before making its instances portable; initialization preserves their current native settings and inactive assignments',
            ];
        }
        ksort($options, SORT_STRING);
        return ['options' => $options, 'advisories' => $advisories, 'blockers' => $blockers];
    }

    /**
     * The types an unmanaged plugin's decision leaves local. Pure — the
     * offline seam for the rule above.
     *
     * A registered type (public, or any plugin-registered one) that has rows,
     * is not in the proposed authored scope, and is not declared by any
     * selected manifest with a class of its own, is exactly what capture's
     * scope gate would refuse. It receives `{class: runtime}` — the same
     * whole-type exclusion `wprism classify --set=scope:<kind>:<name>=runtime`
     * writes — and one advisory naming it. Types with no rows are left alone:
     * nothing is decided about a type that holds nothing yet, and the gate
     * only fires on rows.
     *
     * @param list<string> $registeredPostTypes
     * @param list<string> $registeredTaxonomies
     * @param list<string> $proposedPostTypes   the proposal's authored post_types
     * @param list<string> $proposedTaxonomies  the proposal's authored taxonomies
     * @param list<string> $declaredPostTypes   every post type a selected manifest declares (any class)
     * @param list<string> $declaredTaxonomies  every taxonomy a selected manifest declares (any class)
     * @param array<string,int> $postCounts     post type => capturable rows
     * @param array<string,int> $taxCounts      taxonomy => term rows
     * @return array{scope:array{post_type:array<string,array{class:string}>,taxonomy:array<string,array{class:string}>},advisories:list<array<string,string>>}
     */
    public static function unmanaged_scope(
        array $registeredPostTypes,
        array $registeredTaxonomies,
        array $proposedPostTypes,
        array $proposedTaxonomies,
        array $declaredPostTypes,
        array $declaredTaxonomies,
        array $postCounts,
        array $taxCounts
    ): array {
        $scope = ['post_type' => [], 'taxonomy' => []];
        $advisories = [];
        foreach ([
            ['post_type', $registeredPostTypes, $proposedPostTypes, $declaredPostTypes, $postCounts, 'row(s)'],
            ['taxonomy', $registeredTaxonomies, $proposedTaxonomies, $declaredTaxonomies, $taxCounts, 'term(s)'],
        ] as [$kind, $registered, $proposed, $declared, $counts, $unit]) {
            $registered = array_values(array_unique(array_map('strval', $registered)));
            sort($registered, SORT_STRING);
            $skip = array_fill_keys(array_map('strval', array_merge($proposed, $declared)), true);
            foreach ($registered as $name) {
                if (isset($skip[$name]) || (int) ($counts[$name] ?? 0) < 1) {
                    continue;
                }
                $scope[$kind][$name] = ['class' => 'runtime'];
                $advisories[] = [
                    'code' => 'unmanaged_scope_left_local',
                    'extension' => "$kind:$name",
                    'kind' => 'scope',
                    'reason' => 'registered by no selected adapter and holding ' . (int) $counts[$name] . " $unit; "
                        . 'left local (class runtime) until an adapter declares it or wprism classify decides it',
                    'remediation' => "to manage it later, run wprism classify and decide scope:$kind:$name, or install an adapter that declares it",
                ];
            }
        }
        return ['advisories' => $advisories, 'scope' => $scope];
    }

    /**
     * The post types and taxonomies the selected adapters put into the
     * proposed scope: every declaration of class `authored`, and every
     * STRUCTURAL declaration — one with no class at all, which the policy
     * reads as authored (Policy::post_type_rule_details() /
     * taxonomy_rule_details(): "portable data the adapter understands; the
     * site opts it in"). Contact Form 7 declares `wpcf7_contact_form: {}` and
     * Polylang its four taxonomies that way; leaving them out proposed a scope
     * whose own baseline capture then refused incomplete_policy_scope for
     * exactly those types (T7 grind A4), because a DECLARED type is not left
     * local either. Init is the site's opt-in.
     *
     * The per-manifest half of that reading is `ScopeAdoption::
     * declared_authored()`, shared with the post-init opt-in `wprism adapter
     * certify --pin` performs (issue #3495): an adapter that arrives after init
     * has to mean the same thing to the site's scope as one selected during
     * it, and two copies of "declared authored" would eventually disagree.
     *
     * @param list<string> $selected
     * @param array<string,array<string,mixed>> $manifests
     * @return array{post_types:list<string>,taxonomies:list<string>}
     */
    public static function adapter_scope(array $selected, array $manifests): array {
        $postTypes = [];
        $taxonomies = [];
        foreach ($selected as $name) {
            $manifest = $manifests[$name] ?? null;
            if (!is_array($manifest)) {
                continue;
            }
            $declared = ScopeAdoption::declared_authored($manifest);
            $postTypes = array_merge($postTypes, $declared['post_types']);
            $taxonomies = array_merge($taxonomies, $declared['taxonomies']);
        }
        $postTypes = array_values(array_unique($postTypes));
        $taxonomies = array_values(array_unique($taxonomies));
        sort($postTypes, SORT_STRING);
        sort($taxonomies, SORT_STRING);

        return ['post_types' => $postTypes, 'taxonomies' => $taxonomies];
    }

    /**
     * The certified core FSE profile's scope for a block theme, or null when
     * the active theme is classic (nothing to propose, nothing to say).
     *
     * $manifestNames is the library the caller actually loaded, and it is what
     * keeps this from proposing scope out of a GHOST. validate_profiles()
     * resolves `profile.manifest` against the registry's own declared names,
     * never against the directory, so a reviewed entry that outlived its
     * manifest keeps its profile valid; since WP-1.2 that direction has no
     * runtime reader either (assert_covers() validates what it was handed), so
     * a library whose `profiles.fse` points at an adapter that is not
     * installed would put core's site-editor types — wp_template,
     * wp_template_part, wp_navigation, wp_block and their taxonomies — under a
     * proposal backed by nothing. `make release-gate` bounds that for the
     * SHIPPED library only; an out-of-repo library reaches a running site with
     * no such gate in front of it. Resolving here rather than at load is
     * deliberate: the pinned subset is the wrong set to resolve against — a
     * site legitimately pinning one plugin adapter and nothing else leaves
     * `fse` -> `core` unpinned, and refusing that load would refuse a correct
     * library.
     *
     * Passing null means "no library in hand", which leaves the target alone;
     * InitPlanner::plan() passes array_keys() of the manifests it discovered.
     *
     * @param ?list<string> $manifestNames
     * @return ?array{scope:?array{post_types:list<string>,taxonomies:list<string>},advisory:array<string,string>}
     */
    public static function fse_profile_scope(
        ?bool $blockTheme = null,
        ?array $profiles = null,
        ?array $manifestNames = null,
        ?Policy $policy = null
    ): ?array {
        $blockTheme ??= function_exists('wp_is_block_theme') && wp_is_block_theme();
        if (!$blockTheme) {
            return null;
        }
        $stylesheet = function_exists('get_option') ? (string) get_option('stylesheet', '') : '';
        if ($profiles === null) {
            try {
                // plan() already resolved the exact library while selecting its
                // adapters. Reuse that object so profiles cannot be joined to
                // another physical inventory. The null path selects the one
                // shipped library for this public pure helper.
                $dispositions = $policy === null
                    ? ManifestDispositions::load_library(Policy::shipped_adapter_library())
                    : ManifestDispositions::load_library($policy->adapter_library());
                $profiles = $dispositions === null ? [] : $dispositions->profiles();
            } catch (\Throwable $t) {
                $profiles = [];
            }
        }
        $fse = is_array($profiles['fse'] ?? null) ? $profiles['fse'] : null;
        $scope = is_array($fse['scope'] ?? null) ? $fse['scope'] : null;
        $target = is_string($fse['manifest'] ?? null) ? $fse['manifest'] : '';
        $dangling = $fse !== null && $manifestNames !== null && !in_array($target, $manifestNames, true);
        if ($fse === null || ($fse['status'] ?? null) !== 'certified' || $scope === null || $dangling) {
            return [
                'scope' => null,
                'advisory' => [
                    'code' => 'fse_profile_not_certified',
                    'extension' => 'profile:fse',
                    'kind' => 'profile',
                    // Same code, because the operator-facing consequence is
                    // identical — nothing is proposed and `wprism classify`
                    // decides — but not the same sentence: "carries no
                    // certified profile" would be false about a library whose
                    // profile IS certified and whose adapter is simply absent,
                    // and would send the operator to install a profile they
                    // already have.
                    'reason' => $dangling
                        ? "the active theme '$stylesheet' is a block theme, and this library's core FSE profile "
                            . "names the adapter '$target', which this site has not installed; scope is not proposed "
                            . 'from a manifest that is not here, so site-editor customisations (templates, template '
                            . 'parts, navigation, patterns) are left out of the proposed scope'
                        : "the active theme '$stylesheet' is a block theme, but this library carries no certified "
                            . 'core FSE profile; site-editor customisations (templates, template parts, navigation, '
                            . 'patterns) are left out of the proposed scope',
                    'remediation' => $dangling
                        ? "install the adapter '$target' that this library's dispositions review, or decide those "
                            . 'types with wprism classify after init'
                        : 'install a library whose dispositions certify profiles.fse, or decide those types '
                            . 'with wprism classify after init',
                ],
            ];
        }
        $postTypes = array_values(array_map('strval', (array) ($scope['post_types'] ?? [])));
        $taxonomies = array_values(array_map('strval', (array) ($scope['taxonomies'] ?? [])));

        return [
            'scope' => ['post_types' => $postTypes, 'taxonomies' => $taxonomies],
            'advisory' => [
                'code' => 'fse_profile_scope_selected',
                'extension' => 'profile:fse',
                'kind' => 'profile',
                'reason' => "the active theme '$stylesheet' is a block theme; the certified core FSE profile's scope "
                    . '(' . implode(', ', $postTypes) . '; ' . implode(', ', $taxonomies) . ') is proposed so '
                    . 'site-editor customisations are managed rather than left behind silently',
                'remediation' => 'to leave any of these types local, run wprism classify after init and decide it',
            ],
        ];
    }

    /**
     * The directory half of `slug/file.php`, which is the identity assess
     * builds its `plugin:<slug>` surface row from (T6 §3.6) and the identity a
     * bundled `wprism-adapter.json` anchors to. A single-file plugin has no
     * directory, so its file name without `.php` is the only identity it has.
     */
    private static function plugin_slug(string $plugin): string {
        $directory = strpos($plugin, '/') === false ? '' : dirname($plugin);
        return $directory !== '' && $directory !== '.'
            ? $directory
            : (string) preg_replace('/\.php$/D', '', basename($plugin));
    }

    /** @return array<string,array<string,mixed>> */
    private static function installed_manifests(string $repo, ?AdapterLibrary $adapterLibrary = null): array {
        $out = [];
        [$dir, $sources] = self::adapter_sources($repo, $adapterLibrary);
        foreach ($sources->names() as $name) {
            $file = $sources->file($name, $dir);
            $manifest = Canon::decode(Canon::read_file($file));
            if ($sources->is_out_of_tree($name)) {
                AdapterSources::assert_out_of_tree_contract($manifest, $name, (string) $sources->path($name));
            }
            $out[$name] = $manifest;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Whether the repository's site.wprism.json is exactly the adoption seed
     * (possibly carrying explicit out-of-tree pins and the scope rules those
     * pins wrote — see existing_config()) — the state in which `wprism assess`
     * previews the init proposal instead of the seed's own `core`-only pin set
     * (T7 grind A3).
     */
    public static function is_adoption_seed(string $repo, ?AdapterLibrary $adapterLibrary = null): bool {
        try {
            return self::existing_config($repo, $adapterLibrary)['mode'] === 'adoption-seed';
        } catch (\Throwable $t) {
            return false;
        }
    }

    /** @return array{mode:string,identity:string} */
    private static function existing_config(string $repo, ?AdapterLibrary $adapterLibrary = null): array {
        $file = $repo . '/site.wprism.json';
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            return ['mode' => 'unsafe', 'identity' => 'unsafe'];
        }
        if (!is_file($file)) {
            return ['mode' => 'absent', 'identity' => 'absent'];
        }
        $raw = Canon::read_file($file);
        $data = Canon::decode($raw);
        $seed = [
            'manifests' => ['core'],
            'policy' => [
                'options' => [], 'post_meta' => [], 'term_meta' => [],
                'post_types' => ['post', 'page', 'attachment'],
                'taxonomies' => ['category', 'post_tag'],
            ],
            'spec_version' => WPRISM_SPEC_VERSION,
        ];
        // T6 §3.4's own remediation order — install the site adapter, certify
        // it (`wprism adapter certify --pin` writes the {name, source:"site",
        // digest} pin), THEN run init — means the seed init receives already
        // carries the operator's explicit out-of-tree pins. Those pins are the
        // pin set init recomputes and republishes exactly (load_selected_policy()
        // resolves the same digest for the same bytes), so they do not make
        // the repository init-owned. Only source-bearing OBJECT pins are set
        // aside: that is precisely what the host verbs write; a hand-added
        // name-only pin or any policy edit still reads as an owned repository.
        // (Found by the walk: certify --pin then init refused
        // `existing_configuration` on a repository whose non-seed content was
        // exactly one certified adapter and its pin.)
        $comparable = $data;
        $pinned = [];
        if (is_array($comparable) && is_array($comparable['manifests'] ?? null)) {
            foreach ($comparable['manifests'] as $pin) {
                if (is_array($pin)
                    && is_string($pin['name'] ?? null)
                    && in_array($pin['source'] ?? null, [AdapterSources::SITE, AdapterSources::PLUGIN], true)) {
                    $pinned[(string) $pin['name']] = (string) $pin['source'];
                }
            }
            $comparable['manifests'] = array_values(array_filter(
                $comparable['manifests'],
                static fn ($pin): bool => !(is_array($pin)
                    && in_array($pin['source'] ?? null, [AdapterSources::SITE, AdapterSources::PLUGIN], true))
            ));
        }
        // The SCOPE half of the same pin. Since issue #3495 `--pin` is the site's
        // scope opt-in as well as its pin: AdapterCertify::adoptScope()
        // (cli/src/Adapter/AdapterCertify.php:501) writes
        // `policy.scope.<kind>.<name> = {"class":"authored"}` for every surface
        // the certified adapter declares authored that site.wprism.json had not
        // decided (:588-609 is the writer). So the file T6 §3.4's order hands
        // init is the seed PLUS a pin PLUS those rules — and reading only the
        // pin as seed-compatible made the documented order refuse
        // `existing_configuration` (grind_adapter_walk.sh S2).
        //
        // Set aside exactly the rules that command would have written on the
        // seed and nothing else: ScopeAdoption::plan() is asked against the
        // literal $seed above, so an EXTEND row is by construction a surface a
        // pinned adapter declares authored and the seed had not decided.
        // Everything else under `policy.scope` — a `runtime` rule, a rule for a
        // type no pinned adapter declares, a rule for a name the adapter
        // classifies itself — is a decision only the site can have made, and
        // still reads owned. `policy.scope` absent takes none of this path.
        // The set-aside is keyed on the same site/plugin pins as the pin half
        // above, for the same reason: what the operator's own host verb wrote
        // in one act is one fact about the repository, not two.
        //
        // What init then republishes carries the opt-in ONCE, as the flat
        // `policy.post_types`/`taxonomies` entry adapter_scope():842 folds in
        // for every selected adapter's declared-authored surfaces — the same
        // list the pre-init order produces — and the scope rule does not
        // survive. Two representations would be the divergence risk issue #3495
        // was careful about in the other direction: a site scope rule OUTRANKS
        // every manifest (Policy::post_type_rule_details():1876-1879 returns the
        // site rule before it looks at one), so a stale `authored` rule would
        // keep classifying a surface the adapter had since reclassified, with
        // the file agreeing with itself and disagreeing with the library. The flat entry cannot diverge that way — it names
        // the type as in scope and leaves the CLASS to the declaring manifest,
        // which is what issue #3504 needs `declaring_manifest()` to keep answering.
        if ($pinned !== []
            && is_array($comparable['policy'] ?? null)
            && is_array($comparable['policy']['scope'] ?? null)) {
            $scope = $comparable['policy']['scope'];
            $stripped = self::without_pin_scope_rules(
                $scope,
                self::pin_scope_rules($pinned, $repo, $seed, $adapterLibrary)
            );
            // Only a set-aside that actually removed a rule may remove the
            // node it emptied. `"scope": {}` or `{"post_type": {}}` in the file
            // is not something either verb writes (writeScopeRules() runs only
            // for a non-empty row set), so it is left where it is and reads
            // owned like any other byte the seed does not have.
            if ($stripped !== $scope) {
                if ($stripped === []) {
                    unset($comparable['policy']['scope']);
                } else {
                    $comparable['policy']['scope'] = $stripped;
                }
            }
        }
        return [
            'mode' => Canon::encode($comparable) === Canon::encode($seed) ? 'adoption-seed' : 'owned',
            'identity' => InitOwnedArtifacts::regular_file_identity($file, 'site.wprism.json'),
        ];
    }

    /**
     * The `policy.scope` entries `wprism adapter certify --pin` / `wprism adapter
     * pin` would have written on the adoption seed for these out-of-tree pins.
     *
     * The manifests are read the way installed_manifests():930 reads them —
     * AdapterSources::discover() over the same library, the origin's own
     * resolved file — and the pin's WRITTEN source must be the source the
     * engine resolves, because a pin claiming `site` for a name only the
     * shipped library answers to is a repository defect PinResolver::
     * validate_manifest_sources() refuses on the next load; it does not get to
     * vouch for a scope rule here.
     *
     * Every failure returns fewer rules, never more: an unscannable library, a
     * pin naming no installed adapter, an unreadable manifest each leave the
     * recorded rule in `$comparable`, where it reads as a site edit and the
     * repository reads owned. That is the refusing direction, and it is the
     * one init already takes for every other input it cannot read.
     *
     * @param array<string,string> $pinned name => the source the pin declares
     * @param array<string,mixed>  $seed   the literal adoption seed body
     * @return array<string,array<string,true>> kind => surface name => true
     */
    private static function pin_scope_rules(
        array $pinned,
        string $repo,
        array $seed,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        try {
            [$dir, $sources] = self::adapter_sources($repo, $adapterLibrary);
        } catch (\Throwable $t) {
            return [];
        }
        $names = $sources->names();
        $rules = [];
        foreach ($pinned as $name => $source) {
            if (!in_array($name, $names, true) || $sources->source($name) !== $source) {
                continue;
            }
            try {
                $manifest = Canon::decode(Canon::read_file($sources->file($name, $dir)));
            } catch (\Throwable $t) {
                continue;
            }
            if (!is_array($manifest)) {
                continue;
            }
            // One reading of "declares authored", shared with the writer:
            // ScopeAdoption::plan() is what AdapterCertify::adoptScope() calls,
            // and an EXTEND row is exactly the rule it writes.
            foreach (ScopeAdoption::plan($manifest, $seed) as $row) {
                if ($row['state'] === ScopeAdoption::EXTEND) {
                    $rules[$row['kind']][$row['name']] = true;
                }
            }
        }
        return $rules;
    }

    /** @return array{0:string,1:AdapterSources} */
    private static function adapter_sources(string $repo, ?AdapterLibrary $adapterLibrary = null): array {
        $library = $adapterLibrary ?? Policy::adapter_library_context();
        return [$library->root(), AdapterSources::discover_library($library, $repo)];
    }

    /**
     * `policy.scope` with exactly those rules removed, and the `kind` node
     * removed when removing them emptied it — which is the state the file was
     * in before the pin wrote the first one.
     *
     * The recorded value must be `{"class":"authored"}` and nothing else: that
     * is the whole rule the writer emits, and a value carrying anything more is
     * not a rule this command produced.
     *
     * @param array<string,mixed> $scope             the file's decoded policy.scope
     * @param array<string,array<string,true>> $pinned kind => surface name => true
     * @return array<string,mixed>
     */
    private static function without_pin_scope_rules(array $scope, array $pinned): array {
        foreach ($scope as $kind => $rules) {
            if (!is_array($rules)) {
                continue;
            }
            $kept = $rules;
            foreach ($rules as $name => $rule) {
                if (isset($pinned[$kind][$name]) && $rule === ['class' => 'authored']) {
                    unset($kept[$name]);
                }
            }
            if ($kept === $rules) {
                continue;
            }
            if ($kept === []) {
                unset($scope[$kind]);
            } else {
                $scope[$kind] = $kept;
            }
        }
        return $scope;
    }

    /** @param array<string,mixed> $proposal */
    public static function assert_confirmed_proposal(array $proposal, string $expectedDigest): void {
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedDigest)
            || !hash_equals((string) ($proposal['digest'] ?? ''), $expectedDigest)) {
            throw new \RuntimeException(
                'wprism: init proposal changed before confirmation; review the fresh proposal and confirm its new digest'
            );
        }
        if (empty($proposal['ready'])) {
            throw new \RuntimeException('wprism: init proposal is not ready; resolve every reported unsupported capability first');
        }
    }
    /** @param array<string,string> $blocker @return array<string,mixed> */
    private static function blocked_repository_proposal(string $repo, array $blocker): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('wprism: init requires a loaded WordPress database connection');
        }
        $proposal = [
            'format' => self::FORMAT,
            'advisories' => [],
            'environment' => [
                'database' => ['access' => 'not-probed', 'server' => 'unknown'],
                'home' => (string) get_option('home', ''),
                'php' => PHP_VERSION,
                'wordpress' => (string) get_bloginfo('version'),
            ],
            'code' => [
                'management' => 'unsupported-repository-root', 'files' => 0, 'bytes' => 0,
                'source_revision' => null, 'roots' => [], 'active_plugins' => [], 'active_theme' => [],
            ],
            'state' => [
                'adapters' => [], 'baseline' => 'not-probed', 'existing_config' => 'not-probed',
                'media' => ['strategy' => 'not-probed', 'attachments' => 0, 'unavailable' => 0],
                'repository' => $repo, 'repository_identity' => null,
                'git' => ['mode' => 'not-probed', 'version' => 'not-probed'],
                'ledger' => ['rows' => 0, 'tables' => 0],
                'risk_surfaces' => [
                    'options' => [], 'user_meta' => [],
                    'oversized' => ['options' => 0, 'user_meta' => 0],
                    'scanned' => ['options' => 0, 'user_meta' => 0],
                    'limits' => InitSiteProbe::riskLimits(),
                    'truncated' => false,
                ],
            ],
            'unsupported' => [$blocker],
            'ready' => false,
        ];
        $proposal['digest'] = hash('sha256', Canon::encode($proposal));
        return $proposal;
    }
}
