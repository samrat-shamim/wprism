<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

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
 *     gate, so a fourth signed surface cannot ship unregistered;
 *   - a THIRD expiry member appearing in AdapterCertification fails the gate
 *     that backs row R-14, which records the certification engine's expiry
 *     vocabulary as EXACTLY `not_after`/`not_before` (WP-4.8 turned that row
 *     from an absence into a bounded presence; the ratchet is the same one, and
 *     it is the one class of claim nothing else would contradict).
 *
 * The fixture is a real copy of `agent/`, `cli/`, `recovery/`, `docs/` and
 * `manifests/` (everything --root reads — the last one because gate 6 checks
 * the shipped platform trust root) built once for the class; each case restores
 * the file it edited. Mutating in place under the repo is not an option —
 * AGENTS.md rule 3 forbids scratch under agent/ and manifests/, and pair.sh
 * refuses on an untracked file there.
 */
final class WireSurfaceTest extends TestCase
{
    private static ?string $fixture = null;

    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        $fixture = (string) tempnam(sys_get_temp_dir(), 'duo-wire-surface');
        unlink($fixture);
        if (!mkdir($fixture, 0700) && !is_dir($fixture)) {
            self::fail("could not create the fixture root at $fixture");
        }
        foreach (['agent', 'cli', 'recovery', 'docs', 'manifests'] as $tree) {
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

    public function testAMovedSignatureDomainFailsTheCheck(): void
    {
        $result = self::withMutation(
            'agent/src/Adapter/AdapterCertification.php',
            static fn(string $source): string => str_replace(
                'duo-site-adapter-certification-signature/v1',
                'duo-site-adapter-certification-signature/v2',
                $source
            )
        );
        self::assertSame(1, $result['status'], 'a moved signature domain must fail the register check');
        self::assertStringContainsString('disagrees with the shipped wire at line', $result['stderr']);
        // The reported "shipped" line carries the MUTATED domain, which is the
        // whole point: the document is projected from the constant the
        // refusals consult, never compared against a second copy of it.
        self::assertStringContainsString('duo-site-adapter-certification-signature/v2', $result['stderr']);
    }

    public function testARenamedStatementMemberFailsTheCheck(): void
    {
        $result = self::withMutation(
            'agent/src/Adapter/AdapterCertification.php',
            static fn(string $source): string => str_replace(
                "['adapter', 'authority', 'bundle', 'platform', 'ratification']",
                "['adapter', 'authority', 'bundle', 'platform', 'ratifications']",
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
            "<?php\n// A fourth signing surface, added without a register row.\n"
                . "function duo_wire_surface_probe_sign(string \$m, string \$k): string {\n"
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
     * Gate 6, and it is the reason `manifests/` joined the fixture: the shipped
     * platform trust root has exactly two legal states, and a populated one
     * that does not verify must never reach a site. Register row R-08 rests on
     * that file being empty, so this is that gate under an argument rather than
     * a tidy-up.
     */
    public function testAPopulatedUnsignedPlatformTrustRootFailsTheShippedAuthoritiesGate(): void
    {
        $result = self::withMutation(
            'manifests/capabilities/adapter-authorities.json',
            static fn(string $source): string => json_encode([
                'format' => 'duo-adapter-authorities/v1',
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
        self::assertStringContainsString('neither the empty duo-adapter-authorities/v1 registry', $result['stderr']);
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
