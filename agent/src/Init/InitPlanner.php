<?php
namespace Duo;

require_once __DIR__ . "/../Adapter/AdapterSources.php";
require_once __DIR__ . "/../Kernel/Canon.php";
require_once __DIR__ . "/../Code/Code.php";
require_once __DIR__ . "/InitAttemptJournal.php";
require_once __DIR__ . "/InitCodeInventory.php";
require_once __DIR__ . "/InitOwnedArtifacts.php";
require_once __DIR__ . "/InitProtocol.php";
require_once __DIR__ . "/InitRecovery.php";
require_once __DIR__ . "/InitRepositoryBoundary.php";
require_once __DIR__ . "/InitSiteProbe.php";
require_once __DIR__ . "/../Policy/Policy.php";
require_once __DIR__ . "/../Repository/RepositoryCompiler.php";

/**
 * Builds the read-only, content-addressed initialization proposal.
 */
final class InitPlanner {
    public const FORMAT = InitProtocol::PLAN_FORMAT;
    /** @return array<string,mixed> */
    public static function proposal(string $repo): array {
        $logicalRepo = InitRepositoryBoundary::normalize($repo);
        $rootBlocker = InitRepositoryBoundary::root_blocker($logicalRepo);
        if ($rootBlocker !== null) {
            return self::blocked_repository_proposal($logicalRepo, $rootBlocker);
        }
        $binding = InitRepositoryBoundary::bind($logicalRepo);
        try {
            return self::proposal_bound('.', $logicalRepo, $binding['identity']);
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
        bool $ownsInitAttempt = false
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
        foreach ($activePlugins as $plugin) {
            $owners = $byPlugin[$plugin] ?? [];
            if (count($owners) !== 1) {
                $unsupported[] = [
                    'code' => $owners === [] ? 'active_plugin_without_adapter' : 'ambiguous_plugin_adapter',
                    'extension' => $plugin,
                    'kind' => 'plugin',
                    'reason' => $owners === []
                        ? 'no installed manifest declares this active plugin identity'
                        : 'multiple installed manifests declare this active plugin identity',
                    'remediation' => 'install or review one versioned adapter, then rerun duo init',
                ];
                continue;
            }
            $selected[] = $owners[0];
        }

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
        unset($code['blockers']);

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
            $unsupported[] = [
                'code' => (string) ($blocker['code'] ?? 'capability_not_ready'),
                'extension' => (string) ($blocker['name'] ?? 'registry'),
                'kind' => 'adapter',
                'reason' => (string) ($blocker['reason'] ?? 'adapter capability is not ready'),
                'remediation' => trim((string) ($blocker['remediation'] ?? '')) !== ''
                    ? (string) $blocker['remediation']
                    : 'install a certified compatible adapter/runtime or leave the site unmanaged',
                'source' => (string) ($blocker['source'] ?? AdapterSources::SHIPPED),
                'trust_tier' => (string) ($blocker['trust_tier'] ?? ''),
            ];
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
        $postTypes = array_values(array_unique($postTypes));
        $taxonomies = array_values(array_unique($taxonomies));
        sort($postTypes, SORT_STRING);
        sort($taxonomies, SORT_STRING);

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
            'mode' => Canon::encode($data) === Canon::encode($seed) ? 'adoption-seed' : 'owned',
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
