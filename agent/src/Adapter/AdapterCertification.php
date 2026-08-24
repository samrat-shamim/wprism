<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/../Policy/ManifestDispositions.php';

/**
 * External, signed certification for a data-only site adapter.
 *
 * A site adapter is deliberately not allowed to carry its own public key,
 * disposition, registry entry, or executable adapter package.  This class is
 * the narrow bridge for a reviewed authority to make one such adapter a
 * certified claim without importing that claim into the agent's shipped
 * disposition registry.
 *
 * The live entry point reads the exact raw adapter and certificate files.
 * The frozen entry point accepts only the opaque canonical certificate
 * envelope returned by the live entry point: it may avoid reopening mutable
 * site bytes, but still verifies the Ed25519 signature against CURRENT
 * agent-owned authority roots and re-binds the current authority-record and
 * platform digests.
 */

/**
 * A well-formed companion certificate that binds DIFFERENT bytes than
 * `adapters/<name>.json` carries now — i.e. the adapter was edited after it
 * was certified. This is not an authority anomaly: docs/guides/adapter-
 * authoring.md states an edit "moves the digest and the claim drops back to
 * uncertified". AdapterSources::scan() catches this specific signal and
 * resolves the adapter as uncertified support (the same state a companion-
 * absent site adapter reaches), so `duo assess`/`duo release` see a clean
 * uncertified row instead of an unclassified hard failure, and `duo adapter
 * certify --pin` can re-sign over the new bytes. Every genuine anomaly
 * (malformed/misplaced companion, wrong authority, bad signature) stays a
 * hard \RuntimeException — a superseded adapter never keeps its certified
 * grants, so routing only this case to uncertified changes no trust outcome.
 */
final class SupersededSiteAdapterCertificate extends \RuntimeException {
}

/**
 * A correctly-signed companion, under a currently-trusted authority, binding
 * exactly the bytes `adapters/<name>.json` carries now — whose statement names
 * an agent platform boundary this agent no longer publishes. THE AGENT MOVED,
 * NOT THE ADAPTER: `manifests/capabilities/platform.json` is agent-owned and
 * changes on an ordinary upgrade (a new `compatibility.wordpress.last_verified`
 * moves its bytes without moving one manifest), and verifyCertificate()
 * compares it byte for byte (`:1093-1096`).
 *
 * Before this type the comparison threw a bare \RuntimeException, which
 * scan_site_source() could not tell from a forgery, so discover() refused the
 * WHOLE site source (refuse() at SCOPE_SOURCE) and Policy::load() propagated
 * it uncaught (Policy.php:400) — an agent upgrade bricked every command on
 * every site holding a certified adapter, including `duo adapter certify
 * --pin`, the one command that repairs it (AdapterCertify.php:283, 349, 570).
 *
 * Routing it to uncertified support is a WITHDRAWAL OF A CLAIM, never a
 * fallback: the adapter loses its certified grants until it is re-signed
 * against the current boundary, which is strictly more conservative for it and
 * strictly less destructive for the unrelated adapters the same site pins. The
 * line holds because the Ed25519 signature and the authority binding are
 * verified BEFORE this comparison (`:1057-1085`) — a forged or wrongly-rooted
 * companion can never reach this throw site.
 */
final class StalePlatformSiteAdapterCertificate extends \RuntimeException {
}

/**
 * A canonical companion whose root `format` names the certification wire
 * family (`duo-adapter-certification/v<n>`) at a version this agent cannot
 * verify. Same withdrawal, same argument as StalePlatformSiteAdapterCertificate
 * and for the same reason — the wire is agent-owned, so a fleet that upgrades
 * past a certificate's version must degrade the adapter rather than refuse
 * every command on the site.
 *
 * Deliberately NOT a catch-all for a bad `format`: only the exact family at a
 * different integer version reaches here. Any other string, a non-string, a
 * missing key, a non-canonical or unparseable file, and every malformed
 * statement inside a correctly-versioned envelope stay hard whole-source
 * refusals — that predicate is the whole difference between "this agent does
 * not speak this version" and "this file is not a certificate", and widening
 * it by one term would launder a forgery into unsigned support.
 */
final class SupersededWireSiteAdapterCertificate extends \RuntimeException {
}

final class AdapterCertification {
    public const FORMAT = 'duo-adapter-certification/v1';
    // Frozen policy snapshots carry the exact certificate format too; there
    // is no weaker second envelope protocol to accidentally accept.
    public const ENVELOPE_FORMAT = self::FORMAT;
    public const AUTHORITIES_FORMAT = 'duo-adapter-authorities/v1';
    public const BUNDLE_FORMAT = 'duo-site-adapter-certification-bundle/v1';
    public const RATIFICATION_FORMAT = 'duo-manifest-dispositions/v1';

    /** Kept independent from JSON framing so this signature cannot verify elsewhere. */
    public const SIGNATURE_DOMAIN = "duo-site-adapter-certification-signature/v1\0";

    private const AUTHORITIES_RELATIVE = 'capabilities/adapter-authorities.json';
    /**
     * The SITE trust root (round-3 T6 §3.1): the operator's own authority
     * file, in the operator's own repository, travelling with it.
     *
     * It exists because the shipped authorities file is empty and only
     * Anthropic-side review could ever fill it — so before T6 every operator's
     * own adapter was permanently `uncertified` and `Site-certified` was
     * NEVER_EMITTED, despite the product spec defining it as "customer-
     * organization approval ... explicitly not a Duo endorsement". A key here
     * is trusted ONLY for adapters in this repository, which is the whole of
     * its authority: the certificate binds `adapter.path:
     * adapters/<name>.json` inside the signed statement, so a site key cannot
     * reach a shipped manifest or another repository's adapter.
     */
    private const SITE_AUTHORITIES_RELATIVE = AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE;
    private const PLATFORM_RELATIVE = 'capabilities/platform.json';
    private const CERTIFICATE_DIR = 'adapters/certifications';

    /** Reviewed by this project and shipped with the agent. */
    public const TRUST_ROOT_PLATFORM = 'platform';
    /** Held by the customer organization, in its own site repository. */
    public const TRUST_ROOT_SITE = 'site';

    /**
     * The manifest's own top-level vocabulary, partitioned the way a
     * disposition names it, so sign_site() can DERIVE a ratification instead of
     * asking an operator to hand-write one.
     *
     * Three lists rather than two, and the third is the point: a manifest key
     * in none of them makes sign_site() refuse by name. Silently dropping an
     * unrecognised section would mint a certificate that covers less than the
     * adapter declares — the capability claim's `surfaces` list is built from
     * exactly these sections (ManifestDispositions::claim_from_disposition()),
     * so the uncovered surface would simply be blocked later with nothing
     * saying why. A new section kind must stop the signer, not narrow the
     * certificate.
     *
     * ManifestDispositions::validate_entry() independently refuses a named
     * section the manifest does not declare, so these lists can only ever be
     * too narrow, never too wide.
     */
    private const ENTITY_SECTIONS = ['post_types', 'tables', 'taxonomies', 'taxonomy_patterns', 'widgets'];
    private const FIELD_SECTIONS = [
        'block_attrs', 'dynamic_options', 'interpreter', 'menu_fields', 'meta_patterns', 'option_name_refs',
        'option_namespaces', 'option_patterns', 'options', 'post_meta', 'post_meta_patterns', 'shortcode_attrs',
        'term_meta', 'user_meta',
    ];
    /** Manifest keys that declare no branchable state surface of their own. */
    private const NON_SURFACE_KEYS = [
        'actions', 'deletions', 'lifecycle_effects', 'name', 'note', 'notes', 'option_autoload', 'plugin',
        'providers', 'spec_version', 'theme', 'version_range',
    ];

    /**
     * What a grammar-only certificate may claim. `delete` is absent because
     * deletion semantics are exactly what a validator run cannot review, and
     * `render-api`/`test-only` because they are reviewed runtime behaviours.
     */
    private const SITE_OPERATIONS = ['apply', 'capture', 'compile', 'deploy', 'plan', 'recapture'];

    // This has no production setter.  The offline regression reaches it only
    // through Reflection to deterministically simulate an evidence-directory
    // replacement after an asset's descriptor check.
    private static ?\Closure $testVerifiedBundleAssetReadHook = null;

    /**
     * A site adapter's certification path is derived, never declared by the
     * adapter or a site pin.  This prevents one certificate from being reused
     * for a different adapter by merely changing a path claim.
     */
    public static function certificatePath(string $repo, string $name): string {
        $name = self::adapterName($name);
        return rtrim($repo, '/') . '/' . self::CERTIFICATE_DIR . '/' . $name . '.json';
    }

    /** Agent-owned roots are optional; an absent file means no external trust. */
    public static function hasAuthorities(string $manifestDir): bool {
        return is_file(rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE);
    }

    /**
     * Validate the SITE trust root whole, selecting no key.
     *
     * The adapter scan calls this the moment the file EXISTS, before any
     * certificate is paired, because a broken trust root is not one adapter's
     * problem: every certificate in the repository is judged against it, and
     * an operator who wrote an authorities file believes their adapters are
     * certifiable. Reporting that belief as an ordinary `uncertified` row
     * would be the silence this source refuses everywhere else. Absent is
     * fine and means exactly "this repository certifies nothing".
     */
    public static function assert_site_authorities(string $repo): void {
        $root = self::repoRoot($repo, 'site repository');
        self::authorityKeys(
            $root . '/' . self::SITE_AUTHORITIES_RELATIVE,
            'site adapter certification authorities'
        );
    }

    /**
     * Verify one site certificate against the live raw adapter bytes.
     *
     * @return array{disposition:array,claim:array,provenance:array,envelope:array}
     */
    public static function verifyFile(
        string $manifestDir,
        string $repo,
        string $name,
        array $manifest,
        string $certPath
    ): array {
        self::assertSodium();
        $name = self::adapterName($name);
        $root = self::repoRoot($repo, 'site repository');
        $adapterRelative = 'adapters/' . $name . '.json';
        $adapterPath = self::ownedFile($root, $adapterRelative, 'site adapter');
        [$adapterRaw, $adapterTyped, $adapterDecoded] = self::readCanonicalObjectFile($adapterPath, 'site adapter');
        self::assertSiteManifest($name, $adapterDecoded, $manifestDir);
        self::assertSameManifest($name, $manifest, $adapterDecoded);

        $certRelative = self::CERTIFICATE_DIR . '/' . $name . '.json';
        $expectedCert = self::ownedFile($root, $certRelative, 'site adapter certification');
        $provided = realpath($certPath);
        if ($provided === false || !hash_equals($expectedCert, $provided)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification path must be exactly $certRelative"
            );
        }
        [$certificateRaw, $certificateTyped, $certificate] = self::readCanonicalObjectFile(
            $expectedCert,
            'site adapter certification'
        );

        $result = self::verifyCertificate(
            $manifestDir,
            $name,
            $manifest,
            $certificateRaw,
            $certificateTyped,
            $certificate,
            ['raw' => $adapterRaw, 'size' => strlen($adapterRaw)],
            $root
        );
        $result['envelope'] = self::envelope($certificateRaw);
        return $result;
    }

    /**
     * Compatibility spelling for the source/policy integration boundary.
     * Keep verifyFile() as the documented public API while callers following
     * the surrounding source classes' snake-case convention need no shim.
     *
     * @return array{disposition:array,claim:array,provenance:array,envelope:array}
     */
    public static function verify_file(
        string $manifestDir,
        string $repo,
        string $name,
        array $manifest,
        string $certPath
    ): array {
        return self::verifyFile($manifestDir, $repo, $name, $manifest, $certPath);
    }

    /**
     * Re-verify a frozen certificate without reopening mutable site files.
     *
     * The envelope intentionally keeps canonical certificate bytes rather
     * than a decoded PHP array.  JSON's {} versus [] distinction is therefore
     * not erased between live verification, snapshotting, and signature
     * verification.
     *
     * @return array{disposition:array,claim:array,provenance:array,envelope:array}
     */
    public static function verifyFrozen(
        string $manifestDir,
        string $name,
        array $manifest,
        array $envelope
    ): array {
        self::assertSodium();
        $name = self::adapterName($name);
        self::assertSiteManifest($name, $manifest, $manifestDir);
        self::assertExactKeys($envelope, ['certificate_json', 'certificate_sha256', 'format'], 'frozen certification envelope');
        $certificateDigest = $envelope['certificate_sha256'] ?? null;
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || !is_string($certificateDigest) || !self::sha($certificateDigest)) {
            throw new \RuntimeException('duo: frozen site adapter certification envelope is malformed');
        }
        $encoded = $envelope['certificate_json'] ?? null;
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($raw !== false && !self::isCanonicalBase64($encoded, $raw)) {
            $raw = false;
        }
        if ($raw === false || !hash_equals($certificateDigest, hash('sha256', $raw))) {
            throw new \RuntimeException('duo: frozen site adapter certification envelope has corrupt certificate bytes');
        }
        [, $certificateTyped, $certificate] = self::parseCanonicalObject($raw, 'frozen site adapter certification');
        $result = self::verifyCertificate(
            $manifestDir,
            $name,
            $manifest,
            $raw,
            $certificateTyped,
            $certificate,
            null,
            null
        );
        $result['envelope'] = $envelope;
        return $result;
    }

    /** @return array{disposition:array,claim:array,provenance:array,envelope:array} */
    public static function verify_frozen(
        string $manifestDir,
        string $name,
        array $manifest,
        array $envelope
    ): array {
        return self::verifyFrozen($manifestDir, $name, $manifest, $envelope);
    }

    /**
     * Scan every certificate supplied by a site.  A missing certification
     * directory is intentionally fine (the adapter remains uncertified); a
     * present malformed/orphan/misplaced certificate is always fatal.
     *
     * @return array<string, array{disposition:array,claim:array,provenance:array,envelope:array}>
     */
    public static function verifyDirectory(string $manifestDir, string $repo): array {
        $root = self::repoRoot($repo, 'site repository');
        $directory = $root . '/' . self::CERTIFICATE_DIR;
        if (!file_exists($directory)) {
            return [];
        }
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new \RuntimeException(
                'duo: site adapter certifications must be a real directory at ' . self::CERTIFICATE_DIR
            );
        }
        if (!is_readable($directory)) {
            throw new \RuntimeException(
                'duo: site adapter certifications are not readable; authority-bearing bytes cannot be treated as absent'
            );
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException(
                'duo: site adapter certifications could not be enumerated; authority-bearing bytes cannot be treated as absent'
            );
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_link($path) || !is_file($path) || !str_ends_with($entry, '.json')) {
                throw new \RuntimeException(
                    "duo: site adapter certification source contains '$entry' — only direct <name>.json certificate files are allowed"
                );
            }
            $name = self::adapterName(substr($entry, 0, -5));
            $adapterPath = self::ownedFile($root, 'adapters/' . $name . '.json', 'certified site adapter');
            [, , $manifest] = self::readCanonicalObjectFile($adapterPath, 'certified site adapter');
            $out[$name] = self::verifyFile($manifestDir, $root, $name, $manifest, $path);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Build, but do not write, a canonical certificate.  The command-line
     * tool is the mutation boundary.  It verifies the entire imported bundle
     * (assets AND evidence-repository bound inputs) before calling Ed25519.
     */
    public static function sign(
        string $manifestDir,
        string $repo,
        string $name,
        string $bundleInput,
        string $evidenceRepo,
        string $authorityId,
        string $secretKey
    ): string {
        self::assertSodium();
        $name = self::adapterName($name);
        $root = self::repoRoot($repo, 'site repository');
        $adapterPath = self::ownedFile($root, 'adapters/' . $name . '.json', 'site adapter');
        [$adapterRaw, , $manifest] = self::readCanonicalObjectFile($adapterPath, 'site adapter');
        self::assertSiteManifest($name, $manifest, $manifestDir);

        [$authority, $keyId, $authorityDigest, $trustRoot] = self::authority($manifestDir, $authorityId, $root);
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAuthorityScope($authority, $keyId, $name, $tier);

        $secret = self::secretKey($secretKey);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $configured = self::publicKey($authority);
        if (!hash_equals($configured, $public)) {
            throw new \RuntimeException(
                "duo: private key does not match trusted authority key '$keyId'"
            );
        }

        $bundle = self::verifyBundleForImport(
            $bundleInput,
            $evidenceRepo,
            $name,
            $manifest,
            $adapterRaw,
            $trustRoot
        );
        return self::signStatement(
            $manifestDir,
            $name,
            $manifest,
            $adapterRaw,
            $tier,
            [
                'fingerprint' => hash('sha256', $configured),
                'key_id' => $keyId,
                // The exact authority record, inside the signature. It is what
                // makes a SITE-rooted certificate re-verifiable on the frozen
                // path, which reopens no mutable site file and therefore has no
                // adapters/authorities.json to consult (verifyCertificate()
                // states the trust consequence at its own site).
                'record' => $authority,
                'record_sha256' => $authorityDigest,
                'trust_root' => $trustRoot,
            ],
            $bundle['typed'],
            $bundle['ratification_typed'],
            $secret
        );
    }

    /**
     * Certify a site adapter under the operator's OWN trust root, from the one
     * piece of evidence an operator can actually produce: the loader's own
     * grammar verdict, plus their stated reason.
     *
     * WHY THE BUNDLE IS BUILT HERE rather than by the caller. The bundle
     * grammar is this file's (verifyBundleManifest() refuses any deviation by
     * exact key set), and a producer living in the host would be a second copy
     * of that grammar in a different language of the same repository — drifting
     * the moment either moved, and drifting SILENTLY on the host side, where
     * nothing re-verifies. The reviewed-exercise path keeps its external
     * producer because there the bundle is the OUTPUT of a real conformance
     * run that this file has no business performing.
     *
     * NOTHING IS WRITTEN TO DISK, and nothing is lost by that: an unexercised
     * bundle's only assets are environment.json and ratification.json, and
     * both are already inside the signed statement, content-addressed by the
     * descriptors the signature covers. There is no directory to keep, so
     * there is no directory to tamper with.
     *
     * The grammar verdict is TAKEN, not asserted: the real loader is run
     * against this repository and this name, and a manifest that does not load
     * refuses to be signed with the loader's own message. A certificate for
     * bytes no command can use would be the emptiest possible claim.
     *
     * Repeating the same certification preserves the timestamp of an existing
     * certificate only after the live verifier accepts its exact bytes and a
     * deterministic re-sign of every current input is byte-identical. Thus
     * `created_at` records when this claim changed, not how often an idempotent
     * command was invoked; any changed signed input mints a fresh statement.
     *
     * @param string $reason the operator's stated basis, signed and reported
     * @return string canonical duo-adapter-certification/v1 bytes
     */
    public static function sign_site(
        string $manifestDir,
        string $repo,
        string $name,
        string $authorityId,
        string $secretKey,
        string $reason
    ): string {
        self::assertSodium();
        $name = self::adapterName($name);
        if (trim($reason) === '') {
            throw new \RuntimeException(
                'duo: a site adapter certification must state its basis; supply a non-empty reason'
            );
        }
        $root = self::repoRoot($repo, 'site repository');
        $adapterPath = self::ownedFile($root, 'adapters/' . $name . '.json', 'site adapter');
        [$adapterRaw, , $manifest] = self::readCanonicalObjectFile($adapterPath, 'site adapter');
        self::assertSiteManifest($name, $manifest, $manifestDir);

        [$authority, $keyId, $authorityDigest, $trustRoot] = self::authority($manifestDir, $authorityId, $root);
        if ($trustRoot !== self::TRUST_ROOT_SITE) {
            // The relaxation follows the ROOT, not the caller. An agent-owned
            // key certifies a reviewed exercise or nothing; routing it through
            // this entry point would be exactly the downgrade the two words
            // exist to keep separable.
            throw new \RuntimeException(
                "duo: authority key '$keyId' is agent-owned, and an agent-owned key certifies a reviewed "
                . 'exercise — sign a ' . self::BUNDLE_FORMAT . ' bundle through sign() instead'
            );
        }
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAuthorityScope($authority, $keyId, $name, $tier);

        $secret = self::secretKey($secretKey);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $configured = self::publicKey($authority);
        if (!hash_equals($configured, $public)) {
            throw new \RuntimeException(
                "duo: private key does not match trusted authority key '$keyId'"
            );
        }

        $grammar = self::siteGrammarVerdict($manifestDir, $repo, $name);
        $ratification = self::siteRatification($name, $manifest, $reason);
        $ratificationRaw = Canon::encode($ratification);
        $authorityBinding = [
            'fingerprint' => hash('sha256', $configured),
            'key_id' => $keyId,
            'record' => $authority,
            'record_sha256' => $authorityDigest,
            'trust_root' => $trustRoot,
        ];
        $freshCreatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $existing = self::verifiedExistingSiteCertificate(
            $manifestDir,
            $root,
            $name,
            $manifest,
            $root . '/' . self::CERTIFICATE_DIR . '/' . $name . '.json'
        );
        if ($existing !== null) {
            $candidate = self::siteCertificateCandidate(
                $manifestDir,
                $name,
                $manifest,
                $adapterRaw,
                $tier,
                $authorityBinding,
                $trustRoot,
                $grammar,
                $ratificationRaw,
                $reason,
                $secret,
                $existing['created_at']
            );
            // Ed25519 signatures are deterministic. Equality here therefore
            // binds every signed input (including authority record, adapter
            // bytes, platform, PHP environment, grammar, reason and derived
            // ratification), rather than maintaining a second semantic
            // comparison beside the verifier. Only a certificate that passed
            // the live verifier above may lend its timestamp.
            if (hash_equals($existing['raw'], $candidate)) {
                return $existing['raw'];
            }
        }

        return self::siteCertificateCandidate(
            $manifestDir,
            $name,
            $manifest,
            $adapterRaw,
            $tier,
            $authorityBinding,
            $trustRoot,
            $grammar,
            $ratificationRaw,
            $reason,
            $secret,
            $freshCreatedAt
        );
    }

    /**
     * @return null|array{raw:string,created_at:string}
     */
    private static function verifiedExistingSiteCertificate(
        string $manifestDir,
        string $root,
        string $name,
        array $manifest,
        string $path
    ): ?array {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        try {
            $verified = self::verifyFile($manifestDir, $root, $name, $manifest, $path);
            $envelope = $verified['envelope'] ?? null;
            $encoded = is_array($envelope) ? ($envelope['certificate_json'] ?? null) : null;
            $digest = is_array($envelope) ? ($envelope['certificate_sha256'] ?? null) : null;
            $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($raw === false || !is_string($digest)
                || !hash_equals($digest, hash('sha256', $raw))) {
                return null;
            }
            [, , $certificate] = self::parseCanonicalObject($raw, 'verified site adapter certification');
            $createdAt = $certificate['statement']['bundle']['created_at'] ?? null;
            if (!is_string($createdAt)) {
                return null;
            }
            return ['raw' => $raw, 'created_at' => $createdAt];
        } catch (\Throwable) {
            // A superseded, malformed, revoked or otherwise unverifiable
            // certificate has no authority over the next signature's time.
            // The ordinary signing path below still validates every current
            // input and replaces it, preserving certify's existing repair
            // behavior without reusing untrusted bytes.
            return null;
        }
    }

    /**
     * Build and verify the exact site-profile candidate before Ed25519.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $authorityBinding
     */
    private static function siteCertificateCandidate(
        string $manifestDir,
        string $name,
        array $manifest,
        string $adapterRaw,
        string $tier,
        array $authorityBinding,
        string $trustRoot,
        string $grammar,
        string $ratificationRaw,
        string $reason,
        string $secret,
        string $createdAt
    ): string {
        $bundle = self::siteBundle(
            $name,
            $adapterRaw,
            $ratificationRaw,
            $grammar,
            $reason,
            $createdAt
        );

        // Verify the freshly built bundle through the SAME validator that will
        // re-verify it at every load. A producer that trusted its own output
        // would be the one place in this file where a certificate's grammar
        // was never checked.
        $bundleRaw = Canon::encode($bundle);
        [, $bundleTyped, $bundleArray] = self::parseCanonicalObject($bundleRaw, 'site certification bundle manifest');
        $info = self::verifyBundleManifest(
            $bundleTyped,
            $bundleArray,
            'site certification bundle manifest',
            $name,
            $trustRoot
        );
        self::assertBundleSubjectInput($info['bound_inputs'], $name, [
            'raw_sha256' => hash('sha256', $adapterRaw),
            'raw_size' => strlen($adapterRaw),
        ]);
        [, $ratificationTyped, $ratificationArray] = self::parseCanonicalObject(
            $ratificationRaw,
            'site certification ratification'
        );
        self::verifyRatification(
            $ratificationTyped,
            $ratificationArray,
            $name,
            $manifest,
            $info,
            $ratificationRaw
        );

        return self::signStatement(
            $manifestDir,
            $name,
            $manifest,
            $adapterRaw,
            $tier,
            $authorityBinding,
            $bundleTyped,
            $ratificationTyped,
            $secret
        );
    }

    /**
     * The real loader's verdict for this adapter, in this repository.
     *
     * Policy is required HERE rather than at file scope for the reason
     * AdapterSources gives for its own lazy require of this class: the two
     * form a cycle through the source/certification integration boundary, and
     * a file-scope edge would make otherwise independent offline entry points
     * order-sensitive.
     *
     * The manifest directory must be the one this process would load anyway.
     * A verdict judged against a different library than the verifier will use
     * is not a verdict about anything.
     */
    private static function siteGrammarVerdict(string $manifestDir, string $repo, string $name): string {
        require_once __DIR__ . '/../Policy/Policy.php';
        $resolvedDeclared = realpath($manifestDir);
        $resolvedLoaded = realpath(Policy::manifests_dir());
        if ($resolvedDeclared === false || $resolvedLoaded === false
            || !hash_equals($resolvedLoaded, $resolvedDeclared)) {
            throw new \RuntimeException(
                'duo: site adapter certification must be signed against the manifest library this process loads ('
                . ($resolvedLoaded === false ? '(unresolvable)' : $resolvedLoaded) . '), not '
                . ($resolvedDeclared === false ? '(unresolvable)' : $resolvedDeclared)
            );
        }
        try {
            Policy::load($repo, [$name]);
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                "duo: site adapter '$name' does not load, so there is no grammar verdict to certify: "
                . $t->getMessage()
            );
        }
        return AdapterSources::GRAMMAR_OK;
    }

    /**
     * A ratification DERIVED from the manifest, never authored beside it.
     *
     * Every field is a restatement of something the manifest already declares
     * or of something a grammar check provably did not review:
     *
     *   - the sections are exactly the state surfaces the manifest declares,
     *     partitioned by the shipped entity/field vocabulary;
     *   - `deletion_semantics` is unsupported outright, and `operations` omits
     *     `delete`, because deletion semantics are what a validator run cannot
     *     review;
     *   - `lifecycle_phases` is empty for the same reason;
     *   - every intent-only table is marked unsupported (ManifestDispositions
     *     requires it, and the requirement is right: an
     *     authored_typed_snapshot_post_v1 table is a declaration of intent);
     *   - every open-ended `default_class: authored` keyspace is recorded
     *     `unsupported` rather than `justified` — justification is a review
     *     judgement about a plugin-upgrade tripwire, and nobody made one here.
     *
     * @return array<string,mixed>
     */
    private static function siteRatification(string $name, array $manifest, string $reason): array {
        $entity = [];
        $field = [];
        foreach (array_keys($manifest) as $key) {
            $key = (string) $key;
            if (in_array($key, self::ENTITY_SECTIONS, true)) {
                $entity[] = $key;
            } elseif (in_array($key, self::FIELD_SECTIONS, true)) {
                $field[] = $key;
            } elseif (!in_array($key, self::NON_SURFACE_KEYS, true)) {
                throw new \RuntimeException(
                    "duo: site adapter '$name' declares '$key', which this signer cannot classify as an entity "
                    . 'or field surface — a certificate that silently omitted it would cover less than the '
                    . 'adapter does. Certify it through a reviewed bundle, or teach the signer this section'
                );
            }
        }
        sort($entity, SORT_STRING);
        sort($field, SORT_STRING);

        $unsupported = [[
            'operation' => 'delete',
            'reason' => 'A manifest grammar verdict reviews no deletion semantics.',
            'surface' => 'deletions.*',
        ]];
        $keyspaces = [];
        foreach ((array) ($manifest['tables'] ?? []) as $table => $rule) {
            $table = (string) $table;
            if (!is_array($rule)) {
                continue;
            }
            if (($rule['class'] ?? null) === 'authored_typed_snapshot_post_v1') {
                $unsupported[] = [
                    'operation' => 'apply',
                    'reason' => 'An intent-only table is a declaration, not a reviewed convergence surface.',
                    'surface' => "tables.$table",
                ];
            }
            if (($rule['default_class'] ?? null) === 'authored') {
                $keyspaces[] = [
                    'reason' => 'The open-ended authored default was declared by the site operator and reviewed '
                        . 'by no exercise; a key introduced by a later plugin version is not covered.',
                    'status' => 'unsupported',
                    'table' => $table,
                ];
            }
        }
        usort($unsupported, static fn(array $a, array $b): int => [$a['surface'], $a['operation']]
            <=> [$b['surface'], $b['operation']]);
        usort($keyspaces, static fn(array $a, array $b): int => strcmp($a['table'], $b['table']));

        $plugin = $manifest['plugin'] ?? null;
        $versions = is_string($plugin) && $plugin !== ''
            // validate_entry() compares both against the manifest itself, so
            // this is a restatement rather than a claim.
            ? ['plugin' => $plugin, 'range' => $manifest['version_range'] ?? null]
            : ['source' => 'site-operator'];

        return [
            'format' => self::RATIFICATION_FORMAT,
            'manifests' => [
                $name => [
                    'capabilities' => [
                        'deletion_semantics' => [
                            'supported' => [],
                            'unsupported' => ['every declared deletion selector'],
                        ],
                        'entity_sections' => $entity,
                        'field_sections' => $field,
                        'lifecycle_phases' => [],
                        'operations' => self::SITE_OPERATIONS,
                    ],
                    'default_authored_keyspaces' => $keyspaces,
                    'evidence' => [
                        'bundle_schema' => self::BUNDLE_FORMAT,
                        'tests' => [],
                    ],
                    'reason' => $reason,
                    'status' => 'certified',
                    'supported_versions' => $versions,
                    'unsupported' => $unsupported,
                ],
            ],
            'profiles' => [],
        ];
    }

    /**
     * The unexercised bundle, in memory.
     *
     * `git_revision` is the nil SHA because this profile binds no evidence
     * repository at all. Borrowing the site repository's HEAD would read as
     * provenance for a review that did not happen, and the field is 40-hex by
     * grammar — so the honest value is the one that means "no commit".
     *
     * @return array<string,mixed>
     */
    private static function siteBundle(
        string $name,
        string $adapterRaw,
        string $ratificationRaw,
        string $grammar,
        string $reason,
        string $createdAt
    ): array {
        $environment = [
            'exercised' => false,
            'note' => 'no environment was exercised; this certificate binds the manifest grammar only',
            'php' => PHP_VERSION,
        ];
        $environmentRaw = Canon::encode($environment);
        $bundle = [
            'artifacts' => [],
            'bound_inputs' => [[
                'path' => 'adapters/' . $name . '.json',
                'sha256' => hash('sha256', $adapterRaw),
                'size' => strlen($adapterRaw),
            ]],
            'created_at' => $createdAt,
            'environment' => [
                'path' => 'environment.json',
                'sha256' => hash('sha256', $environmentRaw),
                'size' => strlen($environmentRaw),
            ],
            'environment_summary' => $environment,
            'evidence' => [
                'exercised' => false,
                'grammar' => $grammar,
                'reason' => $reason,
            ],
            'force_hatches' => [],
            'git_revision' => str_repeat('0', 40),
            'harness' => ['name' => 'duo-adapter-certify', 'version' => 1],
            'ratification' => [
                'path' => 'ratification.json',
                'sha256' => hash('sha256', $ratificationRaw),
                'size' => strlen($ratificationRaw),
            ],
            'ratification_summary' => [
                'certified_claims' => ['manifests.' . $name],
                'manifest_count' => 1,
                'profile_count' => 0,
            ],
            'schema_version' => self::BUNDLE_FORMAT,
            'subject' => ['kind' => 'site_adapter', 'name' => $name],
            'tests' => [],
            'verdict' => 'pass',
        ];
        $bundle['bundle_digest'] = self::bundleDigest($bundle);
        return $bundle;
    }

    /**
     * The one place a certificate is minted, shared by the reviewed-exercise
     * path and the site path. Both bind the identical statement shape; a
     * second builder would be a second thing to keep in step with the
     * verifier.
     *
     * @param array<string,mixed> $authorityBinding
     */
    private static function signStatement(
        string $manifestDir,
        string $name,
        array $manifest,
        string $adapterRaw,
        string $tier,
        array $authorityBinding,
        object $bundleTyped,
        object $ratificationTyped,
        string $secret
    ): string {
        [$platform, ] = self::currentPlatform($manifestDir);
        $statement = [
            'adapter' => [
                'canonical_sha256' => self::canonicalHash($manifest),
                'name' => $name,
                'path' => 'adapters/' . $name . '.json',
                'raw_sha256' => hash('sha256', $adapterRaw),
                'raw_size' => strlen($adapterRaw),
                'source' => AdapterSources::SITE,
                'trust_tier' => $tier,
            ],
            'authority' => $authorityBinding,
            // Keep the exact, verified bundle manifest and ratification as
            // objects.  The signature binds their content-addressed identity,
            // all declared asset descriptors, and all named test claims.
            'bundle' => $bundleTyped,
            'platform' => $platform,
            'ratification' => $ratificationTyped,
        ];
        $signature = sodium_crypto_sign_detached(self::signatureBytes($statement), $secret);
        return Canon::encode([
            'format' => self::FORMAT,
            'signature' => base64_encode($signature),
            'statement' => $statement,
        ]);
    }

    /** Return a non-secret summary suitable for the command-line tool. */
    public static function certificateSummary(array $verified): array {
        $provenance = is_array($verified['provenance'] ?? null) ? $verified['provenance'] : [];
        $proof = is_array($provenance['proof'] ?? null) ? $provenance['proof'] : [];
        $certification = is_array($verified['claim']['certification'] ?? null)
            ? $verified['claim']['certification']
            : [];
        return [
            'authority' => $proof['authority'] ?? null,
            'bundle_digest' => $proof['bundle']['digest'] ?? null,
            'exercised' => $proof['bundle']['exercised'] ?? null,
            'name' => $verified['claim']['name'] ?? null,
            // The two words a host prints beside `Site-certified`: WHO
            // certified, and under WHICH root. Projected from the verified
            // claim rather than re-derived, so the printed line cannot drift
            // from the claim `duo promote` gates on.
            'principal' => $certification['principal'] ?? null,
            'status' => $verified['claim']['status'] ?? null,
            'trust_root' => $certification['trust_root'] ?? null,
            'trust_tier' => $verified['disposition']['trust_tier'] ?? null,
        ];
    }

    /** @return array{format:string,certificate_sha256:string,certificate_json:string} */
    private static function envelope(string $certificateRaw): array {
        return [
            'format' => self::ENVELOPE_FORMAT,
            'certificate_sha256' => hash('sha256', $certificateRaw),
            'certificate_json' => base64_encode($certificateRaw),
        ];
    }

    /**
     * Verify the signed statement after either live or frozen input handling.
     * $rawAdapter is deliberately nullable only for frozen verification.
     */
    private static function verifyCertificate(
        string $manifestDir,
        string $name,
        array $manifest,
        string $certificateRaw,
        object $certificateTyped,
        array $certificate,
        ?array $rawAdapter,
        ?string $repoRoot
    ): array {
        self::assertCertificateShape($certificateTyped, $certificate);
        $statementTyped = $certificateTyped->statement;
        $statement = $certificate['statement'];
        self::assertStatementShape($statementTyped, $statement);

        $adapter = $statement['adapter'];
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAdapterBinding($name, $manifest, $adapter, $tier, $rawAdapter);

        $statementAuthority = $statement['authority'];
        self::assertExactKeys(
            $statementAuthority,
            ['fingerprint', 'key_id', 'record', 'record_sha256', 'trust_root'],
            'certification authority binding'
        );
        $selectedAuthority = $statementAuthority['key_id'] ?? null;
        if (!is_string($selectedAuthority)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority key_id must be a canonical string selector"
            );
        }
        $claimedRoot = $statementAuthority['trust_root'] ?? null;
        if (!in_array($claimedRoot, [self::TRUST_ROOT_PLATFORM, self::TRUST_ROOT_SITE], true)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification must name trust root "
                . self::TRUST_ROOT_PLATFORM . ' or ' . self::TRUST_ROOT_SITE
            );
        }
        $embeddedRecord = $statementAuthority['record'] ?? null;
        if (!is_array($embeddedRecord) || array_is_list($embeddedRecord)
            || !isset($statementTyped->authority->record) || !is_object($statementTyped->authority->record)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority binding must carry its exact authority record"
            );
        }
        if ($claimedRoot === self::TRUST_ROOT_SITE && $repoRoot === null) {
            // THE ONE ASYMMETRY BETWEEN THE TWO ROOTS, and it is a property of
            // where each root LIVES rather than a weaker rule.
            //
            // Frozen verification deliberately reopens no mutable site file
            // (see verifyFrozen()), so a site-rooted certificate has no
            // adapters/authorities.json to consult here. It is re-bound to the
            // authority record the SIGNATURE covers instead. Concretely: a
            // platform key revoked in the shipped file stops verifying frozen
            // snapshots immediately, while a site key revoked in the
            // operator's repository stops verifying on every live scan
            // (discover() reopens the file each run) but not inside an already
            // frozen snapshot. That is the operator's own root, revoked by the
            // operator, in a document the same operator produced; treating it
            // as a platform revocation would be claiming a custody property
            // this profile explicitly defers (T6 §2).
            //
            // What is NOT relaxed: the shipped library still wins the key-id
            // namespace, and that check needs no repository at all.
            self::validateAuthorityRecord(
                $embeddedRecord,
                "site adapter certification key '$selectedAuthority'"
            );
            self::assertKeyIdNotPlatformOwned($manifestDir, self::keyId($selectedAuthority));
            $authority = $embeddedRecord;
            $keyId = self::keyId($selectedAuthority);
            $authorityDigest = self::canonicalHash($embeddedRecord);
            $trustRoot = self::TRUST_ROOT_SITE;
        } else {
            [$authority, $keyId, $authorityDigest, $trustRoot] = self::authority(
                $manifestDir,
                $selectedAuthority,
                $repoRoot
            );
        }
        self::assertAuthorityBinding(
            $authority,
            $keyId,
            $authorityDigest,
            $trustRoot,
            $statementAuthority,
            $name,
            $tier
        );
        // The proof (and through it the adapter digest every repository pin
        // binds) records the authority record the certificate was SIGNED
        // over. Under the site root that is the embedded record's digest, not
        // the current file's: the site trust root grows with every certified
        // adapter, and a proof that followed the current file would move the
        // pinned digest of every earlier adapter each time — the frozen path
        // below already re-derives exactly this digest from the embedded
        // record, so live and frozen now say the same thing. The platform
        // root binds the whole record, so there the two digests are equal.
        if ($trustRoot === self::TRUST_ROOT_SITE) {
            $authorityDigest = (string) $statementAuthority['record_sha256'];
        }
        $signature = base64_decode((string) $certificate['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $signature,
                self::signatureBytes($statementTyped),
                self::publicKey($authority)
            )) {
            throw new \RuntimeException("duo: site adapter '$name' certification has an invalid Ed25519 signature");
        }

        // Reached only after the signature and the authority binding above have
        // both verified, which is exactly why the typed signal below is safe:
        // this line can only be about an AGENT-owned document that moved, never
        // about the companion's provenance.
        [$platform, $platformDigest] = self::currentPlatform($manifestDir);
        if (!hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))) {
            throw new StalePlatformSiteAdapterCertificate(
                "duo: site adapter '$name' certification platform boundary disagrees with the current agent-owned platform"
            );
        }

        $bundle = self::verifyEmbeddedBundle($statementTyped->bundle, $statement['bundle'], $name, $trustRoot);
        self::assertBundleSubjectInput($bundle['bound_inputs'], $name, $adapter);
        [$ratification, $disposition, $ratificationRaw] = self::verifyEmbeddedRatification(
            $statementTyped->ratification,
            $statement['ratification'],
            $name,
            $manifest,
            $bundle
        );
        unset($ratification); // The signed exact document is represented in the envelope/proof below.

        $certificateDigest = hash('sha256', $certificateRaw);
        $statementDigest = hash('sha256', Canon::encode($statementTyped));
        $derived = self::derivedDisposition(
            $name,
            $adapter,
            $tier,
            $disposition,
            $statementAuthority,
            $authorityDigest,
            $bundle,
            $platformDigest,
            $ratificationRaw,
            $certificateDigest,
            $statementDigest
        );

        return [
            'disposition' => $derived,
            'claim' => self::projectClaim($name, $manifest, $disposition, $derived, $statement['platform']),
            'provenance' => $derived['provenance'],
        ];
    }

    private static function assertSodium(): void {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('duo: signed site adapter certification requires the PHP sodium extension');
        }
    }

    private static function agentVersion(): string {
        return defined('DUO_AGENT_VERSION') ? (string) DUO_AGENT_VERSION : '0.5.0';
    }

    private static function specVersion(): int {
        return defined('DUO_SPEC_VERSION') ? (int) DUO_SPEC_VERSION : 2;
    }

    private static function adapterName(string $name): string {
        AdapterSources::assert_name($name, 'site adapter certification name');
        return $name;
    }

    private static function keyId(string $id): string {
        AdapterSources::assert_name($id, 'authority key id');
        return $id;
    }

    private static function repoRoot(string $repo, string $label): string {
        $root = realpath($repo);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException("duo: $label is absent or not a directory: $repo");
        }
        return rtrim($root, '/');
    }

    /** A deliberately non-normalising relative path grammar. */
    private static function relativePath(string $path, string $label): string {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new \RuntimeException("duo: $label must be a non-empty slash-separated relative path");
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException("duo: $label is not a canonical relative path: $path");
            }
        }
        return $path;
    }

    /**
     * Resolve a regular file only when every component remains inside an exact
     * physical root.  This closes final-file and ancestor symlinks alike.
     */
    private static function ownedFile(string $root, string $relative, string $label): string {
        $relative = self::relativePath($relative, $label . ' path');
        $expected = $root . '/' . $relative;
        if (!is_file($expected) || is_link($expected)) {
            throw new \RuntimeException("duo: $label is absent, not a regular file, or a symbolic link: $relative");
        }
        $resolved = realpath($expected);
        if ($resolved === false || !hash_equals($expected, $resolved)) {
            throw new \RuntimeException("duo: $label must resolve exactly inside its declared root: $relative");
        }
        return $resolved;
    }

    /** @return array{0:string,1:object,2:array} */
    private static function readCanonicalObjectFile(string $path, string $label): array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read $label: $path");
        }
        return self::parseCanonicalObject($raw, $label);
    }

    /** @return array{0:string,1:object,2:array} */
    private static function parseCanonicalObject(string $raw, string $label): array {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!is_object($typed) || !is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("duo: $label JSON root must be an object");
        }
        if (!hash_equals(Canon::encode($typed), $raw)) {
            throw new \RuntimeException("duo: $label must use Duo canonical JSON bytes");
        }
        return [$raw, $typed, $decoded];
    }

    /**
     * The existing certification-bundle producer deliberately uses compact
     * canonical JSON for its digest and four-space pretty canonical JSON for
     * bundle.json/result assets.  It is not the repository-file Canon format;
     * accepting that producer's exact bytes avoids inventing a second bundle
     * identity while still refusing whitespace/key-order ambiguity.
     *
     * @return array{0:string,1:object,2:array}
     */
    private static function readBundleObjectFile(string $path, string $label): array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read $label: $path");
        }
        return self::parseBundleObject($raw, $label);
    }

    /** @return array{0:string,1:object,2:array} */
    private static function parseBundleObject(string $raw, string $label): array {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!is_object($typed) || !is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("duo: $label JSON root must be an object");
        }
        if (!hash_equals(self::bundlePretty($typed), $raw)) {
            throw new \RuntimeException("duo: $label must use certification-bundle canonical JSON bytes");
        }
        return [$raw, $typed, $decoded];
    }

    private static function bundlePretty($value): string {
        try {
            $json = json_encode(
                Canon::normalize($value),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('duo: certification bundle is not canonicalizable: ' . $e->getMessage());
        }
        return $json . "\n";
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                "duo: $label must contain exactly " . implode(', ', $expected)
            );
        }
    }

    /** @param mixed $value */
    private static function stringList($value, string $label, bool $allowEmpty = true): array {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            throw new \RuntimeException("duo: $label must be a " . ($allowEmpty ? 'list' : 'non-empty list'));
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                throw new \RuntimeException("duo: $label must contain unique non-empty strings");
            }
            $seen[$item] = true;
        }
        return $value;
    }

    /** @param mixed $value */
    private static function sha($value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /** Base64 is part of a signed/agent-owned grammar, never a loose codec. */
    private static function isCanonicalBase64(string $encoded, string $decoded): bool {
        return $encoded !== '' && hash_equals(base64_encode($decoded), $encoded);
    }

    private static function canonicalHash($value): string {
        return hash('sha256', Canon::encode($value));
    }

    private static function signatureBytes($statement): string {
        return self::SIGNATURE_DOMAIN . Canon::encode($statement);
    }

    private static function assertSiteManifest(string $name, array $manifest, string $manifestDir): void {
        if (($manifest['name'] ?? null) !== $name) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification requires adapters/$name.json to declare the same name"
            );
        }
        // These are trust/certification facts, not adapter facts.  A manifest
        // that carries one is refused instead of leaving a future reader to
        // decide whether it is inert, partially honoured, or self-certifying.
        foreach ([
            'adapter_certificate', 'authority', 'authority_id', 'certificate',
            'certification', 'certification_authority', 'key_id', 'public_key',
            'signature', 'trust_tier',
        ] as $reserved) {
            if (array_key_exists($reserved, $manifest)) {
                throw new \RuntimeException(
                    "duo: site adapter '$name' declares reserved self-certification field '$reserved' — "
                    . 'trust roots and signatures belong only in agent-owned roots and adapters/certifications/'
                );
            }
        }
        // A site copy of a SHIPPED name is an override (T6 §3.3): its
        // interpreter / regenerator / provider declarations are admitted
        // exactly when they repeat the shipped grant, and refused when they
        // add one — the same rule the loader applies, so a certificate is
        // never signed over a manifest the engine would then refuse (the
        // walk's S4 hit exactly that split: the pre-flight load passed, the
        // signature step refused `out_of_tree_privilege`).
        AdapterSources::assert_out_of_tree_contract(
            $manifest,
            $name,
            'adapters/' . $name . '.json',
            'site adapter',
            false,
            AdapterSources::shipped_executable_grants($manifestDir, $name)
        );
    }

    private static function assertSameManifest(string $name, array $provided, array $raw): void {
        if (!hash_equals(self::canonicalHash($raw), self::canonicalHash($provided))) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certificate was asked to verify manifest bytes different from adapters/$name.json"
            );
        }
    }

    /**
     * Resolve one authority key across the two trust roots.
     *
     * THE PRECEDENCE IS SHIPPED-WINS, and it is the reason a site trust root
     * can exist at all: the operator writes their own authorities file, so
     * without this rule an operator could re-point a key id the agent library
     * reviews and have a certificate signed under it read as platform-rooted.
     * The shipped record is therefore consulted first, and a site record for
     * the same key id is never reached — not merged, not preferred, and not
     * silently ignored either, because a certificate that CLAIMED
     * `trust_root: site` for such a key then fails its binding check by name
     * (assertAuthorityBinding()) instead of quietly downgrading.
     *
     * Both files are validated WHOLE whenever they are opened, exactly as the
     * agent-owned file always was: an authority record nobody selected is
     * still authority-bearing bytes an operator believes in, and a malformed
     * sibling is a broken trust root rather than an unrelated file.
     *
     * @param ?string $repoRoot resolved site repository, or null where the
     *        caller reopens no mutable site file (frozen verification)
     * @return array{0:array,1:string,2:string,3:string} [record, key id, canonical record digest, trust root]
     */
    private static function authority(string $manifestDir, string $id, ?string $repoRoot): array {
        $id = self::keyId($id);
        $platform = self::authorityKeys(
            rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE,
            'adapter certification authorities'
        );
        if (isset($platform[$id])) {
            return [$platform[$id], $id, self::canonicalHash($platform[$id]), self::TRUST_ROOT_PLATFORM];
        }
        $site = $repoRoot === null
            ? []
            : self::authorityKeys(
                rtrim($repoRoot, '/') . '/' . self::SITE_AUTHORITIES_RELATIVE,
                'site adapter certification authorities'
            );
        if (isset($site[$id])) {
            return [$site[$id], $id, self::canonicalHash($site[$id]), self::TRUST_ROOT_SITE];
        }
        if ($platform === [] && $site === []) {
            throw new \RuntimeException(
                'duo: no adapter certification authorities are installed at ' . self::AUTHORITIES_RELATIVE
                . ($repoRoot === null ? '' : ' or ' . self::SITE_AUTHORITIES_RELATIVE)
            );
        }
        throw new \RuntimeException(
            "duo: authority key '$id' is not installed in " . self::AUTHORITIES_RELATIVE
            . ($repoRoot === null ? '' : ' or ' . self::SITE_AUTHORITIES_RELATIVE)
        );
    }

    /**
     * One authorities file's validated key map, or [] when the file is absent.
     *
     * Absence is a legitimate answer for BOTH roots — the shipped file may not
     * exist in a custom manifest directory, and a repository that certifies
     * nothing has no adapters/authorities.json — but a file that EXISTS and is
     * not an ordinary readable canonical document is a refusal, never an
     * absence. Laundering unreadable authority bytes into "no trust root" is
     * the exact failure mode the certificate-source rules already refuse.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function authorityKeys(string $file, string $label): array {
        if (!file_exists($file) && !is_link($file)) {
            return [];
        }
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException("duo: $label must be an ordinary regular file: $file");
        }
        [, $typed, $data] = self::readCanonicalObjectFile($file, $label);
        self::assertExactKeys($data, ['format', 'keys'], $label);
        if (($data['format'] ?? null) !== self::AUTHORITIES_FORMAT
            || !is_array($data['keys'] ?? null)
            || !isset($typed->keys) || !is_object($typed->keys)) {
            throw new \RuntimeException("duo: $label have an unsupported or malformed root");
        }
        $keys = [];
        foreach ($data['keys'] as $keyId => $record) {
            if (!is_string($keyId)) {
                throw new \RuntimeException(
                    'duo: adapter certification authority key map contains non-string key ' . var_export($keyId, true)
                    . ' — numeric-only identities are forbidden because PHP coerces JSON object-map keys to integers'
                );
            }
            self::keyId($keyId);
            if (!is_array($record) || array_is_list($record)) {
                throw new \RuntimeException("duo: adapter certification key '$keyId' must be an object");
            }
            if (!isset($typed->keys->{$keyId}) || !is_object($typed->keys->{$keyId})) {
                throw new \RuntimeException("duo: adapter certification key '$keyId' must be a JSON object");
            }
            self::validateAuthorityRecord($record, "adapter certification key '$keyId'");
            $keys[$keyId] = $record;
        }
        return $keys;
    }

    /**
     * The shipped-wins rule, asked without a repository.
     *
     * The frozen path re-binds a site-rooted certificate to its own signed
     * authority record, so this is what stops that record from claiming a key
     * id the agent library reviews: the shipped file is agent-owned and
     * readable there, and a certificate naming one of its ids is not a site
     * certificate at all.
     */
    private static function assertKeyIdNotPlatformOwned(string $manifestDir, string $id): void {
        $platform = self::authorityKeys(
            rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE,
            'adapter certification authorities'
        );
        if (isset($platform[$id])) {
            throw new \RuntimeException(
                "duo: authority key '$id' is reviewed and shipped by this agent, so a site trust root cannot claim it"
            );
        }
    }

    private static function validateAuthorityRecord(array $record, string $label): void {
        self::assertExactKeys($record, [
            'adapter_names', 'algorithm', 'public_key', 'scope', 'status', 'trust_tiers',
        ], $label);
        if (($record['algorithm'] ?? null) !== 'ed25519'
            || ($record['scope'] ?? null) !== 'site_adapter_certification') {
            throw new \RuntimeException("duo: $label must declare algorithm ed25519 and scope site_adapter_certification");
        }
        if (!in_array($record['status'] ?? null, ['trusted', 'revoked'], true)) {
            throw new \RuntimeException("duo: $label status must be trusted or revoked");
        }
        $names = self::stringList($record['adapter_names'] ?? null, "$label.adapter_names", false);
        foreach ($names as $name) {
            self::adapterName($name);
        }
        $tiers = self::stringList($record['trust_tiers'] ?? null, "$label.trust_tiers", false);
        foreach ($tiers as $tier) {
            // `compatibility_shim` is admitted because an OVERRIDE of a shipped
            // adapter inherits the shipped interpreter/provider grants and so
            // carries the shipped tier (T6 §3.3); the site key certifies the
            // override's declarations, never new code — the loader refuses a
            // site manifest that adds an executable declaration.
            if (!in_array($tier, [
                AdapterSources::TIER_DECLARATIVE,
                AdapterSources::TIER_NATIVE_ACTION,
                AdapterSources::TIER_PLUGIN_PROVIDER,
                AdapterSources::TIER_COMPATIBILITY_SHIM,
            ], true)) {
                throw new \RuntimeException("duo: $label names unsupported site adapter trust tier '$tier'");
            }
        }
        self::publicKey($record);
    }

    private static function publicKey(array $authority): string {
        $encoded = $authority['public_key'] ?? null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || !is_string($encoded) || !self::isCanonicalBase64($encoded, $key)
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('duo: authority public_key must be a base64 Ed25519 public key');
        }
        return $key;
    }

    private static function secretKey(string $secret): string {
        $trimmed = trim($secret);
        if (preg_match('/^[0-9a-f]{128}$/Di', $trimmed) === 1) {
            $decoded = hex2bin($trimmed);
        } else {
            $decoded = base64_decode($trimmed, true);
        }
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('duo: private key must be a base64 or hexadecimal Ed25519 secret key');
        }
        return $decoded;
    }

    private static function assertAuthorityScope(array $authority, string $keyId, string $name, string $tier): void {
        if (($authority['status'] ?? null) !== 'trusted') {
            throw new \RuntimeException("duo: authority key '$keyId' is revoked and cannot certify adapters");
        }
        if (!in_array($name, $authority['adapter_names'], true)) {
            throw new \RuntimeException("duo: authority key '$keyId' is not scoped to site adapter '$name'");
        }
        if (!in_array($tier, $authority['trust_tiers'], true)) {
            throw new \RuntimeException(
                "duo: authority key '$keyId' is not scoped to derived trust tier '$tier'"
            );
        }
    }

    private static function assertAuthorityBinding(
        array $authority,
        string $keyId,
        string $authorityDigest,
        string $trustRoot,
        array $statementAuthority,
        string $name,
        string $tier
    ): void {
        $embedded = $statementAuthority['record'] ?? null;
        $recordDigest = $statementAuthority['record_sha256'] ?? null;
        if ($trustRoot === self::TRUST_ROOT_SITE) {
            // The site trust root is a LIVING registry: `duo adapter certify`
            // appends every newly certified name (and its tier) to the key's
            // record, so binding the whole record would invalidate every
            // earlier certificate under that key the moment a second adapter
            // is certified (seen: certifying an override made the certified
            // wpforms adapter `certificate_invalid`). The certificate binds
            // the key's IDENTITY — id, algorithm, public key, scope, status,
            // fingerprint, trust root — and self-consistently the record it
            // was signed over; the record's scope lists (adapter_names,
            // trust_tiers) are enforced LIVE against the current record by
            // assertAuthorityScope() below, and revocation with them. The
            // platform root keeps whole-record binding: that file is
            // reviewed and shipped, and never grows under an operator's hand.
            $bound = is_array($embedded)
                && self::sha($recordDigest)
                && hash_equals((string) $recordDigest, self::canonicalHash($embedded))
                && hash_equals(
                    Canon::encode(self::authorityIdentity($authority)),
                    Canon::encode(self::authorityIdentity($embedded))
                );
        } else {
            // The embedded record is compared canonically, not by digest
            // alone: record_sha256 is what the frozen path re-derives FROM the
            // embedded bytes, so a digest-only check would let those two agree
            // with each other while disagreeing with the installed root.
            $bound = self::sha($recordDigest)
                && hash_equals((string) $recordDigest, $authorityDigest)
                && hash_equals(Canon::encode($authority), Canon::encode($embedded));
        }
        if (($statementAuthority['key_id'] ?? null) !== $keyId
            || ($statementAuthority['trust_root'] ?? null) !== $trustRoot
            || ($statementAuthority['fingerprint'] ?? null) !== hash('sha256', self::publicKey($authority))
            || !$bound) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority/key/fingerprint/trust root does not match the "
                . "current $trustRoot authority record"
            );
        }
        self::assertAuthorityScope($authority, $keyId, $name, $tier);
    }

    /** The key-identity half of an authority record: everything but its scope lists. */
    private static function authorityIdentity(array $record): array {
        unset($record['adapter_names'], $record['trust_tiers']);
        ksort($record, SORT_STRING);

        return $record;
    }

    /** @return array{0:array,1:string} */
    private static function currentPlatform(string $manifestDir): array {
        $file = rtrim($manifestDir, '/') . '/' . self::PLATFORM_RELATIVE;
        if (!is_file($file)) {
            throw new \RuntimeException(
                'duo: current agent platform boundary is absent at capabilities/platform.json'
            );
        }
        // Read here rather than through ManifestDispositions::platform_boundary()
        // because this path needs the TYPED object: canonicalHash($typed->platform)
        // is the `platform_sha256` inside every signed statement, so the bytes
        // hashed must be the decoded object itself and not a re-encoding of an
        // array projection. Both readers bind the same file and the same
        // format constant; only the shape they hand back differs.
        [, $typed, $data] = self::readCanonicalObjectFile($file, 'agent platform boundary');
        if (($data['format'] ?? null) !== ManifestDispositions::PLATFORM_FORMAT
            || !isset($typed->platform) || !is_object($typed->platform)
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])) {
            throw new \RuntimeException('duo: agent platform boundary document has no valid platform object');
        }
        $platform = $data['platform'];
        self::assertExactKeys($platform, [
            'agent_version', 'branchable_state', 'compatibility', 'plugin_execution', 'site_mode', 'spec_version',
        ], 'agent capability platform boundary');
        if (($platform['agent_version'] ?? null) !== self::agentVersion()
            || ($platform['spec_version'] ?? null) !== self::specVersion()
            || ($platform['site_mode'] ?? null) !== 'single-site'
            || !is_array($platform['compatibility'] ?? null) || array_is_list($platform['compatibility'])) {
            throw new \RuntimeException('duo: agent capability platform boundary disagrees with the loaded agent');
        }
        return [$typed->platform, self::canonicalHash($typed->platform)];
    }

    private static function assertCertificateShape(object $typed, array $certificate): void {
        // BEFORE the closed key set, deliberately: a companion written on a
        // different version of this wire is entitled to keys this agent has
        // never heard of, so assertExactKeys() would refuse it as malformed and
        // the operator would never learn the real reason. The predicate is the
        // exact family at a different integer version and nothing else — see
        // SupersededWireSiteAdapterCertificate for why one term wider would be
        // a laundering path. The bytes reaching here are already proved
        // canonical JSON by readCanonicalObjectFile()/parseCanonicalObject(),
        // so an unparseable file never arrives at this test at all.
        $format = $certificate['format'] ?? null;
        if (is_string($format) && $format !== self::FORMAT
            && preg_match('#^duo-adapter-certification/v[1-9][0-9]*$#D', $format) === 1) {
            throw new SupersededWireSiteAdapterCertificate(
                // The regex above has already proved $format is exactly
                // `duo-adapter-certification/v<digits>`, so it is safe to
                // print verbatim: there is nothing left in it to inject.
                "duo: site adapter certification is written in wire version '$format', which this agent does not "
                . "verify; it verifies '" . self::FORMAT . "'"
            );
        }
        self::assertExactKeys($certificate, ['format', 'signature', 'statement'], 'site adapter certification');
        if (($certificate['format'] ?? null) !== self::FORMAT
            || !is_string($certificate['signature'] ?? null)
            || !isset($typed->statement) || !is_object($typed->statement)
            || !is_array($certificate['statement'] ?? null) || array_is_list($certificate['statement'])) {
            throw new \RuntimeException('duo: site adapter certification has an unsupported or malformed root');
        }
        $signature = base64_decode($certificate['signature'], true);
        if ($signature === false || !self::isCanonicalBase64($certificate['signature'], $signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('duo: site adapter certification signature is not a base64 Ed25519 signature');
        }
    }

    private static function assertStatementShape(object $typed, array $statement): void {
        self::assertExactKeys($statement, ['adapter', 'authority', 'bundle', 'platform', 'ratification'], 'site adapter certification statement');
        foreach (['adapter', 'authority', 'bundle', 'platform', 'ratification'] as $key) {
            if (!isset($typed->$key) || !is_object($typed->$key)
                || !is_array($statement[$key] ?? null) || array_is_list($statement[$key])) {
                throw new \RuntimeException("duo: site adapter certification statement.$key must be an object");
            }
        }
    }

    private static function assertAdapterBinding(
        string $name,
        array $manifest,
        array $adapter,
        string $derivedTier,
        ?array $rawAdapter
    ): void {
        self::assertExactKeys($adapter, [
            'canonical_sha256', 'name', 'path', 'raw_sha256', 'raw_size', 'source', 'trust_tier',
        ], 'site adapter certification adapter binding');
        $expectedPath = 'adapters/' . $name . '.json';
        // Structural/format anomalies FIRST: a malformed, misplaced, wrong-
        // source or wrong-tier companion, or a mis-shaped digest. These are
        // authority anomalies and stay hard failures. Deliberately excludes
        // the canonical/raw VALUE comparison so a plain content edit is not
        // conflated with a corrupt companion.
        if (($adapter['name'] ?? null) !== $name
            || ($adapter['path'] ?? null) !== $expectedPath
            || ($adapter['source'] ?? null) !== AdapterSources::SITE
            || ($adapter['trust_tier'] ?? null) !== $derivedTier
            || !self::sha($adapter['canonical_sha256'] ?? null)
            || !self::sha($adapter['raw_sha256'] ?? null)
            || !is_int($adapter['raw_size'] ?? null) || $adapter['raw_size'] < 1) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification does not bind the exact source/path/canonical manifest/trust tier"
            );
        }
        // Content supersession: the well-formed companion binds different
        // canonical (or raw) bytes than the adapter carries now — the adapter
        // was edited after certification. Signalled distinctly so scan() can
        // route it to uncertified support (see SupersededSiteAdapterCertificate).
        if (!hash_equals((string) $adapter['canonical_sha256'], self::canonicalHash($manifest))) {
            throw new SupersededSiteAdapterCertificate(
                "duo: site adapter '$name' certification binds a superseded manifest; the adapter changed since it was certified"
            );
        }
        if ($rawAdapter !== null
            && (!hash_equals((string) $adapter['raw_sha256'], hash('sha256', (string) $rawAdapter['raw']))
                || $adapter['raw_size'] !== (int) $rawAdapter['size'])) {
            throw new SupersededSiteAdapterCertificate(
                "duo: site adapter '$name' certification binds superseded raw bytes; the adapter changed since it was certified"
            );
        }
    }

    /** @return array{typed:object,array:array,ratification_typed:object,ratification:array,ratification_raw:string,info:array} */
    private static function verifyBundleForImport(
        string $bundleInput,
        string $evidenceRepo,
        string $name,
        array $manifest,
        string $adapterRaw,
        string $trustRoot
    ): array {
        [$bundleDir, $bundleFile] = self::bundleFile($bundleInput);
        [$bundleRaw, $bundleTyped, $bundle] = self::readBundleObjectFile($bundleFile, 'certification bundle manifest');
        unset($bundleRaw);
        $info = self::verifyBundleManifest($bundleTyped, $bundle, 'certification bundle manifest', $name, $trustRoot);
        self::assertBundleSubjectInput($info['bound_inputs'], $name, [
            'raw_sha256' => hash('sha256', $adapterRaw),
            'raw_size' => strlen($adapterRaw),
        ]);
        $evidenceRoot = self::repoRoot($evidenceRepo, 'certification evidence repository');
        [$ratificationRaw, $ratificationTyped, $ratification] = self::verifyBundleAssets(
            $bundleDir,
            $bundleTyped,
            $bundle,
            $info,
            $evidenceRoot
        );
        [, $disposition] = self::verifyRatification(
            $ratificationTyped,
            $ratification,
            $name,
            $manifest,
            $info,
            $ratificationRaw
        );
        unset($disposition);
        return [
            'typed' => $bundleTyped,
            'array' => $bundle,
            'ratification_typed' => $ratificationTyped,
            'ratification' => $ratification,
            'ratification_raw' => $ratificationRaw,
            'info' => $info,
        ];
    }

    /** @return array{0:string,1:string} */
    private static function bundleFile(string $input): array {
        $candidate = is_dir($input) ? rtrim($input, '/') . '/bundle.json' : $input;
        if (basename($candidate) !== 'bundle.json' || !is_file($candidate) || is_link($candidate)) {
            throw new \RuntimeException('duo: certification bundle input must be a regular bundle.json file or its directory');
        }
        $dir = realpath(dirname($candidate));
        $file = realpath($candidate);
        if ($dir === false || $file === false || !hash_equals($dir . '/bundle.json', $file)) {
            throw new \RuntimeException('duo: certification bundle must resolve to an exact bundle.json inside its directory');
        }
        return [$dir, $file];
    }

    /**
     * Structural bundle verification shared by imported and embedded bundles.
     * The importer additionally opens every descriptor; runtime validates the
     * signed descriptors and content-addressed bundle manifest without finding
     * an arbitrary external evidence directory.
     *
     * @return array{tests:array<string,bool>,assets:array<string,array>,bound_inputs:list<array>,ratification_asset:array,summary:array,bundle_digest:string,git_revision:string,exercised:bool,created_at:string}
     */
    private static function verifyBundleManifest(
        object $typed,
        array $bundle,
        string $label,
        string $name,
        string $trustRoot
    ): array {
        self::assertExactKeys($bundle, [
            'artifacts', 'bound_inputs', 'bundle_digest', 'created_at', 'environment', 'environment_summary',
            'evidence', 'force_hatches', 'git_revision', 'harness', 'ratification', 'ratification_summary',
            'schema_version', 'subject', 'tests', 'verdict',
        ], $label);
        foreach (['environment', 'environment_summary', 'evidence', 'harness', 'ratification', 'ratification_summary'] as $key) {
            if (!isset($typed->$key) || !is_object($typed->$key)
                || !is_array($bundle[$key] ?? null) || array_is_list($bundle[$key])) {
                throw new \RuntimeException("duo: $label.$key must be an object");
            }
        }
        foreach (['artifacts', 'bound_inputs', 'force_hatches', 'tests'] as $key) {
            if (!isset($typed->$key) || !is_array($typed->$key)
                || !is_array($bundle[$key] ?? null) || !array_is_list($bundle[$key])) {
                throw new \RuntimeException("duo: $label.$key must be a JSON list");
            }
        }
        if (!isset($typed->subject) || !is_object($typed->subject)
            || !is_array($bundle['subject'] ?? null) || array_is_list($bundle['subject'])) {
            throw new \RuntimeException("duo: $label.subject must be an object");
        }
        self::assertExactKeys($bundle['subject'], ['kind', 'name'], "$label.subject");
        if (($bundle['subject']['kind'] ?? null) !== 'site_adapter'
            || ($bundle['subject']['name'] ?? null) !== $name) {
            throw new \RuntimeException(
                "duo: $label must be scoped exactly to site_adapter.$name"
            );
        }
        if (($bundle['schema_version'] ?? null) !== self::BUNDLE_FORMAT
            || ($bundle['verdict'] ?? null) !== 'pass'
            || !self::sha($bundle['bundle_digest'] ?? null)
            || !hash_equals((string) $bundle['bundle_digest'], self::bundleDigest($bundle))
            || !is_string($bundle['created_at'] ?? null) || strtotime($bundle['created_at']) === false
            || !is_string($bundle['git_revision'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/D', $bundle['git_revision']) !== 1
            || !is_string($bundle['harness']['name'] ?? null) || $bundle['harness']['name'] === ''
            || !is_int($bundle['harness']['version'] ?? null) || $bundle['harness']['version'] < 1) {
            throw new \RuntimeException("duo: $label is not an intact passing " . self::BUNDLE_FORMAT . ' manifest');
        }
        self::assertExactKeys($bundle['harness'], ['name', 'version'], "$label.harness");
        $exercised = self::bundleEvidence($bundle['evidence'], $label, $trustRoot);
        // An external certificate is not a vehicle for force-flag approval.
        // Binding a non-empty list would make it visible, but still turns an
        // override into a green site claim, which this source never permits.
        if ($bundle['force_hatches'] !== []) {
            throw new \RuntimeException('duo: a site adapter certification bundle may not use force hatches');
        }
        self::assertExactKeys($bundle['ratification_summary'], ['certified_claims', 'manifest_count', 'profile_count'], "$label.ratification_summary");
        if (!is_int($bundle['ratification_summary']['manifest_count'] ?? null)
            || !is_int($bundle['ratification_summary']['profile_count'] ?? null)) {
            throw new \RuntimeException("duo: $label.ratification_summary is malformed");
        }

        $assets = [];
        $environment = self::assetDescriptor($bundle['environment'], "$label.environment", 'environment.json');
        $ratification = self::assetDescriptor($bundle['ratification'], "$label.ratification", 'ratification.json');
        $assets[$environment['path']] = $environment;
        $assets[$ratification['path']] = $ratification;

        $bound = [];
        $boundSeen = [];
        foreach ($bundle['bound_inputs'] as $i => $input) {
            $descriptor = self::assetDescriptor($input, "$label.bound_inputs[$i]");
            if (isset($boundSeen[$descriptor['path']])) {
                throw new \RuntimeException("duo: $label has duplicate bound input '{$descriptor['path']}'");
            }
            $boundSeen[$descriptor['path']] = true;
            $bound[] = $descriptor;
        }
        if ($bound === []) {
            throw new \RuntimeException("duo: $label has no bound code/manifest/harness inputs");
        }

        foreach ($bundle['artifacts'] as $i => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                throw new \RuntimeException("duo: $label.artifacts[$i] must be an object");
            }
            self::assertExactKeys($artifact, ['name', 'role', 'sha256', 'url', 'version'], "$label.artifacts[$i]");
            if (!is_string($artifact['name'] ?? null) || $artifact['name'] === ''
                || !is_string($artifact['version'] ?? null) || $artifact['version'] === ''
                || !self::sha($artifact['sha256'] ?? null)
                || !filter_var($artifact['url'] ?? '', FILTER_VALIDATE_URL)
                || !in_array($artifact['role'] ?? null, ['certified-boundary', 'refusal-fixture'], true)) {
                throw new \RuntimeException("duo: $label.artifacts[$i] is malformed");
            }
        }

        $tests = [];
        foreach ($bundle['tests'] as $i => $test) {
            if (!is_array($test) || array_is_list($test)) {
                throw new \RuntimeException("duo: $label.tests[$i] must be an object");
            }
            self::assertExactKeys($test, ['diff', 'id', 'log', 'result', 'verdict'], "$label.tests[$i]");
            $id = self::testId($test['id'] ?? null, "$label.tests[$i].id");
            if (isset($tests[$id]) || ($test['verdict'] ?? null) !== 'pass') {
                throw new \RuntimeException("duo: $label must contain each named passing test exactly once");
            }
            $result = self::assetDescriptor($test['result'], "$label.tests[$i].result", "results/$id.json");
            $diff = self::assetDescriptor($test['diff'], "$label.tests[$i].diff", "diffs/$id.json");
            $log = self::assetDescriptor($test['log'], "$label.tests[$i].log", "logs/$id.txt");
            foreach ([$result, $diff, $log] as $asset) {
                if (isset($assets[$asset['path']])) {
                    throw new \RuntimeException("duo: $label reuses bundle asset path '{$asset['path']}'");
                }
                $assets[$asset['path']] = $asset;
            }
            $tests[$id] = true;
        }
        if ($exercised && $tests === []) {
            throw new \RuntimeException("duo: $label has no named tests");
        }
        if (!$exercised && ($tests !== [] || $bundle['artifacts'] !== [])) {
            // `exercised: false` is a claim about what was NOT done. A bundle
            // that also carried tests or artifacts would be asserting both
            // halves at once, and the projection an operator reads
            // (`exercised: false` beside a named artifact list) would be the
            // exact ambiguity this key exists to remove.
            throw new \RuntimeException(
                "duo: $label declares evidence.exercised false but names tests or artifacts"
            );
        }
        return [
            'tests' => $tests,
            'assets' => $assets,
            'bound_inputs' => $bound,
            'created_at' => (string) $bundle['created_at'],
            'exercised' => $exercised,
            'ratification_asset' => $ratification,
            'summary' => $bundle['ratification_summary'],
            'bundle_digest' => $bundle['bundle_digest'],
            'git_revision' => $bundle['git_revision'],
        ];
    }

    /**
     * What this bundle actually proves, stated by the bundle itself.
     *
     * Before T6 the grammar had exactly one admissible shape — a passing
     * exercise with named tests and result/diff/log assets — and every
     * certificate silently meant that. An operator certifying their OWN
     * adapter cannot produce it: there is no reviewed conformance harness for
     * an adapter that was authored ten minutes ago, and the honest evidence is
     * the grammar verdict plus the operator's stated reason. So the fact is
     * now DECLARED rather than implied, in one grammar with two admissible
     * shapes, and the weaker one is admissible only under the SITE root:
     *
     *   exercised: true  — today's rule, unchanged, and the only shape a
     *                      platform-rooted certificate may take.
     *   exercised: false — no exercise proof; `tests` and `artifacts` must
     *                      both be empty, and the claim carries the fact
     *                      forward so `duo assess` prints it rather than
     *                      letting `certified` imply an exercise nobody ran.
     *
     * `grammar` must be `ok`: a certificate for a manifest the loader itself
     * refuses would be certifying bytes no command can use.
     *
     * @param mixed $evidence
     */
    private static function bundleEvidence($evidence, string $label, string $trustRoot): bool {
        if (!is_array($evidence) || array_is_list($evidence)) {
            throw new \RuntimeException("duo: $label.evidence must be an object");
        }
        self::assertExactKeys($evidence, ['exercised', 'grammar', 'reason'], "$label.evidence");
        $exercised = $evidence['exercised'] ?? null;
        if (!is_bool($exercised)
            || ($evidence['grammar'] ?? null) !== AdapterSources::GRAMMAR_OK
            || !is_string($evidence['reason'] ?? null) || trim((string) $evidence['reason']) === '') {
            throw new \RuntimeException(
                "duo: $label.evidence must declare a boolean exercised, grammar '" . AdapterSources::GRAMMAR_OK
                . "', and a non-empty reason"
            );
        }
        if (!$exercised && $trustRoot !== self::TRUST_ROOT_SITE) {
            throw new \RuntimeException(
                "duo: $label declares no exercise proof, which only a " . self::TRUST_ROOT_SITE
                . ' trust root may certify — a platform-rooted certificate states a reviewed exercise'
            );
        }
        return $exercised;
    }

    /** @return array{path:string,sha256:string,size:int} */
    private static function assetDescriptor($descriptor, string $label, ?string $expectedPath = null): array {
        if (!is_array($descriptor) || array_is_list($descriptor)) {
            throw new \RuntimeException("duo: $label must be an asset descriptor object");
        }
        self::assertExactKeys($descriptor, ['path', 'sha256', 'size'], $label);
        if (!is_string($descriptor['path'] ?? null)
            || !self::sha($descriptor['sha256'] ?? null)
            || !is_int($descriptor['size'] ?? null) || $descriptor['size'] < 0) {
            throw new \RuntimeException("duo: $label is malformed");
        }
        $path = self::relativePath($descriptor['path'], $label . '.path');
        if ($expectedPath !== null && !hash_equals($expectedPath, $path)) {
            throw new \RuntimeException("duo: $label path must be exactly $expectedPath");
        }
        return ['path' => $path, 'sha256' => $descriptor['sha256'], 'size' => $descriptor['size']];
    }

    private static function testId($value, string $label): string {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]*$/D', $value) !== 1) {
            throw new \RuntimeException("duo: $label must match ^[a-z][a-z0-9-]*$");
        }
        return $value;
    }

    private static function bundleDigest(array $bundle): string {
        $unsigned = $bundle;
        unset($unsigned['bundle_digest']);
        try {
            $json = json_encode(
                Canon::normalize($unsigned),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('duo: certification bundle is not canonicalizable: ' . $e->getMessage());
        }
        return hash('sha256', $json . "\n");
    }

    /**
     * A passing general bundle is not evidence for this adapter unless it
     * itself bound the exact raw source bytes.  Live signing compares those
     * bytes; frozen verification compares the same descriptor with the raw
     * binding already inside the signed adapter statement.
     */
    private static function assertBundleSubjectInput(array $boundInputs, string $name, array $adapter): void {
        $path = 'adapters/' . $name . '.json';
        $matches = [];
        foreach ($boundInputs as $input) {
            if (($input['path'] ?? null) === $path) {
                $matches[] = $input;
            }
        }
        if (count($matches) !== 1
            || !self::sha($adapter['raw_sha256'] ?? null)
            || !is_int($adapter['raw_size'] ?? null) || $adapter['raw_size'] < 1
            || !hash_equals((string) $matches[0]['sha256'], (string) $adapter['raw_sha256'])
            || $matches[0]['size'] !== $adapter['raw_size']) {
            throw new \RuntimeException(
                "duo: certification bundle must bind exactly current raw adapters/$name.json as one bound input"
            );
        }
    }

    /**
     * Import-time evidence verification.  The bundle manifest is not enough:
     * every described asset and every repository-bound source input must still
     * exist at the recorded bytes before a signing key is used.
     *
     * @return array{0:string,1:object,2:array}
     */
    private static function verifyBundleAssets(
        string $bundleDir,
        object $bundleTyped,
        array $bundle,
        array $info,
        string $evidenceRoot
    ): array {
        // Keep the exact bytes which passed the content-address check.  The
        // semantic checks below must parse these buffers, never reopen a path
        // after an attacker has had a chance to replace its contents.
        $verifiedAssets = [];
        foreach ($info['assets'] as $asset) {
            $verifiedAssets[$asset['path']] = self::readVerifiedBundleAsset($bundleDir, $asset);
        }
        foreach ($info['bound_inputs'] as $input) {
            $file = self::ownedFile($evidenceRoot, $input['path'], 'certification bundle bound input');
            $size = filesize($file);
            $digest = hash_file('sha256', $file);
            if ($size === false || $digest === false || $size !== $input['size']
                || !hash_equals($input['sha256'], $digest)) {
                throw new \RuntimeException(
                    "duo: certification bundle bound input is missing or tampered: {$input['path']}"
                );
            }
        }

        [, $environmentTyped] = self::parseCanonicalObject(
            $verifiedAssets['environment.json'],
            'certification bundle environment asset'
        );
        if (!hash_equals(Canon::encode($environmentTyped), Canon::encode($bundleTyped->environment_summary))) {
            throw new \RuntimeException('duo: certification bundle environment asset disagrees with environment_summary');
        }

        foreach ($bundle['tests'] as $test) {
            $id = (string) $test['id'];
            [, , $result] = self::parseBundleObject(
                $verifiedAssets['results/' . $id . '.json'],
                "certification bundle result '$id'"
            );
            if (($result['test'] ?? null) !== $id || ($result['verdict'] ?? null) !== 'pass'
                || ($result['exit_code'] ?? null) !== 0) {
                throw new \RuntimeException(
                    "duo: certification bundle result '$id' does not record a named passing zero-exit test"
                );
            }
            self::parseCanonicalObject(
                $verifiedAssets['diffs/' . $id . '.json'],
                "certification bundle diff '$id'"
            );
        }

        return self::parseCanonicalObject(
            $verifiedAssets['ratification.json'],
            'certification bundle ratification asset'
        );
    }

    /** @param array{path:string,sha256:string,size:int} $asset */
    private static function readVerifiedBundleAsset(string $bundleDir, array $asset): string {
        $file = self::ownedFile($bundleDir, $asset['path'], 'certification bundle asset');
        $raw = file_get_contents($file);
        if ($raw === false || strlen($raw) !== $asset['size']
            || !hash_equals($asset['sha256'], hash('sha256', $raw))) {
            throw new \RuntimeException(
                "duo: certification bundle asset is missing or tampered: {$asset['path']}"
            );
        }
        self::afterVerifiedBundleAssetRead($asset['path']);
        return $raw;
    }

    private static function afterVerifiedBundleAssetRead(string $path): void {
        if (self::$testVerifiedBundleAssetReadHook !== null) {
            (self::$testVerifiedBundleAssetReadHook)($path);
        }
    }

    /** @return array{0:array,1:array,2:string} */
    private static function verifyEmbeddedRatification(
        object $ratificationTyped,
        array $ratification,
        string $name,
        array $manifest,
        array $bundleInfo
    ): array {
        $raw = Canon::encode($ratificationTyped);
        [$data, $disposition] = self::verifyRatification(
            $ratificationTyped,
            $ratification,
            $name,
            $manifest,
            $bundleInfo,
            $raw
        );
        return [$data, $disposition, $raw];
    }

    /** @return array{tests:array<string,bool>,assets:array<string,array>,bound_inputs:list<array>,ratification_asset:array,summary:array,bundle_digest:string,git_revision:string,exercised:bool} */
    private static function verifyEmbeddedBundle(
        object $bundleTyped,
        array $bundle,
        string $name,
        string $trustRoot
    ): array {
        return self::verifyBundleManifest(
            $bundleTyped,
            $bundle,
            'signed certification bundle manifest',
            $name,
            $trustRoot
        );
    }

    /**
     * Validate the exact one-manifest ratification embedded in or imported
     * beside a bundle.  $ratificationRaw is canonical raw asset bytes when
     * importing and reconstructed canonical bytes at runtime.
     *
     * @return array{0:array,1:array}
     */
    private static function verifyRatification(
        object $ratificationTyped,
        array $ratification,
        string $name,
        array $manifest,
        array $bundleInfo,
        string $ratificationRaw
    ): array {
        self::assertExactKeys($ratification, ['format', 'manifests', 'profiles'], 'site adapter ratification');
        if (($ratification['format'] ?? null) !== self::RATIFICATION_FORMAT
            || !isset($ratificationTyped->manifests) || !is_object($ratificationTyped->manifests)
            || !isset($ratificationTyped->profiles) || !is_array($ratificationTyped->profiles)
            || !is_array($ratification['manifests'] ?? null) || array_is_list($ratification['manifests'])
            || !is_array($ratification['profiles'] ?? null) || !array_is_list($ratification['profiles'])
            || $ratification['profiles'] !== []) {
            throw new \RuntimeException(
                'duo: site adapter ratification must be a canonical one-manifest duo-manifest-dispositions/v1 document with profiles []'
            );
        }
        $ratifiedNames = array_keys($ratification['manifests']);
        foreach ($ratifiedNames as $ratifiedName) {
            if (!is_string($ratifiedName)) {
                throw new \RuntimeException(
                    'duo: site adapter ratification manifests map contains non-string key ' . var_export($ratifiedName, true)
                    . ' — numeric-only identities are forbidden because PHP coerces JSON object-map keys to integers'
                );
            }
            self::adapterName($ratifiedName);
        }
        if ($ratifiedNames !== [$name]
            || !isset($ratificationTyped->manifests->$name) || !is_object($ratificationTyped->manifests->$name)
            || !is_array($ratification['manifests'][$name]) || array_is_list($ratification['manifests'][$name])) {
            throw new \RuntimeException(
                "duo: site adapter ratification must name exactly manifests.$name"
            );
        }
        $disposition = $ratification['manifests'][$name];
        self::validateDisposition($name, $disposition, $manifest, (bool) $bundleInfo['exercised']);
        foreach ($disposition['evidence']['tests'] as $test) {
            if (!isset($bundleInfo['tests'][$test])) {
                throw new \RuntimeException(
                    "duo: certified site adapter '$name' cites absent or non-passing bundle test '$test'"
                );
            }
        }
        $expectedSummary = ['manifests.' . $name];
        if (($bundleInfo['summary']['certified_claims'] ?? null) !== $expectedSummary
            || ($bundleInfo['summary']['manifest_count'] ?? null) !== 1
            || ($bundleInfo['summary']['profile_count'] ?? null) !== 0) {
            throw new \RuntimeException(
                "duo: certification bundle ratification summary does not describe exactly certified manifests.$name"
            );
        }
        $asset = $bundleInfo['ratification_asset'];
        if (!hash_equals($asset['sha256'], hash('sha256', $ratificationRaw))
            || $asset['size'] !== strlen($ratificationRaw)) {
            throw new \RuntimeException(
                "duo: certification bundle ratification asset does not bind the exact ratification for '$name'"
            );
        }
        return [$ratification, $disposition];
    }

    private static function validateDisposition(
        string $name,
        array $disposition,
        array $manifest,
        bool $exercised
    ): void {
        // The authority-specific document is strict about the exact JSON
        // vocabulary it signs.  The semantic rules themselves are delegated to
        // ManifestDispositions so external and shipped entries cannot drift.
        self::assertExactKeys($disposition, [
            'capabilities', 'default_authored_keyspaces', 'evidence', 'reason', 'status', 'supported_versions', 'unsupported',
        ], "site adapter disposition '$name'");
        $capabilities = $disposition['capabilities'] ?? null;
        $evidence = $disposition['evidence'] ?? null;
        if (!is_array($capabilities) || array_is_list($capabilities)
            || !is_array($evidence) || array_is_list($evidence)) {
            throw new \RuntimeException("duo: site adapter disposition '$name' is malformed");
        }
        self::assertExactKeys($capabilities, [
            'deletion_semantics', 'entity_sections', 'field_sections', 'lifecycle_phases', 'operations',
        ], "site adapter disposition '$name'.capabilities");
        if (!is_array($capabilities['deletion_semantics'] ?? null)
            || array_is_list($capabilities['deletion_semantics'])) {
            throw new \RuntimeException("duo: site adapter disposition '$name' deletion semantics are malformed");
        }
        self::assertExactKeys($capabilities['deletion_semantics'], ['supported', 'unsupported'], "site adapter disposition '$name'.capabilities.deletion_semantics");
        self::assertExactKeys($evidence, ['bundle_schema', 'tests'], "site adapter disposition '$name'.evidence");
        foreach ((array) ($disposition['unsupported'] ?? []) as $i => $unsupported) {
            if (!is_array($unsupported) || array_is_list($unsupported)) {
                throw new \RuntimeException("duo: site adapter disposition '$name'.unsupported[$i] is malformed");
            }
            self::assertExactKeys($unsupported, ['operation', 'reason', 'surface'], "site adapter disposition '$name'.unsupported[$i]");
        }
        foreach ((array) ($disposition['default_authored_keyspaces'] ?? []) as $i => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException("duo: site adapter disposition '$name'.default_authored_keyspaces[$i] is malformed");
            }
            self::assertExactKeys($row, ['reason', 'status', 'table'], "site adapter disposition '$name'.default_authored_keyspaces[$i]");
        }
        ManifestDispositions::validate_external_entry(
            $name,
            $disposition,
            $manifest,
            self::BUNDLE_FORMAT,
            // An unexercised bundle has an empty test set by construction
            // (verifyBundleManifest() refuses any other shape), so a citation
            // requirement here would demand a test name that provably does not
            // exist. The bundle's own `exercised` flag is the single fact both
            // rules read.
            $exercised
        );
    }

    /**
     * The object inserted into Policy's existing disposition slot.  It binds
     * source identity and proof facts but deliberately does NOT calculate a
     * final adapter digest: integration derives that only after settling the
     * same disposition row into its normal manifest identity calculation.
     */
    private static function derivedDisposition(
        string $name,
        array $adapter,
        string $tier,
        array $ratifiedDisposition,
        array $authority,
        string $authorityDigest,
        array $bundle,
        string $platformDigest,
        string $ratificationRaw,
        string $certificateDigest,
        string $statementDigest
    ): array {
        unset($name);
        return [
            'certification' => 'certified',
            'provenance' => [
                // Preserve AdapterSources' source/path/canonical-manifest
                // vocabulary, with proof nested rather than widening the
                // source identity into an unauditable set of top-level facts.
                'format' => AdapterSources::FORMAT,
                'path' => $adapter['path'],
                'sha256' => $adapter['canonical_sha256'],
                'source' => $adapter['source'],
                'proof' => [
                    'authority' => [
                        'fingerprint' => $authority['fingerprint'],
                        'key_id' => $authority['key_id'],
                        'record_sha256' => $authorityDigest,
                        // Identity-bearing, like every other proof fact: the
                        // disposition is folded into the adapter digest and a
                        // repository pin binds that digest, so the same adapter
                        // re-certified under a different root is a different
                        // identity and every pin naming the old one refuses.
                        'trust_root' => $authority['trust_root'],
                    ],
                    'bundle' => [
                        'digest' => $bundle['bundle_digest'],
                        'exercised' => $bundle['exercised'],
                        'force_hatches' => [],
                        'git_revision' => $bundle['git_revision'],
                        'schema' => self::BUNDLE_FORMAT,
                        'signed_at' => $bundle['created_at'],
                        'tests' => $ratifiedDisposition['evidence']['tests'],
                    ],
                    'certificate_sha256' => $certificateDigest,
                    'platform_sha256' => $platformDigest,
                    'ratification_sha256' => hash('sha256', $ratificationRaw),
                    'statement_sha256' => $statementDigest,
                    'raw_input' => [
                        'sha256' => $adapter['raw_sha256'],
                        'size' => $adapter['raw_size'],
                    ],
                ],
            ],
            'reason' => $ratifiedDisposition['reason'],
            'status' => 'certified',
            'trust_tier' => $tier,
        ];
    }

    /**
     * A source-scoped current claim.  It shares generic capability projection
     * with shipped claims, but its evidence and platform are the signed row's
     * own data, not the global shipped registry's evidence status.
     */
    private static function projectClaim(
        string $name,
        array $manifest,
        array $disposition,
        array $derived,
        array $platform
    ): array {
        $proof = $derived['provenance']['proof'];
        $evidence = [
            'authority_record_sha256' => $proof['authority']['record_sha256'],
            'bundle_digest' => $proof['bundle']['digest'],
            'bundle_schema' => $proof['bundle']['schema'],
            'certificate_sha256' => $proof['certificate_sha256'],
            // Carried onto the claim so a reader of `status: certified` can
            // see what was and was not proved. A site certificate with no
            // exercise proof is still a certified claim — the customer
            // organization is the authority for its own site — but `certified`
            // must not be readable as "somebody ran it".
            'exercised' => $proof['bundle']['exercised'],
            'force_hatches' => $proof['bundle']['force_hatches'],
            'git_revision' => $proof['bundle']['git_revision'],
            'platform_sha256' => $proof['platform_sha256'],
            'source' => AdapterSources::SITE,
            'status' => 'current',
            'statement_sha256' => $proof['statement_sha256'],
            'tests' => $proof['bundle']['tests'],
        ];
        $claim = ManifestDispositions::claim_from_disposition(
            $manifest,
            $disposition,
            $evidence,
            $platform,
            [
                'mode' => 'unmodified',
                'status' => 'verified',
            ]
        );
        // The generic projection has no source-specific facts.  Keep them
        // alongside it, while deliberately omitting an adapter digest: the
        // caller computes that after it installs this disposition into the
        // normal adapter identity row.
        $claim['name'] = $name;
        // The four facts the host projection needs to print `Site-certified`
        // with a principal (T6 §3.6) and nothing else: WHO certified, under
        // WHICH root, from WHICH adapter source, and WHEN. Every one is a
        // projection of the signed statement — `principal` is the authority
        // key id, `signed_at` is the bundle's own created_at, both inside the
        // signature — so the human line cannot drift from the claim that
        // `duo promote` gates on.
        $claim['certification'] = [
            'principal' => $derived['provenance']['proof']['authority']['key_id'],
            'signed_at' => $derived['provenance']['proof']['bundle']['signed_at'],
            'source' => AdapterSources::SITE,
            'trust_root' => $derived['provenance']['proof']['authority']['trust_root'],
        ];
        $claim['provenance'] = $derived['provenance'];
        $claim['trust_tier'] = $derived['trust_tier'];
        $claim['provider_code'] = [
            'binding' => 'providers_negotiation',
            'runtime_tree_digest' => 'not_bound',
        ];
        return $claim;
    }
}
