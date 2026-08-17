<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/PathSafety.php';

/**
 * Removal authority for one immutable code descriptor set (DUO-3350 slice
 * 5, extracted from Code -- the seam CodeMaterializer.php's own docblock
 * already named "removal authority remains in Code until the
 * CodeOwnershipPruner slice"): computes the deterministic prior-owned-file
 * inventory shared by both the mutating finalize prune and its own
 * standalone preflight, walks it to detect a changed/special/type-conflicted
 * path before any write, and performs the actual unlink/rmdir once that
 * preflight passes. A sibling read-only method (owned_extra_files) answers
 * a related but distinct question -- does a COMPLETED descriptor's owned
 * root contain any file Duo never recorded -- used by baseline/mismatch
 * verification rather than by removal itself.
 *
 * Code keeps thin compatibility facades for all three entry points
 * (remove_old_owned_files, assert_removal_safe, owned_extra_files) so the
 * promotion orchestration and its failure semantics remain unchanged,
 * matching every prior slice in this issue. assert_removal_safe is also
 * reached indirectly as a callback CodeMaterializer::materialize_payload()
 * invokes -- that callback is constructed inside Code's own facade (never
 * inside CodeMaterializer.php, which has no dependency on either class),
 * so it needs no change here.
 *
 * The fixed top-level component allowlist (Code::ROOTS -- mu-plugins,
 * plugins, themes) is Code's own configuration, not this collaborator's;
 * it travels as an explicit $roots parameter into the two entry points
 * that need it (remove_old_owned_files, assert_removal_safe), the same
 * PathSafety::safe_component_root() already takes it as a parameter since
 * slice 1. owned_extra_files() never calls safe_component_root() (it
 * trusts the descriptor's own owned_roots, not the fixed allowlist), so it
 * takes no $roots parameter at all.
 */
final class CodeOwnershipPruner {
    /**
     * Prune the union of completed, previously staged, and current component
     * roots against the current descriptor.  Ownership is component-scoped:
     * a stale file inside an adopted plugin/theme/mu-plugin root is Duo-owned,
     * while an unrelated sibling component is never traversed or touched.
     *
     * @param array<string,array<string,mixed>> $history
     * @param list<string> $roots
     * @return list<string>
     */
    public static function remove_old_owned_files(?array $previous, ?array $staged, array $history, array $current, array $roots): array {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('duo: code-finalize requires WordPress WP_CONTENT_DIR');
        }
        $inventory = self::removal_inventory($previous, $staged, $history, $current);
        self::assert_removal_inventory($inventory, $roots);
        $rootNames = $inventory['roots'];
        $knownHashes = $inventory['known_hashes'];
        $currentPaths = $inventory['current_paths'];
        $removed = [];
        foreach ($rootNames as $root) {
            // Repeat target-shape checks while mutating so ordinary external
            // changes are detected close to each unlink/rmdir. These checks
            // are not an atomic defense against an adversarial concurrent
            // directory-to-symlink swap; promotion requires filesystem
            // exclusion from non-Duo writers (documented in repo-format.md).
            if (!PathSafety::safe_component_root($root, $roots) || PathSafety::reserved_path($root)) {
                throw new \RuntimeException("duo: code-finalize refuses unsafe owned root '$root'");
            }
            PathSafety::assert_no_symlinked_target_path($root, true, 'code-finalize');
            $absolute = PathSafety::safe_join(WP_CONTENT_DIR, $root);
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
            if (!PathSafety::has_current_path_at_or_below($root, $currentPaths)
                && is_dir($absolute)
                && (!@rmdir($absolute) || is_dir($absolute))) {
                throw new \RuntimeException("duo: code-finalize could not remove obsolete owned directory '$root'");
            }
        }
        sort($removed, SORT_STRING);
        return $removed;
    }

    /**
     * @param array<string,array<string,mixed>> $history
     * @param list<string> $roots
     */
    public static function assert_removal_safe(?array $previous, ?array $staged, array $history, array $current, array $roots): void {
        self::assert_removal_inventory(self::removal_inventory($previous, $staged, $history, $current), $roots);
    }

    /**
     * Build one deterministic ownership view used by both preflight and the
     * mutating prune. Keeping the two phases on the same inventory prevents a
     * safety check from silently drifting away from deletion behavior.
     *
     * @param array<string,array<string,mixed>> $history
     * @return array{roots:list<string>,known_hashes:array<string,array<string,bool>>,current_paths:array<string,bool>,recorded_types:array<string,array<string,bool>>,current_types:array<string,array<string,bool>>}
     */
    private static function removal_inventory(?array $previous, ?array $staged, array $history, array $current): array {
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
        $currentPaths = [];
        foreach ($current['files'] as $row) {
            $currentPaths[$row['path']] = true;
        }
        $rootNames = array_keys($roots);
        sort($rootNames, SORT_STRING);
        return [
            'roots' => $rootNames,
            'known_hashes' => $knownHashes,
            'current_paths' => $currentPaths,
            'recorded_types' => $recordedTypes,
            'current_types' => $currentTypes,
        ];
    }

    /**
     * Traverse the complete prune set without mutation. This catches a late
     * changed file, symlink, special entry, or type replacement before stage
     * writes new bytes and before finalize removes any earlier path.
     *
     * @param array{roots:list<string>,known_hashes:array<string,array<string,bool>>,current_paths:array<string,bool>,recorded_types:array<string,array<string,bool>>,current_types:array<string,array<string,bool>>} $inventory
     * @param list<string> $roots
     */
    private static function assert_removal_inventory(array $inventory, array $roots): void {
        self::assert_recorded_target_types($inventory['recorded_types'], $inventory['current_types']);
        $knownHashes = $inventory['known_hashes'];
        $currentPaths = $inventory['current_paths'];
        foreach ($inventory['roots'] as $root) {
            if (!PathSafety::safe_component_root($root, $roots) || PathSafety::reserved_path($root)) {
                throw new \RuntimeException("duo: code-finalize refuses unsafe owned root '$root'");
            }
            PathSafety::assert_no_symlinked_target_path($root, true, 'code-finalize preflight');
            $absolute = PathSafety::safe_join(WP_CONTENT_DIR, $root);
            if (is_link($absolute)) {
                throw new \RuntimeException("duo: code-finalize refuses to traverse a symlink at owned root '$root'");
            }
            if (!file_exists($absolute)) {
                continue;
            }
            if (is_file($absolute)) {
                if (!isset($currentPaths[$root])) {
                    self::assert_obsolete_file_unchanged($absolute, $root, $knownHashes);
                }
                continue;
            }
            if (!is_dir($absolute)) {
                throw new \RuntimeException("duo: code-finalize refuses to mutate a special owned root '$root'");
            }
            self::assert_prunable_directory($absolute, $root, $currentPaths, $knownHashes);
        }
    }

    /** @param array<string,bool> $currentPaths @param array<string,array<string,bool>> $knownHashes */
    private static function assert_prunable_directory(
        string $absolute,
        string $relativeRoot,
        array $currentPaths,
        array $knownHashes
    ): void {
        $children = @scandir($absolute);
        if ($children === false) {
            throw new \RuntimeException("duo: code-finalize cannot read owned component '$relativeRoot'");
        }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            if (!PathSafety::safe_component($child)) {
                throw new \RuntimeException("duo: code-finalize found an unsafe path under owned component '$relativeRoot'");
            }
            $relative = $relativeRoot . '/' . $child;
            $path = $absolute . '/' . $child;
            if (is_link($path)) {
                throw new \RuntimeException("duo: code-finalize refuses to delete a symlink at '$relative'");
            }
            if (is_dir($path)) {
                self::assert_prunable_directory($path, $relative, $currentPaths, $knownHashes);
                continue;
            }
            if (!is_file($path)) {
                throw new \RuntimeException("duo: code-finalize refuses to mutate a special path '$relative'");
            }
            if (!isset($currentPaths[$relative])) {
                self::assert_obsolete_file_unchanged($path, $relative, $knownHashes);
            }
        }
    }

    /** @param array<string,array<string,bool>> $knownHashes */
    private static function assert_obsolete_file_unchanged(string $absolute, string $relative, array $knownHashes): void {
        if (isset($knownHashes[$relative])
            && !isset($knownHashes[$relative][hash_file('sha256', $absolute)])) {
            throw new \RuntimeException("duo: code-finalize refuses to remove changed prior-owned file '$relative'");
        }
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
            PathSafety::assert_no_symlinked_target_path($relative, true, 'code-finalize type preflight');
            $absolute = PathSafety::safe_join(WP_CONTENT_DIR, $relative);
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
            if (!PathSafety::safe_component($child)) {
                throw new \RuntimeException("duo: code-finalize found an unsafe path under owned component '$relativeRoot'");
            }
            $relative = $relativeRoot . '/' . $child;
            $path = $absolute . '/' . $child;
            if (is_link($path)) {
                throw new \RuntimeException("duo: code-finalize refuses to delete a symlink at '$relative'");
            }
            if (is_dir($path)) {
                self::prune_owned_directory($path, $relative, $currentPaths, $removed, $knownHashes);
                if (!PathSafety::has_current_path_at_or_below($relative, $currentPaths)
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

    /**
     * Whether a COMPLETED descriptor's owned roots contain any file Duo
     * never recorded -- a read-only verification question distinct from
     * removal itself, used by baseline completion and mismatch detection.
     *
     * @return list<string>
     */
    public static function owned_extra_files(array $descriptor): array {
        $currentPaths = [];
        foreach ($descriptor['files'] as $row) {
            $currentPaths[$row['path']] = true;
        }
        $extras = [];
        foreach ($descriptor['owned_roots'] as $root) {
            PathSafety::assert_no_symlinked_target_path($root, true, 'completed code verification');
            $absolute = PathSafety::safe_join(WP_CONTENT_DIR, $root);
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
            if (!PathSafety::safe_component($child)) {
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
}
