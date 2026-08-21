<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/PathSafety.php';

/**
 * Materializes and verifies one immutable code descriptor.
 *
 * This collaborator owns only payload bytes, target-layout preflight, and
 * staged-created-path provenance.  Ledger publication remains in
 * CodeStageTransaction; removal authority remains in Code until the
 * CodeOwnershipPruner slice.  Code keeps private compatibility facades for
 * the historical helpers so the promotion orchestration and its failure
 * semantics remain unchanged while the ownership boundary becomes explicit.
 */
final class CodeMaterializer {
    private const SOURCE = 'code/wp-content';

    /**
     * Materialize the payload after the caller's ownership/removal preflight.
     * The callback is deliberately injected: Code still owns the union of
     * completed/staged/current removal inventory until CodeOwnershipPruner.
     *
     * @param array<string,array<string,mixed>> $history
     * @param list<string> $stagedCreatedPaths
     * @return array{abandoned_stage_removed:list<string>,created_paths:list<string>,written:int,unchanged:int}
     */
    public static function materialize_payload(
        string $repo,
        array $descriptor,
        ?array $previous,
        ?array $staged,
        array $history,
        array $stagedCreatedPaths,
        callable $assertRemovalSafe
    ): array {
        self::assert_payload_targets($descriptor);
        $assertRemovalSafe($previous, $staged, $history, $descriptor);
        $createdPaths = self::created_paths_for_stage(
            $descriptor,
            $previous,
            $stagedCreatedPaths
        );
        $abandonedStageRemoved = self::remove_abandoned_staged_mu_files(
            $previous,
            $staged,
            $descriptor,
            $stagedCreatedPaths
        );
        $counts = self::write_payload($repo, $descriptor);
        return [
            'abandoned_stage_removed' => $abandonedStageRemoved,
            'created_paths' => $createdPaths,
            'written' => $counts['written'],
            'unchanged' => $counts['unchanged'],
        ];
    }

    /**
     * Materialize every descriptor row under the caller's lease and report
     * what it had to move.  Re-staging the same descriptor is the common
     * case, not the exception: a lifecycle retry, a state-only release and a
     * repeated `duo deploy` of one artifact all re-enter this loop with a
     * target that already holds those exact bytes, and the loop used to
     * temp+rename all 8,918 files of a real payload every single time.
     *
     * @return array{written:int,unchanged:int}
     */
    public static function write_payload(string $repo, array $descriptor): array {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code-stage requires WordPress WP_CONTENT_DIR');
        }
        self::assert_target_layout($descriptor);
        $source = rtrim($repo, '/') . '/' . self::SOURCE;
        $written = 0;
        $unchanged = 0;
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
            // Every refusal this loop owns has already been re-checked for
            // this row above -- source present, not a symlink, still hashing
            // to the descriptor -- and the target has been proven a regular
            // non-symlink file or absent.  Only then may an already-correct
            // target be left alone: the two conditions below are exactly the
            // observable result of the write they replace, so a skipped row
            // is byte-for-byte and mode-for-mode what the temp+rename would
            // have produced.  Mode is part of the test because the write
            // branch below chmods its temp file to fileperms($src) & 0777
            // before the rename; a content-only test would silently stop
            // converging a bit the write converges.  Nothing downstream
            // loses proof either:
            // stage's own authorization gate re-hashes the entire staged
            // payload (Code::assert_verified_staged, agent/src/Code/Code.php:96-124)
            // and code-finalize re-verifies it again, so a wrongly-skipped
            // row still fails closed with "code-finalize verification failed
            // for '<path>'" (self::verify_payload) rather than promoting.
            if (is_file($dst)
                && (fileperms($dst) & 0777) === (fileperms($src) & 0777)
                && hash_equals((string) $row['sha256'], (string) hash_file('sha256', $dst))) {
                $unchanged++;
                continue;
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
            $written++;
        }
        return ['written' => $written, 'unchanged' => $unchanged];
    }

    /** @param list<string> $stagedCreatedPaths @return list<string> */
    public static function created_paths_for_stage(
        array $current,
        ?array $previous,
        array $stagedCreatedPaths
    ): array {
        $completedPaths = [];
        foreach ($previous['files'] ?? [] as $row) {
            $completedPaths[(string) $row['path']] = true;
        }
        $createdBefore = array_fill_keys($stagedCreatedPaths, true);
        $created = [];
        foreach ($current['files'] as $row) {
            $relative = (string) $row['path'];
            $absolute = self::safe_join(WP_CONTENT_DIR, $relative);
            if (!file_exists($absolute)
                || (isset($createdBefore[$relative]) && !isset($completedPaths[$relative]))) {
                $created[] = $relative;
            }
        }
        sort($created, SORT_STRING);
        return $created;
    }

    /** @return list<string> */
    public static function remove_abandoned_staged_mu_files(
        ?array $previous,
        ?array $staged,
        array $current,
        array $stagedCreatedPaths
    ): array {
        if ($staged === null) {
            return [];
        }
        $completedPaths = [];
        foreach ($previous['files'] ?? [] as $row) {
            $completedPaths[(string) $row['path']] = true;
        }
        $currentPaths = [];
        foreach ($current['files'] as $row) {
            $currentPaths[(string) $row['path']] = true;
        }
        $createdPaths = array_fill_keys($stagedCreatedPaths, true);
        $candidates = [];
        foreach ($staged['files'] as $row) {
            $relative = (string) $row['path'];
            if (!str_starts_with($relative, 'mu-plugins/')
                || isset($completedPaths[$relative])
                || isset($currentPaths[$relative])
                || !isset($createdPaths[$relative])) {
                continue;
            }
            self::assert_no_symlinked_target_path($relative, true, 'code-stage recovery');
            $absolute = self::safe_join(WP_CONTENT_DIR, $relative);
            if (!file_exists($absolute)) {
                continue;
            }
            if (is_link($absolute) || !is_file($absolute)
                || !hash_equals((string) $row['sha256'], (string) hash_file('sha256', $absolute))) {
                throw new \RuntimeException(
                    "duo: code-stage recovery refuses changed abandoned staged MU file '$relative'"
                );
            }
            $candidates[] = $row;
        }

        $removed = [];
        foreach ($candidates as $row) {
            $relative = (string) $row['path'];
            self::assert_no_symlinked_target_path($relative, true, 'code-stage recovery');
            $absolute = self::safe_join(WP_CONTENT_DIR, $relative);
            if (!is_file($absolute) || is_link($absolute)
                || !hash_equals((string) $row['sha256'], (string) hash_file('sha256', $absolute))) {
                throw new \RuntimeException(
                    "duo: code-stage recovery lost exact ownership of abandoned staged MU file '$relative'"
                );
            }
            if (!@unlink($absolute) || file_exists($absolute)) {
                throw new \RuntimeException(
                    "duo: code-stage recovery could not remove abandoned staged MU file '$relative'"
                );
            }
            $removed[] = $relative;
        }
        sort($removed, SORT_STRING);
        return $removed;
    }

    /** Validate the complete desired path inventory without creating it. */
    public static function assert_payload_targets(array $descriptor): void {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code-stage requires WordPress WP_CONTENT_DIR');
        }
        $content = rtrim(WP_CONTENT_DIR, '/');
        foreach ($descriptor['files'] as $row) {
            $relative = $row['path'];
            self::assert_no_symlinked_target_path($relative, true, 'code-stage preflight');
            $parts = explode('/', $relative);
            array_pop($parts);
            $cursor = $content;
            $walked = [];
            foreach ($parts as $part) {
                $walked[] = $part;
                $cursor .= '/' . $part;
                if (file_exists($cursor) && !is_dir($cursor)) {
                    $parent = implode('/', $walked);
                    throw new \RuntimeException(
                        "duo: code-stage target parent is not a directory '$parent'"
                    );
                }
            }
            $target = self::safe_join($content, $relative);
            if (file_exists($target) && !is_file($target)) {
                throw new \RuntimeException(
                    "duo: code-stage target path is not a regular file '$relative'"
                );
            }
        }
    }

    public static function verify_payload(array $descriptor): void {
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

    /** The v0 descriptor targets standard WordPress content roots only. */
    public static function assert_target_layout(?array $descriptor = null): void {
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
        foreach (['WP_PLUGIN_DIR' => 'plugins', 'WPMU_PLUGIN_DIR' => 'mu-plugins'] as $constant => $relative) {
            if (!$managed[$relative] || !defined($constant)) {
                continue;
            }
            $actual = constant($constant);
            if ($constant === 'WPMU_PLUGIN_DIR'
                && defined('DUO_CONTROL_PLANE') && DUO_CONTROL_PLANE === true) {
                if (!defined('DUO_CONTROL_WPMU_PLUGIN_DIR')
                    || !is_string(DUO_CONTROL_WPMU_PLUGIN_DIR)
                    || DUO_CONTROL_WPMU_PLUGIN_DIR === '') {
                    throw new \RuntimeException(
                        'duo: control-plane bootstrap did not preserve the real mu-plugins materialization root'
                    );
                }
                $actual = DUO_CONTROL_WPMU_PLUGIN_DIR;
            }
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

    private static function safe_join(string $root, string $relative): string {
        return PathSafety::safe_join($root, $relative);
    }

    private static function same_target_path(string $actual, string $expected): bool {
        return PathSafety::same_target_path($actual, $expected);
    }

    private static function assert_no_symlinked_target_path(
        string $relative,
        bool $includeLeaf,
        string $operation
    ): void {
        PathSafety::assert_no_symlinked_target_path($relative, $includeLeaf, $operation);
    }

    private static function ensure_target_parent(string $dir): void {
        if (!defined('WP_CONTENT_DIR')) {
            throw new \RuntimeException('duo: WP_CONTENT_DIR is not defined');
        }
        $root = rtrim((string) WP_CONTENT_DIR, '/');
        $relative = ltrim(substr($dir, strlen($root)), '/');
        $cursor = $root;
        if ($relative !== '' && !PathSafety::safe_relative($relative)) {
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
