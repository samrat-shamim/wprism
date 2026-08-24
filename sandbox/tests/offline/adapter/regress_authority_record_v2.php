<?php
/**
 * The authority record v2 grammar (spec/repo-format.md § v3.7, WP-4.8).
 *
 * WHAT IS UNDER TEST, AND WHY IT IS ONE SUITE
 * -------------------------------------------
 * Five changes to ONE grammar — fingerprint-derived key ids, a mandatory
 * validity window with a named clock, a namespace pattern beside exact names,
 * a signed envelope, and the platform root's identity-only record binding.
 * They are one work package because they are one document: a reader who
 * accepts a v2 record accepts all five at once, and any of them landing
 * separately would be a second flag day for whoever already held a v2 file.
 * (The fifth change — the platform binding — is proved where it lives, on the
 * live certification path, by the SECOND-ENROLLMENT case in
 * regress_site_adapter_certification.php; this suite proves the four grammar
 * changes and the one thing the binding change must not have laundered.)
 *
 * THE INVARIANT EVERY CASE HERE IS MEASURED AGAINST
 * ------------------------------------------------
 * `DUO_SPEC_VERSION` stays 2 and no shipped byte moves. So every rule below is
 * gated on a document declaring `duo-adapter-authorities/v2` (and on each
 * record restating that with `record_version: 2`), and the FIRST section of
 * this suite is the control: the identical v1 document, key for key, keeps
 * today's verdict byte for byte — including the ids, names and absent window
 * that v2 would refuse. A rule that leaked out of its gate fails there before
 * any v2 assertion is reached.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED: that a v2 record exists anywhere in the
 * shipped library. It does not, and must not — `manifests/capabilities/
 * adapter-authorities.json` is `{"keys":{}}` through the flag day, asserted
 * here on every run, because issuing one key freezes this wire format in a
 * stranger's hands.
 */
declare(strict_types=1);

if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', '0.5.0');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterCertification.php';

use Duo\AdapterCertification;
use Duo\Canon;

$repo = dirname(__DIR__, 4);
$root = $repo . '/sandbox/tmp/authority-record-v2-' . getmypid();

function arv2_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        arv2_remove_tree($item->getPathname());
    }
    rmdir($path);
}

arv2_remove_tree($root);
if (!mkdir($root . '/capabilities', 0777, true)) {
    fwrite(STDERR, "cannot create scratch root $root\n");
    exit(1);
}
register_shutdown_function(static fn() => arv2_remove_tree($root));

/**
 * `authorityKeys()` is private and is the ONE reader every consumer reaches —
 * `authority()` (live), `assertKeyIdNotPlatformOwned()` (frozen) and
 * `hasAuthorities()` all go through it — so driving it directly is driving the
 * product path, not a re-implementation of it. Reflection rather than a public
 * shim, for the reason the class already states about its one other test seam:
 * the shipped surface stays what a site can call.
 */
$readAuthorities = static function (array $document) use ($root): array {
    $file = $root . '/capabilities/adapter-authorities.json';
    file_put_contents($file, Canon::encode($document));
    $method = new ReflectionMethod(AdapterCertification::class, 'authorityKeys');

    return (array) $method->invoke(null, $file, 'adapter certification authorities');
};

$refusal = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

/** Deterministic keypairs: the fingerprint grammar has to be computable here too. */
$keypair = sodium_crypto_sign_seed_keypair(str_repeat('V', SODIUM_CRYPTO_SIGN_SEEDBYTES));
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$fingerprint = substr(hash('sha256', $public), 0, 12);
$keyId = 'acme-' . $fingerprint;

$otherPair = sodium_crypto_sign_seed_keypair(str_repeat('W', SODIUM_CRYPTO_SIGN_SEEDBYTES));
$otherSecret = sodium_crypto_sign_secretkey($otherPair);
$otherPublic = sodium_crypto_sign_publickey($otherPair);
$otherId = 'zeta-' . substr(hash('sha256', $otherPublic), 0, 12);

/** A v1 record: exactly the six shipped members, no window, any legal id. */
$v1Record = static fn(string $encodedPublic, array $names = ['acme-forms']): array => [
    'adapter_names' => $names,
    'algorithm' => 'ed25519',
    'public_key' => $encodedPublic,
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => ['declarative_manifest'],
];

/** A v2 record: the six, plus `record_version` and the mandatory window. */
$v2Record = static function (
    string $encodedPublic,
    array $names = ['acme-forms'],
    string $notBefore = '2026-01-01T00:00:00Z',
    string $notAfter = '2027-01-01T00:00:00Z'
): array {
    return [
        'adapter_names' => $names,
        'algorithm' => 'ed25519',
        'not_after' => $notAfter,
        'not_before' => $notBefore,
        'public_key' => $encodedPublic,
        'record_version' => 2,
        'scope' => 'site_adapter_certification',
        'status' => 'trusted',
        'trust_tiers' => ['declarative_manifest'],
    ];
};

/** Sign a `{format, keys}` v2 document through the shipped producer. */
$sign = static function (array $keys, string $signerId, string $signerSecret): array {
    $signed = AdapterCertification::signAuthorities(
        Canon::encode((object) [
            'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
            'keys' => (object) $keys,
        ]),
        $signerId,
        base64_encode($signerSecret)
    );

    return Canon::decode($signed);
};

echo "\n== the shipped precondition, re-checked on every run ==\n";

duo_check_same(
    ['format' => 'duo-adapter-authorities/v1', 'keys' => []],
    (array) json_decode((string) file_get_contents($repo . '/manifests/capabilities/adapter-authorities.json'), true),
    'manifests/capabilities/adapter-authorities.json is still the EMPTY v1 registry — the flag day\'s standing '
    . 'precondition, and the reason the platform root may still choose its binding (register row R-08)'
);
duo_check_same(
    2,
    (int) DUO_SPEC_VERSION,
    'and DUO_SPEC_VERSION is still 2: every rule below is gated on the authorities DOCUMENT format, never on '
    . 'the manifest wire version, so the flip stays WP-4.12\'s alone'
);

echo "\n== the v1 control: identical keys, today's verdict, byte for byte ==\n";

// Every one of these v1 documents would be refused at v2 — a squatted id, a
// namespace pattern, no window, no signature. Each is ACCEPTED here, which is
// what proves the five rules are gated rather than global.
$v1Squatted = ['format' => 'duo-adapter-authorities/v1', 'keys' => (object) [
    'wordpress-security-team' => $v1Record(base64_encode($public)),
]];
duo_check_same(
    ['wordpress-security-team'],
    array_keys($readAuthorities($v1Squatted)),
    'a v1 key id that derives from NO key material still loads: `keyId()` is the shared identity slug grammar '
    . 'and v1 asks nothing more of it'
);
$v1Unsigned = ['format' => 'duo-adapter-authorities/v1', 'keys' => (object) [
    $keyId => $v1Record(base64_encode($public)),
    $otherId => $v1Record(base64_encode($otherPublic), ['acme-forms', 'zeta-catalog']),
]];
duo_check_same(
    [$keyId, $otherId],
    array_keys($readAuthorities($v1Unsigned)),
    'a v1 document carries no envelope signature and is not asked for one — `{format, keys}` stays closed in '
    . 'both directions (register row R-10)'
);
duo_check_same(
    null,
    $refusal(static fn() => $readAuthorities($v1Unsigned)),
    'and a v1 record with no window is never judged against a clock: no expiry vocabulary reaches a v1 record '
    . 'at all'
);
$v1Pattern = ['format' => 'duo-adapter-authorities/v1', 'keys' => (object) [
    $keyId => $v1Record(base64_encode($public), ['acme-*']),
]];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($v1Pattern)), 'adapter certification name'),
    'a namespace PATTERN inside a v1 record is refused by the exact-name grammar, exactly as it is today — the '
    . 'pattern is a v2 vocabulary and does not leak backwards'
);
$v1WithWindow = ['format' => 'duo-adapter-authorities/v1', 'keys' => (object) [
    $keyId => ['not_after' => '2027-01-01T00:00:00Z'] + $v1Record(base64_encode($public)),
]];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($v1WithWindow)), 'must contain exactly'),
    'and a v1 record that grows a window member is refused by the closed key set rather than honoured — an '
    . 'unenforced expiry in front of a reader who thinks it is enforced is the failure this refuses'
);

echo "\n== change (a): a v2 key id derives from its own key material ==\n";

$signedGood = $sign([$keyId => $v2Record(base64_encode($public))], $keyId, $secret);
duo_check_same(
    [$keyId],
    array_keys($readAuthorities($signedGood)),
    "a v2 key id ending in its own '-$fingerprint' fingerprint loads — the id `duo adapter keygen` already "
    . 'derives by default is now the grammar'
);
$squatted = $signedGood;
$squattedKeys = (array) $squatted['keys'];
$squattedKeys['wordpress-security-team'] = $squattedKeys[$keyId];
unset($squattedKeys[$keyId]);
$squatted['keys'] = (object) $squattedKeys;
$squattedRefusal = (string) $refusal(static fn() => $readAuthorities($squatted));
duo_check(
    str_contains($squattedRefusal, 'does not derive from its own key material')
        && str_contains($squattedRefusal, "'-$fingerprint'"),
    'a SQUATTED v2 id — a name nobody holding that key material could honestly claim — is refused, and the '
    . 'refusal names the fingerprint the id owed (' . $squattedRefusal . ')'
);
$swapped = $signedGood;
$swappedKeys = (array) $swapped['keys'];
$swappedKeys[$keyId]['public_key'] = base64_encode($otherPublic);
$swapped['keys'] = (object) $swappedKeys;
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($swapped)), 'does not derive from its own key material'),
    'and swapping the KEY under a legitimate id is the same refusal read from the other end: the id and the '
    . 'key material are one fact, not two'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::signAuthorities(
            Canon::encode((object) [
                'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
                'keys' => (object) ['wordpress-security-team' => $v2Record(base64_encode($public))],
            ]),
            'wordpress-security-team',
            base64_encode($secret)
        )),
        'does not derive from its own key material'
    ),
    'the signer refuses a squatted id too, so an unreadable registry can never be produced by the one producer '
    . 'that ships'
);

echo "\n== change (b): not_after, the named clock, and the implausible-clock posture ==\n";

$clock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
$setClock = static function (?int $epoch) use ($clock): void {
    $clock->setValue(null, $epoch === null ? null : static fn(): int => $epoch);
};
$windowed = $sign(
    [$keyId => $v2Record(base64_encode($public), ['acme-forms'], '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z')],
    $keyId,
    $secret
);
$record = ((array) $windowed['keys'])[$keyId];
$scope = new ReflectionMethod(AdapterCertification::class, 'assertAuthorityScope');
$inScope = static fn(): mixed => $scope->invoke(null, $record, $keyId, 'acme-forms', 'declarative_manifest');
$openEpoch = (int) strtotime('2026-06-01T00:00:00Z');
$expiryEpoch = (int) strtotime('2027-01-01T00:00:00Z');
$issuedEpoch = (int) strtotime('2026-01-01T00:00:00Z');

$setClock($openEpoch);
duo_check_same(null, $refusal($inScope), 'inside its window a v2 key certifies');
$setClock($issuedEpoch);
duo_check_same(null, $refusal($inScope), 'the window is closed at the TOP only: the issuance instant itself is inside it');
$setClock($expiryEpoch - 1);
duo_check_same(null, $refusal($inScope), 'one second before not_after the key still certifies');
$setClock($expiryEpoch);
$atBoundary = (string) $refusal($inScope);
duo_check(
    str_contains($atBoundary, "authority key '$keyId' expired at 2027-01-01T00:00:00Z")
        && str_contains($atBoundary, "judged against this host's own wall clock, which reads 2027-01-01T00:00:00Z")
        && str_contains($atBoundary, 'no skew allowance in either direction'),
    'AT not_after it refuses — the comparison is `>=`, like the contract root\'s — and the refusal NAMES its '
    . 'clock and states the no-skew posture (' . $atBoundary . ')'
);
$setClock($expiryEpoch + 86400);
duo_check(
    str_contains((string) $refusal($inScope), 'expired at 2027-01-01T00:00:00Z'),
    'and past it, still refused, still naming the instant rather than the interval'
);
$setClock($issuedEpoch - 1);
$implausible = (string) $refusal($inScope);
duo_check(
    str_contains($implausible, "this host's own wall clock reads 2025-12-31T23:59:59Z")
        && str_contains($implausible, "before authority key '$keyId' was issued at 2026-01-01T00:00:00Z")
        && str_contains($implausible, 'refuses rather than resurrecting an expired record'),
    'a clock BEFORE the record\'s own issuance instant refuses rather than finding an already-retired record '
    . 'inside its window — the resurrection this ordering exists to prevent (' . $implausible . ')'
);
// The ordering is the rule, not an accident of which test ran first: a record
// that is BOTH expired and read on a backwards clock must answer with the
// clock, because "expired" computed from a clock nobody trusts is not a fact.
$setClock(0);
duo_check(
    str_contains((string) $refusal($inScope), 'implausible clock'),
    'and the implausible-clock test runs FIRST: a host at the epoch is told its clock is wrong, not that the '
    . 'record is fine'
);
$setClock(null);
$revokedWindowed = $windowed;
$revokedKeys = (array) $revokedWindowed['keys'];
$revokedKeys[$keyId]['status'] = 'revoked';
$revokedRecord = $revokedKeys[$keyId];
duo_check(
    str_contains(
        (string) $refusal(static fn() => $scope->invoke(null, $revokedRecord, $keyId, 'acme-forms', 'declarative_manifest')),
        'is revoked and cannot certify adapters'
    ),
    'revocation still answers before the window does: an operator who revoked a key is told that, not that it '
    . 'has not expired yet'
);
$badWindow = [
    'not_before after not_after' => ['2027-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 'a window that has never been open'],
    'not_before equal to not_after' => ['2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 'a window that has never been open'],
    'a loose instant' => ['2026-13-01T00:00:00Z', '2027-01-01T00:00:00Z', 'one unambiguous ISO-8601 UTC instant'],
    'a local-time instant' => ['2026-01-01T00:00:00+01:00', '2027-01-01T00:00:00Z', 'one unambiguous ISO-8601 UTC instant'],
];
foreach ($badWindow as $label => [$before, $after, $needle]) {
    $document = ['format' => 'duo-adapter-authorities/v2', 'keys' => (object) [
        $keyId => $v2Record(base64_encode($public), ['acme-forms'], $before, $after),
    ], 'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))]];
    duo_check(
        str_contains((string) $refusal(static fn() => $readAuthorities($document)), $needle),
        "a v2 record with $label is refused by the window grammar, before any signature byte is read"
    );
}
$noWindow = $v2Record(base64_encode($public));
unset($noWindow['not_after']);
$noWindowDocument = ['format' => 'duo-adapter-authorities/v2', 'keys' => (object) [$keyId => $noWindow],
    'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))]];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($noWindowDocument)), 'must contain exactly'),
    'and the window is MANDATORY at v2: a record that omits an end is refused by the closed key set, because '
    . 'an optional member has no honest home in a set that refuses missing and unknown alike'
);

echo "\n== change (c): adapter_names admits a namespace beside exact names ==\n";

$namespaced = $sign(
    [$keyId => $v2Record(base64_encode($public), ['acme-*', 'legacy-shop'])],
    $keyId,
    $secret
);
$namespacedRecord = ((array) $namespaced['keys'])[$keyId];
$setClock($openEpoch);
$scopeVerdict = static fn(string $name): ?string => $refusal(
    static fn() => $scope->invoke(null, $namespacedRecord, $keyId, $name, 'declarative_manifest')
);
duo_check_same(null, $scopeVerdict('acme-forms'), 'a namespace-scoped key certifies INSIDE its pattern');
duo_check_same(null, $scopeVerdict('acme-forms-pro'), 'and anywhere deeper inside it');
duo_check_same(null, $scopeVerdict('legacy-shop'), 'while an exact name listed beside the pattern still matches exactly');
foreach (['zeta-forms', 'acme', 'acmex-forms', 'legacy-shop-pro'] as $outside) {
    duo_check(
        str_contains((string) $scopeVerdict($outside), "is not scoped to site adapter '$outside'"),
        "and '$outside' is OUTSIDE it: the pattern is `<vendor>-` and nothing looser — no prefix-of-a-prefix, "
        . 'no bare name, no widening of an exact entry'
    );
}
$setClock(null);
foreach (['*', '*-forms', 'acme-*-pro', 'ac*me', 'acme-**'] as $illegal) {
    $document = ['format' => 'duo-adapter-authorities/v2', 'keys' => (object) [
        $keyId => $v2Record(base64_encode($public), [$illegal]),
    ], 'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))]];
    duo_check(
        str_contains(
            (string) $refusal(static fn() => $readAuthorities($document)),
            "neither an exact adapter name nor a '<vendor>-*' namespace"
        ),
        "the pattern '$illegal' is refused: a wildcard that binds no vendor prefix, or binds one loosely, is "
        . 'exactly the widening § v3.7 forbids, so the grammar cannot express it at all'
    );
}

echo "\n== change (d): the v2 document attests to itself ==\n";

duo_check(
    str_contains(
        (string) $refusal(static fn() => $readAuthorities([
            'format' => 'duo-adapter-authorities/v2',
            'keys' => (object) [$keyId => $v2Record(base64_encode($public))],
        ])),
        'must contain exactly format, keys, signature'
    ),
    'an UNSIGNED v2 document is refused by the envelope key set — the signature is not an optional adornment'
);
$tampered = $signedGood;
$tamperedKeys = (array) $tampered['keys'];
$tamperedKeys[$keyId]['adapter_names'] = ['acme-forms', 'acme-invoices'];
$tampered['keys'] = (object) $tamperedKeys;
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($tampered)), 'does not verify under key'),
    'a TAMPERED v2 document refuses: widening a scope list in a signed registry needs the signing key, which '
    . 'is the whole property enrollment needs from this envelope'
);
$appended = $signedGood;
$appendedKeys = (array) $appended['keys'];
$appendedKeys[$otherId] = $v2Record(base64_encode($otherPublic), ['zeta-*']);
$appended['keys'] = (object) $appendedKeys;
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($appended)), 'does not verify under key'),
    'and APPENDING a key to a signed registry refuses for the same reason — the signature covers `{format, '
    . 'keys}` whole, not each record separately'
);
$foreign = $signedGood;
$foreign['signature'] = (object) ['key_id' => $otherId, 'value' => $signedGood['signature']['value']];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($foreign)), 'is signed by a key it carries itself'),
    'a signature naming a key the document does not carry refuses by name, rather than by failing to verify '
    . 'against nothing'
);
$revokedSigner = $sign(
    [$keyId => $v2Record(base64_encode($public)), $otherId => $v2Record(base64_encode($otherPublic), ['zeta-catalog'])],
    $keyId,
    $secret
);
$revokedSignerKeys = (array) $revokedSigner['keys'];
$revokedSignerKeys[$keyId]['status'] = 'revoked';
$revokedSigner['keys'] = (object) $revokedSignerKeys;
$revokedSignerRefusal = (string) $refusal(static fn() => $readAuthorities($revokedSigner));
duo_check(
    str_contains($revokedSignerRefusal, 'does not verify under key')
        || str_contains($revokedSignerRefusal, 'revoked key'),
    'and a revoked signer cannot attest the registry it sits in (' . $revokedSignerRefusal . ')'
);
$twoKeys = $sign(
    [$keyId => $v2Record(base64_encode($public)), $otherId => $v2Record(base64_encode($otherPublic), ['zeta-catalog'])],
    $otherId,
    $otherSecret
);
duo_check_same(
    [$keyId, $otherId],
    array_keys($readAuthorities($twoKeys)),
    'any key the document carries may sign it: the registry attests to itself, and which member did so is in '
    . 'the document rather than in a convention'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $readAuthorities([
            'format' => 'duo-adapter-authorities/v2',
            'keys' => (object) [],
            'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))],
        ])),
        'an empty registry stays duo-adapter-authorities/v1'
    ),
    'an EMPTY v2 registry is unrepresentable, which is exactly what lets the shipped empty file stay v1 and '
    . 'byte-identical through the flag day'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::signAuthorities(
            Canon::encode((object) ['format' => 'duo-adapter-authorities/v1', 'keys' => (object) [
                $keyId => $v1Record(base64_encode($public)),
            ]]),
            $keyId,
            base64_encode($secret)
        )),
        'documents have no signed envelope at all'
    ),
    'and the producer refuses to sign a v1 document: a signature on a document nothing checks it against would '
    . 'be decoration'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::signAuthorities(
            Canon::encode((object) [
                'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
                'keys' => (object) [$keyId => $v2Record(base64_encode($public))],
            ]),
            $keyId,
            base64_encode($otherSecret)
        )),
        'private key does not match authority key'
    ),
    'the producer refuses a private key that is not the record\'s own, before a single signature byte exists'
);

// The reviewer-reachable path, end to end: `authorities-sign` rewrites the
// document in place, and the SHIPPED READER then accepts what it wrote. A
// producer nothing reads back is a producer nobody has checked.
$cliPath = $root . '/cli-authorities.json';
file_put_contents($cliPath, Canon::encode((object) [
    'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
    'keys' => (object) [$keyId => $v2Record(base64_encode($public))],
]));
$cliSecret = $root . '/cli-secret.key';
file_put_contents($cliSecret, base64_encode($secret) . "\n");
chmod($cliSecret, 0600);
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open([
    PHP_BINARY,
    dirname(__DIR__, 4) . '/scripts/adapter-certification.php',
    'authorities-sign',
    '--authorities=' . $cliPath,
    '--authority=' . $keyId,
    '--secret-key-file=' . $cliSecret,
], $descriptors, $pipes);
$cliOut = '';
$cliErr = '';
$cliExit = 1;
if (is_resource($process)) {
    $cliOut = (string) stream_get_contents($pipes[1]);
    $cliErr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $cliExit = proc_close($process);
}
duo_check(
    $cliExit === 0 && str_contains($cliOut, AdapterCertification::AUTHORITIES_FORMAT_V2),
    'the reviewer verb `authorities-sign` signs a v2 registry in place and reports what it signed ('
    . trim($cliErr === '' ? $cliOut : $cliErr) . ')'
);
$method = new ReflectionMethod(AdapterCertification::class, 'authorityKeys');
duo_check_same(
    [$keyId],
    array_keys((array) $method->invoke(null, $cliPath, 'adapter certification authorities')),
    'and the SHIPPED READER accepts exactly what that verb wrote — the producer and the verifier agree on '
    . 'the bytes, which is the only way a signature means anything'
);

echo "\n== the version member, and the two statements of one grammar ==\n";

$mixedV1 = ['format' => 'duo-adapter-authorities/v1', 'keys' => (object) [
    $keyId => $v2Record(base64_encode($public)),
]];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($mixedV1)), 'the envelope and the record must state one grammar'),
    'a v2 RECORD inside a v1 envelope refuses: the two must state one grammar, so a windowed record can never '
    . 'sit in front of a reader that would not enforce its window'
);
$mixedV2 = ['format' => 'duo-adapter-authorities/v2', 'keys' => (object) [
    $keyId => $v1Record(base64_encode($public)),
], 'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))]];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($mixedV2)), 'the envelope and the record must state one grammar'),
    'and a v1 record inside a v2 envelope refuses the same way — the disagreement is the finding, in either '
    . 'direction'
);
$futureVersion = $v2Record(base64_encode($public));
$futureVersion['record_version'] = 3;
$futureDocument = ['format' => 'duo-adapter-authorities/v2', 'keys' => (object) [$keyId => $futureVersion],
    'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))]];
$futureRefusal = (string) $refusal(static fn() => $readAuthorities($futureDocument));
duo_check(
    str_contains($futureRefusal, 'declares authority record_version 3')
        && str_contains($futureRefusal, 'refused by version'),
    'a record version this agent does not implement is refused BY VERSION rather than read as a v2 record with '
    . 'unexpected members — the property § v3.6 adds to the statement, here in the record (' . $futureRefusal . ')'
);
$stringVersion = $v2Record(base64_encode($public));
$stringVersion['record_version'] = '2';
$stringDocument = ['format' => 'duo-adapter-authorities/v2', 'keys' => (object) [$keyId => $stringVersion],
    'signature' => (object) ['key_id' => $keyId, 'value' => base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES))]];
duo_check(
    str_contains((string) $refusal(static fn() => $readAuthorities($stringDocument)), 'refused by version'),
    "and `record_version: '2'` is not `record_version: 2`: a JSON string is a different wire value and is not "
    . 'coerced into agreement'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $readAuthorities([
            'format' => 'duo-adapter-authorities/v9',
            'keys' => (object) [$keyId => $v1Record(base64_encode($public))],
        ])),
        'have an unsupported or malformed root'
    ),
    'a third authorities format is still refused with today\'s sentence: v2 opened one named door, not a '
    . 'version-tolerant reader'
);

echo "\n== what the identity-only binding must NOT have laundered ==\n";

// Change (e) narrows what a certificate binds to the key IDENTITY. The whole
// safety of that narrowing rests on `authorityIdentity()` dropping the two
// scope lists AND NOTHING ELSE, so the members a v2 record adds are inside the
// binding: a moved window or a moved version is an identity move, not a scope
// move, and must still invalidate a certificate signed over the old one.
$identity = new ReflectionMethod(AdapterCertification::class, 'authorityIdentity');
$base = $v2Record(base64_encode($public), ['acme-*']);
$baseIdentity = Canon::encode($identity->invoke(null, $base));
$scopeMoved = $base;
$scopeMoved['adapter_names'] = ['acme-*', 'acme-forms'];
$scopeMoved['trust_tiers'] = ['declarative_manifest', 'plugin_provider'];
duo_check_same(
    $baseIdentity,
    Canon::encode($identity->invoke(null, $scopeMoved)),
    'growing the two SCOPE LISTS leaves the bound identity byte-identical — this is the enrollment case, and '
    . 'the reason both roots can now bind identity'
);
foreach (['not_after', 'not_before', 'record_version', 'public_key', 'status', 'algorithm', 'scope'] as $member) {
    $moved = $base;
    $moved[$member] = $member === 'record_version' ? 3 : 'moved-' . $member;
    duo_check(
        Canon::encode($identity->invoke(null, $moved)) !== $baseIdentity,
        "moving `$member` MOVES the bound identity: the narrowing dropped the scope lists and nothing else, so "
        . 'a v2 window or version cannot be edited under a signature that covered the old one'
    );
}

duo_check_summary('authority record v2');
