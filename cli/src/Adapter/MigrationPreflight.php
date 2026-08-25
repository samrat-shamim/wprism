<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\ArtifactPolicyIdentity;
use Duo\Canon;
use Duo\CommandRefusalException;
use Duo\CompiledRepository;
use Duo\CompiledArtifactReader;
use Duo\ManifestDispositions;
use Duo\PinResolver;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\ScopeContract;
use Duo\StalePlatformSiteAdapterCertificate;
use Duo\SupersededSiteAdapterCertificate;
use Duo\SupersededWireSiteAdapterCertificate;

/**
 * `duo adapter doctor --migration` — the blast radius of an agent bump, on one
 * site, before the bump.
 *
 * WHAT QUESTION THIS ANSWERS
 * --------------------------
 * WP-1.4's flag-day rehearsal MEASURED what an agent transition moves across a
 * nine-site estate. Two of its findings are the ones an operator cannot guess:
 *
 *   (a) `artifact_hash` moves for EVERY site, including a site that pins
 *       nothing but shipped adapters under full digest neutrality, because
 *       every resolved adapter row carries its capability claim and that claim
 *       embeds the platform boundary — `resolved_adapters[*].capability
 *       .platform.agent_version` is literally inside the compiled document
 *       (`agent/src/Policy/ArtifactPolicyIdentity.php:134-159` builds the row;
 *       `ManifestDispositions::claim_from_disposition()` puts `platform` in
 *       it). So everything that PINS artifact_hash — a scope contract's
 *       `source.artifact_hash`, and the scoped mutation authorities and
 *       rollback claims derived from it — needs re-projection on the flag day.
 *   (b) A site holding a CERTIFIED SITE ADAPTER additionally moves its own
 *       `manifest_hash` and `revision_hash`, because the withdrawal changes the
 *       certificate-derived digest folded into `manifest_rows()`. That
 *       population is exactly the certificate-holding population, not the
 *       fleet, and its held compiled artifact refuses with
 *       `compiled_artifact_manifest_mismatch` until it is recompiled.
 *
 * A rehearsal proves those on a synthetic estate. This verb answers them for
 * ONE REAL SITE, which is the thing a rollout needs: enumerate the blast radius
 * per site, before the bump, so the bump is a scheduled list of remedies rather
 * than a discovery.
 *
 * WHERE THE ANSWERS COME FROM — CALLED, NEVER RESTATED
 * ---------------------------------------------------
 * Every verdict below is the return value or the thrown signal of a SHIPPED
 * gate. Nothing here re-implements a comparison the engine already owns,
 * because a preflight that restated the rules would drift from them and the
 * drift would surface as a false green — the one failure that turns a
 * controlled bump into an incident.
 *
 *   certificates   `AdapterCertification::verifyFile()` (:236). Its platform
 *                  arm is `currentPlatform()` (:1643), which raises the typed
 *                  `StalePlatformSiteAdapterCertificate` when the certificate's
 *                  signed `statement.platform` disagrees with the boundary this
 *                  agent publishes (:1123-1128). That THROW is the answer to
 *                  "does this certificate's platform_sha256 match the target".
 *   pins           `PinResolver::normalize_manifest_pins()` (:61) for the pin
 *                  SHAPE, and `ArtifactPolicyIdentity::resolved_adapters()`
 *                  (:134) for the digest each pin would have to carry after the
 *                  bump — the same call `duo adapter certify`'s `pinObject()`
 *                  (AdapterCertify.php:957-980) and `wp duo manifest-pin` make.
 *   identity       `RepositoryCompiler::compile()` for the target's four
 *                  identity values and `CompiledRepository::from_array()` for
 *                  the held artifact's, so held-vs-target is two gate outputs
 *                  compared rather than one value re-derived here.
 *   scope contract `ScopeContract::assert_associated()` (:243), the gate that
 *                  binds `source.artifact_hash`.
 *   contracts      `ContractAttestation::verify()` for the attestation (it
 *                  refuses `contract_attestation_platform_moved`), and
 *                  `ContractProjection::generate()` for the reviewed-registry
 *                  observation — `registry_sha256` addresses the WHOLE reviewed
 *                  document (`ManifestDispositions::sha256()`), and
 *                  `ContractProjection.php:186-215` is the definition of what
 *                  moving it means.
 *
 * WHAT "THE TARGET" IS, AND WHY THERE IS NO --target FLAG
 * ------------------------------------------------------
 * A rehearsal state is the pair (`agent/duo.php`'s two `define()`s, the
 * manifest library) — AGENTS.md rule 8, and `spec_migration_estate.php`'s
 * header states why a PHP process can hold exactly one such pair. So the TARGET
 * agent state is the agent state THIS PROCESS RUNS AT: run the preflight from
 * the release you are about to ship, against a site repository you have
 * checked out. A `--target=<other-library>` flag would be a lie in the two
 * directions that matter — `AdapterCertification::currentPlatform()` and
 * `ManifestDispositions::platform_boundary()` both refuse a library whose
 * platform document disagrees with the loaded agent ("agent capability platform
 * boundary disagrees with the loaded agent", "platform version disagrees with
 * the loaded agent"), so a mixed bundle could not reach a single certificate
 * gate. `DUO_MANIFESTS_DIR` already selects the library for a process that IS
 * the matching agent, which is the only combination any gate here accepts.
 *
 * WHAT THE SITE MUST HAND OVER, AND WHAT IS SAID WHEN IT DOES NOT
 * --------------------------------------------------------------
 * The repository answers the certificate and pin questions on its own. The
 * IDENTITY questions are held-versus-target comparisons, and the held half
 * lives with the deployed site, not in git: `--artifact`, `--scope-contract`
 * and `--snapshot` name those documents explicitly. Each is optional and every
 * unanswered question appears as a `deferred` row naming the flag that answers
 * it — never as silence, and never folded into a pass.
 *
 * Finding (a) survives a missing `--artifact` anyway, on a weaker but real
 * basis: a certificate's own signed `statement.platform.agent_version` and a
 * contract attestation's `platform_sha256` each record the agent state the site
 * was last bound to, so either one disagreeing with the target proves the site
 * is on a different agent and therefore that its artifact_hash moves. Each
 * movement row carries the `basis` that produced it.
 *
 * THE REFUSE-TO-CLASSIFY POSTURE
 * ------------------------------
 * `CompiledArtifactReader::artifact_guidance()` (:96-136) is a `match()` over
 * reason codes with NO default: a gate whose operator answer nobody reviewed
 * throws `\UnhandledMatchError` rather than shipping without one. The same
 * discipline, expressed for a command that must REPORT rather than throw:
 * `CERTIFICATE_SIGNALS` below classifies only the outcomes reviewed there,
 * anything else becomes an `unclassified` row carrying the engine's own
 * sentence, `movementGuidance()` is itself a defaultless `match()` so a movement
 * class cannot ship without an operator answer, and one `unclassified` row
 * makes a green verdict impossible (`status` can never be `ok`, and the exit
 * code is 1). Silence can never read as clean, which is the whole reason this
 * verb is worth having.
 */
final class MigrationPreflight {
    public const FORMAT = 'duo-migration-preflight/v1';

    /** The sub-verb `--migration` belongs to; `duo adapter doctor` is the door. */
    public const VERB = 'doctor';

    /**
     * The reviewed certificate outcomes, keyed by the exception class the
     * certificate gate raises. A class absent from this map is NOT given a
     * default — it becomes an `unclassified` row and blocks the green verdict.
     *
     * TWO of the three are migration movements, and the second one became one
     * when WP-4.7 published a second certificate wire generation
     * (spec/repo-format.md § v3.6). `superseded_adapter_bytes` still is not: it
     * describes a certificate ALREADY withdrawn today because an operator edited
     * the adapter, for a reason the bump neither causes nor fixes, and reporting
     * it would inflate the blast radius with work that is not the flag day's.
     *
     * `superseded_wire_format` used to sit in that same sentence and no longer
     * can. Every certificate minted before WP-4.7 carries the v1 statement
     * generation, and the target agent refuses exactly those BY GENERATION — so
     * for a site crossing that release the wire refusal IS the withdrawal the
     * bump causes, and it moves the same four identities `stale_platform` moves
     * (see MOVING_CERTIFICATE_SIGNALS). Leaving it silent would have made the
     * one verb whose job is predicting the blast radius under-report the largest
     * release in the program.
     */
    private const CERTIFICATE_SIGNALS = [
        StalePlatformSiteAdapterCertificate::class => 'stale_platform',
        SupersededSiteAdapterCertificate::class => 'superseded_adapter_bytes',
        SupersededWireSiteAdapterCertificate::class => 'superseded_wire_format',
    ];

    /**
     * The signals above that the BUMP causes, and that therefore predict
     * movement. A subset of CERTIFICATE_SIGNALS rather than a second list of
     * classes, so a signal can never be predicted-as-moving without first being
     * reviewed as classified at all.
     */
    private const MOVING_CERTIFICATE_SIGNALS = ['stale_platform', 'superseded_wire_format'];

    /**
     * @param list<string> $args everything `duo adapter` was given, including
     *        the `doctor` sub-verb — this parser owns the whole tail so
     *        AdapterCatalog's own flag loop keeps its exact refusals.
     * @return int 0 nothing moves and everything classified, 1 a finding, 2 usage/IO
     */
    public static function run(array $args): int {
        $verb = null;
        $repoArg = null;
        $held = ['artifact' => null, 'scope-contract' => null, 'snapshot' => null];
        $json = false;

        // A repeated flag is refused rather than last-wins, exactly as
        // AdapterCatalog::run() refuses it (:234-242): a second --artifact
        // silently replacing the first would preflight a document the operator
        // did not name and report it as though they had.
        $seen = [];
        foreach ($args as $arg) {
            $flag = str_starts_with($arg, '--') ? explode('=', $arg, 2)[0] : null;
            if ($flag !== null) {
                if (isset($seen[$flag])) {
                    return self::fail("duplicate flag '$flag'");
                }
                $seen[$flag] = true;
            }
            if ($arg === '--migration') {
                continue;
            }
            if ($arg === '--format=json') {
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--repo=')) {
                $repoArg = trim(substr($arg, strlen('--repo=')));
                continue;
            }
            $matched = false;
            foreach (array_keys($held) as $name) {
                if (str_starts_with($arg, "--$name=")) {
                    $held[$name] = trim(substr($arg, strlen("--$name=")));
                    $matched = true;
                }
            }
            if ($matched) {
                continue;
            }
            if (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            }
            if ($verb === null) {
                $verb = $arg;
                continue;
            }
            return self::fail("unexpected argument '$arg'");
        }

        if ($verb !== self::VERB) {
            return self::fail(
                '--migration is a mode of `' . self::VERB . '`: duo adapter ' . self::VERB
                . ' --migration --repo=<site-repo>'
            );
        }
        // A preflight is a statement about ONE site. Without --repo there is no
        // certificate, no pin and no contract to classify, and reporting the
        // shipped library's own health under a migration heading would be the
        // false green this verb exists to prevent.
        if ($repoArg === null || $repoArg === '') {
            return self::fail(
                '--migration needs the site it is about: --repo=<site-repo> (the directory holding site.duo.json)'
            );
        }
        $repo = is_dir($repoArg) ? realpath($repoArg) : false;
        if ($repo === false) {
            return self::fail("--repo '$repoArg' is not a directory");
        }
        if (!is_file($repo . '/site.duo.json')) {
            return self::fail(
                "--repo '$repo' has no site.duo.json — --migration takes the duo SITE REPO (the directory "
                . 'holding site.duo.json), whose pins, adapters/ source and contract this preflight classifies'
            );
        }
        foreach ($held as $name => $path) {
            if ($path === null) {
                continue;
            }
            $resolved = is_file($path) ? realpath($path) : false;
            if ($resolved === false) {
                return self::fail("--$name '$path' is not a file");
            }
            // Absolutised here rather than left as given:
            // `MediaPayloadAuthority::readArtifactDocument()` refuses a relative
            // artifact path outright, so a relative --artifact would read fine
            // through `CompiledRepository::from_array()` and then come back
            // `compiled_artifact_invalid` from the reader gate on the very next
            // line — one document, two answers, for a reason that is about the
            // path and not about the migration.
            $held[$name] = $resolved;
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        $manifestDir = Policy::manifests_dir();
        if (!is_dir($manifestDir)) {
            return self::fail("the agent manifest library '$manifestDir' is not a directory");
        }

        try {
            $report = self::report($repo, $manifestDir, $held);
        } catch (\Throwable $t) {
            // Reaching here means an input this command was given is
            // unreadable — an IO fault about the preflight's own arguments,
            // not a verdict about anybody's site. Every verdict path below
            // catches its own gate.
            return self::fail($t->getMessage());
        }

        if ($json) {
            echo self::encode($report) . "\n";
        } else {
            self::render($report);
        }
        return $report['status'] === 'ok' ? 0 : 1;
    }

    /**
     * The whole document. Built in the order an operator reads it: what the
     * target IS, then what the site holds against it, then the movement list
     * those two produce.
     *
     * @param array<string,?string> $held
     * @return array<string,mixed>
     */
    private static function report(string $repo, string $manifestDir, array $held): array {
        $state = [
            'movements' => [],
            'unclassified' => [],
            'deferred' => [],
        ];

        $report = [
            'format' => self::FORMAT,
            'spec_version' => DUO_SPEC_VERSION,
            'repo' => $repo,
            // The target platform digest comes from
            // ContractAttestation::currentPlatformDigest() (:469-493) rather
            // than from a hash written here: that is the number a signed
            // attestation binds and re-observes, so a preflight computing its
            // own would be a second definition of the value the gate compares.
            'target' => [
                'agent_version' => DUO_AGENT_VERSION,
                'spec_version' => DUO_SPEC_VERSION,
                'manifests_dir' => $manifestDir,
                'platform_sha256' => ContractAttestation::currentPlatformDigest($manifestDir),
                'registry_sha256' => ManifestDispositions::load($manifestDir)->sha256(),
            ],
        ];

        // Pins are read from the raw site.duo.json through the engine's own
        // normalizer BEFORE Policy::load() runs, and deliberately so: a
        // repository whose load refuses is exactly the repository an operator
        // most needs a pin verdict for, and AdapterCatalog's header states that
        // constraint for this whole command family. A pin shape the normalizer
        // refuses is an unclassified shape, not an absent one.
        [$pins, $pinShapeRefusal] = self::declaredPins($repo);
        if ($pinShapeRefusal !== null) {
            $state['unclassified'][] = [
                'subject' => 'site.duo.json manifests',
                'what' => 'pin shape',
                'detail' => $pinShapeRefusal,
                'why' => 'this preflight cannot say whether a pin it cannot parse would move; the engine\'s own '
                    . 'normalizer (PinResolver::normalize_manifest_pins) refused this list',
            ];
        }

        $policy = null;
        $compiled = null;
        try {
            $policy = Policy::load($repo);
            $report['site'] = ['load' => 'ok', 'refusal' => null];
        } catch (\Throwable $t) {
            $report['site'] = ['load' => 'refused', 'refusal' => $t->getMessage()];
        }
        if ($policy !== null) {
            try {
                $compiled = RepositoryCompiler::compile($repo, $policy);
            } catch (\Throwable $t) {
                $report['site']['compile'] = 'refused';
                $report['site']['compile_refusal'] = $t->getMessage();
            }
        }

        $resolved = [];
        if ($policy !== null) {
            try {
                $resolved = ArtifactPolicyIdentity::resolved_adapters($policy);
            } catch (\Throwable $t) {
                $report['site']['resolve_refusal'] = $t->getMessage();
            }
        }
        $targetDigests = [];
        $targetClaimAgent = [];
        foreach ($resolved as $row) {
            $name = (string) $row['name'];
            $targetDigests[$name] = (string) $row['digest'];
            // The value finding (a) is about, carried verbatim rather than
            // summarized: a claim withdrawn by the bump has no platform block
            // at all, which is itself the movement.
            $targetClaimAgent[$name] = is_array($row['capability'] ?? null)
                ? ($row['capability']['platform']['agent_version'] ?? null)
                : null;
        }

        $site = basename($repo);
        $certificates = self::certificates($repo, $manifestDir, $site, $state);
        $adapters = self::adapters($resolved, $targetClaimAgent);
        $identity = self::identity($site, $compiled, $held['artifact'], $policy, $adapters, $state);
        $contracts = self::contracts($repo, $manifestDir, (string) $report['target']['registry_sha256'], $state);

        $report['certificates'] = $certificates;
        $report['pins'] = self::pins($pins, $targetDigests, $policy !== null, $state);
        $report['adapters'] = $adapters;
        $report['identity'] = $identity['values'];
        $report['held_artifact'] = $identity['held_artifact'];
        $report['scope_contracts'] = self::scopeContracts($held['scope-contract'], $compiled, $policy, $state);
        $report['snapshot'] = self::snapshot($held['snapshot'], $state);
        $report['contracts'] = $contracts;

        // Finding (a), on the weaker bases, once every source that can carry it
        // has been read. Deduplicated by id: a site whose certificate AND held
        // artifact both prove the agent moved has one artifact_hash, not two.
        self::artifactReprojection(
            $site,
            (string) $report['target']['agent_version'],
            $certificates,
            $contracts,
            $state
        );

        $movements = self::sortRows($state['movements'], 'id');
        $unclassified = self::sortRows($state['unclassified'], 'subject');
        $report['movements'] = $movements;
        $report['unclassified'] = $unclassified;
        $report['deferred'] = self::sortRows($state['deferred'], 'question');
        $report['summary'] = [
            'movements' => count($movements),
            'unclassified' => count($unclassified),
            'deferred' => count($report['deferred']),
            'certificates' => count($report['certificates']),
            'pins' => count($report['pins']),
        ];
        // Four words, and only one of them is green. `refused` outranks the
        // rest because a repository whose pins do not load has no movement list
        // worth reading at all; `unclassified` outranks `moves` because an
        // unclassified shape means the movement list is INCOMPLETE, and an
        // incomplete list read as a complete one is the false green this verb
        // exists to prevent. Only `ok` returns exit 0.
        $report['status'] = match (true) {
            ($report['site']['load'] ?? null) !== 'ok' => 'refused',
            $unclassified !== [] => 'unclassified',
            $movements !== [] => 'moves',
            default => 'ok',
        };

        return $report;
    }

    /**
     * The declared pins, through the engine's own normalizer.
     *
     * @return array{0:list<array{name:string,digest:?string,source:?string}>,1:?string}
     */
    private static function declaredPins(string $repo): array {
        try {
            $site = Canon::decode(Canon::read_file($repo . '/site.duo.json'));
        } catch (\Throwable $t) {
            return [[], $t->getMessage()];
        }
        try {
            // The default is the engine's own: Policy::load() reads
            // `$p->site['manifests'] ?? ['core']` (Policy.php:647), so a site
            // that declares no manifests pins `core` by name and this preflight
            // classifies the same list the loader would.
            return [PinResolver::normalize_manifest_pins($site['manifests'] ?? ['core']), null];
        } catch (\Throwable $t) {
            return [[], $t->getMessage()];
        }
    }

    /**
     * Every certificate the repository carries, classified by the gate.
     *
     * `verifyFile()` is called rather than a platform digest compared here,
     * because the comparison it performs is the whole question: it verifies the
     * Ed25519 signature and the authority binding FIRST, and only then compares
     * the signed platform against the one this agent publishes — so the typed
     * `StalePlatformSiteAdapterCertificate` it raises can only ever be about an
     * agent-owned document that moved, never about the companion's provenance
     * (AdapterCertification.php:1118-1128 says exactly that).
     *
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    private static function certificates(string $repo, string $manifestDir, string $site, array &$state): array {
        $directory = $repo . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR;
        if (!is_dir($directory)) {
            return [];
        }
        // The site trust root is validated WHOLE before any certificate is
        // paired, which is the scan's own posture (assert_site_authorities()'s
        // docblock): a broken root is not one adapter's problem. Its key ids
        // are then read BY ID — the reachability question is "is this id in the
        // root", and no public_key byte is touched to answer it.
        $reachable = [];
        $rootRefusal = null;
        try {
            AdapterCertification::assert_site_authorities($repo);
            $reachable = self::authorityKeyIds($repo . '/' . AdapterSources::SITE_DIR . '/'
                . AdapterSources::SITE_AUTHORITIES_FILE);
        } catch (\Throwable $t) {
            $rootRefusal = $t->getMessage();
        }
        // Gated on hasAuthorities() rather than on this path existing, so the
        // path spelling is never the thing that decides: that method owns
        // AdapterCertification::AUTHORITIES_RELATIVE (private), and if the two
        // ever disagreed the gate answers false and every SITE certificate — the
        // only kind an operator mints — still gets its reachability answer from
        // the site root below. The shipped root is empty on every shipped build.
        $platformKeyIds = AdapterCertification::hasAuthorities($manifestDir)
            ? self::authorityKeyIds($manifestDir . '/capabilities/adapter-authorities.json')
            : [];

        $rows = [];
        foreach (self::jsonFiles($directory) as $file) {
            $name = substr(basename($file), 0, -5);
            $row = [
                'name' => $name,
                'path' => AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR . "/$name.json",
                'key_id' => null,
                'key_reachable' => null,
                'certificate_agent_version' => null,
                'certificate_spec_version' => null,
                'trust_root' => null,
                'outcome' => null,
                'matches_target_platform' => null,
                'reason' => null,
            ];
            // Read facts first: an operator whose certificate does not verify
            // still needs to know which key id signed it and which agent state
            // it was bound to, and those are the two values that say what to do
            // next. Read, never derived — the key id is a string in the signed
            // statement and the reachability answer is set membership over ids.
            $statement = self::certificateStatement($file);
            if (is_array($statement)) {
                $row['key_id'] = is_string($statement['authority']['key_id'] ?? null)
                    ? (string) $statement['authority']['key_id']
                    : null;
                $row['trust_root'] = is_string($statement['authority']['trust_root'] ?? null)
                    ? (string) $statement['authority']['trust_root']
                    : null;
                $row['certificate_agent_version'] = is_string($statement['platform']['agent_version'] ?? null)
                    ? (string) $statement['platform']['agent_version']
                    : null;
                $row['certificate_spec_version'] = is_int($statement['platform']['spec_version'] ?? null)
                    ? (int) $statement['platform']['spec_version']
                    : null;
            }
            if ($row['key_id'] !== null) {
                $row['key_reachable'] = in_array($row['key_id'], $reachable, true)
                    || in_array($row['key_id'], $platformKeyIds, true);
                if ($row['key_reachable'] === false && $rootRefusal !== null) {
                    $row['key_reachable'] = null;
                }
            }

            $manifestPath = $repo . '/' . AdapterSources::SITE_DIR . "/$name.json";
            $manifest = null;
            if (is_file($manifestPath)) {
                try {
                    $manifest = Canon::decode(Canon::read_file($manifestPath));
                } catch (\Throwable $t) {
                    $manifest = null;
                }
            }
            if (!is_array($manifest)) {
                $row['outcome'] = 'unclassified';
                $row['reason'] = 'the certificate names an adapter this repository does not carry as '
                    . AdapterSources::SITE_DIR . "/$name.json";
                $state['unclassified'][] = [
                    'subject' => "certificate $name",
                    'what' => 'certificate shape',
                    'detail' => (string) $row['reason'],
                    'why' => 'a certificate with no adapter beside it cannot be verified at all, so its migration '
                        . 'outcome is unknown rather than unaffected',
                ];
                $rows[] = $row;
                continue;
            }

            try {
                AdapterCertification::verifyFile($manifestDir, $repo, $name, $manifest, $file);
                $row['outcome'] = 'holds';
                $row['matches_target_platform'] = true;
            } catch (\Throwable $t) {
                $signal = self::CERTIFICATE_SIGNALS[get_class($t)] ?? null;
                $row['reason'] = $t->getMessage();
                if ($signal === null) {
                    $row['outcome'] = 'unclassified';
                    $state['unclassified'][] = [
                        'subject' => "certificate $name",
                        'what' => 'certificate outcome',
                        'detail' => $t->getMessage(),
                        'why' => 'the certificate gate refused for a reason this preflight has no REVIEWED '
                            . 'migration outcome for. Following CompiledArtifactReader::artifact_guidance() '
                            . '(:96-136), an unreviewed gate is a hard stop, so no green verdict is printed for '
                            . 'this site',
                    ];
                    $rows[] = $row;
                    continue;
                }
                $row['outcome'] = $signal;
                // `false` only for `stale_platform`: that gate REACHED the
                // platform question and answered it. A wire-generation refusal
                // never got there — the generation is read before a signature
                // can be verified at all — so its platform answer is unknown,
                // and `null` is the only honest value for it.
                $row['matches_target_platform'] = $signal === 'stale_platform' ? false : null;
                if (in_array($signal, self::MOVING_CERTIFICATE_SIGNALS, true)) {
                    // Finding (b), predicted from the gate alone: the
                    // withdrawal changes the certificate-derived disposition
                    // folded into manifest_rows(), so this site's own
                    // manifest_hash and revision_hash move with it.
                    self::movement($state, 'certificate_withdrawn', "certificate:$name", $name, 'certificate-gate');
                    self::movement($state, 'adapter_digest_moved', "adapter_digest:$name", $name, 'certificate-gate');
                    self::movement($state, 'manifest_identity_moved', 'manifest_hash', $site, 'certificate-gate');
                    self::movement($state, 'revision_identity_moved', 'revision_hash', $site, 'certificate-gate');
                }
            }
            $rows[] = $row;
        }
        if ($rootRefusal !== null) {
            $state['unclassified'][] = [
                'subject' => AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE,
                'what' => 'site trust root',
                'detail' => $rootRefusal,
                'why' => 'every certificate in this repository is judged against this root, so a root this agent '
                    . 'refuses makes every signing-key reachability answer unknown rather than false',
            ];
        }

        return $rows;
    }

    /**
     * Every `{name, source, digest}` pin and whether it would move.
     *
     * @param list<array{name:string,digest:?string,source:?string}> $pins
     * @param array<string,string> $targetDigests
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    private static function pins(array $pins, array $targetDigests, bool $resolved, array &$state): array {
        $rows = [];
        foreach ($pins as $pin) {
            $name = $pin['name'];
            $target = $targetDigests[$name] ?? null;
            $row = [
                'name' => $name,
                'source' => $pin['source'],
                'digest' => $pin['digest'],
                'target_digest' => $target,
                'verdict' => null,
            ];
            if ($pin['digest'] === null) {
                // A bare name or a source-only override pins no content, so
                // there is no digest to move. Reported rather than dropped: the
                // absence is the answer, and an operator comparing two sites
                // needs to see which one carries content pins at all.
                $row['verdict'] = 'no_content_pin';
                $rows[] = $row;
                continue;
            }
            if (!$resolved) {
                $row['verdict'] = 'unresolved';
                $state['unclassified'][] = [
                    'subject' => "pin $name",
                    'what' => 'pin outcome',
                    'detail' => 'the repository did not load at the target, so no target digest was resolved',
                    'why' => 'a content pin whose target digest is unknown cannot be reported as holding',
                ];
                $rows[] = $row;
                continue;
            }
            if ($target === null) {
                $row['verdict'] = 'unresolved';
                $state['unclassified'][] = [
                    'subject' => "pin $name",
                    'what' => 'pin outcome',
                    'detail' => 'the target resolves no adapter under this pinned name',
                    'why' => 'a pinned name the target does not answer is not a pin that holds; it is a pin whose '
                        . 'subject is gone',
                ];
                $rows[] = $row;
                continue;
            }
            if (hash_equals($pin['digest'], $target)) {
                $row['verdict'] = 'holds';
                $rows[] = $row;
                continue;
            }
            $row['verdict'] = 'moves';
            self::movement($state, 'content_pin_moved', "content_pin:$name", $name, 'pin-comparison');
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * One row per resolved adapter, carrying the value finding (a) is about.
     *
     * @param list<array<string,mixed>> $resolved
     * @param array<string,?string> $claimAgent
     * @return list<array<string,mixed>>
     */
    private static function adapters(array $resolved, array $claimAgent): array {
        $rows = [];
        foreach ($resolved as $row) {
            $name = (string) $row['name'];
            $rows[] = [
                'name' => $name,
                'source' => (string) $row['source'],
                'trust_tier' => (string) $row['trust_tier'],
                'target_digest' => (string) $row['digest'],
                // `resolved_adapters[*].capability.platform.agent_version`,
                // named in full because it is the mechanism, not a decoration:
                // this value is inside the compiled document, so it is why
                // artifact_hash moves for a site that pins nothing but shipped
                // adapters.
                'capability_platform_agent_version' => $claimAgent[$name] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * The four identity values, held against target.
     *
     * The HELD half is the compiled artifact the deployed site is carrying —
     * the document WP-1.4 measured moving. `CompiledRepository::from_array()`
     * is the gate that reads it (it re-derives the document's own content hash
     * and refuses a mismatch), so both halves of every comparison below are
     * gate outputs rather than values re-derived here.
     *
     * @param list<array<string,mixed>> $adapters the target-resolved rows
     * @param array<string,mixed> $state
     * @return array{values:array<string,mixed>,held_artifact:?array<string,mixed>}
     */
    private static function identity(
        string $site,
        ?CompiledRepository $compiled,
        ?string $artifactPath,
        ?Policy $policy,
        array $adapters,
        array &$state
    ): array {
        $keys = ['artifact_hash', 'manifest_hash', 'revision_hash', 'site_hash'];
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = [
                'held' => null,
                'target' => $compiled === null ? null : $compiled->{$key}(),
                'verdict' => 'unobserved',
            ];
        }
        if ($artifactPath === null) {
            $state['deferred'][] = [
                'question' => 'do this site\'s four identity values move at the bump?',
                'answered_by' => '--artifact=<the compiled artifact this site is holding>',
                'why' => 'held-versus-target is a comparison of two documents and only one of them is in the '
                    . 'repository. Without the held artifact the identity block is unobserved, which is not the '
                    . 'same answer as unchanged',
            ];
            return ['values' => $values, 'held_artifact' => null];
        }
        try {
            $heldDocument = CompiledRepository::from_array(Canon::decode(Canon::read_file($artifactPath)));
        } catch (\Throwable $t) {
            $state['unclassified'][] = [
                'subject' => 'held compiled artifact',
                'what' => 'artifact shape',
                'detail' => $t->getMessage(),
                'why' => 'CompiledRepository::from_array() is the gate that proves an artifact is its own recorded '
                    . 'content; a document it refuses cannot be compared against the target',
            ];
            return ['values' => $values, 'held_artifact' => null];
        }

        $heldClaimAgent = [];
        $heldDigests = [];
        foreach ($heldDocument->resolved_adapters() as $row) {
            $name = (string) ($row['name'] ?? '');
            // The value finding (a) is about, read straight out of the held
            // payload: `resolved_adapters[*].capability.platform.agent_version`
            // is inside the compiled document, so a held document naming a
            // different agent than the target IS the reason artifact_hash
            // moves. A claim the target withdraws carries no platform block at
            // all, which is why the null is kept rather than defaulted.
            $heldClaimAgent[$name] = is_array($row['capability'] ?? null)
                ? ($row['capability']['platform']['agent_version'] ?? null)
                : null;
            $heldDigests[$name] = (string) ($row['digest'] ?? '');
        }
        $heldArtifact = [
            'path' => $artifactPath,
            'capability_platform_agent_versions' => $heldClaimAgent,
            'reader' => null,
        ];

        foreach ($keys as $key) {
            $values[$key]['held'] = $heldDocument->{$key}();
            if ($compiled === null) {
                continue;
            }
            $values[$key]['verdict'] = hash_equals($values[$key]['held'], (string) $values[$key]['target'])
                ? 'holds'
                : 'moves';
        }
        // The reader is the gate an operator meets on the deployed site, so it
        // is reported with its own reason code rather than inferred from the
        // hashes above — `compiled_artifact_manifest_mismatch` is the sentence
        // AGENTS.md rule 2 names, and it is the one that stops a promotion.
        if ($policy !== null) {
            try {
                CompiledArtifactReader::read_artifact($artifactPath, $policy);
                $heldArtifact['reader'] = 'verifies';
            } catch (\Throwable $t) {
                $heldArtifact['reader'] = $t instanceof CommandRefusalException ? $t->reasonCode : 'refused';
                $heldArtifact['reader_detail'] = $t->getMessage();
            }
        }

        foreach ([
            'artifact_hash' => 'artifact_reprojection',
            'manifest_hash' => 'manifest_identity_moved',
            'revision_hash' => 'revision_identity_moved',
            'site_hash' => 'site_identity_moved',
        ] as $key => $class) {
            if ($values[$key]['verdict'] === 'moves') {
                self::movement($state, $class, $key, $site, 'held-artifact');
            }
        }
        // The per-adapter half of the same comparison: a digest that moved
        // between the held document and the target is the movement, whatever
        // caused it — a certificate withdrawal, or manifest bytes that moved
        // under a pin.
        foreach ($adapters as $adapter) {
            $name = (string) $adapter['name'];
            $before = $heldDigests[$name] ?? null;
            if ($before === null || $before === '') {
                continue;
            }
            if (!hash_equals($before, (string) $adapter['target_digest'])) {
                self::movement($state, 'adapter_digest_moved', "adapter_digest:$name", $name, 'held-artifact');
            }
        }
        return ['values' => $values, 'held_artifact' => $heldArtifact];
    }

    /**
     * The scope-contract gate, which is what finding (a) actually costs.
     *
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    private static function scopeContracts(
        ?string $path,
        ?CompiledRepository $compiled,
        ?Policy $policy,
        array &$state
    ): array {
        if ($path === null) {
            $state['deferred'][] = [
                'question' => 'does a scope contract this site holds still associate after the bump?',
                'answered_by' => '--scope-contract=<the scope contract this site is holding>',
                'why' => 'ScopeContract::assert_associated() binds source.artifact_hash, and artifact_hash is the '
                    . 'value the bump moves fleet-wide. A site holding one needs a re-projection; this run was '
                    . 'given none to judge',
            ];
            return [];
        }
        $row = ['path' => $path, 'verdict' => 'unobserved', 'reason' => null];
        if ($compiled === null || $policy === null) {
            $state['unclassified'][] = [
                'subject' => 'held scope contract',
                'what' => 'scope contract outcome',
                'detail' => 'the repository did not compile at the target, so the contract has nothing to '
                    . 'associate against',
                'why' => 'an unjudged scope contract is not an associated one',
            ];
            return [$row];
        }
        try {
            ScopeContract::assert_associated(
                Canon::decode(Canon::read_file($path)),
                $compiled,
                $policy
            );
            $row['verdict'] = 'associates';
        } catch (\Throwable $t) {
            $row['verdict'] = 'refuses';
            $row['reason'] = $t->getMessage();
            self::movement(
                $state,
                'scope_contract_reassociation',
                'scope_contract',
                basename($path),
                'scope-contract-gate'
            );
        }
        return [$row];
    }

    /**
     * The frozen path: a promoted site that may not reopen its mutable
     * repository still re-derives its certificates against the boundary now
     * installed, so its claims move even though its files do not.
     *
     * @param array<string,mixed> $state
     * @return ?array<string,mixed>
     */
    private static function snapshot(?string $path, array &$state): ?array {
        if ($path === null) {
            return null;
        }
        $out = ['path' => $path, 'verdict' => 'unobserved', 'adapters' => [], 'reason' => null];
        try {
            $frozen = Policy::from_snapshot(Canon::decode(Canon::read_file($path)));
        } catch (\Throwable $t) {
            $out['verdict'] = 'refuses';
            $out['reason'] = $t->getMessage();
            $state['unclassified'][] = [
                'subject' => 'frozen policy snapshot',
                'what' => 'snapshot outcome',
                'detail' => $t->getMessage(),
                'why' => 'a snapshot the target cannot rehydrate has an unknown migration outcome, not a clean one',
            ];
            return $out;
        }
        $out['verdict'] = 'rehydrates';
        $sources = $frozen->adapter_sources();
        foreach (ArtifactPolicyIdentity::resolved_adapters($frozen) as $row) {
            $name = (string) $row['name'];
            $certified = $sources->is_certified($name);
            // `source` is on the row because `certified` alone reads as a loss
            // for a SHIPPED adapter, which is never site-certified and never
            // was: only a site-source adapter can have a claim the bump
            // withdraws, and the movement below is emitted for those alone.
            $out['adapters'][] = [
                'name' => $name,
                'source' => $sources->source($name),
                'digest' => (string) $row['digest'],
                'certified' => $certified,
            ];
            // The frozen record still SAYS certified; the rehydrated policy is
            // re-derived against the installed boundary, so a claim the bump
            // withdraws is withdrawn here too.
            if (!$certified && $sources->source($name) === AdapterSources::SITE) {
                self::movement(
                    $state,
                    'frozen_certificate_withdrawn',
                    "frozen_certificate:$name",
                    $name,
                    'frozen-snapshot-gate'
                );
            }
        }
        return $out;
    }

    /**
     * Every host contract this repository carries, and the two things a bump
     * can do to one: move the platform boundary its attestation binds, and move
     * the reviewed registry its evidence pins.
     *
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    private static function contracts(string $repo, string $manifestDir, string $registry, array &$state): array {
        $path = $repo . '/' . ContractStore::DIRECTORY . '/' . ContractStore::CONTRACT_FILE;
        if (!is_file($path)) {
            return [];
        }
        $relative = ContractStore::DIRECTORY . '/' . ContractStore::CONTRACT_FILE;
        $row = [
            'path' => $relative,
            'attestation' => ['state' => null, 'verdict' => 'unobserved', 'reason' => null],
            'registry' => [
                'pinned_sha256' => null,
                'target_sha256' => $registry,
                'invalidation' => null,
                'stale_registry' => null,
            ],
        ];
        try {
            $contract = ApplicationContract::parse((string) file_get_contents($path));
        } catch (\Throwable $t) {
            $state['unclassified'][] = [
                'subject' => $relative,
                'what' => 'contract shape',
                'detail' => $t->getMessage(),
                'why' => 'ApplicationContract::parse() is the contract grammar; a document it refuses has no '
                    . 'evidence pins this preflight can classify',
            ];
            $row['attestation']['verdict'] = 'unclassified';
            return [$row];
        }
        $row['registry']['pinned_sha256'] = is_string($contract['evidence_pins']['registry_sha256'] ?? null)
            ? (string) $contract['evidence_pins']['registry_sha256']
            : null;
        $row['attestation']['state'] = is_string($contract['attestation']['state'] ?? null)
            ? (string) $contract['attestation']['state']
            : null;

        if ($row['attestation']['state'] === 'signed') {
            try {
                ContractAttestation::verify($contract, $repo, $manifestDir);
                $row['attestation']['verdict'] = 'holds';
            } catch (\Throwable $t) {
                $code = $t instanceof CommandRefusalException ? $t->reasonCode : null;
                $row['attestation']['reason'] = $t->getMessage();
                if ($code === 'contract_attestation_platform_moved') {
                    $row['attestation']['verdict'] = 'platform_moved';
                    self::movement(
                        $state,
                        'contract_attestation_moved',
                        'contract_attestation',
                        $relative,
                        'contract-attestation-gate'
                    );
                } else {
                    // Every other attestation refusal — expired, revoked,
                    // tampered — is a state the bump neither causes nor clears,
                    // and this preflight has no reviewed migration outcome for
                    // it. Hard stop, same posture as an unreviewed certificate.
                    $row['attestation']['verdict'] = 'unclassified';
                    $state['unclassified'][] = [
                        'subject' => $relative,
                        'what' => 'contract attestation outcome',
                        'detail' => $t->getMessage(),
                        'why' => 'the attestation gate refused for a reason other than a moved platform boundary, '
                            . 'so what the bump does to this contract is unknown',
                    ];
                }
            }
        } else {
            $row['attestation']['verdict'] = 'unsigned';
        }

        // The reviewed-registry observation, from the projection's own
        // invalidation rule rather than a hash_equals written here. Observed
        // pins are deliberately NOT supplied: `ContractProjection::
        // validateFacts()` refuses `manifest_pins` unless every surface names
        // the adapters that govern it, and a host preflight has observed no
        // per-surface attribution — so this run takes the documented
        // no-observed-pins branch, `whole-contract` iff the registry moved
        // (ContractProjection.php:200-215). `duo assess <env>` is where the
        // exact flip is available, and the deferred row says so.
        $surfaces = [];
        foreach ((array) ($contract['declarations']['surfaces'] ?? []) as $surface) {
            if (is_array($surface) && is_string($surface['id'] ?? null)) {
                $surfaces[(string) $surface['id']] = [];
            }
        }
        if ($surfaces !== []) {
            try {
                $projection = ContractProjection::generate(
                    $contract,
                    [
                        'operations' => ProjectionVocabulary::OPERATIONS,
                        'registry_sha256' => $registry,
                        'surfaces' => $surfaces,
                    ],
                    ['agent_version' => DUO_AGENT_VERSION],
                    [],
                    gmdate('Y-m-d\TH:i:s\Z', 0)
                );
                $row['registry']['invalidation'] = (string) $projection['evidence_pins']['invalidation'];
                $row['registry']['stale_registry'] = (bool) $projection['evidence_pins']['stale_registry'];
                if ($row['registry']['stale_registry']) {
                    self::movement(
                        $state,
                        'contract_registry_moved',
                        'contract_registry',
                        $relative,
                        'contract-projection-gate'
                    );
                }
            } catch (\Throwable $t) {
                $state['unclassified'][] = [
                    'subject' => $relative,
                    'what' => 'contract registry observation',
                    'detail' => $t->getMessage(),
                    'why' => 'ContractProjection::generate() is the definition of what a moved registry_sha256 '
                        . 'means; without its verdict the contract half of this preflight is incomplete',
                ];
            }
        }
        $state['deferred'][] = [
            'question' => 'which SURFACES of this contract does the bump flip?',
            'answered_by' => 'duo assess <env>',
            'why' => 'the exact per-adapter flip needs the target\'s own surface attribution (governed_by), which '
                . 'a host process has not observed. This run reports the blunt whole-contract answer the '
                . 'projection defines for exactly that case',
        ];
        return [$row];
    }

    /**
     * Finding (a) on the bases that do not need a held artifact.
     *
     * A certificate's signed `statement.platform.agent_version` and a contract
     * attestation's `platform_sha256` each RECORD the agent state this site was
     * last bound to. Either one disagreeing with the target proves the site is
     * on a different agent — and every resolved adapter row inside the compiled
     * document carries `capability.platform.agent_version`, so a different agent
     * is a different artifact_hash. Emitted here, after every source has been
     * read, and deduplicated by id: one artifact_hash, however many sources
     * proved it moved.
     *
     * @param list<array<string,mixed>> $certificates
     * @param list<array<string,mixed>> $contracts
     * @param array<string,mixed> $state
     */
    private static function artifactReprojection(
        string $site,
        string $targetAgentVersion,
        array $certificates,
        array $contracts,
        array &$state
    ): void {
        foreach ($certificates as $certificate) {
            $recorded = $certificate['certificate_agent_version'] ?? null;
            if (is_string($recorded) && $recorded !== $targetAgentVersion) {
                self::movement($state, 'artifact_reprojection', 'artifact_hash', $site, 'certificate-platform');
            }
        }
        foreach ($contracts as $contract) {
            if (($contract['attestation']['verdict'] ?? null) === 'platform_moved') {
                self::movement($state, 'artifact_reprojection', 'artifact_hash', $site, 'contract-attestation');
            }
        }
    }

    /**
     * Record one predicted movement, or fold a second basis into a movement
     * already recorded.
     *
     * `why` and `remedy` come from a `match()` with NO default — the same
     * discipline `CompiledArtifactReader::artifact_guidance()` uses
     * (:96-136): a movement class added without a reviewed operator answer
     * raises \UnhandledMatchError at authoring time instead of shipping a row
     * that says nothing.
     *
     * @param array<string,mixed> $state
     */
    private static function movement(array &$state, string $class, string $id, string $subject, string $basis): void {
        foreach ($state['movements'] as $index => $existing) {
            if ($existing['id'] === $id) {
                $bases = $existing['basis'];
                if (!in_array($basis, $bases, true)) {
                    $bases[] = $basis;
                    sort($bases, SORT_STRING);
                    $state['movements'][$index]['basis'] = $bases;
                }
                return;
            }
        }
        [$why, $remedy] = self::movementGuidance($class);
        $state['movements'][] = [
            'class' => $class,
            'id' => $id,
            'subject' => $subject,
            'basis' => [$basis],
            'why' => $why,
            'remedy' => $remedy,
        ];
    }

    /**
     * The reviewed operator answer for each movement class.
     *
     * @return array{0:string,1:string}
     */
    private static function movementGuidance(string $class): array {
        return match ($class) {
            'artifact_reprojection' => [
                'artifact_hash covers the whole compiled document, and every resolved adapter row inside it '
                . 'carries capability.platform.agent_version — so it moves on an agent bump even under full '
                . 'digest neutrality, for a site that pins nothing but shipped adapters (WP-1.4 measured this '
                . 'for EVERY site in its estate)',
                're-project everything that PINS artifact_hash: scope contracts, scoped mutation authorities and '
                . 'scoped rollback claims. The compiled artifact itself still VERIFIES — read_artifact() compares '
                . 'site_hash, manifest_hash, effects and code, never artifact_hash',
            ],
            'manifest_identity_moved' => [
                'manifest_hash is manifest_rows() hashed, and each row folds the adapter\'s reviewed disposition '
                . '(ArtifactPolicyIdentity.php:60-115). A certificate the target withdraws changes the '
                . 'certificate-derived disposition, so this site\'s manifest identity moves with it',
                'recompile the repository and re-pin it with the object `wp duo manifest-pin` emits; the held '
                . 'compiled artifact refuses with compiled_artifact_manifest_mismatch until you do',
            ],
            'revision_identity_moved' => [
                'revision_hash binds the same manifest identity, so it moves with manifest_hash',
                'recompile; anything holding the old repository revision (an identity sidecar, a recovery '
                . 'checkpoint binding) must be re-taken against the new one',
            ],
            'site_identity_moved' => [
                'site_hash addresses site.duo.json. An agent bump does not touch it, so this row means the site '
                . 'policy itself moved as well — a second change riding along with the migration',
                'review the site.duo.json change on its own terms before treating this as migration fallout',
            ],
            'adapter_digest_moved' => [
                'the adapter\'s digest is its manifest_rows() row hashed, disposition included, so a withdrawn '
                . 'certificate or edited manifest bytes move it',
                're-pin this adapter with `duo adapter pin`, and re-certify it first if the certificate is the '
                . 'thing that moved (`duo adapter certify --pin`)',
            ],
            'certificate_withdrawn' => [
                'the certificate binds the platform boundary it was signed against; the target publishes a '
                . 'different one, so the claim is withdrawn and the adapter resolves as uncertified support',
                're-sign it against the target boundary with `duo adapter certify --pin` — certificates are '
                . 're-minted symmetrically, never restored, in both directions of the bump',
            ],
            'frozen_certificate_withdrawn' => [
                'a promoted site rehydrating from its frozen snapshot re-derives every certificate against the '
                . 'boundary now installed, so the frozen record still SAYS certified while the rehydrated policy '
                . 'does not',
                're-certify on the mutable repository and re-promote; the frozen snapshot cannot be repaired in '
                . 'place',
            ],
            'content_pin_moved' => [
                'an exact {name, source, digest} pin in site.duo.json no longer matches the digest the target '
                . 'resolves for that name',
                'update the pin with `duo adapter pin` (or the object `wp duo manifest-pin` emits) after the '
                . 'bump; a shipped or plugin pin that stops matching refuses the whole load',
            ],
            'scope_contract_reassociation' => [
                'ScopeContract::assert_associated() compares source.artifact_hash, and artifact_hash moves '
                . 'fleet-wide on an agent bump',
                're-resolve the scope contract against the recompiled artifact; there is no widened comparison '
                . 'that would make the held one associate',
            ],
            'contract_attestation_moved' => [
                'the attestation binds the platform boundary the contract was reviewed against, and an agent '
                . 'upgrade moves it — the most operator-visible refusal a flag day produces',
                're-attest with `duo contract <env> attest`; an expired or moved attestation is refused, never '
                . 'silently downgraded to unsigned',
            ],
            'contract_registry_moved' => [
                'evidence_pins.registry_sha256 addresses the WHOLE reviewed dispositions document, so any '
                . 'authored change to any subject moves it and the contract\'s evidence goes stale',
                're-run `duo assess <env>` and re-accept a fresh proposal; the projection\'s exact per-adapter '
                . 'flip needs the target\'s surface attribution, which a host preflight has not observed',
            ],
        };
    }

    /**
     * The key IDS of an authority record file — never a key byte.
     *
     * Read as a plain document rather than through the certification loader,
     * because the loader is private and, more to the point, the question here
     * is set membership over identifiers. The root has already been VALIDATED
     * by `assert_site_authorities()` before this is called for the site file;
     * for the shipped file `hasAuthorities()` gated the read.
     *
     * @return list<string>
     */
    private static function authorityKeyIds(string $file): array {
        if (!is_file($file)) {
            return [];
        }
        try {
            $document = Canon::decode(Canon::read_file($file));
        } catch (\Throwable $t) {
            return [];
        }
        $keys = $document['keys'] ?? null;
        if (!is_array($keys)) {
            return [];
        }
        $ids = [];
        foreach (array_keys($keys) as $id) {
            $ids[] = (string) $id;
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** Every `<name>.json` directly under a certification directory. @return list<string> */
    private static function jsonFiles(string $directory): array {
        $entries = @scandir($directory);
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_file($path) && !is_link($path)) {
                $out[] = $path;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * The signed statement of a certificate, as READ facts.
     *
     * Deliberately not verified here — `verifyFile()` below does that, and this
     * read exists so an operator whose certificate does NOT verify still learns
     * which key id signed it and which agent state it was bound to. Nothing
     * from this array is ever treated as trusted; it is printed through
     * `AdapterSources::render_untrusted()`.
     *
     * @return ?array<string,mixed>
     */
    private static function certificateStatement(string $file): ?array {
        try {
            $document = Canon::decode(Canon::read_file($file));
        } catch (\Throwable $t) {
            return null;
        }
        $statement = $document['statement'] ?? null;
        return is_array($statement) ? $statement : null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function sortRows(array $rows, string $key): array {
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a[$key], (string) $b[$key]));
        return array_values($rows);
    }

    /** @param array<string,mixed> $report */
    private static function render(array $report): void {
        echo "manifests dir: {$report['target']['manifests_dir']}\n";
        echo "site repo:     {$report['repo']}\n";
        echo "\nTARGET AGENT STATE (this process IS the target — see the class docblock):\n";
        echo "  agent_version   {$report['target']['agent_version']}\n";
        echo "  spec_version    {$report['target']['spec_version']}\n";
        echo "  platform_sha256 {$report['target']['platform_sha256']}\n";
        echo "  registry_sha256 {$report['target']['registry_sha256']}\n";

        echo "\nsite load: " . (string) $report['site']['load'] . "\n";
        if (is_string($report['site']['refusal'] ?? null)) {
            echo '  ' . AdapterSources::render_untrusted($report['site']['refusal']) . "\n";
        }

        echo "\ncertificates (verdict from AdapterCertification::verifyFile against the target):\n";
        foreach ($report['certificates'] as $row) {
            echo sprintf(
                "  [%-24s] %-28s key %s (%s) signed against agent %s\n",
                (string) $row['outcome'],
                (string) $row['name'],
                $row['key_id'] === null ? '(unreadable)' : AdapterSources::render_untrusted($row['key_id']),
                $row['key_reachable'] === null ? 'reachability unknown' : ($row['key_reachable'] ? 'reachable by id' : 'NOT reachable by id'),
                $row['certificate_agent_version'] === null
                    ? '(unreadable)'
                    : AdapterSources::render_untrusted($row['certificate_agent_version'])
            );
            if (is_string($row['reason'])) {
                echo '        ' . AdapterSources::render_untrusted($row['reason']) . "\n";
            }
        }
        if ($report['certificates'] === []) {
            echo "  (none)\n";
        }

        echo "\npins (target digest from ArtifactPolicyIdentity::resolved_adapters):\n";
        foreach ($report['pins'] as $row) {
            echo sprintf(
                "  [%-14s] %-28s %-8s %s\n",
                (string) $row['verdict'],
                (string) $row['name'],
                (string) ($row['source'] ?? '-'),
                $row['digest'] === null
                    ? 'no digest declared'
                    : substr((string) $row['digest'], 0, 12) . ' -> '
                        . ($row['target_digest'] === null ? '(unresolved)' : substr((string) $row['target_digest'], 0, 12))
            );
        }
        if ($report['pins'] === []) {
            echo "  (none)\n";
        }

        echo "\nidentity (held -> target):\n";
        foreach ($report['identity'] as $key => $row) {
            echo sprintf(
                "  [%-10s] %-14s %s -> %s\n",
                (string) $row['verdict'],
                (string) $key,
                $row['held'] === null ? '(unobserved)' : substr((string) $row['held'], 0, 12),
                $row['target'] === null ? '(unresolved)' : substr((string) $row['target'], 0, 12)
            );
        }
        if (is_array($report['held_artifact'])) {
            // The refusal an operator meets on the deployed site, printed with
            // the reason code they will see there — rule 2's
            // compiled_artifact_manifest_mismatch is the one that stops a
            // promotion, and it is the row that tells them to recompile.
            echo '  held artifact reader: ' . (string) ($report['held_artifact']['reader'] ?? '(unjudged)') . "\n";
        }

        echo "\nheld scope contracts (ScopeContract::assert_associated against the target):\n";
        foreach ($report['scope_contracts'] as $row) {
            echo '  [' . (string) $row['verdict'] . '] ' . (string) $row['path'] . "\n";
            if (is_string($row['reason'])) {
                echo '        ' . AdapterSources::render_untrusted($row['reason']) . "\n";
            }
        }
        if ($report['scope_contracts'] === []) {
            echo "  (none given)\n";
        }

        if (is_array($report['snapshot'])) {
            echo "\nfrozen policy snapshot (the path a promoted host walks):\n";
            echo '  [' . (string) $report['snapshot']['verdict'] . '] ' . (string) $report['snapshot']['path'] . "\n";
            foreach ((array) $report['snapshot']['adapters'] as $row) {
                echo sprintf(
                    "        %-28s %-8s %s\n",
                    (string) $row['name'],
                    (string) $row['source'],
                    (string) $row['source'] === AdapterSources::SITE
                        ? ($row['certified'] ? 'certified after rehydration' : 'WITHDRAWN on rehydration')
                        : 'not a site-certified claim'
                );
            }
        }

        echo "\nhost contracts:\n";
        foreach ($report['contracts'] as $row) {
            echo '  ' . (string) $row['path'] . "\n";
            echo '        attestation: ' . (string) $row['attestation']['verdict']
                . ($row['attestation']['reason'] === null
                    ? ''
                    : ' — ' . AdapterSources::render_untrusted($row['attestation']['reason'])) . "\n";
            echo '        registry:    ' . (string) ($row['registry']['invalidation'] ?? '(unjudged)')
                . ' (pinned ' . substr((string) ($row['registry']['pinned_sha256'] ?? '?'), 0, 12)
                . ' -> target ' . substr((string) $row['registry']['target_sha256'], 0, 12) . ")\n";
        }
        if ($report['contracts'] === []) {
            echo "  (none in this repository)\n";
        }

        echo "\nPREDICTED INVALIDATIONS (" . count($report['movements']) . "):\n";
        foreach ($report['movements'] as $row) {
            echo '  ' . (string) $row['class'] . ' [' . (string) $row['id'] . '] via '
                . implode(', ', (array) $row['basis']) . "\n";
            echo '        why:    ' . (string) $row['why'] . "\n";
            echo '        remedy: ' . (string) $row['remedy'] . "\n";
        }
        if ($report['movements'] === []) {
            echo "  (none)\n";
        }

        echo "\ndeferred (questions this run did NOT answer):\n";
        foreach ($report['deferred'] as $row) {
            echo '  ' . (string) $row['question'] . "\n";
            echo '        answered by: ' . (string) $row['answered_by'] . "\n";
            echo '        ' . (string) $row['why'] . "\n";
        }
        if ($report['deferred'] === []) {
            echo "  (none)\n";
        }

        if ($report['unclassified'] !== []) {
            echo "\nUNCLASSIFIED — no green verdict is printed for this site:\n";
            foreach ($report['unclassified'] as $row) {
                echo '  ' . (string) $row['subject'] . ' (' . (string) $row['what'] . ")\n";
                echo '        ' . AdapterSources::render_untrusted($row['detail']) . "\n";
                echo '        ' . (string) $row['why'] . "\n";
            }
        }

        echo "\nstatus: " . (string) $report['status'] . "\n";
    }

    /**
     * Machine-read output, so slashes stay unescaped and key order is this
     * command's own — the same rule AdapterCatalog::encode() states.
     *
     * @param array<string,mixed> $document
     */
    private static function encode(array $document): string {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('duo: migration preflight could not encode its report');
        }
        return $json;
    }

    /**
     * Load the engine surface this preflight reaches, exactly as
     * AdapterCatalog::boot() does and for the same reason (Policy::load()
     * compares a manifest's declared spec_version against DUO_SPEC_VERSION).
     *
     * The list is longer than the catalog's because a preflight COMPILES:
     * RepositoryCompiler::compile() reaches the whole repository surface, and
     * the certificate, scope-contract and pin gates each bring their own. It is
     * the same list `spec_migration_estate.php` requires to drive one agent
     * state end to end, which is not a coincidence — that file and this one
     * exercise the same engine.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("adapter: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter: could not resolve DUO_AGENT_VERSION');
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter: could not resolve DUO_SPEC_VERSION');
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }
        $duoAgentClassmap = require $repo . '/agent/duo-classmap.php';
        if (!is_array($duoAgentClassmap)) {
            throw new \RuntimeException('adapter: agent/duo-classmap.php did not return a map');
        }
        $duoAgentFiles = [];
        foreach ($duoAgentClassmap as $duoAgentPath) {
            $duoAgentFiles[basename((string) $duoAgentPath, '.php')] = (string) $duoAgentPath;
        }
        foreach ([
            'Uuid', 'OrderPreserved', 'Canon', 'OptionState', 'UserMetaState', 'Db', 'Secrets', 'PersonalData',
            'CommandRefusal', 'ManifestDispositions', 'PlatformCompatibility', 'AdapterSources', 'TargetProbe',
            'AdapterRegistry', 'NativeActions', 'ReferenceRules', 'Policy', 'Providers', 'Ledger', 'Deletion',
            'JsonRefs', 'PlainData', 'StructuredValue', 'SidebarState', 'Snapshot', 'RepositoryAuthorization',
            'CodeCompatibility', 'Code', 'CodeStateContract', 'ReferenceGraph', 'RepositoryCompiler',
            'ArtifactPolicyIdentity', 'CompiledArtifactReader', 'ScopeClosure', 'CanonicalSurfaces', 'ScopeContract',
            'ScopedStateOverlay', 'ScopedApplySession', 'AdapterCertification', 'PinResolver',
        ] as $class) {
            $duoAgentFile = $duoAgentFiles[$class] ?? null;
            if (!is_string($duoAgentFile)) {
                throw new \RuntimeException(
                    'adapter: agent source ' . $class . '.php is absent from agent/duo-classmap.php'
                );
            }
            require_once $repo . '/agent/' . $duoAgentFile;
        }
        require_once $repo . '/cli/src/Contract/ApplicationContract.php';
        require_once $repo . '/cli/src/Contract/ContractAttestation.php';
        require_once $repo . '/cli/src/Contract/ContractProjection.php';
        require_once $repo . '/cli/src/Contract/ContractStore.php';
    }

    /** Fail closed on this command's own paths: usage, a bad path, an unreadable library. */
    private static function fail(string $message): int {
        fwrite(STDERR, "duo: adapter: $message\n");
        return 2;
    }
}
