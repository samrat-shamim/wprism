<?php
namespace Duo;

/**
 * Signals that an init-owned artifact could not be safely compensated in the
 * current process. The sealed attempt journal and canonical capture lock must
 * remain in place so a fresh process can verify and recover the exact tuple.
 */
final class InitAttemptRetentionException extends \RuntimeException {}

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
    private const ATTEMPT_FILE = '.duo-init-attempt';
    private const ATTEMPT_NEXT_FILE = '.duo-init-attempt.next';

    /** @return array<string,mixed> */
    public static function proposal(string $repo): array {
        $logicalRepo = self::normalize_repository_path($repo);
        $rootBlocker = self::repository_root_blocker($logicalRepo);
        if ($rootBlocker !== null) {
            return self::blocked_repository_proposal($logicalRepo, $rootBlocker);
        }
        $binding = self::bind_repository_root($logicalRepo);
        try {
            return self::proposal_bound('.', $logicalRepo, $binding['identity']);
        } finally {
            if (!@chdir($binding['previous_cwd'])) {
                throw new \RuntimeException('duo: init could not restore its process working directory after proposal');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function proposal_bound(
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

        $attempt = self::read_init_attempt($repo);
        if (!$ownsInitAttempt && $attempt !== null) {
            return self::interrupted_attempt_proposal($attempt, $repo, $logicalRepo, $rootIdentity);
        }

        $git = self::git_probe($repo);
        $ledger = self::ledger_probe();
        $existing = self::existing_config($repo);
        $gitignoreIdentity = self::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore');
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
        $code = self::code_probe($activePlugins, $template, $stylesheet);
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

    /** @return ?array<string,mixed> */
    private static function read_init_attempt(string $repo): ?array {
        $path = rtrim($repo, '/') . '/' . self::ATTEMPT_FILE;
        $nextPath = rtrim($repo, '/') . '/' . self::ATTEMPT_NEXT_FILE;
        $hasAttempt = file_exists($path) || is_link($path);
        $hasNext = file_exists($nextPath) || is_link($nextPath);
        if (!$hasAttempt && $hasNext) {
            throw new \RuntimeException(
                'duo: interrupted init next-record exists without its canonical sealed attempt; retained it'
            );
        }
        if (!$hasAttempt) {
            return null;
        }
        $record = self::read_init_attempt_file($path, self::ATTEMPT_FILE);
        if ($hasNext) {
            $next = self::read_init_attempt_file($nextPath, self::ATTEMPT_NEXT_FILE);
            self::assert_init_attempt_transition($record, $next);
        }
        return $record;
    }

    /** @return array<string,mixed> */
    private static function read_init_attempt_file(string $path, string $label): array {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo: interrupted init $label is not an ordinary regular file");
        }
        $record = Canon::decode(Canon::read_file($path));
        if (!is_array($record)) {
            throw new \RuntimeException("duo: interrupted init $label is not an object");
        }
        $seal = $record['record_sha256'] ?? null;
        $payload = $record;
        unset($payload['record_sha256']);
        $expectedKeys = ['format', 'owned', 'phase', 'proposal', 'repository', 'repository_identity'];
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if (!is_string($seal) || preg_match('/^[a-f0-9]{64}$/D', $seal) !== 1
            || $keys !== $expectedKeys
            || !hash_equals($seal, hash('sha256', Canon::encode($payload)))
            || ($record['format'] ?? null) !== 'duo-init-attempt/v1'
            || !is_string($record['phase'] ?? null)
            || !is_array($record['owned'] ?? null)
            || !is_array($record['proposal'] ?? null)
            || !is_string($record['repository'] ?? null)
            || !is_string($record['repository_identity'] ?? null)) {
            throw new \RuntimeException("duo: interrupted init $label is malformed or unsealed");
        }
        return $record;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $next */
    private static function assert_init_attempt_transition(array $current, array $next): void {
        $phases = [
            'preparing', 'locked', 'git-planned', 'git-reserved', 'git-ready',
            'gitignore-planned', 'gitignore-ready', 'code-stage-planned',
            'code-staging', 'code-staged', 'code-root-planned',
            'code-root-reserved', 'code-publish-planned', 'code-ready',
            'config-planned', 'config-ready', 'media-planned', 'state-planned',
            'capture-ready', 'capture-payload-ready',
        ];
        $currentRank = array_search($current['phase'] ?? null, $phases, true);
        $nextRank = array_search($next['phase'] ?? null, $phases, true);
        $payloadRefresh = ($current['phase'] ?? null) === 'capture-payload-ready'
            && ($next['phase'] ?? null) === 'capture-payload-ready';
        if (!is_int($currentRank) || !is_int($nextRank)
            || (!$payloadRefresh && $nextRank <= $currentRank)
            || ($current['format'] ?? null) !== ($next['format'] ?? null)
            || ($current['repository'] ?? null) !== ($next['repository'] ?? null)
            || ($current['repository_identity'] ?? null) !== ($next['repository_identity'] ?? null)
            || Canon::encode($current['proposal'] ?? null) !== Canon::encode($next['proposal'] ?? null)) {
            throw new \RuntimeException(
                'duo: interrupted init next-record is not a forward transition of its canonical sealed attempt'
            );
        }
    }

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    private static function interrupted_attempt_proposal(
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
        if (($proposal['format'] ?? null) !== self::FORMAT
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
    private static function interrupted_attempt_manual_recovery_reason(string $repo, array $attempt): ?string {
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
                $actual = self::directory_identity($path, $label);
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
            if (str_starts_with($entry, 'state.capture-intent.tmp.')
                || str_starts_with($entry, 'state.capture-receipt.tmp.')) {
                return 'the interrupted-init repository contains an unbound capture record temporary artifact';
            }
            $knownInitArtifact = $entry === self::ATTEMPT_FILE
                || $entry === self::ATTEMPT_NEXT_FILE
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
            return 'the sealed attempt has a partial state staging root without a complete deletion manifest';
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
            return 'the sealed attempt has a partial code staging root without a complete descriptor';
        }
        if (is_string($stage) && $present($stage)) {
            $reason = $identityMismatch($stage, $owned['code_stage_identity'] ?? null, 'code staging root');
            if ($reason !== null) {
                return $reason;
            }
        }

        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if ($present($siteFile) && (is_link($siteFile) || !is_file($siteFile))) {
            return 'the sealed attempt has a non-regular site.duo.json boundary';
        }
        if ($present($siteFile)
            && !is_array($owned['site_publication'] ?? null)
            && !is_array($owned['site_plan'] ?? null)) {
            return 'the sealed attempt has an unbound site.duo.json';
        }
        $gitignore = rtrim($repo, '/') . '/.gitignore';
        if ($present($gitignore) && (is_link($gitignore) || !is_file($gitignore))) {
            return 'the sealed attempt has a non-regular .gitignore boundary';
        }
        if ($present($gitignore)
            && !is_array($owned['gitignore_publication'] ?? null)
            && !is_array($owned['gitignore_plan'] ?? null)) {
            return 'the sealed attempt has an unbound .gitignore';
        }
        $gitDir = rtrim($repo, '/') . '/.git';
        if (($owned['git_created'] ?? false) === true && $present($gitDir)
            && (is_link($gitDir) || !is_dir($gitDir))) {
            return 'the sealed attempt has a non-directory Git metadata boundary';
        }
        if (($owned['git_created'] ?? false) === true && $present($gitDir)
            && !is_string($owned['git_identity'] ?? null)
            && !is_string($owned['git_empty_identity'] ?? null)) {
            return 'the sealed attempt has incomplete Git metadata without a complete ownership manifest';
        }
        return null;
    }

    /** @param array<string,mixed> $attempt @return array{previous:?string,published:string} */
    private static function write_init_attempt(
        string $repo,
        array $attempt,
        string $expectedIdentity
    ): array {
        unset($attempt['record_sha256']);
        $attempt['record_sha256'] = hash('sha256', Canon::encode($attempt));
        $path = rtrim($repo, '/') . '/' . self::ATTEMPT_FILE;
        $bytes = Canon::encode($attempt);
        $parent = dirname($path);
        if ($expectedIdentity === 'absent') {
            Publish::write_file_fresh(
                $path,
                $bytes,
                self::ATTEMPT_FILE,
                Publish::directory_ownership_identity($parent)
            );
            return ['previous' => null, 'published' => self::regular_file_identity($path, self::ATTEMPT_FILE)];
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedIdentity) !== 1
            || !hash_equals($expectedIdentity, self::regular_file_identity($path, self::ATTEMPT_FILE))) {
            throw new \RuntimeException('duo: interrupted init record changed before its durable phase transition');
        }
        $tmp = $parent . '/' . self::ATTEMPT_NEXT_FILE;
        $tmpIdentity = Publish::write_file_fresh(
            $tmp,
            $bytes,
            self::ATTEMPT_FILE . ' transition',
            Publish::directory_ownership_identity($parent)
        );
        try {
            // Same-filesystem rename is the crash boundary: after a kill the
            // canonical name contains either the complete prior phase or the
            // complete next phase, never an absent hidden claim window.
            self::init_fault_checkpoint('attempt-transition-pre-rename');
            self::init_fault_checkpoint('attempt-transition-pre-rename-' . (string) $attempt['phase']);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('duo: interrupted init record phase transition could not be published');
            }
            Publish::sync_parent($path);
        } finally {
            if (file_exists($tmp) || is_link($tmp)) {
                Publish::remove_owned_file($tmp, $tmpIdentity, self::ATTEMPT_FILE . ' transition');
            }
        }
        return ['previous' => null, 'published' => self::regular_file_identity($path, self::ATTEMPT_FILE)];
    }

    /**
     * Finish a fully sealed fixed-slot journal transition after the recovery
     * lock is held. The next phase is already content-addressed and was
     * validated by read_init_attempt(); publishing it makes its completed
     * payload manifests available to the cleanup authority.
     *
     * @param array<string,mixed> $attempt
     * @param array{previous:?string,published:string} $publication
     * @return array{0:array<string,mixed>,1:array{previous:?string,published:string}}
     */
    private static function resolve_init_attempt_transition(
        string $repo,
        array $attempt,
        array $publication
    ): array {
        $path = rtrim($repo, '/') . '/' . self::ATTEMPT_FILE;
        $nextPath = rtrim($repo, '/') . '/' . self::ATTEMPT_NEXT_FILE;
        if (!file_exists($nextPath) && !is_link($nextPath)) {
            return [$attempt, $publication];
        }
        $next = self::read_init_attempt_file($nextPath, self::ATTEMPT_NEXT_FILE);
        self::assert_init_attempt_transition($attempt, $next);
        if (!hash_equals(
            (string) $publication['published'],
            self::regular_file_identity($path, self::ATTEMPT_FILE)
        )) {
            throw new \RuntimeException('duo: interrupted init canonical journal changed before transition recovery');
        }
        if (!@rename($nextPath, $path)) {
            throw new \RuntimeException('duo: interrupted init could not publish its sealed next journal phase');
        }
        Publish::sync_parent($path);
        return [
            $next,
            ['previous' => null, 'published' => self::regular_file_identity($path, self::ATTEMPT_FILE)],
        ];
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
    private static function recover_interrupted_attempt(
        string $repo,
        string $logicalRepo,
        array $rootStat,
        $lock,
        array $attempt,
        array $attemptPublication
    ): array {
        self::assert_repository_binding($logicalRepo, $rootStat);
        $currentAttempt = self::read_init_attempt($repo);
        if ($currentAttempt === null || Canon::encode($currentAttempt) !== Canon::encode($attempt)) {
            throw new \RuntimeException('duo: interrupted init record changed before recovery locking');
        }
        $stateDir = rtrim($repo, '/') . '/state';
        Publish::assert_lock_path($lock, $stateDir);
        $owned = (array) $attempt['owned'];
        if (is_string($owned['lock_inode'] ?? null)
            && !hash_equals(
                (string) $owned['lock_inode'],
                self::regular_file_inode_identity(Publish::lock_path($stateDir), 'state.capture.lock')
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
            self::remove_init_attempt_records(
                $repo,
                $logicalRepo,
                $attempt,
                $attemptPublication,
                true
            );
            self::assert_repository_binding($logicalRepo, $rootStat);
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
                || !hash_equals($stateIdentity, self::directory_identity($stateDir, 'initial state reservation'))) {
                throw new \RuntimeException(
                    'duo: interrupted init retained an incomplete state reservation without a complete ownership manifest; manual recovery is required'
                );
            }
            self::remove_owned_tree($stateDir, $stateIdentity, 'initial state reservation');
        }

        $mediaDir = rtrim($repo, '/') . '/media';
        if (is_dir($mediaDir) || is_link($mediaDir)) {
            $mediaManifest = $owned['media_manifest'] ?? null;
            if (is_array($mediaManifest)) {
                Publish::remove_owned_tree($mediaDir, $mediaManifest, 'initial media root');
            } else {
                $mediaIdentity = $owned['media_identity'] ?? null;
                if (!is_string($mediaIdentity)
                    || !hash_equals($mediaIdentity, self::directory_identity($mediaDir, 'media publication root'))) {
                    throw new \RuntimeException(
                        'duo: interrupted init retained a partial media root without a complete deletion manifest; manual recovery is required'
                    );
                }
                self::remove_owned_tree($mediaDir, $mediaIdentity, 'media publication root');
            }
        }

        $codeDir = rtrim($repo, '/') . '/code';
        if (is_dir($codeDir) || is_link($codeDir)) {
            $codeIdentity = $owned['code_identity'] ?? null;
            if (!is_string($codeIdentity)) {
                $codeIdentity = $owned['code_root_empty_identity'] ?? null;
                if (!is_string($codeIdentity)
                    || !hash_equals($codeIdentity, self::directory_identity($codeDir, 'code publication root'))) {
                    throw new \RuntimeException(
                        'duo: interrupted init retained an incomplete code root without a complete descriptor; manual recovery is required'
                    );
                }
            }
            self::remove_owned_tree($codeDir, $codeIdentity, 'code publication root');
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
            self::remove_owned_tree($stage, $stageIdentity, 'code staging root');
        }

        $sitePublication = $owned['site_publication'] ?? null;
        if (is_array($sitePublication)) {
            self::compensate_owned_file(
                rtrim($repo, '/') . '/site.duo.json',
                $sitePublication,
                'site.duo.json'
            );
        } elseif (file_exists(rtrim($repo, '/') . '/site.duo.json')
            || is_link(rtrim($repo, '/') . '/site.duo.json')) {
            $sitePlan = $owned['site_plan'] ?? null;
            if (!is_array($sitePlan)) {
                throw new \RuntimeException('duo: interrupted init has an unbound site.duo.json; retained it');
            }
            $expected = (string) ($sitePlan['expected_identity'] ?? '');
            $current = self::regular_file_identity(rtrim($repo, '/') . '/site.duo.json', 'site.duo.json');
            if (!hash_equals($expected, $current)) {
                self::compensate_owned_file(
                    rtrim($repo, '/') . '/site.duo.json',
                    ['previous' => $sitePlan['previous'] ?? null, 'published' => $current],
                    'site.duo.json'
                );
            }
        }

        $gitignorePublication = $owned['gitignore_publication'] ?? null;
        if (is_array($gitignorePublication)) {
            self::compensate_owned_file(
                rtrim($repo, '/') . '/.gitignore',
                $gitignorePublication,
                '.gitignore'
            );
        } elseif (is_array($owned['gitignore_plan'] ?? null)
            && (file_exists(rtrim($repo, '/') . '/.gitignore')
                || is_link(rtrim($repo, '/') . '/.gitignore'))) {
            $gitignorePlan = $owned['gitignore_plan'];
            $expected = (string) ($gitignorePlan['expected_identity'] ?? '');
            $current = self::regular_file_identity(rtrim($repo, '/') . '/.gitignore', '.gitignore');
            if (!hash_equals($expected, $current)) {
                self::compensate_owned_file(
                    rtrim($repo, '/') . '/.gitignore',
                    ['previous' => $gitignorePlan['previous'] ?? null, 'published' => $current],
                    '.gitignore'
                );
            }
        }
        if (($owned['git_created'] ?? false) === true) {
            $gitDir = rtrim($repo, '/') . '/.git';
            if (is_dir($gitDir) || is_link($gitDir)) {
                $gitIdentity = $owned['git_identity'] ?? null;
                if (!is_string($gitIdentity)) {
                    $gitIdentity = $owned['git_empty_identity'] ?? null;
                    if (!is_string($gitIdentity)
                        || !hash_equals($gitIdentity, self::directory_identity($gitDir, 'Git metadata root'))) {
                        throw new \RuntimeException(
                            'duo: interrupted init retained incomplete Git metadata without a complete ownership manifest; manual recovery is required'
                        );
                    }
                }
                self::remove_owned_tree($gitDir, $gitIdentity, 'Git metadata root');
            }
        }
        $postCleanupGit = self::git_probe($repo);
        if (($postCleanupGit['blockers'] ?? []) !== []) {
            throw new \RuntimeException(
                'duo: interrupted init retained an unjournalled repository artifact; inspect it before retrying'
            );
        }
        self::assert_repository_binding($logicalRepo, $rootStat);
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
        $proposal = $attempt['proposal'] ?? null;
        $expectedConfig = is_array($proposal) ? ($proposal['state']['config'] ?? null) : null;
        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if (!is_array($expectedConfig) || is_link($siteFile) || !is_file($siteFile)
            || Canon::read_file($siteFile) !== Canon::encode($expectedConfig)) {
            throw new \RuntimeException('duo: committed init site.duo.json no longer matches the confirmed proposal');
        }
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::compile($repo, $policy);
        $descriptor = $compiled->code_descriptor();
        $expectedRevision = is_array($proposal) ? ($proposal['code']['source_revision'] ?? null) : null;
        if (!is_array($descriptor) || !is_string($expectedRevision)
            || !hash_equals($expectedRevision, (string) ($descriptor['code_revision'] ?? ''))) {
            throw new \RuntimeException('duo: committed init code descriptor no longer matches the confirmed proposal');
        }
        $codeMismatch = Code::completed_code_mismatch($compiled);
        if ($codeMismatch !== null) {
            throw new \RuntimeException('duo: committed init code lifecycle is not complete: ' . $codeMismatch);
        }
        $git = self::git_probe($repo);
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

    /**
     * @param array<string,mixed> $attempt
     * @param array{previous:?string,published:string} $attemptPublication
     */
    private static function remove_init_attempt_records(
        string $repo,
        string $logicalRepo,
        array $attempt,
        array $attemptPublication,
        bool $completed = false
    ): void {
        $nextAttempt = rtrim($repo, '/') . '/' . self::ATTEMPT_NEXT_FILE;
        if (file_exists($nextAttempt) || is_link($nextAttempt)) {
            $next = self::read_init_attempt_file($nextAttempt, self::ATTEMPT_NEXT_FILE);
            self::assert_init_attempt_transition($attempt, $next);
            self::remove_exact_owned_file(
                $nextAttempt,
                self::regular_file_identity($nextAttempt, self::ATTEMPT_NEXT_FILE),
                self::ATTEMPT_NEXT_FILE
            );
        }
        self::remove_exact_owned_file(
            rtrim($repo, '/') . '/' . self::ATTEMPT_FILE,
            (string) $attemptPublication['published'],
            self::ATTEMPT_FILE,
            $completed
        );
    }

    /** @return array<string,mixed> */
    public static function confirm(string $repo, string $expectedDigest): array {
        $logicalRepo = self::normalize_repository_path($repo);
        $proposal = self::proposal($logicalRepo);
        self::assert_confirmed_proposal($proposal, $expectedDigest);

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

            $binding = self::bind_repository_root($logicalRepo);
            $previousCwd = $binding['previous_cwd'];
            $rootStat = $binding['stat'];
            $repo = '.';
            $siteFile = './site.duo.json';
            self::assert_repository_binding($logicalRepo, $rootStat);
            $reviewedIdentity = $proposal['state']['repository_identity'] ?? null;
            if (!is_string($reviewedIdentity)
                || !hash_equals($reviewedIdentity, $binding['identity'])) {
                throw new \RuntimeException(
                    'duo: init repository identity changed after confirmation and before publication locking'
                );
            }
            $stateDir = $repo . '/state';
            $lockPath = Publish::lock_path($stateDir);
            $attemptRecord = self::read_init_attempt($repo);
            if ($attemptRecord !== null) {
                $publicationLock = (file_exists($lockPath) || is_link($lockPath))
                    ? Publish::lock($stateDir)
                    : Publish::lock_new($stateDir);
                $lockOwnedAndCreated = true;
                Publish::assert_lock_path($publicationLock, $stateDir);
                $lockPublication = [
                    'previous' => null,
                    'published' => self::regular_file_identity($lockPath, 'state.capture.lock'),
                ];
                $attemptPublication = [
                    'previous' => null,
                    'published' => self::regular_file_identity(
                        $repo . '/' . self::ATTEMPT_FILE,
                        self::ATTEMPT_FILE
                    ),
                ];
                [$attemptRecord, $attemptPublication] = self::resolve_init_attempt_transition(
                    $repo,
                    $attemptRecord,
                    $attemptPublication
                );
                try {
                    $recoveryOutcome = self::recover_interrupted_attempt(
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
                        $finalGit = self::git_probe($repo);
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
                        self::remove_exact_owned_file(
                            $lockPath,
                            (string) $lockPublication['published'],
                            'state.capture.lock'
                        );
                        $lockOwnedAndCreated = false;
                        $lockPublication = null;
                    }
                    self::remove_init_attempt_records(
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
                throw new \RuntimeException(
                    'duo: interrupted pre-COMMIT init was safely rolled back; rerun duo init and confirm the fresh proposal'
                );
            }
            $attemptRecord = [
                'format' => 'duo-init-attempt/v1',
                'phase' => 'preparing',
                'repository' => $logicalRepo,
                'repository_identity' => $binding['identity'],
                'proposal' => $proposal,
                'owned' => ['lock_planned' => true],
            ];
            $attemptPublication = self::write_init_attempt($repo, $attemptRecord, 'absent');
            self::assert_absent_owned_path($lockPath, 'state.capture.lock');
            $publicationLock = Publish::lock_new($stateDir);
            $lockOwnedAndCreated = true;
            self::init_fault_checkpoint('lock-created');
            Publish::assert_lock_path($publicationLock, $stateDir);
            $lockPublication = [
                'previous' => null,
                'published' => self::regular_file_identity($lockPath, 'state.capture.lock'),
            ];
            $attemptRecord['phase'] = 'locked';
            $attemptRecord['owned']['lock_inode'] = self::regular_file_inode_identity(
                $lockPath,
                'state.capture.lock'
            );
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            self::assert_repository_binding($logicalRepo, $rootStat);
            Publish::assert_lock_path($publicationLock, $stateDir);

            // Recompute while BOTH the init advisory lease and the shared
            // state publication lock are held. The operator confirms facts,
            // never a mutable config payload; neither a second init nor an
            // ordinary capture can publish between this recheck and commit.
            $proposal = self::proposal_bound($repo, $logicalRepo, $binding['identity'], true, true);
            self::assert_confirmed_proposal($proposal, $expectedDigest);

            if (($proposal['state']['git']['mode'] ?? null) === 'initialize-on-confirm') {
                Publish::assert_lock_path($publicationLock, $stateDir);
                self::assert_repository_binding($logicalRepo, $rootStat);
                self::assert_absent_owned_path($repo . '/.git', 'Git metadata root');
                $attemptRecord['phase'] = 'git-planned';
                $attemptRecord['owned']['git_created'] = true;
                $attemptPublication = self::write_init_attempt(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
                if (!mkdir($repo . '/.git', 0775)) {
                    throw new \RuntimeException('duo: could not reserve the target Git metadata root');
                }
                $gitCreated = true;
                $gitRootIdentity = self::directory_inode_identity($repo . '/.git', 'Git metadata root');
                $attemptRecord['phase'] = 'git-reserved';
                $attemptRecord['owned']['git_created'] = true;
                $attemptRecord['owned']['git_root_inode'] = $gitRootIdentity;
                $attemptRecord['owned']['git_empty_identity'] = self::directory_identity(
                    $repo . '/.git',
                    'Git metadata root'
                );
                $attemptPublication = self::write_init_attempt(
                    $repo,
                    $attemptRecord,
                    (string) $attemptPublication['published']
                );
                self::assert_directory_inode($repo . '/.git', $gitRootIdentity, 'Git metadata root');
                self::initialize_git($repo);
                self::assert_directory_inode($repo . '/.git', $gitRootIdentity, 'Git metadata root');
                if (getenv('DUO_TEST_MODE') === '1'
                    && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'git-initialized-before-identity') {
                    throw new \RuntimeException(
                        'duo: injected failure after Git initialization and before its complete ownership manifest'
                    );
                }
                $gitIdentity = self::directory_identity($repo . '/.git', 'Git metadata root');
                $attemptRecord['phase'] = 'git-ready';
                $attemptRecord['owned']['git_identity'] = $gitIdentity;
                $attemptPublication = self::write_init_attempt(
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
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            $gitignorePublication = self::ensure_gitignore(
                $repo,
                (string) ($proposal['state']['gitignore_identity'] ?? '')
            );
            $attemptRecord['phase'] = 'gitignore-ready';
            $attemptRecord['owned']['gitignore_publication'] = $gitignorePublication;
            $attemptRecord['owned']['gitignore_identity'] = self::regular_file_identity(
                $repo . '/.gitignore',
                '.gitignore'
            );
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );

            self::assert_repository_binding($logicalRepo, $rootStat);
            Publish::assert_lock_path($publicationLock, $stateDir);
            [$descriptor, $stagedCode, $stagedCodeIdentity] = self::capture_code(
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
                    $attemptPublication = self::write_init_attempt(
                        $repo,
                        $attemptRecord,
                        (string) $attemptPublication['published']
                    );
                }
            );
            $attemptRecord['phase'] = 'code-staged';
            $attemptRecord['owned']['code_stage'] = $stagedCode;
            $attemptRecord['owned']['code_stage_identity'] = $stagedCodeIdentity;
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            $codeRoot = $repo . '/code';
            self::assert_repository_binding($logicalRepo, $rootStat);
            self::assert_absent_owned_path($codeRoot, 'code publication root');
            $attemptRecord['phase'] = 'code-root-planned';
            $attemptRecord['owned']['code_root_planned'] = true;
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (!mkdir($codeRoot, 0700)) {
                throw new \RuntimeException('duo: could not reserve the code publication root');
            }
            $codeRootCreated = true;
            $codeRootIdentity = self::directory_inode_identity($codeRoot, 'code publication root');
            $codeRootEmptyIdentity = self::directory_identity($codeRoot, 'code publication root');
            $attemptRecord['phase'] = 'code-root-reserved';
            $attemptRecord['owned']['code_root_inode'] = $codeRootIdentity;
            $attemptRecord['owned']['code_root_empty_identity'] = $codeRootEmptyIdentity;
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            self::assert_directory_inode($codeRoot, $codeRootIdentity, 'code publication root');
            self::assert_absent_owned_path($codeRoot . '/wp-content', 'code baseline child');
            if (!hash_equals(
                (string) $stagedCodeIdentity,
                self::directory_identity($stagedCode, 'code capture staging directory')
            )) {
                throw new \RuntimeException('duo: verified code staging changed before publication');
            }
            $attemptRecord['phase'] = 'code-publish-planned';
            $attemptPublication = self::write_init_attempt(
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
            self::assert_directory_inode($codeRoot, $codeRootIdentity, 'code publication root');
            $publishedDescriptor = Code::descriptor_from_source($codeRoot . '/wp-content');
            if (Canon::encode($publishedDescriptor) !== Canon::encode($descriptor)) {
                throw new \RuntimeException('duo: published code baseline differs from its reviewed descriptor');
            }
            $publishedCodeIdentity = self::directory_identity($codeRoot, 'code publication root');
            $attemptRecord['phase'] = 'code-ready';
            unset(
                $attemptRecord['owned']['code_stage'],
                $attemptRecord['owned']['code_stage_identity'],
                $attemptRecord['owned']['code_stage_root_inode'],
                $attemptRecord['owned']['code_stage_planned']
            );
            $attemptRecord['owned']['code_identity'] = $publishedCodeIdentity;
            $attemptPublication = self::write_init_attempt(
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
            $attemptPublication = self::write_init_attempt(
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
            $sitePublication = self::publish_owned_file(
                $siteFile,
                Canon::encode($proposal['state']['config']),
                (string) ($proposal['state']['config_identity'] ?? ''),
                'site.duo.json'
            );
            $attemptRecord['phase'] = 'config-ready';
            $attemptRecord['owned']['site_publication'] = $sitePublication;
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            Policy::load($repo);
            Publish::assert_lock_path($publicationLock, $stateDir);
            $mediaDir = $repo . '/media';
            self::assert_absent_owned_path($mediaDir, 'media publication root');
            $attemptRecord['phase'] = 'media-planned';
            $attemptRecord['owned']['media_planned'] = true;
            $attemptPublication = self::write_init_attempt(
                $repo,
                $attemptRecord,
                (string) $attemptPublication['published']
            );
            if (!mkdir($mediaDir, 0775)) {
                throw new \RuntimeException("duo: could not create media publication root $mediaDir");
            }
            $mediaCreated = true;
            $mediaIdentity = self::directory_identity($mediaDir, 'media publication root');
            $mediaRootIdentity = self::directory_inode_identity($mediaDir, 'media publication root');
            self::assert_absent_owned_path($stateDir, 'initial state reservation');
            $attemptRecord['phase'] = 'state-planned';
            $attemptRecord['owned']['media_root_inode'] = $mediaRootIdentity;
            $attemptRecord['owned']['media_identity'] = $mediaIdentity;
            $attemptRecord['owned']['state_planned'] = true;
            $attemptPublication = self::write_init_attempt(
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
            $stateIdentity = self::directory_identity($stateDir, 'initial state reservation');
            $attemptRecord['phase'] = 'capture-ready';
            $attemptRecord['owned']['media_root_inode'] = $mediaRootIdentity;
            $attemptRecord['owned']['media_identity'] = $mediaIdentity;
            $attemptRecord['owned']['state_identity'] = $stateIdentity;
            $attemptPublication = self::write_init_attempt(
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
            self::assert_repository_binding($logicalRepo, $rootStat);
            Publish::assert_lock_path($publicationLock, $stateDir);
            if (!hash_equals(
                (string) $lockPublication['published'],
                self::regular_file_identity($lockPath, 'state.capture.lock')
            )) {
                throw new \RuntimeException('duo: init capture lock pathname no longer names the held lock inode');
            }
            if (!hash_equals(
                (string) $sitePublication['published'],
                self::regular_file_identity($siteFile, 'site.duo.json')
            )) {
                throw new \RuntimeException('duo: init site.duo.json changed before initial capture');
            }
            if (is_array($gitignorePublication) && !hash_equals(
                (string) $gitignorePublication['published'],
                self::regular_file_identity($repo . '/.gitignore', '.gitignore')
            )) {
                throw new \RuntimeException('duo: init .gitignore changed before initial capture');
            }
            $reviewedGitignore = (string) ($proposal['state']['gitignore_identity'] ?? '');
            if (!is_array($gitignorePublication)
                && !hash_equals(
                    $reviewedGitignore,
                    self::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore')
                )) {
                throw new \RuntimeException('duo: init .gitignore changed before initial capture');
            }
            if (!hash_equals(
                (string) $publishedCodeIdentity,
                self::directory_identity($codeRoot, 'code publication root')
            )) {
                throw new \RuntimeException('duo: init code root changed before initial capture');
            }
            if (!hash_equals(
                (string) $mediaIdentity,
                self::directory_identity($mediaDir, 'media publication root')
            )) {
                throw new \RuntimeException('duo: init media root changed before initial capture');
            }
            if ($gitCreated && !hash_equals(
                (string) $gitIdentity,
                self::directory_identity($repo . '/.git', 'Git metadata root')
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
                    $attemptPublication = self::write_init_attempt(
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
            $finalGit = self::git_probe($repo);
            if ($finalGit['mode'] !== 'existing-worktree' || $finalGit['blockers'] !== []) {
                throw new \RuntimeException(
                    'duo: init baseline committed, but the target Git worktree changed before final verification'
                );
            }
            if (!hash_equals(
                (string) $publishedCodeIdentity,
                self::directory_identity($codeRoot, 'code publication root')
            ) || Canon::encode(Code::descriptor_from_source($codeRoot . '/wp-content')) !== Canon::encode($descriptor)) {
                throw new \RuntimeException(
                    'duo: init baseline committed, but the code tree changed before final verification'
                );
            }
            self::assert_repository_binding($logicalRepo, $rootStat);
            self::init_fault_checkpoint('capture-complete');
            if (is_array($attemptPublication) && is_array($attemptRecord)) {
                self::remove_init_attempt_records(
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
            if (is_string($stagedCode) && is_string($stagedCodeIdentity)
                && (file_exists($stagedCode) || is_link($stagedCode))) {
                self::remove_owned_tree($stagedCode, $stagedCodeIdentity, 'code staging root');
            }
            if ($mediaCreated && is_string($mediaIdentity)
                && (file_exists($repo . '/media') || is_link($repo . '/media'))) {
                self::remove_owned_tree($repo . '/media', $mediaIdentity, 'media publication root');
            }
            if ($stateReserved && is_string($stateIdentity)
                && is_dir($repo . '/state')
                && hash_equals($stateIdentity, self::directory_identity($repo . '/state', 'initial state reservation'))) {
                self::remove_owned_tree($repo . '/state', $stateIdentity, 'initial state reservation');
            }
            if ($publishedCode && is_string($publishedCodeIdentity)
                && (file_exists($repo . '/code') || is_link($repo . '/code'))) {
                self::remove_owned_tree($repo . '/code', $publishedCodeIdentity, 'code publication root');
            } elseif ($codeRootCreated && is_string($codeRootEmptyIdentity)
                && (file_exists($repo . '/code') || is_link($repo . '/code'))) {
                self::remove_owned_tree($repo . '/code', $codeRootEmptyIdentity, 'empty code publication root');
            }
            if (is_array($sitePublication)) {
                try {
                    self::compensate_owned_file($siteFile, $sitePublication, 'site.duo.json');
                } catch (\Throwable $restoreError) {
                    throw new \RuntimeException(
                        $error->getMessage() . "\nduo: init could not compensate its site.duo.json publication: " . $restoreError->getMessage(),
                        0,
                        $error
                    );
                }
            }
            if (is_array($gitignorePublication)) {
                self::compensate_owned_file($repo . '/.gitignore', $gitignorePublication, '.gitignore');
            }
            if ($gitCreated && is_string($gitIdentity)
                && (file_exists($repo . '/.git') || is_link($repo . '/.git'))) {
                self::remove_owned_tree($repo . '/.git', $gitIdentity, 'Git metadata root');
            }
            if (is_array($attemptPublication) && is_resource($publicationLock)) {
                try {
                    $diskAttempt = self::read_init_attempt($repo);
                    if (!is_array($diskAttempt)) {
                        throw new \RuntimeException(
                            'duo: init lost its sealed recovery journal before final compensation verification'
                        );
                    }
                    $diskPublication = [
                        'previous' => null,
                        'published' => self::regular_file_identity(
                            $repo . '/' . self::ATTEMPT_FILE,
                            self::ATTEMPT_FILE
                        ),
                    ];
                    [$diskAttempt, $diskPublication] = self::resolve_init_attempt_transition(
                        $repo,
                        $diskAttempt,
                        $diskPublication
                    );
                    $verifiedCleanup = self::recover_interrupted_attempt(
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
                self::remove_exact_owned_file(
                    Publish::lock_path($repo . '/state'),
                    (string) $lockPublication['published'],
                    'state.capture.lock'
                );
                $lockOwnedAndCreated = false;
                $lockPublication = null;
            }
            if (is_array($attemptPublication) && is_array($attemptRecord)
                && (file_exists($repo . '/' . self::ATTEMPT_FILE)
                    || is_link($repo . '/' . self::ATTEMPT_FILE))) {
                self::remove_init_attempt_records(
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
                self::remove_exact_owned_file(
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
            'identity' => self::regular_file_identity($file, 'site.duo.json'),
        ];
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

    private static function normalize_repository_path(string $repo): string {
        $repo = rtrim(trim($repo), '/');
        if ($repo === '' || $repo === '/' || !str_starts_with($repo, '/')) {
            throw new \RuntimeException('duo: init requires an absolute, non-root repository path');
        }
        if (preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $repo) === 1
            || str_contains($repo, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $repo) === 1) {
            throw new \RuntimeException('duo: init requires a normalized repository path without traversal or control bytes');
        }
        return $repo;
    }

    /** @return ?array<string,string> */
    private static function repository_root_blocker(string $repo): ?array {
        $current = '';
        $parts = explode('/', ltrim($repo, '/'));
        foreach ($parts as $index => $part) {
            $current .= '/' . $part;
            $stat = self::fresh_lstat($current);
            if ($stat === false) {
                return [
                    'code' => 'repository_root_missing', 'extension' => $repo, 'kind' => 'repository',
                    'reason' => 'the repository root and every parent must already exist before init can bind it safely',
                    'remediation' => 'create the ordinary repository root through the environment bootstrap/adopt path, then rerun init',
                ];
            }
            $type = ((int) $stat['mode']) & 0170000;
            if ($type === 0120000) {
                return [
                    'code' => 'unsafe_repository_root', 'extension' => $current, 'kind' => 'repository',
                    'reason' => 'the repository path contains a symbolic-link boundary',
                    'remediation' => 'use an existing ordinary directory reached through ordinary parent directories only',
                ];
            }
            if ($type !== 0040000) {
                return [
                    'code' => 'unsafe_repository_root', 'extension' => $current, 'kind' => 'repository',
                    'reason' => $index === count($parts) - 1
                        ? 'the repository root is not an ordinary directory'
                        : 'a repository parent is not an ordinary directory',
                    'remediation' => 'use an existing ordinary directory reached through ordinary parent directories only',
                ];
            }
        }
        return null;
    }

    /** @return array{previous_cwd:string,stat:array<string|int,mixed>,identity:string} */
    private static function bind_repository_root(string $repo): array {
        $blocker = self::repository_root_blocker($repo);
        if ($blocker !== null) {
            throw new \RuntimeException('duo: init cannot bind repository root: ' . $blocker['reason']);
        }
        $before = self::fresh_lstat($repo);
        $previous = getcwd();
        if ($before === false || !is_string($previous) || $previous === '') {
            throw new \RuntimeException('duo: init could not inspect its repository process boundary');
        }
        if (!@chdir($repo)) {
            throw new \RuntimeException('duo: init could not bind the reviewed repository directory');
        }
        try {
            $bound = self::fresh_lstat('.');
            if ($bound === false || !self::same_directory_identity($before, $bound)) {
                throw new \RuntimeException('duo: init repository root changed while it was being bound');
            }
            self::assert_repository_binding($repo, $bound);
            return [
                'previous_cwd' => $previous,
                'stat' => $bound,
                'identity' => 'sha256:' . hash('sha256', Canon::encode([
                    'device' => (string) $bound['dev'],
                    'inode' => (string) $bound['ino'],
                ])),
            ];
        } catch (\Throwable $error) {
            @chdir($previous);
            throw $error;
        }
    }

    /** @param array<string|int,mixed> $expected */
    private static function assert_repository_binding(string $repo, array $expected): void {
        $blocker = self::repository_root_blocker($repo);
        $lexical = $blocker === null ? self::fresh_lstat($repo) : false;
        $bound = self::fresh_lstat('.');
        if ($blocker !== null || $lexical === false || $bound === false
            || !self::same_directory_identity($expected, $lexical)
            || !self::same_directory_identity($expected, $bound)) {
            throw new \RuntimeException('duo: init repository root changed after review; no lexical child path will be followed');
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function same_directory_identity(array $left, array $right): bool {
        return (((int) ($left['mode'] ?? 0)) & 0170000) === 0040000
            && (((int) ($right['mode'] ?? 0)) & 0170000) === 0040000
            && (string) ($left['dev'] ?? '') === (string) ($right['dev'] ?? '')
            && (string) ($left['ino'] ?? '') === (string) ($right['ino'] ?? '');
    }

    /** @return array<string|int,mixed>|false */
    private static function fresh_lstat(string $path): array|false {
        clearstatcache(true, $path);
        return @lstat($path);
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
                    'limits' => ['rows_per_surface' => self::RISK_ROW_LIMIT, 'bytes_per_surface' => self::RISK_BYTE_LIMIT],
                    'truncated' => false,
                ],
            ],
            'unsupported' => [$blocker],
            'ready' => false,
        ];
        $proposal['digest'] = hash('sha256', Canon::encode($proposal));
        return $proposal;
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
        if (is_link($repo . '/.gitignore')
            || (file_exists($repo . '/.gitignore') && !is_file($repo . '/.gitignore'))) {
            $blockers[] = [
                'code' => 'unsafe_gitignore', 'extension' => '.gitignore', 'kind' => 'repository',
                'reason' => 'the repository ignore path is not an ordinary regular file',
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
            'code', 'media', 'site.duo.json', 'state', 'state.capture.lock', 'state.capture-receipt',
            self::ATTEMPT_FILE, self::ATTEMPT_NEXT_FILE,
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
        if ($result['exit'] !== 0 || self::git_probe($repo)['mode'] !== 'existing-worktree') {
            throw new \RuntimeException('duo: init could not create and verify the target Git worktree');
        }
    }

    /** @return ?array{previous:?string,published:string} */
    private static function ensure_gitignore(string $repo, string $expectedIdentity): ?array {
        $path = $repo . '/.gitignore';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo: init refuses a non-file .gitignore boundary');
        }
        $previous = is_file($path) ? Canon::read_file($path) : null;
        $identity = $previous === null ? 'absent' : self::regular_file_identity($path, '.gitignore');
        if ($expectedIdentity === '' || !hash_equals($expectedIdentity, $identity)) {
            throw new \RuntimeException('duo: init .gitignore boundary changed after proposal review');
        }
        $required = [
            '.tmp*', '.duo-init-code-*', '.*.duo-init-*', self::ATTEMPT_FILE, self::ATTEMPT_NEXT_FILE,
            'state.capture.lock', 'state.capture-staging/', 'state.capture-backup/',
            'state.capture-intent', 'state.capture-receipt', 'state.capture-intent.tmp.*',
            'state.capture-receipt.tmp.*', 'state.capture-intent.previous',
            'state.capture-intent.next', 'state.capture-receipt.previous',
            'state.capture-receipt.next', '.duo-env-values.json',
        ];
        $lines = $previous === null ? [] : preg_split('/\r?\n/', $previous);
        $known = array_fill_keys(is_array($lines) ? $lines : [], true);
        $missing = array_values(array_filter($required, static fn(string $line): bool => !isset($known[$line])));
        if ($missing === []) {
            return null;
        }
        $next = $previous ?? '';
        if ($next !== '' && !str_ends_with($next, "\n")) {
            $next .= "\n";
        }
        if ($next !== '') {
            $next .= "\n";
        }
        $next .= "# Duo local publication and environment artifacts\n" . implode("\n", $missing) . "\n";
        return self::publish_owned_file($path, $next, $identity, '.gitignore');
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

    private static function regular_file_identity(string $path, string $label): string {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo: init lost ownership of repository-owned $label path $path");
        }
        $stat = @lstat($path);
        $raw = Canon::read_file($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("duo: init could not identify repository-owned $label path $path");
        }
        return 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'sha256' => hash('sha256', $raw),
        ]));
    }

    private static function regular_file_inode_identity(string $path, string $label): string {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo: init lost ownership of repository-owned $label path $path");
        }
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("duo: init could not identify repository-owned $label path $path");
        }
        return 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
        ]));
    }

    private static function owned_file_boundary_identity(string $path, string $label): string {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return 'unsafe';
        }
        return is_file($path) ? self::regular_file_identity($path, $label) : 'absent';
    }

    /** @return array{previous:?string,published:string} */
    private static function publish_owned_file(
        string $path,
        string $content,
        string $expectedIdentity,
        string $label
    ): array {
        $dir = dirname($path);
        if (is_link($dir) || !is_dir($dir)) {
            throw new \RuntimeException("duo: init refuses unsafe parent for repository-owned $label path $path");
        }
        $tmp = $dir . '/.' . basename($path) . '.duo-init-' . bin2hex(random_bytes(8));
        $previous = null;
        try {
            Canon::write_file($tmp, $content);
            self::init_fault_checkpoint('owned-file-temp');
            if ($expectedIdentity === 'absent') {
                if (!@link($tmp, $path)) {
                    throw new \RuntimeException(
                        "duo: init $label boundary changed after review; a concurrent writer was preserved"
                    );
                }
            } else {
                if (preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedIdentity) !== 1) {
                    throw new \RuntimeException("duo: init has no valid reviewed identity for $label");
                }
                self::assert_regular_file_or_absent($path, $label);
                if (!hash_equals($expectedIdentity, self::regular_file_identity($path, $label))) {
                    throw new \RuntimeException(
                        "duo: init $label boundary changed after review; the concurrent bytes were preserved"
                    );
                }
                $previous = Canon::read_file($path);
                // Repository-wide external-writer exclusion makes atomic
                // same-filesystem replacement the crash-safe boundary: the
                // canonical name always contains the complete old or new
                // file, never an absent hidden claim.
                if (!@rename($tmp, $path)) {
                    throw new \RuntimeException("duo: init could not publish the reviewed $label boundary");
                }
            }
            Publish::sync_parent($path);
            return ['previous' => $previous, 'published' => self::regular_file_identity($path, $label)];
        } finally {
            if (is_file($tmp) || is_link($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function restore_claimed_file(string $claim, string $path, string $label): void {
        if (!is_file($claim) || is_link($claim) || !@link($claim, $path)) {
            throw new \RuntimeException(
                "duo: init retained a raced $label boundary at $claim because $path is no longer absent"
            );
        }
        @unlink($claim);
    }

    /** @param array{previous:?string,published:string} $publication */
    private static function compensate_owned_file(string $path, array $publication, string $label): void {
        if (!is_file($path) || is_link($path)
            || !hash_equals((string) $publication['published'], self::regular_file_identity($path, $label))) {
            throw new \RuntimeException("duo: init preserved a replacement $label instead of deleting external bytes");
        }
        $dir = dirname($path);
        $claim = $dir . '/.' . basename($path) . '.duo-init-compensate-' . bin2hex(random_bytes(8));
        if (!@rename($path, $claim)) {
            throw new \RuntimeException("duo: init could not claim its published $label for compensation");
        }
        self::init_fault_checkpoint('owned-file-claim');
        if (!hash_equals((string) $publication['published'], self::regular_file_identity($claim, $label))) {
            self::restore_claimed_file($claim, $path, $label);
            throw new \RuntimeException("duo: init preserved a raced $label during compensation");
        }
        $previous = $publication['previous'];
        if ($previous !== null) {
            $restore = $dir . '/.' . basename($path) . '.duo-init-restore-' . bin2hex(random_bytes(8));
            Canon::write_file($restore, $previous);
            self::init_fault_checkpoint('owned-file-restore');
            if (!@link($restore, $path)) {
                throw new \RuntimeException(
                    "duo: init retained its prior $label at $restore because a concurrent writer owns $path"
                );
            }
            @unlink($restore);
        }
        @unlink($claim);
    }

    /**
     * Remove an exact first-init-owned file without ever making its canonical
     * name disappear into an unjournalled claim. Under the proposal's
     * repository-wide writer exclusion, unlink is the crash boundary: a kill
     * leaves either the complete sealed journal or no journal after the fully
     * verified tuple has become authoritative.
     */
    private static function remove_exact_owned_file(
        string $path,
        string $expectedIdentity,
        string $label,
        bool $completedJournal = false
    ): void {
        if (!is_file($path) || is_link($path)
            || !hash_equals($expectedIdentity, self::regular_file_identity($path, $label))) {
            throw new \RuntimeException("duo: init preserved a replacement $label instead of removing external bytes");
        }
        if ($completedJournal) {
            self::init_fault_checkpoint('attempt-remove-pre-unlink');
        }
        if (!@unlink($path)) {
            throw new \RuntimeException("duo: init could not remove its exact $label");
        }
        Publish::sync_parent($path);
        if ($completedJournal) {
            self::init_fault_checkpoint('attempt-remove-post-unlink');
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

    /** @return array{0:array<string,mixed>,1:string,2:string} descriptor, staging root, ownership identity */
    private static function capture_code(string $repo, array $code, ?callable $onStage = null): array {
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
        if ($onStage !== null) {
            $onStage($stage, null);
        }
        $stageParent = dirname($stage);
        $stagePublication = Publish::create_directory_fresh(
            $stageParent,
            Publish::directory_ownership_identity($stageParent),
            basename($stage),
            0775,
            'code capture staging directory'
        );
        $stageIdentity = self::directory_identity($stage, 'code capture staging directory');
        $stageRootIdentity = self::directory_inode_identity($stage, 'code capture staging directory');
        $ownedDirs = ['' => $stagePublication];
        if ($onStage !== null) {
            $onStage($stage, $stageRootIdentity);
        }
        try {
            foreach ((array) ($code['components'] ?? []) as $rootName => $names) {
                $sourceRoot = (string) (($code['roots'][$rootName] ?? null) ?: '');
                foreach ((array) $names as $name) {
                    self::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
                    self::copy_code_path(
                        rtrim($sourceRoot, '/') . '/' . $name,
                        $stage . '/' . $rootName . '/' . $name,
                        $stage,
                        $stageRootIdentity,
                        $ownedDirs,
                        $stageIdentity
                    );
                }
            }
            self::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
            self::assert_staged_code_no_secrets($stage);
            $descriptor = Code::descriptor_from_source($stage);
            $copiedRevision = hash('sha256', Canon::encode($descriptor['files']));
            if (!hash_equals($revision, $copiedRevision)) {
                throw new \RuntimeException('duo: code changed while the baseline was being copied; rerun init');
            }
            return [$descriptor, $stage, self::directory_identity($stage, 'code capture staging directory')];
        } catch (\Throwable $error) {
            try {
                // A bound copy can create its destination and then reject a
                // changed source digest before the caller refreshes the stage
                // manifest. Re-identify the Duo-created partial tree under the
                // confirmed repository-writer exclusion before compensating it.
                $currentIdentity = self::directory_identity($stage, 'code capture staging directory');
                self::remove_owned_tree($stage, $currentIdentity, 'code capture staging directory');
            } catch (\Throwable $cleanupError) {
                throw new InitAttemptRetentionException(
                    $error->getMessage()
                    . "\nduo: init retained the partial code staging tree for sealed fresh-process recovery: "
                    . $cleanupError->getMessage(),
                    0,
                    $error
                );
            }
            throw $error;
        }
    }

    /** @param array<string,array<string,string>> $ownedDirs */
    private static function copy_code_path(
        string $source,
        string $destination,
        string $stage,
        string $stageRootIdentity,
        array &$ownedDirs,
        string &$stageIdentity
    ): void {
        if (is_link($source)) {
            throw new \RuntimeException("duo: refusing symbolic-link code source $source");
        }
        if (is_file($source)) {
            $parent = dirname($destination);
            self::ensure_code_stage_directory($stage, $parent, $stageRootIdentity, $ownedDirs, $stageIdentity);
            $parentKey = trim(substr($parent, strlen(rtrim($stage, '/'))), '/');
            self::copy_code_file_fresh(
                $source,
                $destination,
                $stage,
                $stageRootIdentity,
                $ownedDirs[$parentKey]
            );
            $stageIdentity = self::directory_identity($stage, 'code capture staging directory');
            return;
        }
        if (!is_dir($source)) {
            throw new \RuntimeException("duo: code source disappeared before copy: $source");
        }
        self::ensure_code_stage_directory($stage, $destination, $stageRootIdentity, $ownedDirs, $stageIdentity);
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
                self::ensure_code_stage_directory($stage, $target, $stageRootIdentity, $ownedDirs, $stageIdentity);
                continue;
            }
            if (!$item->isFile()) {
                throw new \RuntimeException("duo: could not copy regular code file $path");
            }
            self::ensure_code_stage_directory($stage, dirname($target), $stageRootIdentity, $ownedDirs, $stageIdentity);
            $parentKey = trim(substr(dirname($target), strlen(rtrim($stage, '/'))), '/');
            self::copy_code_file_fresh(
                $path,
                $target,
                $stage,
                $stageRootIdentity,
                $ownedDirs[$parentKey]
            );
            $stageIdentity = self::directory_identity($stage, 'code capture staging directory');
        }
    }

    /** @param array<string,array<string,string>> $ownedDirs */
    private static function ensure_code_stage_directory(
        string $stage,
        string $directory,
        string $stageRootIdentity,
        array &$ownedDirs,
        string &$stageIdentity
    ): void {
        self::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
        $prefix = rtrim($stage, '/') . '/';
        if (!str_starts_with($directory . '/', $prefix)) {
            throw new \RuntimeException('duo: code staging destination escaped its owned root');
        }
        $relative = trim(substr($directory, strlen(rtrim($stage, '/'))), '/');
        $current = rtrim($stage, '/');
        $key = '';
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            if (!self::safe_component($part)) {
                throw new \RuntimeException('duo: code staging destination has an unsafe component');
            }
            $key = $key === '' ? $part : $key . '/' . $part;
            if (!isset($ownedDirs[$key])) {
                $parentKey = str_contains($key, '/') ? substr($key, 0, (int) strrpos($key, '/')) : '';
                $ownedDirs[$key] = Publish::create_directory_fresh(
                    $current,
                    $ownedDirs[$parentKey],
                    $part,
                    0775,
                    'code staging child directory'
                );
                $current .= '/' . $part;
                $stageIdentity = self::directory_identity($stage, 'code capture staging directory');
            } else {
                $current .= '/' . $part;
                if (Publish::directory_ownership_identity($current) !== $ownedDirs[$key]) {
                    throw new \RuntimeException('duo: code staging child directory changed identity');
                }
            }
        }
        self::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
    }

    private static function copy_code_file_fresh(
        string $source,
        string $destination,
        string $stage,
        string $stageRootIdentity,
        array $expectedParent
    ): void {
        self::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
        if (file_exists($destination) || is_link($destination)) {
            throw new \RuntimeException('duo: code staging gained an unowned destination file');
        }
        $digest = @hash_file('sha256', $source);
        $mode = @fileperms($source);
        if (!is_string($digest) || !is_int($mode)) {
            throw new \RuntimeException('duo: could not identify the reviewed code source before copy');
        }
        $expectedDigest = $digest;
        if (getenv('DUO_TEST_MODE') === '1'
            && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'code-copy-after-file') {
            // Exercise the bound helper's post-create source-digest refusal:
            // the helper must remove only the destination inode it created.
            $expectedDigest = str_repeat('0', 64);
        }
        Publish::copy_file_fresh(
            $source,
            $destination,
            $expectedDigest,
            $mode & 0777,
            'code staging file',
            $expectedParent
        );
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

    private static function init_fault_checkpoint(string $phase): void {
        if (getenv('DUO_TEST_MODE') !== '1'
            || (string) getenv('DUO_TEST_INIT_KILL_PHASE') !== $phase) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill(getmypid(), defined('SIGKILL') ? SIGKILL : 9);
        }
        exit(137);
    }

    private static function directory_inode_identity(string $path, string $label): string {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new \RuntimeException("duo: init lost ownership of $label $path");
        }
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("duo: init could not identify $label $path");
        }
        return 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
        ]));
    }

    private static function assert_directory_inode(string $path, string $expected, string $label): void {
        $actual = self::directory_inode_identity($path, $label);
        if (!hash_equals($expected, $actual)) {
            throw new \RuntimeException("duo: init preserved a replacement $label instead of writing through it");
        }
    }

    private static function directory_identity(string $path, string $label): string {
        $inode = self::directory_inode_identity($path, $label);
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("duo: init could not identify $label $path");
        }
        $rows = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $prefixLength = strlen(rtrim($path, '/')) + 1;
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            $relative = substr($itemPath, $prefixLength);
            $childStat = @lstat($itemPath);
            if (!is_array($childStat) || !isset($childStat['dev'], $childStat['ino'])) {
                throw new \RuntimeException("duo: init could not identify $label child $relative");
            }
            $ownership = ['dev' => (string) $childStat['dev'], 'ino' => (string) $childStat['ino']];
            if ($item->isLink()) {
                $rows[] = ['path' => $relative, 'type' => 'link', 'ownership' => $ownership, 'target' => (string) readlink($itemPath)];
            } elseif ($item->isDir()) {
                $rows[] = ['path' => $relative, 'type' => 'directory', 'ownership' => $ownership];
            } elseif ($item->isFile()) {
                $digest = hash_file('sha256', $itemPath);
                if (!is_string($digest)) {
                    throw new \RuntimeException("duo: init could not hash $label child $relative");
                }
                $rows[] = ['path' => $relative, 'type' => 'file', 'ownership' => $ownership, 'sha256' => $digest];
            } else {
                $rows[] = ['path' => $relative, 'type' => 'special', 'ownership' => $ownership];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return 'sha256:' . hash('sha256', Canon::encode([
            'inode' => $inode,
            'tree' => $rows,
        ]));
    }

    /**
     * Claim and remove a manifest-matching tree. Stable replacements are
     * preserved; the operator-confirmed external-writer exclusion remains
     * authoritative across the claim/recursive-cleanup window.
     */
    private static function remove_owned_tree(string $path, string $identity, string $label): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)
            || !hash_equals($identity, self::directory_identity($path, $label))) {
            throw new \RuntimeException("duo: init preserved a replacement $label instead of deleting external data");
        }
        $claim = dirname($path) . '/.' . basename($path) . '.duo-init-remove-' . bin2hex(random_bytes(8));
        if (!@rename($path, $claim)) {
            throw new \RuntimeException("duo: init could not claim its $label for cleanup");
        }
        self::init_fault_checkpoint('owned-tree-claim');
        if (!hash_equals($identity, self::directory_identity($claim, $label))) {
            if (!file_exists($path) && !is_link($path)) {
                @rename($claim, $path);
            }
            throw new \RuntimeException(
                "duo: init retained a raced $label at $claim instead of recursively deleting unowned data"
            );
        }
        self::remove_tree($claim);
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
