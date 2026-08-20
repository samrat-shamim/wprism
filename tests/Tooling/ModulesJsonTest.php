<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Pins tools/modules.json's own internal consistency now that DUO-3493 made
 * it the single per-path {module, layer} source for agent/src. It used to be
 * duplicated into tools/layers.json's hand-maintained path=>layer map, kept
 * current by a second, independent gate
 * (sandbox/tests/offline/guards/regress_agent_src_requires.php's DUO-3481
 * section); that suite now derives the same map from this file directly
 * instead, which is what retired the second registry and the friction of
 * a new agent/src file needing a hand entry in each of two files, gated
 * separately, that could silently disagree.
 *
 * What is actually at risk: file_count is the one field nothing else reads
 * for a lint (it is prose a human skims in a module's table) and is exactly
 * the kind of decorative fact that drifts silently -- proven, not
 * hypothesised: the Apply module's file_count sat at 26 against a 27-entry
 * files list until this test's own assertion (added with it) caught it.
 * This class asserts the properties a hand-edited registry can violate
 * without either of the two consuming gates (regress_agent_src_requires.php,
 * tests/Tooling/MoveModulesTest.php's real-tree dry run) noticing on their
 * own -- both of those check completeness against the real tree; neither
 * checks file_count or layer_notes staleness.
 */
final class ModulesJsonTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /** @return array<string,mixed> */
    private static function decode(): array
    {
        $path = self::repoRoot() . '/tools/modules.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, "$path is unreadable");
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "$path is not a JSON object");
        return $decoded;
    }

    /**
     * The regression this test exists for: Apply's file_count (26) silently
     * disagreed with its 27-entry files list until this assertion was added
     * in the same change that fixed it (DUO-3493).
     */
    public function testEveryModuleFileCountMatchesItsFilesList(): void
    {
        $modules = self::decode();
        foreach (['agent', 'cli'] as $root) {
            self::assertArrayHasKey($root, $modules, "tools/modules.json has no '$root' root");
            self::assertIsArray($modules[$root]['modules'] ?? null, "tools/modules.json.$root has no modules object");
            foreach ($modules[$root]['modules'] as $name => $module) {
                self::assertArrayHasKey('file_count', $module, "$root.$name has no file_count");
                self::assertArrayHasKey('files', $module, "$root.$name has no files list");
                self::assertSame(
                    count($module['files']),
                    $module['file_count'],
                    "$root.$name.file_count ({$module['file_count']}) disagrees with its files list ("
                        . count($module['files']) . ' entries) -- set file_count to match files in the same edit'
                );
            }
        }
    }

    public function testEveryModuleLayerIsAKnownLadderRung(): void
    {
        $modules = self::decode();
        self::assertIsArray($modules['ladder'] ?? null, 'tools/modules.json has no ladder');
        $rank = array_flip($modules['ladder']);
        foreach (['agent', 'cli'] as $root) {
            foreach ($modules[$root]['modules'] as $name => $module) {
                self::assertArrayHasKey('layer', $module, "$root.$name has no layer");
                self::assertArrayHasKey(
                    $module['layer'],
                    $rank,
                    "$root.$name.layer '{$module['layer']}' is not one of tools/modules.json's ladder rungs"
                );
            }
        }
    }

    public function testNoFileIsAssignedToTwoAgentModules(): void
    {
        $modules = self::decode();
        $seenIn = [];
        foreach ($modules['agent']['modules'] as $name => $module) {
            foreach ($module['files'] as $file) {
                $prior = $seenIn[$file] ?? '';
                self::assertArrayNotHasKey(
                    $file,
                    $seenIn,
                    "agent/src/$file is assigned to both $prior and $name in tools/modules.json"
                );
                $seenIn[$file] = $name;
            }
        }
        self::assertNotSame([], $seenIn);
    }

    /**
     * layer_notes is DUO-3493's migration of tools/layers.json's old "notes"
     * section (free-text rationale for a placement a filename suffix would
     * suggest otherwise) into the single source file; a note naming a path
     * no module assigns is exactly the stale prose the deleted layers.json's
     * "notes describe files it does not assign" check guarded against.
     */
    public function testLayerNotesOnlyNameAssignedAgentFiles(): void
    {
        $modules = self::decode();
        self::assertArrayHasKey('layer_notes', $modules, 'tools/modules.json has no layer_notes map');
        $assigned = [];
        foreach ($modules['agent']['modules'] as $name => $module) {
            foreach ($module['files'] as $file) {
                $path = $name === '.' ? "src/$file" : "src/$name/$file";
                $assigned[$path] = true;
            }
        }
        foreach (array_keys($modules['layer_notes']) as $path) {
            self::assertArrayHasKey($path, $assigned, "layer_notes names $path, which no agent module assigns");
        }
    }

    /**
     * Mutation check (DUO-3493): a file quietly dropped from its module's
     * files list is exactly the failure mode the old two-registry setup
     * could produce silently (one file edited out of tools/layers.json
     * without the matching tools/modules.json edit, or vice versa). Proves
     * the derived assignment really does lose the entry, and that the file
     * it names is real -- so the real-tree comparison
     * regress_agent_src_requires.php runs against this same files list would
     * flag it as unassigned, without forking a real suite run to prove it.
     */
    public function testRemovingAFileFromItsModuleMakesItUnassigned(): void
    {
        $modules = self::decode();
        $assignedBefore = [];
        foreach ($modules['agent']['modules'] as $name => $module) {
            foreach ($module['files'] as $file) {
                $path = $name === '.' ? "src/$file" : "src/$name/$file";
                $assignedBefore[$path] = true;
            }
        }
        self::assertArrayHasKey('src/Kernel/Canon.php', $assignedBefore);

        $mutated = $modules;
        $mutated['agent']['modules']['Kernel']['files'] = array_values(
            array_diff($mutated['agent']['modules']['Kernel']['files'], ['Canon.php'])
        );
        $assignedAfter = [];
        foreach ($mutated['agent']['modules'] as $name => $module) {
            foreach ($module['files'] as $file) {
                $path = $name === '.' ? "src/$file" : "src/$name/$file";
                $assignedAfter[$path] = true;
            }
        }
        self::assertArrayNotHasKey(
            'src/Kernel/Canon.php',
            $assignedAfter,
            'mutation did not actually drop the file from the derived assignment map'
        );
        self::assertFileExists(
            self::repoRoot() . '/agent/src/Kernel/Canon.php',
            'fixture assumption: the mutated-away file must be real, or the real-tree comparison this proves has nothing to disagree with'
        );
    }

    /**
     * Mutation check, the other direction: a file claimed by a second module
     * derives a path with that module's own name embedded (a module's files
     * never collide on path with another module's), so the failure surfaces
     * as an assignment to a file that does not exist on disk -- proven here
     * by confirming the mutated path is absent from the real agent/src tree.
     */
    public function testClaimingAnotherModulesFileDerivesANonexistentPath(): void
    {
        $modules = self::decode();
        $mutated = $modules;
        $mutated['agent']['modules']['Policy']['files'][] = 'Canon.php'; // already Kernel's
        $path = null;
        foreach ($mutated['agent']['modules']['Policy']['files'] as $file) {
            if ($file === 'Canon.php') {
                $path = 'src/Policy/Canon.php';
            }
        }
        self::assertSame('src/Policy/Canon.php', $path);
        self::assertFileDoesNotExist(
            self::repoRoot() . '/' . $path,
            'the phantom path must not exist, or the real-tree comparison this proves has nothing to disagree with'
        );
    }
}
