<?php
namespace Duo;

/**
 * First-run, target-local onboarding for an existing WordPress installation.
 *
 * Discovery is deliberately read-only and produces a content-addressed plan.
 * The confirmed path recomputes that plan before writing, so an activation,
 * version, scope, or capability change between review and confirmation cannot
 * smuggle different authority into site.duo.json. The reviewed confirmation
 * publishes state and executable code as separate canonical contracts: code
 * receives its own content-addressed descriptor and completed baseline marker,
 * while state keeps its independent capture revision and ledger semantics.
 */
final class Init {
    public const FORMAT = 'duo-init-plan/v1';
    private const RISK_ROW_LIMIT = 5000;
    private const RISK_BYTE_LIMIT = 8388608;
    private const RISK_BATCH_SIZE = 100;

    /** @return array<string,mixed> */
    public static function proposal(string $repo): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('duo: init requires a loaded WordPress database connection');
        }

        $repo = rtrim($repo, '/');
        $git = self::git_probe($repo);
        $ledger = self::ledger_probe();
        $existing = self::existing_config($repo);
        $manifests = self::installed_manifests($repo);
        $activePlugins = array_values(array_filter(
            (array) get_option('active_plugins', []),
            static fn($value): bool => is_string($value) && $value !== ''
        ));

        $selected = ['core'];
        $unsupported = [];
        $advisories = [];
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
        $code = self::code_probe($activePlugins, $template, $stylesheet);
        $unsupported = array_merge($unsupported, $code['blockers']);
        unset($code['blockers']);

        $selected = array_values(array_unique($selected));
        sort($selected, SORT_STRING);
        if ($selected[0] !== 'core') {
            $selected = array_values(array_unique(array_merge(['core'], $selected)));
        }
        // The proposal has no site.duo.json yet, but its installed adapter
        // source is already repository-owned. Load selected manifests through
        // that source without pretending the not-yet-confirmed config exists.
        $policy = Policy::load(null, $selected, false, $repo);
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
        $pins = [];
        $adapterRows = [];
        foreach ($resolved as $row) {
            $pins[] = ['digest' => (string) $row['digest'], 'name' => (string) $row['name']];
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
        $media = self::media_probe();
        $risks = self::risk_probe();
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
                'existing_config' => $existing['mode'],
                'media' => $media,
                'repository' => $repo,
                'git' => ['mode' => $git['mode'], 'version' => $git['version']],
                'ledger' => ['rows' => $ledger['rows'], 'tables' => $ledger['tables']],
                'risk_surfaces' => $risks,
            ],
            'unsupported' => $unsupported,
            'ready' => $unsupported === [],
        ];
        $proposal['digest'] = hash('sha256', Canon::encode($proposal));
        return $proposal;
    }

    /** @return array<string,mixed> */
    public static function confirm(string $repo, string $expectedDigest): array {
        $proposal = self::proposal($repo);
        $repo = rtrim($repo, '/');
        self::assert_confirmed_proposal($proposal, $expectedDigest);

        // A connection-scoped database advisory lease is non-durable and
        // shared by every local/docker/SSH WP-CLI process using this target.
        // It prevents two confirmations from both taking ownership of an
        // absent config before the filesystem capture lock can exist.
        $lease = self::acquire_init_lease($repo);
        $publicationLock = null;
        $lockPathExisted = false;
        $lockOwnedAndCreated = false;
        $succeeded = false;
        $repoCreated = false;
        $gitCreated = false;
        $gitignoreWritten = false;
        $previousGitignore = null;
        $siteFile = $repo . '/site.duo.json';
        $previous = null;
        $publishedCode = false;
        $stagedCode = null;
        $mediaCreated = false;
        try {
            if (getenv('DUO_TEST_MODE') === '1') {
                $pauseMs = (int) (getenv('DUO_TEST_INIT_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }

            if (!is_dir($repo)) {
                if (!mkdir($repo, 0775, true) && !is_dir($repo)) {
                    throw new \RuntimeException("duo: could not create repository directory $repo");
                }
                $repoCreated = true;
            }
            $stateDir = $repo . '/state';
            $lockPath = Publish::lock_path($stateDir);
            self::assert_regular_file_or_absent($lockPath, 'state.capture.lock');
            $lockPathExisted = file_exists($lockPath);
            $publicationLock = Publish::lock($stateDir);
            $lockOwnedAndCreated = !$lockPathExisted;

            // Recompute while BOTH the init advisory lease and the shared
            // state publication lock are held. The operator confirms facts,
            // never a mutable config payload; neither a second init nor an
            // ordinary capture can publish between this recheck and commit.
            $proposal = self::proposal($repo);
            self::assert_confirmed_proposal($proposal, $expectedDigest);

            if (($proposal['state']['git']['mode'] ?? null) === 'initialize-on-confirm') {
                $gitCreated = true;
                self::initialize_git($repo);
            }
            [$previousGitignore, $gitignoreWritten] = self::ensure_gitignore($repo);

            self::assert_regular_file_or_absent($siteFile, 'site.duo.json');
            $previous = is_file($siteFile) ? Canon::read_file($siteFile) : null;
            [$descriptor, $stagedCode] = self::capture_code($repo, $proposal['code']);
            $codeStage = $repo . '/.duo-init-code-root-' . bin2hex(random_bytes(8));
            if (!mkdir($codeStage, 0775, true) && !is_dir($codeStage)) {
                throw new \RuntimeException("duo: could not create code publication stage $codeStage");
            }
            if (!rename($stagedCode, $codeStage . '/wp-content')) {
                self::remove_tree($codeStage);
                throw new \RuntimeException('duo: could not assemble the verified code baseline');
            }
            $stagedCode = $codeStage;
            $codeRoot = $repo . '/code';
            self::assert_absent_owned_path($codeRoot, 'code publication root');
            if (!rename($stagedCode, $codeRoot)) {
                throw new \RuntimeException('duo: could not publish the verified code baseline');
            }
            $stagedCode = null;
            $publishedCode = true;
            self::write_owned_file($siteFile, Canon::encode($proposal['state']['config']), 'site.duo.json');
            Policy::load($repo);
            $mediaDir = $repo . '/media';
            self::assert_absent_owned_path($mediaDir, 'media publication root');
            if (!mkdir($mediaDir, 0775, true) && !is_dir($mediaDir)) {
                throw new \RuntimeException("duo: could not create media publication root $mediaDir");
            }
            $mediaCreated = true;
            if (getenv('DUO_TEST_MODE') === '1') {
                $pauseMs = (int) (getenv('DUO_TEST_INIT_PUBLICATION_PAUSE_MS') ?: 0);
                if ($pauseMs > 0 && $pauseMs <= 10000) {
                    usleep($pauseMs * 1000);
                }
            }
            $capture = Capture::run_initial_baseline($repo, $publicationLock);
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
            $finalGit = self::git_probe($repo);
            if ($finalGit['mode'] !== 'existing-worktree' || $finalGit['blockers'] !== []) {
                throw new \RuntimeException(
                    'duo: init baseline committed, but the target Git worktree changed before final verification'
                );
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
                    'repository' => $repo,
                    'site_config' => $siteFile,
                ],
                'unsupported' => [],
            ];
        } catch (\Throwable $error) {
            // Once Capture has swapped state or written an intent, its
            // transaction/receipt protocol is the authority. Never delete
            // config/code around a possibly committed state tree; retain the
            // complete set for deterministic recovery instead of creating a
            // ghost baseline. All failures before that boundary are fully
            // compensated below while both leases remain held.
            $crossedPublication = is_dir($repo . '/state')
                || file_exists(Publish::intent_path($repo . '/state'));
            if ($crossedPublication) {
                throw new \RuntimeException(
                    'duo: init publication crossed its durable receipt boundary; retained config, code, state, and ledger together for recovery: '
                    . $error->getMessage(),
                    0,
                    $error
                );
            }
            if (is_string($stagedCode) && file_exists($stagedCode)) {
                self::remove_tree($stagedCode);
            }
            if ($mediaCreated && (file_exists($repo . '/media') || is_link($repo . '/media'))) {
                self::remove_tree($repo . '/media');
            }
            if ($publishedCode && (file_exists($repo . '/code') || is_link($repo . '/code'))) {
                self::remove_tree($repo . '/code');
            }
            if ($previous === null) {
                @unlink($siteFile);
            } else {
                try {
                    self::write_owned_file($siteFile, $previous, 'site.duo.json');
                } catch (\Throwable $restoreError) {
                    throw new \RuntimeException(
                        $error->getMessage() . "\nduo: init could not restore the prior site.duo.json: " . $restoreError->getMessage(),
                        0,
                        $error
                    );
                }
            }
            if ($gitignoreWritten) {
                $gitignore = $repo . '/.gitignore';
                if ($previousGitignore === null) {
                    @unlink($gitignore);
                } else {
                    self::write_owned_file($gitignore, $previousGitignore, '.gitignore');
                }
            }
            if ($gitCreated && (file_exists($repo . '/.git') || is_link($repo . '/.git'))) {
                self::remove_tree($repo . '/.git');
            }
            throw $error;
        } finally {
            if (is_resource($publicationLock)) {
                Publish::unlock($publicationLock);
            }
            if (!$succeeded && $lockOwnedAndCreated) {
                @unlink(Publish::lock_path($repo . '/state'));
            }
            if (!$succeeded && $repoCreated && is_dir($repo)
                && iterator_count(new \FilesystemIterator($repo)) === 0) {
                @rmdir($repo);
            }
            self::release_init_lease($lease);
        }
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

    /** @return array{mode:string} */
    private static function existing_config(string $repo): array {
        $file = $repo . '/site.duo.json';
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            return ['mode' => 'unsafe'];
        }
        if (!is_file($file)) {
            return ['mode' => 'absent'];
        }
        $data = Canon::decode(Canon::read_file($file));
        $seed = [
            'manifests' => ['core'],
            'policy' => [
                'options' => [], 'post_meta' => [], 'term_meta' => [],
                'post_types' => ['post', 'page', 'attachment'],
                'taxonomies' => ['category', 'post_tag'],
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ];
        return ['mode' => Canon::encode($data) === Canon::encode($seed) ? 'adoption-seed' : 'owned'];
    }

    /** @param array<string,mixed> $proposal */
    private static function assert_confirmed_proposal(array $proposal, string $expectedDigest): void {
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

    /** @return array{tables:int,rows:int} */
    private static function ledger_probe(): array {
        global $wpdb;
        $pattern = $wpdb->esc_like((string) $wpdb->prefix . 'duo_') . '%';
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('duo: init could not inspect the existing Duo ledger boundary');
        }
        sort($tables, SORT_STRING);
        $expected = [
            (string) $wpdb->prefix . 'duo_journal',
            (string) $wpdb->prefix . 'duo_kv',
            (string) $wpdb->prefix . 'duo_map',
            (string) $wpdb->prefix . 'duo_state',
        ];
        $rows = 0;
        foreach ($tables as $table) {
            if (!in_array($table, $expected, true)) {
                // An unknown duo_* table is itself non-pristine evidence;
                // never interpolate its target-controlled name into SQL.
                $rows++;
                continue;
            }
            $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
            if (!empty($wpdb->last_error) || !is_numeric($count)) {
                throw new \RuntimeException('duo: init could not verify that the existing Duo ledger is pristine');
            }
            $rows += (int) $count;
        }
        return ['tables' => count($tables), 'rows' => $rows];
    }

    /** @return array{mode:string,version:string,blockers:list<array<string,string>>} */
    private static function git_probe(string $repo): array {
        $versionResult = self::run_process(['git', '--version']);
        $version = $versionResult['exit'] === 0 ? trim($versionResult['stdout']) : 'unavailable';
        $blockers = [];
        if ($versionResult['exit'] !== 0) {
            $blockers[] = [
                'code' => 'git_unavailable', 'extension' => 'git', 'kind' => 'repository',
                'reason' => 'Git is unavailable on the target that owns the site repository',
                'remediation' => 'install Git on the target, then rerun duo init',
            ];
            return ['mode' => 'unavailable', 'version' => $version, 'blockers' => $blockers];
        }
        if (is_link($repo)) {
            $blockers[] = [
                'code' => 'unsafe_repository_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the repository root is not an ordinary directory',
                'remediation' => 'choose a non-symlinked directory owned by this site',
            ];
            return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
        }
        if (!file_exists($repo)) {
            return ['mode' => 'initialize-on-confirm', 'version' => $version, 'blockers' => []];
        }
        if (!is_dir($repo)) {
            $blockers[] = [
                'code' => 'unsafe_repository_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the repository root is not an ordinary directory',
                'remediation' => 'choose a non-symlinked directory owned by this site',
            ];
            return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
        }
        if (is_link($repo . '/.gitignore')) {
            $blockers[] = [
                'code' => 'unsafe_gitignore', 'extension' => '.gitignore', 'kind' => 'repository',
                'reason' => 'the repository ignore file is a symbolic link',
                'remediation' => 'replace it with an ordinary repository-owned file',
            ];
        }
        if (is_link($repo . '/.git')) {
            $blockers[] = [
                'code' => 'unsafe_git_metadata', 'extension' => '.git', 'kind' => 'repository',
                'reason' => 'the repository Git metadata root is a symbolic link',
                'remediation' => 'replace it with an ordinary worktree-owned .git directory or gitfile',
            ];
        }
        $captureLock = $repo . '/state.capture.lock';
        if (is_link($captureLock) || (file_exists($captureLock) && !is_file($captureLock))) {
            $blockers[] = [
                'code' => 'unsafe_capture_lock', 'extension' => 'state.capture.lock', 'kind' => 'repository',
                'reason' => 'the state publication lock is present but is not an ordinary regular file',
                'remediation' => 'remove the link or special file before initialization',
            ];
        }

        $allowed = array_fill_keys([
            '.', '..', '.duo', '.duo-env-values.json', '.duo-envs.json', '.git', '.gitignore', 'adapters',
            'code', 'media', 'site.duo.json', 'state', 'state.capture.lock',
        ], true);
        $unexpected = [];
        $entries = @scandir($repo);
        if ($entries === false) {
            $blockers[] = [
                'code' => 'unreadable_repository_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the repository root cannot be enumerated, so init cannot prove that it contains only owned paths',
                'remediation' => 'restore read and directory-enumeration permission, then rerun init',
            ];
        } else {
            foreach ($entries as $entry) {
                if (!isset($allowed[$entry])) {
                    $unexpected[] = $entry;
                }
            }
        }
        sort($unexpected, SORT_STRING);
        if ($unexpected !== []) {
            $blockers[] = [
                'code' => 'repository_not_empty', 'extension' => implode(', ', array_slice($unexpected, 0, 8)),
                'kind' => 'repository',
                'reason' => 'the repository contains files that init does not own',
                'remediation' => 'move the foreign files or choose an empty/adoption-seed site repository',
            ];
        }

        $rootResult = self::run_process(['git', '-C', $repo, 'rev-parse', '--show-toplevel']);
        if ($rootResult['exit'] !== 0) {
            if (file_exists($repo . '/.git')) {
                $blockers[] = [
                    'code' => 'invalid_git_worktree', 'extension' => '.git', 'kind' => 'repository',
                    'reason' => 'the repository contains Git metadata but is not a usable worktree',
                    'remediation' => 'repair or remove the invalid Git metadata, then rerun duo init',
                ];
                return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
            }
            return ['mode' => 'initialize-on-confirm', 'version' => $version, 'blockers' => $blockers];
        }
        $actual = realpath(trim($rootResult['stdout']));
        $expectedRoot = realpath($repo);
        if ($actual === false || $expectedRoot === false || $actual !== $expectedRoot) {
            $blockers[] = [
                'code' => 'repository_not_git_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the site repository resolves inside a different Git worktree',
                'remediation' => 'use a dedicated Git worktree whose top level is the site repository',
            ];
            return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
        }
        return ['mode' => 'existing-worktree', 'version' => $version, 'blockers' => $blockers];
    }

    private static function initialize_git(string $repo): void {
        $result = self::run_process(['git', 'init', '--initial-branch=main', $repo]);
        if (getenv('DUO_TEST_MODE') === '1'
            && getenv('DUO_TEST_INIT_FAIL_AFTER_GIT_CREATE') === '1'
            && (file_exists($repo . '/.git') || is_link($repo . '/.git'))) {
            throw new \RuntimeException('duo: injected init failure after Git metadata creation');
        }
        if ($result['exit'] !== 0 || self::git_probe($repo)['mode'] !== 'existing-worktree') {
            throw new \RuntimeException('duo: init could not create and verify the target Git worktree');
        }
    }

    /** @return array{0:?string,1:bool} previous bytes and whether a write occurred */
    private static function ensure_gitignore(string $repo): array {
        $path = $repo . '/.gitignore';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo: init refuses a non-file .gitignore boundary');
        }
        $previous = is_file($path) ? Canon::read_file($path) : null;
        $required = [
            '.tmp*', 'state.capture.lock', 'state.capture-staging/', 'state.capture-backup/',
            'state.capture-intent', 'state.capture-receipt', 'state.capture-intent.tmp.*',
            'state.capture-receipt.tmp.*', '.duo-env-values.json',
        ];
        $lines = $previous === null ? [] : preg_split('/\r?\n/', $previous);
        $known = array_fill_keys(is_array($lines) ? $lines : [], true);
        $missing = array_values(array_filter($required, static fn(string $line): bool => !isset($known[$line])));
        if ($missing === []) {
            return [$previous, false];
        }
        $next = $previous ?? '';
        if ($next !== '' && !str_ends_with($next, "\n")) {
            $next .= "\n";
        }
        if ($next !== '') {
            $next .= "\n";
        }
        $next .= "# Duo local publication and environment artifacts\n" . implode("\n", $missing) . "\n";
        self::write_owned_file($path, $next, '.gitignore');
        return [$previous, true];
    }

    private static function assert_regular_file_or_absent(string $path, string $label): void {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException("duo: init refuses non-regular repository-owned $label path $path");
        }
    }

    private static function assert_absent_owned_path(string $path, string $label): void {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("duo: init refuses pre-existing $label $path");
        }
    }

    /** Publish one owned file by replacement, never by following its target. */
    private static function write_owned_file(string $path, string $content, string $label): void {
        self::assert_regular_file_or_absent($path, $label);
        $dir = dirname($path);
        if (is_link($dir) || !is_dir($dir)) {
            throw new \RuntimeException("duo: init refuses unsafe parent for repository-owned $label path $path");
        }
        $tmp = $dir . '/.' . basename($path) . '.duo-init-' . bin2hex(random_bytes(8));
        try {
            Canon::write_file($tmp, $content);
            self::assert_regular_file_or_absent($path, $label);
            // rename replaces a raced symlink itself; it never follows the
            // link to overwrite the external target as file_put_contents does.
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException("duo: init could not publish repository-owned $label path $path");
            }
        } finally {
            if (is_file($tmp) || is_link($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function run_process(array $args): array {
        if (!function_exists('proc_open')) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => ''];
        }
        $pipes = [];
        $process = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => ''];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return list<array{basename:string,version:string}> */
    private static function plugin_inventory(array $activePlugins): array {
        if (!function_exists('get_plugin_data') && defined('ABSPATH')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($file)) require_once $file;
        }
        $rows = [];
        foreach ($activePlugins as $basename) {
            $version = '';
            $file = defined('WP_PLUGIN_DIR') ? rtrim(WP_PLUGIN_DIR, '/') . '/' . ltrim($basename, '/') : '';
            if ($file !== '' && is_file($file) && function_exists('get_plugin_data')) {
                $data = get_plugin_data($file, false, false);
                $version = is_array($data) ? (string) ($data['Version'] ?? '') : '';
            }
            $rows[] = ['basename' => $basename, 'version' => $version];
        }
        usort($rows, static fn(array $a, array $b): int => $a['basename'] <=> $b['basename']);
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function code_probe(array $activePlugins, string $template, string $stylesheet): array {
        $roots = [
            'content' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
            'mu_plugins' => defined('DUO_CONTROL_PLANE') && DUO_CONTROL_PLANE === true
                && defined('DUO_CONTROL_WPMU_PLUGIN_DIR')
                ? DUO_CONTROL_WPMU_PLUGIN_DIR
                : (defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : null),
            'plugins' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
            'themes' => function_exists('get_theme_root') ? get_theme_root() : null,
        ];
        $blockers = [];
        try {
            Code::assert_initial_capture_layout();
        } catch (\Throwable $error) {
            $blockers[] = [
                'code' => 'unsupported_code_layout',
                'extension' => 'wp-content',
                'kind' => 'code',
                'reason' => $error->getMessage(),
                'remediation' => 'use standard non-symlinked plugins, themes, and mu-plugins roots or initialize state separately',
            ];
        }

        $components = ['plugins' => [], 'themes' => []];
        foreach ($activePlugins as $plugin) {
            $component = explode('/', (string) $plugin, 2)[0];
            if (!self::safe_component($component)) {
                $blockers[] = [
                    'code' => 'unsafe_plugin_path', 'extension' => (string) $plugin, 'kind' => 'code',
                    'reason' => 'the active plugin basename does not resolve to one safe component',
                    'remediation' => 'restore the plugin under a standard safe plugin basename',
                ];
                continue;
            }
            $components['plugins'][] = $component;
        }
        foreach (array_values(array_unique(array_filter([$template, $stylesheet]))) as $theme) {
            if (!self::safe_component($theme)) {
                $blockers[] = [
                    'code' => 'unsafe_theme_path', 'extension' => $theme, 'kind' => 'code',
                    'reason' => 'the active theme slug is not one safe component',
                    'remediation' => 'restore the theme under a standard safe directory slug',
                ];
                continue;
            }
            $components['themes'][] = $theme;
        }
        foreach ($components as &$list) {
            $list = array_values(array_unique($list));
            sort($list, SORT_STRING);
        }
        unset($list);

        $inventory = self::code_inventory($roots, $components, $blockers);
        return [
            'active_plugins' => self::plugin_inventory($activePlugins),
            'active_theme' => ['stylesheet' => $stylesheet, 'template' => $template],
            'bytes' => $inventory['bytes'],
            'components' => $components,
            'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE],
            'files' => count($inventory['files']),
            'management' => 'managed-baseline-proposed',
            'roots' => $roots,
            'source_revision' => hash('sha256', Canon::encode($inventory['files'])),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<string,mixed> $roots
     * @param array<string,list<string>> $components
     * @param list<array<string,string>> $blockers
     * @return array{files:list<array{path:string,sha256:string}>,bytes:int}
     */
    private static function code_inventory(array $roots, array $components, array &$blockers): array {
        $files = [];
        $bytes = 0;
        foreach ($components as $rootName => $names) {
            $root = $roots[$rootName] ?? null;
            if (!is_string($root) || $root === '') {
                $blockers[] = [
                    'code' => 'code_root_unavailable', 'extension' => $rootName, 'kind' => 'code',
                    'reason' => "the $rootName root is unavailable", 'remediation' => 'restore the standard WordPress code root',
                ];
                continue;
            }
            foreach ($names as $name) {
                $source = rtrim($root, '/') . '/' . $name;
                $prefix = $rootName . '/' . $name;
                try {
                    self::inventory_path($source, $prefix, $files, $bytes, $blockers);
                } catch (\Throwable $error) {
                    $blockers[] = [
                        'code' => 'code_component_unreadable', 'extension' => $prefix, 'kind' => 'code',
                        'reason' => $error->getMessage(), 'remediation' => 'restore a readable regular-file component with no symlinks',
                    ];
                }
            }
        }
        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return ['files' => $files, 'bytes' => $bytes];
    }

    /** @param list<array{path:string,sha256:string}> $files @param list<array<string,string>> $blockers */
    private static function inventory_path(string $source, string $prefix, array &$files, int &$bytes, array &$blockers): void {
        if (is_link($source)) {
            throw new \RuntimeException("symbolic link is not portable: $prefix");
        }
        if (is_file($source)) {
            self::inventory_file($source, $prefix, $files, $bytes, $blockers);
            return;
        }
        if (!is_dir($source) || !is_readable($source)) {
            throw new \RuntimeException("code component is missing or unreadable: $prefix");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = $prefix . '/' . substr($path, strlen(rtrim($source, '/')) + 1);
            if ($item->isLink()) {
                throw new \RuntimeException("symbolic link is not portable: $relative");
            }
            if ($item->isDir()) continue;
            if (!$item->isFile()) {
                throw new \RuntimeException("non-regular code entry is not portable: $relative");
            }
            self::inventory_file($path, $relative, $files, $bytes, $blockers);
        }
    }

    /** @param list<array{path:string,sha256:string}> $files @param list<array<string,string>> $blockers */
    private static function inventory_file(string $path, string $relative, array &$files, int &$bytes, array &$blockers): void {
        if (!is_readable($path)) {
            throw new \RuntimeException("code file is unreadable: $relative");
        }
        $digest = hash_file('sha256', $path);
        $size = filesize($path);
        if (!is_string($digest) || $size === false) {
            throw new \RuntimeException("code file could not be hashed: $relative");
        }
        $base = strtolower(basename($relative));
        $secretLabel = self::code_secret_label($path);
        if ($base === '.env' || str_starts_with($base, '.env.') || $base === 'wp-config.php'
            || $secretLabel !== null) {
            $blockers[] = [
                'code' => 'credential_bearing_code_file', 'extension' => $relative, 'kind' => 'code',
                'reason' => $secretLabel === null
                    ? 'an environment-owned configuration file is inside the proposed code payload'
                    : "a high-confidence $secretLabel is inside the proposed code payload; the value is redacted",
                'remediation' => 'remove the credential from executable code and inject it as environment-owned configuration',
            ];
        }
        $files[] = ['path' => $relative, 'sha256' => $digest];
        $bytes += (int) $size;
    }

    /**
     * Scan every byte through Secrets' high-confidence matcher without ever
     * loading an unbounded file or putting the matched value in diagnostics.
     * Secrets bounds every accepted pattern to less than one read chunk. A
     * full-chunk overlap therefore detects every cross-boundary credential.
     */
    private static function code_secret_label(string $path): ?string {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('code file could not be opened for credential scanning');
        }
        $tail = '';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 32768);
                if ($chunk === false) {
                    throw new \RuntimeException('code file could not be read for credential scanning');
                }
                $window = $tail . $chunk;
                $label = Secrets::hard_match($window);
                if ($label !== null) {
                    return $label;
                }
                $tail = substr($window, -32768);
            }
        } finally {
            fclose($handle);
        }
        return null;
    }

    /** @return array{0:array<string,mixed>,1:string} verified descriptor and unpublished staging root */
    private static function capture_code(string $repo, array $code): array {
        $blockers = [];
        $inventory = self::code_inventory((array) ($code['roots'] ?? []), (array) ($code['components'] ?? []), $blockers);
        if ($blockers !== []) {
            throw new \RuntimeException('duo: code changed into an unsupported shape after confirmation');
        }
        $revision = hash('sha256', Canon::encode($inventory['files']));
        if (!hash_equals((string) ($code['source_revision'] ?? ''), $revision)) {
            throw new \RuntimeException('duo: code changed after proposal review; rerun init and review the new digest');
        }

        $stage = $repo . '/.duo-init-code-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0775, true) && !is_dir($stage)) {
            throw new \RuntimeException("duo: could not create code capture staging directory $stage");
        }
        try {
            foreach ((array) ($code['components'] ?? []) as $rootName => $names) {
                $sourceRoot = (string) (($code['roots'][$rootName] ?? null) ?: '');
                foreach ((array) $names as $name) {
                    self::copy_code_path(
                        rtrim($sourceRoot, '/') . '/' . $name,
                        $stage . '/' . $rootName . '/' . $name
                    );
                }
            }
            self::assert_staged_code_no_secrets($stage);
            $descriptor = Code::descriptor_from_source($stage);
            $copiedRevision = hash('sha256', Canon::encode($descriptor['files']));
            if (!hash_equals($revision, $copiedRevision)) {
                throw new \RuntimeException('duo: code changed while the baseline was being copied; rerun init');
            }
            return [$descriptor, $stage];
        } catch (\Throwable $error) {
            self::remove_tree($stage);
            throw $error;
        }
    }

    private static function copy_code_path(string $source, string $destination): void {
        if (is_link($source)) {
            throw new \RuntimeException("duo: refusing symbolic-link code source $source");
        }
        if (is_file($source)) {
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new \RuntimeException("duo: could not create code directory $parent");
            }
            if (!copy($source, $destination)) {
                throw new \RuntimeException("duo: could not copy code file $source");
            }
            @chmod($destination, fileperms($source) & 0777);
            return;
        }
        if (!is_dir($source)) {
            throw new \RuntimeException("duo: code source disappeared before copy: $source");
        }
        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException("duo: could not create code component $destination");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $target = $destination . '/' . substr($path, strlen(rtrim($source, '/')) + 1);
            if ($item->isLink()) {
                throw new \RuntimeException("duo: refusing symbolic-link code source $path");
            }
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("duo: could not create code directory $target");
                }
                continue;
            }
            if (!$item->isFile() || !copy($path, $target)) {
                throw new \RuntimeException("duo: could not copy regular code file $path");
            }
            @chmod($target, fileperms($path) & 0777);
        }
    }

    /** The exact copied bytes get their own redacted credential gate. */
    private static function assert_staged_code_no_secrets(string $stage): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $label = self::code_secret_label($item->getPathname());
            if ($label !== null) {
                throw new \RuntimeException(
                    "duo: captured code contains a high-confidence $label; the value is redacted and was not published"
                );
            }
        }
    }

    private static function safe_component(string $value): bool {
        return $value !== '' && $value !== '.' && $value !== '..'
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    private static function remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** @return array{attachments:int,local:int,provider:int,unavailable:int,strategy:string} */
    private static function media_probe(): array {
        $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'numberposts' => -1]);
        $local = 0; $provider = 0; $unavailable = 0;
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id, true);
            if (is_string($path) && is_file($path) && is_readable($path)) {
                $local++;
                continue;
            }
            $source = apply_filters('duo_attachment_capture_source', null, (int) $id, $path);
            if (is_array($source)
                && ((is_string($source['path'] ?? null) && is_file($source['path']) && is_readable($source['path']))
                    || is_string($source['bytes'] ?? null))) {
                $provider++;
            } else {
                $unavailable++;
            }
        }
        $strategy = $unavailable > 0 ? 'incomplete' : ($provider > 0 ? 'local+provider' : 'local');
        return ['attachments' => count($ids), 'local' => $local, 'provider' => $provider, 'unavailable' => $unavailable, 'strategy' => $strategy];
    }

    /** @return array{options:array<string,int>,user_meta:array<string,int>,oversized:array{options:int,user_meta:int},scanned:array{options:int,user_meta:int},limits:array{rows_per_surface:int,bytes_per_surface:int},truncated:bool} */
    private static function risk_probe(): array {
        global $wpdb;
        $oversizedOptions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE LENGTH(option_value) > 65536"
        );
        if (!is_numeric($oversizedOptions) || trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('duo: init risk probe could not bound oversized option values safely');
        }
        $oversizedOptions = (int) $oversizedOptions;
        $oversizedUserMeta = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE LENGTH(meta_value) > 65536"
        );
        if (!is_numeric($oversizedUserMeta) || trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('duo: init risk probe could not bound oversized user-meta values safely');
        }
        $oversizedUserMeta = (int) $oversizedUserMeta;

        $options = self::bounded_risk_counts(
            (string) $wpdb->options,
            'option_id',
            'option_name',
            'option_value',
            'option values',
            static function (string $name, string $raw): ?string {
                $label = Secrets::hard_match($raw);
                return $label ?? (Secrets::suspicious($name, $raw) ? 'suspicious-name-and-shape' : null);
            }
        );
        // $options now retains counts only. No row payload survives while the
        // second surface is scanned, so the byte bound is real in PHP memory.
        $userMeta = self::bounded_risk_counts(
            (string) $wpdb->usermeta,
            'umeta_id',
            'meta_key',
            'meta_value',
            'user-meta values',
            static fn(string $name, string $raw): ?string =>
                PersonalData::match_deep($name, self::safe_risk_value($raw))
        );
        $secretCounts = $options['counts'];
        $piiCounts = $userMeta['counts'];
        ksort($secretCounts, SORT_STRING); ksort($piiCounts, SORT_STRING);
        return [
            'options' => $secretCounts,
            'user_meta' => $piiCounts,
            'oversized' => ['options' => $oversizedOptions, 'user_meta' => $oversizedUserMeta],
            'scanned' => ['options' => $options['rows'], 'user_meta' => $userMeta['rows']],
            'limits' => [
                'rows_per_surface' => self::RISK_ROW_LIMIT,
                'bytes_per_surface' => self::RISK_BYTE_LIMIT,
            ],
            'truncated' => $options['truncated'] || $userMeta['truncated']
                || $oversizedOptions > 0 || $oversizedUserMeta > 0,
        ];
    }

    /**
     * Deterministic keyset scan with both row and byte ceilings. Only one
     * small batch is resident at a time; the returned structure contains no
     * target values, only redacted labels and counts.
     *
     * @param callable(string,string):?string $classify
     * @return array{counts:array<string,int>,rows:int,bytes:int,truncated:bool}
     */
    private static function bounded_risk_counts(
        string $table,
        string $idColumn,
        string $nameColumn,
        string $valueColumn,
        string $failureLabel,
        callable $classify
    ): array {
        global $wpdb;
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        foreach ([$idColumn, $nameColumn, $valueColumn] as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
                throw new \RuntimeException('duo: init risk probe received an unsafe column boundary');
            }
        }
        $counts = [];
        $rows = 0;
        $bytes = 0;
        $lastId = 0;
        $truncated = false;

        while ($rows < self::RISK_ROW_LIMIT && $bytes < self::RISK_BYTE_LIMIT) {
            $processLimit = min(self::RISK_BATCH_SIZE, self::RISK_ROW_LIMIT - $rows);
            $fetchLimit = $processLimit + 1;
            $batch = $wpdb->get_results(
                "SELECT $idColumn, $nameColumn, $valueColumn FROM $quotedTable "
                . "WHERE $idColumn > $lastId AND LENGTH($valueColumn) <= 65536 "
                . "ORDER BY $idColumn ASC LIMIT $fetchLimit",
                ARRAY_A
            );
            if (!is_array($batch) || trim((string) $wpdb->last_error) !== '') {
                throw new \RuntimeException("duo: init risk probe could not read $failureLabel safely");
            }
            if ($batch === []) {
                break;
            }

            $processed = 0;
            $stoppedForBytes = false;
            foreach ($batch as $row) {
                if ($processed >= $processLimit) {
                    break;
                }
                $raw = (string) ($row[$valueColumn] ?? '');
                $size = strlen($raw);
                if ($bytes + $size > self::RISK_BYTE_LIMIT) {
                    $truncated = true;
                    $stoppedForBytes = true;
                    break;
                }
                $label = $classify((string) ($row[$nameColumn] ?? ''), $raw);
                if ($label !== null) {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
                $lastId = (int) ($row[$idColumn] ?? 0);
                $rows++;
                $bytes += $size;
                $processed++;
            }

            $hasUnprocessed = count($batch) > $processed;
            unset($batch);
            if ($stoppedForBytes) {
                break;
            }
            if ($rows >= self::RISK_ROW_LIMIT || $bytes >= self::RISK_BYTE_LIMIT) {
                $truncated = $truncated || $hasUnprocessed;
                break;
            }
            if (!$hasUnprocessed) {
                break;
            }
        }

        return ['counts' => $counts, 'rows' => $rows, 'bytes' => $bytes, 'truncated' => $truncated];
    }

    /** Decode scalar/array metadata without ever instantiating stored PHP objects. */
    private static function safe_risk_value(string $raw) {
        if (!function_exists('is_serialized') || !is_serialized($raw)) {
            return $raw;
        }
        $decoded = @unserialize(trim($raw), ['allowed_classes' => false]);
        // A disallowed object becomes __PHP_Incomplete_Class. Keep the raw
        // bytes opaque; the outer meta key can still produce a redacted PII
        // label, while no wakeup/unserialize/destructor code can execute.
        return is_object($decoded) ? $raw : $decoded;
    }
}
