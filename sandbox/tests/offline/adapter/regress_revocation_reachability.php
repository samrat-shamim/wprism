<?php
/**
 * Typed revocation and the out-of-band channel (spec § v3.8, WP-4.9).
 *
 * THE GAP THIS CLOSES, EXACTLY
 * ----------------------------
 * Before this rider, revocation was one `status` word per key in a file that
 * ships inside the agent archive (`Adopt.php:149` tars `agent manifests
 * recovery`), so revocation latency was agent-release latency. On the FROZEN
 * path it was worse than slow — it was unreachable: verifyCertificate() re-binds
 * a site-rooted certificate to the authority record its own SIGNATURE covers,
 * because frozen verification reopens no mutable site file, so nothing an
 * operator wrote to `adapters/authorities.json` ever reached an already-frozen
 * snapshot. That was reasoned as correct while a site root held only the
 * OPERATOR'S OWN key — "the operator's own root, revoked by the operator, in a
 * document the same operator produced" — a premise that fails the moment
 * federation-by-copy (§ v3.8's depth-1 delegation) puts a VENDOR key there.
 *
 * THE TWO HALVES, AND WHY BOTH ARE ASSERTED HERE
 * ---------------------------------------------
 * 1. CLOSED: a revoked vendor key held in a site root stops verifying on the
 *    frozen path, because the typed revocation record is agent-owned,
 *    platform-signed, and therefore readable exactly where the site's own
 *    document is not.
 * 2. PRESERVED: the operator-own-key asymmetry itself does not move. Flipping
 *    `status` in `adapters/authorities.json` still stops every live scan and
 *    still does not reach a frozen snapshot. A rider that "fixed" that too would
 *    have taken a custody decision T6 §2 explicitly defers, silently.
 * Every refusal from the new channel STATES the distinction, because an operator
 * looking at a refused snapshot has to be able to tell which of the two
 * mechanisms answered without reading this file.
 *
 * WHAT "NOT THE AGENT RELEASE" MEANS, CHECKABLY
 * --------------------------------------------
 * Asserted rather than claimed: the shipped tree carries no revocation document
 * at all; its absence means "nothing is revoked" and not "the reader is broken";
 * the document is SELF-AUTHENTICATING, so the same bytes produce the same
 * verdict from any path a courier put them on; and a file that exists but does
 * not verify REFUSES rather than reading as an absent one — which is what stops
 * deleting a signature from being the cheapest way to un-revoke a burnt key.
 *
 * @see sandbox/tests/offline/adapter/regress_authority_delegation.php — the
 *      other half of WP-4.9: the depth-1 grant this channel exists to revoke.
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
$root = $repo . '/sandbox/tmp/revocation-reachability-' . getmypid();

function rev_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        rev_remove_tree($item->getPathname());
    }
    rmdir($path);
}

function rev_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        fwrite(STDERR, "cannot create $to\n");
        exit(1);
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir("$from/$entry")) {
            rev_copy_tree("$from/$entry", "$to/$entry");
        } elseif (!copy("$from/$entry", "$to/$entry")) {
            fwrite(STDERR, "cannot copy $from/$entry\n");
            exit(1);
        }
    }
}

/** @return array{exit:int,stdout:string,stderr:string} */
function rev_run(array $command): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'cannot start ' . implode(' ', $command)];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

rev_remove_tree($root);
if (!mkdir($root . '/site/adapters', 0777, true)) {
    fwrite(STDERR, "cannot create scratch root $root\n");
    exit(1);
}
register_shutdown_function(static fn() => rev_remove_tree($root));

$library = $root . '/library';
$site = $root . '/site';
rev_copy_tree($repo . '/manifests', $library);

$refusal = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

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
$operatorKey = $pair('O');
$platformId = 'platform-' . $platformKey['short'];
$vendorId = 'acme-' . $vendorKey['short'];
$operatorId = 'acme-ops';

$revocationsPath = $library . '/capabilities/adapter-revocations.json';
$authoritiesPath = $library . '/capabilities/adapter-authorities.json';
$siteAuthoritiesPath = $site . '/adapters/authorities.json';

/** Install a signed revocation document, or remove it when handed no entries. */
$installRevocations = static function (
    array $entries,
    string $signerId,
    string $signerSecret,
    string $issuedAt = '2026-05-01T00:00:00Z'
) use ($revocationsPath): void {
    if ($entries === []) {
        if (is_file($revocationsPath)) {
            unlink($revocationsPath);
        }
        return;
    }
    file_put_contents($revocationsPath, AdapterCertification::signRevocations(
        Canon::encode((object) [
            'format' => AdapterCertification::REVOCATION_FORMAT,
            'issued_at' => $issuedAt,
            'revocations' => $entries,
            'version' => 1,
        ]),
        $signerId,
        base64_encode($signerSecret)
    ));
};

$entry = static fn(
    string $keyId,
    string $fingerprint,
    string $reason,
    string $effectiveAt = '2026-05-01T00:00:00Z'
): array => [
    'effective_at' => $effectiveAt,
    'fingerprint' => $fingerprint,
    'key_id' => $keyId,
    'reason' => $reason,
];

$clock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
$setClock = static function (?string $instant) use ($clock): void {
    $clock->setValue(null, $instant === null ? null : static fn(): int => (int) strtotime($instant));
};
$setClock('2026-06-01T00:00:00Z');

echo "\n== the channel ships ABSENT, and absence is an answer ==\n";

duo_check(
    !file_exists($repo . '/manifests/capabilities/adapter-revocations.json'),
    'no revocation document ships: the flag day moves no byte, because this channel\'s shipped state is "the '
    . 'file is not there"'
);
duo_check_same(
    ['format' => 'duo-adapter-authorities/v1', 'keys' => []],
    (array) json_decode((string) file_get_contents($repo . '/manifests/capabilities/adapter-authorities.json'), true),
    'and the shipped trust root is still the empty v1 registry, so no key exists anywhere that this channel '
    . 'could revoke in the field'
);
duo_check(
    AdapterCertification::SIGNATURE_DOMAIN_REVOCATION !== AdapterCertification::SIGNATURE_DOMAIN_DELEGATION
        && AdapterCertification::SIGNATURE_DOMAIN_REVOCATION !== AdapterCertification::SIGNATURE_DOMAIN_AUTHORITIES
        && str_ends_with(AdapterCertification::SIGNATURE_DOMAIN_REVOCATION, "\0"),
    'a revocation is its own domain-separated statement kind: its subject set is larger than a delegation\'s '
    . '(it can name a key nobody delegated), so one domain would make a grant replayable as a revocation'
);

echo "\n== the fixture: an operator's own key, and a delegated vendor key ==\n";

$manifest = [
    'name' => 'acme-shop',
    'option_autoload' => 'preserve',
    'options' => ['acme_shop_layout' => ['class' => 'authored']],
    'plugin' => 'acme-shop/acme-shop.php',
    'post_types' => [],
    'spec_version' => DUO_SPEC_VERSION,
    'version_range' => ['max' => '3.0.0', 'min' => '1.0.0'],
];
file_put_contents($site . '/adapters/acme-shop.json', Canon::encode($manifest));
file_put_contents($site . '/site.duo.json', Canon::encode((object) [
    'manifests' => [['name' => 'acme-shop', 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]));
// The operator's OWN root: a plain v1 record, minted by the operator, under a
// name the v1 grammar allows and the v2 fingerprint rule would not. That is the
// population the frozen-path asymmetry was reasoned about.
$writeSiteAuthorities = static function (string $status) use ($siteAuthoritiesPath, $operatorId, $operatorKey): void {
    file_put_contents($siteAuthoritiesPath, Canon::encode((object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT,
        'keys' => (object) [
            $operatorId => [
                'adapter_names' => ['acme-shop'],
                'algorithm' => 'ed25519',
                'public_key' => $operatorKey['encoded'],
                'scope' => 'site_adapter_certification',
                'status' => $status,
                'trust_tiers' => ['declarative_manifest'],
            ],
        ],
    ]));
};
$writeSiteAuthorities('trusted');
$operatorSecretPath = $root . '/operator.key';
file_put_contents($operatorSecretPath, base64_encode($operatorKey['secret']) . "\n");
chmod($operatorSecretPath, 0600);

$signSite = static fn(string $authorityId, string $secretPath): array => rev_run([
    'env',
    'DUO_MANIFESTS_DIR=' . $library,
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'sign-site',
    '--manifest-dir=' . $library,
    '--repo=' . $site,
    '--name=acme-shop',
    '--authority=' . $authorityId,
    '--secret-key-file=' . $secretPath,
    '--reason=grammar verified by the site operator; not exercised',
]);
$operatorSign = $signSite($operatorId, $operatorSecretPath);
duo_check(
    $operatorSign['exit'] === 0,
    'the operator certifies their own adapter under their own root (' . trim($operatorSign['stderr']) . ')'
);
$operatorVerified = AdapterCertification::verifyFile($library, $site, 'acme-shop', $manifest, $site . '/adapters/certifications/acme-shop.json');
$operatorEnvelope = $operatorVerified['envelope'];
duo_check_same(
    'certified',
    $operatorVerified['claim']['status'] ?? null,
    'and it verifies live, under trust root site'
);

echo "\n== PRESERVED: the operator-own-key asymmetry does not move ==\n";

$writeSiteAuthorities('revoked');
$liveStatusFlip = (string) $refusal(static fn() => AdapterCertification::verifyFile(
    $library,
    $site,
    'acme-shop',
    $manifest,
    $site . '/adapters/certifications/acme-shop.json'
));
duo_check(
    str_contains($liveStatusFlip, "site adapter 'acme-shop' certification authority/key/fingerprint/trust root")
        && str_contains($liveStatusFlip, 'does not match the current site authority record'),
    'flipping `status` in the operator\'s OWN adapters/authorities.json still stops every LIVE scan with '
    . 'today\'s sentence, unchanged — and it is the IDENTITY binding that answers, because § v3.7 dropped only '
    . 'the two scope lists from what a certificate binds, so a moved `status` is an identity move ('
    . $liveStatusFlip . ')'
);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $operatorEnvelope)['claim']['status'] ?? null,
    'and it still does NOT reach an already-frozen snapshot — the asymmetry T6 §2 defers is preserved exactly, '
    . 'because closing it would claim a custody property this profile does not have'
);
$writeSiteAuthorities('trusted');

echo "\n== CLOSED: a revoked VENDOR key stops verifying on the frozen path ==\n";

// The vendor key reaches this site root the way § v3.8 says it does: a platform
// key delegates to it, and the site installs the delegation. That is
// federation-by-copy, and it is the population the old reasoning did not cover.
file_put_contents($authoritiesPath, AdapterCertification::signAuthorities(
    Canon::encode((object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
        'keys' => (object) [
            $platformId => [
                'adapter_names' => ['acme-*'],
                'algorithm' => 'ed25519',
                'not_after' => '2028-01-01T00:00:00Z',
                'not_before' => '2026-01-01T00:00:00Z',
                'public_key' => $platformKey['encoded'],
                'record_version' => 2,
                'scope' => 'site_adapter_certification',
                'status' => 'trusted',
                'trust_tiers' => ['declarative_manifest'],
            ],
        ],
    ]),
    $platformId,
    base64_encode($platformKey['secret'])
));
$delegationStatement = [
    'adapter_names' => ['acme-shop'],
    'delegate' => [
        'algorithm' => 'ed25519',
        'key_id' => $vendorId,
        'public_key' => $vendorKey['encoded'],
    ],
    'delegator' => [
        'fingerprint' => $platformKey['fingerprint'],
        'key_id' => $platformId,
        'trust_root' => 'platform',
    ],
    'format' => AdapterCertification::DELEGATION_FORMAT,
    'not_after' => '2027-01-01T00:00:00Z',
    'not_before' => '2026-02-01T00:00:00Z',
    'trust_tiers' => ['declarative_manifest'],
    'version' => 1,
];
file_put_contents($site . '/adapters/delegations.json', Canon::encode((object) [
    'delegations' => (object) [
        $vendorId => Canon::decode(AdapterCertification::signDelegation(
            Canon::encode($delegationStatement),
            $platformId,
            base64_encode($platformKey['secret'])
        )),
    ],
    'format' => AdapterCertification::DELEGATIONS_FORMAT,
]));
$vendorSecretPath = $root . '/vendor.key';
file_put_contents($vendorSecretPath, base64_encode($vendorKey['secret']) . "\n");
chmod($vendorSecretPath, 0600);
$vendorSign = $signSite($vendorId, $vendorSecretPath);
duo_check(
    $vendorSign['exit'] === 0,
    'a DELEGATED vendor key certifies an adapter in this repository (' . trim($vendorSign['stderr']) . ')'
);
$vendorVerified = AdapterCertification::verifyFile($library, $site, 'acme-shop', $manifest, $site . '/adapters/certifications/acme-shop.json');
$vendorEnvelope = $vendorVerified['envelope'];
duo_check_same(
    'site',
    $vendorVerified['claim']['certification']['trust_root'] ?? null,
    'and the certificate it mints is site-rooted — a vendor key in a site root, which is precisely the shape the '
    . 'frozen path used to reason about as "the operator\'s own"'
);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)['claim']['status'] ?? null,
    'it verifies frozen too, before anything is revoked — the control this whole section is measured against'
);

$installRevocations(
    [$entry($vendorId, $vendorKey['fingerprint'], 'vendor signing key disclosed in incident 2026-05-01')],
    $platformId,
    $platformKey['secret']
);
$frozenRefusal = (string) $refusal(
    static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)
);
duo_check(
    str_contains($frozenRefusal, "authority key '$vendorId' is revoked by the platform-signed revocation record at capabilities/adapter-revocations.json")
        && str_contains($frozenRefusal, 'effective 2026-05-01T00:00:00Z')
        && str_contains($frozenRefusal, 'reason: vendor signing key disclosed in incident 2026-05-01'),
    'THE GAP IS CLOSED: a revoked vendor key held in a site root stops verifying ON THE FROZEN PATH, and the '
    . 'refusal carries the instant and the reason an operator needs (' . $frozenRefusal . ')'
);
duo_check(
    str_contains($frozenRefusal, 'This channel reaches the frozen path, which a status flip in the operator\'s own adapters/authorities.json deliberately does not'),
    'and the REASON TEXT STATES THE DISTINCTION: an operator reading a refused snapshot can tell which of the '
    . 'two revocation mechanisms answered without reading the source'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFile(
            $library,
            $site,
            'acme-shop',
            $manifest,
            $site . '/adapters/certifications/acme-shop.json'
        )),
        'is revoked by the platform-signed revocation record'
    ),
    'the same document reaches the LIVE path through the same seat — one channel, one sentence, both paths'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => $signSite($vendorId, $vendorSecretPath)['exit'] === 0
            ? throw new RuntimeException('signing succeeded')
            : null),
        'signing succeeded'
    ) === false,
    'and a revoked key cannot SIGN a new certificate either: the revocation is consulted where every key is '
    . 'selected, so signing, live verification and the frozen path answer alike'
);

echo "\n== the delegator's revocation reaches every delegate ==\n";

$installRevocations(
    [$entry($platformId, $platformKey['fingerprint'], 'platform enrollment key rotated out of service')],
    $platformId,
    $platformKey['secret']
);
$delegatorRefusal = (string) $refusal(static fn() => AdapterCertification::verifyFile(
    $library,
    $site,
    'acme-shop',
    $manifest,
    $site . '/adapters/certifications/acme-shop.json'
));
duo_check(
    str_contains($delegatorRefusal, "authority key '$platformId' is revoked by the platform-signed revocation record"),
    'revoking the DELEGATOR through the typed channel invalidates its delegates on the live path, without '
    . 'waiting for an agent release to move a status word (' . $delegatorRefusal . ')'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'is revoked'
    ) === false,
    'while the FROZEN path is unaffected by the delegator\'s revocation alone: it holds the delegate\'s own '
    . 'record and no delegation document, so revoking a delegate is what reaches a snapshot — a fact stated '
    . 'here rather than left to be discovered'
);
$installRevocations([], $platformId, $platformKey['secret']);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)['claim']['status'] ?? null,
    'removing the document restores the verdict: ABSENCE means "nothing is revoked", which is the shipped state '
    . 'of every site and the reason this channel costs nothing until it is used'
);

echo "\n== a document that exists and does not verify REFUSES ==\n";

$installRevocations(
    [$entry($vendorId, $vendorKey['fingerprint'], 'vendor signing key disclosed')],
    $platformId,
    $platformKey['secret']
);
$signed = Canon::decode((string) file_get_contents($revocationsPath));

// The cheapest un-revocation an attacker can attempt is deleting rows, and it
// must not read as "fewer keys are revoked now". Two doors are closed, and the
// order of the checks is why both are needed: the SIGNATURE answers an edit
// nobody re-signed, and the grammar answers a document its own signer emptied.
$tampered = $signed;
$tamperedStatement = (array) $tampered['statement'];
$tamperedStatement['revocations'] = [];
$tampered['statement'] = (object) $tamperedStatement;
file_put_contents($revocationsPath, Canon::encode($tampered));
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        "do not verify under key '$platformId'"
    ),
    'EMPTYING the revocations list without the signing key refuses under the signature — the signature covers '
    . 'the whole statement, so a row cannot be dropped out of it'
);
file_put_contents($revocationsPath, AdapterCertification::signRevocations(
    Canon::encode((object) [
        'format' => AdapterCertification::REVOCATION_FORMAT,
        'issued_at' => '2026-05-01T00:00:00Z',
        'revocations' => [],
        'version' => 1,
    ]),
    $platformId,
    base64_encode($platformKey['secret'])
));
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'must carry a non-empty revocations list'
    ),
    'and a correctly-SIGNED empty document is refused by the grammar too: a document that revokes nothing has '
    . 'no honest reason to exist, and "installed but vacuous" must not be a state an operator can reach by '
    . 'accident'
);
$dropped = $signed;
$droppedStatement = (array) $dropped['statement'];
$droppedStatement['revocations'] = [
    ['effective_at' => '2026-05-01T00:00:00Z', 'fingerprint' => str_repeat('a', 64),
        'key_id' => 'someone-else', 'reason' => 'unrelated'],
];
$dropped['statement'] = (object) $droppedStatement;
file_put_contents($revocationsPath, Canon::encode($dropped));
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        "do not verify under key '$platformId'"
    ),
    'and REPLACING an entry refuses under the signature: without this, deleting the row that names your key '
    . 'would be the cheapest way to un-revoke it'
);
$unsigned = $signed;
unset($unsigned['signature']);
file_put_contents($revocationsPath, Canon::encode($unsigned));
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'must contain exactly format, signature, statement'
    ),
    'an UNSIGNED document is refused by the closed envelope key set — the signature is not an optional '
    . 'adornment, and stripping it is not a downgrade path'
);
file_put_contents($revocationsPath, "not json at all\n");
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'is not valid JSON'
    ),
    'an unreadable document REFUSES rather than reading as an absent one: laundering unreadable revocation '
    . 'bytes into "no revocations" is exactly the failure this channel exists to prevent'
);

echo "\n== who may revoke, and what an entry binds ==\n";

// The operator's own key is not a platform key, so it cannot mint a revocation
// anyone honours. This is what stops the channel becoming a way for whoever can
// write the manifest directory to disable arbitrary identities.
file_put_contents($revocationsPath, AdapterCertification::signRevocations(
    Canon::encode((object) [
        'format' => AdapterCertification::REVOCATION_FORMAT,
        'issued_at' => '2026-05-01T00:00:00Z',
        'revocations' => [$entry($vendorId, $vendorKey['fingerprint'], 'not my key to burn')],
        'version' => 1,
    ]),
    $operatorId,
    base64_encode($operatorKey['secret'])
));
$foreignSigner = (string) $refusal(
    static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)
);
duo_check(
    str_contains($foreignSigner, "are signed by key '$operatorId', which is not installed in capabilities/adapter-authorities.json")
        && str_contains($foreignSigner, 'a site key cannot make one'),
    'a revocation signed by a key the SHIPPED root does not carry refuses by name: revocation is a '
    . 'platform-rooted statement (' . $foreignSigner . ')'
);
$installRevocations(
    [$entry($vendorId, $operatorKey['fingerprint'], 'names the wrong key material')],
    $platformId,
    $platformKey['secret']
);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)['claim']['status'] ?? null,
    'an entry binds the key FINGERPRINT, not the id: naming the vendor\'s id over somebody else\'s key material '
    . 'revokes nothing, because an id can be re-minted over new material and material cannot'
);
$installRevocations(
    [$entry('an-unrelated-label', $vendorKey['fingerprint'], 'the label is not the binding')],
    $platformId,
    $platformKey['secret']
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'is revoked by the platform-signed revocation record'
    ),
    'and the reverse holds: the right fingerprint under an unrelated label still revokes, because the material '
    . 'is the identity and the label is a courtesy'
);
$installRevocations(
    [$entry($vendorId, $vendorKey['fingerprint'], 'scheduled for the end of the quarter', '2026-09-01T00:00:00Z')],
    $platformId,
    $platformKey['secret']
);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)['claim']['status'] ?? null,
    'a SCHEDULED revocation grants its subject until its own instant, judged against the same named host clock '
    . '(`$now ?? time()`) every window in this file reads'
);
$setClock('2026-09-01T00:00:00Z');
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'effective 2026-09-01T00:00:00Z'
    ),
    'and it takes effect AT that instant — the same `>=` comparison and the same no-skew posture as every other '
    . 'time judgement this file makes'
);
$setClock('2026-06-01T00:00:00Z');

$grammarCases = [
    'an entry with no reason' => [
        ['effective_at' => '2026-05-01T00:00:00Z', 'fingerprint' => $vendorKey['fingerprint'],
            'key_id' => $vendorId, 'reason' => '  '],
        'must state a non-empty reason',
    ],
    'an entry whose fingerprint is not a sha256' => [
        ['effective_at' => '2026-05-01T00:00:00Z', 'fingerprint' => 'not-a-digest',
            'key_id' => $vendorId, 'reason' => 'burnt'],
        'must bind a sha256 key fingerprint',
    ],
    'an entry with a loose instant' => [
        ['effective_at' => '2026-13-01T00:00:00Z', 'fingerprint' => $vendorKey['fingerprint'],
            'key_id' => $vendorId, 'reason' => 'burnt'],
        'one unambiguous ISO-8601 UTC instant',
    ],
];
foreach ($grammarCases as $label => [$row, $needle]) {
    $installRevocations([$row], $platformId, $platformKey['secret']);
    duo_check(
        str_contains(
            (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
            $needle
        ),
        "$label is refused by the grammar, inside the signature that covers it"
    );
}
$installRevocations(
    [
        $entry($vendorId, $vendorKey['fingerprint'], 'first'),
        $entry($vendorId, $vendorKey['fingerprint'], 'second', '2027-01-01T00:00:00Z'),
    ],
    $platformId,
    $platformKey['secret']
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'revoke the same key fingerprint twice'
    ),
    'two instants for one identity is a disagreement, not a list — refused rather than resolved by order'
);

echo "\n== the version member, and the channel's independence from its courier ==\n";

$versioned = Canon::decode(AdapterCertification::signRevocations(
    Canon::encode((object) [
        'format' => AdapterCertification::REVOCATION_FORMAT,
        'issued_at' => '2026-05-01T00:00:00Z',
        'revocations' => [$entry($vendorId, $vendorKey['fingerprint'], 'burnt')],
        'version' => 1,
    ]),
    $platformId,
    base64_encode($platformKey['secret'])
));
$futureStatement = (array) $versioned['statement'];
$futureStatement['version'] = 2;
$future = $versioned;
$future['statement'] = (object) $futureStatement;
file_put_contents($revocationsPath, Canon::encode($future));
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)),
        'refused by version, never read as a v1 revocation with unexpected members'
    ),
    'a revocation version this agent does not implement is refused BY VERSION — the property that stops a '
    . 'tamperer downgrading a document by editing one integer'
);
// SELF-AUTHENTICATING, and this is what "not the agent release" means in
// checkable terms: the identical bytes produce the identical verdict from a
// second library that never saw the courier. Nothing about the transport is
// part of the trust decision.
$installRevocations(
    [$entry($vendorId, $vendorKey['fingerprint'], 'vendor signing key disclosed')],
    $platformId,
    $platformKey['secret']
);
$courierBytes = (string) file_get_contents($revocationsPath);
$second = $root . '/second-library';
rev_copy_tree($library, $second);
$installRevocations([], $platformId, $platformKey['secret']);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, 'acme-shop', $manifest, $vendorEnvelope)['claim']['status'] ?? null,
    'the first library, with the document removed, verifies again'
);
duo_check(
    str_contains(
        (string) $refusal(static fn() => AdapterCertification::verifyFrozen($second, 'acme-shop', $manifest, $vendorEnvelope)),
        'is revoked by the platform-signed revocation record'
    ),
    'while a SECOND library holding the identical bytes refuses identically: the trust comes from the '
    . 'signature, not from the channel that carried it — which is the whole claim behind "a distribution '
    . 'channel that is NOT the agent release"'
);
duo_check_same(
    $courierBytes,
    (string) file_get_contents($second . '/capabilities/adapter-revocations.json'),
    'and the bytes a courier moves are the whole document: there is nothing to re-derive, re-sign or install '
    . 'beyond copying one file'
);

$setClock(null);
duo_check_summary('revocation reachability');
