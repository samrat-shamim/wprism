<?php
namespace Duo;

require_once __DIR__ . '/../Adapter/AdapterSources.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/InitAttemptJournal.php';
require_once __DIR__ . '/InitCodeInventory.php';
require_once __DIR__ . '/InitOwnedArtifacts.php';
require_once __DIR__ . '/InitProtocol.php';
require_once __DIR__ . '/InitRecovery.php';
require_once __DIR__ . '/InitRepositoryBoundary.php';
require_once __DIR__ . '/InitSiteProbe.php';
require_once __DIR__ . '/../Policy/ManifestDispositions.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';

/**
 * Builds the read-only, content-addressed initialization proposal.
 */
final class InitPlanner {
    public const FORMAT = InitProtocol::PLAN_FORMAT;

    /**
     * The operator's explicit decision to initialize a site whose active
     * plugins Duo does not manage (round-3 T6 §3.4).
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

    /** @return array<string,mixed> */
    public static function proposal(string $repo, bool $allowUnmanagedPlugins = false): array {
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
                $allowUnmanagedPlugins
            );
        } finally {
            if (!@chdir($binding['previous_cwd'])) {
                throw new \RuntimeException('duo: init could not restore its process working directory after proposal');
            }
        }
    }

    /** @return array<string,mixed> */
    public static function proposal_bound(
        string $repo,
        string $logicalRepo,
        string $rootIdentity,
        bool $ownsCaptureLock = false,
        bool $ownsInitAttempt = false,
        bool $allowUnmanagedPlugins = false
    ): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('duo: init requires a loaded WordPress database connection');
        }

        $attempt = InitAttemptJournal::read($repo);
        if (!$ownsInitAttempt && $attempt !== null) {
            return InitRecovery::interrupted_attempt_proposal($attempt, $repo, $logicalRepo, $rootIdentity);
        }

        $git = InitRepositoryBoundary::git_probe($repo);
        $ledger = InitSiteProbe::ledger();
        $existing = self::existing_config($repo);
        $gitignoreIdentity = InitOwnedArtifacts::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore');
        $manifests = self::installed_manifests($repo);
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
            'reason' => 'the init lease and capture lock serialize Duo writers only; first publication requires every non-Duo writer to remain quiescent across the repository namespace',
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
                    'remediation' => 'retain one versioned theme adapter, then rerun duo init',
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
            return is_string($name) && $name !== 'duo-loader.php';
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

        $selected = array_values(array_unique($selected));
        sort($selected, SORT_STRING);
        if ($selected[0] !== 'core') {
            $selected = array_values(array_unique(array_merge(['core'], $selected)));
        }
        // The proposal has no site.duo.json yet, but its installed adapter
        // source is already repository-owned. Resolve the selected manifests
        // once to obtain their canonical digests/provenance, then reload the
        // exact pins that init will publish. This second verification is what
        // elevates a signed site adapter: name-only discovery must never grant
        // authority, while a reviewed proposal must not remain permanently
        // "signed_unpinned" merely because the config does not exist yet.
        [$policy, $pins] = self::load_selected_policy($selected, $repo);
        $capabilities = $policy->capability_report(['operation' => 'capture']);
        foreach ($capabilities['blockers'] ?? [] as $blocker) {
            $unsupported[] = self::capability_blocker_row(is_array($blocker) ? $blocker : []);
        }

        $postTypes = ['attachment', 'page', 'post'];
        $taxonomies = ['category', 'post_tag'];
        foreach ($selected as $name) {
            $manifest = $manifests[$name] ?? null;
            if (!is_array($manifest)) {
                continue;
            }
            foreach (($manifest['post_types'] ?? []) as $postType => $rule) {
                if (is_array($rule) && ($rule['class'] ?? null) === 'authored') {
                    $postTypes[] = (string) $postType;
                }
            }
            foreach (($manifest['taxonomies'] ?? []) as $taxonomy => $rule) {
                if (is_array($rule) && ($rule['class'] ?? null) === 'authored') {
                    $taxonomies[] = (string) $taxonomy;
                }
            }
        }
        // A block theme keeps its site-editor customisations in core's FSE
        // post types (wp_template, wp_template_part, wp_navigation, wp_block)
        // and taxonomies (wp_theme, wp_template_part_area, wp_pattern_category).
        // They are core-registered, non-public and _builtin, so the scope
        // gate never names them — a proposal that left them out would let a
        // customised footer stay behind SILENTLY, which T7 grind A2 exists to
        // catch. The certified core FSE profile (manifests/dispositions.json
        // profiles.fse) declares exactly that scope: propose it whenever the
        // active theme is a block theme, and say so; when the profile is not
        // certified or the registry is unreadable, say that instead and leave
        // the types to `duo classify`.
        $fse = self::fse_profile_scope();
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
        // `duo init --allow-unmanaged-plugins --yes`). Each such type gets a
        // reviewed-looking scope rule of class runtime — the exact rule
        // `duo classify` would write — and is printed as an advisory so the
        // operator sees what was left local; `duo classify` re-decides it in
        // one line when an adapter arrives.
        // T7 grind A6 widened this from "when unmanaged plugins are present"
        // to always: an ADAPTER-owned plugin can register a rowful type its
        // adapter deliberately leaves to the site (Elementor's
        // elementor_library — manifests/elementor.json says the site opts
        // it in), and init refusing incomplete_policy_scope at confirmation
        // gave the operator no way to adopt at all. Left local and printed,
        // the decision is one `duo classify` line, exactly as for a type an
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
            'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE],
            'manifests' => $pins,
            'policy' => [
                'options' => new \stdClass(),
                'post_meta' => new \stdClass(),
                'post_types' => $postTypes,
                'taxonomies' => $taxonomies,
                'term_meta' => new \stdClass(),
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ];
        if ($scope['post_type'] !== [] || $scope['taxonomy'] !== []) {
            $config['policy']['scope'] = array_filter([
                'post_type' => $scope['post_type'] !== [] ? $scope['post_type'] : null,
                'taxonomy' => $scope['taxonomy'] !== [] ? $scope['taxonomy'] : null,
            ]);
        }

        $dbVersion = (string) $wpdb->get_var('SELECT VERSION()');
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('duo: init database probe failed: ' . $wpdb->last_error);
        }
        $media = InitSiteProbe::media();
        $risks = InitSiteProbe::risk();
        if (!empty($media['unavailable'])) {
            $unsupported[] = [
                'code' => 'attachment_bytes_unavailable',
                'extension' => 'media',
                'kind' => 'media',
                'reason' => $media['unavailable'] . ' attachment(s) have neither readable local bytes nor a valid provider source',
                'remediation' => 'install an offload provider for duo_attachment_capture_source or restore the originals',
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
                'extension' => 'site.duo.json',
                'kind' => 'repository',
                'reason' => 'site.duo.json is present but is not an ordinary repository-owned regular file',
                'remediation' => 'replace the link or special file with an ordinary adoption seed, or remove it',
            ];
        } elseif ($existing['mode'] === 'owned') {
            $unsupported[] = [
                'code' => 'existing_configuration',
                'extension' => 'site.duo.json',
                'kind' => 'repository',
                'reason' => 'the repository already has a non-seed Duo configuration',
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
                'code' => 'existing_duo_ledger',
                'extension' => 'wordpress-database',
                'kind' => 'repository',
                'reason' => 'Duo ledger rows already exist, so this is not an uninitialized environment',
                'remediation' => 'use ordinary recovery/capture workflows or explicitly remove the abandoned baseline after review',
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
     * Resolve discovery names into the exact source/digest pins the confirmed
     * config will own, then re-verify readiness against those same pins.
     *
     * @param list<string> $selected
     * @return array{0:Policy,1:list<array<string,string>>}
     */
    private static function load_selected_policy(array $selected, string $repo): array {
        $discovered = Policy::load(null, $selected, false, $repo);
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
        return [Policy::load(null, $pins, false, $repo), $pins];
    }
    /**
     * One capability blocker as an `unsupported` row.
     *
     * AdapterSources::diagnostics() writes one remediation for every consumer
     * and `wp duo capabilities` keeps it byte-identical, so the init-specific
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
                ? ((string) $remediation . ' — then rerun duo init (duo adapter certify <site-repo> --name=' . $name
                    . ' --pin signs and pins the promoted copy)')
                : 'certify it with duo adapter certify <site-repo> --name=' . $name
                    . ', or remove it, then rerun duo init';
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
                    ? 'rerun duo init --' . self::ALLOW_UNMANAGED_PLUGINS
                        . ' to leave it unmanaged, or install/certify an adapter (duo adapter certify)'
                    : 'install or review one versioned adapter, then rerun duo init',
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
     * The types an unmanaged plugin's decision leaves local. Pure — the
     * offline seam for the rule above.
     *
     * A registered type (public, or any plugin-registered one) that has rows,
     * is not in the proposed authored scope, and is not declared by any
     * selected manifest with a class of its own, is exactly what capture's
     * scope gate would refuse. It receives `{class: runtime}` — the same
     * whole-type exclusion `duo classify --set=scope:<kind>:<name>=runtime`
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
                        . 'left local (class runtime) until an adapter declares it or duo classify decides it',
                    'remediation' => "to manage it later, run duo classify and decide scope:$kind:$name, or install an adapter that declares it",
                ];
            }
        }
        return ['advisories' => $advisories, 'scope' => $scope];
    }

    /**
     * The certified core FSE profile's scope for a block theme, or null when
     * the active theme is classic (nothing to propose, nothing to say).
     *
     * @return ?array{scope:?array{post_types:list<string>,taxonomies:list<string>},advisory:array<string,string>}
     */
    public static function fse_profile_scope(?bool $blockTheme = null, ?array $profiles = null): ?array {
        $blockTheme ??= function_exists('wp_is_block_theme') && wp_is_block_theme();
        if (!$blockTheme) {
            return null;
        }
        $stylesheet = function_exists('get_option') ? (string) get_option('stylesheet', '') : '';
        if ($profiles === null) {
            try {
                $dispositions = ManifestDispositions::load(Policy::manifests_dir());
                $profiles = $dispositions === null ? [] : $dispositions->profiles();
            } catch (\Throwable $t) {
                $profiles = [];
            }
        }
        $fse = is_array($profiles['fse'] ?? null) ? $profiles['fse'] : null;
        $scope = is_array($fse['scope'] ?? null) ? $fse['scope'] : null;
        if ($fse === null || ($fse['status'] ?? null) !== 'certified' || $scope === null) {
            return [
                'scope' => null,
                'advisory' => [
                    'code' => 'fse_profile_not_certified',
                    'extension' => 'profile:fse',
                    'kind' => 'profile',
                    'reason' => "the active theme '$stylesheet' is a block theme, but this library carries no certified "
                        . 'core FSE profile; site-editor customisations (templates, template parts, navigation, '
                        . 'patterns) are left out of the proposed scope',
                    'remediation' => 'install a library whose dispositions certify profiles.fse, or decide those types '
                        . 'with duo classify after init',
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
                'remediation' => 'to leave any of these types local, run duo classify after init and decide it',
            ],
        ];
    }

    /**
     * The directory half of `slug/file.php`, which is the identity assess
     * builds its `plugin:<slug>` surface row from (T6 §3.6) and the identity a
     * bundled `duo-adapter.json` anchors to. A single-file plugin has no
     * directory, so its file name without `.php` is the only identity it has.
     */
    private static function plugin_slug(string $plugin): string {
        $directory = strpos($plugin, '/') === false ? '' : dirname($plugin);
        return $directory !== '' && $directory !== '.'
            ? $directory
            : (string) preg_replace('/\.php$/D', '', basename($plugin));
    }

    /** @return array<string,array<string,mixed>> */
    private static function installed_manifests(string $repo): array {
        $out = [];
        $dir = Policy::manifests_dir();
        $sources = AdapterSources::discover($dir, $repo);
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
     * Whether the repository's site.duo.json is exactly the adoption seed
     * (possibly carrying explicit out-of-tree pins) — the state in which
     * `duo assess` previews the init proposal instead of the seed's own
     * `core`-only pin set (T7 grind A3).
     */
    public static function is_adoption_seed(string $repo): bool {
        try {
            return self::existing_config($repo)['mode'] === 'adoption-seed';
        } catch (\Throwable $t) {
            return false;
        }
    }

    /** @return array{mode:string,identity:string} */
    private static function existing_config(string $repo): array {
        $file = $repo . '/site.duo.json';
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            return ['mode' => 'unsafe', 'identity' => 'unsafe'];
        }
        if (!is_file($file)) {
            return ['mode' => 'absent', 'identity' => 'absent'];
        }
        $raw = Canon::read_file($file);
        $data = Canon::decode($raw);
        // T6 §3.4's own remediation order — install the site adapter, certify
        // it (`duo adapter certify --pin` writes the {name, source:"site",
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
        if (is_array($comparable) && is_array($comparable['manifests'] ?? null)) {
            $comparable['manifests'] = array_values(array_filter(
                $comparable['manifests'],
                static fn ($pin): bool => !(is_array($pin)
                    && in_array($pin['source'] ?? null, [AdapterSources::SITE, AdapterSources::PLUGIN], true))
            ));
        }
        $seed = [
            'manifests' => ['core'],
            'policy' => [
                'options' => [], 'post_meta' => [], 'term_meta' => [],
                'post_types' => ['post', 'page', 'attachment'],
                'taxonomies' => ['category', 'post_tag'],
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ];
        return [
            'mode' => Canon::encode($comparable) === Canon::encode($seed) ? 'adoption-seed' : 'owned',
            'identity' => InitOwnedArtifacts::regular_file_identity($file, 'site.duo.json'),
        ];
    }

    /** @param array<string,mixed> $proposal */
    public static function assert_confirmed_proposal(array $proposal, string $expectedDigest): void {
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedDigest)
            || !hash_equals((string) ($proposal['digest'] ?? ''), $expectedDigest)) {
            throw new \RuntimeException(
                'duo: init proposal changed before confirmation; review the fresh proposal and confirm its new digest'
            );
        }
        if (empty($proposal['ready'])) {
            throw new \RuntimeException('duo: init proposal is not ready; resolve every reported unsupported capability first');
        }
    }
    /** @param array<string,string> $blocker @return array<string,mixed> */
    private static function blocked_repository_proposal(string $repo, array $blocker): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('duo: init requires a loaded WordPress database connection');
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
