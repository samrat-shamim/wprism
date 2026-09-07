<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins tools/engine-gap-doc.php and the ledger it projects (WP-0.4).
 *
 * The ledger's whole claim is that engine-gap demand is COUNTABLE: two
 * candidates blocked on the same missing primitive must collapse into one row
 * of the open-demand ranking. That property rests entirely on the vocabulary
 * being closed, so the refusal cases below are not defensive trivia — each one
 * is a way the ledger would silently degrade back into the prose wishlist it
 * replaced:
 *
 *   - an out-of-vocabulary `primitive_required` lets the second candidate spell
 *     the same gap differently, and the ranking then counts two ones instead of
 *     one two.
 *   - a declared primitive nothing demands is a wish, not a measurement.
 *   - a lifecycle/layer disagreement means the ledger is describing a closed
 *     coordinate on work that did not ship, or treating remaining adapter work
 *     as though the generic platform facility were still missing.
 *   - a `closed_by` path that left the tree turns a closure into a story about
 *     code nobody can open.
 *   - a coordinate head no manifest declares is not a grammar coordinate at all.
 *
 * gap_validate()/gap_open_demand()/gap_render() are pure (the manifest section
 * list and the path-existence predicate are injected), so each case runs
 * against a MUTATED COPY of the real committed ledger rather than a synthetic
 * one — the mutation is the only difference between a passing and a failing
 * input, which is what makes the failure attributable. This mirrors
 * tests/Tooling/ApiSurfaceTest.php's rationale for pinning as_diff() directly
 * instead of only through a subprocess exit code.
 *
 * The two CLI-level cases run the tool out-of-process via proc_open, the same
 * way AffectedTest/ApiSurfaceTest run theirs: `--check` is the exact command
 * `make release-gate` runs, and a green assertion here means an agent finds the
 * drift in `composer check` rather than at the gate.
 */
final class EngineGapsTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /**
     * The tool's functions live in the global namespace (it is a plain CLI
     * script). Its bottom guard only runs gap_main() when SCRIPT_FILENAME
     * resolves to itself, never under phpunit, so requiring it here defines the
     * functions and regenerates nothing.
     */
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('gap_validate')) {
            require_once self::repoRoot() . '/tools/engine-gap-doc.php';
        }
    }

    /** @return array<string,mixed> */
    private static function ledger(): array
    {
        return gap_load(self::repoRoot() . '/tools/engine-gaps.json');
    }

    /** @return list<string> */
    private static function sections(): array
    {
        return gap_manifest_sections(gap_library(self::repoRoot()));
    }

    private static function exists(): callable
    {
        $repo = self::repoRoot();
        return static fn (string $path): bool => file_exists($repo . '/' . ltrim($path, '/'));
    }

    /**
     * @param array<string,mixed> $ledger
     */
    private function assertRefuses(array $ledger, string $needle): void
    {
        try {
            gap_validate($ledger, self::sections(), self::exists());
        } catch (RuntimeException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
            return;
        }
        self::fail("gap_validate() accepted a ledger it must refuse (expected to name: $needle)");
    }

    /**
     * @param list<string> $args
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $args): array
    {
        $repo = self::repoRoot();
        $cmd = [PHP_BINARY, $repo . '/tools/engine-gap-doc.php', ...$args];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
        self::assertIsResource($process, 'could not launch tools/engine-gap-doc.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testCommittedDocumentMatchesTheLedger(): void
    {
        $result = self::invoke(['--check']);
        self::assertSame(0, $result['status'], "engine-gap-doc --check is red:\n" . $result['stderr']);
        self::assertStringContainsString('agree', $result['stdout']);
    }

    public function testProjectionIsDeterministic(): void
    {
        $ledger = self::ledger();
        self::assertSame(gap_render($ledger), gap_render($ledger));
        self::assertSame(
            gap_render($ledger),
            (string) file_get_contents(self::repoRoot() . '/docs/guides/adapter-authoring-limitations.md')
        );
    }

    public function testCommittedLedgerValidates(): void
    {
        gap_validate(self::ledger(), self::sections(), self::exists());
        self::assertTrue(true, 'the committed ledger passes every cross-check');
    }

    /** Duplicate demand is one ranked primitive row with N unique candidates. */
    public function testDemandRankingCollapsesDuplicateDemandAndLeadsWithIt(): void
    {
        $ledger = self::ledger();
        $sourceCandidate = null;
        $sourceCoordinate = null;
        foreach ($ledger['candidates'] as $candidate) {
            foreach ($candidate['coordinates'] as $coordinate) {
                if (!gap_coordinate_closed($coordinate)) {
                    $sourceCandidate = (string) $candidate['candidate'];
                    $sourceCoordinate = $coordinate;
                    break 2;
                }
            }
        }
        self::assertNotNull($sourceCoordinate, 'the committed ledger has no open coordinate to aggregate');
        self::assertNotNull($sourceCandidate, 'the committed open coordinate has no candidate owner');
        $probe = $ledger['candidates'][0];
        $probe['candidate'] = 'Aggregation probe';
        $probe['blocked_adapters'] = ['aggregation-probe'];
        $probe['coordinates'] = [$sourceCoordinate, $sourceCoordinate];
        $ledger['candidates'][] = $probe;

        $demand = gap_open_demand($ledger);
        self::assertNotSame([], $demand);
        $counts = array_map(static fn (array $row): int => count($row['candidates']), $demand);
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts, 'the open-demand table is not ordered most-blocking first');

        $primitive = (string) $sourceCoordinate['primitive_required'];
        $aggregated = array_values(array_filter(
            $demand,
            static fn (array $row): bool => $row['primitive'] === $primitive
        ));
        self::assertCount(1, $aggregated, 'shared demand was split into more than one primitive row');
        // A committed primitive can already have multiple native consumers.
        // Adding WPForms' body-URL demand exposed the old two-owner assumption;
        // derive the exact owner set from coordinates, not from ranked output.
        $expectedOwners = [];
        foreach ($ledger['candidates'] as $candidate) {
            foreach ($candidate['coordinates'] as $coordinate) {
                if (!isset($coordinate['closed_by']) && $coordinate['primitive_required'] === $primitive) {
                    $expectedOwners[(string) $candidate['candidate']] = true;
                }
            }
        }
        $expectedCandidates = array_keys($expectedOwners);
        sort($expectedCandidates, SORT_STRING);
        self::assertSame(
            $expectedCandidates,
            $aggregated[0]['candidates'],
            'two candidates demanding one primitive were not collapsed into one ranked row'
        );
        foreach ($demand as $row) {
            self::assertSame(
                count($row['candidates']),
                count(array_unique($row['candidates'])),
                "primitive '{$row['primitive']}' counts the same candidate twice"
            );
        }
    }

    public function testCurrentDispositionRefusesAStalePromotionBlocker(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][0]['disposition'] = 'promotion_blocked';
        $ledger['candidates'][0]['blocked_adapters'] = ['code-snippets'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'code-snippets' is promotion_blocked");
        gap_assert_current_dispositions($ledger, gap_library(self::repoRoot()));
    }

    public function testRefusesAnOutOfVocabularyPrimitive(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][0]['coordinates'][0]['primitive_required'] = 'a_brand_new_way_of_saying_the_same_thing';
        $this->assertRefuses($ledger, 'the closed vocabulary does not declare');
    }

    public function testRefusesAPrimitiveNoCandidateDemands(): void
    {
        $ledger = self::ledger();
        $ledger['primitives']['some_primitive_nobody_asked_for'] = [
            'title' => 'a primitive nobody asked for',
            'definition' => 'declared, demanded by nothing',
            'status' => 'open',
        ];
        $this->assertRefuses($ledger, 'no candidate demands it');
    }

    public function testRefusesALifecycleDisagreementBetweenCoordinateAndPrimitive(): void
    {
        $ledger = self::ledger();
        foreach ($ledger['candidates'] as $i => $row) {
            if ($row['disposition'] === 'closed') {
                // The primitive that closed this coordinate is shipped; pointing
                // it at an open one claims a gap closed on work not done.
                $ledger['candidates'][$i]['coordinates'][0]['primitive_required'] = 'structured_leaf_text_codec';
                break;
            }
        }
        $this->assertRefuses($ledger, 'a closed coordinate demands a shipped primitive');
    }

    public function testOpenCoordinateOnShippedFacilityMustBeClassifiedAsAdapterWork(): void
    {
        $ledger = self::ledger();
        foreach ($ledger['candidates'] as $i => $row) {
            foreach ($row['coordinates'] as $j => $coordinate) {
                if (($coordinate['blocker_layer'] ?? null) === 'adapter') {
                    unset($ledger['candidates'][$i]['coordinates'][$j]['blocker_layer']);
                    $this->assertRefuses($ledger, 'mark blocker_layer adapter');
                    return;
                }
            }
        }
        self::fail('fixture has no adapter-layer blocker');
    }

    public function testClosedCoordinateCannotRetainAStaleBlockerLayer(): void
    {
        $ledger = self::ledger();
        foreach ($ledger['candidates'] as $i => $row) {
            foreach ($row['coordinates'] as $j => $coordinate) {
                if (isset($coordinate['closed_by'])) {
                    $ledger['candidates'][$i]['coordinates'][$j]['blocker_layer'] = 'adapter';
                    $this->assertRefuses($ledger, 'is closed and must not retain blocker_layer');
                    return;
                }
            }
        }
        self::fail('fixture has no closed coordinate');
    }

    public function testRefusesClosureEvidenceThatLeftTheTree(): void
    {
        $ledger = self::ledger();
        foreach ($ledger['candidates'] as $i => $row) {
            if ($row['disposition'] === 'closed') {
                $ledger['candidates'][$i]['coordinates'][0]['closed_by']
                    = ['agent/src/Policy/ThisFileMovedYearsAgo.php'];
                break;
            }
        }
        $this->assertRefuses($ledger, 'which is not in the tree');
    }

    /**
     * WP-6.1's split: `closed_by` belongs to the COORDINATE whose primitive
     * shipped. The old row-level spelling is refused by name rather than
     * ignored, because a ledger carrying both would have two answers to "is
     * this closed" and the projector reads only one of them.
     */
    public function testRefusesTheRetiredRowLevelClosedBy(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][0]['closed_by'] = ['agent/src/Policy/ManifestGrammar.php'];
        $this->assertRefuses($ledger, 'carries a row-level closed_by');
    }

    /**
     * A candidate's `disposition` is derived from its coordinates and merely
     * checked. Closing the last open coordinate of a still-blocked row without
     * moving the word is the drift this catches.
     */
    public function testRefusesADispositionThatDisagreesWithItsCoordinates(): void
    {
        $ledger = self::ledger();
        foreach ($ledger['candidates'] as $i => $row) {
            if ($row['disposition'] === 'closed') {
                // Every coordinate stays closed and every primitive stays
                // shipped, so the per-coordinate check above is satisfied and
                // the ROW's own word is the only thing wrong. That isolation is
                // the point: the two rules must both exist.
                $ledger['candidates'][$i]['disposition'] = 'promotion_blocked';
                break;
            }
        }
        $this->assertRefuses($ledger, "0 open coordinate(s); a row is 'closed'");
    }

    /**
     * The property that forced the split, asserted on the real data: a row can
     * hold a closed coordinate and an open one at the same time, and the open
     * ranking must count only the open one.
     */
    public function testAPartiallyClosedRowCountsOnlyItsOpenCoordinates(): void
    {
        $ledger = self::ledger();
        $partial = null;
        foreach ($ledger['candidates'] as $row) {
            $closed = 0;
            foreach ($row['coordinates'] as $coordinate) {
                $closed += gap_coordinate_closed($coordinate) ? 1 : 0;
            }
            if ($closed > 0 && $closed < count($row['coordinates'])) {
                $partial = $row;
                break;
            }
        }
        self::assertNotNull($partial, 'no partially closed candidate; WP-6.1 shipped two of them');

        $demanded = [];
        foreach (gap_open_demand($ledger) as $entry) {
            foreach ($entry['candidates'] as $candidate) {
                $demanded[$candidate][] = $entry['primitive'];
            }
        }
        foreach ($partial['coordinates'] as $coordinate) {
            $primitive = (string) $coordinate['primitive_required'];
            $listed = in_array($primitive, $demanded[(string) $partial['candidate']] ?? [], true);
            self::assertSame(
                !gap_coordinate_closed($coordinate),
                $listed,
                "the open-demand ranking is wrong about '$primitive' for {$partial['candidate']}"
            );
        }
    }

    public function testRefusesShippedPrimitiveEvidenceThatLeftTheTree(): void
    {
        $ledger = self::ledger();
        $ledger['primitives']['composite_ref_identity']['evidence'] = ['agent/src/Policy/Gone.php'];
        $this->assertRefuses($ledger, 'which is not in the tree');
    }

    public function testRefusesACoordinateNoManifestSectionHeads(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][0]['coordinates'][0]['coordinate'] = 'wishful_section.some_key';
        $this->assertRefuses($ledger, 'which no manifest declares as a section');
    }

    public function testRefusesADuplicateCandidateId(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][] = $ledger['candidates'][0];
        $this->assertRefuses($ledger, 'appears twice');
    }

    public function testRefusesAGapThatBlocksNothing(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][0]['blocked_adapters'] = [];
        $this->assertRefuses($ledger, 'names no blocked adapter');
    }

    public function testRefusesAnUnknownDisposition(): void
    {
        $ledger = self::ledger();
        $ledger['candidates'][0]['disposition'] = 'pending';
        $this->assertRefuses($ledger, 'expected one of');
    }

    /**
     * The preamble prints ONE probe date for every rejected candidate, so two
     * dates would make that projected sentence false about at least one row.
     */
    public function testRefusesRejectedCandidatesThatDisagreeOnTheProbeDate(): void
    {
        $ledger = self::ledger();
        $seen = 0;
        foreach ($ledger['candidates'] as $i => $row) {
            if ($row['disposition'] === 'rejected' && $seen++ === 1) {
                $ledger['candidates'][$i]['probed_on'] = '2019-01-01';
                break;
            }
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different dates');
        gap_render($ledger);
    }

    /**
     * sandbox/tests/offline/adapter/regress_ecosystem_adapter_batch.php asserts
     * these exact phrases appear in the projected document with str_contains.
     * They are pinned here too so a ledger edit that would break the offline
     * corpus fails in `composer check` — seconds — instead of in the gate.
     */
    public function testProjectionKeepsThePhrasesTheOfflineCorpusRequires(): void
    {
        $doc = gap_render(self::ledger());
        foreach ([
            'WPForms Lite 2.0.0.4 / 2.0.0.5',
            'Redirection 5.9.0',
            'Custom Post Type UI 1.19.3',
            'PHP serialization length prefixes',
            'type-preserving',
            'string-id attribute codec',
            'verified post-apply type-registration/process boundary',
        ] as $phrase) {
            self::assertStringContainsString($phrase, $doc);
        }
    }
}
