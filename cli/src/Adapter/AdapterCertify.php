<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\ScopeAdoption;

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
 * ## What this class does NOT build, and why
 *
 * It builds no certification bundle. `AdapterCertification::sign_site()`
 * does: it derives the ratification from the manifest, runs the real loader
 * for the `grammar` verdict, assembles the unexercised
 * `duo-site-adapter-certification-bundle/v1` in memory, verifies its own
 * output through the same validator that re-verifies it at every load, and
 * signs. Nothing of that reaches disk but the certificate — an unexercised
 * bundle's only assets are `environment.json` and `ratification.json`, and
 * both are already inside the signed statement.
 *
 * That split is deliberate and it is the second design this file had. The
 * first built the bundle here, out of the same shipped grammar, and it
 * verified. What it could not do is carry `exercised` onto the CLAIM: the
 * agent never reads a result asset for a claim field, so the fact would have
 * lived only in a blob nothing projects — and `evidence.tests:
 * ["manifest-grammar"]` is indistinguishable downstream from a reviewed
 * conformance run, which is exactly the collapse `exercised: false` exists
 * to prevent. The grammar and its producer belong in one file, beside the
 * validator that refuses them.
 *
 * ## What this class owns
 *
 * The three mutations, and nothing else: the private key (`keygen`), the
 * site trust root and the certificate file (`certify`), and the pin
 * (`certify --pin`, `pin`). The key must be registered in
 * `adapters/authorities.json` BEFORE signing — that is where
 * `AdapterCertification::authority()` resolves it, and resolving under the
 * site root is what makes the result `site_signed` rather than an
 * agent-owned certificate.
 *
 * WordPress-free, no environment, no transport: every verb here reads and
 * writes files on the machine running `duo`.
 */
final class AdapterCertify {
    /** The sub-verbs this class owns; `AdapterCatalog` owns list/inspect/doctor. */
    public const VERBS = ['keygen', 'certify', 'pin'];

    /** T6 §3.1: the SITE trust root, beside the adapters it is trusted for. */
    public const AUTHORITIES_RELATIVE = 'adapters/authorities.json';

    /**
     * Default `--reason` when the operator states none.
     *
     * `sign_site()` refuses a blank one, so there is always a stated basis on
     * the claim. This is the honest default rather than a placeholder: it
     * says exactly what a grammar-only certificate attests to.
     */
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
            // Typed, like every refusal a walk or an operator's script keys
            // on: the bracketed code is the contract (T6 §3.1 names it), the
            // sentence is for the human.
            return self::fail(
                "[secret_key_inside_repository] --out '$path' is inside the duo site repository $inside — "
                . 'private keys never live in a repository that gets committed and published; name a path outside it'
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
     *  [--key-id=<id>] [--reason=<text>] [--pin] [--adopt-scope]`
     *
     * @param list<string> $args
     */
    private static function certify(array $args): int {
        $flags = self::flags($args, ['name', 'secret-key-file', 'key-id', 'reason'], ['pin', 'adopt-scope']);
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
        $pinRequested = ($flags['pin'] ?? false) === true;
        $adoptScope = ($flags['adopt-scope'] ?? false) === true;
        if ($adoptScope && !$pinRequested) {
            // Without a pin there is no adoption to widen: an unpinned
            // adapter is not loaded, so writing its scope would opt the site
            // into types nothing can classify.
            return self::fail('--adopt-scope only means something with --pin: scope follows the pin, not the signature');
        }

        self::boot();
        AdapterSources::assert_name($name, 'adapter certify --name');

        $adapterPath = $repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json';
        if (!is_file($adapterPath) || is_link($adapterPath)) {
            return self::fail(
                'no site adapter at ' . AdapterSources::SITE_DIR . "/$name.json in $repo — certification signs an "
                . 'installed site adapter; install it there first (duo adapter-draft <site-repo> --name=' . $name
                . ' --out=' . AdapterSources::SITE_DIR . "/$name.json)"
            );
        }
        $adapterRaw = (string) file_get_contents($adapterPath);
        // An operator finishes a draft by hand (jq, an editor), and the engine
        // reads out-of-tree bytes EXACTLY: a hand-edited file is valid JSON
        // and almost never canonical. Refusing here sent every author to
        // "rewrite it canonically" by hand (the T6 walk's S2 stopped there);
        // the canonical form is a formatting of the same declarations, so
        // certify writes it — before any digest, signature or trust root — and
        // says so. Invalid JSON is still refused with the parser's own words.
        $adapterRaw = self::canonicalizeSiteAdapter($adapterPath, $adapterRaw, "site adapter $name.json");
        $manifest = self::canonicalObject($adapterRaw, "site adapter $name.json");

        $secret = self::readSecretKey($secretFile);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $keyId = (string) ($flags['key-id'] ?? self::derivedKeyId($public));
        AdapterSources::assert_name($keyId, 'adapter certify --key-id');

        $tier = AdapterSources::trust_tier($manifest);

        // A PRE-FLIGHT, not the verdict. `sign_site()` runs the real loader
        // itself and refuses with the loader's own message, so this is not the
        // authority on anything — it exists purely so that a manifest the
        // engine will not load never causes a WRITE. Registering the key
        // first and discovering the adapter is broken second would leave a
        // trust root in a repository whose certify attempt failed, and "a
        // failed certify leaves the repository exactly as it found it" is
        // worth one extra offline Policy::load(). (Found by running it.)
        $grammar = self::grammarVerdict($repo, $name);
        if ($grammar['status'] !== 'ok') {
            return self::fail(
                "site adapter '$name' does not load: {$grammar['message']}\n"
                . '       certification signs a manifest the engine accepts; run `duo manifest-validate '
                . AdapterSources::SITE_DIR . " --site=$repo --manifest=$name` and fix it first"
            );
        }

        // The key has to be IN the site trust root before signing: that is
        // where `AdapterCertification::authority()` resolves it from, and
        // resolving under the site root is what makes the result `site_signed`
        // rather than an agent-owned certificate.
        $authoritiesFile = $repo . '/' . self::AUTHORITIES_RELATIVE;
        $authoritiesBefore = is_file($authoritiesFile) ? (string) file_get_contents($authoritiesFile) : null;
        $registered = self::registerAuthority($repo, $keyId, $public, $name, $tier);

        // T6 §3.5, as the agent landed it: the AGENT owns the unexercised
        // bundle. `sign_site()` derives the ratification from the manifest,
        // runs the real loader for the `grammar` verdict, builds the bundle in
        // memory, verifies its own output through the same validator that
        // re-verifies at every load, and signs. Nothing of it reaches disk but
        // the certificate — an unexercised bundle's only assets are
        // `environment.json` and `ratification.json`, and both are already
        // inside the signed statement.
        //
        // This verb deliberately does NOT build a bundle. One grammar with
        // two producers is the failure mode both halves of this train were
        // trying to avoid, and the producer belongs beside the validator that
        // refuses it.
        $manifestDir = Policy::manifests_dir();
        try {
            $certificate = AdapterCertification::sign_site(
                $manifestDir,
                $repo,
                $name,
                $keyId,
                base64_encode($secret),
                $reason
            );
        } catch (\Throwable $t) {
            // The pre-flight is a load, the signature step is the certifier's
            // own reading of the manifest; when they disagree the trust root
            // must not keep a record this attempt wrote (a stale record can
            // invalidate every OTHER certificate under the key — seen when an
            // override's tier landed in the record and the signing refused).
            if ($authoritiesBefore === null) {
                @unlink($authoritiesFile);
            } else {
                file_put_contents($authoritiesFile, $authoritiesBefore, LOCK_EX);
            }
            throw $t;
        }
        $certificatePath = self::writeCertificate($repo, $name, $certificate);
        // Verify what was just written, through the live verifier, before
        // claiming anything. A producer that trusted its own bytes would put
        // the one certificate nobody checked into the repository.
        $verified = AdapterCertification::verifyFile(
            $manifestDir,
            $repo,
            $name,
            $manifest,
            $certificatePath
        );

        $summary = AdapterCertification::certificateSummary($verified);
        $pinObject = self::pinObject($repo, $name, 'site');

        echo "certified:  $name (site adapter)\n";
        echo "authority:  $keyId (site trust root, " . self::AUTHORITIES_RELATIVE
            . ($registered ? ' — key registered by this run' : ' — key already registered') . ")\n";
        echo 'trust tier: ' . (string) ($summary['trust_tier'] ?? $tier) . "\n";
        echo 'claim:      ' . (string) ($summary['status'] ?? 'certified') . "\n";
        echo "evidence:   grammar=ok, exercised=false, reason stated in the signed bundle\n";
        echo 'certificate: ' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR . "/$name.json\n";
        echo "\npin object for site.duo.json manifests[]:\n";
        echo rtrim(Canon::encode($pinObject)) . "\n";

        if ($pinRequested) {
            $changed = self::writePin($repo, $pinObject);
            echo "\n" . ($changed ? 'wrote' : 'confirmed') . ' the pin in site.duo.json'
                . " — the certificate binds these exact bytes, so re-run certify after any edit\n";
            // The pin is the site's opt-in act, so it must carry the scope
            // that act implies — DUO-3495: before this, certify --pin wrote a
            // pin that extended no scope and `duo capture` silently skipped
            // every type the newly-certified adapter declares.
            self::adoptScope($repo, $name, $manifest, $adoptScope);
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
     * `duo adapter pin <site-repo> --name=<n> [--source=site|plugin]
     *  [--adopt-scope]`
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
        $flags = self::flags($args, ['name', 'source'], ['adopt-scope']);
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

        // The override bootstrap (T6 §3.3, AdapterSources::override_pins()):
        // a site copy of a SHIPPED name loads only once site.duo.json pins that
        // name with source "site", and the digest that completes the pin can
        // only be read by loading it. So `--source=site` for a shipped name
        // writes the source statement first, loads, then completes the pin
        // with the digest — the same two-step the guide describes, in one
        // command. If the load then refuses, the file is put back exactly.
        $before = null;
        if ($source === AdapterSources::SITE
            && is_file(rtrim(Policy::manifests_dir(), '/') . '/' . $name . '.json')
            && !self::hasSourcePin($repo, $name, AdapterSources::SITE)) {
            $before = (string) file_get_contents($repo . '/site.duo.json');
            self::writePin($repo, ['name' => $name, 'source' => AdapterSources::SITE]);
            echo "override: site.duo.json now names the site copy of shipped adapter '$name' (source \"site\"); "
                . "the shipped definition is shadowed\n";
        }
        try {
            $pinObject = self::pinObject($repo, $name, is_string($source) ? $source : null);
        } catch (\Throwable $t) {
            if ($before !== null) {
                file_put_contents($repo . '/site.duo.json', $before, LOCK_EX);
            }
            throw $t;
        }
        $changed = self::writePin($repo, $pinObject);

        echo ($changed ? 'wrote' : 'confirmed') . " the pin in $repo/site.duo.json:\n";
        echo rtrim(Canon::encode($pinObject)) . "\n";
        if (($pinObject['source'] ?? null) === AdapterSources::SITE) {
            echo "\nThis site pin is content-addressed: any edit to " . AdapterSources::SITE_DIR . "/$name.json "
                . "moves the digest and the pin refuses\n  until it is rewritten. That is the point — rerun "
                . "`duo adapter pin` (and `duo adapter certify`, if it is certified) after every edit.\n";
        }
        // Same act, same consequence as `certify --pin`: this is where the
        // site opts into an adapter, so this is where the scope it declares
        // stops being invisible (DUO-3495). Last, so the pin's own trailer
        // stays one block.
        self::adoptScope($repo, $name, self::resolvedManifest($repo, $name), ($flags['adopt-scope'] ?? false) === true);

        return 0;
    }

    // -----------------------------------------------------------------
    // shared mechanism
    // -----------------------------------------------------------------

    /**
     * The scope half of a pin: opt the site into what the newly-pinned
     * adapter declares, say exactly what that did, and never guess.
     *
     * ## Why a pin may widen scope at all
     *
     * "Site policy always wins" (docs/guides/adapter-authoring.md
     * §Precedence) is about a rule the site RECORDED, not about a hole. This
     * writes only where `policy.scope.<kind>.<name>` is absent and the flat
     * list does not already name the type — i.e. where the site has said
     * nothing — and what it writes is exactly what the operator's own command
     * just asked for: `duo adapter certify --pin`/`duo adapter pin` IS a
     * site-authored act, and it edits site.duo.json already. It is also the
     * same opt-in `duo init` performs for an adapter selected at init time
     * (`InitPlanner::adapter_scope()` merges every declared-authored type into
     * the proposed `policy.post_types`), which is the whole asymmetry DUO-3495
     * reported: an adapter that arrives one minute after init meant nothing.
     *
     * ## Why a recorded class is never flipped without being asked
     *
     * `{"class":"runtime"}` under `policy.scope` is byte-identical whether a
     * human wrote it with `duo classify` or `duo init --allow-unmanaged-
     * plugins` recorded it for an unmanaged plugin's rowful type, and the
     * grammar has no third key to tell them apart ("scope rules accept class
     * only", Policy.php:2745). So provenance is not recoverable and this does
     * not infer it: a recorded entry is PRINTED with the two commands that
     * change it and left exactly as the site wrote it. `--adopt-scope` is the
     * operator supplying the missing fact themselves.
     *
     * @param array<string,mixed> $manifest the adapter this pin resolves to
     */
    private static function adoptScope(string $repo, string $name, array $manifest, bool $adopt): void {
        $site = json_decode((string) file_get_contents($repo . '/site.duo.json'), true);
        if (!is_array($site)) {
            throw new \RuntimeException('duo: site.duo.json must be a JSON object');
        }
        $rows = ScopeAdoption::plan($manifest, $site);
        if ($rows === []) {
            return;
        }

        // Three buckets, keyed on the plan's own verdict rather than on the
        // recorded class: `added` is a hole being filled, `flipped` is a
        // recorded decision the operator asked to override, `shadowed` is one
        // left standing. Nothing else is touched.
        $added = [];
        $flipped = [];
        $shadowed = [];
        foreach ($rows as $row) {
            if ($row['state'] === ScopeAdoption::EXTEND) {
                $added[] = $row;
            } elseif ($row['state'] === ScopeAdoption::SHADOWED && $adopt) {
                $flipped[] = $row;
            } elseif ($row['state'] === ScopeAdoption::SHADOWED) {
                $shadowed[] = $row;
            }
        }
        $write = array_merge($added, $flipped);
        if ($write !== []) {
            self::writeScopeRules($repo, $write);
        }

        if ($added !== []) {
            echo "\nscope: wrote " . count($added) . ' authored scope rule(s) for surface(s) this adapter declares'
                . " and site.duo.json had not decided\n";
            foreach ($added as $row) {
                echo '  + ' . $row['pointer'] . " = {\"class\": \"authored\"}\n";
            }
        }
        if ($flipped !== []) {
            echo "\nscope: --adopt-scope overrode " . count($flipped)
                . " decision(s) site.duo.json had already recorded\n";
            foreach ($flipped as $row) {
                echo '  ~ ' . $row['pointer'] . ' = {"class": "' . $row['class'] . "\"} -> {\"class\": \"authored\"}\n";
            }
        }
        if ($shadowed !== []) {
            echo "\nscope: " . count($shadowed) . ' surface(s) this adapter declares stay LOCAL — site.duo.json'
                . " already decided them, and a recorded site rule outranks every manifest\n";
            foreach ($shadowed as $row) {
                echo '  ! ' . $row['pointer'] . ' = {"class": "' . $row['class'] . '"} — capture will skip '
                    . $row['kind'] . ' ' . $row['name'] . "\n";
            }
            echo "  to adopt them anyway: duo adapter pin $repo --name=$name --adopt-scope\n";
            echo '  to decide one on the site: wp duo classify --repo=<repo> --set=\''
                . $shadowed[0]['spec'] . "'\n";
        }
        if ($added === [] && $flipped === [] && $shadowed === []) {
            echo "\nscope: every surface this adapter declares is already in site.duo.json's authored scope\n";
        }
    }

    /**
     * The manifest the ENGINE resolves for `$name`, read after the pin is
     * written so precedence, source pins and overrides are already applied —
     * the same bytes the next `duo capture` will classify with.
     *
     * @return array<string,mixed>
     */
    private static function resolvedManifest(string $repo, string $name): array {
        foreach (Policy::load($repo)->manifests as $manifest) {
            if (is_array($manifest) && (string) ($manifest['name'] ?? '') === $name) {
                return $manifest;
            }
        }
        throw new \RuntimeException("duo: the engine resolved no manifest for adapter '$name' after pinning it");
    }

    /**
     * Write `{"class": "authored"}` scope rules into site.duo.json.
     *
     * Typed, like `writePin()` and for the same reason: `"policy": {}` and
     * every empty section inside it are JSON OBJECTS, and an associative
     * round trip rewrites them as `[]` — a file the engine then refuses.
     * Only the named `policy.scope.<kind>.<name>` nodes are touched.
     *
     * @param list<array{kind:string,name:string}> $rows
     */
    private static function writeScopeRules(string $repo, array $rows): void {
        $file = $repo . '/site.duo.json';
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read $file");
        }
        $site = self::typedObject($raw, 'site.duo.json');
        $scope = self::objectNode(self::objectNode($site, 'policy'), 'scope');
        foreach ($rows as $row) {
            // The rule shape the scope grammar admits and nothing more:
            // ScopeGrammar::validate_scope_classes() refuses any other key,
            // and Policy::set_rule() writes exactly this for
            // `--set=scope:<kind>:<name>=authored` (Policy.php:2745-2752).
            self::objectNode($scope, $row['kind'])->{$row['name']} = ['class' => 'authored'];
        }

        $encoded = Canon::encode($site);
        if (hash_equals($raw, $encoded)) {
            return;
        }
        self::atomicWrite($file, $encoded, 0644);
    }

    /**
     * The child object at `$key`, created when absent.
     *
     * An empty JSON object that has been through PHP associative arrays comes
     * back as `[]` — Canon::normalize()'s own rule — and the engine reads both
     * as "nothing declared here"; the hand-edited fixture above (this file's
     * T6-walk S2 case) carries a literal `"policy": []` for that reason. So an
     * EMPTY list is admitted and becomes the object the new rule needs, while
     * a populated one is a genuine shape error and is refused.
     */
    private static function objectNode(\stdClass $parent, string $key): \stdClass {
        $value = $parent->{$key} ?? null;
        if ($value instanceof \stdClass) {
            return $value;
        }
        if ($value === null || $value === []) {
            return $parent->{$key} = new \stdClass();
        }

        throw new \RuntimeException("duo: site.duo.json '$key' must be a JSON object");
    }

    /** Whether site.duo.json already pins `$name` with the given source. */
    private static function hasSourcePin(string $repo, string $name, string $source): bool {
        $raw = @file_get_contents($repo . '/site.duo.json');
        if (!is_string($raw)) {
            return false;
        }
        try {
            $site = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return false;
        }
        foreach ((array) ($site['manifests'] ?? []) as $pin) {
            if (is_array($pin) && ($pin['name'] ?? null) === $name && ($pin['source'] ?? null) === $source) {
                return true;
            }
        }

        return false;
    }

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
        // operator's own decoded value, re-encoded unchanged. A hand-edited
        // (valid, non-canonical) site.duo.json is admitted: the write below
        // is canonical whatever the input was, and refusing here sent the
        // author to reformat a file this command was about to rewrite (the T6
        // walk's S2 stopped on exactly that).
        $site = self::typedObject($raw, 'site.duo.json');
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

    /**
     * Rewrite a valid-but-not-canonical site adapter canonically, in place,
     * and return the bytes the engine will read. Nothing but formatting
     * changes: the JSON object/array distinction is kept by decoding typed.
     */
    private static function canonicalizeSiteAdapter(string $path, string $raw, string $label): string {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!$typed instanceof \stdClass) {
            throw new \RuntimeException("duo: $label must be a JSON object");
        }
        $canonical = Canon::encode($typed);
        if (hash_equals($canonical, $raw)) {
            return $raw;
        }
        if (@file_put_contents($path, $canonical, LOCK_EX) !== strlen($canonical)) {
            throw new \RuntimeException("duo: could not rewrite $label canonically at $path");
        }
        echo "rewrote $label canonically (same declarations; the engine reads these bytes exactly)\n";

        return $canonical;
    }

    /** @return array<string,mixed> */
    private static function canonicalObject(string $raw, string $label): array {
        self::canonicalTyped($raw, $label);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** Valid JSON object, typed (object/array distinction kept); canonical or not. */
    private static function typedObject(string $raw, string $label): \stdClass {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!$typed instanceof \stdClass) {
            throw new \RuntimeException("duo: $label must be a JSON object");
        }

        return $typed;
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
        // ManifestDispositions is the surviving dependency of the pair: it owns
        // the claim projection and the platform boundary AdapterCertification
        // binds. There is no CapabilityRegistry.php to require any more.
        foreach ([
            'Canon', 'OptionState', 'ManifestDispositions',
            'Policy', 'AdapterCertification', 'RepositoryCompiler',
            // ScopeAdoption is the agent's own reading of "which surfaces
            // does this manifest declare authored" — the same one init
            // applies to a selected adapter. A second copy here would be a
            // second product (DUO-3495).
            'ScopeAdoption',
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
