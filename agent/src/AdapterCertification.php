<?php
namespace Duo;

require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/ManifestDispositions.php';
require_once __DIR__ . '/CapabilityRegistry.php';

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
final class AdapterCertification {
    public const FORMAT = 'duo-adapter-certification/v1';
    // Frozen policy snapshots carry the exact certificate format too; there
    // is no weaker second envelope protocol to accidentally accept.
    public const ENVELOPE_FORMAT = self::FORMAT;
    public const AUTHORITIES_FORMAT = 'duo-adapter-authorities/v1';
    public const BUNDLE_FORMAT = 'duo-certification-bundle/v1';
    public const RATIFICATION_FORMAT = 'duo-manifest-dispositions/v1';

    /** Kept independent from JSON framing so this signature cannot verify elsewhere. */
    public const SIGNATURE_DOMAIN = "duo-site-adapter-certification-signature/v1\0";

    private const AUTHORITIES_RELATIVE = 'capabilities/adapter-authorities.json';
    private const PLATFORM_RELATIVE = 'capabilities/registry.json';
    private const CERTIFICATE_DIR = 'adapters/certifications';

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
        self::assertSiteManifest($name, $adapterDecoded);
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
            ['raw' => $adapterRaw, 'size' => strlen($adapterRaw)]
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
        self::assertSiteManifest($name, $manifest);
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
        $out = [];
        foreach (scandir($directory) ?: [] as $entry) {
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
        self::assertSiteManifest($name, $manifest);

        [$authority, $keyId, $authorityDigest] = self::authority($manifestDir, $authorityId);
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

        $bundle = self::verifyBundleForImport($bundleInput, $evidenceRepo, $name, $manifest, $adapterRaw);
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
            'authority' => [
                'fingerprint' => hash('sha256', $configured),
                'key_id' => $keyId,
                'record_sha256' => $authorityDigest,
            ],
            // Keep the exact, verified bundle manifest and ratification as
            // objects.  The signature binds their content-addressed identity,
            // all declared asset descriptors, and all named test claims.
            'bundle' => $bundle['typed'],
            'platform' => $platform,
            'ratification' => $bundle['ratification_typed'],
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
        return [
            'authority' => $proof['authority'] ?? null,
            'bundle_digest' => $proof['bundle']['digest'] ?? null,
            'name' => $verified['claim']['name'] ?? null,
            'status' => $verified['claim']['status'] ?? null,
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
        ?array $rawAdapter
    ): array {
        self::assertCertificateShape($certificateTyped, $certificate);
        $statementTyped = $certificateTyped->statement;
        $statement = $certificate['statement'];
        self::assertStatementShape($statementTyped, $statement);

        $adapter = $statement['adapter'];
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAdapterBinding($name, $manifest, $adapter, $tier, $rawAdapter);

        $selectedAuthority = $statement['authority']['key_id'] ?? null;
        if (!is_string($selectedAuthority)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority key_id must be a canonical string selector"
            );
        }
        [$authority, $keyId, $authorityDigest] = self::authority($manifestDir, $selectedAuthority);
        self::assertAuthorityBinding($authority, $keyId, $authorityDigest, $statement['authority'], $name, $tier);
        $signature = base64_decode((string) $certificate['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $signature,
                self::signatureBytes($statementTyped),
                self::publicKey($authority)
            )) {
            throw new \RuntimeException("duo: site adapter '$name' certification has an invalid Ed25519 signature");
        }

        [$platform, $platformDigest] = self::currentPlatform($manifestDir);
        if (!hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification platform boundary disagrees with the current agent-owned platform"
            );
        }

        $bundle = self::verifyEmbeddedBundle($statementTyped->bundle, $statement['bundle']);
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
            $statement['authority'],
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
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $name) !== 1) {
            throw new \RuntimeException(
                "duo: site adapter certification name " . var_export($name, true)
                . ' must be one lowercase path-free adapter basename'
            );
        }
        return $name;
    }

    private static function keyId(string $id): string {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $id) !== 1) {
            throw new \RuntimeException("duo: authority key id " . var_export($id, true) . ' is malformed');
        }
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

    private static function assertSiteManifest(string $name, array $manifest): void {
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
        AdapterSources::assert_out_of_tree_contract($manifest, $name, 'adapters/' . $name . '.json');
    }

    private static function assertSameManifest(string $name, array $provided, array $raw): void {
        if (!hash_equals(self::canonicalHash($raw), self::canonicalHash($provided))) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certificate was asked to verify manifest bytes different from adapters/$name.json"
            );
        }
    }

    /** @return array{0:array,1:string,2:string} [record, key id, canonical record digest] */
    private static function authority(string $manifestDir, string $id): array {
        $id = self::keyId($id);
        $file = rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE;
        if (!is_file($file)) {
            throw new \RuntimeException(
                'duo: no agent-owned adapter certification authorities are installed at '
                . self::AUTHORITIES_RELATIVE
            );
        }
        [, $typed, $data] = self::readCanonicalObjectFile($file, 'adapter certification authorities');
        self::assertExactKeys($data, ['format', 'keys'], 'adapter certification authorities');
        if (($data['format'] ?? null) !== self::AUTHORITIES_FORMAT
            || !is_array($data['keys'] ?? null)
            || !isset($typed->keys) || !is_object($typed->keys)) {
            throw new \RuntimeException('duo: adapter certification authorities have an unsupported or malformed root');
        }
        $found = null;
        foreach ($data['keys'] as $keyId => $record) {
            self::keyId((string) $keyId);
            if (!is_array($record) || array_is_list($record)) {
                throw new \RuntimeException("duo: adapter certification key '$keyId' must be an object");
            }
            if (!isset($typed->keys->$keyId) || !is_object($typed->keys->$keyId)) {
                throw new \RuntimeException("duo: adapter certification key '$keyId' must be a JSON object");
            }
            self::validateAuthorityRecord($record, "adapter certification key '$keyId'");
            if ($keyId === $id) {
                $found = $record;
            }
        }
        if ($found === null) {
            throw new \RuntimeException("duo: agent-owned authority key '$id' is not installed");
        }
        return [$found, $id, self::canonicalHash($found)];
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
            if (!in_array($tier, [
                AdapterSources::TIER_DECLARATIVE,
                AdapterSources::TIER_NATIVE_ACTION,
                AdapterSources::TIER_PLUGIN_PROVIDER,
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
        array $statementAuthority,
        string $name,
        string $tier
    ): void {
        self::assertExactKeys($statementAuthority, ['fingerprint', 'key_id', 'record_sha256'], 'certification authority binding');
        if (($statementAuthority['key_id'] ?? null) !== $keyId
            || ($statementAuthority['fingerprint'] ?? null) !== hash('sha256', self::publicKey($authority))
            || !self::sha($statementAuthority['record_sha256'] ?? null)
            || !hash_equals((string) $statementAuthority['record_sha256'], $authorityDigest)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority/key/fingerprint does not match the current agent-owned authority record"
            );
        }
        self::assertAuthorityScope($authority, $keyId, $name, $tier);
    }

    /** @return array{0:array,1:string} */
    private static function currentPlatform(string $manifestDir): array {
        $file = rtrim($manifestDir, '/') . '/' . self::PLATFORM_RELATIVE;
        if (!is_file($file)) {
            throw new \RuntimeException(
                'duo: current agent platform boundary is absent at capabilities/registry.json'
            );
        }
        [, $typed, $data] = self::readCanonicalObjectFile($file, 'agent capability registry');
        if (($data['format'] ?? null) !== 'duo-capability-registry/v1'
            || !isset($typed->platform) || !is_object($typed->platform)
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])) {
            throw new \RuntimeException('duo: agent capability registry has no valid platform boundary');
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
        if (($adapter['name'] ?? null) !== $name
            || ($adapter['path'] ?? null) !== $expectedPath
            || ($adapter['source'] ?? null) !== AdapterSources::SITE
            || ($adapter['trust_tier'] ?? null) !== $derivedTier
            || !self::sha($adapter['canonical_sha256'] ?? null)
            || !self::sha($adapter['raw_sha256'] ?? null)
            || !is_int($adapter['raw_size'] ?? null) || $adapter['raw_size'] < 1
            || !hash_equals((string) $adapter['canonical_sha256'], self::canonicalHash($manifest))) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification does not bind the exact source/path/canonical manifest/trust tier"
            );
        }
        if ($rawAdapter !== null
            && (!hash_equals((string) $adapter['raw_sha256'], hash('sha256', (string) $rawAdapter['raw']))
                || $adapter['raw_size'] !== (int) $rawAdapter['size'])) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification does not bind the current raw adapters/$name.json input"
            );
        }
    }

    /** @return array{typed:object,array:array,ratification_typed:object,ratification:array,ratification_raw:string,info:array} */
    private static function verifyBundleForImport(
        string $bundleInput,
        string $evidenceRepo,
        string $name,
        array $manifest,
        string $adapterRaw
    ): array {
        [$bundleDir, $bundleFile] = self::bundleFile($bundleInput);
        [$bundleRaw, $bundleTyped, $bundle] = self::readBundleObjectFile($bundleFile, 'certification bundle manifest');
        unset($bundleRaw);
        $info = self::verifyBundleManifest($bundleTyped, $bundle, 'certification bundle manifest');
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
     * @return array{tests:array<string,bool>,assets:array<string,array>,bound_inputs:list<array>,ratification_asset:array,summary:array,bundle_digest:string,git_revision:string}
     */
    private static function verifyBundleManifest(object $typed, array $bundle, string $label): array {
        self::assertExactKeys($bundle, [
            'artifacts', 'bound_inputs', 'bundle_digest', 'created_at', 'environment', 'environment_summary',
            'force_hatches', 'git_revision', 'harness', 'ratification', 'ratification_summary', 'schema_version',
            'tests', 'verdict',
        ], $label);
        foreach (['environment', 'environment_summary', 'harness', 'ratification', 'ratification_summary'] as $key) {
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
        if ($tests === []) {
            throw new \RuntimeException("duo: $label has no named tests");
        }
        return [
            'tests' => $tests,
            'assets' => $assets,
            'bound_inputs' => $bound,
            'ratification_asset' => $ratification,
            'summary' => $bundle['ratification_summary'],
            'bundle_digest' => $bundle['bundle_digest'],
            'git_revision' => $bundle['git_revision'],
        ];
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
        foreach ($info['assets'] as $asset) {
            $file = self::ownedFile($bundleDir, $asset['path'], 'certification bundle asset');
            $size = filesize($file);
            $digest = hash_file('sha256', $file);
            if ($size === false || $digest === false || $size !== $asset['size']
                || !hash_equals($asset['sha256'], $digest)) {
                throw new \RuntimeException(
                    "duo: certification bundle asset is missing or tampered: {$asset['path']}"
                );
            }
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

        $environmentAsset = self::ownedFile($bundleDir, 'environment.json', 'certification bundle environment asset');
        [, $environmentTyped] = self::readCanonicalObjectFile($environmentAsset, 'certification bundle environment asset');
        if (!hash_equals(Canon::encode($environmentTyped), Canon::encode($bundleTyped->environment_summary))) {
            throw new \RuntimeException('duo: certification bundle environment asset disagrees with environment_summary');
        }

        foreach ($bundle['tests'] as $test) {
            $id = (string) $test['id'];
            $resultPath = self::ownedFile($bundleDir, 'results/' . $id . '.json', 'certification bundle result asset');
            [, , $result] = self::readBundleObjectFile($resultPath, "certification bundle result '$id'");
            if (($result['test'] ?? null) !== $id || ($result['verdict'] ?? null) !== 'pass'
                || ($result['exit_code'] ?? null) !== 0) {
                throw new \RuntimeException(
                    "duo: certification bundle result '$id' does not record a named passing zero-exit test"
                );
            }
            $diffPath = self::ownedFile($bundleDir, 'diffs/' . $id . '.json', 'certification bundle diff asset');
            self::readCanonicalObjectFile($diffPath, "certification bundle diff '$id'");
        }

        $ratificationPath = self::ownedFile($bundleDir, 'ratification.json', 'certification bundle ratification asset');
        return self::readCanonicalObjectFile($ratificationPath, 'certification bundle ratification asset');
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

    /** @return array{tests:array<string,bool>,assets:array<string,array>,bound_inputs:list<array>,ratification_asset:array,summary:array,bundle_digest:string,git_revision:string} */
    private static function verifyEmbeddedBundle(object $bundleTyped, array $bundle): array {
        return self::verifyBundleManifest($bundleTyped, $bundle, 'signed certification bundle manifest');
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
        if (array_keys($ratification['manifests']) !== [$name]
            || !isset($ratificationTyped->manifests->$name) || !is_object($ratificationTyped->manifests->$name)
            || !is_array($ratification['manifests'][$name]) || array_is_list($ratification['manifests'][$name])) {
            throw new \RuntimeException(
                "duo: site adapter ratification must name exactly manifests.$name"
            );
        }
        $disposition = $ratification['manifests'][$name];
        self::validateDisposition($name, $disposition, $manifest);
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

    private static function validateDisposition(string $name, array $disposition, array $manifest): void {
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
        ManifestDispositions::validate_external_entry($name, $disposition, $manifest);
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
                    ],
                    'bundle' => [
                        'digest' => $bundle['bundle_digest'],
                        'force_hatches' => [],
                        'git_revision' => $bundle['git_revision'],
                        'schema' => self::BUNDLE_FORMAT,
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
            'force_hatches' => $proof['bundle']['force_hatches'],
            'git_revision' => $proof['bundle']['git_revision'],
            'platform_sha256' => $proof['platform_sha256'],
            'source' => AdapterSources::SITE,
            'status' => 'current',
            'statement_sha256' => $proof['statement_sha256'],
            'tests' => $proof['bundle']['tests'],
        ];
        $claim = CapabilityRegistry::claim_from_disposition(
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
        $claim['provenance'] = $derived['provenance'];
        $claim['trust_tier'] = $derived['trust_tier'];
        $claim['provider_code'] = [
            'binding' => 'providers_negotiation',
            'runtime_tree_digest' => 'not_bound',
        ];
        return $claim;
    }
}
