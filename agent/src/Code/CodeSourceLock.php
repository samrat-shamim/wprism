<?php
declare(strict_types=1);

namespace Duo;

// Most production/bootstrap paths load Canon before Code; a few offline
// contract fixtures install a local Duo\Canon seam first, so only load the
// production implementation when no Canon class exists yet. This mirrors
// CodeDescriptorCompiler.php:4-9 for the same reason.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
require_once __DIR__ . '/../Kernel/PathSafety.php';

/**
 * `duo-code-lock/v1` — the provenance and byte-identity declaration for code
 * components the repository deliberately does NOT carry in Git.
 *
 * Scope, deliberately narrow (DUO-3499): one entry declares WHERE one
 * component's bytes came from (`origin`) and WHAT the unpacked component must
 * hash to (`tree_sha256`). It expresses no version range, no dependency graph
 * and no update policy — `CodeCompatibility` already owns dependency-graph and
 * version-range findings from the frozen source, and a lock that grew those
 * would be a second manifest treadmill.
 *
 * This class is pure: parse, validate by name, canonically encode, and derive
 * a component's `tree_sha256` from an already-built descriptor. It never reads
 * a repository, never fetches, never mutates. The compile-time gate that
 * compares a lock against on-disk bytes lives in CodeDescriptorCompiler
 * (`lock_diagnostics()`); resolution — turning an origin back into bytes — is
 * host-side by design, because a target that fetched from a registry would
 * falsify the `code_release_provider` probe attestation "off-target build and
 * dependency resolution … no target Git history or registry credentials"
 * (docs/code-release-runtime.md:24-28).
 *
 * `tree_sha256` is computed over exactly the rows
 * `CodeDescriptorCompiler::descriptor_from_source()` already builds — sorted
 * `{path, sha256}` pairs, canonically encoded — with each path made relative
 * to the component root rather than to `code/wp-content`. That reuse is the
 * point: the digest a lock declares and the digest a compile computes come
 * from one algorithm, so they cannot drift apart.
 */
final class CodeSourceLock {
    public const FORMAT = 'duo-code-lock/v1';

    /** The only lock path a `code.format: 2` declaration may name in v1. */
    public const PATH = 'code/duo-code.lock.json';

    /**
     * Lockable roots. `mu-plugins` is deliberately absent: user mu-plugins are
     * an init blocker (`mu_plugin_requires_review`,
     * agent/src/Init/InitPlanner.php:156-164), so no component that could be
     * locked ever lands there.
     *
     * @var list<string>
     */
    public const ROOTS = ['plugins', 'themes'];

    /** @var list<string> */
    public const KINDS = ['vendored-archive', 'wp-org-release'];

    /**
     * The payload source prefix, spelled as a literal rather than as
     * `CodeDescriptorCompiler::SOURCE` so this class stays loadable on its own:
     * requiring the compiler would drag CodeCompatibility and the rest of the
     * descriptor graph into every consumer of a pure grammar. The two
     * spellings are held together by
     * sandbox/tests/offline/code-half/regress_code_source_lock.php, which
     * asserts this constant equals CodeDescriptorCompiler::SOURCE
     * (agent/src/Code/CodeDescriptorCompiler.php:57).
     */
    public const SOURCE = 'code/wp-content';

    /** @return array<string,mixed> */
    public static function parse(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('duo: code lock is not a JSON object');
        }
        self::assert_lock($decoded);
        return $decoded;
    }

    /**
     * Validate one decoded lock by name. Every refusal below names the exact
     * fact that is wrong, because the operator's next action differs per fact:
     * an unsorted list is a regeneration, a bad digest is a re-resolve, an
     * unsafe component name is a repository the lock must not describe at all.
     *
     * @param array<string,mixed> $lock
     */
    public static function assert_lock(array $lock): void {
        $keys = array_keys($lock);
        sort($keys, SORT_STRING);
        if ($keys !== ['components', 'format']) {
            throw new \RuntimeException('duo: code lock must contain exactly format and components');
        }
        if (($lock['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('duo: code lock format must be "' . self::FORMAT . '"');
        }
        $components = $lock['components'];
        if (!is_array($components) || !array_is_list($components)) {
            throw new \RuntimeException('duo: code lock components must be a list');
        }
        $seen = [];
        $order = [];
        foreach ($components as $i => $entry) {
            self::assert_entry($entry, (int) $i);
            $key = $entry['root'] . '/' . $entry['component'];
            if (isset($seen[$key])) {
                throw new \RuntimeException("duo: code lock declares component '$key' more than once");
            }
            $seen[$key] = true;
            $order[] = [(string) $entry['root'], (string) $entry['component']];
        }
        $expected = $order;
        usort($expected, static fn(array $a, array $b): int => $a <=> $b);
        if ($order !== $expected) {
            throw new \RuntimeException('duo: code lock components are not deterministically sorted by root then component');
        }
    }

    /** @param mixed $entry */
    private static function assert_entry($entry, int $i): void {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \RuntimeException("duo: code lock components[$i] must be an object");
        }
        $keys = array_keys($entry);
        sort($keys, SORT_STRING);
        if ($keys !== ['component', 'origin', 'root', 'tree_sha256', 'version']) {
            throw new \RuntimeException(
                "duo: code lock components[$i] must contain exactly component, origin, root, tree_sha256, and version"
            );
        }
        if (!is_string($entry['root']) || !in_array($entry['root'], self::ROOTS, true)) {
            throw new \RuntimeException(
                "duo: code lock components[$i] root must be one of " . implode('/', self::ROOTS)
            );
        }
        if (!is_string($entry['component']) || !PathSafety::safe_component($entry['component'])) {
            throw new \RuntimeException("duo: code lock components[$i] component must be one safe path segment");
        }
        if (!is_string($entry['version']) || trim($entry['version']) === ''
            || preg_match('/[\x00-\x1f\x7f]/', $entry['version']) === 1) {
            throw new \RuntimeException("duo: code lock components[$i] version must be a non-empty single-line string");
        }
        if (!self::is_digest($entry['tree_sha256'])) {
            throw new \RuntimeException("duo: code lock components[$i] tree_sha256 must be 64 lowercase hex characters");
        }
        self::assert_origin($entry['origin'], $i);
    }

    /** @param mixed $origin */
    private static function assert_origin($origin, int $i): void {
        if (!is_array($origin) || array_is_list($origin)) {
            throw new \RuntimeException("duo: code lock components[$i] origin must be an object");
        }
        $kind = $origin['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException(
                "duo: code lock components[$i] origin.kind must be one of " . implode('/', self::KINDS)
            );
        }
        $keys = array_keys($origin);
        sort($keys, SORT_STRING);
        $locator = $kind === 'wp-org-release' ? 'url' : 'path';
        $required = ['archive_sha256', 'kind', $locator];
        sort($required, SORT_STRING);
        $withRoot = [...$required, 'archive_root'];
        sort($withRoot, SORT_STRING);
        if ($keys !== $required && $keys !== $withRoot) {
            throw new \RuntimeException(
                "duo: code lock components[$i] origin of kind '$kind' must contain exactly "
                . implode(', ', $required) . ' and may add archive_root'
            );
        }
        if (!self::is_digest($origin['archive_sha256'])) {
            throw new \RuntimeException("duo: code lock components[$i] origin.archive_sha256 must be 64 lowercase hex characters");
        }
        if ($kind === 'wp-org-release') {
            $url = $origin['url'];
            // HTTPS only, and no whitespace anywhere: the URL is handed to a
            // host-side fetcher (curl argv or a PHP stream), and a folded or
            // space-bearing value is the shape that turns one locator into two
            // arguments. There is no http:// escape hatch by design.
            if (!is_string($url) || !str_starts_with($url, 'https://') || strlen($url) < 12
                || preg_match('/\s/', $url) === 1) {
                throw new \RuntimeException(
                    "duo: code lock components[$i] origin.url must be an https:// URL with no whitespace"
                );
            }
        } else {
            $path = $origin['path'];
            // A vendored archive is a repository-relative path. safe_relative()
            // is the same predicate the descriptor applies to every payload
            // path (agent/src/Kernel/PathSafety.php:68-75), so '..', a leading
            // '/', a backslash and a control byte are all refused here — the
            // lock can never name bytes outside the repository it describes.
            if (!is_string($path) || !PathSafety::safe_relative($path)) {
                throw new \RuntimeException(
                    "duo: code lock components[$i] origin.path must be a repository-relative path that cannot escape the repository"
                );
            }
        }
        if (array_key_exists('archive_root', $origin)
            && (!is_string($origin['archive_root']) || !PathSafety::safe_relative($origin['archive_root']))) {
            throw new \RuntimeException(
                "duo: code lock components[$i] origin.archive_root must be a safe relative path inside the archive"
            );
        }
    }

    /** @param mixed $value */
    private static function is_digest($value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * Canonical lock bytes. The entries are sorted here rather than trusted
     * from the caller, so a writer cannot emit a lock its own reader refuses.
     *
     * @param list<array<string,mixed>> $components
     */
    public static function encode(array $components): string {
        $lock = ['format' => self::FORMAT, 'components' => self::sort_components($components)];
        self::assert_lock($lock);
        return Canon::encode($lock);
    }

    /**
     * @param list<array<string,mixed>> $components
     * @return list<array<string,mixed>>
     */
    public static function sort_components(array $components): array {
        $rows = array_values($components);
        usort($rows, static fn(array $a, array $b): int =>
            [(string) ($a['root'] ?? ''), (string) ($a['component'] ?? '')]
            <=> [(string) ($b['root'] ?? ''), (string) ($b['component'] ?? '')]);
        return $rows;
    }

    /**
     * `{root}/{component}` => entry, for the two consumers that need lookup by
     * identity (the compile gate and CodeStateContract's remedy branch).
     *
     * @param array<string,mixed> $lock
     * @return array<string,array<string,mixed>>
     */
    public static function index(array $lock): array {
        $index = [];
        foreach ((array) ($lock['components'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['root'] ?? null) && is_string($entry['component'] ?? null)) {
                $index[$entry['root'] . '/' . $entry['component']] = $entry;
            }
        }
        return $index;
    }

    /**
     * The rows of one component's subtree, taken from a descriptor's `files`
     * inventory with the `{root}/{component}/` prefix removed.
     *
     * @param list<array{path:string,sha256:string}>|array<mixed> $files
     * @return list<array{path:string,sha256:string}>
     */
    public static function component_rows(array $files, string $root, string $component): array {
        $prefix = $root . '/' . $component . '/';
        $rows = [];
        foreach ($files as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null) || !is_string($row['sha256'] ?? null)) {
                continue;
            }
            if (!str_starts_with($row['path'], $prefix)) {
                continue;
            }
            $rows[] = ['path' => substr($row['path'], strlen($prefix)), 'sha256' => $row['sha256']];
        }
        usort($rows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return $rows;
    }

    /**
     * The digest a lock entry's `tree_sha256` must equal.
     *
     * @param list<array{path:string,sha256:string}> $rows
     */
    public static function tree_sha256(array $rows): string {
        return hash('sha256', Canon::encode($rows));
    }

    /**
     * A component's tree digest computed from an already-built descriptor, or
     * null when the descriptor owns no file under that component — which is
     * exactly the "absent from disk" case the compile gate reports as
     * `code_component_unresolved`.
     *
     * @param array<string,mixed> $descriptor
     */
    public static function tree_sha256_from_descriptor(array $descriptor, string $root, string $component): ?string {
        $rows = self::component_rows((array) ($descriptor['files'] ?? []), $root, $component);
        return $rows === [] ? null : self::tree_sha256($rows);
    }

    /**
     * The exact `.gitignore` line a locked component gets.
     *
     * Root-anchored, and written only to the repository-root `.gitignore`:
     * a `.gitignore` at `code/wp-content/` is refused by the descriptor
     * compiler as an unsafe payload path
     * (agent/src/Code/CodeDescriptorCompiler.php:97-99), and one at
     * `code/wp-content/plugins/` would be inventoried as an owned component
     * file and SHIPPED to the target. Those are the two placements this
     * helper exists to make impossible to reach by accident.
     */
    public static function gitignore_line(string $root, string $component): string {
        if (!in_array($root, self::ROOTS, true) || !PathSafety::safe_component($component)) {
            throw new \RuntimeException('duo: code lock cannot ignore an unsafe component identity');
        }
        return '/' . self::SOURCE . '/' . $root . '/' . $component . '/';
    }

    /**
     * The components a repository's own `.gitignore` excludes from Git.
     *
     * Deliberately literal: only a line that is exactly the root-anchored form
     * gitignore_line() writes counts. No glob engine, no `git` subprocess, no
     * `.git/index` parsing — the compile gate must give the same answer inside
     * a container with no git binary and on a checkout with no `.git` at all,
     * and a partial glob implementation would silently disagree with Git.
     *
     * A component excluded here but absent from the lock is the failure the
     * gate names `code_component_unlocked`: Git will not carry its bytes and
     * nothing declares how to get them back.
     *
     * @return array<string,bool> `{root}/{component}` => true
     */
    public static function ignored_components(string $gitignore): array {
        $ignored = [];
        $prefix = '/' . self::SOURCE . '/';
        foreach (preg_split('/\r?\n/', $gitignore) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#' || !str_starts_with($line, $prefix) || !str_ends_with($line, '/')) {
                continue;
            }
            $relative = substr($line, strlen($prefix), -1);
            $parts = explode('/', $relative);
            // A literal component only. `plugins/*/` is a Git pattern, not an
            // identity, and reading it as one would let the gate claim a
            // component named `*` is locked (or unlocked) when Git is in fact
            // excluding every sibling beside it.
            if (count($parts) !== 2 || !in_array($parts[0], self::ROOTS, true)
                || !PathSafety::safe_component($parts[1])
                || preg_match('/[*?\[\]!]/', $parts[1]) === 1) {
                continue;
            }
            $ignored[$relative] = true;
        }
        ksort($ignored, SORT_STRING);
        return $ignored;
    }
}
