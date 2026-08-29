#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use RuntimeException;

/**
 * Assemble the adapter test kit — the already-generic half of sandbox/ —
 * as a distributable developer artifact, and pin it under `make release-gate`.
 *
 * Usage:
 *   php tools/adapter-kit.php --check              # byte-compare tools/adapter-kit.json (default)
 *   php tools/adapter-kit.php --write              # regenerate tools/adapter-kit.json
 *   php tools/adapter-kit.php --print              # print the manifest to stdout
 *   php tools/adapter-kit.php --assemble=DIR [--adapter=slug]
 *
 * WHY THIS EXISTS
 * ---------------
 * `cli/src/Onboarding/Adopt.php` tars exactly `agent recovery`, so
 * nothing under `sandbox/` reaches anyone — yet that is where the entire
 * ability to PROVE an adapter lives. A third party writing an adapter today
 * has the manifest grammar and no harness, so they write their own $wpdb fake;
 * `sandbox/tests/lib/README.md` counts 42 bespoke fakes and 33 bespoke stub
 * sets already in-tree that disagree with each other, and the one property
 * they all get wrong is the important one: `FakeWpdb` throws \LogicException
 * NAMING any statement it cannot interpret (FakeWpdb.php:29), where a
 * hand-rolled fake returns null and pushes the suite down a "no row" branch
 * the live gate never takes. That is a false green, and it is guaranteed to be
 * reinvented wrongly by anyone who has to reinvent it.
 *
 * ASSEMBLED, NOT COPIED
 * ---------------------
 * The kit's PHP/shell members are not stored here in any form. `assemble()`
 * reads the LIVE files and writes them byte-for-byte into a target directory,
 * so a kit is by construction a function of the tree at the moment it was
 * built — there is no second copy of check.php in the repository that could
 * receive a fix the original never got, which is the failure mode a
 * "distributable copy of the harness" normally ends in.
 *
 * What IS committed is `tools/adapter-kit.json`: the member list, each
 * member's sha256, the declared out-of-kit dependencies, and the adoption tar
 * composition read out of Adopt.php. `--check` regenerates it in memory and
 * byte-compares, exactly the way tools/capability-doc.php and
 * tools/offline-corpus.php pin their outputs, so:
 *
 *   - editing a kit source file (say FakeWpdb.php) moves a digest and the gate
 *     asks for one command, which turns "the shipped harness changed" from an
 *     invisible side effect into a reviewed line in the diff;
 *   - adding or dropping a member cannot happen silently;
 *   - a new out-of-kit `require` inside a kit member is refused BY NAME
 *     (see EXTERNAL_DEPENDENCIES), because that is precisely how a kit that
 *     ran standalone yesterday becomes a kit that only runs inside this repo;
 *   - a change to Adopt.php's tar line moves `adoption_tar` here, so "the kit
 *     is not shipped to managed sites" is a checked fact rather than a promise
 *     in a comment.
 *
 * WHAT THE KIT IS NOT
 * -------------------
 * It ships no product code. Nothing under `agent/`, `cli/` or `recovery/`
 * requires anything in it, and it is deliberately NOT added to Adopt's tar:
 * the drop-in stays dependency-free (AGENTS.md rule 1) and a managed site
 * still receives exactly `agent recovery`; the adapter library is embedded
 * beneath `agent/` and therefore crosses the same atomic install boundary.
 * `sandbox/tests/offline/guards/regress_adapter_test_kit.php` asserts both.
 *
 * Plain PHP, no composer, requirable without side effects (the same
 * SCRIPT_FILENAME guard idiom as tools/offline-corpus.php) so
 * tests/Tooling/AdapterKitTest.php can drive the pure halves in-process.
 */
final class AdapterKit
{
    /** The wire generation of tools/adapter-kit.json. */
    public const FORMAT = 'wprism-adapter-kit/v1';

    /** The committed manifest this file owns. */
    public const MANIFEST_PATH = 'tools/adapter-kit.json';

    /** The kit-relative name the manifest takes inside an assembled kit. */
    public const KIT_MANIFEST = 'MANIFEST.json';

    /** The adapter slug the committed manifest's skeleton is generated for. */
    public const DEFAULT_ADAPTER = 'example';

    /**
     * Live repository files copied verbatim into the kit.
     *
     * kit path => [repo path, role]. The order is the order the manifest and
     * the generated README list them in, so it is documentation as well as
     * data: harness first, conformance harness second.
     */
    public const COPIED = [
        'lib/check.php' => ['sandbox/tests/lib/check.php', 'wprism_check*() assertions, the summary line and the suite exit code'],
        'lib/wp_stubs.php' => ['sandbox/tests/lib/wp_stubs.php', 'WPrismTest\\WpStore plus function_exists()-guarded WordPress function stubs'],
        'lib/FakeWpdb.php' => ['sandbox/tests/lib/FakeWpdb.php', 'WPrismTest\\FakeWpdb — a $wpdb that holds ROWS and interprets SQL against them'],
        'lib/frozen_policy.php' => ['sandbox/tests/lib/frozen_policy.php', 'WPrismTest\\FrozenPolicy — a wprism-policy-snapshot/v6 envelope; needs the agent runtime'],
        'lib/ConformanceVector.php' => ['sandbox/tests/lib/ConformanceVector.php', 'WPrismTest\\ConformanceVector — record a wprism-conformance-vector/v1 once on a pair, replay the round trip offline forever; needs the agent runtime'],
        'lib/pair_identity.sh' => ['sandbox/lib/pair_identity.sh', 'candidate source resolution and caller-local Compose mount pinning for parallel evidence lanes'],
        'conformance/run.sh' => ['sandbox/conformance/run.sh', 'the manifest-agnostic capture -> apply -> re-capture round-trip harness; needs a pair estate'],
        'conformance/asserts.sh' => ['sandbox/conformance/asserts.sh', 'premise assertions every seed/postdeploy hook calls before its engine assertion'],
    ];

    /**
     * Dependencies a kit member reaches for OUTSIDE the kit, declared.
     *
     * This list is a closed vocabulary, checked in both directions: a target
     * that does not resolve inside the kit and is not declared here refuses
     * the build by name, and a declaration nothing reaches for any more
     * refuses it too. Without the first half, a `require_once __DIR__ .
     * '/../../../agent/...'` added to check.php in a normal in-repo change
     * would silently produce a kit that only works inside this repository —
     * and it would work in every in-repo test, because in-repo the path
     * resolves. Without the second half the list rots into folklore.
     *
     * kit path => [target as written => why it is acceptable].
     */
    public const EXTERNAL_DEPENDENCIES = [
        'lib/check.php' => [
            '/../../../agent/src/Kernel/CommandRefusal.php' =>
                'wprism_check_refuses() lazily requires the agent class it asserts on, and ONLY when '
                . '\\WPrism\\CommandRefusalException is undeclared (check.php:348-357). Outside this '
                . 'repository the path does not exist, so that one helper is unavailable to a kit '
                . 'user; every other assertion, both stub sets and the whole SQL interpreter are '
                . 'reached without it, which the generated skeleton demonstrates by running to a '
                . 'PASS from a directory that has no agent/ above it.',
        ],
        'conformance/run.sh' => [
            'bin/fetch-artifact.sh' =>
                'run.sh is the estate-bound member: it cd\'s to its own parent and drives a '
                . 'disposable env pair through docker (run.sh:70, :172-173). It is shipped as the '
                . 'reference round-trip harness — the one file that states what an adapter has to '
                . 'survive — not as something a kit user runs unmodified.',
        ],
    ];

    /** A slug that can be a file name, a class name fragment and a Makefile-ish target. */
    public const SLUG_PATTERN = '#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#';

    /** Where Adopt.php composes the archive a managed site receives. */
    public const ADOPT_PATH = 'cli/src/Onboarding/Adopt.php';

    /** The tar composition the kit asserts it is NOT part of. */
    public const ADOPTION_TAR = ['agent', 'recovery'];

    /**
     * The adoption tar's component list, read out of Adopt.php.
     *
     * Read rather than restated: the whole point of recording it in the
     * manifest is that a change to the tar line becomes a diff HERE. A
     * hardcoded answer would record what this file believes instead of what
     * Adopt.php does, which is the failure mode it exists to prevent.
     *
     * @return list<string>
     */
    public static function adoptionTar(string $repo): array
    {
        $path = $repo . '/' . self::ADOPT_PATH;
        $source = @file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException('cannot read ' . self::ADOPT_PATH);
        }
        if (preg_match('#escapeshellarg\(\$localArchive\)\s*\.\s*\'\s+([a-z ]+)\'#', $source, $m) !== 1) {
            throw new RuntimeException(
                'cannot read the adoption tar composition from ' . self::ADOPT_PATH
                . ' — the kit records what a managed site receives, so an unreadable tar line is a refusal, not a default'
            );
        }

        return preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Every dependency a member names, as written in the file.
     *
     * PHP members: `require`/`include` of a `__DIR__ . '…'` literal — the one
     * form every file in sandbox/tests/lib/ uses, and the only one that can be
     * resolved statically. A computed require would be refused by
     * resolveTargets() rather than parsed, because a kit whose dependencies
     * are decided at run time cannot be checked at build time.
     *
     * Shell members: `.`/`source` of a literal path. run.sh resolves those
     * against its own parent directory (it cd's there at :70), which is the
     * kit root, so that is the base resolveTargets() uses for .sh members.
     *
     * @return list<string>
     */
    public static function dependencyTargets(string $kitPath, string $contents): array
    {
        $targets = [];
        if (str_ends_with($kitPath, '.php')) {
            if (preg_match_all(
                '#(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*\'([^\']+)\'#',
                $contents,
                $matches
            ) > 0) {
                $targets = $matches[1];
            }
        } elseif (str_ends_with($kitPath, '.sh')) {
            if (preg_match_all('#^[ \t]*(?:\.|source)[ \t]+([^\s;&|]+)#m', $contents, $matches) > 0) {
                $targets = $matches[1];
            }
        }

        return array_values(array_unique(array_map('strval', $targets)));
    }

    /**
     * Split a member's targets into the ones that land on another member and
     * the ones that leave the kit.
     *
     * @param list<string> $memberPaths every kit-relative path the kit holds
     * @param list<string> $targets
     * @return array{internal: list<string>, external: list<string>}
     */
    public static function resolveTargets(array $memberPaths, string $kitPath, array $targets): array
    {
        $internal = [];
        $external = [];
        $phpBase = trim(dirname($kitPath), '.');
        foreach ($targets as $target) {
            // .sh members resolve against the kit root, .php members against
            // their own directory (see dependencyTargets()).
            $base = str_ends_with($kitPath, '.php') ? $phpBase : '';
            $resolved = self::normalize(($base === '' ? '' : $base . '/') . ltrim($target, '/'));
            if ($resolved !== null && in_array($resolved, $memberPaths, true)) {
                $internal[] = $target;
                continue;
            }
            $external[] = $target;
        }

        return ['internal' => $internal, 'external' => $external];
    }

    /**
     * Collapse `.`/`..` inside a kit-relative path; null once it climbs out of
     * the kit, which is exactly the case that must not be silently resolved.
     */
    public static function normalize(string $path): ?string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return implode('/', $out);
    }

    /** `my-forms` -> `My_Forms`, the class-name half of a slug. */
    public static function studly(string $slug): string
    {
        return implode('_', array_map(
            static fn(string $part): string => ucfirst($part),
            explode('-', $slug)
        ));
    }

    /** `my-forms` -> `my_forms`, the file-name half of a slug. */
    public static function snake(string $slug): string
    {
        return str_replace('-', '_', $slug);
    }

    public static function assertSlug(string $slug): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new RuntimeException(
                "adapter slug '$slug' is not usable as a file and class name; expected lowercase words joined by '-'"
            );
        }
    }

    /** The kit path of the generated suite for $slug. */
    public static function skeletonSuitePath(string $slug): string
    {
        return 'skeleton/regress_' . self::snake($slug) . '_kit.php';
    }

    /** The kit path of the generated synthetic adapter for $slug. */
    public static function skeletonAdapterPath(string $slug): string
    {
        return 'skeleton/' . $slug . '-adapter.php';
    }

    /**
     * The generated members, kit path => bytes.
     *
     * The skeleton is generated rather than shipped as a static example for
     * the same reason the copied members are copied: an example that is a file
     * in this repository is an example that drifts. It is also the kit's own
     * smoke test — `php <kit>/skeleton/regress_<slug>_kit.php` exercises the
     * assertions, the WordPress stubs, the SQL interpreter and the
     * uninterpretable-statement refusal in one run, using nothing but the kit,
     * which is what makes "this kit works" checkable by whoever received it.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,string>
     */
    public static function generated(array $manifest, string $slug): array
    {
        return [
            self::skeletonAdapterPath($slug) => self::skeletonAdapter($slug),
            self::skeletonSuitePath($slug) => self::skeletonSuite($slug),
            'README.md' => self::readme($manifest, $slug),
        ];
    }

    /**
     * A stand-in for the adapter author's own plugin code.
     *
     * Everything here is what a real adapter's PHP does around the database:
     * a prepared read, a prepared write, an option read — plus one method that
     * issues SQL FakeWpdb deliberately does not interpret (a LEFT JOIN), which
     * exists so the generated suite can assert the loudness property instead
     * of describing it.
     */
    public static function skeletonAdapter(string $slug): string
    {
        $class = self::studly($slug) . '_Adapter_Store';
        $table = self::snake($slug) . '_forms';
        $option = self::snake($slug) . '_retention_days';

        return <<<PHP
        <?php

        /**
         * GENERATED by the WPrism adapter test kit — replace this file with your own plugin code.
         *
         * It stands in for the part of an adapter that touches the database and
         * WordPress: a prepared read, a prepared write, an option read, and one
         * deliberately unsupported statement (see orphan_report()).
         */
        declare(strict_types=1);

        final class {$class}
        {
            public function __construct(private object \$wpdb) {}

            public function table(): string {
                return \$this->wpdb->prefix . '{$table}';
            }

            /** @return list<string> */
            public function published_titles(): array {
                return \$this->wpdb->get_col(\$this->wpdb->prepare(
                    'SELECT title FROM ' . \$this->table() . ' WHERE status = %s ORDER BY id ASC',
                    'publish'
                ));
            }

            public function rename(int \$id, string \$title): void {
                \$this->wpdb->query(\$this->wpdb->prepare(
                    'UPDATE ' . \$this->table() . ' SET title = %s WHERE id = %d',
                    \$title,
                    \$id
                ));
            }

            public function retention_label(): string {
                \$days = (int) get_option('{$option}', 0);

                return \$days > 0 ? \$days . 'd' : 'forever';
            }

            /**
             * A LEFT JOIN, which FakeWpdb refuses on purpose: a query whose
             * behaviour depends on the real planner belongs in live
             * certification, not in an in-memory reimplementation of MySQL.
             *
             * @return list<array<string,mixed>>
             */
            public function orphan_report(): array {
                return \$this->wpdb->get_results(
                    'SELECT f.id FROM ' . \$this->table() . ' AS f'
                    . ' LEFT JOIN ' . \$this->wpdb->posts . ' AS p ON p.ID = f.post_id'
                    . ' WHERE p.ID IS NULL',
                    ARRAY_A
                );
            }
        }

        PHP;
    }

    /**
     * The generated suite: kit-relative requires only, `php` and nothing else.
     *
     * It deliberately does not call wprism_check_refuses(): that is the one
     * helper whose dependency leaves the kit (EXTERNAL_DEPENDENCIES), so a
     * skeleton that used it would fail for a kit user and pass here.
     */
    public static function skeletonSuite(string $slug): string
    {
        $class = self::studly($slug) . '_Adapter_Store';
        $table = 'wp_' . self::snake($slug) . '_forms';
        $option = self::snake($slug) . '_retention_days';
        $adapterFile = basename(self::skeletonAdapterPath($slug));

        return <<<PHP
        <?php

        /**
         * GENERATED by the WPrism adapter test kit — the shape of an adapter regression suite.
         *
         * Run it with `php` from anywhere: no composer, no WordPress, no database,
         * and no path that leaves this kit. Point the requires at your own plugin
         * code, seed the rows your adapter reads, and assert what it does with them.
         *
         * The last assertion is the one worth keeping. FakeWpdb holds ROWS and
         * interprets SQL against them, so a statement it cannot interpret throws
         * \\LogicException naming that statement instead of returning null — which
         * is what a hand-rolled fake does, and how a suite ends up asserting a
         * "no row" branch the real database never takes.
         */
        declare(strict_types=1);

        require_once __DIR__ . '/../lib/check.php';
        require_once __DIR__ . '/../lib/wp_stubs.php';
        require_once __DIR__ . '/../lib/FakeWpdb.php';
        require_once __DIR__ . '/{$adapterFile}';

        use WPrismTest\FakeWpdb;
        use WPrismTest\WpStore;

        WpStore::reset()->seedOptions(['{$option}' => '30']);

        \$wpdb = FakeWpdb::install();
        \$wpdb->seedTable('{$table}', [
            ['id' => 1, 'post_id' => 11, 'title' => 'Contact', 'status' => 'publish'],
            ['id' => 2, 'post_id' => 12, 'title' => 'Signup', 'status' => 'publish'],
            ['id' => 3, 'post_id' => 13, 'title' => 'Draft form', 'status' => 'draft'],
        ]);

        \$adapter = new {$class}(\$wpdb);

        wprism_check_same(
            ['Contact', 'Signup'],
            \$adapter->published_titles(),
            'the prepared SELECT is interpreted against the seeded rows, not transcribed'
        );

        \$adapter->rename(2, 'Signup v2');
        wprism_check_same(
            'Signup v2',
            \$wpdb->rows('{$table}')[1]['title'],
            'the prepared UPDATE lands on the row it addressed'
        );

        wprism_check_same(
            '30d',
            \$adapter->retention_label(),
            'get_option() reads the seeded WpStore, with WordPress-faithful semantics'
        );

        wprism_check_throws(
            static fn(): array => \$adapter->orphan_report(),
            LogicException::class,
            'an uninterpretable statement is refused by name rather than answered with null',
            'FakeWpdb'
        );

        wprism_check_summary('{$slug} adapter kit skeleton');

        PHP;
    }

    /**
     * The kit's README, projected from the manifest so it cannot describe a
     * different kit than the one that was assembled.
     *
     * @param array<string,mixed> $manifest
     */
    public static function readme(array $manifest, string $slug): string
    {
        /** @var list<array<string,mixed>> $members */
        $members = $manifest['members'];
        $rows = [];
        foreach ($members as $member) {
            $origin = $member['origin'] === 'copied'
                ? '`' . $member['source'] . '`'
                : 'generated';
            $rows[] = '| `' . $member['path'] . '` | ' . $origin . ' | ' . $member['role'] . ' |';
        }
        $external = [];
        foreach ((array) $manifest['external_dependencies'] as $dependency) {
            $external[] = '  - `' . $dependency['member'] . '` reaches for `' . $dependency['target'] . '`: '
                . $dependency['note'];
        }
        $tar = implode(' ', (array) $manifest['adoption_tar']['components']);
        $suite = self::skeletonSuitePath($slug);
        $memberTable = implode("\n", $rows);
        $externalList = implode("\n", $external);
        $format = $manifest['format'];

        return <<<MD
        # WPrism adapter test kit ({$format})

        The harness WPrism's own adapters are proven with, assembled straight out of the
        WPrism tree by `tools/adapter-kit.php`. Nothing here is a copy kept in parallel:
        every file below was read from the live source at assembly time, and
        `MANIFEST.json` carries the sha256 of each one so you can tell exactly which
        revision you received.

        ## Run it

        ```sh
        php {$suite}
        ```

        That is the whole dependency list: `php`. No composer, no WordPress, no
        database, and no path that leaves this directory. It should print four `ok:`
        lines and `PASS`, exiting 0; a failing assertion prints `FAIL:` on stderr and
        exits 1, which is the contract your CI can read.

        ## What is in it

        | file | origin | what it gives you |
        | --- | --- | --- |
        {$memberTable}

        ## The property worth taking

        `FakeWpdb` holds ROWS and interprets your SQL against them. Anything it cannot
        interpret throws `\\LogicException` naming the statement — it never answers
        with `null`. A fake that answers `null` for a query it does not recognise
        silently pushes your suite down a "no row" branch your real database never
        takes, and every assertion after that point is green for the wrong reason.
        The generated skeleton's last assertion is exactly this case, kept so that a
        kit you have modified still proves it.

        The same posture runs through `check.php`: `wprism_check_summary()` exits 1 when
        nothing was asserted at all, because a suite that silently stopped asserting
        is the other way a green line stops meaning anything.

        ## What is deliberately NOT in it

        - **WPrism itself.** A managed site receives exactly `tar … {$tar}`; this kit is
          not part of that archive and no WPrism runtime code requires it. It is a test
          harness, not a runtime dependency.
        - **Anything that reaches out of this directory**, with these declared
          exceptions:

        {$externalList}

        ## Keeping it current

        Re-assemble from a newer WPrism tree rather than patching a file here: these
        files are generated output, and a local edit is a fork that will silently
        disagree with the harness WPrism's own suites run against.

        MD;
    }

    /**
     * The manifest: what the kit holds, what each member hashes to, what
     * leaves the kit, and the tar composition it is not part of.
     *
     * @return array<string,mixed>
     */
    public static function manifest(string $repo, string $slug = self::DEFAULT_ADAPTER): array
    {
        self::assertSlug($slug);
        $copied = [];
        foreach (self::COPIED as $kitPath => [$source, $role]) {
            $bytes = @file_get_contents($repo . '/' . $source);
            if (!is_string($bytes)) {
                throw new RuntimeException('kit source is missing: ' . $source);
            }
            $copied[$kitPath] = ['bytes' => $bytes, 'source' => $source, 'role' => $role];
        }

        // Two passes: the generated members' contents depend on the manifest
        // (the README lists every member), so the member LIST is built first
        // and the bytes of the generated half are filled in after.
        $slugRoles = [
            self::skeletonAdapterPath($slug) => 'a synthetic adapter standing in for your plugin code',
            self::skeletonSuitePath($slug) => 'the suite skeleton: `php` and nothing else',
            'README.md' => 'what the kit is, how to run it, and what it deliberately omits',
        ];
        $members = [];
        foreach ($copied as $kitPath => $row) {
            $members[] = [
                'path' => $kitPath,
                'origin' => 'copied',
                'source' => $row['source'],
                'role' => $row['role'],
                'bytes' => strlen($row['bytes']),
                'sha256' => hash('sha256', $row['bytes']),
            ];
        }
        foreach ($slugRoles as $kitPath => $role) {
            $members[] = [
                'path' => $kitPath,
                'origin' => 'generated',
                'source' => null,
                'role' => $role,
                'bytes' => 0,
                'sha256' => '',
            ];
        }
        $memberPaths = array_map(static fn(array $m): string => (string) $m['path'], $members);

        $manifest = [
            '_comment' => 'GENERATED by tools/adapter-kit.php -- do not edit by hand. '
                . 'Regenerate with `php tools/adapter-kit.php --write`; `make release-gate` byte-compares it. '
                . 'The kit itself is never stored: `php tools/adapter-kit.php --assemble=DIR` reads the live '
                . 'files listed below and writes them into DIR, so a kit cannot fork from the harness WPrism runs. '
                . 'A moved digest here means a shipped harness file changed, which is a reviewable fact; a '
                . 'moved `adoption_tar` means the archive a managed site receives changed, and the kit is '
                . 'asserted to be no part of it.',
            'format' => self::FORMAT,
            'generated_by' => 'tools/adapter-kit.php',
            'skeleton_adapter' => $slug,
            'adoption_tar' => [
                'source' => self::ADOPT_PATH,
                'components' => self::adoptionTar($repo),
            ],
            'external_dependencies' => self::externalDependencies($copied, $memberPaths),
            'members' => $members,
        ];

        // Fill in the generated halves now that the member list is known.
        $generated = self::generated($manifest, $slug);
        foreach ($manifest['members'] as $index => $member) {
            if ($member['origin'] !== 'generated') {
                continue;
            }
            $bytes = $generated[$member['path']] ?? null;
            if (!is_string($bytes)) {
                throw new RuntimeException('no generator for kit member ' . $member['path']);
            }
            $manifest['members'][$index]['bytes'] = strlen($bytes);
            $manifest['members'][$index]['sha256'] = hash('sha256', $bytes);
        }

        return $manifest;
    }

    /**
     * Every out-of-kit target the copied members reach for, checked against
     * EXTERNAL_DEPENDENCIES in both directions.
     *
     * @param array<string,array{bytes:string,source:string,role:string}> $copied
     * @param list<string> $memberPaths
     * @return list<array{member:string,target:string,note:string}>
     */
    public static function externalDependencies(array $copied, array $memberPaths): array
    {
        $rows = [];
        $seen = [];
        foreach ($copied as $kitPath => $row) {
            $targets = self::dependencyTargets($kitPath, $row['bytes']);
            $split = self::resolveTargets($memberPaths, $kitPath, $targets);
            foreach ($split['external'] as $target) {
                $note = self::EXTERNAL_DEPENDENCIES[$kitPath][$target] ?? null;
                if ($note === null) {
                    throw new RuntimeException(
                        $kitPath . ' reaches outside the kit for ' . $target . ', which is not declared in '
                        . 'AdapterKit::EXTERNAL_DEPENDENCIES. A kit member that requires a path only this '
                        . 'repository has produces a kit that works here and nowhere else; declare it with the '
                        . 'reason it is acceptable, or keep the dependency inside the kit.'
                    );
                }
                $seen[$kitPath . "\0" . $target] = true;
                $rows[] = ['member' => $kitPath, 'target' => $target, 'note' => $note];
            }
        }
        foreach (self::EXTERNAL_DEPENDENCIES as $kitPath => $declared) {
            foreach (array_keys($declared) as $target) {
                if (!isset($seen[$kitPath . "\0" . $target])) {
                    throw new RuntimeException(
                        'AdapterKit::EXTERNAL_DEPENDENCIES declares ' . $kitPath . ' -> ' . $target
                        . ', which that member no longer reaches for. A declaration nothing exercises is '
                        . 'folklore; drop it.'
                    );
                }
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $manifest */
    public static function render(array $manifest): string
    {
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return $json . "\n";
    }

    /**
     * Write the kit into $dir. Returns the kit-relative paths written.
     *
     * The copied members are read from the tree at THIS moment and written
     * byte for byte; the manifest is recomputed and compared against the
     * committed one first, so an assembly can never hand out a kit whose
     * checksum sheet is stale.
     *
     * @return list<string>
     */
    public static function assemble(string $repo, string $dir, string $slug = self::DEFAULT_ADAPTER): array
    {
        self::assertSlug($slug);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create ' . $dir);
        }
        $existing = array_diff(scandir($dir) ?: [], ['.', '..']);
        if ($existing !== []) {
            throw new RuntimeException(
                'refusing to assemble into a non-empty directory: ' . $dir
                . ' — a kit assembled over an older one is exactly the fork this tool exists to prevent'
            );
        }
        self::assertManifestCurrent($repo);

        $manifest = self::manifest($repo, $slug);
        $written = [];
        $generated = self::generated($manifest, $slug);
        foreach ($manifest['members'] as $member) {
            $path = (string) $member['path'];
            if ($member['origin'] === 'copied') {
                $bytes = @file_get_contents($repo . '/' . $member['source']);
                if (!is_string($bytes)) {
                    throw new RuntimeException('kit source is missing: ' . $member['source']);
                }
            } else {
                $bytes = $generated[$path];
            }
            if (hash('sha256', $bytes) !== $member['sha256']) {
                throw new RuntimeException('kit member changed while assembling: ' . $path);
            }
            self::write($dir . '/' . $path, $bytes);
            $written[] = $path;
        }
        self::write($dir . '/' . self::KIT_MANIFEST, self::render($manifest));
        $written[] = self::KIT_MANIFEST;

        return $written;
    }

    /**
     * Refuse an assembly whose committed checksum sheet no longer matches the
     * tree. The digests are the only thing a kit user has to tell one
     * revision of the harness from another, so handing out a kit while the
     * committed sheet disagrees would put a wrong sha256 in their MANIFEST.json.
     */
    public static function assertManifestCurrent(string $repo): void
    {
        $path = $repo . '/' . self::MANIFEST_PATH;
        $committed = is_file($path) ? (string) file_get_contents($path) : null;
        $generated = self::render(self::manifest($repo));
        if ($committed === $generated) {
            return;
        }
        throw new RuntimeException(
            self::MANIFEST_PATH . " disagrees with the tree; run `php tools/adapter-kit.php --write`\n  "
            . implode("\n  ", self::drift((string) $committed, $generated))
        );
    }

    private static function write(string $path, string $bytes): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create ' . $dir);
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('cannot write ' . $path);
        }
    }

    /**
     * A first-difference report, in the shape tools/engine-gap-doc.php uses:
     * the line number and both sides, not a whole diff nobody reads.
     *
     * @return list<string>
     */
    public static function drift(string $committed, string $generated): array
    {
        $a = explode("\n", $committed);
        $b = explode("\n", $generated);
        $rows = [];
        $limit = max(count($a), count($b));
        for ($i = 0; $i < $limit; $i++) {
            if (($a[$i] ?? null) === ($b[$i] ?? null)) {
                continue;
            }
            $rows[] = 'line ' . ($i + 1) . ': committed ' . self::excerpt($a[$i] ?? '<absent>')
                . ' / generated ' . self::excerpt($b[$i] ?? '<absent>');
            if (count($rows) >= 5) {
                $rows[] = '(further differences suppressed)';
                break;
            }
        }

        return $rows === [] ? ['byte-identical text compared unequal (line endings?)'] : $rows;
    }

    private static function excerpt(string $line): string
    {
        $line = trim($line);

        return mb_strlen($line) > 90 ? mb_substr($line, 0, 87) . '...' : $line;
    }
}

/**
 * @param list<string> $argv
 */
function ak_main(string $repo, array $argv): int
{
    $mode = 'check';
    $slug = AdapterKit::DEFAULT_ADAPTER;
    $target = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check' || $argument === 'check') {
            $mode = 'check';
        } elseif ($argument === '--write' || $argument === 'write') {
            $mode = 'write';
        } elseif ($argument === '--print') {
            $mode = 'print';
        } elseif (str_starts_with($argument, '--assemble=')) {
            $mode = 'assemble';
            $target = substr($argument, strlen('--assemble='));
        } elseif (str_starts_with($argument, '--adapter=')) {
            $slug = substr($argument, strlen('--adapter='));
        } else {
            fwrite(STDERR, "usage: php tools/adapter-kit.php [--check|--write|--print|--assemble=DIR [--adapter=slug]]\n");

            return 2;
        }
    }

    try {
        if ($mode === 'assemble') {
            if ($target === null || $target === '') {
                throw new RuntimeException('--assemble= needs a directory');
            }
            $written = AdapterKit::assemble($repo, $target, $slug);
            fwrite(STDOUT, 'adapter-kit: assembled ' . count($written) . ' files into ' . $target . "\n");

            return 0;
        }
        $generated = AdapterKit::render(AdapterKit::manifest($repo, $slug));
        if ($mode === 'print') {
            fwrite(STDOUT, $generated);

            return 0;
        }
        $path = $repo . '/' . AdapterKit::MANIFEST_PATH;
        $committed = is_file($path) ? (string) file_get_contents($path) : null;
        if ($mode === 'check') {
            if ($committed === $generated) {
                fwrite(STDOUT, 'adapter-kit: ' . AdapterKit::MANIFEST_PATH . ' matches the tree ('
                    . count(AdapterKit::COPIED) . " live files packaged)\n");

                return 0;
            }
            fwrite(STDERR, 'tools/adapter-kit.php: ' . AdapterKit::MANIFEST_PATH . " disagrees with the tree\n");
            foreach (AdapterKit::drift((string) $committed, $generated) as $row) {
                fwrite(STDERR, "  $row\n");
            }
            fwrite(STDERR, "  remedy: php tools/adapter-kit.php --write\n");

            return 1;
        }
        if ($committed === $generated) {
            fwrite(STDOUT, 'adapter-kit: ' . AdapterKit::MANIFEST_PATH . " already current\n");

            return 0;
        }
        if (file_put_contents($path, $generated) === false) {
            throw new RuntimeException('cannot write ' . $path);
        }
        fwrite(STDOUT, 'adapter-kit: wrote ' . AdapterKit::MANIFEST_PATH . "\n");

        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'adapter-kit: ' . $e->getMessage() . "\n");

        return 1;
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    /** @var list<string> $akArgv */
    $akArgv = $_SERVER['argv'] ?? [];
    exit(ak_main(dirname(__DIR__), $akArgv));
}
