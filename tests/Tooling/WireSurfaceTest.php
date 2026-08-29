<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Pin tools/wire-surface.php — the checker behind docs/wire-surface.md.
 *
 * The register's whole value is that it cannot quietly stop describing the
 * shipped wire, and the risk it was written against is a document that reads
 * correctly at review time and has drifted by the time an external party holds
 * a signed artifact. A test that only asserted `--check` exits 0 would pin the
 * wrong half: a checker that read a hand-copied list would pass it forever.
 *
 * So four of the six cases here MUTATE A CONSTANT (or a key set, or a signing
 * call site) in a throwaway copy of the shipped tree and require the checker to
 * fail on it, naming what moved. That is the property the register depends on,
 * stated as a test rather than as a claim in a docblock:
 *
 *   - a moved signature domain fails the byte-compare, and the reported line
 *     carries the MUTATED value — proving the document is projected from the
 *     constant rather than compared against a copy of it;
 *   - a renamed member of the signed statement fails the same way, proving the
 *     closed key sets are read out of the `assertExactKeys()` call sites;
 *   - a new `sodium_crypto_sign_detached()` call site fails the completeness
 *     gate, so another signed surface cannot ship unregistered;
 *   - a THIRD expiry member appearing in AdapterCertification fails the gate
 *     that backs row R-14, which records the certification engine's expiry
 *     vocabulary as EXACTLY `not_after`/`not_before` (WP-4.8 turned that row
 *     from an absence into a bounded presence; the ratchet is the same one, and
 *     it is the one class of claim nothing else would contradict).
 *
 * The fixture is a real copy of `agent/`, `cli/`, `recovery/`, `docs/`,
 * `adapter-packages/`, and `platform/` (everything --root reads) built once for the class; each case restores
 * the file it edited. Mutating in place under the repo is not an option —
 * AGENTS.md rule 3 forbids scratch under shipped source roots, and pair.sh
 * refuses on an untracked file there.
 */
final class WireSurfaceTest extends TestCase
{
    private static ?string $fixture = null;

    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        $fixture = (string) tempnam(sys_get_temp_dir(), 'wprism-wire-surface');
        unlink($fixture);
        if (!mkdir($fixture, 0700) && !is_dir($fixture)) {
            self::fail("could not create the fixture root at $fixture");
        }
        foreach (['agent', 'cli', 'recovery', 'docs', 'adapter-packages', 'platform'] as $tree) {
            $status = 0;
            $output = [];
            exec(
                'cp -R ' . escapeshellarg(self::repoRoot() . '/' . $tree) . ' '
                . escapeshellarg($fixture . '/' . $tree) . ' 2>&1',
                $output,
                $status
            );
            self::assertSame(0, $status, "could not copy $tree into the fixture: " . implode("\n", $output));
        }
        self::$fixture = $fixture;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture !== null && is_dir(self::$fixture)) {
            exec('rm -rf ' . escapeshellarg(self::$fixture));
        }
        self::$fixture = null;
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function invoke(array $args): array
    {
        $repo = self::repoRoot();
        $cmd = [PHP_BINARY, $repo . '/tools/wire-surface.php', ...$args];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $repo);
        self::assertIsResource($process, 'could not launch tools/wire-surface.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Edit one file in the fixture, run `--check` against it, restore it.
     *
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function withMutation(string $relative, callable $edit): array
    {
        $path = (string) self::$fixture . '/' . $relative;
        $original = file_get_contents($path);
        self::assertIsString($original, "the fixture is missing $relative");
        try {
            $mutated = $edit($original);
            self::assertNotSame($original, $mutated, "the mutation of $relative changed nothing");
            file_put_contents($path, $mutated);

            return self::invoke(['--check', '--root=' . self::$fixture]);
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function testCheckAgreesWithTheCommittedRegister(): void
    {
        $result = self::invoke(['--check']);
        self::assertSame(
            0,
            $result['status'],
            "the committed register is stale; run `php tools/wire-surface.php generate`:\n"
                . $result['stdout'] . $result['stderr']
        );
        self::assertStringContainsString('docs/wire-surface.md agrees with the shipped constants', $result['stdout']);
    }

    /**
     * The untouched fixture must pass too. Without this the four mutation
     * cases below would prove nothing: a fixture that failed for its own
     * reasons would make every one of them pass vacuously.
     */
    public function testTheUnmutatedFixturePasses(): void
    {
        $result = self::invoke(['--check', '--root=' . self::$fixture]);
        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
    }

    /**
     * The mutation targets the CURRENT generation, which WP-4.7 moved to `/v2`.
     * A test that kept mutating `/v1` -> `/v2` would silently stop mutating
     * anything the day the shipped domain became `/v2` — which is why
     * withMutation() refuses a no-op edit rather than letting this pass
     * vacuously.
     */
    public function testAMovedSignatureDomainFailsTheCheck(): void
    {
        $result = self::withMutation(
            'agent/src/Adapter/AdapterCertification.php',
            static fn(string $source): string => str_replace(
                'wprism-site-adapter-certification-signature/v2',
                'wprism-site-adapter-certification-signature/v3',
                $source
            )
        );
        self::assertSame(1, $result['status'], 'a moved signature domain must fail the register check');
        self::assertStringContainsString('disagrees with the shipped wire at line', $result['stderr']);
        // The reported "shipped" line carries the MUTATED domain, which is the
        // whole point: the document is projected from the constant the
        // refusals consult, never compared against a second copy of it.
        self::assertStringContainsString('wprism-site-adapter-certification-signature/v3', $result['stderr']);
    }

    /**
     * The six-member v2 statement, not the five-member v1 set beside it:
     * `STATEMENT_V1_KEYS` exists only to RECOGNISE the previous generation and
     * no register row projects it, so mutating that one would change no line of
     * the document and prove nothing.
     */
    public function testARenamedStatementMemberFailsTheCheck(): void
    {
        $result = self::withMutation(
            'agent/src/Adapter/AdapterCertification.php',
            static fn(string $source): string => str_replace(
                "['adapter', 'authority', 'bundle', 'platform', 'ratification', 'version']",
                "['adapter', 'authority', 'bundle', 'platform', 'ratifications', 'version']",
                $source
            )
        );
        self::assertSame(1, $result['status'], 'a renamed signed-statement member must fail the register check');
        self::assertStringContainsString('ratifications', $result['stderr']);
    }

    public function testAnUnregisteredSigningSurfaceFailsTheCompletenessGate(): void
    {
        $path = (string) self::$fixture . '/cli/src/Contract/WireSurfaceProbeSigner.php';
        file_put_contents(
            $path,
            "<?php\n// Another signing surface, added without a register row.\n"
                . "function wprism_wire_surface_probe_sign(string \$m, string \$k): string {\n"
                . "    return sodium_crypto_sign_detached(\$m, \$k);\n}\n"
        );
        try {
            $result = self::invoke(['--check', '--root=' . self::$fixture]);
        } finally {
            unlink($path);
        }
        self::assertSame(1, $result['status'], 'a new signing surface must fail the register check');
        self::assertStringContainsString('every signing surface needs a register row', $result['stderr']);
    }

    public function testAThirdExpiryMemberInTheCertificationEngineFailsTheR14Gate(): void
    {
        $result = self::withMutation(
            'agent/src/Adapter/AdapterCertification.php',
            static fn(string $source): string => str_replace(
                'final class AdapterCertification {',
                "final class AdapterCertification {\n    private const EXPIRES_AT = 'expires_at';\n",
                $source
            )
        );
        self::assertSame(1, $result['status'], 'a third expiry member must fail the gate row R-14 stands on');
        self::assertStringContainsString(
            'expiry vocabulary is now {expires_at, not_after, not_before}',
            $result['stderr']
        );
    }

    /**
     * The other half of the same ratchet: R-14 also claims BOTH expiry-bearing
     * roots read one clock, and a second time source would make that sentence
     * wrong with nothing else contradicting it.
     */
    public function testASecondClockSourceInTheCertificationEngineFailsTheR14Gate(): void
    {
        $result = self::withMutation(
            'agent/src/Adapter/AdapterCertification.php',
            static fn(string $source): string => str_replace(
                'return $now ?? time();',
                'return $now ?? (int) hrtime(true);',
                $source
            )
        );
        self::assertSame(1, $result['status'], 'a second clock source must fail the gate row R-14 stands on');
        self::assertStringContainsString('no longer judges its window against', $result['stderr']);
    }

    /**
     * Gate 6, and it is the reason `platform/` joined the fixture: the shipped
     * platform trust root has exactly two legal states, and a populated one
     * that does not verify must never reach a site. Register row R-08 rests on
     * that file being empty, so this is that gate under an argument rather than
     * a tidy-up.
     */
    public function testAPopulatedUnsignedPlatformTrustRootFailsTheShippedAuthoritiesGate(): void
    {
        $result = self::withMutation(
            'platform/adapter-library/capabilities/adapter-authorities.json',
            static fn(string $source): string => json_encode([
                'format' => 'wprism-adapter-authorities/v1',
                'keys' => ['acme-000000000000' => [
                    'adapter_names' => ['acme-forms'],
                    'algorithm' => 'ed25519',
                    'public_key' => base64_encode(str_repeat("\x01", 32)),
                    'scope' => 'site_adapter_certification',
                    'status' => 'trusted',
                    'trust_tiers' => ['declarative_manifest'],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        self::assertSame(1, $result['status'], 'a populated v1 platform trust root must fail the register check');
        self::assertStringContainsString('neither the empty wprism-adapter-authorities/v1 registry', $result['stderr']);
    }

    /**
     * Gate 7 (WP-4.10, spec § v3.9), membership half. The grandfather list is
     * CLOSED, which means nothing about it unless a library the list does not
     * match is refused: a seventeenth shipped adapter must not be able to
     * arrive by dropping a package in `adapter-packages/`, because each unprefixed name
     * admitted is one more identity handed to the shipped library permanently.
     */
    public function testASeventeenthShippedAdapterNameFailsTheGrandfatherListGate(): void
    {
        $package = (string) self::$fixture . '/adapter-packages/zeta/package';
        self::assertTrue(mkdir($package, 0700, true));
        $source = (string) file_get_contents(
            (string) self::$fixture . '/adapter-packages/wprism-agency-cpt/package/manifest.json'
        );
        $decoded = json_decode($source, true);
        self::assertIsArray($decoded, 'the fixture manifest did not decode');
        $decoded['name'] = 'zeta';
        file_put_contents($package . '/manifest.json', json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        copy(
            (string) self::$fixture . '/adapter-packages/wprism-agency-cpt/package/disposition.json',
            $package . '/disposition.json'
        );
        try {
            $result = self::invoke(['--check', '--root=' . self::$fixture]);
        } finally {
            unlink($package . '/manifest.json');
            unlink($package . '/disposition.json');
            rmdir($package);
            rmdir(dirname($package));
        }
        self::assertSame(1, $result['status'], 'an unlisted shipped adapter name must fail the register check');
        self::assertStringContainsString('unlisted [zeta]', $result['stderr']);
        self::assertStringContainsString('the list is CLOSED (row R-27)', $result['stderr']);
    }

    /**
     * Gate 7, location half. The list is agent code because putting a
     * platform-global admission rule inside one adapter package would make
     * engine policy adapter-owned identity. The realistic way that regresses is a
     * hoist plus a loader left behind, which is exactly what this mutates.
     */
    public function testHoistingTheGrandfatherListOutOfAgentSrcFailsTheGate(): void
    {
        $fixture = (string) self::$fixture;
        $relative = 'agent/src/Adapter/IdentityNamespaces.php';
        $hoisted = $fixture . '/platform/IdentityNamespaces.php';
        $original = (string) file_get_contents($fixture . '/' . $relative);
        file_put_contents($hoisted, $original);
        file_put_contents(
            $fixture . '/' . $relative,
            "<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/../../../platform/IdentityNamespaces.php';\n"
        );
        try {
            $result = self::invoke(['--check', '--root=' . $fixture]);
        } finally {
            file_put_contents($fixture . '/' . $relative, $original);
            unlink($hoisted);
        }
        self::assertSame(1, $result['status'], 'a list declared outside agent/src must fail the register check');
        self::assertStringContainsString('outside agent/src', $result['stderr']);
        self::assertStringContainsString('row R-27', $result['stderr']);
    }

    /**
     * The wiring itself, because a checker nothing runs is a checker that has
     * already failed: `make release-gate` is where this one earns its keep.
     */
    public function testTheReleaseGateRunsTheChecker(): void
    {
        $makefile = (string) file_get_contents(self::repoRoot() . '/Makefile');
        $gate = strstr($makefile, "release-gate:\n");
        self::assertIsString($gate, 'the Makefile has no release-gate target');
        $recipe = substr($gate, 0, (int) strpos($gate, "\n\n"));
        self::assertStringContainsString('php tools/wire-surface.php --check', $recipe);
    }
}
