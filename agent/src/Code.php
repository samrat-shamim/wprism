<?php
namespace Duo;

/**
 * v0 code-half payload support.
 *
 * The code half deliberately starts with a small, explicit contract rather
 * than trying to guess how an arbitrary WordPress checkout is laid out.  A
 * site opts in from site.duo.json with:
 *
 *   "code": {"format": 1, "layout": "wp-content", "source": "code/wp-content"}
 *
 * Only the three WordPress content component roots are payload-owned.  The
 * compiler inventories their regular files and the stage/finalize commands
 * use that immutable inventory as their source of truth.  In particular,
 * stage never deletes old files: lifecycle hooks are allowed to run between
 * stage and finalize, and finalize is the only operation which removes a
 * path previously recorded as Duo-owned.
 */
final class CodeCompilationException extends \RuntimeException {
    /** @var list<array{severity:string,code:string,path:string,locator:string,message:string}> */
    public array $diagnostics;

    public function __construct(array $diagnostics) {
        $this->diagnostics = array_values($diagnostics);
        $lines = array_map(static function (array $d): string {
            $where = $d['path'] . (($d['locator'] ?? '') !== '' ? ':' . $d['locator'] : '');
            return '[' . $d['code'] . '] ' . $where . ' — ' . $d['message'];
        }, $this->diagnostics);
        parent::__construct(
            'duo: code payload validation failed (' . count($this->diagnostics)
            . " blocking diagnostic(s)); no target code was staged:\n  - "
            . implode("\n  - ", $lines)
        );
    }

    public function payload(): array {
        return [
            'ok' => false,
            'error' => 'code_compilation_failed',
            'diagnostics' => $this->diagnostics,
        ];
    }
}

final class Code {
    public const DESCRIPTOR_FORMAT = 'duo-code/v1';
    public const LAYOUT = 'wp-content';
    public const SOURCE = 'code/wp-content';

    /** Keys in duo_kv. These are intentionally stable integration points. */
    public const CODE_REVISION_KEY = 'code_revision';
    public const CODE_DESCRIPTOR_KEY = 'code_descriptor';
    public const CODE_STAGE_REVISION_KEY = 'code_stage_revision';
    /** Temporary descriptor retained until finalize succeeds. */
    public const CODE_STAGE_DESCRIPTOR_KEY = 'code_stage_descriptor';
    /** Artifact identity bound to the temporary staged descriptor. */
    public const CODE_STAGE_ARTIFACT_KEY = 'code_stage_artifact';
    /** Canonical list of prior staged descriptors retained for recovery. */
    public const CODE_STAGE_HISTORY_KEY = 'code_stage_history';

    /** @var list<string> */
    private const ROOTS = ['mu-plugins', 'plugins', 'themes'];

    /**
     * Compile the opted-in code payload. A missing config is the legacy
     * repository shape and remains a no-op for every code-half consumer.
     *
     * @return ?array<string,mixed>
     */
    public static function compile(string $repo, ?array $config): ?array {
        if ($config === null) {
            return null;
        }
        self::assert_config($config);
        return self::descriptor_from_source(rtrim($repo, '/') . '/' . self::SOURCE);
    }

    /**
     * Build a deterministic descriptor from one source directory. This is
     * public for offline tests and for stage's re-check that the source did
     * not change after the immutable artifact was compiled.
     *
     * @return array<string,mixed>
     */
    public static function descriptor_from_source(string $source): array {
        $diagnostics = [];
        $source = rtrim($source, '/');
        if (is_link($source)) {
            self::diagnostic($diagnostics, 'unsafe_code_path', self::SOURCE, '', 'the code payload root is a symbolic link');
        } elseif (!is_dir($source)) {
            self::diagnostic($diagnostics, 'code_source_missing', self::SOURCE, '', 'site.duo.json opts into code materialization but code/wp-content is missing');
        }

        $files = [];
        $pluginMainFiles = [];
        $ownedRoots = [];
        $themeSlugs = [];
        if (is_dir($source) && !is_link($source)) {
            $children = @scandir($source);
            if ($children === false) {
                self::diagnostic($diagnostics, 'unsafe_code_path', self::SOURCE, '', 'the code payload root cannot be read');
            } else {
                foreach ($children as $child) {
                    if ($child === '.' || $child === '..') {
                        continue;
                    }
                    $absolute = $source . '/' . $child;
                    if (!in_array($child, self::ROOTS, true)) {
                        self::diagnostic(
                            $diagnostics,
                            'unsafe_code_path',
                            self::SOURCE . '/' . $child,
                            '',
                            'the payload may contain only plugins/, themes/, and mu-plugins/'
                        );
                        continue;
                    }
                    if (is_link($absolute)) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', $child, '', 'symbolic links are not valid code payload entries');
                        continue;
                    }
                    if (!is_dir($absolute)) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', $child, '', 'code payload components must be directories');
                        continue;
                    }
                    $rootChildren = @scandir($absolute);
                    if ($rootChildren === false) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', $child, '', 'code payload component root cannot be read');
                        continue;
                    }
                    foreach ($rootChildren as $component) {
                        if ($component === '.' || $component === '..') {
                            continue;
                        }
                        if (!self::safe_component($component)) {
                            self::diagnostic(
                                $diagnostics,
                                'unsafe_code_path',
                                $child . '/' . $component,
                                '',
                                'component names must be a single safe path segment'
                            );
                            continue;
                        }
                        $componentAbsolute = $absolute . '/' . $component;
                        $componentRelative = $child . '/' . $component;
                        if (is_link($componentAbsolute)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'symbolic links are not valid code payload entries');
                            continue;
                        }
                        if ($child === 'themes' && !is_dir($componentAbsolute)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'theme components must be directories');
                            continue;
                        }
                        if (!is_dir($componentAbsolute) && !is_file($componentAbsolute)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'code payload entries must be regular files or directories');
                            continue;
                        }
                        if (self::reserved_path($componentRelative)) {
                            self::diagnostic($diagnostics, 'code_loader_collision', $componentRelative, '', 'payload may not own mu-plugins/duo or mu-plugins/duo-loader.php');
                            continue;
                        }
                        $ownedRoots[] = $componentRelative;
                        if ($child === 'themes') {
                            $style = $componentAbsolute . '/style.css';
                            if (is_link($style) || !is_file($style)) {
                                self::diagnostic($diagnostics, 'invalid_theme', $componentRelative, 'style.css', 'each theme must contain a regular style.css with a Theme Name header');
                            } elseif (self::header_value($style, 'Theme Name') === null) {
                                self::diagnostic($diagnostics, 'invalid_theme', $componentRelative, 'style.css', 'style.css must contain a non-empty Theme Name header in its first 8KB');
                            }
                            $themeSlugs[] = $component;
                        }
                        try {
                            self::walk_component($source, $child, $componentAbsolute, $files, $pluginMainFiles, $diagnostics);
                        } catch (\Throwable $t) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'could not safely enumerate code payload component: ' . $t->getMessage());
                        }
                    }
                }
            }
        }

        if ($diagnostics) {
            self::throw_diagnostics($diagnostics);
        }

        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        usort($pluginMainFiles, static fn(array $a, array $b): int => $a['basename'] <=> $b['basename']);
        sort($ownedRoots, SORT_STRING);
        sort($themeSlugs, SORT_STRING);

        $base = [
            'format' => self::DESCRIPTOR_FORMAT,
            'layout' => self::LAYOUT,
            'source' => self::SOURCE,
            'owned_roots' => $ownedRoots,
            'files' => $files,
            'plugin_main_files' => $pluginMainFiles,
            'theme_slugs' => $themeSlugs,
        ];
        $base['code_revision'] = self::revision_for($base);
        self::assert_descriptor($base);
        return $base;
    }

    /** Validate a site.duo.json code declaration independently of Policy. */
    public static function assert_config(array $config): void {
        $keys = array_keys($config);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'layout', 'source']
            || $config['format'] !== 1
            || $config['layout'] !== self::LAYOUT
            || $config['source'] !== self::SOURCE) {
            throw new \RuntimeException(
                'duo: site.duo.json code must contain exactly '
                . '{"format":1,"layout":"wp-content","source":"code/wp-content"}'
            );
        }
    }

    /** Validate a descriptor loaded from a compiled artifact or ledger. */
    public static function assert_descriptor(array $descriptor): void {
        $required = ['code_revision', 'files', 'format', 'layout', 'owned_roots', 'plugin_main_files', 'source', 'theme_slugs'];
        $keys = array_keys($descriptor);
        sort($keys, SORT_STRING);
        $expected = $required;
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || $descriptor['format'] !== self::DESCRIPTOR_FORMAT
            || $descriptor['layout'] !== self::LAYOUT
            || $descriptor['source'] !== self::SOURCE
            || !is_array($descriptor['owned_roots']) || !array_is_list($descriptor['owned_roots'])
            || !is_array($descriptor['files']) || !array_is_list($descriptor['files'])
            || !is_array($descriptor['plugin_main_files']) || !array_is_list($descriptor['plugin_main_files'])
            || !is_array($descriptor['theme_slugs']) || !array_is_list($descriptor['theme_slugs'])
            || !preg_match('/^[0-9a-f]{64}$/', (string) $descriptor['code_revision'])) {
            throw new \RuntimeException('duo: compiled code descriptor has an unsupported or malformed shape');
        }

        $ownedRoots = [];
        foreach ($descriptor['owned_roots'] as $i => $root) {
            if (!is_string($root) || !self::safe_component_root($root) || self::reserved_path($root)) {
                throw new \RuntimeException("duo: compiled code descriptor owned_roots[$i] is malformed");
            }
            if (isset($ownedRoots[$root])) {
                throw new \RuntimeException("duo: compiled code descriptor contains duplicate owned root '$root'");
            }
            $ownedRoots[$root] = true;
        }
        $sortedRoots = array_keys($ownedRoots);
        $expectedRoots = $sortedRoots;
        sort($expectedRoots, SORT_STRING);
        if ($descriptor['owned_roots'] !== $expectedRoots) {
            throw new \RuntimeException('duo: compiled code descriptor owned_roots are not deterministically sorted');
        }

        $files = [];
        $fileRows = [];
        foreach ($descriptor['files'] as $i => $row) {
            if (!is_array($row) || array_keys($row) !== ['path', 'sha256']
                || !self::safe_relative((string) ($row['path'] ?? ''))
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($row['sha256'] ?? ''))) {
                throw new \RuntimeException("duo: compiled code descriptor files[$i] is malformed");
            }
            $path = (string) $row['path'];
            if (isset($files[$path])) {
                throw new \RuntimeException("duo: compiled code descriptor contains duplicate file '$path'");
            }
            $files[$path] = (string) $row['sha256'];
            $fileRows[] = $path;
            if (!self::owned_path($path, $ownedRoots)) {
                throw new \RuntimeException("duo: compiled code descriptor contains file outside owned roots '$path'");
            }
            if (self::reserved_path($path)) {
                throw new \RuntimeException("duo: compiled code descriptor collides with the Duo loader '$path'");
            }
        }
        $expectedFileRows = $fileRows;
        sort($expectedFileRows, SORT_STRING);
        if ($fileRows !== $expectedFileRows) {
            throw new \RuntimeException('duo: compiled code descriptor files are not deterministically sorted');
        }
        $pluginSeen = [];
        foreach ($descriptor['plugin_main_files'] as $i => $row) {
            if (!is_array($row) || array_keys($row) !== ['basename', 'path', 'sha256']
                || !self::safe_relative((string) ($row['basename'] ?? ''))
                || !str_starts_with((string) ($row['path'] ?? ''), 'plugins/')
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($row['sha256'] ?? ''))) {
                throw new \RuntimeException("duo: compiled code descriptor plugin_main_files[$i] is malformed");
            }
            $basename = (string) $row['basename'];
            $path = (string) $row['path'];
            if (!self::plugin_main_candidate($path)
                || $basename !== substr($path, strlen('plugins/'))
                || isset($pluginSeen[$basename])
                || !isset($files[$path])
                || !self::owned_path($path, $ownedRoots)) {
                throw new \RuntimeException("duo: compiled code descriptor plugin_main_files[$i] is inconsistent");
            }
            $pluginSeen[$basename] = true;
            if (!hash_equals($files[$path], (string) $row['sha256'])) {
                throw new \RuntimeException("duo: compiled code descriptor plugin_main_files[$i] hash disagrees with files inventory");
            }
        }
        $pluginBasenames = array_keys($pluginSeen);
        $expectedPluginBasenames = $pluginBasenames;
        sort($expectedPluginBasenames, SORT_STRING);
        if ($pluginBasenames !== $expectedPluginBasenames) {
            throw new \RuntimeException('duo: compiled code descriptor plugin_main_files are not deterministically sorted');
        }
        $themesSeen = [];
        foreach ($descriptor['theme_slugs'] as $i => $slug) {
            if (!is_string($slug) || $slug === '' || !self::safe_component($slug)) {
                throw new \RuntimeException("duo: compiled code descriptor theme_slugs[$i] is malformed");
            }
            if (isset($themesSeen[$slug]) || !isset($ownedRoots['themes/' . $slug])) {
                throw new \RuntimeException("duo: compiled code descriptor theme_slugs[$i] is not an owned theme component");
            }
            $style = 'themes/' . $slug . '/style.css';
            if (!isset($files[$style])) {
                throw new \RuntimeException("duo: compiled code descriptor theme '$slug' has no style.css inventory entry");
            }
            $themesSeen[$slug] = true;
        }
        $expectedThemes = array_keys($themesSeen);
        sort($expectedThemes, SORT_STRING);
        if ($descriptor['theme_slugs'] !== $expectedThemes) {
            throw new \RuntimeException('duo: compiled code descriptor theme_slugs are not deterministically sorted');
        }
        $ownedThemes = [];
        foreach (array_keys($ownedRoots) as $root) {
            if (str_starts_with($root, 'themes/')) {
                $ownedThemes[] = substr($root, strlen('themes/'));
            }
        }
        sort($ownedThemes, SORT_STRING);
        if ($descriptor['theme_slugs'] !== $ownedThemes) {
            throw new \RuntimeException('duo: compiled code descriptor theme ownership is inconsistent');
        }
        $copy = $descriptor;
        $revision = (string) $copy['code_revision'];
        unset($copy['code_revision']);
        if (!hash_equals(self::revision_for($copy), $revision)) {
            throw new \RuntimeException('duo: compiled code descriptor revision does not verify');
        }
    }

    public static function revision_for(array $descriptorWithoutRevision): string {
        return hash('sha256', Canon::encode($descriptorWithoutRevision));
    }

    public static function code_revision(?array $descriptor): ?string {
        return $descriptor === null ? null : (string) ($descriptor['code_revision'] ?? '');
    }

    /**
     * Authorization gate for lifecycle deploy. Call only after Deploy has
     * acquired the promotion lock for the compiled artifact: this proves the
     * stage marker, staged descriptor, staged artifact identity, and every
     * staged target hash still describe exactly that locked artifact.
     */
    public static function assert_verified_staged(CompiledRepository $compiled): void {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            throw new \RuntimeException('duo: materializing-code requires an opted-in compiled code descriptor');
        }
        self::assert_descriptor($descriptor);
        $stageRevision = Ledger::kv_get(self::CODE_STAGE_REVISION_KEY);
        if ($stageRevision === null || !preg_match('/^[0-9a-f]{64}$/', $stageRevision)
            || !hash_equals((string) $descriptor['code_revision'], $stageRevision)) {
            throw new \RuntimeException('duo: materializing-code refused — code_stage_revision does not match the compiled code revision');
        }
        $stageArtifact = Ledger::kv_get(self::CODE_STAGE_ARTIFACT_KEY);
        if ($stageArtifact === null || !preg_match('/^[0-9a-f]{64}$/', $stageArtifact)
            || !hash_equals($compiled->artifact_hash(), $stageArtifact)) {
            throw new \RuntimeException('duo: materializing-code refused — staged code artifact does not match the compiled artifact');
        }
        $staged = self::stored_stage_descriptor($stageRevision, $descriptor);
        if ($staged === null) {
            throw new \RuntimeException('duo: materializing-code refused — no staged code descriptor exists');
        }
        self::stored_stage_history();
        self::verify_payload($staged);
    }

    /**
     * Return a deterministic explanation when a completed descriptor marker
     * no longer proves the target payload.  This is read-only and intended
     * for Deploy/plan's non-forceable code_revision_stale finding.
     */
    public static function completed_code_mismatch(CompiledRepository $compiled): ?string {
        $expected = $compiled->code_descriptor();
        if ($expected === null) {
            return null;
        }
        self::assert_descriptor($expected);
        $revision = Ledger::kv_get(self::CODE_REVISION_KEY);
        if ($revision === null || $revision === '') {
            return 'no completed code_revision marker exists on this environment';
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $revision) || !hash_equals($revision, (string) $expected['code_revision'])) {
            return $revision === ''
                ? 'the completed code_revision marker is empty'
                : "this environment completed code revision '$revision'";
        }
        $raw = Ledger::kv_get(self::CODE_DESCRIPTOR_KEY);
        if ($raw === null || $raw === '') {
            return 'the completed code_revision marker has no stored code descriptor';
        }
        try {
            $stored = Canon::decode($raw);
            if (!is_array($stored) || Canon::encode($stored) !== $raw) {
                return 'the stored completed code descriptor is not canonical JSON';
            }
            self::assert_descriptor($stored);
        } catch (\Throwable $t) {
            return 'the stored completed code descriptor is invalid: ' . $t->getMessage();
        }
        if (Canon::encode($stored) !== Canon::encode($expected)) {
            return 'the stored completed code descriptor does not match this compiled artifact';
        }
        foreach ([self::CODE_STAGE_REVISION_KEY, self::CODE_STAGE_DESCRIPTOR_KEY, self::CODE_STAGE_ARTIFACT_KEY, self::CODE_STAGE_HISTORY_KEY] as $key) {
            if (($pending = Ledger::kv_get($key)) !== null && $pending !== '') {
                return "temporary code-stage metadata '$key' remains after finalize";
            }
        }
        try {
            self::verify_payload($stored);
            $extras = self::owned_extra_files($stored);
        } catch (\Throwable $t) {
            return $t->getMessage();
        }
        if ($extras) {
            return 'managed code root contains unrecorded file(s): ' . implode(', ', array_slice($extras, 0, 8));
        }
        return null;
    }

    /**
     * Stage all desired files with per-file temp+rename atomicity. No old
     * files are deleted. The promotion lease intentionally remains held for
     * code-finalize (and, optionally, the subsequent state apply).
     */
    public static function stage(string $repo, CompiledRepository $compiled, array $opts = []): array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return ['enabled' => false, 'staged' => false, 'code_revision' => null];
        }
        self::assert_descriptor($descriptor);
        self::assert_target_layout($descriptor);
        CodeStateContract::validate($compiled, $descriptor);
        self::assert_expected_artifact($compiled, $opts);

        $owner = (string) ($opts['promotion_owner'] ?? '');
        if ($owner === '') {
            throw new \RuntimeException('duo: code-stage requires an explicit --promotion-owner; use the host duo deploy workflow');
        }
        Ledger::ensure();
        $artifact = $compiled->artifact_hash();
        // This is a strict continuation of promotion-begin. In particular,
        // a same-owner attempt with another artifact must be rejected by the
        // lock without releasing the live session it failed to continue.
        PromotionLock::acquire($owner, $artifact, 'code-stage', null, true);
        try {
            $stageRevision = Ledger::kv_get(self::CODE_STAGE_REVISION_KEY);
            if ($stageRevision !== null && !preg_match('/^[0-9a-f]{64}$/', $stageRevision)) {
                throw new \RuntimeException('duo: malformed code_stage_revision; refusing recovery guesswork');
            }
            $staged = self::stored_stage_descriptor($stageRevision, null);
            $history = self::stored_stage_history();
            if ($staged !== null && $staged['code_revision'] !== $descriptor['code_revision']) {
                $history[$staged['code_revision']] = $staged;
            }
            // Record the attempted descriptor before the first rename.  A
            // crash after a partial payload write must leave its component
            // roots recoverable even when no stage revision was authorized.
            $history[$descriptor['code_revision']] = $descriptor;
            ksort($history, SORT_STRING);
            Ledger::kv_set(self::CODE_STAGE_HISTORY_KEY, Canon::encode(array_values($history)));
            // Re-check after acquiring the target lease. The first check is
            // an early, target-free failure; this one closes the race between
            // source validation and the first target file write.
            self::assert_source_matches($repo, $descriptor);
            CodeStateContract::validate($compiled, $descriptor);
            self::write_payload($repo, $descriptor);
            self::assert_source_matches($repo, $descriptor);
            // Temporary descriptor/artifact come before the stage revision:
            // lifecycle deploy trusts the revision key only after all other
            // metadata and target hashes are durable.
            Ledger::kv_set(self::CODE_STAGE_DESCRIPTOR_KEY, Canon::encode($descriptor));
            Ledger::kv_set(self::CODE_STAGE_ARTIFACT_KEY, $artifact);
            Ledger::kv_set(self::CODE_STAGE_REVISION_KEY, $descriptor['code_revision']);
            PromotionLock::heartbeat($owner, $artifact, 'code-staged');
            return [
                'enabled' => true,
                'staged' => true,
                'code_revision' => $descriptor['code_revision'],
                'files' => count($descriptor['files']),
                'promotion_lock' => ['owner' => $owner, 'held_for_finalize' => true],
            ];
        } catch (\Throwable $t) {
            try {
                PromotionLock::release($owner, $artifact);
            } catch (\Throwable $_releaseFailure) {
                // The bounded lease remains the recovery mechanism if the
                // database connection failed while handling the original error.
            }
            throw $t;
        }
    }

    /**
     * Verify staged files, remove only prior Duo-owned paths now absent, then
     * publish the completed descriptor/revision. The lease is released unless
     * promotion-hold asks us to hand it to state apply.
     */
    public static function finalize(string $repo, CompiledRepository $compiled, array $opts = []): array {
        $descriptor = $compiled->code_descriptor();
        if ($descriptor === null) {
            return ['enabled' => false, 'finalized' => false, 'code_revision' => null];
        }
        self::assert_descriptor($descriptor);
        self::assert_target_layout($descriptor);
        self::assert_expected_artifact($compiled, $opts);
        $owner = (string) ($opts['promotion_owner'] ?? '');
        if ($owner === '') {
            throw new \RuntimeException('duo: code-finalize requires an explicit --promotion-owner; use the host duo deploy workflow');
        }
        Ledger::ensure();
        $stageRevision = Ledger::kv_get(self::CODE_STAGE_REVISION_KEY);
        if ($stageRevision === null || !preg_match('/^[0-9a-f]{64}$/', $stageRevision)
            || !hash_equals($descriptor['code_revision'], $stageRevision)) {
            throw new \RuntimeException(
                'duo: code-finalize refused — code-stage has not recorded this compiled code revision '
                . '(run code-stage first and keep the same compiled artifact)'
            );
        }
        CodeStateContract::validate($compiled, $descriptor);

        $artifact = $compiled->artifact_hash();
        PromotionLock::acquire($owner, $artifact, 'code-finalize', null, true);
        try {
            // Finalize consumes the frozen compiled descriptor and the
            // already-staged target. The checkout may legitimately move
            // between stage and finalize; reopening mutable source here
            // would make a valid frozen artifact fail spuriously.
            CodeStateContract::validate($compiled, $descriptor);
            self::assert_verified_staged($compiled);
            self::verify_payload($descriptor);
            $previous = self::stored_descriptor();
            $staged = self::stored_stage_descriptor($stageRevision, $descriptor);
            $history = self::stored_stage_history();
            $removed = self::remove_old_owned_files($previous, $staged, $history, $descriptor);
            self::verify_payload($descriptor);
            $extras = self::owned_extra_files($descriptor);
            if ($extras) {
                throw new \RuntimeException(
                    'duo: code-finalize verification found unrecorded file(s): '
                    . implode(', ', array_slice($extras, 0, 8))
                );
            }

            self::publish_completed_descriptor($descriptor);

            if (!empty($opts['promotion_hold'])) {
                PromotionLock::heartbeat($owner, $artifact, 'code-finalized');
            } else {
                PromotionLock::release($owner, $artifact);
            }
            return [
                'enabled' => true,
                'finalized' => true,
                'code_revision' => $descriptor['code_revision'],
                'files' => count($descriptor['files']),
                'removed' => $removed,
                'promotion_lock' => ['owner' => $owner, 'held_for_apply' => !empty($opts['promotion_hold'])],
            ];
        } catch (\Throwable $t) {
            try {
                PromotionLock::release($owner, $artifact);
            } catch (\Throwable $_releaseFailure) {
                // Preserve the actionable verification/mutation error; the
                // lease is bounded and recoverable by the next promotion.
            }
            throw $t;
        }
    }

    /**
     * Move the code ledger from staged to completed as one retryable
     * convergence boundary. A process/database failure may leave either side
     * of this transition, but never code_stage_revision without its staged
     * descriptor/history or code_revision without its completed descriptor.
     */
    private static function publish_completed_descriptor(array $descriptor): void {
        $transactionStarted = false;
        try {
            Db::start('code ledger transaction start');
            $transactionStarted = true;
            Ledger::kv_set(self::CODE_DESCRIPTOR_KEY, Canon::encode($descriptor));
            Ledger::kv_delete(self::CODE_STAGE_DESCRIPTOR_KEY);
            Ledger::kv_delete(self::CODE_STAGE_ARTIFACT_KEY);
            Ledger::kv_delete(self::CODE_STAGE_HISTORY_KEY);
            Ledger::kv_delete(self::CODE_STAGE_REVISION_KEY);
            Ledger::kv_set(self::CODE_REVISION_KEY, $descriptor['code_revision']);
            Db::commit('code ledger transaction commit');
            $transactionStarted = false;
        } catch (\Throwable $t) {
            if ($transactionStarted) {
                try {
                    Db::rollback('code ledger transaction rollback');
                } catch (\Throwable $rollback) {
                    throw new \RuntimeException(
                        'duo: code ledger transaction failed and rollback could not be confirmed: '
                        . $rollback->getMessage(),
                        0,
                        $t
                    );
                }
            }
            throw $t;
        }
    }

    /** @return ?array<string,mixed> */
    private static function stored_descriptor(): ?array {
        $raw = Ledger::kv_get(self::CODE_DESCRIPTOR_KEY);
        $revision = Ledger::kv_get(self::CODE_REVISION_KEY);
        if ($raw === null) {
            if ($revision !== null && $revision !== '') {
                throw new \RuntimeException('duo: code ledger has code_revision but no code_descriptor; refusing deletion guesswork');
            }
            return null;
        }
        try {
            $descriptor = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: stored code descriptor is not valid canonical JSON', 0, $t);
        }
        if (!is_array($descriptor)) {
            throw new \RuntimeException('duo: stored code descriptor is not an object');
        }
        if (Canon::encode($descriptor) !== $raw) {
            throw new \RuntimeException('duo: stored code descriptor is not canonical JSON');
        }
        self::assert_descriptor($descriptor);
        if ($revision !== null && $revision !== '' && !hash_equals($revision, $descriptor['code_revision'])) {
            // The descriptor is still self-verifying and is useful as a
            // deletion-ownership record, but the completion marker remains
            // stale until finalize can publish it last.  This state is
            // recoverable after an interrupted descriptor/ledger update;
            // never use the marker mismatch as a reason to guess ownership.
        }
        return $descriptor;
    }

    /** @return ?array<string,mixed> */
    private static function stored_stage_descriptor(?string $stageRevision, ?array $expected): ?array {
        $raw = Ledger::kv_get(self::CODE_STAGE_DESCRIPTOR_KEY);
        if ($stageRevision === null && ($raw === null || $raw === '')) {
            return null;
        }
        if ($stageRevision === null || $raw === null || $raw === '') {
            throw new \RuntimeException(
                'duo: code ledger has code_stage_revision but no code_stage_descriptor; refusing finalize guesswork'
            );
        }
        try {
            $descriptor = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: staged code descriptor is not valid canonical JSON', 0, $t);
        }
        if (!is_array($descriptor)) {
            throw new \RuntimeException('duo: staged code descriptor is not an object');
        }
        if (Canon::encode($descriptor) !== $raw) {
            throw new \RuntimeException('duo: staged code descriptor is not canonical JSON');
        }
        self::assert_descriptor($descriptor);
        if (!hash_equals((string) $stageRevision, (string) ($descriptor['code_revision'] ?? ''))
            || ($expected !== null && !hash_equals((string) $expected['code_revision'], (string) $descriptor['code_revision']))) {
            throw new \RuntimeException(
                'duo: staged code descriptor does not match the compiled code revision; re-run code-stage'
            );
        }
        return $descriptor;
    }

    /** @return array<string,array<string,mixed>> keyed by code revision */
    private static function stored_stage_history(): array {
        $raw = Ledger::kv_get(self::CODE_STAGE_HISTORY_KEY);
        if ($raw === null || $raw === '') {
            return [];
        }
        try {
            $rows = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: staged code history is not valid canonical JSON', 0, $t);
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('duo: staged code history is not a descriptor list');
        }
        if (Canon::encode($rows) !== $raw) {
            throw new \RuntimeException('duo: staged code history is not canonical JSON');
        }
        $history = [];
        $revisions = [];
        foreach ($rows as $i => $descriptor) {
            if (!is_array($descriptor)) {
                throw new \RuntimeException("duo: staged code history[$i] is not a descriptor");
            }
            self::assert_descriptor($descriptor);
            if (isset($history[$descriptor['code_revision']])) {
                throw new \RuntimeException("duo: staged code history contains duplicate revision '{$descriptor['code_revision']}'");
            }
            $revisions[] = $descriptor['code_revision'];
            $history[$descriptor['code_revision']] = $descriptor;
        }
        $expectedRevisions = $revisions;
        sort($expectedRevisions, SORT_STRING);
        if ($revisions !== $expectedRevisions) {
            throw new \RuntimeException('duo: staged code history is not sorted by code revision');
        }
        return $history;
    }

    private static function assert_source_matches(string $repo, array $expected): void {
        $actual = self::descriptor_from_source(rtrim($repo, '/') . '/' . self::SOURCE);
        if (!hash_equals((string) $expected['code_revision'], (string) $actual['code_revision'])
            || Canon::encode($actual) !== Canon::encode($expected)) {
            throw new \RuntimeException(
                'duo: code payload changed after compilation; re-run `wp duo compile` and stage the new artifact'
            );
        }
    }

    private static function assert_expected_artifact(CompiledRepository $compiled, array $opts): void {
        $expected = (string) ($opts['artifact_hash'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)
            || !hash_equals($expected, $compiled->artifact_hash())) {
            throw new \RuntimeException(
                'duo: code materialization artifact does not match the host-compiled artifact hash'
            );
        }
    }

    private static function write_payload(string $repo, array $descriptor): void {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code-stage requires WordPress WP_CONTENT_DIR');
        }
        self::assert_target_layout($descriptor);
        $source = rtrim($repo, '/') . '/' . self::SOURCE;
        foreach ($descriptor['files'] as $row) {
            $relative = $row['path'];
            $src = self::safe_join($source, $relative);
            $dst = self::safe_join(WP_CONTENT_DIR, $relative);
            if (is_link($src) || !is_file($src)) {
                throw new \RuntimeException("duo: code-stage source file disappeared or became a symlink '$relative'");
            }
            if (hash_file('sha256', $src) !== $row['sha256']) {
                throw new \RuntimeException("duo: code-stage source hash changed for '$relative'");
            }
            self::ensure_target_parent(dirname($dst));
            if (is_link($dst) || (file_exists($dst) && !is_file($dst))) {
                throw new \RuntimeException("duo: code-stage target path is not a regular file '$relative'");
            }
            $tmp = dirname($dst) . '/.' . basename($dst) . '.duo-stage-' . bin2hex(random_bytes(8));
            try {
                $bytes = file_get_contents($src);
                if ($bytes === false || file_put_contents($tmp, $bytes, LOCK_EX) === false) {
                    throw new \RuntimeException("duo: code-stage cannot write '$relative'");
                }
                @chmod($tmp, fileperms($src) & 0777);
                if (hash_file('sha256', $tmp) !== $row['sha256'] || !@rename($tmp, $dst)) {
                    throw new \RuntimeException("duo: code-stage atomic publish failed for '$relative'");
                }
            } finally {
                if (is_file($tmp) || is_link($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    private static function verify_payload(array $descriptor): void {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code-finalize requires WordPress WP_CONTENT_DIR');
        }
        self::assert_target_layout($descriptor);
        foreach ($descriptor['owned_roots'] as $root) {
            self::assert_no_symlinked_target_path($root, true, 'code verification');
        }
        foreach ($descriptor['files'] as $row) {
            self::assert_no_symlinked_target_path($row['path'], false, 'code verification');
            $path = self::safe_join(WP_CONTENT_DIR, $row['path']);
            if (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $row['sha256']) {
                throw new \RuntimeException("duo: code-finalize verification failed for '{$row['path']}'");
            }
        }
    }

    /**
     * Prune the union of completed, previously staged, and current component
     * roots against the current descriptor.  Ownership is component-scoped:
     * a stale file inside an adopted plugin/theme/mu-plugin root is Duo-owned,
     * while an unrelated sibling component is never traversed or touched.
     *
     * @return list<string>
     */
    /** @param array<string,array<string,mixed>> $history */
    private static function remove_old_owned_files(?array $previous, ?array $staged, array $history, array $current): array {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code-finalize requires WordPress WP_CONTENT_DIR');
        }
        $roots = [];
        $allDescriptors = [$previous, $staged, $current];
        foreach ($history as $descriptor) {
            $allDescriptors[] = $descriptor;
        }
        foreach ($allDescriptors as $descriptor) {
            if ($descriptor === null) {
                continue;
            }
            foreach ($descriptor['owned_roots'] as $root) {
                $roots[$root] = true;
            }
        }
        $knownHashes = [];
        foreach ($allDescriptors as $descriptor) {
            if ($descriptor === null) {
                continue;
            }
            foreach ($descriptor['files'] as $row) {
                $knownHashes[$row['path']][$row['sha256']] = true;
            }
        }
        $recordedTypes = self::recorded_path_types($allDescriptors);
        $currentTypes = self::recorded_path_types([$current]);
        // Type validation is a preflight over the complete recorded
        // inventory.  Do it before the first unlink/rmdir so a path which an
        // operator replaced with another filesystem type cannot turn a
        // historical ownership record into permission to recursively delete
        // new, unowned content.
        self::assert_recorded_target_types($recordedTypes, $currentTypes);
        $currentPaths = [];
        foreach ($current['files'] as $row) {
            $currentPaths[$row['path']] = true;
        }
        $removed = [];
        $rootNames = array_keys($roots);
        sort($rootNames, SORT_STRING);
        foreach ($rootNames as $root) {
            if (!self::safe_component_root($root) || self::reserved_path($root)) {
                throw new \RuntimeException("duo: code-finalize refuses unsafe owned root '$root'");
            }
            self::assert_no_symlinked_target_path($root, true, 'code-finalize');
            $absolute = self::safe_join(WP_CONTENT_DIR, $root);
            if (is_link($absolute)) {
                throw new \RuntimeException("duo: code-finalize refuses to traverse a symlink at owned root '$root'");
            }
            if (!file_exists($absolute)) {
                continue;
            }
            if (is_file($absolute)) {
                if (!isset($currentPaths[$root]) && isset($knownHashes[$root])
                    && !isset($knownHashes[$root][hash_file('sha256', $absolute)])) {
                    throw new \RuntimeException("duo: code-finalize refuses to remove changed prior-owned file '$root'");
                }
                if (!isset($currentPaths[$root]) && (!@unlink($absolute) || file_exists($absolute))) {
                    throw new \RuntimeException("duo: code-finalize could not remove owned file '$root'");
                }
                if (!isset($currentPaths[$root])) {
                    $removed[] = $root;
                }
                continue;
            }
            if (!is_dir($absolute)) {
                throw new \RuntimeException("duo: code-finalize refuses to mutate a special owned root '$root'");
            }
            self::prune_owned_directory($absolute, $root, $currentPaths, $removed, $knownHashes);
            if (!self::has_current_path_at_or_below($root, $currentPaths)
                && is_dir($absolute)
                && (!@rmdir($absolute) || is_dir($absolute))) {
                throw new \RuntimeException("duo: code-finalize could not remove obsolete owned directory '$root'");
            }
        }
        sort($removed, SORT_STRING);
        return $removed;
    }

    /**
     * Derive filesystem types from immutable descriptors. Files are explicit;
     * every parent segment and directory component root is therefore an
     * expected directory. A component root may itself be a top-level plugin
     * or mu-plugin file.
     *
     * @param list<?array<string,mixed>> $descriptors
     * @return array<string,array<string,bool>>
     */
    private static function recorded_path_types(array $descriptors): array {
        $types = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor === null) {
                continue;
            }
            $filePaths = [];
            foreach ($descriptor['files'] as $row) {
                $filePaths[$row['path']] = true;
            }
            foreach ($descriptor['owned_roots'] as $root) {
                $types[$root][isset($filePaths[$root]) ? 'file' : 'directory'] = true;
            }
            foreach (array_keys($filePaths) as $path) {
                $types[$path]['file'] = true;
                $parent = dirname($path);
                while ($parent !== '.' && $parent !== '') {
                    $types[$parent]['directory'] = true;
                    $next = dirname($parent);
                    if ($next === $parent) {
                        break;
                    }
                    $parent = $next;
                }
            }
        }
        ksort($types, SORT_STRING);
        return $types;
    }

    /**
     * @param array<string,array<string,bool>> $recordedTypes
     * @param array<string,array<string,bool>> $currentTypes
     */
    private static function assert_recorded_target_types(array $recordedTypes, array $currentTypes): void {
        foreach ($recordedTypes as $relative => $historical) {
            self::assert_no_symlinked_target_path($relative, true, 'code-finalize type preflight');
            $absolute = self::safe_join(WP_CONTENT_DIR, $relative);
            if (!file_exists($absolute)) {
                continue;
            }
            // The desired descriptor is authoritative for deliberate type
            // transitions. Otherwise any type actually recorded by a prior
            // completed/staged descriptor remains valid ownership evidence.
            $expected = $currentTypes[$relative] ?? $historical;
            if (count($expected) !== 1 && isset($currentTypes[$relative])) {
                throw new \RuntimeException(
                    "duo: code-finalize current descriptor has conflicting filesystem types for '$relative'"
                );
            }
            if (is_file($absolute)) {
                if (!isset($expected['file'])) {
                    throw new \RuntimeException(
                        "duo: code-finalize refuses recorded directory '$relative' that is now a file"
                    );
                }
                continue;
            }
            if (is_dir($absolute)) {
                if (!isset($expected['directory'])) {
                    throw new \RuntimeException(
                        "duo: code-finalize refuses recorded file '$relative' that is now a directory"
                    );
                }
                continue;
            }
            throw new \RuntimeException(
                "duo: code-finalize refuses recorded path '$relative' that is now a special filesystem entry"
            );
        }
    }

    /** @param array<string,bool> $currentPaths @param list<string> $removed @param array<string,array<string,bool>> $knownHashes */
    private static function prune_owned_directory(string $absolute, string $relativeRoot, array $currentPaths, array &$removed, array $knownHashes): void {
        $children = @scandir($absolute);
        if ($children === false) {
            throw new \RuntimeException("duo: code-finalize cannot read owned component '$relativeRoot'");
        }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            if (!self::safe_component($child)) {
                throw new \RuntimeException("duo: code-finalize found an unsafe path under owned component '$relativeRoot'");
            }
            $relative = $relativeRoot . '/' . $child;
            $path = $absolute . '/' . $child;
            if (is_link($path)) {
                throw new \RuntimeException("duo: code-finalize refuses to delete a symlink at '$relative'");
            }
            if (is_dir($path)) {
                self::prune_owned_directory($path, $relative, $currentPaths, $removed, $knownHashes);
                if (!self::has_current_path_at_or_below($relative, $currentPaths)
                    && is_dir($path)
                    && (!@rmdir($path) || is_dir($path))) {
                    throw new \RuntimeException("duo: code-finalize could not remove obsolete owned directory '$relative'");
                }
                continue;
            }
            if (!is_file($path)) {
                throw new \RuntimeException("duo: code-finalize refuses to mutate a special path '$relative'");
            }
            if (isset($currentPaths[$relative])) {
                continue;
            }
            if (isset($knownHashes[$relative])
                && !isset($knownHashes[$relative][hash_file('sha256', $path)])) {
                throw new \RuntimeException("duo: code-finalize refuses to remove changed prior-owned file '$relative'");
            }
            if (!@unlink($path) || file_exists($path)) {
                throw new \RuntimeException("duo: code-finalize could not remove owned file '$relative'");
            }
            $removed[] = $relative;
        }
    }

    /** @return list<string> */
    private static function owned_extra_files(array $descriptor): array {
        $currentPaths = [];
        foreach ($descriptor['files'] as $row) {
            $currentPaths[$row['path']] = true;
        }
        $extras = [];
        foreach ($descriptor['owned_roots'] as $root) {
            self::assert_no_symlinked_target_path($root, true, 'completed code verification');
            $absolute = self::safe_join(WP_CONTENT_DIR, $root);
            if (is_link($absolute)) {
                throw new \RuntimeException("completed code root '$root' is a symbolic link");
            }
            if (!file_exists($absolute)) {
                continue;
            }
            if (is_file($absolute)) {
                if (!isset($currentPaths[$root])) {
                    $extras[] = $root;
                }
                continue;
            }
            if (!is_dir($absolute)) {
                throw new \RuntimeException("completed code root '$root' is not a regular file or directory");
            }
            self::collect_owned_extras($absolute, $root, $currentPaths, $extras);
        }
        sort($extras, SORT_STRING);
        return $extras;
    }

    /** @param array<string,bool> $currentPaths @param list<string> $extras */
    private static function collect_owned_extras(string $absolute, string $relativeRoot, array $currentPaths, array &$extras): void {
        $children = @scandir($absolute);
        if ($children === false) {
            throw new \RuntimeException("completed code root '$relativeRoot' cannot be read");
        }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            if (!self::safe_component($child)) {
                throw new \RuntimeException("completed code root '$relativeRoot' contains an unsafe path");
            }
            $relative = $relativeRoot . '/' . $child;
            $path = $absolute . '/' . $child;
            if (is_link($path)) {
                throw new \RuntimeException("completed code path '$relative' is a symbolic link");
            }
            if (is_dir($path)) {
                self::collect_owned_extras($path, $relative, $currentPaths, $extras);
            } elseif (is_file($path)) {
                if (!isset($currentPaths[$relative])) {
                    $extras[] = $relative;
                }
            } else {
                throw new \RuntimeException("completed code path '$relative' is not a regular file or directory");
            }
        }
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private static function diagnostic(array &$diagnostics, string $code, string $path, string $locator, string $message): void {
        $diagnostics[] = ['severity' => 'blocking', 'code' => $code, 'path' => $path, 'locator' => $locator, 'message' => $message];
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private static function throw_diagnostics(array $diagnostics): never {
        usort($diagnostics, static fn(array $a, array $b): int => [$a['path'], $a['locator'], $a['code'], $a['message']] <=> [$b['path'], $b['locator'], $b['code'], $b['message']]);
        throw new CodeCompilationException($diagnostics);
    }

    /** @param list<array<string,mixed>> $files @param list<array<string,mixed>> $pluginMainFiles @param list<array<string,mixed>> $diagnostics */
    private static function walk_component(string $source, string $root, string $absolute, array &$files, array &$pluginMainFiles, array &$diagnostics): void {
        if (is_file($absolute)) {
            $relative = str_replace('\\', '/', substr($absolute, strlen($source) + 1));
            if (!self::safe_relative($relative) || self::reserved_path($relative)) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'code payload path is not allowed');
                return;
            }
            $hash = hash_file('sha256', $absolute);
            if ($hash === false) {
                self::diagnostic($diagnostics, 'unreadable_code_file', $relative, '', 'could not hash code payload file');
                return;
            }
            $files[] = ['path' => $relative, 'sha256' => $hash];
            if ($root === 'plugins' && self::plugin_main_candidate($relative)
                && self::header_value($absolute, 'Plugin Name') !== null) {
                $pluginMainFiles[] = [
                    'basename' => substr($relative, strlen('plugins/')),
                    'path' => $relative,
                    'sha256' => $hash,
                ];
            }
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $info) {
            $full = $info->getPathname();
            $relative = str_replace('\\', '/', substr($full, strlen($source) + 1));
            if (!self::safe_relative($relative)) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'code payload path is not normalized');
                continue;
            }
            if (self::reserved_path($relative)) {
                self::diagnostic($diagnostics, 'code_loader_collision', $relative, '', 'payload may not own mu-plugins/duo or mu-plugins/duo-loader.php');
                continue;
            }
            if ($info->isLink()) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'symbolic links are not valid code payload entries');
                continue;
            }
            if ($info->isDir()) {
                continue;
            }
            if (!$info->isFile()) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'code payload entries must be regular files or directories');
                continue;
            }
            $hash = hash_file('sha256', $full);
            if ($hash === false) {
                self::diagnostic($diagnostics, 'unreadable_code_file', $relative, '', 'could not hash code payload file');
                continue;
            }
            $files[] = ['path' => $relative, 'sha256' => $hash];
            if ($root === 'plugins' && self::plugin_main_candidate($relative)
                && self::header_value($full, 'Plugin Name') !== null) {
                $pluginMainFiles[] = [
                    'basename' => substr($relative, strlen('plugins/')),
                    'path' => $relative,
                    'sha256' => $hash,
                ];
            }
        }
    }

    /** Read one WordPress-style header from only the first 8 KiB. */
    private static function header_value(string $path, string $header): ?string {
        $bytes = @file_get_contents($path, false, null, 0, 8192);
        if ($bytes === false) {
            return null;
        }
        $quoted = preg_quote($header, '/');
        if (!preg_match('/^[ \t]*\*?[ \t]*' . $quoted . '[ \t]*:[ \t]*(.+?)[ \t]*$/mi', $bytes, $m)) {
            return null;
        }
        $value = trim((string) $m[1]);
        return $value === '' ? null : $value;
    }

    private static function safe_join(string $root, string $relative): string {
        if (!self::safe_relative($relative)) {
            throw new \RuntimeException("duo: unsafe code path '$relative'");
        }
        return rtrim($root, '/') . '/' . $relative;
    }

    /**
     * The v0 descriptor is specifically a standard WP_CONTENT_DIR payload.
     * Refuse custom plugin/mu-plugin/theme roots: copying into the standard
     * sibling while WordPress executes another directory would falsely claim
     * a successful materialization of inert files.
     */
    private static function assert_target_layout(?array $descriptor = null): void {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code materialization requires WordPress WP_CONTENT_DIR');
        }
        $content = rtrim(WP_CONTENT_DIR, '/');
        $managed = ['plugins' => true, 'mu-plugins' => true, 'themes' => true];
        if ($descriptor !== null && isset($descriptor['owned_roots']) && is_array($descriptor['owned_roots'])) {
            $managed = ['plugins' => false, 'mu-plugins' => false, 'themes' => false];
            foreach ($descriptor['owned_roots'] as $root) {
                $parts = is_string($root) ? explode('/', $root, 2) : [];
                if (isset($parts[0]) && array_key_exists($parts[0], $managed)) {
                    $managed[$parts[0]] = true;
                }
            }
        }
        foreach ($managed as $relative => $enabled) {
            if ($enabled) {
                self::assert_no_symlinked_target_path($relative, true, 'code materialization');
            }
        }
        foreach ([
            'WP_PLUGIN_DIR' => 'plugins',
            'WPMU_PLUGIN_DIR' => 'mu-plugins',
        ] as $constant => $relative) {
            if (!$managed[$relative] || !defined($constant)) {
                continue;
            }
            $actual = constant($constant);
            if (!is_string($actual) || !self::same_target_path($actual, $content . '/' . $relative)) {
                throw new \RuntimeException(
                    "duo: code materialization requires standard $relative root '$content/$relative'; "
                    . "$constant is custom and this v0 payload cannot safely target it"
                );
            }
        }
        if ($managed['themes'] && function_exists('get_theme_root')) {
            $actual = get_theme_root();
            if (!is_string($actual) || !self::same_target_path($actual, $content . '/themes')) {
                throw new \RuntimeException(
                    "duo: code materialization requires standard themes root '$content/themes'; active WordPress theme root is custom"
                );
            }
        }
    }

    private static function same_target_path(string $actual, string $expected): bool {
        $actual = rtrim($actual, '/');
        $expected = rtrim($expected, '/');
        if ($actual === $expected) {
            return true;
        }
        $actualReal = realpath($actual);
        $expectedReal = realpath($expected);
        return $actualReal !== false && $expectedReal !== false && rtrim($actualReal, '/') === rtrim($expectedReal, '/');
    }

    /**
     * Reject a symlink at any path segment below the configured content
     * boundary. A descriptor path can be textually below WP_CONTENT_DIR
     * while an intermediate plugins/themes/mu-plugins (or component) link
     * redirects traversal elsewhere. Recheck this before every verification
     * and prune boundary; stage already performs the same walk while creating
     * each target parent.
     */
    private static function assert_no_symlinked_target_path(
        string $relative,
        bool $includeLeaf,
        string $operation
    ): void {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException("duo: $operation requires WordPress WP_CONTENT_DIR");
        }
        if (!self::safe_relative($relative)) {
            throw new \RuntimeException("duo: $operation refuses unsafe target path '$relative'");
        }
        $parts = explode('/', $relative);
        if (!$includeLeaf) {
            array_pop($parts);
        }
        $cursor = rtrim(WP_CONTENT_DIR, '/');
        $walked = [];
        foreach ($parts as $part) {
            $walked[] = $part;
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                $path = implode('/', $walked);
                throw new \RuntimeException("duo: $operation refuses symbolic-link target path '$path'");
            }
        }
    }

    private static function safe_relative(string $path): bool {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        $parts = explode('/', $path);
        return !in_array('', $parts, true) && !in_array('.', $parts, true) && !in_array('..', $parts, true);
    }

    private static function safe_component(string $name): bool {
        return $name !== '' && self::safe_relative($name) && !str_contains($name, '/');
    }

    /** Match WordPress get_plugins(): root PHP files or PHP files one directory deep. */
    private static function plugin_main_candidate(string $path): bool {
        if (!str_ends_with(strtolower($path), '.php')) {
            return false;
        }
        $parts = explode('/', $path);
        return $parts[0] === 'plugins' && (count($parts) === 2 || count($parts) === 3);
    }

    /** @param array<string,bool> $currentPaths */
    private static function has_current_path_at_or_below(string $path, array $currentPaths): bool {
        foreach ($currentPaths as $current => $_present) {
            if ($current === $path || str_starts_with($current, $path . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,bool> $ownedRoots */
    private static function owned_path(string $path, array $ownedRoots): bool {
        foreach ($ownedRoots as $root => $_owned) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function safe_component_root(string $path): bool {
        if (!self::safe_relative($path)) {
            return false;
        }
        $parts = explode('/', $path);
        return count($parts) === 2
            && in_array($parts[0], self::ROOTS, true)
            && self::safe_component($parts[1]);
    }

    private static function reserved_path(string $path): bool {
        $lower = strtolower($path);
        return $lower === 'mu-plugins/duo'
            || str_starts_with($lower, 'mu-plugins/duo/')
            || $lower === 'mu-plugins/duo-loader.php';
    }

    private static function ensure_target_parent(string $dir): void {
        if (!defined('WP_CONTENT_DIR')) {
            throw new \RuntimeException('duo: WP_CONTENT_DIR is not defined');
        }
        $root = rtrim((string) WP_CONTENT_DIR, '/');
        $relative = ltrim(substr($dir, strlen($root)), '/');
        $cursor = $root;
        if ($relative !== '' && !self::safe_relative($relative)) {
            throw new \RuntimeException("duo: unsafe target code parent '$dir'");
        }
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new \RuntimeException("duo: code-stage target parent is a symbolic link '$cursor'");
            }
            if (!is_dir($cursor) && !mkdir($cursor, 0777) && !is_dir($cursor)) {
                throw new \RuntimeException("duo: cannot create code target directory '$cursor'");
            }
        }
    }
}
