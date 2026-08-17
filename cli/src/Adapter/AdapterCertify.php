<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;

/**
 * `duo adapter keygen | certify | pin` — the operator's own certification
 * authority for the adapters they author (round-3 T6 §3.1, §3.5).
 *
 * ## Why this exists
 *
 * Before T6 the only certification path signed
 * `adapters/certifications/<name>.json` under a key in the **agent-owned**
 * `manifests/capabilities/adapter-authorities.json`, which ships empty. An
 * operator could author a site adapter, install it, and use it for
 * capture/plan/apply — and could never make it anything but `uncertified`,
 * so `duo release`/`duo promote` refused it forever ("only certified
 * adapters may enter promotion"). The product spec has always said
 * *Site-certified* means "customer-organization approval through Duo's
 * certification protocol, explicitly not a Duo endorsement", signed "under a
 * platform **or customer-organization** trust root". These three verbs are
 * the customer-organization half, made reachable.
 *
 * ## What this does NOT do, and says so
 *
 * A certificate minted here attests to exactly two things: *this
 * organization's key approves* **these exact adapter bytes**, and *the
 * engine's own validators accept the manifest's grammar*. It does not
 * attest that the adapter was exercised against a live site, that its
 * deletion semantics were reviewed, or that Duo endorses it. That is why
 * the bundle this class builds carries a single named test
 * (`manifest-grammar`) whose result records `exercised: false` beside the
 * grammar verdict and the operator's own stated reason — the evidence
 * triple T6 §3.5 requires. `duo adapter list` reads `site_signed` and the
 * projection reads `Site-certified`, never `Platform-certified`
 * (T6 §3.2/§3.3).
 *
 * ## The bundle, and why it is the shipped grammar rather than a new one
 *
 * `AdapterCertification` already owns a complete, adversarially-tested
 * `duo-site-adapter-certification-bundle/v1` grammar: content-addressed
 * assets, a bound input binding the exact raw adapter bytes, a
 * one-manifest `duo-manifest-dispositions/v1` ratification, and a bundle
 * digest the Ed25519 signature covers. Nothing in T6 needs that weakened —
 * what was missing was a *producer* an operator could run. So this class
 * produces that exact grammar rather than asking the agent for a second,
 * laxer one: one bundle format means one verifier, and the properties the
 * adversarial matrix in `regress_site_adapter_certification.php` already
 * proves keep applying to an operator-authored certificate unchanged.
 *
 * Two fields carry the "grammar only, nothing exercised" fact explicitly
 * rather than by omission:
 *
 *  - `artifacts: []` — the half of a certified adapter's version story that
 *    says *what was exercised and at which version*. Empty is the honest
 *    answer here and it is a stated empty, not a dropped field.
 *  - `git_revision` is 40 zeros. It binds the revision of an *evidence
 *    repository*, and this profile has none; the site repository's own HEAD
 *    would be a number that looks like provenance while proving nothing
 *    about how the adapter was tested.
 *
 * ## The one thing this class depends on the agent for
 *
 * `AdapterCertification::sign()` resolves the signing key through
 * `authority()`, which reads the agent-owned authorities file. T6 §3.1 adds
 * the site trust root (`<repo>/adapters/authorities.json`, same
 * `duo-adapter-authorities/v1` grammar, trusted **only** for adapters in
 * that repository, shipped record wins on a collision). This class writes
 * that file and then calls `sign()` unchanged. Until the agent consults it,
 * `certify` fails loudly with the engine's own "authority key '<id>' is not
 * installed" — a named missing piece, never a silent downgrade.
 *
 * WordPress-free, no environment, no transport: every verb here reads and
 * writes files on the machine running `duo`.
 */
final class AdapterCertify {
    /** The sub-verbs this class owns; `AdapterCatalog` owns list/inspect/doctor. */
    public const VERBS = ['keygen', 'certify', 'pin'];

    /** T6 §3.1: the SITE trust root, beside the adapters it is trusted for. */
    public const AUTHORITIES_RELATIVE = 'adapters/authorities.json';

    /** The one named test a grammar-only certification bundle carries. */
    public const GRAMMAR_TEST = 'manifest-grammar';

    /**
     * No evidence repository exists in this profile, so the bundle binds no
     * source revision. Stated as an explicit all-zero revision rather than
     * omitted: `verifyBundleManifest()` requires 40 lowercase hex, and a
     * borrowed site-repo HEAD would read as provenance it is not.
     */
    public const NO_EVIDENCE_REVISION = '0000000000000000000000000000000000000000';

    /**
     * Manifest sections `ManifestDispositions::validate_entry()` accepts as
     * entity sections, from the shipped `manifests/dispositions.json`'s own
     * vocabulary. A derived disposition names only the sections the manifest
     * actually declares, so this is a filter and never an assertion.
     */
    public const ENTITY_SECTIONS = [
        'post_types', 'tables', 'taxonomies', 'taxonomy_patterns', 'widgets',
    ];

    /** The field-section half of the same vocabulary. */
    public const FIELD_SECTIONS = [
        'block_attrs', 'dynamic_options', 'interpreter', 'menu_fields', 'meta_patterns',
        'option_name_refs', 'option_namespaces', 'option_patterns', 'options',
        'post_meta', 'shortcode_attrs', 'term_meta', 'user_meta',
    ];

    /**
     * The operations a grammar-only site certification claims.
     *
     * `delete` is deliberately absent and is separately declared
     * unsupported: deletion semantics are the one capability that cannot be
     * inferred from a manifest's grammar, and a certificate that claimed
     * them from a validator run would be claiming a review nobody did.
     * `promote` is not listed because `CapabilityRegistry` mints it from
     * `deploy` + `apply` itself — listing it would be a second spelling of
     * the same claim.
     */
    public const CLAIMED_OPERATIONS = ['apply', 'capture', 'compile', 'deploy', 'plan', 'recapture'];

    /** The stated boundary every certificate minted here carries. */
    public const DELETION_UNSUPPORTED_REASON =
        'deletion semantics were not reviewed: this certificate attests to manifest grammar and to the '
        . 'organization\'s approval of these exact adapter bytes, not to delete behaviour';

    /** Default `--reason` when the operator states none. */
    public const DEFAULT_REASON =
        'The site organization approves these exact adapter bytes. The engine\'s own manifest validators '
        . 'accept its grammar; nothing was exercised against a live site.';

    /**
     * @param list<string> $args the arguments after `duo adapter`
     */
    public static function run(array $args): int {
        $verb = $args[0] ?? '';
        $rest = array_slice($args, 1);

        try {
            return match ($verb) {
                'keygen' => self::keygen($rest),
                'certify' => self::certify($rest),
                'pin' => self::pin($rest),
                default => self::fail("unknown subcommand '$verb'"),
            };
        } catch (\Throwable $t) {
            // Every refusal below is either an operator input error or the
            // engine's own verbatim message. Both are exit 2 usage/IO in
            // this command family (`duo adapter`'s existing contract), and
            // the engine's coordinates are surfaced unchanged.
            return self::fail($t->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // keygen
    // -----------------------------------------------------------------

    /**
     * `duo adapter keygen --out=<secret-key-file> [--key-id=<id>]`
     *
     * @param list<string> $args
     */
    private static function keygen(array $args): int {
        $flags = self::flags($args, ['out', 'key-id'], []);
        if ($flags['positional'] !== []) {
            return self::fail("keygen takes no positional argument, got '{$flags['positional'][0]}'");
        }
        $out = (string) ($flags['out'] ?? '');
        if ($out === '') {
            return self::fail('--out=<secret-key-file> is required: keygen writes the private key to a path you name');
        }
        if (!function_exists('sodium_crypto_sign_keypair')) {
            return self::fail('this PHP has no libsodium; Ed25519 key generation is unavailable');
        }

        $directory = dirname($out);
        $resolvedDir = is_dir($directory) ? realpath($directory) : false;
        if ($resolvedDir === false) {
            return self::fail("--out '$out' names a directory that does not exist: $directory");
        }
        $path = $resolvedDir . '/' . basename($out);
        if (file_exists($path)) {
            // Never overwrite a private key. An operator who reran keygen
            // over a key already used to sign would silently orphan every
            // certificate that key signed.
            return self::fail("--out '$path' already exists; keygen never overwrites a private key");
        }
        // T6 §3.1: "Private keys never live in the repository." A site repo
        // is committed and published, so a secret written inside one is a
        // published secret the moment anybody runs `git add .` — which
        // `duo init`'s own next-steps block tells them to do.
        $inside = self::enclosingSiteRepo($resolvedDir);
        if ($inside !== null) {
            return self::fail(
                "--out '$path' is inside the duo site repository $inside — private keys never live in a "
                . 'repository that gets committed and published; name a path outside it'
            );
        }

        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $keyId = (string) ($flags['key-id'] ?? self::derivedKeyId($public));
        AdapterSources::assert_name($keyId, 'adapter keygen --key-id');

        // Exclusive create, then 0600 before a single secret byte is
        // written: the file is empty for the whole window in which its mode
        // is still the process umask's.
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return self::fail("cannot create the secret key file: $path");
        }
        try {
            if (!chmod($path, 0600)) {
                throw new \RuntimeException("cannot restrict the secret key file to mode 0600: $path");
            }
            if (fwrite($handle, base64_encode($secret) . "\n") === false) {
                throw new \RuntimeException("cannot write the secret key file: $path");
            }
        } catch (\Throwable $t) {
            fclose($handle);
            @unlink($path);
            throw $t;
        }
        fclose($handle);

        echo "key-id:     $keyId\n";
        echo 'public-key: ' . base64_encode($public) . "\n";
        echo "secret-key: $path (mode 0600, outside every site repository)\n";
        echo "\nThis is a CUSTOMER-ORGANIZATION trust root, not a Duo one. Back the secret up where you back up\n"
            . "your deploy keys; a lost key cannot re-sign, and a leaked key can certify any adapter in a\n"
            . "repository whose adapters/authorities.json names it.\n";
        echo "\nNext: duo adapter certify <site-repo> --name=<adapter> --secret-key-file=$path --pin\n";

        return 0;
    }

    // -----------------------------------------------------------------
    // certify
    // -----------------------------------------------------------------

    /**
     * `duo adapter certify <site-repo> --name=<n> --secret-key-file=<f>
     *  [--key-id=<id>] [--reason=<text>] [--pin]`
     *
     * @param list<string> $args
     */
    private static function certify(array $args): int {
        $flags = self::flags($args, ['name', 'secret-key-file', 'key-id', 'reason'], ['pin']);
        $repo = self::onlySiteRepo($flags['positional'], 'certify');
        if (is_int($repo)) {
            return $repo;
        }
        $name = (string) ($flags['name'] ?? '');
        if ($name === '') {
            return self::fail('--name=<adapter-name> is required');
        }
        $secretFile = (string) ($flags['secret-key-file'] ?? '');
        if ($secretFile === '') {
            return self::fail('--secret-key-file=<path> is required: certification is an Ed25519 signature');
        }
        $reason = trim((string) ($flags['reason'] ?? ''));
        if ($reason === '') {
            $reason = self::DEFAULT_REASON;
        }

        self::boot();
        AdapterSources::assert_name($name, 'adapter certify --name');

        $adapterPath = $repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json';
        if (!is_file($adapterPath) || is_link($adapterPath)) {
            return self::fail(
                "no site adapter at " . AdapterSources::SITE_DIR . "/$name.json in $repo — certification signs an "
                . 'installed site adapter; install it there first (duo adapter-draft <site-repo> --name=' . $name
                . ' --out=' . AdapterSources::SITE_DIR . "/$name.json)"
            );
        }
        $adapterRaw = (string) file_get_contents($adapterPath);
        $manifest = self::canonicalObject($adapterRaw, "site adapter $name.json");

        $secret = self::readSecretKey($secretFile);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $keyId = (string) ($flags['key-id'] ?? self::derivedKeyId($public));
        AdapterSources::assert_name($keyId, 'adapter certify --key-id');

        $tier = AdapterSources::trust_tier($manifest);
        $registered = self::registerAuthority($repo, $keyId, $public, $name, $tier);

        // The grammar half of the evidence triple: the REAL loader, over the
        // real site repository, exactly as `duo manifest-validate` runs it.
        $grammar = self::grammarVerdict($repo, $name);
        if ($grammar['status'] !== 'ok') {
            return self::fail(
                "site adapter '$name' does not load: {$grammar['message']}\n"
                . '       certification signs a manifest the engine accepts; run `duo manifest-validate '
                . AdapterSources::SITE_DIR . " --site=$repo --manifest=$name` and fix it first"
            );
        }

        $bundleDir = self::buildBundle($repo, $name, $manifest, $adapterRaw, $grammar, $reason);
        try {
            $manifestDir = Policy::manifests_dir();
            $certificate = AdapterCertification::sign(
                $manifestDir,
                $repo,
                $name,
                $bundleDir,
                // The site repository IS the evidence repository: the one
                // bound input this bundle declares is the adapter's own raw
                // bytes, which live there and nowhere else.
                $repo,
                $keyId,
                base64_encode($secret)
            );
            $certificatePath = self::writeCertificate($repo, $name, $certificate);
            $verified = AdapterCertification::verifyFile(
                $manifestDir,
                $repo,
                $name,
                $manifest,
                $certificatePath
            );
        } finally {
            self::removeTree($bundleDir);
        }

        $summary = AdapterCertification::certificateSummary($verified);
        $pinObject = self::pinObject($repo, $name, 'site');

        echo "certified:  $name (site adapter)\n";
        echo "authority:  $keyId (site trust root, " . self::AUTHORITIES_RELATIVE
            . ($registered ? ' — key registered by this run' : ' — key already registered') . ")\n";
        echo 'trust tier: ' . (string) ($summary['trust_tier'] ?? $tier) . "\n";
        echo 'claim:      ' . (string) ($summary['status'] ?? 'certified') . "\n";
        echo 'evidence:   grammar=' . $grammar['status'] . ', exercised=false, reason stated in the signed bundle' . "\n";
        echo 'certificate: ' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR . "/$name.json\n";
        echo "\npin object for site.duo.json manifests[]:\n";
        echo rtrim(Canon::encode($pinObject)) . "\n";

        if (($flags['pin'] ?? false) === true) {
            $changed = self::writePin($repo, $pinObject);
            echo "\n" . ($changed ? 'wrote' : 'confirmed') . ' the pin in site.duo.json'
                . " — the certificate binds these exact bytes, so re-run certify after any edit\n";
        } else {
            echo "\nThe adapter stays UNCERTIFIED until this exact object is in site.duo.json manifests[]:\n"
                . '  a certificate without an exact {name,source,digest} pin reads `signed_unpinned`. '
                . "Re-run with --pin to write it.\n";
        }

        return 0;
    }

    // -----------------------------------------------------------------
    // pin
    // -----------------------------------------------------------------

    /**
     * `duo adapter pin <site-repo> --name=<n> [--source=site|plugin]`
     *
     * The host-side, WordPress-free twin of `wp duo manifest-pin`. It runs
     * the same `Policy::load()` + `RepositoryCompiler::resolved_adapters()`
     * the target command runs, so the digest is the one the engine will
     * check and not a second hash of the same file.
     *
     * For a name a shipped adapter also answers to, `--source=site` is the
     * OVERRIDE (T6 §3.3): precedence stays `shipped > site > plugin` for a
     * name-only pin, and the explicit site pin is how an operator selects
     * their own copy.
     *
     * @param list<string> $args
     */
    private static function pin(array $args): int {
        $flags = self::flags($args, ['name', 'source'], []);
        $repo = self::onlySiteRepo($flags['positional'], 'pin');
        if (is_int($repo)) {
            return $repo;
        }
        $name = (string) ($flags['name'] ?? '');
        if ($name === '') {
            return self::fail('--name=<adapter-name> is required');
        }
        $source = $flags['source'] ?? null;
        if ($source !== null && !in_array($source, [AdapterSources::SITE, AdapterSources::PLUGIN], true)) {
            // `shipped` is deliberately not offered: a shipped pin is what a
            // name-only pin already resolves to, so the flag would only ever
            // restate the default while inviting an operator to pin the one
            // source they cannot install or move.
            return self::fail(
                "--source accepts '" . AdapterSources::SITE . "' or '" . AdapterSources::PLUGIN
                . "'; omit it to pin whatever the name resolves to by precedence"
            );
        }

        self::boot();
        AdapterSources::assert_name($name, 'adapter pin --name');

        $pinObject = self::pinObject($repo, $name, is_string($source) ? $source : null);
        $changed = self::writePin($repo, $pinObject);

        echo ($changed ? 'wrote' : 'confirmed') . " the pin in $repo/site.duo.json:\n";
        echo rtrim(Canon::encode($pinObject)) . "\n";
        if (($pinObject['source'] ?? null) === AdapterSources::SITE) {
            echo "\nThis site pin is content-addressed: any edit to " . AdapterSources::SITE_DIR . "/$name.json "
                . "moves the digest and the pin refuses\n  until it is rewritten. That is the point — rerun "
                . "`duo adapter pin` (and `duo adapter certify`, if it is certified) after every edit.\n";
        }

        return 0;
    }

    // -----------------------------------------------------------------
    // shared mechanism
    // -----------------------------------------------------------------

    /**
     * The exact `{name, source, digest}` object the engine resolves.
     *
     * @param ?string $source null pins whatever precedence resolves
     * @return array<string,string>
     */
    private static function pinObject(string $repo, string $name, ?string $source): array {
        $requested = $source === null
            ? [$name]
            : [['name' => $name, 'source' => $source]];
        $policy = Policy::load($repo, $requested);
        $resolved = RepositoryCompiler::resolved_adapters($policy);
        $row = null;
        foreach ($resolved as $candidate) {
            if ((string) ($candidate['name'] ?? '') === $name) {
                $row = $candidate;
            }
        }
        if ($row === null || !is_string($row['digest'] ?? null) || $row['digest'] === '') {
            throw new \RuntimeException(
                "duo: the engine resolved no content digest for adapter '$name'; it cannot be pinned"
            );
        }

        return [
            'name' => $name,
            'source' => (string) ($row['source'] ?? AdapterSources::SHIPPED),
            'digest' => (string) $row['digest'],
        ];
    }

    /**
     * Write or replace one pin in `site.duo.json`, leaving every other byte
     * of the operator's file alone.
     *
     * The file is REWRITTEN canonically, which is the same treatment
     * `Policy::load()` already demands of it on every read — so this cannot
     * introduce bytes the engine would then refuse.
     *
     * @param array<string,string> $pin
     * @return bool true when the file changed
     */
    private static function writePin(string $repo, array $pin): bool {
        $file = $repo . '/site.duo.json';
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read $file");
        }
        // Decoded as OBJECTS, not associative arrays. `site.duo.json`
        // legitimately carries empty JSON objects (`"policy": {}` is the
        // common case on a fresh init), and PHP erases the `{}` versus `[]`
        // distinction the moment an empty object becomes an empty array —
        // which would rewrite a valid policy block into a list the engine
        // refuses. Only `manifests` is touched; every other node is the
        // operator's own decoded value, re-encoded unchanged.
        $site = self::canonicalTyped($raw, 'site.duo.json');
        $manifests = $site->manifests ?? [];
        if (!is_array($manifests)) {
            throw new \RuntimeException('duo: site.duo.json manifests must be a JSON array');
        }

        $out = [];
        $replaced = false;
        foreach ($manifests as $entry) {
            $entryName = is_string($entry) ? $entry : (is_object($entry) ? ($entry->name ?? null) : null);
            if ($entryName === $pin['name']) {
                if ($replaced) {
                    // Two pins for one name is a repository defect that
                    // `PinResolver` refuses later anyway; collapsing it
                    // silently here would hide it until the next load.
                    throw new \RuntimeException(
                        "duo: site.duo.json already pins '{$pin['name']}' more than once; remove the duplicate first"
                    );
                }
                $out[] = $pin;
                $replaced = true;
                continue;
            }
            $out[] = $entry;
        }
        if (!$replaced) {
            $out[] = $pin;
        }
        $site->manifests = $out;

        $encoded = Canon::encode($site);
        if (hash_equals($raw, $encoded)) {
            return false;
        }
        self::atomicWrite($file, $encoded, 0644);

        return true;
    }

    /**
     * Register the operator's public key in the SITE trust root.
     *
     * The record shape is exactly the shipped `duo-adapter-authorities/v1`
     * grammar `AdapterCertification::validateAuthorityRecord()` enforces —
     * there is no site-only dialect, because a second dialect is a second
     * verifier.
     *
     * A key already present under this id must carry the SAME public key: a
     * silent rotation would make every certificate that id already signed
     * verify against a different organization.
     *
     * @return bool true when this run added or widened the record
     */
    private static function registerAuthority(
        string $repo,
        string $keyId,
        string $public,
        string $name,
        string $tier
    ): bool {
        $file = $repo . '/' . self::AUTHORITIES_RELATIVE;
        $document = ['format' => AdapterCertification::AUTHORITIES_FORMAT, 'keys' => []];
        if (is_file($file)) {
            if (is_link($file)) {
                throw new \RuntimeException(
                    'duo: ' . self::AUTHORITIES_RELATIVE . ' must be a regular file, not a symbolic link'
                );
            }
            $raw = (string) file_get_contents($file);
            $document = self::canonicalObject($raw, self::AUTHORITIES_RELATIVE);
            if (($document['format'] ?? null) !== AdapterCertification::AUTHORITIES_FORMAT
                || !is_array($document['keys'] ?? null)) {
                throw new \RuntimeException(
                    'duo: ' . self::AUTHORITIES_RELATIVE . ' is not a '
                    . AdapterCertification::AUTHORITIES_FORMAT . ' document'
                );
            }
        }

        $encodedKey = base64_encode($public);
        $record = $document['keys'][$keyId] ?? null;
        if (is_array($record)) {
            if (($record['public_key'] ?? null) !== $encodedKey) {
                throw new \RuntimeException(
                    "duo: authority key '$keyId' is already registered in " . self::AUTHORITIES_RELATIVE
                    . ' with a different public key — choose another --key-id, or remove the stale record '
                    . 'deliberately (every certificate it signed stops verifying)'
                );
            }
            if (($record['status'] ?? null) !== 'trusted') {
                throw new \RuntimeException(
                    "duo: authority key '$keyId' is revoked in " . self::AUTHORITIES_RELATIVE
                    . ' and cannot certify adapters'
                );
            }
        } else {
            $record = [
                'adapter_names' => [],
                'algorithm' => 'ed25519',
                'public_key' => $encodedKey,
                'scope' => 'site_adapter_certification',
                'status' => 'trusted',
                'trust_tiers' => [],
            ];
        }

        $names = is_array($record['adapter_names'] ?? null) ? array_values($record['adapter_names']) : [];
        $tiers = is_array($record['trust_tiers'] ?? null) ? array_values($record['trust_tiers']) : [];
        $before = [$names, $tiers];
        if (!in_array($name, $names, true)) {
            $names[] = $name;
        }
        if (!in_array($tier, $tiers, true)) {
            $tiers[] = $tier;
        }
        sort($names, SORT_STRING);
        sort($tiers, SORT_STRING);
        $record['adapter_names'] = $names;
        $record['trust_tiers'] = $tiers;

        $document['keys'][$keyId] = $record;
        // `keys` is a JSON OBJECT in this grammar and stays one even with a
        // single member; Canon encodes an empty PHP array as `[]`, which
        // `authority()` refuses.
        $encoded = Canon::encode([
            'format' => AdapterCertification::AUTHORITIES_FORMAT,
            'keys' => (object) $document['keys'],
        ]);
        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if ($existing !== null && hash_equals($existing, $encoded)) {
            return false;
        }
        self::ensureDirectory(dirname($file));
        self::atomicWrite($file, $encoded, 0644);

        return $before !== [$record['adapter_names'], $record['trust_tiers']] || $existing === null;
    }

    /**
     * The grammar half of the evidence triple: the engine's REAL loader over
     * this site repository, one adapter at a time.
     *
     * @return array{status:string,message:?string}
     */
    private static function grammarVerdict(string $repo, string $name): array {
        try {
            Policy::load($repo, [$name]);
        } catch (\Throwable $t) {
            return ['status' => 'error', 'message' => $t->getMessage()];
        }

        return ['status' => 'ok', 'message' => null];
    }

    /**
     * Build the certification bundle in a scratch directory.
     *
     * Every asset is written with the encoding its verifier demands:
     * `bundle.json` and `results/*.json` in the bundle producer's four-space
     * pretty canonical form (`AdapterCertification::parseBundleObject()`),
     * `environment.json`, `ratification.json` and `diffs/*.json` in
     * repository `Canon`. Getting one of those wrong is a refusal at sign
     * time, not a silently weaker certificate.
     *
     * @param array<string,mixed> $manifest
     * @param array{status:string,message:?string} $grammar
     * @return string the scratch bundle directory (caller removes it)
     */
    private static function buildBundle(
        string $repo,
        string $name,
        array $manifest,
        string $adapterRaw,
        array $grammar,
        string $reason
    ): string {
        $dir = self::scratchDirectory();

        $environment = [
            'multisite' => false,
            'php' => PHP_VERSION,
            // Stated, not measured: this bundle exercised nothing, so there
            // is no WordPress version it ran against. `environment.json` and
            // `environment_summary` must agree byte-for-byte, so the same
            // array builds both.
            'wordpress' => 'not exercised',
        ];
        self::writeFile($dir . '/environment.json', Canon::encode($environment));

        $ratification = [
            'format' => AdapterCertification::RATIFICATION_FORMAT,
            'manifests' => (object) [$name => self::disposition($name, $manifest, $reason)],
            'profiles' => [],
        ];
        $ratificationRaw = Canon::encode($ratification);
        self::writeFile($dir . '/ratification.json', $ratificationRaw);

        // T6 §3.5's evidence triple, as the named test's own recorded
        // result. It is inside the content-addressed asset set, so the
        // Ed25519 signature covers it: an operator cannot later claim the
        // adapter was exercised when the signed bundle says it was not.
        $result = [
            'exercised' => false,
            'exit_code' => 0,
            'grammar' => $grammar['status'],
            'reason' => $reason,
            'schema_version' => 1,
            'test' => self::GRAMMAR_TEST,
            'verdict' => 'pass',
        ];
        self::writeFile($dir . '/results/' . self::GRAMMAR_TEST . '.json', self::bundlePretty($result));
        self::writeFile(
            $dir . '/diffs/' . self::GRAMMAR_TEST . '.json',
            Canon::encode(['changed' => [], 'status' => 'clean'])
        );
        self::writeFile(
            $dir . '/logs/' . self::GRAMMAR_TEST . '.txt',
            "duo adapter certify: the engine's manifest validators accepted adapters/$name.json\n"
            . "nothing was exercised against a live site\n"
        );

        $bundle = [
            'artifacts' => [],
            'bound_inputs' => [[
                'path' => AdapterSources::SITE_DIR . '/' . $name . '.json',
                'sha256' => hash('sha256', $adapterRaw),
                'size' => strlen($adapterRaw),
            ]],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => self::descriptor($dir, 'environment.json'),
            'environment_summary' => $environment,
            'force_hatches' => [],
            'git_revision' => self::NO_EVIDENCE_REVISION,
            'harness' => ['name' => 'duo-adapter-certify', 'version' => 1],
            'ratification' => self::descriptor($dir, 'ratification.json'),
            'ratification_summary' => [
                'certified_claims' => ['manifests.' . $name],
                'manifest_count' => 1,
                'profile_count' => 0,
            ],
            'schema_version' => AdapterCertification::BUNDLE_FORMAT,
            'subject' => ['kind' => 'site_adapter', 'name' => $name],
            'tests' => [[
                'diff' => self::descriptor($dir, 'diffs/' . self::GRAMMAR_TEST . '.json'),
                'id' => self::GRAMMAR_TEST,
                'log' => self::descriptor($dir, 'logs/' . self::GRAMMAR_TEST . '.txt'),
                'result' => self::descriptor($dir, 'results/' . self::GRAMMAR_TEST . '.json'),
                'verdict' => 'pass',
            ]],
            'verdict' => 'pass',
        ];
        $bundle['bundle_digest'] = self::bundleDigest($bundle);
        self::writeFile($dir . '/bundle.json', self::bundlePretty($bundle));
        unset($repo);

        return $dir;
    }

    /**
     * The one-manifest disposition the bundle ratifies, derived from the
     * manifest's own declarations.
     *
     * Derivation, not invention: `entity_sections`/`field_sections` name
     * only sections the manifest actually declares (a disposition naming an
     * absent section is refused by `ManifestDispositions::validate_entry()`),
     * `default_authored_keyspaces` names exactly the tables whose
     * `default_class` is `authored` (that check is one-for-one in both
     * directions), and every intent-only table is marked unsupported because
     * the same validator requires it.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function disposition(string $name, array $manifest, string $reason): array {
        $entity = [];
        foreach (self::ENTITY_SECTIONS as $section) {
            if (array_key_exists($section, $manifest)) {
                $entity[] = $section;
            }
        }
        $field = [];
        foreach (self::FIELD_SECTIONS as $section) {
            if (array_key_exists($section, $manifest)) {
                $field[] = $section;
            }
        }

        $unsupported = [[
            'operation' => 'delete',
            'reason' => self::DELETION_UNSUPPORTED_REASON,
            'surface' => 'all',
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
                    'reason' => 'intent-only table: this manifest declares its shape, not a write path',
                    'surface' => "tables.$table",
                ];
            }
            if (($rule['default_class'] ?? null) === 'authored') {
                $keyspaces[] = [
                    'reason' => 'the site organization accepts this table\'s default-authored keyspace as '
                        . 'declared; no live keyspace enumeration was performed',
                    'status' => 'justified',
                    'table' => $table,
                ];
            }
        }

        $versions = ['site' => ['source' => 'site certification: manifest grammar only, nothing exercised']];
        $plugin = $manifest['plugin'] ?? null;
        if (is_string($plugin) && $plugin !== '') {
            // `validate_entry()` compares these two against the manifest's
            // own contract for a certified entry; anything else is refused.
            $versions = ['plugin' => $plugin, 'range' => $manifest['version_range'] ?? null];
        }

        return [
            'capabilities' => [
                'deletion_semantics' => ['supported' => [], 'unsupported' => ['all']],
                'entity_sections' => $entity,
                'field_sections' => $field,
                'lifecycle_phases' => [],
                'operations' => self::CLAIMED_OPERATIONS,
            ],
            'default_authored_keyspaces' => $keyspaces,
            'evidence' => [
                'bundle_schema' => AdapterCertification::BUNDLE_FORMAT,
                'tests' => [self::GRAMMAR_TEST],
            ],
            'reason' => $reason,
            'status' => 'certified',
            'supported_versions' => $versions,
            'unsupported' => $unsupported,
        ];
    }

    /**
     * Write the certificate atomically at its DERIVED path.
     *
     * Moved here from `scripts/adapter-certification.php` so the mutation
     * boundary is one implementation: that script is now a thin wrapper over
     * this method, and a hardening change lands once.
     */
    public static function writeCertificate(string $repo, string $name, string $certificate): string {
        $root = realpath($repo);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException("duo: site repository is absent or not a directory: $repo");
        }
        $adapters = $root . '/' . AdapterSources::SITE_DIR;
        if (!is_dir($adapters) || is_link($adapters) || realpath($adapters) !== $adapters) {
            throw new \RuntimeException('duo: site adapters must be a real adapters directory inside the repository');
        }
        $directory = $adapters . '/' . AdapterSources::CERTIFICATION_DIR;
        if (!file_exists($directory) && !mkdir($directory, 0755)) {
            throw new \RuntimeException("duo: cannot create certification directory: $directory");
        }
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new \RuntimeException(
                'duo: site adapter certifications must be a real certifications directory inside adapters'
            );
        }
        $path = AdapterCertification::certificatePath($root, $name);
        if (dirname($path) !== $directory) {
            throw new \RuntimeException('duo: derived certification path escapes the canonical site certification directory');
        }
        self::atomicWrite($path, $certificate, 0644);

        return $path;
    }

    /**
     * Read an Ed25519 secret key from a file the operator named.
     *
     * Moved here from `scripts/adapter-certification.php`, mode check
     * included: a private key readable by the group or the world is not a
     * private key, and signing with one would mint a certificate whose
     * authority anybody on the box could forge.
     */
    public static function readSecretKey(string $path): string {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException("duo: --secret-key-file must be a regular non-symlink file: $path");
        }
        $permissions = fileperms($path);
        if ($permissions === false || (($permissions & 0077) !== 0)) {
            throw new \RuntimeException(
                "duo: --secret-key-file must not be group/world accessible: $path (chmod 600 it)"
            );
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException("duo: cannot read --secret-key-file: $path");
        }
        $trimmed = trim($raw);
        $decoded = preg_match('/^[0-9a-f]{128}$/Di', $trimmed) === 1
            ? hex2bin($trimmed)
            : base64_decode($trimmed, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException(
                "duo: --secret-key-file does not hold a base64 or hexadecimal Ed25519 secret key: $path"
            );
        }

        return $decoded;
    }

    /**
     * A stable, collision-resistant key id derived from the public key.
     *
     * An operator who does not name one still gets an id they can read off
     * two different machines and compare, and re-running `certify` with the
     * same key file finds the same record without them having remembered it.
     */
    private static function derivedKeyId(string $public): string {
        return 'site-' . substr(hash('sha256', $public), 0, 12);
    }

    /**
     * The nearest ancestor directory holding a `site.duo.json`, or null.
     *
     * Walks up rather than testing one level: an operator writing a key to
     * `<repo>/keys/secrets/org.key` is inside the repository just as much as
     * one writing to `<repo>/org.key`.
     */
    private static function enclosingSiteRepo(string $directory): ?string {
        $current = $directory;
        while (true) {
            if (is_file($current . '/site.duo.json')) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
    }

    /**
     * @param list<string> $positional
     * @return string|int the resolved repo path, or an exit code
     */
    private static function onlySiteRepo(array $positional, string $verb) {
        if ($positional === []) {
            return self::fail("$verb needs a <site-repo> argument (the directory holding site.duo.json)");
        }
        if (count($positional) > 1) {
            return self::fail("$verb takes exactly one <site-repo>, got a second argument '{$positional[1]}'");
        }
        $resolved = is_dir($positional[0]) ? realpath($positional[0]) : false;
        if ($resolved === false) {
            return self::fail("'{$positional[0]}' is not a directory");
        }
        if (!is_file($resolved . '/site.duo.json')) {
            return self::fail(
                "'$resolved' has no site.duo.json — <site-repo> is the duo SITE REPO (the directory holding "
                . 'site.duo.json)'
            );
        }

        return rtrim($resolved, '/');
    }

    /**
     * One argument grammar for all three verbs.
     *
     * A repeated flag is refused rather than last-wins, the posture every
     * other offline `duo` verb takes: a silently replaced flag signs a
     * request nobody wrote.
     *
     * @param list<string> $args
     * @param list<string> $valued
     * @param list<string> $boolean
     * @return array<string,mixed> flag name => value, plus `positional`
     */
    private static function flags(array $args, array $valued, array $boolean): array {
        $out = ['positional' => []];
        $seen = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                $out['positional'][] = $arg;
                continue;
            }
            $flag = ltrim(explode('=', $arg, 2)[0], '-');
            if (isset($seen[$flag])) {
                throw new \RuntimeException("duplicate flag '--$flag'");
            }
            $seen[$flag] = true;
            if (in_array($flag, $boolean, true)) {
                if (str_contains($arg, '=')) {
                    throw new \RuntimeException("--$flag takes no value");
                }
                $out[$flag] = true;
                continue;
            }
            if (!in_array($flag, $valued, true)) {
                throw new \RuntimeException("unsupported flag '$arg'");
            }
            if (!str_contains($arg, '=')) {
                throw new \RuntimeException("--$flag needs a value: --$flag=<value>");
            }
            $value = substr($arg, strlen($flag) + 3);
            if (trim($value) === '') {
                throw new \RuntimeException("--$flag needs a non-empty value");
            }
            $out[$flag] = $flag === 'reason' ? $value : trim($value);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function canonicalObject(string $raw, string $label): array {
        self::canonicalTyped($raw, $label);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The same canonical check, keeping the JSON object/array distinction PHP
     * associative arrays erase. Every writer above edits the typed form.
     */
    private static function canonicalTyped(string $raw, string $label): \stdClass {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!$typed instanceof \stdClass) {
            throw new \RuntimeException("duo: $label must be a JSON object");
        }
        if (!hash_equals(Canon::encode($typed), $raw)) {
            throw new \RuntimeException(
                "duo: $label is not canonical JSON — the engine reads these bytes exactly; rewrite it canonically"
            );
        }

        return $typed;
    }

    /** The bundle producer's own four-space canonical encoding. */
    private static function bundlePretty(mixed $value): string {
        return json_encode(
            Canon::normalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /** @param array<string,mixed> $bundle */
    private static function bundleDigest(array $bundle): string {
        unset($bundle['bundle_digest']);

        return hash('sha256', json_encode(
            Canon::normalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n");
    }

    /** @return array{path:string,sha256:string,size:int} */
    private static function descriptor(string $dir, string $relative): array {
        $file = $dir . '/' . $relative;
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read the bundle asset just written: $relative");
        }

        return ['path' => $relative, 'sha256' => hash('sha256', $raw), 'size' => strlen($raw)];
    }

    private static function writeFile(string $path, string $contents): void {
        self::ensureDirectory(dirname($path));
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("duo: cannot write $path");
        }
    }

    private static function ensureDirectory(string $path): void {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException("duo: cannot create directory $path");
        }
    }

    private static function atomicWrite(string $path, string $contents, int $mode): void {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.duo-certify-');
        if ($temporary === false) {
            throw new \RuntimeException("duo: cannot allocate a temporary file in $directory");
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false
                || !chmod($temporary, $mode)
                || !rename($temporary, $path)) {
                throw new \RuntimeException("duo: cannot atomically write $path");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * A scratch bundle directory OUTSIDE the site repository.
     *
     * AGENTS.md's closure rule is one reason; the operator's `git status`
     * is the other. A bundle is signing scaffolding, not repository content.
     */
    private static function scratchDirectory(): string {
        $base = sys_get_temp_dir() . '/duo-adapter-certify-' . bin2hex(random_bytes(8));
        self::ensureDirectory($base);

        return $base;
    }

    private static function removeTree(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
                continue;
            }
            @unlink($child);
        }
        @rmdir($path);
    }

    /**
     * Load the engine's pure surface into this WordPress-free process.
     *
     * The same shape as `ManifestValidate::boot()` and `AdapterDraft::boot()`
     * — resolve the two version constants out of `agent/duo.php`'s own
     * source (never a literal, so they cannot drift from what
     * `Policy::load()` requires), then require the engine files this command
     * reaches. `AdapterCertification` is the one addition: it is the signer
     * and verifier, and it is not on the other two commands' path.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("adapter certify: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter certify: could not resolve DUO_AGENT_VERSION');
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter certify: could not resolve DUO_SPEC_VERSION');
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }

        $classmap = require $repo . '/agent/duo-classmap.php';
        if (!is_array($classmap)) {
            throw new \RuntimeException('adapter certify: agent/duo-classmap.php did not return a map');
        }
        $files = [];
        foreach ($classmap as $path) {
            $files[basename((string) $path, '.php')] = (string) $path;
        }
        foreach ([
            'Canon', 'OptionState', 'ManifestDispositions', 'CapabilityRegistry',
            'Policy', 'AdapterCertification', 'RepositoryCompiler',
        ] as $class) {
            $file = $files[$class] ?? null;
            if (!is_string($file)) {
                throw new \RuntimeException(
                    'adapter certify: agent source ' . $class . '.php is absent from agent/duo-classmap.php'
                );
            }
            require_once $repo . '/agent/' . $file;
        }
    }

    private static function fail(string $message): int {
        fwrite(STDERR, "duo adapter: $message\n");

        return 2;
    }
}
