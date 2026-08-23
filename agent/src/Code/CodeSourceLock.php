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
 * `duo-code-lock/v2` — the sourcing declaration for every plugin and theme
 * component a repository's code half carries.
 *
 * Two lists, one invariant. `components` names the third-party components
 * the repository deliberately does NOT carry in Git: WHERE each one's bytes
 * come from (`origin`) and WHAT the unpacked component must hash to
 * (`tree_sha256`). `first_party` names the components Git DOES carry, by the
 * operator's explicit declaration that the code is the site's own. The
 * invariant the compile gate enforces from these two lists is that Git never
 * carries third-party bytes: a component present under `code/wp-content`
 * that is in neither list is refused (`code_component_undeclared`,
 * CodeDescriptorCompiler::lock_diagnostics()) rather than vendored by
 * omission. There is no third classification and no "vendor it anyway"
 * escape: a premium plugin is imported once as an archive on the host
 * (`duo code-import`) and locked as `imported-archive`, exactly like a
 * wp.org release is locked as `wp-org-release`.
 *
 * `duo-code-lock/v1` (DUO-3499) declared `components` only and admitted a
 * `vendored-archive` origin — a ZIP committed inside the repository, which
 * is third-party bytes in Git by another name. v1 documents still parse
 * (their `first_party` is empty, so any component Git carries beside them
 * reaches `code_component_undeclared` and the remedy names `duo
 * code-classify`), but a `vendored-archive` entry is refused by name, since
 * no writer ever produced one and nothing will resolve one again.
 *
 * Scope stays deliberately narrow: no version range, no dependency graph, no
 * update policy — `CodeCompatibility` already owns dependency-graph and
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
    public const FORMAT = 'duo-code-lock/v2';

    /** The DUO-3499 grammar: `components` only, no `first_party`. Read, never written. */
    public const LEGACY_FORMAT = 'duo-code-lock/v1';

    /** The only lock path a `code.format: 2` declaration may name. */
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

    /**
     * `wp-org-release`: the canonical downloads.wordpress.org archive for a
     * published version, fetched by the host. `imported-archive`: an archive
     * the operator imported into the host's content-addressed cache with
     * `duo code-import`, identified by its digest alone — the lock records no
     * URL and no path for it, because a vendor download URL is usually
     * license-keyed and a path would name bytes the repository must not carry.
     *
     * @var list<string>
     */
    public const KINDS = ['imported-archive', 'wp-org-release'];

    /** Refused by name, with the remedy: the only kind DUO-3499 shipped that put third-party bytes in Git. */
    public const REMOVED_KIND = 'vendored-archive';

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
        $format = $lock['format'] ?? null;
        if ($format !== self::FORMAT && $format !== self::LEGACY_FORMAT) {
            throw new \RuntimeException(
                'duo: code lock format must be "' . self::FORMAT . '" (or the legacy "' . self::LEGACY_FORMAT . '")'
            );
        }
        $keys = array_keys($lock);
        sort($keys, SORT_STRING);
        $expected = $format === self::FORMAT ? ['components', 'first_party', 'format'] : ['components', 'format'];
        if ($keys !== $expected) {
            throw new \RuntimeException(
                'duo: code lock ' . $format . ' must contain exactly ' . implode(', ', $expected)
            );
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
        $sorted = $order;
        usort($sorted, static fn(array $a, array $b): int => $a <=> $b);
        if ($order !== $sorted) {
            throw new \RuntimeException('duo: code lock components are not deterministically sorted by root then component');
        }
        if ($format === self::FORMAT) {
            self::assert_first_party($lock['first_party'], $seen);
        }
    }

    /**
     * `first_party` is a sorted list of unique `{root}/{component}` identities,
     * each a lockable root and a safe component name, and none of them also a
     * locked component: one identity cannot be both "Git carries it" and "Git
     * does not carry it".
     *
     * @param mixed $firstParty
     * @param array<string,bool> $locked `{root}/{component}` => true
     */
    private static function assert_first_party($firstParty, array $locked): void {
        if (!is_array($firstParty) || !array_is_list($firstParty)) {
            throw new \RuntimeException('duo: code lock first_party must be a list');
        }
        $seen = [];
        foreach ($firstParty as $i => $identity) {
            if (!is_string($identity) || !self::is_identity($identity)) {
                throw new \RuntimeException(
                    "duo: code lock first_party[$i] must be one '{root}/{component}' identity with root "
                    . implode('/', self::ROOTS) . ' and one safe component segment'
                );
            }
            if (isset($seen[$identity])) {
                throw new \RuntimeException("duo: code lock first_party declares '$identity' more than once");
            }
            if (isset($locked[$identity])) {
                throw new \RuntimeException(
                    "duo: code lock declares '$identity' both as a locked component and as first-party"
                );
            }
            $seen[$identity] = true;
        }
        $sorted = array_keys($seen);
        sort($sorted, SORT_STRING);
        if ($firstParty !== $sorted) {
            throw new \RuntimeException('duo: code lock first_party is not deterministically sorted');
        }
    }

    /** `{root}/{component}` with a lockable root and one safe component segment. */
    public static function is_identity(string $identity): bool {
        $parts = explode('/', $identity);
        return count($parts) === 2
            && in_array($parts[0], self::ROOTS, true)
            && PathSafety::safe_component($parts[1]);
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
        if ($kind === self::REMOVED_KIND) {
            // Named rather than folded into "must be one of": the operator
            // holding a v1 lock with this entry needs the remedy, not the
            // vocabulary. The archive the entry pointed at is still in their
            // repository; importing it is one command.
            throw new \RuntimeException(
                "duo: code lock components[$i] origin.kind '" . self::REMOVED_KIND . "' is no longer a lock origin: "
                . 'Git must not carry third-party code, archives included; import the archive on the host with '
                . '`duo code-import <archive.zip>` and re-lock the component with `duo code-classify`'
            );
        }
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException(
                "duo: code lock components[$i] origin.kind must be one of " . implode('/', self::KINDS)
            );
        }
        $keys = array_keys($origin);
        sort($keys, SORT_STRING);
        $required = $kind === 'wp-org-release'
            ? ['archive_sha256', 'kind', 'url']
            : ['archive_sha256', 'kind'];
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
        }
        // An imported archive carries NO locator by design: its identity is
        // `archive_sha256`, which the host's cache is keyed by. A URL would be
        // a vendor's license-keyed download link committed to Git; a path
        // would be bytes the repository must not carry. The grammar has no
        // field for either, so neither can be written by accident.
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
     * Canonical lock bytes, always in the current format. The entries are
     * sorted here rather than trusted from the caller, so a writer cannot emit
     * a lock its own reader refuses.
     *
     * @param list<array<string,mixed>> $components
     * @param list<string> $firstParty `{root}/{component}` identities Git carries by declaration
     */
    public static function encode(array $components, array $firstParty = []): string {
        $lock = [
            'format' => self::FORMAT,
            'components' => self::sort_components($components),
            'first_party' => self::sort_first_party($firstParty),
        ];
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
     * @param list<string> $firstParty
     * @return list<string>
     */
    public static function sort_first_party(array $firstParty): array {
        $identities = array_values(array_unique(array_map('strval', $firstParty)));
        sort($identities, SORT_STRING);
        return $identities;
    }

    /**
     * `{root}/{component}` => entry, for the consumers that need lookup by
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
     * `{root}/{component}` => true for every first-party declaration. A legacy
     * v1 lock declares none, which is what sends every component Git carries
     * beside it to `code_component_undeclared` until `duo code-classify`
     * re-declares the repository.
     *
     * @param array<string,mixed> $lock
     * @return array<string,bool>
     */
    public static function first_party(array $lock): array {
        $declared = [];
        foreach ((array) ($lock['first_party'] ?? []) as $identity) {
            if (is_string($identity) && self::is_identity($identity)) {
                $declared[$identity] = true;
            }
        }
        ksort($declared, SORT_STRING);
        return $declared;
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
