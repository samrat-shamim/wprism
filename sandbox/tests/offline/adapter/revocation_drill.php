<?php
/**
 * THE REVOCATION DRILL — a burnt key run to ground on WP-1.4's rehearsal fleet,
 * with propagation latency measured (spec/repo-format.md § v3.8; WP-5.1).
 *
 * WHY THIS IS A CHILD PROCESS, AND NOT PART OF THE SUITE THAT DRIVES IT
 * --------------------------------------------------------------------
 * The estate's certificates are minted at STATE A — the release the fleet is
 * coming from (`spec_migration_estate.php:120-171`) — and a state is a pair of
 * `define()`s a PHP process can hold exactly once. The suite that owns this
 * drill runs at the tree's own pair, because the enrollment ceremony it proves
 * first is a ceremony for the SHIPPED agent. So the drill runs here, at the
 * versions the estate's own `observe` pass reports, which is why they arrive as
 * arguments rather than being re-derived: a second copy of
 * `rehearsal_state_versions()` would be a literal that drifts.
 *
 * WHAT A DRILL IS, AS OPPOSED TO A UNIT TEST OF THE CHANNEL
 * --------------------------------------------------------
 * `regress_revocation_reachability.php` already proves the typed channel's
 * grammar, its three installed states, and that a revoked vendor key stops
 * verifying on the frozen path. None of that answers the operational question
 * G4 condition (3) actually asks: run one end to end on a FLEET, and measure
 * how long it takes to have effect. So this file takes the nine-site estate
 * exactly as WP-1.4's driver built it — the driver still installs no revocation
 * document, which is what keeps that suite's named gaps true — and does what an
 * incident responder does to a fleet that already exists:
 *
 *   1. ENROLL. The estate's libraries ship `{"keys":{}}` like every agent, so
 *      there is no key that could sign a revocation. A platform root is minted
 *      and enrolled first, through the same ceremony the parent suite proves.
 *      That ordering is the drill's first finding: with an empty root the
 *      channel is INERT by construction, so revocation capability is something
 *      enrollment buys, not something an agent has.
 *   2. CONTROL. Every certified site verifies, live and frozen, before anything
 *      is revoked.
 *   3. BURN. One operator key's FINGERPRINT is named in a typed, platform-signed
 *      revocation document, couriered into each site's manifest library.
 *   4. MEASURE. Per site: the interval from the document landing to the first
 *      verification that refuses, on the live path and on the frozen path.
 *   5. BOUND THE BLAST RADIUS. The site certified under the OTHER operator key
 *      is untouched, and no drilled site is bricked — a revocation takes away
 *      the CLAIM, not the site (§ v3.8, G2-FIXES C3).
 *   6. STAND DOWN. Removing the document restores every verdict, because
 *      absence means "nothing is revoked".
 *
 * WHAT THE MEASURED NUMBER IS, AND WHAT IT IS NOT. It is the AGENT-side
 * propagation cost: one document write plus one signature verification at the
 * next resolution, with no cache, no restart and no agent release. It is NOT
 * the courier interval — how long an operator's cron, configuration run or
 * incident paste takes to reach a host is an operator SLO this engine cannot
 * observe and does not claim. The drill measures the half that is the engine's.
 *
 * OUTPUT CONTRACT. One canonical JSON document on STDOUT, progress on STDERR.
 * Every value is a FACT the engine produced — a verdict word, a refusal
 * sentence, an elapsed interval. No judgement is made here; the suite owns all
 * of them, on `spec_migration_estate.php`'s own model.
 *
 * Deliberately NOT named regress_* : this is a helper the suite runs, and
 * `regress_suite_wiring.php` reserves that prefix for files a Makefile target
 * runs directly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    fwrite(STDERR, "revocation_drill.php is a child entry point, not a library\n");
    exit(2);
}
if (($argc ?? 0) !== 4 || !ctype_digit((string) $argv[3])) {
    fwrite(STDERR, 'usage: php ' . basename(__FILE__) . " <estate-root> <agent-version> <spec-version>\n");
    exit(2);
}

$drillEstate = (string) realpath(rtrim($argv[1], '/'));
if ($drillEstate === '' || !is_dir($drillEstate)) {
    fwrite(STDERR, "revocation_drill.php: no estate at {$argv[1]}\n");
    exit(2);
}
define('DUO_AGENT_VERSION', (string) $argv[2]);
define('DUO_SPEC_VERSION', (int) $argv[3]);

$drillRepo = dirname(__DIR__, 4);
require_once $drillRepo . '/agent/src/Kernel/Canon.php';
require_once $drillRepo . '/agent/src/Kernel/OptionState.php';
require_once $drillRepo . '/agent/src/Adapter/AdapterSources.php';
require_once $drillRepo . '/agent/src/Adapter/AdapterCertification.php';
require_once $drillRepo . '/agent/src/Policy/Policy.php';
// A content-pinned site resolves its digests through the compiler on the load
// path (`PinResolver::validate_manifest_pins()`), and rule 1 means nothing
// autoloads it: without this require the drill would report "Class
// RepositoryCompiler not found" as if it were a site refusal.
require_once $drillRepo . '/agent/src/Repository/RepositoryCompiler.php';

use Duo\AdapterCertification;
use Duo\Canon;
use Duo\Policy;

// The WordPress seams a policy load can touch, refusing rather than answering —
// `spec_migration_estate.php:2060-2078` states why a silent stub would hide a
// reach into a target this drill must never make.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        throw new RuntimeException("revocation drill: target contact get_option($name)");
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(...$args): array {
        throw new RuntimeException('revocation drill: target contact wp_upload_dir');
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value = null) {
        return $value;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}
if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "revocation drill: the PHP sodium extension is required\n");
    exit(2);
}

/** Milliseconds since a `hrtime(true)` mark, to three decimals. */
function drill_ms(int $since): float {
    return round((hrtime(true) - $since) / 1e6, 3);
}

/**
 * One site's live-path verdict, and the interval the verification itself took.
 *
 * `verifyFile()` is the seat every live consumer reaches, so driving it is
 * driving the product path rather than a re-implementation of it. The verdict
 * vocabulary is deliberately three-valued: `certified` (the claim stands),
 * `withdrawn` (the typed signal § v3.8 raises when an authority is revoked or
 * outside its window — the claim falls to uncertified support and the SITE
 * keeps working), and `refused` (anything else, which is a whole-source event).
 *
 * @return array{verdict:string,reason:string,elapsed_ms:float}
 */
function drill_live(string $library, string $repo, string $name): array {
    $manifest = Canon::decode(Canon::read_file($repo . '/adapters/' . $name . '.json'));
    $certificate = $repo . '/adapters/certifications/' . $name . '.json';
    $mark = hrtime(true);
    try {
        $verified = AdapterCertification::verifyFile($library, $repo, $name, $manifest, $certificate);
        return [
            'verdict' => (string) ($verified['claim']['status'] ?? '?'),
            'reason' => '',
            'elapsed_ms' => drill_ms($mark),
        ];
    } catch (\Duo\WithdrawnAuthoritySiteAdapterCertificate $withdrawn) {
        return [
            'verdict' => 'withdrawn',
            'reason' => $withdrawn->getMessage(),
            'elapsed_ms' => drill_ms($mark),
        ];
    } catch (Throwable $t) {
        return ['verdict' => 'refused', 'reason' => $t->getMessage(), 'elapsed_ms' => drill_ms($mark)];
    }
}

/**
 * The FROZEN path: a promoted host verifying from the snapshot it holds, which
 * reopens no mutable site file — so it reads no `adapters/authorities.json` and
 * no `adapters/delegations.json`. The revocation document is the only channel
 * that reaches here, which is the entire reason it lives in the manifest
 * library (`AdapterSources.php` calls verifyFrozen() with `Policy::manifests_dir()`
 * and nothing else).
 *
 * @return array{verdict:string,reason:string,elapsed_ms:float}
 */
function drill_frozen(string $library, string $estate, string $id, string $name): array {
    $snapshot = Canon::decode(Canon::read_file($estate . '/holdings/' . $id . '/snapshot.json'));
    $envelope = (array) ($snapshot['adapter_sources']['certificates'][$name] ?? []);
    $manifest = Canon::decode(Canon::read_file($estate . '/sites/' . $id . '/adapters/' . $name . '.json'));
    $mark = hrtime(true);
    try {
        $verified = AdapterCertification::verifyFrozen($library, $name, $manifest, $envelope);
        return [
            'verdict' => (string) ($verified['claim']['status'] ?? '?'),
            'reason' => '',
            'elapsed_ms' => drill_ms($mark),
        ];
    } catch (\Duo\WithdrawnAuthoritySiteAdapterCertificate $withdrawn) {
        return [
            'verdict' => 'withdrawn',
            'reason' => $withdrawn->getMessage(),
            'elapsed_ms' => drill_ms($mark),
        ];
    } catch (Throwable $t) {
        return ['verdict' => 'refused', 'reason' => $t->getMessage(), 'elapsed_ms' => drill_ms($mark)];
    }
}

/**
 * THE BLAST RADIUS OF ONE REVOKED KEY, measured on the site rather than on the
 * certificate: does the repository still LOAD, what word does the adapter carry
 * now, did its content-pinned DIGEST move, did `manifest_hash` move, and does
 * the compiled artifact the site is holding still verify?
 *
 * Five facts, because "the site is not bricked" is five separate questions and
 * an incident responder needs each one answered separately. The last three are
 * the expensive half: a moved digest is a re-pin, a moved `manifest_hash` is a
 * recompile (`compiled_artifact_manifest_mismatch`, AGENTS.md rule 2), and
 * those are the flag day's own step 5/6 arriving unannounced in the middle of a
 * security incident. Whether they move is a measurement this drill exists to
 * take, not an assumption either way.
 *
 * @return array{load:string,word:?string,digest:string,manifest_hash:string,artifact:string}
 */
function drill_site_state(string $estate, string $id, string $name): array {
    $repo = $estate . '/sites/' . $id;
    try {
        $policy = Policy::load($repo);
    } catch (Throwable $t) {
        return [
            'load' => 'refused: ' . $t->getMessage(),
            'word' => null,
            'digest' => '',
            'manifest_hash' => '',
            'artifact' => 'unread',
        ];
    }
    $digest = '';
    foreach (\Duo\ArtifactPolicyIdentity::resolved_adapters($policy) as $row) {
        if ((string) $row['name'] === $name) {
            $digest = (string) $row['digest'];
        }
    }
    // Absolute by construction: `MediaPayloadAuthority::readArtifactDocument()`
    // refuses a relative artifact path, so a relative estate root would report
    // `compiled_artifact_invalid` for every site — a fixture defect that reads
    // exactly like the refusal this drill is measuring
    // (`spec_migration_estate.php:2008-2013` records the same trap).
    $artifact = $estate . '/holdings/' . $id . '/artifact.json';
    $verdict = 'none';
    if (is_file($artifact)) {
        try {
            \Duo\CompiledArtifactReader::read_artifact($artifact, $policy);
            $verdict = 'verified';
        } catch (\Duo\CommandRefusalException $refusal) {
            $verdict = $refusal->reasonCode;
        } catch (Throwable $t) {
            $verdict = 'refused: ' . $t->getMessage();
        }
    }

    return [
        'load' => 'loaded ' . count($policy->manifests) . ' manifests',
        'word' => $policy->adapter_sources()->certification_word($name),
        'digest' => $digest,
        'manifest_hash' => \Duo\ArtifactPolicyIdentity::manifest_hash($policy),
        'artifact' => $verdict,
    ];
}

/** The three certified sites of the estate, and which key vouched for each. */
const DRILL_SUBJECTS = [
    'certified-alpha' => ['adapter' => 'estate-forms', 'key' => 'site-key-alpha', 'path' => 'live'],
    'certified-beta' => ['adapter' => 'estate-shop', 'key' => 'site-key-beta', 'path' => 'live'],
    'promoted-frozen' => ['adapter' => 'estate-catalog', 'key' => 'site-key-alpha', 'path' => 'frozen'],
];

try {
    // THE VERIFYING HOST'S CLOCK, pinned for the duration of the drill through
    // the same reflection seam every window suite in this corpus uses
    // (`AdapterCertification::$testAuthorityClock`, which has no production
    // setter). Without it the enrolled record's own `not_after` would be a time
    // bomb: a window written as a literal here would silently start refusing on
    // its expiry date and the failure would read as a broken drill rather than
    // as a fixture that aged out.
    $drillClock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
    $drillClock->setValue(null, static fn(): int => (int) strtotime('2026-09-01T00:00:00Z'));

    $library = $drillEstate . '/libs/A';
    putenv('DUO_MANIFESTS_DIR=' . $library);
    $authorities = $library . '/capabilities/adapter-authorities.json';
    $revocations = $library . '/capabilities/adapter-revocations.json';
    $out = [
        'format' => 'duo-revocation-drill/v1',
        'agent_version' => DUO_AGENT_VERSION,
        'spec_version' => DUO_SPEC_VERSION,
        'library' => 'libs/A',
    ];

    // --- 1. the pre-enrollment posture -------------------------------------
    //
    // Recorded before anything is enrolled, because it is the drill's first
    // finding: an agent whose platform root is empty cannot honour ANY
    // revocation, so the channel is inert by construction until enrollment.
    $out['shipped_root'] = Canon::decode(Canon::read_file($authorities));
    $out['revocation_document_ships'] = file_exists($revocations);

    $keypair = sodium_crypto_sign_seed_keypair(str_repeat('D', SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $platformSecret = sodium_crypto_sign_secretkey($keypair);
    $platformPublic = sodium_crypto_sign_publickey($keypair);
    $platformId = 'platform-' . substr(hash('sha256', $platformPublic), 0, 12);

    $inertDocument = AdapterCertification::signRevocations(
        Canon::encode((object) [
            'format' => AdapterCertification::REVOCATION_FORMAT,
            'issued_at' => '2026-08-01T00:00:00Z',
            'revocations' => [[
                'effective_at' => '2026-08-01T00:00:00Z',
                'fingerprint' => str_repeat('0', 64),
                'key_id' => 'placeholder-000000000000',
                'reason' => 'the pre-enrollment posture probe',
            ]],
            'version' => 1,
        ]),
        $platformId,
        base64_encode($platformSecret)
    );
    file_put_contents($revocations, $inertDocument);
    $channel = AdapterCertification::revocation_channel($library);
    $out['inert_before_enrollment'] = [
        'signer' => (string) ($channel['signer'] ?? ''),
        'message' => (string) ($channel['message'] ?? ''),
        'certified_alpha_still_verifies' => drill_live(
            $library,
            $drillEstate . '/sites/certified-alpha',
            'estate-forms'
        )['verdict'],
    ];
    unlink($revocations);

    // --- 2. enroll the platform root ---------------------------------------
    file_put_contents($authorities, AdapterCertification::signAuthorities(
        Canon::encode((object) [
            'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
            'keys' => (object) [
                $platformId => [
                    // A name this estate does not carry: the enrolled key's job
                    // here is to SIGN revocations, and a revocation's subject
                    // set is deliberately larger than any grant's — it must be
                    // able to name a key that holds no delegation at all.
                    'adapter_names' => ['estate-drill'],
                    'algorithm' => 'ed25519',
                    'not_after' => '2028-01-01T00:00:00Z',
                    'not_before' => '2026-01-01T00:00:00Z',
                    'public_key' => base64_encode($platformPublic),
                    'record_version' => 2,
                    'scope' => 'site_adapter_certification',
                    'status' => 'trusted',
                    'trust_tiers' => ['declarative_manifest'],
                ],
            ],
        ]),
        $platformId,
        base64_encode($platformSecret)
    ));
    $out['enrolled_key_id'] = $platformId;

    // --- 3. the control ----------------------------------------------------
    $out['before'] = [];
    foreach (DRILL_SUBJECTS as $id => $subject) {
        $row = $subject['path'] === 'frozen'
            ? drill_frozen($library, $drillEstate, $id, $subject['adapter'])
            : drill_live($library, $drillEstate . '/sites/' . $id, $subject['adapter']);
        $out['before'][$id] = $row + drill_site_state($drillEstate, $id, $subject['adapter']);
    }

    // --- 4. the burn, and the measurement ----------------------------------
    //
    // `site-key-alpha` is the compromised identity. The entry binds its
    // FINGERPRINT — sha256 of the public key — and never its id, because an id
    // can be re-minted over new material and material cannot be re-minted under
    // an old id.
    // Read out of the estate's OWN key file rather than out of its materialize
    // report: the fingerprint that must be burnt is a property of the key
    // material an operator holds, and deriving it from that material is the
    // only derivation that cannot disagree with what actually signed.
    $burntSecret = (string) base64_decode(
        trim((string) file_get_contents($drillEstate . '/keys/site-key-alpha.key')),
        true
    );
    $burntPublic = sodium_crypto_sign_publickey_from_secretkey($burntSecret);
    $burntFingerprint = hash('sha256', $burntPublic);
    $document = AdapterCertification::signRevocations(
        Canon::encode((object) [
            'format' => AdapterCertification::REVOCATION_FORMAT,
            'issued_at' => '2026-08-01T00:00:00Z',
            'revocations' => [[
                'effective_at' => '2026-08-01T00:00:00Z',
                'fingerprint' => $burntFingerprint,
                'key_id' => 'site-key-alpha',
                'reason' => 'operator signing key disclosed in drill incident 2026-08-01',
            ]],
            'version' => 1,
        ]),
        $platformId,
        base64_encode($platformSecret)
    );
    $out['burnt'] = ['key_id' => 'site-key-alpha', 'fingerprint' => $burntFingerprint];

    // THE COURIER LANDS. Everything after this instant is agent-side
    // propagation, which is what this drill can honestly measure.
    $landed = hrtime(true);
    file_put_contents($revocations, $document);
    $out['install_ms'] = drill_ms($landed);

    // THE PROPAGATION PASS, and it is deliberately the ONLY thing between the
    // landing instant and each site's verdict: every site is verified first, and
    // the far more expensive blast-radius pass runs afterwards. Folding the two
    // together would have charged each site the previous site's `Policy::load()`
    // and compile — a number that reads as propagation latency and is not one.
    $out['after'] = [];
    foreach (DRILL_SUBJECTS as $id => $subject) {
        $row = $subject['path'] === 'frozen'
            ? drill_frozen($library, $drillEstate, $id, $subject['adapter'])
            : drill_live($library, $drillEstate . '/sites/' . $id, $subject['adapter']);
        // Landing-to-effect for THIS site: the whole interval from the document
        // hitting the filesystem to this site's verdict changing, including the
        // sites walked before it. Reported beside the per-site verification
        // interval so a reader can tell the fleet-wide walk from the single
        // resolution one agent actually performs.
        $row['landed_to_effect_ms'] = drill_ms($landed);
        $row['key'] = $subject['key'];
        $row['path'] = $subject['path'];
        $out['after'][$id] = $row;
    }
    $out['fleet_reached_ms'] = drill_ms($landed);
    foreach (DRILL_SUBJECTS as $id => $subject) {
        $out['after'][$id] += drill_site_state($drillEstate, $id, $subject['adapter']);
    }

    // --- 5. stand down -----------------------------------------------------
    unlink($revocations);
    $out['restored'] = [];
    foreach (DRILL_SUBJECTS as $id => $subject) {
        $out['restored'][$id] = ($subject['path'] === 'frozen'
            ? drill_frozen($library, $drillEstate, $id, $subject['adapter'])
            : drill_live($library, $drillEstate . '/sites/' . $id, $subject['adapter']))['verdict'];
    }
    $drillClock->setValue(null, null);
} catch (Throwable $failure) {
    fwrite(STDERR, 'revocation_drill.php: ' . get_class($failure) . ': ' . $failure->getMessage()
        . "\n" . $failure->getTraceAsString() . "\n");
    exit(1);
}

fwrite(STDOUT, Canon::encode($out) . "\n");
exit(0);
