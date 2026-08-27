<?php
/**
 * WP-4.12 — cross-DOMAIN and cross-ROOT replay, measured through the verifiers.
 *
 * WHAT IS MEASURED
 * ----------------
 * Two axes that the corpus asserted ABOUT but never asserted THROUGH.
 *
 * (1) The four adapter-side signature domains — `SIGNATURE_DOMAIN` (:243),
 *     `SIGNATURE_DOMAIN_AUTHORITIES` (:388), `SIGNATURE_DOMAIN_DELEGATION`
 *     (:407) and `SIGNATURE_DOMAIN_REVOCATION` (:421), all in
 *     agent/src/Adapter/AdapterCertification.php — are exercised as a 4x4
 *     placement matrix against the four SLOTS that consume them:
 *     `verifyCertificate()` (:1556), `assertAuthoritiesEnvelope()` (:3286),
 *     `verifyDelegation()` (:2181) and `revocations()` (:2581). Every
 *     off-diagonal placement must refuse; every diagonal placement must accept.
 *     The diagonal is not decoration — without it the twelve refusals could be
 *     a property of the fixture (a key nobody holds, a document nobody reads)
 *     rather than of the domain.
 *
 * (2) The cross-ROOT clause `($statementAuthority['trust_root'] ?? null) !==
 *     $trustRoot` at `assertAuthorityBinding()` (:3480), and the trust-root
 *     VOCABULARY refusal at `verifyCertificate()` (:1587-1593). Both are
 *     reachable today and neither had a test that reached it through the member
 *     it is about: every existing suite lands on that refusal through the
 *     record-identity or `key_id` clause beside it, and the sentence
 *     "certification must name trust root platform or site" occurred nowhere
 *     under sandbox/, tests/ or scripts/ before this file.
 *
 * WHY A STRUCTURAL ARGUMENT WAS NOT ENOUGH
 * ----------------------------------------
 * The pre-existing coverage of axis (1) is entirely STRUCTURAL:
 * `sandbox/tests/offline/policy/regress_spec_v3_document.php:517-536` and
 * `tools/wire-surface.php:1869-1894` each assert that the four domain strings
 * are distinct, NUL-terminated and pairwise prefix-free. That is a sound
 * ARGUMENT that a signature minted under one domain cannot be replayed under
 * another — given that each verifier actually frames its preimage with its own
 * constant. It is not a test of that given. Nothing in the corpus would have
 * caught a verifier that framed its preimage with the WRONG constant, that
 * omitted the domain prefix altogether, or that grew a second domain arm — and
 * those are precisely the mutations the domains exist to make impossible
 * (register rows R-01/R-04, docs/wire-surface.md). A prefix-free set of strings
 * nobody prepends separates nothing.
 *
 * HOW A PLACEMENT IS BUILT, AND WHY IT IS NOT ARITHMETIC
 * -----------------------------------------------------
 * Every forged signature in this file is minted by invoking a SHIPPED private
 * framer through reflection — the established seam for exactly this in
 * `regress_authority_delegation.php:167,:235`, `regress_authority_record_v2
 * .php:496` and `regress_site_adapter_certification.php:2435`. The suite never
 * spells `domain . Canon::encode($statement)` itself: a copy of the framing
 * would stop testing the framing, and would pass against an engine that had
 * changed both halves in step. Every verdict is then taken from the PRODUCT
 * verifier through its own entry point — `verifyFile()`,
 * `assert_site_authorities()`, `assert_site_delegations()`,
 * `revocation_channel()` — never from a comparison this file performs.
 *
 * Two placement families are driven, because they falsify different mutations:
 *
 *   HARVEST  a signature the fixed key legitimately produced over domain A's
 *            OWN statement, pasted into slot B. The literal replay an attacker
 *            with read access to a repository can attempt today.
 *   REFRAME  slot B's OWN statement, framed under domain A and signed by the
 *            key slot B resolves. The discriminating cell: it holds the
 *            statement, the key, the document and every other check fixed, so
 *            the domain constant is the single variable. A verifier that
 *            dropped or swapped its prefix ACCEPTS here and refuses HARVEST.
 *
 * A third control per slot signs the bare canonical statement with NO domain at
 * all, which is what closes "the prefix was omitted" for the three cells REFRAME
 * cannot express (see CROSS_DOMAIN_GAPS).
 *
 * THE THREE NAMED GAPS
 * --------------------
 * `authoritiesSignatureBytes()` takes `(string $format, object $keys)` (:3328),
 * not a statement, so it can only ever emit
 * `SIGNATURE_DOMAIN_AUTHORITIES . canon({format, keys})`. A certification,
 * delegation or revocation statement is a different closed key set
 * (STATEMENT_KEYS :259, DELEGATION_STATEMENT_KEYS :577,
 * REVOCATION_STATEMENT_KEYS :595), so the shipped framer cannot be made to emit
 * the authorities domain over one of them at all. Those three REFRAME cells are
 * therefore recorded in CROSS_DOMAIN_GAPS with the mutation each leaves
 * unproven, printed on every run, and ratcheted both ways — a gap that becomes
 * expressible fails this suite until it is removed from the list. Faking them
 * would mean re-spelling the preimage here, which is the one thing this file
 * must not do.
 *
 * @see sandbox/tests/offline/policy/regress_spec_v3_document.php — the
 *      structural half this suite is the behavioural counterpart to.
 * @see sandbox/tests/offline/adapter/regress_authority_delegation.php — the
 *      delegation refusal matrix; this file reuses its forger seam.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// THE SCRATCH ESTATE. Built before the engine loads, because sign_site() takes
// its grammar verdict from the loader and refuses to sign against any library
// other than the one THIS process loaded (siteGrammarVerdict(),
// AdapterCertification.php:1271-1291) — so DUO_MANIFESTS_DIR has to be set
// before the first require. Under sandbox/tmp/ per AGENTS.md rule 3, keyed by
// pid and random bytes because `make -j8` runs the corpus concurrently.
// ---------------------------------------------------------------------------
$xrrRepo = dirname(__DIR__, 4);
$xrrRoot = $xrrRepo . '/sandbox/tmp/cross-root-replay-' . getmypid() . '-' . bin2hex(random_bytes(4));

function xrr_rmtree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        xrr_rmtree($item->getPathname());
    }
    rmdir($path);
}

xrr_rmtree($xrrRoot);
if (!mkdir($xrrRoot . '/library/capabilities', 0777, true)
    || !mkdir($xrrRoot . '/library/dispositions', 0777, true)
    || !mkdir($xrrRoot . '/site/adapters/certifications', 0777, true)) {
    fwrite(STDERR, "cannot create scratch root $xrrRoot\n");
    exit(1);
}
register_shutdown_function(static fn() => xrr_rmtree($xrrRoot));

$xrrLibrary = $xrrRoot . '/library';
$xrrSite = $xrrRoot . '/site';

// The library IS the shipped one for every file this suite reads: a hand-authored
// platform boundary would stop being evidence about the document a real
// certificate binds, and a hand-authored disposition set would stop the loader
// agreeing with the one a deployed site loads.
foreach ([
    'platform/adapter-library/core/manifest.json' => 'core.json',
    'platform/adapter-library/core/disposition.json' => 'dispositions/core.json',
    'platform/adapter-library/profiles.json' => 'dispositions/profiles.json',
    'platform/adapter-library/capabilities/platform.json' => 'capabilities/platform.json',
] as $from => $to) {
    if (!copy($xrrRepo . '/' . $from, $xrrLibrary . '/' . $to)) {
        fwrite(STDERR, "cannot copy $from into the scratch library\n");
        exit(1);
    }
}
putenv('DUO_MANIFESTS_DIR=' . $xrrLibrary);

require_once __DIR__ . '/../../lib/check.php';
require_once $xrrRepo . '/cli/src/Adapter/AdapterCertify.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\Orchestrator\AdapterCertify;
use Duo\Policy;

(new ReflectionMethod(AdapterCertify::class, 'boot'))->invoke(null);

if (realpath(Policy::manifests_dir()) !== realpath($xrrLibrary)) {
    fwrite(STDERR, 'the scratch library did not take: Policy::manifests_dir() is ' . Policy::manifests_dir() . "\n");
    exit(1);
}

/**
 * THE THREE UNPROVEN CELLS, and the mutation each one leaves alive.
 *
 * Same shape and same ratchet as REHEARSAL_GAPS in
 * sandbox/tests/offline/guards/regress_spec_migration_rehearsal.php: an
 * unproven cell is VISIBLE, never absent, and the list is checked against what
 * the framers can actually express so it cannot rot in either direction.
 */
const CROSS_DOMAIN_GAPS = [
    'authorities -> certification' =>
        'authoritiesSignatureBytes() is (string $format, object $keys) (AdapterCertification.php:3328) and emits '
        . 'SIGNATURE_DOMAIN_AUTHORITIES over a two-member {format, keys} document only. A certification statement '
        . 'is the six-member STATEMENT_KEYS set (:259), so the shipped framer cannot emit the authorities domain '
        . 'over it and the REFRAME cell is unconstructible without re-spelling the preimage here. UNPROVEN: '
        . 'signatureBytes() silently using SIGNATURE_DOMAIN_AUTHORITIES. HARVEST covers the pasted-signature '
        . 'replay and the no-domain control covers a dropped prefix; only that one substitution is open.',
    'authorities -> delegation' =>
        'same framer signature, and a delegation statement is the eight-member DELEGATION_STATEMENT_KEYS set '
        . '(:577). UNPROVEN: delegationSignatureBytes() silently using SIGNATURE_DOMAIN_AUTHORITIES.',
    'authorities -> revocation' =>
        'same framer signature, and a revocation statement is the four-member REVOCATION_STATEMENT_KEYS set '
        . '(:595). UNPROVEN: revocationSignatureBytes() silently using SIGNATURE_DOMAIN_AUTHORITIES.',
];

// ---------------------------------------------------------------------------
// KEYS. ONE signing key throughout, installed in BOTH roots under two ids, so
// that "the placement refused" can never be read as "the wrong key signed it".
// The v2 grammar makes an id derive from its own key material
// (assertKeyIdBindsKeyMaterial(), :3049), so the two ids share the fingerprint
// suffix and differ only in the label half the grammar leaves free.
// ---------------------------------------------------------------------------
$xrrPair = static function (string $seed): array {
    $keypair = sodium_crypto_sign_seed_keypair(str_repeat($seed, SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $public = sodium_crypto_sign_publickey($keypair);

    return [
        'secret' => sodium_crypto_sign_secretkey($keypair),
        'public' => $public,
        'encoded' => base64_encode($public),
        'fingerprint' => hash('sha256', $public),
        'short' => substr(hash('sha256', $public), 0, 12),
    ];
};
$signerKey = $xrrPair('R');
$delegateKey = $xrrPair('D');
// Never a signer and never installed: the revocation document has to name SOME
// fingerprint, and naming the signer's would revoke the key every other slot in
// this suite depends on (assertNotRevoked() matches by fingerprint, :2481).
$burntKey = $xrrPair('B');

$platformId = 'platform-' . $signerKey['short'];
$siteId = 'site-' . $signerKey['short'];
$delegateId = 'vendor-' . $delegateKey['short'];
$adapter = 'xrr-demo';

// The clock is PINNED (testAuthorityClock, the seam
// regress_authority_record_v2.php:269 and regress_authority_delegation.php use)
// so the windows below are fixed literals that cannot rot into an expired
// fixture: every window judgement in this file reads now(), :3202.
$xrrClock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
$xrrClock->setValue(null, static fn(): int => (int) strtotime('2026-06-15T00:00:00Z'));

/** A v2 authority record: the six shipped members, plus version and window. */
$xrrRecord = static fn(string $encodedPublic): array => [
    'adapter_names' => ['xrr-demo'],
    'algorithm' => 'ed25519',
    'not_after' => '2027-01-01T00:00:00Z',
    'not_before' => '2026-01-01T00:00:00Z',
    'public_key' => $encodedPublic,
    'record_version' => 2,
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => [AdapterSources::TIER_DECLARATIVE],
];

// Both roots, through the SHIPPED producer. signAuthorities() validates every
// record before it touches the private key, so a registry that could not be
// READ cannot be signed either — which is what makes these two files a premise
// rather than an assumption.
$platformRootRaw = AdapterCertification::signAuthorities(
    Canon::encode((object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
        'keys' => (object) [$platformId => $xrrRecord($signerKey['encoded'])],
    ]),
    $platformId,
    base64_encode($signerKey['secret'])
);
file_put_contents($xrrLibrary . '/capabilities/adapter-authorities.json', $platformRootRaw);

$siteRootRaw = AdapterCertification::signAuthorities(
    Canon::encode((object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
        'keys' => (object) [$siteId => $xrrRecord($signerKey['encoded'])],
    ]),
    $siteId,
    base64_encode($signerKey['secret'])
);
$siteRootPath = $xrrSite . '/adapters/authorities.json';
file_put_contents($siteRootPath, $siteRootRaw);

// One declarative site adapter, and the site policy that admits it.
$manifest = [
    'name' => $adapter,
    'option_autoload' => 'preserve',
    'option_namespaces' => [['match' => '^xrr_demo_']],
    'options' => ['xrr_demo_layout' => ['class' => 'authored']],
    'post_types' => ['xrr_demo_item' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
];
Canon::write_file($xrrSite . '/adapters/' . $adapter . '.json', Canon::encode($manifest));
Canon::write_file($xrrSite . '/site.duo.json', Canon::encode([
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]));

$certificatePath = AdapterCertification::certificatePath($xrrSite, $adapter);
$certificateRaw = AdapterCertification::sign_site(
    $xrrLibrary,
    $xrrSite,
    $adapter,
    $siteId,
    base64_encode($signerKey['secret']),
    'The operator reviewed this adapter against its own catalog schema.'
);
file_put_contents($certificatePath, $certificateRaw);
$certificate = Canon::decode($certificateRaw);

// ---------------------------------------------------------------------------
// THE FOUR SHIPPED FRAMERS, by reflection. Never a re-spelling: a suite that
// copied `domain . Canon::encode($statement)` would stop testing the framing
// and would pass against an engine that moved both halves in step.
// ---------------------------------------------------------------------------
$frameCertification = new ReflectionMethod(AdapterCertification::class, 'signatureBytes');
$frameAuthorities = new ReflectionMethod(AdapterCertification::class, 'authoritiesSignatureBytes');
$frameDelegation = new ReflectionMethod(AdapterCertification::class, 'delegationSignatureBytes');
$frameRevocation = new ReflectionMethod(AdapterCertification::class, 'revocationSignatureBytes');

/**
 * Frame one typed statement under one domain, or answer null when the shipped
 * framer cannot express it. Null is the NAMED GAP; it is never a skip.
 */
$frame = static function (string $domain, object $typed) use (
    $frameCertification,
    $frameAuthorities,
    $frameDelegation,
    $frameRevocation
): ?string {
    switch ($domain) {
        case 'certification':
            return (string) $frameCertification->invoke(null, $typed);
        case 'delegation':
            return (string) $frameDelegation->invoke(null, $typed);
        case 'revocation':
            return (string) $frameRevocation->invoke(null, $typed);
        case 'authorities':
            $members = get_object_vars($typed);
            if (array_keys($members) !== ['format', 'keys'] || !is_object($typed->keys)) {
                return null;
            }
            return (string) $frameAuthorities->invoke(null, (string) $typed->format, $typed->keys);
    }
    throw new LogicException("no shipped framer named '$domain'");
};

$sign = static fn(string $preimage): string => base64_encode(
    sodium_crypto_sign_detached($preimage, $signerKey['secret'])
);
$typed = static fn(array $value): object => (object) json_decode(
    Canon::encode((object) $value),
    false,
    512,
    JSON_THROW_ON_ERROR
);

// ---------------------------------------------------------------------------
// THE FOUR STATEMENTS, one per domain, each the exact document its own slot
// carries. Minted once and reused, so HARVEST pastes bytes the fixed key really
// did produce rather than bytes invented for the test.
// ---------------------------------------------------------------------------
$certStatement = $typed($certificate['statement']);

$authoritiesDocument = $typed([
    'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
    'keys' => (object) [$siteId => $xrrRecord($signerKey['encoded'])],
]);

$delegationStatement = $typed([
    'adapter_names' => [$adapter],
    'delegate' => [
        'algorithm' => 'ed25519',
        'key_id' => $delegateId,
        'public_key' => $delegateKey['encoded'],
    ],
    'delegator' => [
        'fingerprint' => $signerKey['fingerprint'],
        'key_id' => $platformId,
        'trust_root' => AdapterCertification::TRUST_ROOT_PLATFORM,
    ],
    'format' => AdapterCertification::DELEGATION_FORMAT,
    'not_after' => '2026-12-01T00:00:00Z',
    'not_before' => '2026-02-01T00:00:00Z',
    'trust_tiers' => [AdapterSources::TIER_DECLARATIVE],
    'version' => 1,
]);

$revocationStatement = $typed([
    'format' => AdapterCertification::REVOCATION_FORMAT,
    'issued_at' => '2026-03-01T00:00:00Z',
    'revocations' => [[
        'effective_at' => '2026-03-02T00:00:00Z',
        'fingerprint' => $burntKey['fingerprint'],
        'key_id' => 'burnt-' . $burntKey['short'],
        'reason' => 'a fingerprint no slot in this suite signs with, so the channel is exercised without '
            . 'revoking the fixed key every other slot resolves',
    ]],
    'version' => 1,
]);

$statements = [
    'certification' => $certStatement,
    'authorities' => $authoritiesDocument,
    'delegation' => $delegationStatement,
    'revocation' => $revocationStatement,
];

// ---------------------------------------------------------------------------
// THE FOUR SLOTS. Each installs a signature into the document its verifier
// reads, drives that verifier through its own PRODUCT entry point, and reports
// the refusal message or null. Nothing here re-implements a check.
// ---------------------------------------------------------------------------
$revocationPath = $xrrLibrary . '/capabilities/adapter-revocations.json';
$delegationPath = $xrrSite . '/adapters/delegations.json';

$verdict = static function (callable $drive): ?string {
    try {
        $drive();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

$slots = [
    'certification' => [
        'install' => static function (string $signature) use ($certificate, $certificatePath): void {
            $document = $certificate;
            $document['signature'] = $signature;
            file_put_contents($certificatePath, Canon::encode($document));
        },
        'drive' => static function () use ($xrrLibrary, $xrrSite, $adapter, $manifest, $certificatePath): void {
            AdapterCertification::verifyFile($xrrLibrary, $xrrSite, $adapter, $manifest, $certificatePath);
        },
        'reset' => static function () use ($certificatePath, $certificateRaw): void {
            file_put_contents($certificatePath, $certificateRaw);
        },
        'refusal' => "duo: site adapter '$adapter' certification has an invalid Ed25519 signature",
    ],
    'authorities' => [
        'install' => static function (string $signature) use ($authoritiesDocument, $siteId, $siteRootPath): void {
            file_put_contents($siteRootPath, Canon::encode((object) [
                'format' => $authoritiesDocument->format,
                'keys' => $authoritiesDocument->keys,
                'signature' => (object) ['key_id' => $siteId, 'value' => $signature],
            ]));
        },
        'drive' => static function () use ($xrrSite): void {
            AdapterCertification::assert_site_authorities($xrrSite);
        },
        'reset' => static function () use ($siteRootPath, $siteRootRaw): void {
            file_put_contents($siteRootPath, $siteRootRaw);
        },
        'refusal' => "duo: site adapter certification authorities envelope signature does not verify under key "
            . "'$siteId'; an unsigned or tampered authorities document is refused, never read as an absent trust"
            . ' root',
    ],
    'delegation' => [
        'install' => static function (string $signature) use (
            $delegationStatement,
            $delegateId,
            $platformId,
            $delegationPath
        ): void {
            file_put_contents($delegationPath, Canon::encode((object) [
                'delegations' => (object) [$delegateId => (object) [
                    'signature' => (object) ['key_id' => $platformId, 'value' => $signature],
                    'statement' => $delegationStatement,
                ]],
                'format' => AdapterCertification::DELEGATIONS_FORMAT,
            ]));
        },
        'drive' => static function () use ($xrrLibrary, $xrrSite): void {
            AdapterCertification::assert_site_delegations($xrrLibrary, $xrrSite);
        },
        'reset' => static function () use ($delegationPath): void {
            if (is_file($delegationPath)) {
                unlink($delegationPath);
            }
        },
        'refusal' => "duo: site adapter certification delegation '$delegateId' does not verify under delegator "
            . "'$platformId'; an unsigned or tampered delegation is refused, never read as an absent grant",
    ],
    'revocation' => [
        'install' => static function (string $signature) use ($revocationStatement, $platformId, $revocationPath): void {
            file_put_contents($revocationPath, Canon::encode((object) [
                'format' => AdapterCertification::REVOCATIONS_FORMAT,
                'signature' => (object) ['key_id' => $platformId, 'value' => $signature],
                'statement' => $revocationStatement,
            ]));
        },
        'drive' => static function () use ($xrrLibrary): void {
            AdapterCertification::revocation_channel($xrrLibrary);
        },
        'reset' => static function () use ($revocationPath): void {
            if (is_file($revocationPath)) {
                unlink($revocationPath);
            }
        },
        'refusal' => "duo: adapter certification authority revocations do not verify under key '$platformId'; an "
            . 'unsigned or tampered revocation document is refused, never read as an absent one',
    ],
];

$domains = array_keys($slots);

/** Install one signature into a slot, take the product verdict, put the slot back. */
$place = static function (string $slot, string $signature) use ($slots, $verdict): ?string {
    $slots[$slot]['install']($signature);
    try {
        return $verdict($slots[$slot]['drive']);
    } finally {
        $slots[$slot]['reset']();
    }
};

echo "\n== the premise: four domains, one key, four live slots ==\n";

duo_check_same(
    [
        "duo-site-adapter-certification-signature/v2\0",
        "duo-adapter-authorities-signature/v1\0",
        "duo-adapter-authority-delegation-signature/v1\0",
        "duo-adapter-authority-revocation-signature/v1\0",
    ],
    array_map(
        static fn(string $name): string => (string) constant(AdapterCertification::class . '::' . $name),
        ['SIGNATURE_DOMAIN', 'SIGNATURE_DOMAIN_AUTHORITIES', 'SIGNATURE_DOMAIN_DELEGATION', 'SIGNATURE_DOMAIN_REVOCATION']
    ),
    'the four adapter-side domains are the four this matrix is sized for — a fifth constant would leave a row and '
    . 'a column of this suite unwritten rather than silently covered'
);
duo_check_same(
    $signerKey['fingerprint'],
    hash('sha256', base64_decode((string) $xrrRecord($signerKey['encoded'])['public_key'], true) ?: ''),
    'one key material is installed under both ids, so no placement below can refuse merely because a different '
    . 'key signed it — the ids differ only in the label half the v2 grammar leaves free (:3049)'
);
$baseline = $verdict($slots['certification']['drive']);
duo_check_same(
    null,
    $baseline,
    'baseline: the sign_site() certificate verifies through verifyFile() before anything is forged ('
    . ((string) $baseline) . ')'
);

echo "\n== the diagonal: each slot ACCEPTS its own domain, minted by the forger ==\n";

// Minted by the SAME construction the off-diagonal cells use, so the diagonal
// is a control over the forger and not merely a restatement of the producer.
$native = [];
foreach ($domains as $domain) {
    $preimage = $frame($domain, $statements[$domain]);
    if ($preimage === null) {
        duo_check(false, "the shipped framer for '$domain' could not frame its OWN statement");
        continue;
    }
    $native[$domain] = $sign($preimage);
    $answer = $place($domain, $native[$domain]);
    duo_check_same(
        null,
        $answer,
        "diagonal $domain -> $domain: a signature this suite framed through the shipped framer and signed with "
        . 'the fixed key is ACCEPTED, so every refusal below is attributable to the domain and not to the '
        . 'construction (' . ((string) $answer) . ')'
    );
}

duo_check_same(
    $certificate['signature'],
    $native['certification'] ?? null,
    'and the forger reproduces the SHIPPED signer byte for byte on the diagonal — Ed25519 is deterministic, so '
    . 'this equality binds signatureBytes() to what sign_site() actually signed rather than to a second framing'
);

echo "\n== HARVEST: a signature minted under domain A, pasted into slot B (12 cells) ==\n";

foreach ($domains as $source) {
    foreach ($domains as $target) {
        if ($source === $target) {
            continue;
        }
        $answer = $place($target, $native[$source]);
        duo_check(
            $answer !== null && str_contains($answer, $slots[$target]['refusal']),
            "HARVEST $source -> $target: the fixed key's genuine $source signature is REFUSED in the $target slot, "
            . 'by that slot\'s own sentence (' . ((string) $answer) . ')'
        );
    }
}

echo "\n== REFRAME: the target slot's OWN statement, framed under a foreign domain (9 cells) ==\n";

$unexpressible = [];
foreach ($domains as $source) {
    foreach ($domains as $target) {
        if ($source === $target) {
            continue;
        }
        $preimage = $frame($source, $statements[$target]);
        if ($preimage === null) {
            // Recorded, then ratcheted against CROSS_DOMAIN_GAPS below. Never
            // skipped: an unproven cell has to be visible in the output.
            $unexpressible[] = "$source -> $target";
            continue;
        }
        $answer = $place($target, $sign($preimage));
        duo_check(
            $answer !== null && str_contains($answer, $slots[$target]['refusal']),
            "REFRAME $source -> $target: the $target slot's OWN statement, its OWN key and its OWN document — only "
            . "the domain is $source — is REFUSED, which is the cell a verifier framing with the wrong constant "
            . 'would pass (' . ((string) $answer) . ')'
        );
    }
}

echo "\n== the named gaps, printed rather than absent ==\n";

foreach (CROSS_DOMAIN_GAPS as $cell => $why) {
    echo "GAP: $cell\n     $why\n";
}
duo_check_same(
    array_keys(CROSS_DOMAIN_GAPS),
    $unexpressible,
    'the cells no shipped framer can express are EXACTLY the ones written down — the ratchet runs both ways, so a '
    . 'cell that becomes constructible fails here until it is driven and removed from CROSS_DOMAIN_GAPS'
);

echo "\n== the no-domain control: every slot applies SOME prefix (4 cells) ==\n";

foreach ($domains as $target) {
    // The bare canonical statement, with no domain byte in front of it. This is
    // the mutation the three named gaps leave open for the authorities domain
    // specifically, and it is closed for all four slots here.
    $answer = $place($target, $sign(Canon::encode($statements[$target])));
    duo_check(
        $answer !== null && str_contains($answer, $slots[$target]['refusal']),
        "NO-DOMAIN -> $target: a signature over the slot's own canonical statement with NO domain prefix is "
        . 'REFUSED, so the prefix is load-bearing rather than decorative (' . ((string) $answer) . ')'
    );
}

echo "\n== cross-ROOT: the trust_root clause at AdapterCertification.php:3480 ==\n";

/** Re-sign one edited certification statement validly, and take the verdict. */
$reroot = static function (callable $edit) use (
    $certificate,
    $certificatePath,
    $certificateRaw,
    $typed,
    $frameCertification,
    $sign,
    $verdict,
    $slots
): ?string {
    $document = $certificate;
    $edit($document['statement']['authority']);
    $statement = $typed($document['statement']);
    $document['statement'] = json_decode(Canon::encode($statement), true, 512, JSON_THROW_ON_ERROR);
    $document['signature'] = $sign((string) $frameCertification->invoke(null, $statement));
    file_put_contents($certificatePath, Canon::encode($document));
    try {
        return $verdict($slots['certification']['drive']);
    } finally {
        file_put_contents($certificatePath, $certificateRaw);
    }
};

$siteToPlatform = $reroot(static function (array &$authority): void {
    $authority['trust_root'] = AdapterCertification::TRUST_ROOT_PLATFORM;
});
duo_check(
    $siteToPlatform !== null && str_contains(
        $siteToPlatform,
        "duo: site adapter '$adapter' certification authority/key/fingerprint/trust root does not match the "
        . 'current site authority record'
    ),
    'site -> platform: a site-rooted certificate whose trust_root alone is flipped to `platform`, RE-SIGNED '
    . 'validly under SIGNATURE_DOMAIN with the key the site root holds, refuses at the :3480 trust_root clause — '
    . 'authority() resolved the id in the site root and the statement claimed the platform one ('
    . ((string) $siteToPlatform) . ')'
);

$platformToSite = $reroot(static function (array &$authority) use ($platformId): void {
    $authority['key_id'] = $platformId;
    $authority['trust_root'] = AdapterCertification::TRUST_ROOT_SITE;
});
duo_check(
    $platformToSite !== null && str_contains(
        $platformToSite,
        "duo: authority key '$platformId' is reviewed and shipped by this agent, so a site trust root cannot claim it"
    ),
    'platform -> site: the reverse replay is answered one clause EARLIER, by the shipped-wins hoist at '
    . 'AdapterCertification.php:1601-1612 (G2-FIXES m3), which is the more specific sentence R-13 records — so '
    . ':3480 is by construction reachable only in the site -> platform direction ('
    . ((string) $platformToSite) . ')'
);

$thirdRoot = $reroot(static function (array &$authority): void {
    $authority['trust_root'] = 'vendor';
});
duo_check(
    $thirdRoot !== null && str_contains(
        $thirdRoot,
        "duo: site adapter '$adapter' certification must name trust root platform or site"
    ),
    'a THIRD trust root word is refused by vocabulary at :1587-1593, before any key is resolved — R-13 reserves '
    . 'that third value for a genuinely new custody model and a certificate may not mint one (' . ((string) $thirdRoot) . ')'
);

duo_check_same(
    ['platform', 'site'],
    [AdapterCertification::TRUST_ROOT_PLATFORM, AdapterCertification::TRUST_ROOT_SITE],
    'and the two words the vocabulary admits are the two shipped constants, so the refusal above is about the '
    . 'closed set rather than about a spelling this suite chose'
);

duo_check_summary('cross-root replay');
