<?php
/**
 * Depth-1 delegated authorities (spec/repo-format.md § v3.8, WP-4.9).
 *
 * THE REFUSAL MATRIX IS THE ACCEPTANCE CRITERION
 * ----------------------------------------------
 * This is a certificate authority, and certificate authorities are where
 * security defects live. The codebase deliberately had no chain, no
 * cross-signing and no path validation before this rider, so the risk of adding
 * one is not hypothetical — it is the whole reason § v3.8 specifies the feature
 * as a list of things that must REFUSE rather than as a thing that must work.
 * This suite is written the same way round: the happy path is four assertions
 * near the top, and everything after it is a refusal that must fire BY NAME,
 * because "it failed somehow" and "it refused for the reason we designed" are
 * different results and only the second one is a security property.
 *
 * WHAT ONE LEVEL MEANS, MECHANICALLY
 * ----------------------------------
 * A platform key — reviewed by this project, shipped in
 * `manifests/capabilities/adapter-authorities.json` — signs a statement granting
 * a namespace pattern, a tier set and a validity window to a vendor key. The
 * vendor key then certifies adapters inside that grant, in ONE site repository,
 * under trust root `site` (register row R-13's third-value channel is
 * deliberately NOT spent: a delegated key is a documented provenance for a key
 * in the existing site root, not a new custody model). A delegate may not
 * delegate, and that is refused by name rather than by failing to resolve.
 *
 * THE FLAG-DAY INVARIANT THIS SUITE IS MEASURED AGAINST
 * ----------------------------------------------------
 * `DUO_SPEC_VERSION` stays 2 and no shipped byte moves. Everything below reads
 * structures that do not exist anywhere today — `adapters/delegations.json` and
 * `capabilities/adapter-revocations.json` — so an agent that meets neither
 * behaves exactly as it does now. Both facts are re-checked on every run: the
 * shipped trust root is still the empty v1 registry, and the shipped manifest
 * library carries no revocation document at all.
 *
 * @see sandbox/tests/offline/adapter/regress_revocation_reachability.php — the
 *      other half of WP-4.9: the typed revocation channel and the frozen path.
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
$root = $repo . '/sandbox/tmp/authority-delegation-' . getmypid();

function del_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        del_remove_tree($item->getPathname());
    }
    rmdir($path);
}

del_remove_tree($root);
if (!mkdir($root . '/library/capabilities', 0777, true) || !mkdir($root . '/site/adapters', 0777, true)) {
    fwrite(STDERR, "cannot create scratch root $root\n");
    exit(1);
}
register_shutdown_function(static fn() => del_remove_tree($root));

$library = $root . '/library';
$site = $root . '/site';

$refusal = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

/**
 * Deterministic keypairs. The v2 fingerprint grammar has to be computable in
 * the suite too, because a delegated id that does not derive from its own key
 * material is one of the refusals below.
 */
$pair = static function (string $seed): array {
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
$platformKey = $pair('P');
$vendorKey = $pair('V');
$otherVendorKey = $pair('W');
$operatorKey = $pair('O');
$platformId = 'platform-' . $platformKey['short'];
$vendorId = 'acme-' . $vendorKey['short'];
$otherVendorId = 'zeta-' . $otherVendorKey['short'];
$operatorId = 'site-' . $operatorKey['short'];

/** A v2 authority record: the six shipped members, plus version and window. */
$v2Record = static fn(
    string $encodedPublic,
    array $names,
    array $tiers,
    string $notBefore = '2026-01-01T00:00:00Z',
    string $notAfter = '2028-01-01T00:00:00Z',
    string $status = 'trusted'
): array => [
    'adapter_names' => $names,
    'algorithm' => 'ed25519',
    'not_after' => $notAfter,
    'not_before' => $notBefore,
    'public_key' => $encodedPublic,
    'record_version' => 2,
    'scope' => 'site_adapter_certification',
    'status' => $status,
    'trust_tiers' => $tiers,
];

/** Write a signed v2 platform trust root into the scratch manifest library. */
$installPlatformRoot = static function (array $keys, string $signerId, string $signerSecret) use ($library): void {
    file_put_contents(
        $library . '/capabilities/adapter-authorities.json',
        AdapterCertification::signAuthorities(
            Canon::encode((object) [
                'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
                'keys' => (object) $keys,
            ]),
            $signerId,
            base64_encode($signerSecret)
        )
    );
};

/**
 * The same document, signed through the engine's own envelope framer instead of
 * through `signAuthorities()`.
 *
 * Needed for exactly one population: a PLATFORM record holding a `<vendor>-*`
 * namespace that reaches a shipped adapter name. The producer holds every record
 * to the site-root rule (it writes bytes without knowing which file they land
 * in, and the strict answer is the safe one for a guard rail), while the READER
 * exempts the reviewed root — see `regress_authority_record_v2.php`, which pins
 * both halves. Framed by reflection rather than re-spelled here, so a suite that
 * copied the framing would stop testing the framing.
 */
$framer = new ReflectionMethod(AdapterCertification::class, 'authoritiesSignatureBytes');
$installPlatformRootRaw = static function (array $keys, string $signerId, string $signerSecret) use (
    $library,
    $framer
): void {
    $document = (object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
        'keys' => (object) $keys,
    ];
    file_put_contents($library . '/capabilities/adapter-authorities.json', Canon::encode((object) [
        'format' => $document->format,
        'keys' => $document->keys,
        'signature' => (object) [
            'key_id' => $signerId,
            'value' => base64_encode(sodium_crypto_sign_detached(
                (string) $framer->invoke(null, $document->format, json_decode(Canon::encode($document->keys))),
                $signerSecret
            )),
        ],
    ]));
};

/** One delegation statement, in the exact member order the closed set names. */
$statement = static fn(
    array $delegate,
    string $delegateId,
    array $delegator,
    string $delegatorId,
    array $names,
    array $tiers,
    string $notBefore = '2026-02-01T00:00:00Z',
    string $notAfter = '2027-01-01T00:00:00Z',
    string $trustRoot = 'platform'
): array => [
    'adapter_names' => $names,
    'delegate' => [
        'algorithm' => 'ed25519',
        'key_id' => $delegateId,
        'public_key' => $delegate['encoded'],
    ],
    'delegator' => [
        'fingerprint' => $delegator['fingerprint'],
        'key_id' => $delegatorId,
        'trust_root' => $trustRoot,
    ],
    'format' => AdapterCertification::DELEGATION_FORMAT,
    'not_after' => $notAfter,
    'not_before' => $notBefore,
    'trust_tiers' => $tiers,
    'version' => 1,
];

/** Sign one statement through the SHIPPED producer, never a local re-spelling. */
$sign = static fn(array $stmt, string $signerId, string $signerSecret): array => Canon::decode(
    AdapterCertification::signDelegation(Canon::encode($stmt), $signerId, base64_encode($signerSecret))
);

/**
 * The FORGER: a delegator's key over a statement the shipped producer refuses
 * to assemble.
 *
 * Needed because the producer is a guard rail and the verifier is the security
 * boundary — an attacker holding a delegator key writes bytes directly, so a
 * refusal that only the producer enforces is not a refusal at all. It signs
 * through the engine's own framer (`delegationSignatureBytes()`, by reflection)
 * rather than re-spelling `domain . Canon::encode(statement)` here: a suite that
 * copied the framing would stop testing the framing.
 */
$framer = new ReflectionMethod(AdapterCertification::class, 'delegationSignatureBytes');
$forge = static function (array $stmt, string $signerId, string $signerSecret) use ($framer): array {
    $typed = json_decode(Canon::encode($stmt), false, 512, JSON_THROW_ON_ERROR);

    return [
        'signature' => [
            'key_id' => $signerId,
            'value' => base64_encode(sodium_crypto_sign_detached(
                (string) $framer->invoke(null, $typed),
                $signerSecret
            )),
        ],
        'statement' => $stmt,
    ];
};

/** Install a `{delegations, format}` document in the scratch site repository. */
$installDelegations = static function (array $delegations) use ($site): void {
    file_put_contents($site . '/adapters/delegations.json', Canon::encode((object) [
        'delegations' => (object) $delegations,
        'format' => AdapterCertification::DELEGATIONS_FORMAT,
    ]));
};

/**
 * `authority()` is the ONE selector every consumer reaches — `sign()`,
 * `sign_site()` and `verifyCertificate()`'s live branch all resolve a key
 * through it — so driving it is driving the product path rather than a
 * re-implementation of it. Reflection rather than a public shim, for the reason
 * the class already states about its other test seams: the shipped surface stays
 * what a site can call.
 */
$authority = new ReflectionMethod(AdapterCertification::class, 'authority');
$resolve = static fn(string $id): array => (array) $authority->invoke(null, $library, $id, $site);

$scope = new ReflectionMethod(AdapterCertification::class, 'assertAuthorityScope');
$clock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
$setClock = static function (?string $instant) use ($clock): void {
    $clock->setValue(null, $instant === null ? null : static fn(): int => (int) strtotime($instant));
};

echo "\n== the shipped preconditions, re-checked on every run ==\n";

duo_check_same(
    ['format' => 'duo-adapter-authorities/v1', 'keys' => []],
    (array) json_decode((string) file_get_contents($repo . '/manifests/capabilities/adapter-authorities.json'), true),
    'manifests/capabilities/adapter-authorities.json is still the EMPTY v1 registry — no platform key exists to '
    . 'delegate FROM, so nothing this suite builds can be reached by any site in the field'
);
duo_check(
    !file_exists($repo . '/manifests/capabilities/adapter-revocations.json'),
    'and the shipped manifest library carries NO revocation document: the out-of-band channel ships absent, '
    . 'which is what makes its absence the meaning "nothing is revoked" rather than a default'
);
duo_check_same(
    2,
    (int) DUO_SPEC_VERSION,
    'DUO_SPEC_VERSION is still 2: § v3.8 gates on structures that do not exist rather than on the wire version, '
    . 'so the flip stays WP-4.12\'s alone'
);
duo_check(
    AdapterCertification::SIGNATURE_DOMAIN_DELEGATION === "duo-adapter-authority-delegation-signature/v1\0"
        && AdapterCertification::SIGNATURE_DOMAIN_DELEGATION !== AdapterCertification::SIGNATURE_DOMAIN
        && str_ends_with(AdapterCertification::SIGNATURE_DOMAIN_DELEGATION, "\0"),
    'a delegation is its OWN domain-separated statement kind, NUL-terminated like every other (register rows '
    . 'R-01/R-04) — never a new arm inside the certification verifier'
);

echo "\n== the happy path: one level, and what it actually grants ==\n";

$installPlatformRoot(
    [$platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider'])],
    $platformId,
    $platformKey['secret']
);
$goodStatement = $statement(
    $vendorKey,
    $vendorId,
    $platformKey,
    $platformId,
    ['acme-forms', 'acme-invoices-*'],
    ['declarative_manifest']
);
$goodDelegation = $sign($goodStatement, $platformId, $platformKey['secret']);
$installDelegations([$vendorId => $goodDelegation]);

$setClock('2026-06-01T00:00:00Z');
[$record, $resolvedId, , $trustRoot] = $resolve($vendorId);
duo_check_same($vendorId, $resolvedId, 'a valid depth-1 delegation resolves the vendor key by its own id');
duo_check_same(
    AdapterCertification::TRUST_ROOT_SITE,
    $trustRoot,
    'and it resolves under trust root `site`, not a third word: a delegated key certifies ONE repository, which '
    . 'is what `site` already means in the signed statement (R-13\'s third-value channel stays unspent)'
);
duo_check_same(
    [
        'adapter_names' => ['acme-forms', 'acme-invoices-*'],
        'algorithm' => 'ed25519',
        'not_after' => '2027-01-01T00:00:00Z',
        'not_before' => '2026-02-01T00:00:00Z',
        'public_key' => $vendorKey['encoded'],
        'record_version' => 2,
        'scope' => 'site_adapter_certification',
        'status' => 'trusted',
        'trust_tiers' => ['declarative_manifest'],
    ],
    $record,
    'the derived record is a plain `record_version: 2` authority record — the grant, the delegate\'s own key '
    . 'material and the delegation\'s window, and nothing a v2 record could not already carry'
);
duo_check_same(
    null,
    $refusal(static fn() => $scope->invoke(null, $record, $vendorId, 'acme-forms', 'declarative_manifest')),
    'and the derived record certifies inside its grant, through the SAME scope check every installed record is '
    . 'judged by — a delegation mints no second reading of the scope rules'
);
duo_check_same(
    null,
    $refusal(static fn() => $scope->invoke(null, $record, $vendorId, 'acme-invoices-pro', 'declarative_manifest')),
    'including inside the narrowed namespace it was granted'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $scope->invoke(null, $record, $vendorId, 'acme-catalog', 'declarative_manifest')),
        "is not scoped to site adapter 'acme-catalog'"
    ),
    'and NOT outside it, even though its delegator holds `acme-*`: the grant is the delegate\'s scope, never the '
    . 'delegator\'s'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $scope->invoke(null, $record, $vendorId, 'acme-forms', 'plugin_provider')),
        "is not scoped to derived trust tier 'plugin_provider'"
    ),
    'the tier set narrows the same way: a tier the delegator holds and the delegation did not grant is refused'
);

echo "\n== refusal 1: verification chains EXACTLY one level ==\n";

$chainStatement = $statement(
    $otherVendorKey,
    $otherVendorId,
    $vendorKey,
    $vendorId,
    ['acme-forms'],
    ['declarative_manifest']
);
// Signed by the DELEGATE's own key, which is what a real second hop would look
// like: the delegate holds its own private key and can produce a syntactically
// perfect statement. Nothing but the depth rule stops it.
$chainDelegation = $sign($chainStatement, $vendorId, $vendorKey['secret']);
$installDelegations([$vendorId => $goodDelegation, $otherVendorId => $chainDelegation]);
$chainRefusal = (string) $refusal(static fn() => $resolve($otherVendorId));
duo_check(
    str_contains($chainRefusal, "is delegated by '$vendorId', which is itself a delegate")
        && str_contains($chainRefusal, 'verification chains exactly 1 level and a delegate may not delegate'),
    'a two-level chain refuses BY NAME — the finding is "a delegate delegated", never the honest-but-useless '
    . '"that delegator is not in the platform root" (' . $chainRefusal . ')'
);
duo_check(
    !str_contains($chainRefusal, 'is not installed in'),
    'and the depth test runs BEFORE the platform lookup, which is what keeps the two findings distinguishable: '
    . 'resolving first would report a missing key and hide the chain'
);
duo_check(
    (string) $refusal(static fn() => $resolve($vendorId)) !== null,
    'the whole document refuses while it carries a chain, rather than silently keeping the level-1 half: a '
    . 'delegation document with a forbidden edge in it is not partly trustworthy'
);

echo "\n== refusal 2: a delegation may only NARROW ==\n";

$installDelegations([$vendorId => $goodDelegation]);
$wideningCases = [
    'a namespace outside the delegator\'s' => [
        ['zeta-forms'], ['declarative_manifest'],
        "grants 'zeta-forms', which its delegator '$platformId' does not hold",
    ],
    'a namespace PATTERN broader than the delegator\'s' => [
        ['acm-*'], ['declarative_manifest'],
        "grants 'acm-*', which its delegator '$platformId' does not hold",
    ],
    'the bare vendor name the delegator\'s own pattern excludes' => [
        ['acme'], ['declarative_manifest'],
        "grants 'acme', which its delegator '$platformId' does not hold",
    ],
    'a tier the delegator does not hold' => [
        ['acme-forms'], ['native_action'],
        "grants trust tier 'native_action', which its delegator '$platformId' does not hold",
    ],
];
foreach ($wideningCases as $label => [$names, $tiers, $needle]) {
    $widened = $sign(
        $statement($vendorKey, $vendorId, $platformKey, $platformId, $names, $tiers),
        $platformId,
        $platformKey['secret']
    );
    $installDelegations([$vendorId => $widened]);
    duo_check(
        str_contains((string) $refusal(static fn() => $resolve($vendorId)), $needle),
        "$label is refused by name, and the message says which grant exceeded which delegator — a delegation "
        . 'may only narrow the scope it was given, never widen it'
    );
}
// The narrowing rule is a GRAMMAR fact, not a review rule, and this is the case
// that proves the pattern half of it is not merely a string prefix test: an
// EXACT grant can never cover a pattern, because a pattern reaches names that
// do not exist yet and an exact name never does.
$installPlatformRoot(
    [$platformId => $v2Record($platformKey['encoded'], ['acme-forms'], ['declarative_manifest'])],
    $platformId,
    $platformKey['secret']
);
$patternFromExact = $sign(
    $statement($vendorKey, $vendorId, $platformKey, $platformId, ['acme-forms-*'], ['declarative_manifest']),
    $platformId,
    $platformKey['secret']
);
$installDelegations([$vendorId => $patternFromExact]);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $resolve($vendorId)),
        "grants 'acme-forms-*', which its delegator '$platformId' does not hold"
    ),
    'a delegator holding the EXACT name `acme-forms` cannot delegate the namespace `acme-forms-*`: the pattern '
    . 'reaches every future name under that prefix, which is a widening dressed as a narrowing'
);
$installPlatformRoot(
    [$platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider'])],
    $platformId,
    $platformKey['secret']
);
$narrowedPattern = $sign(
    $statement($vendorKey, $vendorId, $platformKey, $platformId, ['acme-forms-*'], ['declarative_manifest']),
    $platformId,
    $platformKey['secret']
);
$installDelegations([$vendorId => $narrowedPattern]);
duo_check_same(
    ['acme-forms-*'],
    $resolve($vendorId)[0]['adapter_names'],
    'while `acme-*` DOES narrow to `acme-forms-*` — the pattern rule admits a longer prefix and nothing else'
);

echo "\n== M4: a grant may not sweep up a name the shipped library reserves ==\n";

// THE ESCALATION THE G2 REVIEW FOUND, driven end to end. 10 of the 16
// grandfathered names sit inside a legal `<vendor>-*` namespace, so enrolling a
// vendor with its own products' namespace handed it the SHIPPED adapter of the
// same name — and out of tree that name is the reviewed OVERRIDE, which inherits
// the shipped adapter's interpreter, regenerator and provider grants
// (`AdapterSources::assert_out_of_tree_contract()`). The delegated record is
// synthesized and then put through the shipped record grammar, which is exactly
// where the rule bites, so no second reading of it exists here either.
$installPlatformRootRaw(
    [$platformId => $v2Record($platformKey['encoded'], ['ninja-*'], ['declarative_manifest'])],
    $platformId,
    $platformKey['secret']
);
$vendorReserved = $sign(
    $statement($vendorKey, $vendorId, $platformKey, $platformId, ['ninja-*'], ['declarative_manifest']),
    $platformId,
    $platformKey['secret']
);
$installDelegations([$vendorId => $vendorReserved]);
$reservedRefusal = (string) $refusal(static fn() => $resolve($vendorId));
duo_check(
    str_contains($reservedRefusal, "entry 'ninja-*' covers 'ninja-forms'")
        && str_contains($reservedRefusal, 'adapter names the shipped library reserves')
        && str_contains($reservedRefusal, 'inherits that adapter\'s interpreter, regenerator and'),
    'a delegation granting `ninja-*` is refused because that namespace reaches the shipped `ninja-forms`, and '
    . 'the refusal names the covered member and the privilege an override of it would inherit ('
    . $reservedRefusal . ')'
);
// The platform root itself carried the same pattern above and loaded: the
// exemption is the reviewed library's, and it does not travel with the grant.
$installPlatformRootRaw(
    [$platformId => $v2Record($platformKey['encoded'], ['ninja-*'], ['declarative_manifest'])],
    $platformId,
    $platformKey['secret']
);
duo_check_same(
    null,
    $refusal(static fn() => (new ReflectionMethod(AdapterCertification::class, 'authorityKeys'))
        ->invoke(null, $library . '/capabilities/adapter-authorities.json', 'adapter certification authorities', true)),
    'while the PLATFORM record holding `ninja-*` loads: the exemption belongs to the reviewed root and is not '
    . 'inherited by what that root delegates — which is the whole point, because the delegate is the party the '
    . 'grant was never reviewed for'
);
$installPlatformRoot(
    [$platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider'])],
    $platformId,
    $platformKey['secret']
);
$installDelegations([$vendorId => $goodDelegation]);

echo "\n== M7: the signature is checked BEFORE the statement is read ==\n";

// THE DEFECT (G2 review, M7). The narrowing checks ran ahead of the signature,
// so a TAMPERED delegation reported the narrowing rule it happened to break —
// an attacker-chosen sentence, and one that reads as a policy problem rather
// than as forgery. The fix moves the signature block ahead of them and moves no
// refusal byte; this case is discriminating about the order rather than merely
// about the outcome, on the `:361` model: the widening sentence must be ABSENT.
$widenedForgery = $forge(
    $statement($vendorKey, $vendorId, $platformKey, $platformId, ['zeta-forms'], ['declarative_manifest']),
    $platformId,
    $otherVendorKey['secret']
);
$installDelegations([$vendorId => $widenedForgery]);
$orderRefusal = (string) $refusal(static fn() => $resolve($vendorId));
duo_check(
    str_contains($orderRefusal, "does not verify under delegator '$platformId'")
        && !str_contains($orderRefusal, "grants 'zeta-forms'"),
    'a statement that BOTH widens its grant and was signed by the wrong key answers with the SIGNATURE: the '
    . 'crypto runs first, so nothing an unverified statement claims is reported as a finding about policy ('
    . $orderRefusal . ')'
);
// And the narrowing rules are still the verifier's, not the producer's: the
// same widening under the RIGHT key still refuses by name, which is what stops
// this reordering from being a quiet removal.
$installDelegations([$vendorId => $sign(
    $statement($vendorKey, $vendorId, $platformKey, $platformId, ['zeta-forms'], ['declarative_manifest']),
    $platformId,
    $platformKey['secret']
)]);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $resolve($vendorId)),
        "grants 'zeta-forms', which its delegator '$platformId' does not hold"
    ),
    'while a correctly-signed widening still refuses by name — the ordering moved, the rules did not'
);
$installDelegations([$vendorId => $goodDelegation]);

echo "\n== refusal 3: time is a scope, and it narrows the same way ==\n";

$installDelegations([$vendorId => $goodDelegation]);
$outsideWindow = $sign(
    $statement(
        $vendorKey,
        $vendorId,
        $platformKey,
        $platformId,
        ['acme-forms'],
        ['declarative_manifest'],
        '2026-02-01T00:00:00Z',
        '2029-01-01T00:00:00Z'
    ),
    $platformId,
    $platformKey['secret']
);
$installDelegations([$vendorId => $outsideWindow]);
$windowRefusal = (string) $refusal(static fn() => $resolve($vendorId));
duo_check(
    str_contains($windowRefusal, "outside its delegator '$platformId' window 2026-01-01T00:00:00Z/2028-01-01T00:00:00Z")
        && str_contains($windowRefusal, 'time is a scope like any other and narrows the same way'),
    'a grant that outlives its delegator\'s own window is refused, naming both windows (' . $windowRefusal . ')'
);
// EXPIRY ITSELF is judged where every window in this file is judged — the scope
// seat — and not at document-read time. That is the § v3.7 rule restated: a
// record whose window has closed must still parse, still report, and still be
// distinguishable from a malformed one.
$installDelegations([$vendorId => $goodDelegation]);
$live = $resolve($vendorId)[0];
$setClock('2027-01-01T00:00:00Z');
$expired = (string) $refusal(static fn() => $scope->invoke(null, $live, $vendorId, 'acme-forms', 'declarative_manifest'));
duo_check(
    str_contains($expired, "authority key '$vendorId' expired at 2027-01-01T00:00:00Z")
        && str_contains($expired, 'no skew allowance in either direction'),
    'an EXPIRED delegation refuses at the delegation\'s own not_after, through the shipped window check, naming '
    . 'the host clock it was judged against (' . $expired . ')'
);
$setClock('2026-01-15T00:00:00Z');
duo_check(
    str_contains(
        (string) $refusal(static fn() => $scope->invoke(null, $live, $vendorId, 'acme-forms', 'declarative_manifest')),
        'implausible clock'
    ),
    'and a clock before the grant\'s own not_before refuses as an implausible clock rather than resurrecting it '
    . '— the ordering § v3.7 fixed applies to a derived record exactly as it does to an installed one'
);
$setClock('2026-06-01T00:00:00Z');

echo "\n== refusal 4: a site key cannot delegate, said twice ==\n";

file_put_contents($site . '/adapters/authorities.json', Canon::encode((object) [
    'format' => AdapterCertification::AUTHORITIES_FORMAT,
    'keys' => (object) [
        $operatorId => [
            'adapter_names' => ['acme-forms'],
            'algorithm' => 'ed25519',
            'public_key' => $operatorKey['encoded'],
            'scope' => 'site_adapter_certification',
            'status' => 'trusted',
            'trust_tiers' => ['declarative_manifest'],
        ],
    ],
]));
$siteRooted = $sign(
    $statement(
        $vendorKey,
        $vendorId,
        $operatorKey,
        $operatorId,
        ['acme-forms'],
        ['declarative_manifest'],
        '2026-02-01T00:00:00Z',
        '2027-01-01T00:00:00Z',
        'site'
    ),
    $operatorId,
    $operatorKey['secret']
);
$installDelegations([$vendorId => $siteRooted]);
$siteRootRefusal = (string) $refusal(static fn() => $resolve($vendorId));
duo_check(
    str_contains($siteRootRefusal, "names trust root 'site'")
        && str_contains($siteRootRefusal, 'a delegation is made by a platform key')
        && str_contains($siteRootRefusal, 'certifies its own repository and delegates nothing'),
    'a delegation whose signed statement CLAIMS a site root refuses on the word, before any lookup — the claim '
    . 'is named rather than the miss (' . $siteRootRefusal . ')'
);
// The second half of the same rule, and it is the one that actually holds: an
// operator who writes `platform` over their own key id gets the LOOKUP refusal,
// because the delegator is resolved in the shipped root and nowhere else.
$mislabelled = $sign(
    $statement($vendorKey, $vendorId, $operatorKey, $operatorId, ['acme-forms'], ['declarative_manifest']),
    $operatorId,
    $operatorKey['secret']
);
$installDelegations([$vendorId => $mislabelled]);
$lookupRefusal = (string) $refusal(static fn() => $resolve($vendorId));
duo_check(
    str_contains($lookupRefusal, "is delegated by '$operatorId', which is not installed in capabilities/adapter-authorities.json")
        && str_contains($lookupRefusal, 'never in the site\'s own'),
    'and writing `platform` over a site key changes nothing: the delegator is looked up ONLY in the shipped, '
    . 'reviewed root, so an operator cannot bootstrap standing they were never given (' . $lookupRefusal . ')'
);
unlink($site . '/adapters/authorities.json');

echo "\n== refusal 5: a revoked delegator invalidates its delegates ==\n";

$installDelegations([$vendorId => $goodDelegation]);
$installPlatformRoot(
    [
        $platformId => $v2Record(
            $platformKey['encoded'],
            ['acme-*'],
            ['declarative_manifest', 'plugin_provider'],
            '2026-01-01T00:00:00Z',
            '2028-01-01T00:00:00Z',
            'revoked'
        ),
        $otherVendorId => $v2Record($otherVendorKey['encoded'], ['zeta-*'], ['declarative_manifest']),
    ],
    $otherVendorId,
    $otherVendorKey['secret']
);
$revokedDelegator = (string) $refusal(static fn() => $resolve($vendorId));
duo_check(
    str_contains($revokedDelegator, "authority key '$platformId' is revoked and cannot certify adapters")
        && str_contains($revokedDelegator, "the delegation it made to '$vendorId' grants nothing"),
    'revoking the DELEGATOR in the shipped root invalidates its delegates at once, with no site file touched — '
    . 'the property that makes depth-1 delegation safe enough to ship (' . $revokedDelegator . ')'
);
$installPlatformRoot(
    [$platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider'])],
    $platformId,
    $platformKey['secret']
);
duo_check_same(
    $vendorId,
    $resolve($vendorId)[1],
    'and restoring the delegator restores its delegates: the delegator is read LIVE on every resolution, never '
    . 'copied into the grant at signing time'
);

echo "\n== refusal 6: the grammar, member by member ==\n";

$grammar = [
    'a statement version this agent does not implement' => [
        static function (array $stmt): array {
            $stmt['version'] = 2;
            return $stmt;
        },
        'refused by version, never read as a v1 delegation with unexpected members',
    ],
    'a statement format this agent does not implement' => [
        static function (array $stmt): array {
            $stmt['format'] = 'duo-adapter-authority-delegation/v9';
            return $stmt;
        },
        'the envelope\'s format is outside every signature and proves nothing',
    ],
    'a delegate algorithm that is not ed25519' => [
        static function (array $stmt): array {
            $stmt['delegate']['algorithm'] = 'ed448';
            return $stmt;
        },
        'delegate must declare algorithm ed25519',
    ],
    'a delegator fingerprint that is not the installed key\'s' => [
        static function (array $stmt): array {
            $stmt['delegator']['fingerprint'] = str_repeat('0', 64);
            return $stmt;
        },
        'the key under that id moved',
    ],
];
foreach ($grammar as $label => [$mutate, $needle]) {
    // FORGED, not produced: an attacker holding the delegator's key writes
    // whatever bytes they like, so every one of these must be refused by the
    // VERIFIER. That the producer also refuses them is a separate, weaker fact,
    // asserted beside each one so a regression in either is visible.
    $mutatedStatement = $mutate($goodStatement);
    $installDelegations([$vendorId => $forge($mutatedStatement, $platformId, $platformKey['secret'])]);
    duo_check(
        str_contains((string) $refusal(static fn() => $resolve($vendorId)), $needle),
        "$label is refused BY THE VERIFIER, inside the signature that covers it"
    );
}
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::signDelegation(
            Canon::encode(['version' => 2] + $goodStatement),
            $platformId,
            base64_encode($platformKey['secret'])
        )),
        'must declare format ' . AdapterCertification::DELEGATION_FORMAT . ' and version 1'
    ),
    'and the producer will not assemble a statement at a version it does not implement — the guard rail in '
    . 'front of the boundary, never instead of it'
);
// The id/material rules, which are v3.7's and are REUSED rather than restated:
// a delegated id is a v2 identity and must derive from its own key material.
$squatted = $goodStatement;
$squatted['delegate']['key_id'] = 'wordpress-security-team';
$installDelegations(['wordpress-security-team' => $sign($squatted, $platformId, $platformKey['secret'])]);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $resolve('wordpress-security-team')),
        'does not derive from its own key material'
    ),
    'a SQUATTED delegate id is refused by § v3.7\'s fingerprint grammar, reused here rather than re-implemented '
    . '— a delegation cannot mint an identity an authorities document could not'
);
$mismatched = $sign($goodStatement, $platformId, $platformKey['secret']);
$installDelegations([$otherVendorId => $mismatched]);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $resolve($otherVendorId)),
        'the map key and the delegated identity are one value, not two'
    ),
    'and installing a delegation under a DIFFERENT map key than its signed statement names refuses: the file '
    . 'position is not an identity a signature never saw'
);

echo "\n== refusal 7: an installed record always outranks a granted one ==\n";

$installPlatformRoot(
    [
        $platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider']),
        $vendorId => $v2Record($vendorKey['encoded'], ['acme-forms'], ['declarative_manifest']),
    ],
    $platformId,
    $platformKey['secret']
);
$installDelegations([$vendorId => $goodDelegation]);
[, , , $shippedWins] = $resolve($vendorId);
duo_check_same(
    AdapterCertification::TRUST_ROOT_PLATFORM,
    $shippedWins,
    'a key the SHIPPED root carries resolves there and never through a delegation claiming the same id — the '
    . 'reviewed record is a decision, the grant is someone else\'s'
);
$delegationsPath = $site . '/adapters/delegations.json';
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::assert_site_delegations($library, $site)),
        'is reviewed and shipped by this agent, so a delegation cannot claim it'
    ),
    'and the whole-document validator refuses that collision BY NAME, so the ordering above is belt and braces '
    . 'rather than the only line holding it'
);
$installPlatformRoot(
    [$platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider'])],
    $platformId,
    $platformKey['secret']
);
file_put_contents($site . '/adapters/authorities.json', Canon::encode((object) [
    'format' => AdapterCertification::AUTHORITIES_FORMAT,
    'keys' => (object) [
        $vendorId => [
            'adapter_names' => ['acme-forms'],
            'algorithm' => 'ed25519',
            'public_key' => $vendorKey['encoded'],
            'scope' => 'site_adapter_certification',
            'status' => 'trusted',
            'trust_tiers' => ['declarative_manifest'],
        ],
    ],
]));
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::assert_site_delegations($library, $site)),
        'one identity has one record, never a written one and a granted one that could disagree'
    ),
    'the same refusal on the SITE side: an id the operator already wrote down cannot also be delegated, because '
    . 'two records for one identity is a disagreement nothing arbitrates'
);
unlink($site . '/adapters/authorities.json');

echo "\n== the document envelope, and the tamper property it buys ==\n";

$installDelegations([$vendorId => $goodDelegation]);
$tampered = Canon::decode((string) file_get_contents($delegationsPath));
$tamperedDelegations = (array) $tampered['delegations'];
$tamperedDelegations[$vendorId]['statement']['adapter_names'] = ['acme-*'];
$tampered['delegations'] = (object) $tamperedDelegations;
file_put_contents($delegationsPath, Canon::encode($tampered));
duo_check(
    str_contains(
        (string) $refusal(static fn() => $resolve($vendorId)),
        "does not verify under delegator '$platformId'"
    ),
    'widening a grant inside an installed delegation refuses: the signature covers the whole statement, so the '
    . 'narrowing rules are enforced by the delegator\'s key and not only by this verifier'
);
$installDelegations([$vendorId => $goodDelegation]);
$foreign = Canon::decode((string) file_get_contents($delegationsPath));
$foreignDelegations = (array) $foreign['delegations'];
$foreignDelegations[$vendorId]['signature'] = (object) [
    'key_id' => $otherVendorId,
    'value' => $foreignDelegations[$vendorId]['signature']['key_id'] === $platformId
        ? $foreignDelegations[$vendorId]['signature']['value']
        : '',
];
$foreign['delegations'] = (object) $foreignDelegations;
file_put_contents($delegationsPath, Canon::encode($foreign));
duo_check(
    str_contains(
        (string) $refusal(static fn() => $resolve($vendorId)),
        'the signer and the delegator are one key'
    ),
    'a signature naming a key other than the delegator refuses by name, rather than by failing to verify against '
    . 'a key nobody claimed'
);
duo_check(
    str_contains(
        (string) $refusal(static function () use ($library, $site): void {
            file_put_contents($site . '/adapters/delegations.json', Canon::encode((object) [
                'delegations' => (object) [],
                'format' => AdapterCertification::DELEGATIONS_FORMAT,
            ]));
            AdapterCertification::assert_site_delegations($library, $site);
        }),
        'carry no delegations'
    ),
    'an EMPTY delegation document refuses rather than reading as "no delegations": inert authority bytes an '
    . 'operator believes in are the failure mode this source refuses everywhere else'
);
$installDelegations([$vendorId => $goodDelegation]);
duo_check(
    str_contains(
        (string) $refusal(static function () use ($delegationsPath, $library, $site): void {
            $document = Canon::decode((string) file_get_contents($delegationsPath));
            $document['format'] = 'duo-adapter-authority-delegations/v9';
            file_put_contents($delegationsPath, Canon::encode($document));
            AdapterCertification::assert_site_delegations($library, $site);
        }),
        'have an unsupported or malformed root'
    ),
    'a delegations format this agent does not implement is refused with the same sentence the authorities '
    . 'reader uses — one named door, not a version-tolerant reader'
);

echo "\n== the producer, read back through the shipped reader ==\n";

$installDelegations([$vendorId => $goodDelegation]);
$statementPath = $root . '/statement.json';
file_put_contents($statementPath, Canon::encode($goodStatement));
$secretPath = $root . '/platform-secret.key';
file_put_contents($secretPath, base64_encode($platformKey['secret']) . "\n");
chmod($secretPath, 0600);
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open([
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'delegation-sign',
    '--statement=' . $statementPath,
    '--authority=' . $platformId,
    '--secret-key-file=' . $secretPath,
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
    $cliExit === 0 && $cliOut !== '',
    'the reviewer verb `delegation-sign` signs a statement and prints the installable object ('
    . trim($cliErr === '' ? substr($cliOut, 0, 120) : $cliErr) . ')'
);
$installDelegations([$vendorId => Canon::decode($cliOut)]);
duo_check_same(
    $vendorId,
    $resolve($vendorId)[1],
    'and the SHIPPED READER accepts exactly what that verb wrote — the producer and the verifier agree on the '
    . 'bytes, which is the only way a signature means anything'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::signDelegation(
            Canon::encode($goodStatement),
            $otherVendorId,
            base64_encode($otherVendorKey['secret'])
        )),
        'is not the delegator the statement names'
    ),
    'the producer refuses to sign a statement it is not the delegator of, before a private key is touched'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::signDelegation(
            Canon::encode($goodStatement),
            $platformId,
            base64_encode($otherVendorKey['secret'])
        )),
        'private key does not match the delegator fingerprint'
    ),
    'and it refuses a private key that is not the delegator\'s own, so an unverifiable delegation can never be '
    . 'produced by the one producer that ships'
);

$setClock(null);
duo_check_summary('authority delegation');
